<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sites')) {
            return;
        }

        $defaultSiteId = DB::table('sites')->where('slug', 'default')->value('id') ?? 1;

        // ---- menus: rebuild to add site_id + convert unique(key) to unique(site_id, key) ----
        if (Schema::hasTable('menus')) {
            DB::statement('PRAGMA foreign_keys = OFF');

            // Create new table
            Schema::create('menus_new', function (Blueprint $table) {
                $table->id();
                $table->string('position')->default('main');
                $table->integer('parent_id')->nullable();
                $table->string('label');
                $table->string('url')->nullable();
                $table->integer('category_id')->nullable();
                $table->integer('target')->default(0);
                $table->integer('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->string('key')->nullable();
                $table->string('parent_key')->nullable();
                $table->foreignId('site_id')->constrained('sites')->onDelete('restrict');
            });

            // Copy data
            DB::statement("INSERT INTO menus_new (id, position, parent_id, label, url, category_id, target, sort, is_active, created_at, updated_at, key, parent_key, site_id)
                SELECT id, position, parent_id, label, url, category_id, target, sort, is_active, created_at, updated_at, key, parent_key, {$defaultSiteId} FROM menus");

            // Drop old table
            Schema::drop('menus');
            Schema::rename('menus_new', 'menus');

            // Recreate indexes
            DB::statement('CREATE UNIQUE INDEX menus_key_unique ON menus (site_id, key)');
            DB::statement('CREATE INDEX menus_parent_key_is_active_index ON menus (parent_key, is_active)');
            DB::statement('CREATE INDEX menus_position_is_active_sort_index ON menus (position, is_active, sort)');

            DB::statement('PRAGMA foreign_keys = ON');
        }

        // ---- redirects: rebuild to add site_id + convert unique(from_path) to unique(site_id, from_path) ----
        if (Schema::hasTable('redirects')) {
            DB::statement('PRAGMA foreign_keys = OFF');

            Schema::create('redirects_new', function (Blueprint $table) {
                $table->id();
                $table->string('from_path');
                $table->string('to_path');
                $table->integer('code')->default(301);
                $table->integer('hits')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->foreignId('site_id')->constrained('sites')->onDelete('restrict');
            });

            // Copy data (table is empty, but keep for safety)
            DB::statement("INSERT INTO redirects_new (id, from_path, to_path, code, hits, is_active, created_at, updated_at, site_id)
                SELECT id, from_path, to_path, code, hits, is_active, created_at, updated_at, {$defaultSiteId} FROM redirects");

            Schema::drop('redirects');
            Schema::rename('redirects_new', 'redirects');

            DB::statement('CREATE UNIQUE INDEX redirects_from_path_unique ON redirects (site_id, from_path)');

            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        // ---- rollback redirects ----
        if (Schema::hasTable('redirects')) {
            DB::statement('PRAGMA foreign_keys = OFF');

            Schema::create('redirects_old', function (Blueprint $table) {
                $table->id();
                $table->string('from_path');
                $table->string('to_path');
                $table->integer('code')->default(301);
                $table->integer('hits')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            DB::statement('INSERT INTO redirects_old (id, from_path, to_path, code, hits, is_active, created_at, updated_at)
                SELECT id, from_path, to_path, code, hits, is_active, created_at, updated_at FROM redirects');

            Schema::drop('redirects');
            Schema::rename('redirects_old', 'redirects');
            DB::statement('CREATE UNIQUE INDEX redirects_from_path_unique ON redirects (from_path)');

            DB::statement('PRAGMA foreign_keys = ON');
        }

        // ---- rollback menus ----
        if (Schema::hasTable('menus')) {
            DB::statement('PRAGMA foreign_keys = OFF');

            Schema::create('menus_old', function (Blueprint $table) {
                $table->id();
                $table->string('position')->default('main');
                $table->integer('parent_id')->nullable();
                $table->string('label');
                $table->string('url')->nullable();
                $table->integer('category_id')->nullable();
                $table->integer('target')->default(0);
                $table->integer('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->string('key')->nullable();
                $table->string('parent_key')->nullable();
            });

            DB::statement('INSERT INTO menus_old (id, position, parent_id, label, url, category_id, target, sort, is_active, created_at, updated_at, key, parent_key)
                SELECT id, position, parent_id, label, url, category_id, target, sort, is_active, created_at, updated_at, key, parent_key FROM menus');

            Schema::drop('menus');
            Schema::rename('menus_old', 'menus');

            DB::statement('CREATE UNIQUE INDEX menus_key_unique ON menus (key)');
            DB::statement('CREATE INDEX menus_parent_key_is_active_index ON menus (parent_key, is_active)');
            DB::statement('CREATE INDEX menus_position_is_active_sort_index ON menus (position, is_active, sort)');

            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
