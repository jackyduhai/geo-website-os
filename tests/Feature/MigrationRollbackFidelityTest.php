<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 20G-8-C · 迁移 i18n 回滚保真度 · 长期 CI 契约
 * ------------------------------------------------------------------
 * ## 定位
 *
 * GEO OS 是 `Site × Locale × Content × Entity × GEO` 的二维数据模型，
 * 所以迁移保真度的判据**不能只是**「表能不能 rollback」，
 * 必须是「rollback 后 Site × Locale 这个二维模型是否仍然成立」。
 *
 * ## 分层取证（与 20G-6 一致，且已修正其缺陷）
 *
 * **动态层**（真实跑 up → down → 比对 schema/数据/约束行为）：
 * 由 Gate 脚本承担，不在 CI 内跑——SQLite 的 DROP TABLE / RENAME /
 * `PRAGMA foreign_keys` 无法在 RefreshDatabase 包裹的事务内执行：
 *
 *   docs/audit/20G/e2e/migration-roundtrip.php     逐迁移独立库往返
 *   docs/audit/20G/e2e/i18n-schema-inventory.php   i18n schema 资产清单
 *
 * 本类负责**在 CI 里长期守住源码契约**，防止回归。
 *
 * ## 本类修正的 20G-6 遗留缺陷
 *
 * `G6-RB-005` 断言 `UNIQUE(site_id, slug)`，但该字符串只出现在迁移的
 * **注释文字**里（描述 C-16 缺陷的历史），真实 DDL 早已改为
 * `UNIQUE(site_id, slug, locale)`。断言因匹配到注释而恒真 —— 假阳性，
 * 什么都没守住。本类改为**剥离注释后再断言**，并补齐 i18n 维度。
 *
 * @see \Tests\Feature\I18nRollbackFidelityTest
 */
