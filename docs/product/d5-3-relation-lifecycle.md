# D5-3 裁决：Relation 生命周期（受 D5-2 生命周期类别约束）

- **状态**：D5-3 **DECIDED**（2026-10-08）· 领域裁决，非实现设计
- **上游约束**：`d5-2-entity-lifecycle-class-matrix.md`（D5-2 已 DECIDED）
- **章程**：`docs/product/stage1-discovery-charter.md` D5

---

## 零、问题被 D5-2 转化的方式

D5-3 **不再**是机械的「CASCADE / DETACH / PRESERVE 三选一」。

D5-2 确定了宿主生命周期后，问题转化为：

> **Relation 是否具有独立于 endpoint 的审计价值？**

若有 → 需要保留结构（PRESERVE / dangling marker）
若无 → 纯粹的派生结构，随宿主生灭

---

## 一、取证：Relation 是否有独立于 endpoint 的价值

### 1.1 表结构：没有任何独立业务字段

```text
entity_relations 列：
id, site_id, from_entity_id, to_entity_id,
relation_type, metadata, sort_order, created_at, updated_at
```

**没有**：`status` · `is_public` · `noindex` · `deleted_at` · 标题 · 摘要 · 正文

### 1.2 关系类型全是结构声明，不是知识

```text
实际 relation_type 取值（4 种）：
  produces     （组织 → 产品）
  offers       （组织 → 服务）
  uses      （产品 → 原材料/工艺）
  related_to   （实体 → 相关实体）
```

**它们的语义全部依赖两端实体**：

```text
「这家企业生产这种产品」——离开 organization 与 product，这句话没有意义
「这种产品使用这种材料」——离开 product 与材料实体，同样没有意义
```

### 1.3 公开出口中的消费方式

```text
SchemaBuilder:393 —— 只用 related_to，且只取「指向 product 的那一端」
                     关系本身不产出任何 JSON-LD 节点
GeoGraphBuilder    —— 关系只产出 edges 段的 from/to/type
```

**没有任何出口把关系当作独立知识单元输出。**

### 1.4 唯一的潜在审计线索被证伪

```text
entity_relations 有 metadata 字段
但：没有任何代码读取 relation.metadata 做业务判定
    它只是 extensions 承载位
```

### 1.5 结论

> **Relation 是纯粹的结构声明（structural assertion），
> 它的全部知识内容来自两端实体。**
>
> 删掉任一 endpoint，这条断言就无法被解释为任何东西。

---

## 二、裁决：Relation 无独立审计价值 ⇒ 不引入 dangling 状态

### 2.1 为什么不为了「理论上的历史完整性」引入额外机制

引入 dangling / snapshot / 新 lifecycle state 需要：

```text
· 新增字段（endpoint_missing 标记或端点快照列）
· 新增查询分支（所有出口都要处理「悬空边」）
· 新增测试矩阵（每出口 × 软删/  purge × 悬空 / 非悬空）
· 新的失败模式（边进了 PKS 但端点解析失败 → 正是 G-1 类问题）
```

**而收益是**：能回答「这条关系曾经存在」。

**代价与收益严重不匹配。** 且：

```text
★ 引入 dangling 状态本身就有制造「公开悬空引用」的风险
  —— 这正是 D2 定位的 G-1/G-2/G-3 的同类问题
  为了保留审计痕迹，反而新增一条产生悬空引用的路径
```

### 2.2 裁决结果

```text
Entity SoftDelete
  ↓
endpoint 行仍存在（只是 inactive）
  ↓
**Relation 行保留，但因 endpoint inactive → 不进入 PKS**
  ↓
⇒ 不需要任何新机制
  （因为「保留」是默认状态，不需要显式标记；
    「不进 PKS」由 PublicIndex 的 endpoint 过滤**自动实现**）

Entity Purge
  ↓
endpoint 永久不存在
  ↓
**CASCADE 合理**
  （当前已是 FK ON DELETE CASCADE，实测 1→0）
```

### 2.3 关键洞察

> **SoftDelete 场景下「PRESERVE」不需要做任何事。**

因为：

```text
· Relation 行天然保留（FK 只在物理删除时触发）
· 边是否进入 PKS 由**两端可见性**决定
· 而两端可见性已经由 D5-2 的生命周期模型 + PublicIndex 统一裁决
```

**⇒ 真正的「策略」不在 Relation 身上，而在 endpoint 的可见性裁决上。**
这与 D1 的结论一致：`Relation 完全派生`。

---

## 三、必须同时确认的一件事：当前实现是否真的如此

D5-2 判定「Entity SoftDelete 时 Relation 行保留」，
但**当前 Entity 没有 SoftDeletes**（D1 已证），所以这个场景**尚不存在**。

**因此本裁决是目标语义，其正确性依赖于未来实现遵守**：

```text
若未来 Entity 引入 SoftDeletes
  ⇒ FK 不会触发（软删不是物理删）
  ⇒ Relation 行天然保留 ✅ 本裁决自动成立

若未来仍用物理删除 + Trash 表
  ⇒ 需保证 Trash 中的实体不参与 PKS 判定
  ⇒ 否则会重演 G-1（endpoint 不可见但边仍输出）
```

**⇒ 这条必须进入 D5 的验收契约（D5 PKS Boundary），不能只写在文档里。**

---

## 四、对 D2 缺口的影响：G-1 怎么办

D2 记录的 G-1：

```text
GeoGraphBuilder:238-240
  Content↔Entity 边的 Entity 端是裸 Entity::whereIn（无 published 约束）
  → 草稿实体成了关系端点
```

**D5-3 的裁决使 G-1 的修法变得唯一且清晰**：

```text
不需要为 Relation 建立独立机制
只需要让边的 endpoint 走**统一可见性判定**（PublicIndex）

即：Content↔Entity 边的 Entity 端
     应与 Entity↔Entity 边的两端使用**同一个**判定入口
```

**⇒ G-1 从「三处各自打补丁」收敛为「统一 endpoint 判定」。**

**但按纪律，现在不修**——它属于 Implementation 阶段的 Gate。

---

## 五、冻结结论

```text
P12  EntityRelation 是纯粹的结构声明（structural assertion），
     全部知识内容来自两端实体。
     删掉任一 endpoint，该断言无法被解释。
     ⇒ **不具有独立于 endpoint 的审计价值**

裁决：SoftDelete → 行天然保留，因 endpoint inactive 而自动不进 PKS
     Purge      → CASCADE（当前 FK 已实现）
     **不引入 dangling marker / endpoint snapshot / 新的 relation lifecycle state**

★ 关键洞察：「PRESERVE」在 SoftDelete 场景下**不需要做任何事**
  —— Relation 行天然保留；边是否公开由endpoint 可见性自动决定
  ⇒ 策略不在 Relation 身上，而在 endpoint 可见性裁决上

★ 裁决的依赖条件（必须进 D5 验收契约）：
  若未来 Entity 用物理删除 + Trash 表，
  必须保证 Trash 中的实体不参与 PKS 判定，否则重演 G-1

★ 对 D2 的影响：G-1 的修法收敛为「统一 endpoint 可见性判定」，
  不再是三处分别打补丁
```

---

## 六、本裁决不做的事

```text
❌ 不改 Entity::booted()（当前 FK cascade 保持）
❌ 不给 Entity 加 SoftDeletes
❌ 不改 EntityController::destroy()
❌ 不修 G-1（属Implementation Gate）
❌ 不新增任何字段或状态
❌ 不做 Trash UI
```

**下一步**：D5-4 Semantic Reference Degradation
（结构层已由本裁决解决，只剩语义引用如何退化）。
