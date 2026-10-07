<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Modules\Miniapp\Models\MiniappBuild;
use MultiTenantSaas\Modules\Miniapp\Services\MiniappBuildService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 小程序构建 Job（redis 默认队列，supervisor --queue=default,notify 已覆盖）
 *
 * - 全局串行：Cache::lock('miniapp:build') 持锁 30 分钟（一次只能有一个
 *   构建在跑——多构建并发无收益且微信侧有频率限制）；抢锁失败 release(30)
 *   重排（不消耗 tries）
 * - 队列进程无请求租户上下文：TenantScope fail-closed（WHERE 1=0）会拦截
 *   模型查询，构建记录显式 allowUnscoped 按 build_id 读取（租户在行内，
 *   行归属即权限，Job 由发起方 Controller 校验过租户）
 * - $timeout=1800 与锁时长一致；retryUntil 90 分钟防 worker --tries=3
 *   在长时间构建时丢任务（构建内部异常已在 Service 捕获转 failed，不会
 *   走到 worker 级重试）
 */
class MiniappBuildJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $buildId,
    ) {}

    public function handle(MiniappBuildService $service): void
    {
        $build = TenantScope::allowUnscoped(
            fn () => MiniappBuild::where('build_id', $this->buildId)->first()
        );

        // 构建记录被清理/不存在：静默结束（不重试，无重试价值）
        if ($build === null) {
            return;
        }

        $lock = Cache::lock('miniapp:build', (int) config('miniapp.build_lock_seconds', 1800));

        if (! $lock->get()) {
            // 有构建在跑：30 秒后重试（release 不消耗 tries）
            $this->release(30);

            return;
        }

        try {
            $service->run($build);
        } finally {
            $lock->release();
        }
    }

    /**
     * 超过 90 分钟放弃（worker --tries=3 下也只在极端场景触发）
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(90);
    }
}
