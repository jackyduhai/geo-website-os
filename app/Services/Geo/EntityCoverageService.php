<?php

namespace App\Services\Geo;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Site;
use App\Support\Entities\EntityCapabilityRegistry;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocaleRegistry;
use App\Support\PublicIndex;
use App\Support\SiteContext;

/**
 * Entity Coverage —— 实体知识资产齐备度只读聚合层（P-STEP 18S Capability 3）。
 * ------------------------------------------------------------------
 * 定位：回答「当前站点每个核心实体，按其类型『应覆盖』的关系 / 必备字段填全了多少」。
 * 与 Cap2 GeoHealth（输出运行时健康：OG/URL/JSON-LD/noindex/边计数）严格分工。
 *
 * 唯一强制架构条件——「应覆盖项」的权威来源：
 *   config/entities.php（relations/metadata 的 required/recommended 声明）
 *     → EntityCapabilityRegistry（relationRequirements/requiredRelations/
 *       metadataRequirements/requiredMetadataKeys，归一化、向后兼容）
 *     → 本类（只消费，不拥有任何必备规则）。
 * 本类内不出现任何静态 $requiredRelations/$requiredMetadata 硬编码必备规则。
 *
 * 数据流（纯只读）：
 *   DB(Entity/EntityRelation) → PublicIndex 公开口径（site+locale）
 *     → 本类比对 Registry 应覆盖项 → 单实体/类型/整体 covered/required 比率 + 缺失项
 *     → Admin View。
 *
 * 红线：无写库/持久化/cache 表/migration；检测器非修复器；不做 0–100 加权健康分；
 *   全部按 SiteContext + LocaleContext 限定，不串站/串语言；Blank 空站合法 N/A。
 */
class EntityCoverageService
{
    /**
     * 生成当前站点的实体覆盖报告（只读）。
     *
     * @return array{
     *   site: array{id:int,name:string,locale:string},
     *   overall: string,
     *   blank: bool,
     *   totals: array{entities:int,required:int,covered:int,ratio:float},
     *   by_type: array,
     *   entities: array
     * }
     */
    public function report(): array
    {
        $site = SiteContext::currentSite();
        $locale = LocaleContext::current() ?: LocaleRegistry::default();

        // 当前站 + 当前 locale 的公开实体（PublicIndex 统一口径）。
        $entities = PublicIndex::entityQuery()->forLocale($locale)->get();

        // 批量取当前站全部实体关系，一次性内存聚合，消除 N+1（与 GeoHealthService Check E 同法）。
        $edges = EntityRelation::where('site_id', $site->id)->get();

        // 公开实体 id 集合 + id=>type 映射（两端均公开才计入连接关系）。
        $publicIds = $entities->pluck('id')->flip();
        $typeOf = $entities->mapWithKeys(fn (Entity $e) => [$e->id => $e->type]);

        // adjacency[entityId] = [相连另一端的目标类型 => true]（仅两端公开）。
        $adjacency = [];
        foreach ($edges as $r) {
            if (! isset($publicIds[$r->from_entity_id], $publicIds[$r->to_entity_id])) {
                continue;
            }
            $from = $r->from_entity_id;
            $to = $r->to_entity_id;
            $adjacency[$from][$typeOf[$to] ?? ''] = true;
            $adjacency[$to][$typeOf[$from] ?? ''] = true;
        }

        $entityRows = [];
        $byType = [];
        $totalRequired = 0;
        $totalCovered = 0;

        foreach ($entities as $e) {
            // 应覆盖关系（必备目标类型）与应覆盖字段（必备 metadata 键）——全部来自 Registry。
            $requiredTargets = EntityCapabilityRegistry::requiredRelations($e->type);
            $requiredMetaKeys = EntityCapabilityRegistry::requiredMetadataKeys($e->type);

            $connectedTypes = $adjacency[$e->id] ?? [];
            $missingTargets = array_values(array_filter(
                $requiredTargets,
                fn (string $t): bool => ! isset($connectedTypes[$t])
            ));

            $meta = is_array($e->metadata) ? $e->metadata : [];
            $missingMeta = [];
            foreach ($requiredMetaKeys as $k) {
                $v = $meta[$k] ?? null;
                if ($v === null || trim((string) $v) === '') {
                    $missingMeta[] = $k;
                }
            }

            $requiredCount = count($requiredTargets) + count($requiredMetaKeys);
            $missingCount = count($missingTargets) + count($missingMeta);
            $coveredCount = $requiredCount - $missingCount;

            $gaps = [];
            foreach ($missingTargets as $t) {
                $gaps[] = ['kind' => 'relation', 'label' => "缺少关系 → " . EntityCapabilityRegistry::label($t), 'severity' => 'required'];
            }
            foreach ($missingMeta as $k) {
                $gaps[] = ['kind' => 'metadata', 'label' => "缺少必备字段 {$k}", 'severity' => 'required'];
            }

            $row = [
                'id' => $e->id,
                'type' => $e->type,
                'type_label' => EntityCapabilityRegistry::label($e->type),
                'name' => $e->name,
                'slug' => $e->slug,
                'required' => $requiredCount,
                'covered' => $coveredCount,
                'ratio' => $requiredCount === 0 ? null : round($coveredCount / $requiredCount, 2),
                'gaps' => $gaps,
                'edit_url' => route('admin.entities.edit', ['entity' => $e->id]),
            ];
            $entityRows[] = $row;

            // 类型分组聚合。
            if (! isset($byType[$e->type])) {
                $byType[$e->type] = ['type' => $e->type, 'label' => EntityCapabilityRegistry::label($e->type), 'entities' => 0, 'required' => 0, 'covered' => 0, 'incomplete' => 0];
            }
            $byType[$e->type]['entities']++;
            $byType[$e->type]['required'] += $requiredCount;
            $byType[$e->type]['covered'] += $coveredCount;
            if ($missingCount > 0) {
                $byType[$e->type]['incomplete']++;
            }

            $totalRequired += $requiredCount;
            $totalCovered += $coveredCount;
        }

        // 类型比率 + N/A 标记（某类型无必备应覆盖项）。
        foreach ($byType as $t => &$bt) {
            $bt['ratio'] = $bt['required'] === 0 ? null : round($bt['covered'] / $bt['required'], 2);
        }
        unset($bt);

        $blank = $entities->isEmpty();
        $overall = $blank
            ? 'N/A'
            : ($totalRequired === 0
                ? 'N/A'
                : ($totalCovered === $totalRequired ? 'PASS' : 'WARNING'));

        return [
            'site' => ['id' => (int) $site->id, 'name' => (string) $site->name, 'locale' => $locale],
            'overall' => $overall,
            'blank' => $blank,
            'totals' => [
                'entities' => $entities->count(),
                'required' => $totalRequired,
                'covered' => $totalCovered,
                'ratio' => $totalRequired === 0 ? null : round($totalCovered / $totalRequired, 2),
            ],
            'by_type' => array_values($byType),
            'entities' => $entityRows,
        ];
    }
}
