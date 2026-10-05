# RC-2 · Final Fresh / Upgrade / Rollback

> **状态**：✅ **PASS**
> **完成时间**：2026-10-04 20:04 (GMT+8)
> **基线**：RC-0 `docs/audit/RC/RC-0-Freeze-Snapshot.md`（RC-1 通过的同一工作树）
> **工具**：`docs/audit/RC/rc2-fresh-upgrade-rollback.php`
> **原始日志**：`D:/Temp/rc2_v6.log` · **机器可读**：`D:/Temp/rc2_result.json`

---

## 1. 结论

```text
RC2_TOTALASSERTIONS   95
RC2_PASS              95
RC2_FAIL              0
EXIT_CODE             0
UNEXPECTED_5000

FRESH                 ✅ 34/34
UPGRADE               ✅ 24/24
ROLLBACK              ✅ 37/37
```

| 判定项 | 结果 |
|---|:---:|
| FRESH | ✅ |
| UPGRADE | ✅ |
| ROLLBACK | ✅ |
| Schema | ✅ |
| Data | ✅ |
| i18n | ✅ |
| Multi-site | ✅ |
| GEO | ✅ |
| Cache | ✅ |
| C-16 | ✅ |
| C-17 | ✅ |
| 开发库污染 | **0** |
| 产品代码修改 | **0** |

---

## 2. 边界合规（逐条核实）

| 边界 | 要求 | 实际 | 判定 |
|---|---|---|:--:|
| 工作树 | 与 RC-1 通过后同一状态 | HEAD `fd660b0` / `main` / 79 项变更 | ✅ |
| 产品代码 | 0 修改 | 79 项（与 RC-0/RC-1 完全一致） | ✅ |
| 开发库 | 只读 | applied 恒为 54，脚本全程未访问 | ✅ |
| 临时库 | 项目目录外 | `D:/Temp/rc2_v6/` | ✅ |
| 旧 20G-6 | 仅参考，不复用 PASS | 新写RC-2 专用脚本 | ✅ |

> **说明**：本轮**未修改 `g6-*.php`**。它们写于 20G-6 时代，硬编码
> 「41 迁移 / 27 张表 / 回滚到 40」，现在是 56 迁移 / 41 张表。
> 复用会导致**误判 FAIL**（41 现在是表数不是迁移数，极易混淆）。
> 保留原脚本作为历史证据，另建 RC-2 脚本，所有数字断言**动态取实际值**。

---

## 3. 迁移四态（实测）

```text
MIGRATION_FILES          56
FRESH_APPLIED            56     空库 → 56/56
PREV_RELEASE             55     停在 2026_10_02_000001
AFTER_UPGRADE            56     55 → 56
AFTER_ROLLBACK           55     56 → 55（--step=1）
TABLES (fresh)           41
```

回滚最硬判据（`migrations` 表行数）：`56 → 55` 确实减少 1，
而非仅看退出码（退出码会被 Laravel 吞异常伪造）。

---

## 4. RC2-FRESH（34/34）

```text
空数据库 → 56/56 migrations → 四象限 canary → zh-CN/en → GEO outputs
```

| 断言 | 结果 |
|---|:--:|
| F-01 `artisan migrate` 退出码 0 | ✅ |
| F-02 输出非空且无异常特征 | ✅ |
| F-03 迁移文件 = registered = applied（56） | ✅ |
| F-04 建表 41 张 | ✅ |
| F-05 `contents.locale` / `contents.translation_group` | ✅ |
| F-05 `entities.locale` / `seo_metas.locale` | ✅ |
| F-05 `facts.locale` / `facts.translation_group` | ✅ |
| F-06 facts `UNIQUE(site_id, key, locale)` | ✅ |
| F-07 contents `UNIQUE(site_id, slug, locale)` | ✅ |
| F-08a BelongsToSite 23 个模型对应表全部存在 | ✅ |
| F-08 全部有 `site_id` | ✅ |
| F-09 **C-16** contents `UNIQUE(site_id, external_id)` | ✅ |
| F-10 **C-17** 索引形态已识别（contents 9 个索引） | ✅ |
| F-11 四象限 content 播种（同slug × 2 站 × 2 语言） | ✅ |
| F-12 同站 zh+en 同 slug 共存（2 行） | ✅ |
| F-13 **`UNIQUE(site_id,key,locale)` 行为层生效**（重复被拒） | ✅ |
| F-14 ~ F-20 GEO 端点（真实 HTTP 内核） | ✅ |

### GEO 端点（Fresh）

```text
/geo.json200   sha=d73aca787d5c
/llms.txt      200      sha=410db0255936
/sitemap.xml   200      sha=e4dde5605567
/feed.xml      200
/en/geo.json   200      （与 zh 输出 sha 不同 → 语言维度生效）
```

---

## 5. RC2-UPGRADE（24/24）

```text
上一版本（55 迁移）→ RC（56 迁移）→ 数据/Site×Locale/GEO 语义保持
```

