# P-STEP 18S Capability 2 — GEO Health Dashboard · Discovery（只读）

- 阶段：P-STEP 18S / Product Capability 2（GEO 内容·语义健康后台视图）
- 基线 HEAD：`941ad3c`（18S Capability 1 TD-161 修复提交后）
- 本轮性质：**只读 Discovery**。Implementation 与 Capability 3 全部 HOLD；本文档仅做架构盘点与可行性裁定，不含任何代码/迁移/新表落地。
- 定位边界：本能力回答「当前 Site 的公开内容/实体/SEO·GEO 输出能否被搜索引擎与 AI 正确理解、抓取、引用」，是**语义健康**视图；**不是**传统基础设施 /health。
- 结论：Discovery Gate 通过条件满足（见 D 节），可进入后续人工评审；本文档 STOP 点即交付。

---

## A. Current Architecture（现有能力盘点）

### A.1 已存在的「健康」相关能力（职责边界必须分清）

| 现有对象 | 路径 | 职责 | 与本能力关系 |
| --- | --- | --- | --- |
| `Api\HealthController::health` | `app/Http/Controllers/Api/HealthController.php` | **无鉴权基础设施探活**：db 计数（contents/published）、GEOFlow push/pull 开关。供监控与 GEOFlow 健康检查。 | **不可冒充**。属"基础设施健康"，与本能力"语义健康"正交；本 Dashboard 不得复用其路由/响应，也不得把语义检查塞进此公开端点。 |
| `Admin\DashboardController::index` | `app/Http/Controllers/Admin/DashboardController.php` | 总览：内容计数、复核到期、`ContentGate` 失败项（`$gateFailures`）、Setting 缺口（ICP/邮箱/Logo）、操作日志。 | **天然邻接与数据源**。它已经在跑 `ContentGate` 并渲染失败列表；新 Dashboard 应复用其聚合范式（`View` + `compact` 传给 `admin.dashboard` 类视图），并把语义检查作为新的一组卡片挂出，而非另起炉灶。 |
| `Services\Gate\ContentGate` | `app/Services/Gate/ContentGate.php` | **内容创作/发布门禁**（硬约束）：四层结构（结论/解释/边界）、证据链≥2 且带 source、治理字段（owner/reviewed_at）、title/slug 结构、极限词/对标/冲突口径、fact_refs 有效性、占位符、NAP 电话一致性。返回 `{passed, errors[], warnings[]}`。 | **现有 GEO 审计底层**。本 Dashboard = 运行概览；ContentGate = 深度诊断规则。Dashboard 只**调用并展示**其结果（DashboardController 已在做 `$gate->check($c)`），**不复制、不重写**其规则；本能力新增的 A–E 检查是 ContentGate **不覆盖**的盲区。 |

### A.2 语义检查可直接复用的数据源服务（全部已存在、site-scoped）

| 服务 / Support | 路径 | 关键方法 / 契约 | 服务于哪个检查 |
| --- | --- | --- | --- |
| `SeoMetaResolver` | `app/Services/Seo/SeoMetaResolver.php` | `resolveSite/resolveContent/resolveEntity/resolvePage/resolveListing` → `SeoResult`（含 `ogTitle/ogDescription/ogImage/noindex/nofollow/canonical/schemaType`）。OG 链：SeoMeta 覆盖 → 实体/内容字段 → site fallback → system 默认；`ogImage` 经 `Media::url()` 绝对地址。已内置 `preloadContentSeoMetas/preloadEntitySeoMetas/preloadMediaPaths` **批量预载消除 N+1**。 | A（Missing OG）、D（noindex Leakage） |
| `SeoResult` | `app/Services/Seo/SeoResult.php` | 只读 DTO，`toArray()` 输出 title/description/canonical/og_*/noindex/nofollow/robots/schema_type。 | A、D |
| `PublicUrl` | `app/Support/PublicUrl.php` | 公开落地页 URL 唯一裁决层。`PublicUrl::entity(Entity): ?string`——核心产品→`/products/{slug}`、场景服务→`/solutions/{slug}/`、案例→`/cases/{slug}`；**无独立前台页的实体（组织/人物/地点/主题/非核心产品/无场景服务）返回 null**。host/scheme 按请求 origin 或站点 domain 裁决。 | B（Published Product Without Public URL） |
| `PublicIndex` | `app/Support/PublicIndex.php` | 公开可索引查询口径：`entityQuery()/contentQuery()`（published + 启用栏目 + 非 noindex + 当前 locale）。GeoGraphBuilder/Sitemap/Llms 统一经此过滤。 | A/B/C/D/E（统一公开口径，避免各自实现"什么算公开"） |
| `SchemaBuilder` | `app/Services/Geo/SchemaBuilder.php` | `organization()/website()/article(Content)/entity(Entity)`；`entity()` 经 `EntityCapabilityRegistry::schemaType($type)` 映射 Product/Service/CaseStudy 等 Schema 类型，`?array`（无可输出则 null）。 | C（JSON-LD Emission） |
| `GeoGraphBuilder` | `app/Services/Geo/GeoGraphBuilder.php` | `/geo.json` 构建器。`relations()` 已实现**两类边的权威口径**：Entity→Entity（`EntityRelation`，site-scoped，两端均公开 + 当前 locale 才输出）与 Content→Entity（`ContentEntity` pivot，site-scoped，两端公开才输出）；`entities()/contents()` 经 `PublicIndex` + 批量预载。 | E（GEO Edge Count） |
| `SitemapBuilder` / `LlmsBuilder` | `app/Services/Geo/*.php` | sitemap.xml / llms.txt 构建，输入同为 PublicIndex 公开集。 | C 交叉验证（有 URL 才进 sitemap） |

