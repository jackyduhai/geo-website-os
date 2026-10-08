# D5-2 裁决：Entity 生命周期角色矩阵

- **状态**：D5-2 **DECIDED**（2026-10-08）· 领域裁决，非实现设计
- **上游**：`d5-entity-purge-knowledge-decisions.md`（D5 第一问 + 取证）
- **章程**：`docs/product/stage1-discovery-charter.md` D5
- **裁决方式**：先定 **Lifecycle Class → Policy**，而不是 Entity Type → ORM Trait

---

## 零、Q1 裁决：`organization` 是什么

> **问题：`organization` 是普通业务实体，还是 Site Knowledge Aggregate Root？**

### 取证（判据不是「Catalog 现在依赖它」）

```text
① WizardController:55-62 —— 安装向导在 step 1 自动创建 organization
   Entity::where(type=organization, site_id)->exists() 不存在时才建
   且写入 metadata.is_site_organization = true
   → **每个站点自动生成一个**，不是用户随手建的普通实体

② CatalogSeeder:56 同样写入 is_site_organization = true
   → 种子数据把它当成「站点主体」

③ GeoGraphBuilder:167-170 —— 唯一读取该标记的地方
   if (type === organization && metadata.is_site_organization)
       $node['same_as'] = [PublicUrl::organizationAnchor()];
   注释原文：
     「被标记为站点主体的 organization 实体……通过 same_as 锚定 Site 聚合的
       唯一主体 {base}/#organization」
   → 代码已把它当作 **Site 聚合的身份锚点**

④ EntityController:468 —— Catalog::company() 读它的 metadata.company
   EntityController:399 —— 它的表单字段是 org_brand / org_industry /
                            org_phone / org_email / org_address
   → 它承载的是**企业级信息**，不是某个行业实体的属性

⑤ 实际数据：每站恰好 1 个 organization，且中英文为同一 translation_group
   default 站：示例制造有限公司 / Example Manufacturing Co., Ltd.
   metadata keys: company, brand_language, product_lines, production,
                  cooperation, cases, compliance
   → **整个 Catalog 的数据都挂在这一个实体上**
```

### 裁决

> **`organization` 是 Site Knowledge Aggregate Root，
> 但它的实际实现是「站点主体实体」与「被展示的客户案例实体」的混合体。**

**必须先拆开这两个角色**，否则 D5-2 无法回答：

| 角色 | 判据 | 删除后果 |
|---|---|---|
| **站点主体**（`is_site_organization=true`） | 向导自动创建 · 承载 Catalog 全部 metadata · 被 `same_as` 锚定为 Site 身份 | **整站知识生产能力崩塌**（products/scenes/company 全空） |
| **客户案例 organization**（role=customer） | 作为案例内容被展示 | 只是失去一个案例 |

**而当前实现没有区分这两个角色**：

```text
·向导只创建站点主体（is_site_organization=true）
· 但 EntityController:290 有customer role 判断：
  if (type===ORGANIZATION && meta.role==='customer') { ... }
  → 说明**代码里已经存在「客户案例」这个角色概念**，但创建路径不产生它
· 且 is_site_organization 标记**只被写入、从不被用于保护删除**
```

### Q1 裁决结论

```text
① organization **不能暴露为普通 Entity Delete**。
   它必须是一个独立领域操作：Deactivate / Retire Organization。
   永久 purge 只应发生在 Site teardown / tenant destruction 语境下。

② 删除 Entity 入口对 organization 需**特殊拦截**（当前完全没有）。

③ 「客户案例 organization」应被视为**独立生命周期类**
   （它不是 Aggregate Root，只是可删除的案例内容）。
   ⇒ 这意味着 organization 需要 **type 内的 role 细分**，
      生命周期策略不能只看 type。
```

**当前代码与注释不符（需记录）**：

```text
EntityController:249 注释写「entity_relations 两端外键为 ON DELETE CASCADE」
  → relations 确实是 FK cascade ✅
  → 但 content_entity 是**模型钩子**（无 FK）⚠️ 注释只提了前者，易误导
```

---

## 一、Entity Lifecycle Class Matrix（D5-2 核心产出）

| 生命周期角色 | Entity 类型 | 普通删除 | Public Knowledge 影响 | Restore | Purge |
|---|---|---|---|---|---|
| **Knowledge Root** | organization（`is_site_organization=true`） | ⚠️ **应禁止**，改为主动 Deactivate/Retire | **整体影响**（Catalog 全派生数据） | **必须可恢复** | **高门槛**：仅 Site teardown |
| **Customer Case** | organization（`role=customer`） | 可删除 | 局部（案例展示） | 应可恢复 | 可最终清除 |
| **Public Knowledge Asset** | product / service / case_study | 可删除 | **直接对外**（URL + sitemap + GEO） | **应可恢复** | 可最终清除（需引用检查） |
| **Semantic Node** | person / location / topic | 可删除 | 图谱影响 | **待裁决**（见 D5-2 Q3） | 可清除，需引用检查 |
| **Asset Node** | download_asset | 可删除 | 局部（无 schema） | **视 Media 语义** | 引用保护后清除 |

### 这张表比「type → SoftDelete」正确的原因

```text
真正要决定的是：Lifecycle Class → Policy
而不是：          Entity Type  → ORM Trait
```

**证据：同一个 type（organization）内已经有两个不同生命周期角色**
（站点主体 vs 客户案例），**只按 type 决定策略必然出错**。

---

## 二、Q2 裁决：Public Knowledge Asset 是否必须可恢复

`product` / `service` / `case_study`：

