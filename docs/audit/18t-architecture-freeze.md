# P-STEP 18T — v1.0 架构冻结文档（Architecture Freeze）

- 阶段：P-STEP 18T（18S 全阶段收口 + v1.0 架构冻结）
- 冻结基线 HEAD：`d1ac1c4`（`docs(18T): 18S consolidation + v1.0 readiness review`）
- rc1 锚点：annotated tag `v1.0.0-rc1` → `965d63c`（未移动、未重打）
- 性质：**docs-only**，不改业务代码、不新增能力、不进 19A、不配 remote、不 push、不 release
- 冻结后规则：仅允许 bug 修复；新增 Entity 类型 / 契约变更须另行评审

---

## 1. 冻结基线与 commit 链

### 1.1 基线

| 项 | 值 |
| --- | --- |
| 分支 | `main` |
| 冻结 HEAD | `d1ac1c4da0497cef8dfeb702a2d8b0f0f971c511` |
| rc1 tag | `v1.0.0-rc1`（annotated）→ `965d63c` |
| rc1 → HEAD 提交数 | 59 commits |
| 工作树 | clean |

### 1.2 18S → 18T 关键 commit 链（冻结范围）

```
d1ac1c4  docs(18T): 18S consolidation + v1.0 readiness review (read-only)   ← 冻结基线
3dd2060  feat(18S): Capability 3 Entity Coverage Check (read-only completeness)
dc3fc2a  docs(18S): Capability 3 Entity Coverage Check Discovery (read-only)
71f7ab8  feat(18S): Capability 2 GEO Health Dashboard (read-only semantic health)
246c82d  docs(18S): Capability 2 GEO Health Dashboard Discovery (read-only)
941ad3c  fix(18S): fallback empty entity slug (TD-161)
c0c9c4c  feat(18S): Capability 1 first-run Setup Wizard (6-step orchestration)
7828750  docs(18S): Implementation Plan Discovery (read-only)
addb051  docs(18S): Product Capability Completion Blueprint (read-only audit)
```

18R 前置能力（Entity Registry / case_study / download_asset / CaseStudy 闭环 / Content Hub Lite）：

```
0dca36e  docs(18R-3): Final Validation Gate - PASS (1194 passed, P0=0)
3e6000e  feat(18R-2b): CaseStudy public closed-loop + DownloadAsset minimal panel
0269426  feat(18R-2a): Entity Capability Registry + case_study/download_asset types
```

18F 国际化基线（中英文前端全链路，checkpoint-18F）在 rc1 之前完成，后续 18G–18T 未改动 Locale 契约。

---

## 2. 七契约面冻结清单

冻结后以下七项为 v1.0 不可变契约；任何变更须经架构评审。

### 契约 1：Entity 8 型集合

| # | 类型 | label | schema | public | searchable | geo | sitemap |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `organization` | 组织 | Organization | true | true | true | true |
| 2 | `product` | 产品 | Product | true | true | true | true |
| 3 | `service` | 服务 | Service | true | true | true | true |
| 4 | `person` | 人物 | Person | true | true | true | true |
| 5 | `location` | 地点 | Place | true | true | true | true |
| 6 | `topic` | 主题 | Thing | true | true | true | true |
| 7 | `case_study` | 客户案例 | CaseStudy（自定义） | true | true | true | true |
| 8 | `download_asset` | 下载资料 | null | **false** | false | true | false |

- 定义源：`config/entities.php`（唯一类型声明）
- `scenario` = `service` 别名（非独立类型），见 §6 **TD-166** P3 观察项
- 新增类型必须：登记 `config/entities.php` + 实现 Registry 能力声明 + 评审 Schema/PublicUrl/公开口径影响

### 契约 2：Registry 能力 / 必备声明契约

