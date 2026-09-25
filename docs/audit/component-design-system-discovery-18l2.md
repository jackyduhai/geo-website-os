# P-STEP 18L-2 · Component Design System — Discovery / Gap Report

- 阶段：P-STEP 18L-2（Component Variant Registry + Interaction System）
- 性质：**Discovery，不含任何代码改动**
- 基线：HEAD `2841bac`，tag `checkpoint-18L-1`，回归 1009 / 5339 / 0 / 0
- 上游：18L-1 Design Token Foundation（Typography / Inverse / Locale 排版）已 PASS
- 配套文档：`component-design-system-architecture-18l2.md`

---

## 1. 审计目标与方法

18L-2 不做新页面，回答一个问题：**现有组件是否已经成为「可换气质、状态完整、语义稳定、动效克制」的产品组件，而不是一堆散落 CSS 类。**

审计对象（真实读取，非凭印象）：

- `resources/views/layouts/site.blade.php`（全站组件 CSS + 交互 JS，约 1768 行，全量通读）
- `resources/views/site/blocks/*.blade.php`（约 30 个 block 渲染器，抽查语义与内联样式）
- `resources/views/site/{content,search,home}.blade.php` 与 `partials/*`
- `config/blocks.php`、`app/Support/Blocks/{BlockType,BlockRegistry}.php`

审计维度：组件清单 → 现有变体 → 状态覆盖（default/hover/active/focus/disabled/loading）→ Motion → HTML 语义 → 与 token 的映射 → 内联硬编码。

---

## 2. 现有组件清单（去重后）

### 2.1 布局 / 容器

| 组件 | 类 | 职责 |
| --- | --- | --- |
| 容器 | `.wrap` / `.wrap-wide`(1320) / `.wrap-narrow`(960) / `.text-measure`(720) | 宽度与水平留白 |
| 栅格 | `.grid` + `.g2/.g3/.g4` | 等列网格，`.grid>*{min-width:0}` |
| 区块 | `.sec` / `.sec-tint` | 纵向节奏（`--sec-y`）/ 浅底 |
| 区块头 | `.sec-head`（`.row` / `.center`） | 标题区布局 |
| 眉标 | `.eyebrow`（`.noline`） | 大写小标签 + 品牌短线 |
| 区块标题 | `.sec-h` / `.sec-sub` | H2 标题 / 副标题 |

### 2.2 行动元素

| 组件 | 变体 / 尺寸 | 状态 |
| --- | --- | --- |
| Button | `.btn/.btn-primary`（实心主 CTA）、`.btn-o/.btn-secondary`（白底描边次）、`.btn-ghost`（深色反白幽灵）、`.btn-text`（文字） | hover / active / `:focus-visible` / `:disabled`；**无 loading** |
| 按钮尺寸 | `.btn-lg` / `.btn-sm` | — |
| 箭头 | `.arr`（hover `translateX(3px)`） | 与按钮共享 |
| 按钮组 | `.actions` | flex wrap |
| 标签 | `.tag` / `.tag-a` | hover |

> 按钮四级体系已成立，且主 CTA 用 `--cta`（非主品牌色）、幽灵按钮只用于反白区，归属正确。

### 2.3 导航

| 组件 | 类 |
| --- | --- |
| 吸顶头 | `.hd`（毛玻璃 `backdrop-filter`）/ `.hd-in` |
| Logo | `.logo`（文字 / `img`） |
| 主导航 | `.nav`，`a` 默认 / hover / `.on`，下拉 `.sub`（JS `.open`，hover-intent + 触摸/滚轮锁定） |
| 头部右侧 | `.hd-right` / `.hd-tel` / `.hd-cta` |
| 移动汉堡 | `.nav-toggle`（三横线动画） |
| 语言切换 | `.locale-switch`（`.ls-link` / `.ls-cur`） |
| 外观切换 | `.theme-mode-toggle`（sun/moon 图标随 `data-color-mode-pref`） |
| 子导航 / 面包屑 | `.subnav` / `.crumb`、`breadcrumb` block |

### 2.4 Hero（现有 5 种布局，已较丰富）

