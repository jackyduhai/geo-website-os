<?php

/**
 * 20G-3 · Facts Localization + GEO/LLMS Locale Integrity 四象限 E2E
 * ------------------------------------------------------------------
 * 真实 HTTP 内核（Kernel::handle），不走 Feature Test —— 后者无法可靠控制
 * Host 头与 /en 前缀路由（20G-8 实测教训）。
 *
 * 播种：Site A / Site B × zh-CN / en
 *   · facts：每象限 2 条（key相同、value 带象限标记），共享 translation_group
 *   · content：同slug，中英两行共享 translation_group
 *
 * 验证（A/zh、A/en、B/zh、B/en 四命名空间）：
 *   G1 geo.json 的 facts 段 label/value 为当前语言，无跨语言污染
 *   G2 geo.json 的 facts 段**不含**其它象限的 value（跨站 + 跨语言都不许泄漏）
 *   G3 geo.json 的 facts key 集合在四种语言下稳定（语义身份不随语言变）
 *   G4 facts 段禁止 fallback：缺某语言翻译时该key 不出现
 *   L1 llms.txt 只列当前语言的内容，不混入另一语言
 *   L2 llms.txt 在四种象限下两两不同（证明 locale 与 site 都生效）
 *
 * 用法：
 *   GEO_ROOT=<项目根> GEO_DB=<sqlite 路径> php docs/audit/20G/e2e/facts-locale-e2e.php
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 4);
$db   = getenv('GEO_DB') ?: 'D:/Temp/g3_quadrant.sqlite';

if (! is_file($db)) {
    fwrite(STDERR, "数据库不存在：{$db}\n请先用 artisan migrate 建库。\n");

    exit(1);
}

$_ENV['DB_DATABASE'] = $db;
putenv("DB_DATABASE={$db}");

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';

// 必须 bootstrap：只用 Kernel::handle 不会初始化 DB facade / 容器绑定，
// 播种阶段一碰模型就报「Call to a member function connection() on null」。
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\Category;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Fact;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\SiteContext;

$HOST_A = 'g3a.test';
$HOST_B = 'g3b.test';

$PASS = 0;
$FAIL = 0;
$FAILURES = [];

function ok(string $id, string $desc, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILURES;
    if ($cond) {
        $PASS++;
        echo "  [PASS] $id $desc\n";
    } else {
        $FAIL++;
        $FAILURES[] = "$id $desc".($detail !== '' ? ' | '.$detail : '');
        echo '  [FAIL] '.$id.' '.$desc.($detail !== '' ? ' | '.$detail : '')."\n";
    }
}

function http(string $host, string $uri): array
{
    global $kernel;
    $request = Illuminate\Http\Request::create(
        $uri,
        'GET',
        [],
        [],
        [],
        ['HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'text/html,application/json;q=0.9,*/*;q=0.8']
    );
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    // 单进程连打四象限：必须清干净上下文，否则上一个象限的站点/语言会串到下一个
    Illuminate\Support\Facades\DB::disconnect();
    App\Support\Localization\LocaleContext::clear();
    App\Support\SiteContext::clear();
    Fact::flushMemo();

    return [
        'status' => $response->getStatusCode(),
        'body'   => (string) $response->getContent(),
    ];
}

echo "== 基线 ==\n";
echo 'GIT_HEAD: '.trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD'))."\n";
echo 'DB: '.$db."\n\n";

// ---------------------------------------------------------------- 播种
$quad = [
    'A|zh' => ['site' => 'g3a', 'locale' => 'zh-CN', 'prefix' => '',    'mark' => 'AZH', 'cat' => 'g3cat-azh'],
    'A|en' => ['site' => 'g3a', 'locale' => 'en',    'prefix' => '/en', 'mark' => 'AEN', 'cat' => 'g3cat-aen'],
    'B|zh' => ['site' => 'g3b', 'locale' => 'zh-CN', 'prefix' => '',    'mark' => 'BZH', 'cat' => 'g3cat-bzh'],
    'B|en' => ['site' => 'g3b', 'locale' => 'en',    'prefix' => '/en', 'mark' => 'BEN', 'cat' => 'g3cat-ben'],
];

$siteIds = [];
foreach ([['g3a', $HOST_A], ['g3b', $HOST_B]] as [$slug, $domain]) {
    $site = Site::query()->firstWhere('slug', $slug);

    if (! $site) {
        /**
         * `sites.is_default` 是唯一约束，迁移已建了一个 default 站。
         * A 站直接复用它（改domain 即可），B 站才是新建 ——
         * 否则第二次跑播种会撞「default 站已存在」。
         */
        if ($slug === 'g3a') {
            $site = Site::query()->where('is_default', true)->first();
            if (! $site) {
                $site = Site::query()->create([
                    'slug' => $slug, 'name' => 'G3A', 'domain' => $domain,
                    'status' => 'active', 'is_default' => true,
                ]);
            } else {
                $site->update(['slug' => $slug, 'name' => 'G3A']);
            }
        } else {
            $site = Site::query()->create([
                'slug' => $slug, 'name' => 'G3B', 'domain' => $domain,
                'status' => 'active', 'is_default' => false,
            ]);
        }
    }

    $site->update(['domain' => $domain, 'status' => 'active']);
    $siteIds[$slug] = $site->id;
}

