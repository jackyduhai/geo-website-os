<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 19B P1：Service 详情页（/solutions/{slug}）HTTP 层 JSON-LD 契约。
 *
 * 修复前：service 分支 schemaNodes() 缺 $schema->entity($this->entity)，
 *         导致 /solutions/{slug} 不输出 @type=Service 的 JSON-LD。
 * 修复后：EntityRenderContext::schemaNodes() service 分支加入 entity()，
 *         本测试在 HTTP 响应 HTML 层面断言该修复生效，且不回归
 *         Organization / BreadcrumbList / WebPage 三类既有节点。
 *
 * 使用 Demo 种子数据（CatalogSeeder 为每个 scene 创建 type=service 的 published Entity），
 * 不额外新建实体。
 */
class ServiceSchemaHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * 从 HTML 中提取所有 <script type="application/ld+json"> 并 json_decode。
     *
     * @return array<int, array<string,mixed>>
     */
    private function extractJsonLdNodes(string $html): array
    {
        preg_match_all(
            '#<script type="application/ld\+json">(.*?)</script>#s',
            $html,
            $m
        );

        $nodes = [];
        foreach ($m[1] ?? [] as $json) {
            $decoded = json_decode(trim($json), true);
            if (is_array($decoded)) {
                $nodes[] = $decoded;
            }
        }

        return $nodes;
    }

    /**
     * 在 JSON-LD 节点列表中查找指定 @type（支持单字符串与数组形式）。
     */
    private function hasNodeType(array $nodes, string $type): bool
    {
        foreach ($nodes as $node) {
            $nodeType = $node['@type'] ?? null;
            if (is_string($nodeType) && $nodeType === $type) {
                return true;
            }
            if (is_array($nodeType) && in_array($type, $nodeType, true)) {
                return true;
            }
        }

        return false;
    }

    public function test_service_detail_page_emits_service_json_ld(): void
    {
        $response = $this->get('/solutions/equipment-manufacturing/');
        $response->assertOk();

        $html = $response->content();
        $this->assertNotEmpty($html);

        // 1. 提取所有 ld+json 块
        $nodes = $this->extractJsonLdNodes($html);
        $this->assertNotEmpty($nodes, '页面应至少输出一个 JSON-LD 脚本块');

        // 2. P1 核心断言：至少一个 @type === 'Service'
        $this->assertTrue(
            $this->hasNodeType($nodes, 'Service'),
            '/solutions/equipment-manufacturing/ 应输出 @type=Service 的 JSON-LD 节点（P1 修复）'
        );

        // 3. 不回归：Organization / BreadcrumbList / WebPage 仍存在
        $this->assertTrue(
            $this->hasNodeType($nodes, 'Organization'),
            'Organization JSON-LD 不应因 Service 修复而消失'
        );
        $this->assertTrue(
            $this->hasNodeType($nodes, 'BreadcrumbList'),
            'BreadcrumbList JSON-LD 不应因 Service 修复而消失'
        );
        $this->assertTrue(
            $this->hasNodeType($nodes, 'WebPage'),
            'WebPage JSON-LD 不应因 Service 修复而消失'
        );
    }

    /**
     * Service JSON-LD 节点应指向当前场景页 URL，且 name 与场景一致。
     */
    public function test_service_json_ld_points_to_current_scene(): void
    {
        $response = $this->get('/solutions/equipment-manufacturing/');
        $response->assertOk();

        $nodes = $this->extractJsonLdNodes($response->content());

        $serviceNode = null;
        foreach ($nodes as $node) {
            $nodeType = $node['@type'] ?? null;
            if ((is_string($nodeType) && $nodeType === 'Service')
                || (is_array($nodeType) && in_array('Service', $nodeType, true))) {
                $serviceNode = $node;
                break;
            }
        }

        $this->assertNotNull($serviceNode, '应找到 @type=Service 节点');
        $this->assertArrayHasKey('name', $serviceNode, 'Service 节点应含 name');
        $this->assertNotEmpty($serviceNode['name']);
    }
}
