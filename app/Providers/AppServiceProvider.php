<?php

namespace App\Providers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Fact;
use App\Models\Group;
use App\Models\Media;
use App\Models\Menu;
use App\Models\PageBlock;
use App\Models\Redirect as RedirectRule;
use App\Models\SeoMeta;
use App\Models\Setting;
use App\Contracts\UrlResolverInterface;
use App\Services\Seo\GenericUrlResolver;
use App\Support\Catalog;
use App\Support\Theme\ThemePalette;
use App\Support\PageCache;
use App\Support\RequestScopedState;
use App\Support\SiteCacheKey;
use App\Support\GeoUrlGenerator;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 用「保留目录型尾斜杠」的 UrlGenerator 替换框架默认实现（单点系统修复，
        // 让 url('/products/') 等内部链接直接输出规范地址，避免每次点击先 301）。
        // 这里复刻框架 RoutingServiceProvider 的绑定，补齐 session/key resolver，
        // 不破坏签名 URL、路由集合 rebind 等能力。
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);

            $url = new GeoUrlGenerator(
                $routes,
                $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url']
            );

            $url->setSessionResolver(fn () => $this->app['session'] ?? null);
            $url->setKeyResolver(function () {
                $config = $this->app->make('config');
                return [$config->get('app.key'), ...($config->get('app.previous_keys') ?? [])];
            });

            $app->rebinding('routes', function ($app, $routes) {
                $app['url']->setRoutes($routes);
            });

            return $url;
        });

        // Bind generic URL resolver for SeoMeta canonical generation
        $this->app->bind(UrlResolverInterface::class, GenericUrlResolver::class);

        // P-STEP 18H-1：搜索引擎按运行环境选择——SQLite + FTS5 可用时走 FTS5，
        // 否则（MySQL / PostgreSQL / 未编译 FTS5）回退普通表 LIKE。上层只依赖接口，
        // 未来替换 Meilisearch / Elasticsearch 时无需改动调用方。
        $this->app->bind(\App\Support\Search\SearchEngineInterface::class, function ($app) {
            $fts = $app->make(\App\Support\Search\SqliteFtsEngine::class);

            return $fts->isAvailable() ? $fts : $app->make(\App\Support\Search\DatabaseLikeEngine::class);
        });
    }

    public function boot(): void
    {
        // 导航 / 页脚视图记忆归属本 Provider，注册到统一复位器（进程内仅注册一次，回调幂等）。
        if (! self::$stateCallbackRegistered) {
            RequestScopedState::onReset([self::class, 'flushViewComposerMemos']);
            self::$stateCallbackRegistered = true;
        }

        // 按当前站点复位请求级记忆并重放主题 / 插件注册。
        // 此刻（中间件之前）站点通常仍解析为 default；ResolveSite 解析出真实站点后会再次 reapply()，
        // 避免 default 站预热的设置 / 导航 / 事实 / 主题快照污染真实站点，同时保证常驻进程
        // （Octane / 同进程连续请求 / queue worker）跨站不脏读。
        RequestScopedState::reapply();

        // 前台整页静态化缓存的自动失效：任一影响前台展示的内容模型发生
        // 新增/修改/删除（后台保存、GEOFlow 推内容等），版本号 +1，旧页面缓存整体作废。
        // 留言（Inquiry）、操作日志、同步日志、用户不影响前台展示，刻意不纳入。
        foreach ([
            Content::class, ContentRevision::class, Category::class, Group::class,
            Banner::class, Menu::class, PageBlock::class, Setting::class,
            Media::class, RedirectRule::class, Fact::class,
            // SeoMeta 显式覆盖直接决定前台 title/description/canonical/OG/robots，变更即作废整页静态壳。
            SeoMeta::class,
        ] as $model) {
            $model::saved(static fn () => PageCache::flush());
            $model::deleted(static fn () => PageCache::flush());
        }

        // P-STEP 18H-1：搜索索引增量同步（Content/Entity 保存即增量重算；noindex / 栏目 /
        // 关系 / 站点 / 设置变更标记 dirty，引擎查询前懒重建），索引无需人工 reindex。
        app(\App\Support\Search\SearchIndexSync::class)->register();

        // 301/302 规则增删改后，同步失效跳转执行层的永久缓存
        RedirectRule::saved(static fn () => \App\Http\Middleware\HandleRedirects::flushRules());
        RedirectRule::deleted(static fn () => \App\Http\Middleware\HandleRedirects::flushRules());

        // 页脚联系列的值取自站点设置（电话/手机/地址/二维码），设置变更后同步失效导航缓存
        Setting::saved(static fn () => self::forgetNavCache());

        // 前台全局共享：站点设置、导航树、公开事实库
        View::composer([
            'layouts.site',
            'site.*',
            'components.*',
            'partials.*',
            'errors.*',
            'admin.*',
        ], function ($view) {
            $settings = Setting::allCached();
            $view->with('siteSettings', $settings);
            // P-STEP 18D：从少量外观种子（品牌主色 / 辅色 / 中性 / 圆角 / 密度 / 质感）
            // 派生整套语义化设计令牌，:root 唯一消费；改一个品牌基色即全站协调，无孤立 Hex。
            $view->with('themeTokens', ThemePalette::resolve($settings));
            // P-STEP 18D Light/Dark：深色外观仅覆盖的语义令牌子集（中性阶 / 深底提亮品牌色）。
            $view->with('darkTokens', ThemePalette::darkOverrides($settings));
            $view->with('navTree', static::navTree());
            $view->with('mainMenu', static::mainMenu());
            $view->with('footerMenu', static::footerMenu());
            $view->with('footerExtra', static::footerExtra());
            // 全站主 CTA 文案：后台「基础信息 → 主 CTA 按钮文案」可改，留空回退翻译默认。
            // 非默认语言（/en）时单语言 CTA 不跨语言套用，回退当前语言翻译。
            $ctaDefault = __('ui.bcta_primary');
            $view->with('ctaText', \App\Support\Localization\LocaleContext::current() === \App\Support\Localization\LocaleRegistry::default()
                && trim((string) ($settings['nav_cta_text'] ?? '')) !== ''
                ? $settings['nav_cta_text']
                : $ctaDefault);
            $view->with('publicFacts', Fact::publicMap());
            // 留言归因（首次落地页 / 外部来源 / UTM），由 CaptureAttribution 写入 session
            if (session()->isStarted()) {
                $view->with('leadAttr', session()->get('attr', []));
            } else {
                $view->with('leadAttr', []);
            }
        });

        // SEO 头部归一化（STEP 02）：布局头部只消费归一化后的 $seo，
        // Blade 不再读取 Setting / URL 生成器或自行拼接 SEO 兜底。
        // 须注册在全局 siteSettings composer 之后，保证遗留兜底设置已就位。
        View::composer('layouts.site', \App\Http\View\SeoHeadComposer::class);
    }

    /**
     * 导航树：取 is_nav 的顶级栏目及其启用子栏目。
     * 结构变更时需清缓存（后台保存栏目后调用 Cache::forget('nav.tree')）。
     */
    /** 请求级内存，避免视图 composer 对每个 @include 都重复读缓存存储 */
    private static mixed $navTreeMemo = null;
    private static ?array $mainMenuMemo = null;
    private static ?array $footerExtraMemo = null;
    private static ?array $blueprintMemo = null;
    private static ?array $footerBlueprintMemo = null;
    private static ?array $footerMenuMemo = null;

    /** RequestScopedState 复位回调是否已注册（进程内注册一次即可，回调幂等） */
    private static bool $stateCallbackRegistered = false;

    /**
     * 清空导航 / 页脚等视图 Composer 的进程内短路记忆（幂等，由 RequestScopedState 调起）。
     * 只清进程内存、不动持久缓存：缓存键本身含 site_id，切站后 Cache::remember 会按新站取对数据。
     */
    public static function flushViewComposerMemos(): void
    {
        self::$navTreeMemo = null;
        self::$mainMenuMemo = null;
        self::$footerExtraMemo = null;
        self::$blueprintMemo = null;
        self::$footerBlueprintMemo = null;
        self::$footerMenuMemo = null;
    }

    /**
     * 由 href 推导当前态匹配 pattern（首段 + 通配），如 /about/profile/ => ['about*']
     */
    private static function navPattern(string $href): array
    {
        $path  = (string) parse_url($href, PHP_URL_PATH);
        $first = explode('/', trim($path, '/'))[0] ?? '';
        return $first === '' ? [] : [$first . '*'];
    }

    /**
     * 由链接推导稳定栏目 key：顶级取首段，子级取规范化后的完整路径（含锚点）。
     * /products/ => products；/products/seasoning/ => products-seasoning；
     * /factory/#workshops => factory-workshops。
     */
    public static function keyForHref(string $href): string
    {
        $path = (string) parse_url($href, PHP_URL_PATH);
        $frag = (string) parse_url($href, PHP_URL_FRAGMENT);
        $s = trim($path, '/');
        if ($frag !== '') {
            $s = rtrim($s, '/') . '/' . $frag;
        }
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    public static function navTree()
    {
        if (self::$navTreeMemo !== null) {
            return self::$navTreeMemo;
        }
        return self::$navTreeMemo = Cache::remember(SiteCacheKey::navTree(), 600, function () {
            return Category::query()
                ->where('is_active', true)
                ->where('is_nav', true)
                ->whereNull('parent_id')
                ->with(['children' => function ($q) {
                    $q->where('is_active', true)->orderBy('sort');
                }])
                ->orderBy('sort')
                ->get();
        });
    }

    /**
     * 主导航蓝图（后台「导航菜单」与前台共用的唯一结构来源）：
     * 固定栏目来自 config('copy.nav.menu')（知识中心子项取启用的内容分组 groups），
     * 叠加 menus 表中 key 非空的「覆盖层」——可改名、隐藏、排序，并可恢复默认；
     * key 为空的自定义项是“追加”，不参与蓝图，见 mainMenu()。
     *
     * 节点字段：key / default_name / name / href(原始) / url / patterns / external /
     *          visible / sort / overridden / children。
     */
    public static function mainMenuBlueprint(): array
    {
        if (self::$blueprintMemo !== null) {
            return self::$blueprintMemo;
        }
        return self::$blueprintMemo = Cache::remember(SiteCacheKey::mainMenuBlueprint(), 600, function () {
            $overrides = Menu::whereNotNull('key')->where('position', 'main')->get()->keyBy('key');

            // $dynamic=true 的节点由内容分组驱动（知识中心子项），链接不接受覆盖，请到「内容分组」调整
            $makeNode = function (string $key, string $defaultName, string $rawHref, int $defaultSort, bool $external = false, bool $dynamic = false) use ($overrides) {
                $o = $overrides->get($key);
                $overridden = $o !== null;
                $visible = ! ($o && ! (bool) $o->is_active);
                // 固定栏目 label 走翻译（nav.<key>）；动态/自定义项无翻译键时保留数据 label。
                $navTrans = __("nav.$key");
                if ($navTrans !== "nav.$key") {
                    $defaultName = $navTrans;
                }
                $name = ($o && trim((string) $o->label) !== '') ? $o->label : $defaultName;
                $sortValue = ($o && (int) $o->sort > 0) ? (int) $o->sort : null;
                $sort = $sortValue ?? $defaultSort;

                // 固定栏目的链接 / 打开方式同样可被后台覆盖（动态节点除外）
                $effectiveHref = $rawHref;
                $forceExternal = $external;
                if ($o && ! $dynamic) {
                    $customUrl = trim((string) $o->url);
                    if ($customUrl !== '') {
                        $effectiveHref = $customUrl;
                    }
                    if ((int) $o->target === 1) {
                        $forceExternal = true;
                    }
                }
                $resolved = static::resolveMenuHref($effectiveHref);
                $isExternal = $forceExternal || $resolved['external'];

                // D.2 多站目录隔离：产品 / 场景 / 工厂 / 关于等目录型链接只在当前站点
                // 自己的 Catalog 中真实存在时才进入导航，空目录站不输出指向不存在目录的链接。
                $visible = $visible && static::navPathExists($effectiveHref);

                return [
                    'key'          => $key,
                    'default_name' => $defaultName,
                    'name'         => $name,
                    'href'         => $effectiveHref,
                    'default_href' => $rawHref,
                    'url'          => $resolved['url'],
                    'patterns'     => $isExternal ? [] : static::navPattern($resolved['path'] ?? $effectiveHref),
                    'external'     => $isExternal,
                    'dynamic'      => $dynamic,
                    'visible'      => $visible,
                    'sort'         => $sort,
                    'sort_value'   => $sortValue,
                    'overridden'   => $overridden,
                    'children'     => [],
                ];
            };

            $tops = [];
            $ti = 0;
            foreach ((array) config('copy.nav.menu', []) as $item) {
                $rawTop = (string) ($item['href'] ?? '/');
                $topKey = explode('/', trim((string) parse_url($rawTop, PHP_URL_PATH), '/'))[0] ?? '';
                if ($topKey === '') {
                    $topKey = 'menu-' . $ti;
                }
                $top = $makeNode($topKey, (string) ($item['label'] ?? ''), $rawTop, ($ti + 1) * 10);

                // 子项内容驱动（行业中立、随站点隔离）：知识中心取内容分组，产品中心取
                // 本站产品线，应用场景取本站场景；其余（工厂锚点 / 关于）取 config 固定子项。
                $rawChildren = [];
                if ($topKey === 'knowledge') {
                    foreach (\App\Models\Group::knowledgeChannels() as $g) {
                        $kgKey = 'nav.knowledge-' . $g->slug;
                        $kgTrans = __($kgKey);
                        $kgLabel = $kgTrans !== $kgKey ? $kgTrans : $g->name;
                        $rawChildren[] = ['label' => $kgLabel, 'href' => '/knowledge/' . $g->slug . '/', 'dynamic' => true];
                    }
                } elseif ($topKey === 'products') {
                    foreach (\App\Support\Catalog::productLines() as $line) {
                        $rawChildren[] = ['label' => (string) ($line['name'] ?? ''), 'href' => '/products/' . ($line['slug'] ?? '') . '/', 'dynamic' => true];
                    }
                } elseif ($topKey === 'solutions') {
                    foreach (\App\Support\Catalog::scenes() as $sc) {
                        $rawChildren[] = ['label' => (string) ($sc['name'] ?? ''), 'href' => '/solutions/' . ($sc['slug'] ?? '') . '/', 'dynamic' => true];
                    }
                } else {
                    foreach ((array) ($item['children'] ?? []) as $ch) {
                        $rawChildren[] = ['label' => (string) $ch['label'], 'href' => (string) $ch['href'], 'dynamic' => false];
                    }
                }
                $ci = 0;
                foreach ($rawChildren as $ch) {
                    $cKey = static::keyForHref($ch['href']) ?: ($topKey . '-child-' . $ci);
                    $top['children'][] = $makeNode($cKey, $ch['label'], $ch['href'], ($ci + 1) * 10, false, (bool) ($ch['dynamic'] ?? false));
                    $ci++;
                }
                $tops[] = $top;
                $ti++;
            }

            usort($tops, fn ($a, $b) => $a['sort'] <=> $b['sort']);
            foreach ($tops as &$t) {
                usort($t['children'], fn ($a, $b) => $a['sort'] <=> $b['sort']);
            }
            unset($t);

            return $tops;
        });
    }

    /**
     * 主菜单（前台消费）：蓝图中可见的固定栏目 + 后台追加的自定义项（key 为空）。
     * 输出结构保持 name / url / patterns / children(name,url) / external 不变。
     */
    public static function mainMenu(): array
    {
        if (self::$mainMenuMemo !== null) {
            return self::$mainMenuMemo;
        }
        return self::$mainMenuMemo = Cache::remember(SiteCacheKey::mainMenu(), 600, function () {
            // 自定义菜单（key 为空）一次性取出，支持两级：
            //  - parent_key 非空：挂到固定一级栏目（蓝图节点）下
            //  - parent_id  非空：挂到自定义一级菜单下
            $customAll = Menu::whereNull('key')
                ->where('is_active', true)
                ->whereIn('position', ['main', 'mobile'])
                ->orderBy('sort')
                ->get();
            $customByParentKey = $customAll
                ->filter(fn ($m) => $m->parent_key !== null && $m->parent_key !== '')
                ->groupBy('parent_key');

            // 自定义项未显式排序时排在固定项之后（1000 + id，稳定且可被显式 sort 覆盖）
            $customSort = fn ($m) => ((int) $m->sort > 0 ? (int) $m->sort : 1000 + (int) $m->id);

            $renderChild = function ($m) use ($customSort) {
                $resolved = static::resolveMenuHref($m->link());
                return [
                    'name'     => $m->label,
                    'url'      => $resolved['url'],
                    'external' => $resolved['external'] || (int) $m->target === 1,
                    'sort'     => $customSort($m),
                ];
            };

            $menu = [];
            foreach (static::mainMenuBlueprint() as $top) {
                if (! $top['visible']) {
                    continue;
                }
                $children = [];
                foreach ($top['children'] as $ch) {
                    if ($ch['visible']) {
                        $children[] = [
                            'name' => $ch['name'], 'url' => $ch['url'],
                            'external' => $ch['external'], 'sort' => $ch['sort'],
                        ];
                    }
                }
                // 固定栏目下的自定义子项：与固定子项按 sort 统一排序
                foreach ($customByParentKey->get($top['key'], []) as $cm) {
                    $children[] = $renderChild($cm);
                }
                usort($children, fn ($a, $b) => $a['sort'] <=> $b['sort']);
                $menu[] = [
                    'key'      => $top['key'],
                    'name'     => $top['name'],
                    'url'      => $top['url'],
                    'patterns' => $top['patterns'],
                    'children' => $children,
                    'external' => $top['external'],
                    'sort'     => $top['sort'],
                ];
            }

            // 自定义一级菜单（含其自定义子项），与固定一级按 sort 统一排序（默认在固定项之后）
            $customRoots = $customAll->filter(fn ($m) => empty($m->parent_id) && ($m->parent_key === null || $m->parent_key === ''));
            foreach ($customRoots as $m) {
                $resolved = static::resolveMenuHref($m->link());
                $children = $customAll
                    ->filter(fn ($c) => (int) $c->parent_id === (int) $m->id)
                    ->map($renderChild)
                    ->values()
                    ->all();
                usort($children, fn ($a, $b) => $a['sort'] <=> $b['sort']);
                $menu[] = [
                    'key'      => null,
                    'name'     => $m->label,
                    'url'      => $resolved['url'],
                    'patterns' => $resolved['external'] ? [] : static::navPattern($resolved['path']),
                    'children' => $children,
                    'external' => $resolved['external'] || (int) $m->target === 1,
                    'sort'     => $customSort($m),
                ];
            }

            usort($menu, fn ($a, $b) => $a['sort'] <=> $b['sort']);

            return $menu;
        });
    }

    /**
     * 某固定一级栏目（蓝图 key）合并后的二级子项：固定 / 内容分组动态项 + 自定义挂接项，
     * 已按统一排序合并（与顶部导航下拉完全同源）。供前台页面内二级 Tab（subnav）消费，
     * 杜绝「下拉里有、页面 Tab 里没有」的数据源分叉。
     *
     * @return array<int,array{name:string,url:string,external:bool,sort:int}>
     */
    public static function mergedMenuChildren(string $topKey): array
    {
        $top = collect(static::mainMenu())->firstWhere('key', $topKey);

        return $top ? $top['children'] : [];
    }

    /**
     * 页脚蓝图（后台「导航菜单」与前台共用）：固定列来自 config('copy.footer.columns')，
     * 叠加 menus 表中 position=footer、key 非空的覆盖层——列标题 / 链接文字 / 链接地址 /
     * 新窗 / 显隐 / 排序均可改、可恢复默认；联系列为设置驱动（locked，不接受链接覆盖）。
     */
    public static function footerBlueprint(): array
    {
        if (self::$footerBlueprintMemo !== null) {
            return self::$footerBlueprintMemo;
        }
        return self::$footerBlueprintMemo = Cache::remember(SiteCacheKey::footerBlueprint(), 600, function () {
            $overrides = Menu::whereNotNull('key')->where('position', 'footer')->get()->keyBy('key');

            $colKeyMap = [
                '联系我们' => 'ft-col-contact',
                '产品中心' => 'ft-col-products',
                '应用场景' => 'ft-col-solutions',
                '关于我们' => 'ft-col-about',
            ];

            $applyItem = function (string $key, array $raw, int $defaultSort, bool $locked = false, bool $dynamic = false) use ($overrides) {
                $o = $overrides->get($key);
                $defaultName = (string) ($raw['label'] ?? '');
                // 固定页脚项 label 走翻译（nav.<key>）；动态/自定义项无翻译键保留数据 label。
                $navTrans = __("nav.$key");
                if ($navTrans !== "nav.$key") {
                    $defaultName = $navTrans;
                }
                $rawHref = (string) ($raw['href'] ?? '');
                $type = (string) ($raw['type'] ?? 'link');
                $name = ($o && trim((string) $o->label) !== '') ? $o->label : $defaultName;
                $sortValue = ($o && (int) $o->sort > 0) ? (int) $o->sort : null;
                $forcedHidden = (! $locked && $rawHref !== '' && str_contains($rawHref, '/cases')); // 不建案例中心
                $visible = ! $forcedHidden && ! ($o && ! (bool) $o->is_active);

                $effectiveHref = $rawHref;
                $forceExternal = false;
                if ($o && ! $locked) {
                    $customUrl = trim((string) $o->url);
                    if ($customUrl !== '') {
                        $effectiveHref = $customUrl;
                    }
                    $forceExternal = (int) $o->target === 1;
                }
                $resolved = static::resolveMenuHref($effectiveHref);
                $external = $forceExternal || $resolved['external'];

                // D.2 多站目录隔离：目录型页脚链接同样以当前站点 Catalog 是否存在为准。
                $visible = $visible && static::navPathExists($effectiveHref);

                return [
                    'key'          => $key,
                    'type'         => $type,
                    'default_name' => $defaultName,
                    'name'         => $name,
                    'value'        => (string) ($raw['value'] ?? ''),
                    'href'         => $effectiveHref,
                    'default_href' => $rawHref,
                    'url'          => $resolved['url'],
                    'external'     => $external,
                    'locked'       => $locked,
                    'dynamic'      => $dynamic,
                    'visible'      => $visible,
                    'forced_hidden'=> $forcedHidden,
                    'sort'         => $sortValue ?? $defaultSort,
                    'sort_value'   => $sortValue,
                    'overridden'   => $o !== null,
                ];
            };

            $columns = [];
            $ci = 0;
            foreach ((array) config('copy.footer.columns', []) as $col) {
                $title = (string) ($col['title'] ?? '');
                $colKey = $colKeyMap[$title] ?? ('ft-col-' . $ci);
                $co = $overrides->get($colKey);
                $isContact = $title === '联系我们';

                $items = [];
                if ($isContact) {
                    // 联系列：热线 / 业务手机（设置驱动，无值则不显示）/ 地址 / 二维码
                    $hotline = (string) (\App\Support\Catalog::company()['phone'] ?? '');
                    $si = 0;
                    $items[] = $applyItem('ft-contact-hotline', ['type' => 'text', 'label' => '合作热线', 'href' => $hotline !== '' ? 'tel:' . $hotline : ''], ++$si * 10, true);
                    $items[] = $applyItem('ft-contact-mobile', ['type' => 'text', 'label' => '业务手机'], ++$si * 10, true, true);
                    $items[] = $applyItem('ft-contact-address', ['type' => 'text', 'label' => '地址'], ++$si * 10, true);
                    $items[] = $applyItem('ft-contact-qr', ['type' => 'qr', 'label' => '扫码联系'], ++$si * 10, true);
                } else {
                    $si = 0;
                    // 产品中心 / 应用场景列由本站 Catalog 内容驱动（空目录站整列自动隐藏），
                    // 不内置任何行业条目；其余列取 config 固定链接。
                    $rawItems = [];
                    $colDynamic = false;
                    if ($title === '产品中心') {
                        foreach (\App\Support\Catalog::productLines() as $line) {
                            $rawItems[] = ['label' => (string) ($line['name'] ?? ''), 'href' => '/products/' . ($line['slug'] ?? '') . '/'];
                        }
                        $colDynamic = true;
                    } elseif ($title === '应用场景') {
                        foreach (\App\Support\Catalog::scenes() as $sc) {
                            $rawItems[] = ['label' => (string) ($sc['name'] ?? ''), 'href' => '/solutions/' . ($sc['slug'] ?? '') . '/'];
                        }
                        $colDynamic = true;
                    } else {
                        $rawItems = (array) ($col['items'] ?? []);
                    }
                    foreach ($rawItems as $raw) {
                        $ikey = 'ft-' . (static::keyForHref((string) ($raw['href'] ?? '')) ?: ('item-' . $ci . '-' . $si));
                        $items[] = $applyItem($ikey, (array) $raw, ++$si * 10, false, $colDynamic);
                    }
                }

                $titleTrans = __("nav.$colKey");
                $resolvedTitle = ($titleTrans !== "nav.$colKey") ? $titleTrans : $title;
                $columns[] = [
                    'key'           => $colKey,
                    'default_title' => $title,
                    'title'         => ($co && trim((string) $co->label) !== '') ? $co->label : $resolvedTitle,
                    'contact'       => $isContact,
                    'visible'       => ! ($co && ! (bool) $co->is_active),
                    'sort'          => ($co && (int) $co->sort > 0) ? (int) $co->sort : ($ci + 1) * 10,
                    'sort_value'    => ($co && (int) $co->sort > 0) ? (int) $co->sort : null,
                    'overridden'    => $co !== null,
                    'items'         => $items,
                ];
                $ci++;
            }

            usort($columns, fn ($a, $b) => $a['sort'] <=> $b['sort']);
            foreach ($columns as &$c) {
                usort($c['items'], fn ($a, $b) => $a['sort'] <=> $b['sort']);
            }
            unset($c);

            return $columns;
        });
    }

    /**
     * 页脚菜单（前台消费）：可见固定列（含覆盖层）+ 挂到列上的自定义链接；
     * 未挂列的页脚自定义一级链接由 footerExtra() 输出为「快捷入口」列。
     */
    public static function footerMenu(): array
    {
        if (self::$footerMenuMemo !== null) {
            return self::$footerMenuMemo;
        }
        return self::$footerMenuMemo = Cache::remember(SiteCacheKey::footerMenu(), 600, function () {
            $customSort = fn ($m) => ((int) $m->sort > 0 ? (int) $m->sort : 1000 + (int) $m->id);
            $anchored = Menu::whereNull('key')
                ->where('is_active', true)
                ->where('position', 'footer')
                ->whereNotNull('parent_key')
                ->where('parent_key', '!=', '')
                ->orderBy('sort')
                ->get()
                ->groupBy('parent_key');

            $columns = [];
            foreach (static::footerBlueprint() as $col) {
                if (! $col['visible']) {
                    continue;
                }
                $items = [];
                foreach ($col['items'] as $item) {
                    if (! $item['visible']) {
                        continue;
                    }
                    $items[] = $item + ['sort_effective' => $item['sort']];
                }
                foreach ($anchored->get($col['key'], []) as $m) {
                    $resolved = static::resolveMenuHref($m->link());
                    $items[] = [
                        'key' => null, 'type' => 'link', 'name' => $m->label,
                        'value' => '', 'href' => '', 'url' => $resolved['url'],
                        'external' => $resolved['external'] || (int) $m->target === 1,
                        'locked' => false, 'dynamic' => false, 'visible' => true,
                        'forced_hidden' => false, 'overridden' => false,
                        'sort' => $customSort($m), 'sort_effective' => $customSort($m),
                    ];
                }
                usort($items, fn ($a, $b) => $a['sort_effective'] <=> $b['sort_effective']);
                // D.2：空目录站剔除目录链接后，非联系列若已无任何可见项则整列不输出（联系列保留）。
                if (empty($items) && empty($col['contact'])) {
                    continue;
                }
                $col['items'] = $items;
                $columns[] = $col;
            }

            return $columns;
        });
    }

    /**
     * 页脚未挂列的自定义一级链接（后台「位置=页脚、上级=无」），输出为「快捷入口」列。
     */
    public static function footerExtra(): array
    {
        if (self::$footerExtraMemo !== null) {
            return self::$footerExtraMemo;
        }
        return self::$footerExtraMemo = Cache::remember(SiteCacheKey::footerExtra(), 600, function () {
            $out = [];
            $rows = Menu::active()
                ->where('position', 'footer')
                ->whereNull('parent_id')
                ->where(fn ($q) => $q->whereNull('parent_key')->orWhere('parent_key', ''))
                // 固定页脚列 / 固定页脚项的覆盖行（key 以 ft- 开头）不是独立入口
                ->where(fn ($q) => $q->whereNull('key')->orWhere('key', 'not like', 'ft-%'))
                ->orderBy('sort')
                ->get();
            foreach ($rows as $m) {
                $resolved = static::resolveMenuHref($m->link());
                $out[] = [
                    'name'     => $m->label,
                    'url'      => $resolved['url'],
                    'external' => $resolved['external'] || (int) $m->target === 1,
                ];
            }
            return $out;
        });
    }

    /**
     * 菜单内部路径在「当前站点」是否存在（P-STEP 14 / D.2 多站目录隔离）。
     *
     * 固定导航 / 页脚里的产品 / 场景 / 工厂 / 合作 / 关于 / 联系等目录型链接，其存在性
     * 以当前站点自己的 Catalog（Entity 投影）为准：空目录站不输出指向不存在目录的链接，
     * 部分目录站只输出本站真实存在的产品线 / 场景。首页、知识中心（Content 域）、外链、
     * 电话 / 邮箱、纯锚点不受此门控。
     */
    private static function navPathExists(string $rawHref): bool
    {
        $href = trim($rawHref);
        if ($href === '' || $href === '#' || preg_match('~^(https?:|tel:|mailto:)~i', $href)) {
            return true;
        }

        $path = trim((string) parse_url($href, PHP_URL_PATH), '/');
        if ($path === '') {
            return true; // 首页
        }

        $segments = explode('/', $path);
        $top = $segments[0];
        $catalogTops = ['products', 'solutions', 'factory', 'cooperation', 'about', 'contact'];
        if (! in_array($top, $catalogTops, true)) {
            return true; // 知识中心等非目录域不受门控
        }

        // 目录域：空目录站（无 organization Entity）整体不存在
        if (empty(Catalog::company())) {
            return false;
        }

        $sub = $segments[1] ?? '';
        if ($top === 'products') {
            if ($sub === '') {
                return true; // 产品总览
            }

            return Catalog::line($sub) !== null || Catalog::product($sub) !== null;
        }
        if ($top === 'solutions') {
            if ($sub === '') {
                return true; // 场景总览
            }

            return Catalog::scene($sub) !== null;
        }

        // factory / cooperation / about / contact：站点存在目录即成立
        return true;
    }

    /**
     * 把菜单存储的链接（栏目 URL / 内部路径 / 外链 / 锚点）解析为可输出 href 与外链标记。
     * 返回 url（可直接输出）、external（是否新窗）、path（用于当前态匹配的内部路径）。
     */
    private static function resolveMenuHref(string $href): array
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return ['url' => '#', 'external' => false, 'path' => '/'];
        }
        if (preg_match('~^https?://~i', $href)) {
            $host = (string) parse_url($href, PHP_URL_HOST);
            $internal = $host !== '' && str_contains((string) config('app.url'), $host);
            return ['url' => $href, 'external' => ! $internal, 'path' => (string) parse_url($href, PHP_URL_PATH)];
        }
        if (preg_match('~^(tel:|mailto:)~i', $href)) {
            return ['url' => $href, 'external' => false, 'path' => '/'];
        }
        return ['url' => \App\Support\PublicUrl::url($href), 'external' => false, 'path' => '/' . ltrim($href, '/')];
    }

    public static function forgetNavCache(): void
    {
        self::$navTreeMemo = null;
        self::$mainMenuMemo = null;
        self::$footerExtraMemo = null;
        self::$blueprintMemo = null;
        self::$footerBlueprintMemo = null;
        self::$footerMenuMemo = null;
        // 菜单缓存按语言分隔：后台改动须失效当前站点所有语言副本。
        $locales = array_values(array_unique(array_merge(
            [\App\Support\Localization\LocaleRegistry::default()],
            \App\Support\Localization\LocaleRegistry::supported(),
            (array) \App\Models\Setting::get('site_supported_locales', [])
        )));
        $previous = \App\Support\Localization\LocaleContext::current();
        foreach ($locales as $loc) {
            \App\Support\Localization\LocaleContext::set($loc);
            Cache::forget(SiteCacheKey::navTree());
            Cache::forget(SiteCacheKey::mainMenu());
            Cache::forget(SiteCacheKey::mainMenuBlueprint());
            Cache::forget(SiteCacheKey::footerExtra());
            Cache::forget(SiteCacheKey::footerBlueprint());
            Cache::forget(SiteCacheKey::footerMenu());
        }
        \App\Support\Localization\LocaleContext::set($previous);
    }
}