// 清掉上一轮播种，保证可重跑
foreach (['G3-FACT-1', 'G3-FACT-2'] as $k) {
    Fact::withoutSiteScope()->where('key', $k)->delete();
}
Content::withoutSiteScope()->where('slug', 'g3-article')->delete();
Entity::withoutSiteScope()->where('slug', 'g3-prod')->delete();
Category::withoutSiteScope()->whereIn('slug', array_column($quad, 'cat'))->delete();

foreach ($quad as $qname => $q) {
    [$siteTag] = explode('|', $qname);
    $site = Site::query()->find($siteIds[$q['site']]);

    SiteContext::setSite($site);
    Setting::flush();
    Catalog::flush();
    LocaleContext::set($q['locale']);

    // 语言能力：双语站
    Setting::set('site_supported_locales', ['zh-CN', 'en']);
    Setting::set('site_default_locale', 'zh-CN');
    Setting::set('geo_org_name', $q['mark'].' Org');

    $cat = Category::create([
        'name' => $q['mark'].' 栏目', 'slug' => $q['cat'],
        'type' => 'list', 'is_active' => true,
    ]);

    // ---- facts：同 key 两条中英行，共享 translation_group ----
    $group = Fact::groupForKey('G3-FACT-1');
    $label = $q['locale'] === 'en' ? $q['mark'].' Label' : $q['mark'].' 标签';
    $value = $q['locale'] === 'en' ? $q['mark'].'-value-EN' : $q['mark'].'-值-ZH';
    Fact::create([
        'key' => 'G3-FACT-1', 'label' => $label, 'value' => $value,
        'group' => 'g3', 'is_public' => true, 'sort' => 1,
        'locale' => $q['locale'], 'translation_group' => $group,
    ]);
    Fact::create([
        'key' => 'G3-FACT-2', 'label' => $label.'-2', 'value' => $value.'-2',
        'group' => 'g3', 'is_public' => true, 'sort' => 2,
        'locale' => $q['locale'], 'translation_group' => Fact::groupForKey('G3-FACT-2'),
    ]);

    // ---- content：同 slug，中英共享 translation_group（hreflang 需要） ----
    $catZh = Category::withoutSiteScope()
        ->where('site_id', $site->id)->where('slug', 'g3cat-azh')->first();
    $anchor = Content::withoutSiteScope()
        ->where('slug', 'g3-article')->where('locale', 'zh-CN')
        ->where('site_id', $site->id)->first();
    Content::create([
        'type' => 'article', 'category_id' => $cat->id,
        'title' => $q['mark'].' Article', 'slug' => 'g3-article',
        'summary' => $q['mark'].' summary', 'body' => $q['mark'].' body',
        'status' => 'published', 'published_at' => now()->subDay(),
        'locale' => $q['locale'],
        'translation_group' => $anchor?->translation_group ?: ('TG-G3-'.$siteTag),
    ]);

    // ---- entity：同 slug，中英共享 translation_group ----
    $entAnchor = Entity::withoutSiteScope()
        ->where('slug', 'g3-prod')->where('locale', 'zh-CN')
        ->where('site_id', $site->id)->first();
    Entity::create([
        'type' => 'product', 'slug' => 'g3-prod',
        'name' => $q['mark'].' Product', 'status' => 'published',
        'summary' => $q['mark'].' entity', 'metadata' => ['core' => true],
        'locale' => $q['locale'],
        'translation_group' => $entAnchor?->translation_group ?: ('TG-G3E-'.$siteTag),
    ]);
}

LocaleContext::clear();
Fact::flushMemo();
echo "播种完成：4 象限 × (2 facts + 1 content + 1 entity)\n\n";

// ---------------------------------------------------------------- G1/G2/G3
echo "== G · geo.json facts 语言隔离 ==\n";

