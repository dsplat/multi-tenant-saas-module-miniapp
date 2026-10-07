<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Miniapp\Services\MiniappConfigService;

/**
 * 小程序平台配置（tenant_settings group='miniapp'）
 *
 * GET 返回展示视图：登录凭证/开关只读展示（值取自框架 oauth 组；secret 掩码）。
 * PUT 白名单写入：
 * - common 四键（name/version_name/version_code/api_domain）打包参数可写
 * - mp_weixin：仅 url_check（打包调试）可写；appid/app_secret/enabled 属登录凭证/开关，
 *   已上移框架「第三方登录 → 微信」页统一管理，此处提交一律忽略（单写多读）。
 */
class MiniappConfigController extends BaseController
{
    public function __construct(
        protected MiniappConfigService $config,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json(['success' => true, 'data' => $this->config->display($tenantId)]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        if ($request->has('common')) {
            $this->config->saveCommon($tenantId, $request->input('common', []));
        }

        if ($request->has('mp_weixin')) {
            // 仅打包调试项 url_check 可写（白名单在 Service 层）；登录凭证 appid/secret
            // 与登录总闸 enabled 已上移框架「第三方登录 → 微信」页，此处提交一律忽略。
            $this->config->saveMpWeixin($tenantId, $request->input('mp_weixin', []));
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }
}
