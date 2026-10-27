<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V1.4 · B：把 contents.slot 的唯一约束在三种数据库上统一为「站点级」。
 * ---------------------------------------------------------------------------
 * 问题（跨库行为不一致，实测发现）
 *   - SQLite：UNIQUE(site_id, slot)                     → 同站同 slot 全站仅一行
 *   - MySQL ：contents_slot_locale_unique (site_id, slot, locale)
 *   - PG    ：contents_slot_locale_unique (site_id, slot, locale)
 *   → MySQL / PostgreSQL 允许「同站同 slot、不同 locale 各存一行」，
 *     SQLite 不允许。同一套数据在三种库上的写入结果不同，属于跨库语义漂移。
 *
 * 为什么以「站点级」为准（而不是反过来放宽 SQLite）
 *   1) 项目既有测试契约已锁定：MigrationRollbackFidelityTest MRF-006 断言
 *      2026_10_02_000001 的重建分支必须包含 UNIQUE(site_id, slot)；
 *      MRF-005 只要求 **slug** 含 locale，slot 不在其列。
 *   2) 应用层语义同向：contents.slot 是「叙事插槽」（结构化页面的可运营槽位），
 *      ContentSeeder 用 updateOrCreate(['slot' => 'about.profile']) 写入，
 *      匹配条件只有 slot、没有 locale —— 即站点级唯一。
 *      slot 与 slug 语义不同：slug 是可翻译资源的 URL 标识（必须含 locale），
 *      slot 是站点级结构槽位（同一槽位全站只有一条）。
 *   3) 收紧是**增强**约束、消除跨库漂移，不触碰任何业务判定逻辑。
 *
 * 存量数据处理
 *   收紧前先探测「同站同 slot 多行」。若存在（只可能来自 MySQL / PostgreSQL 的
 *   旧宽松约束），本迁移**中止并报错**，绝不静默删数据或静默改写；
 *   处置指引见异常消息。SQLite 侧不可能出现该冲突（约束本就更严）。
 *
 * SQLite 分支
 *   SQLite 自 2026_10_02_000001 起已经是 UNIQUE(site_id, slot)，无需变更。
 *   这里只做**校验**：若不符合预期则报错，绝不静默放过——避免把
 *   「某个库的结构漂移」伪装成迁移成功。
 */
