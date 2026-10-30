# P-STEP 18G — Page Composition Architecture（架构裁定建议）

- 日期：2026-09-23
- 基线 HEAD：`a5d2c5e`（`checkpoint-18F`，872 / 4655 / 0 / 0，worktree clean）
- 状态：**Discovery 架构建议，待用户 Gate 确认后进入实现**；本文档不代表已实现。
- 配套：`page-composition-discovery-18g.md`（现状取证）。

---

## 1. 目标与原则

**目标**：把"仅首页可装修"升级为"任意页面（首页 / 列表 / 详情 / 文章 / 联系 / Landing）可在后台组合"，且不改核心代码即可换品牌 / 换行业 / 换结构。

**原则（硬约束）**：
1. **不造第二事实源**：业务事实仍只来自 Content / Entity / EntityRelation / Media / Site；结构来自 Template；视觉来自 Theme。
2. **复用现有子系统**：在 `PageBlock` / `Catalog` / `PublicUrl` / `PublicIndex` / `SeoMetaResolver` / `SchemaBuilder` / `GeoGraph` / `PageCache` / Installer 上扩展，不新建平行的 localized / seo / url 体系。
3. **单向投影**：Block 引用数据源并读取，不向 Content / Entity / Catalog 反写。
4. **v1 不做自由拖拽**：用"模板槽位 + 结构化表单"组合页面；Figma 式自由画布明确放到 v1.1。
5. **可回退**：新渲染管线与旧 Blade 先并行、逐页切换、独立 commit，旧 Blade 保留到 Gate 通过。
6. **契约不破坏**：切换过程中 PublicUrl / canonical / hreflang / Schema @id / GEO / Sitemap / 多站隔离 / 缓存必须逐页对拍。

---

## 2. 目标分层模型

```
System            框架 / 系统常量（不含任何业务事实）
  ↓
Site              租户根 + Site 配置（supported/default locale 等）
  ↓
Theme             视觉 token（颜色 / 字体 / 间距 / 圆角 / 阴影 / Light·Dark）；不含结构与业务
  ↓
Template          结构：布局 + 具名槽位 slots + 每槽允许的 block 类型 + 默认 block
  ↓
Page              站点级页面实例：slug / 绑定 template / draft·publish（首页·系统页·自定义落地页统一为 Page）
  ↓
Block             槽位中的可复用单元：type + 配置 + 数据源绑定（site / locale scoped）
  ↓
Content / Entity / EntityRelation / Media      业务事实（唯一事实源）
  ↓
Frontend  →  SEO / GEO / Schema / Feed(Sitemap·LLMS·RSS) / Search
```

一个事实只允许一个 runtime source；其余层只能 fallback / computed / example seed。

---

## 3. 通用 Block Registry（解决 TD-53）

将现有"首页区块注册表"（`config/home_blocks.php`，已验证有效）泛化为**全站通用 Block Registry**（建议 `app/Support/Blocks/BlockRegistry` + `config/blocks.php`，首页 16 型作为其中一组保留）。

**每个 block type 声明**：
- `key` / `label` / `category`（layout / content / media / marketing / data / form）
- `fields`：可编辑字段与校验规则
- `data_source`：`none` / `manual_items` / `content_list` / `entity_list` / `single_entity` / `media` / `catalog_query`
- `per_locale`：内容是否按语言分离（复用 18F 规则）
- `cacheable` 与失效依赖（见 §7）
- `allowed_slots`：可放入哪些模板槽位
- `view`：默认渲染组件（建议 `resources/views/site/blocks/{type}.blade.php`）
- `schema?`：该 block 是否产出结构化数据（如 FAQ / Product / HowTo）

**首批通用 block（覆盖官网常见 80% 场景）**：

| 类别 | block type |
| --- | --- |
| 布局 / 内容 | `rich_text`、`heading`、`divider`、`columns` |
| 媒体 | `image`、`image_gallery`、`media_banner`、`video_embed` |
| 结构 / 交互 | `tabs`、`accordion_faq`、`steps`、`spec_table` |
| 营销 | `page_hero`、`feature_grid`、`cta_band`、`stats_band`、`testimonial_slider`、`logo_wall`、`pricing_cards`、`team_grid` |
| 列表 / 引用 | `content_list`（泛化现有 source 型）、`entity_list`（产品 / 服务 / 案例，读 Catalog）、`related_list` |
| 表单 | `form_block`（联系 / 询盘 / 预约 / 下载，复用 Inquiry） |

