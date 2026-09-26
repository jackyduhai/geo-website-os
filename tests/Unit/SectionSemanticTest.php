<?php

namespace Tests\Unit;

use App\Models\Entity;
use App\Support\Blocks\BlockRegistry;
use App\Support\Blocks\SectionSemantic;
use Tests\TestCase;

/**
 * P-STEP 18L-4b-1 / TD-132：SectionSemantic 单元测试。
 * 验证受控词表、block 默认语义映射、Context entity 细化与 fail-closed 校验。
 */
class SectionSemanticTest extends TestCase
{
    private function type(string $key)
    {
        return BlockRegistry::get($key);
    }

    public function test_hero_attributes(): void
    {
        $this->assertSame(
            'data-section="hero" data-purpose="brand" data-entity="Organization"',
            SectionSemantic::attributes($this->type('hero'))
        );
    }

    public function test_cta_includes_conversion(): void
    {
        $attrs = SectionSemantic::attributes($this->type('cta'));
        $this->assertStringContainsString('data-section="conversion"', $attrs);
        $this->assertStringContainsString('data-purpose="conversion"', $attrs);
        $this->assertStringContainsString('data-entity="Organization"', $attrs);
        $this->assertStringContainsString('data-conversion="contact"', $attrs);
    }

    public function test_breadcrumb_only_navigation_section(): void
    {
        $this->assertSame(
            'data-section="navigation"',
            SectionSemantic::attributes($this->type('breadcrumb'))
        );
    }

    public function test_entity_hero_omits_entity_without_context(): void
    {
        $this->assertStringNotContainsString(
            'data-entity',
            SectionSemantic::attributes($this->type('entity_hero'))
        );
    }

    public function test_entity_hero_resolves_product_from_context_entity(): void
    {
        $product = new Entity();
        $product->type = Entity::TYPE_PRODUCT;

        $attrs = SectionSemantic::attributes($this->type('entity_hero'), ['entity' => $product]);
        $this->assertStringContainsString('data-entity="Product"', $attrs);
        $this->assertStringContainsString('data-section="hero"', $attrs);
        $this->assertStringContainsString('data-conversion="contact"', $attrs);
    }

    public function test_entity_hero_resolves_service_from_context_entity(): void
    {
        $service = new Entity();
        $service->type = Entity::TYPE_SERVICE;

        $attrs = SectionSemantic::attributes($this->type('entity_hero'), ['entity' => $service]);
        $this->assertStringContainsString('data-entity="Service"', $attrs);
    }

    public function test_entity_specs_resolves_service_from_entity_type_context(): void
    {
        $attrs = SectionSemantic::attributes(
            $this->type('entity_specifications'),
            ['entityType' => 'Service']
        );
        $this->assertStringContainsString('data-entity="Service"', $attrs);
    }

    public function test_validate_declared_accepts_full_valid_set(): void
    {
        $this->assertSame([], SectionSemantic::validateDeclared([
            'section' => 'product',
            'purpose' => 'comparison',
            'entity' => 'Product',
            'conversion' => 'contact',
        ]));
    }

    public function test_validate_declared_empty_is_valid(): void
    {
        $this->assertSame([], SectionSemantic::validateDeclared([]));
        $this->assertSame([], SectionSemantic::validateDeclared(null));
    }

    public function test_validate_declared_rejects_each_invalid_value(): void
    {
        $this->assertCount(1, SectionSemantic::validateDeclared(['section' => 'bogus']));
        $this->assertCount(1, SectionSemantic::validateDeclared(['purpose' => 'sell']));
        $this->assertCount(1, SectionSemantic::validateDeclared(['entity' => 'Widget']));
        $this->assertCount(1, SectionSemantic::validateDeclared(['conversion' => 'click']));
        $this->assertCount(4, SectionSemantic::validateDeclared([
            'section' => 'x', 'purpose' => 'x', 'entity' => 'x', 'conversion' => 'x',
        ]));
    }

    public function test_all_built_in_defaults_are_valid(): void
    {
        $this->assertSame([], SectionSemantic::validateDefaults());
    }
}
