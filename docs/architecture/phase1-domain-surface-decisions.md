# 阶段 1：领域与出口裁决（A1～A4）— Architecture Decision Record

状态：**DECIDED / CLOSED**（2026-10-10）
性质：**架构决策记录**，非实现授权。
上游：阶段 0 Legacy Inventory（`94d761a`）；D5 全链路 CLOSED（`500ea91`）。
本轮纪律：**只处理领域语义与出口契约，不修改产品代码、不新增数据库字段、不启动实现。**
代码改动：0；MRF 迁移基线保持 61。

本轮优先遵守两条既有约束：

1. PKS 是按当前条件计算的**知识资格投影**，不是数据库中所有已发布对象的集合。
2. 回收站不得在未获明确授权的情况下**静默自动清理**。

---

## A1 — retired：独立状态决策

**结论：DECIDED / NO-GO — 暂不新增独立状态。**

裁决：保留现有 `archived` 状态，不新增 `retired` 枚举或数据库字段。

现有契约已规定 `archived` Entity 不进入 PKS。只有业务明确需要「退役但仍作为历史节点保留在图谱」的能力，才有理由新增独立状态语义。

| 项目 | 最终规则 |
|---|---|
| 生命周期状态 | 继续使用现有 `draft / published / archived` |
| Retire 操作 | 可继续作为业务操作名称，但**不因此创建新的持久化状态** |
| PKS | `archived` Entity 不进入公开知识发现集合 |
| 历史 Fact | 按 Fact 自身公开性、时效及复核契约处理，不因 Entity 归档而自动删除 |
| 重新启用 | 必须按当前资格规则重新判断，不恢复历史快照 |

**重要边界：保留历史 Fact ≠ 保留其原 Entity 节点。**

若未来确实要求「停产产品仍作为历史知识节点进入 GEO 图谱」，必须另开架构决策，分别定义退役状态、历史节点资格、关系保留条件与人类面展示规则。本次不提前引入该能力。

---

## A2 — review_due：事实到期后的出口策略

**结论：DECIDED — 到期影响复核状态，不自动取消 PKS 资格。**

裁决：保留 Fact 当前资格规则，在事实出口中明确暴露复核状态。

`review_due` 到期意味着需要复核，不等于事实已被证伪，也不等于事实自动失效。

### 1. 统一状态判定

| 状态 | 判定规则 |
|---|---|
| HISTORICAL | 由稳定的 Fact key / contract 明确规定为历史事件 |
| REVIEW-DUE | 当前 Fact 存在未解决的复核 marker，或其复核截止时间已到 |
| VERIFIED | 非历史事件，不存在未解决 marker，且没有到期复核要求 |

**禁止使用 `review_due == NULL` 推断 HISTORICAL。**

WP-8 职责分工保持不变：`fact_review_markers` 是 purge 触发的复核状态权威；AuditLog 只记录事件；`review_due` 表达定期复核期限。最终状态是依据稳定契约对这些状态来源进行**确定性判定**，而不是从审计日志倒推。

关闭 marker 并不必然意味着到期状态自动解除：若 Fact 仍已到复核期限，复核操作必须同时更新相应复核时间信息，才能正确回到 VERIFIED。

### 2. 机器出口契约

| 出口 | 契约 |
|---|---|
| geo.json | 每条公开 Fact 增加机器可读的 `review_state` |
| llms.txt | Fact 条目包含稳定的复核状态标识 |
| Sitemap / RSS / Search | 不虚构 Fact 状态字段；只有实际输出 Fact 的表面才适用本契约 |
| JSON-LD | 按 A3 独立 Surface Policy 处理 |

`review_state` 使用稳定枚举值 `HISTORICAL / VERIFIED / REVIEW-DUE`；允许附加本地化显示标签，但不得改变机器判定值。

**明确禁止：**

- 到期自动把 `is_public` 改为 false。
- 自动修改 Fact 的 `value` 或删除 locale 行。
- 未经独立裁决，按 REVIEW-DUE 直接排除 Fact。
- 把复核警告表达成「事实已失效」。

当前态 Fact 即使需要复核，仍可在满足资格规则时进入 PKS，但其复核状态必须可由机器判定。

---

## A3 — JSON-LD Surface Policy

**结论：DECIDED — 独立页面元数据轨道，不直接套用 PKS。**

裁决：JSON-LD 继续作为页面本地结构化元数据处理，不将其改为 PKS discovery projection。

JSON-LD 不等同于 geo.json / llms.txt / Sitemap 等知识发现出口。一个 noindex 页面可以包含自身的 JSON-LD，但具体字段仍须遵守页面可见契约。

