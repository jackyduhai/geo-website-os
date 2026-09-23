# P-STEP 18G-2 · Architecture — Detail / Listing Composition Migration

> 阶段：P-STEP 18G-2（系统页 Composition 迁移）
> 子步骤：**18G-2 DISCOVERY → 架构裁定**
> 基线 HEAD：`c3ccd07`（checkpoint-18G-1）｜Regression：898 / 4745 / 0 / 0
> 姊妹文档：《page-composition-migration-discovery-18g2.md》（现状取证）
> 日期：2026-09-23
> 性质：本文档提出迁移目标架构、方案权衡与推荐；**经用户 Gate 拍板后才进入实现**。

---

## 1. 迁移总原则

1. 分层与唯一事实源：
   `Site → Theme（视觉）→ Template（结构/槽）→ Page（实例/context）→ Block（组成）→ Content / Entity / Media / Form（业务事实）`。
2. **不复制 Entity 数据**：Product/Service Detail 的业务事实只存在于 Entity（经 Catalog 读模型）；不生成"Product Page Content"第二份数据。
3. **不存任意 Blade/HTML/PHP**：Block 只存结构化 JSON，由注册 renderer 输出 HTML。
4. **SEO 单一管线**：所有公开 HTML 的 SEO 最终由 SeoMetaResolver 按 context 解析，消除控制器手工数组。
5. 复用 18G-1 地基（Block/Template/Page/PageCache/resolvePage），不另造 Localized* / 第二套渲染或 URL 系统。
6. 不破坏公开契约：URL、canonical、404 门禁（空目录站 / factory hasProduction）、sitemap/llms/rss/geo、多站 × locale 隔离。

---

## 2. 统一 Render Context 模型（核心）

每个公开 URL 解析为一个 **Render Context**，三类，共用同一 Template / Block / Resolver / Cache 管线：

| Context | 代表 | Block 来源 | SEO 解析 |
|---|---|---|---|
| **Page Context** | Landing、固定系统页（真实 Page 记录） | PageBlock（page_id） | `resolvePage` ✅ |
| **Entity Context** | Product / Service Detail（路由解析出的 Entity） | Detail Template 的 **resource renderer**（Entity 直驱）+ 可组合槽 | `resolveEntity` ✅（已存在） |
| **Listing Context** | Category / 产品系列 line / 知识频道 Group / 系统列表 | Listing Template 的 resource renderer（header/pager）+ **context grid** | `resolveCategory`（新增）/ `resolvePage`（固定系统页） |

**渲染入口统一**：Site\PageController（或一个等价的统一渲染器）按 context 类型选 Template、收集 resource renderer + block、输出 HTML；现有 Product/Solution/Knowledge/Contact/About/Factory/Cooperation 控制器逐步收敛为"context 解析 + 统一渲染"，最终退休为兼容桥接。

> 关键：**不为动态 Entity / 动态列表硬建 Page 记录**；它们是虚拟 context。固定系统页则建 is_system Page 记录以获得 block 可编辑性。

---

## 3. Detail 架构（Product / Service）

### 3.1 Template 槽位分两类

以 Product Detail Template 为例：

```
detail（extends base）
├── header   【resource-bound，固定】 detail header renderer（Entity 直驱）
├── spec     【resource-bound，固定】 spec/param table renderer（Entity 直驱，无数据则不渲染）
├── process  【resource-bound，固定】 process steps renderer（Entity 直驱，无数据则不渲染）
├── main     【可组合槽】 默认 scene chips / 痛点 / 组合（context block），可 override
├── related  【可组合槽】 默认 product_grid(related)，可 override
└── footer_cta【可组合槽】 默认 faq + cta，可 override
```

- **resource-bound 槽**：由 Template 绑定的 renderer 自动从当前 Entity 渲染，**不接受自由添加任意 block**，保证 Entity 唯一事实源、不复制数据。
- **可组合槽**：默认放 context block（related grid / scene / faq / cta），允许按 §6 裁定做 Page-level override。

### 3.2 Resource Renderer 注册（TD-62）

新增少量 **system/managed renderer**，统一纳入注册机制（保持单一渲染路径）：

