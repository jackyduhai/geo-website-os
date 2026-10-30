# P-STEP 18L — Template Ecosystem Architecture（五层视觉架构 + Template SDK）

- **阶段**：P-STEP 18L DISCOVERY（架构裁定建议，未授权不编码）
- **基线**：HEAD `2d2fbaf`（checkpoint-18K）；1001 / 5247 / 0 / 0
- **配套**：`design-system-template-discovery-18l.md`（Gap）、`industry-template-matrix-18l.md`（行业矩阵）
- **状态**：本文为**目标架构（to-be）**，用于用户裁定；现状（as-is）以 Gap 文档 DISC-1~8 为准。

---

## 1. 五层视觉架构（冻结建议）

```
┌──────────────────────────────────────────────────────────────┐
│ L1  Design System Layer                                       │
│     Primitive → Semantic Token → Component Token             │
│     color / typography / spacing / radius / shadow / motion   │
└───────────────────────────┬──────────────────────────────────┘
                            ↓ Token 被消费
┌──────────────────────────────────────────────────────────────┐
│ L2  Theme Layer（视觉语言 / 品牌气质）                          │
│     Theme = { tokens 引用/派生, typography profile,           │
│              component variant 选择, appearance, density }    │
│     不保存企业事实 / 业务数据                                   │
└───────────────────────────┬──────────────────────────────────┘
                            ↓ Theme 决定「长什么样」
┌──────────────────────────────────────────────────────────────┐
│ L3  Template Layer（结构 / 槽位 / recipe）                     │
│     Template = { slots 布局, 每槽允许 block, 预置 recipe }     │
│     不存真实内容，只声明「这里放什么、怎么排」                    │
└───────────────────────────┬──────────────────────────────────┘
                            ↓ Template 决定「结构」
┌──────────────────────────────────────────────────────────────┐
│ L4  Block Component Layer（页面组成单元）                      │
│     Block Type + Structured JSON + Registered Renderer       │
│     variant 由 Theme 选；语义元数据 data-section/entity/purpose │
└───────────────────────────┬──────────────────────────────────┘
                            ↓ Block 引用 / 投影
┌──────────────────────────────────────────────────────────────┐
│ L5  Content / Entity Layer（业务事实，权威）                    │
│     Content / Entity / Relation / Media / Form / Site Setting │
└──────────────────────────────────────────────────────────────┘
                            ↓ 旁路（与视觉解耦）
              SEO / GEO / Schema / Feed / Search / Cache
```

### 各层硬边界

| 层 | 拥有 | 禁止 |
| --- | --- | --- |
| Design System | token 定义、组件默认样式 | 写品牌 / 业务事实 |
| Theme | 视觉语言（token 集、排版 profile、variant、appearance、density） | 存企业 / 产品 / 行业业务数据、Entity、Content |
| Template | 槽位布局、允许 block、预置 recipe | 存真实内容、复制业务事实 |
| Block | 结构化数据 + 注册 renderer + 语义元数据 | 存任意 Blade / HTML / PHP / script |
| Content / Entity | 业务事实（权威源） | 反向决定视觉 token |

**铁律**：
- 一个事实只有一个 runtime source；上层只能 fallback / computed / derived，不得形成第二事实源。
- Theme 改视觉 ⇒ 内容不变；Content 改事实 ⇒ 视觉不变；Template 改结构 ⇒ Entity 数据不变。
- 视觉 / 模板演进**不得改动** Entity Fact / Schema / URL / SEO / Sitemap / LLMS 公开契约。

---

## 2. Design Token 三层（L1 细化）

```
Primitive（原始值，如 hue / raw hex / 数值）
   ↓
Semantic Token（语义：--brand-primary / --surface / --text-primary / --on-inverse ...）
   ↓
Component Token（组件：--btn-bg / --card-radius / --hero-kicker-color ...）
   ↓
Page（只消费，不自定义）
```

### 2.1 18L 需补齐 / 升级的 Token

**Color 语义集（在现有基础上补，不重复造）**：

- brand：primary / hover / active / soft / ring / dark（已有）；secondary / tertiary **暂不补（无消费）**
- action：primary / secondary / hover / active / disabled 语义（18K 已有 action 簇；disabled 用 opacity）
- surface：background / card / elevated / muted / **inverse**（补 inverse 面）
- text：primary / secondary / tertiary / faint / inverse（**补 `--on-inverse` 及反白次级**）
- border：subtle / default / strong / **inverse-line**
- status：success / warning / error / info（info 保留 brand 派生占位）

**Inverse 簇（TD-114，收口 47 处白色手写）**：

