# 20G-8-A / B · 全部 i18n 迁移动态往返 + 55 迁移全链路

**日期**：2026-10-03
**状态**：✅ **A 组 PASS（238/238）· B 组 PASS（44/44）· 全量回归 1375/1375**
**基线**：`D:\GEO-OS-rewrite\geo-website-os` @ `fd660b0` · 55 migrations

---

## 零、本轮最重要的结论

> **20G-8-C 报「0 new defects」是不完整的。**
>
> C 组只验证了「最后一步的 rollback」+「简单 4 行 canary 回滚」，
> B 组的**全链路**才暴露出 **4 个真实产品缺陷**。
>
> **教训**：迁移 Gate 的覆盖范围决定了能发现多少缺陷。
> 「回滚链最后一步通过」远不等于「回滚链完整可用」。

---

## 〇、最终结果

| Gate | 结果 | 明细 |
|---|---|---|
| **20G-8-A** 全部 i18n 迁移动态往返 | ✅ **238/238** | 15 个迁移 · 1 个 DELTA（语义不兼容的有意 skip） |
| **20G-8-B** 55迁移全链路 | ✅ **44/44** | 回滚链 55 步全通 · 重装零漂移 |
| **MRF 契约** | ✅ **10/10** | 36 断言 · 变异测试 3/4 捕获 |
| **全量回归** | ✅ **1375/1375** | 7231 断言 · 0 失败 |

**唯一 DELTA**：`09_23_000001` 回滚后唯一约束退化为不含 locale 形态，
canary 的双语数据（zh+en 同 slug）在该约束下**必然冲突** ——
属语义不兼容而非迁移缺陷（回滚本就是回到「迁移前的单语世界」），
该层数据保真由方向 A（空库）覆盖。

---

## 一、A 组：全部 i18n 迁移动态往返

工具：`docs/audit/20G/e2e/migration-roundtrip.php`

### 目标集从 2 个扩到 17 个

i18n schema 资产涉及 **17 个迁移**（不只 3 张重建表）。三档目标：

```
GEO_TARGET=rebuild（默认）  2 个重建型迁移
GEO_TARGET=i18n17 个 locale-bearing 表涉及的迁移
GEO_TARGET=all             55 个
GEO_ONLY=<子串>             精确定位单个
```

**Laravel 没有「migrate 到第 N 个」选项** —— 只有 `--path`。
精确验证第 N 个迁移的 up/down 只能把前 N 个文件复制到临时目录
（`stageMigrations()` + `--path` + `--realpath`）。

### 关键设计：断言基线必须是「迁移之前」而非「rollback 之前」

踩坑两次：

1. **方向 A 的 `exists` 断言**：建表迁移（`create_contents_table`）rollback 后
   该表**应该消失**，断言「仍存在」→ 假失败。修法：`tablesCreatedBy()` 识别新建表。
2. **方向 B 的 `B-schema.*`**：用 rollback **前**的快照当基线，
   于是「本迁移引入的 locale 列」rollback 后消失被判成失败（**17 条假失败**）。
   修法：另建「只跑到前序迁移」的库（`$snapPre`）当基线。

> **通则**：断言「rollback 后 X 仍在」时，基线必须是**迁移之前**的状态。

### 方向 B 需显式跳过早期迁移层

在 `create_contents_table` 这一层 `sites` 表还不存在 → 播种 SQL 硬依赖它会抛
`no such table` 中断整轮。必须前置检查，且不适用时**显式 SKIP 并说明原因**
（不能静默通过）。播种 INSERT 也要按 `PRAGMA table_info` 自适应。

---

## 二、B 组：55 迁移全链路 ✅

工具：`docs/audit/20G/e2e/migration-fullpath.php`

| 阶段 | 验证内容 | 结果 |
|---|---|---|
| B1 Fresh | 0 → 55 独立库跑满 | ✅ 27 表 / i18n 列齐全 / `UNIQUE(site_id,slug,locale)` / FK |
| B2 幂等 | 重复 migrate | ✅ 记录数不变、schema 完全一致 |
| B3 Upgrade | 停在 54 → 升到 55 | ✅ 无表丢失 / i18n 列保持 / 4 行双语数据全保留且互不相同 |
| B4 全量回滚 | 55 → 0 逐步 | ✅ **55 步全通** |
| B5 重装 | 回滚后再跑满 | ✅ 与首次 fresh **零 schema 漂移** |

**44/44 全绿**（约 5 分钟）。

---

## 三、抓到的 4 个真实产品缺陷（全部已修）

