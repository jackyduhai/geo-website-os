# P-STEP 18G-2a — Detail Composition Migration · Gate 证据

- **阶段**：P-STEP 18G-2a（Detail Composition），隶属父 Epic **#116 Page Composition / Template System**。
- **执行方式**：两次子 Gate 的第一个 —— 18G-2a（Detail）→ STOP；18G-2b（Listing + 系统页）后续单独授权。
- **基线 before**：HEAD `c3ccd07`（= annotated tag `checkpoint-18G-1`）；Regression **898 / 4745 / 0 / 0**；worktree clean。
- **基线 after**：见 §9（commit + annotated tag `checkpoint-18G-2a`）；Regression **919 / 4798 / 0 / 0**。

---

## 1. 阶段目标与用户裁定

18G-2 Discovery 已证实：仅文章详情（`resolveContent`）与 Landing（`resolvePage`）走 SeoMetaResolver，其余 13 类系统页（Product index/line/show、Solution index/show、Factory、Cooperation、Knowledge index/channel、Category、About profile/history/culture、Contact、Search）的 `$seo` 均为**控制器内手工组装数组**，旁路统一 Resolver。

用户对 18G-2 的正式裁定（18G-2a 部分）：

1. **路线 A — 统一 Render Context 管线**：Public Request → Page / Entity / Listing Context → Template Resolver → Block System → Content/Entity/Relation/Media → SEO/GEO/Schema/PublicIndex/Cache。三类 Context 数据源可不同，但渲染 / SEO / GEO / Schema / URL / Cache 必须走同一公共管线，禁止各 Controller 自拼 SEO。
2. **Detail override 方案 ii**：`pages.entity_id` nullable；固定槽由 Entity 直驱，可组合槽支持 Page-level override（整槽替换）；**Page 只存结构 / 覆盖 / 展示配置，绝不复制 Product 业务数据**。
3. **Detail 新增 block 保持通用**：禁止按行业建 ChemicalSpecificationsBlock / FactoryBlock / MedicalBlock。
4. **grid 至少 all / current / related**，由 Context 提供。
5. **TD-64 在 2a 一起修**。

---

## 2. 架构改动（统一 Render Context）

新增 / 收口的核心类（`app/Support/Render/`）：

| 类 | 职责 |
| --- | --- |
| `RenderContext`（interface） | 统一上下文契约：site / locale / resourceType / resource / template / blocks / url / seo / schema / geo；breadcrumb 项键名冻结为 **name**。 |
| `PageRenderContext` | 持久化 Page 的 Context；blocks 来自 PageBlock；seo 走 `resolvePage`。 |
| `EntityRenderContext` | Detail Context；固定槽（header / main）由当前 Entity + Catalog 直驱，可组合槽（related）支持 override Page 整槽替换；seo 走 `resolveEntity`。 |
| `CompositionRenderer` | slotNames → blocksForSlot → render；seo 的 SeoResult 转部分键数组，统一注入。 |
| `VirtualBlock` | 合成块（固定槽的 entity_* 块），type/content/id 由 Context 生成。 |
| `BlockContract`（interface，`app/Support/Blocks/`） | Block 统一契约（含 cfg()）；`PageBlock` 实现之。 |
| `resources/views/site/composed.blade.php` | 统一组合视图：subnav / sr-only H1 / 按 slot 顺序输出。 |

接线：

- `ProductController::show`、`SolutionController::show`：改为查 `Entity::published()->forLocale(locale())->ofType(...)->where('slug',...)`（null → 404）→ `EntityRenderContext::forEntity()` → `CompositionRenderer::render()`。旧私有 `productSchema()/howTo()` 死代码删除（`subnav()` 保留供 index/line）。
- Site `PageController::renderPage`：收口为 `CompositionRenderer::render(new PageRenderContext($page))`。
- BlockType 增加 `system` 标志；`BlockRegistry::selectableForSlot()` 排除 system block；Admin add_block 视图与 `Admin\PageController::storeBlock` 双重拦截。

---

## 3. Detail 通用 system block（TD-62）

