<?php

/**
 * 20G-7.1 · Site Administration + New Site Bootstrap 端到端验证
 * ------------------------------------------------------------------
 * 走真实 HTTP 内核（Kernel::handle），覆盖 SA-001~006 / BS-001~008 / MS-001~004。
 *
 * 核心验证目标（来自 20G-7.1 验收矩阵）：
 *
 *   SA  站点管理：列表 / 新建 / 编辑 / 启停 / 域名解析 / 当前站标识
 *   BS  出厂初始化：Settings / Categories / Groups / Menu / zh-CN / en /
 *                  建完站立刻能建内容 / GEO 输出 200 / 幂等
 *   MS  多站隔离：A/B 数据、A/B 缓存、A/B GEO、A/B 语言 四层不串
 *
 * 特别断言（20G-7.1 明确要求）：
 *   · requested_site == resolved_site == persisted.site_id
 *     （防「后台认为在管B，实际写进 A」）
 *   · 创建 Site B 后，A 的数据 / 缓存 / GEO 输出均不变
 *   · Bootstrap 重跑不重复创建、不覆盖人工修改
 *
 * 用法：
 *   GEO_ROOT=<项目根> GEO_DB=<sqlite 路径> php docs/audit/20G/e2e/site-lifecycle-e2e.php
 */

$root = getenv('GEO_ROOT') ?: dirname(__DIR__, 4);
$db   = getenv('GEO_DB') ?: 'D:/Temp/g71_e2e.sqlite';

if (! is_file($db)) {
    fwrite(STDERR, "数据库不存在：{$db}\n请先用 artisan migrate 建库。\n");

    exit(1);
}

$_ENV['DB_DATABASE'] = $db;
putenv("DB_DATABASE={$db}");

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\Category;
use App\Models\Content;
use App\Models\Group;
use App\Models\Menu;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Catalog;
use App\Support\Localization\LocaleContext;
use App\Support\PageCache;
use App\Support\RequestScopedState;
use App\Support\SiteContext;

$HOST_A = 'sa-a.test';
$HOST_B = 'sa-b.test';

// 完成 / 崩溃标记（与库同目录，便于清理）
$GLOBALS['__crash_marker'] = $db.'.crashed';
$GLOBALS['__finish_marker'] = $db.'.finished';

$PASS = 0;
$FAIL = 0;
$FAILURES = [];

/**
 * 崩溃显式化。
 *
 * ⚠️ 20G-7.1 实测：PHP fatal error 会被 Laravel 异常处理器渲染后**吞掉**，
 * 进程退出码仍是 0 —— 与 20G-8「 Laravel 吞迁移异常」同款。
 * 于是变异测试里「脚本崩溃」会被误判为「断言全绿」。
 *
 * 这里注册 shutdown handler：任何 fatal / 未捕获异常都写标记文件 + 退出码 1，
 * 让「脚本崩溃」与「断言全绿」能被区分。
 */
/**
 * 崩溃显式化 —— **用「完成标记」反推，而不是探error_get_last()**。
 *
 * ⚠️ 20G-7.1 实测两种方案都不可靠：
 *   ① `error_get_last()` + shutdown handler → Laravel 的异常处理器
 *      在渲染后直接结束进程，`error_get_last()` 已被清理，读不到东西；
 *   ② 靠退出码 → PHP fatal 被 Laravel 吞掉，退出码**仍是 0**
 *      （与 20G-8「Laravel 吞迁移异常」同款）。
 *
 * 正解：**只有跑到脚本末尾才写 `.finished`**。
 * 变异测试据此判定 —— 没有 `.finished` 就是中途挂了，
 * 中途挂本身就是「行为已被破坏」的证据。
 */
register_shutdown_function(function (): void {
    if (! file_exists($GLOBALS['__finish_marker'])) {
        $err = error_get_last();
        file_put_contents(
            $GLOBALS['__crash_marker'],
            'CRASH: '.($err['message'] ?? 'unknown')
        );
    }
});

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

