# D1 交付物：Lifecycle Domain Model + State Semantics + Public Knowledge Set 边界

- **状态**：D1 COMPLETE（2026-10-08）· **模型研究，非实现设计**
- **事实基线**：`docs/product/d2-public-surface-inventory.md`（D2 SURVEY COMPLETE）
- **章程**：`docs/product/stage1-discovery-charter.md` D1
- **纪律**：本文**不进入实现设计**。产出三件东西，且三者都能解释 D2 全部事实：
  1. Lifecycle Domain Model
  2. State Semantics
  3. Public Knowledge Set 边界

---

## 零、本轮最重要的发现（改变了对问题的理解）

测绘 D2 时以为「Scheduled Publish 完全没有实现」。**事实相反**：

```text
Content::published()（Content.php:136-142）
    → where('status', 'published')
      AND where(published_at IS NULL OR published_at <= now())     ← 时间门禁已存在

后台（ContentController.php:190-193）
    「完整定时发布为 v1.1 能力。v1.0 若 published_at 为未来时间，拒绝并提示」
    → 前端已具备 Scheduled 语义，只是**主动禁止使用**
```

**⇒ Scheduled Publish 不是「从零加字段」，而是「解锁已存在但被闸门拦住的能力」。**

这个区别极重要：

```text
❌ 若按原计划设计 publish_at / unpublish_at 字段
   → 会与已有的 published_at 时间门禁**形成第二套机制**

✅ 正确认知
   → published_at 已是Scheduled 的SoT，只是缺「到点自动翻转 status」与「unpublish_at」
```

同理，**Trash 也已部分存在**：

```text
Content 模型 use SoftDeletes（Content.php:29）→ deleted_at 是 Laravel 软删语义
Entity / Page **未启用** SoftDeletes
```

---

## 一、Lifecycle 的状态主体（问题 1）

### 逐一判断六个对象

| 对象 | 有status？ | 有独立 `published()`？ | 时间门禁 | SoftDeletes | 判定 |
|---|---|---|---|---|---|
| **Content** | ✅ | ✅ `Content.php:136` | ✅ `published_at <= now()` | ✅ | **拥有完整生命周期** |
| **Entity** | ✅ | ✅ `Entity.php:90` | ❌ | ❌ | **拥有生命周期（较弱）** |
| **Page** | ✅ | ✅ `Page.php:84` | ❌ | ❌ | **拥有生命周期（较弱）** |
| **Block** (`page_blocks`) | ❌ | ❌ | ❌ | ❌ | **继承 Page** |
| **Fact** | ❌ | ❌ | ❌ | ❌ | **派生状态**（见下） |
| **Relation** (`entity_relations`) | ❌ | ❌ | ❌ | ❌ | **派生状态**（见下） |

### 三种「published」语义互不相同（核心发现）

```php
// Content（Content.php:136）—— 状态 + 时间
public function scopePublished($query) {
    return $query->where('status', 'published')
        ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
}

// Entity（Entity.php:90）—— 仅状态
public function scopePublished($query) {
    return $query->where('status', self::STATUS_PUBLISHED);
}

// Page（Page.php:84）—— 仅状态
public function scopePublished($query) {
    return $query->where('status', self::STATUS_PUBLISHED);
}
```

**同名方法，三种语义。** 这正是「Publicness 出现多套解释」的微观根源。

**⇒ D1 结论 1：`published()` 这个名字在系统里指三件不同的事。
统一 Publicness 时必须先消除这个同名歧义，而不是简单调用它。**

### 派生状态的两个对象

**Fact**（`app/Services/Geo/FactLabels.php` / `Fact::publicRows()`）：

```text
无 status 字段
但有 locale 门禁（en 事实 23 行 / zh 17 公开 + 6 待补充）
且 D-02 已确立分层 SoT：facts 是 Business Fact 唯一 SoT
```

Fact 的可见性来自**locale 门禁 + 其宿主实体的可见性**，不来自自身状态。

**Relation**：

```text
无 status 字段
可见性完全由两端实体 + 自身 site_id/locale 决定
```

**⇒ 这解释了为什么 G-1/G-2/G-3 会出现**：
Relation 与 Block 没有自己的状态可查，Builder 只能自己去查邻居——
一旦忘记加门禁，就产生「edge public but node private」。

