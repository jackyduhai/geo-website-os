<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-STEP 18H-2：inquiries 关联到来源 Submission / Form，并补 email 列。
 * --------------------------------------------------
 * Inquiry 自本阶段起是 FormSubmission 的业务投影（后台列表 / 跟进 / AuditLog
 * 向后兼容）：submission_id 唯一（一条提交至多投影一条留言），form_id 记录
 * 来源表单，email 承接默认表单的邮箱字段。历史 / 兼容路径产生的留言关联为空。
 *
 * 不形成反向事实源：完整数据以 form_submissions.payload 为准。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('form_id')->nullable()->index()->after('submission_id');
            $table->string('email', 120)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        /**
         * ⚠️ 必须用**显式索引名**，不能用 `dropUnique(['submission_id'])`（20G-8-B 实测）。
         *
         * 原因：Laravel 的 `unique()` 在 SQLite 下建出的是**命名索引**
         * `inquiries_submission_id_unique`，而 `dropUnique(['col'])` 会去找
         * SQLite 的表级唯一约束名 `sqlite_autoindex_*`——那是 SQLite 内部命名，
         * 无法按列名 DROP，于是回滚直接失败。
         *
         * 失败被 Laravel 的异常处理器吞掉（进程退出码仍是 0），导致
         * 「回滚链完整」表面通过、实际残留半个索引 —— 与 20G-6 · C-17
         * （menus.parent_key删错索引名）**同款缺陷**。
         *
         * 修法：显式 dropIndex 真实索引名，再dropColumn。
         * 且 dropIndex 必须**先于** dropColumn（列没了索引就无从引用）。
         */
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex('inquiries_submission_id_unique');
            $table->dropIndex('inquiries_form_id_index');
            $table->dropColumn(['submission_id', 'form_id', 'email']);
        });
    }
};
