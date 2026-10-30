# 18U-1 Publication Lifecycle — Discovery

> 只读 Discovery 文档。本阶段**不实施**任何 migration / 模型 / 状态字段变更，仅盘点现状、冻结契约、给出进入 Implementation 的 Gate 结论。

## 1. 元信息

| 项 | 值 |
|---|---|
| 日期 | 2026-09-27（Asia/Shanghai） |
| 基线仓库 | `D:\GEO-OS-rewrite\geo-website-os`，分支 `main` |
| 基线 commit | `043a71cc5ba399c3e1a3a4510511516db1a75b2f`（`docs(v1.1): P0 reality check...`） |
| 工作树状态 | clean（提交前） |
| 范围 | Content / Entity / Page / PageBlock / SeoMeta / Banner / Menu / Setting 的发布态字段、状态机、定时发布、缓存/索引失效、Trash/Restore、Page Manager 契约、Template 挂载 |
| 明确排除 | 任何编码、migration、第二状态字段、Workflow Engine；不进入 Implementation |
| 关联文档 | `docs/audit/v11-p0-reality-check.md:26,69`（半成品定时发布结论）、`docs/product/v1.1-product-maturity-gap-matrix.md:99`（`publish_at`/`unpublish_at` 诉求） |

---

## 2. 执行摘要

1. **现状是"一套 status 列 + 一个 published_at 查询过滤"，没有真正的生命周期状态机。** 三个前台内容模型（Content / Entity / Page）各有 `status`，但取值集合不一致（Content/Entity 有 `archived`，Page 只有 draft/published），`published_at` 行为也不一致（Content 的 scope 按时间过滤，Entity 的 scope 完全不看时间，Page 连列都没有）。
2. **unpublish 语义已经分裂，但没有人把它写成契约。** Admin 手动下线一律写回 `draft`（Content `ContentController.php:199-206`、Entity `EntityController.php:288-297`、Page `PageController.php:149-159`）；外部同步下线写 `archived`（`GeoflowSync.php:163-176`）。这恰好符合"可再编辑 vs 上游权威下架"的区分，但目前是**偶然一致、未在 Contract 写死**。
3. **定时发布只对 Content 做了一半（lazy 查询过滤），对 Entity/Page 完全没做。** 无 `unpublish_at`、无 `scheduled` 派生态、无 scheduler（`routes/console.php` 仅有 `inspire`）。lazy settlement 的正确性来源已经存在于 `Content::scopePublished()`（`Content.php:119-125`），但到期翻转**不触发任何模型事件**，因此 PageCache（TTL 6h）不会主动失效——这是到期下线（published→expired）场景下"旧页面壳残留"的核心风险点。
4. **Trash 目前只有 Content 一个模型软删，且没有恢复入口。** `destroy()` 提示"可在回收站恢复"（`ContentController.php:165`），但路由表中**不存在任何 restore/trash 路由**。Entity / Page 都是物理删除。若要扩展 Trash 到 Entity/Page，最大陷阱是：现有的 `deleting` 级联（`Page.php:48-51`、`Entity.php:41-46`）在加了 SoftDeletes 后会被软删事件触发，从而把块/SEO/绑定 Page 物理删掉，导致 Restore 无法成立——必须在 Implementation 中改为只在 forceDelete 时级联。
5. **Template 挂载已在运行时正确落在 Page.template。** `categories.template` 列存在（`2026_09_14_000001_create_categories_table.php:23`）但 `app/` 内 0 运行时引用；运行时一律读 `Page.template`（`PageRenderContext.php:52`、`SystemPageRenderContext.php:137`）。历史列保留，勿删。

**结论（Gate）**：现状事实清晰、边界明确，**满足进入 18U-1 Implementation 的条件**；前置风险（软删级联陷阱、到期翻转无事件、Entity scope 忽略时间、Page 无 published_at）已在本文逐项定位并给出契约，见 §11。

---

## 3. 现有状态字段盘点

### 3.1 逐模型盘点