### 🔴 缺陷 1：`dropUnique(['col'])` 删不掉 SQLite 表级唯一约束

`2026_09_24_000018_add_form_links_to_inquiries` 的 down。

`->unique()` 在 SQLite 下建的是**表级**约束（`sqlite_autoindex_*`，
`PRAGMA index_list` 的 `origin=u`），而 `dropUnique(['submission_id'])`
找**命名索引**（`origin=c`）→ 报错。

**修法**：`dropIndex('inquiries_submission_id_unique')` +
`dropIndex('inquiries_form_id_index')` 再 `dropColumn`
（**dropIndex 必须先于 dropColumn**）。

### 🔴 缺陷 2：`09_23_000001` 的 down 同样问题

它的 up 是「建新表→改名」，unique 全是表级；down 里
`dropUnique('contents_slot_unique')` 按名找命名索引 → 必然失败。

**修法**：加 `downSqliteContents()`，显式重建表（禁 CTAS，见 C-16）。

### 🔴 缺陷 3：`dropColumn` 在 SQLite 上会丢其他唯一约束

`2026_09_17_000002_add_slot_to_contents` 试过 `$table->dropColumn('slot')`
—— SQLite 走表重建，但 **Laravel 不复制其他唯一约束**：
实测重建后只剩 `UNIQUE(site_id, slot)`，把 `UNIQUE(site_id, slug, locale)` 弄丢
（locale / translation_group 是 18F 引入的）。

**修法**：`downSqliteSlot()` 按**实际列**动态重建。

### 🔴 缺陷 4：回滚恢复成 `UNIQUE(slug)` 而非 `UNIQUE(site_id, slug)`

`09_23_000001::downSqliteContents()` 最初写的是 `UNIQUE(slug)`。

**空库回滚通过，有数据回滚必失败** —— 本库是多站点系统，
同一 slug 会在多个站点各有一行；回滚时 `INSERT INTO contents_down SELECT ...`
遇到「A 站中文页 + A 站英文页 slug 相同」（i18n 行级翻译模型的必然结果）
→ 唯一约束冲突。

**正确形态**（实测该迁移 up 之前的库确认）：`UNIQUE(site_id, slug)` +
`UNIQUE(site_id, slot)`。

> **这类缺陷只做「空库 up→down」永远测不出来。**
> 必须造「同 slug 跨站 / 跨语言」的多行数据再回滚。

---

## 四、⚠️ 最隐蔽的技术陷阱

### Laravel 吞异常，退出码仍是 0

迁移里的 SQL 异常被 `Illuminate\Foundation\Exceptions\Handler` 渲染成
HTML 打到 stdout，**进程退出码返回 0**。于是「code === 0」为真、实际已失败
→ 回滚链表面完整、实际残留索引。

**回滚成功判据必须三条同时满足**：
1. 退出码为 0
2. 输出无异常特征（`SQLSTATE` / `Exception` / `renderThrowable` / `<title>` / 无 `DONE`）
3. **`migrations` 表行数确实减少 1**（最硬的判据）

### 回滚到中间态时，表结构与 fresh 不一致

逐步回滚到 `add_slot` 时，`09_23_000001` 的 down 已把 `locale`/`translation_group`
删掉、`site_id` 也还没加入 → 此时 `contents` 只有 36 列、无 `site_id`。

**任何硬编码列清单的 downSqlite*() 都会报「no such column」**。
故重建逻辑必须读 `PRAGMA table_info` 按实际列动态生成 DDL。

### 迁移索引形态速查

```
$table->unique()/index() 在 SQLite 下的形态取决于所在迁移：
  · Schema::create（建表时）      → 表级 sqlite_autoindex_*（origin=u），不可按名 DROP
  · Schema::table（后续加列时）  → 命名索引 {table}_{col}_unique（origin=c），可按名 DROP
```

**升级库与 fresh 库形态可能不同** —— 必须实测 `PRAGMA index_list` 的 `origin`。

---

## 五、测试自身的缺陷（本轮修掉的）

| # | 缺陷 | 修法 |
|---|---|---|
| 1 | 断言基线用「rollback 前」而非「迁移前」 | 另建「只跑到前序迁移」的库当基线 |
| 2 | 建表迁移的 `exists` 断言误报 | `tablesCreatedBy()` 区分新建表 |
| 3 | 播种硬编码列名 → 早期迁移层报 `no such column` | 按 `PRAGMA table_info` 自适应 |
| 4 | 行为断言污染回滚场景 | 行为断言改在**独立副本库**上跑 |
| 5 | 未验证「rollback 真的撤销了目标迁移」 | 加前置判定（查 `migrations` 表） |
| 6 | 同一「回滚未生效」有两种原因被混为一谈 | 区分「down 真正失败」与「数据与回滚语义不兼容」 |

