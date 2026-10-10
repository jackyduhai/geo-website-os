# Legacy Inventory — 遗留事项取证台账（阶段 0）

状态：**PHASE 0 COMPLETE / READ-FOR-REVIEW**（2026-10-10）
性质：**只读取证台账**，非领域裁决、非实现授权。
上游基线：D5 全链路 CLOSED（Discovery / Architecture Synthesis `9173a04` /
Implementation Contracts v2 `69c5077` / WP-1～WP-9，最终提交 `500ea91`，
最终回归 1497 passed / 7928 assertions / 0 failures）。
本阶段纪律：**不新增字段 / 关系表 / 服务 / 迁移，不改产品代码**。
工作树：取证全程 clean，代码改动 0。

---

## 0. 阅读说明

每项遗留事项按四栏记录：

- **Observed（当前事实）**：代码 / Schema / 调用入口 / 测试证明的实际行为，附文件位置。
- **Contract（已冻结边界）**：不可被该项工作破坏的 D5 已冻结规则。
- **Gap（缺口）**：当前未定义 / 未实现 / 语义不清之处。
- **Decision Needed（待裁决）**：阶段 1～4 需明确的决策点；阶段 0 不预设结论，
  仅在用户已表达倾向处标注「初步倾向」。

遗留事项分三类：

- **A 类（领域与产品语义）**：A1 retired / A2 review_due 出口 / A3 JSON-LD Surface Policy / A4 回收站保留。
- **B 类（引用与知识架构）**：B1 Reference Inventory / B2 fact_entity_refs / B3 media_refs·taxonomy_refs / B4 统一 Reference Framework。
- **C 类（媒体与派生系统工程）**：C1 媒体存储契约 / C2 媒体去重 / C3 孤儿 reconciliation / C4 缓存失效精细化。

---

# A 类：领域与产品语义

## A1. retired 状态（退役 vs 归档）

| 栏 | 内容 |
|---|---|
| **Observed** | Entity 状态只有 `draft / published / archived`（`app/Models/Entity.php:106-108`，varchar，无 DB CHECK）。后台 `EntityController` 有三个动作：`deactivate()` → `draft`（清 `published_at`，审计 `entity.deactivate`，:317）；`retire()` → `archived`（审计 `entity.retire`，提示「已退役（归档）」，:331）；`publish()` → `published`（:341）。**「退役」在当前实现里已统一映射到 `archived`，不存在独立 `retired` 状态。** `archived` 被 `PublicIndex::entityQuery()`（只取 `published`，`app/Support/PublicIndex.php:58`）排除出 PKS。 |
| **Contract** | PKS 按知识类型实例化资格谓词（P15）；`published` 仅适用于有发布生命周期的 Entity / Content。`archived` 当前不进入 PKS。生命周期不越权控制其他领域。 |
| **Gap** | `archived` 是否应「作为历史知识保留在图谱」当前未定义——实际行为是**完全排除**出 PKS / graph。「退役」（停产 / 离任 / 不再销售）与「归档」（不再可信 / 仅留存）是否需要区分，无业务场景支撑。 |
| **Decision Needed** | **初步倾向（用户）**：无真实业务场景证明语义独立前，**不新增 retired 状态**，先核实 `archived` 是否足够。若确需区分，必须将「退役语义」与「PKS 资格」分别定义，不能只加一个枚举值便宣布完成。需回答：退役后哪些历史事实仍有效、关系边是否保留、各出口（人类面 / GEO / LLMS / Sitemap）如何表现。 |

## A2. review_due 出口策略（事实到期复核）