| renderer | 输出 | 数据 |
|---|---|---|
| `detail_header` | tag / H1 / lead / mains / key_params 卡 / CTA（Product）；问句式 H1（Solution） | 当前 Entity |
| `spec_table` | 规格/参数表（复用 `_param_table`）；无数据不渲染 | 当前 Entity（P0/P2 分支） |
| `process_steps` | HowTo 步骤（复用 `_process_steps`）；无数据不渲染 | 当前 Entity |
| `scene_chips` / `adjacent` | 适用场景 chips / 相邻场景卡 | Entity relation / Catalog |
| `pain_grid` / `combo_grid` | 痛点 / 推荐组合（Solution） | 当前 scene Entity + relation |

这些 renderer 标记 `system` / `dataSource=current entity`，**不出现在管理员"自由添加 block"列表**，仅由对应 Detail Template 的固定槽调用。现有 `_param_table / _process_steps / _product_card / _scene_card / _subnav / _bottom_cta` 等 partial 作为这些 renderer 的内部实现迁移复用。

### 3.3 Schema 契约保持

Product Detail 输出 organization + Product（含 offers/aggregateRating，无值则不输出）+ FAQPage（有 FAQ）+ BreadcrumbList；Solution 输出 organization + Service + FAQPage + BreadcrumbList。Schema 由 SchemaBuilder 按 context 生成，`@id/canonical` 继续经 PublicUrl，不因模板化改变。

---

## 4. Listing 架构

```
listing（extends base）
├── header   【resource-bound】 栏目/系列名 + 导语
├── main     【可组合槽】 context grid（product_grid / service_grid / content_grid = current）
├── sidebar  【可选】 分组 tag / 筛选
└── pager    【resource-bound】 分页（非 block）
```

- **context grid**：grid block 新增 `current` 模式，自动取当前 Category / line / Group 上下文（TD-63）。
- 产品总览的"按系列分组 + 系列锚点 + 空系列 301"、栏目的"子栏目 pcard / 纯父栏目不渲染空列表"、知识的"封面卡 + 频道 tag"等差异，由 Listing Template 的 context renderer 保留（数据驱动，不臆造）。
- 分页 / 筛选 tag 为 resource-bound（随 Template），不是自由 block。

---

## 5. 固定系统页 / Contact / About / Factory / Cooperation 映射

| 页面 | 迁移形态 | Template | 组合 |
|---|---|---|---|
| Contact | **is_system Page 记录** | contact | `contact_info`（Catalog + siteSettings）+ `form_reference` |
| 产品总览 / 场景总览 / 知识总览 | is_system Page（可编辑）或 Listing Context | listing | header + context grid + cta |
| 产品系列 line / 栏目 / 频道 | Listing Context（动态，不建固定 Page） | listing | header + current grid + pager |
| About profile/history/culture | is_system Page（3 条） | detail/landing | detail header + rich_text / history nodes / culture cards |
| Factory | is_system Page（**保留 hasProduction 404 门禁**） | detail | header + stats + workshops(media) + certs |
| Cooperation | is_system Page | detail | header + feature(types) + process steps + faq |

- is_system Page 由 **installer（geo:install）注入**，默认 published、绑定 Template；Demo 数据仍独立加载，**Blank ≠ Demo** 不被破坏。
- Factory 的 `hasProduction()` 404、空目录站系统页 404 等"配置契约降级"门禁，迁移为系统 Page 的**可见性判定**（无对应资源时不发布 / 不输出 URL，sitemap/llms 同步排除）。

---

## 6. Detail 的 Page-level Block Override 决策（需拍板）

用户在 18G 裁定中要求 Detail "Template defaults + Page-level Block overrides"。两种实现：

- **方案 i（v1.0 最小）**：Detail = resource renderer + Template 默认槽（grid/faq/cta），**不支持 Entity 级自由 block override**；该自由 override 连同所需的 page_blocks 资源关联，登记 **TD（DEFERRED v1.1）**。
  - 范围小、零 schema 改动；但不满足"管理员可改 Detail 槽位"。
