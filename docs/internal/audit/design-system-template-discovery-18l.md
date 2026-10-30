# P-STEP 18L — Design System / Template Ecosystem Discovery（Gap Report）

- **阶段**：P-STEP 18L，**第一阶段 DISCOVERY（只读，不写产品代码）**
- **审计基线**：HEAD `2d2fbaf`（= annotated tag `checkpoint-18K`）；Regression **1001 tests / 5247 assertions / 0 failed / 0 skipped**；Worktree clean
- **前置**：18A–18K 全部 Gate PASS；`v1.0.0-rc1` 冻结于 `965d63c`（HOLD）；无 remote、未 push、未 Release
- **方法**：不相信描述，以真实代码 + computed style + 真实浏览器（cu 桌面 Chrome / bu）取证
- **配套文档**：
  - `template-ecosystem-architecture-18l.md`（五层架构 + Template SDK / Manifest + 行业包结构）
  - `industry-template-matrix-18l.md`（8 类「行业 × 品牌气质」主题/模板矩阵）

---

## 0. 为什么需要 18L

18K 完成了 Theme Engine 的**颜色 / Token / 切换**基础闭环：Brand-led 配色、Action/CTA 由品牌基色派生、accent 降级点缀、彩色孤立 hex 清零。但 18K 的主题本质上仍是「**一套换色机制**」：

- 主题不能改**排版气质**（字号 / 行高 / 字距硬编码）；
- 主题不能改**组件形态**（hero 布局 / card 风格 / button 风格无变体）；
- 「模板」集中在 `config/templates.php`，**不是可独立分发、可演进的 Package**，没有 Manifest / SDK；
- 选择一个「行业模板」不会带出该行业的**页面骨架 / 区块 recipe**；
- 首页区块排序只有「上移 / 下移」，没有拖拽；
- 中文 / 英文共用同一套排版参数，英文长标题无独立微调；
- 区块缺少**语义元数据**，AI 无法直接知道「这个区块是什么实体、什么用途」。

因此 18L 的目标不是再做一个更漂亮的网站，而是建立长期可演进的**视觉交互基础设施**：

```
Design System Layer  →  Theme Layer  →  Template Layer  →  Block Component Layer  →  Content / Entity Layer
```

使得「换品牌 = 换 Theme、换行业 = 换 Industry Template Pack、换设计趋势 = 升级 Design System」，且**全程不破坏 AI / GEO 可理解结构**。

---

## 1. 八项只读审计结论（DISC-1 ~ DISC-8）

### DISC-1 — Token 体系完整性

**现状（浅色 :root，`site.blade.php`）**：

| 簇 | 已有 Token | 缺口 / 备注 |
| --- | --- | --- |
| brand | primary / hover / active / soft / ring / dark 等 7 | secondary / tertiary **无定义、无 consumer** → 不新增（NON-DEBT） |
| accent | 6 | accent-active 缺失（小） |
| action（18K 新增） | 5（action=brand 派生） | 完整 |
| cta | 4（cta / hover / soft / on-cta） | cta-active / cta-ring 缺失（小） |
| 中性 | 14（bg / surface / ink 阶 / line 阶） | 完整 |
| 状态 | success / warning / error / info | **info=brand 复用、且无 var(--info) consumer**；不新增独立 info（NON-DEBT） |
| radius / layout / spacing / motion / shadow | radius 6、spacing 9（sp-1..9）、motion 3、shadow 4 | 完整 |

- **disabled**：`.btn:disabled{opacity:.45;cursor:not-allowed;transform:none}`、pagination disabled 用 `--ink-faint` —— 用 **opacity 通用处理**，不需要独立 disabled 颜色 token（NON-DEBT）。
- **深色 darkOverrides**：完整；CTA 深色只覆盖 cta-soft，实心面白字沿用浅色（合理）。

**结论**：颜色 / 间距 / 圆角 / 阴影 token 骨架完整；真实缺口集中在 **inverse（反白）语义 token**（见 DISC-2），其余「疑似缺口」经核对均无消费、不新增（遵循 18E「不过度设计」）。

### DISC-2 — 组件全 Token 化（真实缺口：inverse 语义）

- 全站前台 CSS 集中于 `resources/views/layouts/site.blade.php`（主样式 L80–1370；L1372 `theme_custom_css`）；`resources/css/app.css` 基本空。
- 组件区共 **47 个颜色行，全部是中性反白**：
  - `#fff` / `rgba(255,255,255,x)`：深色 inverse 面（hero / cta-band / footer / is-inverse / ghost 按钮）上的反白文字、边框、ghost 描边；
  - `rgba(0,0,0,x)` / `#000`：CSS mask、text-shadow；
  - `rgba(var(--scrim),x)`：token 派生遮罩。
