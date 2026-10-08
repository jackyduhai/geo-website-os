# D5 Implementation Contracts（WP-1 ～ WP-9）

- **状态**：**PROPOSED v2（按 CONDITIONAL PASS 6 点收紧，待裁定）**（2026-10-08）
- **上游**：`docs/architecture/d5-architecture-contract.md`（9173a04，已 APPROVED）
- **执行顺序（固定，已消除循环依赖）**：
  **WP-1 → WP-2 → WP-4 → WP-3 → WP-5 → WP-6 → WP-7 → WP-8 → WP-9**
  > 变更：Tombstone（WP-4）是身份基础设施，须先于其消费方
  > SemanticReferenceValidator（WP-3）建成；故 WP-4 提前到 WP-3 之前。
  > 本文件按执行顺序排列（WP-4 排在 WP-3 前）。
- **纪律**：每个 WP 独立实现 → 证据 + 实跑测试 + 回归 + 污染扫描 → Exit Gate → STOP 等授权；
  不自行跨 WP、不 push、不发布。
- **SoT 三分类**：
  - **数据 SoT**：业务数据主表（facts / entities / contents / media …）
  - **决策/资格 SoT**：PublicIndex（可见性）、ContentGate（发布）、
    MediaReferenceScanner（删除资格）、EntityCapabilityRegistry（能力）
  - **失效/缓存控制 SoT**：CacheInvalidationMap（失效登记）
  - 决策/资格 SoT 不是数据主表，禁止机械等同。

---

# WP-1　Entity Lifecycle（实体生命周期原语）

**Scope**：把 Entity 从「硬删、零保护」改为「角色化生命周期 + 可恢复 + 站点主体保护」。

**Preconditions**：Architecture Contract 已 APPROVED；Translatable 已支持 soft/force 级联。

## ★ 收紧点 1：role 字段——选定独立列（不再留 metadata 二选一）

- entities 表新增 **`role`（string，nullable，index）**：
  - organization：`site_subject`（站点主体）或 `customer`（客户案例）。
  - 其他类型：`role` 置 NULL（其生命周期策略由 `type` 决定）。
- 迁移回填：从 metadata 读取 `is_site_organization=true` → `site_subject`；
  metadata.role=customer → `customer`；之后 **`role` 列是生命周期策略的唯一权威**，
  metadata 中的 role/is_site_organization 仅作冗余展示，不再驱动策略。
- 生命周期策略统一由 `role`（organization）或 `type`（其余）决定，禁止运行时解析 JSON 猜测。

**MUST**：
1. entities 增加 `deleted_at`（SoftDeletes）；Entity 模型 `use SoftDeletes`。
2. 角色化删除策略（P11，读 `role`/`type`）：
   - product/service/case_study（Public Knowledge Asset）：`destroy` → 软删（可恢复）。
   - person/location/topic（Semantic Node）：软删 + 退化规则，不产生公开悬空。
   - download_asset：引用保护后软删。
   - organization·**site_subject**：**禁止普通 destroy**；提供 Deactivate/Retire；
     purge 仅 Site teardown。
   - organization·**customer**：独立生命周期，可软删。
3. restore（数据恢复 + 按 CURRENT 重算资格，受下方 slug 冲突约束）；
   force-delete（终态，需引用检查 + 触发 WP-4 墓碑与 WP-8 信号）。
4. 软删自动撤出 PKS（PublicIndex 排除）；翻译行级联软删（Translatable 已支持）。

## ★ 收紧点 2：slug 唯一性 / 重建 / restore 冲突——写死

- **ACTIVE 唯一**：同一 site 下，active（未软删）实体的 (type, slug) 必须唯一。
- **软删不占用**：软删行不得阻止新 ACTIVE 实体使用同 slug。
- **RESTORE 冲突**：恢复时若当前已存在 ACTIVE 同 (type, slug) 实体 → **restore BLOCK**
  （审计），绝不允许产生两个 ACTIVE identity；此时须先人工处理冲突（改名/删除新实体）。
