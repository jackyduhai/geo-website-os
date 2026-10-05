# 20G-8-C · Migration i18n Rollback Fidelity

**日期**：2026-10-03
**状态**：✅ **PASS**
**基线**：`D:\GEO-OS-rewrite\geo-website-os` @ `fd660b0` · 55 migrations · WORKTREE DIRTY

---

## 零、Gate 定义

> 任何涉及表重建的迁移，在 `up → down` 往返过程中，都不能破坏既有 i18n schema、数据和约束。
> 判据不是「表能不能rollback」，而是「**rollback 后 Site × Locale 二维数据模型是否仍然成立**」。

---

## 一、C-1基线锁定

```
PROJECT_ROOT : D:/GEO-OS-rewrite/geo-website-os
GIT_HEAD     : fd660b0
MIGRATIONS   : 55
WORKTREE     : 合并后 DIRTY
```

旧基线（`D:\734666\GEO OS` @ `91aef17` / 41 migrations）已删除，
其迁移相关结论**只作历史证据**，不参与本 Gate 判断。

---

## 二、C-2 i18n schema 资产全量扫描

工具：`docs/audit/20G/e2e/i18n-schema-inventory.php`（从**真实迁移后的库**提取，不读源码推测）

### 8 张 locale-bearing 表

| 表 | i18n 列 | 唯一约束 | 外键 |
|---|---|---|---|
| `contents` | `locale` `translation_group` | `(site_id,slug,locale)` `(site_id,slot)` `(site_id,external_id)` | `site_id → sites.id` |
| `entities` | `locale` `translation_group` | `(site_id,type,slug,locale)` | `site_id → sites.id` |
| `seo_metas` | `locale` | 3 个 partial unique（含 locale） | — |
| `pages` | `locale` `translation_group` | `(site_id,slug,locale)` `(site_id,system_key,locale)` partial `(site_id,entity_id)` | — |
| `search_documents` | `locale` | `(resource_type,resource_id,site_id,locale)` | — |
| `form_fields` | `locale` | — （索引 `form_id,locale,sort_order`） | — |
| `form_submissions` | `locale` | — | — |
| `search_index`（FTS 虚表） | `locale` | — | — |

> **`settings` 表不在清单内**：`settings` 从设计之初就是**全局单站表**（`key` 全局 UNIQUE、无 `site_id`），
> `SiteScope` 与 `BelongsToSite` 均已 `hasColumn` 防护。不是遗漏，是有意设计。
>
> `facts` 表也无 `locale`（20G-3 待办项，非本Gate 范围）。

### 8 个 i18n 相关迁移

```
2026_09_23_000001_add_locale_to_translatables            ⚠️ 重建 contents+entities+seo_metas
2026_09_23_000010_create_pages_table
2026_09_23_000012_add_page_id_to_seo_metas_table
2026_09_23_000014_fix_site_seo_unique_exclude_page
2026_09_24_000015_add_system_fields_to_pages_table
2026_09_24_000016_create_search_index
2026_09_24_000017_create_form_tables
2026_10_02_000001_add_external_id_unique_to_contents      ⚠️ 重建 contents
```

### 重建风险表（3 张）

| 表 | 重建迁移 | 风险 |
|---|---|---|
| `contents` | `09_23_000001` + `10_02_000001` | **最高** —— 两次 RENAME 换表 |
| `entities` | `09_23_000001` | 高 |
| `seo_metas` | `09_23_000001` | 高 |

---

## 三、C-3 / C-7 逐迁移独立库往返验证

工具：`docs/audit/20G/e2e/migration-roundtrip.php`

### 为什么必须独立库（三个踩过的坑）

1. 同一库反复 migrate/rollback 会踩 PDO 文件锁 —— Windows 下 `unlink` 不掉打开中的 SQLite
2. `SiteScope::$eligibleMemo` 会记住「某表无 `site_id` 列」，迁移改结构后不重查会读到脏判断
3. `DB_DATABASE` env 在 bootstrap 之后才生效，脚本内 `config()` 切库会打到旧库

故：**建库一律走 artisan CLI（独立进程 + `proc_open` 显式传 env），脚本只做验证**。
静态读取用 PDO 直连，不经过 Laravel，杜绝应用层污染。

### 方向 A：空库往返

全量 migrate → snapshot → `rollback --step=1` → snapshot，
断言「**原本就存在的** i18n 资产不得丢失」。

### 方向 B：双语 canary 数据往返

播种**四象限 canary**（same slug + different content）：

```
site A | shared-slug | zh-CN | TG-001 | HASH-A-zh
site A | shared-slug | en    | TG-001 | HASH-A-en
site B | shared-slug | zh-CN | TG-002 | HASH-B-zh
site B | shared-slug | en    | TG-002 | HASH-B-en
```