新增 5 个 **system=true** block（不进"自由添加"列表，仅 Detail Template 固定槽调用），复用原 partials、当前 Entity 直驱、无数据不渲染：

| Block | 槽 | 呈现 |
| --- | --- | --- |
| `entity_hero` | header | Detail 头部（型号 / tagline / 关键参数） |
| `entity_specifications` | main | 规格 / 参数表（`_param_table`） |
| `entity_steps` | main | 使用 / 流程步骤（`_process_steps`） |
| `entity_relations` | main / related | 关系 / 场景（`_scene_card`、`_product_card`） |
| `bottom_cta` | related | 底部统一 CTA |

均为通用块，未按行业建垂直块。

---

## 4. grid current / related（TD-63）

`BlockRegistry::resolveData` 三个 grid 传 context：

- **product**：all / line / picked / **current**（当前系列）/ **related**（Catalog 关系）。
- **service**：all / picked / **related**（prev/next 相邻 + Catalog）。
- **content**：latest / picked / **current**（当前栏目 category_id）。

Blade 内不再自行判断 `if ($product->id ...)`；Listing main 槽接线留待 TD-57（18G-2b）。

---

## 5. 数据与索引

- **migration `2026_09_23_000013`**：pages 加 nullable `entity_id` + 普通 index + partial unique `(site_id, entity_id) WHERE entity_id IS NOT NULL`；down 完整。不用 DB 外键（SQLite ALTER 受限），改 Entity 模型 `static::deleted` 钩子删关联 Page（Page::deleting 级联 PageBlock / SeoMeta），等价 FK ON DELETE CASCADE。
  - partial unique **不加 locale**：zh/en 详情绑定不同翻译行 Entity（id 不同），旧 unique 已正确；加 locale 反而放宽、允许永不被查询的垃圾行（该修改曾提出后完整撤回）。
- **migration `2026_09_23_000014`（TD-64 索引层）**：重建 `sites_seo_meta_unique`，谓词由 `content_id IS NULL AND entity_id IS NULL` 改为 `... AND page_id IS NULL`；down 还原。
- **SeoMetaResolver `findSiteLevelSeo`（TD-64 查询层）**：whereNull content_id / entity_id 后补 `whereNull('page_id')`。
- **PublicIndex `indexableEntitySlugs()`（TD-65）**：加 `forLocale(LocaleContext::current())`，消除跨翻译行 pluck 泄漏。

---

## 6. 测试证据

### 6.1 Focused — `tests/Feature/DetailComposition18G2Test.php`

**21 passed / 41 assertions / 0 failed / 0 skipped**，覆盖八类契约：

- A Product detail section 顺序 / H1=Entity.name / 非 core·缺失 404。
- B Service detail section 顺序 / 缺失 404（尾斜杠 301 由独立 CanonicalizeSlashTest 锁定）。
- C TD-61（P0）：entity-level SeoMeta 的 title / description / canonical / OG / noindex 前台真实消费。
- D 方案 ii：override Page 整槽替换 related、固定槽仍 Entity 直驱、draft override 不生效、Page 不复制业务数据。
- E Draft Entity 404 / 跨站 Entity detail 404。
- F Entity 更新经模型钩子失效详情整页缓存。
- G Locale：en product/service detail 走 /en、英文化、canonical / html lang 正确。
- H 删 Entity 级联 override Page（block / seo）、同 Entity 不允许两个 override Page。
- TD-64：site-level 与 page-level SeoMeta 共存且分别解析。

### 6.2 Full Regression

**919 passed / 4798 assertions / 0 failed / 0 skipped**（较 18G-1 的 898 / 4745 新增 21 tests，只增不减）。证据：`D:\Temp\18g\full-regression-2a.txt`。

---

## 7. 真实 HTTP / 浏览器对拍

### 7.1 迁移前 vs 迁移后（旧基线 `c3ccd07` worktree + 三 serve）

环境：旧 demo 8121（c3ccd07 install+seed）、新 demo 8122（当前 install+seed）、新 blank 8123（当前 install 不 seed）。旧 worktree 经实体复制 vendor 保证独立（Junction 会解析真实路径致类源串仓）。