- **机制（唯一）**：
  - 单一写入守卫 `EntitySlugGuard`（或等价 service），create/update/restore 全部经此校验；
  - 现有 DB 唯一约束 (site_id,type,slug) 调整为不再被软删行阻塞：
    MySQL 不支持部分唯一索引，故 ACTIVE 唯一性的**权威机制是该守卫**；
    允许用生成列（active 时=slug、软删时=NULL）做 DB 纵深防御，但不强制。
  - 守卫被绕过须由变异测试捕获。

**MUST NOT**：
- 不允许普通删除 site_subject organization。
- 不在 restore 时复制历史公共快照或制造双 ACTIVE identity。
- 不 CASCADE 删除知识（P6）。
- 不改变 EntityRelation / ContentEntity / Fact 既有语义（终态按现有钩子清理）。

**Files & Layers**：
- 新增迁移：`..._add_role_and_soft_deletes_to_entities.php`
- 模型：`app/Models/Entity.php`（SoftDeletes + role 判定）
- 守卫：新增 `app/Support/EntitySlugGuard.php`（或放入 Entity service）
- 控制器：`app/Http/Controllers/Admin/EntityController.php`
  （destroy/restore/forceDelete/deactivate/retire）
- 路由：`routes/admin.php`

**Acceptance Criteria**：
- product 软删 → 前台/机器面消失、翻译行同软删、可无损恢复。
- 软删后同 slug 可建 ACTIVE 新实体；旧实体 restore 遇 ACTIVE 冲突 → BLOCK。
- site_subject destroy 被拒绝；Deactivate 可用。
- restore 资格按当前规则重算，不回放历史。
- force-delete 清理结构边/Override，不 mutate Fact/外部知识。

**Tests**：新增 `EntityLifecycleTest`（软删/恢复/终态/角色保护/翻译级联）
+ `EntitySlugGuardTest`（active 唯一、软删重建、restore 冲突）。
**Mutation Tests**：短路 SoftDeletes 作用域 / 移除 site_subject 保护 / 绕过 slug 守卫 → FAIL。
**Exit Gate**：全测试通过 + 回归 + 污染扫描 + 护栏 PASS → STOP。

---

# WP-2　Endpoint Visibility（统一端点可见性，消除 G-1）

**Scope**：所有进入图谱的边，其 Entity 端点必须经统一可见性判定。

**Preconditions**：WP-1 已完成。

**MUST**：
1. `GeoGraphBuilder` Content↔Entity 边端点（当前 `Entity::whereIn` 裸查询，约 :240）
   改为 `PublicIndex::entityQuery()`（与 Entity↔Entity 边同一入口）。
2. 全仓审计其余「边端点直查 Entity」点，统一走 PublicIndex。
3. 端点 draft / noindex / soft-deleted / 他站 / 缺译 → 该边不输出。

**MUST NOT**：
- 不允许任何边端点绕过 PublicIndex 自行查询。
- 不扩大 PKS（只收窄）。

**Files & Layers**：
- `app/Services/Geo/GeoGraphBuilder.php`（relations / contentEdges）
- 审计：`app/Support`、`app/Services` 中所有 Entity 边端点查询。

**Acceptance Criteria**：
- 草稿/noindex/软删实体不再成为任何公开边端点。
- 图谱边端点与 PublicIndex 判定一致。

**Tests**：扩展 `GeoGraphTest`（端点 draft/noindex/soft-deleted 各断言不出边）。
**Mutation Tests**：端点查询改回裸 Entity → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-4　Tombstone Registry（身份基础设施，先于 WP-3）

**Scope**：为语义引用提供「实体当前不存在 vs 已知曾存在但已清除」的身份判定。

**Preconditions**：WP-1（force-delete 路径）、WP-2。

**★ 选定机制（唯一）：Minimal Tombstone Registry**

> 理由：G-7 已批准 KNOWN-DELETED → WARNING；仅墓碑登记可保留该语义，
> 且为窄面 append-only 身份记录，非宽链接框架、非 Reference Framework。

