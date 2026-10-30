# P-STEP 14 — Runtime Architecture Closure

> 范围：只收口终验独立审计抓出的三个 Runtime 架构问题 **D.2 / D.3 / D.4**。
> 不新增产品功能、不扩展 Plugin/Theme SDK、不新增 GEO Feature、不做 Git 历史重写、不打 v1.0.0、不做公开 Release。
>
> 基线：`f284213`（646 tests / 2929 assertions / 0 failed / 0 skipped）
> 终态：**661 tests / 3057 assertions / 0 failed / 0 skipped**（+15 tests / +128 assertions）

---

## 0. 结论速览

| 项 | 结果 | 证据 |
|---|---|---|
| D.2 Legacy Facts 多站泄漏 | ✅ PASS | Catalog 站点隔离读模型 + 全部 Runtime 消费点切换 + HTTP A/B 矩阵 + 6 跳往复 |
| D.3 插件 per-site 路由隔离 | ✅ PASS | 注册/授权分离 + `EnsurePluginEnabled` 守卫 + 5 用例 per-site HTTP 测试 |
| D.4 withSite 进程状态隔离 | ✅ PASS | 进/出 `reapply()` + 4 用例 + 变异测试证明非假绿 |
| Facts Runtime 消费者 | ✅ 0 | `app/`、`resources/` 对 `Facts::` / `Support\Facts` 引用为 0 |
| 业务污染 | ✅ 0 | app / resources / seeders / plugins / config / routes 全量复扫 0 命中 |
| 全量回归 | ✅ 661 / 3057 / 0 / 0 | `junit_p14b.xml` |
| HTTP 异常日志 | ✅ 干净一轮 26 请求后无 laravel.log | storage/logs |
| Git | ✅ 基线 f284213 之上单阶段提交，working tree clean | 见 §10 |

**最终判定：GEO WEBSITE OS — GENERIC RUNTIME BASELINE READY。**

本阶段不宣布"无 Bug"。已完成当前测试与 HTTP 范围内的系统性验证，剩余风险与技术债务见 §9。

---

## 1. 问题本质：两代 Runtime

终验暴露出系统长期并存两套世界：

- **新世界（站点隔离）**：Site / Content / Entity / EntityRelation / SeoMeta / Theme / Plugin。
- **旧世界（全局）**：Facts（`config/facts.php` 文件型事实源）/ 旧 URL / 旧前台目录 / legacy config。

D.2/D.3/D.4 不是"业务污染"（Example 数据已通用化），而是 **Legacy Runtime Leakage 与进程状态生命周期** 问题：

- D.2：空站 B（`b.test`）仍能通过旧 Facts Runtime 完整 200 渲染全局产品/场景/sitemap/schema —— SiteScope 没有覆盖到旧前台数据源。
- D.3：插件可启停（lifecycle PASS），但 per-site 插件路由 HTTP 不可隔离。
- D.4：`SiteContext::withSite()` 同进程内不复位 static memo，CLI / Admin / Job / nested 场景有串站风险。

---

## 2. D.2 — Legacy Facts 多站泄漏（BLOCKER）

### 2.1 根因

旧前台目录（产品/场景/工厂/合作/关于/联系）、首页区块、Sitemap、LLMs 输出直接读取全局
`App\Support\Facts`（`config/facts.php`），该数据源**没有 site_id 维度、不受 SiteScope 约束**。
因此无论当前 Host 是哪个站，渲染的都是同一份全局目录。

### 2.2 方案：Catalog 站点隔离读模型 + Example 投影

新增 **`app/Support/Catalog.php`**（333 行）：

- 站点隔离的目录读模型，对外 API 与 Facts 同构（`company() / productLines() / productsByLine() / coreProductSlugs() / line() / product() / isCoreProduct() / scenes() / scene() …`）。
- 数据**只来自当前站点的 Entity / EntityRelation**（走 BelongsToSite + SiteScope 的正式模型）。
- `private static` memo **按 siteId 分桶**；空 organization 的站点 `company()` 返回空（所有目录访问器随之返回空集合）。
- `flush()` 接入 `RequestScopedState::flushAll()`，切站/请求结束即复位。

新增 **`database/seeders/CatalogSeeder.php`**（145 行）：

