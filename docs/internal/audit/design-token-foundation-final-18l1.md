# P-STEP 18L-1 — Design Token Foundation：Final Gate Audit

> 阶段：P-STEP 18L（Design System & Template Ecosystem Productization）第 1 Gate
> 范围：**TD-115 Typography 可主题化 / TD-114 Inverse Token / TD-120 Locale 排版**
> 硬边界：不改变 GEO / Schema / SEO 公开契约（唯一 H1、固定 section/h1/p/a、PublicUrl、canonical、feed 准入保持不变）
> 日期：2026-09-25

---

## 1. 目标与结论

18L-1 把视觉地基从「一套写死的字号 + 散落白色手写」升级为**可被 Theme 消费、随 profile / locale 派生的 Design Token Foundation**：

- 字号 / 行高 / 字重 / 字距不再硬编码，统一由 `ThemePalette::resolve()` 按 **typography profile** 派生；
- 反白场景（深色 Hero / CTA band / Footer / is-inverse / ghost）不再手写白色，统一走 **inverse 语义 token**；
- 中英排版在组件 token 层最小分离，消除桌面英文长标题 3 行 / "One" 孤字。

**裁定建议：P-STEP 18L-1 = PASS。**

---

## 2. 架构变更（单一事实源，未造第二套）

### 2.1 Typography profile（TD-115）

- 新增 Site-scoped 设置键 `theme_typography`（白名单 `ThemePresets::allowedKeys()` 第 13 键），三档：
  - `standard`（默认）/ `compact`（紧凑、信息密度高）/ `editorial`（杂志、大标题宽松）。
- `ThemePalette::typographyScale()` 输出 12 档字号（display / h1–h4 / lg / base / sm / xs / 2xs / label / button），`resolve()` 拼接 `rem`。
- 新增行高（无单位）/ 字重 / 字距 token：
  - 行高：`--lh-tight 1.1 / --lh-snug 1.25 / --lh-normal 1.5 / --lh-relaxed 1.7`，外加 profile 派生的 `--lh-heading / --lh-body`；
  - 字重：`--fw-normal 400 / --fw-medium 500 / --fw-semibold 600 / --fw-bold 700`；
  - 字距：`--ls-tight -.02em / --ls-snug -.01em / --ls-normal 0 / --ls-wide .02em / --ls-caps .08em`。
- `site.blade` 删除原硬编码字号块，改为在 `:root` 统一 `@foreach` 输出 36 个 typography / inverse token（逐个显式引用 `$themeTokens[$key]`，非全量 dump）。

### 2.2 Inverse token（TD-114）

新增 8 个不随深色覆盖的 inverse token：

| Token | 值 |
| --- | --- |
| `--surface-inverse` | `#0B1220` |
| `--on-inverse` | `#FFFFFF` |
| `--on-inverse-soft` | `rgba(255,255,255,.9)` |
| `--on-inverse-faint` | `rgba(255,255,255,.62)` |
| `--inverse-line` | `rgba(255,255,255,.15)` |
| `--inverse-line-strong` | `rgba(255,255,255,.4)` |
| `--inverse-hover` | `rgba(255,255,255,.10)` |
| `--inverse-hover-strong` | `rgba(255,255,255,.20)` |

共替换 **47 处白色手写**（pagination active / btn-ghost / hero-banner / hero-image 含 hi-ghost·hi-tel·hi-trust / cta-band / footer / is-inverse / ft-v / thead），映射规则：
`color→--on-inverse`、`.9x→--on-inverse-soft`、`.5–.7x→--on-inverse-faint`、ghost 背景/边框→`--inverse-hover/--inverse-line-strong`、低透明装饰边框→`--inverse-line`、表头背景→`--inverse-hover`。`rgba(0,0,0)` mask 保留。

### 2.3 Locale 排版（TD-120）

不下载 web font，系统字体优先：

