<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Exceptions\StorageException;
use MultiTenantSaas\Modules\Miniapp\Models\MiniappBuild;
use Symfony\Component\Process\Process;

/**
 * 小程序构建流程（rsync 模板 → 生成 manifest → pnpm 构建 → patch 配置 → zip）
 *
 * 外部命令统一经 $commandRunner 执行：
 * - 生产默认 null → Symfony Process（rsync/pnpm/zip）
 * - 单测注入假 runner（闭包 (array $cmd, string $cwd, array $env): string），
 *   全程不落真实文件系统/不跑 node
 *
 * 流程消费 config_snapshot（发起时快照），产物与发起时配置一致；
 * 异常捕获不抛出，终态 failed + error + last_log（Job 层兜底即可）。
 *
 * 领域异常改用框架 DomainException 体系（架构守卫检查 6：src/ 新增行禁 throw
 * new \RuntimeException）：本流程所有异常均在 run() 内被 \Throwable 捕获转 failed，
 * 不冒泡到 HTTP，故异常类型仅影响日志语义，无接口行为变化。
 */
class MiniappBuildService
{
    /** 模板内相对 workspace 的 manifest 路径（apps/h5 为 uni-app 应用根） */
    protected const H5_MANIFEST_REL = 'apps/h5/src/manifest.json';

    /** 构建产物相对 workspace 的目录（pnpm --filter h5 build:mp-weixin 输出） */
    protected const MP_DIST_REL = 'apps/h5/dist/build/mp-weixin';

    /**
     * @var callable|null 外部命令执行器：(array, string, array) => string
     */
    public $commandRunner = null;

    public function __construct(
        protected MiniappConfigService $config,
    ) {}

    /**
     * 执行一次构建（状态机推进，异常转 failed 不抛出）
     */
    public function run(MiniappBuild $build): void
    {
        try {
            $snapshot = $this->requireSnapshot($build);

            $this->markBuilding($build);

            $log = [];
            $workDir = $this->prepareWorkDir($build, $log);

            $manifestPath = $workDir . '/' . self::H5_MANIFEST_REL;
            $this->writeManifest($manifestPath, $snapshot);
            $log[] = '[miniapp] manifest.json 已写入（名称/版本/appid/urlCheck）';

            $apiBase = $this->resolveApiBase($snapshot);
            $brandPrimary = $this->resolveBrandPrimary($snapshot);
            $this->runProcess(
                [$this->bin('pnpm'), 'install', '--frozen-lockfile', '--prefer-offline'],
                $workDir,
                [],
                $log
            );
            $log[] = '[miniapp] pnpm install 完成';

            $this->runProcess(
                [$this->bin('pnpm'), '--filter', 'h5', 'build:mp-weixin'],
                $workDir,
                ['VITE_API_BASE' => $apiBase, 'VITE_BRAND_PRIMARY' => $brandPrimary],
                $log
            );
            $log[] = '[miniapp] mp-weixin 构建完成，API 域: ' . $apiBase . '，品牌色: ' . $brandPrimary;

            $mpDistDir = $workDir . '/' . self::MP_DIST_REL;
            if (! is_dir($mpDistDir)) {
                throw new DomainException('构建产物目录缺失（预期 ' . self::MP_DIST_REL . '），请确认 h5 工程 build:mp-weixin 脚本输出路径');
            }

            // 双保险 patch project.config.json（不依赖 uni 对 manifest mp-weixin.setting 的透传）
            $this->patchProjectConfig($mpDistDir, $snapshot);
            $log[] = '[miniapp] project.config.json appid/urlCheck 已核对';

            $artifactPath = $this->packageArtifact($build, $mpDistDir, $log);

            $build->forceFill([
                'status' => MiniappBuild::STATUS_SUCCESS,
                'artifact_path' => $artifactPath,
                'error' => null,
                'last_log' => $this->tailLog($log),
                'finished_at' => now(),
            ])->save();

            Log::info('[MiniappBuild] success', [
                'tenant_id' => $build->tenant_id,
                'build_id' => $build->build_id,
                'artifact' => $artifactPath,
            ]);
        } catch (\Throwable $e) {
            Log::error('[MiniappBuild] failed', [
                'tenant_id' => $build->tenant_id,
                'build_id' => $build->build_id,
                'error' => $e->getMessage(),
            ]);

            $build->forceFill([
                'status' => MiniappBuild::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'last_log' => isset($log) ? $this->tailLog($log) : null,
                'finished_at' => now(),
            ])->save();
        }
    }