| 断言 | 结果 |
|---|:--:|
| U-01 旧版本库建立（迁移至 `2026_10_02_000001`） | ✅ |
| U-02 旧版本迁移数 = 55 | ✅ |
| U-03 升级前 facts **无** locale 列（确属旧版本） | ✅ |
| U-04 升级退出码 0 | ✅ |
| U-05 输出无异常特征 | ✅ |
| U-06 升级后迁移数 = 56 | ✅ |
| U-07 无 pending | ✅ |
| U-08 contents 行数保持 | ✅ |
| U-09 facts 行数保持 | ✅ |
| U-10 facts 新增 `locale` 列 | ✅ |
| U-11 facts 新增 `translation_group` 列 | ✅ |
| U-12 **facts 存量行 locale 已回填（非 NULL）** | ✅ |
| U-13 **facts 存量行 translation_group 已回填** | ✅ |
| U-14 升级后 `UNIQUE(site_id,key,locale)` 生效 | ✅ |
| U-15 升级后 `/geo.json` 200 | ✅ |
| U-16 升级后 `/en/geo.json` 200 | ✅ |
| U-17 zh / en 输出不同（语言维度保持） | ✅ |
| U-Q1~Q7 四象限验证 | ✅ |

> **U-12 / U-13 是本轮最关键的升级保真证明**：存量行必须被回填，
> 而不是留NULL。回填失败会让「已有中文事实」在英文站凭空多出一份。

---

## 6. RC2-ROLLBACK（37/37）· 本轮最重要

```text
RC schema/data（56）→ rollback --step=1 → previous schema/data（55）
```

### 6.1 回滚生效（三重判据）

| 判据 | 结果 |
|---|:--:|
| 退出码 0 | ✅ |
| 输出无 SQLSTATE / Exception | ✅ |
| **`migrations` 表行数确实减少（56 → 55）** | ✅ |

### 6.2 i18n 保真（方向已按down 语义校正）

`2026_10_03_000001` 的 down 语义 = **把 facts 还原成「单语世界」**，
因此正确判据是「**回到迁移前 schema**」，而非「列还在」：

| 断言 | 期望 | 结果 |
|---|---|:--:|
| R-11 回滚后 `facts.locale` **消失** | ✅ 正确撤销 | ✅ |
| R-12 回滚后 `facts.translation_group` **消失** | ✅ 正确撤销 | ✅ |
| R-12b `facts` 基础列仍在（key/label/value 未误删） | ✅ | ✅ |
| R-13 回滚后 `contents.locale` **仍在** | ✅ 不受本轮影响 | ✅ |
| R-14 回滚后 `contents.translation_group` **仍在** | ✅ | ✅ |

> `contents` 的 i18n 列来自第 43 号迁移（`2026_09_23`），
> 本轮回滚的是第 56 号，**不受影响是正确的**。

### 6.3 数据保真

| 断言 | 结果 |
|---|:--:|
| R-15 回滚后 facts 中文基线行完整保留（2 行） | ✅ |
| R-16 英文行按 down 语义撤销（还原单语世界） | ✅ |
| R-16b 保留行与回滚前**逐行一致**（中文零丢失） | ✅ |

### 6.4 C-16 / C-17 回归锁

| 断言 | 结果 |
|---|:--:|
| R-18 L1 含 `UNIQUE(site_id, external_id)` | ✅ |
| R-19 回滚本轮迁移后该约束**仍在**（未被误伤） | ✅ |
| R-20 **行为层**：同站同 external_id 仍被拒 | ✅ |
| R-23 **C-17** menus 索引形态正确（无错名残留） | ✅ |

> **关键修正**：本轮 `rollback --step=1` 撤销的是第 56 号迁移，
> 而 `external_id` 约束来自第 55 号 —— **本就不该被撤销**。
> C-16 的真正回归锁是「约束在 up/down 两态都存在且行为正确」，
> 而非「本轮必须消失」。R-20 用**行为层**（重复插入被拒）证明，
> 比只看 DDL 文本更硬。

### 6.5 Schema 保真

```text
R-21  contents 含site_id 级唯一约束（多站隔离地基未丢）✅
R-22  facts 唯一约束回到迁移前形态 UNIQUE(site_id, key)  ✅
```

### 6.6 四象限（回滚后仍成立）

```text
R-Q1  四象限 content 共 4 行                    ✅
R-Q2  四象限内容互不相同（无跨站/跨语言串档）    ✅
R-Q3  A 站 2 行 / B 站 2 行分布正确             ✅
R-Q4  A/zh=1  A/en=1  B/zh=1  B/en=1            ✅
R-Q5  facts 回滚后中文基线 2 行且值互不相同      ✅
R-Q6  唯一约束语义：同站同语言维度不重复          ✅
R-Q7  translation_group 已随 down 撤销          ✅
```

### 6.7 派生输出（DELTA · 非缺陷 · 已有三库对照实证）

`/geo.json` 在回滚态返回 **500**，根因 `no such column: facts.locale`。

**三库对照（同一份代码）**：

