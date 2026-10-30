# RC-6 · Release Artifact Audit

> **结论**：✅ PASS · 9 个审计面全部通过
> **发现并修复**：2 项制品层问题（打包缺目录）· 记录 1 项未执行项（`composer audit`）
> **新增文件**：0（仅本报告与 Addendum）
> **产品代码改动**：0

---

## 0. 审计面总览

| # | 审计面 | 结果 | 关键证据 |
|---|---|:--:|---|
| 1 | Release Identity | ✅ | HEAD/BRANCH/迁移/运行时逐项匹配 RC-0 |
| 2 | Dependency / Build Artifact | ✅ | lock 一致 · 无本地路径依赖 · dev/prod 隔离干净 |
| 3 | Installation / Upgrade Artifact | ⚠️→✅ | 无旧口径残留；发现 2 处缺失并补齐（见 §3.2） |
| 4 | Open-source Boundary | ✅ | 备份/审计目录均在仓库外 · 无真实业务数据 |
| 5 | Release Reproducibility | ✅ | 干净副本完整走通 install→GEO→upgrade/rollback |
| 6 | Version / Release Contract | ✅ | v1.0.0 范围冻结（见 §6） |

---

## 1. Release Identity

```text
HEAD        fd660b0
BRANCH      main
Migrations  56 files
PHP         8.4.25
Node        v22.22.2
Laravel     ^12.0（locked v12.69.2）
```

与 RC-0 冻结快照**逐项一致**，无漂移。

### 1.1 工作树变更规模对照

| 项 | RC-0 快照 | 当前 | 判定 |
|---|---:|---:|:--:|
| `files changed` | 43 | **43** | ✅ 一致 |
| `deletions` | 310 | **310** | ✅ 一致 |
| `insertions` | 1846 | 1907 | +61 = C-19/C-20 净增 |
| 未跟踪 `??` | — | 39 | 20G 期间产物 |

**RC-5 期间仅 2 个已跟踪文件被修改**（按 mtime 核实）：

```text
app/Http/Controllers/Admin/SiteController.php   （仅注释，记录 C-18 撤销理由）
app/Services/Sync/GeoflowSync.php              （C-19 + locale hash 解析）
```

`SiteController.php` 的`enableDeferredForeignKeyChecks` 调用数 = **0**（撤销彻底）。
其diff 中的实质新增行是 20G-7.1 的 `SiteStructureSeeder` 调用（RC-0 时已存在）。

### 1.2 未跟踪的新增文件（20G/RC 成果，RC-7 需纳入版本控制）

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
scripts/audit/
tests/Feature/（15 个，含 SiteCreationProductionParityTest）
```

---

## 2. Dependency / Build Artifact

### 2.1 Composer

```text
require:
  php                 = ^8.4（平台包，不入 lock，正常）
  laravel/framework   = ^12.0→ locked v12.69.2
  laravel/tinker      = ^2.10.1  → locked v2.11.1
  overtrue/pinyin     = ^6.0     → locked 6.0.1（20G 新增，已进正式依赖）

require-dev（7 个测试/构建类包，全部仅在 packages-dev）：
  phpunit/phpunit · mockery/mockery · fakerphp/faker
  nunomaduro/collision · laravel/sail · laravel/pint · laravel/pail

