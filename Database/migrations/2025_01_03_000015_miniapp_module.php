<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 小程序发布构建记录（Miniapp 模块 / 框架侧标准件）
 *
 * 物理归位自 scrm `database/migrations/2025_01_03_000015_miniapp_module.php`，
 * 逐列照抄，迁移名沿用 scrm 既有 basename（存量库 migrations 表已有同名记录自动跳过）。
 *
 * - miniapp_builds：一次构建 = 一行。status 状态机：
 *   queued（建行即入队）→ building（Job 开始）→ success / failed（终态）
 * - config_snapshot 记录发起时的 {auth_mode, common, mp_weixin} 完整配置，
 *   Job 消费快照而非运行期配置（构建产物与发起时所见一致）
 * - artifact_path 仅存相对 storage/app 的 zip 路径，下载走 DB 路径（禁拼用户输入）
 * - 预留 channel='component'（服务商代发布，后续扩展）
 *
 * 幂等：先 Schema::hasTable 再 Schema::create —— 框架全新安装建表；scrm 存量库
 * 该表已存在则**跳过**（不重复建、不报错），hasTable 守卫是「生产表已存在不重建」的第二道保险。
 * 本表无交易金额列（build_id/tenant_id 为 ID，非金额），不涉金额单位口径。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('miniapp_builds')) {
            Schema::create('miniapp_builds', function (Blueprint $table) {
                $table->unsignedBigInteger('build_id')->primary()->comment('IdGenerator 全局ID');
                $table->unsignedBigInteger('tenant_id');
                $table->string('platform', 20)->default('mp-weixin')->comment('构建平台');
                $table->string('channel', 20)->default('manual')->comment('manual=手工构建 / component=服务商代发布(预留)');
                $table->string('status', 20)->default('queued')->comment('queued/building/success/failed');
                $table->json('config_snapshot')->nullable()->comment('构建时配置快照 {auth_mode, common, mp_weixin}');
                $table->string('artifact_path', 500)->nullable()->comment('zip 产物相对 storage/app 路径');
                $table->text('error')->nullable()->comment('失败原因');
                $table->text('last_log')->nullable()->comment('构建日志尾部（截断保留）');
                $table->timestamp('started_at')->nullable()->comment('Job 开始构建时间');
                $table->timestamp('finished_at')->nullable()->comment('success/failed 终态时间');
                $table->timestamps();

                $table->index(['tenant_id', 'platform', 'status']);
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('miniapp_builds');
    }
};