> **通则**：任何「为了验证约束而插入的数据」都不能与「验证回滚保真」共用一个库。
> 否则约束验证的副作用会被误判成回滚缺陷。

---

## 六、静态契约的已知盲区（变异测试诚实记录）

脚本：`docs/audit/20G/gates/mrf-mutation-test.sh`（4 个变异）

**3/4 被捕获**：

| 变异 | 结果 |
|---|---|
| `downSqliteContents` 漏删 `translation_group` | ✅ RED |
| `downSqliteContents` 漏删 `locale` | ✅ RED |
| `downSqlite` 丢 i18n 列（保留语义被破坏） | ✅ RED |
| **`upSqlite` 丢 `UNIQUE(site_id,slug,locale)`** | ❌ **GREEN（漏抓）** |

**结论：静态层只能守「分支级存在性」，守不住「实际约束行为」。**
后者必须靠动态层（真跑 up → 查约束 → 跑 down → 比对）。

### 判据被注释骗过的三种形态

1. **搜词被注释骗**：注释里也写着列名
2. **窗口搜被骗**：`DROP INDEX IF EXISTS contents_translation_group_index`
   恰好落在「array_diff 前后 160 字符」窗口内
3. **只看一侧**：`$excludes` 只判 locale → 变异「只删 tg 不删 locale」时漏抓

**正解**：`excludesColumn()` 抓出 `array_diff/array_filter/unset/if` 的
**配平括号块**，只在块内判断列名是否作为**字符串字面量**出现；且要求**两列都命中**。

---

## 七、有效迁移契约标准（Gate 制度）

```
Valid Migration Contract =
    Static Assertion          静态源码契约（CI 长期守）
  + Branch-specific Assertion 按重建分支分别校验（up/down 各自独立）
  + Dynamic Round-trip        真实跑 up → down，比对 schema/数据/约束行为
  + Mutation Verification     故意破坏，确认断言必然红
```

**缺任一条就会退化成「字符串存在即 PASS」**。

### 静态层的固有边界（不假装能覆盖）

| 情形 | 处置 |
|---|---|
| DDL 由类属性驱动（`$contentColumns`） | 豁免 + 断言「必须由该属性驱动」+ 动态层守住 |
| DDL 由运行时变量拼表名 | 同上 |
| DDL 跨行字符串拼接 | 正则须允许 `[\s\S]{0,80}?` 跨行 |
| 列声明两种写法 | 内联 DDL 与数组驱动都要认 |
| 一文件改多张表 | 按 `Schema::table('x', fn)` 块逐个判断表归属 |
| 回退分支的相反语义 | 分「删 i18n 列」与「保留 i18n 列」两种判据 |
| 实际约束行为 | **静态层守不住**，交动态层 |

---

## 八、交付物

| 文件 | 用途 |
|---|---|
| `docs/audit/20G/e2e/i18n-schema-inventory.php` | i18n schema 资产扫描（8 张表 / 约束 / 索引 / FK / 迁移归属） |
| `docs/audit/20G/e2e/migration-roundtrip.php` | 逐迁移独立库 up→down 往返（三档目标集） |
| `docs/audit/20G/e2e/migration-fullpath.php` | 55 迁移全链路（Fresh/幂等/Upgrade/回滚/重装） |
| `docs/audit/20G/gates/mrf-mutation-test.sh` | MRF 契约变异测试 |
| `tests/Feature/MigrationRollbackFidelityTest.php` | MRF-001~010 长期 CI 契约 |

---

## 九、状态与后续

```
20G-8-C  Migration i18n Rollback Fidelity     ✅ PASS
20G-8-B  55 迁移全链路                          ✅ PASS 44/44
20G-8-A  全部 i18n 迁移动态往返                 ✅ PASS 238/238（15 个迁移）
20G-8-D  Site × Locale 四象限E2E               ⏸ 待跑
20G-8-E  Cache 失效确定性                      ⏸ 待跑
20G-8-F  旧 20G-5 双站 30 断言                 ⏸ 待跑
20G-8    Baseline Transition                  ⏳ NOT YET CLOSED
```

**Release 阻塞项**：
1. ~~Migration i18n 列回滚保真~~ ✅ B 组已封口
2. Facts localization（20G-3，P1）
3. Site Administration / `/admin/sites`（UX-001，P1）
4. New Site Bootstrap（UX-002，P1）