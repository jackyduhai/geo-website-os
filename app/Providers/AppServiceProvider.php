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
use App\Models\Setting;
use App\Contracts\UrlResolverInterface;
use App\Services\Seo\GenericUrlResolver;
use App\Support\PageCache;
use App\Support\SiteCacheKey;
use App\Support\ExampleUrlGenerator;
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

            $url = new ExampleUrlGenerator(
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
    }

    public function boot(): void
    {
        // 请求级内存缓存逐请求复位：PHP-FPM worker / artisan serve 进程复用，
        // 模型 static 属性会跨请求存活，必须在每请求启动时清空，保证后台改完设置/事实/导航后，
        // 下一个请求（哪怕落在同一进程）读到的都是最新值，而不是上个请求残留的内存快照。
        Fact::flushMemo();
        Setting::resetRequestMemo();
        Group::flushKnowledgeMemo();
        \App\Support\Narrative::flush();
        \App\Support\Copy::flush();
        self::$navTreeMemo = null;
        self::$mainMenuMemo = null;
        self::$footerExtraMemo = null;
        self::$footerBlueprintMemo = null;
        self::$footerMenuMemo = null;

        // 前台整页静态化缓存的自动失效：任一影响前台展示的内容模型发生
        // 新增/修改/删除（后台保存、GEOFlow 推内容等），版本号 +1，旧页面缓存整体作废。
        // 留言（Inquiry）、操作日志、同步日志、用户不影响前台展示，刻意不纳入。
        foreach ([
            Content::class, ContentRevision::class, Category::class, Group::class,
            Banner::class, Menu::class, PageBlock::class, Setting::class,
            Media::class, RedirectRule::class, Fact::class,
        ] as $model) {
            $model::saved(static fn () => PageCache::flush());
            $model::deleted(static fn () => PageCache::flush());
        }

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
            $view->with('navTree', static::navTree());
            $view->with('mainMenu', static::mainMenu());
            $view->with('footerMenu', static::footerMenu());
            $view->with('footerExtra', static::footerExtra());
            // 全站主 CTA 文案：后台「基础信息 → 主 CTA 按钮文案」可改，留空回退 config 默认
            $view->with('ctaText', trim((string) ($settings['nav_cta_text'] ?? '')) !== ''
                ? $settings['nav_cta_text']
                : (config('copy.nav.cta') ?? '免费获取样品'));
            $view->with('publicFacts', Fact::publicMap());
            // 留言归因（首次落地页 / 外部来源 / UTM），由 CaptureAttribution 写入 session
            if (session()->isStarted()) {
                $view->with('leadAttr', session()->get('attr', []));
            } else {
                $view->with('leadAttr', []);
            }
        });
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

                // 知识中心子项由 groups 驱动（启用几个显示几个）；其余取 config 固定子项
                $rawChildren = [];
                if ($topKey === 'knowledge') {
                    foreach (\App\Models\Group::knowledgeChannels() as $g) {
                        $rawChildren[] = ['label' => $g->name, 'href' => '/knowledge/' . $g->slug . '/', 'dynamic' => true];
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
                    $si = 0;
                    $items[] = $applyItem('ft-contact-hotline', ['type' => 'text', 'label' => '全国合作热线', 'href' => 'tel:+86400-000-0000'], ++$si * 10, true);
                    $items[] = $applyItem('ft-contact-mobile', ['type' => 'text', 'label' => '业务手机'], ++$si * 10, true, true);
                    $items[] = $applyItem('ft-contact-address', ['type' => 'text', 'label' => '厂区地址'], ++$si * 10, true);
                    $items[] = $applyItem('ft-contact-qr', ['type' => 'qr', 'label' => '加微信要样品'], ++$si * 10, true);
                } else {
                    $si = 0;
                    foreach ((array) ($col['items'] ?? []) as $raw) {
                        $ikey = 'ft-' . (static::keyForHref((string) ($raw['href'] ?? '')) ?: ('item-' . $ci . '-' . $si));
                        $items[] = $applyItem($ikey, (array) $raw, ++$si * 10);
                    }
                }

                $columns[] = [
                    'key'           => $colKey,
                    'default_title' => $title,
                    'title'         => ($co && trim((string) $co->label) !== '') ? $co->label : $title,
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
        return ['url' => url('/' . ltrim($href, '/')), 'external' => false, 'path' => '/' . ltrim($href, '/')];
    }

    public static function forgetNavCache(): void
    {
        self::$navTreeMemo = null;
        self::$mainMenuMemo = null;
        self::$footerExtraMemo = null;
        self::$blueprintMemo = null;
        self::$footerBlueprintMemo = null;
        self::$footerMenuMemo = null;
        Cache::forget(SiteCacheKey::navTree());
        Cache::forget(SiteCacheKey::mainMenu());
        Cache::forget(SiteCacheKey::mainMenuBlueprint());
        Cache::forget(SiteCacheKey::footerExtra());
        Cache::forget(SiteCacheKey::footerBlueprint());
        Cache::forget(SiteCacheKey::footerMenu());
    }
}