- 把 `config/facts.php` 的 **Example** 数据投影为**当前站点**的 Entity（1 个 organization + 8 个 product + 3 个 service）与 EntityRelation（produces / offers / uses / related_to）。
- `firstOrNew` 幂等；organization 为空即 return；挂在 `DemoSeeder` 末尾。
- `relate()` 显式填 `site_id`（绕开 EntityRelation saving 跨站校验早于 BelongsToSite creating 填 site_id 的时序）。

由此 `config/facts.php` 退为**安装/演示期的 Example 种子源**，不再是请求期 Runtime 数据源。

### 2.3 Runtime Consumer Inventory 与处置

| # | 消费点 | 旧数据源 | 站点感知 | 处置 |
|---|---|---|---|---|
| 1 | ContactController | Facts | 否 | → Catalog |
| 2 | CooperationController | Facts | 否 | → Catalog |
| 3 | FactoryController | Facts | 否 | → Catalog |
| 4 | AboutController | Facts | 否 | → Catalog |
| 5 | HomeController | Facts | 否 | → Catalog |
| 6 | ProductController（index/line/show/resolve） | Facts + 路由期全局 slug 白名单 | 否 | → Catalog；`resolve()` 按本站 Catalog 分流系列/详情，查无 404 |
| 7 | SolutionController | Facts | 否 | → Catalog；查无场景 404 |
| 8 | HomeBlockDefaults | Facts | 否 | → Catalog |
| 9 | Narrative | Facts | 否 | → Catalog |
| 10 | AppServiceProvider（页脚热线等） | Facts | 否 | → Catalog |
| 11 | SitemapBuilder | Facts | 否 | `$hasCatalog = !empty(Catalog::company())` 门控，空站只出首页 + Content 域 |
| 12 | LlmsBuilder | Facts | 否 | 空 company 走 `buildGeneric()` |
| 13 | **AppServiceProvider 主导航/页脚蓝图**（本轮新抓） | `config('copy.nav.menu')` / `config('copy.footer.columns')` 固定目录树 | 否 | 新增 `navPathExists()`，对 Catalog 域路径按本站 Catalog 门控；空列不输出 |
| 14 | **Product/Solution `index()`**（本轮新抓） | 漏门控 | — | 空目录站 `abort_if(empty(Catalog::company()), 404)` |
| 15 | **Blade 影子地址**（本轮新抓） | 硬编码 `/contact/contact-us` | — | 规范化为 `/contact/`，并加 Catalog 门控（home/content/search） |
| 16 | **products.show 尾斜杠**（本轮新抓，见 §2.6） | 路由静态 `defaults('_slash',1)` | — | 改由 CanonicalizeSlash 按本站 Catalog 动态判定 |

路由层同步删除 Facts 三正则与全局 slug 白名单（`routes/web.php`），产品/场景可访问性统一交给当前站 Catalog 在控制器内判定。

Blade 统一使用全限定 `\App\Support\Catalog::`；Sitemap/Llms/前台模板零 Facts 引用。

### 2.4 Facts Runtime 消费者归零复核

- `app/` 对 `App\Support\Facts` / `Facts::` 的引用：**0**。
- `resources/` 对 `App\Support\Facts` / `Facts::` 的引用：**0**。
- `app/` 内 `config('facts')` 命中 7 处，**全部为 docblock/注释**（Catalog、Facts、HomeBlockDefaults 的说明文字），无运行时调用。
- 编译视图缓存 `view:clear` 后 compiled 内 Facts 引用：0。

允许保留的 Facts 引用（非请求 Runtime）：

- **种子**：`CatalogSeeder` / `SettingSeeder` / `DemoSeeder` 在安装/演示期读 Facts 投影 Example。
- **契约/迁移完整性测试**、**历史 migration**（如 `2026_09_15_000017`）。
- **架构护栏测试**：`SiteTest` / `ThemeArchitectureTest` 的 `assertStringNotContainsString('Facts::')`。
- `Catalog` docblock 的 `{@see Facts}` 与"禁止读 Facts"注释。
- 单数 `App\Models\Fact`（后台事实要点 CRUD）局部变量（`$factRows`、`geo_key_facts` 等）与本问题无关，保留。

`Facts.php` 与 `config/facts.php` **保留**为 Example 种子源；历史 migration 不修改（工程历史真实性）。

### 2.5 HTTP 证据（真实服务器，非测试客户端）

磁盘库构造双站：A=`a.test`（完整 Example 目录：1 organization / 8 product / 3 service / 3 产品线 / 3 场景 / 4 核心产品）、B=`b.test`（0 Entity / 空 Catalog / 无 Content）。

