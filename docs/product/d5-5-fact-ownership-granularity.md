# D5-5 裁决：Fact Ownership / Granularity

- **状态**：D5-5 **DECIDED**（2026-10-08 裁定，精确修订版冻结）· 领域裁决，非实现设计
- **上游**：`d4-deletion-recovery-semantics.md`（P6）· `d1-lifecycle-domain-model.md`
  · `d5-2-entity-lifecycle-class-matrix.md` · `d5-4-semantic-reference-degradation.md`
- **章程**：`docs/product/stage1-discovery-charter.md` D5
- **D5-5 已不是最初的问题**：CASCADE 已被 P6 禁止，且取证确认
  `facts` 表无 entity FK，Entity purge 事实上不可能删事实。
  **真正裁决的是：Fact 在什么粒度上拥有公共知识权威性。**

---

## 零、取证：facts 的真实结构与用法

### 0.1 表结构（综合建表与后续迁移）

```text
facts
  id            PK
  key           语义身份（如 FACT-COMPANY-003），UNIQUE(site_id, key, locale)
  label         逐语言标签
  value         逐语言值（TEXT）
  unit          单位（可空，2026-09-21 补列）
  group         company | product | business | trust | service（可空）
  source        依据（可空）
  source_url     来源链接（可空，2026-09-21 补列）
  owner         责任人（可空）
  reviewed_at   核定日期（可空）
  review_due    下次复核日期（可空）
  is_public     公开布尔（公开出口的唯一自身过滤）
  sort          排序
  site_id       FK → sites(id) ON DELETE RESTRICT
  locale        zh-CN | en（2026-10-03 纳入行级翻译）
  translation_group  TG-FACT-{key}（系统按 key 派生）
  created_at / updated_at

★ 无 entity_id · 无 status · 无 deleted_at
```

### 0.2 系统中存在两层「fact」源（必须分清）

```text
① app/Support/Facts.php     —— legacy 文件型事实源
   读 config('facts')（由 scripts/compile_facts.php 从 facts.yaml 编译），
   类注释已自我标注「过渡层，后续退场」，新代码不应依赖。
② app/Models/Fact.php       —— DB 事实模型，Business Fact 的唯一 Canonical SoT
   （RC-9 D-02 架构决策锁定）。
```

### 0.3 事实的消费点（全部走同一出口）

```text
geo.json facts 段    GeoGraphBuilder::facts() → Fact::publicRows($locale)
                     输出 key/label/value/group/source/reviewed_at/review_due
llms.txt Core Facts  LlmsBuilder::businessFacts() → Fact::publicRows()
                     标签经 FactLabels 与 geo.json 同源
前台模板             AppServiceProvider:162 全站注入 publicFacts = Fact::publicMap()
ContentGate 规则 6   fact_refs 悬空（引用了不存在的 fact key）→ ERROR，阻断发布
Content 页「关键事实」 取 Content 自身 JSON keyFactList，不直读 facts 表
```

### 0.4 事实清单（按 FactSeeder 实数）

```text
每语言公开 17 条 + 待补充 6 条（zh 23 行；en 17 行，不补 gap）：
  company   公开 11（001/002/003/004/006/007/008/009/010/011/012）
            gap  2（005 地址 · 013 官方电话）
  product   公开  2（001 产品体系 · 002 质量体系）
  business  公开  3（001 业务模式 · 002 核心客户群 · 003 服务区域）
            gap  2（004 MOQ · 005 交付周期）
  trust     gap  2（001 资质编号 · 002 执行标准号）
  service   公开  1（001 服务口径）
```

### 0.5 与 Entity 的真实关系：**无结构引用，只有自由文本对应**

```text
① 无 entity_id 列、无 fact_entity 关联表；实体生命周期不触及事实行。
② 少数事实的 value 是自由文本清单，语义上「对应」目录结构：
     FACT-COMPANY-011 核心产品线 = 工业涂料 / 结构胶粘剂 / 功能性助剂
     FACT-PRODUCT-001 产品体系   = 同上（三大产品线）
     FACT-BIZ-002 核心客户群     = 工业制造 / 建筑工程 / 汽车零部件 / …
   这些字符串与实体/分类之间没有任何结构化链接。
③ D1 文档第 96 行写过「Fact 的可见性来自其宿主实体的可见性」
   —— 事实没有「宿主实体」。该措辞在 D5-5 正式修正（见裁决一）。
```

### 0.6 事实的删除语义

```text
FactController::destroy() → 物理删除（无 SoftDeletes），
Translatable 的 deleting 钩子级联删除同 translation_group 的各语言行。
无引用保护（对比 Media 有 MediaReferenceScanner）。
唯一既有保护：Content.fact_refs 引用了已删 key → ContentGate 规则 6 报 ERROR。
```

---

## 一、问题分解：四个必须回答的粒度问题

