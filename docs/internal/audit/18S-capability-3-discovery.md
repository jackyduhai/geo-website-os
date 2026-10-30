# P-STEP 18S Capability 3 — Entity Coverage Check · Discovery（只读）

- 阶段：P-STEP 18S / Product Capability 3（实体知识资产齐备度）
- 基线：Cap2 GEO Health = `71f7ab8`；Discovery = `246c82d`
- 本轮性质：**只读 Discovery**。禁止编码 / 改库 / Implementation；完成后 STOP 交 Discovery Gate。
- 定位：回答「企业知识图谱里，每个核心实体应有的关系 / 必备字段是否填全」（知识资产填没填全），**不是** Cap2 的「输出运行时健康」（AI 能否正确抓取理解）。

---

## 1. Current Architecture（现有可复用对象 + 签名）

### 1.1 "应覆盖"的权威来源（Single Source of Truth）
- **`config/entities.php` + `App\Support\Entities\EntityCapabilityRegistry`**：每个 Entity 类型的能力声明，是"应覆盖关系 / 应覆盖字段"的天然派生源。关键签名：
  - `EntityCapabilityRegistry::get(string $type): ?array` → `{label, schema, public, searchable, geo, sitemap, relations[], metadata[]}`
  - `::relations(string $type): array`（允许关联的目标类型声明，2a 起声明式、不强制校验）
  - `::metadataKeys(string $type): array`（该类型 metadata JSON 允许承载的业务键白名单）
  - `::schemaType/isPublic/isGeo/isSitemap` 等。
- 逐类型声明（实测 `config/entities.php`）：

| type | relations（允许目标） | metadata 白名单 |
| --- | --- | --- |
| organization | product, person, location | brand, industry, phone, email, address, is_site_organization |
| product | service, organization, download_asset | core, line, tagline, key_params, params, og_image, card_image |
| service | product | scope, title_q, og_image, card_image |
| person | organization | （空） |
| location | organization | address, geo |
| topic | （空） | （空） |
| case_study | product, organization, scenario | industry, scenario, challenge, solution, result |
| download_asset | product, media | media_id, type, language, version |

> 注：`case_study.relations` 中的 `scenario` 即 `service`（服务/场景）；`download_asset.relations` 中的 `media` 是经 `metadata.media_id` 引用 Media，非实体关系边。

### 1.2 关系 / 内容边的真实数据源
- **`EntityRelation`**：五型冻结 `produces/offers/uses/located_in/related_to`（`TYPE_*` 常量），site-scoped，`saving` 钩子强制两端同站；`from_entity_id/to_entity_id`。
- **`ContentEntity`**（18R-2c，表 `content_entity`）：Content↔Entity，`relation_type` = `about` / `mention`（`RELATION_ABOUT/MENTION`），site-scoped。
- **`content_tag`**（18R-2c）：Content 多对多标签（本能力不展开，仅登记）。
- 关系语义权威（`App\Support\Catalog::relationMap`）：`product uses→service`（适用场景）、`service uses→product`（组合）、`product related_to→product`（相关）、`service related_to→service`（相邻）、`organization produces→product`。

### 1.3 既有只读聚合范式（Cap2，本能力复用、不复制）
- **`App\Services\Geo\GeoHealthService`**（Cap2，`71f7ab8`）：`report()` 只读聚合 5 检查；其中 **Check E（GEO Edge Count）+ orphan 检测** 已示范：`EntityRelation::where('site_id',…)` + `ContentEntity`，两端均经 `PublicIndex` 公开口径过滤才计数，`whereIn` 批量取端点实体、一次 `get()` 内存聚合，无 N+1。
- **`PublicIndex::entityQuery()/contentQuery()`**：公开口径（published + 非 noindex + 栏目启用 + 当前站 + locale）。
- **`SeoMetaResolver` + `preload*`**：OG/noindex 解析与 N+1 消除（Cap2 已用）。
- **`PublicUrl` / `SchemaBuilder` / `GeoGraphBuilder` / `Catalog`**：Cap2 已确认的复用对象。
- Admin：`Admin\GeoController`（`health()` 已存在）、`admin.layout` 导航、`.card/.tbl/.stat/.badge/.line-list` 视图组件。