### A.3 模型与表（全部已存在，零新增）

- `Entity`：type 冻结枚举（organization/product/service/case_study/...）、status（draft/published/archived）、site-scoped（`BelongsToSite`）、locale、translation_group；slug 契约 `^[a-z0-9]+(-[a-z0-9]+)*$`、按 site+type+locale 唯一；TD-161 已加 creating 钩子兜底空 slug。
- `EntityRelation`：五型冻结（produces/offers/uses/located_in/related_to），site-scoped，`saving` 钩子强制两端同站（跨站抛异常）。
- `ContentEntity`（18R-2c 引入）：Content↔Entity pivot，带 `relation_type`、site-scoped。即 Check E 的 Content→Entity 边来源（与 `EntityRelation` 是**两张不同的表**，不可混为一谈）。
- `Content`：status published/draft/archived、type article/page、category、`og_image_id/cover_id`、`review_due`、`geo_*` 门禁字段。
- `SeoMeta`：site-scoped、locale、三态外键（content_id/entity_id/page_id 可空，全空=站点级）、`title/description/og_title/og_description/og_image_path/noindex/nofollow/canonical/schema_type`。

### A.4 路由 / 视图 / 导航现状

- admin 路由组 `admin.auth + admin.site`（鉴权 + 当前站点解析）。现有 GEO 相关：`admin.geo.tools`（`GET geo/tools`）、`admin.geo.preview`（sitemap|llms|robots|rss）、`admin.geo.sync-logs`；18S 新增 `admin.wizard` / `admin.wizard.save`。
- 视图：`resources/views/admin/`（`layout.blade.php` + `dashboard.blade.php`），GEO 工具页 `admin/geo/{tools,preview,sync-logs}.blade.php`。无独立组件目录（components 目录为空），卡片/表格/badge 约定内联在 layout 与各页。
- 导航分组（实测浏览器）：「内容中心」「搜索与 AI（SEO/GEO）」「外观与扩展」。GEO 工具（抓取产出/Sitemap）位于「搜索与 AI」组。新 Dashboard 自然落点即此组，紧邻 `admin.geo.tools`。

---

## B. Existing Reusable Components（复用分级）

### B.1 可直接复用（零改动，Dashboard 调用即可）
- `PublicIndex::entityQuery()/contentQuery()` — 公开口径唯一入口（published + 非 noindex + 启用栏目 + locale）。
- `SeoMetaResolver` + `preload*` — OG/noindex 解析与 N+1 消除，直接对公开集批量解析。
- `PublicUrl::entity(Entity)` — B 检查的真实 URL 裁决（返回 null 即无落地页）。
- `SchemaBuilder::entity/article` — C 检查"该页型是否应产出 JSON-LD"的判定器。
- `GeoGraphBuilder::relations()` 的边过滤口径 — E 检查计数的权威口径（EntityRelation + ContentEntity，两端公开）。**建议**：Implementation 时将该过滤口径抽为可被 Dashboard 直接调用的只读计数方法，或 Dashboard 直接对同一两表用 `PublicIndex` 口径聚合，**禁止另写一套边判定规则**。
- `ContentGate::check(Content)` — A–E 之外的内容深度诊断结果，Dashboard 直接展示。
- admin 鉴权中间件组 `admin.auth + admin.site`、`SiteContext::currentSite()`、`LocaleContext::current()`、`SiteCacheKey`（若需短期缓存）。

