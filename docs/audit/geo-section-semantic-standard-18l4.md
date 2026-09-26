# P-STEP 18L-4 — GEO Section Semantic Standard

> 专题：定义区块（Section）面向 AI / GEO 的**机器可读语义层**——
> 受控的 Section 类型与 purpose / entity / conversion 词表，以及统一渲染输出规范。
> 本文为 Discovery（只读）产物。对应 TD：**TD-132**（吸收 TD-121）。
> 主文档：`geo-template-experience-discovery-18l4.md`。

---

## 1. 目标：一半给人看，一半给 AI 看

- **人**消费视觉层（Theme / Component / 排版 / 动效）。
- **AI / 搜索引擎 / GEO 抓取方**消费**稳定的结构语义**：DOM 语义标签、Heading 层级、
  Section 用途、关联实体、转化行为。

现状（18L-3b）：block 根只有 `aria-label` / `role`（服务**无障碍 a11y**），
**没有** `data-section` / `data-purpose` / `data-entity` / `data-conversion`，
AI 无法直接从容器读出「这一区是什么、讲哪个实体、用于什么、引导什么转化」。

本标准在**不改变可见结构、H1、Schema、URL** 的前提下，为每个 block 根补充受控语义属性。

---

## 2. 输出规范

### 2.1 根标签语义属性

每个 block 的最外层语义容器统一输出（值全部来自 registry 受控词表，**非自由文本**）：

```html
<section
  data-section="product"
  data-purpose="comparison"
  data-entity="Product"
  data-conversion="contact">
  …原有可见内容与 Heading 结构不变…
</section>
```

| 属性 | 含义 | 来源 |
|---|---|---|
| `data-section` | 区块在**商业叙事**中的角色（Section 类型） | BlockType `semantic.section` |
| `data-purpose` | 区块服务的**沟通目的** | BlockType `semantic.purpose` |
| `data-entity` | 区块主要描述的**实体类型**（大写，对齐 Schema 词表） | BlockType `semantic.entity` |
| `data-conversion` | 区块引导的**转化行为**（无转化可省略） | BlockType `semantic.conversion` |

### 2.2 渲染落点（单一出口，不散落手写）

- `BlockType` 增加 `semantic` 声明字段（从 `config/blocks.php` 加载）。
- 由统一渲染层（`BlockRegistry::render` 的根包装，或一个共享 `<x-section>` 根组件）输出属性；
  **禁止在各 block blade 中各自手写 data-***，避免词表漂移。
- 语义可随 Render Context 细化（如详情页 entity 块的 `data-entity` 取当前 Entity 类型）。

### 2.3 与 JSON-LD / aria 的关系（互补，不重复、不冲突）

| 层 | 服务对象 | 表达内容 |
|---|---|---|
| HTML 语义 + Heading | 所有人 | 文档结构层级（section/h1/h2/p/nav） |
| **data-* Section 语义（本标准）** | AI / GEO 抓取 | **区块级**用途 / 实体 / 转化（轻量、随 DOM 即可读） |
| JSON-LD Schema | 搜索引擎富结果 | **实体级**结构化事实（Organization/Product/FAQPage…） |
| aria-* / role | 辅助技术 | 无障碍名称 / 状态 / 角色 |

原则：data-* 描述「**这一区的意图**」，JSON-LD 描述「**实体事实**」，二者互补；
不把 JSON-LD 的完整事实塞进属性，也不因为有了 aria 就省略 Section 语义。

---

## 3. 受控词表（Controlled Vocabularies）

### 3.1 Section 类型（`data-section`，至少覆盖 10 类）

| Section | 商业叙事角色 | 典型 block |
|---|---|---|
| `hero` | 首屏价值主张 | hero、entity_hero |
| `about` | 品牌 / 主体介绍 | rich_text、image、media_text、sys_about |
| `trust` | 可信背书 / 实力证明 | stats、logo_cloud、testimonial、sys_factory |
| `product` | 产品体系 / 产品事实 | product_grid、entity_specifications、sys_products |
| `solution` | 解决方案 / 服务场景 | service_grid、feature_grid、entity_steps、entity_relations、sys_solutions |
| `case` | 客户案例 / 评价 | testimonial、content_grid（案例） |
| `certificate` | 认证 / 资质 | feature_grid（证书）、sys_factory（资质） |
| `faq` | 常见问题 | faq、sys_knowledge |
| `contact` | 联系方式 / 表单 | contact_info、form_reference |
| `conversion` | 转化引导带 | cta、bottom_cta、sys_cooperation |