- 权威类：`app/Support/Entities/EntityCapabilityRegistry.php`
- 声明维度：`schema` / `public` / `searchable` / `geo` / `sitemap` + `relations`（required/recommended）+ `metadata`（required/recommended）
- 归一化：纯字符串关系/字段 → `required=false`（recommended）；`['type' => 'x', 'required' => true]` → required
- 消费方一律经 Registry（`requiredRelations()` / `requiredMetadataKeys()` / `isPublic()` 等），**禁止散点 `if($type===...)`**
- 已验证：`EntityCoverageService` 仅消费 Registry 派生方法，不拥有静态必备集合（18T Discovery §3.1 grep 证据）

### 契约 3：五型关系语义

| 关系型 | 方向 | 语义 |
| --- | --- | --- |
| `produces` | organization → product | 组织生产产品 |
| `offers` | organization → service / product → download_asset | 组织提供服务 / 产品提供资料 |
| `uses` | service → product | 服务使用产品（有向，不做对称推断） |
| `located_in` | 主体 → location | 主体位于地点 |
| `related_to` | 通用 | 通用关联（含 case_study → product / case_study → organization(role=customer)） |

- 定义源：`app/Models/EntityRelation.php` 常量（TYPE_PRODUCES / TYPE_OFFERS / TYPE_USES / TYPE_LOCATED_IN / TYPE_RELATED_TO）
- 边两端同站，saving 钩子校验；关系语言中性，只在默认语言权威行建边，其他语言经 `translation_group` 映射
- 禁止 EntityRelation ↔ Catalog 双向反写（18A 单向派生决策）

### 契约 4：PublicIndex 公开口径

- 权威类：`app/Support/PublicIndex.php`
- 公开 = **published + 非 noindex + 栏目启用（Content）+ 当前站（SiteScope）+ 当前 locale**
- `contentQuery()` / `entityQuery()` / `indexableEntitySlugs()` 为 sitemap / llms.txt / geo.json / RSS / 站内搜索的唯一入口
- noindex 唯一来源：`SeoMeta`（content_id / entity_id + noindex=true）
- noindex 实体的前台详情页仍可直接访问（200），只是不被 feed 收录

### 契约 5：SEO/GEO 输出契约

| 输出 | 权威类 | 冻结点 |
| --- | --- | --- |
| JSON-LD Schema | `app/Services/Geo/SchemaBuilder.php` | Home/Product/Service/CaseStudy/Article 类型映射；`inLanguage` 随 locale；@id 语言中性 |
| OG / canonical / hreflang | `app/Support/SeoMetaResolver.php` + `SeoHeadComposer` | canonical 语言自指；hreflang 三元（zh-CN / en / x-default→/）；OG 随语言 |
| sitemap.xml | `app/Services/Geo/SitemapBuilder.php`（FeedController） | 按 locale 分文件（`/sitemap.xml` + `/en/sitemap.xml`），各自只列本语言 URL |
| llms.txt | `LlmsBuilder`（FeedController） | locale-aware，`/llms.txt` + `/en/llms.txt` |
| geo.json | `app/Services/Geo/GeoGraphBuilder.php`（FeedController） | 图谱节点 + 边；已知缺口见 §4 / **TD-163**（en 版 facts/实体未本地化） |
| robots.txt | `FeedController::robots` | 根级唯一，同时引用各语言 sitemap |

### 契约 6：Admin 三看板契约

| 看板 | 路由 | 性质 |
| --- | --- | --- |
| Setup Wizard（6 步首跑） | `admin.wizard` / `admin.wizard.save` | 录入编排，不判定健康/覆盖 |
| GEO Health Dashboard（5 检查） | `admin.geo.health` | 运行时语义健康，只读聚合 |
| Entity Coverage Dashboard（齐备率） | `admin.geo.coverage` | 资产齐备度，只读聚合 |

- 路由定义：`routes/admin.php:43-44`（wizard）、`:248-249`（geo.health / geo.coverage）
- 三看板均为**只读**，不发展为修复器，不建第二事实源
- Health（输出对不对）与 Coverage（填得全不全）为正交维度，不互相覆盖、不加权融合

### 契约 7：PublicUrl 落地页裁决