| 模型 | status 取值 | published_at | SoftDeletes | noindex / 其他 | 证据 |
|---|---|---|---|---|---|
| **Content** | `draft` / `published` / `archived`（默认 draft） | 有，`datetime` cast | **有**（`use SoftDeletes`） | `contents.noindex` 列已于 `2026_09_19_000003` 删除，noindex 现走 `SeoMeta` | 迁移 `2026_09_14_000003:36-37,68`；模型 `Content.php:8,27`；cast `:62`；drop 迁移 `:27` |
| **Entity** | `draft` / `published` / `archived`（常量 `STATUS_*`） | 有，`datetime` cast | **无** | 无 noindex 列，走 `SeoMeta.entity_id` | 常量 `Entity.php:67-69`；迁移 `2026_09_18_000008:22,25`；cast `:51` |
| **Page** | `draft` / `published`（仅两态） | **无此列** | **无** | 无 noindex；`is_home`/`is_system`/`system_key` | 常量 `Page.php:39-40`；迁移 `2026_09_23_000010:27-30`（无 published_at / softDeletes） |
| **PageBlock** | **无 status** | 无 | 无 | `is_active` 布尔（区块级显隐，非生命周期）；`content` JSON | `PageBlock.php:21-22,48-57` |
| **SeoMeta** | 无 status | 无 | 无 | `noindex` / `nofollow` 布尔（`SeoMeta.php:28-29,41-42`）——**属 SEO 指令，非生命周期状态** | `SeoMeta.php` |
| **Banner** | 无 status | `start_at` / `end_at`（date 型投放窗） | 无 | `is_active` 布尔；`scopeActive` 同时过滤 is_active + 时间窗 | `Banner.php:19-25,34-44` |
| **Menu** | 无 status | 无 | 无 | `is_active` 布尔 | `Menu.php:21-22,45-48` |
| **Setting** | 无 status（KV） | 无 | 无 | 整表缓存 + 请求级 memo | `Setting.php:36-63` |

### 3.2 现有查询口径（scope）实测差异

- **Content::scopePublished**：`status=published` **且**（`published_at IS NULL` 或 `published_at <= now()`）。`Content.php:119-125`。
  → 已实现"到点才可见"的 lazy 时间过滤；未来 `published_at` 的内容天然不可见。
- **Entity::scopePublished**：仅 `status = published`，**完全不看 published_at**。`Entity.php:86-89`。
  → Entity 的"定时发布"当前**不会**在前台生效；`published_at` 只是一个被写入、不被查询消费的时间戳。
- **Page::scopePublished**：仅 `status = published`。`Page.php:84-87`；前台组合页门禁 `Site/PageController.php:258`。

### 3.3 现有字段 → 归一化 → Publication State 映射

统一 Publication State（只读派生，**不新增第二状态列**）：

| Publication State | 判定（派生） | 来源模型/字段 |
|---|---|---|
| `draft` | `status = draft` | Content/Entity/Page.status 直接映射 |
| `scheduled` | `status = published` **且** `published_at > now()` | **派生**（仅 Content 现有数据可判定；Entity/Page 因列缺失或 scope 忽略，当前不可判定） |
| `published` | `status = published` 且 `published_at <= now()`（或 NULL） | Content 现有 scope 口径；Entity/Page 去掉时间条件即 published |
| `expired` | `published` 态但 `unpublish_at <= now()` | **需新增 `unpublish_at` 列**（当前完全不存在，全仓 grep `unpublish_at` 仅命中 docs） |
| `archived` | `status = archived` | Content/Entity.status 直接映射（外部同步下线语义） |
| `trashed` | `deleted_at IS NOT NULL`（软删） | 仅 Content 现有；Entity/Page 当前无 |

**硬约束（契约）**：
- 统一状态集固定为 `draft / scheduled / published / expired / archived / trashed`。
- **禁止** `status` + `publication_status` + `workflow_status` 三列双轨/多轨；`scheduled`/`expired` 一律由 `status + published_at + unpublish_at` **派生**，不落第二列。
- SeoMeta.noindex / PageBlock.is_active / Banner.is_active **不属于** Publication State，不纳入状态机。

---

## 4. Publication Lifecycle 状态机

> 形式：`Current State + Action + Actor + Condition → Next State`。
> 以下"应然"状态机是本 Discovery 提出的目标契约；"现状"列标注当前代码是否已如此。

### 4.1 转换表

