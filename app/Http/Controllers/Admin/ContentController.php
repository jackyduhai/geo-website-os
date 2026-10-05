<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Entity;
use App\Models\Fact;
use App\Models\Group;
use App\Models\Media;
use App\Models\Setting;
use App\Services\Gate\ContentGate;
use App\Support\Localization\LocaleRegistry;
use App\Support\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 内容管理（文章 / 单页）
 *
 * 关键纪律：草稿可以不完整；一旦点「发布」必须通过 ContentGate。
 * 手动发布与 GEOFlow 推送共用同一套门禁，没有第二条宽松路径。
 *
 * P-STEP 18F：同一内容的多语言版本 = 同表多行 + translation_group；
 * 编辑页通过 ?trans=<locale> 在各语言行间切换，翻译状态在顶部 Tabs 可见。
 */
class ContentController extends Controller
{
    public function index(Request $request, ?string $tab = 'article'): View
    {
        $query = Content::with('category', 'group')->latest();

        if ($tab && $tab !== 'all') {
            $query->where('type', $tab);
        }
        if ($search = trim((string) $request->get('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        return view('admin.contents.index', [
            'tab'      => $tab ?? 'all',
            'items'    => $query->paginate(20)->withQueryString(),
            'q'        => $request->get('q'),
            'fStatus'  => $status,
            'typeName' => ['article' => '文章', 'page' => '单页', 'all' => '全部'][$tab ?? 'all'] ?? '内容',
        ]);
    }

    public function create(string $type = 'article'): View
    {
        $content = new Content([
            'type'    => $type,
            'status'  => 'draft',
            'site_id' => SiteContext::currentSiteId(),
            'locale'  => LocaleRegistry::default(),
        ]);

        return $this->form($content, $content, LocaleRegistry::default());
    }

    public function store(Request $request): RedirectResponse
    {
        $transLocale = $this->resolveTransLocale($request);
        $data = $this->validateForm($request);
        $data = $this->hydrate($data, $request);

        // 路径一：基于默认语言 anchor 创建翻译行
        if ($transLocale !== LocaleRegistry::default() && $request->filled('translation_group')) {
            $anchor = Content::withoutSiteScope()
                ->where('translation_group', $request->input('translation_group'))
                ->where('locale', LocaleRegistry::default())
                ->firstOrFail();

            unset($data['locale'], $data['translation_group'], $data['site_id']);
            $content = $anchor->createTranslation($transLocale, $data);

            $this->snapshot($content, '创建翻译 ' . $transLocale);
            AuditLog::record('content.translation.created', '创建翻译：' . $content->title, [], 'content', $content->id);

            return redirect()->route('admin.contents.edit', [
                'content' => $anchor,
                'trans'   => $transLocale,
            ])->with('success', '翻译草稿已保存。');
        }

        // 路径二：全新默认语言内容
        $content = new Content();
        $content->fill($data);
        $content->site_id = SiteContext::currentSiteId();
        $content->locale = LocaleRegistry::default();
        $content->status = 'draft';
        $content->content_hash = $content->computeHash();
        $content->save();

        $this->snapshot($content, '创建草稿');
        AuditLog::record('content.created', '创建内容：' . $content->title, [], 'content', $content->id);

        return redirect()->route('admin.contents.edit', $content)
            ->with('success', '草稿已保存。完善四层结构后可提交发布。');
    }

    public function edit(Request $request, Content $content): View
    {
        // 以默认语言行作为锚点
        $anchor = $content->locale === LocaleRegistry::default()
            ? $content
            : ($content->translation(LocaleRegistry::default()) ?? $content);

        $transLocale = $this->resolveTransLocale($request);

        if ($transLocale === LocaleRegistry::default()) {
            $editing = $anchor;
        } else {
            $editing = $anchor->translation($transLocale)
                ?: $this->blankTranslation($anchor, $transLocale);
        }

        return $this->form($editing, $anchor, $transLocale);
    }

    public function update(Request $request, Content $content): RedirectResponse
    {
        $transLocale = $this->resolveTransLocale($request);
        $data = $this->validateForm($request, $content);
        $data = $this->hydrate($data, $request);

        unset($data['locale'], $data['translation_group']);

        $content->fill($data);

        // 已发布内容不能把发布时间改到未来：否则后台显示已发布、前台 scopePublished
        // 过滤导致 404（BUG-20B-RD-002，BUG-004 的 update 残留）。完整定时发布为 v1.1。
        if ($content->status === 'published'
            && $content->published_at
            && $content->published_at->isFuture()) {
            return back()->with('error', '已发布内容不能把发布时间改到未来（定时发布将在 v1.1 完整支持）。请把发布时间改为当前或过去时间，或先下架为草稿。')->withInput();
        }

        $content->content_hash = $content->computeHash();
        $content->save();
        $this->syncRelations($content, $request);

        $this->snapshot($content, '编辑保存 ' . $transLocale);
        AuditLog::record('content.updated', '编辑内容：' . $content->title, [], 'content', $content->id);

        $anchor = $content->locale === LocaleRegistry::default()
            ? $content
            : ($content->translation(LocaleRegistry::default()) ?? $content);

        return redirect()->route('admin.contents.edit', [
            'content' => $anchor,
            'trans'   => $transLocale,
        ])->with('success', '内容已保存');
    }

    public function destroy(Content $content): RedirectResponse
    {
        $title = $content->title;
        $content->delete();
        AuditLog::record('content.deleted', '删除内容：' . $title, [], 'content', $content->id);

        return redirect()->route('admin.contents.index', 'all')->with('success', '已删除（可在回收站恢复，如需彻底删除请联系技术）');
    }

    /**
     * 发布：门禁不通过则拒绝，内容保持原状
     */
    public function publish(Request $request, Content $content, ContentGate $gate): RedirectResponse
    {
        // 发布只针对默认语言行；翻译行的发布状态由共享列同步。
        $result = $gate->check($content);

        if (! $result['passed']) {
            return back()->with('error', '未通过 GEO 门禁，已拒绝发布：')->with('gateErrors', $result['errors'])
                ->with('gateWarnings', $result['warnings']);
        }

        // 完整定时发布为 v1.1 能力。v1.0 若 published_at 为未来时间，拒绝并提示，
        // 避免「后台显示已发布、前台 404」的状态不一致（BUG-20B-004）。
        if ($content->published_at && $content->published_at->isFuture()) {
            return back()->with('error', '定时发布将在 v1.1 完整支持；请把发布时间改为当前时间或留空以立即发布。');
        }
        $content->published_at = $content->published_at ?: now();
        $content->status = 'published';
        $content->content_hash = $content->computeHash();
        $content->save();

        $this->snapshot($content, '发布');
        AuditLog::record('content.published', '发布内容：' . $content->title, ['warnings' => $result['warnings']], 'content', $content->id);

        $msg = '内容已发布';
        if ($result['warnings']) {
            $msg .= '，有 ' . count($result['warnings']) . ' 条警告（不影响发布）';
        }

        return redirect()->route('admin.contents.edit', $content)->with('success', $msg);
    }

    public function unpublish(Content $content): RedirectResponse
    {
        $content->status = 'draft';
        $content->save();
        AuditLog::record('content.unpublished', '下架内容：' . $content->title, [], 'content', $content->id);

        return back()->with('success', '已下架，转为草稿');
    }

    /**
     * 门禁预检：不落库，返回当前表单内容的校验结果
     */
    public function check(Request $request, ContentGate $gate): JsonResponse
    {
        $data = $this->hydrate($request->all(), $request);
        $content = new Content($data);
        $result = $gate->check($content);

        return response()->json($result);
    }

    /**
     * 正文 Markdown 预览：用与前台完全相同的渲染（{@see Content::renderMarkdown}），
     * 保证「所见即前台所得」，支持 GFM 表格。仅管理员可用，不落库。
     *
     * 收敛到唯一渲染出口（C-1）：预览若用裸 Str::markdown，后台看着安全的 HTML
     * 到前台会被转义，或反之预览通过而前台注入——两份渲染策略必然分叉。
     */
    public function mdPreview(Request $request): JsonResponse
    {
        $text = (string) $request->input('text', '');

        return response()->json(['html' => Content::renderMarkdown($text)]);
    }

    public function revisions(Content $content): View
    {
        return view('admin.contents.revisions', [
            'content'   => $content,
            'revisions' => $content->revisions()->with('user')->paginate(20),
        ]);
    }

    // ---------------------------------------------------------------
    // 内部
    // ---------------------------------------------------------------

    protected function form(Content $content, ?Content $anchor = null, ?string $transLocale = null): View
    {
        $anchor = $anchor ?? $content;
        $transLocale = $transLocale ?? LocaleRegistry::default();

        // 各语言版本完成状态（用于顶部 Tabs 标记）。
        $versions = [];
        foreach ($this->editableLocales() as $loc) {
            if ($loc === LocaleRegistry::default()) {
                $versions[$loc] = (bool) $anchor->title;
            } else {
                $row = $anchor->translation($loc);
                $versions[$loc] = $row && (bool) $row->title;
            }
        }

        return view('admin.contents.form', [
            'content'     => $content,
            'anchor'      => $anchor,
            'transLocale' => $transLocale,
            'versions'    => $versions,
            'categories'  => Category::with('children')->whereNull('parent_id')->orderBy('sort')->get(),
            'groups'      => Group::with('category')->orderBy('sort')->get(),
            'facts'       => Fact::orderBy('sort')->get(),
            // 18R-2c：标签（站点级）+ 可关联实体（产品/服务/组织/主题）
            'tags'             => \App\Models\Tag::orderBy('name')->get(),
            'selectedTagIds'   => $anchor->exists ? $anchor->tags()->pluck('tags.id')->all() : [],
            'entityOptions'    => Entity::whereIn('type', [
                Entity::TYPE_PRODUCT, Entity::TYPE_SERVICE,
                Entity::TYPE_ORGANIZATION, Entity::TYPE_TOPIC,
            ])->orderBy('type')->orderBy('name')->get(),
            'selectedLinks'    => $anchor->exists
                ? $anchor->entityLinks()->get()->map(fn ($l) => ['entity_id' => $l->entity_id, 'relation_type' => $l->relation_type])->all()
                : [],
        ]);
    }

    protected function validateForm(Request $request, ?Content $except = null): array
    {
        $siteId = ($except && $except->site_id) ? $except->site_id : SiteContext::currentSiteId();
        $locale = ($except && $except->locale) ? $except->locale : $this->resolveTransLocale($request);

        $slugRule = ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        $slugRule[] = Rule::unique('contents', 'slug')
            ->where(fn ($q) => $q->where('site_id', $siteId)->where('locale', $locale))
            ->ignore($except?->id);

        return $request->validate([
            'type'        => ['required', 'in:article,page'],
            'title'       => ['required', 'string', 'max:200'],
            'slug'        => $slugRule,
            'category_id' => ['nullable', 'exists:categories,id'],
            'group_id'    => ['nullable', 'exists:groups,id'],
            'summary'     => ['nullable', 'string', 'max:1000'],
            'body'        => ['nullable', 'string'],
            'published_at'=> ['nullable', 'date'],
            // 安全：与媒体库/内联上传保持一致，禁止 SVG（可内嵌 <script>，经
            // /storage 以 image/svg+xml 直出构成存储型 XSS，见 MediaController）。（UAT Bug#4）
            'cover_file'  => ['nullable', 'file', 'max:6144', 'mimes:jpg,jpeg,png,webp,gif'],
            'cover_remove'=> ['nullable', 'boolean'],

            'geo_conclusion'   => ['nullable', 'string'],
            'geo_explanation'  => ['nullable', 'string'],
            'geo_boundary'     => ['nullable', 'string'],

            'lock_manual'  => ['nullable', 'boolean'],

            'owner'        => ['nullable', 'string', 'max:60'],
            'reviewed_at'  => ['nullable', 'date'],
            'review_due'   => ['nullable', 'date'],
            'source_note'  => ['nullable', 'string', 'max:255'],
            'fact_refs'    => ['nullable', 'array'],
            // 18R-2c：标签（受控 id）+ 内容-实体关系（relation_type 白名单）
            'tag_ids'            => ['nullable', 'array'],
            'tag_ids.*'          => ['integer', 'exists:tags,id'],
            'entity_links'       => ['nullable', 'array'],
            'entity_links.*.entity_id'    => ['integer', 'exists:entities,id'],
            'entity_links.*.relation_type'=> ['in:about,mention'],
        ]);
    }

    /**
     * 同步标签与内容-实体关系（仅默认语言 anchor；标签/关系为站点级，不随翻译行重复）。
     */
    protected function syncRelations(Content $content, Request $request): void
    {
        if ($content->locale !== LocaleRegistry::default()) {
            return;
        }
        $content->tags()->sync((array) $request->input('tag_ids', []));

        \App\Models\ContentEntity::where('content_id', $content->id)->delete();
        foreach ((array) $request->input('entity_links', []) as $link) {
            if (empty($link['entity_id'])) {
                continue;
            }
            \App\Models\ContentEntity::create([
                'site_id'        => $content->site_id,
                'content_id'     => $content->id,
                'entity_id'      => (int) $link['entity_id'],
                'relation_type'  => in_array($link['relation_type'] ?? '', ['about', 'mention'], true)
                    ? $link['relation_type'] : 'mention',
            ]);
        }
    }

    /**
     * 把表单（含动态行）整理为可 fill 的数据
     */
    protected function hydrate(array $data, Request $request): array
    {
        // 证据动态行：ev_label[] ev_value[] ev_source[] ev_url[]
        $evidence = [];
        $labels = (array) $request->input('ev_label', []);
        foreach ($labels as $i => $label) {
            $label = trim((string) $label);
            $value = trim((string) $request->input("ev_value.$i", ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $evidence[] = array_filter([
                'label'  => $label,
                'value'  => $value,
                'source' => trim((string) $request->input("ev_source.$i", '')),
                'url'    => trim((string) $request->input("ev_url.$i", '')),
            ], fn ($v) => $v !== '');
        }
        $data['geo_evidence'] = $evidence;

        // FAQ 动态行
        $faqs = [];
        foreach ((array) $request->input('faq_q', []) as $i => $q) {
            $q = trim((string) $q);
            $a = trim((string) $request->input("faq_a.$i", ''));
            if ($q === '' && $a === '') {
                continue;
            }
            $faqs[] = ['q' => $q, 'a' => $a];
        }
        $data['geo_faq'] = $faqs;

        // 关键事实动态行
        $keyFacts = [];
        foreach ((array) $request->input('kf_key', []) as $i => $key) {
            $key = trim((string) $key);
            $value = trim((string) $request->input("kf_value.$i", ''));
            if ($key === '' && $value === '') {
                continue;
            }
            $keyFacts[] = ['key' => $key, 'value' => $value];
        }
        $data['geo_key_facts'] = $keyFacts;

        $data['fact_refs'] = array_values((array) $request->input('fact_refs', []));
        $data['lock_manual'] = $request->boolean('lock_manual');

        // 封面图：直接上传即入媒体库并关联；勾选移除则清空。未上传也未移除时保持原封面。
        unset($data['cover_file'], $data['cover_remove']); // 非数据表字段，禁止进入 fill
        if ($request->boolean('cover_remove')) {
            $data['cover_id'] = null;
        }
        if ($request->hasFile('cover_file') && $request->file('cover_file')->isValid()) {
            $file = $request->file('cover_file');
            $path = \App\Support\ImageOptimizer::store($file, 'covers/' . date('Ym'), \App\Support\ImageOptimizer::MAXW_CONTENT);
            $storedPath = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
            [$width, $height] = @getimagesize($storedPath) ?: [null, null];
            $media = Media::create([
                'disk'          => 'public',
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType(),
                'size'          => is_file($storedPath) ? filesize($storedPath) : $file->getSize(),
                'width'         => $width,
                'height'        => $height,
                'alt'           => $request->input('title'),
                'uploaded_by'   => $request->user()?->id,
            ]);
            $data['cover_id'] = $media->id;
        }

        return $data;
    }

    protected function snapshot(Content $content, string $note): void
    {
        ContentRevision::create([
            'content_id' => $content->id,
            'user_id'    => auth()->id(),
            'note'       => $note,
            'snapshot'   => $content->only([
                'title', 'slug', 'summary', 'body', 'type', 'category_id', 'group_id',
                'geo_conclusion', 'geo_explanation', 'geo_evidence', 'geo_boundary',
                'geo_faq', 'geo_key_facts', 'fact_refs', 'owner', 'reviewed_at',
                'review_due', 'source_note',
                'status', 'published_at',
            ]),
        ]);
    }

    /** 请求 ?trans=<locale>；非法 / 缺省回退默认语言。 */
    protected function resolveTransLocale(Request $request): string
    {
        $loc = (string) $request->input('trans', '');

        return LocaleRegistry::supports($loc) ? $loc : LocaleRegistry::default();
    }

    /** 当前站点可编辑的语言（Setting site_supported_locales），默认语言置首。 */
    protected function editableLocales(): array
    {
        $raw = Setting::get('site_supported_locales', [LocaleRegistry::default()]);

        if (is_array($raw)) {
            $locales = array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? trim($v) : '',
                $raw
            )));
        } else {
            $locales = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
        }

        $locales = $locales !== [] ? $locales : [LocaleRegistry::default()];

        usort($locales, function ($a, $b): int {
            if ($a === LocaleRegistry::default()) { return -1; }
            if ($b === LocaleRegistry::default()) { return 1; }
            return 0;
        });

        return $locales;
    }

    /** 未保存的新翻译行（复制站点 / 分组 / 类型 / 状态，locale 切换）。 */
    protected function blankTranslation(Content $anchor, string $locale): Content
    {
        $row = new Content();
        $row->site_id = $anchor->site_id;
        $row->translation_group = $anchor->translation_group;
        $row->locale = $locale;
        $row->type = $anchor->type;
        $row->status = $anchor->status;

        return $row;
    }
}
