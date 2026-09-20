<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 分组表 groups
 *
 * 职责：栏目内的二级归集。栏目可独立使用，分组可选。
 * 例：知识中心（栏目）→ 选型指南 / 工艺与配方 / 选型与应用（分组）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 120);                         // 栏目内唯一
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'slug']);
            $table->index(['category_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
