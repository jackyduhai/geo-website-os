# 阶段 2 · B2 fact_entity_refs — 架构裁决（GO / NO-GO）

| 状态 | DECIDED / CLOSED |
| --- | --- |
| 日期 | 2026-10-10 |
| 裁决 | **NO-GO（当前不引入 fact_entity_refs）** |
| 上游 | B1 Reference Inventory（`db8aba5`）、D5-5 Fact Ownership（P14/G-8）、D5-4 Semantic Reference（P13/G-7）、WP-8 Fact Re-review |
| 取证方式 | 直接读取当前仓库（`D:\GEO-OS-rewrite\redact-v1`，main）真实模型 / 迁移 / 测试 |
| 本阶段范围 | 只读取证与裁决；**不新增字段 / 表 / 服务 / 迁移，不改产品代码**；不提前进入 B3/B4 |
| MRF 基线 | 61（未变更） |

---

## 1. 裁决问题

B2 要回答的不是「能不能加一张关联表」，而是：**现有系统是否确实需要单条 Fact 级别的 Entity 关联与漂移定位？**

具体三个候选能力：

1. **漂移定位**：Entity purge 后，精确指出哪些 Fact 受影响。
2. **事实证据 / 溯源**：记录哪个 Entity 为某 Fact 提供证据。
3. **事实依赖**：记录某 Fact 依赖哪个 Entity 才成立。

---

## 2. 源码级事实基线

### 2.1 Fact 结构（无 entity_id）

- 迁移 `2026_09_14_000004_create_facts_table`：列为 `id / key / label / value / group / source / owner / reviewed_at / review_due / is_public / sort / timestamps`，**无 entity_id**。
- `Fact` 模型为行级翻译模型（同表多行 + `translation_group`），语义身份是稳定的 **`key`**（如 `FACT-COMPANY-003`），跨语言恒定；`translation_group` 由 key 确定性派生（`TG-FACT-{key}`）。
- Fact 归属 site / business subject（P14），实体级属性不进 facts。

### 2.2 现有 Fact 引用机制（按 key，非按 id）

- `Content.fact_refs`：JSON 数组，元素是 **Fact key**（不是行 id）；`ContentGate` 规则 6 校验悬空 fact_refs → ERROR。
- Fact 输出经 `Fact::publicRows / publicMap / publicByLabel`（带显式 locale，不跨语言 fallback）。

### 2.3 现有 purge 复核机制（site 粒度）

- `FactReviewSignaler::signalForPurge`：Entity purge 后在 **site 粒度**产生 open `fact_review_markers`（`scope='site'`、`scope_key=NULL`）。
- `fact_review_markers` 表含 **`triggered_by_entity_id`**（触发复核的实体）——这是系统当前唯一的「复核→实体」溯源，已能回答「这条复核是哪个实体触发的」。
- marker 是当前复核状态权威；AuditLog 仅事件证据；purge 不删除 / 改写 / 抑制 Fact 行。

### 2.4 一处取证纠正（相对 B1 表述）

B1 矩阵与用户 B2 备注曾称「`FactsEntityMappingTest` 断言映射不存在」。经读源码，该测试**并非断言 fact_entity_refs 不存在**：它是把遗留 `Facts` 支持类（`config/facts.php` 业务数据）映射 / 建为 Entity 的迁移测试。**「无 fact_entity_refs」的真实证据是**：facts 表迁移无 entity_id 列（§2.1）、Fact 模型无 entity 关系、purge 复核明确落在 site 粒度（§2.3），而非该测试。

---

## 3. NO-GO 论证

### 3.1 最需要关联的事实，按 D5-5 恰恰不应持久化

会随实体目录漂移的，是「核心产品线 / 产品体系 / 核心客户群」这类**聚合陈述**（如 `FACT-COMPANY-011`、`FACT-PRODUCT-001`、`FACT-BIZ-002`）。它们：

- 不指向单个 Entity，而是**一组 Entity**；简单多对多表无法表达集合语义。
- 按 D5-5 原则：**可从 Entity Graph 计算的聚合值 → Derived，不应存为 Canonical Fact**；不能从图得出且有独立来源的才是 Canonical Fact。

