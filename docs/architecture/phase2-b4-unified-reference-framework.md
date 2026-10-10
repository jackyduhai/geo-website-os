# 阶段 2 · B4 Unified Reference Framework — 架构裁决

| 状态 | DECIDED / CLOSED |
| --- | --- |
| 日期 | 2026-10-10 |
| 裁决 | **GO：统一引用治理规范；NO-GO：统一引用实现（不建通用 references 表 / 通用 Resolver / 统一生命周期引擎 / 取代领域权威的通用删除守卫）** |
| 上游 | B1 Reference Inventory（`db8aba5`）、B2 fact_entity_refs（`fca721c`，NO-GO）、B3 media/taxonomy refs（`ecdff28`） |
| 取证方式 | 直接读取当前仓库（`D:\GEO-OS-rewrite\redact-v1`，main）真实模型 / 支持类 / 迁移 / 测试 |
| 本阶段范围 | 只读取证与裁决；**不改产品代码、不新增表 / 字段 / 迁移 / 服务**；不实施 Scanner 第 8 路 |
| MRF 基线 | 61（未变更） |

---

## 1. 裁决问题

B4 回答：九类引用已经分别盘点、并在 B2/B3 验证后，是否应进一步建设一个**统一的运行时 / 持久化 Reference Framework**（通用引用表 + 通用 Resolver + 统一生命周期 / 删除守卫）？

---

## 2. 最终 Go / No-Go 矩阵

| 层面 | 结论 | 内容 |
| --- | --- | --- |
| **统一引用治理规范** | ✅ **GO** | 共同的引用盘点矩阵、契约描述（Ownership / SoT / Lifecycle / Failure）、测试覆盖与变异验证原则 |
| **统一引用实现** | ❌ **NO-GO** | 不新增通用 references 表、通用 Resolver、统一生命周期引擎，或取代现有领域权威的通用删除守卫 |

---

## 3. NO-GO（统一实现）的依据

### 3.1 统一存储不会统一语义

B1 已证实九类引用在**存储形态、所有权、生命周期、删除权威、失败语义、覆盖状态**上各不相同。把它们放进同一张通用表，只是把差异转移到行的 metadata，并不会消除差异；反而需要一个通用引擎再解释每种类型，等于在领域层之上复制一套判定逻辑。

### 3.2 B2 / B3 已两次验证「领域专属」优于「形式统一」

- **B2（NO-GO）**：Fact 与 Entity 不应为形式统一建立关联；站点级复核已有明确触发与治理路径。
- **B3（NO-GO 表 / GO 补现有权威）**：媒体删除缺口应扩展现有 MediaReferenceScanner，而非新增第二份引用数据源；Taxonomy 保留现有类型化关系。

### 3.3 通用框架会反客为主、制造第二 SoT

- 各引用的权威本就属于具体领域（Entity / Content / Fact / Media / Menu / Category）。通用 Resolver / 守卫若取代它们，会产生第二事实源与跨 SoT 漂移，违反 P14/P15 与 D5 架构契约。
- 统一生命周期引擎会把「引用 ≠ 所有权」「门禁不反向 mutate」等已冻结边界重新耦合。

---

## 4. R1–R9 权威保留矩阵（不被通用框架取代）

| Ref | 引用 | 保留的领域权威（现有实现） |
| --- | --- | --- |
| R1 | Entity ↔ Entity | `EntityRelation` + 结构边生命周期（D5-3），跨站 saving 守卫 |
| R2 | Content ↔ Entity（结构） | `content_entity`（about/mention），forceDeleted 清理 |
| R3 | Content → Entity（语义） | `contents.entity_refs` + `SemanticReferenceResolver/Validator` + `ContentGate`（G-7 五分支） |
| R4 | Fact ↔ Entity | **有意缺失**：无 fact_entity_refs；purge → 站点级 fact_review_markers |
| R5 | Menu → 内部目标 | `MenuTargetResolver` + `MenuReferenceGuard`（删除前阻断，INVALID 降级） |
| R6 | Media 使用引用 | `MediaReferenceScanner`（唯一删除资格入口；PageBlock 第 8 路待实施） |
| R7 | Taxonomy / Category | `Category.parent_id` 自引用 + `content.category_id`（FK）+ `content_tag` pivot |
| R8 | SEO / Settings 媒体 | `SeoMeta` + `Settings`（纳入 Scanner 路 6/7） |
| R9 | Page / Block 结构 | `PageBlock` + `BlockRegistry`（Block 实时读模型，按当前状态重算） |

---

## 5. GO：统一引用治理规范（保留并推广）

这是本次唯一「统一」的部分，属于**方法与契约**，不是运行时基础设施：

1. **统一盘点**：任何新增引用类型必须先进入引用矩阵，记录 Source→Target / 存储 / Ownership / Lifecycle / Delete Authority / Guard / Failure Semantics / Test Evidence。
2. **统一契约语言**：用 MUST / MUST NOT、Ownership、SoT、Lifecycle、PKS、Failure/Degradation 描述。
3. **统一测试要求**：每个引用权威必须有契约测试 + 负向测试 + **变异验证**（移除守卫后测试必须 FAIL）。
4. **统一新增纪律**：新增引用类型必须明确归属权威、失败语义与测试，**不允许绕过既有保护**；不得在没有结构化关系时声称具备自动检测能力。
5. **统一的禁止项**：不反向 mutate、不静默改写、不复制历史快照、不把引用健康度提升为发布资格。

---

## 6. 对后续架构的约束

- 结构关系 / 语义引用 / 导航引用各自保留领域权威。
- Fact 与 Entity 不建通用映射，不声称单 Fact 漂移定位。
- Media 仍以 MediaReferenceScanner 为删除资格唯一入口（第 8 路 GO 但须单独授权）。
- Taxonomy 保留分类 / 标签 / 父子层级各自的关系结构。
- PKS 继续作为派生的知识资格投影，不物化成通用引用集合。
- 本裁决的 NO-GO **不取消** B3 已批准的 Scanner 第 8 路。

---

## 7. 重开条件

只有在以下条件出现时，才另开裁决重新评估统一实现：

1. 出现**跨多个领域、语义稳定且可复用**的引用契约（而非仅代码重复），并由真实场景证明。
2. 领域专属实现的维护成本经证据显示显著高于共享框架，且共享框架不会制造第二 SoT。
3. 先行的引用矩阵证明多类引用在身份、删除权限、生命周期上确实收敛到同一语义。

---

## 8. B4 关闭结论与 STOP

| 项目 | 状态 |
| --- | --- |
| 统一引用治理规范 | ✅ **GO（采用）** |
| 统一引用实现（通用表 / Resolver / 引擎 / 守卫） | ✅ **NO-GO** |
| Scanner 第 8 路实施 | ⛔ 未授权（B3 已 GO，独立授权） |
| 产品代码 / 迁移 / UI | 0 改动 |
| Remote / Push / Release | ⛔ STOP |

**阶段 2（B1–B4）至此全部收口。** **当前动作：STOP。** 不自行实施 Scanner 第 8 路，不修改产品代码；发布链路保持冻结。后续要么授权实施 Scanner 第 8 路，要么进入阶段 3（Media / Derived-System Architecture）或独立的 Release Readiness，均需明确授权。
