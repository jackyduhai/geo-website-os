# 阶段 2 · B1 Reference Inventory — 源码级引用取证最终矩阵

| 状态 | DECIDED（取证完成，B1 可正式关闭） |
| --- | --- |
| 日期 | 2026-10-10 |
| 上游 | 阶段 0 Legacy Inventory（`94d761a`）、阶段 1 A1-A4（`a4307fe`）、D5 Architecture Contract（`9173a04`）、Implementation Contracts v2（`69c5077`） |
| 取证方式 | 直接读取当前仓库（`D:\GEO-OS-rewrite\redact-v1`，main）真实模型 / 迁移 / 支持类 / 控制器 / 视图，非文档推演 |
| 本阶段范围 | 只读取证与矩阵编译；**不新增字段 / 表 / 服务 / 迁移，不改产品代码** |
| MRF 基线 | 61（未变更，本阶段无迁移） |

---

## 0. 取证方法与覆盖口径

用户在 B1 复核中要求：正式关闭 B1 前，每个真实引用点必须落实为
`Ref ID / Source→Target / 存储位置 / Ownership / Lifecycle / Delete Authority / Guard / Failure Semantics / Test Evidence / Coverage Status`。

本矩阵据此逐点取证。**Coverage Status 取值：**

- **VERIFIED**：源码中存在权威 Guard，且有对应测试 / 变异验证。
- **GAP**：存在真实引用点，但当前没有权威 Guard 覆盖（须在 B2/B3 或后续决策处理，本阶段不修复）。
- **UNKNOWN**：无法从当前源码确认（本轮无此类）。

贯穿结论（与 D5 冻结一致）：**当前系统存在多种引用权威，没有证据支持把它们归并为一个通用引用模型；B1 只产出清单与权威映射，不创建 references 通用表。**

---

## 1. 九类引用总览（含 Coverage）

| Ref | 引用类别 | 权威机制 | 存储 | Coverage |
| --- | --- | --- | --- | --- |
| R1 | Entity ↔ Entity 结构关系 | EntityRelation + FK CASCADE + 跨站守卫 | `entity_relations` | ✅ VERIFIED |
| R2 | Content ↔ Entity 结构边 | content_entity + forceDeleted 清理 | pivot `content_entity` | ✅ VERIFIED |
| R3 | Content → Entity 语义引用 | entity_refs + SemanticReferenceValidator + ContentGate | `contents.entity_refs`（JSON） | ✅ VERIFIED |
| R4 | Fact ↔ Entity 业务关联 | **无结构化关联**（永久不声称自动定位） | —（无字段 / pivot） | ✅ VERIFIED（缺失为有意） |
| R5 | Menu → 内部目标 | MenuReferenceGuard + MenuTargetResolver + nav-invalid | `menus.url` / `category_id` | ✅ VERIFIED |
| R6 | Media 使用引用 | MediaReferenceScanner（唯一删除资格入口） | 七路（见 §3 R6） | ⚠️ GAP（漏 R9 媒体引用） |
| R7 | Taxonomy / Category | category_id + content_tag + parent_id | 列 / pivot / 自引用 | ✅ VERIFIED |
| R8 | SEO / Schema / Settings 媒体引用 | Scanner 路 6/7 + fallback 链 | `seo_meta.og_image_path` / settings | ✅ VERIFIED |
| R9 | Page / Block 结构依赖 | BlockRegistry + CompositionPolicy + 实时重算 | `page_blocks.content`（JSON） | ⚠️ GAP（content 内媒体 ID 未入 Scanner） |

**两处 GAP 实为同一根因**：`PageBlock.content` JSON 中的媒体引用（hero `image_id`、image/media_text/logo_cloud `media_id`）不在 `MediaReferenceScanner` 七路内。该点在 R6（删除资格）与 R9（组合结构）两处同时暴露。

---

## 2. 逐点取证矩阵