| # | Current | Action | Actor | Condition | Next | 现状 |
|---|---|---|---|---|---|---|
| T1 | draft | 立即发布（通过 ContentGate） | Admin | `gate->check() passed`；无 `published_at` 则置 now | published | ✅ Content `ContentController.php:171-197`；Entity `EntityController.php:244-254` |
| T2 | draft | 设定未来 `published_at` 发布 | Admin | `published_at > now()` | scheduled | ⚠️ Content 允许（注释 `ContentController.php:181-182`），但无 scheduled 态、无事件；Entity/Page 无列 |
| T3 | scheduled | 到点结算 | System（lazy）/ Scheduler（optional） | `published_at <= now()` | published | ⚠️ lazy：Content scope 到点自动可见（`Content.php:119-125`）；**无 scheduler、无事件、无缓存主动失效** |
| T4 | published | 手动下线 | Admin | — | **draft** | ✅ Content `:199-206`；Entity `:288-297`（同时清 published_at）；Page `:149-159` |
| T5 | published | 外部同步下架 | GeoflowSync | 上游推送 unpublish | **archived** | ✅ `GeoflowSync.php:163-176`（写 `status=archived`） |
| T6 | published | 到期下线 | System（lazy）/ Scheduler | `unpublish_at <= now()` | expired | ❌ 无 `unpublish_at`，当前不成立 |
| T7 | expired | 恢复再发布 / 退回草稿 | Admin | 人工裁决 | published / draft | ❌ 无 |
| T8 | published/archived | 归档 | Admin | — | archived | ⚠️ Entity 仅能经表单下拉选 archived（`EntityController.php:375-377`），无独立 archive 动作；Content 无 archive 动作 |
| T9 | any | 软删（移入回收站） | Admin | 引用检查通过 | trashed | ⚠️ 仅 Content（`ContentController.php:159-166` → SoftDeletes）；Entity/Page 为物理删 |
| T10 | trashed | 恢复 | Admin | — | **trash 前原态**（非一律 draft） | ❌ 路由表无 restore 入口（grep `restore|trash` in routes = 0） |
| T11 | trashed | 物理删除 | Admin | 引用检查（关系/绑定） | purged | ⚠️ 仅 Narrative 对 slot 片段 `forceDelete`（`NarrativeController.php:87-88,122`）；无前台引用检查 |

### 4.2 状态图（文字描述）

```
                 ┌────────────── T2(设未来时间) ──────────────┐
                 │                                           ▼
   Admin publish(T1)                              ┌────────────┐
   draft ───────────────────────────────────────▶│ scheduled  │
    ▲  │                                          └─────┬──────┘
    │  │ T4 手动下线                                    │ T3 到点(lazy/scheduler)
    │  ▼                                               ▼
    │ published ◀────────────────────────────── published(可见)
    │   │  │  │                                          ▲
    │   │  │  └── T5 GeoflowSync ──▶ archived            │
    │   │  └── T6 到期(unpublish_at) ──▶ expired ────────┘(T7 人工再发布)
    │   │
    │   └── T9 软删 ──▶ trashed ── T10 恢复 ──▶ 原态
    │                     └── T11 purge ──▶ purged
    └──（archived/expired 经人工编辑/再发布回 draft）
```

### 4.3 Actor 清单与现状接线

| Actor | 现状接线 | 证据 |
|---|---|---|
| Admin（手动 publish/unpublish/archive/delete） | Content/Entity/Page Controller 各自实现；archive 缺独立动作 | `ContentController.php:171-206`、`EntityController.php:244-297`、`PageController.php:149-159` |
| System（模型事件 / gate） | 发布过 ContentGate；saved/deleted 触发 PageCache flush + SearchIndexSync | `ContentGate.php:53-159`；`AppServiceProvider.php:93-102`；`SearchIndexSync.php:55-85` |
| GeoflowSync（外部 publish/unpublish） | upsert 按 `sync_auto_publish` 决定 published/draft；unpublish→archived | `GeoflowSync.php:108-114,163-176` |
| Scheduled Job（scheduler） | **不存在**；`routes/console.php` 仅注册 `inspire` | `routes/console.php:6-8` |
| Restore（回收站恢复） | **无入口** | routes grep `restore|trash` = 0；仅 Narrative `withTrashed`（`NarrativeController.php:87-88`） |

---

## 5. unpublish 语义统一（draft vs archived 的条件）

**现状已天然形成的区分（需在 Contract 写死，而非重新发明）：**

