# P-STEP 18G-2 · Discovery — Detail / Listing Composition Migration

> 阶段：P-STEP 18G-2（系统页向 Template + Page + Block Composition 迁移）
> 子步骤：**18G-2 DISCOVERY（现状取证）**
> 基线 HEAD：`c3ccd07`（= annotated tag `checkpoint-18G-1`）
> Regression：898 passed / 4745 assertions / 0 failed / 0 skipped
> Worktree：clean（Discovery 全程零代码改动）
> 日期：2026-09-23
> 方法：逐文件实读 routes / 11 个 Site 控制器 / 相关 Blade / AppServiceProvider / Block·Template 配置 / SeoMetaResolver，不凭旧报告推断。

---

## 1. 盘点目标

18G-1 已让 **Home（page='home'）** 与 **Landing（Page + PageBlock）** 进入组合架构。本阶段要判定其余系统页——Product / Service（Solution）/ Article（Content）/ 各类 Listing / Contact / About / Factory / Cooperation——能否、以及如何迁入同一套 Composition，且：

- **不复制 Entity 数据**（不生成"Product Page Content"第二份事实）；
- **不破坏既有公开契约**（URL、canonical、404 门禁、sitemap/llms/rss/geo、多站隔离、locale）。

---

## 2. 页面 × 路由 × 控制器 × 视图 × 数据源 × SEO 全景矩阵

| # | 页面 | 路由（zh / en） | Controller 方法 | 视图 | 数据源 | SEO 来源 |
|---|---|---|---|---|---|---|
| 1 | 首页 | `/` `/en/` | `HomeController::index` | `site.home` + `site/home/{type}`（16 block） | PageBlock(page=home) + Catalog 投影 | 站点级（composer/手工） |
| 2 | 产品总览 | `/products/` | `ProductController::index` | `site.products.index` | Catalog::productLines / productsByLine / company；flatMode 平铺 | **手工数组** |
| 3 | 产品系列 | `/products/{line}/` | `ProductController::line` | `site.products.line` | Catalog::line / productsByLine；空系列 301 | **手工数组** |
| 4 | 产品详情 | `/products/{slug}` | `ProductController::show` | `site.products.show` | Catalog::product / relatedProducts / scenesOfProduct / Pages::faqs | **手工数组**（不调 resolver） |
| 5 | 场景总览 | `/solutions/` | `SolutionController::index` | `site.solutions.index` | Catalog::scenes | **手工数组** |
| 6 | 场景详情 | `/solutions/{scene}/` | `SolutionController::show` | `site.solutions.show` | Catalog::scene / sceneCombo / adjacentScenes / keyProduct / Pages::faqs | **手工数组**（不调 resolver） |
| 7 | 工厂与资质 | `/factory/` | `FactoryController::show` | `site.factory` | Catalog::company / workshops / certifications / stats | **手工数组** |
| 8 | 合作方式 | `/cooperation/` | `CooperationController::show` | `site.cooperation` | Catalog::cooperation / Pages::faqs | **手工数组** |
| 9 | 知识总览 | `/knowledge/` | `KnowledgeController::index` | `site.knowledge.index` | Content（Category slug=knowledge）/ Group；paginate(12) | **手工数组** |
| 10 | 知识栏目 | `/knowledge/{channel}/` | `KnowledgeController::channel` | `site.knowledge.index` | Group knowledgeChannels；非栏目转 dispatch | **手工数组** |
| 11 | 文章详情 | `/{cat...}/{slug}` | `PageController::dispatch → renderContent` | `site.content` | Content + category/related | **SeoMetaResolver::resolveContent** ✅ |
| 12 | 栏目页 | `/{cat...}/` | `PageController::dispatch → renderCategory` | `site.category` | Category / children / groups / Content(paginate) | **手工数组**（category.seo_title/desc） |
| 13 | 关于（profile/history/culture） | `/about/{page}/` | `AboutController::page` | `site.about.{page}` | Catalog::company / brandLanguage / Pages::about | **手工数组** |
| 14 | 联系 | `/contact/` | `ContactController::show` | `site.contact` | Catalog::company + 全局 `siteSettings`（contact_*） | **手工数组** |
| 15 | 搜索 | `/search` | `SearchController::index` | `site.search` | SearchResult（Content；Entity 搜索另登记） | 手工 |
| 16 | 组合落地页 | `/{slug}` | `PageController::dispatch → renderPage` | `site.page` | Page + PageBlock(page) | **SeoMetaResolver::resolvePage** ✅ |

**观察**：只有 #11（文章详情）与 #16（Landing）走 SeoMetaResolver；其余 13 类页面的 `$seo` 均为控制器内手工组装数组。

---

## 3. Detail 固定 section 取证

### 3.1 Product Detail（`site/products/show.blade.php`）— 6 section + bottom CTA

