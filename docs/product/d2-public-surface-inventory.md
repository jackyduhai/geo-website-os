# D2 交付物 1：Public Surface Inventory（现状测绘）

- **状态**：SURVEY COMPLETE（2026-10-08）
- **章程**：`docs/product/stage1-discovery-charter.md` D2
- **性质**：**现状事实记录**，不是缺陷修复，不是实现授权
- **纪律**：本文件只回答「现在哪里在判公开」，**不回答「应该怎么判」**（那是 D1/D5）

---

## 零、测绘前的关键区分（避免把合理差异当缺口）

`PublicIndex` 的契约**明确限定了适用范围**（`PublicIndex.php:14-16`）：

> 任何会「引导爬虫 / AI / 用户发现一个 URL」的公开产出
> ——sitemap.xml、llms.txt、/geo.json、RSS、站内搜索

因此必须区分两类：

```text
【发现型出口】=会产出「资源清单」，把 URL 推给爬虫/AI/用户
    → 必须走 PublicIndex

【访问型出口】= 用户已知 URL 直接访问
    → 前台详情页（noindex 实体的详情页仍可返回 200，这是**契约允许**的）
```

`PublicIndex::entityQuery()` 的注释也写明：

> noindex 实体的前台详情页仍可直接访问（200），
> 只是不被 sitemap / geo / llms 收录。

**所以「Frontend 全部不用 PublicIndex」是合理差异，不是缺口。**
本次测绘只对**发现型出口**判缺口。

---

## 一、Public Surface Matrix

| Surface | Source | Visibility判定 | Site隔离 | Locale | Deleted | Scheduled | 关系完整性 |
|---|---|---|---|---|---|---|---|
| **Sitemap** | `PublicIndex::contentQuery()` / `entityQuery()` / `indexableEntitySlugs()` | ✅ 契约内 | ✅ BelongsToSite | ✅ `forLocale` | ❌ 无字段 | ❌ 无字段 | ✅ |
| **LLM**（llms.txt） | `PublicIndex` + `Catalog` + `Fact::publicRows()` | ⚠️ 实体经白名单投影；`Catalog` 段不判 noindex（G-4） | ✅ | ✅ | ❌ | ❌ | ✅ |
| **GEO**（geo.json）节点 | `PublicIndex::contentQuery()` 等 | ✅ 契约内 | ✅ | ✅ | ❌ | ❌ | ✅ |
| **GEO** Entity↔Entity 关系 | `PublicIndex::entityQuery()` | ✅ 契约内 | ✅ | ✅ | ❌ | ❌ | ✅ |
| **GEO** Content↔Entity 关系 | ⚠️ **裸 `Entity::whereIn`** | ❌ **缺口 G-1** | ✅ `where(site_id)` | ❌ 无 locale 门禁 | ❌ | ❌ | ❌ |
| **RSS**（feed.xml） | `PublicIndex::contentQuery()`（`FeedController:146`） | ✅ 契约内 | ✅ | ✅ | ❌ | ❌ | — |
| **Search** | `SearchIndexBuilder:55,59` → `PublicIndex` | ✅ 契约内 | ✅ | ✅ | ❌ | ❌ | — |
| **Schema** Organization | `Setting` + `Catalog::company()` | ✅ `Entity::published()` | ✅ | ✅ | ❌ | ❌ | ✅ |
| **Schema** Website productLines | `Catalog::productLines()` | ✅ `Entity::published()` | ✅ | ❌ | ❌ | ❌ | ✅ |
| **Schema** article about/mentions | ⚠️ **裸 `entityLinks()`** | ❌ **缺口 G-2** | ✅ 关联带 scope | ❌ | ❌ | ❌ | ❌ |
| **Schema** 产品 about | ⚠️ **`Entity::withoutSiteScope()`** | ❌ **缺口 G-3（最严重）** | ❌ **无站点隔离** | ❌ | ❌ | ❌ | ❌ |
| **Frontend** 详情页 | 各 Controller / 直接访问 | ✅ 契约外（合理） | ✅ | ✅ | ❌ | ❌ | — |
| **Frontend** 列表页 | `Catalog::*` | ⚠️ published 但不判 noindex（G-4） | ✅ | ✅ | ❌ | ❌ | — |
| **Internal Links** | 视图层 `PublicUrl` + 菜单/区块 | ✅ 不产生清单 | — | — | — | — | — |
| **robots.txt** | `FeedController::robots()` | ✅ 静态规则 | — | — | — | — | — |

**结论：发现型出口的主体路径（节点 / 列表收录）4/5 严格遵守契约。
缺口集中在「关系端点」（G-1/G-2/G-3）与「noindex 跨出口一致性」（G-4）。**

> `Deleted` / `Scheduled` 两列**全表为空** —— 因为系统当前根本没有这两个概念。
> 这不是测绘遗漏，而是 D4/D3 要补的内容。