### B.2 仅参考（不直接调用）
- `Api\HealthController` — 仅作"基础设施探活 vs 语义健康"职责边界的反面参照，不复用。
- `DashboardController` 现有 `$stats/$gateFailures/$settingGaps` — 复用其**聚合+视图传值范式**，但新 Dashboard 是独立路由/视图（避免把首页总览撑爆）。
- `GeoController::tools` — feed 预览页结构作为 GEO 信息架构的排版参照。

### B.3 不可复用 / 禁止
- `GenericUrlResolver`（硬编码 `/product/{slug}` 旧规则，注释标注已不用于 canonical，待 #86 移除）——**不得**用于 B 检查，否则会复现"产出 404 URL"的历史问题。
- 任何 `count(*)` 不带公开口径的边计数——必须经 PublicIndex/GeoGraphBuilder 口径。

---

## C. Five Checks Feasibility（逐项数据源 / 可实现性 / 是否需新表 / 风险）

> 统一口径：所有检查限定**当前 Site（`SiteContext::currentSite()`）+ 当前 Locale**，只读 `PublicIndex` 公开集；Blank 空站（0 公开实体/内容）一律 N/A / valid empty，不判 Fail。

### Check A — Missing OG
- **真实数据源**：`SeoMetaResolver::resolveEntity/resolveContent/resolveSite` → `SeoResult.ogTitle/ogDescription/ogImage`。
- **关键判定（不人为扩大规则）**：OG 在前台**永远非空输出**（resolver 有完整 fallback：ogTitle→title→站点名→system；ogImage→SeoMeta→entity/内容图→站点 logo）。因此"Missing OG"不能判为"输出里没有 og:"，而应判为**"最终靠 fallback 兜底、无显式 OG 资产"**：
  - ogDescription 最终退化为空/system（内容无 summary 且站点无描述）；
  - ogImage 最终退化为站点 logo（实体/内容无自有图）；
  - 实体/内容无任何 `SeoMeta` 行（即完全未做任何 SEO 覆盖）。
- **可实现性**：是。批量预载后遍历公开实体/内容，统计「显式 OG 完备 / 部分 fallback / 完全无 SEO 覆盖」三档计数 + affected IDs。
- **新表**：无。**风险**：须把"依赖站点 logo 兜底"与"真正缺图"区分清楚，避免把所有实体误报为缺图（站点 logo 是合法兜底，计 Warning 而非 Fail）。

### Check B — Published Product Without Public URL
- **真实数据源**：`PublicUrl::entity(Entity)`（真实前台路由裁决，非看 slug 是否非空）。
- **判定**：枚举当前站 published 产品实体（`PublicIndex::entityQuery()->where('type','product')`），逐个跑 `PublicUrl::entity()`；返回 null 者为"有 slug 但无落地页"。
- **关键口径（防误报）**：`PublicUrl::entity` 对**非核心产品**（非 `Catalog::isCoreProduct`）按设计返回 null——这是预期行为（它们是 GEO 节点但无独立详情页）。故 B 检查应区分：
  - published **核心**产品却 `PublicUrl::entity()` 返回 null → **异常**（应 `/products/{slug}` 却解析失败，可能路由/Catalog 标志异常），Fail + affected slug；
  - published 非核心产品无落地页 → by design，计 N/A 不计 Fail。
- **覆盖 locale/site**：随 `PublicIndex` locale 过滤；跨站由 `BelongsToSite` 隔离。
- **可实现性**：是。**新表**：无。**风险**：必须复用 `Catalog::isCoreProduct` 同一判定，不能另写"核心产品"规则。

### Check C — JSON-LD Emission
- **真实数据源**：`SchemaBuilder`（Builder）+ 前台 layout/head 渲染（Renderer）。
- **判定**：确认三件事——
  1. Builder 存在：Organization/WebSite（站点级）+ `article(Content)` + `entity(Entity)`（→Product/Service/CaseStudy）；
  2. Renderer 调用：对应页型在 head 注入 `<script type="application/ld+json">`（现状由 SeoHead 渲染链消费 `SeoResult` + SchemaBuilder）；
  3. 覆盖 Home/Product/Service/CaseStudy/Content 五类页型。