---

## 2. Coverage 定义："应覆盖 / 实际覆盖"口径（逐类型）

### 2.1 派生原则（不另写一份应覆盖清单）
"应覆盖项"**从 `EntityCapabilityRegistry` 的 `relations`（允许目标类型）+ `metadata`（白名单键）+ 五型关系语义（Catalog::relationMap）派生**，不硬编码第二张应覆盖表。判定时：
- 关系类应覆盖项 = 该类型按语义"应建立"的有向边（from→to + relation_type）。
- 字段类应覆盖项 = 该类型 metadata 白名单中"必备"键 + 实体必备文本字段（summary/description）。
- 标注 **必备（required）** vs **建议（recommended）**：必备缺失 → 该实体 coverage 记缺口；建议缺失 → 提示。

### 2.2 逐类型应覆盖矩阵（草案，Implementation 期按 Registry 数据落地）

| 类型 | 应覆盖关系（必备 / 建议） | 应覆盖字段（必备 / 建议） |
| --- | --- | --- |
| organization（主体） | 必备：`produces→≥1 product`；建议：`located_in→location`、`person` 关联 | 必备：summary/description；建议：metadata brand/industry/phone/email/address |
| product（核心） | 必备：被 `organization produces→` 指向；必备：`uses→≥1 service`（适用场景）；建议：`related_to→product`、`offers→≥1 download_asset` | 必备：summary；建议：metadata tagline/key_params/params/og_image |
| service（场景） | 必备：`uses→≥1 product`（组合）；建议：`related_to→service`（相邻） | 必备：summary；建议：metadata scope/title_q/og_image |
| case_study | 必备：`related_to→≥1 product`；建议：`related_to→organization`（客户）、`scenario` | 必备：metadata challenge/solution/result；建议：industry/scenario |
| download_asset | 必备：被 `product offers→` 指向；必备：`metadata.media_id` | 建议：type/language/version |
| person / location / topic | 建议：按 relations 声明挂边（person→organization、location→organization located_in） | 按 metadata 白名单 |

> 说明：以上"应覆盖"全部可由 Registry 的 `relations`/`metadata` + 五型语义机械派生；Registry 增删类型 / 键时，Coverage 自动跟随，无需改第二张清单。

### 2.3 "实际覆盖"取数（全部只读、site+locale scoped）
- 关系覆盖：`EntityRelation::where('site_id',current)` 批量取边，两端实体经 `PublicIndex::entityQuery()->forLocale(current)` 判公开（与 GeoGraphBuilder 同口径）；按 from_type + relation_type 聚合"已建立的边"。
- 内容覆盖：`ContentEntity::where('site_id',current)`，content 端 `PublicIndex::contentQuery()` 公开才计（about/mention）。
- 字段覆盖：读实体 `metadata`（白名单键是否非空）+ `summary/description` 是否非空。
- 可行性：全部有现成只读查询；无需新表。

---

## 3. 与 Cap2 GEO Health 的边界（复用 vs 不复制）

| 维度 | Cap2 GEO Health（已建） | Cap3 Entity Coverage（本能力） |
| --- | --- | --- |
| 回答 | AI 能否正确抓取 / 理解（输出运行时健康） | 知识资产填没填全（内容齐备度） |
| 检查对象 | OG / 公开 URL / JSON-LD 实际输出 / noindex / 边计数 | 每实体应覆盖关系 + 必备字段的齐备率 |
| 状态模型 | PASS/WARNING/FAIL/N/A（运行时健康） | 覆盖率 = 已覆盖/应覆盖（齐备比率），不产出加权健康分 |
| 复用 | — | 复用同一 `PublicIndex/SeoMetaResolver/GeoGraphBuilder` 口径与 Cap2 只读聚合范式（preload/whereIn/一次 get） |
| 不复制 | — | 不重算边计数（Cap2 Check E 已有）；Coverage 只在"边存在"基础上判"是否满足该实体应有的边"，不另建事实源 |

