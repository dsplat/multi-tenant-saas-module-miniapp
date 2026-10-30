<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Services;

use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatComponent\Models\Authorization;
use MultiTenantSaas\Modules\WechatComponent\Services\WechatComponentService;

/**
 * 小程序配置读写（打包构建业务；登录凭证/开关单写多读框架）
 *
 * 职责边界：
 * - 本服务只管「小程序打包构建业务」：common（名称/版本/API 域）、url_check（打包调试）。
 * - 登录凭证（appid/secret）与登录总闸（enabled）已上移框架：唯一写入口在框架
 *   「第三方登录 → 微信」页（oauth.wechat_miniapp_client_id/secret/enabled），本服务
 *   只读消费——appid 单写多读供打包（与登录凭证同源，杜绝双写漂移），enabled/secret
 *   仅只读展示（前端渲染只读 + 引导框架页）。
 *
 * 存储布局：
 * - 项目层 tenant_settings group='miniapp' key='common'：{name,version_name,version_code,api_domain}，明文。
 * - 项目层 group='miniapp' key='mp-weixin'：本服务只消费 {url_check}（打包调试）；历史
 *   appid/app_secret/enabled 已由框架 wechat:migrate-miniapp-credentials 迁至 oauth 组，
 *   本服务不再读写（saveMpWeixin 非破坏保留残留键，避免部署窗口回滚读空）。
 * - 框架 group='oauth'：wechat_miniapp_client_id/enabled（+ client_secret 加密）——只读消费。
 *
 * auth_mode 实时判定：wechat_authorizations 存在 mini_program+authorized → 'component'
 * （appid 取授权记录）；否则 → 'self'（appid 取框架 oauth.wechat_miniapp_client_id）。
 *
 * 安全：TenantSetting 静态方法显式按 tenant_id 查询绕过 TenantScope，调用方须保证租户访问权。
 */
class MiniappConfigService
{
    public const GROUP = 'miniapp';

    public const KEY_COMMON = 'common';

    public const KEY_MP_WEIXIN = 'mp-weixin';

    /** secret 回显掩码（只读展示框架登录凭证状态，绝不回显明文） */
    public const SECRET_MASK = '********';

    /** 品牌主色兜底（微信绿），租户未配 branding.primary_color 时用；须与前端 utils/color.ts 同值 */
    public const BRAND_PRIMARY_FALLBACK = '#07c160';

    /** common 白名单（构建 Job/前端展示消费键） */
    public const COMMON_KEYS = ['name', 'version_name', 'version_code', 'api_domain'];

    /**
     * mp-weixin 白名单：仅打包调试项。
     *
     * 登录凭证（appid/app_secret）与登录总闸（enabled）已上移框架，不再从项目层写入
     * （登录凭证唯一写入口在框架微信登录页）。
     */
    public const MP_WEIXIN_KEYS = ['url_check'];

    /**
     * 框架 oauth 组：小程序登录凭证/开关键（单写点在框架微信登录页，本服务只读多读）
     */
    private const FRAMEWORK_GROUP = 'oauth';

    private const FW_KEY_MINIAPP_APPID = 'wechat_miniapp_client_id';

    private const FW_KEY_MINIAPP_SECRET = 'wechat_miniapp_client_secret';

    private const FW_KEY_MINIAPP_ENABLED = 'wechat_miniapp_enabled';

    // ==================================================================
    // auth_mode 判定
    // ==================================================================

    /**
     * 判定小程序凭证形态：component（服务商授权）/ self（自建）
     */
    public function authMode(int $tenantId): string
    {
        return $this->miniProgramAuthorization($tenantId) !== null ? 'component' : 'self';
    }

