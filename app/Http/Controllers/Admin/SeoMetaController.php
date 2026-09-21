<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Entity;
use App\Models\Media;
use App\Models\SeoMeta;
use App\Models\Site;
use App\Services\Seo\SeoMetaResolver;
use App\Support\SiteContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * SEO 覆盖（SeoMeta）后台管理 —— P-STEP 17D。
 *
 * SeoMeta 是 Title / Description / Canonical / OG / Robots 的**唯一显式覆盖层**，
 * 最终解析一律由 {@see SeoMetaResolver} 按 5.6-C 冻结的「分资源类型独立 Resolution
 * Context」完成。本控制器**不自行实现任何 fallback / 继承链 / canonical 拼接**，
 * 表单中的「当前解析值」直接调用 resolveSite() / resolveContent() / resolveEntity()
 * 得到，后台与前台共用同一个真相源。
 *
 * 三种作用域（seo_metas.chk_seo_metas_binding：content / entity 二选一或皆空）：
 *   - 站点级：content_id / entity_id 皆 NULL，每站至多一条（partial unique）；
 *   - 内容级：绑定本站 Content，每个内容至多一条；
 *   - 实体级：绑定本站 Entity，每个实体至多一条。
 * 三枚 partial unique index 与绑定 CHECK 之外，这里再做前置友好校验，避免把
 * SQLite 约束异常以 500 暴露给管理员。
 *
 * 边界：Content 与 Entity 为平行资源（5.6-C），本模块不建立任何 Content ↔ Entity
 * 关联；canonical host 绝对化、og:image host 绝对化、双路径收敛属 #86，不在此改
 * Resolver / URL 架构。og_image_path 按冻结契约保存「以 / 开头的站内公开路径」或
 * 完整 URL，前台原样输出（媒体库选择写 /storage/{path}）。
 */
class SeoMetaController extends Controller
{
    /** 作用域中文名。 */
    public const SCOPES = [
        'site'    => '站点级',
        'content' => '内容级',
        'entity'  => '实体级',
    ];

    /** og:type 常用取值（DB 默认 website）。 */
    public const OG_TYPES = [
        'website'   => 'website（站点 / 首页）',
        'article'   => 'article（文章）',
        'product'   => 'product（产品）',
        'profile'   => 'profile（人物 / 组织）',
    ];

    /** twitter:card 取值（DB 默认 summary_large_image）。 */
    public const TWITTER_CARDS = [
        'summary_large_image' => 'summary_large_image（大图卡）',
        'summary'             => 'summary（标准卡）',
    ];