---

## 二、Publication 是状态还是可计算边界（问题 2）

### 采纳「可计算边界」模型

```text
Publication State（各自Domain 的事实）
        ↓
Public Visibility（统一判定· 单一入口）
        ↓
Public Knowledge Set（Site + Locale + Time 唯一确定）
        ↓
Frontend / Sitemap / GEO / LLM / Schema / Search / RSS
```

### 为什么必须是「可计算」而非「状态」

**证据 1：三个模型的 published() 语义已不一致**（见上）。
若Publicness 是状态，就得为三种语义造三个状态字段。

**证据 2：D2 已证明 Collection 层已存在且工作良好**

```text
PublicIndex::contentQuery()  = published + 栏目启用 + 非 noindex + 当前站点
PublicIndex::entityQuery()   = published + 非 noindex + 当前站点
```

它们已经是**可计算的集合定义**，不是状态字段。
设计方向应是**扩大它的覆盖面**，而不是另起一套。

### 目标形态

```php
// 目标（尚不存在，D5 才正式化）
PublicKnowledgeSet::for(siteId, locale, time): Set<ResourceRef>

// 覆盖六类资源
  ContentRef / EntityRef / PageRef / FactRef / RelationRef / BlockRef
```

**关键：它必须能解释 D2 的全部 15 行矩阵**（见第五节验收）。

---

## 三、Relation 属于谁的生命周期（问题 3）

### Relation 没有自己的生命周期 —— 它是**完全派生的**

```text
Entity A ── relation ──> Entity B

PublicRelation(A,R,B) ≡ Public(A) ∧ Public(B) ∧ SameSite ∧ SameLocale ∧ Public(R自身约束)
```

### 六种组合的判定（当前实际行为 vs 应有行为）

| A | B | 当前 geo.json 行为 | 应有行为 | 是否一致 |
|---|---|---|---|---|
| published | published | 输出 | 输出 | ✅ |
| **draft** | published | **输出（G-1/G-2 缺口）** | 不输出 | ❌ |
| published | **draft** | **输出（同上）** | 不输出 | ❌ |
| published | **noindex** | ? 待D5 裁决 | 需明确定义 | ⚠️ |
| published | **不同 locale** | 不输出（GeoGraph 有 `forLocale` 反查） | 不输出 | ✅ |
| published | **不同 site** | Entity↔Entity 段✅ / Schema 产品段❌（G-3） | 不输出 | ❌ |

### 关键发现：**当前行为在 Entity↔Entity 段是正确的**

`GeoGraphBuilder::relations()`（`:195-230`）已正确实现：

```php
$zh = PublicIndex::entityQuery()->forLocale($defaultLocale)->whereIn('id', $entityIds)->get();
// 注释原文：任一端 draft / noindex / 他站则不输出该边
if (! $fromZh || ! $toZh) { return null; }
```

**⇒ 正确的实现已经在代码里。** G-1/G-2/G-3 不是「不知道怎么做」，
而是**同一 Builder 内不同段落各自重写了查询**。

**这是 D1 的重要输入**：统一 Publicness 大概率不需要发明新算法，
而是把已验证正确的算法**提取为唯一入口**。

---

## 四、`contents.deleted_at` 的真实语义（问题 4）

### 完整摸清结果

| 问题 | 答案 | 证据 |
|---|---|---|
| **谁写入？** | **一次性迁移**，不是运行时 | `2026_09_17_000002_add_slot_to_contents.php:49-53` |
| **写入意图？** | 软删「双轨占位文章（可恢复）」 | 同上注释原文 |
| **谁读取？** | 仅 1 处：`NarrativeController.php:88` | `whereNotNull('deleted_at')->forceDelete()` |
| **运行时语义？** | **无**。当前代码不写、不读、不判| 全库仅 2 处引用，无一是可见性判定 |
| **能否恢复？** | 能（迁移的 `down()` 会`deleted_at = null`） | `add_slot_to_contents.php:69-71` |
| **与 status 关系？** | **无直接关系**。迁移只写 `deleted_at`，不动 status | 同上 |
| **对 GEO/Schema/Sitemap 影响？** | **恒为 0**（开发库 `deleted_at` 非空数 = 0） | 实测 |

