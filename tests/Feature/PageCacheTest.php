<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

/**
 * v0.9.15 前台整页静态化缓存（PageCache）回归：
 *  - 匿名 GET 首次 MISS、再次 HIT；搜索/后台不缓存；UTM 不产生重复副本
 *  - CSRF token 与归因字段在缓存外壳里是占位符，命中后按当前会话回填，不串号
 *  - 内容/设置变更自动失效；留言成功后的 PRG 个性化页旁路缓存
 */
class PageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    private function tokenFrom(string $html): ?string
    {
        return preg_match('#name="_token"\s+value="([^"]+)"#', $html, $m) ? $m[1] : null;
    }

    public function test_first_request_miss_second_hit(): void
    {
        $first = $this->get('/');
        $first->assertOk();
        $this->assertSame('MISS', $first->headers->get('X-Page-Cache'));
        $this->assertNotEmpty($first->headers->get('ETag'));

        $second = $this->get('/');
        $second->assertOk();
        $this->assertSame('HIT', $second->headers->get('X-Page-Cache'));
    }

    public function test_security_headers_present_on_miss_and_hit(): void
    {
        // 安全头由最外层中间件下发，必须在整页缓存 MISS 与 HIT 两种回程都保留
        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy'] as $header) {
            $this->assertNotEmpty($this->get('/')->headers->get($header), "MISS 缺少 {$header}");
        }
        $hit = $this->get('/');
        $this->assertSame('HIT', $hit->headers->get('X-Page-Cache'));
        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy'] as $header) {
            $this->assertNotEmpty($hit->headers->get($header), "HIT 缺少 {$header}");
        }
        $this->assertEmpty($hit->headers->get('X-Powered-By'), '不应暴露 PHP 版本');
    }

    public function test_front_csp_is_nonce_based_on_miss_and_hit(): void
    {
        foreach (['MISS' => $this->get('/'), 'HIT' => $this->get('/')] as $label => $resp) {
            $csp = $resp->headers->get('Content-Security-Policy');
            $this->assertNotEmpty($csp, "{$label} 缺少 CSP");
            $this->assertStringContainsString("script-src 'nonce-", $csp, "{$label} 前台脚本应为 nonce 白名单");
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringContainsString("base-uri 'self'", $csp);
            $this->assertStringContainsString("frame-ancestors 'self'", $csp);
            $this->assertStringNotContainsString("'unsafe-inline'", explode(';', $csp)[1] ?? '', "{$label} 前台 script-src 不应含 unsafe-inline");

            // CSP 头里的 nonce 必须与页面内联脚本上的 nonce 一致，否则脚本会被拦截
            preg_match("#script-src 'nonce-([A-Za-z0-9+/=]+)'#", $csp, $m);
            $nonce = $m[1] ?? '';
            $this->assertNotEmpty($nonce);
            $this->assertStringContainsString('nonce="'.$nonce.'"', $resp->getContent(), "{$label} 内联脚本 nonce 与 CSP 头不一致");
            $this->assertStringNotContainsString(PageCache::P_CSP, $resp->getContent(), "{$label} 不应把 CSP 占位符发给用户");
        }
    }

    public function test_csp_nonce_is_not_pinned_by_cache(): void
    {
        $a = $this->get('/');
        preg_match("#script-src 'nonce-([A-Za-z0-9+/=]+)'#", (string) $a->headers->get('Content-Security-Policy'), $ma);
        $b = $this->get('/');
        $this->assertSame('HIT', $b->headers->get('X-Page-Cache'));
        preg_match("#script-src 'nonce-([A-Za-z0-9+/=]+)'#", (string) $b->headers->get('Content-Security-Policy'), $mb);
        $this->assertNotEmpty($ma[1] ?? '');
        $this->assertNotEmpty($mb[1] ?? '');
        // HIT 页面里的脚本 nonce 必须是本次请求的新 nonce，而不是缓存里钉死的旧 nonce
        $this->assertStringContainsString('nonce="'.$mb[1].'"', $b->getContent());
        $this->assertStringNotContainsString('nonce="'.$ma[1].'"', $b->getContent());
    }

    public function test_admin_csp_allows_inline_but_locks_navigation(): void
    {
        // 后台历史模板含内联事件，脚本保留 unsafe-inline，但 object/base/frame/form-action 仍锁死
        $resp = $this->get('/admin');
        $csp = $resp->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_search_and_admin_are_not_cached(): void
    {
        $this->get('/search?q=test')->assertOk();
        $this->get('/search?q=test')->assertOk();
        $response = $this->get('/search?q=test');
        $this->assertEmpty($response->headers->get('X-Page-Cache'), '搜索页不应被整页缓存');

        $admin = $this->get('/admin');
        $this->assertEmpty($admin->headers->get('X-Page-Cache'), '后台不应被整页缓存');
    }

    public function test_utm_does_not_duplicate_cache_and_is_personalized(): void
    {
        $a = $this->get('/?utm_source=baidu');
        $this->assertSame('MISS', $a->headers->get('X-Page-Cache'));

        // 同一测试内新会话：清掉 cookie，使归因/令牌按新访客处理
        $this->defaultCookies = [];
        $b = $this->get('/?utm_source=sogou');
        $this->assertSame('HIT', $b->headers->get('X-Page-Cache'));
        $this->assertStringContainsString('value="sogou"', $b->getContent());
        $this->assertStringNotContainsString('value="baidu"', $b->getContent());
    }

    public function test_csrf_token_is_not_pinned_by_cache(): void
    {
        $a = $this->get('/');
        $tokenA = $this->tokenFrom($a->getContent());
        $this->assertNotEmpty($tokenA);

        // 模拟全新访客：清空测试内会话单例并重置 cookie，强制生成新 token
        $this->defaultCookies = [];
        $session = $this->app['session'];
        $session->flush();
        $session->regenerateToken();

        $b = $this->get('/');
        $this->assertSame('HIT', $b->headers->get('X-Page-Cache'));
        $tokenB = $this->tokenFrom($b->getContent());

        $this->assertNotEmpty($tokenB);
        $this->assertNotSame($tokenA, $tokenB, '缓存命中页必须回填当前会话的新 token，而不是缓存生成时的 token');
        $this->assertStringContainsString($tokenB, $b->getContent());
        $this->assertStringNotContainsString($tokenA, $b->getContent());
    }

    public function test_setting_change_invalidates_cache(): void
    {
        $this->get('/')->assertOk();
        $this->assertSame('HIT', $this->get('/')->headers->get('X-Page-Cache'));

        // 后台保存设置（触发 Setting 模型 saved 事件）应使整页缓存版本 +1
        Setting::set('nav_cta_text', '临时拿样按钮');
        Setting::flush();

        $this->assertSame('MISS', $this->get('/')->headers->get('X-Page-Cache'));
        $this->assertStringContainsString('临时拿样按钮', $this->get('/')->getContent());

        Setting::set('nav_cta_text', '免费获取样品');
        Setting::flush();
    }

    public function test_prg_success_page_bypasses_cache(): void
    {
        // testing 环境 CSRF 中间件自动放行；提交有效留言
        $this->post('/inquiry', [
            'name'        => '缓存测试',
            'phone'       => '13800138000',
            'demand_type' => '经销商',
            'message'     => 'PRG 旁路验证',
            'website'     => '',
        ])->assertRedirect();

        // 回跳后该会话携带 lead_success，必须旁路缓存并显示成功提示
        $back = $this->get('/');
        $this->assertSame('BYPASS', $back->headers->get('X-Page-Cache'));
        $this->assertStringContainsString('lead-ok', $back->getContent());
        $this->assertStringContainsString((string) config('copy.form.success'), $back->getContent());
    }

    public function test_shell_placeholders_and_personalize(): void
    {
        $html = '<form>'
            .'<input type="hidden" name="_token" value="RAWTOKEN" autocomplete="off">'
            .'<input type="hidden" name="landing_url" value="https://example.com/a/">'
            .'<input type="hidden" name="utm_source" value="baidu">'
            .'<input type="hidden" name="referer" value="">'
            .'</form>'
            .'<script nonce="OLDNONCE123">alert(1)</script>';

        $shell = PageCache::toShell($html);
        $this->assertStringContainsString(PageCache::P_CSRF, $shell);
        $this->assertStringNotContainsString('RAWTOKEN', $shell);
        $this->assertStringContainsString('{{PC_ATTR_LANDING}}', $shell);
        $this->assertStringContainsString('{{PC_ATTR_UTM_SOURCE}}', $shell);
        $this->assertStringContainsString('{{PC_ATTR_REFERER}}', $shell);
        $this->assertStringContainsString('nonce="'.PageCache::P_CSP.'"', $shell);
        $this->assertStringNotContainsString('OLDNONCE123', $shell);

        $session = new Store('test', new NullSessionHandler());
        $session->start();
        $session->put('attr', ['landing_url' => 'https://mine/x', 'utm_source' => 'sogou']);
        $token = $session->token();

        $request = Request::create('/');
        $request->setLaravelSession($session);
        $request->attributes->set('csp_nonce', 'FRESHNONCE456');

        $out = PageCache::personalize($shell, $request);
        $this->assertStringContainsString('value="'.$token.'"', $out);
        $this->assertStringContainsString('value="https://mine/x"', $out);
        $this->assertStringContainsString('value="sogou"', $out);
        $this->assertStringNotContainsString(PageCache::P_CSRF, $out);
        $this->assertStringContainsString('nonce="FRESHNONCE456"', $out);
        $this->assertStringNotContainsString(PageCache::P_CSP, $out);
    }
}