- **Dashboard 口径（只读、不做全站 HTTP 爬取）**：对公开实体/内容集跑 `SchemaBuilder::entity/article`，统计"应产出 JSON-LD 的页型 → 是否成功产出非 null 节点"的 emitted/missing 计数 + affected route；站点级 Organization/WebSite 恒产出，计 Pass。
- **可实现性**：是（Builder 级聚合）。是否做真实 HTTP 逐页 `ld+json` 探测 → 列为可选增强（内部 sub-request 采样首页/一个产品/一篇文章），**Discovery 阶段不依赖它**。
- **新表**：无。**风险**：不得新增 Schema 类型；missing 的判定是"该公开页型在 SchemaBuilder 下产出 null"，而非"HTML 里没找到 script"。

### Check D — noindex Leakage
- **真实数据源**：`SeoMetaResolver` 的 `SeoResult.noindex/nofollow`，或直接 `SeoMeta` 行（当前 site+locale+content_id/entity_id）。
- **判定**：枚举**已 published + 公开**的内容/实体中，`noindex=true`（或 canonical/robots/locale 明显冲突）者。这些本应被索引却被 noindex = leakage。
- **防误判**：draft/archived/admin/system/private 资源本就不公开，**不纳入**（`PublicIndex` 已天然排除）；只在"既已发布公开、又被显式 noindex"的矛盾态才报警。
- **可实现性**：是。**新表**：无。**风险**：需排除管理员有意对某篇 noindex 的合法场景 → 计 Warning（提示人工确认），不直接 Fail。

### Check E — GEO Edge Count（核心）
- **真实数据源**：`EntityRelation`（Entity→Entity，五型）+ `ContentEntity`（Content→Entity，18R-2c）。
- **判定口径（与 GeoGraphBuilder 一致，不 `count(*)`）**：
  - Entity→Entity 边：`EntityRelation::where('site_id',current)`，仅计**两端均经 `PublicIndex` 公开（published + 当前 locale，默认语言端存在）**的边；按 relation_type 分组计数。
  - Content→Entity 边：`ContentEntity::where('site_id',current)`，仅计 content 端 `PublicIndex::contentQuery()` 公开、entity 端存在者。
  - 输出：两类各自计数 + relation_type 分布 + 无任何边的"孤立公开实体"计数（published 实体既无 incoming 也无 outgoing 边）。
- **不串站/不串语言**：site_id 固定当前站；locale 走 `LocaleContext`；跨站关系由 `EntityRelation::saving` 钩子本就写不进去。
- **可实现性**：是。**新表**：无。**风险**：TD-158（content_entity pivot 无删除清理、孤儿行累积）——Dashboard 计数须用"两端都公开"口径，孤儿 pivot（content 已删）因 content 端不公开被自然排除，不会误计；该 TD 仅登记、不在本能力修。

---

## D. Architecture Decision（架构裁定）

| 项 | 裁定 | 理由 |
| --- | --- | --- |
| 新 Entity 类型 | **No** | A–E 全部基于现有 Entity/Content/SeoMeta/EntityRelation/ContentEntity。 |
| 新表 / migration | **No** | 红线明确禁止 geo_health_records / geo_scores / geo_edges / geo_facts / geo_index / health_snapshots 等任何新表。Dashboard 每次请求实时只读聚合，不落盘快照。 |
| 新 Renderer | **No** | 复用前台 head 渲染链；Dashboard 只在 admin 视图展示卡片。 |
| 新 SEO pipeline | **No** | 直接消费 `SeoMetaResolver`/`SeoResult`，不改写 OG/canonical/noindex 逻辑。 |
| 新 GEO pipeline | **No** | 直接消费 `SchemaBuilder`/`GeoGraphBuilder`/`PublicUrl`，不改写 geo.json/sitemap/llms 产出。 |
| 新 relation system | **No** | E 检查只读现有两张关系表，沿用五型冻结 + ContentEntity。 |
| 0–100 综合伪评分 | **No** | 只给 `Pass / Warning / Fail / Count / Affected / Reason`，不合成分数。 |
| 检测器 vs 修复器 | **检测器** | 只读聚合；**禁止**写库/自动补 OG/自动建关系/自动改 noindex。 |
| 第二事实源 | **No** | 一切数字来自 DB 真值 + 既有 Resolver/Builder。 |

> **Discovery Gate 判定**：架构已理解；5 检查均有真实数据源；无第二系统；无新 Entity/表/migration/Renderer/第二 SEO·GEO pipeline；无业务硬编码（全程不出现任何企业名/产品名/slug/行业/固定 URL）；无未决 P0/P1 阻断。**满足进入人工评审条件。**
> 若后续 Implementation 阶段发现必须新增任一项 → 立即 STOP 上报，不擅自扩范围。

