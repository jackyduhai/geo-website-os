# P-STEP 18R-1 Discovery — Product Capability Patch (TD-151 CaseStudy + TD-152 DownloadAsset)

> **阶段**：18R-1 Discovery（只读 Gate）— **重做版**
> **日期**：2026-09-26
> **代码库**：`D:\GEO-OS-rewrite\geo-website-os`（Laravel 12，branch=`main`，HEAD=`a7e9f7d`，122 commits）
> **前版作废**：基于错误目录 `D:\73466\Demo Tenant A官网\demo-tenant-a-site`（旧简化副本）的 Discovery 与 ADR 全部作废，本文档取代之。
> **审计方式**：全量代码阅读 + 数据库 Schema 逆向 + 配置分析 + 路由/控制器/视图/渲染链路追踪
> **状态**：只读完成，未修改任何产品代码/模板/主题/DB；19A / GitHub / remote / push / release / rc1(`965d63c`) 全 HOLD

---

## 0. 执行摘要

### 架构现实（已验证）

GEO Website OS 拥有完整的 **Entity Graph 体系**：

| 层 | 实现 | 证据 |
|---|---|---|
| Entity 模型 | `app/Models/Entity.php`，6 种冻结类型 organization/product/service/person/location/topic | `:47-52` 常量 |
| Entity 关系 | `app/Models/EntityRelation.php`，5 种关系 produces/offers/uses/located_in/related_to | `:23-27` 常量 |
| 多语言 | `Translatable` trait，同表多行 + `translation_group` UUID，共享列单向同步 | `app/Support/Translatable.php` |
| 多站 | `BelongsToSite` trait，全局 scope + creating 自动填充 site_id | `app/Support/BelongsToSite.php` |
| 类型专属字段 | `entities.metadata` JSON 列（跨语言共享） | 迁移 `2026_09_18_000008:23` |
| GEO 实体图 | `GeoGraphBuilder` 输出 `/geo.json`（entities/relations/contents/facts/site） | `app/Services/Geo/GeoGraphBuilder.php` |
| Schema | `SchemaBuilder::ENTITY_SCHEMA_TYPES` 硬映射 6 型→schema.org | `:296-303` |
| Block 体系 | `config/blocks.php` 注册 27 种 block + `BlockRegistry` + `CompositionRenderer` | 普通块 16 + 系统块 11 |
| 渲染上下文 | `EntityRenderContext`（product/service 详情）、`ListingRenderContext`、`SystemPageRenderContext` | `app/Support/Render/` |
| 搜索 | `search_index` FTS5 + CJK 分词，`SearchIndexSync` 监听 Entity saved/deleted | `app/Support/Search/` |
| 模板 | 8 个 pro 包（零 blade），`TemplatePackageManager` + `ThemeManager` 视图覆盖 | `resources/templates/` |
| SEO | `SeoMeta` 独立表 + `SeoMetaResolver` 统一解析 + `PublicUrl` 唯一 URL 裁决 | `app/Services/Seo/` |

### 核心裁定

**两个能力均作为独立 Entity 类型扩展现有 Entity Graph，不新建表、不新增第二套体系：**

1. **TD-151 CaseStudy** → `Entity::TYPE_CASE_STUDY = 'case_study'`，metadata 承载 industry/scenario/challenge/solution/result，与 Product 通过 `EntityRelation(related_to)` 关联，拥有独立前台页 `/cases/{slug}`，进入 Schema/geo.json/llms.txt/sitemap/Search。
2. **TD-152 DownloadAsset** → `Entity::TYPE_DOWNLOAD_ASSET = 'download_asset'`，metadata 承载 media_id/type/language/version，Product 通过 `EntityRelation(offers)` → DownloadAsset → `metadata.media_id` → Media。无独立前台页（PublicUrl 返回 null），不进 Search/Sitemap（下载资料 noindex），在产品详情页经 `download_panel` 系统块渲染。

**关键工作量**：不是建表，而是**解除 7 处硬编码 type 白名单** + 扩展 2 个 RenderContext + 新增 4 个核心 Block + 修改 1 个冻结测试。

---

## 1. ADR Decisions

### ADR-18R-001：CaseStudy = 独立 Entity 类型 `case_study`（Lite）

**Status**：Proposed（待 18R-2 授权）

**Context**：
- 用户要求 CaseStudy 作为「企业解决方案结果实体」，支持多产品关联、行业标签、中英文独立内容、SEO URL、Schema、GEO 输出、Search Index。
- Entity 体系已存在，6 型冻结。CaseStudy 是企业内容资产三角（产品/案例/新闻）之一，应与 Product/Organization 同属 Entity Graph。
- 现有 `Catalog::cases()` 读的是 organization metadata 里的 `cases` 数组（匿名卡，`quote_verified=false`），不是独立实体——这正是 TD-151 要补的缺口。

**Options 对比**：

| 维度 | A: Entity type='case_study' ✅ | B: Content 子类型 | C: 独立 case_studies 表 |
|---|---|---|---|
| Entity Graph 统一 | ✅ 同表同关系同图 | ❌ Content 是平行体系，不进 entities/relations | ❌ 第三套体系 |
| 多语言 | ✅ Translatable 自动（同表多行） | ✅ Content 也 Translatable | 需自行实现 |
| 多站 | ✅ BelongsToSite 自动 | ✅ | 需自行实现 |
| 与 Product 关系 | ✅ EntityRelation(related_to) | ❌ 无关系层，需 JSON | 需自建关系 |
| geo.json | ✅ entities() 自动收录（无 type 过滤） | ❌ 仅进 contents 数组 | 需自行接线 |
| Schema | 需改 ENTITY_SCHEMA_TYPES 映射 | 复用 Content article() | 需新建 |
| Admin CRUD | 复用 EntityController（改 TYPES 白名单） | 复用 ContentController | 需新建全套 |
| Search | 需改 PublicUrl + documentForEntity path | 自动（Content 已索引） | 需自行接 |
| 前台渲染 | 需扩展 EntityRenderContext | 复用 PageController catch-all | 需新建控制器+视图 |
| 迁移成本 | 0 新表（metadata JSON 承载字段） | 0 新表 | 1 新表 + 索引 |
| 对"统一 Entity Graph"承诺 | ✅ 完全符合 | ❌ 形成 Content 孤岛 | ❌ 形成第三孤岛 |

**Decision**：**A**。理由：
1. CaseStudy 是知识实体而非文章——它有客户/行业/场景/挑战/方案/成果/关联产品的结构化语义，必须进入 Entity Graph 的 entities + relations，AI 才能理解 `Company|CaseStudy|Product|Industry` 图关系。
2. 复用 Entity 全链路（Translatable/BelongsToSite/EntityRelation/GeoGraphBuilder/SearchIndexSync/EntityController），不新增第二套体系。
3. metadata JSON 承载类型专属字段，与现有 product/service/organization 的 metadata 模式一致。

**Consequences**：
- 需解除 7 处硬编码 type 白名单（见 §2.2）。
- `EntitySchemaTest.php:300-304` 冻结 `count($allowed)===6`，需改为 7（或 8，含 DownloadAsset）。
- 需扩展 `EntityRenderContext` 支持 case_study 详情页（当前仅 product/service，`:49-78` return null）。
- 需新增 `PublicUrl::caseStudy()` + `/cases/{slug}` 路由。
- CaseStudy 与 Product 的关联用 `EntityRelation(relation_type='related_to')`，需扩展 `Catalog::relationMap()` 消费 case_study↔product 边。

---

### ADR-18R-002：DownloadAsset = 独立 Entity 类型 `download_asset`（Lite），Product→DownloadAsset→Media

**Status**：Proposed（待 18R-2 授权）

**Context**：
- 用户要求下载资料结构化，关系方向 Product→DownloadAsset→Media，AI 可回答「X 产品有哪些资料」。
- Media 是平铺文件库（disk/path/mime/size/width/height/alt/title），无语义关联层。
- EntityRelation 已存在，可表达 Product→DownloadAsset 的 `offers` 关系。
- DownloadAsset 不需要独立前台页、不需要 Search/Sitemap 收录（下载资料通常 noindex）。

**Options 对比**：

| 维度 | A: Entity type='download_asset' ✅ | B: Media 扩展列 | C: 独立 download_assets 表 |
|---|---|---|---|
| Entity Graph 统一 | ✅ 进 entities 数组（无 url） | ❌ Media 不是 Entity | ❌ 第三套体系 |
| Product 关系 | ✅ EntityRelation(offers) | ❌ 需 product_id 列 + 自行查询 | 需自建关系 |
| 多语言/多站 | ✅ 自动 | 需加列 | 需自行实现 |
| Media 职责 | ✅ Media 保持文件存储单一职责 | ❌ Media 膨胀（type/language/version/product_id） | ✅ |
| Admin CRUD | 复用 EntityController（改 TYPES） | 改 MediaController | 需新建 |
| geo.json | ✅ 自动进 entities（无 url 节点） | ❌ 不进 | 需自行接线 |
| Search/Sitemap | 自然排除（PublicUrl default null）✅ | 需手动排除 | 需手动排除 |
| 迁移成本 | 0 新表 | 加 4-5 列到 media | 1 新表 |
| 类型枚举 | metadata.type（datasheet/manual/certificate/whitepaper/brochure） | media 加 type 列 | 独立 type 列 |

