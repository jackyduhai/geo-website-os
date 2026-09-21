<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Media;
use App\Services\Seo\SeoMetaResolver;
use App\Support\Catalog;
use App\Support\SiteContext;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 实体（Entity）后台管理 —— P-STEP 17B。
 *
 * Entity 是 GEO 知识图谱与前台 Catalog 的正式目录资源：组织 / 产品 / 服务 /
 * 人物 / 地点 / 主题。对外的产品 / 服务 / 组织 / 地点统一在此生产，由
 * {@see Catalog} 按站点投影到 /products/{slug}、/solutions/{slug} 与 Schema /
 * GEO / Sitemap，根治 16A 发现的「后台 Content(product) 可发布、前台 Entity
 * Catalog 取不到 → 404，feed 仍输出 URL」双轨缺陷。
 *
 * 边界：Content 与 Entity 为平行资源（5.6-C 冻结），本控制器不建立任何
 * Content ↔ Entity 隐式关联；EntityRelation 的可视化编辑在 17C 交付。
 */
class EntityController extends Controller
{
    /** 实体类型（冻结枚举，禁止新增 brand / solution / place 等别名）。 */
    public const TYPES = [
        'organization' => '组织',
        'product'      => '产品',
        'service'      => '服务',
        'person'       => '人物',
        'location'     => '地点',
        'topic'        => '主题',
    ];