| 变体 | 类 | 特征 |
| --- | --- | --- |
| 轮播 A | `.hero` / `.hero-track` / `.hero-slide` / `.hero-overlay` | 横向 scroll-snap 轮播、渐变压字、圆点 |
| 图片轮播 B | `.hb` / `.hb-track` / `.hb-slide` / `.hb-overlay` | 1920/640 图片、单向渐变压字、圆点胶囊 |
| 分栏 | `.hero-split` | 左价值主张 / 右视觉，kicker-line + trust |
| 一体化主视觉 C | `.hero-int` / `.hi-panel` | 底渐变 + 图 mask 羽化 + glow + veil 四层，交叉淡入 |
| 文字兜底 | `.hero-text` | 无 Banner 时径向渐变 + 纯文字 |
| 能力面板 | `.hero-panel` / `.hp-*` | Hero 右侧中性能力格（cell/grid/foot） |

### 2.5 卡片（按"用途"分 6 套，非按"视觉气质"分）

| 类 | 用途 | 共同交互 |
| --- | --- | --- |
| `.card` | 通用卡 | `a.card` hover 上浮 + shadow |
| `.pcard` | 产品卡（`.pgrid` 首条 `.pcard-feature` 通栏） | hover 上浮 |
| `.kcard` | 知识卡（`.kc-cover` 16:9 封面） | hover 上浮、封面缓缩放 |
| `.wscard` | 能力卡（居中图标） | hover 上浮 |
| `.post` | 列表 / 文章卡（`.has-thumb` 横向缩略） | hover 上浮 |
| `.scene-card` | 场景卡（3px 顶条、hover 揭示 `.scp` 参数） | hover 上浮 + 揭示 |

### 2.6 区块内容 / 结构

| 组件 | 类 |
| --- | --- |
| 数据指标 | `.stats` / `.stat`（桌面 5 列 / 1024 三列）、`.stats.center` |
| 能力点 | `.cap`（去盒子、发丝分隔） |
| 流程步骤 | `.steps` / `.step`（描边数字 + 连线） |
| GEO 结论面板 | `.answer`（顶部品牌线、结论前置） |
| 事实定义 | `.facts`（`dt/dd`、`.auto` 自适应） |
| FAQ | `details.faq`（原生折叠、`+` 旋转） |
| 参数表 / 卡 | `.param-table` / `.param-card` |
| CTA 色带 | `.cta-band`（墨黑反白、装饰光圈） |

### 2.7 表单 / 页脚

- 表单：`.lead-form`（`.lf-grid` 两列 / `.lf-full`）、成功 `.lead-ok`；honeypot `.hp`；错误 `.lf-err`。
- 页脚：`.ft` / `.ft-grid` / `.ft-btm`（品牌 + 链接列 + 底部条）。

---

## 3. 状态系统现状

| 状态 | 覆盖 | 载体 |
| --- | --- | --- |
| default | 全部 | — |
| hover | 按钮 / 卡片 / 导航 / 标签 / 图标 | 颜色 + `translateY(-1px/-3px)` + shadow |
| active | 按钮 | `translateY(0)` |
| focus | 全站交互元素 | 统一 `:focus-visible` 焦点环（`--accent`/`--brand` outline） |
| disabled | 按钮系 | `opacity:.45; cursor:not-allowed` |
| **loading** | **缺失** | 无 spinner、无 `aria-busy`、无 `.is-busy`（仅 `img loading=lazy`） |

结论：静态状态完整、键盘焦点规范；**异步/提交反馈态（loading）是明确缺口**。

---

## 4. Motion / Interaction 现状

- Motion token 三档：`--motion-fast` / `--motion-base` / `--motion-slow`（由 Theme 解析，已被绝大多数 transition 消费）。
- 滚动揭示：`html.js .reveal{opacity:0;translateY(10px)}` → IntersectionObserver 加 `.in`；threshold .12、rootMargin 下 -8%。
- 数字滚动：`data-count` 元素进入视口后 `requestAnimationFrame` 计数（千分位 `fmt`），尊重 reduced-motion。
- 轮播：scroll-snap（无 JS 也可滑）+ 圆点；Hero C 交叉淡入。
- 降级：`@media(prefers-reduced-motion:reduce)` 关闭全部 animation/transition、reveal 直接可见；JS 侧 `matchMedia` 同步判断。

结论：**动效体系已"信息优先、克制、可降级"**，符合 18L-2 原则；本阶段不需要新增炫技动效，只需把散落的位移数值（`translateY(-1px/-3px)`、`translateX(3px)`）收敛为语义，并补 loading 反馈。

---

## 5. HTML 语义核查（硬约束）

抽查 block 渲染器（`feature_grid`、`cta` 等）：

- 最外层 `<section class="sec">`，区块标题 `<h2 class="sec-h">`，卡片标题 `<h3>`，描述 `<p>`，导航 `<nav>`，折叠用原生 `<details>/<summary>`，面包屑/列表用语义结构。
- **未发现"为视觉退化为 div 汤"**：层级标签（section / h1-4 / p / a / button / nav / details）保持良好。