**Decision**：**A**。理由：
1. DownloadAsset 是业务实体（有类型/语言/版本/描述/关联产品），不是媒体文件属性。作为 Entity 进入统一 Graph，AI 可通过 `Product --offers--> DownloadAsset` 关系回答产品资料查询。
2. Entity 的 `name`=资料标题、`summary`=资料描述、`metadata`={media_id, type, language, version}，字段完全够用。
3. PublicUrl::entity() 对 download_asset 返回 null（不进 sitemap/search/有独立页），这正是下载资料应有的行为——无需额外排除逻辑。
4. Media 保持纯净，不混入业务语义。

**Consequences**：
- `Entity::TYPE_DOWNLOAD_ASSET` 加入常量和所有白名单。
- Product→DownloadAsset 用 `EntityRelation(relation_type='offers')`（现有关系类型，`EntityRelation.php:24`）。
- 产品详情页新增 `download_panel` 系统块（detail/main slot），从 EntityRelation 查询该产品的 download_asset 并渲染。
- DownloadAsset 的 `slug` 可自动生成（如 `{product-slug}-datasheet`），Admin 表单可隐藏 slug 字段或自动填充。
- `EntityController::validateData()` 需加 download_asset 分支（media_id 必填、type 枚举校验）。

---

### ADR-18R-003：不新建数据库表，metadata JSON 承载类型专属字段

**Status**：Proposed

**Context**：Entity 表已有 `metadata` JSON 列，现有 product/service/organization/location 均用 metadata 承载类型专属字段（product: core/line/tagline/key_params/params；organization: brand/industry/phone/email/address；location: address/lat/lng）。

**Decision**：CaseStudy 和 DownloadAsset 的专属字段全部放入 `entities.metadata`，不新增列、不新建表。

**CaseStudy metadata 结构**：
```json
{
  "industry": "食品加工",
  "scenario": "中央厨房",
  "challenge": "客户痛点描述",
  "solution": "解决方案概述",
  "result": "量化成果描述",
  "og_image": 123,
  "card_image": 456
}
```
- `industry`/`scenario`：短文本，用于列表筛选和 GEO 输出。
- `challenge`/`solution`/`result`：结构化文本块，英文走 `description` 列（按语言独立），metadata 内放默认语言摘要。
- 关联产品**不**放 metadata——用 `EntityRelation(related_to)` 表达，符合「关系以 EntityRelation 为唯一权威」的架构原则（`Catalog.php:142-148` 注释）。
- `og_image`/`card_image`：与现有 entity metadata 约定一致（int media id，`SeoMetaResolver.php:264-267`）。

**DownloadAsset metadata 结构**：
```json
{
  "media_id": 789,
  "type": "datasheet",
  "language": "zh-CN",
  "version": "v2.1",
  "file_size": "2.4MB"
}
```
- `media_id`：引用 Media.id（与 og_image 同模式，裸整数，应用层维护）。
- `type`：枚举 datasheet/manual/certificate/whitepaper/brochure。
- `language`：资料语言（独立于 Entity locale，因为一个中文 Entity 行可挂英文资料）。
- `version`：版本号。
- `file_size`：可选，从 Media.size 计算或手动填写。

---

## 2. Current Architecture Mapping（真实代码证据）

### 2.1 Entity 数据模型

**`entities` 表**（迁移 `2026_09_18_000008_create_entities_table.php` + `2026_09_23_000001_add_locale_to_translatables.php`）：

| 列 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| site_id | foreignId → sites (restrict) | 多站隔离 |
| type | string(32) | **无 enum 约束**，但应用层 6 型冻结 |
| slug | string(128) | 同站同型同语言唯一 |
| name | string(255) | 按语言独立 |
| summary | text nullable | 按语言独立 |
| description | longText nullable | 按语言独立 |
| status | string(16) default 'draft' | draft/published/archived，跨语言共享 |
| metadata | json nullable | 类型专属字段，跨语言共享 |
| sort_order | integer default 0 | 跨语言共享 |
| published_at | timestamp nullable | 跨语言共享 |
| locale | string(16) default 'zh-CN' | 翻译行语言 |
| translation_group | string(36) nullable | UUID，同逻辑实体多语言关联 |
| timestamps | | |

**唯一约束**：`(site_id, type, slug, locale)` — 同一实体不同语言可共用 slug 或各自独立 slug。
**索引**：`(site_id, type)`、`status`、`slug`、`translation_group`。

**`entity_relations` 表**（迁移 `2026_09_18_000009`）：

| 列 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| site_id | foreignId → sites (restrict) | |
| from_entity_id | foreignId → entities (cascade) | 关系起点 |
| to_entity_id | foreignId → entities (cascade) | 关系终点 |
| relation_type | string(32) | produces/offers/uses/located_in/related_to |
| metadata | json nullable | 关系专属数据 |
| sort_order | integer default 0 | |
| timestamps | | |

**唯一约束**：`(site_id, from_entity_id, to_entity_id, relation_type)` — 同一对实体同一关系类型唯一。

### 2.2 硬编码 type 白名单清单（新增 type 必须逐一解除）

| # | 位置 | 行号 | 行为 | 新增 case_study/download_asset 需改 |
|---|---|---|---|---|
| 1 | `Entity::TYPE_*` 常量 | `Entity.php:47-52` | 6 型冻结枚举 | 加 `TYPE_CASE_STUDY`、`TYPE_DOWNLOAD_ASSET` |
| 2 | `EntityController::TYPES` | `EntityController.php:36-43` | `abort_unless(array_key_exists($type, TYPES), 404)` | 加两项 + 中文标签 |
| 3 | `SchemaBuilder::ENTITY_SCHEMA_TYPES` | `SchemaBuilder.php:296-303` | 查不到映射返回 null（无 JSON-LD） | case_study→`Article`/`TechArticle`；download_asset→`DigitalDocument` |
| 4 | `PublicUrl::entity()` match | `PublicUrl.php:149-160` | default→null（无 url） | case_study 加分支返回 `/cases/{slug}`；download_asset 保持 default null |
| 5 | `EntityRenderContext::forEntity()` | `EntityRenderContext.php:49-78` | 非 product/service return null（404） | 加 case_study 分支；download_asset 不需要详情页（保持 null） |
| 6 | `SearchIndexBuilder::documentForEntity()` | `SearchIndexBuilder.php:188-210` | `PublicUrl::entity()===null` return null；path 硬编码 products/ | case_study 因 PublicUrl 非 null 自动通过；需改 path 分支（`:200-202`）加 case_study→`/cases/{slug}` |
| 7 | `EntitySchemaTest::test_entity_types_are_frozen()` | `EntitySchemaTest.php:300-304` | `assertEquals(6, count($allowed))` | 改为 8（或重构为「允许的 type 集合」断言而非计数） |

**半自动（无需改代码，但需验证）**：
- `GeoGraphBuilder::entities()` — 无 type 过滤，自动收录 ✅
- `GeoGraphBuilder::relations()` — 无 type 过滤，自动收录 ✅
- `SearchIndexSync` — Entity saved/deleted 自动监听 ✅（但被 documentForEntity 的 PublicUrl 门控）
- `Translatable` — 不感知 type，自动可用 ✅
- `BelongsToSite` — 不感知 type，自动可用 ✅
- `Entity::saved/deleted` 事件 — PageCache flush + Page 级联删除 ✅

**需扩展（非白名单，但需新增逻辑）**：
- `LlmsBuilder` — 仅遍历 `Catalog::products()`/`scenes()`，需新增案例段落
- `SitemapBuilder` — 仅 core product + scene，需新增案例段落
- `Catalog::relationMap()` — 仅注册 product/service/organization，需加 case_study↔product 映射
- `Catalog` — 需新增 `Catalog::cases()` 投影（从 Entity type=case_study 读，替代当前读 organization metadata.cases）
- `SectionSemantic::entityFromContext()` — 仅 product/service，需加 case_study→`'Case'`（词表已有 `Case`，`:26`）
- `SqliteFtsEngine` 排序 CASE — 硬编码 product/service 优先，可加 case_study 优先级

### 2.3 GEO/SEO 链路

```
SchemaBuilder (app/Services/Geo/SchemaBuilder.php)
  ├─ ENTITY_SCHEMA_TYPES (6 型硬映射) → entity() 通用输出
  │    └─ 新增 type 必须加映射，否则返回 null
  ├─ article() — Content 文章
  ├─ faqPage() / faqPageFromList()
  ├─ breadcrumb()
  ├─ webPage() — Page 通用
  └─ render() → <script type="application/ld+json">

GeoGraphBuilder (app/Services/Geo/GeoGraphBuilder.php)
  ├─ build() → /geo.json: { $schema, site, facts, entities, relations, contents }
  ├─ entities() — PublicIndex::entityQuery()->forLocale()  (无 type 过滤 ✅)
  ├─ relations() — EntityRelation 全量，按 translation_group 跨语言解析 (无 type 过滤 ✅)
  └─ contents() — PublicIndex::contentQuery()

LlmsBuilder (app/Services/Geo/LlmsBuilder.php)
  ├─ 遍历 Catalog::products() (core + indexable)
  ├─ 遍历 Catalog::scenes() (indexable)
  └─ ⚠️ 无其他 entity type 通道，需新增案例段落

SitemapBuilder (app/Services/Geo/SitemapBuilder.php)
  ├─ 产品详情: core product → /products/{slug}, priority 0.7
  ├─ 场景详情: scene service → /solutions/{slug}/, priority 0.8
  ├─ Content 文章: knowledge 0.6 + 其余栏目 0.5
  └─ ⚠️ 无 case_study 通道，需新增

PublicUrl (app/Support/PublicUrl.php)
  ├─ entity() match: product(core)→/products/{slug}, service(scene)→/solutions/{slug}/, default→null
  ├─ organizationAnchor() → {base}/#organization
  └─ ⚠️ case_study 需加分支; download_asset 保持 null
```