**MUST**：
1. 新增 `entity_tombstones` 表：
   `id, site_id, entity_id(原 ID), type, slug, name, role, purged_at, purged_by, timestamps`，
   index(site_id, entity_id)。
2. Entity force-delete（purge）时，在同流程写入一条 tombstone（可实时查询）。
3. 提供 `TombstoneResolver`（具体实现）：
   - 给定 (site_id, entity_id)，返回是否存在墓碑及身份快照。
4. 跨站隔离（site_id）；tombstone 仅作身份判定，不复活实体、不参与 PKS。

**MUST NOT**：
- 不以 AuditLog 充当 tombstone/identity registry。
- 不把 tombstone 当作实体重新参与 PKS。

**Fallback（仅在用户拒绝建表时启用，须显式回改契约）**：
- 不建 tombstone → 所有「行不存在」按 UNKNOWN-ID → BLOCK（KNOWN-DELETED 不再 WARNING）。

**Files & Layers**：
- 新增迁移：`..._create_entity_tombstones_table.php`
- 模型：`app/Models/EntityTombstone.php`
- 解析器：`app/Support/TombstoneResolver.php`
- 写入点：Entity force-delete 流程（WP-1）

**Acceptance Criteria**：
- purge 后 `entity_tombstones` 有对应行；TombstoneResolver 可实时查询。
- 墓碑不影响实体 PKS、不复活实体。

**Tests**：新增 `EntityTombstoneTest`（purge 写入、跨站隔离、Resolver 查询）。
**Mutation Tests**：purge 不写 tombstone / Resolver 跳过表 → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-3　entity_refs + ContentGate（语义引用结构化，消费 WP-4）

**Scope**：新增 `contents.entity_refs` 并在 ContentGate 增加生命周期感知规则。

**Preconditions**：WP-1、WP-2、**WP-4（TombstoneResolver 已存在）**。

**MUST**：
1. contents 表增加 `entity_refs`（JSON，nullable）；Content 模型 cast `entity_refs` => array。
2. 定义 `SemanticReferenceResolver` 接口（契约）；ContentGate 依赖该接口而非具体实现。
   - WP-4 的 `TombstoneResolver` 作为身份解析的底层，由本 WP 的具体 resolver 组合。
3. `SemanticReferenceValidator` 判定顺序（G-7 五分支）：
   ```text
   entities 行存在（含软删）→ published=PASS；
                             draft / soft-deleted / noindex（身份存在）→ PASS + WARNING
   行不存在 + tombstone 存在 → KNOWN-DELETED → PASS + WARNING
   行不存在 + 无 tombstone   → UNKNOWN-ID → BLOCK
   ```
4. ContentGate 增加语义引用规则（与 fact_refs 同位置）；手动后台与 GEOFlow 共用。
5. WARNING/BLOCK 均不改变 Content 业务状态（不拉 draft/noindex）。

**MUST NOT**：
- 不解析自由文本做实体识别（不做 NLP/字符串扫描）。
- 不合并 content_entity（结构）与 entity_refs（语义）。
- 不据 WARNING 改变 Content 发布状态。

**Files & Layers**：
- 新增迁移：`..._add_entity_refs_to_contents.php`
- 模型：`app/Models/Content.php`（cast）
- 接口：`app/Services/Gate/Contracts/SemanticReferenceResolver.php`
- 校验器：`app/Services/Gate/SemanticReferenceValidator.php`
- 门禁：`app/Services/Gate/ContentGate.php`（新规则）
- 绑定：`app/Providers/AppServiceProvider.php`（接口 → 组合 TombstoneResolver 的实现）

**Acceptance Criteria**：
- 五分支判定正确；WARNING/BLOCK 均无业务副作用。
- 无 UNKNOWN-ID 才可正常发布。

**Tests**：新增 `SemanticReferenceGateTest`（五分支 + 无副作用断言）。
> 接口可用 fake 做单测；真实 tombstone 联调在 WP-3 集成测试（WP-4 已先建成）。

