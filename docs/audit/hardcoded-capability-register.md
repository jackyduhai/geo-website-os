# GEO Website OS — Frontend Hardcoded Capability Register

- **阶段**：P-STEP 18E — Frontend ↔ Backend Capability Closure。
- **方法**：AST / 文本 Grep（业务词、hex/rgba/hsl、URL、SEO/GEO 字段）+ 真实浏览器 / HTTP 三层审计；每条给出位置、现值、写死原因、正确来源、是否需迁移/后台/测试、状态。
- **状态图例**：`FIXED`=本轮已改并补测试；`NON-DEBT`=经核实属系统常量 / 语义 token / 框架默认 / 数据驱动，非写死缺陷；`DEFERRED`=正式裁定 v1.1+；`OBSERVE`=记录观察、不阻塞。
- **总判定**：**业务内容硬编码 = 0；品牌硬编码 = 0；URL 事实硬编码 = 0；SEO/GEO 事实硬编码 = 0。** 视觉孤立彩色硬编码已由 18D 清零（TD-31）。

---

## 1. 本轮发现并修复（FIXED）

| ID | Location | Current（修复前写死值） | Why Hardcoded | Correct Source | Migration? | Admin? | Test? | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| R-01 | `resources/views/site/factory.blade.php` H1 / stats；`FactoryController` stats·SEO | H1 对缺失 area_sqm/workshops/annual_capacity_tons 裸输出「自有约 0 ㎡ 厂区，0 个车间，年产能约 0 吨」；stats 渲染全部 5 条含 0；SEO 拼空 area/产能 | Blade 直接插值未做存在性门控；Demo 三项齐全故未暴露 | Entity[organization] production 投影（Catalog），按真实事实逐项拼接、`num>0` 过滤 | 否（同源数据） | 否（实体派生） | **是** FrontendBackendClosure18ETest（2 用例） | FIXED（TD-39） |
| R-02 | `resources/views/site/_bottom_cta.blade.php` 电话行 | 「或直接致电」无 `@if` 门控，空站 /knowledge/ 出现空号码行 | Blade 假设电话恒存在 | Copy::bcta + 电话值，`@if(!empty($bcPhone))` | 否 | 否 | **是** test_bottom_cta_hides_phone_row_when_no_phone | FIXED（TD-40） |
| R-03 | `resources/views/site/solutions/index.blade.php` H1 | 「你的**店**属于哪一类？」（零售/餐饮口径） | 早期面向门店客户的措辞 | 行业中性「你的**业务**属于哪一类？」 | 否 | 否（可后续做文案键） | **是** test_solutions_index_uses_neutral_business_wording | FIXED（TD-41） |

---

## 2. 业务 / 品牌事实写死审计（结果 = 0，列证据）

| ID | Location | 现值 / 现象 | Why | Correct Source | 迁移/后台/测试 | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-04 | `app/Support/Copy.php` `form()` phone.invalid；`config/copy.php` | 一度疑 `@json($ff['phone']['invalid'])` 为 null（config 无 invalid 键），曾向 config 加键 | 误判：`Copy::form()` 组装层 line101 已自行兜底 `copy_form_phone_invalid`，**不读 config 该键** | Copy::form()（已提供） | 已撤销 config 多余改动；不占债编号 | NON-DEBT |
| R-05 | Header/Footer（layouts/site.blade） | 公司名/电话/地址/ICP/公众号/邮箱 | 审计确认**零写死**：全 Site-scoped 模型，缺失整段隐藏 | Site.name / Setting / Catalog | 18B/17F 测试 | NON-DEBT |
| R-06 | `products/show` 规格槽（net_weight/packaging/shelf_life/storage/moq） | 实物商品规格字段，疑食品专属 | 通用实物商品槽位；Catalog 投影默认 null，`filter(filled)` 门控，换行业不供即整行隐藏 | Entity[product] metadata | 空数据 HTTP 验证 | NON-DEBT（DATA-DRIVEN） |
| R-07 | buildStats / hero「年产能（吨）/自有场地（㎡）/生产设施」 | 制造口径数字 | 数据驱动：行业事实仅 Demo（示例制造）提供，空站/非制造不供即隐藏（stats 已过滤 num>0） | Entity[organization] production | HTTP 空站不渲染 | NON-DEBT（DATA-DRIVEN） |
| R-08 | hero 信任条「工厂口径」、/factory/ `hasProduction()` 门禁 | 工厂/产能表述 | hasProduction 仅在存在任一生产事实时成立；无该事实 /factory/ 直接 404，信任条不渲染 | Catalog::hasProduction | 17G/18B 测试 | NON-DEBT（DATA-DRIVEN） |
| R-09 | 首页 cases（客户案例） | 早期可能含真实客户名 | 已匿名化（无客户名），数据来自组织 metadata.cases，空则不渲染 | Entity metadata / Block | 污染扫描 | NON-DEBT（DATA-DRIVEN） |
| R-10 | 首页 facts `Fact::publicRows` | 公司全称/品牌/官方电话 | 三 key 已在装配时剔除，take(8)，敏感事实不裸输出 | Fact 模型 + 剔除逻辑 | HTTP 剔除断言 | NON-DEBT |

---

## 3. 视觉写死审计（18D 已清零，记录保留项）