### 2.4 Block / Composition / Render 体系

**BlockRegistry**（`app/Support/Blocks/BlockRegistry.php`）：
- boot 时从 `config('blocks.types')` 加载（`:31-40`），无合并步骤。
- `render()` → 视图 `site.blocks.{type}`（`BlockType.php:47` 默认值）。
- `resolveData()` 硬编码 match 仅 `product_grid/service_grid/content_grid`（`:120-125`），新增数据源块需加分支。
- `selectableForSlot()` 排除 `system=true` 块（`:83-89`）。

**现有 27 种 block**：
- 普通块 16：hero, rich_text, image, media_text, feature_grid, stats, logo_cloud, faq, testimonial, cta, contact_info, breadcrumb, product_grid, service_grid, content_grid, form_reference
- Entity 详情系统块 5：entity_hero, entity_specifications, entity_steps, entity_relations, bottom_cta
- 系统页主体块 6：sys_solutions, sys_products, sys_knowledge, sys_about, sys_factory, sys_cooperation

**CompositionRenderer**（`app/Support/Render/CompositionRenderer.php`）：
- 遍历模板 slot，逐块 `BlockRegistry::render()`，无 type 白名单。
- 系统块注入由各 RenderContext 的 `blocksForSlot()` 负责（`new VirtualBlock(...)`）。

**EntityRenderContext**（`app/Support/Render/EntityRenderContext.php`）：
- `forEntity()` 仅接受 product/service（`:49-78`），其余 return null → 404。
- `blocksForSlot()` 硬编码：
  - header → `[VirtualBlock('entity_hero')]`
  - main → product: `entity_steps → entity_relations[scenes] → entity_specifications`；service: `entity_relations[pain_combo] → entity_specifications → entity_steps`
  - related → override Page blocks 或默认 `[faq?, entity_relations[related|adjacent], bottom_cta]`
- `viewContext()` 注入 `$product/$line/$scenes` 或 `$scene/$combo/$keyProduct`，blade 直接读这些变量。
- `productSchema()`（`:361-385`）手写 Product JSON-LD（brand/manufacturer/PropertyValue/HowTo），不走 SchemaBuilder::entity()。

**系统块 = VirtualBlock**（`app/Support/Render/VirtualBlock.php`）：非持久化，由 RenderContext `new` 出来，不入库。

### 2.5 Admin Entity CRUD

**EntityController**（`app/Http/Controllers/Admin/EntityController.php`）：
- 通用 CRUD，`TYPES` 常量 6 型（`:36-43`），`create()`/`store()` 均 `abort_unless(in TYPES, 404)`。
- `validateData()` 按 type 分支校验（`:338-357`）：product(meta_line/meta_tagline)、organization(org_*)、location(loc_*)、service(svc_*)。
- `applyMetadata()` 按 type 分支写 metadata（`:391-433`），通用部分只有 card_image/og_image。
- 多语言：`?trans=<locale>` 切换，`blankTranslation()` 预填共享列。
- 表单 `resources/views/admin/entities/form.blade.php`：按 `$isProduct/$isOrg/$isLocation/$isService` 硬编码 section，新 type 不会自动出现专属字段。

**EntityRelationController**：独立 CRUD，5 型关系冻结，表单下拉列默认语言权威行。

**路由**（`routes/admin.php:73-91`）：`entities/*` 7 条 + `relations/*`，无 per-type 路由。

### 2.6 Template 体系

- 8 个 pro 包在 `resources/templates/`：commerce/construction/education/export/healthcare/manufacturing/saas/service。
- **包内零 blade 文件**（实测递归 `*.blade.php` = 0）。
- 包结构：`manifest.json` + `template.json`（TemplateDefinition）+ `theme.json` + `defaults/` + `recipes/*.json` + `preview/`。
- `TemplatePackageManager::register()` 读 template.json → `TemplateRegistry::registerPack()`。
- **核心模板不可被包覆盖**（`TemplateRegistry.php:83-85`），包只能注册新 template key（如 mfg-home）。
- 视图覆盖通道是 **Theme**（`resources/themes/{active}/views/` prepend 到 View finder，`ThemeManager.php:130-150`），不是 Template Package。
- `config/templates.php` detail 槽的 block 白名单是**显式数组**（`:62-66`），不含 `*`，新 block 必须加入。

### 2.7 Search 体系

- `search_documents` 表：`resource_type/resource_id/site_id/locale/slug/path/title/summary/body/published_at`，唯一键 `(resource_type, resource_id, site_id, locale)`。
- FTS5 虚拟表 `search_index`：title/summary/body 经 `CjkTokenizer` 分词。
- `SearchIndexSync`：Entity saved → `upsertEntity()` + `Catalog::flush()`；deleted → `forgetEntity()`。
- `documentForEntity()` 硬门槛：`PublicUrl::entity($e)===null → return null`（`:190`）；path 硬编码 service→`/solutions/`，其余→`/products/`（`:200-202`）。
- `SqliteFtsEngine` 排序：`CASE resource_type WHEN 'product' THEN 0 WHEN 'service' THEN 1 ELSE 2 END`（`:80`）。
- locale 隔离：两个引擎都 `where locale = ?`。

### 2.8 现有「案例」状态

- `Catalog::cases()`（`Catalog.php:213, 502-513`）读 organization metadata 里的 `cases` 数组，形状 `{title, region_label, quote, quote_verified, combo, image, detail_page}`。
- 3 条示例案例全部 `quote_verified=false, detail_page=false`。
- `routes/web.php` 无 `/cases` 路由（注释明确「不建案例中心」）。
- 首页 testimonial 块可展示客户评价，但不是结构化案例实体。

---

## 3. Data Model（数据契约）

### 3.1 CaseStudy Entity

**Entity 行**（type='case_study'）：

| 字段 | 来源 | 说明 |
|---|---|---|
| id | entities.id | 自动 |
| site_id | BelongsToSite | 自动填充 |
| type | 'case_study' | 新增常量 |
| slug | entities.slug | URL 标识，如 `industrial-robot-overseas` |
| name | entities.name | 案例标题（按语言独立） |
| summary | entities.summary | 案例摘要（按语言独立） |
| description | entities.description | 完整案例叙事 Markdown（按语言独立） |
| status | entities.status | draft/published |
| published_at | entities.published_at | |
| sort_order | entities.sort_order | |
| locale | entities.locale | zh-CN / en / ... |
| translation_group | entities.translation_group | UUID，多语言关联 |
| metadata.industry | json | 行业标签（如「工业制造」「食品加工」） |
| metadata.scenario | json | 应用场景（可关联 Facts::scenes() slug） |
| metadata.challenge | json | 客户挑战摘要（默认语言） |
| metadata.solution | json | 解决方案摘要（默认语言） |
| metadata.result | json | 成果摘要（默认语言） |
| metadata.og_image | json | int media id |
| metadata.card_image | json | int media id（列表卡图） |

**不存储**（明确排除）：客户联系人、合同金额、销售阶段、CRM ID、审批状态。

**与 Product 的关系**：
- `EntityRelation(from_entity_id=case_study, to_entity_id=product, relation_type='related_to')`
- 一个 CaseStudy 可关联多个 Product（多条 EntityRelation 行，sort_order 排序）。
- 反向查询：Product 的 `relationsTo()` 可查到哪些案例关联了它。
- `Catalog::relationMap()` 需扩展：识别 `case_study --related_to--> product`，在案例详情页派生「相关产品」、在产品详情页派生「客户案例」。

**与 Industry/Scenario 的关系**：
- v1.0 用 metadata 字符串标签（industry/scenario），不新建 Industry/Scenario Entity。
- 若未来需要 Industry 作为独立实体，可迁移为 Entity type='topic' + EntityRelation，但 18R 不做。

### 3.2 DownloadAsset Entity

**Entity 行**（type='download_asset'）：

| 字段 | 来源 | 说明 |
|---|---|---|
| id | entities.id | 自动 |
| site_id | BelongsToSite | 自动 |
| type | 'download_asset' | 新增常量 |
| slug | entities.slug | 自动生成，如 `{product-slug}-datasheet-zh` |
| name | entities.name | 资料标题（如「XX 产品技术规格书」） |
| summary | entities.summary | 资料描述（可空） |
| description | entities.description | 留空或详细说明 |
| status | entities.status | draft/published |
| metadata.media_id | json | **int，引用 Media.id**（核心关联） |
| metadata.type | json | 枚举：datasheet/manual/certificate/whitepaper/brochure |
| metadata.language | json | 资料语言（zh-CN/en/...，独立于 Entity locale） |
| metadata.version | json | 版本号（如 v2.1、2026-09） |
| locale | entities.locale | 默认 zh-CN（下载资料通常不需要多语言 Entity 行，language 在 metadata） |