**Mutation Tests**：短路 validator / 跳过 entity_refs 校验 / 解接口绑定 → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-5　Content Trash（回收站三操作）

**Scope**：补齐内容回收站的列表 / 恢复 / 物理删除操作层。

**Preconditions**：Content 已有 SoftDeletes；WP-3 entity_refs 已就位。

**MUST**：
1. 回收站列表：`onlyTrashed()` 分页（后台管理面可见 deleted 行）。
2. restore：清除 deleted_at，翻译行一并恢复；恢复后按 CURRENT 重算公共资格（不回放快照）。
3. force-delete：物理删除内容 + 翻译行，清理自有关联（ContentEntity/content_tag/SEO/Revision），
   解除引用但不 mutate Fact/Entity/Media。
4. 三面分别成立：public human absent / machine absent / admin trash present。

**MUST NOT**：
- 不在 restore 时复制历史公共快照。
- 不静默自动清理回收站。
- 不把被引用外部知识随内容删除。

**Files & Layers**：
- 控制器：`app/Http/Controllers/Admin/ContentController.php`（trash/restore/forceDelete）
- 路由：`routes/admin.php`
- 视图：`resources/views/admin/contents/trash.*`（最小列表，不做复杂 UI）

**Acceptance Criteria**：
- 三操作闭环；恢复无损且资格重算；force-delete 外部知识不变。
- 三面断言成立。

**Tests**：新增 `ContentTrashTest`（列表/恢复/终态/三面/无副作用）。
**Mutation Tests**：短路软删过滤 / restore 直接回放 flags → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-6　Menu Dangling Detection（菜单悬空，唯一策略）

**Scope**：删除 Page 后不得产生新的有效悬空菜单目标；既有悬空须被识别。

**Preconditions**：WP-1、WP-5。

## ★ 收紧点 4：菜单悬空治理——唯一选定「删除前阻断 + 健康标记 INVALID」

- **删除前**：Page/Content/Entity 删除前，若仍被任一 ACTIVE 菜单引用其路径 → **BLOCK 删除**
  （提示先解除菜单引用），审计。
- **既有历史悬空**：健康检查（渲染/健康服务）将无法解析为真实路由的内部菜单目标
  标记为 **INVALID**。
- **渲染**：INVALID 项**不得生成可点击的“正常目标”**（降级为非链接/占位 + 提示）。
- **不采用自动删除或自动改写菜单**（不反向 mutate 引用方）。

**MUST**：
1. 删除路径统一调用菜单引用反查；被引用则阻断。
2. 健康检查识别内部菜单目标路由是否存在；悬空 → INVALID。
3. 渲染层对 INVALID 做安全降级。

**MUST NOT**：
- 不允许无声产生新的有效 dangling menu target。
- 不自动删除/改写菜单（不反向 mutation）。

**Files & Layers**：
- 新增：`app/Support/MenuReferenceGuard.php`（删除前反查）
- 健康：`app/Services/Geo/GeoHealthService.php`（悬空识别/计数）
- 渲染：`app/Providers/AppServiceProvider.php`（resolveMenuHref 增加 INVALID 降级）
- 删除路径：Page/Content/Entity 删除前调用 guard

**Acceptance Criteria**：
- 删除被菜单指向页面 → 被 BLOCK。
- 历史悬空被标记 INVALID 且不渲染为正常可点击目标。
- 无「可点击但 404 且无提示」的新悬空。

**Tests**：新增 `MenuDanglingTest`（删除前阻断 + 历史悬空 INVALID + 渲染降级）。
**Mutation Tests**：移除删除前 guard / 去掉 INVALID 降级 → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-7　Media WebP + Delete Failure（媒体一致性与幂等重试）

**Scope**：Media 删除覆盖衍生物；文件删除失败的一致性与幂等重试。

**Preconditions**：MediaReferenceScanner 已是唯一删除资格入口。

