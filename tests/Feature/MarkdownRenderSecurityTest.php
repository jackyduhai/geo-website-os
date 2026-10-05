<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Services\Gate\ContentGate;
use App\Services\Sync\GeoflowSync;
use App\Support\ContentFieldContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Markdown 渲染安全契约（20G-1 · C-1）
 * ==================================================================
 * 缺陷背景：系统曾存在两个 Markdown 渲染实现——
 *   Content::renderMarkdown  已用 html_input=escape（安全）
 *   Narrative::renderMarkdown 无参数，CommonMark 默认放行原始 HTML（可利用）
 * 修复方式：Narrative 改为委托 Content 渲染，保留 H1→H2 降级后处理。
 * 本测试锁死该收敛结果，防止将来重新分叉。
 *
 * 测试分三层（评审第 8 条）：
 *   A类 HTML Injection      —— 原始 HTML 是否被放行
 *   B 类 Protocol Injection —— 危险协议是否进入可执行位置
 *   C 类 Output Sink        —— 经真实 HTTP 响应验证端到端无裸攻击语义
 *
 * 判据原则（评审第 7 条）：
 *   只认「裸标签」——即 `<` 后紧跟字母的真实标签。
 *   绝不用关键词匹配：`html_input=escape` 下onerror / javascript:
 *   这些字符串仍会出现在输出文本里（已实体化为 &lt;…&gt;），
 *   但浏览器不会解析为标签或事件处理器。历史上正是关键词匹配
 *   把「已安全」误判成「仍可利用」，本测试不得重犯。
 */
class MarkdownRenderSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** A 类 · HTML 注入载荷 */
    public const HTML_PAYLOADS = [
        'XSS-HTML-001' => ['<img src=x onerror=alert(1)>', 'img'],
        'XSS-HTML-002' => ['<img src=x onerror="alert(document.cookie)">', 'img'],
        'XSS-HTML-003' => ['<svg onload=alert(1)>', 'svg'],
        'XSS-HTML-004' => ['<script>alert(1)</script>', 'script'],
        'XSS-HTML-005' => ['<iframe src="javascript:alert(1)"></iframe>', 'iframe'],
        'XSS-HTML-006' => ['<object data="javascript:alert(1)"></object>', 'object'],
        'XSS-HTML-007' => ['<form action="javascript:alert(1)"><input type=submit></form>', 'form'],
        'XSS-HTML-008' => ['<div onmouseover=alert(1)>hover</div>', 'div'],
        'XSS-HTML-009' => ['<details open ontoggle=alert(1)>x</details>', 'details'],
        'XSS-HTML-010' => ['<body onload=alert(1)>', 'body'],
    ];

    /** B 类 · 协议 / 属性注入载荷 */
    public const PROTOCOL_PAYLOADS = [
        'XSS-PROTO-001' => ['[x](javascript:alert(1))', 'href'],
        'XSS-PROTO-002' => ['![x](javascript:alert(1))', 'src'],
        'XSS-PROTO-003' => ['<a href="javascript:alert(1)">c</a>', 'a'],
        'XSS-PROTO-004' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>', 'a'],
        'XSS-PROTO-005' => ['<a href="vbscript:msgbox(1)">x</a>', 'a'],
        'XSS-PROTO-006' => ['[v](vbscript:msgbox(1))', 'href'],
        'XSS-PROTO-007' => ['<div style="background:url(javascript:alert(1))">x</div>', 'div'],
    ];

    /**
     * 结构性判定：输出中是否含**可执行**标签。
     *
     * 判据要点（三次踩坑后的正确写法）：
     *   1. 只认「裸标签」——`<` 后紧跟字母的真实标签。转义输出形如
     *      `&lt;img …&gt;`，其中根本没有 `<` 字符，天然不匹配。
     *   2. **不能简单地「无任何 <img> 就算通过」**——B 类载荷
     *      `![x](javascript:alert(1))` 在 allow_unsafe_links=false 下
     *      会正确渲染成 `<img src="" alt="x" />`（协议被剥离，图片语法保留）。
     *      这是**正确的安全行为**，不是攻击。因此判据必须区分：
     *        - `<img src="">` → 安全（无协议，无事件处理器）
     *        - `<img src=x onerror=…>` → 危险（有事件处理器）
     *   3. 真正的判据是「标签上是否携带事件处理器或危险协议」。
     */
    protected function hasBareTag(string $html, string $tag): bool
    {
        if (! preg_match_all('/<' . preg_quote($tag, '/') . '(\s[^>]*)?>/i', $html, $m)) {
            return false;
        }

        foreach ($m[0] as $occurrence) {
            // 事件处理器 → 危险
            if (preg_match('/\son[a-z]+\s*=/i', $occurrence)) {
                return true;
            }
            // 危险协议 → 危险
            if (preg_match('/(javascript|vbscript|data:text\/html)\s*:/i', $occurrence)) {
                return true;
            }
            // <script> / <style> 等内容型标签：出现即危险
            if (in_array(strtolower($tag), ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function assertNoBareTag(string $html, string $caseId, string $tag): void
    {
        $this->assertFalse(
            $this->hasBareTag($html, $tag),
            sprintf('%s 失败：输出含裸 <%s> 标签 → %s', $caseId, $tag, mb_substr($html, 0, 90))
        );
    }

    /**
     * 判定：危险协议是否进入了可执行的属性位置。
     * 关键：`&lt;a href="javascript:…"&gt;` 是已转义文本，不算可执行。
     */
    protected function hasDangerousProtocolInTag(string $html, string $attr): bool
    {
        return (bool) preg_match(
            '/<[a-z][^>]*\s' . preg_quote($attr, '/') . '\s*=\s*["\']?\s*(javascript|vbscript|data:text\/html)/i',
            $html
        );
    }

    // ─────────────────────────────────────────────────────────
    // A 类 · HTML Injection（两个渲染入口）
    // ─────────────────────────────────────────────────────────

    /**
     * 全部 A 类载荷在 Content 渲染入口均不得产生裸标签。
     */
    public function test_html_injection_payloads_are_blocked_in_content_renderer(): void
    {
        foreach (self::HTML_PAYLOADS as $caseId => [$payload, $tag]) {
            $html = Content::renderMarkdown($payload);
            $this->assertNoBareTag($html, $caseId, $tag);
        }
    }

    /**
     * 全部 A 类载荷在 Narrative 渲染入口均不得产生裸标签。
     * 这是 C-1 的核心回归：修复前该入口 11/14 载荷可利用。
     */
    public function test_html_injection_payloads_are_blocked_in_narrative_renderer(): void
    {
        foreach (self::HTML_PAYLOADS as $caseId => [$payload, $tag]) {
            $html = \App\Support\Narrative::renderMarkdown($payload);
            $this->assertNoBareTag($html, $caseId, $tag);
        }
    }

    // ─────────────────────────────────────────────────────────
    // B 类 · Protocol / Attribute Injection
    // ─────────────────────────────────────────────────────────

    /**
     * 全部 B 类载荷不得让危险协议进入可执行属性位置。
     * 这类走的是 Markdown 链接解析，必须靠 allow_unsafe_links=false 挡——
     * 只测 A 类会漏掉它（例如误删该参数时 A 类仍全绿）。
     */
    public function test_protocol_injection_payloads_are_blocked(): void
    {
        foreach (self::PROTOCOL_PAYLOADS as $caseId => [$payload, $attr]) {
            $html = Content::renderMarkdown($payload);
            $this->assertFalse(
                $this->hasDangerousProtocolInTag($html, $attr),
                sprintf('%s 失败：危险协议进入 <%s> 属性 → %s', $caseId, $attr, mb_substr($html, 0, 90))
            );
        }
    }

    /**
     * Narrative 入口同样必须阻断协议注入。
     */
    public function test_protocol_injection_blocked_in_narrative_renderer(): void
    {
        foreach (self::PROTOCOL_PAYLOADS as $caseId => [$payload, $attr]) {
            $html = \App\Support\Narrative::renderMarkdown($payload);
            $this->assertFalse(
                $this->hasDangerousProtocolInTag($html, $attr),
                sprintf('%s 失败（Narrative）：危险协议进入 %s 属性 → %s', $caseId, $attr, mb_substr($html, 0, 90))
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // 收敛断言：全站只有一个底层 renderer
    // ─────────────────────────────────────────────────────────

    /**
     * Narrative 必须复用 Content 的渲染器，不得自建一套。
     * 这是 C-1 的架构不变量：两份渲染策略必然分叉，那正是漏洞成因。
     *
     * 断言用「去空白后等价」而非严格相等——Narrative 额外做了 trim()
     * 与 H1→H2 降级（业务要求），尾部换行差异属正常，不应误报。
     */
    public function test_narrative_delegates_to_content_renderer(): void
    {
        $payloads = [
            '<img src=x onerror=alert(1)>',
            '[x](javascript:alert(1))',
            '# 标题',
            '普通段落文字',
        ];

        foreach ($payloads as $payload) {
            $expected = Content::renderMarkdown($payload);
            $actual = \App\Support\Narrative::renderMarkdown($payload);

            // H1→H2 是 Narrative 的合法业务差异（每页唯一 h1）。
            // 归一方向：把两边的 h1 都降为 h2 后比较——
            // 若归一 Narrative 侧，则期望值仍是 <h1>，永远不相等。
            $normalize = static fn (string $html): string => str_replace(
                ['<h1>', '</h1>'],
                ['<h2>', '</h2>'],
                trim($html)
            );

            $this->assertSame(
                $normalize($expected),
                $normalize($actual),
                "Narrative 渲染结果必须与 Content 一致（除 H1 降级与空白外不得有独立策略）：{$payload}"
            );
        }
    }

    /**
     * H1→H2 降级必须保留（每页唯一 H1 是业务约束，
     * 收敛 renderer 时绝不能把它一起丢掉）。
     */
    public function test_narrative_preserves_h1_downgrade(): void
    {
        $html = \App\Support\Narrative::renderMarkdown('# 一级标题');

        $this->assertStringContainsString('<h2>一级标题</h2>', $html);
        $this->assertStringNotContainsString('<h1>', $html, 'Narrative 正文不应产出 h1');
    }

    /**
     * 合法 Markdown 不得被误伤（escape 策略不能把正常内容也转义掉）。
     *
     * 样本只取 CommonMark 标准语法。注意**不含表格**：
     * Laravel 的 Str::markdown 用的是 CommonMark（未启用 GFM 表格扩展），
     * `| a | b |` 会原样输出为段落。断言超出实现能力就是假失败——
     * 若将来启用了 GFM，应同步在项目设置里开启并补表格断言。
     */
    public function test_legitimate_markdown_is_not_broken(): void
    {
        $samples = [
            ['# 标题', '<h1>'],
            ['## 二级', '<h2>'],
            ['**粗体**', '<strong>'],
            ['*斜体*', '<em>'],
            ['- 项1', '<li>'],
            ['1. 第一', '<li>'],
            ['[链接](https://example.com)', 'href="https://example.com"'],
            ['`代码`', '<code>'],
            ['> 引用', '<blockquote>'],
            ['---', '<hr'],
            ['段落文字', '<p>'],
        ];

        foreach ($samples as [$md, $needle]) {
            $content = Content::renderMarkdown($md);
            $this->assertStringContainsString(
                $needle,
                $content,
                "合法 Markdown 被误伤：{$md}"
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // C 类 · Output Sink：经 GEOFlow 写入 → 真实 HTTP 响应
    // ─────────────────────────────────────────────────────────

    /**
     * 从响应体中提取文章正文区域。
     *
     * 必须只检查正文而不是整页：页面本身有正常的 <img>（logo、图片块、
     * og 图），整页扫描会把它们误判为攻击载荷。真实攻击链只经过
     * `{!! $content->bodyHtml() !!}` 这一个 sink。
     */
    protected function extractArticleBody(string $html): string
    {
        // 优先取正文容器
        if (preg_match('/<div class="prose[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/<article[^>]*>(.*?)<\/article>/s', $html, $m)) {
            return $m[1];
        }

        // 退化：取 <main> 内内容
        if (preg_match('/<main[^>]*>(.*?)<\/main>/s', $html, $m)) {
            return $m[1];
        }

        return $html;
    }

    /**
     * 真实攻击链端到端验证：
     *   GEOFlow API 写入恶意正文 → 前台页面 → 响应体
     * 确认从外部输入到浏览器可执行语义之间没有裸标签泄漏。
     *
     * 这条比 Service 级测试更重要：它覆盖了 Controller / Model /
     * 视图 / Blade {!! !!} 整条链，而不只是渲染函数。
     */
    public function test_geoflow_written_markdown_is_safe_in_real_http_response(): void
    {
        $this->seed([
            \Database\Seeders\FactSeeder::class,
            \Database\Seeders\StructureSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

        \App\Models\Setting::set('sync_geoflow_token', 'sink-test-token');
        \App\Models\Setting::set('sync_geoflow_enabled', '1');
        \App\Models\Setting::set('sync_auto_publish', '1');
        \App\Models\Setting::set('sync_gate_strict', '0');

        // 注意：不能对 HTML_PAYLOADS 用 array_column(..., 0)——
        // 它的值是 [payload, tag] 二元组（数组），array_column 处理嵌套
        // 数组会得到空结果，导致 $body 为空、后续断言对象错位。
        $payloads = [];
        foreach ([self::HTML_PAYLOADS, self::PROTOCOL_PAYLOADS] as $set) {
            foreach ($set as $caseId => $pair) {
                $payloads[$caseId] = $pair[0];
            }
        }

        $this->assertNotEmpty($payloads, '载荷清单不能为空');
        $this->assertGreaterThanOrEqual(15, count($payloads), '应至少组合 15 个载荷');

        $body = implode("\n\n", $payloads);
        $this->assertNotSame('', trim($body), '组合后的正文不能为空');

        $this->withToken('sink-test-token')
            ->postJson('/api/v1/geoflow/contents', [
                'external_id' => 'SINK-001',
                'type' => 'article',
                'title' => '输出汇安全验证',
                'slug' => 'output-sink-safety-article',
                'category_slug' => 'knowledge',
                'summary' => '摘要。',
                'body' => $body,
                'geo_conclusion' => '结论。',
                'geo_explanation' => '解释。',
                'geo_boundary' => '边界。',
                'geo_evidence' => [
                    ['label' => '设备A', 'value' => '配置1', 'source' => '台账'],
                    ['label' => '设备 B', 'value' => '配置 2', 'source' => '实拍'],
                ],
                'owner' => 'GEOFlow',
                'reviewed_at' => '2026-09-14',
            ])
            ->assertOk();

        $this->assertDatabaseHas('contents', ['external_id' => 'SINK-001']);

        // 先用 Service 级入口确认渲染本身是安全的（隔离变量）
        $stored = Content::where('external_id', 'SINK-001')->firstOrFail();
        $rendered = $stored->bodyHtml();

        foreach (self::HTML_PAYLOADS as $caseId => [$payload, $tag]) {
            $this->assertNoBareTag($rendered, "C-SINK-render {$caseId}", $tag);
        }

        // 再验证真实 HTTP 响应：只取正文区域，避免把页面正常 <img> 误判为攻击
        $response = $this->get('/knowledge/output-sink-safety-article');
        $response->assertOk();

        $articleBody = $this->extractArticleBody($response->getContent());

        foreach (self::HTML_PAYLOADS as $caseId => [$payload, $tag]) {
            $this->assertFalse(
                $this->hasBareTag($articleBody, $tag),
                sprintf('C-SINK 失败：%s 的 <%s> 出现在文章正文响应中', $caseId, $tag)
            );
        }

        foreach (self::PROTOCOL_PAYLOADS as $caseId => [$payload, $attr]) {
            $this->assertFalse(
                $this->hasDangerousProtocolInTag($articleBody, $attr),
                sprintf('C-SINK 失败：%s 的危险协议进入文章正文的 %s 属性', $caseId, $attr)
            );
        }
    }

    /**
     * 契约统计自动输出（评审第 7 条：不靠人工维护数字）。
     */
    public function test_payload_inventory_is_complete(): void
    {
        $html = count(self::HTML_PAYLOADS);
        $proto = count(self::PROTOCOL_PAYLOADS);
        $renderers = 2;   // Content::renderMarkdown / Narrative::renderMarkdown

        // ID 唯一且格式规范
        $ids = array_merge(array_keys(self::HTML_PAYLOADS), array_keys(self::PROTOCOL_PAYLOADS));
        $this->assertSame($ids, array_unique($ids), '载荷 ID 必须唯一');

        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression(
                '/^XSS-(HTML|PROTO)-\d{3}$/',
                $id,
                "载荷 ID 不符合规范：{$id}"
            );
        }

        // 载荷清单本身不能为空（防止有人清空数组让测试空转）
        $this->assertGreaterThanOrEqual(10, $html, 'A 类载荷不应少于 10 个');
        $this->assertGreaterThanOrEqual(5, $proto, 'B 类载荷不应少于 5 个');

        // 断言总数 = (A + B) × 2 renderer —— 供报告直接引用
        fwrite(STDERR, sprintf(
            "\n[载荷清单] HTML=%d, Protocol=%d, Renderers=%d, 断言总数=%d\n",
            $html, $proto, $renderers, ($html + $proto) * $renderers
        ));
    }
}
