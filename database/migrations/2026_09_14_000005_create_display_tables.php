<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 展示层四张表：媒体、Banner、导航菜单、首页区块
 *
 * 对应「官网可脱离 GEOFlow 独立运维」中的可自定义部分：
 * 运营在后台增删 Banner、调整首页区块顺序、改导航，均不需要动代码。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- 媒体库 ----------
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 40)->default('public');
            $table->string('path', 255);
            $table->string('original_name', 200)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt', 200)->nullable();          // 无障碍与图片检索都依赖 alt
            $table->string('title', 200)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('mime');
        });

        // ---------- Banner 轮播 ----------
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('position', 40)->default('home_top');  // home_top | category_top | ...
            $table->string('title', 120)->nullable();
            $table->string('subtitle', 200)->nullable();
            $table->string('link', 255)->nullable();
            $table->string('link_text', 40)->nullable();          // CTA 文案
            $table->unsignedBigInteger('image_id')->nullable();
            $table->tinyInteger('target')->default(0);            // 0 同窗 | 1 新窗
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->index(['position', 'is_active', 'sort']);
        });

        // ---------- 导航菜单 ----------
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('position', 40)->default('main');      // main | footer | mobile
            $table->unsignedBigInteger('parent_id')->nullable();  // 父=自定义一级菜单
            $table->string('parent_key', 80)->nullable();         // 父=固定一级栏目（蓝图 key）
            $table->string('label', 60);
            $table->string('url', 255)->nullable();               // 与 category_id 二选一
            $table->unsignedBigInteger('category_id')->nullable();
            $table->tinyInteger('target')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['position', 'is_active', 'sort']);
            $table->index(['parent_key', 'is_active']);
        });

        // ---------- 首页区块（可排序、可开关） ----------
        Schema::create('page_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('page', 40)->default('home');          // 归属页面标识
            $table->string('type', 40);                           // hero | facts | products | knowledge | news | cta | custom
            $table->string('title', 120)->nullable();
            $table->string('subtitle', 200)->nullable();
            $table->text('content')->nullable();                  // 自定义区块正文
            $table->unsignedBigInteger('category_id')->nullable(); // 数据来源栏目
            $table->unsignedInteger('limit')->default(6);         // 取几条
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['page', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_blocks');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('media');
    }
};
