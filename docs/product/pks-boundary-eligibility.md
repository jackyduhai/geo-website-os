# PKS Boundary 裁决：Public Knowledge Set Eligibility

- **状态**：**DECIDED**（2026-10-08，三修正后冻结）· 领域边界与契约裁决，非实现设计
- **上游**：D2（PKS 概念缺口）· D1（生命周期域模型）· D5-2 Entity Lifecycle
  · D5-3 Relation Lifecycle · D5-4 Semantic Reference · D5-5 Fact Ownership
- **章程**：`docs/product/stage1-discovery-charter.md`
- **纪律**：只做领域边界与契约裁决；**不改产品代码、不做 Trash UI、
  不修 G-1～G-8、不提前进入 Architecture Decision**。
- **冻结前三处修正**：①真实路由是 surface constraint 而非通用成员谓词；
  ②资格谓词按知识类型实例化（Fact 无 status）；
  ③JSON-LD 是 page-local machine metadata，不是 PKS discovery projection。

---

## 零、取证：PKS 不是一张表，而是现有机制的隐式投影

### 0.1 已存在的三块「机器」

```text
① PublicIndex（app/Support/PublicIndex.php）
   公开可索引资源查询的唯一入口：
     contentQuery()  = published + (无栏目/启用栏目) + 非 noindex + site scope
     entityQuery()   = published + 非 noindex + site scope
     indexableEntitySlugs() = locale 感知的 slug 白名单
   注释明确：noindex 实体的前台详情页仍可直接访问（200），只是不进 feed。

② EntityCapabilityRegistry（config/entities.php）
   类型能力声明（唯一事实源）：public / searchable / geo / sitemap / schema；
   未知类型 → 最弱能力。

③ 各 Builder（派生出口）
   统一消费 ①②，不自行查 published 行。
```

### 0.2 出口的真实筛选口径（探针实测，分两类）

**A. Discovery / retrieval surfaces（PKS 投影）**

| 出口 | 成员筛选口径 | 额外门槛（surface constraint） |
|---|---|---|
| **geo.json · entities** | PublicIndex::entityQuery | `isGeo(type)` 能力；无 URL 语义节点仍入图 |
| **geo.json · relations** | 两端均为公开可索引实体 | 默认语言端 + 当前语言译都存在才出边 |
| **geo.json · contents** | PublicIndex::contentQuery | title/summary 经 SEO Resolution |
| **geo.json · facts** | Fact::publicRows | is_public + locale + site |
| **llms.txt** | PublicIndex + Catalog + Fact::publicRows | 需链接的条目再要求真实 200 页；标签经 FactLabels |
| **sitemap.xml** | PublicIndex + indexableEntitySlugs | 只收录真实 200 路由；不编造 lastmod |
| **feed.xml (RSS)** | PublicIndex::contentQuery | 仅 knowledge/news；limit 30 |
| **站内搜索** | PublicIndex + capability | `isSearchable` + 有前台落地页 |

**B. Page-local machine metadata（非 PKS 投影）**

| 出口 | 性质 | 约束 |
|---|---|---|
| **JSON-LD** | 嵌入人类页面的结构化描述 | schema/page-visible 契约；不直读 facts；是否受 noindex/PKS 约束由独立 Surface Policy 定义 |

### 0.3 生命周期状态的真实表示

```text
Content：status draft|published|archived（varchar）+ SoftDeletes（deleted_at）
         published() = status=published AND (published_at IS NULL OR <= now)
Entity ：status draft|published|archived（varchar），无 SoftDeletes，物理删除
         published() = status=published
Fact   ：无 status，公开与否由 is_public；复核由 reviewed_at/review_due
```

### 0.4 D2 早已识别的概念缺口

```text
D2 第 261 行：「系统缺一个概念：Public Knowledge Set（公开知识集合）」
D2 第 270 行：「PublicIndex 只解决了实体和内容的可见性，
              没有解决关系与语义节点。」
本阶段正式把该概念补齐为领域边界。
```

---

## 一、PKS 的定义

```text
Public Knowledge Set（PKS）是一个「派生的知识资格投影」，不是存储集合：
  它是「在当前站点、当前语言下，有资格被机器知识发现面
  （geo / llms / sitemap / RSS / search）收录的知识陈述」
  在输出时刻由「按知识类型适用的谓词」判定得到。

PKS 成员不是「数据库行」，而是「知识陈述」：
  Entity 节点 · Content 节点 · Fact 陈述 · 以及连接它们的结构边。
Relation 与 Semantic Reference 本身不是独立成员，
  它们随端点 / 宿主的资格而成立或被评价。
```

---

## 二、三层模型（冻结结构）

