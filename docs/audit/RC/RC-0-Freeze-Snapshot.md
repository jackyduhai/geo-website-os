# RC-0 · Release Freeze & Snapshot

> **状态**：✅ **FROZEN**
> **生成时间**：2026-10-04 18:47 (GMT+8)
> **性质**：RC-1 ~ RC-7 的**唯一参照基线**
> **纪律**：本快照之后**任何**代码变更都必须重新产生 RC 证据，不得引用旧快照的 PASS。

---

## 1. Snapshot

```text
PROJECT              D:\GEO-OS-rewrite\geo-website-os
HEAD                 fd660b0
BRANCH               main
WORKTREE             DIRTY（未 commit / 未 tag / 未 push）
RELEASE PHASE        RC / Final Hardening
```

### 变更规模（`git diff --stat`，仅已跟踪文件）

```text
43 files changed, 1846 insertions(+), 310 deletions(-)
```

未跟踪（`??`）关键新增：

```text
app/Http/Middleware/RejectMalformedUtf8.php
app/Services/Geo/LlmsSanitizer.php
app/Services/Sync/SyncDisabledException.php
app/Services/Sync/SyncValidationException.php
app/Support/CacheInvalidationMap.php
app/Support/ContentFieldContract.php
app/Support/SlugSuggester.php
database/migrations/2026_10_02_000001_add_external_id_unique_to_contents.php
database/migrations/2026_10_03_000001_add_locale_to_facts.php
database/seeders/SiteStructureSeeder.php
docs/audit/20G/     （20G 全部审计文档）
tests/Feature/      （14 个新增测试文件）
```

> **WORKTREE = DIRTY 是预期状态**。所有 20G 实施变更尚未落库。
> 从 RC-7 起才执行唯一一次正式 release commit + `v1.0.0` tag。

---

## 2. 迁移三口径（RC 前独立库实证，禁止推测）

| 口径 | 数值 | 核实方式 |
|---|---:|---|
| `MIGRATION_FILES` | **56** | 磁盘 `database/migrations/*.php` 文件数（含 3 个 Laravel 默认 `0001_01_01_*`） |
| `MIGRATION_REGISTERED` | **56** | 独立库 `artisan migrate` 全部执行，`migrate:status` 0 pending |
| `MIGRATION_APPLIED (fresh)` | **56** | 独立临时库 `migrations` 表行数 |
| `DEVELOPER_DB_APPLIED` | **54** | 开发库 `database/database.sqlite` 只读统计 |
| `DEVELOPER_DB_LAG` | **2** | 开发库落后 2 个迁移 |

**开发库缺失的 2 个迁移**：

```text
2026_10_02_000001_add_external_id_unique_to_contents   （20G-6 · C-16 修复）
2026_10_03_000001_add_locale_to_facts                 （20G-3 · Facts 行级翻译）
```

**开发库附加状态**：`MAX_BATCH = 1`

### ⚠️ 口径纪律

- **「55」已作废**。它是 20G-8-B 报告时的数字，其后 20G-3 新增了
  `2026_10_03_000001_add_locale_to_facts` → 现为 **56**。
- **禁止**在未跑独立库的情况下断言 registered / applied 数量。
- **禁止**为核实迁移数去 migrate 开发库。RC-0 核实用
  `D:/Temp/rc0_verify/` 独立临时库，**开发库全程只读、未污染**。
- 开发库 lag=2 **不阻塞** RC：测试用库由 phpunit 独立创建，
  且 fresh/upgrade/rollback 全部在独立库验证（见 RC-2）。

---

## 3. 环境

```text
PHP                8.4.25 (cli) NTS Visual C++ 2022 x64
Node               v22.22.2
composer.json      php ^8.4 · laravel/framework ^12.0
DB_CONNECTION      sqlite（开发库 = database/database.sqlite）
APP_ENV            local
CACHE_STORE        database
remote             git@github.com:jackyduhai/geo-website-os.git
.env.production.example存在（SITE_DEFAULT_FALLBACK=false 多站安全默认）
```

---

## 4. 测试基线

```text
Tests              1401（20G-7.1 结束时）
Assertions         7290
Failures           0
Errors             0
测试文件            tests/Feature 132 + tests/Unit 7 = 139 个文件
```

