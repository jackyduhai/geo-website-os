<?php

namespace App\Support\Render;

use App\Models\Page;
use App\Models\PageBlock;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Services\Geo\SchemaBuilder;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoResult;
use App\Support\Blocks\BlockContract;
use App\Support\Localization\LocaleContext;
use App\Support\PublicUrl;
use App\Support\SiteContext;
use App\Support\Templates\TemplateDefinition;
use App\Support\Templates\TemplateRegistry;
use Illuminate\Support\Facades\App;

/**
 * 固定系统页渲染上下文（P-STEP 18G-2b）。
 * --------------------------------------------------
 * 承载 is_system Page（产品总览 / 知识总览 / About×3 / 工厂 / 合作）：
 *  - main 为固定槽，由对应 sys_* 系统块直驱（数据由控制器从 Catalog / Pages /
 *    siteSettings 准备后经 $resource 注入），不可被 override、不复制业务事实；
 *  - related 为可组合槽，读 Page 持久化 PageBlock（管理员追加 CTA / FAQ）。
 *
 * TD-61：系统页 SEO 统一走 {@see SeoMetaResolver::resolvePage}，控制器不再手工拼
 * SEO / Canonical / Schema。页面身份与模板绑定由 is_system Page 提供。
 */
class SystemPageRenderContext implements RenderContext
{
    /**
     * 固定系统页默认定义（唯一来源，SystemPageSeeder 与 fallback 共用）。
     * system_key => [template, canonical slug path, title translation key]
     */
    public const DEFINITIONS = [
        'products'    => ['listing', 'products/',       'nav.products'],
        'solutions'   => ['listing', 'solutions/',      'nav.solutions'],
        'knowledge'   => ['listing', 'knowledge/',      'nav.knowledge'],
        'profile'     => ['detail',  'about/profile/',  'ui.eyebrow_about'],
        'history'     => ['detail',  'about/history/',  'ui.eyebrow_history'],
        'culture'     => ['detail',  'about/culture/',  'ui.eyebrow_culture'],
        'factory'     => ['detail',  'factory/',        'ui.eyebrow_factory'],
        'cooperation' => ['detail',  'cooperation/',    'ui.eyebrow_cooperation'],
        'contact'     => ['contact', 'contact/',        'ui.contact_h1'],
    ];

    /**
     * 取当前站点 / 语言的固定系统页：优先持久化 is_system Page（installer 注入，承载
     * 管理员定制 / page-level SEO）；缺失时按 {@see DEFINITIONS} 构造未保存的默认 Page。
     *
     * 这样固定系统页不会因为记录缺失就 404：是否公开仍由各控制器的 company /
     * production 门禁裁决（空目录站 products / factory 等仍 404），而 knowledge
     * 这类 Content 域页面在空站也可达。
     */
    public static function resolve(string $systemKey): Page
    {
        if (! array_key_exists($systemKey, self::DEFINITIONS)) {
            throw new \InvalidArgumentException("Unknown system page: {$systemKey}");
        }

        $locale = LocaleContext::current();
        $site = SiteContext::currentSite();

        $page = Page::query()
            ->where('site_id', $site->id)
            ->where('system_key', $systemKey)
            ->where('is_system', true)
            ->where('locale', $locale)
            ->first();

        if ($page !== null) {
            return $page;
        }

        [$template, $slugPath, $titleKey] = self::DEFINITIONS[$systemKey];

        return new Page([
            'site_id'    => $site->id,
            'template'   => $template,
            'slug'       => $slugPath,
            'is_system'  => true,
            'is_home'    => false,
            'system_key' => $systemKey,
            'status'     => Page::STATUS_PUBLISHED,
            'locale'     => $locale,
            'title'      => self::trans($titleKey, $locale),
        ]);
    }