```text
① 归属粒度（ownership）   企业级事实 vs 实体级事实？
② 权威性（authority）     canonical fact vs derived fact？
③ 时效性（temporality）   verified fact vs historical fact？
④ 失效语义（degradation） Entity 消失后，哪些 Fact 仍是站点事实，
                          哪些失去知识有效性？
```

---

## 二、裁决一：归属粒度 = 站点（企业主体）级，事实表不承载实体级事实

```text
结构证据：facts 只有 site_id（FK → sites RESTRICT），没有 entity_id。
事实的知识主体 = 该 site 的 business subject（D5-2 裁决的 organization
                 Site Knowledge Aggregate Root），不是任一具体实体。
owner 字段 = 责任人（顾问 / 企业 / Consultant），是「人」不是知识主体。
```

**正式修正 D1 第 96 行：**

```text
原措辞：Fact 的可见性来自「locale 门禁 + 其宿主实体的可见性」
修正为：Fact 的可见性来自「site 归属 + locale 门禁 + is_public」；
       其知识宿主是 site / business subject，不是某个具体实体。
```

**边界规则：**

```text
若一条陈述真的是关于单个实体的属性（某产品规格、某服务参数），
它属于实体（metadata / description / 关联 Content），不进 facts。

反例风险：把「产品 X 耐温 300℃」写进 facts
  → 实体 X 改名/删除后，事实失去归属且无法被解析；
  → 这正是把实体级知识错误提升为站点事实。
```

---

## 三、裁决二：Canonical vs Derived

```text
Canonical fact = 独立来源、在 facts 表登记、
                 带 source / owner / reviewed_at 的断言。
Derived fact   = 可由 entity graph（实体 / 分类 / 关系）计算的值
                 （计数、列表、聚合）→ 渲染时计算，不存为事实。

判据：「这个值能否从实体图算出来？」
  能 → 不存为事实（否则实体变化后必然漂移，如把「共 3 大产品线」
        存成值，新增产品线时即过期）。
  不能（须独立来源核定）→ 才是 canonical fact。
```

**与 RC-9 D-02 分层 SoT 一致**：facts 是 Business Fact 唯一 SoT，
派生物不允许新建第三套数据源。

```text
注意：FACT-COMPANY-011 / FACT-PRODUCT-001 是「描述性断言」（列举线名），
不是可计算计数；但它们是全库最易随目录变化漂移的事实（见裁决四）。
```

---

## 四、裁决三：Verified vs Historical

```text
HISTORICAL（纪元事实）—— 描述已发生事件，真值不随现状改变：
  成立时间（003）· 正式投产（004）· 总投资（008）· 建厂时厂区面积（006）
  → Entity 生命周期变化不使其失效；它们是企业的「记忆」。

CURRENT-STATE（现状事实）—— 描述当前经营，须按 review_due 复核：
  年产能（007）· 核心产品线（011）· 销售大区（010）· 核心客户群（BIZ-002）
  · 服务区域（BIZ-003）· 业务模式（BIZ-001）· 服务口径（SVC-001）
  · MOQ / 交付周期（gap）
```

**事实的认识状态（从现有字段派生，不要求新增列）：**

```text
VERIFIED     reviewed_at 非空 且 review_due 未过
REVIEW-DUE   review_due 已过（或现状事实从未核定）
             → 不是「假」，而是「当前未核定」
HISTORICAL   纪元事实（真值不随复核改变）
GAP          is_public = 0（待补充，不进任何公开出口）
```

**下游契约：temporality 分类必须来自稳定的 Fact 语义契约，禁止运行时启发式。**

```text
Historical / Current-State 的分类
  → 由稳定的 key / fact contract / canonical definition 决定
  （不新增数据库字段也可以做到）
  → 不得临时根据「有没有 review_due」猜：
     review_due = NULL 不能单独证明它是 Historical
     （现状事实也可能从未安排复核；gap 也可能 review_due 为 NULL）

⇒ 禁止的实现：review_due == null → HISTORICAL
```

---

## 五、裁决四：Entity 消失后事实的有效性（核心）

四类事实在 Entity purge 后的命运：

| 事实类型 | 例 | purge 后 |
|---|---|---|
| ① 纪元事实 | 成立时间 / 正式投产 / 总投资 | ✅ 仍是站点事实，永久保留 |
| ② 不依赖该实体的现状事实 | 业务模式 / 服务区域 | ✅ 仍是站点事实 |
| ③ 关于该实体的实体级事实 | （当前模型不存在，裁决一） | 实体属性随实体消亡，本就不在 facts |
| ④ 值中列举/包含该实体的站点事实 | 核心产品线 / 产品体系 / 核心客户群 | ⚠️ 事实保留，变为「待复核」候选 |

**第④类的关键语义：**

```text
事实失去的是「verified 认识状态」，不是「存在」。
  Entity purge 不删除、不改写、不自动抑制事实；
  受影响的现状事实从 VERIFIED 转为待复核（re-review candidate），
  直至下一次核定。
```

