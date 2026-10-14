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
         * ⚠️ 唯一索引必须用 **dropUnique（显式名）**，普通索引才用 dropIndex。
         *
         * 跨引擎语义不同（Phase 2D 实测）：
         *   - PostgreSQL：`->unique()` 建出的是唯一「约束」，其支撑索引不能用
         *     DROP INDEX 删除（2BP01，必须 DROP CONSTRAINT），故用 dropUnique。
         *   - MySQL：dropUnique 编译为 ALTER TABLE ... DROP INDEX，等价可用。
         *   - SQLite：dropUnique 内部委托 compileDropIndex（DROP INDEX），可用。
         *
         * 早期写法曾用 dropUnique(['submission_id'])（列数组，20G-8-B 实测失败）：
         * Laravel 在 SQLite 下按列名解析会去找表级 sqlite_autoindex_*，那是
         * SQLite 内部命名、无法按列名 DROP。因此这里用显式约束名字符串。
         *
         * 且必须**先于** dropColumn（列没了索引就无从引用）。
         */
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropUnique('inquiries_submission_id_unique');
            $table->dropIndex('inquiries_form_id_index');
            $table->dropColumn(['submission_id', 'form_id', 'email']);
        });
    }
};
