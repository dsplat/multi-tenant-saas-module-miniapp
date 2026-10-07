<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Miniapp\Jobs\MiniappBuildJob;
use MultiTenantSaas\Modules\Miniapp\Models\MiniappBuild;
use MultiTenantSaas\Modules\Miniapp\Services\MiniappBuildService;
use MultiTenantSaas\Modules\Miniapp\Services\MiniappConfigService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 小程序构建管理
 *
 * store 前置校验（422）：enabled（框架登录总闸）且 appid 已配（component 取授权
 * appid / self 取框架登录凭证）+ api_domain 已配；同租户同平台 queued/building → 409。
 * config_snapshot 落发起时快照，Job 消费快照（产物与发起时所见一致）。
 *
 * artifact 下载：归属校验由 TenantScope + 后台租户中间件链保证；zip 路径
 * 仅取 DB artifact_path（禁拼用户输入）。
 */
class MiniappBuildController extends BaseController
{
    public function __construct(
        protected MiniappBuildService $buildService,
        protected MiniappConfigService $config,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $platform = (string) $request->query('platform', MiniappBuild::PLATFORM_MP_WEIXIN);

        $builds = MiniappBuild::where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->orderByDesc('build_id')
            ->limit(50)
            ->get()
            ->map(fn (MiniappBuild $build) => $this->toArray($build));

        return response()->json(['success' => true, 'data' => $builds]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        // 前置校验：配置完整性（422）
        $common = $this->config->common($tenantId);
        $appid = $this->config->appid($tenantId);
        $enabled = $this->config->enabled($tenantId);

        $errors = [];
        if (! $enabled) {
            $errors[] = '小程序登录未启用，请先在「第三方登录 → 微信」开启';
        }
        if ($appid === '') {
            $errors[] = '小程序 AppID 未配置（自建模式请在「第三方登录 → 微信」填写；服务商模式请先完成小程序授权）';
        }
        if ((string) ($common['api_domain'] ?? '') === '') {
            $errors[] = '租户 API 域名未配置（构建产物将无法访问后端接口）';
        }
        if ($errors !== []) {
            return response()->json(['success' => false, 'message' => implode('；', $errors)], 422);
        }

        // 防重：同租户同平台已有排队/构建中任务 → 409
        $inflight = MiniappBuild::where('tenant_id', $tenantId)
            ->where('platform', MiniappBuild::PLATFORM_MP_WEIXIN)
            ->whereIn('status', [MiniappBuild::STATUS_QUEUED, MiniappBuild::STATUS_BUILDING])
            ->exists();
        if ($inflight) {
            return response()->json([
                'success' => false,
                'message' => '已有构建任务进行中，请等待完成后再发起',
            ], 409);
        }

        $build = MiniappBuild::create([
            'tenant_id' => $tenantId,
            'platform' => MiniappBuild::PLATFORM_MP_WEIXIN,
            'channel' => MiniappBuild::CHANNEL_MANUAL,
            'status' => MiniappBuild::STATUS_QUEUED,
            'config_snapshot' => $this->config->snapshot($tenantId),
        ]);

        MiniappBuildJob::dispatch($build->build_id);

        return response()->json(['success' => true, 'data' => $this->toArray($build->refresh())], 201);
    }

    /**
     * 下载构建产物 zip（仅 success 态）
     *
     * 返回类型取 disk driver 行为并集：本地文件路径可解析 → BinaryFileResponse；
     * Storage::fake/远端 driver 无本地路径 → StreamedResponse（同 Response 体系）。
     */
    public function artifact(MiniappBuild $build): JsonResponse|BinaryFileResponse|StreamedResponse
    {
        // TenantScope 已按当前租户过滤：跨租户 build_id 在此 404（归属校验）
        if ($build->status !== MiniappBuild::STATUS_SUCCESS || $build->artifact_path === null) {
            return response()->json(['success' => false, 'message' => '构建尚未完成，无产物可下载'], 404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($build->artifact_path)) {
            return response()->json(['success' => false, 'message' => '构建产物文件缺失（可能已被清理）'], 404);
        }

        // 文件名仅由 DB 路径 basename 得出（禁拼用户输入）
        $filename = 'miniapp-build-' . $build->build_id . '.zip';

        return $disk->download($build->artifact_path, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    protected function toArray(MiniappBuild $build): array
    {
        return [
            'build_id' => (string) $build->build_id,
            'platform' => $build->platform,
            'channel' => $build->channel,
            'status' => $build->status,
            'config_snapshot' => $build->config_snapshot,
            'artifact_path' => $build->artifact_path,
            'error' => $build->error,
            'last_log' => $build->last_log,
            'started_at' => $build->started_at?->toDateTimeString(),
            'finished_at' => $build->finished_at?->toDateTimeString(),
            'created_at' => $build->created_at?->toDateTimeString(),
        ];
    }
}