**目录页状态码矩阵（修后）**

| 路径 | A (a.test) | B (b.test) |
|---|---|---|
| `/` | 200 | 200（空态，无目录链接） |
| `/products/` | 200（含目录，命中 22） | **404** |
| `/solutions/` | 200（命中 8） | **404** |
| `/factory/` | 200 | **404** |
| `/cooperation/` | 200 | **404** |
| `/about/profile/` | 200 | **404** |
| `/contact/` | 200 | **404** |
| `/knowledge/`（Content 域） | 200 | 200（保留） |
| 产品线 `/products/coatings/` | 200 | 404 |
| 核心产品 `/products/epoxy-primer-100` | 200 | 404 |
| 场景 `/solutions/equipment-manufacturing/` | 200 | 404 |

B 站所有 404 / 首页 / knowledge 响应体对 A 站目录 slug 与名称（产品线、场景、核心产品、源头工厂/OEM 等）扫描：**0 命中**。

**Sitemap 隔离**：A `sitemap.xml` 4604B（完整目录，核心产品详情无尾斜杠）；B `sitemap.xml` 410B（仅首页 + `/knowledge/` 总览），host 严格为各自域，无交叉。

### 2.6 本轮 HTTP 验证新抓的四个真实残留（均已修）

D.2-E 真实 HTTP 回归并非一次通过，又抓出四类测试未覆盖的问题：

1. **主导航/页脚/页内 Tab 全局泄漏**：顶部下拉菜单与页脚"产品中心/应用场景"来自 `config('copy')` 的固定目录树，未经 SiteScope 门控，空站 B 仍列出 A 的链接（指向 404）。
   → `AppServiceProvider` 新增 `navPathExists(string $rawHref): bool`，在 `mainMenuBlueprint()` / `footerBlueprint()` 节点 visible 上按本站 Catalog 门控；空目录站无可见链接的非联系列不输出，联系列保留。
2. **空站总览 200 空壳**：`ProductController::index` / `SolutionController::index` 缺空目录门控（resolve/line/show 已有），空站返回 200 并渲染 `config/pages.php` 的全局目录 lead。
   → 两 index 开头加 `abort_if(empty(Catalog::company()), 404)`，与 factory/cooperation/about/contact/详情对齐。
3. **影子地址 `/contact/contact-us`**：home（@empty hero CTA）、content（留言按钮）、search（无结果空态）硬编码已声明下线的旧影子地址，空站为死链。
   → 规范化为 `/contact/`，home/search 额外加 Catalog 门控。
4. **详情页 trailing-slash 契约反转（最重要 URL bug）**：`products.show` 路由同时承载产品系列（目录型，带斜杠）与核心产品详情（详情型，**无**斜杠），却被静态 `defaults('_slash',1)` 整体判为目录型，导致 sitemap/canonical 写入的规范详情 URL（无尾斜杠）访问时被 **301 到带尾斜杠**。
   → 去掉该静态 default；`CanonicalizeSlash` 新增 `resolveProductWantsSlash(string $param): ?bool`，按**当前站 Catalog** 判定：系列→true（带斜杠）、核心产品→false（无斜杠）、查无→null（不跳转，交控制器 404）。`solutions.show` 场景页契约本就是目录型，保留 `_slash=1`。

**修后斜杠矩阵（curl 首次状态码 / Location）**

| 请求 | 结果 |
|---|---|
| A `/products/epoxy-primer-100`（详情无斜杠） | **200** |
| A `/products/epoxy-primer-100/`（详情带斜杠） | 301 → 无斜杠 |
| A `/products/coatings`（系列无斜杠） | 301 → `/products/coatings/` |
| A `/products/coatings/`（系列带斜杠） | 200 |
| A `/solutions/equipment-manufacturing/`（场景） | 200 |
| B 上述任意产品路径（含两种斜杠） | 404（wantSlash=null 不跳转） |

> 测试架构限制：Laravel Feature 测试客户端会 trim 请求目标尾斜杠，且 `CanonicalizeSlash` 在 `runningUnitTests()` 下跳过 301（否则无限自跳），故真实 301 无法在 Feature 层复现。现以「抽出的纯判定方法 `resolveProductWantsSlash()` 单测 + curl 真实 HTTP」双保险锁定。

### 2.7 多站 6 跳往复（不清缓存，直接交替，验证缓存/上下文不串）

