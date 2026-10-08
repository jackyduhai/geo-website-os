# Stage 1 Discovery 章程：Publication Lifecycle Domain

- **状态**：**D4 FROZEN**（2026-10-08）· D2✅ D1✅ D4✅ · **下一步 D5 Domain Decisions**
- **基线**：`v1.0.0` = 504f71d（immutable）· `main` = ae30d7e
- **上游**：`docs/product/roadmap-1x.md` 阶段一
- **交付物**：
  - D2 → `d2-public-surface-inventory.md` ✅
  - D1 → `d1-lifecycle-domain-model.md` ✅
  - D4 → `d4-deletion-recovery-semantics.md` ✅ **FROZEN**
- **纪律**：本文是**研究章程，不是实现授权**。
- **D4 冻结后不再为文字细枝末节重开**；剩余事项作为
  D5 / Architecture Decision / Implementation Gate 的**输入约束**向下传递。

---

## 零之二、D4 冻结的三条硬约束（超出「删除功能」范畴）

```text
P6  Fact 是受保护的知识资产，Entity purge 不得 CASCADE 删除
P7  结构关系与语义知识引用必须分开处理
PKS 不是数据库 join
    = Domain eligibility + lifecycle state + relationship semantics
    + semantic references + publication policy + derived-output validity
```

这三条已成为 GEO Web OS 的**知识生命周期基础规则**，
供 D5 / D3 / Architecture Decision / Implementation / 测试共同引用。

---

## 零之三、阶段序列（含 D3 重新定位）

```text
D1  Lifecycle Domain Model                ✅
D2  Public Surface Inventory             ✅
D4  Deletion / Recovery Semantics        ✅ FROZEN
    ↓
D5  Domain Decisions                      ← 当前应进入
    ↓
D3  Architectural / UX implications      （原 Scheduled Execution）
    ↓
Architecture Decision
    ↓
Implementation
    ↓
Mutation / Regression / HTTP Gate
```

**D3 重新定位的理由**：scheduled 相关决策依赖生命周期模型
（Content 已有时间门禁，Entity/Page 没有），因此在 D5 之后更合适。

### D5 的顺序纪律

> **先决定「什么知识应该存在」，再决定「数据库怎么删」。**

不得从 `SoftDeletes` / Trash Controller / Purge Service 起步。

**D5 真正第一问**：

> **Entity 被永久清除以后，系统中的知识资产应该变成什么状态？**

这一问裁决清楚后，
`Relation → Fact → Content semantic reference → PKS → Cache/Search/GEO/SEO`
都会自然收敛。

---

## 零之四、可提前冻结的原则（删除语义）

> **删除不是数据库操作，而是 Public Knowledge Set 的变更事件。**

```text
传统 CMS   Delete = 数据库行没了
GEO Web OS Delete = 公开知识边界改变
                = Frontend + GEO + LLM + Schema
                + Sitemap + Search + Relations + Cache
```

已由 D4 探针实证：删除与恢复**确实**触发全部出口重算。

> **Roadmap 是方向，不是需求实现授权。**
> 每个 P0/P1 进入开发前，都必须重新完成 Discovery 与架构评估。
> 本文定义要研究什么、必须回答什么，**不预设答案**。

---

## 零之前置：Probe Validation 原则（已从本轮实践升格）

本轮第一次探针返回 `draft_in_geojson = false`，
若直接采信，就会把一个**真实缺口**写成「已排除」。
因此确立探针的四步验证法：

```text
Probe Validation
1. Positive control exists            阳性样本真实存在
2. Probe detects positive control     探针能发现阳性样本 → 证明路径通
3. Probe detects negative control     探针能正确排除阴性样本 → 证明过滤有效
4. Only then interpret target result  才可解释目标结果
```

**缺第 2 步时，「未发现」只能说明「探针没找到路径」，不能说明「缺陷不存在」。**

> **探针失败 ≠ 被测对象正常。**
> 本轮实例：测试库无数据 / 表名猜错（`content_entity` 单数）/ 读错结构
> （`entities`+`relations`+`contents` 三段，不是 `edges`）——三者叠加造成假阴性。

---

## 零、为什么这三项必须放在一起研究

Roadmap 阶段一的三个 P0：

```text
Scheduled Publish
Trash / Restore / Purge
Publication Lifecycle Contract
```

它们不是三个功能，而是**同一个 Publication Lifecycle Domain 的三个外在表现**：