**MUST**：
1. Media 删除同步处置受管理衍生物（WebP sibling 等），禁止已知孤儿。
2. 媒体记录须明确所属存储（disk），不默认假定当前 disk。
3. 结果性不变量：
   - 成功：DB 记录不存在 + 文件/衍生已处置。
   - 失败：不宣告删除 + 可重试/可审计。
4. 不强制 DB 事务与文件删除绝对原子（只保结果不变量）。

## ★ 收紧点 6：失败持久状态 + 幂等重试——写死

- 新增 `media.delete_failed_at`（nullable timestamp）作为**可重试持久状态**。
- 删除流程（扫描通过后）：
  ```text
  逐项删除（原始 + WebP/衍生）；
    “文件已不存在” 一律视为幂等成功，不视为失败；
    全部处置成功 → 删除 DB 记录（成功终态）；
    任一仍存在且无法删除 → 置 delete_failed_at + 审计 media.delete_failed，
                            记录保留（不宣告成功）。
  ```
- **Retry**（命令/任务）：扫描 `delete_failed_at` 非空记录，幂等重跑：
  ```text
  已不存在的文件 → 幂等成功；仍存在的 → 再尝试；
  全部成功 → 清除 delete_failed_at 并删除 DB 记录。
  ```
  示例：首次原始删成功、WebP 失败、DB 保留 → DELETE_FAILED；
  重试时原始已不存在（幂等成功）、WebP 再删成功 → 删 DB 记录，正常收口。

**MUST NOT**：
- 不绕过 MediaReferenceScanner 删除。
- 不在文件删除失败时无条件删 DB 记录或宣告成功。
- 不 mutate 引用方。

**Files & Layers**：
- 新增迁移：`..._add_delete_failed_at_to_media.php`
- `app/Http/Controllers/Admin/MediaController.php`（destroy 一致性）
- `app/Support/ImageOptimizer.php`（衍生路径映射）
- 新增：`app/Console/Commands/RetryMediaDeletes.php`（幂等重试）

**Acceptance Criteria**：
- 正常删除：原文件 + WebP 均消失、DB 记录删除。
- 模拟 WebP 删除失败 → 记录保留、delete_failed_at 置位、审计；
  重试后（缺失文件视为成功）正常收口。

**Tests**：扩展 `MediaControllerTest`（WebP 清理、删除失败、幂等重试）。
**Mutation Tests**：跳过 WebP 删除 / 忽略文件删除返回值 / 重试不把缺失当成功 → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-8　Purge → Fact Re-review（事实复核信号与状态权威）

**Scope**：Entity purge 触发 owning site/business-subject 粒度事实复核；状态机器可判定。

**Preconditions**：WP-1（purge 路径）；Fact 无 entity_id。

## ★ 收紧点 5：review-state SoT——选定独立 Review Marker 为当前状态权威

- **AuditLog = 事件证据**（记录 purge 与信号发出，一次性）。
- **Review Marker = 当前状态权威**（可查询“该 scope 是否存在未决复核”）。
- 新增 `fact_review_markers` 表：
  `id, site_id, scope(site|business_subject), scope_key, reason,
   triggered_by_entity_id(可空), status(open|resolved), created_at, resolved_at`，
  index(site_id, status)。
- Entity purge 时：写一条 `open` marker（site/business-subject 粒度）+ AuditLog 事件；
  人工复核完成后 marker 置 `resolved`。
- 公开 Fact 复核状态（HISTORICAL/VERIFIED/REVIEW-DUE）由 Fact 现有字段
  （reviewed_at/review_due）+ 稳定 fact contract 派生；存在 open marker 的 scope
  在机器面可判定为“需复核”。

**MUST**：
1. purge 产生 site/business-subject 粒度 marker（open）+ AuditLog。
2. Fact 行不被 mutate：row 不删、value 不改、locale 行不删、is_public 不变。
3. temporality 由稳定 key/fact contract 决定，禁止 `review_due==NULL→HISTORICAL`。
4. marker 是当前状态权威；AuditLog 仅事件证据，二者不混。

