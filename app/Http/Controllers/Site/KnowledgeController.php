<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Content;
use App\Models\Group;
use App\Services\Geo\SchemaBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * 知识中心：全部 + 各子栏目。
 *
 * 子栏目不再写死，统一由 groups 表（category=knowledge、is_active、按 sort）数据驱动：
 * 后台「结构管理 → 内容分组」启用/停用/新建/改名后，前台子栏目、导航、sitemap、llms.txt
 * 同步变化，无需改代码。文章 URL 保持扁平 /knowledge/{slug}；数字分页，每页 12 篇。
 */
class KnowledgeController extends Controller
{
    private const PER_PAGE = 12;

    public function index(SchemaBuilder $schema, Request $request)
    {
        $cat = Category::where('slug', 'knowledge')->where('is_active', true)->first();
        $query = Content::published()->with(['category', 'group', 'cover']);
        if ($cat) {
            $query->where('category_id', $cat->id);
        }
        $items = $query->orderByDesc('published_at')->paginate(self::PER_PAGE);

        return $this->render($schema, $items, null, '知识中心：选料、工艺配方与开店经营指南', $request);
    }

    public function channel(string $channel, SchemaBuilder $schema, Request $request, PageController $page)
    {
        $group = Group::knowledgeChannels()->firstWhere('slug', $channel);
        // 不是启用栏目时，可能是扁平的知识文章 /knowledge/{slug}：转交统一分发器，
        // 由其按内容/栏目判定，仍找不到才 404（路由正则已放宽，不能在此直接 404）。
        if (! $group) {
            return $page->dispatch($request, 'knowledge/' . $channel, $schema);
        }

        $items = Content::published()->with(['category', 'group', 'cover'])
            ->where('group_id', $group->id)
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE);

        return $this->render($schema, $items, $channel, $group->name, $request);
    }

    private function render(SchemaBuilder $schema, $items, ?string $active, string $title, Request $request)
    {
        $channels = Group::knowledgeChannels();
        $channelMap = $channels->pluck('name', 'slug')->all(); // slug => 名称，供视图 H1 取值

        $crumbs = [
            ['name' => '首页', 'url' => url('/')],
            ['name' => '知识中心', 'url' => url('/knowledge/')],
        ];
        if ($active !== null && isset($channelMap[$active])) {
            $crumbs[] = ['name' => $channelMap[$active], 'url' => url('/knowledge/' . $active . '/')];
        }

        // 二级 Tab 与顶部导航下拉同源：内容分组动态项 + 后台挂接到「知识中心」的自定义二级项
        $cur = trim($request->path(), '/');
        $subItems = [['name' => '全部', 'slug' => null, 'url' => url('/knowledge/'), 'on' => $active === null]];
        foreach (\App\Providers\AppServiceProvider::mergedMenuChildren('knowledge') as $ch) {
            $path = trim((string) parse_url($ch['url'], PHP_URL_PATH), '/');
            $slug = preg_match('#^knowledge/([^/]+)$#', $path, $m) ? $m[1] : null;
            $external = (bool) ($ch['external'] ?? false);
            $customOn = ! $external && $path !== '' && ($cur === $path || str_starts_with($cur, $path . '/'));
            $subItems[] = [
                'name'     => $ch['name'],
                'slug'     => $slug,
                'url'      => $ch['url'],
                'external' => $external,
                'on'       => $slug !== null ? $slug === $active : $customOn,
            ];
        }

        $canonical = $active
            ? url('/knowledge/' . $active . '/')
            : url('/knowledge/');

        return view('site.knowledge.index', [
            'items'      => $items,
            'channels'   => $channelMap,
            'active'     => $active,
            'crumbs'     => array_slice($crumbs, 1),
            'subnav'     => ['items' => $subItems, 'active' => $active],
            'schemas'    => array_values(array_filter([
                $schema->organization(),
                $schema->breadcrumb($crumbs),
            ])),
            'seo' => [
                'title'       => $title,
                'description' => 'Example知识中心：中式Sample SnackSample Marinade选料指南、腌制工艺与配方、开店与经营经验，帮助餐饮创业者与连锁品牌把出品做稳定。',
                'canonical'   => $canonical,
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