A→B→A→B→A→B，每跳请求 `/solutions/`、`/products/epoxy-primer-100`、首页场景链接计数：

| hop | 站 | solutions | productDetail | 首页场景链接数 |
|---|---|---|---|---|
| 1 | A | 200 | 200 | 3 |
| 2 | B | 404 | 404 | 0 |
| 3 | A | 200 | 200 | 3 |
| 4 | B | 404 | 404 | 0 |
| 5 | A | 200 | 200 | 3 |
| 6 | B | 404 | 404 | 0 |

零串站、零缓存污染。

---

## 3. D.3 — 插件 per-site 路由隔离（BLOCKER）

### 3.1 根因

此前只验证了插件 enable/disable 生命周期，未验证 per-site HTTP 路由边界；且插件路由在
`enable()` 时由 provider 动态注册，存在一个**跨应用实例的 `static bool $routesRegistered`**
防重入标记，导致 Feature 测试（新应用实例）里插件路由根本没被注册。

### 3.2 契约：注册 / 授权分离

- **注册期（路由加载，与启停状态无关）**：`bootstrap/app.php` 的 `withRouting()->then()` 在 api 组之后调用
  `PluginManager::registerInstalledRoutes()`，遍历 `plugins/*/plugin.json`，对含 `routes/web.php` 的插件
  `Route::middleware(['web', EnsurePluginEnabled::class.':'.$slug])->group($file)`，注册**全部已安装**插件路由。
  不再使用跨应用 static bool 防重入。
- **授权期（请求时，按当前站）**：新增 `app/Http/Middleware/EnsurePluginEnabled.php`（29 行），
  `handle($request,$next,$slug)`：插件不存在或在**当前站点**未启用（读当前站 Setting `plugins_enabled` JSON 数组）→ `abort(404)`；slug 全参数化。
- `enable()` / `disable()` 只写当前站 Setting 并清 `enabledMemo`，**不再动态 register provider**。
- `HelloServiceProvider::boot()` 清空路由注册；插件路由迁入 `plugins/hello/routes/web.php`（18 行，`prefix plugins/hello`，`ping` 返回 JSON）。
- 不使用 global singleton / static route state / session hack / URL 参数猜站；站点判定只走正式 SiteContext。

### 3.3 验证

- 新增 `tests/Feature/PluginPerSiteRoutingTest.php`（5 用例）：A 启用→插件路由 200、B 未启用→404；A disable / B enable 后反转；反复切站路由独立切换；未安装插件 unknown 404。
- `PluginArchitectureTest::test_disable` 增强为 disable 后 ping `assertNotFound()`。
- 架构护栏 `test_core_code_has_no_plugin_references` 保持：递归 `app_path()` 除 `Support/Plugins/PluginManager.php` 外不得含 `plugins/hello` 或 `Plugin\Hello`（`EnsurePluginEnabled` 合规、`bootstrap/app.php` 不在 app_path 扫描内）。
- `php artisan route:list` 共 94 条路由，插件路由在列。
- `--filter Plugin`：**12 tests / 246 assertions / 0 failed**。

---

## 4. D.4 — withSite 进程状态隔离（P1）

### 4.1 根因

`SiteContext::withSite()` 切换 `currentSite` 后未复位各组件的 static memo / 注册态。HTTP 主路径有
ResolveSite 中间件保护，但 CLI / Admin / Job 内嵌 / nested `withSite()` 场景缺少统一复位，存在串站风险。

### 4.2 修复

`SiteContext::withSite()` 在两处各调用一次 `App\Support\RequestScopedState::reapply()`：

1. 进入闭包、`setSite($new)` 之后；
2. `finally` 恢复外层 `currentSite` 之后。

`reapply()` = `flushAll()`（含 `Catalog::flush()`、SiteScope / CacheKey / Setting / SeoMeta 等 memo 复位）
+ `ThemeManager::register()` + `PluginManager::register()`（幂等、try-catch、不递归 withSite）。

由此同一套生命周期语义覆盖 request / process / CLI / queue / nested；队列侧 `QueuedBySite` 恢复路径本就 reapply，HTTP 侧 ResolveSite `finally clear()`。

### 4.3 验证与变异测试

新增 `tests/Feature/SiteContextStateIsolationTest.php`（4 用例 / 23 assertions）：