```css
--surface-inverse: <深色/品牌实心面>;
--on-inverse:      #ffffff;            /* 反白主文字 */
--on-inverse-soft: rgba(255,255,255,.9);/* 反白次级 */
--on-inverse-faint:rgba(255,255,255,.65);
--inverse-line:    rgba(255,255,255,.15);/* 反白边框/分隔 */
```

**Typography（TD-115，token 化 + 可主题化）**：

```css
/* 字号 scale（从 :root 硬编码迁为 token，且可被 Theme 覆盖） */
--fs-display / --fs-h1 / --fs-h2 / --fs-h3 / --fs-h4
--fs-lg / --fs-base / --fs-sm / --fs-xs / --fs-2xs / --fs-label / --fs-button
/* 新增统一行高 token */
--lh-tight / --lh-snug / --lh-normal / --lh-relaxed
/* 字重 / 字距 */
--fw-* / --ls-*
```

> Theme 通过一个 **typography profile**（如 `compact` / `comfortable` / `editorial`）整体映射到这套 token，而非逐字段硬编码。

**Spacing / Radius / Shadow**：现有 sp-1..9、radius 6、shadow 4 已 token 化；行业包可在**组件 token 层**调整，不新增第二套 scale。

### 2.2 Locale 排版（TD-120）

- 以默认（zh-CN）token 为基线，用 **`[lang="en"]` 在组件 token 层做最小覆盖**（不改结构）：
  - 英文 display / h1 字号略降或放宽容器，避免孤字行；
  - 英文行高、字距、按钮最小宽度独立微调；
- **UI 系统串允许 fallback；公开内容不做无条件跨语言 fallback**（沿用 18F 政策）。

---

## 3. Theme Layer 升级（L2）

### 3.1 Theme 结构（扩展现有 theme.json，向后兼容）

```json
{
  "name": "technology-saas",
  "version": "1.0.0",
  "description": "SaaS / AI visual language",
  "industry": "saas",
  "tokens": {
    "theme_primary": "#4F46E5",
    "theme_accent": "#0891B2",
    "theme_radius": "12",
    "theme_shadow": "soft",
    "theme_density": "comfortable"
  },
  "typography": "editorial-wide",
  "components": {
    "hero": "split-wide",
    "card": "soft-elevated",
    "button": "solid-pill"
  },
  "appearance": { "default": "light", "available": ["light", "dark", "system"] }
}
```

- 仍走现有 12 键白名单 + ThemePalette 派生（Brand Seed → hover / active / soft / surface / contrast / focus / disabled + AA），**不要求人工填几十个颜色**。
- 新增 `typography`（排版 profile）与 `components`（variant 映射）两个**受控键**（TD-115 / TD-116）；未知键继续拒绝。

### 3.2 Component Variant Registry（TD-116）

```
ComponentVariantRegistry
 ├── hero:   split | split-wide | centered | full-bleed
 ├── card:   bordered | soft | elevated | soft-elevated
 ├── button: solid | soft | outline | ghost | pill
 └── section: default | tinted | inverse | spacious
```

- 每个 variant = 一组**组件 token 覆盖**（仍在 Design System 内），由 Theme 选择；
- renderer 按 variant 输出，但**语义 HTML 结构不变**（hero 永远是 section/h1/p/a）；
- 不按行业建组件（无 FactoryCard / MedicalHero），行业差异 = token + variant + recipe。

---

## 4. Template Layer 升级（L3）

### 4.1 Template 类型（收敛，不膨胀）

```
base
 ├── home
 ├── landing
 ├── listing
 │     ├── product listing   └─ 同一 listing 模板 + Entity kind
 │     ├── service listing
 │     └── content/knowledge listing
 ├── detail
 │     ├── product detail    └─ 同一 detail 模板 + Entity Context
 │     ├── service detail
 │     └── article detail
 └── contact
```

- Article / Product / Service 复用 listing / detail（经 Entity / Content Context），**不复制独立 Blade**；
- 8 类口径映射见 `industry-template-matrix-18l.md`。

### 4.2 Template 携带 Recipe（TD-118）

- 模板可声明**预置区块 recipe**（默认 block 类型 + 槽位 + 排序的「骨架」，不含企业事实）：

```json
{
  "key": "home",
  "recipe": [
    { "slot": "main", "type": "hero" },
    { "slot": "main", "type": "logo_cloud" },
    { "slot": "main", "type": "feature_grid" },
    { "slot": "main", "type": "product_grid" },
    { "slot": "main", "type": "faq" },
    { "slot": "main", "type": "cta" }
  ]
}
```

- 新建页面 / 应用行业包时，recipe 生成**结构化空 block**（内容待管理员填或由 Example Dataset 投影）；
- recipe 与 Example 数据严格分离：

```
Industry Template Pack → visual tokens + variants + recipe（视觉/结构）
Example Dataset        → demo 内容（业务事实，可选）
```

