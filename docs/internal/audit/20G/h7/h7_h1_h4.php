<?php
/**
 * 20G-7 · H1–H4 核心运营闭环模拟器
 *
 * 模拟「没读过源码、只按后台 UI 操作」的运营人员，逐步留下五层证据：
 *   ACTION → UI FEEDBACK → PERSISTED STATE → DERIVED OUTPUT → USER UNDERSTANDING
 *
 * 走真实 HTTP 内核（Feature Test 无法覆盖 Host 头，见 20G-5 教训）。
 */

$root = getenv('GEO_ROOT') ?: 'D:/734666/GEO OS';
$db   = getenv('GEO_DB') ?: 'D:/Temp/h7_human.sqlite';

putenv("DB_DATABASE=$db"); $_ENV['DB_DATABASE'] = $db; $_SERVER['DB_DATABASE'] = $db;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config([
    'database.connections.sqlite.database' => $db,
    'site.default_fallback' => true,
    'session.driver' => 'array',
]);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\SiteContext;
use App\Support\Catalog;

$FAIL = [];
$EV = [];   // 证据收集

function line(string $t): void { echo "\n" . str_repeat('═', 3) . ' ' . $t . ' ' . str_repeat('═', max(0, 66 - mb_strlen($t))) . "\n"; }
function ok(string $m): void   { echo "  ✅ $m\n"; }
function bad(string $m): void  { echo "  ❌ $m\n"; global $FAIL; $FAIL[] = $m; }
function note(string $m): void { echo "     · $m\n"; }
function chk(string $c, string $p, string $f): void { $c ? ok($p) : bad($f); }

/**
 * 从 HTML 中解析 CSRF token（运营人员浏览器实际做的事）。
 */
function csrfFromHtml(string $html): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/"csrf_token"\s*:\s*"([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

/** 真实 HTTP 请求（携带 session cookie） */
function http(string $method, string $uri, array $data = [], array $server = [], ?string $cookieJar = null): array
{
    $req = Illuminate\Http\Request::create(
        $uri, $method, [], [], [],
        $server + ['HTTP_ACCEPT' => 'text/html,application/json', 'CONTENT_TYPE' => 'application/json'],
        $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE)
    );
    if ($cookieJar) {
        $req->headers->set('Cookie', $cookieJar);
    }
    $res = app(Illuminate\Contracts\Http\Kernel::class)->handle($req);
    $cookies = $res->headers->getCookies();
    $setCookie = '';
    foreach ($cookies as $c) { $setCookie .= $c->getName() . '=' . $c->getValue() . '; '; }

    return [
        'code'  => $res->getStatusCode(),
        'body'  => $res->getContent(),
        'json'  => json_decode($res->getContent(), true),
        'cookie'=> trim($setCookie),
        'redirect' => $res->headers->get('Location'),
    ];
}

function fresh(): void
{
    SiteContext::clear();
    Catalog::flush();
    Illuminate\Support\Facades\Cache::flush();
}

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  20G-7 · H1–H4 核心运营闭环（真实运营人员视角）              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "库: $db\n";

// ══════════════════════════════════════════
line('H1 · 登录与站点识别');

fresh();
// H1-1 未登录访问后台 → 应被重定向到登录页
$r = http('GET', '/admin', [], ['HTTP_HOST' => 'a.h7.test']);
chk(in_array($r['code'], [302, 401], true) || str_contains($r['body'], 'login'),
    '未登录访问后台被拦截（HTTP ' . $r['code'] . '）',
    '未登录竟能访问后台（HTTP ' . $r['code'] . '）——严重缺陷');
note('跳转目标: ' . ($r['redirect'] ?? '(无 Location 头，页面内提示)'));

// H1-2 错误密码 → 应拒绝
//先 GET 登录页：拿到 session cookie 与 CSRF token（与真实浏览器一致）
fresh();
$rLoginPage = http('GET', '/admin/login', [], ['HTTP_HOST' => 'a.h7.test']);
$preJar = $rLoginPage['cookie'];
$loginToken = csrfFromHtml($rLoginPage['body']);
note('登录页 CSRF token: ' . ($loginToken !== '' ? substr($loginToken, 0, 20) . '...' : '(未解析到)'));
if ($loginToken === '') {
    bad('登录页未提供 CSRF token —— 运营人员无法登录');
}

