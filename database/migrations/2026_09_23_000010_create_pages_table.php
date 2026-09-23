<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18G-1：创建组合页面表 pages。
 * --------------------------------------------------
 * Page = 页面实例（site / template / slug / is_home / title / status / locale /
 * translation_group）。不存业务事实。
 *
 * 唯一：(site_id, slug, locale)。SQLite 中 NULL 不参与唯一，故首页 slug 为 NULL
 * 时多行不冲突；Landing 的 slug 非空、每站每语言唯一。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('template', 40)->default('landing');
            $table->string('slug', 120)->nullable();
            $table->boolean('is_home')->default(false);
            $table->string('title', 160)->default('');
            $table->string('status', 20)->default('draft');
            $table->string('locale', 16)->default('zh-CN');
            $table->string('translation_group', 36)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'slug', 'locale'], 'pages_site_slug_locale_unique');
            $table->index(['site_id', 'status'], 'pages_site_status_index');
            $table->index('translation_group', 'pages_translation_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