### 输出矩阵

| 页面状态 | JSON-LD 规则 |
|---|---|
| 已发布、公开可访问、可索引 | 可输出页面可见且符合 Schema 类型要求的字段 |
| 已发布、公开可访问、noindex | 不因 noindex 单独禁止 JSON-LD |
| Draft 或仅有授权预览的页面 | 不向公共页面输出预览对象的 JSON-LD |
| 软删除 / 归档 / 已清除对象的不可公开页面 | 不输出这些对象的 JSON-LD |
| 字段未在当前页面公开呈现 | 不得借 JSON-LD 单独暴露该字段 |
| 来源无法满足页面可见契约 | 省略相应字段，不推测或补造事实 |

### 字段级约束

每个 JSON-LD 属性必须同时满足：

- **页面可见**：用户能从当前页面可见内容中核验该信息。
- **类型正确**：符合对应 Schema 类型的字段语义。
- **来源明确**：来自当前页面实际使用的合法数据来源。
- **不扩大知识权限**：不能借助结构化数据输出页面隐藏的 Fact 或其他私有信息。

`SchemaBuilder` 不得因此获得直接读取 Fact 表并输出隐藏事实的权限。

noindex 控制搜索发现资格，**不是访问控制机制**；页面是否允许输出 JSON-LD，仍须首先满足公共页面和字段可见性要求。

---

## A4 — Content Trash 保留策略

**结论：DECIDED — 无限期保留，人工授权永久删除。**

裁决：当前采用无自动过期的内容回收站策略。

已软删除的 Content 保留在回收站中，直到具有权限的操作者明确执行永久删除。现阶段不增加 TTL 自动清除，也不新增定时 Purge Job。本决策遵守「不静默自动清理」的已冻结契约。

| 场景 | 最终规则 |
|---|---|
| 内容软删除 | 进入回收站，仍可按现有规则恢复 |
| 长期未恢复 | 不因时间流逝而自动永久删除 |
| 人工永久删除 | 通过既有 force-delete 操作明确触发 |
| 清理范围 | 按已批准的 WP-5 规则清理内容、自有关联及翻译，不删除外部知识 |
| 备份与存储 | 不把回收站保留期限与备份保留期限混为一谈 |

「无限期」是**应用层策略**，不意味着数据库备份、灾备副本或基础设施中的历史数据必然永久保留。

以后若确需设置 30 天 / 90 天 / 可配置保留期限，必须另立架构决策，评估授权机制、恢复窗口、审计证据、备份保留及实际清理失败处理。本轮不授权这些改动。

---

## 阶段 1 汇总

| 议题 | 决策 |
|---|---|
| A1 retired | **DECIDED / NO-GO**：不新增独立状态，保留 archived |
| A2 review_due | **DECIDED**：到期不自动撤销资格，公开 Fact 的机器出口暴露确定性复核状态 |
| A3 JSON-LD | **DECIDED**：页面本地轨道；noindex 不单独禁止输出，所有字段受页面可见性约束 |
| A4 Trash Retention | **DECIDED**：无限期保留，人工授权永久删除；不做自动清理 |

---

## 下游实现映射（供后续 Implementation Contract，本阶段不实施）

以下为各决策落地时需要的实现项，**仅登记，不在本轮授权**：

- **A2**：geo.json facts 增加 `review_state` 字段；llms.txt Fact 条目增加复核状态标识；
  状态由 `FactReviewState` 确定性派生（不新增状态列）。
- **A3**：JSON-LD 渲染点按输出矩阵门控（draft / 软删 / 不可公开页面不输出对象 JSON-LD）；
  字段级页面可见契约由 SchemaBuilder 维持。
- **A1 / A4**：无代码改动（维持现状即符合决策）。

---

## 当前状态与 STOP

| 项目 | 状态 |
|---|---|
| D5 Implementation | ✅ CLOSED / APPROVED |
| Legacy Inventory（阶段 0） | ✅ CLOSED |
| 阶段 1：A1～A4 | ✅ DECIDED / CLOSED |
| 阶段 2：Reference Discovery | ⛔ 等待授权 |
| 产品代码 / 数据库迁移 | ⛔ STOP |
| UI / Remote / Push | ⛔ STOP |
| Release / Publish | ⛔ STOP |

**当前动作：STOP。** 不进入阶段 2 Reference Architecture Discovery，不实施任何产品改动。
下一候选阶段固定从 B1 Reference Inventory 复核开始，再审议 fact_entity_refs / media_refs / taxonomy_refs，
最后评估统一 Reference Framework 的 Go/No-Go；该阶段需独立授权。
