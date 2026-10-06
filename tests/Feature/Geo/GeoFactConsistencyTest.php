<?php

namespace Tests\Feature\Geo;

use App\Models\Entity;
use App\Models\Fact;
use App\Models\Site;
use App\Services\Geo\GeoGraphBuilder;
use App\Services\Geo\FactLabels;
use App\Services\Geo\LlmsBuilder;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RC-9 D-02 ·跨出口业务事实一致性契约测试
 * ------------------------------------------------------------------
 * 这不是「验证某个具体值」的功能测试，而是**架构护栏**。
 *
 * ## 背景（为什么必须有这个测试）
 *
 * 历史实现里，同一批业务事实（成立时间 / 厂区面积 / 年产能 / 总投资 / 技术积累…）
 * 存在**两份独立存储**：
 *
 *   · `facts` 表                （FactSeeder 写入，仅 zh-CN）
 *   · `organization.metadata['company']`（含 `_en` 变体）
 *
 * 二者 locale 覆盖不同，于是同一英文站出现矛盾：
 *
 *   `en/geo.json` 的 facts = []      （facts 表无 en 行，门禁正确拦下）
 *   `en/llms.txt` 却输出 8 条英文事实 （读 metadata，绕过了 facts）
 *
 * AI 同时消费这两个端点会得到**互相矛盾的事实集合**。
 *
 * ## 冻结的架构规则（见 docs/audit/RC/D-02-Architecture-Decision-Lock.md）
 *
 *   1. `facts` 是**业务事实（Business Fact）的唯一 Canonical SoT**；
 *      `geo.json` 与 `llms.txt` 的事实出口必须经 `Fact::publicRows($locale)`。
 *   2. `SchemaBuilder` **不直接读 facts**（遵守SchemaBuilder.php:34 已冻结的
 *      数据源边界）；实体属性类字段（legalName / address / areaServed /
 *      knowsAbout）仍由 organization.metadata 驱动。
 *   3. 跨出口不要求「同表读取」，但**绝不允许产生互相矛盾的事实**。
 *
 * 因此本测试断言的是**不变量**，而非具体值：
 *   只要有人再次让某个出口绕过 Fact contract，测试立刻失败并说明原因。
 */
class GeoFactConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();
        SiteContext::clear();
        Fact::flushMemo();

        $this->siteA = Site::create([
            'name' => 'Fact Contract Site A',
            'slug' => 'fact-site-a',
            'domain' => 'fact-a.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $this->siteB = Site::create([
            'name' => 'Fact Contract Site B',
            'slug' => 'fact-site-b',
            'domain' => 'fact-b.test',
            'status' => 'active',
            'is_default' => false,
        ]);

        $this->seedFacts($this->siteA, 'A');
        $this->seedFacts($this->siteB, 'B');
    }

    protected function tearDown(): void
    {
        SiteContext::clear();
        Fact::flushMemo();
        parent::tearDown();
    }

    /**
     * 为每个站点写入 zh-CN 与 en 两行事实，**并建双语 organization 实体**。
     *
     * 值里带站点标记（A / B）与语言标记，任何跨站 / 跨语言泄漏都会被直接抓住。
     *
     * ⚠️ fixture 设计要点 1：zh 与 en 的值**故意不同**
     * （zh `founded-a-zh` vs en `founded-a-en`）。
     * 若某个出口的中文与英文值相同，测试就抓不住「英文出口偷偷读了中文源」
     * 这类分叉 —— 值不同才能让来源切换在断言上立刻暴露。
     *
     * ⚠️ fixture 设计要点 2：**必须建 organization 实体**。
     * `LlmsBuilder` 在 `Catalog::company()['name']` 为空时会走
     * `buildGeneric()` / `buildGenericEnglish()` 降级分支，**根本不输出事实段**。
     * 没有 organization 实体的测试会「假绿」—— 它测的是降级分支，
     * 而真实站点（有实体）走的是 `compose()` / `buildEnglish()` 事实路径。
     * 这是变异测试实测踩到的坑。
     */
    private function seedFacts(Site $site, string $tag): void
    {
        SiteContext::withSite($site, function () use ($site, $tag): void {
            foreach (LocaleRegistry::supported() as $locale) {
                $isDefault = $locale === LocaleRegistry::default();
                $suffix = $isDefault ? 'zh' : 'en';

                Entity::create([
                    'site_id' => $site->id,
                    'type' => 'organization',
                    'slug' => 'contract-org-' . strtolower($tag),
                    'name' => 'Contract Co ' . strtoupper($tag) . ' (' . $suffix . ')',
                    'status' => 'published',
                    'locale' => $locale,
                ]);

                /**
                 * ⚠️ `label` 故意写成与 {@see FactLabels} 契约**不同**
                 * （如 `厂区占地面积` vs 契约的 `厂区面积`）。
                 *
                 * 若两个出口都直接用 `Fact.label`，它们会「一致」但都用错了源；
                 * 若两个出口各自硬编码标签，也碰巧一致。只有**都走 FactLabels
                 * 契约**才能让 geo.json 与 llms.txt 同时输出契约里的权威标签。
                 * 这个 fixture 设计让「用契约」成为唯一能通过的口径。
                 */
                foreach ([
                    ['FACT-COMPANY-001', '公司全称（运营自填）', 'Company Name (ops)', 'contract-co-' . strtolower($tag) . '-' . $suffix],
                    ['FACT-COMPANY-003', '成立时间（运营自填）', 'Founded (ops)', 'founded-' . strtolower($tag) . '-' . $suffix],
                    ['FACT-COMPANY-006', '厂区占地面积（运营自填）', 'Facility Area (ops)', 'area-' . strtolower($tag) . '-' . $suffix],
                    ['FACT-COMPANY-007', '年产能（运营自填）', 'Annual Capacity (ops)', 'capacity-' . strtolower($tag) . '-' . $suffix],
                    ['FACT-BIZ-001', '业务模式（运营自填）', 'Business Model (ops)', 'model-' . strtolower($tag) . '-' . $suffix],
                ] as [$key, $zhLabel, $enLabel, $value]) {
                    Fact::create([
                        'key' => $key,
                        'label' => $isDefault ? $zhLabel : $enLabel,
                        'value' => $value,
                        'group' => str_contains($key, 'BIZ') ? 'business' : 'company',
                        'locale' => $locale,
                        'is_public' => true,
                        'sort' => 10,
                    ]);
                }
            }
        });
        Fact::flushMemo();
    }

    /**
     * 从 llms.txt 正文里抽出业务事实行。
     *
     * ⚠️ 只认**已知事实标签**，不做「见到 `- x: y` 就当事实」的宽松匹配。
     *
     * 变异测试实测踩到的两个坑：
     *   1. Markdown 链接行（`- [文字](url)：描述`）是导航不是事实；
     *   2. llms.txt 里还有「实体说明」类自由文本（如「本节说明单个实体」），
     *      其标签是自然语言片段（如 `thissiterepresentsasingleentity`），
     *      宽松匹配会把它当成业务事实，产生假失败。
     *
     * 因此判据 = 标签必须落在 {@see LlmsBuilder::FACT_LABELS} 的当前语言标签集合内。
     * 这样 llms.txt 少输出、多输出、换来源，都不会被解析噪声掩盖。
     */
    private function llmsFactMap(string $llms, ?string $locale = null): array
    {
        $locale ??= (string) (LocaleContext::has() ? LocaleContext::current() : LocaleRegistry::default());

        // 当前语言下所有合法事实标签（小写去空格，与 geo.json 的 label 归一化一致）
        $known = [];
        foreach (self::knownFactLabels() as $labels) {
            $label = $labels[$locale] ?? $labels[LocaleRegistry::default()] ?? null;
            if ($label !== null && $label !== '') {
                $known[mb_strtolower(preg_replace('/\s+/u', '', $label) ?? '')] = true;
            }
        }

        $map = [];
        foreach (preg_split('/\R/', $llms) ?: [] as $line) {
            $line = trim($line);
            if (! str_starts_with($line, '-')) {
                continue;
            }
            if (! preg_match('/^-\s*([^:：]+)\s*[:：]\s*(\S.*)$/u', $line, $m)) {
                continue;
            }
            $label = trim($m[1]);
            $value = trim($m[2]);

            // Markdown 链接行是导航，不是事实
            if (str_contains($label, ']') || str_contains($value, '](')) {
                continue;
            }

            $key = mb_strtolower(preg_replace('/\s+/u', '', $label) ?? '');
            if (! isset($known[$key])) {
                continue;
            }

            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * 从共享标签契约 {@see FactLabels} 反射出各语言的权威标签集合，
     * 避免测试里复制一份标签表（复制 = 会与实现漂移，护栏就失效了）。
     */
    private static function knownFactLabels(): array
    {
        return FactLabels::all();
    }

    /**
     * 把 geo.json 的 facts 数组转成 label → value 映射。
     */
    private function geoFactMap(array $facts): array
    {
        $map = [];
        foreach ($facts as $f) {
            $label = mb_strtolower(preg_replace('/\s+/u', '', (string) ($f['label'] ?? '')) ?? '');
            if ($label !== '') {
                $map[$label] = (string) ($f['value'] ?? '');
            }
        }

        return $map;
    }

    // -----------------------------------------------------------------
    // 1. 核心不变量：两个出口的事实集合必须一致
    // -----------------------------------------------------------------

    /**
     * @dataProvider localeSiteProvider
     */
    public function test_geo_json_and_llms_txt_share_identical_business_facts(
        string $locale,
        int $siteIndex
    ): void {
        $site = $siteIndex === 0 ? $this->siteA : $this->siteB;

        [$geoFacts, $llms] = SiteContext::withSite($site, function () use ($locale): array {
            LocaleContext::set($locale);
            try {
                $graph = app(GeoGraphBuilder::class)->build();
                $llms = app(LlmsBuilder::class)->build();
            } finally {
                LocaleContext::clear();
            }

            return [$graph['facts'] ?? [], $llms];
        });

        $geoMap = $this->geoFactMap($geoFacts);
        $llmsMap = $this->llmsFactMap($llms, $locale);

        $this->assertNotEmpty(
            $geoMap,
            "geo.json 在 locale={$locale} 下没有任何业务事实，无法做跨出口比对"
        );

        /**
         * 不变量 1：值一致 —— llms.txt 中出现的每条业务事实，都必须在 geo.json 中
         * 有完全相同的值。允许 llms.txt 少（多出官网 / 电话等实体属性指针），
         * 但不允许「值不一致」或「geo.json 里根本没有」。
         */
        foreach ($llmsMap as $label => $value) {
            $this->assertArrayHasKey(
                $label,
                $geoMap,
                "llms.txt 在 locale={$locale} 输出了业务事实「{$label}」，"
                . "但 geo.json 的 facts 里没有同一事实。"
                . '这说明某个 AI 出口绕过了 Fact::publicRows() contract（RC-9 D-02）。'
            );
            $this->assertSame(
                $geoMap[$label],
                $value,
                "同一业务事实「{$label}」在两个 AI 出口口径不一致（locale={$locale}）："
                . 'geo.json=' . var_export($geoMap[$label], true)
                . ' llms.txt=' . var_export($value, true)
            );
        }

        /**
         * 不变量 2：语言与站点归属一致。
         *
         * fixture 的每个事实值都形如 `founded-a-zh` / `area-b-en`，即
         * `<语义>-<站点>-<语言>`。因此**任何**事实值都能被归属断言覆盖 ——
         * 不做前缀过滤，避免「出口换了数据源导致值格式变了」时断言被跳过
         * （这正是变异测试暴露出的漏洞：带 `preg_match` 前缀条件的断言
         *   会被「值格式完全改变」的变异绕过）。
         *
         * 若某出口输出了不以 `<站点>-<语言>` 结尾的值，说明它读的不是
         * 本 locale / 本站点的 Fact 行，即绕过了 locale 门禁或站点隔离。
         */
        $tag = $siteIndex === 0 ? 'a' : 'b';
        $expectedSuffix = '-' . $tag . '-' . ($locale === LocaleRegistry::default() ? 'zh' : 'en');

        foreach (['geo.json' => $geoMap, 'llms.txt' => $llmsMap] as $出口 => $map) {
            foreach ($map as $label => $value) {
                $this->assertMatchesRegularExpression(
                    '/^[a-z-]+' . preg_quote($expectedSuffix, '/') . '$/',
                    $value,
                    "{$出口} 在 locale={$locale}（site {$tag}）下的事实「{$label}」"
                    . "值归属不符：{$value}。期望以 '{$expectedSuffix}' 结尾。"
                    . '这说明该出口读的不是本 locale / 本站点的 Fact 行，'
                    . '即绕过了 locale 门禁或站点隔离（RC-9 D-02）。'
                );
            }
        }
    }

    public static function localeSiteProvider(): array
    {
        return [
            'zh site A' => ['zh-CN', 0],
            'en site A' => ['en', 0],
            'zh site B' => ['zh-CN', 1],
            'en site B' => ['en', 1],
        ];
    }

    // -----------------------------------------------------------------
    // 2. 缺口语言不得泄漏另一语言的值
    // -----------------------------------------------------------------

    public function test_missing_locale_does_not_leak_other_locale_values(): void
    {
        // Site B 只留 zh-CN 行（删掉 en），模拟「英文译文未提供」
        SiteContext::withSite($this->siteB, function (): void {
            Fact::query()->where('locale', 'en')->delete();
        });
        Fact::flushMemo();

        [$geoFacts, $llms] = SiteContext::withSite($this->siteB, function (): array {
            LocaleContext::set('en');
            try {
                return [app(GeoGraphBuilder::class)->build()['facts'] ?? [], app(LlmsBuilder::class)->build()];
            } finally {
                LocaleContext::clear();
            }
        });

        $this->assertSame(
            [],
            $geoFacts,
            '缺 en 译文时 geo.json 不应输出任何 facts（门禁必须拦下）'
        );

        foreach ($this->llmsFactMap($llms, 'en') as $label => $value) {
            $this->assertStringNotContainsString(
                '-zh',
                $value,
                "缺 en 译文时 llms.txt 的「{$label}」泄漏了中文值：{$value}"
            );
        }
    }

    // -----------------------------------------------------------------
    // 3. 跨站隔离
    // -----------------------------------------------------------------

    public function test_facts_never_leak_across_sites(): void
    {
        foreach ([$this->siteA, $this->siteB] as $site) {
            $tag = $site === $this->siteA ? 'A' : 'B';

        [$geoFacts, $llms] = SiteContext::withSite($site, function (): array {
            LocaleContext::set('en');
            try {
                return [app(GeoGraphBuilder::class)->build()['facts'] ?? [], app(LlmsBuilder::class)->build()];
            } finally {
                LocaleContext::clear();
            }
        });

            $other = '-' . ($tag === 'A' ? 'b' : 'a') . '-';

            foreach ($geoFacts as $f) {
                $this->assertStringNotContainsString(
                    $other,
                    (string) ($f['value'] ?? ''),
                    "site {$tag} 的 geo.json 混入了 site {$other} 的事实"
                );
            }

            $this->assertStringNotContainsString(
                $other,
                $llms,
                "site {$tag} 的 llms.txt 混入了 site {$other} 的事实"
            );
        }
    }

    // -----------------------------------------------------------------
    // 4. 未公开事实不得出现在任何出口
    // -----------------------------------------------------------------

    public function test_non_public_facts_never_reach_ai_outputs(): void
    {
        SiteContext::withSite($this->siteA, function (): void {
            Fact::create([
                'key' => 'FACT-SECRET-001',
                'label' => '内部代号',
                'value' => 'should-never-appear-in-ai-output',
                'group' => 'company',
                'locale' => 'en',
                'is_public' => false,
                'sort' => 999,
            ]);
        });
        Fact::flushMemo();

        [$geoFacts, $llms] = SiteContext::withSite($this->siteA, function (): array {
            LocaleContext::set('en');
            try {
                return [app(GeoGraphBuilder::class)->build()['facts'] ?? [], app(LlmsBuilder::class)->build()];
            } finally {
                LocaleContext::clear();
            }
        });

        foreach ($geoFacts as $f) {
            $this->assertNotSame(
                'FACT-SECRET-001',
                (string) ($f['key'] ?? ''),
                'is_public=false 的事实出现在了 geo.json'
            );
        }

        $this->assertStringNotContainsString(
            'should-never-appear-in-ai-output',
            $llms,
            'is_public=false 的事实出现在了 llms.txt'
        );
    }

    // -----------------------------------------------------------------
    // 5. 架构护栏：LlmsBuilder 不得直读 metadata 的业务数值字段
    // -----------------------------------------------------------------

    /**
     * 这是防复发的关键断言。
     *
     * 若有人再次让 llms.txt 从 `organization.metadata` 读取成立时间 / 面积 /
     * 产能 / 投资 / 技术经验，本测试会直接失败并指明行号——
     * 避免 RC-9 的 P0 以任何形式回来。
     */
    public function test_llms_builder_does_not_read_business_facts_from_metadata(): void
    {
        $source = file_get_contents(app_path('Services/Geo/LlmsBuilder.php'));
        $this->assertNotFalse($source);

        // 这些 metadata 键是「业务数值类事实」，必须经 Fact contract，
        // 不得在 llms.txt 里直接读（否则与 geo.json 口径分叉）。
        $forbidden = [
            'founded_display',
            'established_production_display',
            'area_display',
            'area_sqm',
            'annual_capacity_display',
            'annual_capacity_tons',
            'total_investment_wan',
            'total_investment_display',
            'tech_experience_years',
            'tech_experience_display',
        ];

        foreach ($forbidden as $key) {
            $this->assertStringNotContainsString(
                "\$company['" . $key . "']",
                $source,
                "LlmsBuilder 直接读取了 \$company['{$key}']。"
                . '业务数值类事实必须经 Fact::publicRows() 取得（RC-9 D-02），'
                . '否则 llms.txt 与 geo.json 会再次分叉。'
            );
        }
    }

    /**
     * SchemaBuilder 的数据源边界是**已冻结**的架构约束，
     * 不得为了让「所有出口同表读取」而破坏它。
     */
    public function test_schema_builder_keeps_its_frozen_source_boundary(): void
    {
        $source = file_get_contents(app_path('Services/Geo/SchemaBuilder.php'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString(
            '禁止：直读业务事实库',
            $source,
            'SchemaBuilder.php:34 的数据源边界声明被移除了。'
            . '分层 SoT 下 Schema 不直接读 facts；若确需变更，须先更新'
            . 'D-02-Architecture-Decision-Lock.md 并重过架构评审。'
        );

        $this->assertStringNotContainsString(
            'Fact::publicRows',
            $source,
            'SchemaBuilder 开始直读 Fact::publicRows()，违反已冻结的数据源边界。'
            . 'Schema 与 facts 的一致性应由 GeoFactConsistencyTest 保证，'
            . '而不是让 Schema 直接依赖 facts 表。'
        );
    }
}