```text
Scheduled   ← 时间维度的状态转移
Trash       ← 可逆性维度的状态转移
Lifecycle   ← 状态机的整体抽象
```

分开研究会得到三套互不兼容的状态定义，
最后必然出现「Scheduled 的内容能不能进 Trash」这类无法回答的问题。

---

## 一、D2 优先于 D1：已测绘完成，缺口已定位

**D2 交付物**：`docs/product/d2-public-surface-inventory.md`（已完成，2026-10-08）

测绘结论摘要：

```text
发现型出口主体路径  4/5 严格遵守 PublicIndex 契约
  ✅ Sitemap / RSS / Search / LLM 实体段 / GEO 节点与 Entity↔Entity 关系

缺口 4 处
  G-1  GeoGraphBuilder:238   Content↔Entity 边端点   无 published + 无 locale
  G-2  SchemaBuilder:293      article about/mentions  无 published
  G-3  SchemaBuilder:393-403  产品 about              无 published + 无站点隔离 + 无 locale  ← P1
  G-4  Catalog vs PublicIndex noindex 口径分歧       需 D5 裁决
```

**核心洞察**：

> `PublicIndex` 只解决了「实体和内容」的可见性，
> **没有解决「关系」与「语义节点」**。
> 三处G-1/G-2/G-3 不是三个独立 bug，而是同一个抽象缺失的三种表现。

因此 Discovery 的第一个动作不是设计状态机，而是测绘——
**这一步已完成**，接下来才能进入 D1。

### 硬门槛（进入 Implementation 前必须可执行）

> 对于给定 Site + Locale + Time，系统可以**唯一、确定性地**计算
> Public Knowledge Set；所有人类页面与 AI/搜索出口**只能从这个集合派生**，
> 不得自行定义另一套可见性。

允许不同 Domain SoT（Entity / Content / Fact / Page / Relation），
但 `Publicness` **不能再出现七套解释**。

### D2 不是独立调查完就结束

它输出的 Inventory + Invariants **必须能被 D1 的状态机解释**：

```text
若Lifecycle 能解释 Frontend
但不能解释 GEO relations / Schema 节点
    → 不是 GEO 去迁就 Lifecycle
    → 而是 **Lifecycle 模型尚未完整**
```

---

## 二、D1：Lifecycle Domain Model

### 必答问题

```text
1. 谁拥有 publication state？
   —— Content / Entity / Page / Block / Category / Fact 各自还是共享？

2. 哪些实体可以独立 publish？
   哪些只能随父级 publish？（如 knowledge 文章随所属分类）

3. Trash 是否与 Draft 属于同一状态机？
   —— 决定 Trash 是状态还是正交维度（可逆性）

4. Purge 后哪些关系必须保留 / 清理？
   —— EntityRelation / ContentEntity / Media 引用 / GEOGraph 残留
```

### 已知现状（实测输入，非结论）

| 表 | 现有生命周期字段 |
|---|---|
| `contents` | `status`, `published_at`, **`deleted_at`** |
| `entities` | `status`, `published_at` |
| `pages` | `status` |
| `form_submissions` | `status` |
| `inquiries` | `status` |
| `entity_relations` / `page_blocks` / `facts` / `categories` / `media` / `seo_metas` | **无** |

**字段分布高度不一致**，这本身就是 D1 要回答的核心问题：

```text
· contents 有 deleted_at，entities 没有 →软删除是局部的还是全局的？
· facts / categories 没有 status       → 它们是否参与可见性判定？
· page_blocks 无status            → 区块随页面走，还是可独立下线？
```

### 必须证明的抽象可行性

「统一生命周期，不给 Content 单独造」是**架构目标，不是实现答案**。
Discovery 必须证明它能覆盖：

```text
36 张表· GEO · Schema · Sitemap · LLM 输出 · Cache · 关系清理
```

若证明不成立，**必须如实报告并给出替代方案**，不得强行套用。

---

## 三、D2：Public Index Contract

### 目标

> **Publication Boundary = Knowledge Visibility Boundary**

Publication Boundary 一动，整个机器可读知识集合都要变：

```text
publish_at 变更
  → Public Knowledge Set 变化
  → GEO / geo.json 变化
  → Sitemap 变化
  → Schema 变化
  → LLM output（llms.txt）变化
  → Cache / Search surface 变化
```

### 必答问题