    /** 在不泄漏全局 locale 的前提下取某语言文案。 */
    private static function trans(string $key, string $locale): string
    {
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            return (string) __($key);
        } finally {
            App::setLocale($previous);
        }
    }

    public function __construct(
        private readonly Page $page,
        private readonly string $systemKey,
        private readonly array $resource = [],
    ) {}

    public function page(): Page
    {
        return $this->page;
    }

    public function systemKey(): string
    {
        return $this->systemKey;
    }

    public function site(): Site
    {
        return SiteContext::currentSite();
    }

    public function locale(): string
    {
        return LocaleContext::current();
    }

    public function resourceType(): string
    {
        return 'system';
    }

    public function template(): TemplateDefinition
    {
        return TemplateRegistry::get($this->page->template)
            ?: TemplateRegistry::get('listing');
    }

    public function seo(): SeoResult
    {
        $resolver = app(SeoMetaResolver::class);
        $result = $resolver->resolvePage($this->page);
        $default = $this->resource['seo_default'] ?? [];

        // 管理员为该系统页设置的 page-level SeoMeta 优先（resolver 已消费）；
        // 否则使用页面类型默认文案（resource，等价 Entity 自身 name/summary 层），
        // 再回退站点。resolver 仍是唯一 SEO 裁决器，控制器不另拼 SEO。
        $hasPageMeta = SeoMeta::query()
            ->where('page_id', $this->page->id)
            ->where('locale', $this->locale())
            ->exists();
        if ($hasPageMeta) {
            return $result;
        }

        $title = $result->title;
        $desc = $result->description;
        $dirty = false;
        if (! empty($default['title'])) {
            $title = $default['title'];
            $dirty = true;
        }
        if (! empty($default['description'])) {
            $desc = $default['description'];
            $dirty = true;
        }
        if (! $dirty) {
            return $result;
        }

        return new SeoResult(
            title: $title,
            description: $desc,
            keywords: $result->keywords,
            canonical: $result->canonical,
            ogTitle: $title,
            ogDescription: $desc,
            ogImage: $result->ogImage,
            ogType: $result->ogType,
            twitterCard: $result->twitterCard,
            noindex: $result->noindex,
            nofollow: $result->nofollow,
            robots: $result->robots,
            schemaType: $result->schemaType,
        );
    }

    public function hasHero(): bool
    {
        // Contact 无 hero（header rich_text 提供可见标题）；其余 sys_* 内含 page-hero。
        return $this->systemKey !== 'contact';
    }

    public function crumbs(): array
    {
        $home = ['name' => __('nav.home'), 'url' => PublicUrl::home()];

        return match ($this->systemKey) {
            'solutions' => array_merge([$home], [
                ['name' => __('nav.solutions'), 'url' => PublicUrl::url('solutions/')],
            ]),
            'products' => array_merge([$home], [
                ['name' => __('nav.products'), 'url' => PublicUrl::url('products/')],
            ]),
            'knowledge' => array_merge([$home], [
                ['name' => __('nav.knowledge'), 'url' => PublicUrl::url('knowledge/')],
            ]),
            'factory' => array_merge([$home], [
                ['name' => __('ui.eyebrow_factory'), 'url' => PublicUrl::url('factory/')],
            ]),
            'cooperation' => array_merge([$home], [
                ['name' => __('ui.eyebrow_cooperation'), 'url' => PublicUrl::url('cooperation/')],
            ]),
            'profile' => array_merge([$home], [
                ['name' => __('ui.eyebrow_about'), 'url' => PublicUrl::url('about/profile/')],
            ]),
            'history', 'culture' => [
                $home,
                ['name' => __('ui.eyebrow_about'), 'url' => PublicUrl::url('about/profile/')],
                ['name' => __($this->systemKey === 'history' ? 'ui.eyebrow_history' : 'ui.eyebrow_culture'),
                 'url' => PublicUrl::url('about/' . $this->systemKey . '/')],
            ],
            'contact' => array_merge([$home], [
                ['name' => __('ui.contact_h1'), 'url' => PublicUrl::url('contact/')],
            ]),
            default => [$home],
        };
    }

    public function subnav(): ?array
    {
        return $this->resource['subnav'] ?? null;
    }

    public function blocksForSlot(string $slot): array
    {
        // Contact：header / main 为可组合普通 block（rich_text / contact_info /
        // form_reference），由 Page 持久化、管理员可编辑（Scenario A）。
        if ($this->systemKey === 'contact') {
            if ($this->page->exists) {
                return $this->page->blocks()
                    ->where('slot', $slot)
                    ->where('is_active', true)
                    ->orderBy('sort')
                    ->get()
                    ->all();
            }

            // fallback（无持久化 Page，如未跑 SystemPageSeeder 的测试 / 数据缺失）：
            // 返回内存默认 contact blocks，保证渲染链不依赖记录是否存在。
            return $this->defaultContactBlocks($slot);
        }

        return match ($slot) {
            'main' => [new VirtualBlock($this->systemBlockType(), [])],
            'related' => $this->page->blocks()
                ->where('slot', 'related')
                ->where('is_active', true)
                ->orderBy('sort')
                ->get()
                ->all(),
            default => [],
        };
    }

    public function viewContext(): array
    {
        return array_merge(
            ['page' => $this->page, 'site' => $this->site(), 'aboutPage' => $this->systemKey],
            $this->resource
        );
    }

    public function schemaNodes(): array
    {
        $schema = app(SchemaBuilder::class);
        $nodes = array_merge(
            [$schema->organization(), $schema->breadcrumb($this->crumbs())],
            $this->resource['schemas'] ?? []
        );

        return array_values(array_filter($nodes));
    }

    /** 系统页 key → 固定槽系统块类型。 */
    private function systemBlockType(): string
    {
        return match ($this->systemKey) {
            'solutions' => 'sys_solutions',
            'products' => 'sys_products',
            'knowledge' => 'sys_knowledge',
            'profile', 'history', 'culture' => 'sys_about',
            'factory' => 'sys_factory',
            'cooperation' => 'sys_cooperation',
            default => 'sys_' . $this->systemKey,
        };
    }

    /** Contact fallback：无持久化 Page 时的内存默认区块（不写库）。 */
    private function defaultContactBlocks(string $slot): array
    {
        $locale = $this->locale();
        $siteId = $this->site()->id;

        if ($slot === 'header') {
            return [new PageBlock([
                'site_id' => $siteId,
                'page' => 'contact',
                'slot' => 'header',
                'type' => 'rich_text',
                'sort' => 0,
                'limit' => 0,
                'is_active' => true,
                'content' => json_encode(['title' => self::trans('ui.contact_h1', $locale), 'body' => '']),
            ])];
        }

        if ($slot === 'main') {
            return [
                new PageBlock([
                    'site_id' => $siteId,
                    'page' => 'contact',
                    'slot' => 'main',
                    'type' => 'contact_info',
                    'sort' => 0,
                    'limit' => 0,
                    'is_active' => true,
                    'content' => json_encode([
                        'title' => '',
                        'show_phone' => true, 'show_email' => true,
                        'show_address' => true, 'show_social' => true,
                    ]),
                ]),
                new PageBlock([
                    'site_id' => $siteId,
                    'page' => 'contact',
                    'slot' => 'main',
                    'type' => 'form_reference',
                    'sort' => 1,
                    'limit' => 0,
                    'is_active' => true,
                    'content' => json_encode(['title' => '', 'subtitle' => '']),
                ]),
            ];
        }

        return [];
    }
}
