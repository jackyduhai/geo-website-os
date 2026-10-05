<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\Menu;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * 新站出厂初始化 —— 栏目 / 分组 / 导航可见性 / 语言开关（20G-7.1 · UX-002）
 * ------------------------------------------------------------------
 * SiteController@store 已调用 DefaultSettingSeeder / DefaultFormSeeder /
 * BlankHomepageSeeder / SystemPageSeeder，缺的是**栏目结构与导航可见性**。
 * 实测新站状态下的三个具体后果：
 *
 *   1. 后台「新建内容」的栏目下拉为空
 *      （`ContentController@formData` 取 `Category::with('children')->whereNull('parent_id')`）
 *      → 运营无法给内容归类，零代码建站契约断裂。
 *   2. 内容列表页无入口，栏目页 /{slug} 全部 404。
 *   3. 前台主导航渲染出config 里的**工业制造口径**项
 *      （`factory` = 工厂与资质、`solutions` = 应用场景），
 *      对食品 / 服务类站点是错误信息。
 *
 * ── 三类数据必须分开处理（20G-7.1 纪律）────────────────────────
 *
 *   站点级事实 → Settings（语言、站点名…）由 DefaultSettingSeeder 负责
 *   结构骨架   → 本 Seeder：categories / groups（行业中立，可跨站相同 slug）
 *   可见性决策 → menus 表，且必须走 **override 语义**（见下）
 *
 * ── menus 的两种语义（踩过坑，务必分清）─────────────────────
 * `AppServiceProvider::mainMenuBlueprint()` 的判据是
 * **`whereNotNull('key')` = 覆盖项，`whereNull('key')` = 自定义新增项**。
 *
 * 主导航一级项来自 `config('copy.nav.menu')`（key 取 href 首段：
 * products / solutions / factory / knowledge / about），**不是** menus 表。
 * 所以给这些 key 建行 = 覆盖内置项（改 label / url / 启停），而非新增导航。
 *
 * 因此本 Seeder 只做两件克制的事：
 *   · 明确**关掉**明显不适用的一级项（factory / solutions），
 *     避免食品站出现「工厂与资质」；
 *   · 保留 products / knowledge / about —— 它们对应本 Seeder 建的栏目，
 *     前台蓝图依赖同名栏目 slug 提供落地页。
 *
 * 刻意**不**做的：不覆盖 label（让内置文案继续走 `lang/` 翻译）、
 * 不新建 key 非空的自定义项（那是运营在后台的活）。
 *
 * ── 站点隔离 ────────────────────────────────────────────────
 * categories / groups / menus 唯一约束均含 site_id：
 *   UNIQUE(site_id, slug) / UNIQUE(site_id, category_id, slug) / UNIQUE(site_id, key)
 * 多站可安全使用相同 slug。依赖调用方已 `SiteContext::withSite($site, ...)`。
 *
 * ── 幂等（BS-008）──────────────────────────────────────────
 * 全部走 **`firstOrCreate`**（不是 `updateOrCreate`），匹配条件依赖
 * SiteScope 自动限定站点。
 *
 * ⚠️ 这里必须用 firstOrCreate：`updateOrCreate` 的实现是
 * `firstOrCreate(...)` 后接 `if (! wasRecentlyCreated) fill($values)->save()`
 * —— **已存在的行会被 values 覆盖**。那样重跑 bootstrap 会把运营
 * 改过的栏目名 / 导航文案重置回出厂值（20G-7.1 实测 BS-008 抓到）。
 *
 * `firstOrCreate` 只在匹配不到时用 values 新建，已存在则**原样不动** ——
 * 初始化是「补齐缺项」，不是「重置为出厂」。
 */
class SiteStructureSeeder extends Seeder
{
    /**
     * 行业中立栏目结构。
     *
     * 刻意与 StructureSeeder（演示数据，含涂料 / 选型 / 工艺等工业文案）区分 ——
     * 演示数据只进 Demo 站，出厂新站必须是任何行业都能用的骨架。
     */
    private const TREE = [
        [
            'name' => '关于我们', 'slug' => 'about', 'type' => 'list',
            'description' => '企业简介、发展历程与资质信息。', 'sort' => 10,
        ],
        [
            'name' => '产品中心', 'slug' => 'products', 'type' => 'list',
            'description' => '产品目录与规格说明。', 'sort' => 20,
        ],
        [
            'name' => '知识中心', 'slug' => 'knowledge', 'type' => 'list',
            'description' => '选型指南、技术资料与应用案例。', 'sort' => 30,
            'groups' => [
                ['name' => '常见问题', 'slug' => 'faq', 'sort' => 10],
                ['name' => '使用指南', 'slug' => 'guide', 'sort' => 20],
            ],
        ],
        [
            'name' => '新闻动态', 'slug' => 'news', 'type' => 'list',
            'description' => '企业新闻与公告。', 'sort' => 40,
            'groups' => [
                ['name' => '企业新闻', 'slug' => 'company-news', 'sort' => 10],
                ['name' => '通知公告', 'slug' => 'notice', 'sort' => 20],
            ],
        ],
        [
            'name' => '联系我们', 'slug' => 'contact', 'type' => 'list',
            'description' => '联系方式与留言入口。', 'sort' => 50,
        ],
    ];

