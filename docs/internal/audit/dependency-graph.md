# Dependency Graph

## 图 1：当前总体架构

```
Request
  ↓
Route (routes/web.php — 静态定义，但 slug 白名单来自 Facts::)
  ↓
Middleware (SecurityHeaders → HandleRedirects → CanonicalizeSlash → CachePage → CaptureAttribution)
  ↓
Controller (Site/* — 7 个 use Facts)
  ↓
┌─────────────────────────────────────────────┐
│  数据来源                                    │
│  ├── Facts:: (config/facts.php) — 66处调用  │
│  ├── Fact Model (facts 表)                  │
│  ├── Setting Model (settings 表)            │
│  ├── Content Model (contents 表)            │
│  ├── Category Model (categories 表)         │
│  ├── Group Model (groups 表)                │
│  ├── PageBlock Model (page_blocks 表)       │
│  ├── Menu Model (menus 表)                  │
│  ├── Media Model (media 表)                 │
│  └── Copy / Narrative / HomeBlockDefaults   │
└─────────────────────────────────────────────┘
  ↓
GEO Engine (SchemaBuilder / LlmsBuilder / SitemapBuilder — 依赖 Facts + Setting + Fact + 硬编码)
  ↓
Blade View (resources/views — 79个模板，SEO meta 由 Controller/Builder 注入)
  ↓
Response (PageCache 缓存整页)
```

## 图 2：Facts 依赖图

```
config/facts.php
  ↓
App\Support\Facts (13处 config('facts') 读取)
  ↓
调用者 (10个文件 use App\Support\Facts，66处 Facts:: 调用)
  ├── app/Http/Controllers/Site/AboutController.php
  ├── app/Http/Controllers/Site/ContactController.php
  ├── app/Http/Controllers/Site/CooperationController.php
  ├── app/Http/Controllers/Site/FactoryController.php
  ├── app/Http/Controllers/Site/HomeController.php
  ├── app/Http/Controllers/Site/ProductController.php
  ├── app/Http/Controllers/Site/SolutionController.php
  ├── app/Services/Geo/SchemaBuilder.php
  ├── app/Services/Geo/LlmsBuilder.php
  └── app/Services/Geo/SitemapBuilder.php
  ↓
间接调用
  └── routes/web.php (通过 Facts::productLines()/CORE_PRODUCTS/scenes() 生成路由白名单)
```

### Facts 方法 → 未来数据来源映射

| Facts 方法 | 当前调用者 | 业务含义 | 未来来源 |
|-----------|-----------|----------|----------|
| `company()` | SchemaBuilder, LlmsBuilder | 公司名称/地址/电话/成立时间 | Entity(type=organization) |
| `productLines()` | web.php, ProductController, LlmsBuilder, SitemapBuilder | 产品线列表/slug | Entity(type=product) GROUP BY metadata.line + Category |
| `products()` | ProductController, LlmsBuilder, SitemapBuilder | 产品详情 | Entity(type=product) |
| `productsByLine()` | LlmsBuilder, SitemapBuilder | 按产品线筛选产品 | Entity(type=product).metadata.line |
| `scenes()` | web.php, SolutionController, LlmsBuilder, SitemapBuilder | 应用场景 | Entity(type=service) + Category |
| `sceneCombo()` | SolutionController, LlmsBuilder | 场景推荐产品组合 | EntityRelation(produces/offers) |
| `salesRegions()` | SchemaBuilder, LlmsBuilder | 销售区域 | Organization.metadata.area_served |
| `cooperation()` | CooperationController, LlmsBuilder | 合作方式/流程 | Content + settings |
| `brandLanguage()` | LlmsBuilder | 品牌口号/价值观 | Organization.metadata + settings |
| `CORE_PRODUCTS` | web.php, Facts::isCoreProduct() | 6款核心产品 slug | Entity(type=product).metadata.is_core |
| `isCoreProduct()` | SitemapBuilder, LlmsBuilder | 判断核心产品 | Entity.metadata.is_core |

## 图 3：GEO Engine 数据来源

```
┌──────────────────────────────────────────────────────────┐
│                    当前数据来源                            │
├──────────────┬──────────────────┬────────────────────────┤
│ SchemaBuilder│ LlmsBuilder      │ SitemapBuilder         │
├──────────────┼──────────────────┼────────────────────────┤
│ Fact::publicMap()│ Facts::company()│ Facts::productLines()│
│ Setting::allCached()│ Facts::brandLanguage()│ Facts::products()│
│ Facts::company()│ Facts::salesRegions()│ Facts::isCoreProduct()│
│ Facts::salesRegions()│ Facts::productLines()│ Facts::scenes()│
│ config('app.url')│ Facts::products()│ Category::          │
│ 硬编码(Sample City/Sample Province/│ Facts::productsByLine()│ Content::published()│
│ Sample SnackSample Marinade/Example)│ Facts::scenes()  │ URL前缀硬编码         │
│              │ Facts::sceneCombo()│ /products/ /solutions/│
│              │ Facts::cooperation()│ /knowledge/          │
│              │ Content::published()│                        │
│              │ Group::knowledgeChannels()│                  │
│              │ 硬编码(公司描述段落)│                        │
└──────────────┴──────────────────┴────────────────────────┘
         ↓                    ↓                    ↓
    Schema.org JSON-LD    llms-full.txt       sitemap.xml
```