    /**
     * 小程序授权记录（wechat_authorizations authorizer_type=mini_program + authorized）
     *
     * module-wechat-component 未安装/未迁移（class/表缺失）时返回 null 回退 self，
     * 不得抛 SQL 错误（对齐 WechatOAuthService::componentAuthorization 防护）。
     */
    public function miniProgramAuthorization(int $tenantId): ?Authorization
    {
        if (! class_exists(WechatComponentService::class) || ! Schema::hasTable('wechat_authorizations')) {
            return null;
        }

        $authorization = app(WechatComponentService::class)
            ->authorization($tenantId, Authorization::TYPE_MINI_PROGRAM);

        return $authorization !== null && $authorization->isAuthorized() ? $authorization : null;
    }

    /**
     * 小程序 appid（component=授权 appid / self=框架登录凭证 appid）
     *
     * 单写多读：self appid 读框架 oauth.wechat_miniapp_client_id（唯一写入口在框架
     * 微信登录页），打包构建与登录凭证同源，杜绝双写漂移。
     */
    public function appid(int $tenantId): string
    {
        $authorization = $this->miniProgramAuthorization($tenantId);
        if ($authorization !== null) {
            return (string) $authorization->authorizer_appid;
        }

        return (string) TenantSetting::get($tenantId, self::FRAMEWORK_GROUP, self::FW_KEY_MINIAPP_APPID, '');
    }

    /**
     * 小程序登录总闸（只读框架 oauth.wechat_miniapp_enabled；写入口在框架微信登录页）
     *
     * 登录与构建均以此为总闸：登录由框架 WechatMiniProgramService::isEnabled 消费同键，
     * 构建由 MiniappBuildController::store 前置校验消费，两者同源不脱节。
     */
    public function enabled(int $tenantId): bool
    {
        return (bool) TenantSetting::get($tenantId, self::FRAMEWORK_GROUP, self::FW_KEY_MINIAPP_ENABLED, false);
    }

    /**
     * self 形态框架登录 secret 是否已配置（只读展示掩码用，绝不回显明文）
     */
    public function secretConfigured(int $tenantId): bool
    {
        return (string) TenantSetting::get($tenantId, self::FRAMEWORK_GROUP, self::FW_KEY_MINIAPP_SECRET, '') !== '';
    }

    // ==================================================================
    // common 读写（打包参数，本模块自管）
    // ==================================================================

    public function common(int $tenantId): array
    {
        $stored = TenantSetting::get($tenantId, self::GROUP, self::KEY_COMMON, []);

        return array_merge([
            'name' => '',
            'version_name' => '',
            'version_code' => '',
            'api_domain' => '',
        ], is_array($stored) ? $stored : []);
    }

    public function saveCommon(int $tenantId, array $values): void
    {
        $merged = array_merge($this->common($tenantId), $this->filterKeys($values, self::COMMON_KEYS));
        TenantSetting::set($tenantId, self::GROUP, self::KEY_COMMON, $merged);
    }

    // ==================================================================
    // mp-weixin 读写（仅打包调试 url_check；登录凭证/开关已上移框架）
    // ==================================================================

    /**
     * mp-weixin 消费视图：仅返回打包调试项 url_check。
     *
     * 历史残留的 appid/app_secret/enabled 不在此视图返回（已迁框架，本服务不消费；
     * 且避免明文 secret 经 snapshot 落入构建产物/DB 快照）。
     */
    public function mpWeixin(int $tenantId): array
    {
        $stored = TenantSetting::get($tenantId, self::GROUP, self::KEY_MP_WEIXIN, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'url_check' => (bool) ($stored['url_check'] ?? false),
        ];
    }

    /**
     * 保存 mp-weixin 打包调试配置（白名单仅 url_check）。
     *
     * 非破坏：读原始存储（含迁移残留的 appid/secret/enabled）合并 url_check 后整键
     * 加密写回，残留键保留不清除（部署窗口内回滚旧代码仍可读，避免读空）；本服务
     * 消费视图 mpWeixin() 只取 url_check，残留键不参与任何业务。
     */
    public function saveMpWeixin(int $tenantId, array $values): void
    {
        $stored = TenantSetting::get($tenantId, self::GROUP, self::KEY_MP_WEIXIN, []);
        $stored = is_array($stored) ? $stored : [];
        $incoming = $this->filterKeys($values, self::MP_WEIXIN_KEYS);

        TenantSetting::set(
            $tenantId,
            self::GROUP,
            self::KEY_MP_WEIXIN,
            array_merge($stored, $incoming),
            true // 整键加密（保留历史残留 secret 的加密态）
        );
    }