| 场景 | 目标态 | 理由 | 现状证据 |
|---|---|---|---|
| **Admin 手动下线** | `published → draft` | 内容仍由本站拥有，可再编辑、再发布；保留 `published_at`（Content 不清空）以便恢复 | Content `ContentController.php:201`（仅改 status，不动 published_at）；Entity `:290-291` 写 draft **且**清 published_at |
| **外部同步下线** | `published → archived` | 上游权威（GEOFlow）要求下架，本站为从属方，**不可本地擅自再发布**；避免与上游权威冲突 | GeoflowSync `:169`（`update(['status'=>'archived'])`） |

**契约要点（Implementation 必须落实）：**
1. 两个动作的**目标态由 Actor 决定，不由调用点自由选择**：Admin Web 下线接口只能落到 `draft`；GeoflowSync unpublish 只能落到 `archived`。
2. `archived` 的内容**禁止** Admin 一键再 publish 回 `published`，必须先经"取消归档 → draft → 重新走 ContentGate publish"，防止与上游权威状态脱节。
3. 注意现状一处不一致：Entity 手动下线会**清空 `published_at`**（`EntityController.php:291`），而 Content 手动下线**保留** `published_at`（`ContentController.php:201-202`）。归一化后应统一——建议 draft 态保留原 `published_at` 仅作审计，真正"重新发布"时由 T1 决定是否刷新。

---

## 6. Scheduled Publish 双轨设计

### 6.1 现状

- Content：`scopePublished` 已做 lazy 时间过滤（`Content.php:119-125`）；Admin publish 注释明确"留空即立即发布，指定未来时间即定时发布"（`ContentController.php:181-182`）。
- **无 `unpublish_at`**：全仓 grep `unpublish_at` 仅命中 `docs/`（`v11-p0-reality-check.md:26,69`、`v1.1-product-maturity-gap-matrix.md:99`），代码/迁移 0 命中。
- **无 scheduler**：`routes/console.php` 无任何 `schedule()`。
- **Entity 的 published_at 不被查询消费**（`Entity.php:86-89` 只看 status）；**Page 无 published_at 列**。

### 6.2 目标双轨

**(A) Lazy settlement（唯一正确性来源，必须在查询/scope 层实现）**

- 不依赖任何后台 worker：请求到达时按 `status + published_at + unpublish_at` 当场计算 Publication State。
- 落地方式：把"公开可见"口径统一收敛到一个 Publication scope（替代当前三处不一致的 scopePublished），口径为：
  `status=published AND (published_at IS NULL OR published_at<=now) AND (unpublish_at IS NULL OR unpublish_at>now)`。
- 前台所有公开查询（`PublicIndex::contentQuery/entityQuery`、`Site/*Controller`）与索引准入都走该 scope，保证"无 worker 也正确"。

**(B) Optional scheduler（加速器，非正确性来源）**

- 有 worker 环境时，scheduler 周期性扫描"到期应翻转"的行（`published_at<=now 仍 scheduled`、`unpublish_at<=now 仍 published`），显式翻转状态并**主动触发缓存/索引失效**，把可见性延迟从"等下一个请求"压到秒级。
- 即使 scheduler 缺失/宕机，(A) 仍保证正确性；scheduler 只是减少延迟、主动刷新派生产物。

### 6.3 必须新增的列（Implementation 阶段，本阶段仅记录）

| 模型 | 新增列 | 用途 |
|---|---|---|
| Content | `unpublish_at`（timestamp, nullable） | 定时下线 → expired |
| Entity | `unpublish_at` + 修正 scope 消费 published_at | 定时发布/下线 |
| Page | `published_at` + `unpublish_at`（当前两列皆无） | Page 定时发布/下线 |

> `scheduled` 不新增列：`status=published + published_at>now()` 派生。

---

## 7. 状态变化后的缓存 / 索引失效策略

### 7.1 现有失效资产盘点