### 目标架构（v1.2）

```
Site (sites 表)
  ├── Entity (entities 表 — organization/product/service)
  ├── EntityRelation (entity_relations 表 — produces/offers/located_at)
  ├── Content (contents 表)
  ├── Category (categories 表)
  └── SeoMeta (seo_metas 表 — MorphOne)
         ↓
    GEO Engine (纯数据驱动，无硬编码)
         ↓
  ├── Schema.org (从 Entity + Relation 自动生成)
  ├── llms.txt (从 Entity + Content 自动生成)
  └── Sitemap (从 Entity + Content + Category 自动生成)
```

### GAP

| Builder | 当前硬编码 | 目标 |
|---------|-----------|------|
| SchemaBuilder | L44 公司名兜底, L83-84 省市, L134 knowsAbout, L145/L192 品牌名 | 全部从 Entity/Setting 读取，无兜底硬编码业务名 |
| LlmsBuilder | L27-30 公司描述段落, L40/L134/L144 品牌名 | 从 Entity + Content 生成描述 |
| SitemapBuilder | URL 前缀 /products/ /solutions/ /knowledge/ | 从 Site Configuration 读取可配置前缀 |

## 图 4：URL 数据流

### 当前

```
Request → Route (web.php 中 Facts:: 生成 slug 白名单正则)
  → Controller (ProductController/SolutionController)
  → Facts:: 获取业务数据 (config/facts.php)
  → Model (Content/Category) 获取数据库数据
  → Blade 渲染
  → SEO meta: Content.metaDescription() / Setting
  → GEO Schema: SchemaBuilder (Facts + Setting + 硬编码)
  → Response
```

### v1.2 目标

```
Request → 稳定路由 (/products/{slug}, /services/{slug}, /knowledge/{slug}, /{path})
  → SeoUrlGenerator::resolveEntity($slug, $type)
  → 查 entities 表 (type + slug + status=published)
  → 查 redirects 表 (301 兼容旧 URL)
  → Controller/View
  → SEO: SeoMeta (默认值继承: SeoMeta → Content → Site → 兜底)
  → GEO: Site + Entity + EntityRelation → SchemaBuilder
  → Response
```

## 图 5：当前架构 vs v1.2 目标

| 层 | 当前 | v1.2 目标 | GAP |
|----|------|-----------|-----|
| 目录结构 | app/Http, app/Models, app/Services, app/Support | app/Core, app/Cms, app/Http, app/Console | 需分层重构 |
| Site 模型 | 不存在 | sites 表 + site_id 全局 scope | 需新建 |
| Entity 模型 | facts 表 + config/facts.php (非统一模型) | entities 单表 + type 枚举 | 需新建 + 数据迁移 |
| EntityRelation | Facts::sceneCombo() 硬编码 | entity_relations 表 (纯FK) | 需新建 |
| SeoMeta | 分散在 Content/Setting | seo_metas 表 (MorphOne) | 需新建 |
| URL 解析 | Facts:: 生成白名单 | SeoUrlResolver 查库 | 需重构 |
| GEO 数据 | Facts + Setting + 硬编码 | Entity + Relation + Site | 需换数据源 |
| 业务数据 | config/facts.php + 8个seed migration | ExampleSeeder → entities | 需剥离 |
| 升级/备份 | 不存在 | Release + Backup + Rollback | 需新建 |

## 其他业务依赖

| 类 | 定义位置 | 调用者 | 业务数据 | 未来替代 |
|----|----------|--------|----------|----------|
| `Copy` | app/Support/Copy.php | InquiryController, AppServiceProvider | 文案默认值 | settings 表 |
| `Narrative` | app/Support/Narrative.php | 6个Site Controller + NarrativeController + Content model | 叙事文案生成 | Content model + settings |
| `HomeBlockDefaults` | app/Support/HomeBlockDefaults.php | HomeController (8处) | 首页区块默认值 | page_blocks 表 + ExampleSeeder |
| `ExampleUrlGenerator` | app/Support/ExampleUrlGenerator.php | AppServiceProvider 注册 | URL 生成逻辑 | SeoUrlGenerator (重命名+通用化) |
