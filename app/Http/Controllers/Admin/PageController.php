<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageBlock;
use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\BlockType;
use App\Support\Localization\LocaleRegistry;
use App\Support\PageCache;
use App\Support\Render\CompositionRenderer;
use App\Support\Render\PageRenderContext;
use App\Support\SiteContext;
use App\Support\Templates\TemplateRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 组合页面管理（Page Composition Manager）。
 * --------------------------------------------------
 * 把"仅首页可装修"升级为"任意页面可组合"：
 *   - Page CRUD / 发布 / 下架（Page 是页面实例，不存业务事实）；
 *   - 在模板槽位内添加 / 编辑 / 删除 / 排序 / 显隐 Block；
 *   - Block 内容一律为结构化 JSON（按 BlockType fields 编辑），由注册渲染器生成 HTML。
 *
 * 不做 Figma 式自由拖拽；模板结构由 TemplateRegistry 裁决，槽位不允许的 Block 被拒绝。
 */
class PageController extends Controller
{
    // ---------------------------------------------------------------
    // Page CRUD
    // ---------------------------------------------------------------

    public function index(): View
    {
        $groups = Page::orderBy('id')->get()->groupBy('translation_group');

        return view('admin.pages.index', compact('groups'));
    }

    public function create(): View
    {
        $templates = TemplateRegistry::selectable();
        $locales = LocaleRegistry::supported();
        $existing = Page::where('locale', LocaleRegistry::default())->get();

        return view('admin.pages.form', compact('templates', 'locales', 'existing'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'template'       => ['required', 'string', 'max:40'],
            'locale'         => ['required', 'string', 'max:16'],
            'title'          => ['nullable', 'string', 'max:160'],
            'slug'           => [
                'nullable', 'string', 'max:120',
                Rule::unique('pages', 'slug')->where(function ($q) use ($request) {
                    $q->where('site_id', SiteContext::currentSiteId())
                        ->where('locale', (string) $request->input('locale'));
                }),
            ],
            'status'         => ['nullable', 'in:draft,published'],
            'translation_of' => ['nullable', 'integer', 'exists:pages,id'],
        ]);

        $page = Page::create([
            'template' => $validated['template'],
            'locale'   => $validated['locale'],
            'title'    => $validated['title'] ?? '',
            'slug'     => ($validated['slug'] ?? '') ?: null,
            'status'   => $validated['status'] ?? Page::STATUS_DRAFT,
        ]);

        if (! empty($validated['translation_of'])) {
            $parent = Page::find($validated['translation_of']);
            if ($parent) {
                $page->translation_group = $parent->translation_group;
                $page->save();
            }
        }

        return redirect()->route('admin.pages.composer', $page)
            ->with('success', '页面已创建，请组合内容区块');
    }