> 现有首页 `hero / scenes / capabilities / ... / facts` 16 型保持可用，逐步在 registry 中与通用型对齐（不强制重写，避免无效返工）。

---

## 4. Template Registry（解决 TD-54）

**Template = 结构的可复用定义**。建议文件注册表（随主题 / Core 分发）+ 必要的 DB 覆盖，不把纯结构写进业务表：

每个 template 声明：
- `key` / `label` / `page_type`（home / listing / detail / article / product / service / contact / landing）
- `layout`：基础布局（复用 18D layout）
- `slots`：具名槽位（如 `main` / `sidebar` / `after_content`），每槽声明允许的 block 类型、是否可空、默认 block 集合
- `binding`：可绑定的页面 / 实体类型

**首批模板**：
| template | 说明 | 由谁使用 |
| --- | --- | --- |
| `home` | 现有首页，迁移为"默认 block 布局" | 首页 |
| `listing` | 通用列表（栏目 / 产品 / 知识 / 场景），槽位：列表主体 + 侧栏 + 上下营销位 | 各 index / category |
| `detail-product` | 产品详情：槽位化现有八区块 | Product Entity |
| `detail-service` | 服务 / 场景详情 | Service Entity |
| `detail-article` | 文章详情 | Content(article) |
| `contact` | 联系页（表单作为 form_block 槽位） | 联系页 |
| `landing` | **空白槽位，自由组合**（hero / 特性 / CTA / 表单） | 自定义落地页 |

系统页（factory / cooperation / about.profile·history·culture）建议映射为"内容型 Page + 对应模板"；个别强结构页可保留固定模板，逐一裁定，不搞一刀切。

---

## 5. Page 模型与 Landing（解决 TD-55）

新增 **`Page`（site-scoped）** 作为页面实例，统一承载首页、系统页与自定义落地页：

- 字段（建议）：`site_id`、`slug`、`title`、`template_key`、`status`(draft/published)、`published_at`、`is_home`、`is_system`、`locale`、`translation_group`（复用 18F 同表翻译模型，Page identity 与 locale 分离）
- SEO：经现有 **SeoMeta**（page 级作用域），由 `SeoMetaResolver` 解析
- Block：扩展现有 **`page_blocks`**——增加对 Page 的引用（`page_id` 或沿用 page key）、`slot`、`template` 维度；现有首页行零破坏迁移
- 路由：自定义 Page 经统一分发器（`PageController::dispatch`）解析，URL 由 **PublicUrl** 裁决；"可发布 / 可索引"由 **PublicIndex** 判定

**Landing 搭建路径**：Admin 新建 Page（选 `landing` 模板）→ 向槽位添加 / 配置 block（hero / 特性 / CTA / 表单）→ 预览 → publish → 自动获得 URL / canonical / sitemap（可设 noindex）。

---

## 6. 渲染管线

```
请求
 → SetLocale + SiteContext（现有；locale / site 单一裁决）
 → 路由 / 分发器解析目标：首页 / 系统页 / 自定义 Page / 实体详情
 → 取 Page.template_key → TemplateRegistry 解析布局与槽位
 → 每槽取 active PageBlock（by sort）→ BlockRegistry 解析配置 + 数据源
      （manual / Content / Entity / Media / Catalog，批量加载防 N+1）
 → 渲染 block 组件，装配到模板
 → SEO：SeoMetaResolver（扩展支持 Page）
 → Schema：SchemaBuilder（页面级 + block 级 FAQ/Product/HowTo）
 → GEO：GeoGraph（页面不产生伪实体 / 伪边）
 → 输出并写 PageCache（key 含 site / locale / template / block version）
```

---

## 7. 与现有子系统集成（不另造体系）