**与 Product 的关系**：
- `EntityRelation(from_entity_id=product, to_entity_id=download_asset, relation_type='offers')`
- 关系方向严格为 Product → DownloadAsset（符合用户要求）。
- 一个 Product 可 offer 多个 DownloadAsset（多条关系，sort_order 排序）。
- `offers` 是现有关系类型（`EntityRelation.php:24`），无需新增。

**与 Media 的关系**：
- `metadata.media_id` → `Media.id`（裸整数引用，与现有 og_image/card_image 同模式）。
- Media 保持文件存储单一职责，不感知 DownloadAsset。
- DownloadAsset 删除时不级联删 Media（媒体库独立管理）。

### 3.3 实现后的统一 Entity Graph

```
Organization (entity/organization/{slug})
  │
  ├─ produces → Product (entity/product/{slug})
  │              │
  │              ├─ offers → DownloadAsset (entity/download_asset/{slug})
  │              │              └─ metadata.media_id → Media (文件)
  │              │
  │              └─ related_to ← CaseStudy (entity/case_study/{slug})
  │                                  ├─ metadata.industry
  │                                  ├─ metadata.scenario
  │                                  ├─ metadata.challenge/solution/result
  │                                  └─ related_to → Product (多产品关联)
  │
  └─ offers → Service (entity/service/{slug}) [现有]
```

所有节点共享 `entities` 表，所有边共享 `entity_relations` 表，统一进入 `/geo.json` 的 entities + relations 数组。**无孤岛、无第二套体系。**

---

## 4. Admin Capability

### 4.1 CaseStudy Admin（复用 EntityController，扩展白名单）

**改动清单**：

| 文件 | 改动 |
|---|---|
| `app/Models/Entity.php` | 加 `const TYPE_CASE_STUDY = 'case_study'` |
| `app/Http/Controllers/Admin/EntityController.php` | `TYPES` 加 `'case_study' => '客户案例'`；`validateData()` 加 case_study 分支（industry/scenario 可选文本）；`applyMetadata()` 加 case_study 分支（写 industry/scenario/challenge/solution/result/card_image） |
| `resources/views/admin/entities/form.blade.php` | 加 `$isCaseStudy` 判定，显示案例专属字段组：行业、场景、客户挑战、解决方案、成果、卡图 |
| `routes/admin.php` | 无需改（通用 entities 路由已覆盖） |

**案例专属表单字段**：
- 行业（text，或 select 从已有案例 distinct industry）
- 场景（select，选项来自 `Facts::scenes()` 或自由文本）
- 客户挑战（textarea → metadata.challenge）
- 解决方案（textarea → metadata.solution）
- 成果（textarea → metadata.result）
- 列表卡图（media 选择 → metadata.card_image）
- 关联产品：**不在 Entity 表单编辑**，在 `admin/relations` 独立管理（EntityRelationController），与现有 product↔service 关系管理一致。

**发布门禁**：CaseStudy 走 Entity 的 status 流转（draft→published），无 ContentGate 那种 GEO 四层完整性强制。若需要案例发布前校验（如必须有关联产品），可在 `EntityController::publish()` 加 type 条件，但 v1.0 建议不强制。

### 4.2 DownloadAsset Admin（复用 EntityController，扩展白名单）

**改动清单**：

| 文件 | 改动 |
|---|---|
| `app/Models/Entity.php` | 加 `const TYPE_DOWNLOAD_ASSET = 'download_asset'` |
| `app/Http/Controllers/Admin/EntityController.php` | `TYPES` 加 `'download_asset' => '下载资料'`；`validateData()` 加 download_asset 分支（media_id required、type enum、language、version）；`applyMetadata()` 加 download_asset 分支 |
| `resources/views/admin/entities/form.blade.php` | 加 `$isDownloadAsset` 判定，显示下载资料专属字段组：文件（media 选择）、类型（select）、语言（select）、版本（text）；隐藏 slug 字段（自动生成）或标注「自动生成」 |
| `routes/admin.php` | 无需改 |

**下载资料专属表单字段**：
- 文件（media 选择器，必填 → metadata.media_id）
- 类型（select: datasheet=技术规格书 / manual=使用手册 / certificate=认证证书 / whitepaper=白皮书 / brochure=产品手册）
- 语言（select: zh-CN/en/...，默认 zh-CN → metadata.language）
- 版本（text，如 v2.1 → metadata.version）
- 标题（name，资料名称）
- 描述（summary，可选）

**关联产品**：在 `admin/relations` 中创建 `Product --offers--> DownloadAsset` 关系。或在产品编辑页提供「添加下载资料」快捷入口（v1.0 可先只用 relations 管理）。

**slug 自动生成**：`EntityController::store()` 中，若 type='download_asset' 且 slug 为空，自动生成 `{product-slug}-{type}-{language}`（需从关系中取 product，或在表单中加「关联产品」临时字段）。v1.0 简化：slug 字段保留，由 Admin 手动填写或留空自动用 name 的 slug 化。

### 4.3 EntityRelation Admin（无需改）

- 现有 `EntityRelationController` 已支持 5 种关系类型，`offers` 和 `related_to` 均可直接使用。
- 表单下拉列出所有 published Entity（默认语言行），新增 case_study/download_asset 后自动出现在下拉中。
- 无需改代码。

### 4.4 后台导航

- Entity 列表页 `admin/entities/{tab?}` 加「案例」和「下载资料」tab（TYPES 常量自动驱动）。
- 关系管理 `admin/relations` 不变。

---

## 5. Frontend Blocks

### 5.1 新增核心 Block 清单

| Block key | 类型 | category | system | allowed slots | 说明 |
|---|---|---|---|---|---|
| `case_list` | 数据源块 | source | false | `listing/main`, `*/main` | 案例列表网格（类似 product_grid，数据源 entity=case_study） |
| `case_detail` | 系统块 | system | true | `detail/main` | 案例详情主体（挑战/方案/成果三段式 + 关联产品） |
| `download_panel` | 系统块 | system | true | `detail/main` | 产品详情页下载资料面板（按 type 分组） |
| `download_list` | 数据源块 | source | false | `listing/main`, `*/main` | 下载中心列表（v1.0 可选，默认不做独立下载中心页） |

### 5.2 case_list（数据源块）

**config/blocks.php 注册**：
```php
'case_list' => [
    'label' => '案例网格（数据源）',
    'category' => 'source',
    'icon' => 'doc',
    'per_locale' => true,
    'data_source' => true,
    'fields' => [
        ['key'=>'title','label'=>'标题','type'=>'text'],
        ['key'=>'subtitle','label'=>'副标题','type'=>'text'],
        ['key'=>'limit','label'=>'最多显示','type'=>'number'],
        ['key'=>'source','label'=>'数据来源','type'=>'source',
         'entity'=>'case_study','modes'=>['all','industry','picked','related']],
    ],
    'default' => ['title'=>'','subtitle'=>'','limit'=>6,
                  'source'=>['mode'=>'all','industry'=>'','ids'=>[]]],
],
```

**BlockRegistry::resolveData()** 需加 `case_list` 分支（`:120-125`），查询 `Entity::published()->ofType('case_study')->forLocale()`，支持按 industry 过滤、picked 选稿、related（当前 Entity 关联的案例）。

**视图**：`resources/views/site/blocks/case_list.blade.php`（核心分发，模板包无需自带）。渲染案例卡（卡图 + 标题 + 行业标签 + 客户名 + 摘要 + 链接 `/cases/{slug}`）。

### 5.3 case_detail（系统块）

**系统块，VirtualBlock，由 CaseStudy RenderContext 注入**。

**config/blocks.php 注册**：
```php
'case_detail' => [
    'label' => '案例详情主体（系统）',
    'category' => 'system',
    'icon' => 'doc',
    'system' => true,
    'per_locale' => true,
    'data_source' => false,
    'allowed' => ['detail/main'],
    'fields' => [],
    'default' => [],
    'help' => '系统块：由当前 CaseStudy Entity 直驱（挑战/方案/成果 + 关联产品），不可手动添加。',
],
```

**视图**：`resources/views/site/blocks/case_detail.blade.php`。渲染：
- 客户信息栏（客户名 + 行业 + 场景标签）
- 挑战/方案/成果三段式（从 metadata.challenge/solution/result + description）
- 关联产品卡（从 EntityRelation related_to 查询）
- GEO 语义属性 `data-entity="Case"`（SectionSemantic 词表已有 `Case`）

### 5.4 download_panel（系统块）

**系统块，VirtualBlock，由 EntityRenderContext product 分支注入到 detail/main**。

**config/blocks.php 注册**：
```php
'download_panel' => [
    'label' => '下载资料面板（系统）',
    'category' => 'system',
    'icon' => 'doc',
    'system' => true,
    'per_locale' => true,
    'data_source' => false,
    'allowed' => ['detail/main'],
    'fields' => [],
    'default' => [],
    'help' => '系统块：由当前 Product 的 offers 关系直驱下载资料列表，无数据不渲染。',
],
```

