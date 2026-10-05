<?php
/**
 * 20G-3i18n 合并后功能巡检（真实 HTTP 内核，覆盖多站 + 多语言）
 * ------------------------------------------------------------------
 * 目的：验证「两个 checkout 合并为单一基线」后，以下能力仍然成立：
 *   1. /en 前缀路由真实可访问，且返回英文内容而非中文；
 *   2. 中英 sitemap 按语言隔离（各自只收录本语言 URL）；
 *   3. 中英 geo.json 的 facts / entities 按 locale 隔离；
 *   4. hreflang 只输出已发布语言（不指向 404）；
 *   5. 站点未启用 en 时 /en/* 严格 404；
 *   6. 双站 + 双语言组合下不串站不串语言；
 *   7. GEOFlow 响应契约带 request_id；
 *   8. sitemap lastmod 全部可追溯到实体真值（无编造「今天」）。
 *
 * 走 Kernel::handle真实 HTTP：Laravel Feature Test 无法覆盖 Host 头
 * （getHost() 恒为 localhost），站点隔离断言必须用真实请求。
 *
 * 用法：
 *   GEO_DB=D:/Temp/i18n_check.sqlite GEO_ROOT=<repo> php i18n-merge-check.php
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 3);
$db   = getenv('GEO_DB') ?: 'D:/Temp/i18n_check.sqlite';

$_ENV['DB_DATABASE'] = $db;
putenv("DB_DATABASE=$db");

require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite');

use App\Models\{Site, Entity, Content, Category, Setting, Fact};
use App\Support\{SiteContext, Catalog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

// ---------------------------------------------------------------- 断言框架
$PASS = 0; $FAIL = 0; $FAILURES = [];

function ok(string $id, string $desc, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILURES;
    if ($cond) {
        $PASS++;
        echo "  [PASS] $id $desc\n";
    } else {
        $FAIL++;
        $FAILURES[] = "$id $desc" . ($detail !== '' ? " | $detail" : '');
        echo "  [FAIL] $id $desc" . ($detail !== '' ? " | $detail" : '') . "\n";
    }
}

/** 真实 HTTP 请求：走完整中间件管道 */
function http(string $host, string $uri): array
{
    global $app;
    $request = Request::create($uri, 'GET', [], [], [], [
        'HTTP_HOST'      => $host,
        'HTTP_ACCEPT'    => 'text/html,application/xhtml+xml',
        'HTTP_USER_AGENT'=> 'i18n-merge-check',
    ]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();
    // 每个请求后复位请求级状态，避免串站/串语言
    $kernel->terminate($request, $response);
    return [$status, $body, $response->headers->all()];
}

// ---------------------------------------------------------------- 建库播种
echo "== 准备隔离库 ==\n";
@unlink($db);
touch($db);
Artisan::call('migrate', ['--force' => true]);

$sites = Site::where('slug', 'default')->first();
if (! $sites) {
    $sites = new Site(['slug' => 'default', 'name' => '默认站', 'domain' => 'zh.test',
                       'status' => 'active', 'is_default' => true]);
    $sites->save();
}
$sites->update(['domain' => 'zh.test', 'name' => '中文公司', 'status' => 'active']);

// 仅中文站（验证 /en 严格 404）
$zhOnly = Site::where('slug', 'zh-only')->first() ?? Site::create([
    'slug' => 'zh-only', 'name' => '纯中文站', 'domain' => 'cn.test',
    'status' => 'active', 'is_default' => false,
]);
$zhOnly->update(['domain' => 'cn.test', 'status' => 'active']);

// 每站语言配置独立设置（Setting 按 site_id 分片，写错站会导致 /en 全站 404）：
//   默认站 zh.test → 双语言；cn.test → 仅中文，用于验证 /en 严格 404。
SiteContext::setSite($sites);
Setting::flush();
Setting::set('site_supported_locales', ['zh-CN', 'en']);
Setting::set('site_default_locale', 'zh-CN');
Setting::set('geo_org_name', '中文公司');
Setting::set('geo_org_en_name', 'English Company');

SiteContext::setSite($zhOnly);
Setting::flush();
Setting::set('site_supported_locales', ['zh-CN']);   // 仅中文
Setting::set('site_default_locale', 'zh-CN');
Setting::set('geo_org_name', '纯中文公司');

function seedLang(Site $s, array $locales): void
{
    SiteContext::setSite($s);
    Catalog::flush();
    $org = Entity::where('type', 'organization')->first();
    if (! $org) {
        $org = Entity::create([
            'type' => 'organization', 'name' => '中文公司', 'slug' => 'org',
            'status' => 'published',
            'metadata' => ['is_default' => true, 'company' => ['name' => '中文公司', 'short' => '中文公司']],
        ]);
    }
    if (in_array('en', $locales, true) && ! $org->translation('en')) {
        $org->createTranslation('en', ['name' => 'English Company', 'slug' => 'org-en']);
    }

    $prod = Entity::where('type', 'product')->first();
    if (! $prod) {
        $prod = Entity::create([
            'type' => 'product', 'name' => '中文产品', 'slug' => 'prod',
            'status' => 'published', 'summary' => '中文产品摘要',
            'metadata' => ['core' => true],
        ]);
    }
    if (in_array('en', $locales, true) && ! $prod->translation('en')) {
        $prod->createTranslation('en', [
            'name' => 'English Product', 'slug' => 'prod-en', 'summary' => 'English summary',
        ]);
    }

    $cat = Category::where('slug', 'knowledge')->first();
    $art = Content::where('slug', 'art-zh')->first();
    if (! $art) {
        $art = Content::create([
            'type' => 'article', 'category_id' => $cat?->id, 'title' => '中文文章',
            'slug' => 'art-zh', 'status' => 'published', 'summary' => '中文摘要',
            'body' => '中文正文', 'published_at' => now()->subDays(3),
        ]);
    }
    if (in_array('en', $locales, true) && ! $art->translation('en')) {
        $art->createTranslation('en', [
            'title' => 'English Article', 'slug' => 'art-en',
            'summary' => 'English summary', 'body' => 'English body',
        ]);
    }

    Fact::where('key', 'CANARY_FACT')->delete();
    Fact::create([
        'key' => 'CANARY_FACT', 'label' => '中文事实标签', 'value' => '中文事实值',
        'group' => 'g', 'is_public' => true, 'source' => 'test',
    ]);
}

SiteContext::setSite($sites);
seedLang($sites, ['zh-CN', 'en']);
SiteContext::setSite($zhOnly);
seedLang($zhOnly, ['zh-CN']);

// ---------------------------------------------------------------- 1. /en 可访问
echo "\n== 1. /en 路由与语言隔离 ==\n";
[$st, $body] = http('zh.test', '/en');
ok('I18N-001', '/en 首页返回 200', $st === 200, "status=$st");

[$stZh, $bodyZh] = http('zh.test', '/');
ok('I18N-002', '中文首页返回 200', $stZh === 200, "status=$stZh");

// /en 页面应含英文内容，且 hreflang 指向 /en
ok('I18N-003', '/en 页面含 hreflang en', str_contains($body, 'hreflang="en"') || str_contains($body, 'hreflang=en'));
ok('I18N-004', '/en 页面含英文实体名', str_contains($body, 'English Company') || str_contains($body, 'English Company'));

[$stEnProd] = http('zh.test', '/en/products/prod-en');
ok('I18N-005', '/en 英文产品详情 200', $stEnProd === 200, "status=$stEnProd");
[$stEnZhProd] = http('zh.test', '/en/products/prod');
ok('I18N-006', '/en 下访问中文 slug 应 404', $stEnZhProd === 404, "status=$stEnZhProd");

// ---------------------------------------------------------------- 2. 未启用 en 严格 404
echo "\n== 2. 未启用 en 的站点 ==\n";
[$stNoEn] = http('cn.test', '/en');
ok('I18N-007', '未启用 en 的站点 /en 返回 404', $stNoEn === 404, "status=$stNoEn");
[$stCn] = http('cn.test', '/');
ok('I18N-008', '纯中文站首页 200', $stCn === 200, "status=$stCn");

// ---------------------------------------------------------------- 3. sitemap 语言隔离 + lastmod 真值
echo "\n== 3. sitemap ==\n";
[$stSmZh, $smZh] = http('zh.test', '/sitemap.xml');
ok('I18N-010', '中文 sitemap 200', $stSmZh === 200, "status=$stSmZh");
ok('I18N-011', '中文 sitemap 含中文产品 URL', str_contains($smZh, '/products/prod<') || str_contains($smZh, '/products/prod/'));
ok('I18N-012', '中文 sitemap 不含英文产品 URL', ! str_contains($smZh, 'prod-en'));

[$stSmEn, $smEn] = http('zh.test', '/en/sitemap.xml');
ok('I18N-013', '英文 sitemap 200', $stSmEn === 200, "status=$stSmEn");
ok('I18N-014', '英文 sitemap 含英文产品 URL', str_contains($smEn, 'prod-en'));
ok('I18N-015', '英文 sitemap 不含中文产品 URL', ! str_contains($smEn, '/products/prod<'));

// lastmod 真值追溯：不得出现「今天」以外的编造，且实体页必须带真实 updated_at
$entityProd = Entity::withoutSiteScope()->where('type', 'product')->where('slug', 'prod')->first();
$past = now()->subDays(45);
if ($entityProd) {
    Entity::withoutSiteScope()->where('id', $entityProd->id)->update(['updated_at' => $past]);
}
$smZh2 = http('zh.test', '/sitemap.xml')[1];
ok('I18N-016', '产品页 lastmod 使用实体真值（无编造 today）',
    str_contains($smZh2, $past->toDateString()) || ! str_contains($smZh2, now()->toDateString().'</lastmod>'),
    '今天=' . now()->toDateString() . ' 实体真值=' . $past->toDateString());

// ---------------------------------------------------------------- 4. geo.json locale 隔离
echo "\n== 4. geo.json locale 隔离 ==\n";
[$stGeoZh, $geoZh] = http('zh.test', '/geo.json');
ok('I18N-020', '中文 geo.json 200', $stGeoZh === 200, "status=$stGeoZh");
$jZh = json_decode($geoZh, true);
ok('I18N-021', '中文 geo.json facts 存在', is_array($jZh) && isset($jZh['facts']));
ok('I18N-022', '中文 geo.json 主体名为中文', str_contains($geoZh, '中文公司'));

[$stGeoEn, $geoEn] = http('zh.test', '/en/geo.json');
ok('I18N-023', '英文 geo.json 200', $stGeoEn === 200, "status=$stGeoEn");
$jEn = json_decode($geoEn, true);
ok('I18N-024', '英文 geo.json 含英文实体名', str_contains($geoEn, 'English Company'));
ok('I18N-025', '英文 geo.json 不含中文实体名', ! str_contains($geoEn, '中文产品'));

// ---------------------------------------------------------------- 5. GEOFlow 响应契约
echo "\n== 5. GEOFlow 响应契约（合并后的 request_id） ==\n";
// 仅静态断言控制器契约字段，避免依赖 token 配置
$ctrl = new ReflectionMethod(\App\Http\Controllers\Api\GeoflowController::class, 'ok');
ok('I18N-030', 'GeoflowController::ok() 存在', $ctrl->isProtected());
$src = file_get_contents($root . '/app/Http/Controllers/Api/GeoflowController.php');
ok('I18N-031', '响应含 request_id 字段', str_contains($src, "'request_id'"));
ok('I18N-032', '响应含 changed_fields 字段', str_contains($src, "'changed_fields'"));
ok('I18N-033', '失败出口 errors 恒为数组', str_contains($src, "'errors'      => \$errors"));

// ---------------------------------------------------------------- 6. 合并引入项在位
echo "\n== 6. 合并引入的能力 ==\n";
ok('I18N-040', 'CacheInvalidationMap 已接入', str_contains(
    file_get_contents($root . '/app/Providers/AppServiceProvider.php'), 'CacheInvalidationMap::register()'));
$cim = App\Support\CacheInvalidationMap::MODELS;
ok('I18N-041', '缓存失效登记含 Site', in_array(Site::class, $cim, true));
ok('I18N-042', '缓存失效登记含 Entity', in_array(Entity::class, $cim, true));
ok('I18N-043', '缓存失效登记含 EntityRelation', in_array(\App\Models\EntityRelation::class, $cim, true));

$cs = file_get_contents($root . '/app/Http/Middleware/RejectMalformedUtf8.php');
ok('I18N-044', 'RejectMalformedUtf8 存在', str_contains($cs, 'class RejectMalformedUtf8'));
ok('I18N-045', 'utf8.guard 中间件已注册', str_contains(
    file_get_contents($root . '/bootstrap/app.php'), "'utf8.guard'"));
ok('I18N-046', 'web 组挂载 UTF8 守卫', substr_count(
    file_get_contents($root . '/bootstrap/app.php'), 'RejectMalformedUtf8::class') >= 2);

ok('I18N-047', 'SlugSuggester 存在', class_exists(App\Support\SlugSuggester::class));
ok('I18N-048', 'pinyin 依赖已安装', class_exists(\Overtrue\Pinyin\Pinyin::class));
ok('I18N-049', 'slug 建议路由已注册', str_contains(
    file_get_contents($root . '/routes/admin.php'), 'entities/slug-suggest'));

ok('I18N-050', 'LlmsSanitizer 已接入 build() 出口', str_contains(
    file_get_contents($root . '/app/Services/Geo/LlmsBuilder.php'), 'sanitizer->clean'));
ok('I18N-051', 'Markdown 渲染唯一出口 renderMarkdown', method_exists(Content::class, 'renderMarkdown'));
// C-1：Markdown 渲染必须收敛到 Content::renderMarkdown 唯一出口。
// 判据：剔除注释后，真实代码里只有 Content.php 允许出现 Str::markdown
// （注释里提及「Str::markdown」是说明文字，不是调用，不算残留）。
$realCallers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app')) as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    // 去块注释与行注释，避免把说明文字误判为调用残留
    $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $src) ?? $src;
    if (str_contains($code, 'Str::markdown') && ! str_ends_with(str_replace('\\', '/', $f->getPathname()), '/Models/Content.php')) {
        $realCallers[] = $f->getPathname();
    }
}
ok('I18N-052', 'Str::markdown 真实调用仅存在于 Content 唯一出口', count($realCallers) === 0,
    '残留: ' . implode(', ', $realCallers));

// ---------------------------------------------------------------- 汇总
echo "\n========================================\n";
echo "PASS: $PASS   FAIL: $FAIL\n";
if ($FAILURES !== []) {
    echo "\n失败明细:\n";
    foreach ($FAILURES as $f) {
        echo "  - $f\n";
    }
}
echo "========================================\n";
exit($FAIL === 0 ? 0 : 1);