### R1 — Entity ↔ Entity 结构关系

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R1 / structural edge |
| Source → Target | Entity（from）→ Entity（to），多型：`produces / offers / uses / located_in / related_to` |
| 存储位置 | 表 `entity_relations`：`from_entity_id`、`to_entity_id`、`relation_type`、`metadata`(array)、`site_id` |
| Ownership | 关系属领域结构，**两端均不拥有关系**；两端必须同站 |
| Lifecycle | 由后台关系 CRUD 建立；实体软删 → 关系行保留但端点被 PKS 排除；实体终态删 → 关系行随 FK 级联清除 |
| Delete Authority | DB 外键 `from_entity_id`/`to_entity_id` 均 `constrained('entities')->onDelete('cascade')`（迁移 `2026_09_18_000009`） |
| Guard | `EntityRelation::saving` 跨站守卫：两端必须存在且 `site_id` 一致，否则抛 `RuntimeException`；GEO 边端点可见性由 `PublicIndex::entityQuery()` 统一（WP-2） |
| Failure Semantics | 端点缺失 / 跨站 → 保存期抛异常；GEO 边端点不满足公开条件 → 被 PublicIndex 过滤，不进公开图谱 |
| Test Evidence | `EntityRelationCrossTypeTest`、`EntityRelationDeepTest`、`AdminEntityRelationCrudTest`、`CatalogRelationAuthorityTest`、`GeoGraphTest`、`EntityGeoGraphContractTest` |
| Coverage | **VERIFIED** |

### R2 — Content ↔ Entity 结构边

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R2 / structural edge |
| Source → Target | Content → Entity，关系型 `about` / `mention` |
| 存储位置 | pivot 表 `content_entity`：`content_id`、`entity_id`、`relation_type`、`site_id` |
| Ownership | **Content 拥有该边**（内容的结构组成） |
| Lifecycle | 内容编辑时建立；软删内容 → 边保留；forceDelete 内容 → `Content::forceDeleted` 清理 content_entity；forceDelete 实体 → `Entity::forceDeleted` 清理 `ContentEntity::where('entity_id',…)` |
| Delete Authority | Content（对其自有边）；实体 purge 清理指向它的边 |
| Guard | content_entity 无 DB 外键；终态清理由 `Content::forceDeleted`（:67-70）与 `Entity::forceDeleted`（:66）钩子负责 |
| Failure Semantics | 孤儿边在终态删时清理；该边不参与运行时渲染（仅图谱），无悬空渲染面 |
| Test Evidence | `Remediation20BTest`、`AdminContentTest`、`ContentTrashTest` |
| Coverage | **VERIFIED** |

### R3 — Content → Entity 语义引用

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R3 / semantic reference |
| Source → Target | Content → Entity（显式声明的知识引用） |
| 存储位置 | 列 `contents.entity_refs`：JSON 数组（int 实体 ID）；`Content` casts `entity_refs` => array（:77-78） |
| Ownership | Content 拥有引用；承载知识完整性（P7） |
| Lifecycle | 内容编辑时建立；软删内容 → 引用随内容保留但不参与门禁；forceDelete 内容 → 列随内容行消失（实体不动） |
| Delete Authority | `ContentGate`（发布资格）；实体 purge **不删引用**，保留并转为 KNOWN_DELETED 警告 |
| Guard | `SemanticReferenceValidator` → `EloquentSemanticReferenceResolver`（五分支）+ `TombstoneResolver`；接入 `ContentGate` |
| Failure Semantics | VALID / DRAFT / SOFT_DELETED / NOINDEX / KNOWN_DELETED → WARNING（不阻断）；UNKNOWN_ID → BLOCK（引用本身不存在）；**绝不修改 Content 业务状态** |
| Test Evidence | `SemanticReferenceGateTest`、`EntityTombstoneTest`、`ContentTrashTest` |
| Coverage | **VERIFIED** |

### R4 — Fact ↔ Entity（无结构化关联）

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R4 / ABSENT（有意缺失） |
| Source → Target | Fact →（无）；Fact 仅 `site_id`，**无 entity_id** |
| 存储位置 | 无字段、无 pivot；`Fact` 模型仅站点归属 |
| Ownership | Fact 归 site / business subject（P14） |
| Lifecycle | 独立于 Entity；Entity 生命周期不增删改 Fact |
| Delete Authority | 不适用 |
| Guard | 无（设计如此）；Entity purge → `FactReviewSignaler` 产生 **site 粒度**复核 marker，不定位单条 Fact |
| Failure Semantics | **不声称单条 Fact 漂移检测**；purge → 站点级复核信号；Fact 行 / value / locale / is_public 永不被 mutate |
| Test Evidence | `FactReReviewSignalTest`、`FactsEntityMappingTest`（断言不存在映射）、`FactLocaleIntegrityTest` |
| Coverage | **VERIFIED**（缺失本身经源码与测试确认，属永久能力边界） |

