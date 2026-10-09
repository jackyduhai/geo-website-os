<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-4 Tombstone Registry（身份基础设施，先于 WP-3）：
 *  - Entity force-delete（physical purge）时写入一条窄面 append-only 墓碑，
 *    保留原实体 ID / 站点 / 类型 / slug / name / role / 清除时间，
 *    供语义引用区分「KNOWN-DELETED（曾存在，已清除）」与「UNKNOWN-ID（从未登记）」。
 *  - 墓碑仅作身份判定：不复活实体、不进入 PKS、不充当发现面。
 *  - (site_id, entity_id) 唯一：同一原实体 ID 只可能有一条墓碑，DB 级保证幂等。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_tombstones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('entity_id'); // 原实体 ID（默认语言锚点）
            $table->string('type', 32);
            $table->string('slug');
            $table->string('name')->nullable();
            $table->string('role', 32)->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->unsignedBigInteger('purged_by')->nullable();
            $table->timestamps();

            // 跨站隔离查询入口 + 原实体 ID 唯一（幂等，重复 purge 不产生第二条）。
            $table->unique(['site_id', 'entity_id'], 'entity_tombstones_site_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_tombstones');
    }
};
