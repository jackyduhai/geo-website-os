<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use App\Models\Setting;
use App\Support\Audit\AuditSnapshot;
use App\Support\PageCache;
use App\Support\SiteContext;
use Database\Seeders\DefaultFormSeeder;
use Database\Seeders\DefaultSettingSeeder;
use Database\Seeders\SystemPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P-STEP 18H-3 — AuditLog 覆盖 + 审计安全快照 防回归。
 *
 * 旧实现 AuditLog 有 record() 但 detail 多为空、无 before/after，且
 * Entity / SeoMeta / Page(+Block) / Form / Theme / Plugin 无任何审计点。
 * 本测试逐资源验证「管理操作 → 带 before/after 的审计」：
 *   A) Settings 更新（仅真正变化字段，detail.changes）；
 *   B) Entity store / update（recordChange，敏感无关的 name/summary）；
 *   C) SeoMeta store / update；
 *   D) Page store / update；
 *   E) Block store / update / destroy；
 *   F) Form store / field store / update；
 *   G) Theme activate；Plugin enable / disable；
 *   H) AuditSnapshot：敏感字段 [REDACTED]、数组归一化、无变化返回 []；
 *   I) 前台表单提交不产生含 payload 的管理员审计。
 *
 * 边界：AuditLog（谁在何时做了什么）≠ Revision（可恢复版本，TD-75 v1.1）。
 */
class AuditCoverage18H3Test extends TestCase
{
    use RefreshDatabase;