> 边界结论：Coverage 是 Cap2 只读聚合范式上的**新检查组**，复用同一批底层只读数据；二者不重叠、不各建一套事实源。

---

## 4. 覆盖率口径论证（齐备比率 vs 加权健康分）

- **覆盖率定义**：`coverage = 已覆盖项数 / 应覆盖项数`，可下钻到 单实体 / 类型 / 整体三层。它是"应 N 已 M"的**齐备比率**，描述知识资产填全程度。
- **与"0–100 加权健康分"的区别（明确不做）**：
  - 不做跨检查加权、不做综合健康分、不与 Cap2 的 PASS/WARNING/FAIL 融合成一个分数；
  - 覆盖率只回答"应覆盖项填了多少"，**不**回答"输出是否健康 / AI 能否抓取"（那是 Cap2）；
  - 不做阈值红线（如 <80% 即 Fail）制造假故障——只给比率 + 缺失项清单 + 去编辑链接。
- 这样既满足"覆盖率定义本身"，又不与 Health 重叠、不产生第二个综合评分体系。

---

## 5. 可行性矩阵

| 判定 | 数据源 | 可实现 | 需新表 | 风险 |
| --- | --- | --- | --- | --- |
| 应覆盖关系派生 | EntityCapabilityRegistry.relations + 五型语义 | 是 | 否 | relations 是"允许目标"非"方向/类型"，需按 Catalog::relationMap 语义映射为有向应覆盖边 |
| 实际关系覆盖 | EntityRelation（site+locale，两端公开） | 是 | 否 | 与 GeoGraphBuilder 口径保持一致即可 |
| 内容覆盖 | ContentEntity（about/mention） | 是 | 否 | orphan pivot（端已删）经公开口径自然排除（TD-158） |
| 字段覆盖 | Entity.metadata 白名单 + summary/description | 是 | 否 | metadata 键可能为空串，需判非空 |
| 空站/空类型 | PublicIndex 空集 | 是 | 否 | 0 应覆盖项 → N/A，不报假 Fail |
| 多语言 | LocaleContext + translation_group | 是 | 否 | 见 §6.E 规则 |

---

## 6. 关键问题裁定（A–H）

- **A 应覆盖定义**：可直接从 Registry `relations`+`metadata`+五型语义派生（§2.2），不另写清单。
- **B 实际覆盖取数**：全部走 EntityRelation/ContentEntity/PublicIndex 只读（§2.3）。
- **C 覆盖率口径**：齐备比率（已覆盖/应覆盖），**不做**加权综合健康分（§4）。
- **D 空站/空类型**：0 应覆盖项 → N/A，不报假 Fail、不制造虚假缺失。
- **E Site/Locale 隔离**：全部 `SiteContext`/`LocaleContext` 限定，不读 `request('site_id')`；**多语言规则**：覆盖按**当前 locale 的公开实体行**计算，边在"两端均有当前 locale 公开行"时计入（与 GeoGraphBuilder 一致）；不按 translation_group 跨语言聚合（避免把 zh 行的边算进 en 覆盖）。
- **F 检测器非修复器**：纯只读，无写库/持久化/coverage 表/cache 表/migration；不自动补关系/补字段。
- **G 性能**：复用 preload*/whereIn/一次 get 内存聚合，禁止 N+1，不新建缓存系统。
- **H 安全**：走既有 `admin.auth+admin.site`，无公开端点，视图输出转义。

---

## 7. Architecture Decision

| 项 | 裁定 |
| --- | --- |
| 新 Entity | **No** |
| 新表 / migration | **No** |
| 新 Renderer | **No** |
| 第二 SEO·GEO pipeline | **No** |
| 第二 Coverage 事实体系 | **No**（应覆盖项从 Registry 派生，不建第二张清单/表） |
| 第二关系系统 | **No**（只读现有 EntityRelation/ContentEntity） |

