# GEO Website OS — Technical Debt & Release Gate Registry

- **定位**：本文件是 GEO Website OS **唯一**的技术债 / 架构债 / 产品化债 / Release Gate 登记与销项台账。所有阶段（P-STEP / 17x / 18x）的 Gate 对账以本文件为准；其他审计文档（product-uat-final、settings-inventory-17f、runtime-architecture-closure、admin-control-plane-final-acceptance、admin-management-completion-design 等）只作为**来源证据**，不再各自维护债务清单。
- **建立时基线**：HEAD `4dbc95a`（= annotated tag `checkpoint-18A`）；Regression **801 tests / 3948 assertions / 0 failed / 0 skipped**；`v1.0.0-rc1` 冻结于 `965d63c`（HOLD）；无 remote、未 push、未发布。
- **阶段口径修正**：P-STEP 17 中 **17A–17F = 六大管理面**（Site / Entity / EntityRelation / SeoMeta / Theme·Plugin / Settings）；**17G = 六大管理面的系统级 Full Admin UAT**，不是第七个管理面。
- **最后更新**：P-STEP 18G-2b **Gate** 后（annotated tag `checkpoint-18G-2b`；Regression **950 passed / 4873 assertions / 0 failed / 0 skipped**）。Listing 与固定系统页全部迁入 Composition，父 Epic **#116 v1.0 收口**；CLOSED TD-56/57/58/61/66/68/69，**TD-70**（首页 Composition 统一）DEFERRED v1.1。**v1.0 Required 未闭合 = 4**：P0×3 外部工程（TD-01/02/03）+ TD-59 完整 Form Builder（18H）。
- **P-STEP 18G-1（Page Composition / Template System）启动**：18G Discovery 已 ACCEPTED，登记父 Epic **#116** 与 **TD-53..TD-59**（Block / Template / Page Registry + Detail·Listing Composition + Composition Admin + Form Block）；18G-1 建立三层并完成 Landing 闭环，18G-2 迁移系统页。
- **P-STEP 18G-2 Discovery ACCEPTED / 18G-2a AUTHORIZED**：用户拍板 **路线 A（统一 Render Context 管线）**——Page / Entity / Listing 三类 Context 共用同一 Template·Block·Resolver·Schema·Url·Cache，渲染/SEO/GEO/Schema/URL/Cache 禁止再有多套；**Detail override 方案 ii**（`pages.entity_id` nullable，Entity 直驱固定槽 + Page-level 可组合槽覆盖，**绝不复制业务数据进 Page**）；**两次 Gate**（18G-2a Detail → STOP；18G-2b Listing+系统页 → STOP）；**固定系统页全部 Page 化**（contact / products·solutions·knowledge 总览 / about profile·history·culture / factory / cooperation，事实仍来自 Site·Setting·Entity）。登记 **TD-61（P0，系统页 SEO 双轨）/ TD-62（P1，Detail 资源渲染器）/ TD-63（P2，grid current·related 上下文）/ TD-64（P2，site-level SEO page_id 边界）**。
- **P-STEP 18G-2a Gate ACCEPTED / PASS**：Product/Service Detail 已迁入统一 Render Context 管线（EntityRenderContext + CompositionRenderer + Template·Block），entity-level SeoMeta（title/desc/canonical/OG/noindex）前台真实消费；**TD-62/63/64 CLOSED**，新发现并修复 **TD-65**（indexableEntitySlugs 缺 locale）CLOSED、登记 **TD-66**（SeoMeta 编辑不跟随 locale，en SEO 覆盖无法管理，ACTIVE，待裁定修复阶段）；新发现并修复 **TD-67**（外观切换按钮 aria-label/title 键 mode_aria_toggle 缺失，屏幕阅读器显示原始键名）CLOSED；TD-61/TD-56 在 Detail 部分收口（PARTIAL），Listing 与其余系统页待 18G-2b。回归 **919 / 4798 / 0 / 0**。
- **P-STEP 18H Discovery 完成（STOP，待裁定）**：一次性盘点 Search / Media / Forms·Inquiry / Analytics / Audit·Revision / Cache / Performance / SEO·GEO；用户已锁定 **Search V1 = 本地 FTS5（已实测 sqlite 3.53.4 可用）+ Engine 接口预留**、**Form V1 = 结构化字段（不做自由排版 / 多步）**。登记 **TD-71（SearchEngine 契约/接口）/ TD-72（FTS5 默认引擎）/ TD-73（Analytics/转化集成）/ TD-74（AuditLog 覆盖缺口）**（V1 Required），**TD-75（Revision 仅 Content）/ TD-76（logo 双轨·无 srcset）** DEFERRED v1.1；TD-59 验收标准细化。**v1.0 Required 未闭合 4→8**（外部 P0×3 + 18H 代码层 5：TD-59/71/72/73/74）。产出 `operations-product-discovery-18h.md`、`operations-product-architecture-18h.md`；Discovery 未改产品代码。
- **P-STEP 18H-1 Gate ACCEPTED / PASS**：Search Productization 收口——Search Contract v1 + SearchEngineInterface、search_documents + search_index（FTS5）、Builder/Sync/双引擎、search:reindex 接线；**CLOSED TD-71 / TD-72**，测试发现并修复 **TD-78 / TD-80 / TD-81**；新增 **TD-77**（Form Submission，18H-2）、**TD-79**（Page/Landing 索引，v1.1）。**v1.0 Required 未闭合 = 7**。
- **P-STEP 18H-2 Gate ACCEPTED / PASS**：Form / Submission / Inquiry Productization 收口——forms + form_fields（每 locale 行）+ form_submissions（payload JSON 全量、完整事实源）+ InquiryProjector 确定性投影 + 可选通知（默认关、事务外发、失败不丢数据）；管理员零代码创建并发布与 contact 字段完全不同的 Download 表单（form id=2，5 字段 × zh/en）+ Landing（page 19 / block 41），李四提交 sub#4 实测。**CLOSED TD-59 / TD-77**，测试发现并修复 **TD-82 / TD-83 / TD-84 / TD-85 / TD-86**；新登记 **TD-87**（Core migration 000011 首页 builder 默认 items 含制造业静态字符串、运行时不可达）DEFERRED v1.1（随 TD-70）。**v1.0 Required 未闭合 = 5**（P0×3 外部 + TD-73/TD-74 留 18H-3）。
- **P-STEP 18H-3 Gate ACCEPTED / PASS**：Analytics + Audit / Revision Operations Closure 收口——独立 Site-scoped **analytics 设置组**（GA4/GTM/Meta，默认全关）+ **GeoAnalytics 事件层**（page_view/cta_click/form_submit/download/contact，统一事件层不知 provider API）+ **Basic Consent Mode**（未同意第三方完全不加载、拒绝零第三方请求、gwos-consent 记忆）+ **受控动态 CSP**（仅启用 provider 进 script/connect/frame，无 unsafe-inline / strict-dynamic）+ **HeadCodeSanitizer**（seo_head_code 仅 meta/link 白名单、script/javascript: 剔除并标 invalid）；Audit 侧 **AuditSnapshot**（白名单 / 脱敏 / 归一化）+ **recordChange**，Entity/SeoMeta/Page/Block/Form/Theme/Plugin 全部补审计、detail.changes 带 before/after、敏感字段 [REDACTED]、AuditLog≠Revision（TD-75 不重开）。登记并 CLOSED **TD-88..TD-92**，真实后台 / 提交发现并修复 **TD-93（analytics 路由）/ TD-94（settings 审计 before/after）/ TD-95（form_submit 标识）**；**CLOSED TD-73 / TD-74**。新增 AnalyticsConsentCsp18H3Test（15）+ AuditCoverage18H3Test（12）。**v1.0 Required 未闭合 = 3**（仅 P0 外部发布工程 TD-01/02/03）。
- **P-STEP 18I Gate ACCEPTED / PASS**：蓝图 18 项对拍 17 MATCH / 1 PARTIAL（RBAC 增强 TD-14 DEFERRED v1.1）/ 0 MISSING；陌生品牌 Aurora Living 零代码建站成立；**CLOSED TD-70（首页 Composition 拉回 v1.0）/ TD-99 / TD-100 / TD-101 / TD-102**。回归 **993 / 5112 / 0 / 0**（commit `a3ca4ad` / tag `checkpoint-18I`）。
- **P-STEP 18J Final Product Acceptance（进行中）**：Test Inventory Delta 对账完成——1016→993（**-23**）= 删旧首页装修器测试 29（随 TD-70 拆除）+ 新增 HomeComposition18ITest 6 + 修改净 0，意图由 Composition 测试群继承，**测试面未悄然缩小**；两处能力收敛登记 **TD-103（Hero 多 slide 轮播）/ TD-104（item 自定义配图）** DEFERRED v1.1。
- **P-STEP 18J-3 最终全链路复核（CLOSED）**：真实 HTTP 对拍发现并修复 5 项 URL / 缓存 / 约定文件缺陷，登记并 CLOSED **TD-105（`home()` en 尾斜杠，canonical 指向会 301 的 /en/）/ TD-106（站点级 @id 引用断裂：内页 isPartOf、geo org @id·same_as 偏离全局锚点 base/#website·#organization）/ TD-107（en-only / 默认语言非 zh 站点根路径 / 不按 `site_default_locale` 渲染、误 404）/ TD-108（CLI `page-cache:clear` 无站点上下文只清默认站；locale Setting 失效经核实已由 AppServiceProvider 统一 saved→flush 覆盖，无需新增挂接）/ TD-109（en-only 站根 robots.txt·sitemap.xml·llms.txt 默认位置 404、无 robots 可用；robots 漏声明多语 sitemap）**。实测 en-only 根约定文件 200 且内容为英文、双语 robots 同时声明两套 sitemap、zh-only 站 /en/* 仍 404，直接影响 SEO / GEO 正确性的缺口全部收口。

---

## 1. 字段定义

| 字段 | 含义 |
| --- | --- |
| **ID** | 债务编号。可验收子项用 `TD-NN`；跨多子项的父级 Epic 用项目 issue 号 `#NNN`。编号一经分配不复用、不重排。 |
| **Priority** | P0 Release 工程 / P1 架构与数据一致性 / P2 通用化与产品化 / P3 后台与体验 / P4 观察与测试限制。 |
| **Title** | 一句话问题陈述。 |
| **Source** | 首次发现/登记的阶段与审计文档（可追溯证据）。 |
| **Current Status** | 见 §2 状态机。 |
| **Acceptance Criteria** | 可判定销项的具体、可验证条件（测试 / HTTP / 数据 / 文件证据）。 |
| **Blocks v1.0.0?** | `YES` = 公开发布前必须闭合；`DECISION` = 发布前必须做出并记录裁定（不一定补实现）；`NO` = 规划 v1.1+。当前为**建议基线，最终以 Release Gate 裁决为准**。 |
| **Parent / Related** | 父级 Epic 与关联项，体现父子结构，避免重复登记。 |

---

## 2. 状态机（销项走流程，不允许直接删条目）

```
ACTIVE  →  FIXED  →  TESTED  →  ACCEPTED  →  CLOSED
   │
   ├─ PARTIAL      （父项的部分子项已闭合，剩余子项仍 ACTIVE）
   ├─ DEFERRED     （明确裁定到 v1.1+，记录理由与验收条件）
   ├─ WONTFIX      （明确不做，记录理由）
   └─ NON-DEBT     （经核实不属于债，移入 §6 白名单并说明）
```

- **不得为了"看起来债务少"而合并或删除条目**。大项（如 TD-10）可拆多个子项，但保留母项编号。
- 每次阶段 Gate 必须更新本台账：变更 Status、补证据（commit/tag/测试数/HTTP），并在 §8 Changelog 记录。
- CLOSED 项移入 §5 归档区保留销项历史，不从台账抹除。

---

## 3. 父级 Epic（跨子项的架构 / 产品化事项）

| Epic | 范围 | Current Status | Blocks v1.0.0? |
| --- | --- | --- | --- |
| **#86** Public Render Contract / Feed 泄漏 | 任何进入 Sitemap/GEO/LLMS/RSS/Search 的公开资源必须 Published + 当前 Site 可见 + Canonical 有效 + 前台 HTTP 200 | **CLOSED by 17G**（PublicIndex/PublicUrl 七大输出改派，90 URL×3 站全 200） | — |
| **#114** Catalog Read Model / Entity Public Model | 后台生产模型 → Catalog 读模型 → 前台 / Schema / GEO / Sitemap / Search 的权威链路与公开 URL 体系 | **CLOSED for v1.0 by 18A+18C**：关系权威源 TD-04 CLOSED（18A）；TD-05 URL 体系冻结、TD-09 @id/PublicUrl 统一 CLOSED（18C）；TD-06 `/article/` 收敛书面 DEFERRED v1.1（301 桥接已锁定） | —（v1.0 收口；TD-06 转 v1.1） |
| **#115** Default Template Neutralization | 出厂为行业中性空站，系统默认层与 Example Demo（工业材料）彻底分离 | **CLOSED by 18B**（TD-10/11/12/13 + TD-25/26；Blank System ≠ Demo Site 两态 HTTP 对拍） | — |
| **#116** Page Composition / Template System | 把“仅首页可装修”升级为“任意页面可组合”：Block Registry + Template Registry + Page 模型 + Landing 闭环 + Page Composition Manager；18G-2 迁移系统页 | **CLOSED for v1.0 by 18G-1/2a/2b**：三层 + Landing + Detail·Listing·系统页 Composition 全部收口；首页 Composition 统一 TD-70 DEFERRED v1.1；完整 Form Builder 转 18H | —（v1.0 收口；TD-70 转 v1.1；Form Builder 18H） |

---

## 4. 主台账（ACTIVE / PARTIAL / DEFERRED）

### P0 — Release 硬门槛（发布工程 + 代码层阻塞）

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
| **TD-01** | GitHub Actions 云端 Runner 真实首跑未执行 | P13/P16；CI 配置本地已就绪（965d63c） | ACTIVE | Push 后云端 PHP 8.4 流水线真实全绿：composer validate/audit/check-platform-reqs → install → geo:install → 全量测试 → HTTP smoke → artifact → SHA-256 | **YES** | TD-03 |
| **TD-02** | Release Candidate 须基于最终 HEAD 重建 | P15 后历史重写致旧 hash 失效；当前 rc1=965d63c 已落后 | ACTIVE（HOLD） | 代码冻结后：新 RC commit → annotated tag → CI GITHUB_SHA 生成 release-manifest → ZIP → SHA-256，provenance 链清晰且 tag target 一致 | **YES** | TD-01, TD-03 |
| **TD-03** | Private push → 观察 → 转 Public / v1.0.0 未授权、未执行 | P15/P16；空 Private 仓已建（jackyduhai/geo-website-os），未配 remote | ACTIVE（等待外部授权） | 新仓作为全新 source of truth（不与旧远程合并）；先 Private 全验证（Fresh Clone/Secret Scan/CI/Artifact）通过，再由用户决定转 Public | **YES** | TD-01, TD-02 |
| **TD-61** | 系统页 SEO 双轨：Product/Service Detail、Listing、单页控制器手工拼 `$seo`、旁路 SeoMetaResolver；17D 为 Entity/系统页设置的 SeoMeta 前台不消费 | 18G-2 Discovery；18G-2a/2b | **CLOSED by 18G-2b**（见 §5） | Detail=resolveEntity、文章=resolveContent、Page=resolvePage、Listing=resolveListing，控制器手工 SEO 数组清零；后台 Entity/系统页 SeoMeta（title/desc/canonical/OG/noindex）真实反映前台 | — | #116, TD-64 |