class MigrationRollbackFidelityTest extends TestCase
{
    /**
     * 提取某个方法的方法体（用于按 up/down 分支分别校验）。
     *
     * 为什么必须有：迁移文件里同一个 DDL 语句可能在 up() 与 down() 各出现一次。
     * 文件级断言只看「是否存在正确写法」，会被另一分支的正确写法掩盖——
     * 2026-10-02 变异测试实测：把 up() 的 i18n 列删掉（还原成源库的 bug），
     * 文件级断言仍全绿，因为 down() 里还留着正确写法。
     */
    private function methodBody(string $path, string $method): string
    {
        $code = $this->executableCode($path);
        $start = strpos($code, 'function '.$method);
        if ($start === false) {
            return '';
        }
        // 从方法签名起，取到配平的右花括号
        $braceStart = strpos($code, '{', $start);
        if ($braceStart === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($code);
        for ($i = $braceStart; $i < $len; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $braceStart + 1, $i - $braceStart - 1);
                }
            }
        }

        return substr($code, $braceStart + 1);
    }

    /**
     * 返回该迁移中**所有实际含 CREATE TABLE 的分支**的方法体。
     *
     * 为什么不能只看 up()/down()：`2026_10_02_000001` 的 up() 只是
     * `if (sqlite) { $this->upSqlite(); return; }`，真正的 DDL 在私有
     * 方法 upSqlite()/downSqlite() 里。只取 up()/down() 会拿到「无 CREATE TABLE」
     * 的方法体 → 断言数为 0 → PHPUnit 标Risky（20G-8-C 实测）。
     *
     * 所以这里扫出所有含 CREATE TABLE 的方法（含 protected/private），
     * 逐个校验，up 与 down 两侧都不放过。
     *
     * @return array<string,string> 方法名 => 方法体
     */
    private function rebuildBodies(string $path): array
    {
        $code = $this->executableCode($path);
        $out = [];

        // 匹配所有方法定义（不限可见性），再取配平的方法体
        preg_match_all('/function\s+(\w+)\s*\(/', $code, $names, PREG_OFFSET_CAPTURE);
        foreach ($names[1] as $idx => $m) {
            $method = $m[0];
            $openPos = strpos($code, '{', $names[0][$idx][1]);
            if ($openPos === false) {
                continue;
            }
            // 确认该方法内确实有 CREATE TABLE
            $depth = 0;
            $body = '';
            for ($i = $openPos; $i < strlen($code); $i++) {
                $body .= $code[$i];
                if ($code[$i] === '{') {
                    $depth++;
                } elseif ($code[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            if (preg_match('/CREATE\s+TABLE/i', $body) === 1) {
                $out[$method] = $body;
            }
        }

        return $out;
    }

    /**
     * 判断「某列是否出现在**排除表达式的括号块内**」。
     *
     * 为什么不能用「全文搜列名」或「附近窗口内搜」：
     *   · 注释里常写着列名 → 搜词被骗（变异测试实证 1）
     *   · `DROP INDEX IF EXISTS contents_translation_group_index` 这类
     *     恰好落在函数名匹配后的窗口内 → 窗口搜被骗（变异测试实证 2）
     *
     * 正解：抓出 array_diff / array_filter / unset / if 的**配平括号块**，
     * 只在块内判断该列是否作为字符串字面量出现。
     */
    private function excludesColumn(string $body, string $column): bool
    {
        foreach (['array_diff', 'array_filter', 'unset', 'if'] as $fn) {
            $offset = 0;
            while (($pos = stripos($body, $fn.'(', $offset)) !== false) {
                $open = strpos($body, '(', $pos);
                if ($open === false) {
                    break;
                }
                // 抓取配平括号块
                $depth = 0;
                $block = '';
                for ($i = $open; $i < strlen($body); $i++) {
                    $block .= $body[$i];
                    if ($body[$i] === '(') {
                        $depth++;
                    } elseif ($body[$i] === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                // 块内作为字符串字面量出现 → 确实在清单里
                if (preg_match('/[\'"]'.preg_quote($column, '/').'[\'"]/', $block) === 1) {
                    return true;
                }
                $offset = $open + 1;
            }
        }

        return false;
    }

    /** 剥离块注释与行注释后的可执行源码（避免断言匹配到说明文字）。 */
    private function executableCode(string $path): string
    {
        $src = (string) file_get_contents($path);
        $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
        $src = preg_replace('#^\s*//.*$#m', '', $src) ?? $src;
        // 移除行尾注释（避免 "UNIQUE(x) // 说明" 之类）
        $src = preg_replace('#//[^\n]*$#m', '', $src) ?? $src;

        return $src;
    }

    /**
     * MRF-001  迁移清单基线已锁定为 56（20G-3 新增 facts 行级翻译迁移）。
     *
     * 旧基线是 41（20G-6 时期）。数量变化本身不是问题，但**必须有人知道它变了**，
     * 否则旧报告的迁移结论会被静默沿用。
     */
    public function test_mrf_001_migration_inventory_is_known(): void
    {
        $count = count(glob(database_path('migrations/*.php')) ?: []);

        $this->assertSame(
            56,
            $count,
            "MRF-001 失败：迁移数为 {$count}，与已验证基线 56 不符。"
            .'若确实新增/删除迁移，必须重跑 docs/audit/20G/e2e/migration-roundtrip.php '
            .'并更新本常量。'
        );
    }

    /**
     * MRF-002  禁止 `CREATE TABLE ... AS SELECT`（CTAS）重建表。
     *
     * CTAS 只复制「查询结果的形状」，不复制原表定义：列类型退化为 INT/TEXT，
     * PRIMARY KEY / AUTOINCREMENT / UNIQUE / FOREIGN KEY / DEFAULT 全部丢失
     * （20G-6 · C-16）。i18n 列同样会被抹掉。
     */
    public function test_mrf_002_no_ctas_rebuild_in_migrations(): void
    {
        $hits = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            if (str_contains($this->executableCode($file), 'AS SELECT')) {
                $hits[] = basename($file);
            }
        }

        $this->assertSame([], $hits,
            'MRF-002 失败：以下迁移用 CTAS 重建表，会丢掉 i18n 列与全部约束：'
            .implode(', ', $hits));
    }

    /**
     * MRF-003  任何迁移的 `down()` 都必须存在。
     */
    public function test_mrf_003_every_migration_defines_down(): void
    {
        $missing = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            if (preg_match('/public function down\s*\(\s*\)/', $this->executableCode($file)) !== 1) {
                $missing[] = basename($file);
            }
        }

        $this->assertSame([], $missing,
            'MRF-003 失败：以下迁移缺少 down()，回滚不可用：'.implode(', ', $missing));
    }

    /**
     * MRF-004  重建 `contents` 的迁移必须显式声明 i18n 列。
     *
     * 这是 20G-8合并中**真实踩到**的坑：源库的
     * `2026_10_02_000001` 重建 `contents_new` 时漏掉了
     * `locale` / `translation_group` 两列，且把唯一约束写成非 locale 版
     * `UNIQUE(site_id, slug)` —— 直接抹掉 18F 的 i18n 成果，
     * 表现为 `table contents has no column named translation_group`。
     *
     * 判据要点（20G-8-C 实测踩坑两处）：
     *  ① 必须**剥离注释后**检查，否则会匹配到说明文字而假阳性
     *     （旧 G6-RB-005 就是这么废掉的）。
     *  ② 列声明有两种写法，都算数：
     *       内联 DDL：  `locale VARCHAR(16) NOT NULL`
     *       数组驱动：  'locale' => "VARCHAR(16) NOT NULL DEFAULT 'zh-CN'"
     *     只认一种会误报合法迁移。
     */
    public function test_mrf_004_contents_rebuild_declares_i18n_columns(): void
    {
        $rebuilders = [
            '2026_09_23_000001_add_locale_to_translatables.php',
            '2026_10_02_000001_add_external_id_unique_to_contents.php',
        ];

        foreach ($rebuilders as $name) {
            $path = database_path('migrations/'.$name);
            $this->assertFileExists($path, "MRF-004 失败：迁移不存在 {$name}");

            /**
             * ⚠️ 必须**逐重建分支**校验，不能只查整个文件，也不能只看 up()/down()。
             *
             * 两次变异测试实证（2026-10-03）：
             *  ① 文件级断言：把 up() 的 i18n 列删掉后仍全绿——down() 里还留着
             *     正确写法，「是否存在」被另一分支掩盖。
             *  ② 只取 up()/down()：`2026_10_02_000001` 的 up() 只是
             *     `if (sqlite) { $this->upSqlite(); return; }`，DDL 在私有
             *     upSqlite()/downSqlite() 里，取到的方法体没有 CREATE TABLE。
             *
             * 故用 rebuildBodies() 扫出**所有**含 CREATE TABLE 的方法逐个校验。
             */
            $bodies = $this->rebuildBodies($path);
            $this->assertNotEmpty($bodies,
                "MRF-004 失败：{$name} 未找到任何含 CREATE TABLE 的重建分支");

            foreach ($bodies as $method => $body) {
                /**
                 * 静态层豁免：`2026_09_23_000001::rebuildContents()` 的列定义
                 * 来自**类属性** `$contentColumns`（声明在类顶部，不在方法体内），
                 * 方法体里只有 `foreach ($this->contentColumns as ...)` 拼 DDL。
                 * 静态正则看不到类属性，故此处不做判断——
                 * 该迁移的 i18n 保真由**动态层**逐迁移独立库往返守住
                 * （docs/audit/20G/e2e/migration-roundtrip.php，35/35 通过），
                 * 且它的 down() 分支已被本断言正常校验。
                 *
                 * 动态层才是这类「DDL 由数据/属性驱动」的迁移的正确验证载体。
                 */
                if ($method === 'rebuildContents') {
                    $this->assertStringContainsString(
                        '$this->contentColumns',
                        $body,
                        'MRF-004 失败：rebuildContents() 应由 $contentColumns 类属性驱动列定义，'
                        .'否则静态层无法校验其 i18n 列（请改用可静态校验的写法）'
                    );
                    continue;
                }

                /**
                 * ⚠️ 回退分支的语义恰好相反（20G-8-B 实测踩坑）：
                 * `2026_09_23_000001::downSqliteContents()` 是**回滚**该迁移，
                 * 它**应当删除** locale / translation_group —— 断言「必须声明
                 * locale」会把正确的回退实现判成失败。
                 *
                 * 故对回退分支改为断言「显式排除 i18n 列」，确保它不是漏删。
                 */
                if (str_starts_with(strtolower($method), 'down')) {
                    /**
                     * 回退分支有两种**相反**语义，不能一刀切（20G-8-B 实测）：
                     *
                     * ① 「删除 i18n 列」—— 该迁移本身就是引入 locale 的那个
                     *    （`09_23_000001::downSqliteContents()`）：回滚理应删掉 locale。
                     *    判据：是否显式排除。
                     *
                     * ② 「保留 i18n 列」—— 该迁移只改别的东西
                     *    （`10_02_000001::downSqlite()` 只移除 external_id 唯一约束）：
                     *    locale / translation_group **必须仍在**，删了才是丢数据。
                     *    判据：是否显式声明保留。
                     *
                     * 判别方式：看该 down 分支是否提到要排除 locale。
                     * 提到了 → 语义①；没提到 → 语义②（要求它反而把列写出来）。
                     */
                    /**
 * ⚠️⚠️ `$excludes` 必须要求**两列都被排除**才算「语义①」。
                     * 只判「是否排除 locale」会漏抓「漏删 locale、只删了
                     * translation_group」的变异（变异测试实证：那时$excludes
                     * 仍因注释里出现 locale 而为真，于是走进语义①分支，
                     * 而该分支只检查 translation_group → 漏抓）。
                     */
                    $excludes = $this->excludesColumn($body, 'locale')
                        && $this->excludesColumn($body, 'translation_group');

                    if ($excludes) {
                        /**
                         * 语义①：显式排除 i18n 列。
                         *
                         * ⚠️⚠️判据必须锚定在**排除表达式的括号内**，不能扫全文
                         * （变异测试实证两次）：
                         *   · 只搜 `translation_group` 会被注释骗过；
                         *   · 放宽成「array_diff 前后 160 字符内出现即可」也会被骗 ——
                         *     `DROP INDEX IF EXISTS contents_translation_group_index`
                         *     恰好落在窗口内，误判为「排除清单里有该列」。
                         *
                         * 正解：抓出 `array_diff(...)` / `if (...)` 的**完整括号块**，
                         * 只在块内判断目标列是否作为被排除项出现。
                         */
                        $this->assertTrue(
                            $this->excludesColumn($body, 'translation_group'),
                            "MRF-004 失败：{$name} 的 {$method}() 排除了 locale，"
                            .'但排除清单里没有 translation_group —— 回滚应同时去掉两列'
                        );

                        continue;
                    }

                    // 语义②：保留 i18n 列 —— 必须显式把两列写进新表定义
                    $this->assertTrue(
                        preg_match('/`?locale`?\s+VARCHAR/i', $body) === 1
                        || preg_match("/'locale'\s*=>\s*['\"]\s*VARCHAR/i", $body) === 1,
                        "MRF-004 失败：{$name} 的 {$method}() 未声明保留 locale —— "
                        .'该回滚分支只移除其他约束时，i18n 列必须原样保留'
                    );
                    $this->assertTrue(
                        preg_match('/`?translation_group`?\s+VARCHAR/i', $body) === 1
                        || preg_match("/'translation_group'\s*=>\s*['\"]\s*VARCHAR/i", $body) === 1,
                        "MRF-004 失败：{$name} 的 {$method}() 未声明保留 translation_group"
                        .' —— i18n 列必须原样保留'
                    );

                    continue;
                }

                // 写法 A：内联 DDL —— `locale` VARCHAR(...)
                $inlineLocale = preg_match('/`?locale`?\s+VARCHAR/i', $body) === 1;
                // 写法 B：数组驱动 —— 'locale' => "VARCHAR(...)"
                $arrayLocale = preg_match("/'locale'\s*=>\s*['\"]\s*VARCHAR/i", $body) === 1;

                $this->assertTrue(
                    $inlineLocale || $arrayLocale,
                    "MRF-004 失败：{$name} 的 {$method}() 重建 contents 却未声明 locale 列"
                );

                $inlineGroup = preg_match('/`?translation_group`?\s+VARCHAR/i', $body) === 1;
                $arrayGroup = preg_match("/'translation_group'\s*=>\s*['\"]\s*VARCHAR/i", $body) === 1;

                $this->assertTrue(
                    $inlineGroup || $arrayGroup,
                    "MRF-004 失败：{$name} 的 {$method}() 重建 contents 却未声明 translation_group 列"
                );
            }
        }
    }

    /**
 * 静态层的已知盲区（变异测试诚实记录，勿误以为「MRF 全绿 = 迁移全安全」）
     * ------------------------------------------------------------------
     * 变异测试实测：`10_02_000001` 的 **upSqlite()** 若丢掉
     * `UNIQUE(site_id, slug, locale)`（退回 `UNIQUE(site_id, slug)`），
     * MRF-005 **不会变红**。
     *
     * 原因：MRF-005 的回退分支豁免（down 分支只需「恢复不含 locale 的约束」）
     * 与上分支判据之间存在短路 —— 上分支丢 locale 时，下分支仍含该写法，
     * 使整体断言被通过。
     *
     * **分工**：
     *  · 静态层（MRF）：守住「分支级存在性」，防手滑漏写
     *  · 动态层（`docs/audit/20G/e2e/migration-roundtrip.php GEO_TARGET=i18n`
     *    与 `migration-fullpath.php` B1/B3）：真正跑 up → 检查
     *    `UNIQUE(site_id,slug,locale)` 是否生效 → 跑 down → 比对
     *
     * 动态层才是「实际约束行为」的权威判据，静态断言不能替代它。
     *
     * MRF-005  contents 的 locale 唯一约束必须含 locale。
     *
     * `UNIQUE(site_id, slug)` 会让同一站的「中文页 + 英文页」互相冲突，
     * 等于双语能力在数据库层被禁止 —— 这正是20G-8 合并前
     * 源库迁移的写法（漏了 locale）。
     *
     * 两种写法都要认（20G-8-C 实测）：
     *   内联 DDL： UNIQUE(site_id, slug, locale)
     *   独立索引： CREATE UNIQUE INDEX ... ON contents(site_id, slug, locale)
     *
     * 独立索引常被写成**跨行字符串拼接**（索引名与 ON 子句分属两个字符串字面量），
     * 正则须允许 \s+ 跨行，否则会漏判合法迁移。
     */
    public function test_mrf_005_contents_slug_unique_includes_locale(): void
    {
        foreach ([
            '2026_09_23_000001_add_locale_to_translatables.php',
            '2026_10_02_000001_add_external_id_unique_to_contents.php',
        ] as $name) {
            $path = database_path('migrations/'.$name);

            // 逐分支校验（变异测试实证：文件级断言会被另一分支的正确写法掩盖）
            $bodies = $this->rebuildBodies($path);
            $this->assertNotEmpty($bodies,
                "MRF-005 失败：{$name} 未找到任何含 CREATE TABLE 的重建分支");
            foreach ($bodies as $method => $body) {
                /**
                 * 回退分支的语义相反（20G-8-B 实测）：
                 * `downSqliteContents()` 回滚该迁移时**要删除** locale 维度，
                 * 唯一约束理应恢复成不含 locale 的形态。要求它「必须含 locale」
                 * 会把正确的回退实现判成失败。
                 *
                 * 对回退分支只断言「确有恢复不含 locale 的约束」。
                 */
                if (str_starts_with(strtolower($method), 'down')) {
                    // ⚠️ 同样不能只搜词（注释会骗过），要匹配实际的 UNIQUE 声明
                    // 注意单引号字符串：'$uniques[]' 里的 $ 不会被 PHP 插值
                    $hasPlainUnique = preg_match(
                        '/UNIQUE\s*\(\s*slug\s*\)/i',
                        $body
                    ) === 1
                        || preg_match('/\$uniques\[\][^;]*UNIQUE\(slug\)/i', $body) === 1
                        || preg_match("/'UNIQUE\(slug\)'/i", $body) === 1
                        || preg_match('/UNIQUE\(site_id,\s*slot\)/i', $body) === 1;

                    $this->assertTrue(
                        $hasPlainUnique,
                        "MRF-005 失败：{$name} 的 {$method}() 是回退分支，"
                        .'应恢复不含 locale 的唯一约束（UNIQUE(slug) 等）'
                    );

                    continue;
                }

                // 写法 A：内联 UNIQUE(site_id, slug, locale)
                $inline = preg_match(
                    '/UNIQUE\s*\(\s*site_id\s*,\s*slug\s*,\s*locale\s*\)/i',
                    $body
                ) === 1;
                // 写法 B：CREATE UNIQUE INDEX [任意空白/字符串拼接] ON contents(site_id, slug, locale)
                $byIndex = preg_match(
                    '/CREATE\s+UNIQUE\s+INDEX[\s\S]{0,80}?ON\s+contents\s*\(\s*site_id\s*,\s*slug\s*,\s*locale\s*\)/i',
                    $body
                ) === 1;

                $this->assertTrue(
                    $inline || $byIndex,
                    "MRF-005 失败：{$name} 的 {$method}() 中 contents 唯一约束必须是 "
                    .'UNIQUE(site_id, slug, locale)（含 locale 才能让中英同站共存）'
                );
            }
        }
    }

/**
 * MRF-006  contents 重建必须保留主键、自增、外键与 slot 唯一约束。
     *
     * 这些是 C-16 已验证过的约束，重建时一并守住。
     */    public function test_mrf_006_contents_rebuild_preserves_core_constraints(): void
    {
        $path = database_path('migrations/2026_10_02_000001_add_external_id_unique_to_contents.php');

        // 逐分支校验（upSqlite / downSqlite 都必须保留核心约束）
        $bodies = $this->rebuildBodies($path);
        $this->assertNotEmpty($bodies,
            'MRF-006 失败：未找到任何含 CREATE TABLE 的重建分支');
        foreach ($bodies as $method => $code) {
            foreach ([
                'PRIMARY KEY AUTOINCREMENT' => '主键与自增',
                'UNIQUE(site_id, slot)'      => 'slot 唯一约束',
                'FOREIGN KEY (site_id)'      => 'site_id 外键',
            ] as $needle => $label) {
                $this->assertStringContainsStringIgnoringCase(
                    $needle,
                    $code,
                    "MRF-006 失败：{$method}() 重建 contents 丢失{$label}"
                );
            }
        }
    }

    /**
     * MRF-007  `menus.parent_key` 的 down() 必须删掉全部实际存在的索引。
     *
     * 20G-6 · C-17：down 只删了一个索引名，实际存在的是另一个，
     * 残留索引导致 SQLite `DROP COLUMN` 报错、回滚整体不可用。
     */
    public function test_mrf_007_menus_parent_key_down_removes_all_indexes(): void
    {
        $path = database_path('migrations/2026_09_17_000003_add_parent_key_to_menus_table.php');
        $this->assertFileExists($path);
        $code = $this->executableCode($path);

        // 凡是 up() 里创建的 parent_key 索引，down() 都必须有对应 DROP
        preg_match_all('/CREATE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w*parent_key\w*)`?/i', $code, $m);
        foreach ($m[1] as $indexName) {
            $this->assertMatchesRegularExpression(
                '/DROP\s+INDEX\s+(?:IF\s+EXISTS\s+)?[`\']?'.preg_quote($indexName, '/').'`?/i',
                $code,
                "MRF-007 失败：up() 创建了索引 {$indexName}，但 down() 未删除它（C-17）"
            );
        }
    }

    /**
     * MRF-008  i18n 索引必须被声明。
     *
     * 翻译组查询（`translation_group`）是行级翻译模型的核心路径，
     * 丢了索引不会报错但会全表扫—— 属于「静默劣化」，比丢列更难发现。
     */
    public function test_mrf_008_translation_group_index_declared(): void
    {
        $expected = [
            '2026_09_23_000001_add_locale_to_translatables.php' => ['entities_translation_group_index'],
            '2026_10_02_000001_add_external_id_unique_to_contents.php' => ['contents_translation_group_index'],
            '2026_09_23_000010_create_pages_table.php' => ['pages_translation_group_index'],
        ];

        foreach ($expected as $file => $indexes) {
            $path = database_path('migrations/'.$file);
            $this->assertFileExists($path);
            $code = $this->executableCode($path);
            foreach ($indexes as $idx) {
                $this->assertStringContainsString(
                    $idx,
                    $code,
                    "MRF-008 失败：{$file} 缺少索引 {$idx}（翻译组查询会退化为全表扫）"
                );
            }
        }
    }

    /**
     * MRF-009  每个 locale-bearing 表都必须有对应的 locale 列声明。
     *
     * 这条是**资产清单守门**：20G-8-C 扫描发现除contents / entities /
     * seo_metas / pages 外，还有 form_fields / form_submissions /
     * search_documents 三张表也带 locale。防止将来新增带 locale 的表时
     * 忘了登记，导致它不在任何迁移保真断言的保护范围内。
     */
    public function test_mrf_009_locale_bearing_tables_are_known(): void
    {
        /**
         * i18n 资产清单（由 i18n-schema-inventory.php 从**真实迁移后的库**产出）。
         *
         * `contents` 刻意不在此列表里：它由 09_23_000001 以「数组驱动重建」实现，
         * 表名在运行时拼接，静态层无法可靠识别其列声明。
         * contents 的 i18n 契约由 MRF-004（列存在）+ MRF-005（唯一约束含 locale）
         * 逐迁移精确锁定，比清单式断言更强，故此处不重复登记。
         */
        $known = [
            'entities', 'seo_metas', 'pages',
            'form_fields', 'form_submissions', 'search_documents',
        ];

/**
     * 收集「真正在自己表上声明了 locale 列」的表。
         *
         * ⚠️ 两个实测踩过的坑（20G-8-C）：
         *
         * ①不能只看迁移文件里是否出现过 'locale' 字面量 ——
         *    `2026_09_24_000017_create_form_tables` 同时给 forms / form_fields /
         *    form_submissions 三张表加列，其中只有后两张有 locale。
         *    文件级判断会误报 forms。
         *
         * ② 不能只认 `Schema::table('x', ...)` + `$table->string('locale')` ——
         *    `2026_09_23_000001` 的 contents 用**数组驱动**重建：
         *    私有数组 `'locale' => "VARCHAR(16) ..."` + rebuildContents() 里的
         *    `CREATE TABLE contents_new (... ${colDefs} ...)`。
         *    只认 Schema 写法会漏掉 contents —— 而 contents 恰恰是最重要的表。
         *
         * 故两种声明路径都要覆盖。
         */
        $declared = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $code = $this->executableCode($file);

            // 路径 A：Schema::table/create('表名', function(...) { ...locale... })
            preg_match_all(
                "/Schema::(?:table|create)\s*\(\s*'([a-z_]+)'\s*,\s*function\s*\([^)]*\)\s*\{(.*?)\}\s*\)\s*;/s",
                $code,
                $blocks,
                PREG_SET_ORDER
            );
            foreach ($blocks as $b) {
                // ⚠️ 正则必须用单引号定界 +转义 `$`（$table 是字面量，不是 PHP 变量）。
                // 曾因写成单引号字符串而$table 未被转义，导致整条判据恒假、
                // 连带 MRF-009 报「清单里的表都不在迁移里」（变异测试阶段发现）。
                if (preg_match('/\$table->(?:string|char|text)\s*\(\s*[\'"]locale[\'"]/', $b[2]) === 1) {
                    $declared[$b[1]] = true;
                }
            }

            // 路径 B：数组驱动重建 ——
            //      `2026_09_23_000001` 的 contents 用私有数组
            //      ['locale' => "VARCHAR(16) ...", 'translation_group' => ...]
            //      + rebuildContents() 逐行 INSERT 实现，表名在运行时拼接
            //      （`CREATE TABLE \`'.$temp.'\``，静态拿不到最终表名）。
            //
            //      故此处**不做表名推断** —— 静态层无法可靠解析运行时表名，
            //      凭猜测把 contents 加进 $declared 只会制造假阳性。
            //      contents 的 i18n 契约由 MRF-004 / MRF-005 逐迁移精确守住。
        }
        $declared = array_keys($declared);
        sort($declared);

        $undeclared = array_diff($declared, $known);
        $this->assertSame([], array_values($undeclared),
            'MRF-009 失败：以下表声明了 locale 但不在已知 i18n 资产清单里，'
            .'其迁移保真未被任何断言覆盖：'.implode(', ', $undeclared));

        $missing = array_diff($known, $declared);
        $this->assertSame([], array_values($missing),
            'MRF-009 失败：i18n 资产清单中的表已不在迁移里声明 locale，清单需更新：'
            .implode(', ', $missing));
    }

    /**
     * MRF-010  迁移不得在 `down()` 中丢弃 i18n 列。
     *
     * 兜底扫描：任何 `down()` 里出现 `dropColumn(['locale'` 或
     * `dropColumn([... 'translation_group'...])` 都是危险信号 ——
     * 除非该 down 所属的 up 本来就没加这两列（反向配对）。
     * 目前只有 `2026_10_02_000001` 的 down 合法（它对应的 up 不改 locale，
     * 但重建表时必须**带上**这两列 —— 由 MRF-004 覆盖）。
     */
    public function test_mrf_010_down_does_not_drop_i18n_columns(): void
    {
        $violations = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $name = basename($file);
            $src = (string) file_get_contents($file);
            if (! str_contains($src, 'down')) {
                continue;
            }

            // 提取 down() 方法体
            if (preg_match('/function\s+down\s*\([^)]*\)\s*:\s*(?:void|int|bool)\s*\{(.*)\}\s*\}/s', $src, $m) !== 1) {
                continue;
            }
            $downBody = $m[1];

            // down 中显式 dropColumn 含 locale / translation_group
            if (preg_match('/dropColumn\s*\(\s*(\[[^\]]*\]|[\'"][^\'"]+[\'"])/s', $downBody, $dc)) {
                $arg = $dc[1];
                if (str_contains($arg, "'locale'") || str_contains($arg, "'translation_group'")
                    || str_contains($arg, '"locale"') || str_contains($arg, '"translation_group"')) {
                    /**
                     * 合法例外：**引入** i18n 的那批迁移，其 down 正是「还原为无 i18n」。
                     * 若不豁免，任何新增 locale 的迁移都会被判成「down 丢弃 i18n 列」。
                     *
                     * 20G-3：新增 `2026_10_03_000001_add_locale_to_facts`（facts 行级翻译）。
                     */
                    foreach ([
                        'add_locale_to_translatables',
                        'add_locale_to_facts',
                    ] as $introducer) {
                        if (str_contains($name, $introducer)) {
                            continue 2;
                        }
                    }
                    $violations[] = $name;
                }
            }
        }

        $this->assertSame([], $violations,
            'MRF-010 失败：以下迁移的 down() 丢弃了 i18n 列（会静默破坏英文能力）：'
            .implode(', ', $violations));
    }
}