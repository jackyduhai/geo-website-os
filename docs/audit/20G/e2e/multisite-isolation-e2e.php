<?php
/**
 * 20G-5 · 真实双站 Multi-site 隔离 E2E（真实 HTTP 栈取证）
 * ==================================================================
 * 为什么不用 Laravel Feature Test：
 *   TestCase 的 $this->get() / withHeaders(['Host'=>]) 无法覆盖 Request::getHost()
 *   （实测三种方式均失效，getHost() 恒为 localhost），
 *   导致 Host 驱动的站点解析无法在测试环境验证。
 *   因此本取证走**真实 HTTP 内核**（Kernel::handle），与生产链路一致。
 *
 * 隔离链路：Host → ResolveSite → SiteContext → Controller → Service
 *          → Model Query(SiteScope) → Cache → GEO Output
 *
 * 7 维度 + Negative Test。
 */

$db = getenv('GEO_DB') ?: 'D:/Temp/gw_e2e.sqlite';
$_ENV['DB_DATABASE'] = $db;
putenv("DB_DATABASE=$db");
require getenv('GEO_ROOT') . '/vendor/autoload.php';
$app = require_once getenv('GEO_ROOT') . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['database.connections.sqlite.database' => $db, 'site.default_fallback' => false]);
Illuminate\Support\Facades\DB::purge('sqlite');

use App\Support\SiteContext;
use App\Support\Catalog;
use App\Support\PageCache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

const A_CANARY = 'AAA-CANARY-A';
const B_CANARY = 'BBB-CANARY-B';

