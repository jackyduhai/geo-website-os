<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P-STEP 03：Site Bootstrap Contract。
 *
 * php artisan geo:install 从空环境引导可运行站点：
 *   环境检查 → 数据库检查 → 迁移 → 站点创建 → 管理员初始化 → 引导自检
 *
 * 契约：全部默认值为系统级通用值；超管授权走 is_super_admin 显式标记
 * （不绑定任何特定邮箱）；幂等可重复执行。
 *
 * 注：测试环境由 RefreshDatabase 先行迁移，本用例验证安装器在已迁移
 * 库上的完整引导与幂等性；「零字节 DB 文件 → install」的端到端安装
 * 在 P-STEP 08/10 用 Release Artifact 于独立目录实测。
 */
class GeoInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_bootstraps_site_and_admin_with_generic_defaults(): void
    {
        DB::table('users')->delete();

        $this->artisan('geo:install')->assertExitCode(0);

        // 默认站点存在，未被塞入业务信息
        $site = DB::table('sites')->where('slug', 'default')->first();
        $this->assertNotNull($site);
        $this->assertSame('Default Site', $site->name);

        // 管理员以通用默认邮箱创建，并持有超管标记
        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_super_admin);

        // 授权走标记而非邮箱
        $this->assertTrue(\App\Support\SystemAuthorization::canCrossSite($admin));

        // 引导后首页可用且 SEO head 完整
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="', $html);
    }

    public function test_install_is_idempotent_and_applies_site_options(): void
    {
        $this->artisan('geo:install', [
            '--site-name' => 'Example Site',
            '--site-domain' => 'Example.ORG',
            '--admin-email' => 'owner@example.org',
            '--admin-password' => 'secret-password-123',
        ])->assertExitCode(0);

        $site = DB::table('sites')->where('slug', 'default')->first();
        $this->assertSame('Example Site', $site->name);
        $this->assertSame('example.org', $site->domain);

        $admin = User::where('email', 'owner@example.org')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_super_admin);

        // 幂等重跑：无副作用
        $this->artisan('geo:install')->assertExitCode(0);
        $this->assertSame(1, DB::table('sites')->count());
        $this->assertSame(1, User::where('email', 'owner@example.org')->count());
    }

    public function test_no_business_defaults_in_install_paths(): void
    {
        $installer = file_get_contents(app_path('Console/Commands/GeoInstall.php'));
        $migration = file_get_contents(database_path('migrations/2026_09_18_000001_create_sites_table.php'));

        foreach (['demo-tenant-a', 'demo-tenant-a.local', 'Demo Tenant A@', 'Demo Tenant A', 'Sample Snack', 'Sample Marinade', 'Sample City'] as $kw) {
            $this->assertStringNotContainsString($kw, $installer, "安装器含业务默认值: {$kw}");
            $this->assertStringNotContainsString($kw, $migration, "建站迁移含业务默认值: {$kw}");
        }
    }
}
