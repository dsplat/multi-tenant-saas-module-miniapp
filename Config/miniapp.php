<?php

declare(strict_types=1);

/**
 * 小程序发布模块配置（框架侧标准件，物理归位自 scrm 项目层 Miniapp 模块）
 *
 * 构建 Job 依赖服务器一次性环境（见 docs「服务器构建环境」）：
 * - workspace_path = 前端 h5 工程 rsync 快照（模板源）
 * - pnpm 全局安装；构建工作目录 {builds_root}/{tenant}/{build_id}
 *
 * 所有路径/命令均可用 .env 覆盖，供单测注入假 runner 时对齐。
 */

return [

    /*
    |--------------------------------------------------------------------------
    | 路由前缀（配置插口）
    |--------------------------------------------------------------------------
    | 与 Membership 同为物理归位模块，故路由前缀提供配置插口，让下游沿用既有
    | 路由命名空间。api 管理端组最终前缀 = 本值 + '/miniapp'：
    | - 框架默认 'api/v1'：/api/v1/miniapp/{config,builds,…}
    | - 下游（如 scrm）设 MINIAPP_ROUTE_PREFIX=api/v1/biz → /api/v1/biz/miniapp/* 零变更
    |
    | **公开组不受此配置影响**：登录桥 /auth/mp-weixin/login 固定 api/v1（小程序
    | 入口，路径不可变），见 MiniappServiceProvider::loadModuleRoutes()。
    */
    'route_prefix' => env('MINIAPP_ROUTE_PREFIX', 'api/v1'),

    // h5 工程 workspace（模板源，Job 内 rsync 至工作目录）
    'workspace_path' => env('MINIAPP_WORKSPACE_PATH', '/data/miniapp/workspace'),

    // 构建工作目录根（{tenant}/{build_id} 二级目录）
    'builds_root' => env('MINIAPP_BUILDS_ROOT', '/data/miniapp/builds'),

    // 构建产物目录根（storage/app/miniapp/{tenant}/{build_id}.zip）
    'artifact_root' => 'miniapp',

    // 可执行文件（服务器 npm i -g pnpm@11）
    'pnpm_bin' => env('MINIAPP_PNPM_BIN', 'pnpm'),
    'rsync_bin' => env('MINIAPP_RSYNC_BIN', 'rsync'),
    'zip_bin' => env('MINIAPP_ZIP_BIN', 'zip'),

    // 构建全局串行锁（同平台并发构建无意义，微信审核/发布有频率限制）
    'build_lock_seconds' => 1800,

    // Job 单次执行超时（pnpm install + 构建通常 2-5 分钟，首次 install 更久）
    'job_timeout_seconds' => 1800,

    // 失败保留的日志行数
    'log_tail_lines' => 200,
];
