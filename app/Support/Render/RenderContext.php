<?php

namespace App\Support\Render;

use App\Models\Site;
use App\Services\Seo\SeoResult;
use App\Support\Blocks\BlockContract;
use App\Support\Templates\TemplateDefinition;

/**
 * 统一渲染上下文契约（P-STEP 18G-2，路线 A）。
 * --------------------------------------------------
 * Page / Entity（Listing 在 2b）三类资源实现本契约；渲染、SEO、Schema、URL、
 * Cache 均经由同一公共管线（CompositionRenderer），控制器不再各自手工拼 SEO。
 */
interface RenderContext
{
    public function site(): Site;

    public function locale(): string;

    /** 资源类型：'page' | 'entity'（'listing' 于 2b）。 */
    public function resourceType(): string;

    public function template(): TemplateDefinition;

    public function seo(): SeoResult;

    /** 是否含首屏 Hero（用于布局间距 / 首屏判定）。 */
    public function hasHero(): bool;

    /**
     * 面包屑（不含结构化包装；name 供 SchemaBuilder::breadcrumb）。
     * @return array<int,array{name:string,url:string}>
     */
    public function crumbs(): array;

    /**
     * 二级导航（产品体系等）；无则 null。
     * @return array{items:array<int,mixed>,active:string|null}|null
     */
    public function subnav(): ?array;

    /**
     * 指定槽位的可渲染块（已按固定 / 默认 / override 裁定与排序）。
     * @return array<int,BlockContract>
     */
    public function blocksForSlot(string $slot): array;

    /**
     * 传给 block 渲染器的视图变量（Entity / Catalog / 关联数据等）。
     * @return array<string,mixed>
     */
    public function viewContext(): array;

    /**
     * 结构化数据节点（JSON-LD）。
     * @return array<int,array<string,mixed>>
     */
    public function schemaNodes(): array;
}