$r = http('POST', '/admin/login',
    ['email' => 'admin@example.com', 'password' => 'wrong-password', '_token' => $loginToken],
    ['HTTP_HOST' => 'a.h7.test'], $preJar ?: null);
// 302 是 PRG（Post/Redirect/Get）的正确形态，关键是 flash 里要有可理解的提示。
if ($r['code'] === 302) {
    $back = http('GET', $r['redirect'] ?? '/admin/login', [], ['HTTP_HOST' => 'a.h7.test'], $r['cookie'] ?: ($preJar ?: null));
    $hasMsg = str_contains($back['body'], '邮箱或密码不正确') || str_contains($back['body'], '尝试次数过多');
    chk($hasMsg, '错误密码被拒绝，且提示文案可理解（HTTP 302 →回登录页 + flash）',
        '错误密码虽被拒绝，但运营人员看不到任何提示（会以为没提交）');
    note('flash: ' . (preg_match('/邮箱或密码不正确|尝试次数过多/', $back['body'], $m) ? $m[0] : '(未解析到)'));
} else {
    chk(false, '', '错误密码未按 PRG 返回 302（实际 ' . $r['code'] . '）');
}
$EV['H1_wrong_password'] = $r['code'];

// H1-3 正确密码登录 → 应拿到 session cookie
fresh();
$rLoginPage = http('GET', '/admin/login', [], ['HTTP_HOST' => 'a.h7.test']);
$preJar = $rLoginPage['cookie'];
$loginToken = csrfFromHtml($rLoginPage['body']);
$r = http('POST', '/admin/login',
    ['email' => 'admin@example.com', 'password' => 'Admin@123456', '_token' => $loginToken],
    ['HTTP_HOST' => 'a.h7.test'], $preJar ?: null);
$jar = $r['cookie'] ?: $preJar;
chk($jar !== '', '正确密码登录成功并下发 session cookie', '登录后未下发 session cookie');
note('cookie: ' . substr($jar, 0, 46) . '...');

// H1-4 携带 cookie 访问后台 → 应成功
fresh();
$r = http('GET', '/admin', [], ['HTTP_HOST' => 'a.h7.test'], $jar);
chk($r['code'] === 200, '携带 cookie 访问后台成功（HTTP ' . $r['code'] . '）', '已登录仍无法访问后台（HTTP ' . $r['code'] . '）');

// H1-5 后台应显示当前站点身份（运营人员要能确认自己在哪站操作）
$showsSiteName = str_contains($r['body'], 'H7 第一站') || str_contains($r['body'], 'a.h7.test');
chk($showsSiteName, '后台页面显示当前站点身份（运营人员能确认所在站点）',
    '后台未显示站点身份 —— 运营人员可能误操作他站');

// H1-6 站点管理能力（多站是核心卖点，运营人员必须能管理站点）
$rSites = http('GET', '/admin/sites', [], ['HTTP_HOST' => 'a.h7.test'], $jar);
if ($rSites['code'] === 404) {
    bad('H1 站点管理页不存在（HTTP 404）—— 多站是核心卖点，但后台无站点增删改查入口');
    note('已确认：routes/admin.php 中无任何 sites 路由，只有 settings/{group}（当前站设置）');
} else {
    chk($rSites['code'] === 200, '超管可访问站点管理页（HTTP ' . $rSites['code'] . '）',
        '站点管理页不可达（HTTP ' . $rSites['code'] . '）');
}

// H1-7 当前站设置页应可访问（运营人员改品牌名等）
$rSet = http('GET', '/admin/settings/general', [], ['HTTP_HOST' => 'a.h7.test'], $jar);
chk($rSet['code'] === 200, '当前站设置页可访问（HTTP ' . $rSet['code'] . '）',
    '设置页不可访问（HTTP ' . $rSet['code'] . '）');

// ══════════════════════════════════════════
line('H2 · 内容创建（运营人员新建文章）');