rollback 后比对：**按 `translation_group` 定位**、逐字段（PK/site_id/slug/locale/translation_group/content_hash）全等。

### 结果

| 迁移 | 方向 A | 方向 B | 合计 |
|---|---|---|---|
| `2026_10_02_000001_add_external_id_unique_to_contents` | 17 PASS | 18 PASS | **35/35** ✅ |
| `2026_09_23_000001_add_locale_to_translatables` | 17 PASS | 18 PASS | **35/35** ✅ |

---

## 四、C-5 双语数据保真

```
B-data.identical        rollback 前后播种的双语数据行（2站×2语言）完全一致
B-data.quadrant-present rollback 后四象限行齐全（A/zh、A/en、B/zh、B/en）
B-data.quadrant-distinct 四象限行内容互不相同（Site × Locale 未串）
```

**⚠️ 判据修正（实测踩坑）**：不可全表比对。
上方的行为型约束会额外插入验证行，全表比对会把约束验证的副作用
误判为数据漂移。必须**只比对播种的4 行**。

---

## 五、C-6 行为型约束（不依赖 `sqlite_master` 静态观察）

| 用例 | 期望 | 结果 |
|---|---|---|
| 同站 + 同语言 + 同 slug | UNIQUE **拒绝** | ✅ |
| 同站 + 同 slug + **不同语言** | **允许**（locale 参与唯一键） | ✅ |
| 不同站 + 相同 slug | **允许**（UNIQUE 含 site_id） | ✅ |
| rollback 后重复插入 | UNIQUE **仍拒绝** | ✅ |

---

## 六、C-8 长期 CI 契约

工具：`tests/Feature/MigrationRollbackFidelityTest.php`（MRF-001~010，34 断言）

| 编号 | 守什么 |
|---|---|
| MRF-001 | 迁移清单基线 = 55（数量变化必须有人知道） |
| MRF-002 | 禁止 `CREATE TABLE ... AS SELECT`（CTAS） |
| MRF-003 | 每个迁移必须有 `down()` |
| MRF-004 | 重建 `contents` 的**每个重建分支**必须声明 `locale` / `translation_group` |
| MRF-005 | `contents` slug 唯一约束必须含 `locale` |
| MRF-006 | 重建必须保留主键自增 / slot 唯一 / site_id 外键 |
| MRF-007 | `menus.parent_key` 的 down() 删净全部索引（C-17） |
| MRF-008 | `translation_group` 索引必须声明（丢了不报错但全表扫 = 静默劣化） |
| MRF-009 | i18n 资产清单守门：新增带 `locale` 的表必须登记 |
| MRF-010 | `down()` 不得丢弃 i18n 列（唯一例外：`add_locale_to_translatables`） |

### 修正 20G-6 遗留的假阳性断言

**旧 `G6-RB-005` 是废的**：它断言迁移源码含 `UNIQUE(site_id, slug)`，
而该字符串只出现在迁移的**注释文字**里（描述 C-16 缺陷的历史）。
真实 DDL 早已改成 `UNIQUE(site_id, slug, locale)`。
断言因匹配到注释而**恒真** —— 什么都没守住。

新 MRF 全部先**剥离注释**再断言。

### 变异测试：证明契约有牙

不验证「变异后会不会失败」的断言，等于没有断言。做了三轮：

| 变异 | 结果 |
|---|---|
| 删除 `upSqlite()` 的 `locale` / `translation_group` 两列 + 改用非 locale 唯一约束 | ✅ MRF-004 + MRF-005 精确指出 `upSqlite()` |
| 破坏 `upSqlite()` 唯一约束 | ✅ MRF-005 捕获 |
| 破坏 `downSqlite()` 唯一约束 | ✅ MRF-005 捕获 |
| 恢复原状 | ✅ 10/10 全绿 |

---

## 七、两次变异测试暴露的断言缺陷（我的失误，已修）

### 失误 1：文件级断言被另一分支掩盖

把 `up()` 的 i18n 列删掉后，**文件级断言仍全绿** —— 因为 `down()` 里
还留着正确写法，「是否存在」被掩盖。

**修法**：改为**按方法体分别校验**。

### 失误 2：只看 `up()` / `down()` 拿不到 DDL

`2026_10_02_000001` 的 `up()` 只是 `if (sqlite) { $this->upSqlite(); return; }`，
真正的 DDL 在私有 `upSqlite()` / `downSqlite()` 里。取到的方法体没有
`CREATE TABLE` → 断言数为 0 → PHPUnit 标 **Risky**。

