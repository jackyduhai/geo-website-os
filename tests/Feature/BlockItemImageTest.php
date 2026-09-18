<?php

namespace Tests\Feature;

use App\Models\PageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 首页装修：条目自定义图片/图标上传 → 落库 → 前台优先用图；车间/流程区块可被后台覆盖。
 */
class BlockItemImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_item_custom_image_is_stored_and_rendered_ahead_of_icon(): void
    {
        $cap = PageBlock::where('type', 'capabilities')->firstOrFail();

        // 文件内嵌在 data 的同名层级，Laravel 会自动抽取到文件袋（与后台 multipart 等价）
        $this->actingAs($this->admin)->put("/admin/blocks/{$cap->id}", [
            'sort' => $cap->sort,
            'is_active' => 1,
            'items' => [
                ['icon' => 'flame', 'title' => '带图能力', 'text' => '说明',
                 'image_file' => UploadedFile::fake()->image('cap.png', 96, 96)],
            ],
        ])->assertRedirect();

        $items = $cap->fresh()->items();
        $this->assertNotEmpty($items[0]['image'] ?? '');
        $this->assertStringContainsString('storage/blocks', $items[0]['image']);

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('feat-media-img', $html);
        $this->assertStringContainsString('带图能力', $html);
    }

    public function test_existing_item_image_is_kept_without_file_and_removed_when_checked(): void
    {
        $cap = PageBlock::where('type', 'capabilities')->firstOrFail();

        $this->actingAs($this->admin)->put("/admin/blocks/{$cap->id}", [
            'sort' => $cap->sort, 'is_active' => 1,
            'items' => [['icon' => 'gear', 'title' => '保留图', 'text' => '',
                'image_file' => UploadedFile::fake()->image('keep.png')]],
        ]);
        $url = $cap->fresh()->items()[0]['image'];
        $this->assertNotEmpty($url);

        // 不传文件、回传隐藏域 image => 原图保留
        $this->actingAs($this->admin)->put("/admin/blocks/{$cap->id}", [
            'sort' => $cap->sort, 'is_active' => 1,
            'items' => [['icon' => 'gear', 'title' => '保留图', 'text' => '', 'image' => $url]],
        ]);
        $this->assertSame($url, $cap->fresh()->items()[0]['image']);

        // 勾选移除 => 不再写 image 键
        $this->actingAs($this->admin)->put("/admin/blocks/{$cap->id}", [
            'sort' => $cap->sort, 'is_active' => 1,
            'items' => [['icon' => 'gear', 'title' => '保留图', 'text' => '', 'image' => $url, 'image_remove' => 1]],
        ]);
        $this->assertArrayNotHasKey('image', $cap->fresh()->items()[0]);
    }

    public function test_workshops_and_steps_blocks_override_facts_defaults(): void
    {
        $ws = PageBlock::where('type', 'workshops')->firstOrFail();
        $this->actingAs($this->admin)->put("/admin/blocks/{$ws->id}", [
            'sort' => $ws->sort, 'is_active' => 1,
            'items' => [['icon' => 'factory', 'title' => '自定义车间X', 'text' => '自定义描述']],
        ])->assertRedirect();
        $this->assertStringContainsString('自定义车间X', $this->get('/')->getContent());

        $steps = PageBlock::where('type', 'steps')->firstOrFail();
        $this->actingAs($this->admin)->put("/admin/blocks/{$steps->id}", [
            'sort' => $steps->sort, 'is_active' => 1,
            'items' => [['title' => '自定义流程一', 'text' => '流程描述']],
        ])->assertRedirect();
        $this->assertStringContainsString('自定义流程一', $this->get('/')->getContent());
    }
}
