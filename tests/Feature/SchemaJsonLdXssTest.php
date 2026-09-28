<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Geo\SchemaBuilder;
use App\Support\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 20A P1 回归：SchemaBuilder::render 必须对 ld+json 输出启用 JSON_HEX_TAG，
 * 防止 schema 数据中的 </script> 突破 <script type="application/ld+json"> 块。
 */
class SchemaJsonLdXssTest extends TestCase
{
    use RefreshDatabase;

    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::where('slug', Site::DEFAULT_SLUG)->firstOrFail();
        $site->update([
            'name'   => 'Test Site',
            'domain' => 'example.com',
        ]);
        SiteContext::setSite($site);

        $this->schema = app(SchemaBuilder::class);
    }

    public function test_render_escapes_script_tags_to_prevent_ld_json_xss(): void
    {
        $payload = [
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'headline' => 'XSS</script><script>alert(1)</script>Test',
        ];
        $html = $this->schema->render([$payload]);

        // 原始 </script> 不得出现在 ld+json 块内部（应被转义为 \u003C/script\u003E）
        $this->assertStringNotContainsString(
            '</script><script>alert(1)</script>',
            $html,
            'ld+json 不得输出原始 </script>，防止突破脚本块形成存储型 XSS'
        );

        // 提取并验证 JSON 仍有效
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1], '应产出一个 ld+json 块');
        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            'XSS</script><script>alert(1)</script>Test',
            $decoded['headline'],
            'JSON 解码后原文应完整保留'
        );
    }

    public function test_render_with_organization_containing_script_payload(): void
    {
        // 用真实 organization schema 验证（含 name/description 字段）
        $org = $this->schema->organization();
        $org['name'] = 'Evil</script><script>alert(2)</script>Org';
        $html = $this->schema->render([$org]);

        $this->assertStringNotContainsString('</script><script>alert(2)</script>', $html);

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $decoded = json_decode($m[1], true);
        $this->assertSame('Evil</script><script>alert(2)</script>Org', $decoded['name']);
    }
}
