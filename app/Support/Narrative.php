<?php

namespace App\Support;

use App\Models\Content;
use App\Support\Localization\LocaleContext;
use Illuminate\Support\Str;

/**
 * 叙事插槽（Narrative Slot）取数层
 * ------------------------------------------------------------------
 * 结构化页面（关于 / 工厂 / 合作 / 联系 / 产品 / 场景）的硬数据（配比、参数、
 * 资质、时间线、数字、FAQ、产品组合）始终锁定在站点隔离的 Catalog 单一目录源，保证 GEO
 * 证据一致、不可在 CMS 虚构；而面向运营的「叙事段落」（hero 导语、企业简介
 * 正文）允许后台「页面文案」覆盖。
 *
 * 覆盖内容以 contents.slot 片段存储（复用 Content 表，天然兼容 Markdown 编辑器、
 * 正文插图与后期 GEOFlow 推送）。slot 不是独立页面：无 URL、不进搜索 /
 * sitemap / feed / 列表（Content 的 not_slot 全局作用域统一排除）。
 *
 * 约定：
 *   - 未覆盖时一律回退 Catalog / config/pages 的默认文案，上线零差异、不做 DB 回填；
 *   - 后台清空保存即删除覆盖行，恢复默认；
 *   - summary 为纯文本 hero 导语（同时用于 meta / Schema description）；
 *   - body 为 Markdown 正文，渲染时把 H1 降级为 H2，保证每页唯一 H1。
 */
class Narrative
{
    /** 请求级缓存：slot key => Content|null（false 表示已查无） */
    private static array $cache = [];

    /** 正文 HTML 请求级缓存：slot key => string */
    private static array $htmlCache = [];

    /** 清空请求级缓存（每个请求生命周期开始时由 AppServiceProvider 调用） */
    public static function flush(): void
    {
        self::$cache = [];
        self::$htmlCache = [];
    }

    // ---------------------------------------------------------------
    // 取数
    // ---------------------------------------------------------------