- `--font`（zh / 基础 CJK 栈，`theme_font` 可覆盖）；新增 `--font-en`（拉丁栈：Inter / SF Pro Text / Segoe UI / Roboto ...）；
- 规则 `html[lang="en"]{ --font: var(--font-en); }`；
- 桌面（`min-width:900px`）英文覆盖：`--fs-h1:2.35rem; --lh-heading:1.16; --ls-snug:-.016em;`；
- `html[lang=en] .btn{ min-width:116px; }`；英文导航链接左右 padding 14px。

### 2.4 GEO 保护（硬边界，已核对）

- 唯一 H1、固定语义结构（section/h1/p/a）、非 canvas 保持不变；
- 未新增 `LocalizedSeo / LocalizedPublicUrl / LocalizedSchema`，全部在现有 `ThemePalette` / `SeoMetaResolver` / `PublicUrl` / `SchemaBuilder` 体系内扩展；
- typography / inverse 仅改变视觉表达，不进入 Entity / Content / Schema 事实层。

---

## 3. 验证证据矩阵

| # | 验证项 | 结果 | 证据 / 口径 |
| --- | --- | --- | --- |
| 1 | Focused（新测试） | ✅ PASS | `DesignTokenFoundation18L1Test`：**8 tests / 92 assertions** 全通过 |
| 2 | Full Regression | ✅ PASS | **1009 passed / 5339 assertions / 0 failed / 0 skipped**（710s）；基线 1001（18K）+ 新增 8 = 1009，数量对齐 |
| 3 | Fresh Install | ✅ PASS | 空 sqlite + `geo:install --no-interaction`：blank homepage / 默认 contact form / 系统页全中性；search index 0 docs；admin 创建 |
| 4 | Blank 初始状态 | ✅ PASS | locales `["zh-CN"]`、default zh-CN、`theme_typography=standard`、forms=1、pages=20、entities=0、contents=0、theme 组设置 **16** |
| 5 | Demo HTTP（zh） | ✅ PASS | `/` title「示例制造有限公司」、h1「工业涂料 · 胶粘剂 · 功能助剂 一体化定制」 |
| 6 | Demo HTTP（en） | ✅ PASS | `/en` title「Example Manufacturing Co., Ltd.」、h1「Coatings · Adhesives · Additives, Customized as One」 |
| 7 | Browser zh × Light | ✅ PASS | 桌面 Chrome + curl 对拍，排版/对比正常 |
| 8 | Browser zh × Dark | ✅ PASS | `lang=zh-CN`、`data-color-scheme=dark`、body bg `rgb(11,18,32)`=#0B1220、h1 中文、h1 color `rgb(232,237,244)`=#E8EDF4 |
| 9 | Browser en × Light | ✅ PASS | 桌面 h1 收敛 **2 行**「Coatings · Adhesives ·」「Additives, Customized as One」，**"One" 不再孤字**；场景三卡英文正常 |
| 10 | Browser en × Dark | ✅ PASS | 深色 elevated 面、白标题灰描述、蓝色图标，无穿帮 |
| 11 | PageCache locale 隔离 | ✅ PASS | 清缓存后 `/ → /en → / → /en → / → /en` 交替，title/h1 始终跟随各自 locale，**无串页**（真实响应内容对拍，非仅查 key） |
| 12 | Multi-Site × Locale | ✅ 保持 | 隔离契约由 18F/18J 测试锁定，本轮未改动多站解析路径 |
| 13 | SEO / canonical / hreflang | ✅ 不变 | typography / inverse 为纯视觉层，公开 SEO 契约未触及 |
| 14 | Schema inLanguage | ✅ 不变 | 未改 SchemaBuilder 事实输出 |
| 15 | GEO / Sitemap / LLMS / RSS | ✅ 不变 | feed 准入与 PublicUrl 未触及 |
| 16 | Search locale | ✅ 不变 | 未改搜索链路 |
| 17 | 硬编码复扫（白色） | ✅ 0 | 组件规则无 `color:#fff` / `background:rgba(255,255,255` / `solid #fff` 直写（token 定义除外） |
| 18 | 强身份业务污染 | ✅ 0 | 运行时产品代码（app/resources/config/routes）无Demo Tenant A / Sample City / Sample Snack / Sample Marinade等强身份词；命中仅在 docs/audit 与历史 migrations（豁免） |
| 19 | 日志审计 | ✅ 干净 | 最后产品 ERROR 为 10:09:50（TD-112 修复前），本轮验证窗口 **0 新增**；历史项均为 tinker/probe 工具噪音 |
| 20 | Runtime pollution | ✅ 0 | Site × Locale × Theme 无串状态 |
| 21 | `git diff --check` | ✅ PASS | 仅 CRLF→LF 行尾提示，无 whitespace error |

