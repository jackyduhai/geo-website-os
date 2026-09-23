<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\EntityRelation;
use App\Support\Facts;
use Illuminate\Database\Seeder;

/**
 * Example 目录（Catalog）播种器（P-STEP 14 / D.2）。
 *
 * 把内置 Example 事实库（config/facts.php，经 Facts 读取）投影为当前站点的
 * 正式领域数据：1 个 organization + N 个 product / service Entity，以及它们之间的
 * EntityRelation。投影后，前台 / Sitemap / llms / Schema 的目录数据统一由
 * {@see \App\Support\Catalog} 按站点从 Entity 读取，旧的全局文件事实源 Facts 不再
 * 作为 Multi-Site Runtime Source。
 *
 * 边界：
 *   - 仅用于演示 / 开发 / 测试（由 DemoSeeder 编排），geo:install 不调用本 Seeder；
 *   - 幂等：按 (site_id, type, slug) firstOrNew，可重复执行；
 *   - 完整原始数组原样存入 Entity.metadata，零字段丢失；
 *   - 历史 migration 与 Facts / config/facts.php 保留，本 Seeder 是它们退场前
 *     唯一被允许的 Runtime 相邻消费者（种子投影），不属于业务运行时。
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // 无 Example 公司数据时（开源裸部署）不播种任何目录，保持空站。
        $company = Facts::company();
        if (empty($company)) {
            return;
        }

        $now = now();

        // 1) Organization：承载公司 / 品牌 / 产品线 / 生产资质 / 合作 / 案例 / 合规等
        //    站点级目录数据（整体同构存入 metadata）。
        $organization = Entity::firstOrNew([
            'type' => Entity::TYPE_ORGANIZATION,
            'slug' => 'example-organization',
            'locale' => 'zh-CN',
        ]);
        $organization->fill([
            'name'        => $company['name'] ?? 'Example Organization',
            'summary'     => $company['industry'] ?? null,
            'description' => $company['name_en'] ?? null,
            'status'      => 'published',
            'published_at' => $now,
            'sort_order'  => 0,
            'metadata'    => [
                // TD-07：标记该 organization 实体为站点主体（Demo 目录中的主体公司
                // 节点，供 produces/offers 关系边挂载）；GEO 通过 same_as 锚定 Site
                // 聚合的唯一主体 {base}/#organization，避免出现第二个组织事实源。
                'is_site_organization' => true,
                'company'        => Facts::company(),
                'brand_language' => Facts::brandLanguage(),
                'product_lines'  => Facts::productLines(),
                'production'     => [
                    'workshops'      => Facts::workshops(),
                    'sales_regions'  => Facts::salesRegions(),
                    'sales_regions_en' => Facts::salesRegionsEnglish(),
                    'certifications' => Facts::certifications(),
                ],
                'cooperation'    => Facts::cooperation(),
                'cases'          => Facts::cases(),
                'compliance'     => Facts::compliance(),
            ],
        ]);
        $organization->save();

        // 2) Products：完整产品数组原样存入 metadata，sort_order 锁定事实库顺序。
        $productEntities = [];
        foreach (Facts::products() as $i => $product) {
            $entity = Entity::firstOrNew([
                'type' => Entity::TYPE_PRODUCT,
                'slug' => $product['slug'],
                'locale' => 'zh-CN',
            ]);
            $entity->fill([
                'name'         => $product['name'],
                'summary'      => $product['tagline'] ?? null,
                'status'       => 'published',
                'published_at' => $now,
                'sort_order'   => $i,
                'metadata'     => $product,
            ]);
            $entity->save();
            $productEntities[$product['slug']] = $entity;
        }

        // 3) Services（应用场景）：完整场景数组原样存入 metadata。
        $serviceEntities = [];
        foreach (Facts::scenes() as $i => $scene) {
            $entity = Entity::firstOrNew([
                'type' => Entity::TYPE_SERVICE,
                'slug' => $scene['slug'],
                'locale' => 'zh-CN',
            ]);
            $entity->fill([
                'name'         => $scene['name'],
                'summary'      => $scene['desc'] ?? null,
                'status'       => 'published',
                'published_at' => $now,
                'sort_order'   => $scene['order'] ?? $i,
                'metadata'     => $scene,
            ]);
            $entity->save();
            $serviceEntities[$scene['slug']] = $entity;
        }

        // 4) Relations：organization -produces-> product
        foreach ($productEntities as $productEntity) {
            $this->relate($organization, $productEntity, EntityRelation::TYPE_PRODUCES);
        }

        // organization -offers-> service
        foreach ($serviceEntities as $serviceEntity) {
            $this->relate($organization, $serviceEntity, EntityRelation::TYPE_OFFERS);
        }

        // service -uses-> product（场景组合 combo）；关键参数产品在同一条 uses 边上以
        // metadata.role=key_param 标记（key_param_product 必为 combo 成员之一，不另建边）。
        foreach (Facts::scenes() as $scene) {
            $serviceEntity = $serviceEntities[$scene['slug']] ?? null;
            if (! $serviceEntity) {
                continue;
            }
            $order = 0;
            foreach (($scene['combo'] ?? []) as $productSlug) {
                $productEntity = $productEntities[$productSlug] ?? null;
                if ($productEntity) {
                    $metadata = null;
                    if (($scene['key_param_product'] ?? null) === $productSlug) {
                        $metadata = [
                            'role'    => 'key_param',
                            'display' => (string) ($scene['key_param_display'] ?? ''),
                        ];
                    }
                    $this->relate($serviceEntity, $productEntity, EntityRelation::TYPE_USES, $order++, $metadata);
                }
            }
        }

        // product -uses-> service（产品适用场景，product.metadata.scenes）。
        // P-STEP 18A / #114：uses 是有向边，不做对称推断；这是与「场景组合 combo」方向
        // 相反、各自显式存在的边，前台产品「适用场景」与 /geo.json 统一以该边为权威来源。
        foreach (Facts::products() as $product) {
            $productEntity = $productEntities[$product['slug']] ?? null;
            if (! $productEntity) {
                continue;
            }
            $order = 0;
            foreach (($product['scenes'] ?? []) as $sceneSlug) {
                $serviceEntity = $serviceEntities[$sceneSlug] ?? null;
                if ($serviceEntity) {
                    $this->relate($productEntity, $serviceEntity, EntityRelation::TYPE_USES, $order++);
                }
            }
        }

        // service -related_to-> service（相邻场景，scene.metadata.adjacent）
        foreach (Facts::scenes() as $scene) {
            $serviceEntity = $serviceEntities[$scene['slug']] ?? null;
            if (! $serviceEntity) {
                continue;
            }
            $order = 0;
            foreach (($scene['adjacent'] ?? []) as $adjacentSlug) {
                $adjacentEntity = $serviceEntities[$adjacentSlug] ?? null;
                if ($adjacentEntity) {
                    $this->relate($serviceEntity, $adjacentEntity, EntityRelation::TYPE_RELATED_TO, $order++);
                }
            }
        }

        // product -related_to-> product
        foreach (Facts::products() as $product) {
            $from = $productEntities[$product['slug']] ?? null;
            if (! $from) {
                continue;
            }
            $order = 0;
            foreach (($product['related'] ?? []) as $relatedSlug) {
                $to = $productEntities[$relatedSlug] ?? null;
                if ($to) {
                    $this->relate($from, $to, EntityRelation::TYPE_RELATED_TO, $order++);
                }
            }
        }

        // 5) English 翻译行（P-STEP 18F）：站点主体 + 核心产品 + 全部应用场景提供 en；
        //    非核心产品暂不翻译，en 目录只列有译项，关系按 translation_group 部分保留。
        $this->ensureEnglish($organization, [
            'name'    => $company['name_en'] ?? 'Example Organization',
            'summary' => 'Industrial materials manufacturing',
            'description' => $company['name_en'] ?? 'Example Organization',
        ]);

        foreach ([
            'epoxy-primer-100' => [
                'name' => 'Zinc-Rich Epoxy Primer ZP-100',
                'summary' => 'Anti-corrosion primer for steel structures; recommended DFT 60-80 um, applied with a polyurethane topcoat.',
                'metadata' => [
                    'short_name' => 'Zinc-Rich Epoxy Primer',
                    'tag' => 'Industrial Protective Coatings',
                    'mains' => ['Steel', 'Steel structural parts'],
                    'key_params' => [
                        ['label' => 'Substrate', 'value' => 'Blast-cleaned steel'],
                        ['label' => 'Mix ratio', 'value' => 'Base : hardener = 9 : 1'],
                        ['label' => 'Touch-dry', 'value' => 'About 30 minutes'],
                    ],
                    'params' => [
                        ['step' => 'Surface prep', 'value' => 'Blast to Sa 2.5', 'note' => 'Remove oil and mill scale'],
                        ['step' => 'Mixing', 'value' => 'Base : hardener 9 : 1', 'note' => 'Mix by weight; induct 10 minutes'],
                        ['step' => 'Application', 'value' => 'DFT 60-80 um', 'note' => 'Spray or brush'],
                        ['step' => 'Curing', 'value' => 'Touch-dry 30 min at 25 C', 'note' => 'Recoat interval per spec'],
                        ['step' => 'Inspection', 'value' => 'Measure DFT', 'note' => 'Apply topcoat after approval'],
                    ],
                ],
            ],
            'polyurethane-topcoat-200' => [
                'name' => 'Polyurethane Topcoat PC-200',
                'summary' => 'Weatherable, gloss-retentive topcoat forming a multi-coat system with the epoxy primer.',
                'metadata' => [
                    'short_name' => 'Polyurethane Topcoat',
                    'tag' => 'Industrial Protective Coatings',
                    'mains' => ['Steel', 'Aluminum alloy'],
                    'key_params' => [
                        ['label' => 'Substrate', 'value' => 'Primed metal surface'],
                        ['label' => 'Mix ratio', 'value' => 'Base : hardener = 6 : 1'],
                        ['label' => 'Touch-dry', 'value' => 'About 40 minutes'],
                    ],
                    'params' => [
                        ['step' => 'Surface check', 'value' => 'Primer cured', 'note' => 'Clean, no oil'],
                        ['step' => 'Mixing', 'value' => 'Base : hardener 6 : 1', 'note' => 'Induct 10 minutes'],
                        ['step' => 'Application', 'value' => 'DFT 40-60 um', 'note' => 'Spray preferred'],
                        ['step' => 'Curing', 'value' => 'Touch-dry 40 min at 25 C', 'note' => 'Full cure 7 days'],
                    ],
                ],
            ],
        ] as $slug => $en) {
            if (isset($productEntities[$slug])) {
                $this->ensureEnglish($productEntities[$slug], $en);
            }
        }

        foreach ([
            'equipment-manufacturing' => [
                'name' => 'Equipment Manufacturing',
                'summary' => 'Coating durability, structural bonding strength and batch consistency.',
            ],
            'construction-infrastructure' => [
                'name' => 'Construction & Infrastructure',
                'summary' => 'Weatherability, waterproof sealing and on-site application.',
            ],
            'automotive-parts' => [
                'name' => 'Automotive Parts',
                'summary' => 'Heat resistance, bonding strength and production-line takt.',
            ],
        ] as $slug => $en) {
            if (isset($serviceEntities[$slug])) {
                $this->ensureEnglish($serviceEntities[$slug], $en);
            }
        }
    }

    private function relate(Entity $from, Entity $to, string $type, int $sortOrder = 0, ?array $metadata = null): void
    {
        $relation = EntityRelation::firstOrNew([
            'from_entity_id' => $from->id,
            'to_entity_id'   => $to->id,
            'relation_type'  => $type,
        ]);
        // 显式绑定站点：EntityRelation 的 saving 跨站校验早于 BelongsToSite 的 creating
        // 自动填充（saving → creating 事件顺序），不预设 site_id 会在保存瞬间被判为空站跨接。
        $relation->fill([
            'site_id'    => $from->site_id,
            'sort_order' => $sortOrder,
            'metadata'   => $metadata,
        ]);
        $relation->save();
    }

    /**
     * P-STEP 18F：为 zh 权威实体确保对应 en 翻译行（幂等）。
     * en 行共享 translation_group；共享列从 zh 复制，独立字段取 $en。
     */
    private function ensureEnglish(Entity $zh, array $en): Entity
    {
        $row = Entity::firstOrNew([
            'type'   => $zh->type,
            'slug'   => $en['slug'] ?? $zh->slug,
            'locale' => 'en',
        ]);
        $row->translation_group = $zh->translation_group;
        $row->fill([
            'name'         => $en['name'],
            'summary'      => $en['summary'] ?? null,
            'description'  => $en['description'] ?? $en['summary'] ?? null,
            'status'       => $zh->status,
            'published_at' => $zh->published_at,
            'metadata'     => isset($en['metadata'])
                ? array_merge(is_array($zh->metadata) ? $zh->metadata : [], $en['metadata'])
                : $zh->metadata,
            'sort_order'   => $zh->sort_order,
        ]);
        $row->save();

        return $row;
    }
}