**EntityRenderContext 改动**：
- `fixedMainBlocks()` product 分支（`:224-241`）在 `entity_specifications` 后注入 `new VirtualBlock('download_panel', [])`。
- `viewContext()` 准备 `$downloads` 变量：查询 `EntityRelation::where('from_entity_id', $product->id)->where('relation_type','offers')->with('toEntity')`，按 metadata.type 分组。
- 无下载资料时 `$downloads` 为空集合，blade 判断 `isNotEmpty()` 才渲染。

**视图**：`resources/views/site/blocks/download_panel.blade.php`。按 type 分组展示（技术规格书 / 使用手册 / 认证证书 / 白皮书 / 产品手册），每项：图标 + 标题 + 语言 + 版本 + 文件大小 + 下载链接（`Media.url()`）。

### 5.5 download_list（数据源块，v1.0 可选）

若需要独立下载中心页，注册 `download_list` 数据源块（类似 case_list，entity=download_asset）。但 Lite 边界下建议 v1.0 仅产品详情页内嵌 download_panel，不做独立下载中心。

### 5.6 案例详情页 RenderContext

**新增或扩展**：在 `EntityRenderContext::forEntity()` 加 case_study 分支（`:49-78`），或新建 `CaseStudyRenderContext`。

**推荐：扩展 EntityRenderContext**，加 case_study 分支：
- `template()` → `TemplateRegistry::get('detail')`（复用详情模板）
- `blocksForSlot()`:
  - header → `[VirtualBlock('entity_hero')]`（复用，entity_hero blade 需加 case_study 分支显示案例标题+客户+行业）
  - main → `[VirtualBlock('case_detail'), VirtualBlock('entity_relations[related_products]'), VirtualBlock('faq')?, VirtualBlock('bottom_cta')]`
  - related → override Page blocks 或默认
- `viewContext()` 注入 `$caseStudy` 变量（含 metadata 解析 + 关联产品查询）

**entity_hero.blade.php 改动**：加 `$isCaseStudy = ($entity->type === 'case_study')` 分支，显示案例标题 + 客户名 + 行业标签（替代产品的 tagline/关键参数卡）。

### 5.7 前台路由

**新增**（`routes/web.php`，zh-CN + en 双注册）：
```php
// 案例中心
Route::get('cases{slash?}', [CaseController::class, 'index'])
    ->where('slash', '/?')->defaults('_slash', 1)->name('cases.index');
Route::get('cases/{slug}{slash?}', [CaseController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')->where('slash', '/?')->name('cases.show');
```

**CaseController**（新建 `app/Http/Controllers/Site/CaseController.php`）：
- `index()`：案例列表页，用 `ListingRenderContext` + `case_list` 系统块（或 `sys_cases` 系统块）。
- `show($slug)`：查 `Entity::published()->ofType('case_study')->forLocale()->where('slug',$slug)` → `EntityRenderContext::forEntity($entity)` → `CompositionRenderer::render()`。

**PublicUrl 新增**：
```php
case_study => self::caseStudy($entity->slug),
// caseStudy(): return self::base() . self::localePrefix() . '/cases/' . $slug;
```

---

## 6. SEO Contract

### 6.1 CaseStudy SEO

| 项 | 实现 | 接入点 |
|---|---|---|
| URL | `/cases/{slug}`（详情型，无尾斜杠） | `PublicUrl::caseStudy()` |
| 列表 URL | `/cases/`（目录型，尾斜杠） | 新增路由 |
| `<title>` | `SeoMetaResolver::resolveEntity()` → seo_title ?: name | 已有机制 |
| meta description | seo_desc ?: summary | 已有机制 |
| canonical | `PublicUrl::caseStudy()` | 已有机制 |
| OG image | metadata.og_image → Media.url() | `SeoMetaResolver.php:264-267` |
| noindex | SeoMeta.noindex 控制 | `PublicIndex::entityQuery()` 已排除 |
| JSON-LD | `SchemaBuilder::entity()` 映射 case_study→`Article`（或 `TechArticle`），输出 @type/name/description/inLanguage/url/image | 需改 `ENTITY_SCHEMA_TYPES` |
| breadcrumb | 首页 → 案例中心 → 案例标题 | `SchemaBuilder::breadcrumb()` |
| sitemap | 新增段落：published case_study → `/cases/{slug}`，priority 0.6 | 需改 `SitemapBuilder` |
| hreflang | `publishedLocaleCodes()` 输出多语言对等链接 | 已有 Translatable 机制 |

### 6.2 DownloadAsset SEO

| 项 | 实现 |
|---|---|
| 独立 URL | 无（PublicUrl::entity() 对 download_asset 返回 null） |
| 产品页内嵌 | download_panel 系统块渲染下载链接，链接指向 `Media.url()` |
| JSON-LD | 产品 Product schema 加 `hasPart` → `DigitalDocument`（name/description/encodingFormat/inLanguage/url），在 `EntityRenderContext::productSchema()` 扩展 |
| sitemap | 不单独收录（无独立页） |
| noindex | 下载文件 URL 本身可被搜索引擎抓取，但 DownloadAsset Entity 不进 sitemap |
| 下载链接 rel | 建议加 `rel="nofollow"`（可选，v1.0 可不加） |

### 6.3 SchemaBuilder 改动

`ENTITY_SCHEMA_TYPES`（`:296-303`）扩展：
```php
Entity::TYPE_CASE_STUDY    => 'Article',       // 或 'TechArticle'
Entity::TYPE_DOWNLOAD_ASSET => 'DigitalDocument',
```

`entity()` 方法（`:310-366`）通用逻辑已覆盖 name/description/url/image/sameAs/address/geo，无需为新 type 加专属分支。DownloadAsset 的 `encodingFormat`（MIME type）需从 metadata.media_id → Media.mime 读取，可在 `entity()` 中加 `if ($e->type === TYPE_DOWNLOAD_ASSET)` 追加 encodingFormat 字段。

### 6.4 对现有 SEO 的影响

- 新增 `/cases/` 栏目页和案例详情页 = 新 URL，不影响现有页面排名。
- 产品详情页新增 download_panel = 页面内容增加，对 SEO 正面。
- **不修改任何现有 URL**（不 rename、不 301 现有页面）。
- SchemaBuilder `entity()` 输出对现有 6 型无影响（仅加映射表条目）。

---

## 7. GEO Contract

### 7.1 geo.json（GeoGraphBuilder）

**entities 数组**：
- CaseStudy **自动进入**（`entities()` 无 type 过滤，`:108-123`）。
- 输出：`{id: "entity/case_study/{slug}", type: "case_study", slug, name, summary, metadata, url: "/cases/{slug}", updated_at}`。
- DownloadAsset **自动进入**，输出 `{id: "entity/download_asset/{slug}", type: "download_asset", ..., url: null}`（无 url 节点，符合 Public Render Contract）。

**relations 数组**：
- `Product --offers--> DownloadAsset` 自动进入（`relations()` 无 type 过滤，`:163-218`）。
- `CaseStudy --related_to--> Product` 自动进入。
- 跨语言解析：关系边指向默认语言行 id，按 translation_group 反查当前语言行（已有逻辑）。

**无需改 GeoGraphBuilder 代码**——新 type 自动被收录。

### 7.2 llms.txt（LlmsBuilder）

**需新增「客户案例」段落**：
```
## 客户案例 (Case Studies)

### {case.name}
- URL: {PublicUrl::caseStudy(case.slug)}
- 客户: {case.name 中的客户名 或 metadata.industry}
- 行业: {case.metadata.industry}
- 场景: {case.metadata.scenario}
- 关联产品: {通过 EntityRelation related_to 查询 → Product name + URL}
- 挑战: {case.metadata.challenge}
- 方案: {case.metadata.solution}
- 成果: {case.metadata.result}
```

**查询**：`Entity::published()->ofType('case_study')->forLocale()->orderBy('sort_order')->limit(20)->get()`，预载 EntityRelation。

**产品下载资料**：在现有产品段落中，每个产品下列出 offers 的 DownloadAsset：
```
### {product.name} 下载资料
- [{type}] {name} ({language}, v{version}) — {Media.url()}
```

**需改 LlmsBuilder 代码**：新增案例段落 + 产品段落加下载资料列表。

### 7.3 GEO Entity Contract（用户要求的输出格式）

**CaseStudy geo.json 节点**：
```json
{
  "id": "entity/case_study/industrial-robot-overseas",
  "type": "case_study",
  "slug": "industrial-robot-overseas",
  "name": "某汽车厂海外工厂自动化案例",
  "summary": "案例摘要",
  "metadata": {
    "industry": "汽车制造",
    "scenario": "海外工厂",
    "challenge": "...",
    "solution": "...",
    "result": "..."
  },
  "url": "https://example.com/cases/industrial-robot-overseas"
}
```

**DownloadAsset geo.json 节点**：
```json
{
  "id": "entity/download_asset/product-x-datasheet",
  "type": "download_asset",
  "slug": "product-x-datasheet",
  "name": "Product X 技术规格书",
  "summary": "详细技术参数",
  "metadata": {
    "media_id": 789,
    "type": "datasheet",
    "language": "zh-CN",
    "version": "v2.1"
  }
}
```