---

## 4. 全量回归失败 → 修复记录（真实闭环）

首次全量出现 **3 failed**，根因均为「新增 `theme_typography` 使设置数量 +1」，属断言需同步（非产品缺陷）：

| 失败位置 | 原断言 → 新断言 |
| --- | --- |
| `ThemeColorModeTest.php:164` | theme 组 count `15 → 16` |
| `SettingsGovernanceTest.php` GROUP_COUNTS | `'theme' => 15 → 16` |
| `SiteIdCoreTablesTest.php` Setting 总数 | `80 → 81`（80 default + demo contact_hours_en） |

修复后 3 文件 focused = **35 passed / 249 assertions**，重跑全量 **1009 / 5339 / 0 / 0** 通过。

> 流程备注：第二次全量经 `Tee-Object` 管道疑似死锁导致全工具超时，由用户手动结束 php 进程后恢复；后续改用 `*>` 文件重定向，正常完成。长任务禁用 Tee。

---

## 5. Technical Debt 状态

| TD | 标题 | 本轮状态 |
| --- | --- | --- |
| **TD-115** | Typography 不可主题化 | **CLOSED**（移入 §5 归档） |
| **TD-114** | Inverse / on-inverse token 缺失 | **CLOSED**（移入 §5 归档） |
| **TD-120** | Locale-aware 排版缺失 | **CLOSED**（移入 §5 归档） |

仍 ACTIVE（后续 Gate，不在本阶段）：TD-116 Component variant（18L-2）、TD-117 Template Package/SDK（18L-3）、TD-118 recipe（18L-3）、TD-119 拖拽（18L-4，P3）、TD-121 区块语义元数据（18L-4）。

---

## 6. 变更文件清单

**产品代码（M）**
- `app/Support/Theme/ThemePalette.php`：typography profile / scale、inverse token、行高无单位
- `app/Support/Theme/ThemePresets.php`：allowedKeys + `theme_typography`
- `database/seeders/DefaultSettingSeeder.php`：+ `theme_typography` select 默认
- `resources/views/admin/settings/form.blade.php`：+ typography 选项
- `resources/views/layouts/site.blade.php`：删硬编码字号、:root 统一输出 36 token、47 处 inverse 替换、locale 排版块

**测试**
- `tests/Feature/DesignTokenFoundation18L1Test.php`（新增，8 / 92）
- `tests/Feature/ThemeColorModeTest.php`、`SettingsGovernanceTest.php`、`SiteIdCoreTablesTest.php`：设置数量断言同步

**文档**
- `docs/audit/technical-debt-registry.md`：TD-114/115/120 CLOSED
- Discovery / Architecture：`design-system-template-discovery-18l.md`、`template-ecosystem-architecture-18l.md`、`industry-template-matrix-18l.md`、`design-token-foundation-architecture-18l1.md`

---

## 7. PASS 条件核对

- [x] 0 failed / 0 skipped
- [x] 字号 / 行高 / 字重 / 字距全部 token 化并可随 profile 切换
- [x] 反白场景 47 处白色手写清零、深色反白面对比正常
- [x] 中英排版分离，英文孤字消除
- [x] zh/en × Light/Dark 四组合真实通过
- [x] PageCache locale 隔离对拍无串页
- [x] GEO / Schema / SEO / Sitemap / Search 公开契约不变
- [x] 无新增业务 / 品牌硬编码，Runtime pollution = 0
- [x] 验证窗口无新增产品 ERROR
- [x] worktree 待提交内容清晰、`diff --check` 通过

**建议 Gate 裁定：P-STEP 18L-1 = PASS / STOP。**
不自动进入 18L-2；待明确授权后再开始 Component Design System。
