<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Miniapp\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 小程序发布构建记录
 *
 * 状态机：queued（建行入队）→ building（Job 开始）→ success/failed（终态）。
 * Job 运行在队列进程（无请求租户上下文），按 build_id 显式读取时
 * 需 TenantScope::allowUnscoped 豁免（见 MiniappBuildJob）。
 */
class MiniappBuild extends Model
{
    use BelongsToTenant;
    use HasGlobalId;
    use SerializesFriendlyDates;

    public const PLATFORM_MP_WEIXIN = 'mp-weixin';

    public const CHANNEL_MANUAL = 'manual';

    public const CHANNEL_COMPONENT = 'component';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_BUILDING = 'building';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_BUILDING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
    ];

    protected $table = 'miniapp_builds';

    protected $primaryKey = 'build_id';

    protected $fillable = [
        'tenant_id',
        'platform',
        'channel',
        'status',
        'config_snapshot',
        'artifact_path',
        'error',
        'last_log',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'config_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