- **方案 ii（v1.0 完整，推荐）**：`pages` 表增加 nullable `entity_id`（一个 Entity 至多一个详情载体 Page，unique + 外键级联）；Detail 渲染时合并 Entity resource renderer（固定槽）与该关联 Page 的可组合槽 block；SEO 仍走 `resolveEntity`。
  - 最小 schema 改动（1 迁移 + Page 关系），满足 override，且不复制业务事实（Page 仅存槽位 block，不存产品字段）。

**推荐方案 ii**（与既定"Page-level override"一致，改动可控）。

---

## 7. SEO 统一（TD-61，最重要）

Resolver 演进（复用、扩展，不造第二套）：

| 页面 | 现状 | 迁移后 |
|---|---|---|
| Product / Service Detail | 手工数组 | **`resolveEntity(Entity)`**（已存在；控制器需取得 Entity 对象而非仅 Catalog 数组） |
| 文章详情 | resolveContent ✅ | 不变 |
| Landing / 固定系统页 | resolvePage ✅ / 手工 | 固定系统页建 is_system Page → **resolvePage** |
| Category 栏目页 | 手工（category.seo_title/desc） | **新增 `resolveCategory(Category)`**：category-level SeoMeta → category.seo_title/name、seo_desc/description → site fallback |
| 产品系列 line / 知识频道 Group | 手工 | Listing Context：按 context 键 + 翻译默认 + site fallback 解析（或为其建系统 Page → resolvePage） |

- 同步修复 **TD-64**：`findSiteLevelSeo()` 补 `whereNull('page_id')`，避免 page-level SeoMeta 被误取为站点级。
- 结果：17D 为 Entity / 系统页设置的 SeoMeta 真正驱动前台；控制器不再手工拼 SEO。

### 7.1 路线权衡（整体收口力度，需拍板）

- **路线 A — 统一 Render Context 管线（推荐）**：按 §2~§7 统一渲染入口与 resolver，固定系统页 Page 化、动态资源 Context 化；旧多 Controller 退休。
  - 优点：单一渲染/SEO/缓存管线，最符合 Website OS；彻底消除双轨。
  - 成本：改动面较大，需严格对拍 + 保留旧路径到验证通过。
- **路线 B — 增量换 SEO（保守）**：保留各 Controller 与 Blade，仅把手工 `$seo` 换成对应 resolver 调用（resolveEntity / resolveCategory / resolvePage），渲染结构暂不模板化。
  - 优点：改动小、风险低、快速闭合 TD-61。
  - 成本：仍保留多渲染入口与固定 Blade，Template 层对系统页仍基本缺失，组合能力有限，需再开阶段才能真正收口。

**推荐路线 A**，并建议在 18G-2 内分两个子 Gate（§9）降低风险；若时间/风险优先，可先以路线 B 闭合 SEO 双轨、再开阶段做结构迁移（但需明确登记）。

---

## 8. 缓存契约（延续 18G-1 页面级失效）

- PageCache key 含 path + locale（18F 已保证 locale 隔离）；resource context 页同样按 canonical path 版本化。
- 失效依赖：
  - Entity 保存/删除（含其 Catalog 投影变化）→ 该 Entity Detail + 引用它的 Listing/组合（related/combo/scene）失效；
  - Relation 变化 → 受影响 Entity Detail 的 related/combo/scene 槽失效；
  - Category/Group 变化 → 对应 Listing 失效；
  - 系统 Page / 槽位 block / Template / Theme 变化 → 关联页面失效（18G-1 已有 forgetPage/forgetTemplate）。
- 不做"一改全 flush"，也不漏失效（对拍时专门验证）。

---

## 9. 任务序列与子 Gate（建议）

> 遵循 DISCOVER → DEFINE → IMPLEMENT → TEST → BROWSER → REGRESSION → DOCUMENT → CLOSE。

**18G-2a — Detail 收口**
1. pages 表加 entity_id（方案 ii）+ Page/Entity 关系与迁移；
2. resource renderer（detail_header/spec_table/process_steps/scene/adjacent/pain/combo）注册；
3. Product / Solution Detail 改 Entity Context + resolveEntity；product_grid 增 related；
4. Detail 迁移前后对拍 + 多站 × locale × light/dark；
5. Gate：focused / full / fresh / HTTP / browser / SEO / Schema / GEO / cache / 污染 / 日志 / tag `checkpoint-18G-2a` → **STOP**。

