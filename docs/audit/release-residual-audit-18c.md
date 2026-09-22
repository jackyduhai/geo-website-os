# P-STEP 18C — Release Residual Audit（发布残余债务裁定与 Release Gate 反向审计）

- **阶段**：P-STEP 18C — Release Residual Audit
- **起点**：HEAD `92a1981`（= annotated tag `checkpoint-18B`）；Regression 810 tests / 4053 assertions。
- **收口**：见文末 Git 段（commit + annotated tag `checkpoint-18C`）。
- **目标**：以 v1.0.0 代码冻结为目标，把技术债台账剩余 v1.0 Required Debt 逐项裁定（CLOSED / ACCEPTED 或书面 DECISION / DEFERRED），并从最终产品倒推做一次 Release Gate 反向审计（#143 声明性绝对 URL host/scheme 分叉、#144 单一事实源）。
- **边界**：不重构 Core、不升级 Laravel、不新增功能、不处理 RBAC / i18n / 搜索等 v1.1 项；不移动 `v1.0.0-rc1`（仍冻结于 `965d63c`，HOLD）；不配 remote、不 push、不 Release。

---

## 0. Gate 结果总览

| Gate 项 | 结果 | 证据 |
| --- | --- | --- |
| Focused tests（ReleaseResidual18CTest） | PASS | 12 tests / 63 assertions |
| Full regression | **PASS — 826 tests / 4139 assertions / 0 failed / 0 skipped**（406.59s） | `D:\Temp\p18c-fresh\..\p18c-full2.txt`（810 + 新增 16 = 826） |
| Fresh `geo:install -n`（空站） | PASS | settings=**65**、sites=1、site_name=GEO Website OS、entities/contents/categories/page_blocks/menus/facts 全 0 |
| Blank site HTTP（真实 serve） | PASS | `/` 200 title=GEO Website OS，banned-mentions=0；geo/sitemap/llms/feed/robots 全 200 且中性；空站产品页 404 |
| Demo site HTTP（真实 serve，db:seed） | PASS | `/`、geo/sitemap/llms/feed/robots 全 200；core 产品 200、非 core 404、单数 `/product/` 404；service 尾斜杠 301 契约 |
| Feed ↔ HTTP 对拍 | PASS（继承 17G/18A 契约 + 本轮 TD-09 host 锁定） | ReleaseResidual18CTest、FeedPublicRenderContractTest |
| Schema / GEO / SEO | PASS | TD-07 organization 锚点、TD-09 全部 ld+json 无 localhost 分叉 |
| Cache invalidation（TD-08b） | PASS | Entity/Site 写入版本 +1、仅失效本站、Site 改名刷新同进程 memo；4 个 feed 端点不命中整页缓存 |
| Multi-Site A↔B | PASS | ReleaseResidual18CTest 跨 host 断言 + 既有 SiteIsolation / CrossSiteMemoLeak / MultiSiteSwitching 全绿 |
| CLI / SubRequest | PASS | geo:install / db:seed / tinker 真实执行；SubRequest 契约由 17E 起沿用真实前台 HTTP Kernel |
| Runtime business pollution | **0** | 强身份词 Runtime 零命中；tests 仅负向护栏、docs/audit 为历史豁免 |
| Log audit | PASS | storage/logs/laravel.log 零 production.ERROR/CRITICAL/EMERGENCY、零 Exception/SQLSTATE/Deprecated |
| Smoke cleanup | PASS | 临时库/脚本/serve 全部在仓库外 `D:\Temp\p18c-fresh\`；4 个临时 serve 进程已杀；端口 8123–8126 全释放；仓库 database/database.sqlite 为 git-ignored 开发库，未跟踪 |
| Git | PASS | 仅 18C 预期源码/测试变更；commit + annotated tag `checkpoint-18C`；worktree clean |

> PHPUnit 输出 `OK, but there were issues!` 仅指 **85 条 doc-comment metadata deprecation 警告**（既有，TD-23，计划 PHPUnit 12 时迁 attribute），**非失败、非跳过**。

---

## 1. 七项 Required Debt 裁定

### TD-20① RSS `enabled` 独立门禁 —— **CLOSED**

- **问题**：`/feed.xml`（RSS）没有独立 enabled 门禁，与 sitemap / llms 的"设置可控 + HTTP 契约一致"不对齐。
- **落地**：
  - `app/Http/Controllers/Geo/FeedController.php::rss()` 开头读取 `Setting::get('geo_rss_enabled', '1')`，为 `'0'` 时 `abort(404)`，与 sitemap / llms 门禁同标准。
  - `DefaultSettingSeeder`（geo 组）新增 `geo_rss_enabled = '1'`（默认开），设置总数 **64 → 65**；fresh `geo:install` 后 settings 实测 **= 65**。
  - `SettingsGovernanceTest`、`SiteIdCoreTablesTest` 的设置计数断言同步为 65。
- **验证**：空站 `/feed.xml` 200（187B，中性）；Demo `/feed.xml` 200（1144B）；门禁关闭路径由设置治理测试覆盖。

### TD-16① Category slug 站点作用域 + type 漂移 —— **① CLOSED；②③④ DEFERRED v1.1**

- **问题**：Category slug 唯一性未按 `site_id` 约束；`type` 枚举在 migration / model / controller / form 间漂移。
- **落地**：
  - `app/Models/Category.php`：冻结四类型常量 `list / product_list / page / external` + 访问器 `isSinglePage() / isProductList() / isExternalLink() / typesExcludedFromSitemap()`；历史只读别名 `single=page`、`product=product_list`。
  - `Admin/CategoryController`：slug / parent 唯一性改为 `Rule::unique(...)->where('site_id', ...)`，父栏目 `Rule::exists(...)->where('site_id', ...)`；`type` 用 `Rule::in(Category::TYPES)`；external 类型强制 `external_url`。
  - `PageController`、`CanonicalizeSlash`、`SitemapBuilder`、`site/category.blade.php` 统一改用访问器，消除字符串枚举漂移。
  - `AdminCategoryCrudTest` 新增 4 个测试（站点作用域唯一、type 白名单、external 强制 URL、父栏目同站）。
- **保留 v1.1**：③ 外链接线前台体验、④ 栏目 `seo_title/seo_desc` 是否归并 SeoMeta（疑似第三套 SEO）——明确 v1.1，不在 18C 扩范围。

### TD-08b PageCache 失效模型 + SiteContext stale memo —— **CLOSED（TD-08 父项整体 CLOSED）**

- **问题**：Entity / Site 写入后旧整页 HTML 不失效；同进程先解析 Site、后改名时 `SiteContext::currentSite()` static memo 过期。
- **落地**：
  - 集中失效名单在 `AppServiceProvider`（覆盖 Content/Revision/Category/Group/Banner/Menu/PageBlock/Setting/Media/RedirectRule/Fact/SeoMeta；EntityRelation 自带钩子）。本轮补齐：
    - `app/Models/Entity.php`：`booted` 的 `saved` / `deleted` → `PageCache::flush()`。
    - `app/Models/Site.php`：`saved` 内独立 try/catch `PageCache::flush()`；当 `SiteContext::hasSite() && currentSite()->id === $site->id` 时 `SiteContext::setSite($site)` 刷新同进程 stale memo；`deleted` → flush。
  - Site 改名会连带 Setting 镜像钩子多 flush 一次（版本 **+2 而非 +1**），幂等安全；测试用 `assertGreaterThanOrEqual($before + 1)` 断言，不锁死精确次数。
- **验证**：缓存批 90 tests / 560 assertions / 15.4s 全绿（证明无递归、无慢路径）；ReleaseResidual18CTest 锁定：Entity save/delete 版本 +1、Entity 写入**只失效本站不失效 B 站**、Site save 版本 ≥+1 且 `currentSite()` memo 刷新为新名、4 个 feed 端点不命中整页缓存。
- **结论**：TD-08a（18A）+ TD-08b（18C）均 CLOSED → **TD-08 父项 CLOSED**。

### TD-07 Organization 单一事实源 —— **CLOSED**

- **书面裁定**：**Site 聚合是站点主体组织（site-owning organization）的唯一事实源** = `Site.name` + `Setting geo_org_*` + `Site.metadata.organization`。Demo 装载的 organization Entity **不是**主体组织事实源，它只是目录 / 关系图中用于挂 `produces` / `offers` 等边的**节点**。
- **落地**：
  - `GeoGraphBuilder::build()` site 块输出 organization 锚点，`@id = PublicUrl::home() . '#organization'`，与 `SchemaBuilder::organization()` 的 `@id` 完全对齐。
  - `entityNode()`：对 `type=organization && metadata.is_site_organization=true` 的节点输出 `same_as = [home() . '#organization']`，把目录节点与主体锚点关联；**节点自身 id 保持 `entity/organization/{slug}` 不变**，不破坏任何边。
  - `CatalogSeeder`：主体 organization Entity 的 metadata 加 `is_site_organization=true`（Demo 实测 org_flag=true）；普通 organization 节点不输出 same_as。
- **grep 证据（facts Runtime 零消费）**：
  - 前台 Runtime（`app/Http/Controllers/Site/*`、`app/Services/Geo/*`、`app/Support/Catalog`）零 `Facts::`、零 `use App\Support\Facts`。
  - `App\Support\Facts`（读 `config/facts.php`）的唯一消费者是安装期 / Demo 种子：`CatalogSeeder` / `DemoSeeder` / `SettingSeeder` / `StructureSeeder`。
  - `GeoInstall` 只跑 `DefaultSettingSeeder` + `BlankHomepageSeeder`，**不读 facts、不装载 Catalog/Demo**。
  - `config/facts.php` 已通用化（示例制造有限公司 / 工业防护涂料 / 工业胶粘剂 / 双组份结构胶 / 功能添加剂；零Sample Snack / Sample Marinade / Demo Tenant A / Sample City等强身份词）。
- **验证**：ReleaseResidual18CTest TD-07 段：GEO site.organization 锚点对齐 Schema、主体 org 有 same_as 且节点 id 不变、普通 org 无 same_as、跨 host A/B 锚点隔离。

### TD-05 Entity 六类型公开 URL 体系 —— **CLOSED（DECISION 冻结，测试锁定）**

书面 URL 契约（v1.0 冻结）：

| Entity 类型 | 公开 URL | 进 Sitemap / Search | 说明 |
| --- | --- | --- | --- |
| `product`（metadata.core=true） | `/products/{slug}`（**无**尾斜杠） | 是 | Catalog 核心产品 |
| `product`（非 core） | `null` | 否 | 仍为 GEO 节点，但不输出 url、不进 feed；前台 404 |
| `service`（有 Catalog 场景） | `/solutions/{slug}/`（**带**尾斜杠） | 是 | 不带斜杠访问 301 到带斜杠 |
| `organization` / `person` / `location` / `topic` | `null` | 否 | 仅 machine-readable GEO / Schema 节点 |
| 无场景 `service` | `null` | 否 | 同上 |

- v1.0 **不补** organization / person / location 独立详情页。
- **实测**：`/products/epoxy-primer-100` 200（canonical 无尾斜杠，og:image 绝对）；`/products/heat-resistant-coating-300`（非 core）404；`/product/epoxy-primer-100`（单数）404；`/solutions/equipment-manufacturing/` 200（canonical 带尾斜杠），不带斜杠 301。
- ReleaseResidual18CTest TD-05 段锁定六类型 URL 契约。

### TD-06 Content 路径收敛 —— **DEFERRED v1.1（书面裁定，301 桥接测试锁定）**

- **裁定**：Content 公开路径维持 `Content::path()` = `/{栏目完整路径}/{slug}`（知识中心 `/knowledge/{slug}`，无栏目单页走 catch-all `/{slug}`），**不引入 `/article/` 前缀**；旧路径继续由 301 桥接。
- `/article/` 前缀收敛、栏目 `seo_title/seo_desc` 是否成为第三套 SEO，均明确 **v1.1**。
- 顺手清理：`Content::schemaType()` 现在仅 `page → WebPage`、default → `Article`；product 死分支已删除（Product 已在 17B 成为正式 Entity，不再是 Content 类型）。
- ReleaseResidual18CTest TD-06 段锁定"无 /article/ 前缀 + schemaType 映射"。

### TD-09 Schema `@id` / PublicUrl 全面统一（#143）—— **CLOSED**

见 §2 反向审计。

---

## 2. Release Gate 反向审计

### #143 声明性绝对 URL host / scheme 分叉 —— **CLOSED**

- **发现方式**：grep 全 `app/`，定位所有声明性绝对 URL（canonical、og:url、og:image、JSON-LD `@id`/`url`/`item`/`image`/`contentUrl`、sitemap `<loc>`、llms 链接、robots Sitemap 行、rss channel link、breadcrumb/faq/collection crumbs url）。此前大量使用 Laravel `url()`，其 host 跟随**请求 origin**；而 `PublicUrl` 按**站点规范 domain** 裁决——两者构成 helper 层 host 分叉。
- **裁定边界**：
  - **声明性绝对 URL → 必须经 `PublicUrl`**（进入 head / schema / feed / sitemap / robots，代表"这一资源的规范身份"）。
  - **功能性同源 URL → 保留 `url()` / `asset()`**：主导航 href、subnav 高亮、可见卡片/区块 `<a>`、表单 action、favicon/logo `<img>`、CSS/JS、重定向 Location、后台预览 label、错误页按钮。真实 HTTP FPM 下 origin === 规范 domain（ResolveSite 保证 host ↔ 站点一致），这些不需要声明性裁决。
- **新增**：`PublicUrl::url(string $path = '/')`。
- **切换清单**：SitemapBuilder、LlmsBuilder、FeedController、ProductController、Content、Category、Media、SeoHeadComposer、SchemaBuilder、Catalog、GeoGraphBuilder，以及 Home/About/Factory/Cooperation/Contact/Solution/Knowledge/Search 控制器。SchemaBuilder 全部经 `PublicUrl` / `baseUrl()` / `absolute()`（organization logo 用 `absolute()`），无分叉。`site.blade.php` 的 canonical/og:url/og:image 使用 resolver / 控制器传入的已 PublicUrl 值；favicon / logo / 导航 / 按钮保留 `asset()` / `url()`。
- **尾斜杠契约（极易再踩，显式记录）**：
  - `PublicUrl::home()` = **带**尾斜杠（首页 canonical 冻结契约；SeoMetaResolver resolveSite 的 canonical = home()）。
  - `PublicUrl::base()` = **无**尾斜杠。
  - **Sitemap 首页 `<loc>` 用 `PublicUrl::base()`（无斜杠）**——sitemap loc 与首页 canonical 是两套冻结契约，不可统一。初版误把 SitemapBuilder 首页 loc 改成 home()（带斜杠）导致 SitemapRobotsTest 3 处失败，已改回 `SitemapBuilder.php` 首页 loc = `PublicUrl::base()` 并加注释。
  - 其余根链接（首页 canonical、llms 官网/首页、rss channel link、Contact LocalBusiness url、各控制器 crumbs 首页、GeoGraph/Schema organization url）统一用 `home()`。
- **SitemapRobotsTest host 预期修正（修正测试实现耦合，非降断言）**：setUp 把 default site domain 改为 `example.com`，但 `locs()` 用相对 `get('/sitemap.xml')`（origin=localhost）。TD-09 后 loc 按站点 domain 输出 `https://example.com`。原断言用 `url()`（localhost）：收录断言会错配，**排除断言（assertNotContains）在 loc 已是 example.com 时恒真、失去排除验证意义**。已把收录/尾斜杠/三条排除断言改为显式 example.com 并加 TD-09 注释——**修正反而强化了断言**。
- **验证**：ReleaseResidual18CTest TD-09 段：产品页 canonical / og:image / 页面内所有 ld+json 均为 example.com、无 localhost；无单数 `/product/`；Catalog 缺 entities 表时安全降级为空。真实 serve（临时库 site domain=NULL）canonical 回退请求 host（127.0.0.1:port），生产 domain===origin 不分叉；首页 localhost-mentions=0。

### #144 单一事实源反向审计 —— **PASS（无新增双源）**

从最终产品倒推 Fresh Install → Blank → Admin → Entity/Relation/Content/SEO → Theme/Plugin/Settings → Frontend → Schema/GEO/Sitemap/LLMS/RSS/Search → Cache → Multi-Site → CLI：

- **组织**：Site 聚合唯一事实源（TD-07）。
- **关系**：EntityRelation 是唯一权威边源，Catalog 单向派生（18A / TD-04），无双向反写。
- **公开 URL**：PublicUrl 单一裁决（TD-09 / #143）。
- **SEO**：SeoMeta + SeoMetaResolver 单一来源，后台只展示 explicit / resolved，不自行 fallback（17D）。
- **站点名**：Site.name 唯一权威，setting site_name 单向镜像（18B / TD-12）。
- **Feed 准入**：PublicIndex / PublicUrl 单一准入（17G / #86）。
- 未发现"同一事实在两套系统各自维护、各自解释、各自产出不同结果"的新双源。

---

## 3. Fresh Install / 两态 / HTTP 实测证据（真实执行）

临时目录 `D:\Temp\p18c-fresh\`（仓库外）。

### 3.1 空站（fresh.sqlite，`geo:install -n`，不 seed）

```
settings=65  sites=1  site_name=GEO Website OS
entities=0  contents=0  categories=0  page_blocks=0  menus=0  facts=0
rss_default='1'
```

真实 `artisan serve`（端口 8126）：

| 路径 | 状态 | 备注 |
| --- | --- | --- |
| `/` | 200（87619B） | title=**GEO Website OS**；banned-mentions（示例制造/工业涂料/结构胶/Sample Snack/Sample Marinade/Demo Tenant A）=0 |
| `/geo.json` | 200（344B） | banned=0 |
| `/sitemap.xml` | 200（426B） | banned=0 |
| `/llms.txt` | 200（221B） | banned=0 |
| `/feed.xml` | 200（187B） | RSS 门禁默认开，空内容中性 |
| `/robots.txt` | 200（1621B） | banned=0 |
| `/products/epoxy-primer-100` | **404** | 空站无 Demo 实体 |

### 3.2 Demo（同一库 `db:seed --force`）

```
settings=65（幂等，不破坏默认）
entities=12（organization 1 / product 8 / service 3）  relations=58
contents=3  categories=2  facts=23
site_name=示例制造有限公司
sample_products=环氧富锌底漆 ZP-100 | 聚氨酯面漆 PC-200 | 有机硅耐高温涂料 HR-300
org_flag=true（主体 organization metadata.is_site_organization）
```

真实 serve（8124/8125）：

| 路径 | 状态 | 关键断言 |
| --- | --- | --- |
| `/` | 200（125775B） | canonical=`http://127.0.0.1:8124/`；localhost-mentions=0 |
| `/geo.json` `/sitemap.xml` `/llms.txt` `/feed.xml` `/robots.txt` | 全 200 | — |
| `/products/epoxy-primer-100`（core） | **200** | canonical=`…/products/epoxy-primer-100`（无尾斜杠）；og:image 绝对 |
| `/products/heat-resistant-coating-300`（非 core） | **404** | 非 core 不公开 |
| `/product/epoxy-primer-100`（单数） | **404** | 无单数路由 |
| `/solutions/equipment-manufacturing/`（带斜杠） | **200** | canonical 带尾斜杠 |
| `/solutions/equipment-manufacturing`（不带） | **301** | 桥接到带尾斜杠 |
| `/no-such-page-xyz` | **404** | — |

> 真实 serve 时临时库 site.domain=NULL，PublicUrl 回退请求 origin（127.0.0.1:port），符合"生产 domain===origin 不分叉"；显式 domain 的 host 裁决由 ReleaseResidual18CTest 在 `example.com` 下断言。

---

## 4. 全量回归与一个测试顺序依赖（登记为 P4，非产品 bug）

- **最终全量**（干净环境、确认无残留 php 后，固定 PHPUnit 顺序）：**826 passed / 4139 assertions / 0 failed / 0 skipped，Duration 406.59s**。新增 = ReleaseResidual18CTest 12 + AdminCategoryCrud 4 = 16（810 + 16 = 826 吻合）。
- 早前一次全量表现为长时间未结束，根因是**页面渲染类测试约 1s/个、全量约 6.8 分钟**叠加机器 / 残留 php 进程负载，**非死循环**；干净环境重跑 406s 正常完成。
- **新登记 TD-27（P4，测试隔离）**：在一次**自定义分批顺序**下，`GeoflowApiTest` 12 个测试出现 `insert "facts" site_id=2 FOREIGN KEY constraint failed`；该测试**单独跑 12 passed / 32 assertions**，**全量固定顺序也全绿**。根因是 RefreshDatabase 同进程 autoincrement / 静态 SiteContext memo 与测试对 site id 的假设在非标准顺序下叠加，属测试夹具顺序依赖，**不是产品 Runtime bug，CI 固定全量顺序为绿**。v1.1 加固测试隔离。

---

## 5. Runtime 业务污染复扫

- 强身份词（Demo Tenant A / Demo Tenant A / demo-tenant-ashipin / 400-001-3770 / Sample City / Sample Province / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Road / 金家街 / admin@demo-tenant-a / Sample SaaS / Sample SaaS）：
  - PHP Runtime（`app` / `config` / `routes` / `resources` / `database` / `plugins` / `scripts`）= **0**。
  - 非 PHP Runtime（js / css / json / yml / blade / html / xml / .env* / sh / stub）= **0**。
  - `tests/` 命中 14 文件，逐条核实**全部为负向护栏断言**（`assertDontSee` / `assertStringNotContainsString` / banned 常量 / `assertDoesNotMatchRegularExpression`），无任何 fixture 插入真实客户数据。
  - `.md` 命中 15 文件，**全部在 `docs/audit/`（含 `docs/audit/history/`）**，为 Historical Audit Exemptions（release archive export-ignore，不进发布包）；根 README / CHANGELOG / SECURITY / CONTRIBUTING 与 docs 非 audit 文档零命中。
- 通用行业词（食品 / 制造 / OEM / ODM / 工业涂料 / 胶粘剂 / 装备制造 / 工厂等，口径 C）按台账白名单保留，不做"全历史 0 行业词"的过度改写。

---

## 6. Remaining Risks / v1.0 后剩余项

**v1.0 Required 未闭合仅剩 3 项 P0 外部工程依赖（需用户外部授权，非代码问题）**：

- **TD-01** GitHub Actions 云端 Runner 真实首跑（CI 配置本地已就绪，965d63c）。
- **TD-02** 基于最终 HEAD 重建干净 RC + release-manifest + ZIP + SHA-256（rc1 仍冻结 965d63c，HOLD）。
- **TD-03** Private push → 云端观察 → 用户决定转 Public / v1.0.0（空 Private 仓 `jackyduhai/geo-website-os` 已建，未配 remote）。

**DEFERRED v1.1（书面裁定，不阻塞 v1.0）**：TD-06 `/article/` 收敛、TD-14 RBAC、TD-15 i18n、TD-16②③④、TD-17 友好 500 页、TD-18 Theme/Plugin 上传 SDK、TD-19 旧 IA 余项、TD-20②③④、TD-23 PHPUnit attribute、TD-24 PageCache file store 回收、**TD-27 GeoflowApiTest 测试顺序隔离**。

**已知边界**：真实 HTTP 生产环境依赖 ResolveSite 保证 host ↔ 站点一致（domain===origin）；功能性 URL 保留 `url()`/`asset()` 是显式裁定而非遗漏（见 §2）。

---

## 7. Git

- 变更范围：TD-07 / TD-05 / TD-06 / TD-09（#143/#144）/ TD-08b / TD-16① / TD-20① 的源码、种子、Blade、中间件与测试；新增 `tests/Feature/ReleaseResidual18CTest.php`；本报告与台账更新。
- commit + **annotated tag `checkpoint-18C`**；worktree clean。
- 未移动 `v1.0.0-rc1`、未配 remote、未 push、未 Release。