**Product `/products/epoxy-primer-100`**：status 200→200；description 完全一致；canonical 路径一致（仅端口）；robots 一致；H1 一致；ld+json 类型完全一致（14 类：Answer / Brand / BreadcrumbList / FAQPage / HowTo / HowToStep / ItemPage / ListItem / Organization / Place / PostalAddress / Product / PropertyValue / Question）；section 顺序完全一致（`page-hero prod-hero | sec sec-tint | sec | sec | sec sec-tint | bcta`）。

**Service `/solutions/equipment-manufacturing/`**：status 200→200；canonical 路径一致；robots 一致；H1 一致；ld+json 类型完全一致（9 类：Answer / BreadcrumbList / FAQPage / ListItem / Organization / Place / PostalAddress / Question / WebPage）；section 顺序完全一致（`page-hero | sec | sec sec-tint | sec is-inverse | sec | sec sec-tint | sec | bcta`）。

**有意收敛（非缺陷）**：

- title 收敛为 Entity.name（+ 站点后缀），与 Schema / GEO name 一致，消除控制器手工 SEO 分叉。
- Service description 由旧长 combo 拼接文收敛为 Entity.summary 短文案。

### 7.2 entity SEO HTTP 实测（TD-61 P0）

tinker 给 zh product（entity id=2）建 SeoMeta（title / description / canonical / OG），HTTP 抓详情：自定义 title、description、canonical、og:title **全部真实消费**。

### 7.3 TD-65 noindex / sitemap locale 隔离

置该 meta noindex：product robots = `noindex, follow`；zh `/sitemap.xml` 该 slug count = **0**（移除）；en `/en/sitemap.xml` count = **1**（en 行未 noindex 仍收录）—— locale 隔离正确。

### 7.4 空站（8123）

title / H1 = **GEO Website OS**（中性）；违禁词扫描（Sample Snack / Sample Marinade / Sample Breading / 撒料 / Demo Tenant A / Demo Tenant A / 400-001 / 图们 / 金家街 / Sample City / Sample Province / 示例制造有限公司 / 工厂 / 车间 / OEM）= **NONE**；sitemap `<url>` count = 2（仅中性页）；robots.txt 中性且 GEO/AI 爬虫放行规则完整；llms.txt 中性，明确"站点未配置业务事实库，仅列真实存在的页面入口"。

### 7.5 真实浏览器四组合（zh/en × light/dark）

- zh+light、zh+dark、en+light、en+dark **全部通过**；`<html lang>` 随 locale 正确；title 中/英正确（示例制造有限公司 / Example Manufacturing Co., Ltd.）。
- Dark 机制：`<html data-color-scheme="dark">`、localStorage 键 `gwos-color-mode`（light/dark/system）、按钮 `[data-theme-toggle]`；干净重载后 **console = 0 error**、深色状态记忆保持、locale 切换不丢 theme。
- console 早期 4 个 `ERR_CONNECTION_REFUSED` 经 network 核实来自 **8139 浏览器启动器页**（browser-launch）的 favicon 历史请求，与产品 8122/8135 无关；产品自身资源（/、/img/logo.png、favicon）全部 ok。

### 7.6 TD-67（本轮浏览器 a11y 复验发现，已修复）

外观切换按钮 aria-label / title 引用 `ui.mode_aria_toggle`，但该键在 zh-CN / en ui.php 均缺失，按钮对屏幕阅读器显示原始键名。已在两个 ui.php 补键（中：切换外观模式（浅色 / 深色 / 跟随系统）；英：Toggle appearance (light / dark / follow system)）。全新端口 8135 serve 验证按钮 accessible name 中/英正确（8122 旧进程缓存异常、8135 正常，证明修复有效）。**TD-67 CLOSED**。

### 7.7 Multi-Site × Locale

`Localization18FTest::test_en_only_site_is_isolated_and_zh_routes_404`（走 HTTP kernel、Host header 模拟多站）：Site A 双语 + org + product；Site B（acme.test）en-only；B zh 路由（/、/sitemap.xml）**404**；B en 路由 200 且含 Acme Global、不含 A 的产品；B sitemap 只列 acme.test/en、不含 demo-product；A 看不到 B。该测试在全量回归 919 中通过；跨站 detail 隔离另由 `test_cross_site_entity_detail_is_404` 锁定。