> 导航类（breadcrumb）属于站点导航，不强制 Section 类型（可标 `navigation` 或省略）。

### 3.2 Purpose 目的（`data-purpose`）

| Purpose | 含义 |
|---|---|
| `brand` | 建立品牌认知 / 第一印象 |
| `trust` | 建立信任 / 降低风险顾虑 |
| `education` | 解释概念 / 能力 / 事实 |
| `comparison` | 帮助比较 / 选择方案 |
| `conversion` | 直接推动一次转化动作 |

### 3.3 Entity 实体（`data-entity`，大写，对齐 Schema 受控词表）

`Organization` · `Product` · `Service` · `Factory` · `Content` · `Person` · `Location` · `Case`
（与 `TemplateManifestValidator::ENTITIES` 保持同一词表，避免第二套枚举）。
详情页可由 Context 提供更精确值（Product / Service）；无明确实体的通用块可省略。

### 3.4 Conversion 转化（`data-conversion`）

`contact` · `download` · `consult` · `purchase` · `demo` · `appointment` · `signup`
（与 manifest `conversion` 受控词表对齐；无转化动作的区块省略该属性）。

---

## 4. Block → 默认 Section 语义映射（v1）

> 下表为注册默认值；可被 Render Context（详情 / 系统页）细化，但不接受自由文本。

| Block | section | purpose | entity | conversion |
|---|---|---|---|---|
| `hero` | hero | brand | Organization | — |
| `rich_text` | about | education | Content | — |
| `image` | about | brand | Content | — |
| `media_text` | about | education | Content | — |
| `feature_grid` | solution | comparison | Product | — |
| `stats` | trust | trust | Organization | — |
| `logo_cloud` | trust | trust | Organization | — |
| `faq` | faq | education | Content | — |
| `testimonial` | case | trust | Case | — |
| `cta` | conversion | conversion | Organization | contact |
| `contact_info` | contact | conversion | Organization | contact |
| `breadcrumb` | （navigation） | — | — | — |
| `product_grid` | product | comparison | Product | contact |
| `service_grid` | solution | comparison | Service | consult |
| `content_grid` | solution | education | Content | — |
| `form_reference` | contact | conversion | Content | contact |
| `entity_hero` | hero | brand | （Context：Product/Service） | contact |
| `entity_specifications` | product | education | （Context） | — |
| `entity_steps` | solution | education | （Context） | — |
| `entity_relations` | solution | comparison | （Context） | — |
| `bottom_cta` | conversion | conversion | Organization | contact |
| `sys_solutions` | solution | education | Service | consult |
| `sys_products` | product | comparison | Product | contact |
| `sys_knowledge` | faq | education | Content | — |
| `sys_about` | about | brand | Organization | — |
| `sys_factory` | trust | trust | Factory | — |
| `sys_cooperation` | conversion | conversion | Organization | contact |

---

## 5. GEO 保护硬边界（视觉升级不得破坏）

1. **HTML 结构稳定**：保留 `section/h1/h2/p/a/nav/details`，禁止退化为 `<div><div>` 或纯 canvas。
2. **Schema 不变**：Section 语义只新增容器属性，不改变 JSON-LD 类型 / `@id` / 事实。
3. **URL / SEO 不变**：不改变 canonical / hreflang / title / description / sitemap / llms。
4. **关键内容 server-rendered**：核心文本与实体随 HTML 输出，不依赖客户端 JS 才可见。
5. **受控词表**：非法值在 validate 阶段报错（ERROR），不静默输出。

---

## 6. 验收要点（供 Gate）

**正向**
- 全 block 渲染后，根标签按映射输出 data-section/purpose/entity/conversion；值均在词表内。
- 详情页 entity 块 data-entity 随 Context 正确（Product / Service）。

**反向 / 对拍**
1. 增补属性前后：H1 / 可见文本 / JSON-LD / canonical / sitemap / llms 逐项不变。
2. 非法 section / 自由文本值 → `template:validate` ERROR。
3. zh/en：属性中实体 / 转化一致（语言无关），inLanguage 仍随 locale。
4. Light/Dark：Section 语义不受主题影响。
5. 多站：Site A / Site B 同页 Section 语义随各自激活包正确，不串站。

---

## 7. 结论

- 本标准把「区块级 AI 语义」收敛为 4 个受控属性 + 4 套受控词表 + 单一渲染出口，
  与 JSON-LD / aria 互补，并给出全 block 默认映射。
- 落地归入 **TD-132**，**吸收并关闭 TD-121**；硬边界保证不破坏 GEO / Schema / SEO 公开契约。
- **STOP，等待裁定，不编码。**
