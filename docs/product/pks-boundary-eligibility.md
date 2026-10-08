# PKS Boundary 裁决建议：Public Knowledge Set Eligibility

- **状态**：**PROPOSED（待裁定）**（2026-10-08）· 领域边界与契约裁决，非实现设计
- **上游**：D2（PKS 概念缺口）· D1（生命周期域模型）· D5-2 Entity Lifecycle
  · D5-3 Relation Lifecycle · D5-4 Semantic Reference · D5-5 Fact Ownership
- **章程**：`docs/product/stage1-discovery-charter.md`
- **纪律**：只做领域边界与契约裁决；**不改产品代码、不做 Trash UI、
  不修 G-1～G-8、不提前进入 Architecture Decision**。

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

### 0.2 八个出口的真实筛选口径（探针实测）

| 出口 | 成员筛选口径 | 额外门槛 |
|---|---|---|
| **sitemap.xml** | PublicIndex + indexableEntitySlugs | 只收录真实 200 路由；不编造 lastmod |
| **geo.json · entities** | PublicIndex::entityQuery | `isGeo(type)` 能力过滤 |
| **geo.json · relations** | 两端均为公开可索引实体 | 默认语言端 + 当前语言译都存在才出边 |
| **geo.json · contents** | PublicIndex::contentQuery | title/summary 经 SEO Resolution |
| **geo.json · facts** | Fact::publicRows | is_public + locale + site |
| **llms.txt** | PublicIndex + Catalog + Fact::publicRows | 只链 200 页；标签经 FactLabels；LlmsSanitizer 终洗 |
| **feed.xml (RSS)** | PublicIndex::contentQuery | 仅 knowledge/news；limit 30 |
| **站内搜索** | PublicIndex + capability | `isSearchable` + 有前台落地页 |
| **JSON-LD** | Entity/Content/Category/Site | 不读 facts；schema 字段须页面可见 |
| **HTML 页缓存** | 匿名 GET 200 | 任一模型 save/delete → 全局 flush |

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
Public Knowledge Set（PKS）是一个「派生投影」，不是存储集合：
  它是「在当前站点、当前语言下，有资格被机器可读出口
  （geo / llms / sitemap / RSS / search / JSON-LD）收录的知识陈述」
  在输出时刻由一组谓词合取判定得到。

PKS 成员不是「数据库行」，而是「知识陈述」：
  Entity 节点 · Content 节点 · Fact 陈述 · 以及连接它们的结构边。
Relation 与 Semantic Reference 本身不是独立成员，
  它们随端点 / 宿主的资格而成立或被评价。
```

---

## 二、八条领域边界

### ① Domain eligibility（领域资格）

```text
只有被领域正式登记的对象才能成为 PKS 成员：
  Entity 类型 → 注册于 EntityCapabilityRegistry
  Content     → 归属启用栏目（或无栏目独立单页）
  Fact        → 站点级 canonical fact（D5-5）
  Relation    → 派生，不独立入集，随端点
  Semantic Ref→ 派生，不独立入集，随宿主并被评价
未登记类型 / 自由文本中的提及 → 不构成 PKS 成员。
```

### ② Lifecycle state（生命周期状态）

```text
draft        → 不入 PKS
published    → 候选（满足其余谓词才入集）
archived     → 不入 PKS（实体的「软删除/退役」表示）
soft-deleted → 不入 PKS（Content 的 deleted_at）
purged       → 物理不存在，非 PKS
```

### ③ Relationship semantics（结构边）

```text
edge(A, R, B) ∈ PKS  ⇔  A ∈ PKS 且 B ∈ PKS
  （D2 公开性不变量）
且两端在当前语言都有可用译文（缺译则不输出该边）。
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

### ⑥ Publication policy（可发布 vs 可入 PKS）—— 关键边界

```text
「可发布（published）」是业务工作流状态；
「可入 PKS」是机器知识投影条件。二者不等价：

  published 是 PKS 的必要条件，但不是充分条件。

  反例：
   published + noindex            → 业务上已发布（人类页 200），不入机器 feed
   published + 停用栏目            → 已发布，不入 PKS
   published + UNKNOWN-ID refs    → 工作流已发布，PKS 阻断
   published + 缺当前语言译文      → 已发布，不入该语言 PKS

PKS = published ∧ 领域合格 ∧ 可索引 ∧ 引用完整 ∧ 语言可用 ∧ 路由真实
```