### R5 — Menu → 内部目标

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R5 / structural-nav |
| Source → Target | Menu → Content / Category / Page（内部目标） |
| 存储位置 | `menus.url`（字符串）；或 `category_id` → `categories.url`；`Menu::link()`（:60） |
| Ownership | Menu 拥有链接；目标独立于菜单 |
| Lifecycle | 菜单编辑时建立；目标删除不自动改菜单 |
| Delete Authority | `MenuReferenceGuard::isReferenced`（读 `Menu::active`）：目标仍被 ACTIVE 菜单引用时，删除前 **BLOCK** |
| Guard | `MenuReferenceGuard`（删除前保护）、`MenuTargetResolver`（VALID/INVALID/EXTERNAL/NON_NAV 分类）、`GeoHealthService`（历史悬空 → INVALID） |
| Failure Semantics | 历史悬空 → INVALID，渲染为非可点击（nav-invalid），不输出伪有效链接；ACTIVE 引用 → 阻断目标删除；**不自动 mutation 菜单** |
| Test Evidence | `MenuDanglingTest`、`MenuOverrideTest`、`SiteIdMenusRedirectsTest` |
| Coverage | **VERIFIED** |

### R6 — Media 使用引用

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R6 / media-usage |
| Source → Target | Content / Banner / Entity / SeoMeta / Settings → Media |
| 存储位置 | 七路（见 §3） |
| Ownership | 引用方拥有引用；**Media 拥有自身删除资格** |
| Lifecycle | Media 无 SoftDeletes；硬删受引用保护 |
| Delete Authority | **`MediaReferenceScanner`（唯一删除资格入口）**；存在有效引用 → 拒绝并记 `media.delete_blocked` |
| Guard | `MediaReferenceScanner` 七路；`MediaAssetPurger` 负责文件 / WebP / DB 一致性 |
| Failure Semantics | 被引用 → 拒绝删除；Media 缺失 → 引用方安全降级为 null / fallback（不 500、不悬空） |
| Test Evidence | `MediaReferenceScannerTest`、`MediaDeleteConsistencyTest`、`ContentCoverTest`、`WebpAndRenderCacheTest` |
| Coverage | **GAP**：七路未覆盖 `PageBlock.content` JSON 内媒体引用（见 R9 / §4） |

### R7 — Taxonomy / Category

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R7 / taxonomy |
| Source → Target | Content → Category（`category_id`）；Content ↔ Tag（pivot）；Category → Category（`parent_id`） |
| 存储位置 | `contents.category_id`（FK）；pivot `content_tag`（content_id, tag_id）；`categories.parent_id`（自引用层级） |
| Ownership | Content 拥有分类 / 标签归属；Category 层级由分类域拥有 |
| Lifecycle | 内容编辑时分配；标签 pivot 随内容 |
| Delete Authority | Category 删除；`content_tag` pivot 随内容终态 |
| Guard | 内容软删 → pivot 保留；forceDelete 内容 → `Content::forceDeleted` 清理 content_tag（:67-70） |
| Failure Semantics | 分类层级经 `Category::url()` 拼路径；菜单可指向 Category（与 R5 联动） |
| Test Evidence | `AdminCategoryCrudTest`、`AdminContentTest`、`Remediation20BTest`、`ContentTrashTest` |
| Coverage | **VERIFIED**（Category 本身不直接引用 Media） |