    /**
     * 校验快照并取回（缺快照视为数据异常）
     */
    protected function requireSnapshot(MiniappBuild $build): array
    {
        $snapshot = $build->config_snapshot;
        if (! is_array($snapshot)) {
            throw new DomainException('构建记录缺少 config_snapshot，无法构建');
        }

        return $snapshot;
    }

    protected function markBuilding(MiniappBuild $build): void
    {
        $build->forceFill([
            'status' => MiniappBuild::STATUS_BUILDING,
            'error' => null,
            'started_at' => now(),
        ])->save();
    }

    /**
     * rsync 模板到工作目录并返回工作目录
     *
     * rsync --delete 幂等对齐目标：排除项（node_modules 等）默认不被删除，
     * 已装依赖跨构建复用（pnpm install --prefer-offline 秒过）。
     */
    protected function prepareWorkDir(MiniappBuild $build, array &$log): string
    {
        $workspace = (string) config('miniapp.workspace_path', '/data/miniapp/workspace');
        if (! is_dir($workspace) || ! is_dir($workspace . '/apps/h5')) {
            throw new ServiceUnavailableException("构建模板 workspace 未就绪（预期 {$workspace}/apps/h5），请联系平台初始化构建环境");
        }

        $root = rtrim((string) config('miniapp.builds_root', '/data/miniapp/builds'), '/');
        $workDir = $root . '/' . $build->tenant_id . '/' . $build->build_id;

        if (! is_dir($workDir) && ! @mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new StorageException("工作目录创建失败：{$workDir}");
        }

        $log[] = "[miniapp] rsync 模板: {$workspace} → {$workDir}";
        $this->runProcess(
            [
                $this->bin('rsync'), '-a', '--delete',
                '--exclude', 'node_modules', '--exclude', 'dist',
                '--exclude', '.git', '--exclude', '.vite',
                '--exclude', '.mimocode', '--exclude', '*.log',
                rtrim($workspace, '/') . '/',
                rtrim($workDir, '/') . '/',
            ],
            dirname($workspace),
            [],
            $log
        );

        return $workDir;
    }

    /**
     * 生成 manifest.json：模板含 JS 风格块注释（非标准 JSON）→ 剥注释 → 深合并
     */
    protected function writeManifest(string $manifestPath, array $snapshot): void
    {
        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            throw new NotFoundException("manifest 模板缺失：{$manifestPath}");
        }

        // 剥离行内块注释（uni-app 模板在 JSON 中嵌入 /* */ 说明）
        $clean = preg_replace('#/\*.*?\*/#s', '', $raw);
        $template = json_decode((string) $clean, true);
        if (! is_array($template)) {
            throw new DomainException('manifest.json 模板解析失败（注释剥离后仍非合法 JSON）');
        }

        $common = $snapshot['common'] ?? [];
        $mp = $snapshot['mp_weixin'] ?? [];
        $appid = $this->resolveAppid($snapshot);

        // 顶层名称/版本 + mp-weixin {appid, setting.urlCheck} 深合并
        $overrides = [
            'name' => (string) ($common['name'] ?? $template['name'] ?? ''),
            'versionName' => (string) ($common['version_name'] ?? $template['versionName'] ?? ''),
            'versionCode' => (string) ($common['version_code'] ?? $template['versionCode'] ?? ''),
            'mp-weixin' => array_merge($template['mp-weixin'] ?? [], [
                'appid' => $appid,
                'setting' => array_merge($template['mp-weixin']['setting'] ?? [], [
                    'urlCheck' => (bool) ($mp['url_check'] ?? false),
                ]),
            ]),
        ];

        $merged = $this->deepMerge($template, $overrides);