### ⑦ Derived-output validity（派生出口有效性）

```text
各出口都是 PKS 的投影，只能在 PKS 之上用能力声明取子集，
  不得发明新知识、不得绕过 PKS 谓词自行查 published 行。
  sitemap 取「有真实 200 路由」子集；geo 取 isGeo 子集；
  search 取 isSearchable + 有落地页子集；llms 取 FactLabels 可对齐子集。
HTML 页缓存是派生副本：PKS 变化时必须失效（现有全局 flush 安全但粗，
  精细化属 Implementation，本裁决不预设）。
```

### ⑧ Human vs machine surface（人类面 vs 机器面）

```text
人类页面可见（HTTP 200） ≠ 机器知识 feed 可见。
  noindex 是「机器面」信号：人类直访 200，机器发现 feed 排除。
  直访 URL / 草稿预览 / 后台页面 = 人类面，不构成 PKS 成员。
规则必须分别表达两个面：
  PKS 成员资格描述机器面；HTTP 可达性描述人类面，二者不互相推导。
```

---

## 三、两条隐含等式正式破除

```text
等式一：DB exists → therefore public          ❌ 不成立
  行存在（draft / noindex / archived / soft-deleted / 他站）不赋予 PKS 资格；
  成员资格是「正向断言」（状态 + 策略 + 门禁），不是「存在即公开」。

等式二：Content published → every referenced knowledge is public   ❌ 不成立
  Content 的 PKS 资格不传递给其引用目标；
  引用目标被独立评价：Content 可带 WARNING 入集，而目标为 draft/noindex。
```

---

## 四、PKS 成员资格谓词（合取，输出时刻判定）

```text
PKS-eligible(x) ⇔
     domain-registered(x)        // 领域登记（Registry / 栏目 / canonical fact）
  ∧  lifecycle-published(x)      // status=published 且到发布时间
  ∧  not-noindex(x)              // SeoMeta 未标记 noindex
  ∧  site-matches(x)             // 当前站点
  ∧  locale-available(x)         // 当前语言有译文
  ∧  reference-integrity(x)      // 内容：无 UNKNOWN-ID 引用
  ∧  real-route-exists(x)        // 带 URL 的出口：路由真实 200

edge(A,R,B) 额外要求 A、B 均满足上式。
不同出口再按能力声明取子集。
```

---

## 五、建议冻结结论

```text
P15（建议编号）
  Public Knowledge Set 是派生投影而非存储集合。
  成员资格由领域登记、发布状态、可索引性、站点/语言匹配、
  引用完整性与真实路由的合取谓词在输出时刻判定；
  「数据库存在」不蕴含成员资格，一个对象的成员资格
  也不传递给其引用目标。Published 是必要而非充分条件。

G-9（建议编号 · Implementation Gate）PKS Projection Integrity
  ① 每个机器出口（sitemap / geo / llms / RSS / search / JSON-LD）
    必须经 PKS 谓词（PublicIndex + capability registry）选取成员，
    不得绕过谓词自行查 published 行。
  ② 该投影必须变异验证：短路任一谓词（noindex / 栏目 / 端点 / 站点）
    必须有测试失败。
  ③ 人类面 200 可达不得被当作 PKS 成员资格；两个面分别断言。
  ④ 缓存等派生副本在 PKS 变化时必须失效。
```

---

## 六、开放边界（显式记录，不静默解决）

```text
① retired（退役）状态：
   当前模型无独立 retired，语义上映射到 archived（不入 PKS）。
   若业务需要「退役但作为历史知识保留在图谱」的表达，
   属 Architecture/Implementation 新增，本阶段不预设。
② review_due 到期出口行为（WARNING / 降级 / 保持公开）
   按 D5-5 G-8 留待 Implementation Contract。
③ 缓存失效的精细化（逐页 vs 全局）留待 Implementation。
```

---

## 七、本裁决不做的事

```text
❌ 不改产品代码、不加字段、不做 Trash UI
❌ 不修 G-1～G-8（既有裁决保持原样）
❌ 不新增 retired 状态、不做 fact_entity_refs、不做 Reference Framework
❌ 不提前进入 Architecture Decision
❌ 不把 PKS 落库为一张物化集合（它是投影）
❌ 本文件在裁定通过前不加入 check-discovery-docs.sh
```

**下一步**：PKS Boundary 经用户裁定后 → 回到 D5-1 / D5-6 / D5-7
→ Architecture Decision → Implementation。