    /**
     * 出厂默认关闭的一级导航项。
     *
     * 内置 config 是工业制造口径（`factory` = 工厂与资质、`solutions` = 应用场景）。
     * 多数站点没有工厂，应用场景也依赖实体数据支撑。默认关闭，
     * 运营在后台「菜单」里可随时启用 —— 比出厂就展示错误信息更安全。
     */
    private const DISABLED_NAV = [
        ['key' => 'factory', 'position' => 'main'],
        ['key' => 'solutions', 'position' => 'main'],
    ];

    public function run(): void
    {
        $this->seedCategories();
        $this->disableIndustrialNav();
        $this->enableBilingual();
    }

    private function seedCategories(): void
    {
        foreach (self::TREE as $node) {
            /**
             * 匹配条件只给 slug：`Category` 的 SiteScope 会自动补 site_id，
             * 所以「相同 slug、不同站」不会互相命中（UNIQUE 亦含 site_id）。
             * 显式再写 site_id 会与 SiteScope 叠加成重复条件。
             */
            $parent = Category::firstOrCreate(
                ['slug' => $node['slug']],
                [
                    'parent_id'   => null,
                    'name'        => $node['name'],
                    'type'        => $node['type'],
                    'description' => $node['description'],
                    'sort'        => $node['sort'],
                    'is_nav'      => true,
                    'is_active'   => true,
                ]
            );

            foreach ($node['groups'] ?? [] as $g) {
                Group::firstOrCreate(
                    ['category_id' => $parent->id, 'slug' => $g['slug']],
                    [
                        'name'      => $g['name'],
                        'sort'      => $g['sort'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /**
     * 关闭工业制造口径的一级导航。
     *
     * 走 `Menu::create` + `is_active=false`：这是 menus 表的 **override 语义**
     * （`mainMenuBlueprint` 读 `whereNotNull('key')->where('position','main')`，
     * 且 `! $o->is_active` 会把该节点从导航里去掉）。
     * 不改label / url —— 让内置项继续走 `lang/` 翻译，运营启用后即恢复原名。
     */
    private function disableIndustrialNav(): void
    {
        foreach (self::DISABLED_NAV as $item) {
            $exists = Menu::query()
                ->where('key', $item['key'])
                ->where('position', $item['position'])
                ->exists();
            if ($exists) {
                continue; // 运营已显式改过 → 尊重人工决策，不动
            }

            Menu::create([
                'position'  => $item['position'],
                'parent_id' => null,
                'key'       => $item['key'],
                'label'     => '',        // 留空：仅作可见性开关，不提供文案
                'url'       => '/',
                'target'    => '_self',
                'sort'      => 0,
                'is_active' => false,
            ]);
        }
    }

    /**
     * 启用 en，让新站出厂即 Multi-language。
     *
     * 现状：`DefaultSettingSeeder` 设 `site_supported_locales = ['zh-CN']`，
     * 于是 `SetLocale` 对 `/en/*` 严格 404（刻意的安全行为）。
     * 但产品已确定 v1.0 = Multi-site × Multi-language，
     * 新站保持单语言会让运营认为「英文功能坏了」。
     *
     * 只改语言开关，**不创建任何英文内容** ——
     * 英文内容由运营逐条补录（facts 行级翻译模型 20G-3 已就位）。
     * 空英文站是合法状态：SetLocale 放行 /en，GEO 输出空集合而非中文。
     */
    private function enableBilingual(): void
    {
        $raw = Setting::get('site_supported_locales', ['zh-CN']);
        $locales = is_array($raw) ? $raw : explode(',', (string) $raw);

        $locales = array_values(array_unique(array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : '', $locales),
            fn ($v) => $v !== ''
        )));

        if (! in_array('en', $locales, true)) {
            $locales[] = 'en';
        }

        Setting::set('site_supported_locales', $locales);
    }
}