### R8 — SEO / Schema / Settings 媒体引用

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R8 / media-config |
| Source → Target | SeoMeta / Settings / Entity metadata → Media（OG 图、组织 Logo、联系二维码） |
| 存储位置 | `seo_meta.og_image_path`（路径字符串）；settings：`geo_org_logo`、`seo_og_image`、`contact_wechat_qr`；`Entity.metadata` 的 image/og_image |
| Ownership | SEO / Settings 拥有引用 |
| Lifecycle | 配置 / 元数据层 |
| Delete Authority | `MediaReferenceScanner` 路 6（SeoMeta 精确 + Entity metadata 兜底）、路 7（Settings） |
| Guard | Scanner 路 6/7 |
| Failure Semantics | 缺失 → OG/Cover/Logo fallback 链向下解析；不 500 |
| Test Evidence | `AdminSeoMetaCrudTest`、`SeoMetaResolverTest`、`SettingsGovernanceTest`、`MediaReferenceScannerTest` |
| Coverage | **VERIFIED** |

### R9 — Page / Block 结构依赖

| 字段 | 取证 |
| --- | --- |
| Ref ID / 类型 | R9 / structural-composition |
| Source → Target | Page → PageBlock →（Content / Entity / Media，经 JSON / 数据源） |
| 存储位置 | `pages`；`page_blocks.content`（JSON）、`category_id`、source ids；**Page 无 SoftDeletes** |
| Ownership | Page 拥有 blocks；Block 是实时读模型 / 数据源视图，不存快照 |
| Lifecycle | Page 硬删；Entity Detail Override 随 Entity purge 级联；自定义 Landing Page 仅 human surface |
| Delete Authority | Page 硬删；`Entity::forceDeleted` 清理 `Page::where('entity_id',…)`（override）；Block 实时重算 |
| Guard | `BlockRegistry`、`CompositionPolicy`、Entity forceDeleted（override）、`PageCache` |
| Failure Semantics | Block 按当前 published domain model 实时重算（无 stale snapshot）；Media 缺失 → null；**但 content JSON 内媒体 ID 未被 Scanner 保护（GAP）** |
| Test Evidence | `PageComposition18GTest`、`DetailComposition18G2Test`、`HomeComposition18ITest`、`PageCacheTest`、`SurfaceSeparationTest` |
| Coverage | **GAP**：`PageBlock.content` 内 `image_id` / `media_id` 未入 MediaReferenceScanner（见 §4） |

---

## 3. MediaReferenceScanner 七路源码清单（R6 权威）

| 路 | 引用方.字段 | 匹配方式 |
| --- | --- | --- |
| 1 | `Content.cover_id` | 精确 ID |
| 2 | `Content.og_image_id` | 精确 ID |
| 3 | `Banner.image_id` | 精确 ID |
| 4 | `Entity.metadata` 的 `media_id` / `og_image` | `whereJsonContains` |
| 5 | `Content.body` | `LIKE %/storage/{path}%` |
| 6 | `SeoMeta.og_image_path`（精确）+ `Entity.metadata->image`（兜底 LIKE） | 路径精确 + 模糊 |
| 7 | `Settings`：`geo_org_logo` / `seo_og_image` / `contact_wechat_qr` | 配置 |
| 附 | `ContentRevision`（仅 warning，不阻断删除） | 快照 |

`MediaReferenceScanner` 是唯一删除资格判定入口；`hasReferences()` / `warnings()` 供删除流程调用。**七路不包含 `PageBlock.content` JSON。**

---

## 4. 核心 GAP 取证：PageBlock.content 媒体引用未入 Scanner

### 4.1 引用点（声明于 `config/blocks.php`）

| Block 类型 | 媒体字段 | 位置 |
| --- | --- | --- |
| `hero` | `image_id`（配图，media） | blocks.php:39，default :41 |
| `image` | `media_id`（单图，required） | blocks.php:62，default :66 |
| `media_text` | `media_id`（图文混排） | blocks.php:75，default :83 |
| `logo_cloud` | `items[].media_id`（Logo 墙） | blocks.php:134，default :138 |

### 4.2 写入路径（代码级证实存在）

1. Composer →「编辑内容」→ `resources/views/admin/pages/fields/media.blade.php`：数字输入 `field[<key>]` + 媒体库选择入口。
2. `PageController::updateBlock`（:243）→ `extractConfig`（:399）：`type=media` => `(int) $raw`（:412）；items 内 media 同样 `(int)`（:436）。
3. 持久化为 `PageBlock.content` JSON。