```text
✅ 有独立落地页（config: public=1）
✅ 进 sitemap（sitemap=1）
✅ 进站内搜索（searchable=1）
✅ 进 geo.json 且有 schema（Product / Service / CaseStudy）
✅ 有明显内容投入
⇒ 删除具有**明确的外部可见后果**（URL 404）
```

### 裁决

> **目标语义**：必须可恢复。

```text
Delete → unpublished / soft-deleted
       → recompute PKS
Restore → recompute PKS
Purge   → 物理清除（需引用检查）
```

而**当前行为**是 `DELETE → physical → irreversible`。

### 重要限定（研究纪律）

> **这是目标语义，不意味着现在就实施 SoftDeletes。**
> 具体生命周期原语由 Architecture Decision 决定。

**但 D5-2 已可冻结一条**：

```text
P11  Entity 生命周期策略由**生命周期角色**决定，不由 ORM trait 决定。
     同一 type 内可能存在不同角色（如 organization 的站点主体 vs 客户案例）。
```

---

## 三、Q3 裁决：Semantic Node 是否需要 Trash

`person` / `location` / `topic`：

```text
public=0  sitemap=0  searchable=0
但geo=1（有 schema：Person / Place / WebPage）
⇒ 只作为图谱节点与关系端点存在，无独立页面
```

### 关键判断（不是「用户是否需要回收站」）

> **删除一个语义节点以后，依赖它的知识应该如何退化？**

目标语义：

```text
semantic node delete
  → node unavailable to PKS
  → structural relations hidden / removed
  → content semantic references flagged
  → **no public dangling reference**
```

**⇒ 裁决：Semantic Node 不需要传统 Trash。**
它们没有对外 URL，内容投入低，回收站对用户没有价值；
真正需要的是**删除后的退化规则**（保证不出现公开悬空引用）。

**理由补充**：`person` 这类实体常由内容生产批量生成，
提供回收站反而会积累大量无价值条目。

---

## 四、Q1 裁决的连锁影响（本轮最重要推论）

### D5-3 Relation 已被 D5-2 重新定义

**在 D5-2 之前**，D5-3 问的是「Relation 三选一：CASCADE / DETACH / PRESERVE」。

**在 D5-2 之后**，Relation 的答案**由上游 Entity 生命周期决定**：

```text
Entity SoftDelete
  ↓
Relation 结构**仍完整**
但因 endpoint inactive → 不进入 PKS
  ⇒ Relation **PRESERVE**（结构保留，语义抑制）
  ⇒ 恢复后自动重新参与计算，无需制造 dangling 状态

Entity Purge
  ↓
endpoint 永久不存在
  ⇒ Relation **CASCADE** 非常合理
     （它是结构，不是知识资产）
```

**⇒ D5-3 的最终形态不是三选一，而是**：

```text
SoftDelete → PRESERVE structurally, SUPPRESS semantically
Purge      → CASCADE
```

### D5-4 content_entity 同样被重新定义

```text
Entity soft-delete
  → content_entity **可以保留**
  → Content 仍知道自己引用过该 Entity
  → 但 PKS 不应把 inactive Entity 当作有效语义节点

Entity purge
  → content_entity 结构边 detach / remove
```

而 `Content` 正文里「A 公司成立于 2012 年」这类**语义引用仍可能存在**
→ 必须继续区分 **Structural Reference ≠ Semantic Reference**（P7 保持不变）。

### D5-5 Fact 从 D5-2 脱钩（成为正式模型）

```text
facts → site_id
     → owner = business subject
     → **无 entity_id**

⇒ **Entity lifecycle ≠ Fact lifecycle**
```

`organization` purge 时 facts 不级联消失。但这才是 D5-5 真正的问题起点：

```text
Enterprise-level facts   属于站点主体，organization retire 后仍成立吗？
Entity-derived facts     实体消失后默认降级为不公开但保留？
Verified facts           （reviewed_at 非空）受更强保护
Historical facts         进入 preserved 状态供审计
```

**⇒ D5-5 已从「删除保护」升级为真正的
Knowledge ownership / fact granularity decision。**

---

## 五、D5-2 冻结结论

```text
P11  Entity 生命周期策略由**生命周期角色**决定，不由 ORM trait 决定

Q1  organization = Site Knowledge Aggregate Root
    但实现上混入了「站点主体」与「客户案例」两种角色，必须拆开
    ⇒ 站点主体**不应暴露为普通 Entity Delete**，改为主动 Deactivate/Retire
    ⇒ 永久 purge 仅在 Site teardown / tenant destruction 语境
    ⇒ 删除入口当前**零保护**，需特殊拦截

Q2  product / service / case_study（Public Knowledge Asset）**必须可恢复**
    （但这是目标语义，不等于现在就实施）

Q3  person / location / topic（Semantic Node）**不需要传统 Trash**
    真正需要的是「删除后退化规则」：不产生公开悬空引用

连带：D5-3 = SoftDelete→PRESERVE / Purge→CASCADE（不再是三选一）
      D5-4 = 软删保留结构边 / purge 才 detach，语义引用仍独立处理
      D5-5 = 已从 D5-2 脱钩，转为 Knowledge ownership / fact granularity 决策
```

---

## 六、下一步

```text
D5-2✅ 已裁决（本文）
   ↓
D5-3 Relation → 裁决 SoftDelete/Purge 两条路径下的具体策略
D5-4 content_entity + 语义引用退化规则
D5-5 Fact 归属粒度（Knowledge ownership）
   ↓
PSK 边界收口
   ↓
D5-1 Content Trash / D5-6 Page / D5-7 Media
   ↓
具体生命周期原语（Architecture Decision，不是实现）
```

**注意**：D5-2 暴露的「organization 双重角色」是一个**新发现的领域问题**，
需要在 Architecture Decision 阶段决定是否引入 role 细分字段。
**现在不动代码。**