- 权威类：`app/Support/PublicUrl.php`
- 核心路由映射：products（系列/详情共用）、solutions、cases、knowledge、about、factory、cooperation、contact
- 路由注册：`routes/web.php` 中 `$registerFrontend` 闭包双语双注册（en 前缀组先于 zh 默认组）
- 旧 `/scenarios` → `/solutions` 301 兼容重定向（`PublicUrlLocalized()` helper）
- 落地页存在性由站点隔离 Catalog / PublicIndex 在控制器内判定，查无即 404（不输出空壳）

---

## 3. 修正后 Readiness Feature Matrix

枚举：**Implemented Verified** / Product Incomplete / Architecture Ready / Deferred / Missing

| 能力域 | 状态 | 证据 / 说明 |
| --- | --- | --- |
| 多站点 + 站点切换 + SiteScope | Implemented Verified | `admin.sites.switch` / BelongsToSite |
| Entity 类型集合（8 型）+ Registry 能力声明 | Implemented Verified | `config/entities.php` / EntityCapabilityRegistry |
| Entity 知识图谱关系（五型冻结） | Implemented Verified | EntityRelation + saving 钩子 |
| Setup Wizard 首跑（6 步） | Implemented Verified | `c0c9c4c` + 测试 + 浏览器证据 |
| 中文 slug 兜底（TD-161） | Implemented Verified | EntitySlug + focused 测试（`941ad3c`） |
| SEO 覆盖（SeoMetaResolver fallback） | Implemented Verified | SeoMetaController / Resolver |
| Schema/JSON-LD 输出（Home/Product/Service/Case/Article） | Implemented Verified | SchemaBuilder + HTTP 断言 |
| sitemap / llms.txt / robots / feed | Implemented Verified | FeedController + 200 实测（双语） |
| geo.json（中文） | Implemented Verified | GeoGraphBuilder + 中文实体/内容正确 |
| geo.json（英文实体图/内容本地化） | **Architecture Ready** | en 路由存在且 200，但 facts/entities/contents 段未本地化（见 §4） |
| GEO Health Dashboard（5 检查） | Implemented Verified | GeoHealthService + 11 类断言 |
| Entity Coverage Dashboard（齐备率） | Implemented Verified | EntityCoverageService + 11 用例 |
| 多语言前台路由 + 渲染（zh/en） | Implemented Verified | /en 双语路由 200 + 英文渲染（18F + 本轮复测） |
| 多语言 SEO（canonical/hreflang/OG/Schema inLanguage） | Implemented Verified | SeoHeadComposer 统一计算，双语对拍 |
| 多语言 sitemap / llms.txt | Implemented Verified | `/en/sitemap.xml` + `/en/llms.txt` 200，英文内容 |
| 多语言站内搜索（locale 隔离） | Implemented Verified | SearchController 合并 content+entity，按 locale 过滤 |
| Admin UI 国际化 | **Architecture Ready** | Admin 文案以 zh-CN 为主，无英文切换；18F 已将 Admin i18n 列入 v1.1 Planned |
| Content 深度门禁 ContentGate | Implemented Verified（既有） | 复用，未改 |
| Customer CaseStudy /cases 前台路由（zh+en） | **Implemented Verified** | routes/web.php:69-72；CaseController；有案例时 200+CaseStudy ld+json；无案例时统一 404 空态（见 §3.1） |
| 0–100 综合健康分 | Deferred（明确不做） | 避免与 Health/Coverage 维度重叠 |
| recommended（建议项）覆盖率 | Deferred（P3） | scenario alias 统一后再启用 |
| 基础设施 /health | Architecture Ready（既有，非本范围） | Api\HealthController |

### 3.1 /cases 状态修正说明（由 Architecture Ready → Implemented Verified）

**代码证据**：
- 路由：`routes/web.php:69-72`（`cases.index` / `cases.show`），经 `$registerFrontend` 双语双注册
- 控制器：`app/Http/Controllers/Site/CaseController.php`
- 列表块：`resources/views/site/blocks/case_list.blade.php`
- 详情块：`resources/views/site/blocks/case_detail.blade.php`