---

## E. UX Proposal（信息架构提案）

### E.1 落点与导航
- 新路由 `admin/geo/health`（名 `admin.geo.health`），挂在现有 `admin.auth + admin.site` 组内，紧邻 `admin.geo.tools`。
- 导航置于「搜索与 AI（SEO/GEO）」组，与「抓取产出 / Sitemap」并列，命名「GEO 健康」。
- Controller 建议复用 `Admin\GeoController` 新增 `health()` 方法（与 feed 预览同组），或新建轻量 `GeoHealthController`——Implementation 再定；视图 `admin/geo/health.blade.php`。

### E.2 页面信息架构
1. **顶栏摘要**：当前站点名 + 当前 locale 标签；五个检查各一张状态卡（Pass/Warning/Fail 徽标 + 关键计数）。
2. **逐检详情列表**（每卡可展开 affected items）：
   - A Missing OG：计数「完全无 SEO 覆盖 / 缺自有 OG 图(靠 logo 兜底) / 缺描述」+ affected 实体/内容名与编辑链接。
   - B Public URL：published 核心产品数 vs 有落地页数；异常项列表（应 200 却无 URL）。
   - C JSON-LD：五类页型（Home/Product/Service/CaseStudy/Article）emitted/missing 计数。
   - D noindex Leakage：published 却 noindex 的矛盾项列表（Warning，提示人工确认）。
   - GEO Edges：Entity→Entity 总数 + 按 relation_type 分布；Content→Entity 总数；孤立公开实体数。
3. **复用既有 ContentGate 结果区**：把 Dashboard 上已有的 `$gateFailures`（内容深度诊断）并入本页或保留在总览，二选一，避免两处重复渲染。
4. **空站态（Blank 一等场景）**：0 公开实体/内容时，五卡显示 `N/A` 与「当前站点尚无公开内容」valid-empty 态，**不显示 Fail/红徽标**。

### E.3 视觉约定（沿用现有 admin）
- 复用 layout 的中性底 + 语义色（绿 Pass / 琥珀 Warning / 红 Fail），与 `VisualConformance18KTest` 锁定的 action/cta 语义色一致；不引入新色板。
- 卡片/表格/badge 内联实现（现有 components 目录为空，不强行引入组件体系）；暗色/locale 跟随 layout 既有机制。
- 所有 affected 项提供到对应 admin 编辑页的链接（实体/内容/SEO 覆盖），但**只读展示、不提供一键修复按钮**（检测器定位）。

### E.4 最小数据契约（仅设计，不建 API）
```jsonc
{
  "site": { "id": 1, "name": "…(当前站)", "locale": "zh" },
  "summary": { "pass": 3, "warning": 1, "fail": 1 },
  "checks": {
    "missing_og":   { "status": "warning", "counts": { "no_seo_meta": 2, "image_fallback_logo": 5, "missing_description": 1 }, "affected": [ {"type":"entity","id":7,"label":"…","reason":"…"} ] },
    "public_url":   { "status": "fail", "counts": { "published_products": 4, "with_public_url": 3, "missing_public_url": 1 }, "affected": [ {"type":"entity","id":9,"label":"…","slug":"…"} ] },
    "jsonld":       { "status": "pass", "counts": { "home": "emitted", "product": "emitted", "service": "emitted", "case_study": "emitted", "article": "emitted" } },
    "noindex":      { "status": "warning", "counts": { "published_but_noindex": 1 }, "affected": [ {"type":"content","id":3,"label":"…"} ] },
    "geo_edges":    { "status": "pass", "counts": { "entity_to_entity": 6, "content_to_entity": 4, "orphan_entities": 1, "by_type": { "produces": 3, "related_to": 3 } } }
  }
}
```

### E.5 多 Site / Locale / 安全 / 性能
- **SiteScope**：全部查询经 `SiteContext::currentSite()` + `BelongsToSite`；**绝不信任 site_id 入参**（防 IDOR）。
- **Locale**：随 `LocaleContext::current()`，edge 计数与 GeoGraphBuilder 一致按当前语言口径。
- **缓存**：只读聚合可借 `SiteCacheKey` 做请求级/短 TTL 缓存，但**不建新缓存系统**。
- **N+1**：强制用 `preload*` / `whereIn` / `groupBy` / aggregate；千级实体不得逐条查关系（GeoGraphBuilder 已示范批量取两端实体）。
- **安全**：路由挂 `admin.auth + admin.site`（EnsureAdmin）、CSRF（无 POST 即可不引入写端点）、Authorization 复用后台既有中间件；**Dashboard 不对外公开**。