- **无任何品牌色孤立 hex**。
- `var(--surface,#fff)` 是 token 降级 fallback、consent `rgba(0,0,0,.14)` 是 box-shadow —— 均 NON-DEBT。

**真实缺口（TD-114）**：47 处白色手写跨 hero / hb / hi / cta-band / footer / is-inverse / ghost 等 **6+ 组件**，缺少统一 inverse 语义 token：

- `--on-inverse`（反白主文字）
- 反白次级文字（`rgba(255,255,255,.9)`）
- `--inverse-line`（反白边框，`rgba(255,255,255,.15)`）

当前这些值在深色 / 反白面上工作正常（非功能缺陷），属 **token 规范化缺口**；收口后「换色 / 调反白对比度」只需改 token。

> Admin 视图少量硬颜色（plugins `#c0392b`、themes 页 swatch 展示预设实际颜色）合理——后台本阶段不要求 token / 深色。

### DISC-3 — Theme 五要素（真实缺口：Typography / Component Variant 不可主题化）

- `resources/themes/default/theme.json` 仅 name / version / description（无 tokens）；`example/theme.json` 含 tokens 仅 4 个（primary `#7C3AED`、accent `#F59E0B`、radius `16`、shadow `soft`）。
- 主题白名单 `ThemePresets::allowedKeys()` = **12 键**：color 7（primary / primary_dark / accent / bg / surface / text / text_muted）+ radius / container / font / density / shadow。

**真实缺口**：

1. **Typography 不可主题化（TD-115，P1）**：
   - 完整 **12 档字号硬编码**在 `site.blade :root`（L162–173）：`--fs-display` 3rem/48、h1 2.75/44、h2 2/32、h3 1.5/24、h4 1.1875/19、lg 1.125/18、base 1/16、sm .9375/15、xs .875/14、2xs .8125/13、label .78/12.5、button .9375/15；
   - **无统一行高 token**：line-height 1.16 / 1.18 / 1.78 散落组件；
   - 主题白名单虽有 `theme_font`，但只能改字体族，**不能改字号 scale / 行高 / 字距** → 行业排版气质无法随主题切换。
2. **Component variant 不可主题化（TD-116，P1）**：
   - hero 布局（split / center / full）、card 风格（边框 / 阴影 / 软面）、button 风格（实心 / 圆角 / 软面 / ghost）**无变体注册机制**；
   - 主题无法表达「SaaS 大留白微光」vs「工业精密紧凑」的组件形态差异。
3. `theme.json` 示例 / 文档未暴露 font / density / surface 等**已支持能力**（文档 / SDK 缺口，并入 TD-117）。

- density 当前仅 comfortable / compact 两档、只改 `--sec-y`（96 / 64px），`--sp-*` 固定。

### DISC-4 — Template Package（真实缺口：非可分发 Package / 无 SDK / 无 recipe）

- `TemplateRegistry` 从 `config/templates.php` **声明式**加载（支持 extends 继承与 slot 合并）；`TemplateDefinition` = key / label / extends / slots / layout，`allows()` 约束每槽允许 block。
- 共 **6 模板**：base（main `*`）、home（main 15 block）、landing（main `*`）、listing（header / main / sidebar）、detail（header / main / related）、contact（header / main）。
- 用户列举 8 类中的 **Article / Product / Service 当前复用 detail**（经 Entity Context）——属合理收敛，在 Industry Matrix 做口径映射（见 `industry-template-matrix-18l.md`）。

**真实缺口**：

1. **模板非可分发 Package、缺 Template SDK / Manifest（TD-117，P1）**：
   - 模板集中在 `config`，没有 `templates/{pack}/template.json` + 资产目录；
   - 没有 Manifest（name / version / supports[home,product,service,article] / slots / tokens 引用）；
   - 第三方无法按规范开发 / 分发 / 升级一整套模板。
2. **模板不携带预置 recipe、无行业目录（TD-118，P2）**：
   - 默认区块由 `HomeBlockDefaults`（seeder）**全局**提供、不随行业模板变；
   - 选 SaaS 模板**不会带出** SaaS 首页骨架（hero→logo→feature→scenario→case→cta），选制造业模板也不带 factory / process / quality recipe；
   - 没有按行业组织的模板目录（行业 IA 无落点；但行业区块仍应是**通用 block** 而非行业专属 block）。

### DISC-5 — 首页 Composition（真实缺口：无拖拽；其余已满足）