```text
                Domain Knowledge
        （Entity / Content / Fact / Relation）
                       │
                       ▼
          ┌─────────────────────────┐
          │   PKS Eligibility        │
          │   class-specific rules   │
          │  PKS(Entity) PKS(Content)│
          │  PKS(Fact)   PKS(Edge)   │
          └─────────────────────────┘
                       │
                       ▼
                  PKS Projection
                       │
          ┌────────────┼────────────┐
          ▼            ▼            ▼
      GEO / LLMS   Sitemap / RSS   Search
          └────────────┼────────────┘
                       ▼
          surface-specific constraints
          （真实 200 路由 / 能力类型 / 输出格式）
          —— 只能收窄，不得扩大 PKS

JSON-LD 走独立轨道：
  Human Page
     ▼
  Page-local machine metadata
     ▼
  Schema / page-visible / 独立 Surface Policy
```

---

## 三、按知识类型的成员资格（class-specific predicates）

### PKS(Entity)

```text
∧ domain-registered      注册于 EntityCapabilityRegistry
∧ status = published
∧ not-noindex            SeoMeta 未标记
∧ site-matches
∧ locale-available       该语言有对应行
（不要求真实路由：person/location/topic 等语义节点无独立落地页，
  只要满足 isGeo 仍进入 GEO 图谱）
```

### PKS(Content)

```text
∧ domain-registered      归属启用栏目，或无栏目独立单页
∧ status = published AND published_at 已到
∧ not-noindex
∧ site-matches
∧ locale-available
∧ reference-integrity    无 UNKNOWN-ID 语义引用（D5-4 G-7）
```

### PKS(Fact)

```text
∧ is_public
∧ site-matches
∧ locale-available
（无 status、不适用 lifecycle-published；
  知识有效性由自身 temporality + review lifecycle 决定，D5-5）
```

### PKS(Edge) —— 派生，不独立入集

```text
edge(A, R, B) ∈ PKS  ⇔  A ∈ PKS(Entity) 且 B ∈ PKS(Entity)
且两端在当前语言都有可用译文。
边没有独立的成员资格，不单独登记。
```

### Semantic Reference —— 派生，不独立入集

```text
entity_refs 随宿主 Content 被评价（D5-4 G-7），自身不获得成员资格。
```

---

## 四、领域边界

### ① Domain eligibility（领域资格）

```text
只有被领域正式登记的对象才能成为 PKS 成员：
  Entity 类型 → 注册于 EntityCapabilityRegistry
  Content     → 归属启用栏目（或无栏目独立单页）
  Fact        → 站点级 canonical fact（D5-5）
未登记类型 / 自由文本中的提及 → 不构成 PKS 成员。
```

### ② Lifecycle / visibility（按知识类型适用，不统一）

```text
Entity / Content → publication lifecycle（status=published）
Fact             → publication visibility（is_public）+ fact lifecycle
draft / archived / soft-deleted / purged → 不入 PKS（对 Entity/Content 而言）
```

### ③ Relationship semantics（结构边）

```text
edge(A, R, B) ∈ PKS  ⇔  A ∈ PKS 且 B ∈ PKS（D2 公开性不变量）
且两端当前语言有可用译文。
任一端 draft / archived / noindex / 他站 / 缺译 → 该边不成立。
```

### ④ Semantic references（语义引用）

```text
Content 携带 entity_refs 时，PKS 对 Content 的评价（沿用 D5-4 G-7）：
  目标 valid                         → Content 入 PKS
  目标 draft / soft-deleted / known-purged / noindex
                                     → Content 入 PKS + WARNING
  目标 UNKNOWN-ID                    → Content 不入 PKS（BLOCK）
语义引用健康度不改变引用目标的资格，也不（除 UNKNOWN-ID 外）改变宿主资格。
```

### ⑤ Fact state（事实状态）

```text
Fact ∈ PKS  ⇔  is_public + 当前语言有译文 + site 匹配
  Historical（纪元事实）   → 入 PKS，真值不随现状改变
  Current-state verified   → 入 PKS
  Current-state review-due → 仍入 PKS，但复核状态须机器可判定
  Gap（is_public=false）   → 不入 PKS
temporality 分类来自稳定 key / fact contract，禁止运行时启发式（D5-5）。
```

### ⑥ Publication policy（可发布 vs 可入 PKS）

```text
「可发布（published）」是业务工作流状态；
「可入 PKS」是机器知识投影条件。二者不等价：
  published 是 Entity/Content 入 PKS 的必要条件，但不是充分条件。
  反例：published + noindex / 停用栏目 / UNKNOWN-ID refs / 缺译文
       → 业务上已发布，不入（或该语言不入）PKS。
对 Fact 而言根本不存在 published 谓词，由 is_public 决定。
```

### ⑦ Derived-output validity & surface constraints

```text
各 discovery 出口都是 PKS 的投影：
  只能在 PKS 之上用 surface-specific constraints 取子集，
  不得发明新知识、不得绕过 PKS 语义、不得扩大 PKS。
  真实 200 路由：sitemap 与 llms 需链接条目才要求；
                GEO 图谱允许无 URL 语义节点。
  能力类型：geo / searchable / sitemap 分别收窄。
HTML 页缓存是派生副本：PKS 变化时必须失效（现有全局 flush 安全但粗，
  精细化属 Implementation，本裁决不预设）。
```

