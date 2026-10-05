<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * facts 纳入行级翻译模型（20G-3 · Locale → Derived Output Integrity）
 * ------------------------------------------------------------------
 * 改造前：facts 只有 `key`（UNIQUE(site_id, key)），label / value 是单语言字段。
 *         后果：`/en/geo.json` 的 facts 段直接输出中文 label / value
 *         （GeoGraphBuilder::facts() → Fact::publicRows()），
 *         属 GEO 产品的**数据正确性缺陷**（AI 检索到英文站点的事实是中文）。
 *
 * 改造后（与 Content / Entity 同一套模型，不另起双语机制）：
 *   - 新增 `locale`（默认 zh-CN）+ `translation_group`；
 *   - 唯一约束 `UNIQUE(site_id, key)` → `UNIQUE(site_id, key, locale)`，
 *     使「FACT-X / zh-CN」与「FACT-X / en」可共存；
 *   - 新增 `facts_translation_group_index`，保留既有索引。
 *
 * ⚠️ `key` 与 `translation_group` 职责严格分离（20G-3 设计约束）：
 *   - `key` = **事实语义身份**（`FACT-COMPANY-NAME`），跨语言恒定。
 *     跨语言引用（Content.fact_refs、GEO 语义节点）只认它。
 *   - `translation_group` = **翻译实体身份**，标识「这几行是同一事实的多语言」，
 *     由系统管理，运营人员不直接编辑。
 *
 * 存量数据的 `translation_group` **不生成随机 UUID**，而是按 key 派生确定性值
 * `TG-FACT-{key}`：这样即使当前只有中文行（单行），后续补英文行时沿用同一
 * group 即可自动归组，无需回填。随机 UUID 会导致「先有 en 行、zh 行另生成
 * 一组」的历史数据无法自愈。
 *
 * SQLite 注意（20G-8 实测教训，本迁移严格遵守）：
 *   1. 加列 + 改唯一约束都要重建表；
 *   2. 重建必须**逐列显式声明**（禁 `CREATE TABLE ... AS SELECT`——
 *      20G-6 · C-16 实测会丢 AUTOINCREMENT / UNIQUE / FK）；
 *   3. 列清单**从 PRAGMA 读实际值动态生成**，只加/删目标列——
 *      硬编码列清单在「回滚到中间态」时会报 no such column；
 *   4. up / down 两侧的列、唯一约束、索引必须完全对称。
 */