这是 18L-2 必须在引入 variant 时继续守住的边界：**variant 只改视觉，不改语义标签。**

---

## 6. 内联硬编码扫描（关键量化缺口）

### 6.1 内联 px 字号：**27 处**（未消费 18L-1 的 `--fs-*` token）

分布：

- `site/content.blade.php`：13 处（GEO 解释/证据/边界/FAQ/CTA/相关，21/22/23/16/15/13px）
- `site/search.blade.php`：3 处（15/14px）
- `site/blocks/`：
  - `cta` 28px；`feature_grid` 17/14px；`testimonial` 36/15px
  - `content_grid` 12/16px；`product_grid` / `service_grid` 16px
  - `media_text` / `rich_text` 16px
- `partials/consent-banner.blade.php`：14px（组件 `<style>` 内）

**影响**：18L-1 的 typography profile（standard/compact/editorial）切换对这些位置**不生效**，排版气质仍有死角。

### 6.2 内联 spacing：散落 px（gap/padding/margin）

如 `feature_grid` 的 `gap:28px;padding:26px`、`cta` 的 margin、各 grid 的间距，未走统一 spacing scale。

### 6.3 内联 hex 颜色：**1 处（可接受）**

- `service_grid`：`background:var(--surface,#fff)` —— 是 CSS 变量兜底（var 在运行时总会解析），非独立白色硬编码。

颜色硬编码在 18L-1 后已实质清零；本阶段缺口集中在**字号 / spacing / 变体注册 / loading**，而非颜色。

---

## 7. 缺口矩阵与正确归属

| # | 缺口 | 规模 | 正确归属 | V1 Required |
| --- | --- | --- | --- | --- |
| G1 | 无声明式 Component Variant Registry（组件→变体→token→renderer 只存在于散落类名） | 全局 | Component 契约层（新增 `app/Support/Components`） | YES（TD-116 核心） |
| G2 | 卡片只按用途分 6 套，无视觉变体（default/glass/border/shadow/minimal），一个组件无法换气质 | 6 卡 | Variant（token 组合，不复制卡片） | YES |
| G3 | 内联 px 字号未 token 化，profile 切换有死角 | 27 处 | 消费 `--fs-*` / 语义 utility | YES |
| G4 | 缺 loading / `aria-busy` 提交反馈态 | 表单/异步 | 状态 token + spinner 组件 | YES（体验闭环） |
| G5 | 内联 spacing 散落 px、位移数值硬编码 | 多处 | spacing scale + 语义 motion | YES（收口） |

归属原则（沿用 18E / 18L-1）：**不把业务内容塞进组件字典，不新增"什么都管"的 Setting，不重写第二套 CSS/URL/SEO**。组件 Registry 是契约与单一事实源，不是新加视觉层。

---

## 8. 新债务登记（与台账对齐，避免重复）

复用并扩展已有条目，仅新增两个真正未覆盖的缺口：

| ID | 归属 | 状态 |
| --- | --- | --- |
| TD-116 | 父条目：Component Variant Registry 契约 + Button/Card/Hero/Section 变体（验收标准已扩展，含 `app/Support/Components`、Card 5 视觉变体） | ACTIVE / P1 |
| **TD-122** | 27 处内联 px 字号未消费 typography token | 新增 ACTIVE / P1 |
| **TD-123** | 内联 spacing 与 hover/箭头位移动效未走 scale / 语义 | 新增 ACTIVE / P2 |
| TD-36 | loading / aria-busy 异步反馈态（由 DEFERRED v1.1 升级纳入 18L-2） | ACTIVE / P2 |

> 详细 Acceptance Criteria、Gate 切分见 `component-design-system-architecture-18l2.md`。

---

## 9. Discovery 结论

1. **地基成立**：按钮四级、Hero 六模式、语义标签、focus-visible、reduced-motion、reveal/数字滚动均已到位，且颜色硬编码已清零。
2. **真正缺口**：组件没有"声明式变体契约"，卡片无视觉变体，27 处字号与部分 spacing 仍是内联 px，缺 loading 态。
3. **改造性质**：以"契约收敛 + token 消费"为主，**不重写视觉、不新增页面、不破坏 GEO/SEO/Schema 公开契约**。

**Discovery 到此 STOP，不进入实现。** 待用户裁定架构（见配套架构文档）与 Gate 切分后，再授权 18L-2 Implementation。