    public function edit(Page $page): View
    {
        $templates = TemplateRegistry::selectable();
        $locales = LocaleRegistry::supported();
        $existing = Page::where('locale', LocaleRegistry::default())
            ->where('id', '!=', $page->id)->get();

        return view('admin.pages.form', compact('page', 'templates', 'locales', 'existing'));
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:40'],
            'title'    => ['nullable', 'string', 'max:160'],
            'slug'     => [
                'nullable', 'string', 'max:120',
                Rule::unique('pages', 'slug')
                    ->ignore($page->id)
                    ->where(fn ($q) => $q
                        ->where('site_id', $page->site_id)
                        ->where('locale', $page->locale)),
            ],
            'status'   => ['nullable', 'in:draft,published'],
        ]);

        $page->fill([
            'template' => $validated['template'],
            'title'    => $validated['title'] ?? '',
            'slug'     => ($validated['slug'] ?? '') ?: null,
            'status'   => $validated['status'] ?? $page->status,
        ])->save();

        PageCache::forgetPage($page);

        return back()->with('success', '页面设置已保存');
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->delete();

        return redirect()->route('admin.pages.index')->with('success', '页面已删除');
    }

    public function publish(Page $page, string $action): RedirectResponse
    {
        $page->status = $action === 'publish' ? Page::STATUS_PUBLISHED : Page::STATUS_DRAFT;
        $page->save();
        PageCache::forgetPage($page);

        return back()->with('success', $action === 'publish' ? '页面已发布' : '页面已下架');
    }

    // ---------------------------------------------------------------
    // Composer（Block 组合）
    // ---------------------------------------------------------------

    public function composer(Page $page): View
    {
        $page->load('blocks');
        $template = TemplateRegistry::get($page->template);
        $blockTypes = BlockRegistry::all();

        return view('admin.pages.composer', compact('page', 'template', 'blockTypes'));
    }

    public function addBlock(Page $page): View
    {
        $template = TemplateRegistry::get($page->template);
        $blockTypes = BlockRegistry::all();

        return view('admin.pages.add_block', compact('page', 'template', 'blockTypes'));
    }

    public function storeBlock(Page $page, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'slot' => ['required', 'string', 'max:40'],
        ]);

        $type = BlockRegistry::get($validated['type']);
        $template = TemplateRegistry::get($page->template);
        if (! $type || ! $template) {
            abort(404);
        }
        if ($type->system) {
            return back()->withErrors(['type' => '系统区块由当前 Entity 直驱，不可手动添加']);
        }
        if (! $template->allows($validated['slot'], $validated['type'])
            || ! $type->allows($page->template, $validated['slot'])) {
            return back()->withErrors(['type' => '该模板槽位不允许此区块']);
        }

        $maxSort = (int) PageBlock::where('page_id', $page->id)
            ->where('slot', $validated['slot'])->max('sort');

        $block = new PageBlock();
        $block->site_id = $page->site_id;
        $block->page = 'page';
        $block->page_id = $page->id;
        $block->slot = $validated['slot'];
        $block->type = $validated['type'];
        $block->sort = $maxSort + 1;
        $block->is_active = true;
        $block->content = json_encode($type->defaultContent, JSON_UNESCAPED_UNICODE);
        $block->save();

        return redirect()->route('admin.pages.editBlock', [$page, $block]);
    }

    public function editBlock(Page $page, PageBlock $block): View
    {
        $this->assertBelongs($page, $block);
        $type = BlockRegistry::get($block->type);

        return view('admin.pages.block_form', compact('page', 'block', 'type'));
    }

    public function updateBlock(Page $page, PageBlock $block, Request $request): RedirectResponse
    {
        $this->assertBelongs($page, $block);
        $type = BlockRegistry::get($block->type);
        if (! $type) {
            abort(404);
        }

        $block->content = json_encode(
            $this->extractConfig($request, $type),
            JSON_UNESCAPED_UNICODE
        );
        $block->save();
        PageCache::forgetPage($page);

        return redirect()->route('admin.pages.composer', $page)->with('success', '区块已保存');
    }

    public function destroyBlock(Page $page, PageBlock $block): RedirectResponse
    {
        $this->assertBelongs($page, $block);
        $block->delete();
        PageCache::forgetPage($page);

        return back()->with('success', '区块已删除');
    }

    public function moveBlock(Page $page, PageBlock $block, string $dir): RedirectResponse
    {
        $this->assertBelongs($page, $block);

        $siblings = PageBlock::where('page_id', $page->id)
            ->where('slot', $block->slot)->orderBy('sort')->get();
        $idx = $siblings->search(fn ($b) => $b->id === $block->id);
        $target = $dir === 'up' ? $idx - 1 : $idx + 1;

        if (isset($siblings[$target])) {
            $a = $siblings[$idx];
            $b = $siblings[$target];
            $tmp = $a->sort;
            $a->sort = $b->sort;
            $b->sort = $tmp;
            $a->save();
            $b->save();
        }

        PageCache::forgetPage($page);

        return back();
    }

    public function toggleBlock(Page $page, PageBlock $block): RedirectResponse
    {
        $this->assertBelongs($page, $block);
        $block->is_active = ! $block->is_active;
        $block->save();
        PageCache::forgetPage($page);

        return back();
    }

    /**
     * 复制区块（TD-58）：在同一槽位末尾生成一份内容相同的副本，便于快速复用。
     * 不复制 id；标题标注「（副本）」；显隐状态沿用原区块。
     */
    public function duplicateBlock(Page $page, PageBlock $block): RedirectResponse
    {
        $this->assertBelongs($page, $block);

        $maxSort = (int) PageBlock::where('page_id', $page->id)
            ->where('slot', $block->slot)->max('sort');

        $copy = $block->replicate();
        $copy->site_id = $page->site_id;
        $copy->page_id = $page->id;
        $copy->slot = $block->slot;
        $copy->sort = $maxSort + 1;
        $copy->is_active = $block->is_active;
        if ($block->title) {
            $copy->title = $block->title.'（副本）';
        }
        $copy->save();

        PageCache::forgetPage($page);

        return redirect()->route('admin.pages.editBlock', [$page, $copy])
            ->with('success', '区块已复制，可继续编辑副本');
    }

    /**
     * 页面预览（TD-58）：在登录态下直接走前台 Composition 管线渲染该页面，
     * 草稿 / 未发布也可查看（不经过 published 门禁）；不产生公开 URL、不写缓存。
     */
    public function preview(Page $page)
    {
        return app(CompositionRenderer::class)->render(new PageRenderContext($page));
    }

    // ---------------------------------------------------------------
    // 内部
    // ---------------------------------------------------------------

    protected function assertBelongs(Page $page, PageBlock $block): void
    {
        if ($block->page_id !== $page->id) {
            abort(404);
        }
    }

    /** 按 BlockType fields 从请求提取结构化配置（合并默认值）。 */
    protected function extractConfig(Request $request, BlockType $type): array
    {
        $cfg = $type->defaultContent;

        foreach ($type->fields as $f) {
            $key = $f['key'];
            $raw = $request->input("field.$key");

            $cfg[$key] = match ($f['type']) {
                'checkbox' => $request->boolean("field.$key"),
                'number'   => ($raw === null || $raw === '')
                    ? null
                    : (str_contains((string) $raw, '.') ? (float) $raw : (int) $raw),
                'media'    => ($raw === null || $raw === '') ? null : (int) $raw,
                'text', 'select', 'textarea', 'markdown' => (string) ($raw ?? ''),
                'items'    => $this->extractItems((array) ($raw ?? []), $f['item_fields'] ?? []),
                'buttons'  => $this->extractButtons((array) ($raw ?? [])),
                'source'   => $this->extractSource((array) ($raw ?? [])),
                default    => $raw,
            };
        }

        return $cfg;
    }

    protected function extractItems(array $rows, array $itemFields): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $entry = [];
            foreach ($itemFields as $f) {
                $k = $f['key'];
                $entry[$k] = match ($f['type']) {
                    'checkbox' => ! empty($row[$k]),
                    'media'    => (isset($row[$k]) && $row[$k] !== '') ? (int) $row[$k] : null,
                    'number'   => (isset($row[$k]) && $row[$k] !== '') ? (int) $row[$k] : null,
                    default    => trim((string) ($row[$k] ?? '')),
                };
            }
            // 空行：首个字段为空则跳过
            $first = $itemFields[0]['key'] ?? null;
            if ($first && trim((string) ($entry[$first] ?? '')) === '') {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    protected function extractButtons(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $style = $row['style'] ?? 'primary';
            $out[] = [
                'label' => $label,
                'url'   => trim((string) ($row['url'] ?? '')),
                'style' => in_array($style, ['primary', 'secondary', 'ghost'], true) ? $style : 'primary',
            ];
        }

        return $out;
    }

    protected function extractSource(array $row): array
    {
        $mode = $row['mode'] ?? 'all';
        $out = ['mode' => $mode];
        if ($mode === 'line') {
            $out['line'] = trim((string) ($row['line'] ?? ''));
        }
        if ($mode === 'picked') {
            $out['ids'] = array_values(array_filter(
                array_map('intval', explode(',', (string) ($row['ids'] ?? '')))
            ));
        }

        return $out;
    }
}