**关系边**：
```json
{"from": "entity/product/product-x", "to": "entity/download_asset/product-x-datasheet", "relation_type": "offers"}
{"from": "entity/case_study/industrial-robot-overseas", "to": "entity/product/product-x", "relation_type": "related_to"}
```

### 7.4 robots.txt

无需改动。AI crawler 白名单（`config/geo.php`）已覆盖主流 AI。`/cases/` 和 `/storage/` 均允许抓取。

---

## 8. Search Contract

### 8.1 CaseStudy 搜索

**自动同步**：`SearchIndexSync` 监听 Entity saved/deleted（`:60-67`），case_study 保存后自动触发 `upsertEntity()`。

**索引门槛**：`documentForEntity()` 的 `PublicUrl::entity()===null` 检查（`:190`）——case_study 因 PublicUrl 新增分支返回非 null，**自动通过门槛**。

**需改 path 分支**（`SearchIndexBuilder.php:200-202`）：
```php
// 当前
$path = $e->type === 'service' ? "/solutions/{$e->slug}/" : "/products/{$e->slug}";
// 改为
$path = match($e->type) {
    'service' => "/solutions/{$e->slug}/",
    'case_study' => "/cases/{$e->slug}",
    default => "/products/{$e->slug}",
};
```

**索引字段**：title=name, summary=summary, body=description（已有 `documentForEntity()` 逻辑，`:188-210`）。metadata 的 industry/scenario/challenge/result 不进搜索正文（v1.0），若需搜索「行业」「客户名」可追加到 body 字段。

**排序加权**：`SqliteFtsEngine.php:80` CASE 表达式可加 `WHEN 'case_study' THEN 1`（排在 product 之后、content 之前），或保持 ELSE 2。v1.0 建议加 case_study 优先级。

**locale 隔离**：已有 `where locale = ?`，案例多语言行各自独立索引。

### 8.2 DownloadAsset 搜索

**不进入搜索索引**：
- `PublicUrl::entity()` 对 download_asset 返回 null → `documentForEntity()` 返回 null → 不索引。
- 这是正确行为：用户搜索意图是找产品/案例/文章，不是找 PDF。
- DownloadAsset 通过产品详情页 download_panel 触达。

### 8.3 前台搜索 UI

- `SearchController` 不传 types 参数（空=全部），case_study 自动出现在结果中。
- 结果模板可加 type badge（「案例」「产品」「文章」），v1.0 可选。
- 若需按 type 筛选（tab: 全部/产品/案例/文章），需改 SearchController + 视图，v1.0 可不做。

---

## 9. Template Compatibility

### 9.1 核心原则

新增 block（case_list/case_detail/download_panel/download_list）均为**核心 Block**，随核心代码分发：
- 配置在 `config/blocks.php`（核心）
- 视图在 `resources/views/site/blocks/{type}.blade.php`（核心）
- 系统块由核心 RenderContext 注入

**8 个 pro 模板包零 blade 文件**，无需为新增 block 修改任何模板包。模板包通过 `theme.json` tokens 控制样式，通过 recipes 写入 block content，不携带 block renderer。

### 9.2 需改的模板配置

| 位置 | 改动 | 原因 |
|---|---|---|
| `config/templates.php` detail/main 白名单（`:62-66`） | 加 `case_detail`, `download_panel` | detail 槽是显式 block 白名单数组，不含 `*` |
| `config/templates.php` listing/main（若有白名单） | 加 `case_list` | 同上 |
| 各 pro 包 `template.json` 的自定义模板（如 mfg-home.main） | **可选**：若包的模板 main 槽是显式列表且想允许 case_list，需加；若是 `'*'` 则自动允许 | 包的 template.json 自主决定 |

**核心模板（config/templates.php）必须改**，模板包**不需要强制改**（若包用 `'*'` 通配则自动兼容；若用显式列表且想展示案例块，由包作者自行添加）。

### 9.3 视图覆盖通道

第三方主题（非模板包）可通过 `resources/themes/{active}/views/site/blocks/case_detail.blade.php` 覆盖案例详情块的渲染——这是正规的 Theme 覆盖通道（`ThemeManager.php:130-150`），不影响核心。

### 9.4 禁止事项

- ❌ 模板包携带 `CaseStudyController.php` / `DownloadService.php`
- ❌ 模板包 override `site/blocks/case_detail.blade.php`（模板包不参与视图覆盖，只有 Theme 可以）
- ❌ 模板包修改 Entity 查询或绕过 SchemaBuilder/GeoGraphBuilder
- ❌ 模板包自带案例/下载的路由定义

### 9.5 对现有模板的影响

- 现有 `entity_hero.blade.php` 需加 case_study 分支（核心文件，非模板包）。
- 现有 `entity_relations.blade.php` 可能需加 case_study 相关 part（如 `related_products` for case_study）。
- 产品详情页 main 槽新增 download_panel 系统块——现有产品页会多一个下载区（无数据时不渲染），不影响布局。
- 8 个 pro 包的产品详情页自动获得 download_panel（因为是核心 RenderContext 注入），无需包改动。

---

## 10. Migration Plan

### 10.1 无新建表

CaseStudy 和 DownloadAsset 均复用 `entities` 表，**不需要新建表、不需要加列**。`entities.metadata` JSON 列已存在，承载类型专属字段。

### 10.2 Migration 文件

| # | 文件 | 操作 | 幂等性 |
|---|---|---|---|
| 1 | `2026_09_26_000001_seed_case_study_entity_type.php` | （可选）插入示例 CaseStudy Entity + EntityRelation | `firstOrCreate` 守卫 |
| 2 | （无） | 不需要改 entities 表结构 | — |

**v1.0 建议不做数据迁移**——不插入示例案例/下载资料，保持 blank install 零数据。Admin 手动创建。

### 10.3 Fresh Install 路径

- `php artisan migrate` → entities 表已含 metadata 列，type 列 varchar(32) 无 enum 约束。
- `php artisan db:seed` → 现有 seeder 不涉及 case_study/download_asset。
- **结果**：entities 表无 case_study/download_asset 行，entity_relations 无 offers/related_to 新边。✅ 零垃圾数据。

### 10.4 Upgrade 路径（18Q → 18R）

- 现有库执行 `php artisan migrate` → 无结构变更（无新 migration 或仅可选种子）。
- 代码改动后，Entity 常量新增 2 个 type，Admin 可创建。
- **不影响**：现有 Product/Service/Organization Entity、现有 URL、现有 sitemap/Search Index（新 type 无数据时不产生新索引条目）。
- **可 rollback**：代码回退即可（无 DB 结构变更）；若有可选种子 migration，down() 删除插入的示例行。

### 10.5 风险

| 风险 | 等级 | 缓解 |
|---|---|---|
| EntitySchemaTest 冻结 type=6 导致测试失败 | 高 | 同步修改测试为 type=8 或重构为集合断言 |
| 新增 type 后某处白名单遗漏导致 404 或无输出 | 中 | 按 §2.2 清单逐一检查，全量测试回归 |
| EntityRenderContext 扩展影响现有 product/service 详情页 | 中 | case_study 分支独立，不改现有 product/service 分支逻辑；download_panel 无数据不渲染 |
| PublicUrl 加 case_study 分支后 sitemap/llms 产出新 URL | 低 | 新 URL 不影响现有页面；sitemap 新增段落独立 |
| metadata JSON 查询性能 | 低 | 案例/下载资料量小（<100），metadata 读为 PHP array，无 DB JSON 查询 |

---

## 11. Test Plan

### 11.1 现有测试基线

测试目录包含（实测）：EntitySchemaTest、GeoGraphTest、SchemaJsonLdTest、FeedPublicRenderContractTest、AdminEntityCrudTest、AdminEntityRelationCrudTest、DetailComposition18G2Test、PageComposition18GTest、HomeComposition18ITest、SystemPageComposition18G2bTest、SectionSemanticTest、TemplatePackage18L3aTest、TemplateEcosystem18L3bTest、TemplateSafety18L4aTest、SearchProductization18HTest、ConfigResilienceTest、FreshBootTest、FinalAcceptanceTest、ProductizationAcceptanceTest 等。

### 11.2 必须修改的现有测试

| 测试文件 | 改动 | 原因 |
|---|---|---|
| `tests/Feature/EntitySchemaTest.php:300-304` | `test_entity_types_are_frozen()`：`assertEquals(6, count($allowed))` → `assertEquals(8, ...)`，白名单数组加 case_study/download_asset | 硬阻塞 |
| `tests/Feature/AdminEntityCrudTest.php` | 若遍历 TYPES 断言 tab 计数，同步更新 | TYPES 从 6→8 |
| `tests/Feature/DetailComposition18G2Test.php` | 若断言 product detail main 槽块顺序，download_panel 注入后顺序变化 | 新增系统块 |
| `tests/Feature/FeedPublicRenderContractTest.php` | 若断言 sitemap/llms URL 集合，新增案例 URL 可能影响 | 新 URL 段落 |
| `tests/Feature/SearchProductization18HTest.php` | 若断言 search resource_type 集合，case_study 新增 | 新 type 索引 |
| `tests/Unit/SectionSemanticTest.php` | 若加 case_study→Case 映射，需补断言 | 词表映射 |
| `tests/Feature/GeoGraphTest.php` | 若断言 entities type 集合或数量，需更新 | 新 type 进 geo.json |