### 关键区分：`deleted_at` 有两个不同来源

```text
① 迁移遗留：contents 表本就有的列
   → 2026_09_18_000004 的 CREATE TABLE 里有 'deleted_at' => 'DATETIME'
   → 一次性迁移用它软删占位文章

② Laravel SoftDeletes：Content 模型 use SoftDeletes（Content.php:29）
   → Eloquent 会自动把 delete() 变成软删、自动给查询加 deleted_at IS NULL
```

**② 才是真正生效的机制**，且它**已经自动作用于所有 Content 查询**——
包括 `PublicIndex::contentQuery()`。

### 结论

```text
contents.deleted_at
  ├─ 是Content 的**真实软删语义**（经 SoftDeletes trait）
  ├─ 但**仅 Content 有**，Entity / Page / Relation / Block 都没有
  └─ 它是**现状**，不是「历史兼容字段」
```

**⇒ D1 结论 4：`contents.deleted_at` 属于 Lifecycle 模型的组成部分，
但它是**唯一**实现。统一 Lifecycle 时应把 SoftDeletes 语义扩展到其他主体，
而不是新增 `trashed_at` 列。**

> **这直接回答了章程D4 的核心担忧**：
> 「不得在现有 `contents.deleted_at` 之上叠第二套机制」——
> 正确路径是**推广 SoftDeletes，而非加列**。

### 但需诚实标注的疑点

```text
· Entity / Page 的删除当前是什么行为？物理删除？需 D4 确认
· SoftDeletes 的 restore() 是否被任何地方使用？当前未见
· NarrativeController:88 用 forceDelete()，说明存在「彻底清除」路径，
  这与 D4 的 Purge 语义直接相关，需 D4 完整测绘
```

---

## 五、noindex 属于 Publicness 的哪一层（问题 5）

### 必须区分的四个层次

```text
Access        （访问权限）    —— 用户能否打开这个 URL
Publicness（是否公开）    —— 是否属于公开知识集合
Discoverability（可发现性）—— 是否会被 sitemap / llms / 内链推荐
Indexability （可索引性）  —— 是否允许被搜索引擎与 AI 引用
```

### 当前 `noindex` 实际承担了什么

```text
PublicIndex::entityQuery()  → published +非 noindex   （Publicness 层）
PublicIndex::contentQuery() → published + 非 noindex  （Publicness 层）
但契约注释同时声明：
  「noindex 实体的前台详情页仍可直接访问（200）」
  → 说明 noindex 当前被当作 Discoverability 约束，
    但被实现为 Publicness 过滤
```

**⇒ 这就是 G-4 的根源：`noindex` 语义本身是跨层的，
而代码把它当成单层的布尔过滤。**

### 分层归属建议（D5 裁决，本文档只提出问题）

| 状态 | Access | Publicness | Discoverability | Indexability |
|---|---|---|---|---|
| draft | ✅ 详情页可访问 | ❌ | ❌ | ❌ |
| scheduled（未到点） | ? **需裁决** | ❌ | ❌ | ❌ |
| published | ✅ | ✅ | ✅ | ✅ |
| published + noindex | ✅ | **? 争议点** | ❌ | ❌ |
| trash | ? **需裁决** | ❌ | ❌ | ❌ |

**「published + noindex 到底是不是 Public？」是 D1 必须回答的核心问题之一。**

因为它直接决定：

```text
若 noindex 不影响 Publicness
    → Catalog 与 PublicIndex 的分歧（G-4）是**正确的**
    → JSON-LD / 前台列表页出现 noindex 实体是**预期行为**

若 noindex 影响 Publicness
    → G-4 是**缺口**，需统一
```

**⇒ 不能留给实现阶段顺手决定，必须在 D1 明确定义。**

---

## 六、State × Surface 矩阵（实测补验完成，非设计）

**这是 D2 事实的 transpose，不是设计稿。** 每格为**探针实测**行为。

> 补验按 Probe Validation 四步执行，先确认阳性对照：
> `positive control: content=PASS entity=PASS page=PASS`
> —— 三个 published 均正确出现，证明查询路径通，后续「未出现」才可解释。

### Content