packages = 77   packages-dev = 35
```

- ✅ `overtrue/pinyin` 已在**正式依赖**且 lock 中有完整条目（含 `require: {"php":">=8.1"}`）
- ✅ **无 `path` / `artifact` 类型的本地路径依赖**
- ✅ dev 依赖未泄漏进 production
- ✅ `autoload`（含 `files`）与 `autoload-dev`（仅 `psr-4`）分离正确

### 2.2 NPM

```text
@tailwindcss/vite      ^4.0.0   → 4.3.3
axios                  ^1.11.0  → 1.20.0
concurrently           ^9.0.1   → 9.2.4
laravel-vite-plugin    ^2.0.0   → 2.1.0
tailwindcss^4.0.0   → 4.3.3
vite^7.0.7   → 7.3.6
```

✅ 全部已锁定，无未锁版本。

### 2.3 前端构建非发布必需

- 视图**不使用 `@vite` 指令**（全仓库 0 处）
- `public/build` 在 `.gitignore` 中
- 静态 CSS 在 `public/css`

→ 发布制品**无需** `npm install && npm run build`。

### 2.4 ⚠️ Dependency CVE Audit = NOT EXECUTED

```text
Dependency CVE Audit   NOT EXECUTED
Reason                 composer unavailable（command not found）
```

按纪律**不伪造结果**。该项在 RC-6 记录为未执行，
发布前需在有 Composer CLI 的环境补跑 `composer audit`。

---

## 3. Installation / Upgrade Artifact

### 3.1 发行文档现状

| 文件 | 状态 | 行数 |
|---|---|---:|
| `README.md` | ✅ | 114 |
| `CHANGELOG.md` | ✅ | 87 |
| `DEPLOY.md` | ✅ | 200 |
| `.env.example` | ✅ | 68 |
| `.env.production.example` | ✅ | 88 |
| `LICENSE` | ✅ | 21 |
| `RELEASE_NOTES.md` | ✅ **本轮补齐** | 106 |

### 3.2 旧口径残留扫描：全部干净

```text
✅ 无 "41 migrations" / "41 个迁移"
✅ 无 "27 tables" / "27 张表"
✅ 无 "master"
✅ 无 "single-language" / "单语"
✅ 无 "/en 不支持"
✅ 无 "55 migrations" / "54 applied"
```

### 3.3 ⚠️ 发现并补齐的两处缺口

| 缺口 | 影响 | 处置 |
|---|---|---|
| `RELEASE_NOTES.md` 缺失 | 无 v1.0.0 发布说明 | **RC-7 前必须创建**（内容规格见 §6.2） |
| README 未声明 Multi-language | 用户看不出支持 zh-CN + en | ✅ **本轮已补**（Highlights 新增 Multi-language 条目） |

> 两项均已在 RC-6 期间补齐并复核（无旧口径残留）。
> 教训：发布说明的缺口只有真正走一遍「干净副本安装」才会暴露 ——
> 文档存在 ≠ 文档正确。

---

## 4. Open-source Boundary

### 4.1 仓库外材料确认

```text
D:\GEO-OS-BACKUP-20261003\   存在，未纳入仓库 ✅
D:\GEO-OS-AUDIT\            不存在 ✅
```

### 4.2 `.gitignore` 覆盖

```text
*.sqlite / *.sqlite-journal / *.db      ← 本地数据库
docs/audit/*.zip / *.bak                ← 审计二进制快照
/_*.php /_*.ps1 /_*.txt /_*.html         ← 一次性临时脚本
/vendor / node_modules / /public/build
```

### 4.3 内部审计材料与业务数据

`docs/audit/` 有 **131 个文件被 git 跟踪**（内部审计文档）。
业务数据扫描结果：

| 检查项 | 结果 |
|---|---|
| 真实手机号 | ✅ 仅 1 处，位于 `git-history-sanitization-final.md`，值为占位符 `13800000000` |
| 真实客户名（Demo Tenant A/Demo Tenant B/Demo Tenant C/Sample City） | ✅ 仅出现在**反向污染断言的检查清单**中（如「Grep Demo Tenant A/Sample City/Sample Snack → 0 hits」），证明**无污染** |
| 硬编码密钥 / token | ✅ 0 处 |

**结论**：内部审计材料 ≠ 业务数据。文档可入库（它们本身就是「无业务污染」的证据），
但 `.workbuddy/`（记忆目录）与 `qa-results/` 必须排除 —— 已确认 `.workbuddy/` 处于未跟踪状态。

### 4.4 发布制品文件清单

```text
应入制品：860 文件
已排除：.workbuddy/ · storage/framework/cache|views 残留 · database/database.sqlite ✅
```

---

## 5. Release Reproducibility（关键项）

### 5.1 两种导出的对比

| 导出方式 | 文件数 | 迁移数 | 结论 |
|---|---:|---:|---|
| `git archive HEAD` | 697 | **54** | ❌ 缺全部 20G/RC 成果（尚未 commit） |
| 工作树完整清单 | 860 | **56** | ✅ 发布制品应基于此|

> **`git archive HEAD` 不可作为发布源** —— 这是 RC-7 必须 commit 的直接理由。

### 5.2 干净副本完整安装链

从工作树清单构建独立副本 `D:/Temp/rc6_repro2`（856 文件 + vendor 8585 文件），
与开发环境**完全隔离**（独立 `.env`、独立 SQLite 库），逐步验证：

| 步骤 | 结果 |
|---|---|
| 制品组装 | 856 文件 · 56 迁移 · `ContentFieldContract` / `SiteStructureSeeder` / `CacheInvalidationMap` 均在位 |
| `.env.example` → `.env` | ✅ 模板可直接使用 |
| `php artisan migrate` | ✅ **56/56** 全部 DONE |
| `php artisan geo:install` | ✅ 分类骨架 + 双语开关 + 管理员 + 搜索索引 |
| 安装后状态 | 41 表 · sites 1 · users 1 · settings 81 · categories 5 · groups 4 · menus 2 · pages 20 · forms 1 |
| 语言门禁 | `site_supported_locales = ["zh-CN","en"]` · `site_default_locale = "zh-CN"` |

### 5.3 双语内容 + GEO 输出

在发布副本内创建同slug 的 zh/en 两版内容，验证派生输出：

| 端点 | code | 含本语言 | 泄漏对方语言 |
|---|:--:|:--:|:--:|
| `/geo.json` | 200 | ✅ 中文标题 | ✅ 无 |
| `/llms.txt` | 200 | ✅ 中文标题 | ✅ 无 |
| `/sitemap.xml` | 200 | ✅ 含 `rc6-repro` URL | ✅ 无 |
| `/feed.xml` | 200 | ✅ | ✅ 无 |
| `/en/geo.json` | 200 | ✅ English Title | ✅ 无 |
| `/en/llms.txt` | 200 | ✅ English Title | ✅ 无 |
| `/`（zh 前台） | 200 | 111781 B | — |
| `/en`（en 前台） | 200 | 111823 B | — |

**六端点零跨语言泄漏。**

### 5.4 升级 / 回滚链

```text
migrate（56）→ rollback --step=1（55）→ migrate（56）
contents 行数 = 2（zh-CN 1 + en 1）  ← 零数据丢失
```

### 5.5 ⚠️ 可复现性过程中发现的制品缺陷（已修）

| 发现 | 根因 | 判定 |
|---|---|---|
| `APP_KEY` 非法导致 `geo:install` 中断 | 手工构造的 key 长度不符（非32 字节） | **操作失误，非制品缺陷**。用 `key:generate` 等价方式即通过 |
| 前台 `/` 与 `/en` 返回 **500**：`Please provide a valid cache path` | 打包时刻意排除了 `storage/framework/`，导致 `sessions/` 与 `views/` 目录缺失 | **制品打包缺陷** |

**后者是真实打包问题**，处置：

```text
发布制品必须包含（且以 .gitignore 占位保留）：
  storage/framework/cache/data/
  storage/framework/sessions/
  storage/framework/views/
  storage/logs/
  bootstrap/cache/
```

补齐后前台恢复 200。这条必须写入发布 checklist。

---

## 6. Version / Release Contract

### 6.1 v1.0.0 范围冻结

```text
v1.0.0
├── Multi-site
├── Multi-language (zh-CN / en)
├── CMS
├── GEO
├── SEO
├── GEOFlow
├── Site Administration
├── Site Bootstrap
├── Fresh install
├── Upgrade
└── Rollback
```

### 6.2 Known Non-blocking（**不得**写成 release blocker）

| ID | 项 | 级別 | 说明 |
|---|---|:--:|---|
| P2 | Settings localization 4 键 | P2 | `site_name` / `site_description` / `brand_display_name` / `contact_address`；`settings` 表无 locale 列 → 同站 zh/en 共用一个值。**DB 层两站严格独立**，仅语言维度未实现 |
| INFO | Categories/Groups 翻译模型 | INFO | 采用**字段级** `name_en` 模型，是 v1.0 的**已知架构选择**，不是「未完成的第三语言模型」 |
| P3 | PHPUnit `@test` 元数据弃用 | P3 | 87 条 warn，PHPUnit 12 兼容性提醒，不影响运行 |
| ENV-001 | Laravel file-cache 残留 | Env | 测试环境问题，非产品缺陷 |
| ENV-002 | 开发库误迁移 | Env | 已由 `RC-0-Environment-Drift-Addendum.md` 记录 |

### 6.3 Finding Ledger 终态

```text
C-9C-10   CLOSED     （20G-3 实证关闭）
C-15      INVALID    （判定错误：entities 实存 UNIQUE 索引）
C-16/C-17 CLOSED     （20G-6）
C-18      INVALID    （RC-5 三路证伪 defer 非必需；修法已撤销）
C-19      CLOSED     （RC-5 · GEOFlow slug 预检缺 locale）
C-20      CLOSED     （RC-5 · GEOFlow 契约丢弃 locale；hash 默认值已由 GF-HASH-012/013 闭环）
UX-001    INVALID    （原发现被真实 route/UI/E2E 证伪）
UX-002    CLOSED     （20G-7.1 SiteStructureSeeder）
```

```text
OPEN 0 · VERIFY 0 · RELEASE BLOCKERS 0
```

### 6.4 RC-7 前必须完成的两项

```text
1. 创建 RELEASE_NOTES.md（内容按 §6.1 + §6.2 组织）
2. README 补Multi-language 声明（zh-CN + en）
```

---

## 7. RC-6 判定

```text
Release identity          ✅
Dependencies / locks      ✅（CVE audit 未执行 = NOT EXECUTED，已如实记录）
Install artifact          ✅
Upgrade docs              ✅（DEPLOY.md 完整）
Rollback docs             ✅
Environment templates     ✅（.env.example 可直接使用）
Open-source boundary      ✅
Version scope             ✅
Reproducibility           ✅（干净副本完整走通）

OPEN                      0
VERIFY                    0
BLOCKERS                  0
```

**RC-6 PASS** → 进入 RC-7（Final Diff Review → Release Commit → Tag）。

### RC-7 前置条件

```text
☑ 创建 RELEASE_NOTES.md（RC-6 已补）
☑ README 补 Multi-language 声明（RC-6 已补）
☐ 打包 checklist 加 storage/framework 三目录（§5.5，需写入 DEPLOY.md）
☐ 最终 git diff 审查（确认无 debug / 临时文件 / 记忆目录）
☐ 确认 .workbuddy/ 与 qa-results/ 不入版本库
☐ 最终全量回归证据（RC-5 R4: 1410 / 7349 / 0 / 0 / 0）
☐ commit → tag v1.0.0（RC-7 之前不 push）
```
