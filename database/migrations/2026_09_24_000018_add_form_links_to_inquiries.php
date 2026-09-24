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
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropUnique(['submission_id']);
            $table->dropColumn(['submission_id', 'form_id', 'email']);
        });
    }
};
