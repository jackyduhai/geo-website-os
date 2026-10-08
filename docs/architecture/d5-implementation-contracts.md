# D5 Implementation Contracts（WP-1 ～ WP-9）

- **状态**：**PROPOSED（待裁定）**（2026-10-08）· 实现契约，非实现授权
- **上游**：`docs/architecture/d5-architecture-contract.md`（9173a04，已 APPROVED）
- **执行顺序（固定）**：WP-1 → WP-2 → WP-3 → WP-4 → WP-5 → WP-6 → WP-7 → WP-8 → WP-9
- **纪律**：每个 WP 独立实现 → 证据 + 实跑测试 + 回归 + 污染扫描 → Exit Gate → STOP 等授权；
  不自行跨 WP、不 push、不发布。
- **SoT 三分类**（本轮统一口径）：
  - **数据 SoT**：业务数据主表（facts / entities / contents / media …）
  - **决策/资格 SoT**：PublicIndex（可见性）、ContentGate（发布）、
    MediaReferenceScanner（删除资格）、EntityCapabilityRegistry（能力）
  - **失效/缓存控制 SoT**：CacheInvalidationMap（失效登记）
  - 决策/资格 SoT 不是数据主表，禁止机械等同。

---

# WP-1　Entity Lifecycle（实体生命周期原语）

**Scope**：把 Entity 从「硬删、零保护」改为「角色化生命周期 + 可恢复 + 站点主体保护」。

**Preconditions**：Architecture Contract 已 APPROVED；Translatable 已支持 soft/force 级联（已确认）。

**MUST**：
1. entities 表增加 `deleted_at`（SoftDeletes 迁移）；Entity 模型 `use SoftDeletes`。
2. 角色化删除策略（P11）：
   - Public Knowledge Asset（product/service/case_study）：`destroy` → 软删（可恢复）。
   - Semantic Node（person/location/topic）：可软删；退化规则不产生公开悬空。
   - download_asset：引用保护后软删。
   - site subject organization（`is_site_organization=true`）：**禁止普通 destroy**；
     提供独立 Deactivate/Retire 动作；purge 仅 Site teardown。
   - customer organization（role=customer）：独立生命周期，可软删。
3. 提供 restore（数据恢复 + 按 CURRENT 重算资格）与 force-delete（终态，需引用检查）。
4. 软删后自动撤出 PKS（PublicIndex 自动排除）；翻译行级联软删（Translatable 已支持）。
5. 区分 site subject vs customer（沿用 metadata.role；是否落独立字段由本 WP 决定并写死）。

**MUST NOT**：
- 不允许删除 site subject organization（走普通删除路径）。
- 不在 restore 时复制历史公共快照。
- 不 CASCADE 删除知识（P6）。
- 不改变 EntityRelation / ContentEntity / Fact 的既有语义（仅在终态按现有钩子清理）。

**Files & Layers**：
- 新增迁移：`..._add_soft_deletes_to_entities.php`
- 模型：`app/Models/Entity.php`（SoftDeletes + 角色判定）
- 控制器：`app/Http/Controllers/Admin/EntityController.php`（destroy/restore/forceDelete/deactivate）
- 路由：`routes/admin.php`（restore / force-delete / deactivate）
- 唯一约束：(site_id,type,slug) 与软删的兼容性须处理（删后同 slug 重建策略写死）。

**Acceptance Criteria**：
- product 软删 → 前台/机器面消失、翻译行同软删、可无损恢复。
- site subject destroy 被拒绝（审计）；Deactivate 可用。
- restore 后资格按当前规则重算，不回放历史。
- force-delete 清理结构边/Override，不 mutate Fact/外部知识。

**Tests**：新增 `EntityLifecycleTest`（软删/恢复/终态/角色保护/翻译级联）。
**Mutation Tests**：短路 SoftDeletes 全局作用域 / 移除 site subject 保护 → 测试 FAIL。
**Exit Gate**：全测试通过 + 回归 + 污染扫描 + 护栏 PASS → STOP。

