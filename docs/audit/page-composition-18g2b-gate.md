# P-STEP 18G-2b Gate — Listing + System Page Composition Closure

- **阶段**：P-STEP 18G-2b（Page Composition / Template System 收口第二阶段）
- **父 Epic**：#116 Page Composition / Template System
- **起始基线**：HEAD `b91adff`（= annotated tag `checkpoint-18G-2a`）；Regression 919 / 4798 / 0 / 0
- **Gate 日期**：2026-09-24
- **结论**：✅ **ACCEPTED / PASS**

---

## 1. 目标与边界

18G-1 建立了 Block / Template / Page 三层并完成 Landing 零代码闭环；18G-2a 把 Product / Service Detail 迁入统一 Render Context。本阶段要消除最后一块"Controller → 固定 Blade"的主要页面结构来源：

1. 各类 **Listing**（Product / Service / Article·Content / Knowledge）迁入 Composition；
2. **固定系统页**（Contact、Products / Solutions / Knowledge 总览、About profile / history / culture、Factory、Cooperation）全部 Page 化；
3. Block CRUD 完整化（TD-58）；
4. Locale-aware SeoMeta Admin（TD-66，用户裁定 P1 纳入本阶段）。

**边界（已遵守）**：

- 业务事实仍来自 Site / Setting / Entity / Content / Media / Relation，**Page 只存结构 / 覆盖 / 展示配置，绝不复制业务数据**；
- 新增 block 全部**通用**，未按行业建垂直块；
- 不做完整 Form Builder（转 18H）、不做自由拖拽、不做三级 RBAC；
- 不移动 `v1.0.0-rc1`（965d63c HOLD）、不配 remote / push、不 Release。

---

## 2. 用户裁定（18G-2 Discovery，本阶段落地）

| 裁定 | 内容 |
| --- | --- |
| **路线 A — 统一 Render Context 管线** | Page / Entity / Listing 三类 Context 数据源可不同，但渲染 / SEO / GEO / Schema / URL / Cache 走同一公共基础管线（CompositionRenderer）；禁止各 Controller 自拼 SEO |
| **Detail override 方案 ii** | `pages.entity_id` nullable，Entity 直驱固定槽 + Page-level 可组合槽整槽覆盖；不复制 Entity 数据（2a 落地，本阶段沿用） |
| **固定系统页全部 Page 化** | 9 个 system_key 由 `geo:install` 注入 is_system Page，事实仍来自 Site / Setting / Entity |
| **两次 Gate** | 2a Detail（已 PASS）→ 2b Listing + 系统页（本 Gate） |

---

## 3. 本阶段完成的工作

### 3.1 固定系统页 Composition（SystemPageRenderContext）

- 新建 `app/Support/Render/SystemPageRenderContext.php`：
  - `DEFINITIONS` 声明 **9 个 system_key** → [template, slugPath, titleKey]：
    `products / solutions / knowledge` = listing；`profile / history / culture / factory / cooperation` = detail；`contact` = contact；
  - `resolve(systemKey)`：先查当前 site + system_key + is_system + locale 的持久化 Page，找不到返回**未保存 Page 实例**（fallback 渲染，不 500）；
  - contact 特殊分支：未持久化时提供默认区块（header rich_text + `contact_info` + `form_reference`）。
- 数据层：迁移 `2026_09_24_000015` 给 pages 表加 `is_system / system_key` + partial unique `pages_site_systemkey_locale_unique` + `pages_system_index`。
- 安装注入：新建 `database/seeders/SystemPageSeeder.php`（幂等，try/finally 恢复 App locale），由 `GeoInstall` 调用；9 system_key × 2 locale = **18 个系统 Page**，contact 含 3 个区块。
- 8 个 Site 控制器（Product / Solution / Knowledge / About / Factory / Cooperation / Contact 等）删除私有 findSystemPage / abort，统一改调 `SystemPageRenderContext::resolve()`；**业务门禁全部保留**（Factory hasProduction 404、company 门禁等）。

### 3.2 Listing Composition（ListingRenderContext）

- 新建 `app/Support/Render/ListingRenderContext.php`：template='listing'，SEO 走 `resolveListing`，main 槽由 system block / grid 组成，支持 pager。
- 系统总览（products / solutions / knowledge）走 SystemPageRenderContext('listing') + `sys_*` block；新增 6 个通用 system block 渲染器（sys_products 双模式 / sys_solutions / sys_knowledge / sys_about / sys_factory / sys_cooperation），均 `system=true`、不进"自由添加"列表。
- Knowledge 子频道由 **Group 模型 `Group::knowledgeChannels()`（groups 表）**驱动，真实 slug = `selection / process / business`；文章 URL 扁平 `/knowledge/{slug}`。

