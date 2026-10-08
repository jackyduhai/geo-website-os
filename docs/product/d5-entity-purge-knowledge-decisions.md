# D5 交付物 1：Entity 永久清除后的知识世界（Domain Decisions）

- **状态**：D5 IN PROGRESS（2026-10-08）· **决策研究，非实现设计**
- **事实基线**：`d1-lifecycle-domain-model.md` · `d2-public-surface-inventory.md` · `d4-deletion-recovery-semantics.md`（FROZEN）
- **章程**：`docs/product/stage1-discovery-charter.md` D5
- **D5 真正第一问**：

> **Entity 被永久清除以后，系统中的知识资产应该变成什么状态？**

**D4 已完成「删除是否影响 PKS」的证明，D5 不再重复。**
D5 任务纯粹是决定目标状态。

---

## 零、本轮取证的三个决定性发现

这三项改变了 D5 部分问题的**性质**，不是补充细节。

### 发现 1：`facts` 表**没有 `entity_id`**，它是站点级资产

```text
facts 列：id, site_id, key, label, value, group, source, owner,
          reviewed_at, review_due, is_public, sort, unit, source_url,
          locale, translation_group

外键：仅 site_id → sites（on_delete=restrict）**没有任何 entity FK**
```

`owner` 实际取值是**业务主体**而非 Entity：

```text
owner 取值：["顾问", "企业", "Consultant"]
```

**⇒ 事实的归属维度是「业务主体 + 站点」，不是某个 Entity。**

### 发现 2：`content_entity` **没有任何外键**，是模型层显式 CASCADE

```text
content_entity FK 数 = 0
但 Entity.php:47-49 有：
  // M-2：content_entity 无 DB 外键约束，硬删时清理该实体的内容关联行
  ContentEntity::where('entity_id', $entity->id)->delete();
```

实测：删 Entity 后该行确实消失。

**⇒ D4 文档中「content_entity CASCADE（FK）」的表述不准确，
实际是「模型层显式 CASCADE」。** 两者行为相同但**归因不同**——
这很重要，因为它意味着**改这条规则必须改模型钩子，不是改数据库约束**。

### 发现 3：8 类 Entity **全部 `geo=1`**，都进入知识图谱

```text
type               public searchable geo sitemap schema
organization       0     0          1    0       Organization
product            1     1          1    1       Product
service            1     1          1    1       Service
person             0     0          1    0       Person
location           0     0          1    0       Place
topic              0     0          1    0       WebPage
case_study         1     1          1    1       CaseStudy
download_asset     0     0          1    0       -
```

**关键读法**：

```text
· 全部 8 类都进 geo.json 节点 → 全部都是 AI 可发现的知识资产
· 但只有 3 类有独立落地页（product / service / case_study）
· organization 虽public=0，却是 Catalog 的**根依赖**
  （无 organization 时 products() 为空、indexableEntitySlugs() 为空）
  → 它是**知识生产的必要前提**，删除影响面最大
· download_asset 无 schema，是唯一不进 JSON-LD 的类型
```

---

## 一、D5 第一问：知识资产清除后的目标状态

### 1.1 事实基础：Entity 不是一个均质的删除对象

D4 说「Entity 物理删除」，但 D5 取证显示**8 类 Entity 的知识地位差异极大**：

| type | 独立落地页 | 进 geo 图谱 | 作为 Catalog 根 | 知识地位 |
|---|---|---|---|---|
| **organization** | ❌ | ✅ | ✅ **是** | **知识生产的必要前提** |
| product | ✅ | ✅ | 依赖 org | 核心知识资产 |
| service | ✅ | ✅ | 依赖 org | 核心知识资产 |
| case_study | ✅ | ✅ | 依赖 org | 核心知识资产 |
| person | ❌ | ✅ | 否 | 语义节点（Person schema） |
| location | ❌ | ✅ | 否 | 语义节点（Place schema） |
| topic | ❌ | ✅ | 否 | 语义节点（WebPage schema） |
| download_asset | ❌ | ✅ | 否 | 媒体资产，**无 schema** |

**⇒ D5-2（Entity 是否改为可恢复）不能给出一个全局答案。**

### 1.2 三个必须回答的子问题