> **Discovery Gate 判定**：应覆盖可从 Registry 派生、实际覆盖有真实只读数据源、无第二系统、无新 Entity/表/migration/Renderer/第二 pipeline、无业务硬编码、无未决 P0/P1。**满足进入人工评审条件。** 若 Implementation 阶段发现必须新增任一项 → 立即 STOP 上报。

---

## 8. UX 提案

- **落点**：`Admin\GeoController` 新增 `coverage()`（或在 `admin.geo.health` 页加一个"实体覆盖"标签区），路由 `admin.geo.coverage`，导航置于「搜索与 AI（SEO/GEO）」组，紧邻 GEO 健康。
- **信息架构**：
  1. 顶部：整体覆盖率（已覆盖/应覆盖，按类型分组进度条）+ 当前站点/语言。
  2. 类型分组卡片：product/service/case_study/organization/… 各显示覆盖率 + 缺失项计数。
  3. 单实体展开：列出"应覆盖未覆盖"的具体关系/字段（如"缺适用场景 uses→service"、"缺 summary"），每项给去编辑链接（只读、无一键补全）。
  4. Empty（空站/空类型）：N/A 态文案，不报红。
- **组件**：复用 `.card/.tbl/.stat/.badge/.line-list`；状态用语义色（齐备率高=中性/绿，缺口=琥珀），不新增 inline style/token；dark/light、zh/en 跟随现有 admin layout。

---

## 9. Test Matrix（Implementation 期用例，仅设计）

| # | 场景 | 期望 |
| --- | --- | --- |
| 1 | Blank 空站 | 整体 N/A、0 应覆盖项、无假 Fail |
| 2 | Demo 站 | 覆盖率随真实 DB 计算，不 hardcode 数量/名称 |
| 3 | 单实体齐备 | 核心产品有 produces 边 + uses→service + summary → 覆盖率满 |
| 4 | 单实体缺失 | 产品缺 uses→service / 缺 summary → 列出缺失项 + 去编辑链接 |
| 5 | 逐类型 | product/service/case_study/organization 各自应覆盖项正确派生 |
| 6 | 多 locale | zh/en 分别按当前 locale 行计算，不串语言 |
| 7 | 多 site 隔离 | A 站实体不计入 B 站覆盖 |
| 8 | AuthZ | guest 访问 302 跳登录 |
| 9 | N+1 | 批量 whereIn/preload，无逐条查关系 |
| 10 | 只读 | 渲染不产生写库（entity/relation/metadata 计数前后不变） |
| 11 | 空类型 | 某类型 0 实体 → 该类型 N/A |

---

## 10. 关联 TD（只登记不修）

- **TD-156**（CaseStudy Coverage Completeness Rule）：发布门禁对案例 metadata 五要素的要求——与本能力 case_study 应覆盖字段直接相关；本能力复用其口径，不重复定义，仅登记。
- **TD-158**（content_entity/content_tag orphan pivot）：Coverage 经公开口径自然排除孤儿行，不修复。
- **TD-162**（P3，Wizard 产品未入系列分组）：保持不动。
- 本 Discovery 不新增 TD 编号；Implementation 期若发现新问题从 TD-163 起登记。

---

## 结论
- 应覆盖可从 `EntityCapabilityRegistry`（relations+metadata）+ 五型语义派生；实际覆盖有真实只读数据源（EntityRelation/ContentEntity/PublicIndex）。
- 无新 Entity/表/migration/Renderer/第二 pipeline/第二事实体系/第二关系系统；覆盖率=齐备比率，不做加权健康分；检测器非修复器。
- 无业务硬编码、无未决 P0/P1。
- **Cap3 满足进入人工评审条件（Discovery PASS）。** 本轮 STOP，不进入 Implementation，不进入后续能力。