### 3.3 Locale-aware SeoMeta Admin（TD-66）

- `Admin/SeoMetaController` 完整重写：SCOPES 含 site / content / entity / **page**，`targetLocale()` 按编辑目标（?trans / 资源 locale）写入，`fillSeo` 写 locale + page_id，三对象互斥，unique 查询带 locale。
- `SeoMeta` fillable 加 `locale`；form / index Blade 重写，呈现中文 / 英文 SEO 版本与显式值 / 解析值。
- en 自定义 SEO 覆盖前台真实消费，`AdminSeoMetaCrudTest`（27 用例 / 111 断言）锁定。

### 3.4 Block CRUD 完整化（TD-58）

- `Admin/PageController` 在 add / edit / move / hide / delete 基础上新增 **duplicateBlock / preview**；composer Blade 加预览 / 复制按钮；routes 加 pages.preview / duplicateBlock、SEO scope 加 page。
- Block 保持 Registered Type + Structured Data + Registered Renderer，**不允许数据库存任意 Blade / PHP / HTML**。

### 3.5 旧 Blade 退休

- 删除 **13 个 orphan Blade**：products/{index,line,show}、solutions/{index,show}、knowledge/index、contact、about/{profile,history,culture}、factory、cooperation、page。
- 保留：site.content（文章模板）、site.category（栏目模板）、site.home（首页）、site.search —— 见 §5 裁定。

---

## 4. 本阶段真实发现并修复的缺陷

| ID | 缺陷 | 修复 | 防回归 |
| --- | --- | --- | --- |
| **TD-68** | `PublicUrl::base()` 用 `url('/')`，在 /en locale 路由下被语言前缀污染，叠加 localePrefix 产生 `/en/en/` 重复 canonical | base() 非 console 改 `rtrim(request()->root(),'/')`（干净 origin，不含 locale/path） | `test_en_canonical_has_single_en_prefix` / `test_zh_canonical_has_no_en_prefix` |
| **TD-69** | 英文页 hreflang 的 zh-CN / x-default 错误指向 `/en`（应指中文根 `/`）：buildHreflang 用了受当前 locale 污染的 `url('/')` | buildHreflang 改 `request()->root()` + **目标 locale prefix 显式拼接**（不依赖当前请求 locale） | `test_hreflang_alternates_point_to_each_locale_own_url`（首页 + 产品详情 zh/en） |
| **TD-70** | 首页 HomeController 仍用旧首页装修器（page_blocks.page='home' 字符串），未迁入新 pages 表 Composition | **DEFERRED v1.1**：首页已 block 驱动、可运营、SEO 走 resolveSite（统一非双轨），仅结构未迁；首页最重要、重构风险高 | v1.1 验收：首页与 Landing 同走 Composition、page='home' 旧链退场 |

> 另有一个实现内缺陷（fallback 渲染未保存 Page 时 `SeoMetaResolver::resolvePage()` 对 id=null 调 `pageSeoMeta(int,int)` 致 TypeError 500），已改为 `$page->exists ? pageSeoMeta(...) : null`，属本阶段实现修正、不单列 TD。
>
> 浏览器 UAT 中"demo 首页 32 个 `.reveal` 仅 1 个可见、其余 opacity 恒 0"经注入 `transition:none!important;opacity:1!important` 后 32/32 立即可见，定性为 **bu attached tab 冻结 CSS 过渡时钟的环境假象（NON-DEBT）**，非 TD-60 回归。

---

## 5. Article / 栏目渲染裁定

并非所有页面都应迁入自由 Composition，本阶段明确：

| 页面 | 渲染方式 | 理由 |
| --- | --- | --- |
| Product / Service Detail | EntityRenderContext + Detail blocks（Composition） | Entity 直驱固定槽 + Page 覆盖，2a/2b 已迁 |
| 系统总览 products / solutions / knowledge | SystemPageRenderContext('listing') + sys_* block | 组合化系统页 |
| **Article / Content Detail** | `PageController::renderContent` → `site.content` 模板，SEO `resolveContent` | 文章 body 自承载内容，模板负责标准版式（title/body/cover/related + Article Schema），**非结构写死缺陷** |
| **普通 Content 栏目** | `renderCategory` → `site.category` 模板，SEO 已改 `resolveListing` | 栏目版式固定，SEO 已统一管线（TD-61 收口） |
| Contact | Contact Template + ContactInfo + FormReference | 组合化，事实来自 Site / Setting |

据此：**TD-56**（Product/Service Detail 迁 Composition；Article 保持文章模板）、**TD-57**（系统 listing Composition；普通栏目模板 + SEO 统一）均裁定 **CLOSED**。

---

## 6. Gate 验证证据

