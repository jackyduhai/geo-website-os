<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Support\Catalog;
use App\Support\SiteContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 实体关系（EntityRelation）后台管理 —— P-STEP 17C。
 *
 * 关系是 GEO 知识图谱的正式有向边：from_entity ──relation_type──▶ to_entity。
 * 五型冻结：produces / offers / uses / located_in / related_to。
 *
 * 契约（与 EntityRelationDeepTest、entity_relations 迁移一致，不在 CRUD 中临时变更）：
 *   - 方向有语义；同四元组（site + from + to + type）唯一，重复关系被拒绝；
 *   - 反向关系允许（不同四元组）；自关系允许（如 topic related_to 自身）；
 *   - 关系表无独立 status：是否对外可见由两端实体的 published 状态决定
 *     （GeoGraphBuilder 仅在两端均发布时输出该边）；
 *   - 两端实体必须同站：表单下拉只列本站实体（UI 前置），模型 saving 跨站守卫兜底；
 *   - 删除实体时，其作为源 / 目标的关系由外键 ON DELETE CASCADE 自动清除。
 *
 * 边界：本控制器只维护 EntityRelation 正式边，不建立任何 Content ↔ Entity 关联
 * （5.6-C 冻结），不新增 ProductCategory / ProductLine 等分类实体体系。
 */
class EntityRelationController extends Controller
{
    /** 关系类型 → 中文说明（含推荐方向），键为冻结枚举，禁止新增别名。 */
    public const TYPE_LABELS = [
        'produces'   => '生产 produces（组织 → 产品）',
        'offers'     => '提供 offers（组织 → 服务）',
        'uses'       => '使用 uses（服务 → 产品）',
        'located_in' => '位于 located_in（主体 → 地点）',
        'related_to' => '相关 related_to（通用关联）',
    ];