- Settings 在反复 withSite 切换间不泄漏；
- withSite 退出后恢复外层上下文与注册态；
- nested withSite 正确逐层恢复；
- Catalog memo 按站隔离。

**变异验证（证明非假绿）**：临时移除两行 `reapply()` → 同组 **4 tests / 17 assertions / 3 Failures**；恢复后全绿。证据 `p14_d4mut.txt`。

---

## 5. 测试与回归

### 5.1 全量回归

| | Tests | Assertions | Failed | Skipped |
|---|---|---|---|---|
| 基线 f284213 | 646 | 2929 | 0 | 0 |
| **P-STEP 14 终态** | **661** | **3057** | **0** | **0** |
| 增量 | **+15** | **+128** | — | — |

新增测试文件：

- `tests/Feature/CatalogRuntimeIsolationTest.php`（6 tests / 43 assertions）：空站目录 404、空站详情/系列/场景 404、空站首页零他站目录链接、完整站渲染本站目录、斜杠方向按实体类型、空站斜杠判定为 null。
- `tests/Feature/PluginPerSiteRoutingTest.php`（5 tests，D.3）。
- `tests/Feature/SiteContextStateIsolationTest.php`（4 tests / 23 assertions，D.4）。

增强既有测试：`SitemapRobotsTest::test_sitemap_is_site_scoped` 改为 A/B 双 host 严格隔离（host 全量校验 + 路径黑名单）；`PluginArchitectureTest::test_disable` 增加 disable 后 404；`RemainingControllerSeoTest` / `FinalAcceptanceTest` / `InquiryTest` 补 Catalog 播种。

专项（斜杠 / URL / sitemap / 菜单 / 解决方案 / 控制器 SEO / 导航对齐）：**85 tests / 444 assertions / 0 failed**（1 个 PHPUnit Deprecation，非失败）。

未删除任何测试、未降低断言、未 skip、未改预期以换取绿灯。85 个 PHPUnit Deprecations 与基线一致（PHPUnit 11 对旧断言风格的提示），不计失败。

### 5.2 HTTP / 日志

- 状态码矩阵、斜杠矩阵、sitemap、6 跳往复见 §2。
- 清空 `storage/logs/laravel.log` 后，对 A/B 两站重放 26 个请求（首页、各目录、系列、详情、两种斜杠、factory/contact/knowledge、sitemap/geo.json/llms.txt/robots.txt、404），**laravel.log 未被创建 = 零 ERROR / Exception / Deprecated**。
- 日志中曾有一条 ERROR 系前序人工误用 `cache:clear --store=file`（该选项不存在）所致，属操作命令误用、非产品缺陷，已随日志清除。

> 开发期发现 PageCache/菜单缓存强制使用 `Cache::store('file')`，物理目录 `storage/framework/cache/data` 会累积大量缓存文件，`cache:clear` 在该环境未即时回收；复验通过物理清空该目录内容完成（**保留并已恢复 `.gitignore`**）。

---

## 6. 业务污染复扫

对本轮新增 / 修改的 Runtime 全量复扫（`Example/Example/Sample City/Sample Province/Sample Snack/Sample Marinade/Sample Road/Sample Breading/撒料/调理鸡/鸡排/Example Street/通用设计参考/Generic/400-000-0000/marinade/fried chicken/chicken/sample-city/sample-province`，大小写不敏感）：

| 范围 | 命中 |
|---|---|
| `app/` | 0 |
| `resources/` | 0 |
| `database/seeders/` | 0 |
| `plugins/` | 0 |
| `config/` | 0 |
| `routes/` | 0 |

Catalog/CatalogSeeder 使用的是通用 Example 语汇（Organization / 产品系列 / 核心产品 / 应用场景 / 工业涂料-胶粘剂-助剂等示例数据），与任何真实客户业务无语义关联。

---

## 7. 架构边界遵守

- 未新增 Entity 类型、未加数据库字段、未新增 Content→Entity 关系（Content 与 Entity 仍为平行资源，Content SEO 不 fallback Entity 的冻结契约不变）。
- 未改 SeoMeta 三态表结构 / CHECK / partial unique index / FK；未动 SeoMetaResolver / GenericUrlResolver 冻结契约。
- 未改 SchemaBuilder / GeoGraphBuilder 的站点隔离实现；未做 Theme/Plugin SDK 大扩展；未加新 GEO Feature。
- 历史 migration、`docs/audit/**`（export-ignore）保持原样，未为"全仓 0 关键词"伪造修改。
- 未做 Git 历史重写、未加 remote、未 push、未打 release tag、未发布 v1.0.0。