---

# WP-2　Endpoint Visibility（统一端点可见性，消除 G-1）

**Scope**：所有进入图谱的边，其 Entity 端点必须经统一可见性判定。

**Preconditions**：WP-1 已完成（Entity 软删）。

**MUST**：
1. `GeoGraphBuilder` Content↔Entity 边的 Entity 端点（当前 `Entity::whereIn` 裸查询，约 :240）
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
**Mutation Tests**：把端点查询改回裸 Entity → 测试 FAIL。
**Exit Gate**：同 WP-1。

---

# WP-3　entity_refs + ContentGate（语义引用结构化）

**Scope**：新增 `contents.entity_refs` 并在 ContentGate 增加生命周期感知规则。

**Preconditions**：WP-1、WP-2 已完成。

**MUST**：
1. contents 表增加 `entity_refs`（JSON，nullable）；Content 模型 cast `entity_refs` => array。
2. ContentGate 增加语义引用规则（与 fact_refs 规则同位置），调用统一语义引用校验器。
3. 语义引用校验器按 WP-4 机制判定身份与生命周期，输出 G-7 五分支。
4. 手动后台与 GEOFlow 推送共用同一校验（无双标）。

**MUST NOT**：
- 不解析自由文本做实体识别（不做 NLP/字符串扫描）。
- 不合并 content_entity（结构）与 entity_refs（语义）。
- 不据 WARNING 改变 Content 发布状态（不拉 draft/noindex）。

**Files & Layers**：
- 新增迁移：`..._add_entity_refs_to_contents.php`
- 模型：`app/Models/Content.php`（cast）
- 门禁：`app/Services/Gate/ContentGate.php`（新规则）
- 校验器：新增 `app/Services/Gate/SemanticReferenceValidator.php`（或等价层）

**Acceptance Criteria**：
- 五分支判定正确；WARNING/BLOCK 均不产生业务副作用。
- 无 UNKNOWN-ID 才可正常发布。

**Tests**：新增 `SemanticReferenceGateTest`（五分支 + 无副作用断言）。
**Mutation Tests**：短路 validator / 跳过 entity_refs 校验 → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-4　Tombstone / Explicit Degradation（身份机制，单一选定）

**Scope**：为语义引用校验器提供「实体当前不存在 vs 已知曾存在但已清除」的身份判定。

**Preconditions**：WP-1（force-delete 路径）、WP-3（校验器）。

**★ 选定机制（唯一，不留分叉）：Minimal Tombstone Registry（最小墓碑登记）**

> 理由：G-7 已批准 KNOWN-DELETED → WARNING；仅墓碑登记可保留该语义，
> 且为窄面 append-only 身份记录，非宽链接框架、非 Reference Framework。

**MUST**：
1. 新增 `entity_tombstones` 表：
   `id, site_id, entity_id(原 ID), type, slug, name, purged_at, purged_by, timestamps`。
2. Entity force-delete（purge）时，在同流程写入一条 tombstone（可实时查询）。
3. 语义引用校验器判定顺序：
   ```text
   entities 行存在（含软删）→ 按生命周期：published / draft / soft-deleted / noindex
   行不存在 + tombstone 存在 → KNOWN-DELETED → WARNING
   行不存在 + 无 tombstone   → UNKNOWN-ID → BLOCK
   ```
4. 跨站隔离（site_id）；tombstone 仅作身份判定，不复活实体。

**MUST NOT**：
- 不以 AuditLog 充当 tombstone/identity registry。
- 不把 tombstone 当作实体重新参与 PKS。
- 不同时保留「显式降级」为长期分叉。

**Fallback（仅在用户拒绝建表时启用，须显式回改契约）**：
- 不建 tombstone → 所有「行不存在」按 UNKNOWN-ID → BLOCK（KNOWN-DELETED 不再 WARNING）。