| 栏 | 内容 |
|---|---|
| **Observed** | Fact 有 `review_due`（date）、`reviewed_at`（date），`scopeNeedsReview`（`review_due` 在 30 天内）。公开取数口 `publicMap / publicRows / publicByLabel`（`app/Models/Fact.php:85/129/106`）**只过滤 `is_public`，不检查 `review_due`**，故到期事实当前仍公开。`geo.json` facts（`GeoGraphBuilder::facts()` :100）暴露 `reviewed_at / review_due`，但**无显式状态标签**。`FactReviewState`（`app/Support/FactReviewState.php`）按稳定 key contract + open marker 派生 `HISTORICAL / VERIFIED / REVIEW-DUE`。 |
| **Contract** | Fact 归属 site / business-subject（P14）；Fact temporality 由稳定 key / fact contract 决定，**不得靠 `review_due == NULL` 启发式猜测**（D5-5）。Entity purge 不得 mutate Fact，只触发复核信号（G-8）。`fact_review_markers` 是当前复核状态权威，AuditLog 仅事件证据。 |
| **Gap** | `llms.txt` 等出口不表达复核状态；`geo.json` 无显式状态标签；`review_due` 到期后的对外行为（WARNING / 降级 / 保持公开）未定义——当前保持公开。 |
| **Decision Needed** | **原则（用户）**：到期**不自动等于知识失效**，不自动改 `value` / `is_public` / 隐藏。需裁决：各机器出口如何稳定、机器可判定地表达复核状态；是否对某一出口增加降级 / 排除规则（须单独说明证据、业务后果与适用范围）。 |

## A3. JSON-LD Surface Policy（页面内结构化数据）

| 栏 | 内容 |
|---|---|
| **Observed** | `SchemaBuilder`（`app/Services/Geo/SchemaBuilder.php`）生成 JSON-LD，经 `resources/views/layouts/site.blade.php:1480` `app(SchemaBuilder::class)->render($schemas ?? [])` 渲染在页面底部。数据源边界已冻结（注释 :27-35：禁止直读 Facts；每个字段须页面可见）。**渲染点无 noindex / PKS 门控，无显式 Surface Policy，无 noindex-vs-schema 矩阵。** |
| **Contract** | JSON-LD 已被归类为 **Page-local machine metadata（页面本地元数据）**，**不是 PKS discovery projection**（PKS Boundary 冻结）。GEO / LLMS / Sitemap / RSS / Search 是知识发现出口；JSON-LD 是嵌入人类页面的结构化描述。 |
| **Gap** | `noindex` 页面是否输出 JSON-LD、可引用哪些实体 / 事实、字段是否须对当前页面用户可见，均未定义。 |
| **Decision Needed** | **初步倾向（用户）**：JSON-LD 只描述当前访问上下文中**实际可见、可验证**的内容，不得借 Schema 输出人类页面看不到的隐藏事实。需形成正式 Surface Policy（含 noindex × Schema 输出矩阵、引用目标资格、页面可见性契约）。 |

## A4. 回收站保留策略（Trash Retention）

| 栏 | 内容 |
|---|---|
| **Observed** | `bootstrap/app.php` 无 `withSchedule`；无 `routes/console.php` 调度、无 `Kernel.php` schedule。自定义命令（`ClearPageCache / Geo* / SearchReindex / Template* / RetryMediaDeletes` 等）**无任何 retention / 自动清理**。回收站项永久保留，直到人工 `force-delete`。 |
| **Contract** | 软删 = 可恢复暂存态（P16）；**禁止静默自动物理删**（等于静默知识损失）。Restore = 数据恢复 + 按 CURRENT 规则重算资格（G-10）。 |
| **Gap** | 无保留期限、无物理清除授权流程 / 审计要求。 |
| **Decision Needed** | 需裁决：定义保留期限与到期物理清除授权（含审计），或显式保持「纯人工、永久保留」。两种都须明确，不可留实现阶段自行决定。 |

---

# B 类：引用与知识架构

## B1. Reference Inventory（引用盘点）