---

## 二、已确认缺口（3 处，按严重度）

### G-1 GEO relations的 Content↔Entity 边端点

`app/Services/Geo/GeoGraphBuilder.php:238-240`

```php
$ceRows = \App\Models\ContentEntity::where('site_id', $siteId)->get();
$entById = Entity::whereIn('id', $ceRows->pluck('entity_id'))->get()->keyBy('id');
//↑ 裸查询，无 published()、无 forLocale()
$pubContents = PublicIndex::contentQuery()->...   // Content端 ✅
```

**探针实测**（草稿实体 + 已发布内容 + 一条 content_entity 边）：

```text
seg[entities]   count=1  只有 probe-pub-product      ← published 正确进入
seg[relations]  count=1  to = probe-draft-product    ← 草稿实体成了端点
seg[contents]   count=1  正常
```

对比同文件 `relations()` 段（Entity↔Entity，`:195`）走的是
`PublicIndex::entityQuery()`，注释还写着「任一端 draft / noindex / 他站则不输出该边」。

**⇒ 同一个 Builder 的两段关系用了两套口径。**

### G-2 Schema `article()` 的 about / mentions

`app/Services/Geo/SchemaBuilder.php:293`

```php
foreach ($c->entityLinks()->with('entity')->get() as $link) {
    $to = $link->entity;
    //无 published() 判定
    $node = ['@type' => ..., 'name' => $to->name];
```

`Content::entityLinks()` 是纯 `hasMany(ContentEntity::class)`（`Content.php:120-123`），
无任何可见性约束。草稿实体的 `name` 会进 JSON-LD 的 `about` / `mentions`。

### G-3 Schema 产品的 about —— **最严重**

`app/Services/Geo/SchemaBuilder.php:393-403`

```php
$relRows = EntityRelation::where('from_entity_id', $e->id)
    ->where('relation_type', EntityRelation::TYPE_RELATED_TO)->get();
foreach ($relRows as $rel) {
    $to = Entity::withoutSiteScope()->find($rel->to_entity_id);
    // ↑ 显式关闭站点隔离，且只判 type，无 published
    if ($to && $to->type === Entity::TYPE_PRODUCT) {
        $about[] = ['@type' => 'Product', 'name' => $to->name];
```

三重问题叠加：

```text
① withoutSiteScope()  →跨站实体可进入
② 无 published()      → 他站草稿 / noindex 实体可进入
③ 无 locale 门禁      → 跨语言泄漏
```

**这是三个维度同时失守**，且输出的是「Product 节点名」——
AI 会把它当作该页面关联产品的正式声明。

---

## 三、补测结果（原「尚未核实」项已全部完成）

### 3.1 `SchemaBuilder::organization()` —— ✅ 无缺口

来源为 `Setting`（`geo_org_name` / `geo_org_en_name` / `geo_org_logo`）
与 `Catalog::company()` / `salesRegions()` / `productLines()`。
`Catalog` 内部走 `Entity::published()`（`Catalog.php:92,103,114,119,132-136`），
故组织节点有published 约束。

### 3.2 `website()` 的 `productLines` —— ✅ 无缺口

`SchemaBuilder:169` 用 `Catalog::productLines()`，同样经 `Entity::published()`。

### 3.3 前台列表页（Product / Solution）—— ⚠️ **口径分歧（非缺口，但需记录）**

```text
ProductController@index  → Catalog::company() / productLines() / productsByLine()
SolutionController@index → Catalog::scenes() / company()
CaseController           → Catalog::isCoreProduct()
```

`Catalog` 内部用 `Entity::published()`，**但不检查 SeoMeta.noindex**。

对比 `PublicIndex::entityQuery()`：**published + 非 noindex**。

⇒ 因此存在真实分歧：

```text
一个 published 但被标记 noindex 的实体
  ├─ PublicIndex::entityQuery()  →  排除 ✅（不进sitemap / geo / llms）
  └─ Catalog::products()         →  仍会出现在前台列表页与 JSON-LD
```

**这不是 bug**，而是**契约边界问题**：
`PublicIndex` 文档明确说 noindex 实体的**详情页仍可访问（200）**，
前台列表页展示 noindex 实体在语义上可争议。

**但它产生一个实质后果**：

```text
llms.txt  不列出该实体 ✅
geo.json  不列出该实体 ✅
sitemap   不列出该实体 ✅
JSON-LD 的 about / productLines 仍可能出现它 ⚠️
前台列表页仍展示它   ⚠️
```

即 **noindex 意图在四个出口之间不完全贯彻**。
这属于 D5Acceptance Contract 必须裁决的范围（noindex 到底约束哪些出口）。

### 3.4 `EntityCapabilityRegistry::isPublic()` —— 与可见性**无关**

```php
/** 是否拥有独立前台落地页（PublicUrl / EntityRenderContext 据此裁决）。 */
public static function isPublic(string $type): bool
```

