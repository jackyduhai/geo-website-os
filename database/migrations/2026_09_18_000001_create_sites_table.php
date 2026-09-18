<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 站点表 sites
 *
 * 职责：GEO Website OS 多站点基础层。
 * 每个 Site 拥有独立的 Entity / Content / Setting / Media / SEO 数据。
 * v1.0 单站点模式下仅存在一条 default site，未来多站点通过 domain 解析。
 *
 * 注意：本 migration 仅创建 sites 表并插入默认站点，
 * 不对现有业务表执行 site_id 迁移（属于 Phase 5.4）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('domain', 255)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('logo', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'slug']);
        });

        // 插入默认站点。这是迁移历史数据，不是 Core Runtime Business Logic。
        // 默认站点名称使用通用标识，不绑定任何具体业务。
        DB::table('sites')->insert([
            'id' => 1,
            'name' => 'Default Site',
            'slug' => 'default',
            'domain' => null,
            'description' => null,
            'logo' => null,
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