**Files & Layers**：
- 新增迁移：`..._create_entity_tombstones_table.php`
- 模型：`app/Models/EntityTombstone.php`
- 写入点：Entity force-delete 流程（WP-1）
- 消费点：`SemanticReferenceValidator`（WP-3）

**Acceptance Criteria**：
- purge 后 entity_refs 该条 → KNOWN-DELETED → WARNING（非 BLOCK）。
- 随机/无登记 ID → UNKNOWN-ID → BLOCK。
- 校验器在门禁路径上可实时查询。

**Tests**：`SemanticReferenceGateTest` 补 KNOWN-DELETED / UNKNOWN-ID 对比。
**Mutation Tests**：purge 不写 tombstone / 校验器跳过 tombstone → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-5　Content Trash（回收站三操作）

**Scope**：补齐内容回收站的列表 / 恢复 / 物理删除操作层。

**Preconditions**：Content 已有 SoftDeletes（已确认）；WP-3 entity_refs 已就位。

**MUST**：
1. 回收站列表：`onlyTrashed()` 分页（后台管理面可见 deleted 行）。
2. restore：清除 deleted_at，翻译行一并恢复；恢复后按 CURRENT 重算公共资格（不回放快照）。
3. force-delete：物理删除内容 + 翻译行，清理自有关联（ContentEntity/content_tag/SEO/Revision），
   解除引用但不 mutate Fact/Entity/Media。
4. 三面分别成立：public human absent / machine absent / admin trash present。

**MUST NOT**：
- 不在 restore 时复制历史公共快照（G-10⑥）。
- 不静默自动清理回收站。
- 不把被引用外部知识随内容删除。

**Files & Layers**：
- 控制器：`app/Http/Controllers/Admin/ContentController.php`（trash/restore/forceDelete）
- 路由：`routes/admin.php`
- 视图：`resources/views/admin/contents/trash.*`（仅最小列表，不做复杂 UI）

**Acceptance Criteria**：
- 三操作闭环；恢复无损且资格重算；force-delete 外部知识不变。
- 三面断言成立。

**Tests**：扩展/新增 `ContentTrashTest`（列表/恢复/终态/三面/无副作用）。
**Mutation Tests**：短路软删过滤 / restore 直接回放 flags → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-6　Menu Dangling Detection（菜单悬空识别）

**Scope**：删除 Page 后不得产生新的有效悬空菜单目标；既有悬空须被识别。

**Preconditions**：WP-1（实体）、WP-5（内容）；菜单模型已确认（Menu.link / resolveMenuHref）。

**MUST**：
1. 删除 Page/Content 前，检查菜单是否引用其路径；引用则按既定治理（阻断或提示）。
2. 渲染/健康检查：内部菜单目标无法解析为真实路由时标记为悬空（不静默渲染 404）。
3. 悬空项进入治理流程（删除前阻断 / 删除后解除引用 / 标记 invalid，三选一并写死）。

**MUST NOT**：
- 不允许无声产生新的有效 dangling menu target。
- 不自动删除菜单（除非选定策略明确）。

**Files & Layers**：
- 新增/复用：菜单引用反查（`app/Support` 或 `MenuController`）
- 删除路径：Page/Content/Entity 删除前检查
- 健康：`app/Services/Geo/GeoHealthService.php`（可选暴露悬空计数）

**Acceptance Criteria**：
- 删除被菜单指向页面 → 被拦截或悬空被标记。
- 无「可点击但 404 且无提示」的新悬空。

**Tests**：新增 `MenuDanglingTest`（删除前阻断 + 悬空识别）。
**Mutation Tests**：移除悬空检查 → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-7　Media WebP + Delete Failure（媒体一致性）

**Scope**：Media 删除覆盖衍生物；文件删除失败一致性。

**Preconditions**：MediaReferenceScanner 已是唯一删除资格入口（已确认）。