**口径**：用例数（`Tests:`）与断言数（`assertions`）**不可混算**。
RC-1 将在**冻结后的同一工作树**上重跑全量并重新取数。

---

## 5. Gate 状态

```text
20G-0      ✅ CLOSED    基线对账
20G-0.1    ✅ CLOSED    P1 闭环 + Finding Ledger
20G-1      ✅ CLOSED    Security / Rendering
20G-2      ✅ CLOSED    GEOFlow Contract
20G-3      ✅ CLOSED    Facts Localization
20G-4      ✅ CLOSED    Derived Output Integrity
20G-5      ✅ CLOSED    双站 E2E
20G-6      ✅ CLOSED    Fresh / Upgrade / Rollback
20G-7      ✅ CLOSED    Human Simulation
20G-7.1    ✅ CLOSED    Site Administration + New Site Bootstrap
20G-8      ✅ CLOSED    Baseline Transition（A~F 六组）
```

### 生命周期终态（防止未来误reopen）

| 条目 | 终态 | 性质 |
|---|---|---|
| **UX-001**（`/admin/sites` 404） | ❌ **INVALID** | **原发现本身错误**，非被修复。真实 route/UI/E2E 证伪 |
| **UX-002**（新站初始化） | ✅ **CLOSED** · 20G-7.1 | `SiteStructureSeeder` 接入两条建站路径 |
| **C-9** | ✅ **CLOSED** · 20G-3 | **实证关闭**（有实现 + 测试） |
| **C-10** | ✅ **CLOSED** · 20G-3 | 依赖项 C-9 已关闭，随之关闭 |
| **C-15** | ❌ **INVALID** | **判定错误**（entities 实存 UNIQUE 索引） |
| **C-16** | ✅ **CLOSED** · 20G-6 | 实证关闭 |
| **C-17** | ✅ **CLOSED** · 20G-6 | 实证关闭 |

> C-9/C-10/C-16/C-17 是**实证关闭**；UX-001 / C-15 是**判定本身错误**。
> 二者性质不同，**不得混写**，也不得因台账旧措辞而重开。

**唯一权威台账**：`docs/audit/20G/20G-0.1-Finding-Ledger.md`
（台账正文仍带旧基线 `91aef17` 措辞，冲突处以本文件为准）

---

## 6. Release 状态

```text
OPENFindings        0
VERIFY              0
RELEASE BLOCKERS    0
```

### KNOWN NON-BLOCKING（明确接受，不阻塞 v1.0）

| 项 | 定级 | 说明 |
|---|---|---|
| Settings localization 4 键 | **P2** | `site_name` `site_description` `brand_display_name` `contact_address`。**`site_name` 进入 `<title>`**，故须进 Release Note |
| Categories/Groups `*_en` 字段级 | **INFO** | `Catalog.php` 30+ 处字段级回退，双语言够用 |

> **RC-7 前必须对P2 做正式决定**（A. v1.0 修掉/ B. 明确接受为已知 P2），
> 不得保持模糊状态。

---

## 7. RC 判断原则（已升级）

> 前面阶段问的是「**有没有缺陷**」。
> RC 阶段问的是「**这个工作树上的代码，是否足以作为 v1.0.0 的候选发布版本**」。

八个维度全部成立即通过：

```text
Correctness · Upgradeability · Recoverability · Multi-site
Multi-language · Operational usability · Security · Release reproducibility
```

**不再追求「零 P2/P3」**。发现问题只能：

```text
P0 / P1 → 修复
P2 / P3 → 记入 v1.1 backlog
```

---

## 8. RC 序列与当前进度

```text
RC-0  Release Freeze✅ DONE（本文件）
RC-1  Full Regression              ✅ PASS  1401/7290/0/0/0
RC-2  Fresh / Upgrade / Rollback   ✅ PASS  95/95
RC-3  Site × Locale Quadrant       ✅ PASS  130/130
RC-4  Security / GEOFlow Contract  ✅ PASS  47/47
RC-5  Installation / Operational UX ✅ PASS  R1-R3 57/57 · R4 1410/7349/0/0/0
RC-6  Release Artifact Audit        ✅ PASS  9 审计面 · 干净副本可复现
RC-7  Final Diff Review ✅ PASS  d51b6a0 · +28223/-321 · tag v1.0.0（未 push）
```

**此刻禁止 commit / tag / push / release。**