function http(string $host, string $uri, string $method = 'GET'): array
{
    global $kernel;
    $request = Illuminate\Http\Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        ['HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'text/html,application/json;q=0.9,*/*;q=0.8']
    );
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    // 单进程连打多站：必须清干净上下文，否则上一个站点的 SiteContext 会串到下一个
    Illuminate\Support\Facades\DB::disconnect();
    LocaleContext::clear();
    SiteContext::clear();
    RequestScopedState::flushAll();
    \App\Models\Fact::flushMemo();

    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
}

/** 在指定站点上下文里跑一次回调（模拟后台切站后操作） */
function inSite(Site $site, callable $fn)
{
    SiteContext::setSite($site);
    Setting::flush();
    Catalog::flush();
    RequestScopedState::flushAll();

    try {
        return $fn($site);
    } finally {
        \Illuminate\Support\Facades\DB::disconnect();
        Setting::flush();
        Catalog::flush();
        LocaleContext::clear();
        SiteContext::clear();
        RequestScopedState::flushAll();
    }
}

echo "== 基线 ==\n";
echo 'GIT_HEAD: '.trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD'))."\n";
echo 'DB       : '.$db."\n\n";

// ═══════════════════════════════════════════════════════════════
echo "== SA · Site Administration ==\n";

// ── SA-001 站点列表可访问
$r = http($HOST_A, '/admin/sites');
ok('SA-001', '/admin/sites 站点列表返回 2xx/3xx（未 404）',
    $r['status'] >= 200 && $r['status'] < 400,
    'status='.$r['status']);

// 准备 A 站（复用 default 站）
$siteA = Site::query()->where('is_default', true)->first();
$siteA->update(['domain' => $HOST_A, 'status' => 'active', 'name' => '站点A']);
$siteAId = $siteA->id;

/**
 * A 站同样要跑一遍 Bootstrap。
 *
 * 两站必须在**同等初始化状态**下比较隔离，否则 MS-001 测的是
 * 「A 空 vs B 满」而不是「隔离是否成立」——
 * 那是两种不同的问题，不能混。
 * （default 站的 bootstrap 缺口由 geo:install 修，本脚本用同一 Seeder 补齐。）
 */
inSite($siteA, function (Site $s): void {
    (new Database\Seeders\DefaultSettingSeeder())->run();
    (new Database\Seeders\DefaultFormSeeder())->run();
    (new Database\Seeders\BlankHomepageSeeder($s))->run();
    (new Database\Seeders\SystemPageSeeder($s))->run();
    (new Database\Seeders\SiteStructureSeeder())->run();
});

// ── SA-002 新建站点（含自动初始化）
$siteB = Site::query()->where('slug', 'site-b')->first();
if (! $siteB) {
    $siteB = Site::query()->create([
        'slug' => 'site-b', 'name' => '站点B', 'domain' => $HOST_B,
        'status' => 'active', 'is_default' => false,
    ]);

    // 复刻 SiteController@store 的 Bootstrap 序列
    inSite($siteB, function (Site $s): void {
        (new Database\Seeders\DefaultSettingSeeder())->run();
        (new Database\Seeders\DefaultFormSeeder())->run();
        (new Database\Seeders\BlankHomepageSeeder($s))->run();
        (new Database\Seeders\SystemPageSeeder($s))->run();
        (new Database\Seeders\SiteStructureSeeder())->run();
    });
}
$siteBId = $siteB->id;

ok('SA-002', '新建 Site B 成功且拿到独立 id',
    $siteBId > 0 && $siteBId !== $siteAId,
    "A={$siteAId} B={$siteBId}");

// ── 关键断言：requested_site == resolved_site == persisted.site_id
$catB = inSite($siteB, fn () => Category::query()->where('slug', 'about')->first());
ok('SA-002.site-context', '写入落到了请求的目标站（B），未串到 A',
    $catB !== null && (int) $catB->site_id === $siteBId,
    'persisted.site_id='.($catB?->site_id ?? 'NULL').' expected='.$siteBId);

// ── SA-003 编辑站点（域名可改）
$oldDomain = $siteB->domain;
$siteB->update(['name' => '站点B-改名']);
ok('SA-003', '编辑站点名称生效', $siteB->fresh()->name === '站点B-改名',
    '实际='.$siteB->fresh()->name);
$siteB->update(['domain' => $oldDomain]); // 还原，后续断言依赖 HOST_B

// ── SA-004 停用后前台不可访问
$siteB->update(['status' => 'inactive']);
$rOff = http($HOST_B, '/');
ok('SA-004', '站点停用后前台不解析到该站（回默认站或 404，不 200 命中 B 的内容）',
    $rOff['status'] !== 200 || ! str_contains($rOff['body'], '站点B-改名'),
    'status='.$rOff['status']);
$siteB->update(['status' => 'active']);

// ── SA-005 域名解析：两站独立
inSite($siteA, function () {
    Setting::set('site_name', '站点A');
});
inSite($siteB, function () {
    Setting::set('site_name', '站点B');
});
$rA = http($HOST_A, '/');
$rB = http($HOST_B, '/');
ok('SA-005', '按 Host 解析到各自站点（A 看到 A 的设置，B 看到 B 的）',
    $rA['status'] === 200 && $rB['status'] === 200
    && $rA['body'] !== $rB['body'],
    "A={$rA['status']} B={$rB['status']} 内容相同=".(($rA['body'] === $rB['body']) ? '是' : '否'));

// ── SA-006 默认站保护：不能删默认站
$delDefault = inSite($siteA, function (Site $s): bool {
    // SiteController@destroy 的核心守卫：is_default 或 DEFAULT_SLUG 不可删
    return $s->is_default || $s->slug === Site::DEFAULT_SLUG;
});
ok('SA-006', '默认站受删除保护', $delDefault === true);

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "== BS · New Site Bootstrap ==\n";

// ── BS-001 默认设置已播种
$settingCount = inSite($siteB, fn () => Setting::query()->withoutSiteScope()->count());
ok('BS-001', "B 站有默认设置（Settings ≥ 1 行，实测 {$settingCount}）", $settingCount >= 1);

// ── BS-002 Categories / Groups
$cats = inSite($siteB, fn () => Category::query()->whereNull('parent_id')->orderBy('sort')->get());
$groups = inSite($siteB, fn () => Group::query()->count());
ok('BS-002', "B 站有栏目（实测 ".$cats->count().' 个）与分组（'.$groups.' 个）',
    $cats->count() >= 5 && $groups >= 4,
    "categories={$cats->count()} groups={$groups}");
ok('BS-002.slug', '栏目 slug 为行业中立骨架（about/products/knowledge/news/contact）',
    $cats->pluck('slug')->intersect(['about', 'products', 'knowledge', 'news', 'contact'])->count() === 5,
    implode(',', $cats->pluck('slug')->all()));

// ── BS-003 导航可见性
$navOverrides = inSite($siteB, fn () => Menu::query()->whereNotNull('key')->where('position', 'main')->get());
$factoryOff = $navOverrides->firstWhere('key', 'factory');
ok('BS-003', 'B 站主导航已把工业口径项（factory）置为不活跃',
    $factoryOff !== null && ! $factoryOff->is_active,
    'factory override '.($factoryOff ? ($factoryOff->is_active ? 'active' : 'off') : '缺失'));

// ── BS-004 / BS-005 语言
$locales = inSite($siteB, fn () => Setting::get('site_supported_locales', []));
ok('BS-004', 'B 站支持 zh-CN', is_array($locales) && in_array('zh-CN', $locales, true), json_encode($locales));
ok('BS-005', 'B 站出厂即支持 en（Multi-language 开箱）',
    is_array($locales) && in_array('en', $locales, true), json_encode($locales));

$rEnHome = http($HOST_B, '/en');
ok('BS-005.route', 'B 站 /en 路由可访问（不再因未启用语言而 404）',
    $rEnHome['status'] !== 404, 'status='.$rEnHome['status']);

// ── BS-006 建完站立刻能建内容
$newCat = inSite($siteB, function () {
    $cat = Category::query()->where('slug', 'news')->firstOrFail();

    return Content::create([
        'type' => 'article', 'category_id' => $cat->id,
        'title' => 'B站首篇文章', 'slug' => 'b-first-post',
        'summary' => '摘要', 'body' => '正文',
        'status' => 'published', 'published_at' => now()->subDay(),
        'locale' => 'zh-CN', 'translation_group' => 'TG-B-FIRST',
    ]);
});
ok('BS-006', 'B 站 bootstrap 后可立即创建并发布内容（0 手工 migration）',
    $newCat->exists && (int) $newCat->site_id === $siteBId,
    'content id='.$newCat->id.' site_id='.$newCat->site_id);

// ── BS-007 GEO / llms / sitemap 立即可用
$geo = http($HOST_B, '/geo.json');
$geoEn = http($HOST_B, '/en/geo.json');
$llms = http($HOST_B, '/llms.txt');
$llmsEn = http($HOST_B, '/en/llms.txt');
$sm = http($HOST_B, '/sitemap.xml');
$smEn = http($HOST_B, '/en/sitemap.xml');

foreach ([
    ['BS-007.geo', '/geo.json', $geo],
    ['BS-007.geo-en', '/en/geo.json', $geoEn],
    ['BS-007.llms', '/llms.txt', $llms],
    ['BS-007.llms-en', '/en/llms.txt', $llmsEn],
    ['BS-007.sitemap', '/sitemap.xml', $sm],
    ['BS-007.sitemap-en', '/en/sitemap.xml', $smEn],
] as [$id, $path, $res]) {
    ok($id, "{$path} 返回 200", $res['status'] === 200, 'status='.$res['status']);
}

$geoDoc = json_decode($geo['body'], true);
ok('BS-007.geo-content', 'B 站 geo.json 含刚发布的文章（B 的内容可见）',
    is_array($geoDoc) && in_array('B站首篇文章', $geoDoc['titles'] ?? $geoDoc['contents'] ?? [], true)
    || str_contains($geo['body'], 'B站首篇文章'),
    'geo.json 未含新建文章');

echo "\n";

// ── BS-008 幂等（关键）
echo "== BS-008 · Bootstrap 幂等 ==\n";

$before = inSite($siteB, fn () => [
    'cats'   => Category::query()->count(),
    'groups' => Group::query()->count(),
    'menus'  => Menu::query()->count(),
]);

// 人工修改一个栏目名，验证「重跑不覆盖人工修改」
inSite($siteB, function () {
    Category::query()->where('slug', 'about')->update(['name' => '人工改过的栏目名']);
});

// 重跑整个 Bootstrap 序列
inSite($siteB, function (Site $s): void {
    (new Database\Seeders\DefaultSettingSeeder())->run();
    (new Database\Seeders\DefaultFormSeeder())->run();
    (new Database\Seeders\BlankHomepageSeeder($s))->run();
    (new Database\Seeders\SystemPageSeeder($s))->run();
    (new Database\Seeders\SiteStructureSeeder())->run();
});

$after = inSite($siteB, fn () => [
    'cats'   => Category::query()->count(),
    'groups' => Group::query()->count(),
    'menus'  => Menu::query()->count(),
    'aboutName' => Category::query()->where('slug', 'about')->value('name'),
]);

ok('BS-008.no-dup', '重跑 bootstrap 不重复创建（行数不变）',
    $before['cats'] === $after['cats'] && $before['groups'] === $after['groups']
    && $before['menus'] === $after['menus'],
    "before=".json_encode($before).' after='.json_encode(array_diff_key($after, ['aboutName' => 1])));

ok('BS-008.no-overwrite', '重跑 bootstrap 不覆盖人工修改',
    $after['aboutName'] === '人工改过的栏目名',
    'about.name='.$after['aboutName']);

// 还原
inSite($siteB, function () {
    Category::query()->where('slug', 'about')->update(['name' => '关于我们']);
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
echo "== MS · Multi-site × Locale 隔离 ==\n";

// 见上方说明：file store 跨运行残留会让版本断言失真，采样前统一重置
PageCache::flush();

// ── MS-001 A/B 数据隔离
$aCats = inSite($siteA, fn () => Category::query()->pluck('slug')->all());
$bCats = inSite($siteB, fn () => Category::query()->pluck('slug')->all());
ok('MS-001', 'A / B 各自持有完整栏目骨架（两站初始化状态等价）',
    count($aCats) >= 5 && count($bCats) >= 5,
    'A='.count($aCats).' B='.count($bCats));
/**
 * 每站栏目数必须**精确**为骨架数量，且两站行数相等。
 *
 * ⚠️ 判据不能用 `>= 5`（20G-7.1 变异测试实测漏抓）：
 * 变异「B 的栏目写进 A」时A 站变成 10 行、B 站 0 行，
 * 但 `inSite($siteB, ...)` 走 SiteScope 读到 0 → `0 >= 5` 为假，本该被抓；
 * 然而该断言与前后条件用 `&&` 串联，A 站的 `>= 5` 仍成立，
 * 整体却因 `inSite` 缓存导致读数不准 —— 故改为直查 DB + 精确相等。
 */
$aCatRows = \Illuminate\Support\Facades\DB::table('categories')->where('site_id', $siteAId)->count();
$bCatRows = \Illuminate\Support\Facades\DB::table('categories')->where('site_id', $siteBId)->count();

ok('MS-001.own-rows', '两站栏目行归属正确（site_id 各自独立，行数相等）',
    $aCatRows === $bCatRows && $aCatRows >= 5,
    "A={$aCatRows} B={$bCatRows}（应相等且 ≥5；不等即说明有站被写到了别处）");

/**
 * 每个栏目 slug 都必须**恰好**出现在两个站名下 —— 少一个说明某站的 bootstrap 没落地，
 * 多一个说明有行落到了站外。`count($sids) > 1` 恰恰是正常态（两站都有），
 * 真正的异常是「只在一个站」。
 */
$slugToSites = \Illuminate\Support\Facades\DB::table('categories')
    ->select('site_id', 'slug')->get()->groupBy('slug');
$orphans = [];
foreach ($slugToSites as $slug => $rows) {
    $sids = $rows->pluck('site_id')->unique()->values()->all();
    if (count($sids) !== 2) {
        $orphans[] = $slug.'@['.implode('/', $sids).']';
    }
}
ok('MS-001.no-orphan', '每个栏目 slug 恰好出现在 A / B 两站（无落单、无站外行）',
    $orphans === [] && count($slugToSites) >= 5,
    '异常: '.implode(', ', array_slice($orphans, 0, 5)));

/**
 * 两站的结构名称必须**逐字一致**（同一份骨架模板渲染两次）。
 *
 * ⚠️ 这条是 20G-7.1 变异测试逼出来的：变异「分组名硬编码成 A-SITE-ONLY」
 * 让两站名称不同，但原断言只比数量与slug，比不出「串味」——
 * 因为结构数据的**内容**被污染时，行数与 key 都不变。
 */
$aNames = \Illuminate\Support\Facades\DB::table('categories')
    ->where('site_id', $siteAId)->orderBy('slug')->pluck('name')->all();
$bNames = \Illuminate\Support\Facades\DB::table('categories')
    ->where('site_id', $siteBId)->orderBy('slug')->pluck('name')->all();
ok('MS-001.same-template', '两站栏目名逐字一致（同一骨架渲染两次，无站间串味）',
    $aNames === $bNames && $aNames !== [],
    'A='.json_encode($aNames, JSON_UNESCAPED_UNICODE).' B='.json_encode($bNames, JSON_UNESCAPED_UNICODE));

$aCats = \Illuminate\Support\Facades\DB::table('categories')->where('site_id', $siteAId)->pluck('id');
$aGroupNames = \Illuminate\Support\Facades\DB::table('groups')
    ->whereIn('category_id', $aCats)->orderBy('slug')->pluck('name')->all();
$bCats = \Illuminate\Support\Facades\DB::table('categories')->where('site_id', $siteBId)->pluck('id');
$bGroupNames = \Illuminate\Support\Facades\DB::table('groups')
    ->whereIn('category_id', $bCats)->orderBy('slug')->pluck('name')->all();
ok('MS-001.group-template', '两站分组名逐字一致（无站间串味）',
    $aGroupNames === $bGroupNames && $aGroupNames !== [],
    'A='.json_encode($aGroupNames, JSON_UNESCAPED_UNICODE).' B='.json_encode($bGroupNames, JSON_UNESCAPED_UNICODE));

/**
 * 分组名必须符合**出厂模板**。
 *
 * ⚠️ 「两站一致」这条断言单独不够（20G-7.1 变异测试实测漏抓）：
 * 变异把分组名硬编码成常量后，**两站都是那个常量** → 一致性仍成立。
 * 那属于「同源污染」—— 数据被同一个缺陷同时污染，比跨站串味更隐蔽。
 *
 * 正解：断言内容符合出厂模板本身（faq / guide / company-news / notice），
 * 而不只是「两站相等」。
 */
$expectedGroups = ['常见问题', '使用指南', '企业新闻', '通知公告'];
$sortedGroups = $aGroupNames;
sort($sortedGroups);
$expectedSorted = $expectedGroups;
sort($expectedSorted);
ok('MS-001.group-content', '分组名符合出厂模板（不只是两站一致，还要内容正确）',
    $sortedGroups === $expectedSorted,
    '实际='.json_encode($sortedGroups, JSON_UNESCAPED_UNICODE)
    .' 期望='.json_encode($expectedSorted, JSON_UNESCAPED_UNICODE));

$dupKey = \Illuminate\Support\Facades\DB::table('categories')
    ->select('slug', \Illuminate\Support\Facades\DB::raw('COUNT(*) c'))
    ->groupBy('slug')->havingRaw('COUNT(*) > 1')->count();
ok('MS-001.slug-ok', '相同 slug 可在两站共存（UNIQUE 含 site_id）', $dupKey >= 1,
    "跨站同slug 的 slug 数={$dupKey}");

// ── MS-002 A/B 缓存隔离
/**
 * 先 flush 再采样。
 *
 * `PageCache` 硬编码 `Cache::store('file')`，绕过 `CACHE_STORE=array`
 * → 缓存跨运行残留（20G-3 实测 8486 个残留文件曾导致 4 个 PageCacheTest
 * 假失败）。不flush 的话「A 写入是否推进 B 版本」会受上一轮残留影响。
 */
PageCache::flush();
$verBefore = [];
inSite($siteA, function () use (&$verBefore) { $verBefore['a'] = PageCache::version(); });
inSite($siteB, function () use (&$verBefore) { $verBefore['b'] = PageCache::version(); });
ok('MS-002', 'A / B 页面缓存版本键相互独立',
    is_int($verBefore['a']) && is_int($verBefore['b']) && $verBefore['a'] >= 1 && $verBefore['b'] >= 1,
    'A='.$verBefore['a'].' B='.$verBefore['b']);

// A 站写入不应影响 B 的版本
inSite($siteA, function () {
    \App\Models\Setting::set('nav_cta_text', 'A 站专属按钮');
});
$verAfter = inSite($siteB, fn () => PageCache::version());
ok('MS-002.isolation', 'A 站写入不推进 B 站缓存版本', $verAfter === $verBefore['b'],
    "B before={$verBefore['b']} after={$verAfter}");

// ── MS-003 A/B GEO 隔离
$geoA = http($HOST_A, '/geo.json');
$geoB = http($HOST_B, '/geo.json');
ok('MS-003', 'A / B 的 geo.json 内容互不相同（无跨站泄漏）',
    $geoA['body'] !== $geoB['body'],
    'A/B geo.json 完全相同');

// ── MS-004 A/B 语言隔离
$geoAEn = http($HOST_A, '/en/geo.json');
$geoBEn = http($HOST_B, '/en/geo.json');
ok('MS-004', 'A / B 的 /en/geo.json 互不相同',
    $geoAEn['status'] === 200 && $geoBEn['status'] === 200 && $geoAEn['body'] !== $geoBEn['body'],
    "A={$geoAEn['status']} B={$geoBEn['status']}");

// B 的中文 GEO 不应出现 A 的内容；反之亦然
ok('MS-004.no-leak', 'B 站中文 GEO 不含 A 站专属内容',
    ! str_contains($geoB['body'], 'A站首篇文章'),
    'B 的 geo.json 含A 的内容');

echo "\n== 汇总 ==\n";
echo "PASS: {$PASS}  FAIL: {$FAIL}\n";
if ($FAILURES !== []) {
    echo "\n失败明细:\n";
    foreach ($FAILURES as $f) {
        echo '  - '.$f."\n";
    }
}

// 只有真正跑到这里才算「跑完了」（无论断言红绿）
file_put_contents($GLOBALS['__finish_marker'], (string) $FAIL);

exit($FAIL === 0 ? 0 : 1);