它判的是「**该类型有没有自己的详情页**」（如 product 有、case 无），
与 published / noindex 是**正交维度**。不构成口径冲突，但命名易混淆——
D1 命名时应避免复用 `isPublic` 表达生命周期概念。

### 3.5 Internal Links —— 不产生清单

视图层 `PublicUrl` + 菜单 / 区块配置生成链接，不输出资源清单，
不受 `PublicIndex` 契约约束。**不算缺口。**

### 3.6 `robots.txt` —— 静态规则

`FeedController::robots()`，不含资源判定。**不算缺口。**

---

## 三之二、修正后的缺口清单

| ID | 位置 | 缺陷维度 | 严重度 |
|---|---|---|---|
| **G-1** | `GeoGraphBuilder:238-240` Content↔Entity 边 | 无 published + 无 locale | P2 |
| **G-2** | `SchemaBuilder:293` article about/mentions | 无 published | P2 |
| **G-3** | `SchemaBuilder:393-403` 产品 about | 无 published + **无站点隔离** + 无 locale | **P1** |
| **G-4** | `Catalog` vs `PublicIndex` 的 noindex 分歧 | 4 出口不一致 | 需 D5 裁决 |

G-3 提到 P1：三维度同时失守，且输出 Product 节点名（AI 会当作正式声明）。

---

## 四、探针有效性声明（按Probe Validation 原则）

本次探针按四步验证后才采信结论：

```text
1. Positive control exists
   published 实体 probe-pub-product 与草稿实体同批创建
2. Probe detects positive control
   ✅ probe-pub-product 出现在 seg[entities] —— 证明出口路径通
3. Probe detects negative control
   ✅ seg[entities] 中**不含** probe-draft-product —— 证明实体层过滤有效
4. Only then interpret target result
   → seg[relations].to = probe-draft-product 才可判定为真缺口
```

**若缺第 2 步**（阳性对照没出现），则 `draftInGeoJson=false` 只能说明
「探针没找到路径」，不能说明「缺口不存在」。

> 第一次探针正是因为缺阳性对照，误报 `NOT CONFIRMED`。
> 根因三连：测试库无数据 / 表名猜错（`content_entity` 单数）/ 读错结构（三段非 edges）。

---

## 五、测试覆盖现状

```text
GeoFactConsistencyTest  覆盖事实出口一致性（D-02 范畴）
SchemaJsonLdTest/ XssTest覆盖 JSON-LD 结构与 XSS
EntitySchemaContractTest       覆盖 schema 类型契约

❌ 无任何测试断言「geo.json 关系端点的可见性」
❌ 无任何测试断言「JSON-LD about/mentions 节点不指向草稿实体」
```

即：这3 个缺口都在测试覆盖之外。**这解释了它们为何能存活至今**——
不是被遗漏，而是从来没被测过。

---

## 六、核心洞察：缺口的共同形状

三处缺口不是三个独立 bug，而是**同一个抽象缺失的三种表现**：

```text
系统缺一个概念：Public Knowledge Set（公开知识集合）
每个 Builder 只能看到自己那一个 Builder 的查询

于是：
  要列实体  → 自己查 Entitypublished
  要列关系  → 自己查 EntityRelation（忘了门禁）
  要出Schema → 自己查关联实体（忘了门禁 + 忘了站点隔离）
```

**`PublicIndex` 只解决了「实体和内容」的可见性，没有解决「关系」与「语义节点」。**

这直接支撑 D1 的核心问题：

> Publication Lifecycle 的状态机必须能**解释这份 Inventory**。
> 若 Lifecycle 能解释 Frontend 却解释不了 GEO relations 与 Schema，
> 说明 **Lifecycle 模型不完整**，而不是让 GEO 去迁就 Lifecycle。

---

## 七、Publicness 不变量（草案，待 D5 正式化）

```text
∀ edge(A, R, B) ∈ PublicRelations:
      public(A) ∧ public(B)

∀ node N ∈ PublicSchema:
      public(N)

即：公开关系的每个端点 ∈ 公开实体集合
```

**当前状态：违反。** G-1 / G-2 / G-3 三处都会输出「edge public但 node private」。

这条不变量将来应成为 **mutation test 的目标**：
故意把端点过滤短路 → 必须有测试失败。

---

## 八、本文件不做的事

```text
❌ 不修任何缺口         —— 章程明确要求先测完再决定
❌ 不提出修复方案       —— 那是 D1/D5 的产出
❌ 不改任何测试         —— 验收契约由 D5 一次性定义
❌ 不给PublicIndex 加方法 —— 那是 Implementation
```

按用户判断保留：

> 当前发现的 draft relation 悬空端点，应保留为 D2 的真实缺口样例，
> 暂时不要急着修实现；先把七个出口的全部判定点测完。