    public function index(Request $request): View
    {
        $scope = (string) $request->get('scope', 'all');
        if (! array_key_exists($scope, self::SCOPES)) {
            $scope = 'all';
        }

        $query = SeoMeta::with(['content', 'entity', 'site']);
        if ($scope === 'site') {
            $query->whereNull('content_id')->whereNull('entity_id');
        } elseif ($scope === 'content') {
            $query->whereNotNull('content_id');
        } elseif ($scope === 'entity') {
            $query->whereNotNull('entity_id');
        }
        if ($search = trim((string) $request->get('q'))) {
            $query->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $items = $query->orderByDesc('id')->paginate(30)->withQueryString();

        // 本站是否已有站点级覆盖（列表顶部引导用）
        $siteLevelExists = SeoMeta::whereNull('content_id')->whereNull('entity_id')->exists();

        return view('admin.seo-metas.index', [
            'items'            => $items,
            'scope'            => $scope,
            'scopeLabels'      => self::SCOPES,
            'q'                => $search,
            'siteLevelExists'  => $siteLevelExists,
            'entityTypeLabels' => EntityController::TYPES,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $scope = (string) $request->get('scope', 'site');
        if (! array_key_exists($scope, self::SCOPES)) {
            $scope = 'site';
        }

        $seo = new SeoMeta([
            'og_type'      => 'website',
            'twitter_card' => 'summary_large_image',
            'noindex'      => false,
            'nofollow'     => false,
            'keywords'     => [],
            'robots'       => [],
            'metadata'     => [],
        ]);

        $contentId = $request->filled('content_id') ? (int) $request->get('content_id') : null;
        $entityId = $request->filled('entity_id') ? (int) $request->get('entity_id') : null;

        $target = null;
        // 站点级：每站至多一条，已存在则直接转去编辑；否则绑定当前管理站点用于解析预览。
        if ($scope === 'site') {
            $existing = SeoMeta::whereNull('content_id')->whereNull('entity_id')->first();
            if ($existing) {
                return redirect()->route('admin.seo-metas.edit', $existing)
                    ->with('success', '本站站点级 SEO 覆盖已存在，直接为你打开编辑。');
            }
            $target = SiteContext::currentSite();
        }

        // content / entity 作用域在对象未选定时，先渲染「选择对象」步骤。
        if ($scope === 'content') {
            if ($contentId) {
                $target = Content::find($contentId);
                abort_if($target === null, 404);
                $existing = SeoMeta::where('content_id', $target->id)->first();
                if ($existing) {
                    return redirect()->route('admin.seo-metas.edit', $existing)
                        ->with('success', '该内容已有 SEO 覆盖，直接为你打开编辑。');
                }
            }
        } elseif ($scope === 'entity') {
            if ($entityId) {
                $target = Entity::find($entityId);
                abort_if($target === null, 404);
                $existing = SeoMeta::where('entity_id', $target->id)->first();
                if ($existing) {
                    return redirect()->route('admin.seo-metas.edit', $existing)
                        ->with('success', '该实体已有 SEO 覆盖，直接为你打开编辑。');
                }
            }
        }

        return $this->formView($seo, 'create', $scope, $target, $contentId, $entityId);
    }

    public function store(Request $request): RedirectResponse
    {
        [$scope, $contentId, $entityId, $data] = $this->validateData($request, null);

        $seo = new SeoMeta();
        $this->fillSeo($seo, $scope, $contentId, $entityId, $data, $request);

        try {
            $seo->save();
        } catch (QueryException $e) {
            $this->throwFriendlyConstraint($scope);
        }

        SeoMetaResolver::resetRequestMemo();

        return redirect()->route('admin.seo-metas.edit', $seo)
            ->with('success', 'SEO 覆盖已创建。');
    }

    public function edit(SeoMeta $seoMeta): View
    {
        $seoMeta->load(['content', 'entity']);

        if ($seoMeta->isSiteLevel()) {
            $scope = 'site';
            $target = SiteContext::currentSite();
        } elseif ($seoMeta->isContentLevel()) {
            $scope = 'content';
            $target = $seoMeta->content;
        } else {
            $scope = 'entity';
            $target = $seoMeta->entity;
        }

        return $this->formView($seoMeta, 'edit', $scope, $target, $seoMeta->content_id, $seoMeta->entity_id);
    }

    public function update(Request $request, SeoMeta $seoMeta): RedirectResponse
    {
        // 作用域与绑定对象创建后不可变更（避免唯一约束 / CHECK 语义被绕过）。
        $scope = $seoMeta->isSiteLevel() ? 'site' : ($seoMeta->isContentLevel() ? 'content' : 'entity');
        [, , , $data] = $this->validateData($request, $seoMeta);

        $this->fillSeo($seoMeta, $scope, $seoMeta->content_id, $seoMeta->entity_id, $data, $request);

        try {
            $seoMeta->save();
        } catch (QueryException $e) {
            $this->throwFriendlyConstraint($scope);
        }

        SeoMetaResolver::resetRequestMemo();

        return redirect()->route('admin.seo-metas.edit', $seoMeta)
            ->with('success', 'SEO 覆盖已保存。');
    }

    public function destroy(SeoMeta $seoMeta): RedirectResponse
    {
        $scope = $seoMeta->isSiteLevel() ? 'site' : ($seoMeta->isContentLevel() ? 'content' : 'entity');
        $seoMeta->delete();
        SeoMetaResolver::resetRequestMemo();

        return redirect()->route('admin.seo-metas.index', $scope === 'site' ? [] : ['scope' => $scope])
            ->with('success', 'SEO 覆盖已删除，该资源对应字段恢复为自动解析（继承）。');
    }

    /**
     * 组装表单视图数据，并**直接调用 Resolver** 计算「当前解析值」。
     * 新建且对象未选定时 $resolved 为 null（视图先渲染对象选择步骤）。
     */
    private function formView(
        SeoMeta $seo,
        string $mode,
        string $scope,
        Content|Entity|Site|null $target,
        ?int $contentId,
        ?int $entityId,
    ): View {
        $resolved = null;
        if ($target !== null) {
            $resolver = app(SeoMetaResolver::class);
            $resolved = match (true) {
                $target instanceof Site    => $resolver->resolveSite($target),
                $target instanceof Content => $resolver->resolveContent($target),
                default                    => $resolver->resolveEntity($target),
            };
        }

        return view('admin.seo-metas.form', [
            'seo'              => $seo,
            'mode'             => $mode,
            'scope'            => $scope,
            'scopeLabels'      => self::SCOPES,
            'target'           => $target,
            'selectedContent'  => $contentId,
            'selectedEntity'   => $entityId,
            'resolved'         => $resolved,
            'ogTypes'          => self::OG_TYPES,
            'twitterCards'     => self::TWITTER_CARDS,
            'entityTypeLabels' => EntityController::TYPES,
            'contents'         => $scope === 'content' && $target === null
                ? Content::orderByDesc('id')->limit(300)->get(['id', 'type', 'title', 'slug', 'status'])
                : collect(),
            'entities'         => $scope === 'entity' && $target === null
                ? Entity::orderBy('type')->orderBy('id')->limit(300)->get(['id', 'type', 'name', 'slug', 'status'])
                : collect(),
            'mediaImages'      => Media::where('mime', 'like', 'image/%')->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    /**
     * @return array{0:string,1:?int,2:?int,3:array<string,mixed>}
     */
    private function validateData(Request $request, ?SeoMeta $existing): array
    {
        $siteId = SiteContext::currentSiteId();

        $scope = (string) $request->input('scope', 'site');
        if (! array_key_exists($scope, self::SCOPES)) {
            throw ValidationException::withMessages(['scope' => 'SEO 作用域非法。']);
        }

        $contentId = $request->filled('content_id') ? (int) $request->input('content_id') : null;
        $entityId = $request->filled('entity_id') ? (int) $request->input('entity_id') : null;

        // 绑定 CHECK 的服务端前置：content / entity 不得同时存在。
        if ($contentId !== null && $entityId !== null) {
            throw ValidationException::withMessages([
                'content_id' => '一条 SEO 覆盖只能绑定内容或实体中的一个，不能同时选择。',
            ]);
        }

        if ($scope === 'site') {
            $contentId = null;
            $entityId = null;
        } elseif ($scope === 'content') {
            $entityId = null;
            Validator::make($request->all(), [
                'content_id' => [
                    'required',
                    Rule::exists('contents', 'id')->where(fn ($q) => $q->where('site_id', $siteId)),
                ],
            ], [
                'content_id.required' => '请选择要覆盖 SEO 的内容。',
                'content_id.exists'  => '所选内容不存在或不属于当前站点。',
            ])->validate();
        } else { // entity
            $contentId = null;
            Validator::make($request->all(), [
                'entity_id' => [
                    'required',
                    Rule::exists('entities', 'id')->where(fn ($q) => $q->where('site_id', $siteId)),
                ],
            ], [
                'entity_id.required' => '请选择要覆盖 SEO 的实体。',
                'entity_id.exists'   => '所选实体不存在或不属于当前站点。',
            ])->validate();
        }

        // 三枚 partial unique index 的前置友好校验。
        $uniqueQuery = SeoMeta::where('site_id', $siteId);
        if ($scope === 'site') {
            $uniqueQuery->whereNull('content_id')->whereNull('entity_id');
        } elseif ($scope === 'content') {
            $uniqueQuery->where('content_id', $contentId);
        } else {
            $uniqueQuery->where('entity_id', $entityId);
        }
        if ($existing && $existing->exists) {
            $uniqueQuery->where('id', '!=', $existing->id);
        }
        if ($uniqueQuery->exists()) {
            $label = self::SCOPES[$scope];
            throw ValidationException::withMessages([
                'scope' => "{$label} SEO 覆盖已存在：同一作用域对象只能有一条覆盖，请编辑已有记录。",
            ]);
        }

        $data = $request->validate([
            'title'          => ['nullable', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'keywords_text'  => ['nullable', 'string', 'max:1000'],
            'canonical'      => ['nullable', 'string', 'max:2048', 'url'],
            'og_title'       => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:2000'],
            'og_image_path'  => ['nullable', 'string', 'max:500'],
            'og_media_id'    => ['nullable', 'integer'],
            'og_type'        => ['nullable', Rule::in(array_keys(self::OG_TYPES))],
            'twitter_card'   => ['nullable', Rule::in(array_keys(self::TWITTER_CARDS))],
            'noindex'        => ['nullable', 'boolean'],
            'nofollow'       => ['nullable', 'boolean'],
            'robots_text'    => ['nullable', 'string', 'max:500'],
            'schema_type'    => ['nullable', 'string', 'max:64'],
            'metadata_text'  => ['nullable', 'string'],
        ], [
            'canonical.url' => 'Canonical 必须是完整 URL（含 http:// 或 https://）；留空则自动生成。',
        ]);

        return [$scope, $contentId, $entityId, $data];
    }

    /**
     * 把校验后的表单数据写入模型（不触发 save）。空字符串统一为 null = 该字段继承。
     */
    private function fillSeo(
        SeoMeta $seo,
        string $scope,
        ?int $contentId,
        ?int $entityId,
        array $data,
        Request $request,
    ): void {
        $siteId = SiteContext::currentSiteId();

        $seo->site_id = $siteId;
        $seo->content_id = $scope === 'content' ? $contentId : null;
        $seo->entity_id = $scope === 'entity' ? $entityId : null;

        $seo->title = $this->nullOnEmpty($data['title'] ?? null);
        $seo->description = $this->nullOnEmpty($data['description'] ?? null);
        $seo->keywords = $this->parseList($data['keywords_text'] ?? '');
        $seo->canonical = $this->nullOnEmpty($data['canonical'] ?? null);

        $seo->og_title = $this->nullOnEmpty($data['og_title'] ?? null);
        $seo->og_description = $this->nullOnEmpty($data['og_description'] ?? null);
        $seo->og_type = $data['og_type'] ?: 'website';
        $seo->twitter_card = $data['twitter_card'] ?: 'summary_large_image';

        // OG 图：媒体库选择（写 /storage/{path} 站内公开相对路径）优先，其次手填，
        // 两者皆空则清除显式 OG 图、回到 Resolver 回退链。冻结契约：前台原样输出。
        $ogPath = null;
        if ($ogMediaId = (int) ($data['og_media_id'] ?? 0)) {
            $media = Media::where('mime', 'like', 'image/%')->find($ogMediaId);
            if ($media) {
                $ogPath = '/storage/' . ltrim((string) $media->path, '/');
            }
        }
        if ($ogPath === null) {
            $ogPath = $this->nullOnEmpty($data['og_image_path'] ?? null);
        }
        $seo->og_image_path = $ogPath;

        $seo->noindex = $request->boolean('noindex');
        $seo->nofollow = $request->boolean('nofollow');
        $seo->robots = $this->parseList($data['robots_text'] ?? '');
        $seo->schema_type = $this->nullOnEmpty($data['schema_type'] ?? null);
        $seo->metadata = $this->parseMetadata($data['metadata_text'] ?? '');
    }

    private function nullOnEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** 逗号 / 换行分隔的指令列表 → 去空、去重的数组（keywords、robots）。 */
    private function parseList(string $value): array
    {
        $parts = preg_split('/[,，\r\n]+/u', $value) ?: [];
        $parts = array_map(static fn ($s) => trim($s), $parts);
        $parts = array_filter($parts, static fn ($s) => $s !== '');

        return array_values(array_unique($parts));
    }

    /** 可选 JSON metadata：空串 → null；非法 JSON → 校验异常（同 17C 范式）。 */
    private function parseMetadata(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'metadata_text' => '高级 metadata 必须是合法 JSON 对象，例如 {"key":"value"}；留空表示不设置。',
            ]);
        }

        return $decoded;
    }

    private function throwFriendlyConstraint(string $scope): never
    {
        $label = self::SCOPES[$scope] ?? '';
        throw ValidationException::withMessages([
            'scope' => "{$label} SEO 覆盖与唯一性 / 绑定约束冲突，该对象可能已有覆盖或绑定关系非法。",
        ]);
    }
}