fresh();
$token = csrf_token();
$articleData = [
    'type' => 'article',
    'title' => 'H7 运营闭环验证文章',
    'slug' => 'h7-human-simulation-article',
    'summary' => '这是 20G-7 人类模拟中由「运营人员」创建的文章摘要。',
    'body' => "## 第一节\n\n这是正文第一段，用于验证内容保存与渲染。\n\n- 要点一\n- 要点二\n",
    'category_slug' => 'knowledge',
    'geo_conclusion' => '结论：GEO Website OS 的运营闭环可用。',
    'geo_explanation' => '解释：通过 H1–H4 五层证据链逐层验证。',
    'geo_boundary' => '边界：仅验证单站内容生命周期，不含多语言。',
    // 后台证据是动态行表单（ev_label[] / ev_value.N / ev_source.N），不是 JSON
    'ev_label'  => ['环境', '回归'],
    'ev_value'  => ['0' => 'SQLite 41 迁移', '1' => '87 文件全绿'],
    'ev_source' => ['0' => '迁移日志', '1' => 'CI 输出'],
    'ev_url'    => ['0' => '', '1' => ''],
    'owner' => '运营人员',
    'reviewed_at' => '2026-09-20',
];

$rTokPage = http('GET', '/admin/contents/create/article', [], ['HTTP_HOST' => 'a.h7.test'], $jar);
$articleData['_token'] = csrfFromHtml($rTokPage['body']) ?: csrf_token();
$r = http('POST', '/admin/contents', $articleData, ['HTTP_HOST' => 'a.h7.test'], $jar);
$created = $r['code'] === 200 || $r['code'] === 302;
chk($created, '创建内容请求被接受（HTTP ' . $r['code'] . '）', '创建内容失败（HTTP ' . $r['code'] . '）：' . mb_substr(strip_tags($r['body']), 0, 140));
if (! $created && $r['json']) { note('错误信息: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE)); }

// 验证 UI 反馈：是否重定向到列表/编辑页（成功反馈）
note('跳转: ' . ($r['redirect'] ?? '(无)'));

fresh();
$row = DB::table('contents')->where('slug', 'h7-human-simulation-article')->first();
chk($row !== null, 'DB 持久化成功：内容已落库', 'DB 未查到该内容 —— 运营人员会以为已保存但实际丢失');
if ($row) {
    chk($row->status === 'draft', "初始状态为 draft（实际 {$row->status}）", "初始状态异常：{$row->status}");
    chk($row->title === $articleData['title'], '标题正确保存', '标题未正确保存');
    chk($row->site_id === 1, "归属 A 站（site_id={$row->site_id}）", "归属错误：site_id={$row->site_id}");
    note("id={$row->id} status={$row->status} site_id={$row->site_id} hash=" . substr((string) $row->content_hash, 0, 12));
    $EV['H2_content_id'] = $row->id;
    $EV['H2_content_hash'] = $row->content_hash;
}

// ══════════════════════════════════════════
line('H3 · 内容编辑');

if (! $row) {
    bad('H3 跳过：H2 未创建成功');
} else {
    fresh();
    $cid = $row->id;
    $r = http('GET', "/admin/contents/{$cid}/edit", [], ['HTTP_HOST' => 'a.h7.test'], $jar);
    chk($r['code'] === 200, '打开编辑页（HTTP ' . $r['code'] . '）', '编辑页打不开（HTTP ' . $r['code'] . '）');
    chk(str_contains($r['body'], 'H7 运营闭环验证文章'),
        '编辑页回填了已有内容（运营人员能看到自己写的内容）',
        '编辑页未回填内容 —— 运营人员会以为内容丢失');

    // 修改摘要与正文
    $editData = $articleData;
    $editData['summary'] = '这是修改后的摘要，用于验证编辑生效。';
    $editData['body'] = "## 已修改\n\n运营人员修改了正文。\n";
    fresh();
    $r = http('PUT', "/admin/contents/{$cid}", $editData + ['_token' => csrfFromHtml($r['body']) ?: csrf_token()], ['HTTP_HOST' => 'a.h7.test'], $jar);
    chk(in_array($r['code'], [200, 302], true), '编辑提交被接受（HTTP ' . $r['code'] . '）', '编辑提交失败（HTTP ' . $r['code'] . '）：' . mb_substr(strip_tags($r['body']), 0, 140));
    note('编辑响应码: ' . $r['code'] . '  Location: ' . ($r['redirect'] ?? '(无)'));

    fresh();
    $after = DB::table('contents')->where('id', $cid)->first();
    chk(str_contains((string) $after->summary, '修改后的摘要'), '摘要修改已持久化', '摘要修改未生效');
    chk(str_contains((string) $after->body, '已修改'), '正文修改已持久化', '正文修改未生效');

    // revision 是否记录
    $revs = DB::table('content_revisions')->where('content_id', $cid)->count();
    chk($revs >= 1, "历史版本已记录（{$revs} 条，运营人员可回溯）", "编辑未产生历史版本（共 {$revs} 条）");

    // 指纹应随内容变化
    chk($after->content_hash !== $row->content_hash, '内容指纹随编辑变化（GEO 幂等依据正确）', '内容指纹未变化 —— 编辑可能被判定为无变化');
    $EV['H3_revisions'] = $revs;
}

// ══════════════════════════════════════════
line('H4 · 发布流程');

if (! $row) {
    bad('H4 跳过：H3 未完成');
} else {
    fresh();
    $cid = $row->id;
    $r = http('POST', "/admin/contents/{$cid}/publish", ['_token' => (function () use ($jar) {
        $p = http('GET', "/admin/contents/h7-human-simulation-article/edit", [], ['HTTP_HOST' => 'a.h7.test'], $jar);
        return csrfFromHtml($p['body']) ?: csrf_token();
    })()], ['HTTP_HOST' => 'a.h7.test'], $jar);
    $published = in_array($r['code'], [200, 302], true);
    chk($published, '发布操作被接受（HTTP ' . $r['code'] . '）', '发布失败（HTTP ' . $r['code'] . '）：' . mb_substr(strip_tags($r['body']), 0, 160));
    if (! $published && $r['json']) {
        note('发布被拒原因: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }

    fresh();
    $pub = DB::table('contents')->where('id', $cid)->first();
    chk($pub->status === 'published', "DB 状态 = published（实际 {$pub->status}）", "状态不是 published：{$pub->status}");
    chk($pub->published_at !== null, 'published_at 已写入（发布有时间依据）', 'published_at 为空');

    // 前台可见性
    fresh();
    $front = http('GET', '/knowledge/h7-human-simulation-article', [], ['HTTP_HOST' => 'a.h7.test']);
    // 301 是 CanonicalizeSlash 的尾斜杠规范化（预期行为），需跟随重定向
    if ($front['code'] === 301 && $front['redirect']) {
        note('尾斜杠规范化 → 跟随到 ' . $front['redirect']);
        $front = http('GET', $front['redirect'], [], ['HTTP_HOST' => 'a.h7.test'], $jar);
    }
    chk($front['code'] === 200, "前台文章页可访问（HTTP {$front['code']}）", "前台文章页不可访问（HTTP {$front['code']}）");

    if ($front['code'] === 200) {
        chk(str_contains($front['body'], 'H7 运营闭环验证文章'), '前台展示标题', '前台未展示标题');
        chk(str_contains($front['body'], '修改后的摘要'), '前台展示最新摘要（编辑已生效）', '前台仍是旧摘要');

        // canonical
        preg_match('/rel="canonical" href="([^"]+)"/', $front['body'], $m);
        $canonical = $m[1] ?? '';
        chk($canonical !== '' && str_contains($canonical, 'h7-human-simulation-article'),
            'canonical 指向本文（' . $canonical . '）', "canonical 异常：{$canonical}");

        // schema
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $front['body'], $ldm);
        $types = [];
        foreach ($ldm[1] as $b) {
            $d = json_decode(trim($b), true);
            if (! $d) { continue; }
            $types = array_merge($types, isset($d['@graph']) ? array_column($d['@graph'], '@type') : [$d['@type'] ?? '?']);
        }
        chk(in_array('Article', $types) || in_array('BlogPosting', $types),
            '前台输出 Article/BlogPosting schema（实际: ' . implode(',', array_unique($types)) . '）',
            '前台缺少 Article schema（实际: ' . implode(',', array_unique($types)) . '）');
        $EV['H4_schema_types'] = array_values(array_unique($types));
    }

    // 内容列表页应能看到它
    fresh();
    $list = http('GET', '/admin/contents', [], ['HTTP_HOST' => 'a.h7.test'], $jar);
    chk(str_contains($list['body'], 'H7 运营闭环验证文章'), '后台列表可见该内容（运营人员能找到自己发的内容）',
        '后台列表找不到该内容 —— 运营人员会困惑');
}

echo "\n";
line('H1–H4 小结');
if (empty($FAIL)) {
    echo "  ✅ H1–H4 全部通过\n";
} else {
    echo "  ❌ " . count($FAIL) . " 项失败\n";
    foreach ($FAIL as $f) { echo "     · $f\n"; }
}
file_put_contents('D:/Temp/h7_h1_h4.json', json_encode([
    'pass' => empty($FAIL), 'failures' => $FAIL, 'evidence' => $EV,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n";