**实测证据**（MainAgent 在含 published case_study 的库独立起服 8145 端口验证）：
- `GET /cases`（zh，有案例）→ **200**，列表页含案例卡片
- `GET /cases/demo-case` → **200**，HTML 含 4 个 `application/ld+json`、含 `CaseStudy` @type、canonical 指向自身
- `GET /en/cases`（en，无英文案例）→ **404**

**空态 404 是系统统一行为，非 cases 独有缺陷**：
- `CaseController.php:35`：`abort_if($rows->isEmpty(), 404);`，注释明确「与空目录站 products/solutions 行为一致，不输出空壳」
- `ProductController.php:32`：`abort_if(empty(Catalog::company()), 404);`
- `SolutionController.php:28`：`abort_if(empty(Catalog::company()), 404);`
- 结论：所有数据驱动的栏目列表页在「无公开对象」时统一返回 404，不输出空壳页面

**出厂事实**：`migrate + seed`（DatabaseSeeder → DemoSeeder → CatalogSeeder）不含 `case_study` 实体，因此全新安装后 `/cases` 为合法 404 空态。用户通过 Admin 创建并发布案例后自动变为 200。此事实登记为 TD 级观察项（与 TD-160 同类，不阻断 v1.0）。

---

## 4. i18n 实测证据（当前 HEAD d1ac1c4）

### 4.1 矛盾背景

- 18T Discovery（`docs/audit/18t-consolidation-discovery.md:132`）将多语言标为 **Architecture Ready**，备注「UI 文案以 zh 为主」
- 18F Final Audit（`docs/audit/localization-final-audit.md`）验收为 **PASS**：中英文主流程 + SEO/GEO/Schema/Sitemap/Search 完整通过，872 tests / 4655 assertions / 0 failed
- 本轮在当前 HEAD d1ac1c4 真实起服复测，以实测为准统一口径

### 4.2 分维度实测结果

#### a) 前台英文路由与渲染

实测环境：fresh SQLite（migrate + seed 零报错），`php artisan serve --port=8140`，`Invoke-WebRequest` 取证。

| URL | HTTP | html lang | Title / 证据 |
| --- | --- | --- | --- |
| `/en` | 200 | `en` | "Example Manufacturing Co., Ltd." |
| `/en/products` | 200 | `en` | "Products – Example Manufacturing" |
| `/en/products/epoxy-primer-100` | 200 | `en` | "Zinc-Rich Epoxy Primer ZP-100" |
| `/en/products/polyurethane-topcoat-200` | 200 | `en` | "Polyurethane Topcoat PC-200" |
| `/en/solutions` | 200 | `en` | "Solutions: 3 Industry Scenarios" |
| `/en/solutions/equipment-manufacturing/` | 200 | `en` | "Equipment Manufacturing" |
| `/en/solutions/automotive-parts/` | 200 | `en` | "Automotive Parts" |
| `/en/knowledge` | 200 | `en` | "Knowledge Center" |
| `/en/contact` | 200 | `en` | "Contact Us" |
| `/en/cases` | 404* | — | 无英文 published case_study → 统一空态 404（见 §3.1） |

\* 有英文案例时为 200；出厂 seeder 不含案例故为 404 空态。

导航为英文（Products / Solutions / Knowledge Center / About Us / Contact Us），页脚含 "Chinese / EN" 语言切换入口。所有英文页导航、标题、面包屑均为英文，无大段中文残留（Organization 法律名 `示例制造有限公司` 为有意 CJK，18F §7 白名单）。

**判定：前台英文路由与渲染 = 完整（Implemented Verified）**。18F PASS 在此维度仍成立。

#### b) 英文 SEO/GEO 输出

