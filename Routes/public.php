<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Miniapp\Http\Controllers\MiniappAuthController;

// 小程序登录桥（公开组，前缀固定 api/v1 + ['api','throttle:30,1']，无认证；
// 租户解析走 api group 全局 IdentifyTenant 注入的 request attributes）
// 最终 URL 恒为 api/v1/auth/mp-weixin/login —— 不受 miniapp.route_prefix 影响。
Route::post('/auth/mp-weixin/login', [MiniappAuthController::class, 'login']);
