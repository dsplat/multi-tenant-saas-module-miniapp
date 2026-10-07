<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Miniapp\Http\Controllers\MiniappBuildController;
use MultiTenantSaas\Modules\Miniapp\Http\Controllers\MiniappConfigController;

// 小程序发布后台组：前缀由 MiniappServiceProvider::loadModuleRoutes() 注入
// （config('miniapp.route_prefix') + '/miniapp'，如 scrm 的 api/v1/biz/miniapp，
// 全新安装默认 api/v1/miniapp）+ 后台中间件链 [api, auth:sanctum,
// VerifyOperatorTenant, tenant.ensure]。故此处路由路径不含 miniapp 段。
Route::get('/config', [MiniappConfigController::class, 'show']);
Route::put('/config', [MiniappConfigController::class, 'update']);

Route::get('/builds', [MiniappBuildController::class, 'index']);
Route::post('/builds', [MiniappBuildController::class, 'store']);
Route::get('/builds/{build}/artifact', [MiniappBuildController::class, 'artifact']);