**MUST NOT**：
- 不声称自动定位受影响具体 Fact（无 fact_entity_refs，永久能力边界）。
- 不以 AuditLog 反推当前复核状态。
- 不自动改写/抑制 Fact。

**Files & Layers**：
- 新增迁移：`..._create_fact_review_markers_table.php`
- 模型：`app/Models/FactReviewMarker.php`
- 触发点：Entity force-delete 流程
- 读取：`app/Support/Fact` 复核状态派生（含 marker）

**Acceptance Criteria**：
- purge 后产生 open marker + AuditLog；Fact 行完全不变。
- marker 可稳定查询当前是否需复核；resolved 后状态翻转。
- 复核状态机器可判定。

**Tests**：新增 `FactReReviewSignalTest`（marker 产生 + Fact 不变 + marker 状态权威 + 状态派生）。
**Mutation Tests**：移除 marker 写入 / 误用 AuditLog 判定状态 / 误改 Fact → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-9　Full Gate Mutation（全门禁变异与回归）

**Scope**：为 G-7～G-12 全部护栏补齐变异测试与最终回归。

**Preconditions**：WP-1～WP-8 全部完成。

**MUST**：
1. 每个 Gate 至少一条「移除关键护栏 → 测试 FAIL」变异测试。
2. 全量回归（phpunit 全量）+ 污染扫描（无调试/临时代码、无凭据）。
3. Discovery 护栏（check-discovery-docs.sh）覆盖全部基线文档且变异有效。
4. 人类面 HTTP 200 与机器面 membership 分别断言。

**MUST NOT**：
- 不允许恒真检查器。
- 不留下「报了警但未验证无副作用」的门禁测试。

**Files & Layers**：
- `tests/`（各 Gate 变异测试）
- `tools/check-discovery-docs.sh`（最终核验清单）

**Acceptance Criteria**：
- 所有护栏变异被抓住；全量回归 0 failures；工作树干净。
- 交付说明列出验证方式、覆盖范围、缺口。

**Tests**：本 WP 即测试工程本身。
**Mutation Tests**：逐条验证。
**Exit Gate**：全量回归 + 污染扫描 + 护栏 PASS → D5 Implementation 收口，STOP 等发布裁定。

---

## 全局禁止（贯穿所有 WP，重申）

```text
❌ CASCADE 删知识 / 自动改正文 / 合并结构与语义引用
❌ PKS 传递 / Restore 回放快照或制造双 ACTIVE identity / 无链接声称自动漂移
❌ AuditLog 充当 registry 或 review-state 权威 / 门禁反向 mutate / 绕过 Scanner
❌ 静默自动清理 / 扩大 PKS / 自动跨 WP
❌ push / release / 配置 remote / 重写 Git 历史
```

## 开放与分层（不进本契约硬判据）

```text
Architecture/Implementation：retired 状态、review_due 到期出口、
  缓存精细化、JSON-LD Surface Policy、去重、孤儿 reconciliation、S3。
独立 Discovery：fact_entity_refs、统一 Reference Framework、media_refs/taxonomy_refs。
```

## v2 收紧对照（回应 CONDITIONAL PASS）

| # | 点 | v2 处置 |
|---|---|---|
| 1 | WP-1 role 字段 | 选定独立 `role` 列，回填并作为唯一权威 |
| 2 | WP-1 slug/restore | 守卫 + ACTIVE 唯一/软删不占/restore 冲突 BLOCK |
| 3 | WP-3↔WP-4 循环 | 顺序改为 WP-4 先于 WP-3；接口/实现拆分 |
| 4 | WP-6 悬空策略 | 选定删除前 BLOCK + 历史 INVALID，渲染降级，不自动改菜单 |
| 5 | WP-8 review SoT | 选定 fact_review_markers 为状态权威，AuditLog 仅事件 |
| 6 | WP-7 重试 | delete_failed_at + 缺失文件幂等成功 + retry 命令 |

**下一步**：本契约经用户裁定 APPROVED 后，从 **WP-1** 开始；每个 WP 完成后 STOP 等授权。