return new class extends Migration
{
    /**
     * 与既有 facts 索引一致，down 侧对称重建。
     *
     * ⚠️ 列名一律反引号包裹：`group` 是 SQL 保留字，
     * 不加引号会 `near "group": syntax error`（20G-3 实测）。
     */
    private const INDEXES = [
        'facts_group_sort_index'        => ['`group`', '`sort`'],
        'facts_review_due_index'        => ['`review_due`'],
        'facts_site_id_index'           => ['`site_id`'],
    ];

    public function up(): void
    {
        if (Schema::hasColumn('facts', 'locale')) {
            return; // 幂等
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqlite(addI18n: true);

            return;
        }

        Schema::table('facts', function (Blueprint $table): void {
            $table->string('locale', 16)->default('zh-CN');
            $table->string('translation_group', 80)->nullable();
        });

        // 存量归组：按 key 派生确定性 group（同一 key 的多语言行天然同组）
        foreach (DB::table('facts')->orderBy('id')->get() as $row) {
            DB::table('facts')->where('id', $row->id)->update([
                'locale'            => 'zh-CN',
                'translation_group' => 'TG-FACT-'.$row->key,
            ]);
        }

        Schema::table('facts', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'key']);
        });
        Schema::table('facts', function (Blueprint $table): void {
            $table->unique(['site_id', 'key', 'locale'], 'facts_site_key_locale_unique');
            $table->index('translation_group', 'facts_translation_group_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('facts', 'locale')) {
            return;
        }

        /**
         * 回滚前必须清理多语言行：`UNIQUE(site_id, key)` 无法容纳同 key 的多语言行。
         * 保留策略：每 key 只留**默认语言**行，与改造前「一个事实一条」的语义一致。
         */
        DB::table('facts')->where('locale', '!=', 'zh-CN')->delete();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('facts', function (Blueprint $table): void {
                $table->dropUnique(['site_id', 'key', 'locale']);
                $table->dropIndex('facts_translation_group_index');
            });
            Schema::table('facts', function (Blueprint $table): void {
                $table->unique(['site_id', 'key']);
            });
            Schema::table('facts', function (Blueprint $table): void {
                $table->dropColumn(['locale', 'translation_group']);
            });

            return;
        }

        $this->rebuildSqlite(addI18n: false);
    }

    /**
     * SQLite 表重建。
     *
     * @param  bool $addI18n true=加 locale/translation_group 并用含locale 的唯一约束；
     *                       false=删这两列并恢复 UNIQUE(site_id, key)
     */
    private function rebuildSqlite(bool $addI18n): void
    {
        $i18nCols = ['locale', 'translation_group'];

        /**
         * 重建前先读出**实际存在的外键定义**。
         *
         * ⚠️ 20G-3 实测踩坑：SQLite 的表重建必须显式重写 FOREIGN KEY 子句，
         * 漏掉就会**静默丢失外键** —— `SiteIdCoreTablesTest` 抓到 facts 丢了
         * `site_id → sites(id) ON DELETE RESTRICT`。
         *
         * 从 `PRAGMA foreign_key_list` 读真实定义（含 on_delete / on_update），
         * 而非硬编码 —— 这样任何后续新增的外键都能自动保住。
         */
        $foreignKeys = [];
        foreach (DB::select('PRAGMA foreign_key_list(facts)') as $fk) {
            $foreignKeys[(string) $fk->from][] = '`'.$fk->table.'`(`'.$fk->to.'`)'
                .' ON DELETE '.(string) $fk->on_delete
                .' ON UPDATE '.(string) $fk->on_update;
        }

        // 从实际表结构取列（不硬编码 —— 中间态下列集合会变）
        $defs  = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
        $names = [];
        foreach (DB::select('PRAGMA table_info(facts)') as $c) {
            $name = (string) $c->name;
            if ($name === 'id') {
                continue;
            }
            if (! $addI18n && in_array($name, $i18nCols, true)) {
                continue; // down：剔除 i18n 列
            }
            $def     = '`'.$name.'` '.($c->type ?: 'TEXT');
            if ((int) $c->notnull === 1) {
                $def .= ' NOT NULL';
            }
            if ($c->dflt_value !== null) {
                $def .= ' DEFAULT '.$c->dflt_value;
            }
            $defs[]  = $def;
            $names[] = $name;
        }

        if ($addI18n) {
            // locale 与 translation_group 追加在末尾（列序无语义要求）
            $defs[]  = "`locale` VARCHAR(16) NOT NULL DEFAULT 'zh-CN'";
            $defs[]  = '`translation_group` VARCHAR(80)';
            $names[] = 'locale';
            $names[] = 'translation_group';
        }

        $has = array_flip($names);

        // 唯一约束：含locale（up）/ 不含 locale（down）
        if (isset($has['site_id'], $has['key'])) {
            $defs[] = $addI18n
                ? 'UNIQUE(site_id, key, locale)'
                : 'UNIQUE(site_id, key)';
        }

        // 恢复全部外键（引用列仍存在时才写）
        foreach ($foreignKeys as $col => $targets) {
            if (! isset($has[$col])) {
                continue;
            }
            foreach ($targets as $t) {
                $defs[] = 'FOREIGN KEY (`'.$col.'`) REFERENCES '.$t;
            }
        }

        $tmp = 'facts_i18n_tmp';

        DB::statement('PRAGMA foreign_keys = OFF');
        Schema::dropIfExists($tmp);
        DB::statement('CREATE TABLE '.$tmp.' ('.implode(', ', $defs).')');

        $q = fn (string $n) => '`'.$n.'`';
        if ($addI18n) {
            /**
             * 存量行的 locale 缺省 zh-CN；translation_group 直接按 key 派生。
             *
             * ⚠️ 不能写成 `CASE WHEN translation_group ...` —— up()执行到这一步时
             * facts 表**还没有** translation_group 列（SQLite 的列是随重建表才加上的），
             * 引用它会直接 no such column。20G-8 的教训：重建路径里凡涉及「新列」的
             * 表达式只能依赖「旧表已存在的列」。
             */
            $keep = array_values(array_diff($names, $i18nCols));
            $src  = implode(', ', array_map($q, $keep));
            $tg   = "'TG-FACT-' || `key`";
            DB::statement(
                'INSERT INTO '.$tmp.' ('.implode(', ', array_map($q, $names)).')'
                ." SELECT {$src}, 'zh-CN', {$tg} FROM facts"
            );
        } else {
            $colList = implode(', ', array_map($q, $names));
            DB::statement("INSERT INTO {$tmp} ({$colList}) SELECT {$colList} FROM facts");
        }

        DB::statement('DROP TABLE facts');
        DB::statement('ALTER TABLE '.$tmp.' RENAME TO facts');

        foreach (self::INDEXES as $name => $cols) {
            $ok = true;
            foreach ($cols as $col) {
                if (! isset($has[trim($col, '`')])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                DB::statement('CREATE INDEX '.$name.' ON facts('.implode(', ', $cols).')');
            }
        }

        if ($addI18n && isset($has['translation_group'])) {
            DB::statement('CREATE INDEX facts_translation_group_index ON facts(`translation_group`)');
        }

        DB::statement('PRAGMA foreign_keys = ON');
    }
};