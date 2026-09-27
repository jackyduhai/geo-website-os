# P-STEP 18T — 18S 架构冻结 + Product Capability Consolidation / v1.0 Readiness Review（Discovery，只读）

- 阶段：P-STEP 18T（18S 全阶段收口审查）
- 当前 HEAD：`3dd2060`；本评审**只读**，不改业务代码、不新增能力。
- 范围：18S P0 三能力收口、重复规则扫描、指标口径自洽、导航/UX 一致性、文档↔实现核对、v1.0 readiness、架构冻结建议。

---

## 1. 三能力产物 / commit 清单

| 能力 | Commit | 产物 | 状态 |
| --- | --- | --- | --- |
| Cap1 Setup Wizard | `c0c9c4c` | `WizardController`（6 步编排）+ `admin/wizard/step.blade.php` + 路由 `admin.wizard(.save)` | PASS/CLOSED |
| TD-161 修复（中文 slug 空串兜底） | `941ad3c` | `app/Support/EntitySlug.php`（Entity creating 钩子统一兜底） | CLOSED |
| Cap2 GEO Health Dashboard | `71f7ab8` | `GeoHealthService`（5 检查）+ `admin.geo.health` + `admin/geo/health.blade.php` | PASS/CLOSED |
| Cap2 Discovery | `246c82d` | `docs/audit/18S-capability-2-discovery.md` | — |
| Cap3 Entity Coverage Check | `3dd2060` | `EntityCoverageService` + `admin.geo.coverage` + `admin/geo/coverage.blade.php` + Registry required/recommended 契约扩展 | PASS/CLOSED |
| Cap3 Discovery | `dc3fc2a` | `docs/audit/18S-capability-3-discovery.md` | — |

基线 `7828750`（18S 计划 Discovery）、`addb051`（能力蓝图）。rc1=`965d63c` 未动。

---

## 2. 两处已接受口径点：代码核实（如实落地）

### 2.1 product→organization 由 required 降为 recommended（v1.0 契约）
- 代码证据 `config/entities.php` product.relations：
  ```php
  'relations' => [
      ['type' => 'service', 'required' => true],   // 必备：uses→service
      'organization',                                 // 纯字符串 → recommended（归属为 Site 级隐含事实）
      'download_asset',
  ],
  ```
- `organization` 为纯字符串 → Registry `normalizeRelation()` 归一为 `required=false`；`requiredRelations('product')` = `['service']`，**不含 organization**。✅ 已落地。
- 契约说明：产品归属哪个组织是 Site 级隐含事实（站点即主体），不强制每个产品反向挂组织边；故 product→org 不进必备覆盖率分母。

### 2.2 case_study relations 的 scenario 是 service 别名、保留 P3
- 代码证据 `config/entities.php` case_study.relations：
  ```php
  'relations' => [
      ['type' => 'product', 'required' => true],
      'organization',
      'scenario',   // 纯字符串 → recommended；且 scenario 不是已注册 Entity 类型（= service 别名）
  ],
  ```
- `scenario` 为纯字符串 → recommended，**不在 `requiredRelations('case_study')`（= `['product']`）内**，不参与必备覆盖率。✅ 已落地。
- P3 登记：当前为半虚拟别名；未来若启用 recommended coverage，**必须**统一为真实 `service` 类型，或在 Registry 正式定义 alias（含归一化与边匹配），不得长期半虚拟。

---

## 3. 重复规则扫描（grep 证据）

### 3.1 必备规则/必备集合——唯一事实源在 Registry
```
$ grep -rn "requiredRelations\|requiredMetadataKeys\|\$requiredRelations\|\$requiredMetadata" app
```
命中：
- `EntityCapabilityRegistry.php:130` `requiredRelations()`（定义）
- `EntityCapabilityRegistry.php:151` `requiredMetadataKeys()`（定义）
- `EntityCoverageService.php:83-84` 仅**消费**上述两方法（无静态必备数组）。

→ 结论：必备关系/必备字段**只在 config/entities.php + Registry 定义**，CoverageService 不拥有规则。无第二必备集合。

### 3.2 OG / noindex / 落地页 / Schema 输出——权威类外无重复定义
```
$ grep -rn "og:title\|og:description\|noindex\|ENTITY_SCHEMA_TYPES" app
```
命中分布（均为权威消费点，非重复规则）：
- `Http/View/SeoHeadComposer.php:68,121`：**消费** SeoResult（OG/noindex 最终值）做 `<head>` 渲染——正确落点。
- `Support/Catalog.php:293`：`product uses→service` 关系语义（既有 relationMap），非 Coverage 规则。
- `Services/Geo/GeoGraphBuilder.php:138,278`：图谱节点 `noindex=false`（图节点本就公开），与 SeoMetaResolver 不冲突。
- `Services/Geo/GeoHealthService.php:229,232,252`：Check C 仅取 product/service/case_study 三类做 JSON-LD 覆盖范围——**检查范围过滤**（本地常量），非第二套 schema 映射；SchemaBuilder 仍是唯一产出方。
- `Http/Controllers/Site/SearchController.php:56`：搜索结果页自身 noindex=true（页面级策略），与内容 noindex 判定无关。