| ID | Location | 现值 | Why | Correct Source | Status |
| --- | --- | --- | --- | --- | --- |
| R-11 | 全站 Blade/CSS 彩色值 | 彩色 hex/rgba/hsl | 18D Global Visual Refactor：**彩色孤立 hex=0、彩色 rgba=0**（TD-31） | 四层 Design Token（Primitive→Semantic→Component→Page） | NON-DEBT |
| R-12 | 反白文字 / 图标 mask | `#fff`（语义反白）、`#000`（CSS mask 技术常量） | 反白是固定语义；mask 必须实色才能裁切 | 技术常量（非品牌色） | NON-DEBT |
| R-13 | 阴影 / 遮罩 | 中性 `rgba(15,23,42,…)` / `rgba(0,0,0,…)` | 阴影与遮罩为中性黑、随 token 透明度，不表达品牌 | shadow token | NON-DEBT |
| R-14 | 固定 spacing/radius/shadow 数值 | 个别 px/rem | 已系统化（spacing scale / radius / shadow token）；缝隙 px 与 @media 收缩值按 design-system §4.3 契约保留 | Design Token | NON-DEBT |

---

## 4. URL / 路由写死审计

| ID | Location | 现值 | Why | Correct Source | Status |
| --- | --- | --- | --- | --- | --- |
| R-15 | 声明性绝对 URL（canonical / og:url / og:image / JSON-LD @id·url·item·image / sitemap loc / llms / rss / crumbs） | 早期用 `url()` helper 随请求 origin | 18C（TD-09/#143）已全改派 **PublicUrl**（按站点 domain 裁决），消除 host/scheme 分叉 | PublicUrl::url() | NON-DEBT（TD-09 CLOSED） |
| R-16 | 功能性同源 URL（导航链接/卡片/表单 action/favicon/后台 label/重定向） | `url()` / `route()` / `asset()` | 这些跟随当前请求 origin 是正确行为（非声明性事实），显式保留 | Laravel 路由/asset helper | NON-DEBT |
| R-17 | 业务路径前缀 /products /solutions /knowledge /contact | 路径段 | 由命名路由 + PublicUrl 统一生成，无散落手写拼接；旧 /scenarios、/product 等有 301 桥接 | 命名路由 / PublicUrl | NON-DEBT |
| R-18 | 政府备案链接（Footer ICP/公安） | `https://beian.miit.gov.cn/`、`https://beian.mps.gov.cn/` | 法定固定政府站点，属系统常量白名单（非企业事实） | System Constant | NON-DEBT |
| R-19 | Schema 上下文 | `https://schema.org` | 标准命名空间常量 | System Constant | NON-DEBT |

---

## 5. SEO / GEO 默认文案审计

| ID | Location | 现值 | Why | Correct Source | 迁移/后台/测试 | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-20 | `KnowledgeController` SEO description | 「…帮助客户把**材料**用对、把**生产**做稳定」 | 偏制造/材料；18B 已过范围，知识中心默认描述 | 可运营文案键（Narrative/Setting） | 登记观察；本轮不扩大修改 | OBSERVE（建议 v1.1 文案键化） |
| R-21 | `SolutionController` index SEO description | 「…关键**工艺参数**」 | "工艺"偏制造，但属通用工程表述 | 可运营文案键 | 观察 | OBSERVE |
| R-22 | SEO title/description/canonical/robots/OG 主链 | 各页 SEO | 统一走 **SeoMetaResolver**（Site/Content/Entity 三作用域），Controller/Blade/config 不另造 fallback | SeoMeta + Resolver | 17D/18C 测试 | NON-DEBT |
| R-23 | GEO 实体/关系/事实 | GeoGraph 输出 | 权威源 Entity/EntityRelation/Fact，无 Blade 写死 GEO 事实 | Entity / Relation / Fact | 17C/18A 测试 | NON-DEBT |

---

## 6. 搜索 / 表单深度（非"写死"，属能力范围，登记阶段归属）

| ID | Location | 现象 | Why | Correct Source / 阶段 | Status |
| --- | --- | --- | --- | --- | --- |
| R-24 | `SearchController` / search.blade | 仅搜 Content（title/summary），不召回 Entity；英文召回弱 | 能力范围而非写死 | **18H Search**（对应 TD-20③④）：Entity 召回、locale、type ranking | DEFERRED（18H 处理，18E 不跨阶段） |
| R-25 | Inquiry 表单字段 | 固定 4 可见字段，暂不支持自定义字段/多表单 | 产品能力范围 | **18H Forms**：字段类型/必填/成功文案/多表单（Contact/Demo/Download） | DEFERRED（18H） |
| R-26 | Analytics 集成点 | 暂无 GA/GTM/Pixel 可配置注入 | 产品能力范围 | **18H Analytics**：integration point + page_view/CTA/form 事件 | DEFERRED（18H） |

---

## 7. 汇总

| 类别 | 数量 / 结论 |
| --- | --- |
| 业务内容硬编码（公司名/电话/地址/行业事实写死 Blade） | **0** |
| 品牌硬编码（品牌色/Logo 写死、不随 Theme/Setting） | **0** |
| 视觉孤立彩色硬编码 | **0**（18D TD-31；仅留语义反白/mask/中性阴影技术常量） |
| URL 事实硬编码（声明性绝对 URL 不随站点 domain） | **0**（18C TD-09，全改派 PublicUrl） |
| SEO/GEO 事实硬编码（绕开 Resolver / 权威实体） | **0** |
| 前台能力无后台数据源 | **0**（见 capability matrix） |
| 本轮 FIXED | 3（R-01/02/02 → TD-39/40/41） |
| 误判撤销 NON-DEBT | 1（R-04 phone.invalid） |
| OBSERVE（建议文案键化，不阻塞） | 2（R-20/21） |
| DEFERRED 到 18H（能力范围，非写死） | 3（R-24/25/26） |

**允许存在的写死（白名单）**：系统常量（schema.org、政府备案站）、框架/技术常量（语义反白、mask、阴影）、语义 token 定义、Example/Demo 数据、tests、历史 migration。

> 债务编号与销项状态统一回写 `technical-debt-registry.md`；能力闭环见 `frontend-backend-capability-matrix.md`。
