# Stage 1 Discovery 章程：Publication Lifecycle Domain

- **状态**：DISCOVERY CHARTER（2026-10-08，**未开始实施**）
- **基线**：`v1.0.0` = 504f71d（immutable）· `main` = 51f6348
- **上游**：`docs/product/roadmap-1x.md` 阶段一
- **纪律**：本文是**研究章程，不是实现授权**。

> **Roadmap 是方向，不是需求实现授权。**
> 每个 P0/P1 进入开发前，都必须重新完成 Discovery 与架构评估。
> 本文定义要研究什么、必须回答什么，**不预设答案**。

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

## 一、D2 优先于 D1：已发现真实缺口

**当前 `PublicIndex` 不是唯一权威口径。** 这是实测发现，不是推测。

```text
app/Support/PublicIndex.php  仅 84 行 / 3 个方法
  contentQuery()  entityQuery()  indexableEntitySlugs()
```

四个 GEO 出口的使用方式实测：

| 出口 | Entity / Content 来源 | 是否走 `PublicIndex` |
|---|---|---|
| `LlmsBuilder` | `PublicIndex::entityQuery()` / `contentQuery()` | ✅ |
| `SitemapBuilder` | `PublicIndex::entityQuery()` / `contentQuery()` | ✅ |
| `GeoGraphBuilder`（节点） | `PublicIndex::contentQuery()` 等 | ✅ |
| **`GeoGraphBuilder`（Content↔Entity 边）** | `Entity::whereIn('id', ...)` | ❌ **裸查询** |
| `SchemaBuilder` | 部分直接 Model 查询 | ⚠️ 需逐处核实 |

### 已确认的缺口（P2 级，非阻塞，已实证）

`app/Services/Geo/GeoGraphBuilder.php:238-240`

```php
$ceRows = \App\Models\ContentEntity::where('site_id', $siteId)->get();
$entById = Entity::whereIn('id', $ceRows->pluck('entity_id'))->get()->keyBy('id');
$pubContents = PublicIndex::contentQuery()->...   // Content 端受约束 ✅
```

**Content 端受 `PublicIndex` 约束，Entity 端是裸查询。**
`Entity` 有 `scopePublished()`（`app/Models/Entity.php:90`）但此处未使用。

**探针实测结果**（草稿实体 +已发布内容 + 一条 content_entity 边）：

```text
seg[entities]   count=1  → 只有 probe-pub-product        ← published 正确进入
seg[relations]  count=1  → to = probe-draft-product      ← 草稿实体成了关系端点
seg[contents]   count=1  → probe-article✅
```

即：**草稿实体不在实体列表里，却出现在关系端点上。**

对 AI 的实际后果：`geo.json` 会输出一条指向**不存在实体**的悬空关系。
AI 侧拿到「content/article/probe-article → entity/product/probe-draft-product」，
但实体列表里没有后者，无法解析出名称、类型与可信度。

这与 D-02（两个出口数据源不同源导致 AI 得到矛盾答案）是**同类问题的残留**。
D-02 已统一事实出口，但**关系端点的可见性口径尚未统一**。

> 注：该探针属一次性实证，已在 Discovery 启动后删除。
> 章程保留结论，正式判据由 D5Acceptance Contract 承接（需含变异验证）。

> **因此 Discovery 的第一个动作不是设计状态机，而是先测绘所有出口的可见性判定点。**
> 在不知道有几个口径的情况下统一口径，等于把未知问题变成返工。

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

## 九、执行顺序建议

```text
① D2 现状测绘（先知道有几个口径，才能统一）
        ↓
② D1 领域模型（基于测绘结果设计，不凭空设计）
        ↓
③ D4 级联清单（与 D1 同源，Trash 是生命周期的一部分）
        ↓
④ D5 验收契约（写码前定，不可后补）
        ↓
⑤ D3 Scheduled Execution（依赖前四项的模型）
        ↓
⑥ 架构影响评估 → Architecture Decision Lock
        ↓
⑦ Implementation
```

**D2 优先于 D1** 是因为：不知道现有几个口径就无法设计统一契约，
先设计状态机会把未知问题变成返工。

---

## 十、明确不做（Development 阶段）

```text
❌ 为「补齐成熟 CMS 功能」而做       —— 判据是运营闭环，不是功能计数
❌ Media Library 做成上传文件夹      —— 核心是 Usage Graph
❌ Content Engine = AI 写文章        —— 是 GEO Knowledge Production
❌ 为预览丰满度改模板包              —— Fidelity 原则
❌ 现在就打v1.1.0 tag               —— 先 Discovery，再 Architecture，再实现
```