| 状态 | Frontend | Sitemap | GEO | LLM | Schema | Search | RSS |
|---|---|---|---|---|---|---|---|
| published（已到点） | 200 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| published + 未来 published_at | **404**（前台不可见） | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| published + noindex | 200 | ❌ | ❌ | ❌ | ✅ JSON-LD | ❌ | ❌ |
| soft-deleted | 404 | ❌ | ❌ | ❌ | ? | ❌ | ❌ |
| draft | ? 待验 | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

> 「published + 未来 published_at → 404」正是 BUG-20B-004要防的状态不一致。
> 门禁已拦住入口，但**若绕过后台直接写库**，该状态依然存在。

### Entity

| 状态 | Frontend | Sitemap | GEO | LLM | Schema | Search |
|---|---|---|---|---|---|---|
| published | 200 | ✅ | ✅ | ✅ | ✅ | ✅ |
| published + noindex | **200** | ❌ | ❌ | ❌ | ⚠️ **仍出现**（G-4） | ❌ |
| draft | ? 待验 | ❌ | ❌ | ❌ | ⚠️ **关系端点仍出现**（G-1/G-2/G-3） | ❌ |
| archived | ? 待验 | ❌ | ❌ | ❌ | ? | ❌ |

### Page

| 状态 | Frontend | Sitemap | GEO | Schema |
|---|---|---|---|---|
| published | 200 | 按 Page 口径 | 按 Page 口径 | ✅ |
| draft | ? 待验 | ❌ | ❌ | ❌ |

### Relation

| 状态组合 | GEO（Entity↔Entity） | GEO（Content↔Entity） | Schema |
|---|---|---|---|
| A pub + B pub | ✅ | ✅ | ✅ |
| **A draft** | ✅ 正确排除 | ❌ **仍输出** | ❌ **仍输出** |
| **B draft** | ✅ 正确排除 | ❌ **仍输出** | ❌ **仍输出**（且无站点隔离） |
| 不同 site | ✅ 正确排除 | ✅ 排除 | ❌ **仍输出**（G-3） |
| 不同 locale | ✅ 正确排除 | ⚠️ 无门禁 | ⚠️ 无门禁 |

### Fact / Block

| 对象 | 可见性来源 | 当前状态 |
|---|---|---|
| Fact | locale 门禁 + 宿主实体 | 无自身 status（设计如此） |
| Block | 继承 Page | 无自身 status |

---

## 六之二、补验结果（填补原「? 待验」格）

**探针输出**（阳性对照已 PASS）：

```text
[positive control] content=PASS entity=PASS page=PASS

[content scheduled 未到点] in PublicIndex  = no   ✅ 确认被排除
[content draft           ] in PublicIndex  = no   ✅ 确认被排除
[content soft-deleted    ] in PublicIndex  = no   ✅ 确认被排除（SoftDeletes 自动生效）
[entity draft            ] in entityQuery   = no   ✅ 确认被排除
[entity archived         ] in entityQuery   = no   ✅ 确认被排除
[page draft              ] in pagePublished = no   ✅ 确认被排除
```

**⇒ 六个待验格全部确认被正确排除。Collection 层（PublicIndex）行为一致且正确。**
这也再次印证 D2 的结论：**缺口只在 Relation 层与 Schema 层，不在 Collection 层。**

### 但补验查出一个新的重要事实：Entity 走**物理删除**

```text
[entity delete] 删后仍存在 = no（物理删除）
```

外键约束实测：

```text
entity_relations.from_entity_id → entities  ON_DELETE=cascade
entity_relations.to_entity_id   → entities  ON_DELETE=cascade
entity_relations.site_id        → sites     ON_DELETE=restrict
```

**两个重要含义**：

```text
✅ 正面：物理删除不留悬空关系（FK cascade 兜住）
         → 这解释了 G-1/G-2/G-3 为何能长期存活：
           删了实体，关系也一起消失，不产生「残留」症状，
           所以没人从数据层发现它，只有 AI 侧读到悬空边才发现

⚠️ 代价：Entity 删除**不可恢复**，且不经过任何 Trash 语义
         → 这正是 D4 要解决的「企业敢不敢删」问题
```

### 三个主体的删除语义完全不同（D1 关键发现）