    private Site $default;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        $this->default = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        SiteContext::setSite($this->default);
        $this->seed([
            DefaultSettingSeeder::class,
            DefaultFormSeeder::class,
            SystemPageSeeder::class,
        ]);
        $this->admin = User::create([
            'name' => '管理员', 'email' => 'admin@example.test',
            'password' => bcrypt('secret123'), 'is_super_admin' => true,
        ]);
        PageCache::flush();
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
    }

    private function latestAudit(string $action): AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->firstOrFail();
    }

    // ---------- A) Settings ----------

    public function test_setting_update_writes_audit_with_changes(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update', 'analytics'), [
                'analytics_ga4_enabled' => 1, 'analytics_ga4_id' => 'G-AUDIT1234',
                'analytics_gtm_enabled' => 0, 'analytics_gtm_id' => '',
                'analytics_meta_enabled' => 0, 'analytics_meta_id' => '',
                'analytics_consent_required' => 1,
            ])->assertRedirect();

        $log = $this->latestAudit('settings.update');
        $changes = $log->detail['changes'];
        $this->assertSame('G-AUDIT1234', $changes['after']['analytics_ga4_id']);
        $this->assertArrayHasKey('before', $changes);
    }

    // ---------- B) Entity ----------

    public function test_entity_store_and_update_write_audit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.entities.store'), [
                'type' => 'product', 'name' => 'A Product', 'slug' => 'a-product',
                'summary' => 'orig summary', 'description' => '',
                'status' => 'published', 'sort_order' => 0,
            ])->assertRedirect();

        // entity.store 审计存在（firstOrFail 即通过），summary 含实体名。
        $this->assertStringContainsString('A Product', $this->latestAudit('entity.store')->summary);

        $entity = \App\Models\Entity::where('slug', 'a-product')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.entities.update', $entity), [
                'type' => 'product',
                'name' => 'Renamed Product', 'slug' => 'a-product',
                'summary' => 'updated summary', 'description' => '',
                'status' => 'published', 'sort_order' => 0,
            ])->assertSessionHasNoErrors();

        $log = $this->latestAudit('entity.update');
        $changes = $log->detail['changes'];
        $this->assertSame('A Product', $changes['before']['name']);
        $this->assertSame('Renamed Product', $changes['after']['name']);
        $this->assertSame('orig summary', $changes['before']['summary']);
    }

    // ---------- C) SeoMeta ----------

    public function test_seo_meta_store_and_update_write_audit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.seo-metas.store'), [
                'scope' => 'site', 'locale' => 'zh-CN',
                'title' => 'SITE TITLE', 'description' => 'SITE DESC',
                'og_type' => 'website', 'twitter_card' => 'summary_large_image',
            ])->assertRedirect();

        $this->assertSame('seo_meta.store', $this->latestAudit('seo_meta.store')->action);

        $seo = \App\Models\SeoMeta::whereNull('content_id')->whereNull('entity_id')
            ->whereNull('page_id')->where('locale', 'zh-CN')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.seo-metas.update', $seo), [
                'locale' => 'zh-CN', 'title' => 'SITE TITLE 2', 'description' => 'SITE DESC',
                'og_type' => 'website', 'twitter_card' => 'summary_large_image',
            ])->assertRedirect();

        $changes = $this->latestAudit('seo_meta.update')->detail['changes'];
        $this->assertSame('SITE TITLE', $changes['before']['title']);
        $this->assertSame('SITE TITLE 2', $changes['after']['title']);
    }

    // ---------- D) Page ----------

    public function test_page_store_and_update_write_audit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.pages.store'), [
                'template' => 'landing', 'locale' => 'zh-CN',
                'title' => 'Audit Page', 'slug' => 'audit-page', 'status' => 'draft',
            ])->assertRedirect();

        $page = \App\Models\Page::where('slug', 'audit-page')->firstOrFail();
        $this->assertSame('page.store', $this->latestAudit('page.store')->action);

        $this->actingAs($this->admin)
            ->put(route('admin.pages.update', $page), [
                'template' => 'landing', 'title' => 'Audit Page Renamed',
                'slug' => 'audit-page', 'status' => 'published',
            ])->assertRedirect();

        $changes = $this->latestAudit('page.update')->detail['changes'];
        $this->assertSame('Audit Page', $changes['before']['title']);
        $this->assertSame('Audit Page Renamed', $changes['after']['title']);
    }

    // ---------- E) Block ----------

    public function test_block_store_update_destroy_write_audit(): void
    {
        $page = \App\Models\Page::create([
            'site_id' => $this->default->id, 'template' => 'landing',
            'slug' => 'block-audit-' . uniqid(), 'title' => 'Block Audit',
            'status' => 'published', 'locale' => 'zh-CN',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.pages.storeBlock', $page), [
                'type' => 'rich_text', 'slot' => 'main',
            ])->assertRedirect();

        $block = \App\Models\PageBlock::where('page_id', $page->id)->firstOrFail();
        $this->assertSame('block.store', $this->latestAudit('block.store')->action);

        $this->actingAs($this->admin)
            ->put(route('admin.pages.updateBlock', [$page, $block]), [
                'field' => ['title' => 'BLOCK TITLE', 'body' => 'BLOCK BODY'],
            ])->assertRedirect();

        $changes = $this->latestAudit('block.update')->detail['changes'];
        $this->assertArrayHasKey('before', $changes);
        $this->assertStringContainsString('BLOCK TITLE', $changes['after']['content']);

        $this->actingAs($this->admin)
            ->delete(route('admin.pages.destroyBlock', [$page, $block]))
            ->assertRedirect();
        $this->assertSame('block.destroy', $this->latestAudit('block.destroy')->action);
    }

    // ---------- F) Form + Field ----------

    public function test_form_store_field_and_update_write_audit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.forms.store'), [
                'name' => 'Demo Request', 'slug' => 'demo-request',
                'title' => '申请演示', 'status' => 'enabled',
                'consent_required' => 0, 'honeypot_enabled' => 1,
                'notification_enabled' => 0, 'notification_channels' => 'email',
                'notification_recipients' => '', 'success_message' => '',
            ])->assertRedirect();

        $form = \App\Models\Form::where('slug', 'demo-request')->firstOrFail();
        $this->assertSame('form.store', $this->latestAudit('form.store')->action);

        $this->actingAs($this->admin)
            ->post(route('admin.forms.fields.store', $form), [
                'type' => 'text', 'name' => 'company', 'required' => 0, 'validation' => '',
                'translations' => [
                    'zh-CN' => ['label' => '公司', 'placeholder' => '', 'help_text' => '', 'options' => ''],
                    'en' => ['label' => 'Company', 'placeholder' => '', 'help_text' => '', 'options' => ''],
                ],
            ])->assertRedirect();

        $this->assertSame('form_field.store', $this->latestAudit('form_field.store')->action);

        $this->actingAs($this->admin)
            ->put(route('admin.forms.update', $form), [
                'name' => 'Demo Request 2', 'slug' => 'demo-request',
                'title' => '申请演示', 'status' => 'enabled',
                'consent_required' => 0, 'honeypot_enabled' => 1,
                'notification_enabled' => 0, 'notification_channels' => 'email',
                'notification_recipients' => '', 'success_message' => '',
            ])->assertSessionHasNoErrors();

        $changes = $this->latestAudit('form.update')->detail['changes'];
        $this->assertSame('Demo Request', $changes['before']['name']);
        $this->assertSame('Demo Request 2', $changes['after']['name']);
    }

    // ---------- G) Theme / Plugin ----------

    public function test_theme_activate_writes_audit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.themes.activate', 'default'))
            ->assertSessionHasNoErrors();

        $this->assertSame('theme.activate', $this->latestAudit('theme.activate')->action);
    }

    public function test_plugin_enable_disable_write_audit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.plugins.enable', 'hello'))
            ->assertSessionHasNoErrors();
        $this->assertSame('plugin.enable', $this->latestAudit('plugin.enable')->action);

        $this->actingAs($this->admin)
            ->post(route('admin.plugins.disable', 'hello'))
            ->assertSessionHasNoErrors();
        $this->assertSame('plugin.disable', $this->latestAudit('plugin.disable')->action);
    }

    // ---------- H) AuditSnapshot ----------

    public function test_snapshot_redacts_sensitive_and_normalizes(): void
    {
        // 敏感字段脱敏后新旧值相同会被 changes 判为无变化（审计不展示敏感值变化细节），
        // 故用「无 → 有敏感值」验证脱敏；非敏感字段 name 正常记录前后值。
        $c = AuditSnapshot::changes(
            ['api_token' => null, 'name' => 'x'],
            ['api_token' => 'BBBSECRET', 'name' => 'y']
        );

        $this->assertNull($c['before']['api_token']);
        $this->assertSame('[REDACTED]', $c['after']['api_token']);
        $this->assertSame('x', $c['before']['name']);
        $this->assertSame('y', $c['after']['name']);
    }

    public function test_snapshot_returns_empty_when_no_change(): void
    {
        $this->assertSame([], AuditSnapshot::changes(['a' => '1'], ['a' => '1']));
    }

    public function test_snapshot_normalizes_arrays(): void
    {
        $c = AuditSnapshot::changes(
            ['metadata' => ['k' => 1]],
            ['metadata' => ['k' => 2, 'j' => 3]]
        );
        $this->assertSame('[array:1]', $c['before']['metadata']);
        $this->assertSame('[array:2]', $c['after']['metadata']);
    }

    /**
     * TD-98：纯敏感字段从密值 A 改为密值 B，脱敏后虽同为 [REDACTED]，
     * 仍须记录「该敏感字段发生变更」（before/after 均 [REDACTED]），不得漏审计；
     * 且真实密值不得落库。
     */
    public function test_sensitive_value_change_is_audited_redacted(): void
    {
        $c = AuditSnapshot::changes(
            ['api_key' => 'SECRET-A', 'password' => 'p'],
            ['api_key' => 'SECRET-B', 'password' => 'q'],
            ['api_key', 'password']
        );

        $this->assertNotEmpty($c);
        $this->assertSame('[REDACTED]', $c['before']['api_key']);
        $this->assertSame('[REDACTED]', $c['after']['api_key']);
        $this->assertArrayHasKey('password', $c['before']);
        $json = json_encode($c);
        $this->assertStringNotContainsString('SECRET-A', $json);
        $this->assertStringNotContainsString('SECRET-B', $json);
    }

    // ---------- I) 前台提交不产生 payload 审计 ----------

    public function test_frontend_submission_does_not_write_payload_audit(): void
    {
        $before = AuditLog::count();

        $this->post('http://localhost/forms/contact/submit', [
            'name' => 'Front User', 'phone' => '13700137000',
            'email' => 'f@example.com', 'message' => 'public inquiry text',
            'website' => '',
        ])->assertRedirect();

        // 前台用户提交不是管理员操作：不新增审计，且无任何审计记录包含 payload 内容。
        $this->assertSame($before, AuditLog::count());
        $this->assertFalse(
            AuditLog::where('detail', 'like', '%public inquiry text%')->exists()
        );
    }
}