```text
Q1  organization 被清除后，Catalog 的全部派生数据（products / scenes /
    productLines / workshops / salesRegions）会一起失效。
    这是否可接受？还是必须先 DETACH 某些依赖？
    —— 它是「一��知识资产」还是「知识生产的根」？

Q2  product / service / case_study 有独立落地页与 sitemap 收录。
    清除后 URL 会 404。对已发布内容而言这是**对外可见的破坏**。
    —— 它们的不可恢复性是否应该被显式承认为业务规则？

Q3  person / location / topic 是**纯语义节点**（无落地页、无 sitemap）。
    它们只作为关系端点与 schema 节点存在。
    —— 这类 Entity 是否应与有落地页的 Entity 采用不同策略？
```

### 1.3 建议的目标状态（待 D5-2 裁决确认）

```text
Entity purge 后，**知识资产本身不应被物理删除**，
而应进入一个**明确的「不再公开但保留可解释性**的状态」。

理由：
  · Entity 是 AI 答案的结构来源（geo.json 全部 8 类都在图谱里）
  · organization 是 Catalog 根依赖
  · product/service/case_study 有对外 URL
  · 物理删除使Relation / content_entity / 语义引用全部失去解释
```

---

## 二、D5-3：Entity purge 后 Relation 的生命周期

### 2.1 当前事实

```text
entity_relations 两端 FK 均 ON DELETE CASCADE（实测生效：1 → 0）
```

### 2.2 关系的业务性质：结构 or 数据？

```text
Entity A ──related_to──> Entity B
```

关系本身**是否有独立知识价值**？取证显示：

```text
· entity_relations 无 status、无 is_public、无独立公开面
· 它不产出 sitemap 条目、不产出独立 JSON-LD 节点
· 它只是 geo.json relations 段的一条边
```

**⇒ 关系是「结构」，不是「知识资产」。** 它的公开性完全由两端派生。

### 2.3 三种策略的对比

| 策略 | 含义 | 后果 |
|---|---|---|
| **CASCADE**（当前） | 两端任一消失 → 边消失 | 结构干净；但边永久丢失，**不可审计** |
| **DETACH** | 删端点 → 保留边但标记不完整 | 产生「悬空边」，需额外状态表达 |
| **PRESERVE** | 保留边及其两端快照 | 知识可解释；但需引入快照机制 |

### 2.4 建议（CURRENT → TARGET）

```text
CURRENT: CASCADE（FK，模型与数据库双重）

TARGET:  **DETACH with dangling marker**（倾向）
  · 删除 Entity → 关系行保留，但标记 endpoint_missing
  · 该边**不进入 Public Knowledge Set**（不违反 P7 不变量）
  · 保留审计价值：可回答「这条关系曾经存在，现在对方没了」
  · 若另一端恢复 → 该边自动回到 PKS（因为是重算）

**理由**：关系虽然不是知识资产，但「A 曾与 B 有关系」是**可审计的历史**。
彻底删除会让审计无法回答「这个 entity 为什么消失了」。
```

**但 D5-3 必须裁决：是否值得为审计价值引入 dangling 状态？**
若不引入，则 CASCADE 保持不变——**这是取舍，不是缺陷。**

---

## 三、D5-4：content_entity 与 Content 语义引用

### 3.1 当前事实（修正 D4 表述）

```text
content_entity **无任何 FK**
Entity::booted() 的 deleting 钩子显式清理该实体的行
⇒ 实际是「模型层显式 CASCADE」，不是数据库 FK
```

**⇒ 要改这条规则必须改 `Entity::booted()`，不是改迁移。**

### 3.2 结构引用 vs 语义引用（P7 的具体化）

```text
Structural relation   →  content_entity 行
Semantic reference    →  Content 正文 / blocks / metadata / JSON-LD / link
```

**取证后的关键事实**：

```text
删 Entity → content_entity 行被清理
但 Content 仍在（published）
⇒ Content 正文可能仍写着「A 公司成立于 2012 年……」
```

D5 取证发现 `content_entity` 零 FK，意味着：

```text
★ **当前不存在「悬空结构引用」**（钩子保证了清理）
★ 真正的问题是「**语义引用残留**」
   —— 即内容正文里的显式知识引用，数据库无法表达也无法检测
```