- `BlockRegistry` 从 `config/blocks.php` 加载，`forSlot` / `selectableForSlot`（Add block 数据源、排除 system block），render 走注册 view；grid 数据从 Entity / Content **单向投影**（all / line / picked / current / related / latest）。
- 共 **27 block 类型**：
  - 16 手动（hero / rich_text / image / media_text / feature_grid / stats / logo_cloud / faq / testimonial / cta / contact_info / breadcrumb / product_grid / service_grid / content_grid / form_reference）；
  - 5 Entity Detail system（entity_hero / entity_specifications / entity_steps / entity_relations / bottom_cta）；
  - 6 固定系统页 system（sys_solutions / sys_products / sys_knowledge / sys_about / sys_factory / sys_cooperation）。

**真实缺口（TD-119，P3）**：后台 composer 排序通过 moveBlock 路由的 **up / down「上移 / 下移」按钮实现，无 draggable / sortable 拖拽**（18L-04 明确要求「拖动」）。

**已满足（不重复登记）**：任意数量区块（可 3 / 7 / 10）、增删 / 复制 / 隐藏 / 排序、结构化 JSON + 注册 renderer（**禁 DB 存任意 Blade / HTML / PHP**）、per-locale 内容。

### DISC-6 — 中英文排版适配（真实缺口：无 locale 排版微调）

- `<html lang>` 正确动态切换（zh-CN / en）。
- 字体栈 `-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Arial,sans-serif`（西文在前、CJK fallback）——**字形渲染正确**：英文不用中文字体、中文不缺字。
- **全仓 CSS 无任何 `[lang="en"]` / locale 排版覆盖选择器**。

**浏览器实测**：

| 项 | zh-CN | en |
| --- | --- | --- |
| h1 字号 / 行高 / 字距（移动） | 32px / 37.76 / -0.64 | **完全相同** |
| 主按钮 padding | 15px 32px | **完全相同** |
| 桌面 h1 换行 | 2 行 | **3 行**（"Coatings · Adhesives ·" / "Additives, Customized as" / **"One" 孤字行**） |

**真实缺口（TD-120，P2/P3）**：无 locale-aware typography——英文长标题用相同字号 / 负字距偏紧、行高 / 按钮宽度未独立微调。属**精修而非硬缺陷**（可读、无溢出、布局正常）。18L-06 要求中文 / 英文独立设计规范（非机械翻译）。

### DISC-7 — Dark Mode 完整性（无新缺口）

- 组件区扫描：**无硬编码浅色背景**（`background:#fff/white/#f8-#fc` 命中 0）；前台仅 `service_grid var(--surface,#fff)` fallback（深色下 --surface 为深色，NON-DEBT）。
- 深色 token（中性阶 / brand / action / accent / 状态 / 阴影深色版）完整，所有表面走 token 自动切换。
- **bu 强制深色运行时确认**：`data-color-scheme=dark`、body bg `rgb(11,18,32)`=#0B1220、body text `rgb(198,208,221)`、h1 color `rgb(232,237,244)`=#E8EDF4、hero bg 深色，对比正常。

**结论：Dark Mode 完整、无穿帮、无新缺口。**

### DISC-8 — GEO 保护边界（真实缺口：无区块级语义元数据）

- Hero block 输出**稳定语义**：`<section class="hero-split ..."><div class="wrap hero-in"><div class="hero-copy"><span class="hero-kicker-line"><h1 class="hero-title"><p class="hero-lead"><div class="actions">` —— 固定 section / h1 / p / a、整页唯一 H1、**非 canvas**。
- `CompositionRenderer` 统一注入 `'schemas' => $context->schemaNodes()`、`'seo' => seoArray($context)`；`PageRenderContext.schemaNodes()` 调 SchemaBuilder（organization / website / breadcrumb + hasSchema block 如 faq），SEO 走 `SeoMetaResolver::resolvePage`。
- **Schema / SEO / Canonical / PublicUrl 与 Template 视觉层完全解耦**；Sitemap / LLMS / RSS 由独立 Feed 构建器从 PublicIndex 生成。

**真实缺口（TD-121，P2）**：无任何**区块级 template semantic metadata**（无 `data-section` / `data-entity` / `data-purpose` / `itemprop` / `itemtype`），AI 无法直接获知区块「是什么实体、什么用途」（如 `{section:"product-benefit", entity:"Product", purpose:"conversion"}`）。JSON-LD 已提供页面 / 实体级强语义，区块级标注是**增量增强**（18L-07 明确要求）。

---

## 2. 缺口总表（登记 TD-114 ~ TD-121）