```text
1. 什么条件 = Public？什么条件 = Not Public？
   ——写成单一判定入口，禁止各出口自行判断

2. 七个出口是否全部收敛到该入口？
   Frontend / GEO / LLMS / Schema / Sitemap / Search+InternalLinks / Cache

3. PublicIndex 是否需要扩成完整的可见性契约？
   当前仅 84 行 / 3 方法，是否够承载 Entity 的多状态（含 Trash / Scheduled）？
```

### 硬性要求

**不能再出现某一个出口自己重新判断一次。**
新增出口时若绕过契约，视为架构违规，不是实现瑕疵。

### 现状测绘结论（已完成，详见交付物）

```text
✅ 已遵守：Sitemap / RSS / Search / LLM 实体段 / GEO 节点 / GEO Entity↔Entity 关系
❌ 缺口：G-1 GEO Content↔Entity 边端点
        G-2 Schema article about/mentions
        G-3 Schema 产品 about（三维度同时失守，P1）
⚠️ 待裁决：G-4 Catalog 与 PublicIndex 的 noindex 口径分歧
✅ 契约外（合理）：前台详情页 / Internal Links / robots.txt
```

### 不变量（草案，D5 正式化）

```text
∀ edge(A, R, B) ∈ PublicRelations:  public(A) ∧ public(B)
∀ node N ∈ PublicSchema:            public(N)
```

**当前违反。** 三处缺口都输出「edge public 但 node private」。

将来应作为 **mutation test 目标**：故意短路端点过滤 → 必须有测试失败。

### 判据（引用工程验收原则）

```text
✗ 控制流断言：GeoGraphBuilder 类里有 where('status', 'published')
✓ 结果语义断言：草稿 Entity 不得作为 geo.json 边的端点出现
```

---

## 四、D3：Scheduled Execution

### 必答问题

```text
1. Scheduler 还是 Queue？
2. Retry 策略：失败几次后放弃？失败状态存哪？
3. Idempotency：同站点 / 同页面 / 同一时间重复执行两次会发生什么？
4. Timezone：站点时区还是服务器时区？多站点跨时区如何处理？
5. Multi-site：调度器是多站各跑一次，还是全局一次按站点分派？
6. Locale：多语言内容是否独立调度？
7. Failure Recovery：调度器宕机错过时间点后，补跑还是跳过？
```

### 必须给出实证答案的那一条

> **同一站点、同一页面、同一时间重复执行两次，会发生什么？**

这是幂等性判据。GEO 产品的输出会被 AI 反复读取，
重复调度导致的重复边 / 重复条目比「不发布」危害更大。

**不允许用「框架保证幂等」作答**，必须实测。

---

## 五、D4：Trash 数据边界

### 禁止的做法

> ❌ 「加 `trashed_at` 就完事」

### 必答问题

对以下每一类，明确「进入 Trash / 仅失去 Public Visibility / 必须级联 / 绝不能级联」：

```text
Content · Media · Entity · Relation · Page · Block · Fact
GEO Graph · Attribution · Inquiry / FormSubmission · Audit · Cache
```

### 需特别回答

```text
1. Inquiry / FormSubmission 能否进 Trash？
   —— 询盘是业务数据还是展示内容？删除可能涉及合规
2. AuditLog 是否可Purge？
   —— 审计日志通常有留存要求
3. Fact 能否进 Trash？
   —— facts 是 AI 答案的事实来源，进 Trash 意味着 AI 答案改变
4. Media 被多处引用时，Trash / Purge 如何处理？
```

### 必须避免的残留

```text
删除 Entity → Relation 残留 → GEOGraph 残留
```

Discovery 需产出**级联清单**，而不是实现代码。

---

## 六、D5：Acceptance Contract（写码前先定）

### 必先写出来的验收场景

```text
【Scheduled publish 到点】
  人可见 · AI 可见 · sitemap 可见 · Schema 可见 · GEO 可见

【Unpublish】
  上述全部撤出

【Trash】
  Public Surface 全撤 · 可恢复 · 恢复后回到 Draft 而非 Published

【Purge】
  数据真正清除 · 无残留引用 / 缓存 / 关系
```

### 每个场景必须能被变异测试打穿

```text
变异：把 Publication Boundary 判定短路
  → 对应验收场景必须失败
```

否则该场景是假绿，不计入验收。

### 已存在的反面教材

