<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * GEO OS 安装器（P-STEP 03：Site Bootstrap Contract）。
 *
 * 从空环境引导出可运行的站点：
 *
 *   环境检查 → 数据库检查 → 迁移 → 站点创建 → 管理员初始化 → 引导自检
 *
 * 契约：
 *   - 全部默认值为系统级通用值（Default Site / Example Site / admin@example.com），
 *     不含任何业务专属信息；
 *   - 幂等：可重复执行（updateOrCreate）；
 *   - 安装的首个管理员获得 is_super_admin 标记（系统级授权唯一入口）；
 *   - 不装载任何业务演示数据（Demo 数据见 DemoSeeder，显式调用）。
 */
class GeoInstall extends Command
{
    protected $signature = 'geo:install
                            {--site-name= : 站点名称（默认 Default Site）}
                            {--site-domain= : 站点域名（默认 null，走通用回退）}
                            {--admin-email= : 管理员邮箱（默认 admin@example.com）}
                            {--admin-password= : 管理员密码（缺省随机生成并打印一次）}';

    protected $description = 'Initialize a fresh GEO OS environment: migrate, bootstrap site, create admin, verify';

    public function handle(): int
    {
        $this->info('GEO OS install — start');

        // ---------- 1. 环境检查 ----------
        if (version_compare(PHP_VERSION, '8.2', '<')) {
            $this->error('PHP >= 8.2 required, got ' . PHP_VERSION);

            return self::FAILURE;
        }
        foreach (['pdo_sqlite', 'pdo_mysql', 'pdo_pgsql'] as $driver) {
            if (extension_loaded($driver)) {
                $this->line("  [ok] extension {$driver}");
                break;
            }
        }
        if (trim((string) config('app.key')) === '') {
            $this->error('APP_KEY is missing. Run: php artisan key:generate');

            return self::FAILURE;
        }
        try {
            encrypt('geo:install-check');
        } catch (\Throwable $e) {
            $this->error('APP_KEY is invalid (' . $e->getMessage() . '). Run: php artisan key:generate');

            return self::FAILURE;
        }
        $this->line('  [ok] environment');

        // ---------- 2. 数据库检查 ----------
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            $this->error('Database unreachable: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->line('  [ok] database connection');

        // ---------- 3. 迁移 ----------
        $this->call('migrate', ['--force' => true]);
        $this->line('  [ok] migrations');

        // ---------- 4. 站点创建（幂等，通用默认值） ----------
        $site = Site::firstOrCreate(
            ['slug' => Site::DEFAULT_SLUG],
            ['name' => 'Default Site', 'status' => 'active', 'metadata' => json_encode([])]
        );

        $name = trim((string) $this->option('site-name'));
        $domain = trim((string) $this->option('site-domain'));
        $site->fill(array_filter([
            'name' => $name !== '' ? $name : null,
            'domain' => $domain !== '' ? strtolower($domain) : null,
        ], fn ($v) => $v !== null))->save();
        $this->line("  [ok] site: {$site->name} ({$site->slug})");

        // ---------- 5. 管理员初始化 ----------
        $email = trim((string) $this->option('admin-email')) ?: 'admin@example.com';
        $password = (string) $this->option('admin-password');
        $generated = false;
        if ($password === '') {
            $password = substr(base64_encode(random_bytes(18)), 0, 22);
            $generated = true;
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Administrator',
                'password' => Hash::make($password),
                'is_super_admin' => true,
            ]
        );
        $this->line("  [ok] admin: {$admin->email}");
        if ($generated) {
            $this->warn("  generated password (shown once): {$password}");
        }

        // ---------- 6. 引导自检 ----------
        $tablesOk = DB::getSchemaBuilder()->hasTable('sites')
            && DB::getSchemaBuilder()->hasTable('contents')
            && DB::getSchemaBuilder()->hasTable('seo_metas');
        if (! $tablesOk) {
            $this->error('Bootstrap verification failed: core tables missing');

            return self::FAILURE;
        }
        if (Site::where('slug', Site::DEFAULT_SLUG)->doesntExist()) {
            $this->error('Bootstrap verification failed: default site missing');

            return self::FAILURE;
        }

        $this->info('GEO OS install — complete');

        return self::SUCCESS;
    }
}