| 子系统 | 集成方式 |
| --- | --- |
| PublicUrl / PublicIndex | 自定义 Page 的 URL 与"published + 可索引"判定纳入；draft / noindex / 跨站不进 feed（复用 17G 规则） |
| SeoMetaResolver | 扩展 `resolvePage()`，与 site/content/entity 同源；禁止第二套 SEO fallback |
| SchemaBuilder | Page 输出 WebPage；block 级 schema 随 block 输出；统一 @id / PublicUrl（TD-09 成果保持） |
| GeoGraph / geo.json | 实体 / 边仍只来自 Entity / EntityRelation；Page 与 block 不制造伪节点 / 伪 URL |
| Sitemap / LLMS / RSS | published Page 入图（landing 可 noindex）；列表 / 详情迁移后 URL 不漂移 |
| Search | Page / block 正文纳入检索，严格 site + locale 过滤 |
| PageCache | key 已含 site / locale；新增"block / 其数据源 → 所在 Page"失效依赖（复用 18C 失效模型，不一刀切全 flush） |
| Installer | `geo:install` 注入默认 templates + 首页默认 blocks；blank 空站不强制落地页；卸载可逆 |
| Multi-Site / Locale | Page / Template 选择 site-scoped；block 内容 per-locale；四组合（zh/en × Light/Dark）继续成立 |

---

## 8. TD-53..TD-59 正式缺口定义（建议登记）

> 建议新设父 Epic **#116 — Page Composition / Template System**，下列条目挂其下；状态拟 ACTIVE，待用户 Gate 确认后由实现阶段第一步写入 `technical-debt-registry.md`。

> **⚠️ 用户最终裁定（2026-09-23，以 `technical-debt-registry.md` 为唯一事实源；下表为 Discovery 旧草案，编号已被正式裁定取代）**
>
> | 最终编号 | 最终定义 | 计划 |
> | --- | --- | --- |
> | TD-56 | **Detail Composition**（Article / Product / Service 详情） | 18G-2 |
> | TD-57 | **Listing Composition**（Products / Solutions / Knowledge / Content 列表） | 18G-2 |
> | TD-58 | **Page Composition Manager**（Page CRUD + block 编排 Admin） | 18G-1 PARTIAL → 18G-2 |
> | TD-59 | **Form Block**（18G-1 最小 FormReference 引用现有 Inquiry；完整 Form Builder → 18H） | 18G-1 / 18H |
>
> - **缓存失效不另立 TD**：PageCache 页面级失效（path 版本号 + `forgetPath/forgetPage/forgetTemplate`）作为 Epic **#116** 在 18G-1 的验收项，已在本阶段落地。
> - 旧草案 → 最终编号映射：旧 TD-56（详情/列表）→ 新 TD-56 + TD-57；旧 TD-57（Block CRUD）→ 新 TD-58；旧 TD-58（缓存）→ 不立号、并入 #116 验收；旧 TD-59（表单）→ 新 TD-59。

| ID | Pri | Title | Source | Acceptance Criteria（验收标准） | Blocks v1.0? |
| --- | --- | --- | --- | --- | --- |
| TD-53 | P1 | 跨页面通用 Block Registry 缺失 | 18G Discovery | 存在全站 block registry；首批通用 block 可配置 / 可渲染 / 可绑定数据源；孤立写死 block=0 | 是 |
| TD-54 | P1 | Template 模型 / 注册表 / 选择器缺失 | 18G Discovery | 模板注册表含 home/listing/detail/contact/landing；声明槽位与允许 block；页面 / 实体可绑定模板；Controller 不再硬编码这些 view | 是 |
| TD-55 | P1 | Page / 自定义落地页模型与路由缺失 | 18G Discovery | 可 Admin 创建 Page（含 landing）→ 组合 block → 预览 → publish → 获 URL/canonical/sitemap；draft 不公开 | 是 |
| TD-56 | P2 | 详情 / 列表页结构可组合化 | 18G Discovery | Product/Service 详情与主列表的写死 section 迁移为模板槽位 + 默认 block；URL/SEO/GEO/Sitemap 对拍一致 | 是（最小范围） |
| TD-57 | P2 | Block 后台 CRUD 扩展 | 18G Discovery | BlockController 支持 create/store/destroy、page/slot 选择、跨页面管理（现仅 home update） | 是 |
| TD-58 | P2 | 组合页 Block 缓存与失效契约 | 18G Discovery | block 及其数据源变更按依赖失效所在页；zh/en 缓存不串页；无 stale；不盲目全 flush | 是 |
| TD-59 | P3 | Contact / 表单 block 化 | 18G Discovery | 联系表单可作为 form_block 放置于任意页；不同页可配不同表单；与表单 / Inquiry 能力债合并 | 否（可并入表单阶段 / v1.1） |