**机制选择（沿用 D5-4 的论证）：**

```text
A 文本扫描事实值匹配实体名
  → 拒绝：中文语境误判、改名后漂移、「提及 ≠ 引用」（同 D5-4）
B 新增 fact_entity_refs 结构化关联
  → 可能是唯一能自动检测漂移的机制，但属新机制；
    本轮不立项，未来须独立 Discovery 授权（不提前泛化）
C Entity purge 触发 fact re-review（审计/任务信号），
  事实发布状态由自身复核状态决定，不被实体生命周期改写
  → ✅ 采用
D 什么都不做
  → 拒绝：事实静默漂移，AI 持续输出过期断言
```

**诚实边界（必须写进契约）：**

```text
在没有结构化 fact-entity 链接的前提下，
系统无法可靠识别「哪些事实受某次 purge 影响」，
自动 fact-entity drift detection = 不可能。
本裁决依赖的是「purge 触发复核 + 事实自身 review 周期」的治理过程，
不是自动检测。若组织要求自动检测，须另行授权 fact_entity_refs。
```

**⇒ 核心原则：Entity 生命周期事件不 mutate 事实，只触发事实复核；
事实的知识有效性由其自身 review 生命周期决定，不由实体存在性决定。**

---

## 六、冻结结论（P14 / G-8 最终版）

### P14（最终冻结版）

```text
P14  Business Facts 是站点 / business subject 级的
     canonical knowledge。
     Fact 的知识有效性由其自身的 temporality 与 review lifecycle
     决定，而不是由任一 Entity 的存在性直接决定。
     Entity lifecycle event 不得增删改 Fact；
     只能触发对相关业务主体的事实复核信号。
     实体级属性不得建模为站点 Fact；
     可由 Entity Graph 计算的值不得持久化为 Canonical Fact。
```

P14 把四件事彻底分开：**ownership · temporality · review lifecycle · entity lifecycle**。

### G-8（最终冻结版 · Implementation Gate）：Fact Review-State Integrity

```text
① Public Fact representations
   MUST expose or make machine-determinable
   the applicable review state（如 HISTORICAL / VERIFIED / REVIEW-DUE）。
   状态可由既有 canonical fields + stable fact semantics 派生，
   不要求新增 status 列。

② Entity purge
   MUST emit a fact re-review signal
   at the owning site / business-subject scope。
   本 Gate 不要求、也不得假设系统能够在没有
   fact-entity structured linkage 的情况下自动定位
   哪些具体 Fact 受该 purge 影响。

③ Entity lifecycle event
   MUST NOT mutate Fact rows：
     - row 不删除
     - value 不自动改写
     - locale rows 不删除
     - is_public 不自动改变
   允许产生的副作用仅限：
     review task / audit signal / re-review marker。

④ review_due 到期后的 Public output 行为
   （WARNING / 降级 / 保持公开）
   属于 Implementation Contract，不由本领域裁决预设。

⑤ 自动 fact-entity drift detection 不属于本 Gate。
   没有结构化 fact → Entity linkage 时，
   不得声称具备自动漂移检测能力。
```

### 知识治理原则（D5-5 核心推论）

```text
Entity lifecycle ≠ Fact lifecycle

Entity lifecycle   → may trigger → Fact review lifecycle
                   but never directly mutates → Fact canonical state

Entity 是事件来源，Fact 才是事实状态的主人。
（比「删除 Entity 时不要删 Fact」更强：它规定了职责方向）
```

### 永久能力边界（非缺陷，禁止在实现阶段脑补）

```text
没有 fact_entity_refs 等结构化 fact → Entity 链接
  → 自动 drift detection = 不可能
  → 模型必须写成：
      purge → site/business-subject scope review signal
            → review governance → 人工重新核定
  → 不得写成：
      purge → 自动找到受影响 Fact → 自动标记

与 D5-4 的 KNOWN-DELETED / UNKNOWN-ID 同性质：
  缺少结构化关系时，不假装系统拥有不存在的语义能力。
```

### 既有保护不重复立项

```text
Content 引用事实 → ContentGate 规则 6（悬空 fact_refs = ERROR）已存在。
```

---

## 七、本裁决不做的事

```text
❌ 不加 fact_entity_refs、不加字段、不做事实版本化 / 历史快照 UI
❌ 不改 Fact::publicRows / publicMap
❌ 不改 ContentGate、不改任何删除路径
❌ 不把实体级属性搬进 facts（方向相反）
❌ 不提前泛化 Reference Framework（沿用 D5-4 范围约束）
❌ 不把 legacy Support\Facts 的退场纳入本轮（独立清理项）
❌ 不自动定位受 purge 影响的具体 Fact（无结构化链接，能力边界）
```

**下一步**：D5-5 已冻结 → **PKS Boundary**（下一步，等授权）
→ 之后 D5-1 / D5-6 / D5-7 → Architecture Decision → Implementation。