### 4.3 读取 / 渲染路径（代码级证实）

- `image.blade.php:4`：`Media::find((int) $c['media_id'])` → `<x-picture :src="$imgMedia->url()">`。
- `media_text.blade.php:4`：同模式。
- `hero.blade.php:13`：`Media::find((int) $c['image_id'])`。
- `logo_cloud.blade.php:7`：逐 item `Media::find((int) $i['media_id'])`。

### 4.4 影响与定性

- **GAP 内容**：这些 `PageBlock.content` 媒体引用不在 Scanner 七路内；删除被引用的 Media 时不会被阻断。
- **运行时后果有限**：四个渲染器均以 `Media::find(...)` 容错，Media 缺失时该块 / 该图降级为不渲染（非 500、非碎图）。故后果是「图片从页面静默消失 + 删除未被阻断」，而非硬错误。
- **当前数据状态**：出厂 `StructureSeeder` 的 hero 规格**不含** image_id（默认 null），`BlankHomepageSeeder` 建立中性首页；故**出厂 / Demo 种子当前不写入这些媒体 ID**。但 Composer 写入路径完整，用户一旦在组合块中设置媒体 ID，即触发该漏扫。
- **与既有审计记录一致**：`docs/audit/pre-release-hardening-discovery.md:65` 已指出 `page_blocks.content` 当前运行时无 media_id、历史 seed 可能残留，建议兜底 `WHERE content LIKE '%"media_id"%'` 命中即告警。

### 4.5 处置归属（本阶段不修复）

该 GAP 不属 B1 修复范围（B1 只盘点）。后续两个合法入口：

- **B3 media_refs**：评估是否把 `PageBlock.content` 媒体引用结构化并纳入 `MediaReferenceScanner`（或新增扫描路 / 健康检查兜底 LIKE）。
- 阶段 3 媒体治理：以「存储身份 + 引用扫描」统一处理。

---

## 5. 关键架构结论

1. **引用权威是分散的，且各自语义不同**：结构边（R1/R2/R9）、语义引用（R3）、导航引用（R5）、媒体删除资格（R6）、分类（R7）、配置媒体（R8）在身份、删除权限、生命周期、PKS 资格上均不同；**没有证据支持归并为通用 references 表**。
2. **两类「有意缺失」经源码确认**：R4 无 fact_entity_refs（不声称单 Fact 漂移检测）；R6/R9 的 PageBlock 媒体漏扫是**真实 GAP**（非有意），须在 B3 处理。
3. **写入即覆盖的保护不依赖开发者记忆**：现有权威（Scanner / Resolver / Guard）均在模型 / 支持层，控制器与 Blade 不重复实现判定；新增媒体引用类型时必须进入 Scanner 契约，否则自动获得删除资格（即本次 GAP 的成因）。
4. **自由文本不被当作结构化引用**：除 Scanner 路 5 对 `Content.body` 做 `/storage/` 路径匹配外，不做 NLP / 全文字符串扫描来伪造引用完整性。

---

## 6. B1 关闭结论与 STOP

| 项目 | 状态 |
| --- | --- |
| 九类引用审查框架 | ✅ 完成 |
| 全量引用点源码级取证 | ✅ 完成（含两处 GAP 明确定位） |
| Coverage Status | R1-R5 / R7 / R8 = VERIFIED；R6 / R9 = GAP（同根因：PageBlock.content 媒体漏扫） |
| **B1 正式关闭** | ✅ **可关闭（矩阵已记录全部覆盖与缺口）** |
| B2 fact_entity_refs | ⛔ 未授权，不进入 |
| B3 media_refs / taxonomy_refs | ⛔ 未授权，不进入（PageBlock GAP 在此处理） |
| B4 统一 Reference Framework Go/No-Go | ⛔ 未授权，最后才裁决 |
| 产品代码 / 迁移 / UI / Remote / Push / Release | ⛔ STOP |

**当前动作：STOP。** B1 源码级矩阵交付后收口，不自行进入 B2；不修改产品代码；发布链路保持冻结。等待明确授权后进入 B2。
