<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Site;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GF-AUTH / GF-SITE · 认证与站点隔离契约（20G-2）
 * ------------------------------------------------------------------
 * 认证不变量：
 *   I1  无 token / 错 token → 401；未配置 token → 503（fail-closed）；
 *   I2  开关关闭时认证仍生效（403 在401 之后）。
 *
 * 站点隔离不变量：
 *   I3  API 分组必须经过 ResolveSite（否则 SiteContext 惰性兜底 default，
 *       GEOFlow 只能往 default 站写内容，多站对接形同虚设）；
 *   I4  跨站操作被拒：A站 token 不得写入 B 站数据。
 */
class GeoflowAuthAndSiteTest extends TestCase
{
    use RefreshDatabase;

    protected string $token = 'test-token-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\FactSeeder::class,
            \Database\Seeders\StructureSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);
    }

    /**
     * 每个用例后复位站点上下文与配置。
     *
     * SiteContext 是**静态状态**，测试中调用 setSite() 会跨用例泄漏——
     * 表现为「单独跑通过、整组跑失败」。这类污染必须显式复位，
     * 否则契约测试本身就成了不可靠的证据。
     */
    protected function tearDown(): void
    {
        SiteContext::clear();

        parent::tearDown();
    }

    protected function payload(array $override = []): array
    {
        return array_merge([
            'external_id' => 'AUTH-001',
            'type' => 'article',
            'title' => '认证验证文章',
            'slug' => 'auth-site-article',
            'category_slug' => 'knowledge',
            'summary' => '摘要。',
            'body' => '正文。',
            'geo_conclusion' => '结论。',
            'geo_explanation' => '解释。',
            'geo_boundary' => '边界。',
            'geo_evidence' => [
                ['label' => '设备A', 'value' => '配置1', 'source' => '台账'],
                ['label' => '设备 B', 'value' => '配置 2', 'source' => '实拍'],
            ],
            'owner' => 'GEOFlow',
            'reviewed_at' => '2026-09-14',
        ], $override);
    }

    // ─────────────────────────────────────────────────────────
    // I1 · 认证
    // ─────────────────────────────────────────────────────────

    /**
     * GF-AUTH-001 无 token → 401。
     */
    public function test_gf_auth_001_missing_token_is_401(): void
    {
        Setting::set('sync_geoflow_token', $this->token);

        $this->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertStatus(401);
    }

    /**
     * GF-AUTH-002 错误 token → 401。
     */
    public function test_gf_auth_002_wrong_token_is_401(): void
    {
        Setting::set('sync_geoflow_token', $this->token);

        $this->withToken('wrong-token')
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertStatus(401);
    }

    /**
     * GF-AUTH-003 token 未配置 → 503（fail-closed，不得放行）。
     */
    public function test_gf_auth_003_unconfigured_token_is_503(): void
    {
        Setting::set('sync_geoflow_token', '');

        $this->withToken('anything')
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertStatus(503);
    }

    /**
     * GF-AUTH-004 认证在开关检查之前（未认证时先401，不泄露写入能力状态）。
     */
    public function test_gf_auth_004_auth_precedes_switch_check(): void
    {
        Setting::set('sync_geoflow_token', $this->token);
        Setting::set('sync_geoflow_enabled', '0');

        // 无 token + 开关关闭 → 应 401（认证优先），不是 403
        $this->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertStatus(401);
    }

    /**
     * GF-AUTH-005 认证通过但开关关闭 → 403 disabled。
     */
    public function test_gf_auth_005_authenticated_but_disabled_is_403(): void
    {
        Setting::set('sync_geoflow_token', $this->token);
        Setting::set('sync_geoflow_enabled', '0');

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('code', 'disabled');
    }

    /**
     * GF-AUTH-006 status 端点同样受认证保护。
     */
    public function test_gf_auth_006_status_endpoint_requires_auth(): void
    {
        Setting::set('sync_geoflow_token', $this->token);

        $this->getJson('/api/v1/geoflow/contents/AUTH-001')->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────
    // I3 / I4 · 站点隔离
    // ─────────────────────────────────────────────────────────

    /**
     * GF-SITE-001 API 分组运行时经过 ResolveSite。
     *
     * 判据用**行为**而非配置断言：`$middleware->api(prepend: [ResolveSite])`
     * 是全局注册（Laravel 12 的 Middleware 配置对象），不会出现在路由的
     * `gatherMiddleware()` 里——那是路由级中间件。断言路由数组会假失败。
     *
     * 真正的判据是：未知 Host 时 API 请求应被拒绝（SiteContext 解析失败），
     * 这只有 ResolveSite 生效才会发生。
     */
    public function test_gf_site_001_api_rejects_unknown_host_via_resolve_site(): void
    {
        Setting::set('sync_geoflow_token', $this->token);
        Setting::set('sync_geoflow_enabled', '1');

        // 未知 Host + 生产级严格模式 → ResolveSite 应拒绝
        // 注意配置键是 site.default_fallback（见 ResolveSite:30,46）
        config(['site.default_fallback' => false]);

        $response = $this->withToken($this->token)
            ->postJson('http://unknown-host-for-test.invalid/api/v1/geoflow/contents', $this->payload());

        $this->assertContains(
            $response->getStatusCode(),
            [404, 422, 403],
            'GF-SITE-001 失败：未知 Host 未被 ResolveSite 拒绝，说明 api 分组缺少站点解析'
        );
    }

    /**
     * GF-SITE-001b api 分组确实注册了 ResolveSite（读 Middleware 配置）。
     *
     * 上一条用行为验证；这条用配置验证，两者互补：
     * 行为证明「有效」，配置证明「意图明确」，防止将来被误删。
     */
    public function test_gf_site_001b_resolve_site_registered_for_api_group(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString(
            '$middleware->api(prepend:',
            $bootstrap,
            'bootstrap/app.php 应为 api 分组 prepend 中间件'
        );

        // api prepend 块内必须含 ResolveSite
        $this->assertMatchesRegularExpression(
            '/\$middleware->api\(prepend:\s*\[[^\]]*ResolveSite::class/s',
            $bootstrap,
            'GF-SITE-001b 失败：api 分组未 prepend ResolveSite，多站对接会写入 default 站'
        );
    }

    /**
     * GF-SITE-002 写入的内容归属当前解析站点。
     */
    public function test_gf_site_002_content_is_scoped_to_current_site(): void
    {
        Setting::set('sync_geoflow_token', $this->token);
        Setting::set('sync_geoflow_enabled', '1');
        Setting::set('sync_auto_publish', '1');

        $currentSiteId = SiteContext::currentSiteId() ?? Site::firstOrFail()->id;

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk();

        $this->assertDatabaseHas('contents', [
            'external_id' => 'AUTH-001',
            'site_id' => $currentSiteId,
        ]);
    }

    /**
     * GF-SITE-003 全局 Scope 过滤：另站查询不到本站内容。
     */
    public function test_gf_site_003_global_scope_hides_other_site_content(): void
    {
        Setting::set('sync_geoflow_token', $this->token);
        Setting::set('sync_geoflow_enabled', '1');
        Setting::set('sync_auto_publish', '1');

        $this->withToken($this->token)
            ->postJson('/api/v1/geoflow/contents', $this->payload())
            ->assertOk();

        $mine = \App\Models\Content::where('external_id', 'AUTH-001')->firstOrFail();

        // 切到另一个站点后，同一查询不应看到
        $other = Site::where('id', '!=', $mine->site_id)->first();
        if ($other === null) {
            $other = Site::create(['slug' => 'site-x', 'name' => '站X', 'is_default' => false]);
        }

        SiteContext::setSite($other);

        $this->assertNull(
            \App\Models\Content::where('external_id', 'AUTH-001')->first(),
            'GF-SITE-003 失败：全局 SiteScope 未隔离跨站数据'
        );
    }

    /**
     * GF-SITE-004 同external_id 在不同站可共存（配合 UNIQUE(site_id, external_id)）。
     */
    public function test_gf_site_004_same_external_id_coexists_across_sites(): void
    {
        $siteA = Site::where('slug', 'default')->firstOrFail();
        $siteB = Site::where('id', '!=', $siteA->id)->first()
            ?? Site::create(['slug' => 'site-y', 'name' => '站Y', 'is_default' => false]);

        foreach ([[$siteA, 'a'], [$siteB, 'b']] as [$site, $suffix]) {
            \Illuminate\Support\Facades\DB::table('contents')->insert([
                'site_id' => $site->id, 'type' => 'article',
                'title' => '跨站内容', 'slug' => 'cross-'.$suffix, 'status' => 'draft',
                'external_id' => 'CROSS-SHARED-001',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            2,
            \Illuminate\Support\Facades\DB::table('contents')
                ->where('external_id', 'CROSS-SHARED-001')->count(),
            'GF-SITE-004 失败：site_id 参与唯一键，跨站同 external_id 应各自独立'
        );
    }

    /**
     * GF-SITE-005 站点未绑定域名时按Host 解析，不依赖查询参数切换。
     */
    public function test_gf_site_005_public_requests_cannot_switch_site_by_query(): void
    {
        $other = Site::where('slug', '!=', 'default')->first();
        if ($other === null) {
            $other = Site::create(['slug' => 'site-q', 'name' => '站Q', 'is_default' => false]);
        }

        // 前台公开请求不得通过查询参数切换站点
        $this->get('/?site_slug=' . $other->slug)->assertOk();

        $this->assertSame(
            Site::where('slug', 'default')->firstOrFail()->id,
            SiteContext::currentSiteId(),
            'GF-SITE-005 失败：公开请求不得通过查询参数切换站点'
        );
    }
}