$geoBodies = [];
foreach ($quad as $qname => $q) {
    [$siteTag] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;

    $r = http($host, $q['prefix'].'/geo.json');
    $doc = json_decode($r['body'], true);
    $facts = is_array($doc) ? ($doc['facts'] ?? []) : [];
    $geoBodies[$qname] = $r['body'];

    $vals  = array_column($facts, 'value');
    $labs  = array_column($facts, 'label');
    $keys  = array_column($facts, 'key');

    ok('G1.'.$qname, "{$qname} geo.json facts 段存在且非空",
        $r['status'] === 200 && $facts !== [],
        'status='.$r['status'].' facts='.count($facts));

    // 本象限的 value 必须出现
    $own = $q['locale'] === 'en' ? $q['mark'].'-value-EN' : $q['mark'].'-值-ZH';
    ok('G1.'.$qname.'.own', "{$qname} facts 含本象限 value",
        in_array($own, $vals, true),
        '实际 values='.json_encode($vals, JSON_UNESCAPED_UNICODE));

    // 不得出现其它三个象限的 value（跨站 + 跨语言都不许）
    $leaked = [];
    foreach ($quad as $other => $oq) {
        if ($other === $qname) {
            continue;
        }
        $otherVal = $oq['locale'] === 'en' ? $oq['mark'].'-value-EN' : $oq['mark'].'-值-ZH';
        if (in_array($otherVal, $vals, true)) {
            $leaked[] = $other.'='.$otherVal;
        }
    }
    ok('G2.'.$qname, "{$qname} geo.json facts 不含其它象限 value",
        $leaked === [], '泄漏: '.implode(', ', $leaked));

    // label 必须是本语言（有中文的就不该出现在 en 输出里，反之亦然）
    $hasCjk = (bool) preg_grep('/[\x{4e00}-\x{9fa5}]/u', $labs);
    ok('G2.'.$qname.'.label-lang', "{$qname} facts label 语言正确（".$q['locale'].'）',
        $q['locale'] === 'en' ? ! $hasCjk : $hasCjk,
        'labels='.json_encode($labs, JSON_UNESCAPED_UNICODE));
}

echo "\n";

// key 集合在四象限下应一致（语义身份与语言/站点无关）
echo "== G3 · facts key 集合的语义稳定性 ==\n";
$keySets = [];
foreach ($geoBodies as $qname => $body) {
    $doc = json_decode($body, true);
    $k = array_column(is_array($doc) ? ($doc['facts'] ?? []) : [], 'key');
    sort($k);
    $keySets[$qname] = $k;
}
$refSet = $keySets['A|zh'];
foreach ($keySets as $qname => $set) {
    ok('G3.'.$qname, "{$qname} facts key 集合与 A|zh 一致（语义身份不随语言/站点变）",
        $set === $refSet,
        '实际='.json_encode($set).' 期望='.json_encode($refSet));
}
echo "\n";

// ---------------------------------------------------------------- G4 fallback
echo "== G4 · 禁止跨语言 fallback ==\n";

// 删掉 A 站 G3-FACT-2 的英文行
Fact::withoutSiteScope()
    ->where('key', 'G3-FACT-2')->where('locale', 'en')
    ->where('site_id', $siteIds['g3a'])->delete();
Fact::flushMemo();

$rEn = http($HOST_A, '/en/geo.json');
$docEn = json_decode($rEn['body'], true);
$enKeys = array_column(is_array($docEn) ? ($docEn['facts'] ?? []) : [], 'key');
$rZh = http($HOST_A, '/geo.json');
$docZh = json_decode($rZh['body'], true);
$zhKeys = array_column(is_array($docZh) ? ($docZh['facts'] ?? []) : [], 'key');

ok('G4.no-fallback', 'A|en 缺 G3-FACT-2 翻译时不得回退到中文行',
    ! in_array('G3-FACT-2', $enKeys, true),
    'en keys='.json_encode($enKeys));
ok('G4.zh-intact', 'A|zh 的中文行仍完整存在',
    in_array('G3-FACT-2', $zhKeys, true),
    'zh keys='.json_encode($zhKeys));

echo "\n";

// ---------------------------------------------------------------- L1/L2 llms.txt
echo "== L · llms.txt 内容语言隔离 ==\n";

$llmsBodies = [];
foreach ($quad as $qname => $q) {
    [$siteTag] = explode('|', $qname);
    $host = $siteTag === 'A' ? $HOST_A : $HOST_B;

    $r = http($host, $q['prefix'].'/llms.txt');
    $llmsBodies[$qname] = $r['body'];

    $own = $q['mark'].' Article';
    $leaked = [];
    foreach ($quad as $other => $oq) {
        if ($other !== $qname && str_contains($r['body'], $oq['mark'].' Article')) {
            $leaked[] = $other;
        }
    }

    ok('L1.'.$qname, "{$qname} llms.txt 含本象限文章 {$own}",
        $r['status'] === 200 && str_contains($r['body'], $own),
        'status='.$r['status']);
    ok('L1.'.$qname.'.clean', "{$qname} llms.txt 不含其它象限文章",
        $leaked === [], '泄漏: '.implode(',', $leaked));
}

echo "\n";

// 四象限 llms.txt 两两不同
$keys = array_keys($llmsBodies);
$dupPairs = [];
for ($i = 0; $i < count($keys); $i++) {
    for ($j = $i + 1; $j < count($keys); $j++) {
        if ($llmsBodies[$keys[$i]] === $llmsBodies[$keys[$j]]) {
            $dupPairs[] = $keys[$i].' == '.$keys[$j];
        }
    }
}
ok('L2.distinct', '四种象限的 llms.txt 两两不同（site 与 locale 都生效）',
    $dupPairs === [], '相同: '.implode('; ', $dupPairs));

echo "\n== 汇总 ==\n";
echo "PASS: {$PASS}  FAIL: {$FAIL}\n";
if ($FAILURES !== []) {
    echo "\n失败明细:\n";
    foreach ($FAILURES as $f) {
        echo '  - '.$f."\n";
    }
}

exit($FAIL === 0 ? 0 : 1);