| TD | 缺口 | 归属层 | 建议优先级 | Blocks v1.0（建议） |
| --- | --- | --- | --- | --- |
| **TD-114** | 缺 inverse / on-inverse 语义 token（47 处白色手写跨 6+ 组件） | Design System | **P2** | 建议 18L 收口 |
| **TD-115** | Typography 不可主题化（字号 12 档硬编码、无行高 token） | Theme | **P1** | **YES（18L 核心）** |
| **TD-116** | Component variant 不可主题化（hero / card / button 无变体） | Theme / Block | **P1** | **YES（18L 核心）** |
| **TD-117** | 模板非可分发 Package、缺 Template SDK / Manifest | Template | **P1** | **YES（18L 核心）** |
| **TD-118** | 模板不携带预置 recipe、无行业模板目录 | Template | **P2** | 建议 18L 收口 |
| **TD-119** | 首页区块排序无拖拽（仅上移 / 下移） | Block / Admin UX | **P3** | 建议 18L 收口（体验） |
| **TD-120** | 无 locale-aware 排版适配（英文长标题 3 行孤字） | Design System / Locale | **P2** | 建议 18L 收口（精修） |
| **TD-121** | 无区块级 template semantic metadata（data-section / entity / purpose） | Block / GEO | **P2** | 建议 18L 收口（GEO 增强） |

**经核对判定 NON-DEBT（不登记、不新增）**：
- disabled 颜色 token（opacity .45 通用处理）；
- brand secondary / tertiary（无定义、无 consumer）；
- 独立 info 色（`--info`=brand 复用、无 consumer，保留语义占位即可）。

---

## 3. v1.0 / v1.1 边界建议

### 建议纳入 18L（v1.0 发布前闭合）

- **P1 核心（行业主题区别于「换色」的本质）**：TD-115 Typography 主题化、TD-116 Component Variant、TD-117 Template SDK / Package；
- **P2 规范化 / GEO 增强**：TD-114 inverse token、TD-118 recipe / 行业目录、TD-120 locale 排版、TD-121 区块语义元数据；
- **P3 体验**：TD-119 拖拽排序。

> 理由：用户已明确「18L 是最后一次产品架构补齐，完成后再进 19A」。这 8 项共同构成「可演进的视觉 / 模板生态」，若只做颜色、模板仍不可分发 / 不带 recipe，18L 的目标未达成。

### 建议保留 v1.1+（不在 18L 扩大）

- 在线主题 / 模板市场、ZIP 上传、自动更新（TD-110）；
- Figma 式自由布局 Builder、多步 / 条件分支表单、AI 自动排版 / 自动翻译；
- 高级 FTS（embedding / 向量 / 语义搜索）；
- brand secondary / tertiary、独立 info 等**出现真实消费后**再补的 token。

### Release Engineering（18L PASS 后才解锁）

- TD-01 Cloud CI 首跑、TD-02 Final RC 重建、TD-03 Private→Public / v1.0.0；
- 19A / 19B / 19C 与 remote / push / rc1 移动 / Release **全部继续 HOLD，直到 18L PASS**。

---

## 4. 建议的实施切分（待用户裁定，未授权不编码）

> 仅为建议，最终 Gate 拆分以用户裁定为准；架构细节见 `template-ecosystem-architecture-18l.md`。

- **18L-1（Foundation）**：Typography token 化 + 可主题化（TD-115）、inverse token（TD-114）、locale 排版微调（TD-120）；
- **18L-2（Theme Variants）**：Component variant 注册（hero / card / button）（TD-116）；
- **18L-3（Template Ecosystem）**：Template SDK / Manifest / 可分发 Package（TD-117）+ 行业 recipe / 目录（TD-118）；
- **18L-4（GEO + UX）**：区块语义元数据（TD-121）+ 拖拽排序（TD-119）。

每个子阶段独立 Gate（focused / full regression / fresh install / blank·demo HTTP / browser / multi-site / zh-en×light-dark / 污染·日志 / cleanup / git），PASS 后 STOP。

---

## 5. Discovery 取证环境与清理

- 新建 demo 双语库 `D:\Temp\18l-demo\demo.sqlite`（migrate + DatabaseSeeder[Demo + DefaultForm + SystemPage + admin]，启用 en，search:reindex=18 documents；最终 entities=18 / contents=6 / page_blocks=24 / forms=1）。
- `php artisan serve` 后台运行于 `127.0.0.1:8171`（task `dc38be9b-3a6b-4426-8c07-d8adb0f3e5eb`），zh / en 均 200。
- cu 桌面 Chrome 实测：中文首页、英文首页（标题 3 行）、深色（bu 强制）computed 取证。
- **Discovery 未修改任何产品代码 / 数据库（仓库内）**。
- 收尾清理：仓库根 `chk18l.php` / `chk18l-en.php`、`D:\Temp\18l-demo`、停止 serve（8171）。

---

## 6. STOP

Discovery 到此 **STOP**，等待用户：
1. 裁定 TD-114 ~ TD-121 的优先级与 v1.0 / v1.1 归属；
2. 裁定实施 Gate 切分；
3. 授权后才进入 18L Implementation。

未授权前不编码、不进入 19A、不碰 remote / push / rc1 / Release。
