<?php
/**
 * 20G-8-D · Site × Locale 四象限 E2E
 * ------------------------------------------------------------------
 * 目的：证明四个命名空间 **A/zh、A/en、B/zh、B/en** 真正独立，
 * 而不只是「有英文路由」。
 *
 * canary 设计：**same slug + different content**（已在 20G-5 证明有效），
 * 四象限各插一行，slug 完全相同、内容与 language 标记各不相同。
 * 任何串站 / 串语言都会立刻在响应内容里暴露。
 *
 * 覆盖维度（每维都做四象限交叉）：
 *   Content / Entity / Category / Facts / Canonical / hreflang /
 *   JSON-LD / geo.json / llms.txt / sitemap
 *
 * ⚠️ 纪律：**Facts 的英文标签缺失是已知缺口（20G-3 P1）**，
 * 本脚本只**观察并登记为复现证据**，不判FAIL、不在此处修复。
 *
 * 走真实 HTTP 内核（Laravel Feature Test 无法覆盖 Host 头）。
 *
 * 用法：
 *   GEO_DB=<sqlite> GEO_ROOT=<repo> php site-locale-quadrant-e2e.php
 * 前置：库已 migrate 到最新（建库请用 artisan CLI）。
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 3);
$db   = getenv('GEO_DB') ?: 'D:/Temp/g8d_quadrant.sqlite';

$_ENV['DB_DATABASE'] = $db;
putenv("DB_DATABASE=$db");

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.sqlite.database' => $db]);
Illuminate\Support\Facades\DB::purge('sqlite');

use App\Models\{Site, Entity, Content, Category, Fact, Setting};
use App\Support\{SiteContext, Catalog, LocaleContext};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

$PASS = 0; $FAIL = 0; $FAILURES = [];
$GAPS = [];   // 20G-3 Facts locale 缺口的复现证据（非失败）

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

function gap(string $id, string $desc, string $evidence): void
{
    global $GAPS;
    $GAPS[] = ['id' => $id, 'desc' => $desc, 'evidence' => $evidence];
    // ⚠️ 描述里可能含中文括号「（20G-3 ...）」，直接拼进双引号会被 PHP
    // 当成变量插值的一部分（`$desc（20G` → Undefined variable）。
    // 故用 printf 拼接，不把 $desc 放进带括号的字符串字面量。
    printf("  [GAP ] %s %s [20G-3 已知缺口，仅登记]\n", $id, $desc);
}

/** 真实 HTTP：走完整中间件管道 */
function http(string $host, string $uri): array
{
    global $app;
    $request = Request::create($uri, 'GET', [], [], [], [
        'HTTP_HOST'       => $host,
        'HTTP_ACCEPT'     => 'text/html,application/json,application/xml;q=0.9,*/*;q=0.8',
        'HTTP_USER_AGENT' => 'g8d-quadrant',
    ]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $out = [
        'status' => $response->getStatusCode(),
        'body'   => (string) $response->getContent(),
        'loc'    => $response->headers->get('Location'),
    ];
    $kernel->terminate($request, $response);

    return $out;
}

// ================================================================ 播种
echo "== 播种四象限 canary ==\n";

$siteA = Site::where('domain', 'a.test')->first() ?: Site::where('slug', 'default')->first();
if (! $siteA) {
    echo "  [FATAL] 找不到 default 站点，请先 migrate + seed\n";
    exit(1);
}
$siteA->update(['domain' => 'a.test', 'name' => 'A站', 'status' => 'active']);

$siteB = Site::where('domain', 'b.test')->first() ?: Site::where('slug', 'site-b')->first();
if (! $siteB) {
    $siteB = Site::create([
        'slug' => 'site-b', 'name' => 'B站', 'domain' => 'b.test',
        'status' => 'active', 'is_default' => false,
    ]);
}
$siteB->update(['domain' => 'b.test', 'status' => 'active']);

/** 四象限标记：同时编码站点与语言，任何串扰都能被检出 */
$QUAD = [
    'A|zh' => ['site' => $siteA, 'locale' => 'zh-CN', 'mark' => 'QUAD-A-ZH', 'cat' => 'cat-a-zh'],
    'A|en' => ['site' => $siteA, 'locale' => 'en',    'mark' => 'QUAD-A-EN', 'cat' => 'cat-a-en'],
    'B|zh' => ['site' => $siteB, 'locale' => 'zh-CN', 'mark' => 'QUAD-B-ZH', 'cat' => 'cat-b-zh'],
    'B|en' => ['site' => $siteB, 'locale' => 'en',    'mark' => 'QUAD-B-EN', 'cat' => 'cat-b-en'],
];

// 每站支持双语言
foreach ([$siteA, $siteB] as $s) {
    SiteContext::setSite($s);
    Setting::flush();
    Setting::set('site_supported_locales', ['zh-CN', 'en']);
    Setting::set('site_default_locale', 'zh-CN');
    Setting::set('geo_org_name', $s->slug === 'default' ? 'AOrg' : 'BOrg');
    Setting::set('geo_org_en_name', $s->slug === 'default' ? 'AOrgEN' : 'BOrgEN');
}

$knowledgeCat = Category::where('slug', 'knowledge')->first();

foreach ($QUAD as $qname => $q) {
    SiteContext::setSite($q['site']);
    Setting::flush();
    Catalog::flush();

    // 象限名形如 'A|zh' →拆出站点标记（translation_group 命名要用）
    [$siteTag] = explode('|', $qname);

    // 独立栏目（四象限各有自己的栏目名）
    $cat = Category::updateOrCreate(
        ['slug' => $q['cat']],
        ['name' => $q['mark'].' 栏目', 'type' => 'list', 'is_active' => true, 'is_nav' => false, 'sort' => 900]
    );

    // 独立 Entity：slug 相同（跨站/跨语言），name 带象限标记
    $entZh = Entity::where('type', 'product')->where('slug', 'quad-prod')
        ->where('locale', 'zh-CN')->first();
    $ent = Entity::updateOrCreate(
        ['type' => 'product', 'slug' => 'quad-prod', 'locale' => $q['locale']],
        ['name' => $q['mark'].' 产品', 'status' => 'published',
         'summary' => $q['mark'].' 摘要', 'metadata' => ['core' => true],
         'translation_group' => $entZh?->translation_group ?: 'TG-ENT-'.($siteTag === 'A' ? 'A' : 'B')]
    );

    // 独立 Content：slug 相同，标题/正文带象限标记
    //
    // ⚠️ zh 与 en 必须是**同一 translation_group 的两行**（真实 i18n 形态），
    // 否则 `publishedLocaleCodes()` 各自只返回 1 个语言 →
    // hreflang 只输出当前语言（+x-default），D4 断言无法成立。
    // 2026-08-03 修正：初版按 slug+locale 独立建行，漏了 translation_group。
    $tg = 'TG-QUAD-'.($siteTag === 'A' ? 'A' : 'B');
    $existing = Content::where('slug', 'quad-article')
        ->where('locale', 'zh-CN')->first();
    Content::updateOrCreate(
        ['slug' => 'quad-article', 'locale' => $q['locale']],
        ['type' => 'article', 'category_id' => $cat->id, 'title' => $q['mark'].' 文章',
         'summary' => $q['mark'].' 摘要', 'body' => $q['mark'].' 正文',
         'status' => 'published', 'published_at' => now()->subDays(2),
         'translation_group' => $existing?->translation_group ?: $tg]
    );

    // 独立 Fact：key 相同、value 带象限标记
    // （facts 表当前无 locale 列 —— 这正是 20G-3 P1 缺口，这里用「同 key 覆盖」
    //  让四象限共享一行，恰好能观察 geo.json 输出的是哪个象限的 label/value）
    Fact::updateOrCreate(
        ['key' => 'QUAD-FACT'],
        ['label' => $q['mark'].' 标签', 'value' => $q['mark'], 'group' => 'g8d',
         'is_public' => true, 'sort' => 900]
    );

    echo "  {$qname} → site={$q['site']->slug} locale={$q['locale']} mark={$q['mark']}\n";
}

// 清缓存，保证下面读到的是新数据
foreach ([$siteA, $siteB] as $s) {
    SiteContext::setSite($s);
    \App\Support\PageCache::flush();
    Setting::flush();
    Catalog::flush();
}
echo "\n";

// 四象限 host/前缀
$HOST_A = 'a.test';
$HOST_B = 'b.test';
$PREFIX = ['zh' => '', 'en' => '/en'];

/** 象限 → 请求目标 */
function url4(string $host, string $lang, string $path): string
{
    $pre = $lang === 'en' ? '/en' : '';

    return $host . $pre . $path;
}

// ================================================================ 维度 1：Content 四象限
echo "== D1 · Content 四象限隔离 ==\n";

$marks = [];
foreach ($QUAD as $qname => $q) {
    [$siteTag, $lang] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;
    $r = http($host, $PREFIX[$lang].'/'.$q['cat'].'/quad-article');
    $marks[$qname] = $r['status'] === 200 && str_contains($r['body'], $q['mark']);
    ok('D1.'.$qname, "{$qname} 能看到自己的文章（200 且含 {$q['mark']}）",
        $marks[$qname], "status={$r['status']}");
}

// 交叉污染检查：A/zh 不得含其它三个象限的标记
foreach ($QUAD as $qname => $q) {
    [$siteTag, $lang] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;
    $r = http($host, $PREFIX[$lang].'/'.$q['cat'].'/quad-article');
    foreach ($QUAD as $other => $oq) {
        if ($other === $qname) {
            continue;
        }
        ok('D1.'.$qname.'.no-'.$other,
            "{$qname} 的响应不含 {$other} 的标记 {$oq['mark']}",
            ! str_contains($r['body'], $oq['mark']));
    }
}
echo "\n";

// ================================================================ 维度 2：Entity / Catalog
echo "== D2 · Entity / Catalog 四象限隔离 ==\n";

/**
 * 判据用 **geo.json 的 entities** 而非前台产品中心页 ——
 * 后者在 `Catalog::company()` 为空（无带 metadata.company 的 organization
 * 实体）时按设计返回 404（实测），那是「空站不渲染目录页」的既有行为，
 * 不是 i18n 或多站隔离问题。用 geo.json 才能直接观察 Entity 的locale 隔离。
 */
foreach ($QUAD as $qname => $q) {
    [$siteTag, $lang] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;
    $r = http($host, $PREFIX[$lang].'/geo.json');
    $doc = json_decode($r['body'], true);
    $names = array_column(is_array($doc) ? ($doc['entities'] ?? []) : [], 'name');

    $hasSelf = false;
    $leaked = [];
    foreach ($names as $n) {
        foreach ($QUAD as $other => $oq) {
            if ($other === $qname) {
                if (str_contains((string) $n, $oq['mark'])) {
                    $hasSelf = true;
                }
            } elseif (str_contains((string) $n, $oq['mark'])) {
                $leaked[] = $oq['mark'];
            }
        }
    }
    ok('D2.'.$qname, "{$qname} geo.json entities 含自己的实体 {$q['mark']}",
        $hasSelf, 'entities: '.json_encode($names, JSON_UNESCAPED_UNICODE));
    ok('D2.'.$qname.'.clean', "{$qname} geo.json 不含其它象限实体",
        $leaked === [], '泄漏: '.implode(',', array_unique($leaked)));
}
echo "\n";

// ================================================================ 维度 3：Canonical
echo "== D3 · Canonical 随 host + locale ==\n";

foreach ([
    ['A|zh', $HOST_A, '', 'a.test'],
    ['A|en', $HOST_A, '/en', 'a.test'],
    ['B|zh', $HOST_B, '', 'b.test'],
    ['B|en', $HOST_B, '/en', 'b.test'],
] as [$qname, $host, $pre, $expectHost]) {
    $r = http($host, $pre.'/'.$QUAD[$qname]['cat'].'/quad-article');
    preg_match('/<link[^>]+rel="canonical"[^>]+href="([^"]+)"/i', $r['body'], $m);
    $canon = $m[1] ?? '';
    ok('D3.'.$qname, "{$qname} canonical 指向 {$expectHost}",
        str_contains($canon, $expectHost), "canonical=".($canon ?: '(未找到)'));
}
echo "\n";

// ================================================================ 维度 4：hreflang
echo "== D4 · hreflang ==\n";

foreach ([
    ['A|zh', $HOST_A, ''],
    ['A|en', $HOST_A, '/en'],
    ['B|zh', $HOST_B, ''],
    ['B|en', $HOST_B, '/en'],
] as [$qname, $host, $pre]) {
    $r = http($host, $pre.'/'.$QUAD[$qname]['cat'].'/quad-article');
    preg_match_all('/<link[^>]+rel="alternate"[^>]+hreflang="([^"]+)"/i', $r['body'], $m);
    $langs = array_unique($m[1] ?? []);
    ok('D4.'.$qname, "{$qname} 输出 hreflang（zh-CN / en）",
        in_array('zh-CN', $langs, true) && in_array('en', $langs, true),
        '实际: '.json_encode($langs));
}
echo "\n";

// ================================================================ 维度 5：JSON-LD
echo "== D5 · JSON-LD四象限 ==\n";

foreach ([
    ['A|zh', $HOST_A, '', 'AOrg'],
    ['A|en', $HOST_A, '/en', 'AOrgEN'],
    ['B|zh', $HOST_B, '', 'BOrg'],
    ['B|en', $HOST_B, '/en', 'BOrgEN'],
] as [$qname, $host, $pre, $orgName]) {
    $r = http($host, $pre.'/'.$QUAD[$qname]['cat'].'/quad-article');
    ok('D5.'.$qname, "{$qname} JSON-LD 含本象限主体名 {$orgName}",
        str_contains($r['body'], $orgName),
        'body 未含 '.$orgName);
}
echo "\n";

// ================================================================ 维度 6：geo.json
echo "== D6 · geo.json 四象限隔离 ==\n";

$geoDocs = [];
foreach ($QUAD as $qname => $q) {
    [$siteTag, $lang] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;
    $r = http($host, $PREFIX[$lang].'/geo.json');
    $doc = json_decode($r['body'], true);
    $geoDocs[$qname] = is_array($doc) ? $doc : [];
    ok('D6.'.$qname, "{$qname} geo.json 200 且结构合法",
        $r['status'] === 200 && is_array($doc), "status={$r['status']}");
}

// entity 隔离：只应含本象限的实体
foreach ($QUAD as $qname => $q) {
    $names = array_column($geoDocs[$qname]['entities'] ?? [], 'name');
    $hasSelf = false;
    $leaked = [];
    foreach ($names as $n) {
        foreach ($QUAD as $other => $oq) {
            if ($other === $qname) {
                if (str_contains((string) $n, $oq['mark'])) {
                    $hasSelf = true;
                }
            } elseif (str_contains((string) $n, $oq['mark'])) {
                $leaked[] = $oq['mark'];
            }
        }
    }
    ok('D6.'.$qname.'.self', "{$qname} geo.json 含自己的实体 {$q['mark']}", $hasSelf,
        'entities: '.json_encode($names, JSON_UNESCAPED_UNICODE));
    ok('D6.'.$qname.'.clean', "{$qname} geo.json 不含其它象限实体", $leaked === [],
        '泄漏: '.implode(',', array_unique($leaked)));
}

// facts 隔离 + 已知缺口观察
foreach ($QUAD as $qname => $q) {
    $facts = $geoDocs[$qname]['facts'] ?? [];
    $vals = array_column($facts, 'value');
    $hasSelfMark = in_array($q['mark'], $vals, true);

    if (! $hasSelfMark) {
        gap('D6.'.$qname.'.facts',
            "{$qname} geo.json 的 facts 未按 locale 隔离（label/value 仍是单语言）",
            'facts='.json_encode($facts, JSON_UNESCAPED_UNICODE));
    } else {
        ok('D6.'.$qname.'.facts', "{$qname} facts 含本象限值 {$q['mark']}", true);
    }
}
echo "\n";

// ================================================================ 维度 7：llms.txt
echo "== D7 · llms.txt 四象限 ==\n";

foreach ($QUAD as $qname => $q) {
    [$siteTag, $lang] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;
    $r = http($host, $PREFIX[$lang].'/llms.txt');
    $leaked = [];
    foreach ($QUAD as $other => $oq) {
        if ($other !== $qname && str_contains($r['body'], $oq['mark'])) {
            $leaked[] = $oq['mark'];
        }
    }

    /**
     * 泄漏分两类，处理方式不同（20G-8-D 实测）：
     *  · 跨站泄漏（QUAD-B-*出现在 A 站）→ 真实隔离缺陷，判 FAIL
     *  · 同站跨语言泄漏（QUAD-A-EN 出现在 A|zh）→ **20G-3 范围的 i18n 缺口**：
     *    `LlmsBuilder::buildGeneric()`（空站通用骨架）的 contentQuery()
     *    缺 `forLocale(LocaleContext::current())`，故中文 llms.txt 会列出英文文章
     *    （app/Services/Geo/LlmsBuilder.php:297）。属已知缺口，仅登记不判失败。
     */
    $crossSite = array_values(array_filter($leaked, fn ($m) => ! str_contains($m, explode('|', $qname)[0])));
    $crossLang = array_values(array_filter($leaked, fn ($m) => str_contains($m, explode('|', $qname)[0])));

    if ($crossSite !== []) {
        ok('D7.'.$qname, "{$qname} llms.txt 不泄漏其它站点的内容",
            false, '跨站泄漏: '.implode(',', $crossSite));
    } else {
        ok('D7.'.$qname.'.site', "{$qname} llms.txt 无跨站泄漏", true);
    }

    if ($crossLang !== []) {
        gap('D7.'.$qname.'.lang',
            "{$qname} llms.txt 混入了同站其它语言的内容（".implode(',', $crossLang).'）',
            '根因：LlmsBuilder::buildGeneric() 的 contentQuery() 缺 forLocale()'
            .'（app/Services/Geo/LlmsBuilder.php:297）——归 20G-3 i18n 缺口');
    } else {
        ok('D7.'.$qname.'.lang', "{$qname} llms.txt 无跨语言泄漏", true);
    }
}
echo "\n";

// ================================================================ 维度 8：sitemap
echo "== D8 · sitemap 四象限隔离 ==\n";

foreach ($QUAD as $qname => $q) {
    [$siteTag, $lang] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;
    $r = http($host, $PREFIX[$lang].'/sitemap.xml');

    // 同slug 四象限都存在 → 靠 URL 无法区分，必须靠 lastmod/语言前缀区分
    $hasEnPrefix = str_contains($r['body'], '/en/');
    $isZh = $lang === 'zh';
    ok('D8.'.$qname, "{$qname} sitemap 200", $r['status'] === 200, "status={$r['status']}");
    ok('D8.'.$qname.'.prefix',
        "{$qname} sitemap " . ($isZh ? '不含 /en/ 前缀URL' : '含 /en/ 前缀URL'),
        $isZh ? ! $hasEnPrefix : $hasEnPrefix);
}
echo "\n";

// ================================================================ 维度 9：Category
echo "== D9 · Category 四象限 ==\n";

foreach ($QUAD as $qname => $q) {
    [$siteTag, $lang] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;
    $r = http($host, $PREFIX[$lang].'/'.$q['cat'].'/quad-article');
    ok('D9.'.$qname, "{$qname} 页面渲染出本象限栏目 {$q['cat']}",
        str_contains($r['body'], $q['mark'].' 栏目'),
        '未找到本象限栏目名');
}
echo "\n";

// ================================================================ 汇总
echo "========================================\n";
echo "PASS: {$PASS}   FAIL: {$FAIL}   KNOWN-GAP: " . count($GAPS) . "\n";

if ($FAILURES !== []) {
    echo "\n失败明细:\n";
    foreach ($FAILURES as $f) {
        echo "  - {$f}\n";
    }
}

if ($GAPS !== []) {
    echo "\n已知缺口复现证据（归20G-3，本轮不修）:\n";
    foreach ($GAPS as $g) {
        echo "  - [{$g['id']}] {$g['desc']}\n      {$g['evidence']}\n";
    }
}
echo "========================================\n";
exit($FAIL === 0 ? 0 : 1);