**MUST**：
1. Media 删除时同步处置受管理衍生物（WebP sibling 等），禁止已知孤儿。
2. 文件删除失败：不宣告删除成功、不无条件删 DB 记录；留可重试/可审计状态。
3. 结果性不变量：
   - 成功：DB 记录不存在 + 文件/衍生已处置。
   - 失败：不宣告删除 + 可重试/可审计。
4. 媒体记录须明确所属存储（disk），不默认假定当前 disk。

**MUST NOT**：
- 不绕过 MediaReferenceScanner 删除。
- 不强制 DB 事务与文件删除绝对原子（只保结果不变量）。
- 不 mutate 引用方。

**Files & Layers**：
- `app/Http/Controllers/Admin/MediaController.php`（destroy 一致性）
- `app/Support/ImageOptimizer.php`（衍生路径映射/删除）
- 可选：`app/Support/StorageReconciliation`（失败重试，属本 WP 最小实现）

**Acceptance Criteria**：
- 删除后原文件 + WebP 均消失，DB 记录删除。
- 模拟文件删除失败 → 不删记录、有可重试/审计状态。

**Tests**：扩展 `MediaControllerTest`（WebP 清理 + 删除失败）。
**Mutation Tests**：跳过 WebP 删除 / 忽略文件删除返回值 → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-8　Purge → Fact Re-review（事实复核信号）

**Scope**：Entity purge 触发 owning site/business-subject 粒度事实复核信号。

**Preconditions**：WP-1（purge 路径）；Fact 无 entity_id（已确认）。

**MUST**：
1. Entity purge 时，在 site / business-subject 粒度发出 fact re-review 信号
   （audit signal / re-review marker；是否引入 ReviewTask 由本 WP 决定并写死）。
2. Fact 行不被 mutate：row 不删、value 不改、locale 行不删、is_public 不变。
3. 公开 Fact 表示须暴露/机器可判定复核状态（HISTORICAL/VERIFIED/REVIEW-DUE，可由现有字段派生）。
4. temporality 由稳定 key/fact contract 决定，禁止 `review_due==NULL→HISTORICAL` 启发式。

**MUST NOT**：
- 不声称自动定位受影响具体 Fact（无 fact_entity_refs，永久能力边界）。
- 不自动改写/抑制 Fact。

**Files & Layers**：
- 触发点：Entity force-delete 流程
- 信号：`app/Models/AuditLog.php`（最小）或新增轻量 marker/task
- 读取：`app/Support/Fact` 复核状态派生

**Acceptance Criteria**：
- purge 后产生 site/business-subject 复核信号；Fact 行完全不变。
- 复核状态机器可判定。

**Tests**：新增 `FactReReviewSignalTest`（信号产生 + Fact 不变 + 状态派生）。
**Mutation Tests**：移除信号发送 / 误改 Fact → FAIL。
**Exit Gate**：同 WP-1。

---

# WP-9　Full Gate Mutation（全门禁变异与回归）

**Scope**：为 G-7～G-12 全部护栏补齐变异测试与最终回归。

**Preconditions**：WP-1～WP-8 全部完成。

**MUST**：
1. 每个 Gate 至少一条「移除关键护栏 → 测试 FAIL」的变异测试。
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
❌ PKS 传递 / Restore 回放快照 / 无链接声称自动漂移
❌ AuditLog 充当 registry / 门禁反向 mutate / 绕过 Scanner
❌ 静默自动清理 / 扩大 PKS / 自动跨 WP
❌ push / release / 配置 remote / 重写 Git 历史
```

## 开放与分层（不进本契约硬判据）

```text
Architecture/Implementation：retired 状态、review_due 到期出口、
  缓存精细化、JSON-LD Surface Policy、去重、孤儿 reconciliation、S3。
独立 Discovery：fact_entity_refs、统一 Reference Framework、media_refs/taxonomy_refs。
```

**下一步**：本契约经用户裁定后，从 **WP-1** 开始；每个 WP 完成后 STOP 等授权。