| 产物 | 机制 | 失效触发点 | 证据 |
|---|---|---|---|
| **PageCache**（整页静态壳） | file 存储，版本号 flush；`TTL=21600`(6h)；`forgetPath` 按 path、`forgetPage` 按 Page | 模型 `saved`/`deleted` 事件批量 flush（`AppServiceProvider.php:93-102` 含 Content/Category/Group/Banner/Menu/PageBlock/Setting/Media/Redirect/Fact/SeoMeta）；Entity 自有 `saved/deleted` flush（`Entity.php:38-46`）；EntityRelation 自有（`:70-75`）；PageController 手动 `forgetPage` | `PageCache.php:26,57-61,97-122`；`AppServiceProvider.php:100-101` |
| **PublicIndex**（前台公开索引口径） | 查询层 scope，无独立存储 | 每次请求实时查询，无缓存 | `PublicIndex.php:36-68` |
| **Sitemap / llms.txt / geo.json / RSS** | 每请求动态生成，`Cache-Control: max-age=3600` | **无应用层失效键**；依赖 CDN/浏览器 1h 过期 | `FeedController.php:21-56,146` |
| **Schema（JSON-LD）** | 随页面渲染输出（Organization/WebSite/Breadcrumb/FAQPage） | 随 PageCache 一起失效（页面壳刷新即刷新 Schema） | `PageRenderContext.php:98-120` |
| **GEO Graph**（geo.json） | 每请求动态 `GeoGraphBuilder::build()`，1h HTTP 缓存 | 无应用层失效键 | `FeedController.php:51-56` |
| **Search 索引** | 派生只读；`saved`→upsert 重算、`deleted`→remove；准入类变更标记 dirty，查询前懒重建 | 模型 `saved`/`deleted`；`search:reindex` 兜底 | `SearchIndexSync.php:55-85,130-152`；`SearchReindex.php:29-62` |

### 7.2 关键风险：到期翻转不触发事件

- 现有 PageCache flush 全部挂在**模型 `saved`/`deleted` 事件**上（`AppServiceProvider.php:100-101`）。
- lazy 到期翻转（`published_at` 到点 / `unpublish_at` 到点）**不写库、不触发任何事件**，因此 PageCache 不会主动 flush。

分两种情况评估：

1. **scheduled → published（到点上线）**：该内容在 scheduled 期间前台查不到（404），而 PageCache **只缓存 200 HTML**（`CachePage.php:122-126`，`statusCode!==200` 不存）。所以上线前不存在"旧页面壳"，到点后第一个请求自然渲染新页面并缓存——**无陈旧风险**。
2. **published → expired（到点下线）**：该页面在上线期间**已被缓存为 200 静态壳**。到点后 lazy scope 让前台查询变成 404，但 PageCache 里那份 200 壳仍可被匿名访客命中，最长残留 **6h（TTL）**——**这就是任务点名的不可接受场景**。

### 7.3 失效触发点设计（契约，供 Implementation）

- **lazy 结算时检测翻转即主动失效**：在公开查询/渲染入口（或一个 publication 中间件）检测"该资源上次计算的 Publication State 与当前不一致"时，主动调用对应失效：
  - `PageCache::flush()`（整站版本 +1，最简单稳妥）或 `forgetPath($path)`（精准）；
  - `SearchIndexSync` 对该文档重算（upsert/remove）；
  - sitemap/llms/geo 因无应用层键，scheduler 触发翻转时应额外 `flush()` 相关派生产物的请求级 memo，并接受最多 1h 的 CDN 缓存延迟（或由 scheduler 主动刷新）。
- **scheduler 触发翻转**时，在翻转事务内显式调用上述失效方法，不等 lazy。
- **restore（trashed→原态）**：当前 `restore()` 触发的是 `restored` 事件而非 `saved` 事件，而 `SearchIndexSync` 只监听 `saved`/`deleted`（`SearchIndexSync.php:55-56`）→ **恢复后内容不会被重新索引**。Implementation 必须补 `restored` 事件接线（或在 restore 动作里手动 upsert + PageCache::flush）。

---

## 8. Trash / Restore 关系保留验证（关键前置）

### 8.1 逐项 FK / 事件行为核查