### 3.3 建议（CURRENT → TARGET）

```text
content_entity   CURRENT: 模型层显式 CASCADE（Entity::booted()）
                 TARGET:  保持 CASCADE 或改 DETACH —— 取决于 D5-3 结论一致性
                 理由：结构引用随主体消失是合理的，不留悬空边

语义引用（Content 正文/blocks）  CURRENT: 无任何机制（数据库无法表达）
                              TARGET:  **不强制清除，但必须可识别**
```

**关于语义引用的建议策略**：

```text
Entity purge 后，仍存 Content 中的显式知识引用
  → 默认**仍允许公开**（内容本身是已发布事实）
  → 但必须可在 D5 定义的「知识资产状态」中**识别出它引用了已清���的实体**
  → 供后续人工复核

**理由**：强制改写已发布正文是危险操作（会静默改变企业已公开的事实）；
而完全不管则会让 AI 拿到指向不存在实体的陈述。
```

**⇒ 真正的 D5-4 问题不是「要不要删」，而是「如何让语义引用可被识别与告警」。**

---

## 四、D5-5：Fact 的历史知识资产生命周期

### 4.1 取证后的重大简化（P6 依然成立，但问题变了）

D4 基于「facts 可能随 Entity CASCADE」提出问题。**D5 取证发现前提不成立**：

```text
facts 表**没有 entity_id**
· 外键仅 site_id → sites
· owner 取值是业务主体（企业 / 顾问 / Consultant），不是 Entity
· geo.json 的 facts 段来自 Fact::publicRows()（站点级查询）
```

**⇒ Entity purge 根本不会 CASCADE facts。P6 已经是事实，不是待裁决项。**

### 4.2 那么真正的问题是什么

**事实的归属维度是「站点 + 业务主体」，所以问题变成**：

```text
Entity purge 后，与该 Entity 强相关的 facts：

Q1  同一 business 的 facts 应当保留吗？
    → 应当。它们描述的是**企业**，不是那个产品/服务实体。

Q2  那些**只对被purge 实体有意义**的 facts 怎么办？
    → 例如「该产品的年产能 5000 吨」——产品没了，这条事实还有意义吗？

Q3  事实的 `is_public=0` 行是内部草稿还是历史？
    → 取证：is_public 分布 [1, 0]，0 的行是待核定草稿
    → 已核定（reviewed_at非空）的内部事实是否应受保护？
```

### 4.3 建议（CURRENT → TARGET）

```text
CURRENT:  facts 不随 Entity 删除（无FK）→ 事实天然保留 ✅
         但没有任何机制区分「企业级事实」与「实体级事实」

TARGET:   引入**归属粒度**（不改变 SoT，只增加归属标记）
         · 企业级事实：owner=企业，Entity purge 后仍公开
         · 实体级事实：需标记关联实体
                      —— Entity purge 后默认**降级为 is_public=0**
                      —— 但**不删除**，保留可恢复性
         · 已核定（reviewed_at 非空）的事实
                      → 永不因 Entity purge 而降级

         依据 P6：Business Facts are protected knowledge assets
```

**⇒ D5-5 的正确表述（修正 D4 版）**：

> **Fact 的归属粒度是什么？** Entity purge 时，
> 哪些 facts 保持公开，哪些降级为不公开但保留？
> 判据：**企业级事实 vs 实体级事实**，
> 且**已核定事实受更强保护**。

---

## 五、D5-1 / D5-6 / D5-7：三个 P1 决策

| 决策 | 取证 | 建议方向 |
|---|---|---|
| **D5-1** Content Trash 是正式能力还是移除错误承诺 | 软删已存在，restore 技术可行，**无 UI 入口**；提示语已承诺 | 倾向**正式能力**（P3 已有重算机制，C/P 内容资产需可恢复），但需同时修提示语或补 UI |
| **D5-6** Page 是可恢复资产还是可再生结构 | `Page::booted()` 已级联清理 blocks + SeoMeta；页面常由 recipe 生成可再生 | 倾向**可再生结构**，不需 Trash。但 D5-4 结论若要求内容不静默变空，需配套告警 |
| **D5-7** Media 是可恢复资产还是引用保护 + 永久删除 | 已有完整 `MediaReferenceScanner` 引用保护 + 阻断；软删二进制意义有限 | 倾向**引用保护 + 永久删除**（保持现状），但需补「删除前影响面预览」 |

