<?php

namespace Tests\Feature;

use App\Models\PageBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PageBlock cfg() 容错契约测试（Phase 2C.1，关闭 MEDIA-BLOCK-03 空内容分支）。
 */
class PageBlockModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cfg_empty_content_returns_empty_array(): void
    {
        $block = new PageBlock(['content' => '']);

        $this->assertSame([], $block->cfg());
    }

    public function test_cfg_whitespace_only_content_returns_empty_array(): void
    {
        $block = new PageBlock(['content' => '   ']);

        $this->assertSame([], $block->cfg());
    }

    public function test_cfg_invalid_json_returns_empty_array(): void
    {
        $block = new PageBlock(['content' => 'not valid json {']);

        $this->assertSame([], $block->cfg());
    }

    public function test_cfg_valid_json_returns_decoded_array(): void
    {
        $block = new PageBlock(['content' => '{"variant":"split","title":"x"}']);

        $this->assertSame(['variant' => 'split', 'title' => 'x'], $block->cfg());
    }
}
