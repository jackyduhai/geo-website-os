<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SITE-07 — 站点 Logo / 品牌资源上传 E2E 防回归。
 * ------------------------------------------------------------------
 * 现有 ImageOptimizerTest 只覆盖压缩器本身；本测试经真实 HTTP 入口验证：
 *  - SiteController::storeUploadedLogo（store / update）：选文件 → 落盘 → 站点 logo
 *    持久化为带 /storage 前缀的公开路径；logo_remove 可清空；
 *  - 尺寸 / 类型非法的 logo 被表单校验拒绝，不产生站点；
 *  - SettingController::updateBrandAssets：file_geo_org_logo → Setting 持久化；
 *    clear_asset 可回退。
 */
class SiteBrandAssetUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed();
        $this->super = User::firstOrFail();
    }

    private function logo(int $w = 200, int $h = 100): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', $w, $h);
    }

    public function test_store_site_uploads_logo_and_persists_public_path(): void
    {
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'Logo Site',
            'domain' => 'logo.test',
            'status' => 'active',
            'logo_file' => $this->logo(),
        ])->assertRedirect();

        $site = Site::where('domain', 'logo.test')->firstOrFail();
        $this->assertStringStartsWith('/storage/sites/', $site->logo);
        Storage::disk('public')->assertExists(substr($site->logo, strlen('/storage/')));
    }

    public function test_update_site_replaces_logo(): void
    {
        $site = Site::create([
            'name' => 'X', 'slug' => 'x-site', 'domain' => 'x.test', 'status' => 'active',
        ]);

        $this->actingAs($this->super)->put(route('admin.sites.update', $site), [
            'name' => 'X', 'status' => 'active', 'domain' => 'x.test',
            'logo_file' => $this->logo(),
        ])->assertRedirect();

        $site->refresh();
        $this->assertStringStartsWith('/storage/sites/', $site->logo);
        Storage::disk('public')->assertExists(substr($site->logo, strlen('/storage/')));
    }

    public function test_update_site_can_remove_logo(): void
    {
        $site = Site::create([
            'name' => 'X', 'slug' => 'x2', 'domain' => 'x2.test', 'status' => 'active',
            'logo' => '/storage/sites/old.png',
        ]);

        $this->actingAs($this->super)->put(route('admin.sites.update', $site), [
            'name' => 'X', 'status' => 'active', 'domain' => 'x2.test',
            'logo_remove' => '1',
        ])->assertRedirect();

        $this->assertNull($site->refresh()->logo);
    }

    public function test_undersized_logo_is_rejected(): void
    {
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'Bad Logo', 'domain' => 'bad.test', 'status' => 'active',
            'logo_file' => $this->logo(32, 32),
        ])->assertSessionHasErrors('logo_file');

        $this->assertNull(Site::where('domain', 'bad.test')->first());
    }

    public function test_non_image_logo_is_rejected(): void
    {
        $this->actingAs($this->super)->post(route('admin.sites.store'), [
            'name' => 'Bad Logo2', 'domain' => 'bad2.test', 'status' => 'active',
            'logo_file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('logo_file');
    }

    public function test_brand_assets_upload_persists_logo_setting(): void
    {
        $this->actingAs($this->super)->post(route('admin.settings.brand-assets'), [
            'file_geo_org_logo' => $this->logo(),
        ])->assertRedirect();

        $val = Setting::get('geo_org_logo');
        $this->assertStringStartsWith('/storage/settings/', $val);
        Storage::disk('public')->assertExists(substr($val, strlen('/storage/')));
    }

    public function test_brand_assets_clear_resets_setting(): void
    {
        Setting::set('geo_org_logo', '/storage/settings/old.png');

        $this->actingAs($this->super)->post(route('admin.settings.brand-assets'), [
            'clear_asset' => ['geo_org_logo'],
        ])->assertRedirect();

        $this->assertSame('', Setting::get('geo_org_logo'));
    }
}