### 11.3 新增测试清单

| 测试文件 | 覆盖点 |
|---|---|
| `CaseStudyEntityTest.php` | 创建/编辑/删除 case_study Entity；metadata 存读；Translatable 多语言；BelongsToSite 隔离 |
| `CaseStudySchemaTest.php` | SchemaBuilder::entity() 对 case_study 输出 Article JSON-LD；字段完整 |
| `CaseStudyGeoGraphTest.php` | geo.json entities 含 case_study 节点；relations 含 case_study--related_to-->product |
| `CaseStudyFrontendTest.php` | `/cases/` 200；`/cases/{slug}` 200 + 正确视图；case_detail 系统块渲染；关联产品显示 |
| `CaseStudySitemapTest.php` | sitemap 含案例 URL；priority 正确 |
| `CaseStudyLlmsTest.php` | llms.txt 含案例段落；字段完整 |
| `CaseStudySearchTest.php` | case_study 进入 search_index；path 正确 /cases/{slug}；搜索命中 |
| `DownloadAssetEntityTest.php` | 创建/编辑/删除 download_asset；metadata.media_id/type/language/version；EntityRelation offers 关联 |
| `DownloadAssetProductPanelTest.php` | 产品详情页 download_panel 渲染；按 type 分组；无数据不渲染；Media.url() 链接正确 |
| `DownloadAssetSchemaTest.php` | 产品 JSON-LD 含 DigitalDocument hasPart；encodingFormat 正确 |
| `DownloadAssetExcludedFromSearchTest.php` | download_asset 不进 search_index（PublicUrl null 门槛） |
| `DownloadAssetExcludedFromSitemapTest.php` | download_asset 不进 sitemap |
| `EntityRelationCrossTypeTest.php` | case_study--related_to-->product 和 product--offers-->download_asset 关系创建/查询/跨语言解析 |
| `TemplateBlockCompatibilityTest.php` | 新增 block 在核心模板 detail/main 白名单中；pro 包零 blade 不受影响 |

### 11.4 回归范围

- 全量测试（`php artisan test`）必须通过。
- 重点回归：Entity CRUD、EntityRelation、详情页组合（product/service）、GEO 三件套（schema/geo.json/llms/sitemap）、Search、模板生态。
- 五场景验证（18R-3）：新企业建站 / 制造业官网 / 出口官网 / AI Agent 操作 / 第三方模板。

---

## 12. Boundary（实施边界）

### ✅ 18R 包含

| 能力 | 范围 |
|---|---|
| CaseStudy Entity Lite | Entity type='case_study' + metadata(industry/scenario/challenge/solution/result) + EntityRelation(related_to→Product) + Admin CRUD + 前台 /cases/ + Schema(Article) + geo.json + llms.txt + sitemap + Search |
| DownloadAsset Entity Lite | Entity type='download_asset' + metadata(media_id/type/language/version) + EntityRelation(Product offers→) + Admin CRUD + 产品详情 download_panel 系统块 + DigitalDocument schema + geo.json 节点 |
| SEO·GEO 接入 | SchemaBuilder 映射、PublicUrl::caseStudy()、LlmsBuilder 案例段落、SitemapBuilder 案例段落、geo.json 自动收录 |
| Admin 管理 | EntityController TYPES 扩展 + 表单分支 + EntityRelation 管理（已有） |
| Block 支持 | case_list(数据源) + case_detail(系统) + download_panel(系统) + download_list(可选) |
| 多语言 | Translatable 自动可用（案例中英文独立内容） |
| 多站 | BelongsToSite 自动可用 |
| 测试 | ~14 个新测试文件 + 现有测试更新 + 全量回归 |

### ❌ 18R 不包含

| 排除项 | 原因 |
|---|---|
| CRM / 客户关系管理系统 | 超出 Lite，案例仅存客户名称展示 |
| 客户联系人 / 商务合同 / 销售数据 / 项目金额 | 非官网内容范畴 |
| DAM / 文件管理系统 | Media 库已够用，DownloadAsset 仅做语义关联层 |
| 下载权限 / 付费系统 / 下载统计 | 超出企业官网 Lite 范围 |
| 审批流 / 内容工作流 | 复用 draft/published 二态 |
| Industry / Scenario 独立 Entity | v1.0 用 metadata 字符串标签，不新建实体 |
| 独立下载中心页 | v1.0 仅产品详情页内嵌 download_panel |
| 搜索按 type 筛选 / 高级搜索 / facet | v1.0 仅 type badge + case 字段可搜 |
| geo.json 正式 Entity Graph endpoint 重构 | 现有 GeoGraphBuilder 已够用，不重构 |
| Entity 基础层重构 / EntityRelation 新增关系类型 | 复用现有 5 型（offers/related_to 足够） |
| Marketplace / 模板市场 | 不在范围 |
| 案例审批 / 客户授权流程 | 业务流程，非技术实现 |
| 新建数据库表 | 全部复用 entities + entity_relations + media |

---

## 13. Impact Matrix

| 模块 | CaseStudy 影响 | DownloadAsset 影响 | 改动类型 |
|---|---|---|---|
| **Database** | 无新表（metadata JSON） | 无新表（metadata JSON） | 无 |
| **Model** | Entity 加 TYPE_CASE_STUDY 常量 | Entity 加 TYPE_DOWNLOAD_ASSET 常量 | 扩展 |
| **Admin Controller** | EntityController TYPES+validate+applyMetadata | EntityController TYPES+validate+applyMetadata | 扩展 |
| **Admin Routes** | 无（通用 entities 路由） | 无 | 无 |
| **Admin Views** | entities/form.blade.php 加案例字段组 | entities/form.blade.php 加下载字段组 | 修改 |
| **Frontend Controller** | 新建 Site/CaseController（index+show） | 无（产品页内嵌） | 新增 |
| **Frontend Routes** | 加 /cases/ + /cases/{slug}（zh+en） | 无 | 新增 |
| **Frontend Views** | 新建 site/blocks/case_list.blade.php + case_detail.blade.php | 新建 site/blocks/download_panel.blade.php | 新增 |
| **Render Context** | EntityRenderContext 加 case_study 分支 | EntityRenderContext product 分支加 download_panel | 修改 |
| **Block Registry** | config/blocks.php 加 case_list + case_detail | config/blocks.php 加 download_panel | 修改 |
| **BlockRegistry::resolveData** | 加 case_list 分支 | （download_panel 是系统块，不走 resolveData） | 修改 |
| **Template** | config/templates.php detail/main 白名单加 case_detail | 加 download_panel | 修改 |
| **Schema** | ENTITY_SCHEMA_TYPES 加 case_study→Article | 加 download_asset→DigitalDocument；productSchema 加 hasPart | 修改 |
| **GEO (geo.json)** | 自动收录（无需改代码） | 自动收录（无需改代码） | 无 |
| **GEO (llms.txt)** | LlmsBuilder 加案例段落 | LlmsBuilder 产品段落加下载列表 | 修改 |
| **Sitemap** | SitemapBuilder 加案例段落 | 不收录（无 url） | 修改 |
| **PublicUrl** | 加 caseStudy() + entity() match 分支 | 保持 default null | 修改 |
| **Search** | SearchIndexBuilder path 分支加 case_study；排序 CASE 可加 | 不索引（PublicUrl null 门槛） | 修改 |
| **Catalog** | 新增 Catalog::cases() 投影；relationMap 加 case_study↔product | relationMap 加 product→download_asset(offers) | 修改 |
| **SectionSemantic** | entityFromContext 加 case_study→'Case' | （download_asset 无详情页，不需要） | 修改 |
| **EntityRelation** | 复用 related_to（无需改） | 复用 offers（无需改） | 无 |
| **Migration** | 无结构变更（可选种子） | 无结构变更 | 无 |
| **Test** | ~8 个新测试 + 现有测试更新 | ~5 个新测试 + 现有测试更新 | 新增/修改 |
| **Navigation** | 后台 Entity 列表 tab 自动加；前台导航可加 /cases/ 链接 | 后台 tab 自动加 | 配置 |

---

## 14. Implementation Plan（精确文件清单）

### 14.1 新增文件

| # | 文件路径 | 说明 |
|---|---|---|
| 1 | `app/Http/Controllers/Site/CaseController.php` | 案例前台 index+show |
| 2 | `resources/views/site/blocks/case_list.blade.php` | 案例网格块 |
| 3 | `resources/views/site/blocks/case_detail.blade.php` | 案例详情系统块 |
| 4 | `resources/views/site/blocks/download_panel.blade.php` | 下载面板系统块 |
| 5 | `tests/Feature/CaseStudyEntityTest.php` | |
| 6 | `tests/Feature/CaseStudySchemaTest.php` | |
| 7 | `tests/Feature/CaseStudyGeoGraphTest.php` | |
| 8 | `tests/Feature/CaseStudyFrontendTest.php` | |
| 9 | `tests/Feature/CaseStudySitemapTest.php` | |
| 10 | `tests/Feature/CaseStudyLlmsTest.php` | |
| 11 | `tests/Feature/CaseStudySearchTest.php` | |
| 12 | `tests/Feature/DownloadAssetEntityTest.php` | |
| 13 | `tests/Feature/DownloadAssetProductPanelTest.php` | |
| 14 | `tests/Feature/DownloadAssetSchemaTest.php` | |
| 15 | `tests/Feature/DownloadAssetExcludedFromSearchTest.php` | |
| 16 | `tests/Feature/DownloadAssetExcludedFromSitemapTest.php` | |
| 17 | `tests/Feature/EntityRelationCrossTypeTest.php` | |
| 18 | `tests/Feature/TemplateBlockCompatibilityTest.php` | |