    public function index(Request $request, ?string $tab = 'all'): View
    {
        if ($tab !== 'all' && ! array_key_exists($tab, self::TYPES)) {
            $tab = 'all';
        }

        $query = Entity::query();
        if ($tab !== 'all') {
            $query->where('type', $tab);
        }
        if ($search = trim((string) $request->get('q'))) {
            $query->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $items = $query->orderBy('type')->orderBy('sort_order')->orderBy('id')
            ->paginate(20)->withQueryString();

        // 各类型数量（Tab 角标）
        $typeCounts = Entity::selectRaw('type, COUNT(*) AS aggregate')
            ->groupBy('type')->pluck('aggregate', 'type');

        // 每个实体作为关系源端的关系数（删除提示用）
        $relationCounts = EntityRelation::whereIn('from_entity_id', $items->pluck('id'))
            ->selectRaw('from_entity_id, COUNT(*) AS aggregate')
            ->groupBy('from_entity_id')->pluck('aggregate', 'from_entity_id');

        return view('admin.entities.index', [
            'items'          => $items,
            'tab'            => $tab,
            'typeNames'      => self::TYPES,
            'typeCounts'     => $typeCounts,
            'relationCounts' => $relationCounts,
            'q'              => $search,
            'fStatus'        => $status,
        ]);
    }

    public function create(string $type): View
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        $entity = new Entity([
            'type'       => $type,
            'status'     => Entity::STATUS_DRAFT,
            'sort_order' => 0,
            'metadata'   => ['core' => true],
        ]);

        return $this->form($entity);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = (string) $request->input('type');
        abort_unless(array_key_exists($type, self::TYPES), 404);

        $data = $this->validateData($request, null, $type);

        $entity = new Entity();
        $entity->fill([
            'type'        => $type,
            'name'        => $data['name'],
            'slug'        => $data['slug'],
            'summary'     => $data['summary'] ?? null,
            'description' => $data['description'] ?? null,
            'status'      => $data['status'],
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);
        $this->applyMetadata($request, $entity, $type);
        $this->applyPublication($entity);
        $entity->save();

        $this->resetReadModels();

        return redirect()->route('admin.entities.edit', $entity)
            ->with('success', '实体已创建。');
    }

    public function edit(Entity $entity): View
    {
        return $this->form($entity);
    }

    public function update(Request $request, Entity $entity): RedirectResponse
    {
        $type = $entity->type; // 类型创建后不可修改
        $data = $this->validateData($request, $entity, $type);

        $entity->fill([
            'name'        => $data['name'],
            'slug'        => $data['slug'],
            'summary'     => $data['summary'] ?? null,
            'description' => $data['description'] ?? null,
            'status'      => $data['status'],
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);
        $this->applyMetadata($request, $entity, $type);
        $this->applyPublication($entity);
        $entity->save();

        $this->resetReadModels();

        return redirect()->route('admin.entities.edit', $entity)
            ->with('success', '实体已保存。');
    }

    public function destroy(Entity $entity): RedirectResponse
    {
        $type = $entity->type;
        // entity_relations 两端外键为 ON DELETE CASCADE，关联关系随实体自动清除。
        $entity->delete();
        $this->resetReadModels();

        return redirect()->route('admin.entities.index', $type)
            ->with('success', '实体已删除，关联关系已一并清除。');
    }

    public function publish(Entity $entity): RedirectResponse
    {
        $entity->status = Entity::STATUS_PUBLISHED;
        $this->applyPublication($entity);
        $entity->save();
        $this->resetReadModels();

        return redirect()->back()->with('success', '实体已发布。');
    }

    public function unpublish(Entity $entity): RedirectResponse
    {
        $entity->status = Entity::STATUS_DRAFT;
        $entity->published_at = null;
        $entity->save();
        $this->resetReadModels();

        return redirect()->back()->with('success', '实体已下架为草稿。');
    }

    /**
     * 一次性演示入口：把内置 Example 种子（config/facts.php，经 CatalogSeeder）
     * 投影为当前站点的实体。运行期不以 Facts 为源，仅用于演示 / 快速体验。
     */
    public function seedExamples(): RedirectResponse
    {
        $before = Entity::count();
        (new CatalogSeeder())->run();
        $this->resetReadModels();
        $created = Entity::count() - $before;

        if (Entity::count() === $before) {
            return redirect()->route('admin.entities.index')
                ->with('error', '当前没有可载入的示例种子（开源裸部署为空），未生成实体。');
        }

        return redirect()->route('admin.entities.index')
            ->with('success', "已从示例种子生成 / 同步实体（新增 {$created} 个，幂等）。");
    }

    private function form(Entity $entity): View
    {
        $mediaImages = Media::where('mime', 'like', 'image/%')
            ->orderByDesc('id')->limit(100)->get();

        return view('admin.entities.form', [
            'entity'      => $entity,
            'typeNames'   => self::TYPES,
            'mediaImages' => $mediaImages,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateData(Request $request, ?Entity $except, string $type): array
    {
        $siteId = SiteContext::currentSiteId();

        $rules = [
            'type'        => ['required', Rule::in(array_keys(self::TYPES))],
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => [
                'required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('entities', 'slug')
                    ->where(fn ($q) => $q->where('site_id', $siteId)->where('type', $type))
                    ->ignore($except?->id),
            ],
            'summary'     => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'status'      => ['required', Rule::in([
                Entity::STATUS_DRAFT, Entity::STATUS_PUBLISHED, Entity::STATUS_ARCHIVED,
            ])],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            // 通用媒体字段
            'card_image'  => ['nullable', 'integer'],
            'og_image'    => ['nullable', 'integer'],
        ];

        if ($type === Entity::TYPE_PRODUCT) {
            $rules['meta_line'] = ['nullable', 'string', 'max:128'];
            $rules['meta_tagline'] = ['nullable', 'string', 'max:255'];
        }
        if ($type === Entity::TYPE_ORGANIZATION) {
            $rules['org_brand'] = ['nullable', 'string', 'max:255'];
            $rules['org_industry'] = ['nullable', 'string', 'max:255'];
            $rules['org_phone'] = ['nullable', 'string', 'max:64'];
            $rules['org_email'] = ['nullable', 'email', 'max:255'];
            $rules['org_address'] = ['nullable', 'string', 'max:255'];
        }
        if ($type === Entity::TYPE_LOCATION) {
            $rules['loc_address'] = ['nullable', 'string', 'max:255'];
            $rules['loc_latitude'] = ['nullable', 'numeric', 'between:-90,90'];
            $rules['loc_longitude'] = ['nullable', 'numeric', 'between:-180,180'];
        }
        if ($type === Entity::TYPE_SERVICE) {
            $rules['svc_scope'] = ['nullable', 'string', 'max:255'];
            $rules['svc_title_q'] = ['nullable', 'string', 'max:255'];
        }

        return $request->validate($rules, [
            'slug.regex' => 'slug 仅允许小写字母、数字与连字符（如 industrial-coating）。',
            'slug.unique' => '同站点下同类型实体已存在相同 slug。',
        ]);
    }

    /**
     * 按类型把表单字段写入 metadata，并保留表单不管理的既有键（示例种子的
     * key_params / params / scenes / product_lines 等垂直数据不被编辑清空）。
     */
    private function applyMetadata(Request $request, Entity $entity, string $type): void
    {
        $metadata = is_array($entity->metadata) ? $entity->metadata : [];

        // 卡片图：媒体库选择（id → 公开 URL），供 _product_card / asset 消费
        if ($request->boolean('card_image_clear')) {
            unset($metadata['image']);
        } elseif ($cardId = (int) $request->input('card_image')) {
            if ($url = Media::find($cardId)?->url()) {
                $metadata['image'] = $url;
            }
        }

        // OG 分享图：存媒体 id（与 GeoGraphBuilder::preloadMediaPaths、
        // SeoMetaResolver::mediaPath 的 id 语义一致）
        if ($request->boolean('og_image_clear')) {
            unset($metadata['og_image']);
        } elseif ($ogId = (int) $request->input('og_image')) {
            $metadata['og_image'] = $ogId;
        }

        if ($type === Entity::TYPE_PRODUCT) {
            // core=true 才拥有独立详情页并进入 sitemap / llms；默认开启
            $metadata['core'] = $request->boolean('meta_core', true);
            $this->setOrUnset($metadata, 'line', trim((string) $request->input('meta_line')));
            $this->setOrUnset($metadata, 'tagline', trim((string) $request->input('meta_tagline')));
        }

        if ($type === Entity::TYPE_ORGANIZATION) {
            // Catalog::company() 读 metadata.company；至少保证 name 非空，
            // 否则 /products/、/solutions/ 等目录页会按空站 404。
            $company = is_array($metadata['company'] ?? null) ? $metadata['company'] : [];
            if ($v = trim((string) $request->input('org_brand'))) {
                $company['brand'] = $v;
            }
            if ($v = trim((string) $request->input('org_industry'))) {
                $company['industry'] = $v;
            }
            if ($v = trim((string) $request->input('org_phone'))) {
                $company['phone'] = $v;
            }
            if ($v = trim((string) $request->input('org_email'))) {
                $company['email'] = $v;
            }
            if ($v = trim((string) $request->input('org_address'))) {
                $company['address'] = $v;
            }
            $company['name'] = trim((string) $entity->name) ?: ($company['name'] ?? '');
            $metadata['company'] = $company;
        }

        if ($type === Entity::TYPE_LOCATION) {
            $this->setOrUnset($metadata, 'address', trim((string) $request->input('loc_address')));
            if (! is_null($v = $request->input('loc_latitude')) && $v !== '') {
                $metadata['latitude'] = (float) $v;
            }
            if (! is_null($v = $request->input('loc_longitude')) && $v !== '') {
                $metadata['longitude'] = (float) $v;
            }
        }

        if ($type === Entity::TYPE_SERVICE) {
            $this->setOrUnset($metadata, 'scope', trim((string) $request->input('svc_scope')));
            $this->setOrUnset($metadata, 'title_q', trim((string) $request->input('svc_title_q')));
        }

        $entity->metadata = $metadata;
    }

    private function setOrUnset(array &$metadata, string $key, string $value): void
    {
        if ($value !== '') {
            $metadata[$key] = $value;
        } else {
            unset($metadata[$key]);
        }
    }

    private function applyPublication(Entity $entity): void
    {
        if ($entity->status === Entity::STATUS_PUBLISHED && ! $entity->published_at) {
            $entity->published_at = now();
        } elseif ($entity->status === Entity::STATUS_DRAFT) {
            $entity->published_at = null;
        }
    }

    /**
     * 实体写入后立即复位 Catalog 投影与 SEO memo，保证同进程 / 下一请求
     * 前台 /products/{slug}、Schema、Sitemap 即时反映（不依赖请求结束自然清理）。
     */
    private function resetReadModels(): void
    {
        Catalog::flush();
        SeoMetaResolver::resetRequestMemo();
    }
}
