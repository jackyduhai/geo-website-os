<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 系统层五张表：站点设置、301 跳转、对接日志、操作日志、内容版本
 *
 * settings 是「可自定义样式」的落点：主题色、字体、容器宽度、联系方式等
 * 全部以键值形式存放，后台改完即时生效，无需改代码。
 */
return new class extends Migration
{
    public function up(): void
    {
        $json = DB::connection()->getDriverName() === 'pgsql' ? 'jsonb' : 'json';

        // ---------- 站点设置 ----------
        Schema::create('settings', function (Blueprint $table) use ($json) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->{$json}('value')->nullable();
            $table->string('group', 40)->default('general');   // general | theme | contact | seo | geo | sync
            $table->string('label', 120)->nullable();          // 后台展示名
            $table->string('type', 20)->default('text');       // text | textarea | color | number | bool | json | image
            $table->text('hint')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['group', 'sort']);
        });

        // ---------- 301 / 302 跳转 ----------
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 255)->unique();
            $table->string('to_path', 255);
            $table->unsignedSmallInteger('code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---------- GEOFlow 对接日志 ----------
        Schema::create('sync_logs', function (Blueprint $table) use ($json) {
            $table->id();
            $table->string('direction', 20);                   // in 接收 | out 回写
            $table->string('action', 40)->nullable();          // create | update | skip | conflict | reject
            $table->string('external_id', 128)->nullable();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->string('status', 20)->default('ok');       // ok | warn | error
            $table->text('message')->nullable();
            $table->{$json}('payload')->nullable();            // 原始报文，便于排查
            $table->timestamps();

            $table->index(['direction', 'status']);
            $table->index('external_id');
            $table->index('created_at');
        });

        // ---------- 操作日志 ----------
        Schema::create('audit_logs', function (Blueprint $table) use ($json) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 60);                      // created | updated | published | deleted | login
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('summary', 255)->nullable();
            $table->{$json}('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        // ---------- 内容版本快照 ----------
        Schema::create('content_revisions', function (Blueprint $table) use ($json) {
            $table->id();
            $table->unsignedBigInteger('content_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note', 200)->nullable();
            $table->{$json}('snapshot');
            $table->timestamps();

            $table->index(['content_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('settings');
    }
};