    /** 取已发布的 slot 覆盖片段（旁路 not_slot 全局作用域） */
    public static function find(string $key): ?Content
    {
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = Content::withoutGlobalScope('not_slot')
                ->published()
                ->where('slot', $key)
                ->forLocale(LocaleContext::current())
                ->first();
        }
        return self::$cache[$key];
    }

    /** hero 导语（纯文本）：有覆盖用覆盖，否则回退默认 */
    public static function lead(string $key, string $default = ''): string
    {
        $c = self::find($key);
        $value = $c ? trim((string) $c->summary) : '';
        return $value !== '' ? $c->summary : $default;
    }

    /** 正文 HTML（Markdown 渲染，H1 降级 H2）：有覆盖用覆盖，否则回退默认 HTML */
    public static function html(string $key, string $defaultHtml = ''): string
    {
        if (! array_key_exists($key, self::$htmlCache)) {
            $c = self::find($key);
            $body = $c ? trim((string) $c->body) : '';
            self::$htmlCache[$key] = $body === ''
                ? $defaultHtml
                : self::renderMarkdown($body);
        }
        return self::$htmlCache[$key];
    }

    /** 渲染 Markdown 并把正文 H1 降级为 H2（每页只允许模板里的一个 H1） */
    public static function renderMarkdown(string $markdown): string
    {
        $html = Str::markdown($markdown);
        $html = preg_replace('/<(\/?)h1>/i', '<$1h2>', $html);
        return trim($html);
    }

    // ---------------------------------------------------------------
    // 插槽注册表（后台「页面文案」用）
    // ---------------------------------------------------------------

    /**
     * 全部可运营插槽，按分组返回
     *
     * @return array<int,array{group:string,items:array<int,array>}>
     */
    public static function registry(): array
    {
        $groups = [];

        foreach (self::definitions() as $def) {
            $group = $def['group'];
            $entry = [
                'key'      => $def['key'],
                'label'    => $def['label'],
                'url'      => $def['url'],
                'location' => $def['location'] ?? '',
                'has_body' => $def['has_body'] ?? false,
                'image_hint' => $def['image_hint'] ?? null,
                'customized' => self::find($def['key']) !== null,
            ];
            $groups[$group] ??= ['group' => $group, 'items' => []];
            $groups[$group]['items'][] = $entry;
        }

        return array_values($groups);
    }

    /** 单个插槽定义（含默认文案），供后台编辑预填 */
    public static function definition(string $key): ?array
    {
        foreach (self::definitions() as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }
        return null;
    }

    /** @return array<int,array> */
    private static function definitions(): array
    {
        $defs = [];

        // ---------- 关于我们 ----------
        $about = config('pages.about', []);
        $defs[] = [
            'group' => '关于我们', 'key' => 'about.profile',
            'label' => '企业简介 · 页头导语与正文', 'url' => url('/about/profile/'),
            'location' => '企业简介页：页头导语 + 左侧正文段落（右侧公司事实卡为锁定数据）',
            'has_body' => true,
            'image_hint' => '正文内插图建议宽度 ≤1280px，比例 16:9 或 4:3，JPG/WebP，单张 ≤ 6MB。',
            'default_summary' => $about['profile']['lead'] ?? '',
            'default_body' => implode("\n\n", $about['profile']['paragraphs'] ?? []),
        ];
        $defs[] = [
            'group' => '关于我们', 'key' => 'about.history',
            'label' => '发展历程 · 页头导语', 'url' => url('/about/history/'),
            'location' => '发展历程页：页头导语（下方时间线节点为锁定数据）',
            'default_summary' => $about['history']['lead'] ?? '',
        ];
        $defs[] = [
            'group' => '关于我们', 'key' => 'about.culture',
            'label' => '企业文化 · 页头导语', 'url' => url('/about/culture/'),
            'location' => '企业文化页：页头导语（使命/愿景/价值观卡片为锁定内容）',
            'default_summary' => $about['culture']['lead'] ?? '',
        ];

        // ---------- 工厂与资质 ----------
        $company = Catalog::company();
        $workshopNames = implode('、', array_map(fn ($w) => $w['name'], Catalog::workshops()));
        $defs[] = [
            'group' => '工厂与资质', 'key' => 'factory.lead',
            'label' => '工厂与资质 · 页头导语', 'url' => url('/factory/'),
            'location' => '工厂页：页头导语（数据条、生产车间、流程、资质为锁定数据）',
            'default_summary' => $workshopNames !== ''
                ? $workshopNames . '等' . count(Catalog::workshops()) . '处自有生产设施，具备稳定的生产与交付能力。'
                : '具备稳定的生产与交付能力。',
        ];

        // ---------- 合作方式 ----------
        $defs[] = [
            'group' => '合作方式', 'key' => 'cooperation.lead',
            'label' => '合作方式 · 页头导语', 'url' => url('/cooperation/'),
            'location' => '合作方式页：页头导语（合作模式、合作流程、FAQ 为锁定内容）',
            'default_summary' => config('pages.narrative.cooperation.lead', ''),
        ];

        // ---------- 联系我们 ----------
        $defs[] = [
            'group' => '联系我们', 'key' => 'contact.lead',
            'label' => '联系我们 · 页头导语', 'url' => url('/contact/'),
            'location' => '联系页：页头导语（左侧公司事实为锁定数据）',
            'default_summary' => config('pages.narrative.contact.lead', ''),
        ];

        // ---------- 产品中心 ----------
        $defs[] = [
            'group' => '产品中心', 'key' => 'products.index.lead',
            'label' => '产品总览 · 页头导语', 'url' => url('/products/'),
            'location' => '产品中心总览页：页头导语',
            'default_summary' => config('pages.narrative.products_index.lead', ''),
        ];
        foreach (Catalog::productLines() as $line) {
            $count = count(Catalog::productsByLine($line['slug']));
            $url = $count >= 1
                ? url('/products/' . $line['slug'] . '/')
                : url('/products/#' . $line['slug']);
            $defs[] = [
                'group' => '产品中心',
                'key' => 'products.line.' . $line['slug'],
                'label' => $line['name'] . ' · 系列导语',
                'url' => $url,
                'location' => '产品总览「' . $line['name'] . '」分组说明'
                    . ($count >= 1 ? '与该系列独立页页头导语（同步用于该页 SEO 描述）' : '（该系列暂无产品，无独立页，仅显示在总览分组）'),
                'default_summary' => $line['desc'] ?? '',
            ];
        }
        foreach (Catalog::products() as $p) {
            if (! Catalog::isCoreProduct($p['slug'])) {
                continue;
            }
            $defs[] = [
                'group' => '产品中心',
                'key' => 'products.detail.' . $p['slug'],
                'label' => $p['name'] . ' · 产品导语',
                'url' => url('/products/' . $p['slug']),
                'location' => '产品详情页页头导语，同步用于该页 SEO 描述与 Product 结构化数据（配比/参数/FAQ 为锁定数据）',
                'default_summary' => $p['tagline'] ?? '',
            ];
        }

        // ---------- 应用场景 ----------
        $defs[] = [
            'group' => '应用场景', 'key' => 'solutions.index.lead',
            'label' => '场景总览 · 页头导语', 'url' => url('/solutions/'),
            'location' => '应用场景总览页：页头导语',
            'default_summary' => config('pages.narrative.solutions_index.lead', ''),
        ];
        foreach (Catalog::scenes() as $scene) {
            $defs[] = [
                'group' => '应用场景',
                'key' => 'solutions.scene.' . $scene['slug'],
                'label' => $scene['name'] . ' · 场景导语',
                'url' => url('/solutions/' . $scene['slug'] . '/'),
                'location' => '场景详情页页头导语，同步用于该页 SEO 描述（痛点/产品组合/参数/流程/FAQ 为锁定数据）',
                'default_summary' => $scene['desc'] ?? '',
            ];
        }

        return $defs;
    }

    /** 插槽默认导语（纯文本） */
    public static function defaultSummary(string $key): string
    {
        return self::definition($key)['default_summary'] ?? '';
    }

    /** 插槽默认正文（Markdown） */
    public static function defaultBody(string $key): string
    {
        return self::definition($key)['default_body'] ?? '';
    }

    /** 插槽保留 slug（_slot_ 前缀，绕开普通 slug 正则与前台 URL） */
    public static function reservedSlug(string $key): string
    {
        return '_slot_' . str_replace('.', '-', $key);
    }
}