| 栏 | 内容 |
|---|---|
| **Observed** | 当前系统引用类型盘点（详见下表「B1 引用矩阵」）。各引用在**存储位置、所有权、删除保护、生命周期语义**上均不同。 |
| **Contract** | 结构引用 ≠ 语义引用（P7）；引用 ≠ Ownership；PKS 成员资格**不传递**；新增引用类型必须进入对应 Scanner / Contract，不能因漏登记自动获得删除资格。 |
| **Gap** | 无统一的引用清单文档（本台账首次汇编）；部分引用为软引用（metadata JSON / 正文字符串 / 配置 JSON），删除保护依赖扫描器手动维护。 |
| **Decision Needed** | 阶段 2 以本矩阵为输入，逐项调查 B2 / B3，最后对 B4 做 Go/No-Go。**阶段 0 不裁决是否统一。** |

### B1 引用矩阵

| # | 引用 | 引用方（Owner） | 被引用方 | 存储位置 | 删除保护 / 生命周期 |
|---|---|---|---|---|---|
| 1 | ContentEntity `about / mention` | Content | Entity | `content_entity` 表 | 软删保留；forceDeleted 清理（`Content.php:67-70`）；Block 实时重算 |
| 2 | EntityRelation `produces/offers/uses/located_in/related_to` | Entity（from） | Entity（to） | `entity_relations` 表 | `saving` 跨站保护（两端同站，`EntityRelation.php:47-66`）；端点经 PublicIndex 可见性（WP-2） |
| 3 | `entity_refs`（语义引用） | Content | Entity | `Content.entity_refs` JSON | ContentGate G-7 五分支；消费 TombstoneResolver（WP-3） |
| 4 | `fact_refs`（事实引用） | Content | Fact（key） | `Content.fact_refs` JSON | 存在性校验；引用事实不随 Content 删除被 mutate |
| 5 | Media 引用（封面 / OG / Banner / 实体 metadata / 正文内联 / SEO OG / 设置项） | Content / Entity / Banner / SeoMeta / Setting | Media | FK 列 + metadata JSON + body 字符串 | **MediaReferenceScanner 七路扫描**（`app/Support/MediaReferenceScanner.php`）；ContentRevision 仅 warning |
| 6 | Menu 目标 | Menu | Category / URL | `Menu.category_id` + `url` | MenuReferenceGuard（删除前 ACTIVE 引用 BLOCK）+ MenuTargetResolver（历史悬空 INVALID） |
| 7 | Category（栏目归属） | Content | Category | `Content.category_id` | 栏目 `is_active`；停用栏目内容不外泄 |
| 8 | Tags（标签） | Content | Tag | `content_tag` pivot | forceDeleted 清理（`Content.php:69`） |
| 9 | PageBlock（区块数据源） | Page | Category / Content | `PageBlock.category_id` + `content` JSON（ids） | Block 实时读模型，按当前 published 状态重算（G-11） |

## B2. fact_entity_refs（Fact ↔ Entity 显式关联）

| 栏 | 内容 |
|---|---|
| **Observed** | **不存在任何 fact-entity 结构化关联。** Fact 表只有 `site_id`（无 `entity_id`）；无 `fact_entity` 表 / 迁移（Grep `fact_entity` 仅命中文档注释）。Entity 侧也无 fact 反查。 |
| **Contract** | **能力边界（永久保留）**：无 `fact_entity_refs` → 系统**不声称能自动定位受 Entity purge 影响的具体 Fact**。purge 只发 site / business-subject 级复核信号（G-8、P14）。 |
| **Gap** | 无法做 entity-level 自动漂移识别；purge 后只能人工复核整个业务主体。 |
| **Decision Needed** | 阶段 2 评估：是否引入 `fact_entity_refs` 以支持结构化漂移检测。需权衡：引入成本、维护一致性、与「实体级属性不进 facts」边界的关系。**当前治理（site 级复核）不依赖此项。** |

## B3. media_refs / taxonomy_refs（媒体 / 分类引用结构化）