| 项 | 结果 | 证据 |
| --- | --- | --- |
| canonical | ✅ 完整 | `/en` 及子页 canonical 指向含 `/en` 前缀的自身 URL |
| hreflang | ✅ 完整 | 三元齐全：`zh-CN`（中文 URL）、`en`（英文 URL）、`x-default`（指向 `/`） |
| JSON-LD Schema | ✅ 完整 | 英文详情页 `application/ld+json` 含 `"inLanguage": "en"`，内容为英文 |
| `/en/sitemap.xml` | ✅ 完整 | 200，XML 中 loc 均为 `/en/` 前缀 URL，不含中文 URL |
| `/en/llms.txt` | ✅ 完整 | 200，英文实体/内容描述 |
| `/en/geo.json` | ⚠️ **部分缺口** | 200，但 facts 段、entities 段、contents 段大量中文未本地化（如「工业防护涂料」「原料处理车间」），无 locale 字段区分 |
| `/robots.txt` | ✅ 完整 | 200，同时引用 zh 和 en 两套 sitemap |

**geo.json 英文缺口详情**：
- `/en/geo.json` 路由存在且返回 200，图谱结构（节点/边/site/organization）正确
- 但 `facts` 段（事实库数据）、`entities` 段的 name/summary/description、`contents` 段的 title 未按 en locale 过滤/翻译，仍输出中文内容
- 中文 `/geo.json` 完全正常
- 此为 18F 之后未覆盖的输出层缺口，**不影响前台页面渲染、SEO、搜索**，仅影响 AI 直读英文 geo.json 时的理解质量
- 定级：P2/P3，不阻断 v1.0（中文完整、英文前台+SEO+搜索完整）

#### c) 英文搜索

| 请求 | 结果 |
| --- | --- |
| `/en/search?q={英文关键词}` | 200，召回英文内容/实体，不出现中文结果 |
| `/search?q={中文关键词}` | 200，召回中文内容/实体，不出现英文结果 |

实测：`/en/search?q=epoxy` → "Found 2 results for epoxy"（Zinc-Rich Epoxy Primer ZP-100、Polyurethane Topcoat PC-200，英文摘要，0 中文泄漏）；`/search?q=环氧` → 中文结果。同实体按 locale 各自召回译文行，不串语言。

**判定：英文搜索 = 完整（Implemented Verified）**，locale 隔离成立，不串语言。

#### d) Admin UI 语言

- Admin 路由（`/admin`）登录后页面文案为 **zh-CN**
- 无语言切换入口 / 英文 UI 选项
- Admin layout 未接入 Locale 切换
- 18F Final Audit §9 已明确将「Admin i18n」列入 v1.1 Planned，不属 v1.0 范围

**判定：Admin UI = Architecture Ready（基础设施支持 locale，但 Admin 文案仅中文）**。

#### e) 中文路由对照

| URL | HTTP | 结果 |
| --- | --- | --- |
| `/` | 200 | 中文首页 |
| `/products` | 200 | 中文产品列表 |
| `/cases` | 200* | 有案例时列表页；无案例时 404 空态 |

\* 实测库含案例时为 200。

### 4.3 统一口径（18F 与 18T 矛盾消解）

| 维度 | 18F 结论 | 当前 HEAD 实测 | 最终口径 |
| --- | --- | --- | --- |
| 前台英文路由+渲染 | PASS | 仍完整 | **Implemented Verified**（保留 18F PASS，不降级） |
| 英文 SEO（canonical/hreflang/OG/Schema） | PASS | 仍完整 | **Implemented Verified** |
| 英文 sitemap / llms.txt | PASS | 仍完整 | **Implemented Verified** |
| 英文搜索 | PASS | 仍完整 | **Implemented Verified** |
| 英文 geo.json（实体图/内容） | PASS* | 未本地化 | **Architecture Ready**（18F 当时未覆盖 facts/entities 段本地化深度，本轮如实修正） |
| Admin UI 英文 | 未做（v1.1 Planned） | 仅中文 | **Architecture Ready**（与 18F 一致，不属 v1.0 范围） |

\* 18F 验收时 geo.json 两语 PASS 侧重于路由可达 + 结构正确 + 不串站，未对 facts/entities 段内容语言做逐字段断言；本轮深度复测发现英文内容未本地化，如实修正。