    // ==================================================================
    // 组合视图（Controller/构建 Job 消费）
    // ==================================================================

    /**
     * GET 视图：登录凭证/开关只读展示（值取自框架），url_check 可编辑。
     *
     * mp_weixin.credential_source='framework' 标注来源，前端据此渲染只读 + 引导
     * 「第三方登录 → 微信」管理凭证与开关。
     */
    public function display(int $tenantId): array
    {
        $authMode = $this->authMode($tenantId);
        $authorization = $this->miniProgramAuthorization($tenantId);

        $data = [
            'auth_mode' => $authMode,
            'common' => $this->common($tenantId),
            'mp_weixin' => [
                // 登录总闸只读（框架 oauth.wechat_miniapp_enabled，与登录服务同源）
                'enabled' => $this->enabled($tenantId),
                // appid 只读：component=授权 appid / self=框架登录凭证 appid（单写多读）
                'appid' => $this->appid($tenantId),
                // self 形态展示框架 secret 是否已配（掩码，绝不回显明文）；component 无独立 secret
                'app_secret_masked' => $authMode === 'component' ? null : ($this->secretConfigured($tenantId) ? self::SECRET_MASK : null),
                // 打包调试，本模块可编辑
                'url_check' => (bool) ($this->mpWeixin($tenantId)['url_check'] ?? false),
                // 凭证/开关来源标注（前端只读 + 引导框架微信登录页）
                'credential_source' => 'framework',
            ],
        ];

        if ($authMode === 'component' && $authorization !== null) {
            $data['mp_weixin']['component'] = [
                'nickname' => (string) ($authorization->nickname ?? ''),
                'appid' => (string) $authorization->authorizer_appid,
                'authorized_at' => $authorization->authorized_at?->toDateTimeString(),
            ];
        }

        return $data;
    }

    /**
     * 品牌主色（构建期注入 VITE_BRAND_PRIMARY）
     *
     * 与 H5 前端同源：前端 resolveTenant 走 TenantResolveController，读的正是
     * tenants.branding.primary_color，小程序构建也取这一处，保证同一租户 H5 与小程序
     * 主题色一致。小程序无 DOM 不能运行时覆盖，只能构建期定死。仅接受 #RRGGBB，
     * 缺失/畸形回退兜底绿（与前端 utils/color.ts BRAND_PRIMARY_FALLBACK 同值），
     * 避免把非法色值注入产物。
     */
    public function brandPrimary(int $tenantId): string
    {
        $branding = Tenant::find($tenantId)?->branding;
        $color = trim((string) (is_array($branding) ? ($branding['primary_color'] ?? '') : ''));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : self::BRAND_PRIMARY_FALLBACK;
    }

    /**
     * 构建消费视角配置快照。
     *
     * appid 按形态解析（component=授权 / self=框架登录凭证）冗余进快照，产物与发起时
     * 一致；url_check 供打包调试；brand_primary 供小程序构建期注入主题色（无 DOM 不能
     * 运行时覆盖）。不携带 app_secret（构建不消费，登录已上移框架，快照不落明文 secret
     * 更安全）。
     */
    public function snapshot(int $tenantId): array
    {
        return [
            'auth_mode' => $this->authMode($tenantId),
            'common' => $this->common($tenantId),
            'mp_weixin' => $this->mpWeixin($tenantId),
            'appid' => $this->appid($tenantId),
            'brand_primary' => $this->brandPrimary($tenantId),
        ];
    }

    /**
     * 键白名单过滤（消费者即契约：仅落白名单键，未知键静默丢弃）
     */
    private function filterKeys(array $values, array $allowed): array
    {
        return array_intersect_key($values, array_flip($allowed));
    }
}
