<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Content;
use App\Services\Geo\SchemaBuilder;
use App\Services\Seo\SeoMetaResolver;
use Illuminate\Http\Request;

/**
 * 统一页面分发器
 *
 * 为什么需要一个分发器：本站 URL 形如 /products/epoxy-primer-100，
 * 它既可能是「栏目」，也可能是「某栏目下 slug 为 epoxy-primer-100 的内容」，
 * 静态路由无法区分。分发器按固定顺序判定，避免路径歧义导致 404 或错页：
 *
 *   1) 先按内容匹配：取最后一段作为 slug，前面各段作为栏目路径
 *   2) 再按栏目匹配：整段路径逐级解析栏目树
 *   3) 都不匹配 → 404
 */
class PageController extends Controller
{
    public function dispatch(Request $request, string $path, SchemaBuilder $schema, SeoMetaResolver $seoResolver)
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($segments)) {
            abort(404);
        }

        // 1) 内容优先
        $slug    = (string) end($segments);
        $catSegs = array_slice($segments, 0, -1);
        if ($content = $this->matchContent($slug, $catSegs)) {
            return $this->renderContent($content, $schema, $seoResolver);
        }

        // 2) 栏目
        if ($category = $this->matchCategory($segments)) {
            return $this->renderCategory($request, $category, $schema, $seoResolver);
        }

        abort(404);
    }

    // ---------------------------------------------------------------
    // 匹配
    // ---------------------------------------------------------------

    protected function matchContent(string $slug, array $catSegs): ?Content
    {
        $content = Content::published()->with('category')->where('slug', $slug)->first();
        if (! $content) {
            return null;
        }

        // URL 中的栏目路径必须与内容实际归属一致，否则 301 到规范地址
        $actual = $this->categoryPathSegments($content->category);
        if ($catSegs !== $actual) {
            return $content;  // 交由 renderContent 做 301
        }

        return $content;
    }

    protected function matchCategory(array $segments): ?Category
    {
        $parent = null;
        $category = null;

        foreach ($segments as $slug) {
            $q = Category::where('slug', $slug)->where('is_active', true);
            $q = $parent ? $q->where('parent_id', $parent->id) : $q->whereNull('parent_id');
            $category = $q->first();
            if (! $category) {
                return null;
            }
            $parent = $category;
        }

        return $category;
    }

    protected function categoryPathSegments(?Category $category): array
    {
        $segs = [];
        $node = $category;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($segs, $node->slug);
            $node = $node->parent;
        }
        return $segs;
    }

    // ---------------------------------------------------------------
    // 渲染
    // ---------------------------------------------------------------

    protected function renderContent(Content $content, SchemaBuilder $schema, SeoMetaResolver $seoResolver)
    {
        // 路径不匹配则跳到规范地址，避免同一内容多入口
        $requested = '/' . implode('/', array_slice(
            array_filter(explode('/', trim(request()->path(), '/'))),
            0, -1
        ));
        $canonicalPath = '/' . implode('/', $this->categoryPathSegments($content->category));
        if (rtrim($requested, '/') !== rtrim($canonicalPath, '/')) {
            return redirect()->to($content->url(), 301);
        }

        $crumbs = $this->categoryPathSegments($content->category);
        $crumbList = [];
        $node = $content->category;
        $chain = [];
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }
        foreach ($chain as $c) {
            $crumbList[] = ['name' => $c->name, 'url' => $c->url()];
        }
        $crumbList[] = ['name' => $content->title, 'url' => $content->url()];

        $related = Content::published()
            ->where('category_id', $content->category_id)
            ->where('id', '!=', $content->id)
            ->orderByDesc('published_at')
            ->limit(4)
            ->get();

        // SEO: 使用 SeoMetaResolver
        $seoResult = $seoResolver->resolveContent($content);

        return view('site.content', [
            'content' => $content,
            'crumbs'  => $crumbList,
            'related' => $related,
            'schemas' => [
                $schema->organization(),
                $schema->article($content, $seoResult),
                $schema->faqPage($content),
                $schema->breadcrumb($crumbList),
            ],
            'seo' => [
                'title'       => $seoResult->title,
                'description' => $seoResult->description,
                'canonical'   => $seoResult->canonical,
                'noindex'     => $seoResult->noindex,
                'type'        => 'article',
                'published'   => $content->published_at?->toIso8601String(),
                'modified'    => $content->updated_at?->toIso8601String(),
                'image'       => $seoResult->ogImage,
            ],
        ]);
    }

    protected function renderCategory(Request $request, Category $category, SchemaBuilder $schema, SeoMetaResolver $seoResolver)
    {
        $crumbs = [];
        $chain = [];
        $node = $category;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }
        foreach ($chain as $c) {
            $crumbs[] = ['name' => $c->name, 'url' => $c->url()];
        }

        // 单页型：渲染其下第一条内容
        if ($category->type === 'single') {
            $page = Content::published()
                ->where('category_id', $category->id)
                ->orderByDesc('published_at')
                ->first();
            if ($page) {
                return $this->renderContent($page, $schema, $seoResolver);
            }
        }

        $children = $category->children()->where('is_active', true)->orderBy('sort')->get();
        $groups   = $category->groups()->where('is_active', true)->orderBy('sort')->get();

        $query = Content::published()->where('category_id', $category->id);
        if ($groupId = $request->integer('group')) {
            $query->where('group_id', $groupId);
        }
        $items = $query->orderByDesc('published_at')->paginate(12)->withQueryString();

        return view('site.category', [
            'category' => $category,
            'children' => $children,
            'groups'   => $groups,
            'items'    => $items,
            'crumbs'   => $crumbs,
            'schemas'  => [
                $schema->organization(),
                $schema->collectionPage($category, $category->url()),
                $schema->breadcrumb($crumbs),
            ],
            'seo' => [
                'title'       => $category->seo_title ?: $category->name,
                'description' => $category->seo_desc ?: $category->description,
                'canonical'   => $category->url(),
                'noindex'     => false,
                'type'        => 'website',
            ],
        ]);
    }
}