---

## 8. 变更文件清单

**新增**

- `app/Support/Catalog.php`
- `database/seeders/CatalogSeeder.php`
- `app/Http/Middleware/EnsurePluginEnabled.php`
- `plugins/hello/routes/web.php`
- `tests/Feature/CatalogRuntimeIsolationTest.php`
- `tests/Feature/PluginPerSiteRoutingTest.php`
- `tests/Feature/SiteContextStateIsolationTest.php`
- `docs/audit/runtime-architecture-closure.md`（本报告）

**修改（D.2）**：7 个 Site 控制器、`HomeBlockDefaults`、`Narrative`、`AppServiceProvider`（菜单/页脚门控 + footer 热线）、`RequestScopedState`（Catalog flush）、`SitemapBuilder`、`LlmsBuilder`、`DemoSeeder`、`routes/web.php`、`CanonicalizeSlash`、11 个 Blade（home/hero/cta/contact/product_card/scene_card/cases/cooperation/products/scenes/content/search）、3 个测试补播种与 SitemapRobotsTest 强化。

**修改（D.3）**：`PluginManager`、`bootstrap/app.php`、`plugins/hello/providers/HelloServiceProvider.php`、`PluginArchitectureTest`。

**修改（D.4）**：`SiteContext.php`。

---

## 9. 剩余技术债务与风险（不阻塞本 Gate）

1. **organization 双载体**：organization Entity 与 `sites.metadata.organization` 暂时并存（SchemaBuilder 读后者，已站点隔离）。本轮不动，后续统一到 Entity 单一载体。
2. **Catalog 早期守卫**：`Catalog::buildDataset()` 直接查 Entity，尚无 `Schema::hasTable()` / try-catch 守卫；在未 migrate 的极早期 CLI 调用理论上可能报错（正常安装与请求均在 migrate 之后）。
3. **Facts/config/facts 退场**：`Facts.php`、`config/facts.php` 保留为 Example 种子源（仅 seeder/契约测试/历史 migration 引用），最终在 Catalog 成为唯一投影、演示数据完全 Entity 化后删除。
4. **默认配置树保留**：`config/copy.php` 菜单默认树、`config/pages.php` 目录 lead 仍作为默认值存在；多站隔离由蓝图层门控（`navPathExists`）与控制器 `abort_if` 保证，空站不渲染。它们不是多站数据源。
5. **注释过时**：`RequestScopedState` 中关于"插件 provider 路由残留"的个别 docblock 表述在 D.3 注册/授权分离后部分过时（零行为影响，可在后续文档清理中更新）。
6. **斜杠 301 测试限制**：真实 301 由 curl 锁定，Feature 层受测试客户端剥尾斜杠 + `runningUnitTests` skip 限制无法复现，已用纯判定方法单测补足。
7. **发布链外部 blocker（需另行授权，不在本阶段）**：GitHub Actions 云端首跑、Git 历史中的客户二进制重写（git-filter-repo 或单 initial commit）、打 v1.0.0 与公开 Release。

本阶段不声明"系统无 Bug"，仅声明：在当前自动化测试（661）、真实 HTTP A/B 矩阵、6 跳往复、日志审计与污染复扫范围内，D.2/D.3/D.4 三个 Runtime 架构问题已修复并有回归保护。

---

## 10. Git 与 Gate

- 基线：`f284213`。
- 本阶段所有改动在该基线之上组织为一次提交（不 push、不打 tag、不重写历史）。
- 提交后 working tree clean。

**Final Gate**

| 条件 | 结果 |
|---|---|
| D.2 Legacy Facts Runtime 消费者归零、多站隔离 | ✅ PASS |
| D.3 Plugin per-site routing | ✅ PASS |
| D.4 withSite state isolation | ✅ PASS |
| Multi-Site（含 6 跳往复） | ✅ PASS |
| Plugin | ✅ PASS |
| Full Regression（661 / 3057 / 0 / 0） | ✅ PASS |
| Business Pollution = 0 | ✅ PASS |
| HTTP 日志零异常 | ✅ PASS |
| Git clean | ✅ PASS |

> **GEO WEBSITE OS — GENERIC RUNTIME BASELINE READY**
>
> STOP。不进入 Git 历史重写 / 公开 Release / v1.0.0；这些动作另行授权。