---

## 5. Template SDK / Manifest（TD-117）

### 5.1 可分发 Package 目录结构（建议）

```
templates/{pack}/
 ├── manifest.json          # 包元数据（见 5.2）
 ├── theme.json             # 视觉 token / typography / components 映射
 ├── tokens.json            # 可选：组件 token 覆盖
 ├── template.json          # 模板定义 + slots + recipe
 ├── components/            # 仅当需要 pack 专属 renderer（注册制，非任意 HTML）
 ├── preview/               # 预览图 / 缩略
 └── screenshots/           # 文档截图（home/product/article...）
```

- V1 以「**文件分发 + 后台发现 / 激活 / 切换 / 预览 / 回滚**」为准（与 18K 主题生命周期一致）；
- 在线安装 / ZIP 上传 / Marketplace 继续 **v1.1（TD-110）**。

### 5.2 Manifest 契约

```json
{
  "name": "modern-tech",
  "version": "1.0.0",
  "industry": "saas",
  "supports": ["home", "listing", "detail", "contact", "landing"],
  "entityKinds": ["product", "service", "article"],
  "slots": {
    "hero":    { "allows": ["hero"] },
    "main":    { "allows": "*" },
    "related": { "allows": ["product_grid", "service_grid", "content_grid"] }
  },
  "appearance": ["light", "dark", "system"],
  "locales": ["zh-CN", "en"]
}
```

- Manifest 经**校验器**校验（name / version / supports / slots / 允许 block 全部在注册表）；
- 加载 Manifest ⇒ 注册 TemplateDefinition + 绑定 Theme / variant / recipe，**不产生第二套渲染逻辑**；
- 渲染仍统一走 `CompositionRenderer` + Render Context（Page / Entity / Listing / System）。

---

## 6. Block 语义元数据（L4，TD-121）

- 每个 block renderer 在**最外层容器**输出受控语义属性（不改变可见结构 / 样式）：

```html
<section class="feature_grid ..."
         data-section="features" data-entity="Product" data-purpose="conversion">
```

- 约定词表（registry 管控，非自由文本）：
  - `data-purpose`：`awareness` / `navigation` / `conversion` / `trust` / `support` / `seo`
  - `data-entity`：`Organization` / `Product` / `Service` / `Article` / `FAQ` ...
  - `data-section`：`hero` / `features` / `catalog` / `proof` / `faq` / `cta` / `contact` ...
- 与 JSON-LD 互补：JSON-LD 给页面 / 实体强语义，区块属性给 AI「区块用途」的轻量信号；
- 不使用纯 canvas / 仅图区块承载关键内容；关键内容保持 server-rendered HTML。

---

## 7. 渲染 / SEO / GEO / Cache 一致性（不造第二套）

- **渲染**：所有页面（Page / Entity / Listing / System）继续走统一 Render Context → Template Resolver → Block System → CompositionRenderer。
- **SEO / Schema / GEO**：继续复用 `SeoMetaResolver` / `SchemaBuilder` / `GeoGraphBuilder` / `PublicUrl` / `PublicIndex`，**只扩展、不新建** Localized* / Theme* 第二套。
- **Locale**：typography / variant / 内容随 locale 正确切换；canonical / hreflang / inLanguage 沿用 18F 契约。
- **Cache（随 18L 建立依赖）**：

| 变更 | 失效范围 |
| --- | --- |
| Block 内容 / 排序 / 显隐 | 该 Page |
| Template / recipe | 使用该 Template 的全部 Page |
| Theme token / typography / variant | 使用该 Theme 的 Site 全部公开页 |
| Entity / Content / Relation | 对应 detail + 相关 listing / grid |
| Site / locale 设置 | 该 Site 公开页 + feed |

- 不全站粗暴 flush，也不留旧页；PageCache key 继续包含 site + locale（+ 主题版本）。

---

## 8. 验收要点（每个 Gate）

- 陌生品牌零代码：应用行业包 → 调品牌基色 → 选 typography / variant → light/dark → zh/en → 改 recipe / 增删拖拽 block → publish → 前台，**不改 PHP / Blade / JS / CSS**；
- 行业包只改视觉 / 结构，不产生行业业务数据（工厂 / OEM / 产能仍来自 Content / Example）；
- GEO 回归：换包 / 换主题后 Schema / sitemap / llms / entity / canonical / URL **公开契约不变**；
- 四组合 zh/en × light/dark、multi-site × locale、cache 对拍、污染 0、日志无新 ERROR、full regression、fresh install、cleanup、worktree clean。

---

## 9. STOP

本架构为 Discovery 建议，**等待用户裁定与授权**后才进入 Implementation；未授权不编码，19A / remote / push / rc1 / Release 继续 HOLD。