    public function index(Request $request): View
    {
        $query = EntityRelation::query()->with(['fromEntity', 'toEntity']);

        $fEntity = (int) $request->get('entity');
        if ($fEntity > 0) {
            $query->where(fn ($w) => $w
                ->where('from_entity_id', $fEntity)
                ->orWhere('to_entity_id', $fEntity));
        }

        $fType = (string) $request->get('type');
        if (array_key_exists($fType, self::TYPE_LABELS)) {
            $query->where('relation_type', $fType);
        }

        $items = $query->orderBy('sort_order')->orderBy('id')
            ->paginate(30)->withQueryString();

        return view('admin.relations.index', [
            'items'           => $items,
            'entities'        => $this->siteEntities(),
            'typeLabels'      => self::TYPE_LABELS,
            'entityTypeNames' => EntityController::TYPES,
            'fEntity'         => $fEntity,
            'fType'           => $fType,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.relations.form', $this->formData(
            new EntityRelation(['sort_order' => 0]),
            (int) $request->get('from')
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new EntityRelation(), 'created');
    }

    public function edit(EntityRelation $relation): View
    {
        return view('admin.relations.form', $this->formData($relation, $relation->from_entity_id));
    }

    public function update(Request $request, EntityRelation $relation): RedirectResponse
    {
        return $this->save($request, $relation, 'updated');
    }

    public function destroy(EntityRelation $relation): RedirectResponse
    {
        $relation->delete();
        Catalog::flush();

        return redirect()->route('admin.relations.index')
            ->with('success', '实体关系已删除。');
    }

    /**
     * @return array<string,mixed>
     */
    private function formData(EntityRelation $relation, int $preselectFrom): array
    {
        return [
            'relation'        => $relation,
            'entities'        => $this->siteEntities(),
            'typeLabels'      => self::TYPE_LABELS,
            'entityTypeNames' => EntityController::TYPES,
            'preselectFrom'   => $preselectFrom,
        ];
    }

    /**
     * 本站全部实体（SiteScope 自动加 site_id），供源 / 目标下拉按类型分组。
     */
    private function siteEntities()
    {
        // 关系语言中性、权威边 from/to 指向默认语言行（GeoGraph/Catalog 按
        // translation_group 自动映射其他语言）。下拉只列默认语言权威行，避免同一逻辑
        // 实体出现多语言选项、误选非默认行导致建边后关系不生效。
        return Entity::query()
            ->where('locale', \App\Support\Localization\LocaleRegistry::default())
            ->orderBy('type')->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    private function save(Request $request, EntityRelation $relation, string $verb): RedirectResponse
    {
        $siteId = SiteContext::currentSiteId();

        $validated = $request->validate([
            // 源 / 目标必须是当前站点实体（Rule::exists 不经 SiteScope，需显式限定 site_id）
            'from_entity_id' => [
                'required', 'integer',
                Rule::exists('entities', 'id')->where(fn ($q) => $q->where('site_id', $siteId)),
            ],
            'to_entity_id' => [
                'required', 'integer',
                Rule::exists('entities', 'id')->where(fn ($q) => $q->where('site_id', $siteId)),
                // 复合唯一（site + from + to + type），编辑时排除自身
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $relation, $siteId): void {
                    $dup = EntityRelation::query()
                        ->where('site_id', $siteId)
                        ->where('from_entity_id', (int) $request->input('from_entity_id'))
                        ->where('to_entity_id', (int) $value)
                        ->where('relation_type', (string) $request->input('relation_type'))
                        ->when($relation->exists, fn ($q) => $q->where('id', '!=', $relation->id))
                        ->exists();
                    if ($dup) {
                        $fail('该关系已存在（源实体、目标实体与关系类型完全相同），请勿重复创建。');
                    }
                },
            ],
            'relation_type' => ['required', Rule::in(array_keys(self::TYPE_LABELS))],
            'sort_order'    => ['nullable', 'integer', 'min:0'],
            'metadata_text' => ['nullable', 'string'],
        ], [
            'from_entity_id.required' => '请选择源实体。',
            'from_entity_id.exists'   => '源实体不存在或不属于当前站点。',
            'to_entity_id.required'   => '请选择目标实体。',
            'to_entity_id.exists'     => '目标实体不存在或不属于当前站点，不能跨站建立关系。',
            'relation_type.required'  => '请选择关系类型。',
            'relation_type.in'        => '关系类型非法。',
        ]);

        // metadata：可选 JSON 对象；空串视为无 metadata
        $metadata = $this->parseMetadata((string) ($validated['metadata_text'] ?? ''));

        try {
            // 显式 site_id：EntityRelation 的 saving 跨站校验早于 BelongsToSite 的
            // creating 自动注入（与 CatalogSeeder::relate 同一约束）。
            $relation->fill([
                'site_id'        => $siteId,
                'from_entity_id' => (int) $validated['from_entity_id'],
                'to_entity_id'   => (int) $validated['to_entity_id'],
                'relation_type'  => $validated['relation_type'],
                'sort_order'     => (int) ($validated['sort_order'] ?? 0),
                'metadata'       => $metadata,
            ]);
            $relation->save();
        } catch (UniqueConstraintViolationException) {
            // 并发 / 绕过前置校验时的最后防线：给出友好提示而非数据库异常白屏
            return back()->withInput()
                ->with('error', '该关系已存在（源实体、目标实体与关系类型完全相同），请勿重复创建。');
        } catch (\RuntimeException $e) {
            // 模型 saving 跨站守卫：两端非同站（正常下拉无法触发，篡改请求时兜底）
            if (str_contains($e->getMessage(), 'Cross-site')) {
                return back()->withInput()
                    ->with('error', '关系保存失败：两端实体必须属于当前站点，不能跨站建立关系。');
            }
            throw $e;
        }

        Catalog::flush();

        return redirect()->route('admin.relations.index')
            ->with('success', $verb === 'created' ? '实体关系已创建。' : '实体关系已更新。');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseMetadata(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'metadata_text' => '关系备注必须是合法的 JSON 对象（如 {"strength":"primary"}），或留空。',
            ]);
        }

        return $decoded;
    }
}