return new class extends Migration
{
    /** 旧索引名（MySQL / PostgreSQL 上的 locale 维度 slot 唯一索引）。 */
    private const OLD_INDEX = 'contents_slot_locale_unique';

    /** 新索引名（站点级 slot 唯一索引）。 */
    private const NEW_INDEX = 'contents_site_id_slot_unique';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertSqliteAlreadySiteScoped();

            return;
        }

        $this->assertNoSlotConflicts('收紧');

        $this->dropUniqueObject($driver, self::OLD_INDEX);
        $this->createNewIndex();
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite 侧本迁移未改动结构（up 为 no-op），回滚同样 no-op。
            return;
        }

        // 回滚 = 放宽约束（加回 locale 维度）。放宽前若有同站同 slot 多行，
        // 加回旧索引会失败；先探测并给出可执行的错误信息，而不是让 DB 报晦涩的
        // unique violation。
        $this->assertNoSlotConflicts('回滚');

        $this->dropUniqueObject($driver, self::NEW_INDEX);

        Schema::table('contents', function (Blueprint $table) {
            $table->unique(['site_id', 'slot', 'locale'], self::OLD_INDEX);
        });
    }

    /**
     * 删除 contents 表上的一个唯一约束 / 唯一索引。
     *
     * 三种物理形态，三种删除方式（实测踩坑记录）：
     *   - MySQL    ：`$table->unique()` 生成的是 UNIQUE INDEX（MySQL 无「约束」对象），
     *                删除语句为 DROP INDEX <name> ON contents。
     *   - PG       ：`$table->unique()` 生成的是**唯一约束**（contype='u'），
     *                其背后的索引被约束依赖，直接 DROP INDEX 会报
     *                2BP01「cannot drop index ... because constraint ... requires it」，
     *                必须先 ALTER TABLE ... DROP CONSTRAINT。
     *   - PG partial：partial unique index（带 WHERE）不可能是约束，只能 DROP INDEX。
     *                本迁移涉及的两个索引都不是 partial，但这里仍按实际探测决定，
     *                避免再次依赖「推断」。
     */
    private function dropUniqueObject(string $driver, string $name): void
    {
        if ($driver === 'mysql') {
            if (! $this->mysqlIndexExists($name)) {
                return;
            }

            DB::statement('DROP INDEX '.$name.' ON contents');

            return;
        }

        // pgsql
        if ($this->pgsqlUniqueConstraintExists($name)) {
            DB::statement('ALTER TABLE contents DROP CONSTRAINT '.$name);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.$name);
    }

    /**
     * 建立站点级 slot 唯一索引。
     *
     * slot 可为 NULL：MySQL / PostgreSQL / SQLite 的 UNIQUE 约束都允许多行 NULL，
     * 三库对「未设置 slot 的普通内容」行为一致。
     */
    private function createNewIndex(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->unique(['site_id', 'slot'], self::NEW_INDEX);
        });
    }

    /**
     * 探测「同站同 slot 多行」。存在则中止迁移并报错。
     */
    private function assertNoSlotConflicts(string $phase): void
    {
        $conflicts = DB::select(
            "SELECT site_id, slot, COUNT(*) AS c
             FROM contents
             WHERE slot IS NOT NULL AND slot <> ''
             GROUP BY site_id, slot
             HAVING COUNT(*) > 1"
        );

        if ($conflicts === []) {
            return;
        }

        $detail = collect($conflicts)
            ->map(fn ($row) => "site_id={$row->site_id} slot={$row->slot} (×{$row->c})")
            ->implode(', ');

        throw new RuntimeException(
            "无法{$phase} contents(site_id, slot) 唯一约束：存在同站同 slot 的多行 → {$detail}。"
            .'这些行只可能来自 MySQL / PostgreSQL 的旧宽松约束 UNIQUE(site_id, slot, locale)。'
            .'请人工确认每行是否应当保留（同一叙事插槽只应保留一条），清理后再重试。'
            .'本迁移不会自动删除或改写任何内容。'
        );
    }

    /**
     * SQLite 分支的校验：确认 contents 已是站点级 UNIQUE(site_id, slot)。
     *
     * 内联 UNIQUE 在 SQLite 中表现为 sqlite_autoindex_*，无法从索引名看出列，
     * 因此这里读表定义（sqlite_master.sql）做文本判定，同时用 PRAGMA 交叉验证
     * 「存在一个列恰为 (site_id, slot) 的唯一索引」且「不存在 (site_id, slot, locale) 形态」。
     */
    private function assertSqliteAlreadySiteScoped(): void
    {
        $ddl = (string) (DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'contents'"
        )->sql ?? '');

        $hasSiteScoped = preg_match(
            '/UNIQUE\s*\(\s*site_id\s*,\s*slot\s*\)/i',
            $ddl
        ) === 1;

        $hasLocaleScoped = preg_match(
            '/UNIQUE\s*\(\s*site_id\s*,\s*slot\s*,\s*locale\s*\)/i',
            $ddl
        ) === 1;

        if (! $hasSiteScoped || $hasLocaleScoped) {
            throw new RuntimeException(
                'SQLite contents 表的唯一约束不符合预期：'
                .'期望 UNIQUE(site_id, slot)，实际 DDL 为 → '.$ddl
                .'请人工核对 SQLite 结构后再重试。'
            );
        }

        // PRAGMA 交叉验证：确实存在列恰为 (site_id, slot) 的唯一索引。
        $siteScoped = false;
        foreach (DB::select('PRAGMA index_list(contents)') as $index) {
            if ((int) $index->unique !== 1) {
                continue;
            }

            $cols = array_map(
                static fn ($c) => (string) $c->name,
                DB::select('PRAGMA index_info("'.str_replace('"', '""', $index->name).'")')
            );

            sort($cols);

            if ($cols === ['site_id', 'slot']) {
                $siteScoped = true;
            }
        }

        if (! $siteScoped) {
            throw new RuntimeException(
                'SQLite contents 表缺少 UNIQUE(site_id, slot) 唯一索引（PRAGMA index_list 校验未通过）。'
                .'请人工核对 SQLite 结构后再重试。'
            );
        }
    }

    /**
     * MySQL 上指定索引是否存在。
     */
    private function mysqlIndexExists(string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1',
            ['contents', $index]
        ) !== null;
    }

    /**
     * PostgreSQL 上指定名称是否是**唯一约束**（contype='u'）。
     *
     * 只有约束才用 ALTER TABLE ... DROP CONSTRAINT；
     * partial unique index 不是约束，只能 DROP INDEX。
     */
    private function pgsqlUniqueConstraintExists(string $name): bool
    {
        return DB::selectOne(
            "SELECT 1 AS ok FROM pg_constraint
             WHERE conrelid = 'contents'::regclass AND conname = ? AND contype = 'u'
             LIMIT 1",
            [$name]
        ) !== null;
    }
};