---

## F. Test Matrix（Implementation 阶段用例矩阵，仅设计）

| # | 场景 | 准备 | 期望 |
| --- | --- | --- | --- |
| 1 | Blank 空站 | migrate+seed 默认站、0 公开实体/内容 | 五卡 N/A / valid-empty，无 Fail 红徽标；edges=0 不判 Fail |
| 2 | Demo 站正常 | 随 DB 真值的公开实体/内容/关系 | 计数来自真实 DB，不 hardcode 数量/名称/URL；Pass/Warning 合理 |
| 3 | Published 实体有 OG | 实体带 SeoMeta og_title/og_image | A 计为"显式完备"，不报 missing |
| 4 | Missing OG（fallback） | 实体无 SeoMeta、无自有图、站点有 logo | A 报 Warning（靠 logo 兜底），列 affected |
| 5 | Missing OG（真空） | 实体无描述、站点描述空 | A 报缺描述，列 affected |
| 6 | Published 产品有落地页 | 核心产品 published | B=Pass，`PublicUrl::entity()` 非 null |
| 7 | Published 产品无落地页（异常） | 核心产品 published 但 resolver 返回 null | B=Fail + affected slug |
| 8 | 非核心产品无落地页 | 非核心产品 published | B 不报错（by design，N/A） |
| 9 | JSON-LD 五类页型 | 公开 product/service/case_study/article | C 五类 emitted；站点级 Organization/WebSite emitted |
| 10 | JSON-LD missing | 某公开页型 SchemaBuilder 产出 null | C 该页型 missing + affected route |
| 11 | noindex leakage | published 内容但 SeoMeta.noindex=true | D=Warning + affected；draft/archived 不出现 |
| 12 | edges 正常 | EntityRelation + ContentEntity 各若干、两端公开 | E 计数与 GeoGraphBuilder `/geo.json` 边数一致 |
| 13 | edges 隔离 | 跨站关系 / 孤儿 pivot（content 已删） | 他站边不计；孤儿 pivot 因 content 不公开被排除 |
| 14 | orphan entity | published 实体无任何边 | E 列出孤立实体计数（Warning，不 Fail） |
| 15 | MultiSite 隔离 | 第二个站有自己的实体/边 | 切站后 Dashboard 只显示当前站数据，不串站 |
| 16 | Locale 口径 | zh/en 双语言实体 | 边计数随当前 locale，缺译端不计边 |
| 17 | AuthZ | 未登录访问 `admin/geo/health` | 302 跳登录（EnsureAdmin） |
| 18 | IDOR | 入参携带他人 site_id | 被忽略，仍按授权上下文当前站 |
| 19 | N+1 防护 | 千级公开实体 | 单次请求查询数受控（preload/groupBy），无逐条循环查关系 |
| 20 | 只读 | 渲染 Dashboard | 不产生任何写库/SQL 变更（无自动补 OG/建关系/改 noindex） |

---

## G. 关联技术债（仅登记，本能力不擅自关闭）

- **TD-158**：content_entity / content_tag pivot 无删除清理、孤儿行累积。E 检查用"两端公开"口径天然排除孤儿行；登记待后续清理，本能力不修。
- **TD-148**：geo.json `site.description` 为空而 JSON-LD Organization description 正常——与 Check C/A 相关的已知不一致，仅记录，不在本能力改。
- **TD-155**：org 名回退一致性（Schema/Geo 两侧逻辑已对齐），观察项。
- **TD-160**：fresh 安装带 18 个示例实体、缺一键清空——Blank 空站场景依赖"清空示例"能力，Implementation 评估空站测试时留意。
- **TD-162**（P3）：Wizard 产品未入系列分组——保持不动。
- 本 Discovery **不新增** TD 编号；若 Implementation 阶段发现新问题，从 TD-163 起登记。

---

## 结论
- 5 检查均有真实数据源（SeoMetaResolver / PublicUrl / SchemaBuilder / PublicIndex / EntityRelation+ContentEntity / ContentGate），全部 site-scoped、只读。
- 无需新 Entity / 新表 / migration / Renderer / 第二 SEO·GEO pipeline / 第二 relation system；不做伪评分；检测器不修复。
- 无业务硬编码、无未决 P0/P1。
- **Capability 2 满足进入人工评审条件（Discovery PASS）。** 本轮 STOP，不进入 Implementation，不进入 Capability 3。
