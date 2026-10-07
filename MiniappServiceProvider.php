<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\VerifyOperatorTenant;

/**
 * 小程序发布模块（框架侧标准件，物理归位自 scrm 项目层 Miniapp 模块）
 *
 * 微信小程序代码包发布：平台配置读写 / 构建任务生命周期 / 产物下载 / 公开登录桥。
 * 形态照抄 Course/Product/Membership：继承框架模块基类 ModuleServiceProvider，
 * 配置（Config/miniapp.php）由基类 mergeModuleConfig 自动合并，迁移由基类
 * loadModuleMigrations 加载。
 *
 * **唯一覆写**为 loadModuleRoutes()：双组加载且前缀口径与基类不同——
 * - 管理端组 Routes/api.php：前缀 = config('miniapp.route_prefix')（默认 api/v1）
 *   再拼 '/miniapp'，中间件沿用 scrm 既有口径（api + auth:sanctum +
 *   VerifyOperatorTenant + tenant.ensure）。下游设 MINIAPP_ROUTE_PREFIX=api/v1/biz
 *   即保持既有 /api/v1/biz/miniapp/* URL 零变更。
 * - 公开组 Routes/public.php：登录桥**固定 api/v1**（小程序入口路径不可变，
 *   不受 route_prefix 影响），中间件 ['api','throttle:30,1']，无认证。
 *
 * 故不能复用基类的 api/v1 写死前缀与全套中间件，须显式覆写（同 Membership 先例）。
 */
class MiniappServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'miniapp';

    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = $this->getModulePath();

        // 管理端组：前缀 = config('miniapp.route_prefix','api/v1') + '/miniapp'
        // （miniapp 段加在 api 组前缀里，路由文件内的 /config、、/builds 不变）
        $prefix = rtrim((string) config('miniapp.route_prefix', 'api/v1'), '/') . '/miniapp';

        $apiRoute = $moduleDir . '/Routes/api.php';
        if (file_exists($apiRoute)) {
            Route::middleware(['api', 'auth:sanctum', VerifyOperatorTenant::class, 'tenant.ensure'])
                ->prefix($prefix)
                ->group($apiRoute);
        }

        // 公开组：登录桥（小程序 wx.login code 换登录态），前缀固定 api/v1、无认证、限流 30/min
        $publicRoute = $moduleDir . '/Routes/public.php';
        if (file_exists($publicRoute)) {
            Route::middleware(['api', 'throttle:30,1'])
                ->prefix('api/v1')
                ->group($publicRoute);
        }
    }
}
