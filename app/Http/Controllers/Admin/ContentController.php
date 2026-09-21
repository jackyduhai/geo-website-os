<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Fact;
use App\Models\Group;
use App\Models\Media;
use App\Services\Gate\ContentGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 内容管理（文章 / 单页 / 产品共用）
 *
 * 关键纪律：草稿可以不完整；一旦点「发布」必须通过 ContentGate。
 * 手动发布与 GEOFlow 推送共用同一套门禁，没有第二条宽松路径。
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
            'typeName' => ['article' => '文章', 'page' => '单页', 'product' => '产品', 'all' => '全部'][$tab ?? 'all'] ?? '内容',
        ]);
    }

    public function create(string $type = 'article'): View
    {
        return $this->form(new Content(['type' => $type, 'status' => 'draft']));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateForm($request);
        $data = $this->hydrate($data, $request);

        $content = new Content();
        $content->fill($data);
        $content->status = 'draft';
        $content->content_hash = $content->computeHash();
        $content->save();

        $this->snapshot($content, '创建草稿');
        AuditLog::record('content.created', '创建内容：' . $content->title, [], 'content', $content->id);

        return redirect()->route('admin.contents.edit', $content)
            ->with('success', '草稿已保存。完善四层结构后可提交发布。');
    }

    public function edit(Content $content): View
    {
        return $this->form($content);
    }

    public function update(Request $request, Content $content): RedirectResponse
    {
        $data = $this->validateForm($request, $content);
        $data = $this->hydrate($data, $request);

        // 已发布内容编辑后保持发布状态，但仍需重新过门禁；此处先保存，发布状态由发布动作把关
        $content->fill($data);
        $content->content_hash = $content->computeHash();
        $content->save();

        $this->snapshot($content, '编辑保存');
        AuditLog::record('content.updated', '编辑内容：' . $content->title, [], 'content', $content->id);

        return back()->with('success', '内容已保存');
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
        // 先把表单外可能的编辑落库？不——发布只针对已保存内容；先保存再发布是固定流程
        $result = $gate->check($content);

        if (! $result['passed']) {
            return back()->with('error', '未通过 GEO 门禁，已拒绝发布：')->with('gateErrors', $result['errors'])
                ->with('gateWarnings', $result['warnings']);
        }

        if (! $content->published_at) {
            $content->published_at = now();   // 留空则立即发布；指定未来时间即定时发布
        }
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
     * 正文 Markdown 预览：用与前台完全相同的 Str::markdown 渲染，保证“所见即前台所得”，
     * 支持 GFM 表格。仅管理员可用，不落库。
     */
    public function mdPreview(Request $request): JsonResponse
    {
        $text = (string) $request->input('text', '');

        return response()->json(['html' => (string) \Illuminate\Support\Str::markdown($text)]);
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

    protected function form(Content $content): View
    {
        return view('admin.contents.form', [
            'content'    => $content,
            'categories' => Category::with('children')->whereNull('parent_id')->orderBy('sort')->get(),
            'groups'     => Group::with('category')->orderBy('sort')->get(),
            'facts'      => Fact::orderBy('sort')->get(),
        ]);
    }

    protected function validateForm(Request $request, ?Content $except = null): array
    {
        $slugRule = ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        $slugRule[] = $except
            ? 'unique:contents,slug,' . $except->id
            : 'unique:contents,slug';

        return $request->validate([
            'type'        => ['required', 'in:article,page,product'],
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

            // legacy SEO 字段（seo_title/seo_desc/canonical/noindex）已随 P0-B 移除：
            // 内容级 SEO 由独立 SeoMeta 管理（SeoMetaResolver 统一 Resolution）。
            'lock_manual'  => ['nullable', 'boolean'],

            'owner'        => ['nullable', 'string', 'max:60'],
            'reviewed_at'  => ['nullable', 'date'],
            'review_due'   => ['nullable', 'date'],
            'source_note'  => ['nullable', 'string', 'max:255'],
            'fact_refs'    => ['nullable', 'array'],
        ]);
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
}