---

## 9. 建议实施任务序列（进入实现后）

| 任务 | 内容 | 关联 TD |
| --- | --- | --- |
| 18G-Impl-01 | 建立 BlockRegistry + `config/blocks.php` + 首批通用 block 与渲染组件 | TD-53 |
| 18G-Impl-02 | 建立 TemplateRegistry + 首批模板（槽位 / 默认 block） | TD-54 |
| 18G-Impl-03 | Page 模型 + 可逆 migration + Admin CRUD + 落地路由（PublicUrl/Index 接入） | TD-55 |
| 18G-Impl-04 | 首页迁移到 Template/Block（行为零变化，回归对拍） | 回归 |
| 18G-Impl-05 | Product/Service 详情 + 主列表写死 section → 槽位 + 默认 block | TD-56 |
| 18G-Impl-06 | Block 后台 CRUD 扩展（create/store/destroy、page/slot） | TD-57 |
| 18G-Impl-07 | Block→Page 缓存失效依赖 + zh/en 串页测试 | TD-58 |
| 18G-Impl-08 | form_block（可与 18H 表单阶段合并） | TD-59 |

每个任务遵循 `DISCOVER → DEFINE → IMPLEMENT → TEST → BROWSER VERIFY → REGRESSION → DOCUMENT → CLOSE`。

**建议 Gate 切分**：
- **Gate 18G-1**：Impl-01..04 + 06 —— 基础层（Block/Template/Page）成立，**Landing 可创建可发布**；
- **Gate 18G-2**：Impl-05 / 07 / 08 —— 详情 / 列表迁移、缓存、表单收口。

---

## 10. v1.0 / v1.1 边界建议

**v1.0 Required**：TD-53 / TD-54 / TD-55；TD-56 至少覆盖 Product / Service 详情与主列表；TD-57 / TD-58。
**可并入相邻阶段 / v1.1**：TD-59（与表单阶段合并）、自由拖拽 Page Builder、区块 / 模板市场、更多行业 block、三级 RBAC + Site Membership、Admin 多语言。

判定原则：若某项虽列 v1.1 但直接影响"换品牌不改代码即可建站"的核心完整性，则不机械降级，需补证据后裁定。

---

## 11. 风险与回滚

| 风险 | 缓解 |
| --- | --- |
| 写死页面迁移为 block 时破坏 SEO / GEO / Sitemap / 缓存契约 | 新旧管线并行 + feature flag，逐页切换并做 URL / feed / 缓存对拍，每页独立 commit，旧 Blade 保留可回退 |
| `page_blocks` 扩展影响现有首页 | 仅新增列 / 维度（page_id / slot / template），首页数据零改动；migration 提供 down |
| 组合 block 带来 N+1 / 性能回退 | block 数据批量加载、按页缓存；设 SQL count / 渲染耗时基线 |
| 多语 / 多站串页 | 复用 18F translation / SiteScope；缓存 key 含 site/locale；四组合 + 跨站用例常驻 |

---

## 12. 待用户拍板项

1. 是否同意 §2 分层与"Template 用文件注册表、Page 用 DB 模型"的归属；
2. 首批通用 block 与模板清单（§3 / §4）是否裁剪；
3. Gate 切分（§9 两个 Gate）是否采纳；
4. TD-59 是随 18G 做还是并入后续表单阶段；
5. 确认后，实现阶段第一步即将 TD-53..TD-59 与父 Epic #116 写入 `technical-debt-registry.md`。

**Discovery 到此 STOP，不进入实现。**