---

## 8. 污染、日志与清理

- **Runtime 业务污染 = 0**：app / resources / config 全量 grep 强身份词（Demo Tenant A / Demo Tenant A / 400-001 / Sample Snack / Sample Marinade / Sample Breading / 撒料 / 图们 / 金家街 / Sample SaaS）= 0 matches；`BusinessPollutionZeroTest` 在全量回归通过。
- **Log audit**：laravel.log 最后写入 2026-09-23 20:44:11；**本次 Gate 验证窗口（09-24）零新增 ERROR / CRITICAL**。历史 ERROR 均为 09-23 开发阶段已修复问题（admin pages form/block_form）或一次性 tinker 输入 ParseError，非产品缺陷。
- **Smoke cleanup**：临时 DB、serve、cookie、cache、端口在收尾统一清理（见 §10）。

---

## 9. TD 状态裁定

| TD | 状态（18G-2a 后） |
| --- | --- |
| TD-62 Detail Resource Renderer | **CLOSED** |
| TD-63 grid current / related | **CLOSED** |
| TD-64 site-level SEO page_id 边界（查询 + 索引） | **CLOSED** |
| TD-65 indexableEntitySlugs locale | **CLOSED** |
| TD-67 外观切换 aria 键 | **CLOSED** |
| TD-61 系统页 SEO 统一 | **PARTIAL**（Product/Service Detail 走 resolveEntity 收口；Listing / 其余系统页手工 SEO 待 18G-2b） |
| TD-56 Detail Composition | **PARTIAL**（Product/Service Detail 已迁；Article 结构待 2b / 裁定） |
| TD-58 Page Composition Manager | **PARTIAL**（剩余系统页 Manager 待 2b） |
| TD-66 SeoMeta 编辑不跟随 locale（en SEO 覆盖无法管理） | **ACTIVE**（已登记 Blocks v1.0.0 YES；修复阶段待用户裁定，建议 18G-2b 或专门 locale 收尾；本轮按边界纪律未修） |

**v1.0 Required 未闭合 = 9**：P0×3 外部发布工程（TD-01 / TD-02 / TD-03）+ 代码层 ×6（TD-56 PARTIAL / TD-57 / TD-58 PARTIAL / TD-59 完整 Form Builder 归 18H / TD-61 PARTIAL / TD-66）。

---

## 10. 边界纪律（继续生效）

- 不移动 `v1.0.0-rc1`（`965d63c` HOLD）；不配置 remote；不 push；不重建 RC；不 Release。
- 不做完整 Form Builder（归 18H）、不做自由拖拽 Builder、不做三级 RBAC。
- Product/Service Detail 不复制 Entity 数据、不形成第二事实源；新增 block 必须通用。
- 18C 已 CLOSED 债务不重开；新问题续号登记（TD-66、TD-67）。

---

## 11. Gate 结论

| Gate 项 | 结果 |
| --- | --- |
| Focused tests | ✅ 21 / 41 / 0 / 0 |
| Full regression | ✅ 919 / 4798 / 0 / 0 |
| Fresh install / seed | ✅ |
| Blank / Demo HTTP | ✅ |
| 迁移前后对拍 | ✅（公开契约无破坏；title/desc 有意收敛已说明） |
| Browser 四组合 | ✅（console 0、dark 记忆） |
| Multi-Site × Locale | ✅ |
| SEO / GEO / Schema / Sitemap | ✅ |
| Cache invalidation | ✅ |
| Runtime pollution | ✅ 0 |
| Log audit | ✅ 窗口内 0 新增 |
| Git commit / annotated tag / worktree | ✅ `checkpoint-18G-2a` / clean |

**P-STEP 18G-2a = PASS / STOP。**

下一阶段 **18G-2b（Listing + 固定系统页 Composition 迁移 + Block CRUD 完整化 + TD-61 完整 CLOSED）** 须经明确授权后启动；**TD-66 修复阶段待用户裁定**。