| 栏 | 内容 |
|---|---|
| **Observed** | **Media 引用是分散的**（FK 列 + metadata JSON + body 字符串 + 设置项），无统一 `media_refs` 结构，反向靠 MediaReferenceScanner 扫描。**分类引用**：Content 属单一 Category（`category_id`）+ 多 Tag（`content_tag`），无统一 `taxonomy_refs`。Category 层级（`parent_id`）。 |
| **Contract** | MediaReferenceScanner 是唯一删除资格入口；七路为当前已发现全部一等路径，新增引用类型必须进 Scanner。引用保护 ≠ 反向 mutation 引用方。 |
| **Gap** | 软引用（metadata / body 字符串）依赖扫描器手动维护，新增引用点易漏；分类引用无统一表达。 |
| **Decision Needed** | 阶段 2 评估：结构化 `media_refs` / `taxonomy_refs` 是否能提升删除保护可靠性 / 生命周期治理；现有 Scanner + FK 已能保证删除正确性。 |

## B4. 统一 Reference Framework（Go / No-Go）

| 栏 | 内容 |
|---|---|
| **Observed** | **无共享引用框架。** 唯一的引用契约是 `SemanticReferenceResolver`（`app/Services/Gate/Contracts/`，专供 entity_refs）。9 类引用各自独立实现，身份 / 生命周期 / 删除语义不同。 |
| **Contract** | **纪律（用户反复强调）**：统一框架必须在完整 Reference Inventory + 逐项调查（B2/B3）**之后**再做 Go/No-Go，**禁止最先设计**。不得为消除重复代码抹平 `entity_refs` 与 `content_entity` 已冻结的结构 / 语义区别；不强制把所有引用物化成一张通用关系表。 |
| **Gap** | 未证明存在可复用的稳定契约。 |
| **Decision Needed** | 阶段 2 最后裁决：仅当证明存在稳定、可复用的引用契约时才考虑统一框架；否则保留领域专属实现。**阶段 0 倾向 NO-GO，但不预设结论。** |

---

# C 类：媒体与派生系统工程

## C1. 媒体存储契约（disk / provider / S3）

| 栏 | 内容 |
|---|---|
| **Observed** | `config/filesystems.php` 配置三盘：`local`（app/private）、`public`（app/public，URL `/storage`）、`s3`（env 驱动，:50）。`media.disk` 列默认 `public`（迁移 `2026_09_14_000005` :20）。`MediaAssetPurger::purge()` 使用 `Storage::disk($media->disk)`（显式 disk，`MediaAssetPurger.php:33`）。但 `Media::url()`（`Media.php:34-40`）对本地文件**硬编码 `/storage/` 前缀**，外链原样返回。 |
| **Contract** | 文件本体与 DB 记录属同一生命周期（P18）；生命周期操作不得默认假定「当前默认 disk」。 |
| **Gap** | **S3 未真正接通**：URL 生成假设本地 `/storage/` 路径，无 S3 URL 解析；跨存储（local ↔ S3）删除 / 迁移行为未定义。 |
| **Decision Needed** | 阶段 3 裁决：若需 S3，须定义完整存储契约（URL 解析、disk 显式记录、跨存储删除、失败重试）。当前可保持纯本地。 |

## C2. 媒体去重（Dedup / 资产身份）

| 栏 | 内容 |
|---|---|
| **Observed** | Media 表**无 hash / checksum 列**（字段：disk/path/original_name/mime/size/width/height/alt/title）。上传经 `ImageOptimizer::store()` → `$file->store($dir,'public')`（`ImageOptimizer.php:32`），使用 Laravel 默认随机 `hashName`。**相同二进制多次上传 → 不同随机文件名 + 多个 Media 记录**，无去重检查。 |
| **Contract** | Media 是资产对象（Asset）；不得因去重把两个 Media 记录偷偷当成同一生命周期对象。 |
| **Gap** | 无逻辑资产身份（logical asset vs physical binary），无去重；重复文件占存储。 |
| **Decision Needed** | 阶段 3 裁决：明确逻辑资产与物理二进制身份关系；若去重，须定义 hash 列、去重范围与删除语义（引用合并规则）。 |

## C3. 孤儿文件 Reconciliation（DB ↔ Storage 对账）