→ 结论：OG/noindex 解析统一在 SeoMetaResolver、落地页在 PublicUrl、Schema 在 SchemaBuilder、公开口径在 PublicIndex、关系语义在 Catalog/GeoGraphBuilder。Cap2/3 只读复用，**无重复规则/阈值/白名单**。

---

## 4. Health（Cap2）vs Coverage（Cap3）指标口径对照

| 维度 | Cap2 GEO Health（运行时健康） | Cap3 Entity Coverage（知识齐备度） |
| --- | --- | --- |
| 回答 | AI/搜索能否正确抓取理解（输出层） | 每个实体应有的关系/字段填全程度（资产层） |
| 数据源 | SeoMetaResolver / PublicUrl / SchemaBuilder / PublicIndex / EntityRelation | PublicIndex + EntityRelation + Registry(required) + Entity.metadata |
| 分母 | 各检查适用对象数（公开资源/产品/页型/边） | 每实体 required 项数（Registry 派生） |
| 状态模型 | PASS/WARNING/FAIL/N/A | PASS(全齐备)/WARNING(有缺口)/N/A(空站或无应覆盖项) + covered/required 比率 |
| 重叠风险 | 二者都读 EntityRelation，但 Health 计"边是否存在/孤立"，Coverage 判"应有的必备边是否满足"——**口径不同、不互相覆盖、不矛盾** | 同左 |
| 综合分 | 无（不做 0–100） | 无（只给齐备比率，不与 Health 加权融合） |

- **自洽性结论**：同一对象可能在 Health 是 PASS（输出正常）、在 Coverage 是 WARNING（必备关系缺）——这是**两个正交维度**，不是矛盾（一个是"输出对不对"，一个是"填得全不全"）。Cap1 Wizard 是"首次填全"的录入引导，其产物（Organization/Product/关系/wizard_completed）正是 Cap2/3 的输入；Wizard 本身不判定健康/覆盖，只编排录入。

---

## 5. 导航 / UX 一致性核对

- Admin 导航「搜索与 AI（SEO/GEO）」组现含：SEO 覆盖 / SEO 设置 / GEO 设置 / 抓取产出·Sitemap / **GEO 健康** / **实体覆盖** / 301 跳转 / GEOFlow 对接 / 同步日志。✅ 两能力相邻、命名一致（GEO 健康 vs 实体覆盖）。
- Wizard 入口：`admin.wizard`（登录后首次/可访问），6 步单视图 + 跳过链接。✅
- 组件复用：Health 与 Coverage 均用 `.card/.tbl/.stat/.badge/.line-list`，状态徽标 ok/info/err/archived；无 inline style / 新 token。✅
- 空态：Blank 空站两页均为合法 N/A 提示（"空站状态，不计为故障"），不报红。✅
- 深浅色 / zh：跟随既有 admin layout（dark/light 由现有机制承载），文案 zh-CN；两能力一致。✅
- 缺口感知：Coverage 缺失项含「去编辑 →」只读链接，无一键修复（检测器非修复器）。✅

---

## 6. 文档 ↔ 实现一致性核对

- Cap1 Gate `docs/audit/18s-wizard-gate.md`：TD-161 复验小节记录 slug 兜底（Entity creating 钩子）与 focused/浏览器证据——与 `EntitySlug.php` 一致。
- Cap2 Discovery/Final：5 检查、blank N/A、HTTP ld+json 三层证据——与 `GeoHealthService` 一致。
- Cap3 Discovery/Final：应覆盖派生自 Registry、covered/required、无加权分——与 `EntityCoverageService` + Registry 契约一致。
- **两处口径点**：§2 已核实代码如实落地；本 18T 文档首次以 v1.0 契约形式正式记录（product→org=recommended；scenario=service alias P3）。
- 无夸大：所有 PASS 结论均有对应测试与浏览器/HTTP 证据支撑。

---

## 7. v1.0 Readiness Feature Matrix

（枚举：Implemented Verified / Product Incomplete / Architecture Ready / Deferred / Missing）