| section | 内容 | 数据 | 现有 Block 可对应？ |
|---|---|---|---|
| 1 产品定位 Hero | tag / H1 / lead / mains / 双 CTA / key_params 卡 | product + line | ❌（通用 hero 不含 key_params 卡 / mains） |
| 2 使用步骤 HowTo | 分步 title+text | product.params | ❌（无 process/steps block） |
| 3 适用场景 | scene chips（链接） | scenes | △（无 chip 列表，service/content grid 形态不同） |
| 4 规格与交付 | spec 表（net/packaging/shelf/storage/moq） | product 字段 | ❌（无 spec/param table block） |
| 5 相关产品 | product grid（3 卡） | relatedProducts | ✅ `product_grid`（但需 related 上下文） |
| 6 产品 FAQ | FAQ 列表 | Pages::faqs | ✅ `faq` |
| — bottom CTA | CTA 色带 | — | ✅ `cta` |

### 3.2 Solution Detail（`site/solutions/show.blade.php`）— 7 section + bottom CTA

| section | 内容 | 数据 | 现有 Block 可对应？ |
|---|---|---|---|
| 1 问句式 Hero | eyebrow / H1(title_q) / desc | scene | △ 通用 hero 可近似 |
| 2 痛点 | pain grid（title+desc 卡） | scene.pain_points | △ feature_grid 可近似 |
| 3 推荐组合 | combo product grid + reason | sceneCombo | ✅ `product_grid`（需 combo/related 上下文） |
| 4 关键参数 | inverse param 表（P0 keyProduct / P2 param_note） | scene + keyProduct | ❌ param table |
| 5 使用流程 | process steps | keyProduct.params | ❌ process steps |
| 6 场景 FAQ | FAQ | Pages::faqs | ✅ `faq` |
| 7 相邻场景 | prev/next adj 卡 | adjacentScenes | ❌（无 adjacent/导航 block） |
| — bottom CTA | CTA 色带 | — | ✅ `cta` |

**结论**：16 Core Block 可表达 Detail 的"可运营尾部"（related / FAQ / CTA），但 **detail header、spec/param table、process steps、scene chips、adjacent 等强结构、Entity 直驱区域无对应渲染器**。这些区域数据高度结构化、只读、且必须严格来自 Entity——不应让管理员自由编辑或复制。

---

## 4. Listing / 单页结构取证

- **产品总览 / 系列**：page-hero + 按系列分组的 product grid（系列锚点 / 计数 / 系列空 301）+ bottom CTA。
- **场景总览**：page-hero + scene grid（`_scene_card`）+ bottom CTA。
- **知识总览 / 栏目**：page-hero + kgrid（封面卡，paginate 12）+ 分组 tag 筛选 + pager。
- **栏目页（category.blade）**：page-head + 子栏目 pcard grid + 分组 tag + 内容列表（产品叶子栏目用 pcard，其余用 posts）+ 分页；纯父栏目不渲染空列表。
- **Contact**：page-hero + `contact-grid`（左：Catalog company + siteSettings 的电话/手机/邮箱/地址/营业时间/目标客户/销售区域/微信二维码；右：`_lead_form`）。
  - `site.contact` 消费的 `$siteSettings`（contact_mobile/email/hours(_en)/map_url/wechat_qr）由 AppServiceProvider 全局 composer 注入，**Contact 非断点**。
- **About（profile/history/culture）**：Catalog company + Pages copy；profile 正文、history 节点（缺省由 facts 成立/投产时间数据驱动）、culture 卡片；含 about subnav。
- **Factory**：仅在 `Catalog::hasProduction()` 时渲染（否则 404）；workshops / certs（资质编号缺失整段隐藏）/ stats（非 0 才显示）/ ImageObject（仅有实拍图）。
- **Cooperation**：cooperation types + process（HowTo）+ FAQ；起订量/交付周期未核定则隐藏。

### 4.1 Listing 共同模式

`resource-bound header（栏目名/导语）+ 资源卡片 grid（随上下文变化）+ 分组/筛选 + 分页（resource-bound）+ 可选 sidebar`。

现有 grid block（product_grid / service_grid / content_grid）的 source 模式为：
- product_grid：`all / line / picked`
- service_grid：`all / picked`
- content_grid：`latest / picked`

**无 "current（当前栏目/系列上下文）" 与 "related（当前 Entity 相关）" 模式**——这是 Detail/Listing 模板默认 block 所必需。

---

## 5. 全局 View Composer 注入清单（迁移视图可直接复用）

`AppServiceProvider::boot` 对 `layouts.site / site.* / components.* / partials.* / errors.* / admin.*` 注入：

| 变量 | 内容 |
|---|---|
| `siteSettings` | `Setting::allCached()` |
| `themeTokens` | `ThemePalette::resolve($settings)`（18D 语义令牌） |
| `darkTokens` | `ThemePalette::darkOverrides($settings)` |
| `navTree` / `mainMenu` / `footerMenu` / `footerExtra` | 导航/菜单（is_nav 栏目树） |
| `ctaText` | 主 CTA 文案（默认语言取 setting，非默认语言回退翻译） |
| `publicFacts` | `Fact::publicMap()` |
| `leadAttr` | 留言归因（CaptureAttribution 写 session） |