| 关系 | 现状 | 软删时行为 | Restore 是否成立 | 证据 |
|---|---|---|---|---|
| **entity_relations**（双 FK） | DB 级 `from_entity_id`/`to_entity_id` 均 `onDelete('cascade')` | 软删 = 对 entities 行做 UPDATE（写 deleted_at），**不发 DELETE** → DB cascade **不触发**，关系行保留 | ✅ 物理删才级联删；软删安全 | 迁移 `2026_09_18_000009:17-18` |
| **content_entity**（pivot） | 仅 `unsignedBigInteger`，**无 FK 约束** | Content 软删不发 DELETE → 关系行**不删、变孤儿**（但不报错） | ⚠️ 关系行残留，需在恢复/清理时判定 | 迁移 `2026_09_26_000001:43-55`；模型 `ContentEntity.php` |
| **PageBlock** | 无 FK；`Page::deleting` 事件里**物理删**（`PageBlock::where('page_id',...)->delete()`） | **陷阱**：若给 Page 加 SoftDeletes，软删会触发 `deleting` 事件 → PageBlock 被物理删 | ❌ 现状下会直接摧毁块，Restore 无法成立 | `Page.php:48-51` |
| **SeoMeta**（page_id） | 无 FK；同一 `Page::deleting` 事件里物理删 | 同上陷阱，软删会把页面级 SEO 物理删 | ❌ | `Page.php:50` |
| **Entity 绑定 Page** | `Entity::deleted` 事件里 `Page::where('entity_id',...)->each->delete()` | **陷阱**：若给 Entity 加 SoftDeletes，软删触发 `deleted` → 绑定 Page（及其块/SEO）被删 | ❌ | `Entity.php:41-46` |
| **Media FK**（cover_id/og_image_id/image_id） | 均 `unsignedBigInteger`，**无 FK** | 软删不影响 Media | ✅ | 迁移 `2026_09_14_000003:34,58`；`Banner.php:29-32` |

### 8.2 Laravel SoftDeletes 事件语义（本方案的技术前提）

- 软删（`delete()` on SoftDeletes 模型）会正常触发 Eloquent `deleting` / `deleted` 事件（本质是事件生命周期内执行 UPDATE deleted_at）。
- 恢复（`restore()`）触发 `restoring` / `restored`，**不**触发 `saved`。
- **推论**：当前 `Page::deleting`、`Entity::deleted` 里的"级联物理删"闭包，一旦对目标模型启用 SoftDeletes，就会在**软删时误触发**，把本该保留的块/SEO/绑定 Page 物理删掉。这是扩展 Trash 的头号阻断点。

### 8.3 目标方案（Implementation 阶段实施，本阶段仅冻结契约）

完整链路：
```
Delete(软删) → 只写 deleted_at，关系行保留
            → 级联闭包改为「仅 forceDelete 时物理删 PageBlock/SeoMeta/绑定Page」
Trash       → Entity/Page 行仍在，deleted_at 标记，前台 scope 自动排除
Restore     → 清 deleted_at，status 回到 trash 前记录的原态（不一律 draft）
            → 补 restored 事件：PageCache::flush + SearchIndexSync 重新索引
Purge       → 物理删前做引用检查（entity_relations / content_entity / 绑定 Page / Media 引用）
```

必须落实的四点：
1. 给 Entity / Page 加 `deleted_at`（migration，本阶段不做）。
2. 改造 `Page::deleting` / `Entity::deleted` 闭包：软删时**不**物理删关系；只有 `forceDelete()`（真物理删）才级联。
3. Restore 恢复原状态：软删前需记录原 status（可在 trash 动作时快照，或软删行本身 status 未动——注意现状 Entity 下线会改 status，trash 与下线要区分）。
4. content_entity 无 FK，软删后留孤儿行；恢复时自然复用，purge 时显式清理，不依赖 DB cascade。

---

## 9. Page Manager 契约

- **事实基线**：Page Manager 基于现有 `Page` 模型（`Page.php`），**不是** `Content(type=page)`。Page 注释明确"不存产品/文章/组织等业务事实，业务事实来自 Content/Entity/Media"（`Page.php:12-24`）。
- **现有 Admin PageController 已有**：index/create/store/edit/update/destroy、publish/unpublish 开关（`PageController.php:149-159`）、Block 组合（add/edit/move/toggle/duplicate/variant，`:182-369`）、登录态预览 `preview()`（`:375-378`，草稿可看、不写缓存）。
- **Page 的 publish/unpublish/archive 必须全部走 §4 的统一 Publication Lifecycle Contract**，不另造第二套 Page 状态。
- **当前缺的运营动作（Implementation 补齐清单）**：
  1. 无 `archived` 态（Page status 仅 draft/published，`Page.php:39-40`）；
  2. 无定时发布（Page 无 `published_at`/`unpublish_at`）；
  3. destroy 为**物理删**（`PageController.php:140-147`），无 Trash/Restore；
  4. 无"复制整页"（仅有 Block 级复制 `:345-369`）；
  5. 无按状态的列表/批量运营视图。
