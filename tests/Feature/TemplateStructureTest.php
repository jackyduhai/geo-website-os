<?php

namespace Tests\Feature;

use App\Support\Templates\TemplatePackageManager;
use Tests\TestCase;

/**
 * 模板结构摘要（RC-11 F）。
 *
 * 背景：manifest只声明「什么行业」，单看它无法区分 8 个出厂包——
 * 真正决定页面形态的是 recipes/*.json 的 block 组合与顺序。
 * 后台模板卡片直接展示这份摘要（预览截图尚未就位时的唯一区分依据），
 * 因此「摘要与包内实际 recipe 一致」必须被锁住。
 */
class TemplateStructureTest extends TestCase
{
    public function test_every_out_of_the_box_pack_exposes_structure(): void
    {
        $packs = TemplatePackageManager::all();
        $this->assertNotEmpty($packs, '出厂应至少有一个模板包');

        $matrix = TemplatePackageManager::structureMatrix();
        $this->assertSame(
            array_keys($packs),
            array_keys($matrix),
            'structureMatrix 必须覆盖 all() 的全部包'
        );

        foreach ($matrix as $id => $st) {
            $this->assertGreaterThan(0, $st['recipe_count'], "{$id} 至少应有 1 个 recipe");
            $this->assertGreaterThan(0, $st['block_total'], "{$id} 至少应有 1 个 block");
            $this->assertNotEmpty($st['page_list'], "{$id} 应列出页面清单");
        }
    }

    /**
     * 8 个出厂包的首页 block 组合**必须两两不同**。
     *
     * 这是结构摘要的价值前提：若组合重复，卡片无法区分，
     * 功能等于没做（RC-11 原本 8 个包的preview 图哈希全同，正是这个问题）。
     */
    public function test_out_of_the_box_packs_have_distinct_homepage_structures(): void
    {
        $matrix = TemplatePackageManager::structureMatrix();

        $signatures = [];
        foreach ($matrix as $id => $st) {
            $this->assertNotEmpty(
                $st['homepage_blocks'],
                "{$id} 应有 homepage recipe"
            );

            $seq = array_map(static fn (array $p): string => $p[0], $st['homepage_blocks']);
            $signature = implode(' > ', $seq);
            if (isset($signatures[$signature])) {
                $this->fail(
                    "模板「{$id}」的首页结构与「{$signatures[$signature]}」重复"
                    . '（' . $signature . '），结构摘要将无法区分它们'
                );
            }
            $signatures[$signature] = $id;
        }

        $this->assertSame(
            count($matrix),
            count($signatures),
            '每个出厂包都应有唯一的首页结构'
        );
    }

    /**
     * label 必须来自config/blocks.php 注册表，不能是裸 key。
     *
     * 裸 key（如 feature_grid）只有模板作者懂，运营者看不懂就失去意义。
     * 未注册类型允许回落为 key —— 那暴露问题，比静默隐藏好。
     */
    public function test_block_labels_come_from_registry(): void
    {
        $labels = TemplatePackageManager::blockLabels();
        $this->assertNotEmpty($labels, '应能从 config/blocks.php 读到 label');

        // 随机抽一个已知类型验证：homepage 首block 必为 hero
        $matrix = TemplatePackageManager::structureMatrix();
        $first  = $matrix[array_key_first($matrix)] ?? null;
        $this->assertNotNull($first);
        $this->assertSame('hero', $first['homepage_blocks'][0][0]);
        $this->assertSame(
            $labels['hero'] ?? 'hero',
            $first['homepage_blocks'][0][1],
            'hero 的 label 应与注册表一致'
        );
        $this->assertNotSame('hero', $first['homepage_blocks'][0][1], 'label 不应是裸 key');
    }

    public function test_unknown_pack_returns_empty_structure(): void
    {
        $st = TemplatePackageManager::structure('no-such-pack-xyz');

        $this->assertSame(0, $st['recipe_count']);
        $this->assertSame([], $st['page_list']);
        $this->assertSame([], $st['homepage_blocks']);
        $this->assertSame(0, $st['block_total']);
    }
}
