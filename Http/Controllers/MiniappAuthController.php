<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Wechat\Services\WechatMiniProgramService;

/**
 * 小程序登录桥（公开端点，无认证）
 *
 * 端点路径 POST /auth/mp-weixin/login 保持不变（小程序客户端零改动；最终 URL
 * api/v1/auth/mp-weixin/login，由 MiniappServiceProvider 公开组前缀注入）；凭证使用
 * 已上移框架——内部委托框架 WechatMiniProgramService（读框架 oauth 组小程序登录
 * 凭证/开关，jscode2session self/component 双 driver），本模块不自实现。
 *
 * 租户解析与框架 UserAuthController::smsLogin 同款：api group 的 IdentifyTenant 全局
 * 中间件按 Host/Header 注入 $request->attributes->get('tenant_id')；小程序
 * 请求 Host 为租户 API 域，天然归因。
 *
 * 业务错误（未配置/微信 errcode/登录失败）→ 400 透传文案（DomainException
 * 由框架 Service 抛出，此处统一转译；不属 500 服务故障）。
 */
class MiniappAuthController extends BaseController
{
    public function __construct(
        protected WechatMiniProgramService $loginService,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:255',
            'nickname' => 'nullable|string|max:64',
            'avatar' => 'nullable|string|max:500',
        ]);

        $tenantId = (int) ($request->attributes->get('tenant_id') ?? 0);
        if ($tenantId <= 0) {
            return response()->json([
                'success' => false,
                'message' => '无法识别租户，请通过租户域名访问',
            ], 400);
        }

        try {
            $result = $this->loginService->login(
                $tenantId,
                (string) $request->input('code'),
                $request->input('nickname'),
                $request->input('avatar'),
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        // 壳账号（无已验证联系方式）：签发 pending token，引导绑定/合并
        if (! empty($result['needs_bindcontact'])) {
            return response()->json([
                'success' => true,
                'data' => [
                    'needs_bindcontact' => true,
                    'pending_token' => $result['pending_token'],
                ],
            ]);
        }

        /** @var User $user */
        $user = $result['user'];

        return response()->json([
            'success' => true,
            'data' => [
                'needs_bindcontact' => false,
                'user' => $this->userToArray($user),
                'tenant_id' => $tenantId,
                'auth_token' => $result['auth_token'],
            ],
        ]);
    }

    /**
     * 用户信息结构对齐框架 BuildsUserTokenResponse / WechatMiniProgramAuthController::userToArray
     * （前端登录存储共用口径）
     *
     * 身份模型铁律：User 永不持有角色（tenant_users 无 role 列，RBAC 只作用于
     * Operator）。旧实现此处读 tenant_users.role 恒为 null，却向前台输出「User 有 role」
     * 的假信号，物理归位时与框架同名逻辑一并移除（依赖走套餐/功能开关，不走角色）。
     */
    protected function userToArray(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'email_verified' => ! empty($user->email_verified_at),
        ];
    }
}
