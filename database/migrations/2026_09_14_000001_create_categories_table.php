<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 栏目表 categories
 *
 * 职责：内容的一级/多级分类，对应站点导航。
 * 与 groups 的区别：栏目管「内容归属哪一类」，分组管「这一类里再分哪几组」。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 80);
            $table->string('slug', 120)->unique();
            $table->string('type', 20)->default('list');        // list 列表 | single 单页 | product 产品
            $table->string('template', 40)->nullable();          // 渲染模板标识，留空走 type 默认模板
            $table->text('description')->nullable();             // 栏目导语，同时用于 meta description
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_nav')->default(true);            // 是否进主导航
            $table->boolean('is_active')->default(true);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_desc', 180)->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'sort']);
            $table->index(['is_nav', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