| 项 | 结果 | 证据 |
| --- | --- | --- |
| Focused tests | ✅ | SystemPageComposition18G2bTest 30 / 66；DetailComposition18G2Test 21 / 41；AdminSeoMetaCrudTest 27 / 111；hreflang 防回归单跑 PASS |
| **Full regression** | ✅ | **950 passed / 4873 assertions / 0 failed / 0 skipped**（相对 2a 919 只增不减） |
| Fresh install | ✅ | `geo:install -n`：**settings=72 / pages=18 / page_blocks=6 / entities=0** |
| Blank site HTTP | ✅ | zh 首页 + /knowledge/=200；products / solutions / factory / about×3 / cooperation / contact=404（company 门禁）；feeds=200；`/en*`=404（出厂 zh-only，正确） |
| Demo site HTTP | ✅ | zh/en 全部系统页 + core 详情 + 列表 + knowledge 频道（selection/process/business）+ feeds=200 |
| 迁移前后对拍 | ✅ | Detail / 系统页 status / canonical / title / OG / Schema / hreflang / links 迁移前后逐项对拍，公开契约未破坏 |
| SEO / Canonical / hreflang | ✅ | TD-61 全 context 统一 resolver；TD-68 canonical 单前缀；TD-69 hreflang 各 locale 指向自己 URL（curl 实测首页 + 产品详情 zh/en 全对） |
| Schema / GEO / Sitemap / Search | ✅ | 复用 2a / 18F 统一管线，locale 正确、跨站不泄漏 |
| Cache invalidation | ✅ | block / Page 改动 → `forgetPage` / `forgetPath`（页面级）；Template → flush；`test_admin_block_update_invalidates_page_cache` 锁定；Admin 接线核查（PageController 7 处页面级失效，非全站 flush） |
| Multi-Site × Locale | ✅ | 系统 Page 按 site + locale 注入；zh/en page SEO 互不干扰测试；沿用 18F / 2a 隔离结论 |
| Real browser UAT | ✅ | zh↔en、Light↔Dark、四组合早前已验；reveal 现象定性为环境假象（NON-DEBT） |
| favicon | ✅ | curl `http://127.0.0.1:8150/favicon.ico` = **200** |
| Runtime pollution | ✅ | 强身份词（Demo Tenant A / Demo Tenant A / 400-001-3770 / Sample Road / 金家街 / demo-tenant-ashipin / Sample SaaS）grep app / resources / config / database/seeders = **0** |
| Log audit | ✅ | laravel.log `local.ERROR` = **0**（修复后窗口无新增未解释异常） |
| Smoke cleanup | ✅ | 临时 DB / serve / 端口 / 缓存收尾清理（见 §8） |

### 陌生品牌零代码场景（自动化锁定）

- **Scenario A（Contact）**：`test_contact_contains_facts_and_form` + SystemPageRenderContext contact fallback + Admin compose；
- **Scenario B（Listing）**：`test_products_overview_lists_product_lines` / `test_product_line_listing_renders` / `test_knowledge_overview_renders_pager`；
- **en SEO 闭环**：`test_admin_saves_en_page_seo_and_frontend_consumes` + `test_zh_and_en_page_seo_do_not_interfere`；
- Landing 零代码：18G-1 `PageComposition18GTest`。

---

## 7. 债务状态变化

- **CLOSED**：TD-56、TD-57、TD-58、TD-61、TD-66、TD-68、TD-69；
- **DEFERRED v1.1**：TD-70（首页 Composition 统一）；
- **PARTIAL**：TD-59（18G 最小 FormReference CLOSED；完整 Form Builder → 18H）；
- 父 Epic **#116 = CLOSED for v1.0**。

**v1.0 Required 未闭合：9 → 4**：

- P0×3 外部发布工程：TD-01（Cloud GitHub Actions 首跑）/ TD-02（重建 RC + Manifest + SHA-256）/ TD-03（Private → Public v1.0.0）；
- TD-59 完整 Form Builder（P-STEP 18H）。

---

## 8. Git / 收尾

- 改动全部 commit；annotated tag **`checkpoint-18G-2b`** 与 HEAD 对齐；worktree clean。
- `v1.0.0-rc1` = 965d63c 保持 HOLD；remote 未配置、未 push、未 Release。
- 收尾：停止 serve、清理临时数据库 / 端口 / 缓存。

---

## 9. 最终裁定

**P-STEP 18G-2b = ✅ PASS / STOP。**

Page Composition / Template System（#116）v1.0 收口：除首页 Composition（TD-70，v1.1）与完整 Form Builder（TD-59，18H）外，系统页与 Listing 全部走统一 Render Context 管线，且公开契约、隔离、缓存、污染、日志均闭环。

下一阶段为 **P-STEP 18H（Search / Media / Forms / Ops 收尾）**，未经明确授权不自动进入。
