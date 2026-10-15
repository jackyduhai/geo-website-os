<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentEntity;
use App\Models\Entity;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B3-1 后台术语精简（第 3 批 · 工作包 1：内容/实体表单）。
 *
 * 覆盖审计项：C005（about/mention 服务端行 + JS 动态行）、C006（关联实体区块标题）、
 * C008（实体下拉类型前缀）、C009（纬度/经度）、C010（产品导语）、C011（资料类型）、
 * C013（媒体下拉显示 alt/original_name），以及 G3 口径（内容类型 vs 资料类型）。
 *
 * 核心契约：本批只改展示层。所有受控英文枚举（about/mention、实体 type、
 * asset_type、媒体 id）的 option value 必须保持英文/数字不变，本测试逐处断言。
 */
class Batch3FormTerminologyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    private function contentDraft(string $slug = 'b3-1-content'): Content
    {
        return Content::create([
            'site_id' => 1, 'type' => 'article',
            'slug' => $slug, 'title' => 'B3-1 测试内容',
            'status' => 'draft', 'locale' => 'zh-CN',
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function makeEntity(string $type, string $slug, string $name, array $attributes = []): Entity
    {
        return Entity::create(array_merge([
            'site_id' => 1, 'type' => $type, 'slug' => $slug, 'name' => $name,
            'status' => 'published', 'locale' => 'zh-CN',
        ], $attributes));
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function makeMedia(string $mime, string $path, string $originalName, array $attributes = []): Media
    {
        return Media::create(array_merge([
            'site_id' => 1, 'disk' => 'public', 'path' => $path,
            'original_name' => $originalName, 'mime' => $mime, 'size' => 1234,
        ], $attributes));
    }

    // ---------------------------------------------------------------
    // 内容编辑页：C006 / G3
    // ---------------------------------------------------------------

    public function test_content_edit_shows_content_type_label_and_chinese_entity_section_title(): void
    {
        $content = $this->contentDraft();

        $this->actingAs($this->admin)->get("/admin/contents/{$content->id}/edit")
            ->assertOk()
            // G3：内容记录的 article/page 类型称「内容类型」
            ->assertSee('内容类型', false)
            // C006：据实修正为实际可关联的四类（产品/服务/组织/主题）
            ->assertSee('关联实体（产品 / 服务 / 组织 / 主题）', false)
            ->assertDontSee('Product / Industry / Scenario', false);
    }

    // ---------------------------------------------------------------
    // 内容编辑页：C008（服务端行 + JS 动态行）
    // ---------------------------------------------------------------

    public function test_content_edit_entity_options_use_chinese_type_label_but_keep_id_value(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'b3-1-prod', 'B3-1 涂层设备');
        $this->makeEntity(Entity::TYPE_SERVICE, 'b3-1-svc', 'B3-1 涂装服务');
        $this->makeEntity(Entity::TYPE_ORGANIZATION, 'b3-1-org', 'B3-1 晨光集团');
        $this->makeEntity(Entity::TYPE_TOPIC, 'b3-1-topic', 'B3-1 新能源主题');
        $content = $this->contentDraft();

        $html = $this->actingAs($this->admin)->get("/admin/contents/{$content->id}/edit")
            ->assertOk()->getContent();

        // JS 动态行模板 foreach 渲染全部可关联实体，显示「中文类型 · 名称」
        $this->assertStringContainsString('产品 · B3-1 涂层设备', $html);
        $this->assertStringContainsString('服务/场景 · B3-1 涂装服务', $html);
        $this->assertStringContainsString('组织 · B3-1 晨光集团', $html);
        $this->assertStringContainsString('主题 · B3-1 新能源主题', $html);
        // 不再出现裸英文类型前缀
        foreach (['[product]', '[service]', '[organization]', '[topic]'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }
        // option value 仍是实体 id（JS 模板行）
        $this->assertMatchesRegularExpression(
            '#<option value="'.$product->id.'">产品 · B3-1 涂层设备</option>#u',
            $html
        );
    }

    // ---------------------------------------------------------------
    // 内容编辑页：C005（服务端行 + JS 动态行）
    // ---------------------------------------------------------------

    public function test_content_edit_relation_labels_chinese_in_server_and_js_but_english_value(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'b3-1-about-prod', 'B3-1 主角产品');
        $content = $this->contentDraft('b3-1-relations');
        ContentEntity::create([
            'site_id' => 1, 'content_id' => $content->id,
            'entity_id' => $product->id, 'relation_type' => 'about',
        ]);

        $html = $this->actingAs($this->admin)->get("/admin/contents/{$content->id}/edit")
            ->assertOk()->getContent();

        // 服务端回填行 + JS 动态行模板各出现一次
        $this->assertSame(2, substr_count($html, 'about 核心关联（正文主角）'));
        $this->assertSame(2, substr_count($html, 'mention 顺带提及'));
        // value 仍为英文受控枚举
        $this->assertMatchesRegularExpression('#<option value="about"[^>]*>#', $html);
        $this->assertMatchesRegularExpression('#<option value="mention"[^>]*>#', $html);
        // 旧的错误括注（含系统不存在的「行业」类型）已消失
        $this->assertStringNotContainsString('核心产品/行业/场景', $html);
        // 服务端回填行的实体下拉同样是中文类型
        $this->assertStringContainsString('产品 · B3-1 主角产品', $html);
    }

    public function test_content_relation_submit_still_uses_english_values_and_persists(): void
    {
        // 展示层中文化不得改变提交契约：relation_type 仍以 about/mention 英文落库
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'b3-1-submit-prod', 'B3-1 提交产品');
        $content = $this->contentDraft('b3-1-submit');

        $this->actingAs($this->admin)->put("/admin/contents/{$content->id}", [
            'type' => 'article',
            'title' => $content->title,
            'slug' => $content->slug,
            'entity_links' => [
                ['entity_id' => $product->id, 'relation_type' => 'mention'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('content_entity', [
            'content_id' => $content->id,
            'entity_id' => $product->id,
            'relation_type' => 'mention',
        ]);
    }

    // ---------------------------------------------------------------
    // 实体编辑页：C010（产品导语）
    // ---------------------------------------------------------------

    public function test_product_edit_shows_chinese_tagline_label(): void
    {
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'b3-1-tagline', 'B3-1 导语产品');

        $this->actingAs($this->admin)->get("/admin/entities/{$product->id}/edit")
            ->assertOk()
            ->assertSee('产品导语（一句话卖点）', false)
            ->assertDontSee('（tagline）', false);
    }

    // ---------------------------------------------------------------
    // 实体编辑页：C009（纬度/经度）
    // ---------------------------------------------------------------

    public function test_location_edit_shows_chinese_latitude_longitude_labels(): void
    {
        $location = $this->makeEntity(Entity::TYPE_LOCATION, 'b3-1-loc', 'B3-1 沈阳工厂');

        $html = $this->actingAs($this->admin)->get("/admin/entities/{$location->id}/edit")
            ->assertOk()->getContent();

        $this->assertStringContainsString('纬度', $html);
        $this->assertStringContainsString('经度', $html);
        $this->assertStringNotContainsString('纬度 latitude', $html);
        $this->assertStringNotContainsString('经度 longitude', $html);
        // 提交键 name 仍为英文（数据契约不变）
        $this->assertStringContainsString('name="loc_latitude"', $html);
        $this->assertStringContainsString('name="loc_longitude"', $html);
    }

    // ---------------------------------------------------------------
    // 实体编辑页：C011 / G3（资料类型：中文显示，英文 value）
    // ---------------------------------------------------------------

    public function test_download_asset_edit_shows_chinese_asset_type_options_with_english_value(): void
    {
        $asset = $this->makeEntity(Entity::TYPE_DOWNLOAD_ASSET, 'b3-1-asset', 'B3-1 产品手册');

        $html = $this->actingAs($this->admin)->get("/admin/entities/{$asset->id}/edit")
            ->assertOk()->getContent();

        // G3：下载资料的类型称「资料类型」
        $this->assertStringContainsString('资料类型', $html);
        $expected = [
            'datasheet' => '数据手册',
            'manual' => '使用手册',
            'certificate' => '资质证书',
            'whitepaper' => '白皮书',
            'brochure' => '宣传册',
        ];
        foreach ($expected as $english => $chinese) {
            $this->assertMatchesRegularExpression(
                '#<option value="'.$english.'"[^>]*>'.preg_quote($chinese, '#').'（'.$english.'）</option>#u',
                $html,
                "资料类型 {$english} 应显示中文且 value 保持英文"
            );
        }
    }

    public function test_download_asset_type_submit_persists_english_value(): void
    {
        $asset = $this->makeEntity(Entity::TYPE_DOWNLOAD_ASSET, 'b3-1-asset-submit', 'B3-1 提交手册');

        $this->actingAs($this->admin)->put("/admin/entities/{$asset->id}", [
            'type' => 'download_asset',
            'name' => $asset->name,
            'slug' => $asset->slug,
            'status' => 'published',
            'asset_type' => 'whitepaper',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertSame('whitepaper', $asset->metadata['type'] ?? null);
    }

    // ---------------------------------------------------------------
    // 实体编辑页：C013（图片媒体下拉：alt → original_name → basename）
    // ---------------------------------------------------------------

    public function test_entity_image_selects_prefer_alt_then_original_name_but_keep_id_value(): void
    {
        $withAlt = $this->makeMedia(
            'image/png', '2026/10/b31hash-with-alt.png', '原始文件名-带alt.png',
            ['alt' => 'B31 卡片图替代文本', 'width' => 100, 'height' => 100]
        );
        $noAlt = $this->makeMedia(
            'image/jpeg', '2026/10/b31hash-no-alt.jpg', 'B31 原始名无替代文本.jpg',
            ['alt' => null, 'width' => 100, 'height' => 100]
        );
        $product = $this->makeEntity(Entity::TYPE_PRODUCT, 'b3-1-media-prod', 'B3-1 图片产品');

        $html = $this->actingAs($this->admin)->get("/admin/entities/{$product->id}/edit")
            ->assertOk()->getContent();

        // 有 alt 显示 alt
        $this->assertStringContainsString('B31 卡片图替代文本', $html);
        // 无 alt 显示 original_name
        $this->assertStringContainsString('B31 原始名无替代文本.jpg', $html);
        // 不再暴露内部存储路径 basename
        $this->assertStringNotContainsString('b31hash-with-alt.png', $html);
        $this->assertStringNotContainsString('b31hash-no-alt.jpg', $html);
        // value 仍为媒体 id（卡片图与 OG 图两个下拉各出现一次；兼容 @selected 渲染的属性位）
        $this->assertSame(2, preg_match_all('#<option value="'.$withAlt->id.'"[^>]*>#', $html));
        $this->assertSame(2, preg_match_all('#<option value="'.$noAlt->id.'"[^>]*>#', $html));
    }

    // ---------------------------------------------------------------
    // 实体编辑页：C013（下载资料文件下拉，非图片）
    // ---------------------------------------------------------------

    public function test_download_asset_doc_select_prefers_alt_then_original_name(): void
    {
        $doc = $this->makeMedia(
            'application/pdf', '2026/10/b31hash-manual.pdf', 'B31 工业储能数据手册.pdf',
            ['alt' => 'B31 手册替代文本']
        );
        $asset = $this->makeEntity(Entity::TYPE_DOWNLOAD_ASSET, 'b3-1-asset-doc', 'B3-1 资料实体');

        $html = $this->actingAs($this->admin)->get("/admin/entities/{$asset->id}/edit")
            ->assertOk()->getContent();

        $this->assertStringContainsString('B31 手册替代文本', $html);
        $this->assertStringNotContainsString('b31hash-manual.pdf', $html);
        $this->assertMatchesRegularExpression(
            '#<option value="'.$doc->id.'"[^>]*>\s*B31 手册替代文本\s*</option>#u',
            $html
        );
    }
}