### 14.2 修改文件

| # | 文件路径 | 改动 |
|---|---|---|
| 1 | `app/Models/Entity.php` | 加 TYPE_CASE_STUDY + TYPE_DOWNLOAD_ASSET 常量 |
| 2 | `app/Http/Controllers/Admin/EntityController.php` | TYPES 加 2 项；validateData() 加 2 分支；applyMetadata() 加 2 分支 |
| 3 | `app/Http/Controllers/Site/ProductController.php` | （若需要）show 方法传 downloads 到视图（实际由 EntityRenderContext 注入） |
| 4 | `app/Support/Render/EntityRenderContext.php` | forEntity() 加 case_study 分支；fixedMainBlocks() product 加 download_panel；viewContext() 加 $caseStudy + $downloads |
| 5 | `app/Support/Render/ListingRenderContext.php` | （若案例列表用 sys_cases 系统块）加 case listing 支持 |
| 6 | `app/Services/Geo/SchemaBuilder.php` | ENTITY_SCHEMA_TYPES 加 2 映射；entity() 加 download_asset encodingFormat |
| 7 | `app/Services/Geo/LlmsBuilder.php` | 加案例段落；产品段落加下载资料 |
| 8 | `app/Services/Geo/SitemapBuilder.php` | 加案例段落 |
| 9 | `app/Support/PublicUrl.php` | 加 caseStudy() 方法；entity() match 加 case_study 分支 |
| 10 | `app/Support/Search/SearchIndexBuilder.php` | documentForEntity() path 分支加 case_study |
| 11 | `app/Support/Search/SqliteFtsEngine.php` | 排序 CASE 加 case_study 优先级（可选） |
| 12 | `app/Support/Catalog.php` | 新增 cases() 投影；relationMap() 加 case_study↔product + product→download_asset |
| 13 | `app/Support/Blocks/BlockRegistry.php` | resolveData() 加 case_list 分支 |
| 14 | `app/Support/Blocks/SectionSemantic.php` | entityFromContext() 加 case_study→'Case' |
| 15 | `config/blocks.php` | 注册 case_list + case_detail + download_panel（+ download_list 可选） |
| 16 | `config/templates.php` | detail/main 白名单加 case_detail + download_panel |
| 17 | `routes/web.php` | 加 /cases/ + /cases/{slug} 路由（zh+en） |
| 18 | `resources/views/admin/entities/form.blade.php` | 加案例字段组 + 下载资料字段组 |
| 19 | `resources/views/site/blocks/entity_hero.blade.php` | 加 case_study 分支 |
| 20 | `resources/views/site/blocks/entity_relations.blade.php` | （若需要）加 case_study related_products part |
| 21 | `tests/Feature/EntitySchemaTest.php` | type 计数 6→8 |
| 22 | 其他受影响测试（见 §11.2） | 同步更新 |

### 14.3 实施顺序（18R-2 建议）

```
Step 1: Entity 常量 + Admin 白名单扩展（Entity.php + EntityController + form.blade.php）
        → 可在 Admin 创建 case_study/download_asset 实体
Step 2: EntityRelation 关联验证（创建 product--offers-->download_asset, case_study--related_to-->product）
Step 3: PublicUrl + SchemaBuilder（case_study 有 URL + JSON-LD）
Step 4: GeoGraph 验证（geo.json 自动收录，无需改代码）
Step 5: LlmsBuilder + SitemapBuilder（案例段落）
Step 6: Search（path 分支 + 索引验证 + download_asset 排除验证）
Step 7: Block 注册（config/blocks.php + BlockRegistry::resolveData + 3 个 blade）
Step 8: RenderContext 扩展（case_study 分支 + download_panel 注入 + viewContext）
Step 9: 前台路由 + CaseController
Step 10: Catalog 扩展（cases() 投影 + relationMap）
Step 11: SectionSemantic + 模板白名单
Step 12: 测试（14 个新测试 + 现有测试更新 + 全量回归）
Step 13: 五场景验证（18R-3）
```

### 14.4 工作量估算

| 模块 | 预估 |
|---|---|
| Entity 常量 + Admin 扩展 + 表单 | 1 天 |
| PublicUrl + Schema + Llms + Sitemap + Search | 1 天 |
| Block 注册 + 3 个 blade + RenderContext 扩展 | 1.5 天 |
| 前台路由 + CaseController + Catalog 扩展 | 0.5 天 |
| SectionSemantic + 模板白名单 + entity_hero 分支 | 0.5 天 |
| 测试（14 新 + 现有更新 + 全量回归） | 2 天 |
| 五场景验证 | 1 天 |
| **合计** | **~7.5 天** |

---

## 15. AI 使用场景验证

**场景**：「创建工业机器人海外案例页」

**正确路径（18R 后）**：
```
1. Admin → 实体管理 → 新建 → 类型选「客户案例」
2. 填写：标题/slug/客户名/行业(汽车制造)/场景(海外工厂)/挑战/方案/成果/卡图
3. 保存（draft）→ 关系管理 → 创建 CaseStudy --related_to--> Product(工业机器人A)
4. 发布（published）
5. 自动：Entity saved → SearchIndexSync upsert → PageCache flush
6. 自动：geo.json entities 收录 + relations 收录
7. 自动：sitemap 收录 /cases/industrial-robot-overseas
8. 自动：llms.txt 案例段落收录结构化信息
9. 前台：/cases/industrial-robot-overseas → EntityRenderContext case_study 分支
   → entity_hero(案例标题+客户+行业) → case_detail(挑战/方案/成果)
   → entity_relations(关联产品) → bottom_cta
10. AI 查询「哪些企业使用过工业机器人」→ geo.json relations 找到
    CaseStudy --related_to--> Product，llms.txt 案例段落提供客户/行业/成果
```

**反模式（禁止）**：
- ❌ 创建普通 Content 文章 + 插关键词（无结构化字段，AI 无法识别为案例实体）
- ❌ 用 Media 上传 PDF 当案例（无客户/行业/方案/成果语义，不进 Entity Graph）
- ❌ 模板包自定义 CaseStudyController（绕过核心 GEO 管道）
- ❌ metadata 存 related_product_ids 数组（绕过 EntityRelation，不进 geo.json relations）

---

## 16. 结论与下一步

### Discovery 结论

1. **架构现实**：GEO Website OS 拥有完整 Entity Graph（Entity 6 型 + EntityRelation 5 型 + Translatable + BelongsToSite + GeoGraphBuilder + BlockRegistry + CompositionRenderer + Search FTS + 8 模板包）。此前基于错误目录的「Entity 不存在」结论作废。
2. **TD-151 CaseStudy**：作为独立 Entity type='case_study'，metadata 承载行业/场景/挑战/方案/成果，与 Product 通过 EntityRelation(related_to) 关联，拥有独立前台页 /cases/{slug}，全链路进入 Schema/geo.json/llms.txt/sitemap/Search。**不新建表、不新增第二套体系。**
3. **TD-152 DownloadAsset**：作为独立 Entity type='download_asset'，metadata 承载 media_id/type/language/version，Product 通过 EntityRelation(offers) → DownloadAsset → Media。无独立前台页、不进 Search/Sitemap，产品详情页经 download_panel 系统块渲染。**Media 保持纯净。**
4. **核心工作量**：解除 7 处硬编码 type 白名单 + 扩展 EntityRenderContext + 新增 3-4 个核心 Block + 修改 1 个冻结测试 + 新增 14 个测试文件。
5. **零数据库结构变更**：全部复用 entities + entity_relations + media 表，metadata JSON 承载类型专属字段。
6. **迁移安全**：fresh install 零数据，upgrade 不影响现有 Entity/URL/sitemap/Search，可 rollback（代码回退即可）。

### 状态

- ✅ 18R-1 Discovery 完成（只读，commit 于 DIR A，未打 tag）
- ⏸ **18R-2 Implementation — 等待授权**
- ⏸ 18R-3 Validation — 等待 18R-2
- ⏸ 19A / GitHub / remote / push / Release / rc1(`965d63c`) — **全 HOLD**
- DIR B（`D:\73466\Demo Tenant A官网\demo-tenant-a-site`）的 c752343 错误产物未删除、未操作，留待用户处理

### 等待裁定

**请授权 18R-2 Implementation**，或对本 Discovery 中的 ADR / 数据契约 / 实施范围提出调整。授权后将按 §14.3 实施顺序执行。

---

*文档结束。本文件为 18R-1 Discovery（重做版）交付物，基于正确代码库 `D:\GEO-OS-rewrite\geo-website-os` 的真实 Entity 架构，只读阶段产物，未修改任何产品代码。*
