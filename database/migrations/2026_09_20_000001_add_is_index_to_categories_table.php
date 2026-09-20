<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 栏目「首页推荐」标记 is_index。
 *
 * 后台栏目表单（category-form）、列表开关（categories）与 CategoryController
 * 一直在读写 is_index，但建表迁移遗漏了该列，导致全新部署下提交「新建栏目」
 * 必然触发 PDOException：table categories has no column named is_index（HTTP 500）。
 * 本迁移仅补齐既有契约已经使用的列，不引入新的业务行为。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'is_index')) {
            return; // 幂等：开发库可能已手动加过列
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_index')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'is_index')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('is_index');
            });
        }
    }
};