### P1 — 架构与数据一致性

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
| **TD-04** | 关系读模型双轨：前台 Catalog 读 `Entity.metadata` slug 数组，而非权威边表 EntityRelation | 17C 发现；17G §5 登记 | **CLOSED by 18A**（见 §5） | — | — | #114 |
| **TD-05** | Entity 六类型公开 URL 体系未冻结 | 17G §5；现仅 core 产品 `/products`、场景服务 `/solutions` 有页，org/person/location/topic/非 core 产品 url=null | **CLOSED by 18C（DECISION 冻结）**（见 §5） | — | — | #114, TD-09 |
| **TD-06** | Content 路径双轨：无 `/article/`，旧路径靠 301 桥接 | 17G §5 | **DEFERRED v1.1（18C 书面裁定）** | 维持 `Content::path()`=`/{栏目路径}/{slug}`，不引入 /article/；301 桥接测试锁定；schemaType 仅 page→WebPage/default→Article | NO（v1.1 收敛） | #114 |
| **TD-07** | Organization 双载体：organization Entity 与 `sites.metadata.organization` 并存，SchemaBuilder 读后者 | P14 遗留；18A 附录 G | **CLOSED by 18C**（见 §5） | — | — | #114, TD-10 |
| **TD-08** | PageCache 整页缓存失效模型不完整 | 18A 实测发现并部分修复 | **CLOSED by 18A+18C**（08a/08b 均闭合，见 §5） | — | — | — |
| └ TD-08a | EntityRelation 写入失效整页缓存 | 18A | **CLOSED by 18A**（模型 saved/deleted → PageCache::flush） | — | — | TD-08 |
| └ TD-08b | Entity / Site / SeoMeta / Content 写入后整页 HTML 失效未挂接 | 18A 附录 G | **CLOSED by 18C**（见 §5） | — | — | TD-08 |
| **TD-09** | Product 自身 `@id` 仍用 `url()` helper；Catalog 早期路径缺 `Schema::hasTable` 守卫 | 17G（manufacturer.@id 已改 PublicUrl，product 自身未改） | **CLOSED by 18C（#143）**（见 §5） | — | — | #114, TD-05 |
| **TD-53** | 无通用 Block Registry：block 类型 / 字段 / 渲染器散落，首页 16 section 与通用 block 未统一 | 18G Discovery（page-composition-discovery-18g） | **CLOSED by 18G-1**（见 §5） | BlockRegistry + BlockType/Definition（type/label/category/fields schema/data source/renderer view/allowed slots/per-locale/cacheable）；16 Core Block 全部注册并有渲染器；`if type==` 不散落 Controller/Blade；测试覆盖 | **YES** | #116 |
| **TD-54** | 无 Template 模型 / 注册表 / 选择器，"模板"即 Blade、由 Controller 硬编码 | 18G Discovery | **CLOSED by 18G-1**（见 §5） | TemplateRegistry + TemplateDefinition + Slot（继承 base）；Base→Home/Listing/Detail(Article·Product·Service)/Contact/Landing；槽位声明允许 block、不存内容；模板可在 Admin 选择 | **YES** | #116, TD-53 |
| **TD-55** | 无 Page 模型、Landing Page 完全缺失（“单页”只能 Category(type=page)+Content 正文驱动、非组合） | 18G Discovery | **CLOSED by 18G-1**（见 §5） | pages 表 + Page 模型（site/template/slug/status/locale/translation_group，**不存业务事实**）；Landing 全流程：新建→选模板→加 block→排序/隐藏→preview→publish→前台 200，不改 PHP/Blade/JS/CSS；draft 404 | **YES** | #116, TD-53, TD-54 |
| **TD-62** | Detail 资源渲染器缺失：detail header / spec table / process steps / scene chips / adjacent 等 Entity 结构化只读呈现无注册 renderer，16 Core Block 无法表达 Detail 全貌 | 18G-2 Discovery | **CLOSED by 18G-2a**（见 §5） | 新增 system/managed 通用 renderer（EntityHero / EntitySummary·Attributes·Specifications·Features·Steps·Relations / RelatedEntities / EntityCTA，**不按行业建块**），由当前 Entity 直驱、无数据不渲染；仅 Detail Template 固定槽调用、不进“自由添加”列表；不复制业务数据 | **YES** | #116, TD-53 |
| **TD-71** | SearchEngine 契约 / 接口缺失：无 SearchEngineInterface、无统一 SearchQuery/SearchFilter，查询/匹配/分页全在 SearchController | 18H Discovery；18H-1 | **CLOSED by 18H-1**（见 §5） | Search Contract（SearchQuery/SearchResult/SearchFilter/Ranking）+ SearchEngineInterface + 容器绑定；控制器只构造 query 调接口；未来换引擎（FTS5/Meili/ES）上层不变 | — | TD-72 |
| **TD-72** | FTS5 默认引擎缺失：现 LIKE 子串 + LengthAwarePaginator 内存分页（get 全量再 slice），无分词/相关性 ranking/type ranking/DB 分页/highlight | 18H Discovery；18H-1；FTS5 已实测可用（sqlite 3.53.4） | **CLOSED by 18H-1**（见 §5） | 统一 FTS5 虚拟表 search_index（Content+Entity，site/locale/kind 过滤列）；事件同步 + `search:reindex`；bm25 ranking + SQL LIMIT/OFFSET DB 分页 + snippet 高亮；FTS5 不可用降级 LIKE 引擎 | — | TD-71 |
| **TD-77** | Form 提交缺 Submission 层：Form→Inquiry 直连、Inquiry 固定列无 payload，下载 / 预约 / 报名 / Demo 等非传统询盘被固定数据结构卡死 | 用户 18H 裁定（TD-59 细化）；18H-2 | **CLOSED by 18H-2**（见 §5） | FormSubmission（form_id/site_id/locale/payload JSON 全量/created_at/source/attribution/status）为完整事实源，InquiryProjector 确定性投影 Inquiry；DB 事务 commit 后才发通知，**通知失败只 warning、不丢 Submission（test_notification_failure_keeps_submission_and_inquiry 锁定）**；李四 Download sub#4 实测 payload 全字段、inq#4 投影正确 | — | TD-59 |
| **TD-78** | flushDirty(siteId) 不切 SiteContext 直接 rebuildSite：查询目标 siteId 与当前 SiteContext 不同时（CLI / SubRequest / 进程内跨站查询），在他站上下文投影、把他站数据重复写入本站，触发 search_documents 唯一约束冲突 | 18H-1 测试（cross_site）发现 | **CLOSED by 18H-1**（见 §5） | flushDirty 内 Site::find + SiteContext::withSite 切到目标站再 rebuildSite；cross_site 隔离测试锁定 | — | TD-71 |
| **TD-80** | SearchIndexSync 增量 upsert 在 CatalogSeeder 中途经 PublicUrl→Catalog 预热并缓存「不含 Service 的半成品」memo：第一个 Product saved 时 Catalog 首次构建（此刻库中尚无 Service）并 memo scenes=[]，后续 Service saved 经 PublicUrl 读空 scenes → 不被索引，测试 scenes() 直接得空（全量回归失败①②③⑥） | 18H-1 全量回归 | **CLOSED by 18H-1**（见 §5） | SearchIndexSync 的 Entity saved/deleted 闭包在 upsert/forget 之后 `Catalog::flush()`，EntityRelation saved/deleted 同样 flush；每次搜索派生（含 catalog 读取）后清 memo，下一次 saved 重建 dataset 时当前行已入库可定位；CatalogRuntimeIsolation+ExampleDataset+NarrativeSlot 组合 30 passed 锁定 | —（派生读模型健壮性） | TD-71 |
| **TD-81** | 跨测试类 SiteContext 静态污染：CatalogRuntimeIsolationTest 最后一个方法 `setSite(site-b,id=2)` 且该类无 tearDown，RefreshDatabase 重建内存库（site id 重置为 1）后静态仍持旧 Site 对象，BelongsToSite creating 取 `currentSiteId()=2` → facts 插入 FK failed（三类手动混跑 24 failed；单跑 ExampleDataset 14 passed 证明系跨类污染） | 18H-1 全量回归 | **CLOSED by 18H-1**（见 §5） | `tests/TestCase` 加 tearDown 统一 `RequestScopedState::flushAll()` + `SiteContext::clear()`，每个用例结束复位全部请求级 static memo 与站点上下文；跨类组合不再 FK、不串站 | —（测试隔离基建） | — |
| **TD-82** | FormSubmission payload 双重编码：service 先 json_encode 字符串、模型 `payload` array cast 又二次编码，落库 payload 变成被转义的 JSON 字符串，前台/投影读取异常 | 18H-2 联调 | **CLOSED by 18H-2**（见 §5） | service 直接传 array payload、由模型 cast 统一单次 json_encode；断言 payloadArray() 还原为原始关联数组、字段不丢 | — | TD-77 |
| **TD-83** | 后台 `admin.forms.index` 路由缺失：layout / submissions 视图引用 `route('admin.forms.index')`，打开表单管理即 500（forms 路由块漏注册 index） | 18H-2 后台访问 | **CLOSED by 18H-2**（见 §5） | routes/admin.php forms 块最前补 `GET forms` → forms.index；表单管理列表（withCount fields/submissions、paginate）正常打开 | — | TD-59 |
| **TD-84** | 无 validation.php 语言包：多选 / 非法值校验失败回退原始键 `validation.in`，无本地化提示、表单错误不可读 | 18H-2 拦截测试 | **CLOSED by 18H-2**（见 §5） | 新建 lang/{en,zh-CN}/validation.php（常用规则 + custom + attributes）；字段级错误输出本地化文案、不暴露原始键 | — | TD-15 |
| **TD-85** | 英文页表单 action 缺 /en：dynamic_form 写死 `route('forms.submit',slug)` 总命中 zh 路由，英文 POST 落中文路由、locale 错存、提示与查询全中文 | 18H-2 真实浏览器（John Smith 首提） | **CLOSED by 18H-2**（见 §5） | routes/web.php 新增全局 `localized_route()`（按 LocaleContext 前缀解析 forms.submit / forms.submit.en），dynamic_form 改派；Jane Doe sub#3 实测 action 含 /en、locale=en、英文成功提示 | — | TD-51, TD-77 |
| **TD-86** | block 内容编辑无法保存：block_form.blade.php 缺 `@method('PUT')`，以 POST 提交到仅注册 PUT 的 updateBlock → 405，所有 page block 内容更新断裂（测试直接 put() 绕过表单故漏检） | 18H-2 真实浏览器（block 41 配 form_id） | **CLOSED by 18H-2**（见 §5） | block_form 加 `@method('PUT')`；PageComposition18GTest 补防回归（GET editBlock 断言含 `name="_method" value="PUT"` 与 updateBlock action）；李四 Download 前台渲染正确、提交落 sub#4 | — | TD-58 |
| **TD-87** | Core migration 000011（seed_home_builder_blocks）默认 items 含制造业静态字符串（配方定制 / OEM 代工 / 打样到量产 / 车间），与其 docblock「Core 仅通用缺省文案」声明不符 | 18H-2 静态复扫 | **DEFERRED v1.1** | 运行时不可达（blank 首页实测无制造业词、demo 被 StructureSeeder content=null 覆盖走 config/facts）；随 TD-70 首页旧装修器迁 Composition 时一并中性化 / 退场。重启条件：v1.1 首页 Composition 迁移 | NO（v1.1；运行时不可达） | TD-70 |