`contents` 有 `deleted_at` 而 `entities` 没有 —— 说明历史上已有一个
**局部软删除实现**。Discovery 必须先查清它被谁使用、语义是什么，
**不得在其之上叠第二套机制**。

---

## 七、Discovery 交付物要求

```text
1. 现状测绘报告
   · 七个出口各自的可见性判定点（含 GeoGraphBuilder 已确认缺口）
   · 各表生命周期字段矩阵
   · 现有 deleted_at 的实际使用方与语义

2. 领域模型提案
   · 状态机 / 正交维度 / 继承关系的取舍与理由
   · 必须回答「为何成立」，而非「为何方便」

3. 可见性契约提案
   · 单一判定入口的设计
   · 七个出口的收敛方案

4. 级联清单
   · Trash / Purge 的完整边界

5. 验收契约
   · D5 的场景，附带变异验证方案

6. 架构影响评估
   · 是否触发 Architecture Change Review
   · 对 36 张表 / 迁移 / 缓存 / 已有 1476 测试的影响面

7. 明确的不做项
   · 本阶段不碰什么
```

**任何一项缺失视为 Discovery 未完成，不得进入 Implementation。**

---

## 八、Discovery 的验收也适用工程验收原则

Discovery 本身也要被验收：

```text
✗ 「我看了代码，应该没问题」
✓ 「GeoGraphBuilder:238 的 Entity 端缺 published 约束 —— 实测草稿实体进入 geo.json」

✗ 「框架保证幂等」
✓ 「同站点同页面同一时间重复执行两次：边数从 N 变为 2N / 不变（实测）」

✗ 「加 trashed_at 就行」
✓ 「Inquiry 能否进 Trash？AuditLog 能否 Purge？逐项回答」
```

（详见 `docs/product/engineering-acceptance-principles.md`）

---

## 九、执行顺序与当前进度

```text
① D2 现状测绘              ✅ d2-public-surface-inventory.md
② D1 领域模型              ✅ d1-lifecycle-domain-model.md
③ D4 删除/恢复语义         ✅ FROZEN  d4-deletion-recovery-semantics.md
④ D5 Domain Decisions     ← 下一步（7 个决策，见下）
⑤ D3 Architectural / UX implications（原 Scheduled Execution）
⑥ Architecture Decision Lock
⑦ Implementation → Mutation / Regression / HTTP Gate（未获授权）
```

### D5 的 7 个 Domain Decisions

```text
D5-1 P1  Content Trash 是正式能力还是移除错误承诺
D5-2 P0  Entity 是否改为可恢复生命周期
D5-3 P0  Entity purge 后 Relation：CASCADE / DETACH / PRESERVE
D5-4 P0  Entity purge 后 content_entity 是否 DETACH
          **且：仍存 Content 中的语义引用是否仍可公开**
D5-5 P0  Entity purge 后 Fact 的历史知识资产生命周期
          （**CASCADE 已定为禁止项 P6**；
            PRESERVE 的 owner / visibility / rebind / audit 语义待定）
D5-6 P1  Page 是可恢复资产还是可再生结构
D5-7 P1  Media 是可恢复资产还是引用保护 + 永久删除
```

**决策顺序**（不先讨论 Trash UI）：

```text
Entity → Relation → Fact → Content 语义引用
       → Public Knowledge Set → Cache / Search / GEO / SEO
```

**G-5 单独作为 Implementation Gate，不是 Decision**——
它不是「业务该怎样」，而是「已确定该怎样但当前没做到」。

### D4 交给 D5 的两个台账

```text
G-5（真实，P1）Content lifecycle mutation 不失效 PageCache
             （**仅 PageCache**。Search 索引已实测自动失效 1→0→1）
G-6（已撤销）原怀疑「Page 删除后 page_blocks 残留」
             → Page::booted() 有 deleting 钩子级联清理，是全库最规范者
             留痕：**「缺口」必须追到根，不能停在现象**
```
```

---

## 十、明确不做（Development 阶段）

```text
❌ 为「补齐成熟 CMS 功能」而做       —— 判据是运营闭环，不是功能计数
❌ Media Library 做成上传文件夹      —— 核心是 Usage Graph
❌ Content Engine = AI 写文章        —— 是 GEO Knowledge Production
❌ 为预览丰满度改模板包              —— Fidelity 原则
❌ 现在就打v1.1.0 tag               —— 先 Discovery，再 Architecture，再实现
```