| 主体 | 删除行为 | 可恢复 | 经 Trash 闭环？ |
|---|---|---|---|
| **Content** | 软删（`SoftDeletes` trait） | ✅ 可 `restore()` | 半 —— 无 UI 入口 |
| **Entity** | **物理删除**（FK cascade） | ❌ **不可恢复** | ❌ 无 |
| **Page** | 待 D4 确认 | — | — |
| Relation | 随端点 cascade | — | 自动 |

**⇒ D1 结论 5：系统当前有三种删除语义，且没有任何一个提供完整的
Trash → Restore 闭环。「统一生命周期」若不同时统一删除语义，就是只统一了一半。**

---

## 七、可提前冻结的原则（采纳你的升级版）

> **任何公开语义关系，其参与节点必须属于同一个 Site + Locale 下的
> Public Knowledge Set；关系自身也必须满足 Publication Contract。**

这条比「edge public → endpoint public」更强，因为它**一次性覆盖六类逃逸**：

```text
✅ 跨站关系      （G-3）
✅ 跨语言关系
✅ draft endpoint（G-1/G-2/G-3）
✅ noindex endpoint
✅ scheduled endpoint（未来 Content）
✅ trash endpoint（未来）
```

**当前违反。** D2 已定位三处（G-1/G-2/G-3），D3/D4 还会新增两类（scheduled/trash）。

### 它将成为 mutation test 的目标

```text
变异：把端点过滤短路
期望：⑥ 类逃逸场景各有一个测试失败
```

---

## 八、D1 结论汇总（三件产物）

### 1. Lifecycle Domain Model

```text
拥有独立生命周期：Content（完整）/ Entity / Page（较弱）
派生状态（无自身状态）：Block（继承 Page）/ Fact（locale + 宿主）/ Relation（完全派生）
```

### 2. State Semantics（本轮最实质的发现）

```text
① 三种 published() 同名不同义 → 必须消除歧义
     Content（状态 + 时间门禁）/ Entity（仅状态）/ Page（仅状态）

② Scheduled 语义已存在于 Content（published_at <= now()），
   只是被后台闸门拦住（BUG-20B-004）→ 解锁而非新建字段

③ Trash 语义已存在于 Content（SoftDeletes trait），是全系统唯一实现
   → 推广而非新增 trashed_at 列

④ 三种删除语义并存（Content 软删 / Entity 物理删 / Page 待确认）
   → 统一生命周期必须同时统一删除语义，否则只统一了一半
```

### 3. Public Knowledge Set 边界

```text
方向：扩大已有的 PublicIndex 覆盖面（它已是可计算集合定义），
     使其成为六类资源的唯一可见性入口。

Collection 层工作良好（4/5 出口合规，D1 补验再确认6 个状态格全部正确排除）；
Problem 在 Relation 层与 Schema 层 —— 因为它们没有自己的状态可查。

重要线索：GeoGraphBuilder::relations() 已实现正确的派生算法。
统一 Publicness 大概率是「提取」而非「发明」。
```

---

## 九、D1 未回答 / 需 D4 D5 承接的问题

| 问题 | 归属 |
|---|---|
| Entity / Page 当前删除是物理还是软删？ | **D4** |
| `SoftDeletes::restore()` 是否可用、有无 UI 入口？ | **D4** |
| `published + noindex` 到底是不是 Public？ | **D5**（决定 G-4 是缺口还是预期） |
| scheduled 未到点时前台应404 还是 200？ | **D5** |
| trash 状态下 Frontend 的 Access 是什么？ | **D5** |
| Content 未来 published_at 绕过后台写库时如何防？ | **D3/D5** |
| `RelationRef` 是否需要独立 noindex？ | D5 裁决 |

---

## 十、本文档不做的事

```text
❌ 不设计实现            —— 只做领域建模
❌ 不提修复方案          —— G-1/G-2/G-3 保持不动
❌ 不改任何代码/ 测试    —— 按章程要求
❌ 不新增字段            —— 特别是**不新增 trashed_at**
❌ 不打 tag
```

**验证方式**：D1 的模型必须能解释第六节矩阵的**每一格**，
包括那些「? 待验」格子——那部分需补验，不是留白。