| 栏 | 内容 |
|---|---|
| **Observed** | **无专门的孤儿 reconciliation 命令。** `GeoHealthService` 是只读健康看板（检查 missing OG / public URL / JSON-LD 覆盖 / noindex / graph orphan / 菜单悬空），**不扫描文件系统孤儿**。`media.delete_failed_at`（WP-7）跟踪失败删除，经 `RetryMediaDeletes` 重试。 |
| **Contract** | 成功删除不得留下仍属于该 Media 的资产（含 WebP sibling，G-12）；删除失败须可重试、可审计。 |
| **Gap** | 无法检测：磁盘有文件但无 DB 记录、DB 有记录但缺文件、残留衍生物、失败删除记录。DB ↔ Storage 可能漂移。 |
| **Decision Needed** | 阶段 3 裁决：是否构建 DB↔Storage 对账命令（扫描 `allFiles()` 与 Media 记录双向比对），输出孤儿清单并进入治理流程。 |

## C4. 缓存失效精细化（Cache Invalidation）

| 栏 | 内容 |
|---|---|
| **Observed** | 缓存失效机制已较成熟：版本化整站 `flush()`（粗粒度，`PageCache.php:57`）+ path 级 `forgetPath / forgetPage / forgetTemplate`（细粒度，:97/114/128）。`PageController` 对页面 / block / 排序等改动用 `forgetPage`（:136/163/263…）。`CacheInvalidationMap`（`app/Support/CacheInvalidationMap.php`）为 15 个前台模型注册 `saved/deleted → PageCache::flush()`。**Content / Entity / Fact 等改动仍走整站 flush（粗粒度）。** |
| **Contract** | 「一次写入 = 一次失效」确定性；缓存 / 派生 / 索引不得反客为主；正确性优先（TD-08）。 |
| **Gap** | Content / Entity 改动未按「受影响页面」精确失效，任一写入即整站失效，可能有性能成本；无性能证据支撑是否需要细化。 |
| **Decision Needed** | 阶段 3 裁决：细粒度优化**仅在有性能基线证据时**进行，避免为优化引入复杂度；当前正确性已闭环。 |

---

## 贯穿性能力边界（永久保留，阶段 1～4 不得脑补）

1. 无 `fact_entity_refs` → 不声称自动定位受影响 Fact（P14 / G-8）。
2. AuditLog 仅事件证据；**≠** identity/tombstone registry，**≠** review-state 权威。
3. `real-route-exists` 是 surface constraint，**不是** PKS 通用成员谓词。
4. PKS 成员资格**不传递**：Content ∈ PKS ≠ 引用 Entity ∈ PKS；Entity A ∈ PKS ≠ Relation(A,B) ∈ PKS ≠ Entity B ∈ PKS。
5. JSON-LD 是页面本地元数据（独立 Surface Policy），**不是** PKS discovery projection。
6. HTTP 200 ≠ PKS 成员；PKS 成员 ≠ 必须存在人类面路由。

---

## 阶段 0 结论与下一步

- **取证完成**：A/B/C 三类共 12 项均已核实现状，附代码位置；B1 首次形成完整引用矩阵。
- **零代码改动**：本阶段未新增字段 / 表 / 服务 / 迁移，工作树 clean，MRF 迁移基线保持 61。
- **待裁决事项**：全部 12 项进入阶段 1～4，本台账不预设结论（仅标注用户已表达的初步倾向）。

**建议推进顺序（待用户授权）**：

```
阶段 1  领域与出口裁决：A1 retired → A2 review_due 出口 → A3 JSON-LD Surface Policy → A4 Trash Retention
阶段 2  Reference Architecture Discovery：B1 复核 → B2 fact_entity_refs → B3 media_refs/taxonomy_refs → B4 统一框架 Go/No-Go
阶段 3  Media / Derived Architecture：C1 存储契约 → C2 去重 → C3 孤儿 reconciliation → C4 缓存精细化
阶段 4  统一 Architecture Decision Register
```

**当前动作：STOP。** 不进入阶段 1，不改代码，不配置 remote / push，不启动发布链路。
等待用户对阶段 1 的明确授权。