| **TD-88** | 无统一 Analytics 事件层：前台无 window.GeoAnalytics、无事件契约，第三方若接入只能在 Blade 直写 gtag/fbq | 18H-3 | **CLOSED by 18H-3** | GeoAnalytics 事件契约（page_view/cta_click/form_submit/download/contact）+ 队列回放，统一事件层不知 provider API；AnalyticsConsentCsp18H3Test 锁定 | — | TD-73 |
| **TD-89** | 无 Cookie Consent 门控：未同意即加载第三方、无 banner、拒绝后仍有第三方请求 / 标识 | 18H-3 | **CLOSED by 18H-3** | Basic Consent Mode：未同意第三方完全不加载、banner 不依赖 analytics、拒绝零第三方请求；consent 记忆 gwos-consent；AnalyticsConsentCsp 测试锁定 | — | TD-73 |
| **TD-90** | CSP 静态拦截 + seo_head_code raw 输出 script：provider 外链全被 CSP 拦、head code {!! !!} 可注入 script（XSS） | 18H-3 | **CLOSED by 18H-3** | 受控动态 CSP（仅启用 provider 进 script/connect/frame，无 unsafe-inline / strict-dynamic）+ HeadCodeSanitizer 仅 meta/link 白名单、script/javascript: 剔除并标 invalid | — | TD-73 |
| **TD-91** | AuditLog 资源覆盖不全（Entity/SeoMeta/Page/Block/Form/Theme/Plugin 无审计点） | 18H-3 | **CLOSED by 18H-3** | 上述资源关键操作全部 AuditLog::record/recordChange；AuditCoverage18H3Test 12 测试锁定 | — | TD-74 |
| **TD-92** | 审计无 before/after：record 仅 summary、detail 空，无法看到字段变化 | 18H-3 | **CLOSED by 18H-3** | recordChange + AuditSnapshot 白名单 / 脱敏 / 归一化，detail.changes 带 before/after；敏感字段 [REDACTED]、无变化不写 | — | TD-74 |
| **TD-93** | settings analytics 路由缺失：admin settings 分组 where 白名单遗漏 analytics，/admin/settings/analytics 404 | 18H-3 真实后台 | **CLOSED by 18H-3** | routes/admin.php settings 分组 where 补 analytics；后台 analytics 页正常渲染、可保存 | — | — |
| **TD-94** | SettingController update 审计仅 summary、缺 before/after（旧 record 未走 snapshot） | 18H-3 实测 | **CLOSED by 18H-3** | update 改 applySetting 收集 before/after + AuditSnapshot changes，detail.changes 真实记录；AuditCoverage settings 测试锁定 | — | TD-92 |
| **TD-95** | form_submit 事件 payload 空：动态表单 form 缺 data-form-id/data-form-slug，analytics 取不到表单标识 | 18H-3 真实提交 | **CLOSED by 18H-3** | dynamic_form 加 data-form-id/slug、analytics form_submit 带 form_id/form_slug（不含字段值）；实测 params={"form_id":"1","form_slug":"contact"} | — | TD-88 |
| **TD-105** | `PublicUrl::home()` 对非默认语言无条件加尾斜杠：en 首页 canonical / WebSite.url / 导航·面包屑·logo·llms 首页链接输出 /en/，而 /en/ 经 CanonicalizeSlash 301→/en（home 路由未声明 `_slash=1`），canonical 指向会重定向的 URL、与 sitemap 首页 loc（/en）矛盾 | 18J-3 真实 HTTP 对拍 | **CLOSED by 18J-3**（见 §5） | home() 区分：默认语言根 base/、前缀语言 base/{locale}（无尾斜杠，en=/en）；en 首页 canonical / WebSite.url / 首页链接 = /en，与 /en 200、/en/ 301、sitemap loc 一致；防回归断言 en canonical 无尾斜杠 | **YES** | #114, TD-68 |
| **TD-106** | 站点级 @id 引用断裂：SchemaBuilder 内页 `webPage.isPartOf` 用 `home().#website`、GeoGraphBuilder `site.organization.@id`·主体节点 `same_as` 用 `home().#organization`，en 下带 /en 前缀（/en/#website·/en/#organization），但 WebSite/Organization 实际全局 @id = base/#website·base/#organization（不带 locale），引用指向图谱中不存在的节点，违背 geo 与 schema「同一 @id」契约 | 18J-3 真实 JSON-LD / geo.json 取证 | **CLOSED by 18J-3**（见 §5） | 站点级锚点统一为全局 base/#website·base/#organization（新增 PublicUrl 锚点方法）：webPage isPartOf、geo org @id·same_as 全部引用全局锚点；en ItemPage.isPartOf = base/#website 与 WebSite @id 匹配、geo org @id 与 schema Organization @id 一致；防回归断言 | **YES** | #114, TD-07, TD-69 |
| **TD-107** | en-only / 默认语言非 zh 站点根路径 / 无法渲染默认语言：静态路由 `/` 固定归属 zh 组（middleware locale:zh-CN），SetLocale 根路径无参时硬取 LocaleRegistry::default()（zh-CN）、不读 `site_default_locale`，默认 en 站点访问 / 被判定 zh-CN 不在 supported → 404，根路径无法呈现英文首页 | 18J-3 Site B（default=en）实测 / 404 | **CLOSED by 18J-3**（见 §5） | 根路径 / 的 locale 解析改为按 `site_default_locale`（SetLocale 根路径读站点默认语言、不硬取 zh-CN）；默认 en 站点 / 渲染英文首页 200；zh 默认站点 / 仍中文；路由/渲染链支持根路径按站点默认语言；防回归断言 | **YES** | #114, TD-42 |
| **TD-108** | 多站 PageCache 清理与 locale 变更失效不完整：CLI `page-cache:clear` 无 HTTP、SiteContext 为空→回退默认站 id=1，只 flush Site 1、非默认站版本不 +1（旧语言 HTML 持续 HIT）；`site_supported_locales/site_default_locale` Setting 变更不触发 PageCache flush，改语言后旧语言缓存继续 served | 18J-3 Site B 旧 zh-CN 缓存持续命中实测 | **CLOSED by 18J-3**（见 §5） | CLI page-cache:clear 遍历所有站点逐站 flush（或显式 --site）；locale 相关 Setting 变更经 AppServiceProvider 统一 saved→flush 覆盖（核实无需新增挂接）；改语言/清缓存后各站按当前语言渲染、无旧语言缓存；防回归断言 CLI 清理覆盖非默认站、locale 变更失效 | **YES** | TD-08, #114 |
| **TD-109** | 根级约定文件在非默认语言（en-only）站点默认位置不可达：`/robots.txt` 仅在 zh-CN 组注册、整组过 locale:zh-CN，en-only 站根 /robots.txt 被判 zh-CN 不支持→404、/en/robots.txt 因 en 组未注册也 404（无任何 robots 可用）；根 /sitemap.xml、/llms.txt 归属 zh 组，en-only 站默认位置同样 404（仅 /en/* 可达），爬虫 / AI 在约定根位置找不到发现文件；robots 仅声明单个 sitemap、双语站漏 /en/sitemap.xml | 18J-3 Site B 实测根 robots/sitemap/llms 全 404 | **CLOSED by 18J-3**（见 §5） | robots.txt 移至 locale 组外注册（语言无关、任何站点语言配置下根 /robots.txt 200）并列出该站各启用语言 sitemap；SetLocale 根级默认资源（站点根 /、sitemap.xml、llms.txt）在路由默认语言 zh-CN 不被站点提供时按 site_default_locale 渲染（显式 /en/* 不支持仍 404）；双语 robots 同时声明根 + /en/sitemap.xml；实测 en-only 根 robots/sitemap/llms 200 且内容为英文、zh-only 站 /en/* 仍 404 | **YES** | TD-107, TD-108 |

### P2 — 通用化与产品化（#115）

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
> **#115 全部子项（TD-10 / TD-11 / TD-12 / TD-13）已由 P-STEP 18B 销项 CLOSED**，证据见 §5 与 `docs/audit/default-template-neutralization-18b.md`。出厂为行业中性空站（Blank System）；工业材料制造 Example 仅经 `db:seed` 可选装载（Demo Site）。两态经 Fresh Install + 真实 HTTP/浏览器对拍，验证 **Blank System ≠ Demo Site**。

| **TD-56** | Detail 页面（Article/Product/Service）结构写死，未迁入 Template+Block 组合 | 18G Discovery；2a/2b | **CLOSED by 18G-2a/2b**（见 §5） | Product/Service Detail 迁 EntityRenderContext + Detail blocks，**不把业务内容复制进 Page**；Article Detail 经裁定保持 Content 文章模板（文章 body 即内容、模板负责标准版式，非结构写死缺陷）；不为每个产品做独立模板 | — | #116, TD-53, TD-54 |
| **TD-57** | Listing 页面（Products/Solutions/Knowledge/Content 列表）结构写死 | 18G Discovery；2b | **CLOSED by 18G-2b**（见 §5） | Product/Service/Knowledge 列表迁 ListingRenderContext / SystemPageRenderContext（grid/filter/pagination）；普通 Content 栏目用通用 category 列表模板、SEO 走 resolveListing；数据 site+locale+published 过滤、结构不写死 | — | #116, TD-53, TD-54 |
| **TD-58** | 后台仅“首页装修器”，无 Page Composition Manager；BlockController 仅 index/update、无 create/store/destroy | 18G Discovery；18G-1/2b | **CLOSED by 18G-1/2b**（见 §5） | Page CRUD + block 编排（add/edit/move up/down/hide/duplicate/delete/preview/publish）；动态 block 编辑器（按 registry fields）；非自由拖拽；Landing/Page/系统页全覆盖 | — | #116, TD-53..TD-55 |
| **TD-63** | grid data_source 缺上下文：product/service/content grid 仅 all/line/picked·latest，无 current（当前栏目/系列）、related（当前 Entity 相关） | 18G-2 Discovery | **CLOSED by 18G-2a**（见 §5） | grid 支持 all/current/related（product）、related（service）、current（content），由 Render Context 提供、Detail related 槽消费；Listing main 槽接线属 TD-57（2b） | **YES** | #116, TD-53 |
| **TD-64** | site-level SEO 查询/唯一索引未排除 page_id：findSiteLevelSeo 仅 whereNull content/entity、sites_seo_meta_unique 谓词未排除 page_id，page-level SeoMeta 可被误取或占用站点级槽位 | 18G-2 Discovery；2a 补索引 | **CLOSED by 18G-2a**（见 §5） | findSiteLevelSeo content/entity/page 全 null；migration 000014 重建 sites_seo_meta_unique 谓词加 page_id IS NULL；site/page 级 SeoMeta 共存与分别解析测试 | **YES** | #116, TD-61 |
| **TD-65** | `indexableEntitySlugs()` 未按 locale 过滤：sitemap/llms 白名单 pluck 跨翻译行 slug，翻译组内仅当前语言 noindex 时白名单仍含该 URL（兄弟翻译行未 noindex） | 18G-2a（测试 + tinker 复现） | **CLOSED by 18G-2a**（见 §5） | indexableEntitySlugs 加 forLocale(LocaleContext::current())；entity noindex 测试覆盖 | **YES** | #116, TD-61 |
| **TD-66** | SeoMeta 编辑不跟随 locale：SeoMetaController::fillSeo 不写 locale、SeoMeta fillable 缺 locale，Admin（?trans=en）保存的 SEO 覆盖恒 zh-CN，en 内容/Entity 自定义 SEO（title/desc/canonical/OG/noindex）无法管理 | 18G-2a；18G-2b | **CLOSED by 18G-2b**（见 §5） | SeoMetaController 重写：fillSeo 按编辑目标 locale 写入、fillable 加 locale、unique 查询带 locale；en 自定义 SEO 覆盖前台真实消费，AdminSeoMetaCrud（27 用例）锁定 | — | #116, TD-61, TD-52 |
| **TD-68** | PublicUrl::base() 用 url('/') 在 /en locale 路由下被语言前缀污染，叠加 localePrefix 产生 /en/en/ 重复 canonical | 18G-2b（两态 canonical 对拍） | **CLOSED by 18G-2b**（见 §5） | base() 非 console 改 request()->root()（干净 origin，不含 locale/path）；test_en_canonical_has_single_en_prefix / test_zh_canonical_has_no_en_prefix 锁定 | — | #116, TD-51 |
| **TD-69** | 英文页 hreflang 的 zh-CN / x-default 错误指向 /en（应指中文根 /）：buildHreflang 用受当前 locale 污染的 url('/') | 18G-2b（HREFLANG_MAKE 诊断定位） | **CLOSED by 18G-2b**（见 §5） | buildHreflang 改 request()->root() + 目标 locale prefix 显式拼接；首页 + 产品详情 zh/en hreflang 实测全正确；test_hreflang_alternates_point_to_each_locale_own_url 锁定 | — | #116, TD-51 |
| **TD-70** | ~~首页 HomeController 用旧首页装修器（page_blocks.page='home' 字符串）、未迁入 pages 表 Composition~~ 用户裁定从 v1.1 拉回 v1.0（blank 零代码建站契约） | 18G-2b；**P-STEP 18I** | **CLOSED by 18I**（见 §5） | HomeController→HomeRenderContext（extends PageRenderContext）→CompositionRenderer；BlankHomepageSeeder 建中性持久化 is_home Page（zh/en 共享翻译组、区块清空）；管理员经 Page Composition Manager 零代码搭首页，StructureSeeder 演示首页 9 block | —（v1.0 收口） | #116 |
| **TD-73** | Analytics / 转化集成完全缺失：无 GA/GTM/Meta Pixel/custom、无 settings key、无 head/body injection、无事件，CSP script-src default 'self' 拦截第三方 | 18H Discovery；**CLOSED by 18H-3** | **CLOSED by 18H-3**（见 §5） | Site-scoped analytics 设置组（GA4/GTM/Meta，默认关）+ GeoAnalytics 事件层 + Basic Consent + 受控动态 CSP；第三方 ID 全在 Setting、Blade 无 gtag/fbq；AnalyticsConsentCsp18H3Test 锁定 | — | TD-88..TD-90 |
| **TD-74** | AuditLog 覆盖缺口：现仅 9 控制器（Content/Category/Inquiry/Fact/Group/Menu/Media/Setting/Site），缺 Entity/EntityRelation/SeoMeta/Page/PageBlock/Theme/Plugin | 18H Discovery；**CLOSED by 18H-3** | **CLOSED by 18H-3**（见 §5） | Entity/SeoMeta/Page/Block/Form/Theme/Plugin 关键操作全部 record/recordChange，detail.changes 带脱敏 before/after；AuditCoverage18H3Test 12 测试锁定；AuditLog≠Revision（TD-75 不重开） | — | TD-91, TD-92 |
| **TD-79** | 搜索 Builder 不索引 Page / Landing / 系统页（仅 Content + Entity）：Landing Page 理应可被站内搜索召回，系统固定页（About / Contact 等）可不强搜 | 18H-1（Composition 接入核查） | **DEFERRED v1.1（建议，待用户裁定）** | Landing/Page 作为可搜资源投影入索引（系统页可按需排除），复用同一 Search Contract；当前 Content/Entity/Product/Service 搜索已满足 V1 核心官网检索 | NO（v1.1；核心资源搜索完整） | #116 |

### P3 — 后台与体验

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
| **TD-14** | RBAC 仅两级（super admin / admin），蓝图 §12.2 要求三角色 + 站点成员 | 蓝图 admin-management-completion-design §12.2 | **DEFERRED v1.1**（用户裁定） | 角色模型（如 owner/admin/editor）+ 站点成员关系 + 越权测试；普通管理员不可跨站 | NO（v1.1；单组织开源 v1 两级可接受） | — |
| **TD-15** | 校验 i18n 缺失 + 无字段级 `@error` | 16A；18H-2（TD-84） | **CLOSED by 18H-2**（见 §5） | validation.php 双语语言包 + form_field 字段级 `@error`；关键字段字段级错误展示；不削弱后端校验 | NO（v1.1） | — |
| **TD-16** | Category 多项不规范：slug unique 未按 site_id、type 枚举漂移、外链接线、栏目 seo_title/seo_desc 疑似第三套 SEO | 17F/17G 遗留 | **PARTIAL：① CLOSED by 18C；②③④ DEFERRED v1.1** | ① slug 站点作用域唯一 + type 四类型常量/访问器收敛（18C 完成，AdminCategoryCrud +4 测试）；② 外链接线、③ 栏目 SEO 归并 v1.1 | ① 已闭合；②③④ NO（v1.1） | SeoMeta(17D) |
| **TD-17** | 缺后台友好 500 错误页 | 16A/17F | **DEFERRED v1.1** | 后台/前台异常展示友好错误页，绝不泄漏堆栈/路径；有模拟 500 的验证 | NO（v1.1；安全上已不泄漏堆栈） | — |
| **TD-18** | Theme/Plugin 上传安装 / SDK / 应用市场缺失 | 蓝图明确划出 v1.x | **DEFERRED v1.x（规划中）** | 蓝图定义的上传安装、SDK 规范、市场能力（按蓝图里程碑） | NO（v1.x） | Theme/Plugin(17E) |
| **TD-19** | GEOFlow 等旧概念后台 IA 命名未清理 | 16A F2；token 前缀 `yhf_` 已在 17F 中性化 | PARTIAL | token 前缀已中性化（DONE）；后台菜单/术语重命名为 Site/Content/Entity/Relation/SEO/GEO/Theme/Plugin/Settings 体系 | NO（v1.1） | — |
| **TD-20** | md-editor 内联上传未测；RSS 未接独立 enabled 门禁；搜索不召回 Entity 且英文召回弱 | 16A/17F；18H-1 | **PARTIAL：① CLOSED by 18C；③④ CLOSED by 18H-1；② DEFERRED v1.1** | ① RSS `geo_rss_enabled` 独立门禁（18C）；③ Entity 召回 + ④ 英文召回随 FTS5 统一搜索解决（实测 coating / 涂料 各召回 2 条、语言各自隔离）；② md-editor 内联上传 v1.1 | ①③④ 已闭合；② NO（v1.1） | #86, #114 |
| **TD-36** | 全站无统一组件 loading / `aria-busy` 模式（当前以整页 POST 刷新为主） | 18D-07 | DEFERRED v1.1 | 建立统一 loading 组件、aria-busy 与提交/加载反馈；不影响当前整页刷新可用性 | NO（v1.1） | TD-32 |
| **TD-37** | 产品列表系列卡在窄屏两列、每卡约 165px 偏密 | 18D-09 | DEFERRED v1.1 | 窄屏单列或密度/间距优化；当前不横向溢出、信息可读 | NO（v1.1） | TD-33 |
| **TD-38** | example 极简主题（31 行、零设计系统）未对齐深色/响应式体系 | 18D | DEFERRED v1.1（说明项） | example 为"零引擎依赖"极简示范主题；完整设计系统在 default。评估是否补齐或在主题文档标注能力边界 | NO（v1.1） | TD-28 |
| **TD-46** | factory / cooperation Core 路由与 IA 命名制造业特定：URL factory、概念 Factory & Certifications / workshops / annual capacity in Tons | 18F（en-only B 对拍登记） | **DEFERRED v1.1** | 数据驱动可见：无 production / facility 数据的站点 FactoryController 实质 404、sitemap/feed 不输出 URL，非制造业不暴露；重命名 factory→facilities、单位 Tons 中性化涉及路由 / sitemap / 翻译键，需独立 IA 阶段。验收：非制造业 Core 默认不出现 factory 概念，或路由 / 文案中性（Facilities & Certifications） | NO（v1.1；数据驱动 404 已保证不串行业） | TD-19 |
| **TD-59** | 完整 Form Builder 缺失：无 Form/FormField 模型，字段固定、验证写死控制器+JS、Inquiry 固定列无 payload、TYPES 写死中文、无 notification、consent 仅文案 | 18G Discovery；18H Discovery 细化；18H-2 | **CLOSED by 18H-2**（见 §5） | forms / form_fields（每 locale 行）+ FieldTypeRegistry 11 类型 + 动态服务端验证（最终裁决）/ 客户端辅助 + FormSubmission payload JSON + Inquiry 投影 + consent 勾选 + notification（默认关、可配收件人）+ FormReference 引用 form_id；不存任意 HTML、不做自由排版 / 多步；零代码 Download 表单 + Landing 实测闭环 | — | #116, TD-55 |
| **TD-67** | 外观切换按钮 aria-label/title 引用 `ui.mode_aria_toggle`，但该键在 zh-CN/en ui.php 均缺失，按钮对屏幕阅读器显示原始键名、无标题提示 | 18G-2a Gate（真实浏览器 a11y 复验发现） | **CLOSED by 18G-2a**（见 §5） | 两 ui.php 补 mode_aria_toggle（中：切换外观模式（浅色 / 深色 / 跟随系统）；英：Toggle appearance (light / dark / follow system)），新端口 serve 后按钮 accessible name 中/英正确 | NO（发现即修复，不阻塞） | TD-28, TD-52 |
| **TD-75** | Revision 仅覆盖 Content（ContentController 快照 + GeoflowSync），Entity/Page 无版本快照 | 18H Discovery | DEFERRED v1.1 | Entity/Page 版本快照 + diff/回滚（对照 ContentRevision）；V1 Content revision 已满足核心 | NO（v1.1） | — |
| **TD-76** | 品牌/标识图片引用双轨：header/footer logo 走 setting.geo_org_logo 路径+asset()（不经 media ID），与媒体库机制不统一；media 无 responsive srcset | 18H Discovery | DEFERRED v1.1 | logo 改 media ID 引用消除双轨；media 渲染补多尺寸 srcset；当前 logo 可配置、picture/webP 已可用 | NO（v1.1） | — |
| **TD-103** | 首页 Composition 迁移后无 Hero 多 slide 轮播 / 分屏 slideshow（旧 Hero mode B/C：每 slide 独立文案、自动轮播、crossfade）；通用 hero 为单屏、无 gallery/carousel block | 18J Test Inventory Delta | **DEFERRED v1.1（建议，待用户裁定）** | 以通用 Gallery/Carousel block（结构化 slides + 注册 renderer）提供多 slide，保持单 h1 / 键盘可达 / 不自动 CLS；单屏 Hero 满足 V1 官网首屏主要需求 | NO（v1.1；轮播对 a11y/SEO/CLS 不友好） | TD-70, #116 |
| **TD-104** | Feature / 网格 item 无法自定义配图（旧 BlockItemImageTest：item 自定义图片优先于 icon）；feature_grid item_fields 仅语义 icon、无 media 字段 | 18J Test Inventory Delta | **DEFERRED v1.1（建议，待用户裁定）** | item_fields 增加可选 media_id（与 icon 二选一、安全渲染）；单图需求当前由 image / media_text block 满足 | NO（v1.1） | TD-70, #116 |

### P4 — 观察与测试限制（默认 NON-BLOCKING，记录在案）

| ID | Title | Source | Status | 说明 / 验收 | Blocks v1.0.0? |
| --- | --- | --- | --- | --- | --- |
| **TD-21** | 尾斜杠 301 在 Feature 测试层无法复现（CanonicalizeSlash runningUnitTests skip，客户端剥尾斜杠） | 多阶段 | NON-DEBT（测试限制） | 真实 HTTP（serve/curl）已验证 301；保留为已知测试框架限制，若未来引入 HTTP 级集成测试再覆盖 | NO |
| **TD-22** | release archive export-ignore docs 后，个别守护测试仅在完整 checkout 成立 | 15/16 | NON-DEBT（打包限制） | 发布包测试矩阵中注明"完整 checkout vs release archive"差异，CI 跑完整 checkout | NO |
| **TD-23** | PHPUnit doc-comment metadata deprecation | 多阶段 | DEFERRED（v1.1） | 升级 PHPUnit 12 时改用 attribute 语法 | NO |
| **TD-24** | PageCache file store 在 `cache:clear` 后磁盘回收不即时 | 18A 观察 | DEFERRED | 功能不影响正确性（flush 逻辑生效）；磁盘回收策略优化延后 | NO |
| **TD-27** | `GeoflowApiTest` 测试顺序依赖：自定义分批顺序下 `insert facts site_id=2` FK failed（RefreshDatabase 同进程 autoincrement / 静态 SiteContext memo 与测试 site id 假设叠加） | 18C 分批回归时发现 | DEFERRED（测试隔离） | 单独跑 12 passed、全量固定顺序 826 全绿，非产品 Runtime bug、CI 固定顺序不受影响；v1.1 加固夹具（显式取 site id / 每类重置 memo） | NO |

---

## 5. 已 CLOSED 归档（销项历史，禁止删除）

| ID / Epic | Title | Closed By | 销项证据 |
| --- | --- | --- | --- |
| **#86** | Public Render Contract / Feed 泄漏（DB 有 / Feed 有 / 前台 404） | **P-STEP 17G** | 新增 PublicUrl + PublicIndex，七大输出（Schema/GEO/Sitemap/LLMS/RSS/Search/Canonical）统一准入：Published + 当前 Site 可见 + 有效公开 URL + HTTP 200 + 可索引；90 URL×3 类站点对拍全 200，NON-200=0 |
| **TD-04** | 关系读模型双轨（Catalog 读 metadata slug 数组而非 EntityRelation） | **P-STEP 18A**（commit `4dbc95a` / tag `checkpoint-18A`） | Catalog 产品场景/相关、场景 combo/相邻/关键参数改为**单向**从 EntityRelation 派生，与 /geo.json 同源，禁止双向反写；CatalogSeeder 对齐后 58 边；新增 CatalogRelationAuthorityTest 12 测试；全量 801/3948/0/0；uses 有向不对称、跨站隔离、Draft 排除、删边均有测试 |
| **TD-08a** | EntityRelation 写入后旧整页缓存不失效 | **P-STEP 18A** | EntityRelation 模型 saved/deleted → PageCache::flush()（模型层覆盖后台/tinker/import 全写入路径）；test_new_manual_relation_edge_reaches_frontend_and_geo_graph 锁定缓存失效契约 |
| **P-STEP 17 P0** | 六大管理面（Site/Entity/EntityRelation/SeoMeta/Theme·Plugin/Settings）无 Admin UI | **17A–17F** | 逐阶段 Gate PASS（68e2b03 / 0d6872b / d839d5a / 220947f / 3348c92 / f783af6） |
| **P-STEP 17G** | 六大管理面系统级 Full Admin UAT + 产品双轨收口 | **17G**（200b5ae，789/3889） | Content 收敛 article/page、Product 成为正式 Entity；中间件顺序修正；4 类真实问题修复 |
| **（17F 子项）** | GEOFlow token 前缀 `yhf_` 中性化 | **17F**（f783af6） | token 前缀改产品中性；IA 重命名余项见 TD-19 |
| **TD-10** | 默认模板 / Copy / IA 行业垂直痕迹 | **P-STEP 18B**（tag `checkpoint-18B`） | geo:install 出厂层 BlankHomepageSeeder 清空历史 migration 播种的 16 个制造区块；config/copy.php、config/pages.php、HomeController、五个前台控制器、home/* blade、产品后缀、询价、copyright 全面行业中性；空站首页中性欢迎屏、导航/footer 经 Catalog 门控（无工厂/合作/案例列）；制造 copy 仅以 slug 键控 Example 包（product_faqs/scene_faqs）保留，通用站零运行时命中；两态 HTTP 对拍 |
| **TD-11** | 默认 Menu / Blocks 未按站初始化 | **P-STEP 18B** | 制造区块只在 `db:seed` 由 Demo StructureSeeder 按站重建；geo:install 不播任何区块（空站 page_blocks=0）；菜单/区块全部 site-scoped + Catalog/Group 数据驱动，空站导航仅知识中心，A/B 不串；BlankSystemDemoSeparationTest 锁定 |
| **TD-12** | `Site.name` 与 setting `site_name` 双源 | **P-STEP 18B** | Site.name 成为唯一权威：Site booted `saved` 单向镜像 site_name；GeoInstall 默认站名收敛为 app.name/--site-name 并 save；DefaultSettingSeeder site_name 跟随 Site.name（不再硬编码 app.name 覆盖自定义名）；SettingController general 回写 Site.name；真实 HTTP 后台改名后 title/OG/footer(9 处)/geo.json/RSS 四端同源跟随，DB 双源一致 |
| **TD-13** | 27 个制造/化工垂直内置图标 | **P-STEP 18B** | 全站收敛为单一通用 SVG 图标库 `site/_icon.blade.php`（通用名 registry）+ config/icons.php 中性 label registry，数据驱动、无 slug→垂直图标硬编码；出厂图标序列中性，垂直语义仅随 Demo 数据出现 |
| **TD-25** | PageCache 整页缓存键只用 `getHost()`（不含端口），同主机异端口多实例（本地并排 / 同机非标端口反代）命中同一 shell，正文与 canonical 串站 | **P-STEP 18B**（两态 HTTP 实测发现） | keyFor 改 `getHttpHost()`（含端口；标准 80/443 行为不变，生产按域名分区不受影响）；新增 test_cache_key_distinguishes_same_host_different_port（同主机异端口键不同 / 同 origin UTM 共享 / 异域名分区）；修复后 blank 8096 与 demo 8097 首页 HIT 互不串 |
| **TD-26** | SQLite 下 `Schema::getTableListing()` 返回 `main.<table>` 限定名，SiteController 删除保护动态表白名单整体失配，空站（含 settings 镜像行）被误判有业务数据无法删除 | **P-STEP 18B**（空站删除复现发现） | resourceCounts 循环开头 `Str::afterLast($listed,'.')` 去除 schema 前缀；settings 列入 CONFIG_TABLES 并在事务内随空站删除后 Setting::flush()；空站可正常删除、有数据站点仍受保护 |
| **#114** | Catalog Read Model / Entity Public Model 权威链路与公开 URL 体系 | **P-STEP 18A + 18C**（tag `checkpoint-18C`） | 关系权威源 TD-04（18A）、Entity URL 冻结 TD-05、Schema @id/PublicUrl TD-09（18C）均 CLOSED；Catalog 单向从 EntityRelation 派生；TD-06 `/article/` 收敛书面 DEFERRED v1.1（301 桥接锁定）。v1.0 权威链路收口 |
| **TD-05** | Entity 六类型公开 URL 体系未冻结 | **P-STEP 18C（DECISION）** | 书面契约 + ReleaseResidual18CTest 锁定：core product→`/products/{slug}` 无斜杠；有场景 service→`/solutions/{slug}/` 带斜杠（301 契约）；organization/person/location/topic/非 core product/无场景 service→url=null 且不进 feed；v1.0 不补 org/person/location 详情页。实测 core 200 / 非 core 404 / 单数 /product/ 404 |
| **TD-07** | Organization 双载体（organization Entity vs sites.metadata.organization） | **P-STEP 18C** | 裁定 **Site 聚合（Site.name + geo_org_* Setting + Site.metadata.organization）为主体组织唯一事实源**，organization Entity 仅为挂边目录节点；GeoGraph site 块 organization 锚点 @id=`home()#organization` 对齐 SchemaBuilder，主体 org 节点 `is_site_organization` 输出 same_as 且节点 id 不变；facts 仅 seeder 消费、geo:install 不装载，前台 Runtime 零 `Facts::`（grep 证实） |
| **TD-08 / TD-08b** | Entity/Site/SeoMeta/Content 写入后整页 HTML 失效未挂接；Site 改名同进程 stale memo | **P-STEP 18C**（08a=18A） | Entity booted saved/deleted → PageCache::flush；Site saved 独立 try/catch flush 并在 currentSite 为本站时 setSite 刷新 memo、deleted flush；改名连带 Setting 镜像 +2 幂等（断言 ≥+1）；Entity 写入只失效本站；4 个 feed 端点不命中整页缓存；缓存批 90 tests 绿 |
| **TD-09 / #143** | 声明性绝对 URL 用 `url()` 随请求 origin，与 PublicUrl 按站点 domain 裁决形成 host/scheme 分叉；Catalog 缺表守卫 | **P-STEP 18C** | 新增 PublicUrl::url()；canonical/og:url/og:image/JSON-LD @id/url/item/image、sitemap loc、llms、robots、rss、crumbs 全部改派 PublicUrl；功能性同源 URL（导航/卡片/表单 action/favicon/重定向/后台 label）显式保留 url()/asset()；sitemap 首页 loc=base()（无斜杠）与首页 canonical=home()（带斜杠）契约分离并注释；SitemapRobotsTest host 预期改 example.com（强化排除断言）；Catalog 缺 entities 表安全降级；ReleaseResidual18CTest 锁定所有 ld+json 无 localhost |
| **#144** | Release Gate 单一事实源反向审计 | **P-STEP 18C** | 倒推 Fresh→Admin→Entity/Relation/SEO→Theme/Plugin/Settings→Frontend→Schema/GEO/Feed→Cache→Multi-Site→CLI，未发现新双源；组织/关系/公开 URL/SEO/站点名/feed 准入各有唯一事实源 |
| **TD-16①** | Category slug 未按 site_id 唯一、type 枚举漂移 | **P-STEP 18C** | Category 冻结 list/product_list/page/external 常量+访问器（single=page、product=product_list 只读别名）；slug/parent Rule::unique/exists 带 where site_id、type Rule::in、external 强制 external_url；PageController/CanonicalizeSlash/SitemapBuilder/blade 统一访问器；AdminCategoryCrud +4 测试（②③④ v1.1） |
| **TD-20①** | RSS 无独立 enabled 门禁 | **P-STEP 18C** | FeedController::rss 读 geo_rss_enabled，=0 时 404，与 sitemap/llms 同标准；DefaultSettingSeeder 默认 '1'，设置 64→65，fresh geo:install settings=65 实测；空站/Demo /feed.xml 均 200（②③④ v1.1） |
| **TD-28** | 前台无 Light/Dark/System 深色模式 | **P-STEP 18D**（`862ff1d`） | ThemePalette darkOverrides 独立深色语义令牌 + lightenForContrast 向白提亮至 AA；SSR `<html data-color-scheme>` 防 FOUC、localStorage 记忆（gwos-color-mode）；外观切换器 light/dark/system；12 正常页 + 404/403/500/503 同源深色；新增 ThemeColorModeTest 10 用例、ThemePalette dark 测试 |
| **TD-29** | 行业视觉预设不足（蓝图要求 8 类） | **P-STEP 18D** | 新增 finance / healthcare，commerce label 承载 Consumer，共 8 预设；预设只改视觉 token、不改 IA 不注入行业数据；契约测试断言 8 预设齐全、primary_dark 恒空、tokens 仅白名单键（6 passed/60 assertions） |
| **TD-30** | 字号未 token 化（200+ 处散落 px） | **P-STEP 18D** | :root 建立 12 档 rem `--fs-*`；结构性标题/正文 27 处消费 token、组件辅助文字归并 82 处；缝隙 px 与 @media 收缩值按契约保留（见 design-system §4.3） |
| **TD-31** | 组件/页面存在彩色孤立硬编码色 | **P-STEP 18D** | Global Visual Refactor：彩色 hex=0、彩色 rgba=0；仅保留语义反白 #fff、mask 技术常量 #000、阴影/遮罩中性 rgba；深色逐页验证 |
| **TD-32** | 焦点环不统一、部分按钮缺 disabled | **P-STEP 18D** | 全局 `:focus-visible`（键盘 only）2px brand 环，覆盖链接/按钮/卡片/summary/分页；表单控件专门 :focus；补 ghost/text disabled；键盘 Tab 实测 activeElement 环 = var(--brand) |
| **TD-33** | 移动产品详情 H1 `.ph-h` 不缩小、长型号裁切 | **P-STEP 18D** | 三层排查（缺 brace / PageCache 旧快照 / CSS 同特异性源顺序）；基础规则加 overflow-wrap:anywhere，媒体缩小块移至基础规则之后（@media600 26px）；headless 重截标题完整不裁切 |
| **TD-34** | 必填字段仅视觉 `*`、屏幕阅读器无必填语义 | **P-STEP 18D** | _lead_form 三个必填字段（name/phone/type）补 `aria-required="true"`；label for/id 关联、autocomplete、错误/成功语义原有 |
| **TD-35** | geo:upgrade 部署新代码后不清旧编译视图与整页缓存 | **P-STEP 18D** | GeoUpgrade 迁移验证后加 view:clear + PageCache::flush；php -l 通过、upgrade focused 6 passed/26 assertions，升级契约不破坏 |
| **TD-39** | 工厂页对缺失生产事实裸输出 0：H1「自有约 0 ㎡…年产能约 0 吨」、数据条渲染 0 值项、SEO 拼出空 / 0 片段（Demo 三项齐全故未暴露） | **P-STEP 18E**（FactoryController + factory.blade） | H1 改为按真实事实（area / workshops / capacity）逐项拼接、缺失不写入；stats 过滤 `num>0`；SEO title/description 按数据拼接。新增 FrontendBackendClosure18ETest 锁定（部分生产站点无 0 ㎡ / 年产能约 0 / 空厂区标题） |
| **TD-40** | 底部统一 CTA「或直接致电」行无电话门控，空站（/knowledge/ 可访问）渲染空号码行 | **P-STEP 18E**（_bottom_cta） | 电话行以 `@if(!empty($bcPhone))` 包裹，未配置电话整行不渲染；test_bottom_cta_hides_phone_row_when_no_phone 锁定 |
| **TD-41** | 应用场景总览 H1「你的**店**属于哪一类？」零售 / 餐饮口径 | **P-STEP 18E**（solutions/index） | 中性化为「你的**业务**属于哪一类？」；test_solutions_index_uses_neutral_business_wording 锁定 |
| **TD-42** | Catalog 硬依赖 zh-CN organization：en-only 站点无 zh-CN 主体行时 relationMap() 传 null，非 nullable 签名在进方法体前 TypeError，首页 / sitemap / geo 全 500 | **P-STEP 18F** | buildDataset 新增关系权威基础语言 baseLocale：默认 zh-CN，站点无该语言主体时回退 site_default_locale（前提该语言主体存在），关系 base 行查询改用 baseLocale；relationMap 首参改 ?Entity（方法体本有 ! zhOrganization 守卫，nullable 后可触达）。en-only 站 B 不再 500 |
| **TD-43** | SchemaBuilder 服务区域 area_served 三元 true 分支（非默认语言）直接访问无 ??，en-only 站点无 production 时 Undefined array key 500 | **P-STEP 18F** | 改 (array) (area_served ?? [])，与 else 分支一致；en-only B 首页 200 |
| **TD-44** | 首页 blank 兜底分支（无 page_blocks）<p> 直接用单语 site_description，en-only / 跨语言站点显示另一语言（中文）描述 | **P-STEP 18F** | blank 描述优先当前语言组织摘要 Catalog::company()['summary']（站点隔离 + locale-aware），其次 site_description，最后 ui.blank_home_lead；B en 首页显示英文自身描述 |
| **TD-45** | 默认 SEO 翻译键带行业特定：knowledge「Selection, Process & Construction / materials / production」、products「Mixing Parameters / Process Parameters / Application Process」 | **P-STEP 18F** | lang/{en,zh-CN}/seo.php 中性化：knowledge_index_title=Knowledge Center / 知识中心、knowledge_desc 通用引导；product line/show/howto 去掉 mixing/process/application 工业措辞（Product Series / Specifications / Product Overview）。factory IA / 单位余项见 TD-46 |
| **TD-47** | routes/web.php 顶层函数 PublicUrlLocalized() 在同一进程路由文件被重复加载（多测试 / 路由重载）时 Cannot redeclare fatal | **P-STEP 18F** | 函数声明以 if (!function_exists('PublicUrlLocalized')) 守卫包裹；全量测试进程不再 fatal |
| **TD-48** | 站内搜索只覆盖 Content（contentQuery），不搜索 Entity，蓝图 §23 要求 V1 统一搜索 Content/Entity/Product/Service | **P-STEP 18F** | 新增 SearchResult 值对象，SearchController 合并 contentQuery + entityQuery（仅纳入 PublicUrl::entity() 有公开落地页的产品 / 场景），LengthAwarePaginator 手动分页，查询层满足 Public Render Contract；双语 / en-only 对拍通过 |
| **TD-49** | geo.json 默认 JSON 编码把中文转义为 `\uXXXX`、URL 斜杠转义，AI 直读不友好（输出质量项，非功能 bug） | **P-STEP 18F** | FeedController::graph 加 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`，中文与 URL 直出；GeoGraphTest `test_graph_emits_unescaped_unicode_for_ai_friendly_output` 锁定（原始 body 含中文、不含 `\u793a`）；两态对拍直出 |
| **TD-50** | 英文 sitemap 首页 loc 用 `PublicUrl::base()`（无 locale 前缀），输出中文首页根地址而非英文首页 | **P-STEP 18F**（两态对拍发现） | SitemapBuilder 首页 loc 改 locale-aware：默认语言 base()、非默认 base()/{locale}（/en，无尾斜杠契约）；Localization18FTest 补两语首页 loc 断言（en 含 base/en、不含无根 base）；真实 HTTP 首项已为 /en |
| **TD-51** | 英文页 header mega menu / footer / 首页与列表正文内部链接用 `url()` 不带 locale 前缀，点击跳回中文站（zh 路由），en 访客被带回中文页 | **P-STEP 18F Gate**（真实链接审计 + HTTP 点击发现） | 两层修复：显式改 PublicUrl（AppServiceProvider resolveMenuHref、layouts/site、search 共约 8 处）+ GeoUrlGenerator 新增 `withLocalePrefix()` 兜底（en 请求相对 url() 自动补 /en；console/后台/默认/外链/tel/mailto/锚点/已带前缀幂等）；新增 `test_en_internal_links_keep_en_prefix`；中英 13 页链接审计 bad=0；Focused 9/42 |
| **TD-52** | 英文页可见/属性 UI 中文残留：header/footer logo alt·aria 用中文 site_name、nav/checkbox/tel aria-label 经 config/copy 中文兜底、系列页 eyebrow「产品系列」、搜索空状态、_subnav「栏目导航」 | **P-STEP 18F Gate**（剥离 style/script/注释的渲染 CJK 审计发现） | config/copy nav.ariaLabels 中文值改 null（翻译键生效，不造第二事实源）、布局 logo alt/aria 改 `$brandDisplayName`、telBase 兜底改 `__('ui.phone_aria')`、line eyebrow/search 空状态/_subnav 改翻译键；en/zh ui.php 补键；PageCache flush 后 en 7 页可见/属性 CJK=0；Focused 9/42、Full 872/4655 |
| **TD-60** | 前台主脚本 SyntaxError：layout `<script>` 内 `{{ json_encode() }}` 被 Blade e()（ENT_QUOTES）二次转义，JSON 双引号→`&quot;`，主脚本 `Unexpected token '&'` 整块失效，导航收缩/下拉/抽屉/数字动画/IntersectionObserver 全不建立，首屏以下 `.reveal` 区块永久 opacity:0 | **P-STEP 18G-1 Gate**（真实浏览器 UAT 发现） | labels/ariaCurrent 改 `{!! json_encode() !!}`（json_encode 本身即合法 JS 字面量）；新增 test_frontend_inline_theme_script_is_not_double_escaped；Focused 26/78；真实 Chrome 滚动后 feature_grid 自动加 `in`/opacity1、console 零错误 |
| **TD-62** | Detail 资源渲染器缺失（detail header / spec table / process steps / scene chips / adjacent 无通用 renderer，16 Core Block 无法表达 Detail 全貌） | **P-STEP 18G-2a** | 新增 5 个 system block（entity_hero / entity_specifications / entity_steps / entity_relations / bottom_cta，system=true 不进自由添加列表），复用原 partials、当前 Entity 直驱、无数据不渲染、通用而不按行业建块；Product/Service Detail 全貌经 Composition 管线输出，DetailComposition18G2Test 锁定 |
| **TD-63** | grid data_source 缺 current / related 上下文 | **P-STEP 18G-2a** | BlockRegistry resolveData 三个 grid 传 context：product all/line/picked/current/related、service related（prev/next）、content current（category）；Detail related 槽真实消费，不在 Blade 判断 id |
| **TD-64** | site-level SEO 查询与唯一索引未排除 page_id | **P-STEP 18G-2a** | findSiteLevelSeo 加 whereNull page_id；migration 000014 重建 sites_seo_meta_unique 谓词加 page_id IS NULL；test_site_and_page_level_seo_meta_coexist_and_resolve_separately 锁定共存与分别解析 |
| **TD-65** | indexableEntitySlugs 缺 locale 过滤，sitemap/llms 白名单跨翻译行泄漏仅当前语言 noindex 的 URL | **P-STEP 18G-2a** | indexableEntitySlugs 加 forLocale(LocaleContext::current())；test_entity_seo_meta_noindex_consumed 覆盖（翻译组仅当前语言 noindex 即从该语言 feed 排除） |
| **TD-67** | 外观切换按钮 aria-label/title 键 `mode_aria_toggle` 缺失（屏幕阅读器显示原始键名） | **P-STEP 18G-2a** | lang/zh-CN、lang/en ui.php 各补 mode_aria_toggle 键；全新端口 serve 验证按钮 accessible name 中/英正确（8122 旧进程缓存异常、8135 正常，证明修复有效） |
| **TD-68** | PublicUrl::base() 用 url('/') 在 /en 路由被语言前缀污染，产生 /en/en/ 重复 canonical | **P-STEP 18G-2b** | base() 非 console 改 request()->root()（干净 origin）；SystemPageComposition18G2bTest `test_en_canonical_has_single_en_prefix` / `test_zh_canonical_has_no_en_prefix` 锁定 |
| **TD-69** | 英文页 hreflang 的 zh-CN / x-default 错指 /en（buildHreflang 用受当前 locale 污染的 url('/')） | **P-STEP 18G-2b** | buildHreflang 改 request()->root() + 目标 locale prefix 显式拼接；首页 + 产品详情 zh/en hreflang 实测全对；Localization18FTest `test_hreflang_alternates_point_to_each_locale_own_url` 锁定 |
| **TD-71** | SearchEngine 契约 / 接口缺失 | **P-STEP 18H-1**（tag `checkpoint-18H-1`） | Search Contract v1：SearchQuery（term/locale/siteId/types/page/perPage）、SearchResults/SearchResult、SearchEngineInterface + 容器延迟绑定；SearchController 只构造 query 调接口；未来换 FTS5/Meili/ES 上层不重写 |
| **TD-72** | FTS5 默认引擎缺失（旧 LIKE + 内存分页） | **P-STEP 18H-1** | 统一派生索引：search_documents（原文/展示）+ search_index（FTS5，CJK bigram/unigram token）；bm25 title10/summary5/body1 排名 + SQL LIMIT/OFFSET DB 分页 + 安全 Highlighter 高亮；增量 SearchIndexSync + dirty 懒重建 + `search:reindex`（GeoInstall/Upgrade 接线）；FTS 不可用降级 DatabaseLikeEngine；SearchProductization18HTest 18 测试锁定 |
| **TD-78** | flushDirty 不切 SiteContext 致跨上下文重建唯一冲突 | **P-STEP 18H-1** | flushDirty 内 SiteContext::withSite 切目标站再 rebuildSite；cross_site 隔离测试锁定 |
| **TD-80** | SearchIndexSync 增量 upsert 缓存「不含 Service 的半成品」Catalog memo（seeder 中途预热，后续 Service 读空 scenes 不索引） | **P-STEP 18H-1** | Entity saved/deleted 与 EntityRelation 回调在搜索派生（含 catalog 读取）之后 `Catalog::flush()`，下一次 saved 重建 dataset 时当前行已入库可定位；CatalogRuntimeIsolation+ExampleDataset+NarrativeSlot 组合 30 passed 锁定 |
| **TD-81** | 跨测试类 SiteContext 静态残留（无 tearDown），RefreshDatabase 重建库后 currentSiteId 指向已不存在的 site，下一测试类 facts 插入 FK | **P-STEP 18H-1** | `tests/TestCase` 加 tearDown 统一 `RequestScopedState::flushAll()` + `SiteContext::clear()`，每个用例结束复位全部请求级 static memo 与站点上下文，跨类不再 FK / 串站 |

| **TD-73 / TD-74 / TD-88..TD-95** | Analytics + Audit Operations Closure（18H-3） | **P-STEP 18H-3** | Site-scoped analytics（GA4/GTM/Meta 默认关）+ GeoAnalytics 事件层 + Basic Consent + 受控动态 CSP + HeadCodeSanitizer；AuditSnapshot 脱敏 + recordChange 覆盖 Entity/SeoMeta/Page/Block/Form/Theme/Plugin；新增 AnalyticsConsentCsp（15）+ AuditCoverage（12）；v1.0 Required 未闭合 5→3（仅 TD-01/02/03） |
| **TD-96** | SeoMetaController::destroy 审计点引用不存在变量 `$seo`（destroy 参数实为 `$seoMeta`，18H-3 加审计笔误），DELETE seo-metas.destroy 抛 ErrorException Undefined variable → HTTP 500 | **P-STEP 18H-3 Gate Validation**（修复前全量回归 AdminSeoMetaCrudTest 两方法实测 500） | 改 `（{$seo->title}）` 为 `（{$seoMeta->title}）`；focused AdminSeoMetaCrudTest + SiteIdCoreTablesTest 42 passed（203 assertions），修复后全量回归全绿；发现即 CLOSED、不新增 v1.0 Required |
| **TD-97** | consent 接受/拒绝按钮带 `class="btn"`，点击冒泡被 analytics 的 CTA click 委托误捕获为 `cta_click`（拒绝后队列被重新 push、接受后误发 `cta_text:"接受"`），隐私控制被计入转化埋点 | **P-STEP 18H-3 Evidence Supplement**（真实浏览器点击 consent 按钮，dataLayer 实测误发 cta、denied queued=1） | click 委托最前加 `if(t.closest('#geoConsentBanner')) return;`；重验 denied queued=0、accepted 无 cta_text 接受；防回归 `test_click_delegation_excludes_consent_banner_buttons`；发现即 CLOSED、不新增 v1.0 Required |
| **TD-98** | AuditSnapshot 先脱敏再判变化，只改 api_key/smtp_password/password 时 before/after 同为 `[REDACTED]`、被误判无变化，导致纯敏感字段修改**完全漏审计** | **P-STEP 18H-3 Evidence Supplement**（audit-probe 复用 recordChange 落库，integrations.update 首次无记录） | 变化判定改以原始值为准（新增 rawSame）、再对变化值脱敏，敏感字段变更保留 before/after（均 [REDACTED]）；防回归 `test_sensitive_value_change_is_audited_redacted`；发现即 CLOSED、不新增 v1.0 Required |

| **TD-99** | Admin 新建站点未初始化 Composition 页面：SiteController::store 仅跑 DefaultSetting/DefaultForm seeder，新站无持久化 is_home Page 与 is_system 页面（首页只渲染未保存兜底欢迎屏、后台无 Page 可组合、/contact 等系统页 404）；且 BlankHomepageSeeder/SystemPageSeeder 硬编码只取默认站、不支持站点注入 | **P-STEP 18I / #280 Multi-site UAT**（demo 库新站 pages 表实测 0 行） | 两个 seeder 加构造函数 `?Site $target`（缺省回退默认站，geo:install 兼容）；store 在 withSite($site) 内追加 BlankHomepageSeeder($site)/SystemPageSeeder($site)；复验新站生成完整 20 页面（home + 9 系统页 × zh/en）+ contact blocks；发现即 CLOSED、不新增 v1.0 Required |
| **TD-100** | blank 站 /contact/ 404：ContactController::show 沿用旧 P-STEP 04「配置契约降级」守卫，`Catalog::company()` 为空即 abort(404)；联系页现已 Composition 化（contact_info 取 Site settings、form_reference 取默认表单），不依赖 company fact，该守卫过时并阻碍零代码启用联系页 | **P-STEP 18I / #280**（fresh 默认站与新站 /contact/ 均实测 404） | 移除 abort 守卫、`$company = Catalog::company() ?: []`；company 为空时 LocalBusiness schema 省略、contact_info 无事实整段隐藏、表单正常渲染；真实 curl 提交 → form_submissions（payload 完整）+ inquiries 投影成功；发现即 CLOSED、不新增 v1.0 Required |
| **TD-70** | 首页 Composition 统一（HomeController 旧 page='home' 装修器 → pages 表 Composition） | **P-STEP 18I**（用户从 v1.1 拉回 v1.0） | HomeController 极简→HomeRenderContext（extends PageRenderContext，resourceType=home、找不到持久化 page 时未保存兜底）→CompositionRenderer；BlankHomepageSeeder 建中性持久化 is_home Page（zh/en 共享翻译组、区块清空）；StructureSeeder 演示首页 9 block；Admin Page Composition Manager 零代码搭首页；HomeComposition18ITest 6 用例锁定 |
| **TD-101** | SearchController 用 `$isEn` 三元硬编码搜索页 UI/SEO 文案（搜索 / 站内搜索 / 搜索：{q} / 站内内容检索），未走 locale dictionary（违反 18F §24） | **P-STEP 18I / #282 Hardcoding 复核** | 改用既有翻译键 `__('ui.search_h1')`、`__('seo.search_title' / 'search_title_q' / 'search_desc')`，移除 `$isEn` 与未使用 import；`/search`、`/en/search` 实测 title/description 正确；发现即 CLOSED、不新增 v1.0 Required |
| **TD-102** | sitemap 漏收录始终可访问的 /contact/：TD-100 让 ContactController 无 org 也安全降级、/contact 始终 200，但 SitemapBuilder 仍以 `$hasCatalog` 为 /contact/ 收录门槛，致出厂 blank（geo:install）默认站与 Admin 新站 /contact 页 200 却不进 sitemap | **P-STEP 18I Gate Validation**（fresh 8141：/contact 200、sitemap 仅首页 + knowledge） | /contact/ 收录移出 `if ($hasCatalog)`、无条件 `$add(PublicUrl::url('contact/'))`（about 三子页仍随 hasCatalog）；空站测试改断言 sitemap **含** /contact/、/contact 200，目录页仍 404；发现即 CLOSED、不新增 v1.0 Required |
| **TD-15** | 校验 i18n 缺失 + 无字段级 `@error` | **P-STEP 18H-2（TD-84）** | `validation.php` 双语语言包（常用规则 + custom + attributes）+ `form_field.blade.php` line 76 字段级 `@error`；产品化动态表单已覆盖，CLOSED |
| **TD-105** | `home()` en 尾斜杠致 canonical / WebSite.url 指向会 301 的 /en/、与 sitemap loc 矛盾 | **P-STEP 18J-3** | PublicUrl::home() zh/en 分流（zh=base/、en=base/en 无尾斜杠）；en canonical / WebSite.url / 首页链接 = /en，与 /en 200、/en/ 301、sitemap loc 一致；真实 HTTP 复测通过 |
| **TD-106** | 站点级 @id 引用断裂：内页 isPartOf、geo org @id·same_as 偏离全局锚点 base/#website·#organization | **P-STEP 18J-3** | 新增 PublicUrl websiteAnchor()/organizationAnchor()；webPage isPartOf、geo org @id·same_as 全部引用全局锚点；实测 en ItemPage.isPartOf=base/#website、geo org @id=base/#organization，与 WebSite/Organization 节点匹配 |
| **TD-107** | en-only / 默认语言非 zh 站点根 / 不按 site_default_locale 渲染、误 404 | **P-STEP 18J-3** | SetLocale 根级默认资源按 site_default_locale 解析（不硬取 zh-CN）；Site B / 200 渲染英文首页、zh 默认站 / 仍中文、其余路径语言不支持仍 404 |
| **TD-108** | CLI page-cache:clear 只清默认站、非默认站版本不 +1；locale Setting 变更失效 | **P-STEP 18J-3** | ClearPageCache 遍历全部站点、withSite 逐站 flush 并逐站输出版本（实测两站版本均 +1）；locale Setting 失效经核实已由 AppServiceProvider 统一 saved→flush 覆盖，撤回 Setting 模型多余改动（delta 归 1） |
| **TD-109** | en-only 站根 robots.txt·sitemap.xml·llms.txt 默认位置 404、无任何 robots 可用；robots 漏声明多语 sitemap | **P-STEP 18J-3** | robots.txt 移至 locale 组外注册（语言无关、根位置恒 200）并列出各启用语言 sitemap；根级 sitemap/llms 在路由默认语言 zh-CN 不被站点提供时按 site_default_locale 渲染（显式 /en/* 不支持仍 404）；实测 en-only 根约定文件 200 且内容英文、双语 robots 声明两套 sitemap、zh-only /en/* 仍 404 |
| **TD-111** | 前台「蓝品牌 + 绿 CTA」混搭：18D 决策让 Action/CTA 默认从独立 accent（绿）派生，与品牌蓝形成双主色；部分组件疑似绕过 Active Theme Token | **P-STEP 18K** | 裁定第三配色方向 Brand-led + Action-aligned + Accent-controlled：新增 Action 簇且 action=brand、CTA 由品牌基色自动派生（mix 黑 4%）、accent 降级为小面积点缀（默认蓝灰 #64748B）、success 固定独立绿；hex 扫描确认彩色 100% 走 var(--*)、无组件绕过 Token；新增 VisualConformance18KTest 6 测试锁定；陌生品牌品红 #BE185D 四组合实测 brand/action/cta 全品红、真实按钮 rgb(182,23,89) |
| **TD-112** | 后台启用 English（json 字段 site_supported_locales）保存 500：Controller 提前 json_encode 数组再经 Setting.value json cast 双重编码，applySetting 对读回数组 (string) 强转触发 Array to string conversion | **P-STEP 18K-05** | json 字段全程保持数组（$value=$decoded，与 DefaultSettingSeeder 传数组一致）、校验加 is_string 守卫、applySetting 加 type 参数并对 json 用规范化 JSON 比较；Setting::get 读回为数组 ['zh-CN','en']、/en 200；新增 test_json_locales_persist_as_array_without_double_encoding 锁定 |
| **TD-113** | color 类型设置无法在后台清空回退默认：HTML type=color 不支持空值，清空被浏览器重置 #000000 并显式保存，致 --accent 变黑 | **P-STEP 18K-05** | color input 空值用占位灰 #E5E7EB + 「回退默认」clear_color[] checkbox；Controller color 分支：勾选清除置空、原始为空且未改占位灰保持空；accent 清除后 DB=''、前台回退蓝灰；新增 test_color_placeholder_stays_empty_and_clear_checkbox_falls_back 锁定 |
---

## 6. NON-DEBT / 有意保留白名单（不是债，禁止当作污染清理）

| 项 | 保留理由 |
| --- | --- |
| 4 个 RETIRE 设置（site_short_name / site_slogan / sync_geoflow_endpoint / sync_pull_enabled） | 17F 裁定无 consumer，SettingController 黑名单 const 标记；不设 is_retired 列、不删 migration |
| 历史 migration | 工程历史真实性与升级路径，不为"全仓 0 词"伪造修改 |
| `docs/audit/**`（含 docs/audit/history） | Historical Audit Exemptions，release archive export-ignore，不进发布包 |
| tests 负向护栏断言 | 主动断言旧业务词/旧 slug **不得**出现，是防回归资产 |
| 口径 C 通用行业词（食品/制造/OEM/ODM/工业涂料/胶粘剂/装备制造/工厂/车间/产能/打样/配方等） | 无法单独指向原客户，按口径 C 保留；强身份词必须清零 |
| `config/facts.php` | 现仅作**安装期 Example Seed 来源**（P14 已降级，Runtime 不消费）；随 TD-07 演示数据完全 Entity 化后退场 |
| 「示例制造有限公司」等 Example Demo 数据 | 通用虚构示例企业，与真实客户无语义关联；18B 将其与系统默认层分离 |
| 「LocaleContext 跨请求泄漏」疑似 P0（en 预热后 zh /geo.json 被初判含英文） | 经 SetLocale trace（每请求正确 set / finally clear）+ json_decode 各层（全中文）+ 编码检查三重查证为**误判**：中文在 JSON body 被 Unicode 转义（字面 str_contains 必然 N），「Example Manufacturing」命中实体 metadata 的 `name_en` 字段而非英文 GEO；TD-49 修复转义后断言恢复 |

---

## 7. Release Gate 视图（发布时只看本节）

> 下表 `Blocks v1.0.0?` 为当前**工程建议基线**，最终以用户 Release Gate 裁决为准；裁定结果回写本列并记入 §8。

### v1.0.0 Required（公开发布前须 CLOSED 或完成 DECISION）

| 项 | 类别 | 计划阶段 |
| --- | --- | --- |
| TD-01 GitHub Actions 云端首跑全绿 | P0 | Private GitHub + Cloud CI |
| TD-02 基于最终 HEAD 重建干净 RC + Manifest + SHA-256 | P0 | Final RC |
| TD-03 Private 全验证 → 授权转 Public / v1.0.0 | P0 | Release |
| TD-53 Block Registry | P1 | P-STEP 18G-1 |
| TD-54 Template Registry | P1 | P-STEP 18G-1 |
| TD-55 Page / Landing Model | P1 | P-STEP 18G-1 |
| TD-56 Detail Composition（Article/Product/Service） | P2 | P-STEP 18G-2 |
| TD-57 Listing Composition | P2 | P-STEP 18G-2 |
| TD-58 Page Composition Manager | P2 | P-STEP 18G-1 / 18G-2 |
| ~~TD-59 完整 Form Builder（forms/form_fields + FieldTypeRegistry + Submission/Inquiry + notification）~~ ✅ CLOSED by 18H-2 | P3 | ✅ P-STEP 18H-2 |
| TD-61 系统页 SEO 统一（Resolver 单一来源） | P0 | P-STEP 18G-2a |
| TD-62 Detail Resource Renderer | P1 | P-STEP 18G-2a |
| TD-63 grid current/related 上下文 | P2 | P-STEP 18G-2a/2b |
| TD-64 site-level SEO page_id 边界 | P2 | P-STEP 18G-2a |
| ~~TD-71 SearchEngine 契约 / 接口~~ ✅ CLOSED by 18H-1 | P1 | ✅ P-STEP 18H-1 |
| ~~TD-72 FTS5 默认引擎（ranking/DB 分页/highlight）~~ ✅ CLOSED by 18H-1 | P1 | ✅ P-STEP 18H-1 |
| ~~TD-77 Form Submission 层（payload 全量事实源 → Inquiry 投影）~~ ✅ CLOSED by 18H-2 | P1 | ✅ P-STEP 18H-2 |
| ~~TD-73 Analytics / 转化集成~~ ✅ CLOSED by 18H-3 | P2 | ✅ P-STEP 18H-3 |
| ~~TD-74 AuditLog 覆盖~~ ✅ CLOSED by 18H-3 | P2 | ✅ P-STEP 18H-3 |
| ~~TD-105 home() en 尾斜杠~~ ✅ CLOSED by 18J-3 | P1 | ✅ P-STEP 18J-3 |
| ~~TD-106 站点级 @id 引用断裂~~ ✅ CLOSED by 18J-3 | P1 | ✅ P-STEP 18J-3 |
| ~~TD-107 en-only 根路径 locale 渲染~~ ✅ CLOSED by 18J-3 | P1 | ✅ P-STEP 18J-3 |
| ~~TD-108 多站缓存清理 + locale 失效~~ ✅ CLOSED by 18J-3 | P1 | ✅ P-STEP 18J-3 |
| ~~TD-109 根级约定文件可达性 + robots 多语 sitemap~~ ✅ CLOSED by 18J-3 | P1 | ✅ P-STEP 18J-3 |

> **18H-3** 已 CLOSED TD-73（Analytics）/ TD-74（Audit）及 TD-88..TD-92，真实发现并修复 TD-93（analytics 路由）/ TD-94（settings 审计）/ TD-95（form_submit 标识）；第三方 ID 全 Site-scoped、Basic Consent、受控动态 CSP、HeadCodeSanitizer、AuditSnapshot 脱敏 before/after。
> **v1.0 Required 未闭合 = 3**：仅 P0×3 外部发布工程（TD-01/02/03）。TD-75 / TD-76 / TD-79 / TD-87 转 v1.1。

### v1.1+ Planned（不阻塞 v1.0.0，须有明确验收条件）

| 项 | 类别 |
| --- | --- |
| TD-06 Content 路径收敛（现有 301 桥接可用） | P1 |
| TD-14 RBAC 三角色 + 站点成员 | P3 |
| TD-15 校验 i18n + 字段级 @error | P3 |
| TD-16②③④ Category type/外链/栏目 SEO | P3 |
| TD-17 友好 500 页 | P3 |
| TD-18 Theme/Plugin 上传安装 / SDK / 市场 | P3（v1.x） |
| TD-19 旧概念 IA 重命名（token 已中性化） | P3 |
| TD-20②③④ md-editor 上传 / 搜索召回 Entity / 英文召回 | P3 |
| TD-23 PHPUnit 12 attribute 迁移 | P4 |
| TD-24 PageCache file store 回收 | P4 |
| TD-27 GeoflowApiTest 测试顺序隔离加固 | P4 |
| TD-36 组件 loading / aria-busy 模式 | P3 |
| TD-37 产品列表移动系列卡密度优化 | P3 |
| TD-38 example 极简主题对齐 / 能力边界标注 | P3 |
| TD-46 factory/cooperation IA 与 URL 命名制造业特定（数据驱动 404，v1.1 中性化） | P3 |
| TD-75 Entity/Page Revision（V1 仅 Content 有版本） | P3 |
| TD-76 logo media ID 统一 + responsive srcset | P3 |
| TD-79 Page/Landing 纳入搜索索引（系统页按需排除） | P2 |
| TD-87 Core migration 000011 首页 builder 默认 items 静态制造业字符串（运行时不可达，随 TD-70 中性化） | P1 |
| TD-103 Hero 多 slide 轮播 / 分屏 slideshow（通用 Gallery/Carousel block） | P3 |
| TD-104 Feature / 网格 item 自定义配图（item_fields 增加 media_id） | P3 |
| TD-110 主题在线安装 / ZIP 上传 / Theme SDK / Marketplace（V1.0 仅「文件分发 + 后台激活/切换/预览/回滚」，细化 TD-18 的主题部分） | P3 |

### 计数（当前）

- CLOSED：#86、**#114（18A+18C）**、**#143 / TD-09**、**#144**、TD-04、**TD-05（DECISION）**、**TD-07**、TD-08a / **TD-08b（TD-08 整体）**、**TD-16①**、**TD-20①**、TD-10、TD-11、TD-12、TD-13、TD-25、TD-26、P17 六管理面 + 17G、**TD-28..TD-35（18D Design System）**、**TD-39..TD-41（18E 能力对账）**、**TD-42..TD-45（18F 本地化）**、**TD-47..TD-48（18F 路由守卫 / Entity 搜索）**、**TD-49..TD-50（18F geo.json 直出 / en-sitemap 首页 locale）**、**TD-51..TD-52（18F Gate）**、**TD-53/54/55（18G-1 三层 + Landing）**、**TD-60（18G-1 Gate）**、**TD-62/63/64/65（18G-2a Detail renderer / grid context / site-level SEO 索引 / indexable locale）**、**TD-67（18G-2a 外观切换 aria 键）**、**TD-56/57/58/61/66（18G-2b Listing·系统页·Manager·locale SEO）**、**TD-68/69（18G-2b canonical / hreflang）**、**TD-71/72（18H-1 搜索契约 / FTS5）、TD-78（18H-1 flushDirty 站点上下文）、TD-80/TD-81（18H-1 Catalog 半成品 memo / 跨类 SiteContext 污染）、**TD-59/TD-77（18H-2 完整 Form Builder / Submission 层）、TD-82/TD-83/TD-84/TD-85/TD-86（18H-2 payload 编码 / forms.index / validation 语言包 / 英文 action / block PUT）、TD-73/74/TD-88~95（18H-3 Analytics + Audit）、**TD-15（18H-2 校验语言包 + 字段错误）、TD-20③④（18H-1 Entity·英文召回）、TD-70 / TD-99 / TD-100 / TD-101 / TD-102（18I 首页 Composition / 新站初始化 / contact 守卫 / 搜索页文案 / sitemap contact 收录）、**TD-105~TD-109（18J-3 home() 尾斜杠 / 站点级 @id / 根路径 locale / 多站缓存清理 / 根级约定文件可达性）、**TD-111/112/113（18K 配色 Brand-led / json 设置保存 / color 回退）**
- v1.0.0 Required 未闭合：**3** = 仅 P0×3 外部发布工程（TD-01 / TD-02 / TD-03）。TD-105~TD-109（URL / 缓存 / 约定文件一致性）已全部 **CLOSED by 18J-3**；TD-73 / TD-74 / TD-88~95 已 CLOSED（18H-3）；TD-75 / TD-76 / TD-79 / TD-87 / TD-103 / TD-104 DEFERRED v1.1
- v1.1+ Planned：TD-06（/article/ 收敛）、TD-14、TD-16②③④、TD-17、TD-18、TD-19、TD-20②、TD-23、TD-24、TD-27、**TD-36、TD-37、TD-38**、**TD-46**、**TD-75（Entity/Page Revision）、TD-76（logo media ID + srcset）、TD-79（Page/Landing 纳入搜索索引）、TD-87（Core migration 000011 静态制造业字符串，运行时不可达，v1.1 清理历史 migration）、TD-103（Hero 多 slide 轮播）、TD-104（item 自定义配图）、**TD-110（主题在线安装 / ZIP 上传 / SDK / Marketplace）**
- NON-DEBT / DEFERRED 观察项：TD-21 / TD-22 / TD-23 / TD-24 / TD-27

---

## 8. 维护规则与变更日志

**维护规则**
1. 每个阶段 Gate 必须更新本台账：Status、证据（commit/tag/测试数/HTTP 对拍）、Blocks 列裁定。
2. 销项严格走 §2 状态机；CLOSED 移入 §5，不删表行。
3. 新发现债务先分配新 `TD-NN`（续号），写清 Source 与 Acceptance Criteria，再开始修。
4. 父级 Epic（#86/#114/#115）只在其全部子项 CLOSED/裁定后才标 CLOSED。
5. 本文件与代码同仓、随阶段提交；它是发布 Gate 的唯一对账基线。

**Changelog**

| 日期 | 阶段 / commit | 变更 |
| --- | --- | --- |
| 2026-09-22 | P-STEP 18A（`4dbc95a` / `checkpoint-18A`） | 建立唯一 Registry；汇总 16A/17F/17G/P14/蓝图散落债务为 TD-01..TD-24 + Epic #86/#114/#115；#86 与 TD-04、TD-08a 登记 CLOSED；#114 标 PARTIAL；#115 标 ACTIVE；锁定 v1.0.0 Required / v1.1 Planned 建议基线 |
| 2026-09-22 | P-STEP 18B（`checkpoint-18B`） | #115 子项 TD-10/11/12/13 全部 CLOSED：出厂 Blank System 与 db:seed Demo Site 分离（BlankHomepageSeeder / Demo StructureSeeder）、Site.name 单一事实源、图标 registry 中性化；两态 Fresh Install + HTTP/浏览器对拍；新发现并修复 TD-25（PageCache 键不含端口致同机异端口串整页）、TD-26（SQLite getTableListing 返回 main. 限定名致空站删除保护失效），各补防回归测试；v1.0 Required 12→9 |
| 2026-09-22 | P-STEP 18C（`checkpoint-18C`，826/4139/0/0） | Release Residual Audit：TD-05 Entity URL 体系书面冻结（DECISION）、TD-07 Organization 裁定 Site 聚合为唯一事实源（facts 降为安装期种子、前台零消费）、TD-08/08b Entity·Site 缓存失效+stale memo、TD-09/#143 声明性绝对 URL 全改派 PublicUrl（功能性 URL 显式保留 url()/asset()，sitemap loc 与首页 canonical 斜杠契约分离）、TD-16① Category slug 站点作用域+type 收敛、TD-20① RSS geo_rss_enabled 门禁（设置 64→65）全部 CLOSED；#114 v1.0 收口；#144 单一事实源反向审计无新双源；TD-06 /article/ 书面 DEFERRED v1.1；新登记 TD-27 GeoflowApiTest 测试顺序依赖（P4，CI 固定顺序绿）；fresh geo:install settings=65、空站/Demo 两态真实 HTTP 对拍；**v1.0 Required 未闭合 9→3（仅 P0 TD-01/02/03 外部发布工程）**；报告 `docs/audit/release-residual-audit-18c.md` |
| 2026-09-23 | P-STEP 18D（`862ff1d` → 收尾提交 / `checkpoint-18D`，857/4574/0/0） | Final Product Completeness 第一阶段 Design System 2.0：新增 TD-28 Light/Dark/System 深色（独立深色令牌 + AA 提亮 + SSR 防闪 + 记忆）、TD-29 行业预设扩 8 类（finance/healthcare/Consumer，只改视觉不改 IA）、TD-30 12 档 rem 字阶 token（结构性 27 处 + 辅助 82 处归并）、TD-31 彩色硬编码清零（彩色 hex/rgba=0）、TD-32 全局 focus-visible 焦点环 + disabled、TD-33 `.ph-h` 移动缩小（CSS 源顺序根因）、TD-34 aria-required、TD-35 geo:upgrade 部署清 view/PageCache，全部 CLOSED；新登记 DEFERRED TD-36（loading）、TD-37（移动系列卡密度）、TD-38（example 极简主题对齐）；Blank System ≠ Demo Site 视觉再确认；v1.0 Required 未闭合仍为 3（TD-01/02/03），18D 未新增发布阻塞；报告 `docs/audit/design-system-final-audit.md` |
| 2026-09-23 | P-STEP 18E（收尾提交 / `checkpoint-18E`，861/4590/0/0） | Frontend ↔ Backend Capability Closure 能力对账：通读首页 16 区块 + Header/Footer/导航 + 全部列表/详情/表单/关于页 + Catalog 投影 + 组件，确认绝大多数前台元素数据驱动、空则隐藏；发现并最小修复 TD-39（factory 部分生产事实裸输出 0：H1/stats/SEO 按数据拼接）、TD-40（_bottom_cta 电话行门控）、TD-41（solutions「你的店」→「你的业务」），新增 FrontendBackendClosure18ETest 4 用例（16 assertions）；疑似 phone.invalid 缺失经核实 `Copy::form()` 组装层已兜底（不读 config 该键），判 NON-DEBT、撤销对 config/copy.php 的多余改动；后台字段 consumer 反查复用 17A–17G / 17F 64 键矩阵结论；v1.0 Required 未闭合仍为 3（TD-01/02/03），18E 未新增发布阻塞；产出 `frontend-backend-capability-matrix.md`、`hardcoded-capability-register.md` |
| 2026-09-23 | P-STEP 18F（`checkpoint-18F`） | Localization 前端 zh-CN + en：Locale Registry / SetLocale / URL（zh 无前缀、en /en）/ 同表多行 translation_group 翻译模型 / 双语 feed / hreflang / 本地化 Schema·GEO·Sitemap·Search；Multi-Site × Locale：en-only Site B 验证 zh 404、en 200、双向隔离；发现并修复 TD-42（Catalog 硬依赖 zh-CN org → baseLocale 回退 + nullable）、TD-43（area_served ??）、TD-44（blank 描述单语泄漏 → 优先 Catalog company summary）、TD-45（knowledge/products SEO 翻译键工业措辞中性化），TD-47（路由 PublicUrlLocalized 重复声明 fatal → function_exists 守卫）、TD-48（搜索补齐 Entity：SearchResult + 合并 entityQuery），均 CLOSED；新登记 TD-46（factory/cooperation IA 制造业命名，数据驱动 404 不暴露，DEFERRED v1.1）；收尾另修 TD-49（geo.json 中文/URL Unicode 转义 → JSON_UNESCAPED 直出 + GeoGraph 防回归）、TD-50（英文 sitemap 首页 loc 缺 /en → locale-aware + 断言）；「LocaleContext 跨请求泄漏 P0」经三重查证裁定 NON-DEBT（误判）；v1.0 Required 未闭合仍为 3（TD-01/02/03）；报告 `docs/audit/localization-final-audit.md` |
| 2026-09-23 | P-STEP 18F **Gate Validation**（`checkpoint-18F`，**872/4655/0/0**） | 对切换设备前会话产出的实现 `d886d5d` 做独立验收（流程异常已记录，≠直接认可 PASS）：Focused 9/42、Full 872/4655、Fresh blank/demo install、Blank/Demo HTTP、Browser 四组合（zh/en × light/dark）、Multi-Site × Locale、PageCache zh↔en 内容对拍全过；真实链接/渲染审计发现并修复 **TD-51**（en 内部链接缺 /en：PublicUrl 显式改约 8 处 + GeoUrlGenerator `withLocalePrefix()` 兜底 + 防回归测试）、**TD-52**（en 可见/属性 UI 中文残留：config/copy ariaLabels 中文值改 null + logo `$brandDisplayName` + tel/eyebrow/空状态/subnav 翻译键 + 字典补键），均 CLOSED，en 7 页渲染 visible/属性 CJK=0；唯一 `local.ERROR` 系本轮 tinker 命令被 shell 剥离双引号的 ParseError（命令构造问题、非产品缺陷，无引号写法重跑成功）；serve(8111/8112)/端口/临时 sqlite/smoke 缓存全清；v1.0 Required 未闭合仍 3（TD-01/02/03），18F 未新增发布阻塞 |
| 2026-09-23 | P-STEP 18G-1 START（Discovery ACCEPTED / 实现授权） | 登记父 Epic **#116** Page Composition / Template System 与 **TD-53..TD-59**：TD-53 Block Registry、TD-54 Template Registry、TD-55 Page/Landing（P1，18G-1）；TD-56 Detail、TD-57 Listing Composition（P2，18G-2）；TD-58 Page Composition Manager（18G-1 PARTIAL→18G-2）；TD-59 Form Block（18G-1 最小 FormReference，完整 Form Builder→18H）。v1.0 Required 未闭合 3→10（Page Composition 7 项在 18G/18H 闭合，P0×3 仍待外部授权） |
| 2026-09-23 | P-STEP 18G-1 Gate Validation（进行中） | 真实浏览器 UAT 发现并修复 **TD-60**：layout 主脚本 `{{ json_encode() }}` 二次转义 `&quot;` 致全站前台 JS SyntaxError、IntersectionObserver 不建立、`.reveal` 永久 opacity0；改 `{!! json_encode() !!}` + 防回归（Focused 26/78）；console 零错误、feature_grid 滚动自然显现。v1.0 Required 未闭合仍 10（TD-60 发现即 CLOSED，不新增阻塞） |
| 2026-09-23 | P-STEP 18G-2 Discovery ACCEPTED / 18G-2a AUTHORIZED | 用户拍板**路线 A（统一 Render Context：Page/Entity/Listing 共用 Template·Block·Resolver·Schema·Url·Cache）**、**Detail 方案 ii（`pages.entity_id` nullable，Entity 直驱固定槽 + Page 覆盖可组合槽，不复制数据）**、两次 Gate、固定系统页全 Page 化；登记 **TD-61（P0 系统页 SEO 双轨）/ TD-62（P1 Detail 资源渲染器）/ TD-63（P2 grid current·related）/ TD-64（P2 site-level SEO page_id）**；v1.0 Required 未闭合 10→14（新增 4 项代码层 Detail 收口，计划 18G-2a 闭合；P0×3 外部工程仍待授权）；Discovery 产出 page-composition-migration-discovery/architecture-18g2.md |
| 2026-09-24 | **P-STEP 18G-2a Gate ACCEPTED / PASS** | Product/Service Detail 迁入统一 Render Context（EntityRenderContext + CompositionRenderer），entity-level SeoMeta 前台消费；新增 5 system block、grid all/current/related、pages.entity_id + 模型 cascade、migration 000014（sites_seo_meta_unique 排除 page_id）；**CLOSED TD-62/63/64**，新发现修复 **TD-65**（indexableEntitySlugs locale）CLOSED、登记 **TD-66**（SeoMeta 编辑不跟随 locale，en SEO 覆盖无法管理）ACTIVE；TD-61/TD-56 PARTIAL（Detail 收口，Listing/系统页/Article 待 2b）；回归 **919/4798/0/0**；v1.0 Required 未闭合 14→9（含 P0×3 外部）；另真实浏览器 a11y 复验发现并修复 **TD-67**（外观切换按钮 aria-label/title 键 mode_aria_toggle 缺失）CLOSED，不增 Required |
| 2026-09-24 | **P-STEP 18G-2b Gate ACCEPTED / PASS** | Listing + 固定系统页全部迁入 Composition：SystemPageRenderContext（9 system_key is_system Page，geo:install 注入 18 行双语 + contact 3 block）、ListingRenderContext（grid/filter/pagination）；SeoMetaController locale 重写（TD-66）、Block CRUD 完整（duplicate/preview，TD-58）、13 orphan blade 退休；**CLOSED TD-56/57/58/61/66**，新发现并修复 **TD-68**（PublicUrl base 致 /en/en 重复 canonical）、**TD-69**（英文页 hreflang zh-CN/x-default 错指 /en），各补防回归；**TD-70**（首页旧装修器未迁 pages Composition）DEFERRED v1.1；父 Epic **#116 v1.0 收口**；回归 **950 / 4873 / 0 / 0**；v1.0 Required 未闭合 9→4（P0×3 外部 + TD-59 完整 Form Builder 18H） |
| 2026-09-24 | P-STEP 18H **Discovery**（STOP，待裁定；HEAD 仍 `bf3d576`、worktree clean，未改产品代码） | 一次性盘点 Search / Media / Forms·Inquiry / Analytics / Audit·Revision / Cache / Performance / SEO·GEO；**FTS5 实测可用（sqlite 3.53.4）**；登记 **TD-71（SearchEngine 契约/接口）/ TD-72（FTS5 默认引擎）/ TD-73（Analytics/转化集成）/ TD-74（AuditLog 覆盖缺口）** 为 V1 Required，**TD-75（Revision 仅 Content）/ TD-76（logo 双轨·无 srcset）** DEFERRED v1.1；TD-59 验收标准细化（Form/FormField、字段类型 registry、动态验证、Inquiry payload、consent、notification、FormReference 引用 form_id）；**v1.0 Required 未闭合 4→8**（外部 P0×3 + 18H 代码 5）；产出 `operations-product-discovery-18h.md`、`operations-product-architecture-18h.md`，建议拆 18H-1/2/3 各自 Gate/STOP |
| 2026-09-24 | **P-STEP 18H-1 Gate ACCEPTED / PASS**（tag `checkpoint-18H-1`） | Search Productization：Search Contract v1（SearchQuery/SearchResults/SearchResult、Highlighter）+ SearchEngineInterface 容器延迟绑定；迁移 search_documents（原文）+ search_index（FTS5，CJK bigram/unigram）；SearchIndexBuilder（派生非事实源）+ SearchIndexSync（增量 + dirty 懒重建）+ SqliteFtsEngine（bm25 title10/summary5/body1、DB 分页、安全高亮）/ DatabaseLikeEngine 回退；search:reindex + GeoInstall/Upgrade 接线；SearchController·search.blade 走引擎。**CLOSED TD-71/TD-72**；测试发现并修复 **TD-78**（flushDirty 不切 SiteContext 致跨上下文重建唯一冲突 → withSite 包裹）、**TD-80**（Catalog 半成品 memo → 搜索派生后 flush）、**TD-81**（跨测试类 SiteContext 污染 → TestCase 统一 tearDown）；新增 **TD-77**（Form Submission 层，18H-2）ACTIVE、**TD-79**（Page/Landing 纳入索引）DEFERRED v1.1；SearchProductization18HTest 18 测试；v1.0 Required 未闭合 8→7（外部 P0×3 + TD-59/77/73/74） |
| 2026-09-24 | **P-STEP 18H-2 Gate ACCEPTED / PASS**（tag `checkpoint-18H-2`） | Form / Submission / Inquiry Productization：forms + form_fields（每 locale 行，结构列跨语言同步、展示列翻译）+ form_submissions（payload JSON 全量事实源）+ InquiryProjector 确定性投影 + 通知（默认关、DB commit 后发、失败只 warning 不丢数据）+ FieldTypeRegistry 11 类型（服务端验证最终裁决）+ FormReference form_id + /inquiry 兼容桥接；DefaultFormSeeder 幂等中性 contact；#258 demo 去行业化（contact 不再含代工 / 原料采购 / 经销，FAQ slug→customized-solutions-faq）。**CLOSED TD-59 / TD-77**；测试发现并修复 **TD-82**（payload 双重编码 → service 传 array）、**TD-83**（forms.index 路由）、**TD-84**（validation.php 语言包）、**TD-85**（英文 action 缺 /en → localized_route helper，Jane Doe 验证）、**TD-86**（block_form 缺 @method PUT → 修复 + PageComposition 防回归，李四 Download sub#4 验证）；新登记 **TD-87**（Core migration 000011 静态制造业字符串、运行时不可达）DEFERRED v1.1；config/facts.php cooperation OEM / 经销裁定为 Example demo 正当内容（NON-DEBT，随 TD-70 v1.1 收口）；零代码 Download 表单 + Landing（page 19 / block 41）实测闭环；v1.0 Required 未闭合 7→5（P0×3 外部 + TD-73 / TD-74 留 18H-3） |
| 2026-09-24 | **P-STEP 18H-3 Gate ACCEPTED / PASS**（tag `checkpoint-18H-3`） | Analytics + Audit Operations Closure：Site-scoped analytics 设置组（GA4/GTM/Meta 默认关）+ GeoAnalytics 事件层（5 事件）+ Basic Consent（未同意不加载、拒绝零请求、gwos-consent）+ 受控动态 CSP（仅启用 provider 放行，无 unsafe-inline / strict-dynamic）+ HeadCodeSanitizer（seo_head_code 仅 meta/link 白名单）；AuditSnapshot（白名单 / 脱敏 / 归一化）+ recordChange，Entity/SeoMeta/Page/Block/Form/Theme/Plugin 全补审计、detail.changes 带 before/after，AuditLog≠Revision（TD-75 不重开）。**CLOSED TD-73/TD-74**，登记并 CLOSED TD-88..TD-92，真实后台 / 提交发现修复 TD-93（analytics 路由）/ TD-94（settings 审计 before/after）/ TD-95（form_submit data-form-id/slug）；新增 AnalyticsConsentCsp18H3Test（15）+ AuditCoverage18H3Test（12）；v1.0 Required 未闭合 5→3（仅 TD-01/02/03 外部工程） |
| 2026-09-24 | P-STEP 18H-3 **Gate Validation（独立复验）** | 修复前全量 3 failed：AdminSeoMetaCrud ×2（destroy 审计点误引用 `$seo`、参数实为 `$seoMeta` → HTTP 500）＝**TD-96** 已修为 `{$seoMeta->title}`；SiteIdCoreTables Setting 计数 73→80（analytics +7、demo +1）随产品新增＝NON-DEBT；focused 两文件 **42 passed / 203 assertions**；修复后全量 **1014 passed / 5169 assertions / 0 failed / 0 skipped（880.24s）**；Fresh install（中性 contact form + blank homepage + search index）、Blank HTTP（默认 CSP 最小、零第三方脚本/请求、空站门禁 404→建 organization 后 200 闭环）、Demo 中英文核心页 + 全部 feed（sitemap/llms/feed/geo/robots）200、英文 contact SEO/canonical/hreflang/Schema(inLanguage=en) 对拍；Runtime 权威目录污染=0、验证窗口无产品 ERROR（12 条历史 ERROR 均为更早已修 blade/工具误调用）；TD-96 CLOSED |
| 2026-09-24 | P-STEP 18H-3 **Evidence Supplement**（首次 Gate HOLD 后补验） | 用户首次裁定 HOLD（仅证明默认全关），补 5 组真实运行时证据：CSP OFF/GA/GA+Meta 对拍（仅启用 provider 放行域）、seo_head_code 7 行混合样本 allow/deny（meta/link 保留、script/style/iframe/onerror/javascript 剔除标 invalid）、consent DENY（零请求记忆）/ACCEPT（gtag/js ok 加载）、dataLayer 事件真实 dispatch（page_view/form_submit 仅 form 标识/download，SECRET 未泄漏）、audit_logs 真实读回（明文/数组 [array:N]/脱敏/无变化降噪）；provider failure isolation（GA collect ERR_ABORTED 全站 200 正常）。真实发现并修复 **TD-97**（consent 按钮被 CTA 委托误捕获 → closest #geoConsentBanner 排除）、**TD-98**（纯敏感字段漏审计 → 原始值判变化再脱敏），各补防回归；focused 29/103；全量见 Gate 文档 §13.10；网络环境（googletagmanager/facebook 此刻可达、google-analytics collect 不可达）如实记录 |
| 2026-09-25 | **P-STEP 18I Gate ACCEPTED / PASS**（`a3ca4ad` / `checkpoint-18I`，993/5112/0/0） | 蓝图 18 项对拍 17 MATCH / 1 PARTIAL（RBAC 增强 TD-14 DEFERRED v1.1）/ 0 MISSING；陌生品牌 Aurora Living 零代码建站 Browser UAT；**TD-70 首页 Composition 拉回 v1.0 CLOSED**，新发现修复 TD-99（Admin 新站初始化 Composition 页）/ TD-100（blank /contact 安全降级 200）/ TD-101（SearchController 硬编码改翻译键）/ TD-102（sitemap 无条件收录 /contact/），各补防回归；Upgrade/Backup/Rollback PASS；Runtime 污染 0、日志无新增 ERROR；v1.0 Required 未闭合仍 3（TD-01/02/03） |
| 2026-09-25 | P-STEP 18J-1 Test Inventory Delta（进行中） | 对账 1016→993（-23）：方法数 879→856、文件 101→97；删旧首页装修器测试 29（BannerSlot2/BlockItemImage3/HeroMode14/HomeBlockItems5/HomeBuilder5，随 TD-70 拆除）+ 新增 HomeComposition18ITest 6 + 修改净 0（SeoHeadComposer+1/SolutionPage-1）；意图由 Composition 测试群继承，测试面未悄然缩小；两处能力收敛登记 TD-103 / TD-104 DEFERRED v1.1；产出 `test-inventory-delta-18j.md` |
| 2026-09-25 | **P-STEP 18J-3 最终全链路复核 CLOSED**（待 Gate） | 真实 HTTP / JSON-LD / geo.json 对拍发现并修复 5 项 URL·缓存·约定文件缺陷，全部 CLOSED：**TD-105**（home() en 尾斜杠→zh/en 分流）、**TD-106**（站点级 @id 断裂→新增全局锚点方法）、**TD-107**（en-only 根 / 误 404→按 site_default_locale 渲染）、**TD-108**（CLI 清缓存只清默认站→遍历逐站 flush；locale Setting 失效经 AppServiceProvider 覆盖、撤回模型多余改动）、**TD-109**（en-only 根 robots/sitemap/llms 404→robots 组外注册恒可达 + 根级 feed 按站点默认语言渲染 + robots 声明多语 sitemap）；实测 en-only 根约定文件 200 且内容英文、双语 robots 声明两套 sitemap、zh-only /en/* 仍 404、zh/en 缓存交替不串；v1.0 Required 未闭合 7→3（仅 P0×3 外部发布工程） |
| 2026-09-25 | **P-STEP 18J Gate ACCEPTED / PASS**（tag `checkpoint-18J`） | Final Product Acceptance 最终签收：产品能力矩阵 22 个能力域全部 CLOSED、四层 / 单一事实源成立、最终质量指标（蓝图 §40）全部为 0；Browser 四组合 zh/en × Light/Dark 全过、Console 0 error；全量回归 **993 / 5118 / 0 failed / 0 skipped**（断言 5112→5118，+6 来自 Localization18FTest 根级契约对齐：en-only 根级默认资源按站点默认语言 200、显式非根 zh 路由仍 404，focused 10/55）；v1.0 **产品代码未闭合 = 0**，仅剩 P0×3 外部发布工程 TD-01/02/03；产出 `final-product-acceptance.md`、`test-inventory-delta-18j.md`；PASS 后 STOP，不自动进入 19A
| 2026-09-25 | **P-STEP 18K Gate**（tag `checkpoint-18K`） | Theme Installation + Visual Conformance Closure：18K-01 主题生命周期（文件分发主题的发现 / 激活 / 切换 / 请求级预览 / 回滚 / 不存在主题 404 无半激活）独立 fresh-install 库实测；8 行业预设 zh/en × light/dark 四组合矩阵 PASS；18K-05 陌生品牌 Northwind Labs 全程 Admin UI 零代码改名 / 启用 en / 品牌基色品红 #BE185D，前台 brand/action/cta 全品红、accent 蓝灰点缀、success 固定绿、真实按钮 rgb(182,23,89)。新增 VisualConformance18KTest（6/116）；**CLOSED TD-111**（配色 Brand-led 第三方向）/ **TD-112**（json 设置 500 双重编码）/ **TD-113**（color 无法清空回退），**TD-110**（主题在线安装 / ZIP / SDK / Marketplace）DEFERRED v1.1；v1.0 Required 未闭合仍 3（TD-01/02/03）；产出 theme-installation-visual-conformance-18k.md |
