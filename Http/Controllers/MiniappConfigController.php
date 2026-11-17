<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Miniapp\Services\MiniappConfigService;

/**
 * 小程序平台配置（tenant_settings group='miniapp' + 框架端发布例外表）
 *
 * GET 返回展示视图：登录凭证/开关只读展示（值取自框架 oauth 组；secret 掩码），
 * 外加 mp_publish（本租户已开通模块在小程序端的发布选择）。
 * PUT 白名单写入：
 * - common 四键（name/version_name/version_code/api_domain）打包参数可写
 * - mp_weixin：仅 url_check（打包调试）可写；appid/app_secret/enabled 属登录凭证/开关，
 *   已上移框架「第三方登录 → 微信」页统一管理，此处提交一律忽略（单写多读）。
 * - mp_publish：{模块名: 是否发布} 增量写入框架 tenant_module_platforms。选择性发布的
 *   事实源在框架而不在本模块，构建期与运行期都读它；这里只是配置台的写入口。
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

        if ($request->has('mp_publish')) {
            $published = $request->input('mp_publish');

            // 非对象形态显式失败，不退回空数组：那会让一次写错的请求静默"保存成功"，
            // 用户以为勾了开关、实际什么都没改。校验只到这里，值是不是布尔、模块在不在
            // 由 Service 判定并抛领域异常（DomainException 渲染 422 / NotFoundException 404）。
            if (! is_array($published)) {
                throw new DomainException('mp_publish 必须是 {模块名: 是否发布} 形态的对象');
            }

            $this->config->saveMpPublish($tenantId, $published);
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }
}