### ⑧ Human vs machine surface（人类面 vs 机器面）

```text
人类页面可见（HTTP 200） ≠ 机器知识 feed 可见。
  noindex 是「机器面」信号：人类直访 200，机器发现 feed 排除。
  直访 URL / 草稿预览 / 后台页面 = 人类面，不构成 PKS 成员。
两个面分别表达、分别断言，不互相推导。
```

### ⑨ JSON-LD：page-local machine metadata（显式裁决）

```text
JSON-LD 是嵌入人类页面的结构化描述，不是 PKS discovery projection。
  PKS discovery 面 = geo / llms / sitemap / RSS / search
  JSON-LD          = 页面自身的结构化描述，
                     必须遵守 schema / page-visible 契约
                     （每个字段页面可见；不直读 facts）
  一个 noindex 页面仍可携带自己的 JSON-LD。
  JSON-LD 是否受 noindex / PKS 限制，由独立 Surface Policy 定义，
  不由实现阶段自行推断。
若未来产品战略明确决定「JSON-LD 也完全服从 PKS」，
  必须作为显式产品规则记录，而不是从现有 PublicIndex 自动推导。
```

---

## 五、PKS 成员资格的不传递性

```text
PKS membership is NOT transitive：

  Content ∈ PKS   ≠  referenced Entity ∈ PKS
  Entity A ∈ PKS  ≠  Relation(A,B) ∈ PKS  ≠  Entity B ∈ PKS

Relation(A,B) 只有在自身通过 endpoint eligibility
（A、B 均 ∈ PKS）时才成立；
一个对象入集不自动使其引用目标、其关系端点入集。
```

---

## 六、两条隐含等式正式破除

```text
等式一：DB exists → therefore public          ❌ 不成立
  行存在（draft / noindex / archived / soft-deleted / 他站）不赋予 PKS 资格；
  成员资格是「正向断言」（状态 + 策略 + 门禁），不是「存在即公开」。

等式二：Content published → every referenced knowledge is public   ❌ 不成立
  Content 的 PKS 资格不传递给其引用目标（见第五节不传递性）；
  引用目标被独立评价：Content 可带 WARNING 入集，而目标为 draft/noindex。
```

---

## 七、冻结结论（P15 / G-9 最终版）

### P15

```text
P15  Public Knowledge Set 是派生的知识资格投影，
     而不是物化存储集合。
     PKS 成员资格由"知识类型适用的领域资格、
     生命周期 / 发布状态、站点与语言、可索引性、
     引用完整性等适用谓词"在输出时刻共同决定。
     Published 仅适用于具有发布生命周期的 Entity / Content，
     并非所有知识类型的统一谓词。
     Relation 与 Semantic Reference 不因宿主 / 端点而获得
     独立的 PKS 成员资格。
     一个对象进入 PKS 不会使其引用目标自动进入 PKS。
     真实路由、具体能力、输出格式等属于
     surface-specific constraints，而不是 PKS 的通用成员资格。
```

### G-9（Implementation Gate）：PKS Projection Integrity

```text
① Discovery-oriented machine surfaces
   （geo / llms / sitemap / RSS / search）
   MUST derive their members from PKS eligibility semantics
   and capability policy.

② No outlet may independently reimplement or weaken
   domain / lifecycle / visibility / locale eligibility.

③ Surface-specific constraints
   （例如真实 200 route、能力类型、输出格式）
   MAY narrow the PKS projection，但不得扩大 PKS。

④ 必须进行 mutation testing：
   任一核心 eligibility predicate 被短路时，
   对应出口测试 MUST FAIL。

⑤ Human-page HTTP 200 与 machine-surface membership
   必须分别断言。

⑥ Page-local JSON-LD 是否属于 PKS projection
   必须由独立 Surface Policy 明确，
   不得由实现阶段自行推断。
```

---

## 八、开放边界（显式记录，不静默解决）

```text
① retired（退役）状态：
   当前模型无独立 retired，语义上映射到 archived（不入 PKS）。
   若业务需要「退役但作为历史知识保留在图谱」，属 Architecture/Implementation 新增。
② review_due 到期出口行为（WARNING / 降级 / 保持公开）
   按 D5-5 G-8 留待 Implementation Contract。
③ 缓存失效精细化、JSON-LD 的独立 Surface Policy 细则，留待后续。
```

---

## 九、本裁决不做的事

```text
❌ 不改产品代码、不加字段、不做 Trash UI
❌ 不修 G-1～G-8（既有裁决保持原样）
❌ 不新增 retired 状态、不做 fact_entity_refs、不做 Reference Framework
❌ 不把 JSON-LD 当作 PKS discovery projection
❌ 不把真实路由 / 能力当作通用 PKS 成员谓词
❌ 不提前进入 Architecture Decision
❌ 不把 PKS 落库为一张物化集合（它是投影）
```

**下一步**：PKS Boundary 已冻结 → 回到 D5-1 / D5-6 / D5-7
→ Architecture Decision → Implementation。