**修法**：新增 `rebuildBodies()`，扫出**所有**含 `CREATE TABLE` 的方法
（含 protected/private）逐个校验。

### 失误 3：静态层看不穿「属性驱动的 DDL」

`09_23_000001::rebuildContents()` 的列定义来自**类属性** `$contentColumns`
（声明在类顶部），方法体内只有 `foreach ($this->contentColumns as ...)`。

静态正则无解。**处置**：静态层豁免该方法，但断言「它必须由 `$contentColumns`驱动」
（若改成不可静态校验的写法反而要报警），并明确该迁移的 i18n 保真
**由动态层往返验证守住**。不假装静态层能覆盖。

### 失误 4：MRF-009 正则跨表误匹配

`create_form_tables` 同时给 `forms` / `form_fields` / `form_submissions`
三张表加列，只有后两张有 `locale`。文件级判断误报 `forms`。

**修法**：按 `Schema::table('x', fn)` 块逐个判断表归属。

---

## 八、工具踩坑记录（Windows）

| 坑 | 修法 |
|---|---|
| `exec()` 走 cmd.exe，不支持 `VAR=value cmd` 前缀（报 `'GEO_ROOT' is not recognized`） | 改用 `proc_open` 显式传 `$env` 数组 |
| 播种时撞 `sites.is_default` 唯一约束（迁移已建 default 站） | 复用已有 default 站作 Site A，只新增 Site B |
| 迁移后触碰 Eloquent（`Setting::allCached()`）抛 `no such column: settings.site_id` | 静态扫描一律 `DB::select` / PDO 直连，不碰应用层 |

---

## 九、PASS 标准核对

```
✓ 当前 55 migrations inventory 已确认              MRF-001 + inventory 脚本
✓ 所有 locale-bearing 表已识别                8 张，扫描产出
✓ 所有相关 rebuild migration 已识别               2 个重建迁移 / 3 张表
✓ up 后 locale / translation_group 未丢失方向 A 17 PASS
✓ down 后既有 locale schema 未丢失                  方向 A 17 PASS
✓ bilingual rows 未丢                                 B-data.identical
✓ site isolation 未破坏                              B-behavior.same-slug-diff-site
✓ locale isolation 未破坏                            B-behavior.same-site-cross-locale
✓ UNIQUE 未丢                                        B-behavior.after-rollback
✓ FK 未丢                                            MRF-006
✓ 非本迁移 index 未丢                                MRF-008
✓ 行为型约束全部符合预期                             C-6 四项全绿
✓ 独立数据库往返验证通过                             2 迁移 × 35 断言
✓ CI 契约永久覆盖MRF-001~010（变异测试证明有牙）
✓ 全量回归继续 0 failures                           1375 / 7229断言全绿
```

全量回归：**1375 tests / 7229 assertions，0 失败 0 错误**（23 分 45 秒）。
比上一轮 1370 多 5 个 —— MRF 的 10 个新用例替换了旧 G6-RB 的 5 个。

---

## 十、新发现缺陷

**0 new defects。**

本Gate 未发现新的实现缺陷。发现并修复的是**测试自身的假阳性**（旧 G6-RB-005）
与 **4 处断言设计缺陷**（见第七节），均属测试层，不计产品缺陷。

Ledger 编号无需递增。

---

## 十一、后续

```
20G-8-C  Migration i18n Rollback Fidelity       ✅ PASS（本 Gate）
   ↓
20G-3   i18n Integrity                          ▶ NEXT
         Facts 行级 locale（P1，Release Blocker）
         Setting Field Contract + 4 键 locale 化（P2）
   ↓
20G-7.1 Site Administration + New Site Bootstrap ▶ NEXT（UX-001 / UX-002）
   ↓
Site × Locale 四象限 E2E
   ↓
Release Candidate
```

### 遗留边界（诚实声明）

| 项 | 状态 |
|---|---|
| `09_23_000001::rebuildContents()` 的静态断言 | **豁免**（属性驱动 DDL），由动态层守住 |
| 非重建型i18n 迁移（`pages` / `form_fields` / `search_documents` 等 6 个）的 `down()` 行为 | 仅源码契约（MRF-002/003/010），**未做逐迁移动态往返** |
| 55 个迁移的 fresh / upgrade 完整链路 | 本轮只验证了最后一步的 rollback；全链路重跑仍待 20G-8 C 组扩展或单列 Gate |

**建议**：下一轮把这6 个 i18n 迁移也纳入 `migration-roundtrip.php` 的验证目标
（改 `GEO_ONLY` 为空时的默认集合即可，当前默认只跑重建型）。
