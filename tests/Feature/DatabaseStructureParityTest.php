<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SchemaInspector;
use Tests\TestCase;

/**
 * V1.4 · B：跨数据库结构一致性断言。
 * ---------------------------------------------------------------------------
 * 背景
 *   三种数据库（SQLite / MySQL / PostgreSQL）承载同一套业务模型，但**唯一约束的
 *   物理形态天然不同**：
 *     - MySQL 不支持 partial unique index，只能用 VIRTUAL 生成列 + 唯一索引；
 *     - SQLite / PostgreSQL 用 partial unique index（带 WHERE 谓词）。
 *   因此「列清单不同」不等于「语义不同」。本测试把约束拆成两层来断言：
 *
 *   1) **业务唯一键层**：逻辑上必须成立的唯一性（三库一致），用「物理列集」表达；
 *   2) **驱动专属形态层**：MySQL 的生成列形态单独断言，不混入业务唯一键清单。
 *
 * 2026-10-27 实测发现的真实漂移（本测试的由来）
 *   contents.slot 的唯一约束此前三库不一致：
 *     SQLite  UNIQUE(site_id, slot)                  （站点级）
 *     MySQL   contents_slot_locale_unique (site_id, slot, locale)
 *     PG      contents_slot_locale_unique (site_id, slot, locale)
 *   同一份数据在三种库上的写入结果不同。已由
 *   2026_10_27_000001_tighten_contents_slot_unique_to_site_scope 统一为站点级，
 *   并由本测试锁定，防止再次漂移。
 *
 * 断言强度
 *   本测试**只加不减**既有约束：全部为「期望存在」+「已修漂移形态不得复现」，
 *   没有任何跳过、放宽或按驱动弱化的分支。
 */
class DatabaseStructureParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 三库共有的业务唯一键（物理列集，已按字典序排序）。
     *
     * 列出的是**逻辑等价**的唯一性，而非物理实现名。列集相同的约束在三库上
     * 必须都存在；驱动专属的生成列形态见 driverSpecificUniqueKeys()。
     *
     * @return array<string, array<int, string>>
     */
    private function sharedUniqueKeys(): array
    {
        return [
            // 基础设施
            'cache.key'                => ['key'],
            'cache_locks.key'          => ['key'],
            'failed_jobs.uuid'         => ['uuid'],
            'password_reset_tokens.email' => ['email'],
            'sessions.id'              => ['id'],
            'job_batches.id'           => ['id'],

            // 站点与全局配置
            'sites.slug'               => ['slug'],
            'sites.domain'             => ['domain'],
            'settings.key'             => ['key', 'site_id'],

            // 内容
            'contents.site_slug_locale' => ['locale', 'site_id', 'slug'],
            'contents.site_slot'        => ['site_id', 'slot'],
            'contents.site_external_id' => ['external_id', 'site_id'],

            // 分类 / 分组 / 标签
            'categories.site_slug'     => ['site_id', 'slug'],
            'groups.site_slug'         => ['category_id', 'site_id', 'slug'],
            'tags.site_slug'           => ['site_id', 'slug'],

            // 实体与关系
            'entities.active_slug'     => ['active_slug', 'locale', 'site_id', 'type'],
            'entity_relations.tuple'   => ['from_entity_id', 'relation_type', 'site_id', 'to_entity_id'],
            'entity_tombstones.entity' => ['entity_id', 'site_id'],
            'content_entity.tuple'     => ['content_id', 'entity_id', 'relation_type'],
            'content_tag.tuple'        => ['content_id', 'tag_id'],

            // 事实 / 表单 / 询盘 / 菜单 / 跳转
            'facts.key_locale'         => ['key', 'locale', 'site_id'],
            'forms.site_slug'          => ['site_id', 'slug'],
            'inquiries.submission_id'  => ['submission_id'],
            'menus.site_key'           => ['key', 'site_id'],
            'redirects.site_path'      => ['from_path', 'site_id'],

            // 页面与检索
            'pages.site_slug_locale'   => ['locale', 'site_id', 'slug'],
            'search_documents.tuple'   => ['locale', 'resource_id', 'resource_type', 'site_id'],
        ];
    }

    /**
     * 驱动专属的唯一约束物理形态。
     *
     * 这些约束在逻辑上与 sharedUniqueKeys() 中的某些项等价，但物理形态不同：
     *   - MySQL：VIRTUAL 生成列 + 唯一索引（MySQL 无 partial index）
     *   - SQLite / PostgreSQL：partial unique index（列集直出）
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private function driverSpecificUniqueKeys(): array
    {
        return [
            // pages：绑定实体的页面在同站内唯一（entity_id 为空则不参与）
            'pages.entity_unique' => [
                'sqlite' => ['entity_id', 'site_id'],
                'pgsql'  => ['entity_id', 'site_id'],
                'mysql'  => ['entity_guard'],
            ],
            // pages：系统页面的 (site, system_key, locale) 唯一
            'pages.system_key_unique' => [
                'sqlite' => ['locale', 'site_id', 'system_key'],
                'pgsql'  => ['locale', 'site_id', 'system_key'],
                'mysql'  => ['system_key_guard'],
            ],
            // seo_metas：四类绑定各自唯一槽位
            'seo_metas.content_unique' => [
                'sqlite' => ['content_id', 'locale', 'site_id'],
                'pgsql'  => ['content_id', 'locale', 'site_id'],
                'mysql'  => ['content_guard'],
            ],
            'seo_metas.entity_unique' => [
                'sqlite' => ['entity_id', 'locale', 'site_id'],
                'pgsql'  => ['entity_id', 'locale', 'site_id'],
                'mysql'  => ['entity_guard'],
            ],
            'seo_metas.page_unique' => [
                'sqlite' => ['locale', 'page_id', 'site_id'],
                'pgsql'  => ['locale', 'page_id', 'site_id'],
                'mysql'  => ['page_guard'],
            ],
            'seo_metas.site_unique' => [
                'sqlite' => ['locale', 'site_id'],
                'pgsql'  => ['locale', 'site_id'],
                'mysql'  => ['site_guard'],
            ],
            // sites：全库最多一个默认站点
            'sites.is_default_unique' => [
                'sqlite' => ['is_default'],
                'pgsql'  => ['is_default'],
                'mysql'  => ['is_default_guard'],
            ],
        ];
    }

    /**
     * 三库共有的业务表（SQLite 另有 FTS5 影子表，属引擎差异，不在此列）。
     *
     * @return array<int, string>
     */
    private function sharedTables(): array
    {
        return [
            'cache', 'cache_locks', 'categories', 'content_entity', 'content_revisions',
            'content_tag', 'contents', 'entities', 'entity_relations', 'entity_tombstones',
            'fact_review_markers', 'facts', 'failed_jobs', 'form_fields', 'form_submissions',
            'forms', 'groups', 'inquiries', 'job_batches', 'jobs', 'media', 'menus',
            'migrations', 'page_blocks', 'pages', 'redirects', 'search_documents',
            'seo_metas', 'settings', 'sites', 'sync_logs', 'tags', 'users',
            'sessions', 'password_reset_tokens', 'audit_logs', 'banners',
        ];
    }

    /**
     * 取某表在当前驱动上的全部唯一列集（已排序去重）。
     *
     * @return array<int, array<int, string>>
     */
    private function uniqueSetsOf(string $table): array
    {
        $sets = [];

        foreach (SchemaInspector::uniqueIndexColumnSets($table) as $cols) {
            $cols = array_values(array_unique(array_map('strval', $cols)));
            sort($cols);
            $sets[] = $cols;
        }

        return array_values(array_unique($sets, SORT_REGULAR));
    }

    /**
     * DSP-001  三库共有的业务表必须全部存在。
     */
    public function test_dsp_001_shared_business_tables_exist(): void
    {
        $missing = [];

        foreach ($this->sharedTables() as $table) {
            if (! SchemaInspector::hasTable($table)) {
                $missing[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'DSP-001 失败：以下共有业务表在当前驱动（'.SchemaInspector::driver().'）缺失：'
            .implode(', ', $missing)
        );
    }

    /**
     * DSP-002  三库共有的业务唯一键必须全部存在。
     */
    public function test_dsp_002_shared_unique_constraints_are_present(): void
    {
        $driver = SchemaInspector::driver();

        foreach ($this->sharedUniqueKeys() as $label => $expected) {
            [$table] = explode('.', $label, 2);

            $this->assertTrue(
                SchemaInspector::hasTable($table),
                "DSP-002 失败：表 {$table} 不存在（唯一键 {$label} 无法校验）"
            );

            $sets = $this->uniqueSetsOf($table);

            $this->assertContains(
                $expected,
                $sets,
                "DSP-002 失败：{$driver} 上 {$label} 缺失。期望唯一列集 "
                .json_encode($expected).'，实际该表唯一列集为 '.json_encode($sets)
            );
        }
    }

    /**
     * DSP-003  驱动专属的唯一约束物理形态必须存在。
     *
     * 这些形态由「MySQL 生成列 / partial unique index」的实现差异造成，
     * 断言它们的物理列集，确保 MySQL 上的生成列没有被误删、SQLite / PG 上的
     * partial index 没有退化成普通索引。
     */
    public function test_dsp_003_driver_specific_unique_forms_are_present(): void
    {
        $driver = SchemaInspector::driver();

        foreach ($this->driverSpecificUniqueKeys() as $label => $perDriver) {
            [$table] = explode('.', $label, 2);

            $this->assertTrue(
                SchemaInspector::hasTable($table),
                "DSP-003 失败：表 {$table} 不存在（唯一键 {$label} 无法校验）"
            );

            $expected = $perDriver[$driver] ?? null;
            $this->assertNotNull(
                $expected,
                "DSP-003 失败：{$driver} 未登记 {$label} 的物理形态"
            );

            $sets = $this->uniqueSetsOf($table);

            $this->assertContains(
                $expected,
                $sets,
                "DSP-003 失败：{$driver} 上 {$label} 的物理形态缺失。期望唯一列集 "
                .json_encode($expected).'，实际该表唯一列集为 '.json_encode($sets)
            );
        }
    }

    /**
     * DSP-004  contents.slot 的唯一约束必须是站点级，且不得复现旧的漂移形态。
     *
     * 2026-10-27 修复前：MySQL / PG 为 (site_id, slot, locale)，SQLite 为 (site_id, slot)。
     * 本用例同时锁死两端：站点级形态必须在，且带 locale 的旧形态不得再出现。
     */
    public function test_dsp_004_contents_slot_unique_is_site_scoped(): void
    {
        $driver = SchemaInspector::driver();
        $sets = $this->uniqueSetsOf('contents');

        $this->assertContains(
            ['site_id', 'slot'],
            $sets,
            "DSP-004 失败：{$driver} 上 contents 缺少站点级唯一约束 UNIQUE(site_id, slot)。"
            .'实际唯一列集为 '.json_encode($sets)
        );

        $this->assertNotContains(
            ['locale', 'site_id', 'slot'],
            $sets,
            "DSP-004 失败：{$driver} 上 contents 仍存在旧的 locale 维度 slot 唯一约束"
            .' UNIQUE(site_id, slot, locale) —— 跨库语义漂移已复发。'
        );
    }

    /**
     * DSP-005  关键外键必须完好。
     *
     * 取值范围说明（2026-10-27 三库实测比对结论）
     *   以下外键在 SQLite / MySQL / PostgreSQL 上**都存在**，因此可以跨库断言。
     *   `content_entity`、`content_tag`、`pages` 三张表在**三库上均未设外键**
     *   （实测一致，属既有设计现状而非跨库漂移），故不在本用例的断言范围内 ——
     *   断言「它们没有外键」会把设计现状固化成契约，超出本次跨库一致性授权范围；
     *   该现状已记录在 V1.4 交付报告的「非漂移观察项」中。
     *
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function coreForeignKeys(): array
    {
        return [
            ['contents', 'site_id', 'sites'],
            ['entities', 'site_id', 'sites'],
            ['facts', 'site_id', 'sites'],
            ['seo_metas', 'site_id', 'sites'],
            ['seo_metas', 'content_id', 'contents'],
            ['seo_metas', 'entity_id', 'entities'],
            ['entity_relations', 'site_id', 'sites'],
            ['entity_relations', 'from_entity_id', 'entities'],
            ['entity_relations', 'to_entity_id', 'entities'],
        ];
    }

    public function test_dsp_005_core_foreign_keys_are_intact(): void
    {
        $driver = SchemaInspector::driver();

        foreach ($this->coreForeignKeys() as [$table, $column, $parent]) {
            $this->assertTrue(
                SchemaInspector::hasTable($table),
                "DSP-005 失败：表 {$table} 不存在"
            );

            $targets = array_map(
                static fn ($fk) => $fk->table,
                SchemaInspector::foreignKeys($table)
            );

            $this->assertContains(
                $parent,
                $targets,
                "DSP-005 失败：{$driver} 上 {$table}.{$column} → {$parent} 的外键缺失。"
                .'实际外键目标为 '.json_encode($targets)
            );
        }
    }

    /**
     * DSP-006  行为断言：同站同 slot 即便 locale 不同也必须被数据库拒绝。
     *
     * 这是唯一约束的**实际行为**验证，比结构断言更强：即使实现形态再变，
     * 只要业务不变量被破坏，用例就会失败。
     */
    public function test_dsp_006_same_slot_in_different_locale_is_rejected(): void
    {
        $siteId = $this->createSite('dsp-six');

        $this->insertContent($siteId, 'dsp-six-zh', 'zh-CN', 'shared-slot');

        $rejected = false;

        try {
            // 用独立事务包裹：PostgreSQL 上唯一冲突会把事务置为 aborted，
            // 交给 Laravel 的 savepoint 回滚，外层测试事务才能继续使用。
            DB::transaction(function () use ($siteId) {
                $this->insertContent($siteId, 'dsp-six-en', 'en-US', 'shared-slot');
            });
        } catch (UniqueConstraintViolationException $e) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'DSP-006 失败：'.SchemaInspector::driver().' 上同站同 slot（不同 locale）的第二条内容'
            .'未被拒绝 —— contents.slot 的站点级唯一约束已失效。'
        );

        $this->assertSame(
            1,
            DB::table('contents')->where('site_id', $siteId)->where('slot', 'shared-slot')->count(),
            'DSP-006 失败：冲突行不应落库'
        );
    }

    /**
     * DSP-007  行为断言：site_id 参与唯一键，不同站点可各自占用同一 slot。
     */
    public function test_dsp_007_same_slot_in_different_sites_is_allowed(): void
    {
        $siteA = $this->createSite('dsp-seven-a');
        $siteB = $this->createSite('dsp-seven-b');

        $this->insertContent($siteA, 'dsp-seven-a-zh', 'zh-CN', 'shared-slot');
        $this->insertContent($siteB, 'dsp-seven-b-zh', 'zh-CN', 'shared-slot');

        $this->assertSame(
            2,
            DB::table('contents')->where('slot', 'shared-slot')->count(),
            'DSP-007 失败：不同站点使用同一 slot 被错误拒绝 —— site_id 未参与唯一键。'
        );
    }

    /**
     * 建立一个测试站点。
     */
    private function createSite(string $slug): int
    {
        $now = now();

        return (int) DB::table('sites')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'domain' => $slug.'.test',
            'status' => 'active',
            'is_default' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 写入一条带 slot 的内容。
     */
    private function insertContent(int $siteId, string $slug, string $locale, string $slot): void
    {
        $now = now();

        DB::table('contents')->insert([
            'site_id' => $siteId,
            'type' => 'article',
            'title' => 'DSP '.$slug,
            'slug' => $slug,
            'status' => 'draft',
            'locale' => $locale,
            'slot' => $slot,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