/** 走真实 HTTP 内核发起请求 */
function http($kernel, string $method, string $uri, string $host, array $data = [], ?string $token = null, array $headers = [])
{
    $server = ['HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'application/json'];
    if ($token) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    foreach ($headers as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }

    $request = Request::create(
        $uri,
        $method,
        $method === 'GET' ? [] : $data,
        [],
        [],
        $server,
        $method === 'GET' ? null : json_encode($data, JSON_UNESCAPED_UNICODE)
    );

    return $kernel->handle($request);
}

/** 每个用例前复位进程内静态状态 */
function resetState(): void
{
    SiteContext::clear();
    Catalog::flush();
}

$pass = 0;
$fail = 0;
$results = [];

function check(string $dim, string $id, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $results;
    $ok ? $pass++ : $fail++;
    $results[] = ['dim' => $dim, 'id' => $id, 'ok' => $ok, 'detail' => $detail];
    printf("  %s [%s] %-6s %s%s\n", $ok ? '✅' : '❌', $dim, $id, '', $ok ? '' : "← $detail");
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  20G-5 · 真实双站 Multi-site 隔离 E2E（真实 HTTP 内核）                ║\n";
echo "║  库: D:/Temp/gw_e2e.sqlite   A=a.test(#1)   B=b.test(#2)                ║\n";
echo "║  canary: A=".A_CANARY."  B=".B_CANARY."            ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

// ═══════════════════════════════════════════════════════════════
// 维度 1 · MS-ROUTING —— Host 解析
// ═══════════════════════════════════════════════════════════════
echo "── MS-ROUTING · Host 解析与 canonical ──\n";

resetState();
$r = http($kernel, 'GET', '/llms.txt', 'a.test');
check('ROUTING', '001', $r->getStatusCode() === 200 && str_contains($r->getContent(), A_CANARY),
    "code={$r->getStatusCode()}；A 站响应未含 A 的 canary");

// 注意：ResolveSite 在 finally 中 clear()，请求外读 SiteContext 只会拿到惰性兜底。
// 因此站点归属必须用「响应内容」判定，而非请求外的上下文快照。
resetState();
$r = http($kernel, 'GET', '/llms.txt', 'b.test');
$body = $r->getContent();
check('ROUTING', '002', $r->getStatusCode() === 200 && str_contains($body, B_CANARY),
    "code={$r->getStatusCode()}；B 站响应未含 B 的 canary");

// 同进程连续切站
resetState();
$aBody = http($kernel, 'GET', '/llms.txt', 'a.test')->getContent();
$bBody = http($kernel, 'GET', '/llms.txt', 'b.test')->getContent();
$resolvedA = str_contains($aBody, A_CANARY);
$resolvedB = str_contains($bBody, B_CANARY);
check('ROUTING', '003', $resolvedA && $resolvedB,
    "a→" . ($resolvedA ? 'A站' : '未识别') . " b→" . ($resolvedB ? 'B站' : '未识别'));

// canonical 随 Host
resetState();
$htmlA = http($kernel, 'GET', '/', 'a.test')->getContent();
resetState();
$htmlB = http($kernel, 'GET', '/', 'b.test')->getContent();
$canonA = preg_match('/<link rel="canonical" href="([^"]+)"/', $htmlA, $mA) ? $mA[1] : '';
$canonB = preg_match('/<link rel="canonical" href="([^"]+)"/', $htmlB, $mB) ? $mB[1] : '';
check('ROUTING', '004', str_contains($canonA, 'a.test') && str_contains($canonB, 'b.test'),
    "A='{$canonA}' B='{$canonB}'");

// 未知 Host 被拒
resetState();
$r = http($kernel, 'GET', '/', 'evil.example.com');
check('ROUTING', '005', $r->getStatusCode() === 404, "code={$r->getStatusCode()}");

// ═══════════════════════════════════════════════════════════════
// 维度 2 · MS-CONTENT —— 内容隔离
// ═══════════════════════════════════════════════════════════════
echo "\n── MS-CONTENT · 内容隔离 ──\n";

resetState();
http($kernel, 'GET', '/', 'a.test');
$htmlA = http($kernel, 'GET', '/llms.txt', 'a.test')->getContent();
resetState();
$htmlB = http($kernel, 'GET', '/llms.txt', 'b.test')->getContent();
check('CONTENT', '001', str_contains($htmlA, A_CANARY) && ! str_contains($htmlA, B_CANARY),
    'A 站 llms.txt 混入 B 站 canary');

// Negative Test：A 站访问 B 站文章
resetState();
$r = http($kernel, 'GET', '/b-only-article', 'a.test');
check('CONTENT', '002', $r->getStatusCode() === 404, "code={$r->getStatusCode()}");

// Negative Test：B 站访问 A 站文章
resetState();
$r = http($kernel, 'GET', '/a-only-article', 'b.test');
check('CONTENT', '003', $r->getStatusCode() === 404, "code={$r->getStatusCode()}");

// A 站看到自己的文章
resetState();
$r = http($kernel, 'GET', '/a-only-article', 'a.test');
check('CONTENT', '004', $r->getStatusCode() === 200, "code={$r->getStatusCode()}");

// ═══════════════════════════════════════════════════════════════
// 维度 3 · MS-TAXONOMY —— 栏目 / 菜单
// ═══════════════════════════════════════════════════════════════
echo "\n── MS-TAXONOMY · 栏目 / 菜单 ──\n";

resetState();
$navA = http($kernel, 'GET', '/', 'a.test')->getContent();
resetState();
$navB = http($kernel, 'GET', '/', 'b.test')->getContent();
check('TAXONOMY', '001', ! str_contains($navA, B_CANARY) && ! str_contains($navB, A_CANARY),
    '首页渲染混入他站 canary');

// ═══════════════════════════════════════════════════════════════
// 维度 4 · MS-FACTS —— 事实库
// ═══════════════════════════════════════════════════════════════
echo "\n── MS-FACTS · 事实库与品牌信息 ──\n";

resetState();
$geoA = json_decode(http($kernel, 'GET', '/geo.json', 'a.test')->getContent(), true);
resetState();
$geoB = json_decode(http($kernel, 'GET', '/geo.json', 'b.test')->getContent(), true);
$rawA = json_encode($geoA, JSON_UNESCAPED_UNICODE);
$rawB = json_encode($geoB, JSON_UNESCAPED_UNICODE);
check('FACTS', '001', str_contains($rawA, A_CANARY) && ! str_contains($rawA, B_CANARY),
    'A 站 geo.json 含 B 站事实');
check('FACTS', '002', str_contains($rawB, B_CANARY) && ! str_contains($rawB, A_CANARY),
    'B 站 geo.json 含 A 站事实');

/**
 * facts 隔离：canary 播种**故意**让两站用同一个 key
 * （`FACT-E2E-SHARED`）+ 不同 label/value —— 这样才能验证
 *「同 key 下两站的 fact 值不串」。
 *
 * 判据因此是「同 key 的 value 必须两两不同」，而不是「key 集合不相交」
 * （后者与canary 设计直接矛盾）或「数量不同」（依赖播种量，脆弱）。
 * 2026-08-03 修正：先前两版判据都是错的。
 */
$valByKey = [];
foreach ([['A', $geoA], ['B', $geoB]] as [$tag, $doc]) {
    foreach (($doc['facts'] ?? []) as $f) {
        $valByKey[$f['key'] ?? ''][$tag] = (string) ($f['value'] ?? '');
    }
}
$sharedOk = true;
$sharedDetail = [];
foreach ($valByKey as $k => $vals) {
    if (count($vals) < 2) {
        continue;   // 只在一站出现（另一站没有这条 fact）—— 也是合法隔离形态
    }
    if (count(array_unique($vals)) !== count($vals)) {
        $sharedOk = false;
        $sharedDetail[] = $k.'='.json_encode($vals, JSON_UNESCAPED_UNICODE);
    }
}
check('FACTS', '003',
    $valByKey !== [] && $sharedOk,
    '同 key 的 fact value 在两站间应互不相同，异常：'
    .(implode(', ', $sharedDetail) ?: '（无共享 key）'));

// entities 名字不同
$aEnts = array_column($geoA['entities'] ?? [], 'name');
$bEnts = array_column($geoB['entities'] ?? [], 'name');
check('FACTS', '004', $aEnts !== $bEnts, '两站实体集相同：' . json_encode($aEnts, JSON_UNESCAPED_UNICODE));

// ═══════════════════════════════════════════════════════════════
// 维度 5 · MS-GEO —— GEO 输出
// ═══════════════════════════════════════════════════════════════
echo "\n── MS-GEO · geo.json / llms.txt / sitemap.xml ──\n";

resetState();
$smA = http($kernel, 'GET', '/sitemap.xml', 'a.test')->getContent();
resetState();
$smB = http($kernel, 'GET', '/sitemap.xml', 'b.test')->getContent();
check('GEO', '001', ! str_contains($smA, B_CANARY) && ! str_contains($smB, A_CANARY),
    'sitemap 混入他站 canary');

resetState();
$llmsA = http($kernel, 'GET', '/llms.txt', 'a.test')->getContent();
resetState();
$llmsB = http($kernel, 'GET', '/llms.txt', 'b.test')->getContent();
check('GEO', '002',
    str_contains($llmsA, 'A 站专属产品') && ! str_contains($llmsA, 'B 站专属产品'),
    'A 站 llms.txt 产品清单越界');
check('GEO', '003',
    str_contains($llmsB, 'B 站专属产品') && ! str_contains($llmsB, 'A 站专属产品'),
    'B 站 llms.txt 产品清单越界');

// feed / robots 也要隔离
resetState();
$feedA = http($kernel, 'GET', '/feed.xml', 'a.test')->getContent();
resetState();
$feedB = http($kernel, 'GET', '/feed.xml', 'b.test')->getContent();
check('GEO', '004', ! str_contains($feedA, B_CANARY) && ! str_contains($feedB, A_CANARY),
    'feed.xml 混入他站 canary');

// ═══════════════════════════════════════════════════════════════
// 维度 6 · MS-CACHE —— 缓存隔离
// ═══════════════════════════════════════════════════════════════
echo "\n── MS-CACHE · 缓存 key 与内容隔离 ──\n";

$keyA = PageCache::keyFor(Request::create('http://a.test/products/'));
$keyB = PageCache::keyFor(Request::create('http://b.test/products/'));
check('CACHE', '001', $keyA !== $keyB, "两站相同路径生成相同 cache key");

resetState();
$body1 = http($kernel, 'GET', '/llms.txt', 'a.test')->getContent();
resetState();
$body2 = http($kernel, 'GET', '/llms.txt', 'a.test')->getContent();
$hitSame = ($body1 === $body2);
resetState();
$bodyB = http($kernel, 'GET', '/llms.txt', 'b.test')->getContent();
check('CACHE', '002', $hitSame && $body1 !== $bodyB,
    '同站二次请求内容不一致（缓存未命中）或两站内容相同（缓存串站）');

// ═══════════════════════════════════════════════════════════════
// 维度 7 · MS-ADMIN-API —— API 写入落点
// ═══════════════════════════════════════════════════════════════
echo "\n── MS-ADMIN-API · API token 与写入落点 ──\n";

use App\Models\Setting;
use App\Models\Content;

SiteContext::setSite(\App\Models\Site::find(1));
Setting::set('sync_geoflow_token', 'token-a');
Setting::set('sync_geoflow_enabled', '1');
Setting::set('sync_auto_publish', '1');

SiteContext::setSite(\App\Models\Site::find(2));
// B 站 facts 少，门禁 GEO 四要素会拒；本用例只验「写入落点与token 隔离」，
// 因此关闭强制门禁（生产由GEOFlow 上游保证内容质量）
Setting::set('geo_gate_enforce', '0');
config(['geo.gate.enforce_on_publish' => false]);
Setting::set('sync_geoflow_token', 'token-b');
Setting::set('sync_geoflow_enabled', '1');
Setting::set('sync_auto_publish', '1');

// B 站 token 写 B 站
resetState();
$r = http($kernel, 'POST', '/api/v1/geoflow/contents', 'b.test', [
    'external_id' => 'MS-E2E-B-001',
    'type' => 'article',
    'title' => 'B 站 API 写入文章',
    'slug' => 'b-api-article',
    'summary' => '摘要',
    'body' => '正文内容',
    'geo_conclusion' => '结论。',
    'geo_explanation' => '解释。',
    'geo_boundary' => '边界。',
    'geo_evidence' => [['label' => '证据A', 'value' => '值1', 'source' => '来源'], ['label' => '证据B', 'value' => '值2', 'source' => '来源2']],
    'reviewed_at' => '2026-09-14',
], 'token-b');
$writeCode = $r->getStatusCode();
check('API', '001', $writeCode === 200, "code={$writeCode} body=" . mb_substr($r->getContent(), 0, 160));

// 验证落点：DB 层 site_id=2（唯一键带 site_id）；A 站上下文查不到（Scope 隔离）
$siteIdOfRow = \Illuminate\Support\Facades\DB::table('contents')->where('external_id', 'MS-E2E-B-001')->value('site_id');
\App\Support\SiteContext::setSite(\App\Models\Site::find(2));
\App\Support\Catalog::flush();
$inB = \App\Models\Content::where('external_id', 'MS-E2E-B-001')->count();
\App\Support\SiteContext::setSite(\App\Models\Site::find(1));
\App\Support\Catalog::flush();
$inA = \App\Models\Content::where('external_id', 'MS-E2E-B-001')->count();
check('API', '002', $inB === 1 && $inA === 0 && $siteIdOfRow === 2,
    "B 站可见={$inB} A 站可见={$inA} DB site_id=" . var_export($siteIdOfRow, true));

// A 站 token 写 A 站
resetState();
$r = http($kernel, 'POST', '/api/v1/geoflow/contents', 'a.test', [
    'external_id' => 'MS-E2E-A-001',
    'type' => 'article',
    'title' => 'A 站 API 写入文章',
    'slug' => 'a-api-article',
    'summary' => '摘要',
    'body' => '正文内容',
    'geo_conclusion' => '结论。',
    'geo_explanation' => '解释。',
    'geo_boundary' => '边界。',
    'geo_evidence' => [['label' => '证据A', 'value' => '值1', 'source' => '来源'], ['label' => '证据B', 'value' => '值2', 'source' => '来源2']],
    'reviewed_at' => '2026-09-14',
], 'token-a');
check('API', '003', $r->getStatusCode() === 200, "code={$r->getStatusCode()}");

// Negative Test：A 站 token 不能写 B 站
resetState();
$r = http($kernel, 'POST', '/api/v1/geoflow/contents', 'b.test', [
    'external_id' => 'MS-E2E-XSITE-001',
    'type' => 'article',
    'title' => '跨站 token 测试',
    'slug' => 'cross-site-token-test',
], 'token-a');
check('API', '004', $r->getStatusCode() === 401, "code={$r->getStatusCode()}（期望 401 跨站 token 拒绝）");

// ═══════════════════════════════════════════════════════════════
// 全链路不变量
// ═══════════════════════════════════════════════════════════════
echo "\n── MS-INVARIANT · 全链路不变量 ──\n";

foreach (['/', '/llms.txt', '/geo.json', '/sitemap.xml', '/feed.xml', '/robots.txt'] as $ep) {
    resetState();
    $bodyA = http($kernel, 'GET', $ep, 'a.test')->getContent();
    resetState();
    $bodyB = http($kernel, 'GET', $ep, 'b.test')->getContent();

    $aClean = ! str_contains($bodyA, B_CANARY);
    $bClean = ! str_contains($bodyB, A_CANARY);
    check('INVARIANT', substr(str_replace('/', '', $ep), 0, 6), $aClean && $bClean,
        'A 泄漏=' . var_export(! $aClean, true) . ' B 泄漏=' . var_export(! $bClean, true));
}

// ═══════════════════════════════════════════════════════════════
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
printf("║  结果: %d 通过 / %d 失败（共 %d 条）%*s║\n", $pass, $fail, $pass + $fail,
    max(0, 40 - strlen("结果: {$pass} 通过 / {$fail} 失败（共 " . ($pass + $fail) . " 条）")), '');
echo "╚══════════════════════════════════════════════════════════════════════╝\n";

if ($fail > 0) {
    echo "\n失败明细:\n";
    foreach ($results as $r) {
        if (! $r['ok']) {
            echo "  ❌ [{$r['dim']}] {$r['id']}: {$r['detail']}\n";
        }
    }
}