另：`SeoHeadComposer` 注册于 `layouts.site`（在全局 composer 之后），归一化头部 `$seo`。

---

## 6. 关键架构发现（Gap）

### Gap A — 系统页 SEO 双轨（最重要）

- 走 Resolver：文章详情（resolveContent）、Landing（resolvePage）。
- 手工数组：Product（index/line/show）、Solution（index/show）、Factory、Cooperation、Knowledge、Category、About、Contact、Search。
- 后果：**管理员在 17D 为 Product / Service Entity 设置的 entity-level SeoMeta，前台 Detail 不消费**（控制器用 `__('seo.product_show_title')` 等手工拼接，旁路 resolver）。同理站点级/系统页 SeoMeta 对各系统页不生效。
- 这与"所有 SEO 输出最终走统一 Resolver、禁止 Controller/Blade/config 第二套 fallback"直接冲突，是"后台 SeoMeta 前台不消费"的真实断点。

### Gap B — Detail 资源渲染器缺失

Detail header（name/tagline/key facts/mains/CTA）、spec/param table、process steps、scene chips、adjacent 等 Entity 结构化只读呈现，没有注册渲染器；16 Core Block 无法表达 Detail 全貌。这些区域不应自由 block 化（否则诱导复制数据 / 存任意 HTML）。

### Gap C — grid data_source 缺上下文模式

product/service/content grid 需新增 `related（当前 Entity 相关）` 与 `current（当前栏目/系列上下文）` 模式，供 Detail/Listing 模板默认 block 使用。

### Gap D — site-level SeoMeta 查询未排除 page_id

`findSiteLevelSeo()` 仅 `whereNull('content_id')->whereNull('entity_id')`，**未显式 `whereNull('page_id')`**。page-level SeoMeta（page_id 非空、content/entity 为 null）可能被误取为站点级 SEO。需补 `whereNull('page_id')`。

---

## 7. 16 Core Block 覆盖度对拍（Detail/Listing）

| 页面区域 | 覆盖情况 |
|---|---|
| related product / combo grid | ✅ product_grid（缺 related/combo 上下文 → Gap C） |
| FAQ | ✅ faq |
| bottom CTA | ✅ cta |
| 痛点 / 特性 | △ feature_grid（可近似，非完全） |
| 列表内容 grid | △ content_grid（缺 current 栏目上下文 → Gap C） |
| Detail header | ❌ Gap B |
| spec / param table | ❌ Gap B |
| process / HowTo steps | ❌ Gap B |
| scene chips / adjacent | ❌ Gap B |
| 分页 / 筛选 tag | ❌ resource-bound（应随 Template，非自由 block） |
| Contact 信息 | ✅ contact_info（+ Catalog/settings） |
| Contact 表单 | ✅ form_reference（最小闭环；完整 Form Builder → 18H） |

---

## 8. 建议新增 TD（不覆盖旧编号；当前最大 TD-60）

| ID | 优先级 | 标题 | 归属 |
|---|---|---|---|
| **TD-61** | P0（Release Blocker） | 系统页 SEO 双轨：Product/Service Detail、Listing、单页控制器手工 SEO，未走 SeoMetaResolver，Entity/系统页 SeoMeta 前台不生效 | Epic #116 |
| **TD-62** | P1 | Detail 资源渲染器缺失：detail header / spec table / process steps / scene / adjacent 的 Entity 直驱注册 renderer | Epic #116 |
| **TD-63** | P2 | grid data_source 扩展：related / current 上下文模式 | Epic #116 |
| **TD-64** | P2 | findSiteLevelSeo 补 whereNull('page_id')，防 page-level 误判为站点级 | Epic #116 |

（最终状态、验收标准、与 TD-56~59 的关系，见姊妹文档《page-composition-migration-architecture-18g2.md》。）

---

## 9. Discovery 结论

1. Home / Landing 已组合化；**其余系统页仍是 Controller → 固定 Blade**，Template 层对这些页面实际不存在。
2. Detail 不应完全自由化：强结构、Entity 直驱区域应作为 **Template 的 resource-bound renderer（不复制数据）**，可运营尾部走 **可组合 block 槽位**。
3. Listing 由 Listing Template + context grid + resource-bound 分页/筛选组合。
4. **最大缺口是 SEO 双轨（TD-61）**：17D 的 SeoMeta 控制面对 Entity/系统页的设置未接入前台。
5. 系统页是否整体 **Page 化（单一管线）**，还是 **保留 Controller + 扩展 resolver（增量）**，是需用户拍板的核心架构决策（方案与权衡见 architecture 文档）。

**Discovery 到此 STOP，不进入实现；待架构裁定与用户 Gate 确认。**
