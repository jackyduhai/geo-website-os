<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * GEO Website OS 安装器（P-STEP 03：Site Bootstrap Contract）。
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
                            {--site-name= : 站点名称（默认取 APP_NAME / GEO Website OS）}
                            {--site-domain= : 站点域名（默认 null，走通用回退）}
                            {--admin-email= : 管理员邮箱（默认 admin@example.com）}
                            {--admin-password= : 管理员密码（缺省随机生成并打印一次）}';

    protected $description = 'Initialize a fresh GEO Website OS environment: migrate, bootstrap site, create admin, verify';

    public function handle(): int
    {
        $this->info('GEO Website OS install — start');

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

        // ---------- 3b. 公共存储软链 ----------
        // public 磁盘上传的媒体（封面/内联插图/logo）需经 public/storage 软链才能在
        // 前台访问；缺失时所有 /storage/... 上传文件返回 404。已存在则跳过，保证幂等。
        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
            $this->line('  [ok] storage link');
        }

        // ---------- 4. 站点创建（幂等，通用默认值） ----------
        $site = Site::firstOrCreate(
            ['slug' => Site::DEFAULT_SLUG],
            ['name' => config('app.name'), 'status' => 'active', 'metadata' => json_encode([])]
        );

        // TD-12：Site.name 是站点显示名唯一权威。未显式 --site-name 时收敛为产品名
        // APP_NAME，save() 会单向镜像 settings.site_name，避免空站残留迁移内置的
        // "Default Site" 与镜像名分叉；显式指定时使用自定义名并同样触发镜像。
        $name = trim((string) $this->option('site-name')) ?: (string) config('app.name');
        $domain = trim((string) $this->option('site-domain'));
        $site->name = $name;
        if ($domain !== '') {
            $site->domain = strtolower($domain);
        }
        $site->save();
        $this->line("  [ok] site: {$site->name} ({$site->slug})");

        // ---------- 4b. 产品级默认站点设置（七组完整字段 + 中性默认，幂等） ----------
        // 必须在默认站点创建之后调用，设置按当前站点（default）落 site_id；
        // 只装产品中性默认，不含任何演示行业数据（演示数据见 DemoSeeder，需显式 db:seed）。
        $this->call('db:seed', ['--class' => 'DefaultSettingSeeder', '--force' => true]);
        $this->line('  [ok] default settings');

        // ---------- 4c. 出厂中性首页（Blank System） ----------
        // 历史迁移播种的行业 Example 首页区块不属于出厂空站，清空后回落到行业中立
        // 欢迎屏；行业演示装修仅在显式 db:seed（DemoSeeder → StructureSeeder）时构建。
        $this->call('db:seed', ['--class' => 'BlankHomepageSeeder', '--force' => true]);
        $this->line('  [ok] blank homepage (industry-neutral)');

        // ---------- 4c-2. 出厂默认联系表单（中性 contact form） ----------
        // 必须在 SystemPageSeeder 之前：contact 页 FormReference 引用其 form_id；
        // 行业中性（name/phone/email/message），不含制造业字段。幂等。
        $this->call('db:seed', ['--class' => 'DefaultFormSeeder', '--force' => true]);
        $this->line('  [ok] default contact form (industry-neutral)');

        // ---------- 4d. 固定系统页（is_system Page） ----------
        // 为产品总览 / 场景总览 / 知识总览 / About / 工厂 / 合作 / 联系注入页面身份、
        // SEO 与模板绑定（行业中性、published）；业务事实仍来自正式数据层，Page 不复制。
        $this->call('db:seed', ['--class' => 'SystemPageSeeder', '--force' => true]);
        $this->line('  [ok] system pages (industry-neutral)');

        // ---------- 4d-2. 栏目骨架 / 导航可见性 / 语言开关（20G-7.1 · UX-002）----------
        // 与 SiteController@store 用同一个 Seeder —— 两条建站路径必须产出等价结构，
        // 否则「命令行装的站」与「后台建的站」能力不一致（实测 default 站曾 0 栏目）。
        $this->call('db:seed', ['--class' => 'SiteStructureSeeder', '--force' => true]);
        $this->line('  [ok] category skeleton + nav visibility + bilingual switch');

        // ---------- 4e. 搜索派生索引初始化 ----------
        // 索引是派生只读模型：安装结束全量重建一次，保证 fresh install 后搜索立即可用、
        // 与初始数据一致（空站为 0 条）。之后内容变更由增量同步 / dirty 懒重建维护。
        $this->call('search:reindex');
        $this->line('  [ok] search index built');

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

        $this->info('GEO Website OS install — complete');

        return self::SUCCESS;
    }
}