---

## 六、D5 的决策依赖图

```text
D5 第一问：Entity purge 后知识资产应变成什么状态？
    ↓
   ┌────────────────┬────────────────┬────────────────┐
   ↓                ↓                ↓                ↓
D5-2Entity      D5-3Relation    D5-5Fact         D5-4 content_entity
是否可恢复      CASCADE/        归属粒度        + 语义引用可识别性
（按 type 分）   DETACH/PRESERVE                （结构层已解决）
   │                │                │                │
   └────────────────┴────────┬───────┴────────────────┘
                           ↓
                  Public Knowledge Set 边界
                           ↓
              D5-6 Page · D5-7 Media · D5-1 Content Trash
                           ↓
                   具体生命周期原语（最后才决定）
```

**关键：先决定知识状态，再决定原语。**
不先从 `SoftDeletes` / Trash Controller / Purge Service 起步。

---

## 七、本轮已可冻结的结论

```text
① P6 已是事实而非待裁决：facts 无 entity FK，Entity purge 不会CASCADE facts
   ⇒ D5-5 的问题从「是否允许 CASCADE」变为「事实的归属粒度是什么」

② content_entity 是模型层显式 CASCADE（Entity::booted()），不是数据库 FK
   ⇒ 改这条规则要改模型钩子，不是改迁移

③ 当前不存在悬空**结构**引用（钩子保证清理）
   真正问题是**语义引用残留**：Content 正文里对已删实体的显式知识
   ⇒ D5-4 的正确问题是「如何让语义引用可被识别与告警」

④ Relation 是结构不是知识资产（无独立公开面）
   ⇒ D5-3 的取舍是「是否值得为审计价值引入 dangling 状态」

⑤ 8 类 Entity 全部进 geo.json，其中 organization 是 Catalog 根依赖
   ⇒ D5-2 不能给全局答案，必须按 type 分别裁决
```

---

## 七之二、D5-2 裁决结果（已出，见独立交付物）

**D5-2 已裁决**：`d5-2-entity-lifecycle-class-matrix.md`

三个子问题的答案：

```text
Q1  organization 是 **Site Knowledge Aggregate Root**
    但实现上混入了两种角色：
      站点主体（metadata.is_site_organization=true，向导自动创建、
                承载全部 Catalog metadata、被 same_as 锚定为 Site 身份）
      客户案例（代码里已有 role=customer 判断，但创建路径不产生它）
    ⇒ 站点主体**不应暴露为普通 Entity Delete**
    ⇒ 永久 purge 仅在 Site teardown 语境
    ⇒ 当前删除入口**零保护**（EntityController:245 无任何 type 区分）

Q2  product / service / case_study（Public Knowledge Asset）**必须可恢复**
    有 URL + sitemap + search + geo + schema，对外可见后果明确

Q3  person / location / topic（Semantic Node）**不需要传统 Trash**
    真正需要的是「删除后退化规则」：不产生公开悬空引用
```

**最重要的一条新冻结原则**：

```text
P11  Entity 生命周期策略由**生命周期角色**决定，不由 ORM trait 决定。
     同一 type 内可能存在不同角色 —— organization 就是活证据。
```

**连锁影响**：D5-2 一旦确定生命周期模型，Relation 答案自然收敛：

```text
SoftDelete → PRESERVE structurally, SUPPRESS semantically
Purge      → CASCADE
```

⇒ D5-3 不再是「CASCADE/DETACH/PRESERVE 三选一」，
   而是**由上游 Entity 生命周期决定的二元策略**。

---

## 八、本文档不做的事

```text
❌ 不设计 Trash UI / SoftDeletes / Purge Service
❌ 不改任何代码 / 测试 / 迁移
❌ 不打 tag
❌ 不替 D5-2 裁决 Entity 是否可恢复（只提出必须回答的 3 个子问题）
❌ 不重开 D4（D4 已 FROZEN）
```

**下一步**：D5-2 是第一个必须裁决的决策，因为它的答案决定
Relation / Fact / content_entity 的处理方式。
