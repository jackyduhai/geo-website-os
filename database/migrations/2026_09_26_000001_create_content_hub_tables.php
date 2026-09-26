<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18R-2c Content Hub Lite。
 * 三个最小 site-scoped 表，全部幂等可回滚：
 *   - tags            扁平标签（站点隔离；与层级栏目 Category 正交，不进 GEO 图）
 *   - content_tag     Content ↔ Tag 多对多
 *   - content_entity  Content ↔ Entity 类型化关系（about/mention；让文章成为
 *                     "关于某产品/行业/场景"的知识资产，反向可查"哪些文章讲产品 X"）
 * 权威数据仍是 contents / entities；本迁移不引入第二套内容/知识体系。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('name', 80);
                $table->string('slug', 100);
                $table->timestamps();
                $table->unique(['site_id', 'slug'], 'tags_site_slug_unique');
            });
        }

        if (! Schema::hasTable('content_tag')) {
            Schema::create('content_tag', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('content_id');
                $table->unsignedBigInteger('tag_id');
                $table->timestamps();
                $table->unique(['content_id', 'tag_id'], 'content_tag_unique');
                $table->index('tag_id', 'content_tag_tag_idx');
            });
        }

        if (! Schema::hasTable('content_entity')) {
            Schema::create('content_entity', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('content_id');
                $table->unsignedBigInteger('entity_id');
                $table->string('relation_type', 30)->default('mention'); // about / mention
                $table->timestamps();
                $table->unique(
                    ['content_id', 'entity_id', 'relation_type'],
                    'content_entity_unique'
                );
                $table->index(['entity_id', 'relation_type'], 'content_entity_entity_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_entity');
        Schema::dropIfExists('content_tag');
        Schema::dropIfExists('tags');
    }
};