- Page Manager 定位：**只加 Admin 运营层**（列表/复制/预览/发布/下线/归档/回收站），**不新增第二套 Page 系统**。

---

## 10. Template 挂载修正

- **正式挂载方向：Template → Page**（非 Category）。
- **`categories.template` 定性**：列仍在（迁移 `2026_09_14_000001:23`，注释"渲染模板标识，留空走 type 默认模板"），但 `app/` 内 grep `->template` **全部命中 `$this->page->template`**（`PageRenderContext.php:52`、`SystemPageRenderContext.php:137`、`PageController.php:168/176/190/198`），**无任何一处读 `category->template`**。判定：**历史字段 / 旧兼容残留，运行时 0 引用**。记录保留，勿贸然删除。
- **运行时源 = `Page.template`**：
  - `PageRenderContext::template()` → `TemplateRegistry::get($this->page->template)`，回退 landing（`PageRenderContext.php:50-53`）；
  - `SystemPageRenderContext::template()` → 同口径，回退 listing（`SystemPageRenderContext.php:135-139`）；
  - Page CRUD 校验 `template` 必填（`PageController.php:58,111`），`sharedTranslatableColumns` 含 template（`Page.php:32`）。
- **最终结构目标**：
  ```
  Page ├── template（运行时模板，唯一事实源）
       ├── composition（PageBlock 经 page_id 组合）
       ├── locale（多语言 translation_group）
       └── SEO / GEO（SeoMeta + GEO 字段，业务事实来自 Content/Entity）
  ```

---

## 11. Discovery Gate 结论

| # | 议题 | 是否满足进入 Implementation | 说明 |
|---|---|---|---|
| 1 | 状态字段盘点 / 归一化映射 | ✅ | 三模型 status/published_at/SoftDeletes 全部实测；统一状态集与派生口径冻结（§3） |
| 2 | Publication Lifecycle 状态机 | ✅ | 11 条转换 + Actor + 现状接线齐全；unpublish 语义 draft/archived 条件已写死（§4-5） |
| 3 | Scheduled Publish 双轨 | ✅ | lazy 正确性来源已存在（Content scope）；Entity/Page 差距定位清晰；需新增 unpublish_at（§6） |
| 4 | 缓存/索引失效 | ✅ | PageCache/PublicIndex/Sitemap/Schema/GEO/Search 逐项定位；到期下线残留 6h 风险与 restore 不索引缺口已记录（§7） |
| 5 | Trash/Restore 关系保留 | ✅（含阻断点） | 头号陷阱——软删触发 deleting/deleted 级联物理删——已定位到 `Page.php:48-51`、`Entity.php:41-46`，契约：仅 forceDelete 级联（§8） |
| 6 | Page Manager 契约 | ✅ | 基于现有 Page 模型，禁止第二 Page 系统；缺口清单明确（§9） |
| 7 | Template 挂载 | ✅ | categories.template 历史字段定性完成；Page.template 运行时源确认（§10） |

**进入 Implementation 的前置条件（须在 18U-1 实施时闭环）：**
1. 统一 Publication scope 取代三处不一致的 `scopePublished`（Content 看时间、Entity 不看、Page 无列）。
2. 新增 `unpublish_at`（Content/Entity/Page）+ `published_at`（Page）；不新增第二状态列。
3. 改造 `Page::deleting` / `Entity::deleted` 级联闭包为"仅 forceDelete 物理删"，否则软删扩展到 Entity/Page 会摧毁关系。
4. 补 `restored` 事件接线（SearchIndexSync + PageCache），否则回收站恢复后内容不重新索引。
5. 到期下线（published→expired）必须在 lazy 结算或 scheduler 翻转时主动 `PageCache::flush`，消除 6h 静态壳残留。
6. 统一 Entity 与 Content 手动下线对 `published_at` 的处理（现状不一致）。

**本 Discovery 到此 STOP，不自动进入 Implementation。**