**最终 i18n 口径**：v1.0 中英文**前台主流程 + SEO + 搜索完整**（Implemented Verified）；**geo.json 英文实体图/内容未本地化**为已知 P2/P3 缺口（Architecture Ready）；**Admin UI 仅中文**为 v1.1 范围（Architecture Ready）。禁止两个矛盾状态并存。

---

## 5. P0 / P1 产品级缺口

- **P0 = 0**
- **P1 = 0**

说明：以「v1.0 可商业交付的 GEO Native Website OS」标尺，首跑 Wizard、知识图谱、SEO/GEO 输出（中文完整+英文前台/SEO/搜索完整）、运行时健康、资产齐备度、/cases 闭环均已实现并有测试+浏览器/HTTP 证据。剩余项均为 P2/P3/TD（见 §6），不阻塞 v1.0。

---

## 6. TD impact（只登记，不关闭）

| ID | 描述 | 级别 | 状态 | 冻结期处置 |
| --- | --- | --- | --- | --- |
| TD-156 | CaseStudy 五要素（industry/scenario/challenge/solution/result）文本非空未强制发布门禁 | P2 | Registered | 与 Cap3 case_study 必备 metadata 口径一致，复用不重复；v1.1 补完整度检查 |
| TD-158 | content_entity / content_tag pivot 表无删除清理（孤儿行累积） | P2 | Registered | 读路径已过滤（GeoGraphBuilder/SchemaBuilder 跳过 null、Search 重建丢弃），输出正确；仅 DB hygiene，不修复 |
| TD-161 | 中文 slug 空串兜底 | — | **CLOSED** | EntitySlug creating 钩子统一兜底（`941ad3c`） |
| TD-162 | Wizard 产品未入系列分组 | P3 | Registered | 保持不动，不影响首跑录入完整性 |
| **TD-163** | /en/geo.json 的 facts/entities/contents 段未按 en locale 本地化、缺 locale 字段（实测 1821 CJK 字符） | P2 | **本次登记** | 不影响前台/SEO/搜索；v1.1 补 GeoGraphBuilder locale-aware 内容过滤 + `/en/geo.json` CJK=0 防回归 |
| **TD-164** | Admin UI 仅 zh-CN、无语言切换器 | P2 | **本次登记** | v1.1 Admin i18n：接入 locale 切换 + en 语言包；中文企业后台定位当前可接受 |
| **TD-165** | 出厂 seeder 不含 case_study，fresh 安装 /cases 为统一 404 空态 | P3 | **本次登记** | 与 TD-160 同类；空态为系统统一设计行为（CaseController.php:35），用户经 Admin 创建案例后自动 200，不阻断 |
| **TD-166** | case_study.relations 的 scenario 为 service 半虚拟别名 | P3 | **本次登记** | 当前不参与必备覆盖率；未来启用 recommended coverage 前必须统一为真实 `service` 类型或在 Registry 正式定义 alias（含归一化与边匹配），不得长期半虚拟 |

---

## 7. 最终结论

- 七契约面（Entity 8 型 / Registry 声明 / 五型关系 / PublicIndex / SEO-GEO 输出 / Admin 三看板 / PublicUrl）全部冻结，代码如实落地，无第二事实源、无重复规则。
- /cases 前台路由由 Architecture Ready 修正为 **Implemented Verified**（MainAgent 独立实测 200 + CaseStudy ld+json；404 为空栏目统一空态，非回归）。
- i18n 口径统一：中英文前台主流程 + SEO + 搜索 = Implemented Verified（18F PASS 保留）；geo.json 英文实体图未本地化 = Architecture Ready（P2/P3 缺口，如实登记）；Admin UI 仅中文 = Architecture Ready（v1.1 范围）。
- **P0 = 0，P1 = 0。**
- **v1.0 ARCHITECTURE FROZEN。**

按红线：本文档 docs-only、可本地 commit（不 push）；不改业务代码、不新增能力；不进 19A、不配 remote、不 push、不 release、不动 rc1。完成后 STOP，交人工审阅。