        $encoded = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new DomainException('manifest.json 序列化失败：' . json_last_error_msg());
        }

        file_put_contents($manifestPath, $encoded . "\n");
    }

    /**
     * patch 产物 project.config.json（appid + urlCheck 双保险）
     */
    protected function patchProjectConfig(string $mpDistDir, array $snapshot): void
    {
        $path = $mpDistDir . '/project.config.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new NotFoundException("project.config.json 缺失：{$path}");
        }

        $config = json_decode($raw, true);
        if (! is_array($config)) {
            throw new DomainException('project.config.json 解析失败');
        }

        $config['appid'] = $this->resolveAppid($snapshot);
        $config['setting']['urlCheck'] = (bool) (($snapshot['mp_weixin']['url_check'] ?? false));

        file_put_contents($path, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * zip 产物 → storage/app/miniapp/{tenant}/{build}.zip；返回相对路径
     */
    protected function packageArtifact(MiniappBuild $build, string $mpDistDir, array &$log): string
    {
        $disk = Storage::disk('local');
        $relative = trim((string) config('miniapp.artifact_root', 'miniapp'), '/')
            . '/' . $build->tenant_id . '/' . $build->build_id . '.zip';
        $zipPath = $disk->path($relative);

        // 压缩 mp-weixin 目录本身（zip 内顶层为 mp-weixin/，微信开发者工具/上传方接受）
        $parent = dirname($mpDistDir);
        $basename = basename($mpDistDir);

        $disk->makeDirectory(dirname($relative));
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $log[] = "[miniapp] zip 产物: {$relative}";
        $this->runProcess(
            [$this->bin('zip'), '-qr', $zipPath, $basename],
            $parent,
            [],
            $log
        );

        if (! file_exists($zipPath)) {
            throw new StorageException("zip 产物生成失败：{$zipPath}");
        }

        return $relative;
    }

    /**
     * 快照形态下的小程序 appid（store 时已按形态解析冗余进快照）
     */
    protected function resolveAppid(array $snapshot): string
    {
        $appid = (string) ($snapshot['appid'] ?? '');
        if ($appid === '') {
            throw new DomainException('小程序 appid 为空，无法构建（请在平台配置或服务商授权）');
        }

        return $appid;
    }

    /**
     * 构建时 API base（注入 VITE_API_BASE）：快照 common.api_domain 补协议 + /api/v1
     *
     * 小程序没有「同域相对路径」可用（wx.request 必须绝对 URL），而前端
     * request.ts 的兜底值 '/api/v1' 是**含版本前缀**的完整 base —— 注入值必须与
     * 之同语义，否则产物请求落到 https://{domain}/auth/xxx（漏 /api/v1）直接 404，
     * 且开发工具内 urlCheck=false 不报错，只在真机上暴露。
     * api_domain 已带前缀时不重复补（容错租户填写差异）。
     */
    protected function resolveApiBase(array $snapshot): string
    {
        $domain = (string) ($snapshot['common']['api_domain'] ?? '');
        if ($domain === '') {
            throw new DomainException('租户 API 域名未配置，无法构建');
        }

        $base = str_starts_with($domain, 'http://') || str_starts_with($domain, 'https://')
            ? $domain
            : 'https://' . $domain;

        return str_ends_with($base, '/api/v1') ? $base : rtrim($base, '/') . '/api/v1';
    }

    /**
     * 构建时品牌主色（注入 VITE_BRAND_PRIMARY）
     *
     * 取自快照 brand_primary（MiniappConfigService::snapshot 已按 tenants.branding
     * 解析并校验）。此处再兜一层：老构建记录快照无此键，或值被篡改，一律回退
     * 兜底绿，绝不把非法值注入产物。与前端 utils/color.ts BRAND_PRIMARY_FALLBACK 同值。
     */
    protected function resolveBrandPrimary(array $snapshot): string
    {
        $color = trim((string) ($snapshot['brand_primary'] ?? ''));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#07c160';
    }

    /**
     * 执行外部命令（默认 Symfony Process；测试注入假 runner）
     */
    protected function runProcess(array $cmd, string $cwd, array $env, array &$log): string
    {
        $runner = $this->commandRunner;
        if ($runner !== null) {
            $output = (string) $runner($cmd, $cwd, $env);
            $log[] = '$ ' . implode(' ', $cmd);

            return $output;
        }

        $timeout = (int) config('miniapp.job_timeout_seconds', 1800);
        $process = new Process($cmd, $cwd, $env, null, $timeout);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();
        $log[] = '$ ' . implode(' ', $cmd);

        if (! $process->isSuccessful()) {
            $detail = mb_substr(trim((string) $output), 0, 4000);
            $log[] = $detail;
            throw new ServiceUnavailableException('命令执行失败 [' . implode(' ', $cmd) . ']：' . ($detail !== '' ? $detail : '无输出'));
        }

        return (string) $output;
    }

    /**
     * 可执行文件解析（config 兜底系统 PATH）
     */
    protected function bin(string $name): string
    {
        return (string) config("miniapp.{$name}_bin", $name);
    }

    /**
     * 日志截尾（保留最近 N 行）
     */
    protected function tailLog(array $log): string
    {
        $lines = (int) config('miniapp.log_tail_lines', 200);

        return implode("\n", array_slice($log, -$lines));
    }

    /**
     * 深合并（递归数组合并，覆盖 override 非空值）
     */
    protected function deepMerge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } elseif ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