| 能力域 | 状态 | 证据 |
| --- | --- | --- |
| 多站点 + 站点切换 + SiteScope | Implemented Verified | admin.sites.switch / BelongsToSite |
| Entity 类型集合（8 型）+ Registry 能力声明 | Implemented Verified | config/entities.php / Registry |
| Entity 知识图谱关系（五型冻结） | Implemented Verified | EntityRelation + saving 钩子 |
| Setup Wizard 首跑（6 步） | Implemented Verified | c0c9c4c + 测试 + 浏览器 |
| 中文 slug 兜底（TD-161） | Implemented Verified | EntitySlug + focused 测试 |
| SEO 覆盖（SeoMetaResolver fallback） | Implemented Verified | SeoMetaController/Resolver |
| Schema/JSON-LD 输出（Home/Product/Service/Case/Article） | Implemented Verified | SchemaBuilder + HTTP 断言 |
| sitemap / llms.txt / geo.json / robots / feed | Implemented Verified | FeedController + 200 实测 |
| GEO Health Dashboard（5 检查） | Implemented Verified | GeoHealthService + 11 类断言 |
| Entity Coverage Dashboard（齐备率） | Implemented Verified | EntityCoverageService + 11 用例 |
| 多语言（locale / translation_group） | Architecture Ready | PublicIndex forLocale；UI 文案以 zh 为主 |
| Content 深度门禁 ContentGate | Implemented Verified（既有） | 复用，未改 |
| 0–100 综合健康分 | Deferred（明确不做） | 避免与 Health/Coverage 维度重叠 |
| recommended（建议项）覆盖率 | Deferred（P3） | scenario alias 统一后再启用 |
| Customer CaseStudy /cases 前台路由 | Architecture Ready（2b 接通后自动） | PublicUrl 已就位 |
| 基础设施 /health | Architecture Ready（既有，非本范围） | Api\HealthController |

---

## 8. P0 / P1 产品级缺口

- **P0 = 0**
- **P1 = 0**
- 说明：以「v1.0 可商业交付的 GEO Native Website OS」标尺，首跑 Wizard、知识图谱、SEO/GEO 输出、运行时健康、资产齐备度均已实现并有测试+浏览器证据。剩余项均为 P2/P3/TD（见 §10），不阻塞 v1.0。

---

## 9. 架构冻结建议清单（v1.0 冻结后只允许 bug 修复）

1. **Entity 类型集合**：8 型（organization/product/service/person/location/topic/case_study/download_asset）冻结；新增类型必须同时登记 Registry 并评审。
2. **Registry 能力 / 必备声明契约**：schema/public/searchable/geo/sitemap + relations/metadata 的 required/recommended 归一化形式冻结；消费方一律经 Registry，禁止散点 `if($type===...)`。
3. **五型关系语义**：produces/offers/uses/located_in/related_to 冻结；边两端同站 saving 钩子不变。
4. **公开口径 PublicIndex**：published + 非 noindex + 栏目启用 + 当前站 + locale 冻结。
5. **SEO/GEO 输出契约**：SchemaBuilder（ld+json）、SeoMetaResolver（OG/canonical/hreflang）、SitemapBuilder、LlmsBuilder、GeoGraphBuilder（geo.json）输出格式冻结。
6. **Admin 三看板契约**：Wizard（admin.wizard）、GEO 健康（admin.geo.health）、实体覆盖（admin.geo.coverage）只读聚合形态冻结；不发展为修复器、不建第二事实源。
7. **落地页 PublicUrl 裁决**：核心产品/场景/案例路由映射冻结。

---

## 10. TD impact

- **TD-161**（中文 slug 空串）：CLOSED（EntitySlug 统一兜底）。
- **TD-156**（CaseStudy 五要素发布门禁）：与 Cap3 case_study 必备 metadata（challenge/solution/result）口径一致，复用不重复。
- **TD-158**（content_entity/content_tag orphan pivot）：经公开/两端公开口径自然排除，不修复。
- **TD-162**（P3，Wizard 产品未入系列分组）：保持不动。
- **scenario = service 别名（P3，本次登记）**：case_study.relations 的 `scenario` 为 recommended 别名，当前不参与必备覆盖率；未来启用 recommended coverage 前必须统一为真实 `service` 类型或在 Registry 正式定义 alias。

---

## 11. 最终结论

- 三能力全部 Implemented Verified，无重复规则、无第二事实源、指标口径正交自洽、导航/UX 一致、文档↔实现无夸大。
- 两处已接受口径点（product→org=recommended；scenario=service alias P3）代码已如实落地。
- **P0 = 0，P1 = 0。**
- **v1.0 READINESS = READY。**

按红线：本评审只读、docs-only 提交、不进 19A、不配 remote、不 push、不 release、不动 rc1。完成后 STOP，交人工裁定。