**18G-2b — Listing + 系统页收口**
6. resolveCategory + Listing Template + context grid(current) + resource-bound pager；
7. 固定系统页（contact/products/solutions/knowledge 总览/about-*/factory/cooperation）is_system Page 化 + installer 注入；
8. 旧 Controller → Blade 退休/桥接；全量 URL 对拍；
9. Gate 全标准 + tag `checkpoint-18G-2b`（或 `checkpoint-18G-2`）→ **STOP**。

---

## 10. 迁移前后逐项对拍方案

在临时 demo 环境，迁移**前**抓取全部公开 URL（含 zh/en、3 类站点）：
- HTTP status（200/404/301）、canonical、title/description、robots/noindex、hreflang；
- Schema：@id / @type / inLanguage / offers / FAQ；
- sitemap / llms / rss / geo 收录集合与 GEO edge；
- 关键页 HTML 主结构标记。

迁移**后**同 URL 逐项 diff：
- status / canonical / 核心 SEO / hreflang 必须一致（或经裁定的改进，如 Entity SeoMeta 生效）；
- sitemap/llms/rss 集合一致，不新增会 404 的 URL、不漏旧 URL；
- GEO edge 不增不减伪节点、不串 locale/站点；
- 反向缓存测试：zh→en→zh、related/current 上下文、改 Entity 后失效，均实测响应内容（非仅看 cache key）。

---

## 11. 旧 Controller / Blade 退休 vs 兼容桥接

- 对拍通过后：Product/Solution/Knowledge/Contact/About/Factory/Cooperation 的渲染职责并入统一管线；旧 Controller 方法删除或保留为**薄兼容桥接**（301 到规范 context / 转发统一渲染）。
- 旧 Blade（products/*、solutions/*、knowledge/index、contact、about/*、factory、cooperation）在被 resource renderer + blocks 等价覆盖后下线；删除前须确认无其他 include / 测试依赖。
- **不删除** migration 历史、Example 数据、docs/audit。

---

## 12. Gate 标准与禁止项

每个子 Gate 必须有真实证据：focused tests、full regression（不低于 898/4745）、fresh install、blank site、demo site、real HTTP、real browser、multi-site、SEO、GEO、Schema、sitemap/llms/rss、cache invalidation、runtime pollution = 0、log audit、smoke cleanup、`git diff --check`、`git status`、commit、annotated tag、worktree clean。

**禁止**：移动 `v1.0.0-rc1`（965d63c HOLD）；配置 remote / push；GitHub Release / Public Release；自动进入下一阶段；做完整 Form Builder（→18H）；做自由拖拽；做三级 RBAC；为赶 Release 放宽断言 / skip / mock 真实链路。

---

## 13. TD 验收标准（建议登记）

| ID | 优先级 | 验收标准 |
|---|---|---|
| **TD-61** | P0 | 全部公开 HTML 的 SEO 经 SeoMetaResolver 按 context 解析；后台对 Entity/系统页的 SeoMeta 改动真实反映到对应前台；控制器手工 SEO 数组清零 |
| **TD-62** | P1 | Detail 的 header/spec/process/scene/adjacent 由 Entity 经注册 system renderer 输出，无数据不渲染；不复制业务数据 |
| **TD-63** | P2 | product/service/content grid 支持 current / related 上下文，Detail/Listing 默认 block 正确取数 |
| **TD-64** | P2 | findSiteLevelSeo 显式 content/entity/page 全 null；page-level SeoMeta 不再被误判为站点级 |

---

## 14. 待用户拍板清单

1. **整体路线**：路线 A（统一 Render Context 管线，推荐）还是路线 B（仅增量换 SEO）？
2. **Detail override**：方案 ii（pages 加 entity_id，支持 Page-level override，推荐）还是方案 i（v1.0 固定槽、override 归 v1.1）？
3. **子 Gate 切分**：是否按 18G-2a（Detail）/ 18G-2b（Listing+系统页）两次验收？
4. **固定系统页范围**：contact / 3 总览 / about-3 / factory / cooperation 是否全部 is_system Page 化？（factory 404 门禁迁移为可见性判定）

**架构提案到此 STOP；待用户就 §14 拍板后，再进入 18G-2a 实现，不提前编码。**