由此形成关键张力：**越是「实体可派生」的事实，越不该持久化、也越不需要关联；真正 Canonical 的事实（成立时间、年产能、总投资）是站点级陈述，不归属单个 Entity。** 不存在一个既「属单实体」又「值得结构化关联」的干净 Canonical 事实集合。

### 3.2 关联语义本身不唯一，一张表无法区分

「Fact 描述某 Entity」「Fact 由某 Entity 提供证据」「Fact 依赖某 Entity 才成立」是三种不同关系。缺少类型化语义的多对多表会把它们混为一谈，反而制造错误的失效推断（例如把「提及」误当「依赖」，在实体删除时错误抑制事实）。

### 3.3 多语言身份与终态留存均需额外机制

- 关联必须挂在**逻辑 Fact（key）**而非单语言行，否则中英重复或遗漏；当前 Fact 行级模型没有现成的「逻辑 Fact 句柄」暴露给关联表。
- Entity purge 时：关联若级联删除，删除后反而无法定位受影响 Fact；若保留，又需为关联引入墓碑 / 历史身份。两者都要额外基础设施。

### 3.4 现有 site 级复核已覆盖真实风险，且成本低

- 真实风险是「实体消失后，站点的聚合事实可能过时」。现有机制：purge → site 级 open marker → 人工重新核定。
- 一个站点的事实量级很小（Demo 约 20–30 条），site 级复核不构成负担；marker 已带 `triggered_by_entity_id` 与 `reason`（含 entity type），人工复核时有明确起点。
- 关联 ≠ 事实失效：即使建立关联，实体删除也只能触发复核、不能自动判定事实过期（P14/G-8）。因此关联带来的唯一增量是「自动定位」，而当前规模下该增量收益不足以覆盖其成本。

### 3.5 引入成本与 SoT 风险

新增 fact_entity_refs 需：新表 + 迁移 + 模型 + CRUD + 类型化关系语义 + 逻辑 Fact 句柄 + 关联墓碑；并带来「另一套事实 SoT / 静默失效推断」风险。在没有明确产品需求驱动时，属于提前构建基础设施。

---

## 4. 能力边界（保留，禁止实现阶段脑补）

- 当前及本裁决后：**Entity purge 不自动定位受影响具体 Fact**；只产生 site / business-subject 级复核信号。
- 任何文档、代码、AI 回答**不得声称具备单 Fact 级自动漂移检测**。
- 不通过 NLP / 自由文本扫描伪造 Fact→Entity 关联。
- `fact_review_markers.triggered_by_entity_id` 仅表示「复核由哪个实体触发」，**不是** fact_entity_refs，不能据此回答「某 Fact 关联哪个 Entity」。

---

## 5. 重新开启 B2（改判 GO）的必要条件

只有在以下条件出现时，才另开架构裁决重新评估：

1. **明确产品需求**：要求精确识别受某 Entity 影响的具体 Fact，且该需求无法由 site 级复核合理满足。
2. **规模触发**：站点事实量级增长到 site 级人工复核明显负担过重。
3. **语义先行**：先在领域层明确区分「描述 / 证据 / 依赖」等关系类型，并确定关联挂在逻辑 Fact（key）上。
4. 若未来引入，应同时规定：purge 时关联的留存 / 墓碑策略，以及「关联只触发复核、不自动判定事实失效」。

---

## 6. B2 关闭结论与 STOP

| 项目 | 状态 |
| --- | --- |
| B2 fact_entity_refs | ✅ **DECIDED：NO-GO（当前不引入）** |
| 源码级核验 | ✅ 完成（facts 无 entity_id；purge 复核 site 粒度；纠正 FactsEntityMappingTest 表述） |
| 产品代码 / 迁移 / UI | 0 改动 |
| B3 media_refs / taxonomy_refs | ⛔ 未授权（B1 的 R6/R9 GAP 在此处理） |
| B4 统一 Reference Framework Go/No-Go | ⛔ 未授权，最后才裁决 |
| Remote / Push / Release | ⛔ STOP |

**当前动作：STOP。** B2 收口，不自行进入 B3；不修改产品代码；发布链路保持冻结。等待明确授权后进入 B3。