| 库 | facts.locale | `/geo.json` |
|---|---|---:|
| `fresh.sqlite` | 存在 | **200** |
| `upgrade.sqlite` | 存在 | **200** |
| `rollback.sqlite` | **已随 down 撤销** | 500 |

**判定：DELTA，不是产品缺陷。**

- 本场景是「schema 已回滚 + 代码仍是 RC 态」的**混合态**；
  真实回滚中代码会与 schema 一起回退到对应版本。
- 若为真实缺陷，fresh / upgrade 同样会 500 —— **它们没有**。
- 同一场景下 `/sitemap.xml`、`/llms.txt`、`/feed.xml`（均不查 facts.locale）
  全部 200，恰好反证500 的成因就是「列已撤销」。

> 归入 `Environment / Test Scenario`（与 ENV-001 同类），
> **不进入 C 系列 Finding Ledger**。

---

## 7. 本轮修掉的 6 个「脚本自身缺陷」

全部是**测试脚本问题，不是产品缺陷**。但它们一度伪装成产品失败，
故记录以防重犯：

| # | 症状 | 根因 | 修法 |
|---|---|---|---|
| 1 | 子进程 `migrate` 从未执行（退出码 255，库0 张表） | runner 缺 `require vendor/autoload.php` | 参照已验证的 `migration-fullpath.php` runner 补autoload + 改用 `ArgvInput`/`$kernel->handle()` |
| 2 | `Array to string conversion` | `$_SERVER` 含 `argv` 数组，混入 `proc_open` 的 env | env 白名单：只取标量 |
| 3 | F-02 / F-08 **假阳性** | 输出为空、表不存在时被静默 `continue` 判PASS | 显式记账：`trim(out) !== ''`、缺表计入 `missingTables` |
| 4 | F-08a 误报 `audit_log` vs `audit_logs` | 模型名转表名未做复数化 | 读模型自带 `$table` + snake + `s`/`es`/`y→ies` 候选匹配 |
| 5 | Q7 断言方向反| 误以为「每行独立 translation_group」 | 行级翻译模型正解：**同 key 多语言行共享同一 TG**（按 key 派生） |
| 6 | R-11/R-12/R-19 断言方向反 | 误以为「回滚后列应还在」/「本轮迁移的约束该消失」 | 回滚判据= **回到迁移前 schema**；约束按**来源迁移**判定归属 |

### 全角括号吞变量（本轮踩坑第3 次，已批量修）

```text
"迁移数（$nFiles）"  →  PHP 把（全角+内容）吃进变量名
```
PHP 变量名允许 0x80-0xFF 字节，`（` 紧跟 `$var` 时会被当作变量名的一部分。
**修法**：变量与全角字符之间加空格断开插值；或改用字符串拼接。
> 已在 RC-2 脚本中批量修正（12 处），并全量复检为 0。

---

## 8. 关键方法论沉淀

### 8.1 「回滚失败」不等于「产品有缺陷」

本轮 R-15~R-19 一开始全红，但逐条核实后**全部是断言方向错**：

| 现象 | 真实原因 | 判定 |
|---|---|---|
| facts 4 行变 2 行 | down 语义 = 还原单语世界，英文行本就是该迁移的产物 | ✅ 正确行为 |
| `facts.locale` 消失 | 这正是 down 该做的事 | ✅ 正确行为 |
| `external_id` 约束还在 | 它来自**上一个**迁移，本轮不该动| ✅ 正确行为 |
| `/geo.json` 500 | 混合态（schema 已退、代码未退） | DELTA，非缺陷 |

> **判据**：断言回滚结果前，必须先确认「**这个迁移的 down 语义是什么**」
> 以及「**被断言的约束/列来自哪个迁移**」。
> 否则会把正确行为判成缺陷，或把缺陷判成正确。

### 8.2 行为层断言强于 DDL 文本断言

```text
R-19（DDL 文本）  external_id UNIQUE 存在 → 只能证明「有这条约束」
R-20（行为层）    同站同 external_id 重复插入被拒 → 证明「约束真的生效」
```

**优先用行为层**（实际写入 +观察数据库反应）证明约束，
它无法被「注释里有这段文字」或「字符串匹配到了」欺骗。

### 8.3 三库对照是最强的「非缺陷」证明

同一份代码跑三个库（fresh / upgrade / rollback），
若只有 rollback 态失败，其余两者正常 →
**失败必然来自 schema 状态差异，而非代码缺陷**。

---

## 9. RC 序列进度

```text
RC-0  Release Freeze✅ PASS / FROZEN
RC-1  Full Regression              ✅ PASS  1401/7290/0/0/0
RC-2  Fresh / Upgrade / Rollback   ✅ PASS  95/95
RC-3  Site × Locale 四象限最终验收  ▶ NEXT
RC-4  Security / GEOFlow Contract⏭
RC-5  Installation / Operational UX ⏭
RC-6  Release Artifact Audit        ⏭
RC-7  Final Diff Review → commit → v1.0.0 tag  ⏭
```

**OPEN 0 · VERIFY 0 · BLOCKERS 0 · 产品代码修改 0 · 开发库污染 0**
