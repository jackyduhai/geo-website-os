# GEO Website OS — Frontend ↔ Backend Capability Matrix

- **阶段**：P-STEP 18E — Frontend ↔ Backend Capability Closure（能力对账，非新开发）。
- **基线**：HEAD `checkpoint-18D`（857/4574）→ 本阶段收尾 `checkpoint-18E`（**861 tests / 4590 assertions / 0 / 0**）；worktree clean；`v1.0.0-rc1=965d63c` HOLD；无 remote。
- **对账目的**：证明 ① 前台每个可见元素都有正式后台数据源；② 后台每个可编辑字段都有真实前台 consumer；③ 业务 / 品牌 / URL / SEO / GEO 写死 = 0。
- **方法**：通读首页 16 区块 + Header/Footer/导航 + 全部列表/详情/表单/关于页 + Catalog 投影 + 控制器 + 组件，全部以真实代码 / HTTP / 测试为准；不采信模型描述。
- **状态图例**：`ACCEPTED`=能力闭环且有测试；`DATA-DRIVEN`=数据驱动、空则隐藏（换行业不供数据即不出现）；`DEFERRED`=正式裁定 v1.1+；`NON-DEBT`=经核实非债。

**列含义**：数据源 (Model · Scope) 标注事实模型与作用域（System/Site/Entity/Content）；后台入口·校验标注编辑位置与校验；Locale·Theme 标注是否受多语言 / 主题控制；消费者列出前台与 SEO/GEO/Schema/Feed/Search；缓存失效标注依赖；测试标注 HTTP / Browser 证据。

---

## A. 全局框架 — Header / Footer / 导航 / 外观（layouts/site.blade）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 (前台/SEO/GEO/Schema/Feed/Search) | 缓存失效 | 测试 (HTTP/Browser) | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-A01 | Logo（页眉/页脚/默认 favicon） | Setting `geo_org_logo*` · Site；缺省 `img/logo.png`（中性） | 站点设置→品牌/Logo；文件类型/尺寸校验 | Theme 适配（深/浅 logo 槽） | 前台 Header/Footer；Schema Organization.logo；GEO organization；OG 无 | Setting saved → PageCache::flush | HTTP 空站/Demo 对拍；Browser 多主题 | ACCEPTED |
| CAP-A02 | 站点名（Header 品牌字 / 页脚版权 / 默认 H1） | **Site.name（唯一权威）**；setting `site_name` 仅镜像 | 站点设置→常规（回写 Site.name） | — | 前台品牌字/copyright；SEO title 缺省；Schema Organization.name；GEO site.name；RSS | Site saved → 镜像 + flush | HTTP 改名 9 处跟随；18B 测试 | ACCEPTED（TD-12） |
| CAP-A03 | 主导航（含下拉 children / 当前态 / 外链） | Menu 系统 + `config/copy.nav.menu` 蓝图，`menus` 表 key 覆盖层 + 自定义追加 · Site | 结构管理→菜单装修；URL/外链校验 | Theme 控制样式 | 前台 Header；站点地图不直接消费；404 恢复入口同源 | 菜单缓存 forget（保存即失效） | HTTP 改名/隐藏同步；MenuOverride 测试 | ACCEPTED |
| CAP-A04 | 导航目录可见性（产品/场景/工厂等空目录自动隐藏） | `navPathExists()` 读 Catalog 实际 line/product/scene/company · Site | 无（自动）；有数据才显示 | — | Header 导航；防止空链 | Catalog/Entity 变更 → flush | 空站导航仅知识中心；BlankSystem 测试 | ACCEPTED |
| CAP-A05 | 头部热线（号码 + tel:） | `config/copy.nav.phone` / siteSettings · Site；**无则整段隐藏** | 站点设置→联系方式；电话格式校验 | — | Header；tel: 语义 | Setting saved → flush | HTTP 有/无电话两态 | ACCEPTED |
| CAP-A06 | 头部 CTA「联系我们」 | `config/copy.nav.cta`（可运营）· Site | 文案话术 | Theme 按钮样式 | Header；锚点 `#s08` | Copy 缓存 | Browser 点击定位 | DATA-DRIVEN |
| CAP-A07 | 外观切换（Light/Dark/System） | Theme tokens + localStorage `gwos-color-mode` · Site（默认外观可配） | 主题管理→默认外观/可用模式 | **Theme 核心** | 前台全站；错误页同源深色；不进 SEO 事实 | Theme 保存 → view/PageCache flush | Browser 三态 + SSR 防 FOUC；ThemeColorMode 测试 | ACCEPTED（TD-28） |
| CAP-A08 | 移动端菜单开关 / 汉堡 | 导航同 CAP-A03；aria-expanded/aria-label | — | Theme | Header 移动；无业务事实 | — | Browser 375/414；computer-use | ACCEPTED |
| CAP-A09 | 页脚 Slogan（品牌列） | `Copy::footerSlogan()`：Setting 覆盖 → config 缺省 · Site | 文案话术 `copy_footer_slogan` | Theme | Footer；不进 SEO | Copy/Setting flush | HTTP 文案跟随 | DATA-DRIVEN |
| CAP-A10 | 页脚事实块（成立/投产/地址/面积/产能/车间/区域/电话） | Catalog::company() + production · Site；**逐项 @if，缺失整行隐藏** | 对应实体/设置；无独立 UI 写死 | — | Footer factBlock；与 Schema/GEO 同源 | Entity/Setting flush | HTTP 空站全隐藏；Demo 显示 | DATA-DRIVEN |
| CAP-A11 | 页脚菜单列（产品/场景/关于/合作/联系） | Menu 系统（footerMenu）· Site；联系项 hotline/mobile/address 无值隐藏、QR 无图隐藏 | 菜单装修 + 联系方式 | Theme | Footer 列 | 菜单 forget | HTTP 空站空列；Browser | DATA-DRIVEN |
| CAP-A12 | 版权行（©年 + 站名） | 计算值：年 + Site.name；`copy.legal.copyright` 可覆盖 | 文案/法务 | — | Footer；不进 feed | Site/Copy flush | HTTP 改名跟随 | DATA-DRIVEN |
| CAP-A13 | ICP / 公安备案号与链接 | Setting `geo_icp` / `police_number` · Site；**无值不渲染**；链接固定政府备案站 | 站点设置→备案；号段校验 | — | Footer；政府站外链白名单 | Setting flush | HTTP 有/无两态；CopySettings 测试 | ACCEPTED |

---

## B. 首页 — 16 区块（home.blade 由 PageBlock 驱动）

> 主 `home.blade` 按 `PageBlock::forPage('home')->active()` 的 sort 动态 include `site/home.<type>`；**空站（无区块）渲染行业中性欢迎屏**（H1=Site.name 缺省 app.name、正文=site_description 或固定引导、CTA 仅当 company 非空）。每区块均"数据为空则整段不渲染"。

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 | 缓存失效 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-B01 | Hero（三模式 A 价值主张+参数卡 / B Banner 轮播 / C 图文） | Catalog 派生默认口径；Banner 表 home_top · Site；无图回退 A | 首页装修→Hero/Banner；整页唯一 H1 | Theme 全控样式 | 首页 H1/CTA；SEO（resolver）；Schema WebSite；OG | Block/Banner/Entity flush | HTTP 三模式 + 空站回退；Browser | ACCEPTED |
| CAP-B02 | 数据统计条 stats（数字 data-count） | `buildStats()` 已**过滤 num>0**，源自 company/facts · Site | 无（实体派生）；空则整段不输出 | Theme | 首页；数字不进 feed 事实 | Entity flush | HTTP 无数据不渲染；Browser 计数 | DATA-DRIVEN |
| CAP-B03 | 核心能力 capabilities（描述 + 能力点 + 「了解实力」） | site_description + 能力点（image/icon）；「→/factory/」仅当 hasProduction | 首页装修；能力点 | Theme | 首页；链接 factory（门控） | Entity/Block flush | HTTP hasProduction 两态 | DATA-DRIVEN |
| CAP-B04 | 产品系列 products（系列卡 / H3「N 款」） | productLines + 产品计数 · Site；链接按有无产品 | 产品线/实体；首页装修 | Theme 卡片 | 首页→products/line；计数 | Entity/Block flush | HTTP 空系列不渲染 | DATA-DRIVEN |
| CAP-B05 | 应用场景 scenes（条目） | 后台 Block 优先，缺省 HomeBlockDefaults；Catalog 场景 · Site | 首页装修 + 场景实体 | Theme | 首页→solutions | Entity/Block flush | HTTP 空则隐藏 | DATA-DRIVEN |
| CAP-B06 | 关键参数 params（paramRows + differentiators） | core 产品 key_params（≤6）· Site；空则不渲染 | 产品实体 metadata | Theme | 首页；参数不进 feed | Entity flush | HTTP 无参数不渲染 | DATA-DRIVEN |
| CAP-B07 | 生产车间 workshops（默认「N 处生产设施」） | workshopItems · Site；**count 门控**，无车间不显示 | 组织 production 实体 | Theme | 首页；制造口径但 count 门控 | Entity flush | HTTP 空站隐藏 | DATA-DRIVEN |
| CAP-B08 | 合作方式 cooperation（类型条目） | Block 后台优先 / 缺省；Catalog cooperation · Site | 首页装修 + 合作实体 | Theme | 首页→cooperation | Entity/Block flush | HTTP 空则隐藏 | DATA-DRIVEN |
| CAP-B09 | 合作步骤 steps（序号自动） | stepItems · Site | 首页装修 | Theme | 首页 | Block flush | HTTP 空则隐藏 | DATA-DRIVEN |
| CAP-B10 | 客户案例 cases（匿名、无客户名） | caseList（组织 metadata.cases）· Site；**匿名化** | 首页装修/案例 | Theme | 首页→/cases/；不含客户身份 | Block/Entity flush | HTTP 空则隐藏；污染扫描 | DATA-DRIVEN |
| CAP-B11 | 首页 FAQ（折叠，答案在初始 DOM） | homeFaqs（config pages.home_faqs）· Site；非空输出 **FAQPage schema** | 文案/首页装修 | Theme | 首页；**Schema FAQPage**；GEO；答案服务 SEO | Block/Copy flush | HTTP FAQ schema；Browser 折叠 | ACCEPTED |
| CAP-B12 | 知识推荐 knowledge（封面/分组/时间） | Block pickedIds 优先，否则按栏目最新 · Site；非空才渲染 | 首页装修选内容 | Theme | 首页→knowledge | Block/Content flush | HTTP 空则隐藏 | DATA-DRIVEN |
| CAP-B13 | 新闻动态 news（同上） | 同 B12（新闻类目） · Site | 首页装修 | Theme | 首页→news 栏目 | Block flush | HTTP 空则隐藏 | DATA-DRIVEN |
| CAP-B14 | 关键事实 facts（take(8)，剔除公司全称/品牌/官方电话三 key） | `Fact::publicRows` · Site；敏感 key 剔除 | 事实管理 | Theme | 首页事实；与 GEO 同源 | Fact/Entity flush | HTTP 剔除断言 | DATA-DRIVEN |
| CAP-B15 | 中部 Banner mid_banner | Banner home_mid · Site；**有图才渲染**，WebP 渐进，不写死尺寸 | Banner 管理 | Theme | 首页图文 | Banner flush | HTTP 无图不渲染 | DATA-DRIVEN |
| CAP-B16 | CTA 区（S08 锚点：左信息热线/地址/客户 + 右 lead form） | 左条件渲染（有热线/地址才显示）· Site；右全站统一表单 | 联系方式 + 表单文案 | Theme | 首页咨询；锚点；表单提交 | Setting/Block flush | HTTP 缺失信息隐藏；Browser 提交 | ACCEPTED |
| CAP-B17 | 空站中性欢迎屏（无任何区块时） | Site.name + site_description（缺 app.name/固定引导）；CTA 仅 company 非空 | 无（自动降级） | Theme | 首页；SEO 走 resolver | — | BlankSystem 测试 + HTTP | ACCEPTED（TD-10） |

---

## C. 产品中心（ProductController / Catalog Entity[product]）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 | 缓存失效 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-C01 | 产品总览 /products（按系列分 section / 空系列空态） | productLines + productsByLine · Site | 产品线/产品实体 | Theme | 产品列表；Catalog | Entity flush | HTTP 空站 404；Demo 分系列 | ACCEPTED |
| CAP-C02 | 产品详情 /products/{slug}（定位/用法 HowTo/场景/规格交付/相关/FAQ） | Catalog::product()（Entity[product] 投影）· Site；**各段 @if 门控** | **Entity 管理→产品**（非 Content）；Site+Type+Slug 唯一 | Theme 模板 | 产品页 200；**Schema Product/HowTo**；GEO；Sitemap；canonical | Entity saved → flush | HTTP core 200 / 非 core 404；17B 测试 | ACCEPTED（双轨收敛） |
| CAP-C03 | 规格表（包装/储存/起订量/有效期/净重槽位） | 产品 metadata（net_weight/packaging/shelf_life/storage/moq）· Site；**filter(filled)，不供即整行隐藏** | Entity 产品 metadata | Theme | 产品详情规格；实物商品通用槽位 | Entity flush | HTTP 无规格不渲染；Browser | DATA-DRIVEN（见 Hardcode R-06） |
| CAP-C04 | 产品系列页 /products/line/{slug} | Catalog::line + productsByLine · Site | 产品线管理 | Theme | 系列页；产品卡 | Entity flush | HTTP 空系列空态 | DATA-DRIVEN |
| CAP-C05 | 产品卡 _product_card（core→详情 / 非 core→系列锚点） | Catalog::isCoreProduct · Site；无图用中性 icon | — | Theme | 列表/首页卡片 | Entity flush | HTTP 链接分流；Browser | ACCEPTED |

---

## D. 应用场景（SolutionController / Catalog Entity[service]）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 | 缓存失效 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-D01 | 场景总览 /solutions（H1 + 场景卡） | Catalog::company() 门控 + scenes · Site；**H1=「你的业务属于哪一类？」** | 服务实体；文案 | Theme | 场景列表；**Schema ItemList** | Entity flush | HTTP 空站 404；中性 H1（TD-41） | ACCEPTED |
| CAP-D02 | 场景详情 /solutions/{slug}/（痛点/推荐组合/关键参数/流程/FAQ/相邻） | Catalog::scene()（Entity[service]，关系由 EntityRelation 派生）· Site；各段 @if | **Entity 管理→服务** + **关系管理** | Theme | 场景页 200；Schema；GEO；Sitemap | Entity/Relation flush | HTTP P0/P2 两态；SolutionPage 测试 | ACCEPTED |
| CAP-D03 | 场景卡 _scene_card（组合产品名 / hover 揭示，移动默认展开） | Catalog scene + product 解析 · Site | — | Theme | 总览卡片；揭示在初始 DOM | Entity flush | Browser 移动展开 | DATA-DRIVEN |

---

## E. 知识中心 / 内容 / 栏目（KnowledgeController / PageController / Content）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 | 缓存失效 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-E01 | 知识中心 /knowledge（子栏目 Tab + 分页，每页 12） | Group（knowledge channels）+ Content published · Site；空态文案 | 结构管理→内容分组（启停/改名） | Theme | 知识列表；子栏目；分页 | Group/Content flush | HTTP 空站可访问+空态 | ACCEPTED |
| CAP-E02 | 知识子栏目 /knowledge/{channel} | Group channel → Content · Site；非栏目转交 dispatch | 分组管理 | Theme | 子栏目列表 | Group flush | HTTP 停用/不存在分发 | ACCEPTED |
| CAP-E03 | 内容页（GEO 通用模板：结论/关键事实/解释/正文/证据/边界/FAQ） | Content（article/page）· Site；contact 类目放表单、有电话放 CTA band | **内容管理**（article/page，Product 已移出） | Theme | 内容页；Schema Article/WebPage；GEO；Sitemap | Content saved → flush | HTTP 各类型；17B Content 收敛 | ACCEPTED |
| CAP-E04 | 栏目页 /category（子栏目/分组筛选/分页/空态，product_list 富卡片） | Category（list/product_list/page/external 四类型）· Site；slug 站点唯一 | 栏目管理；type/外链校验 | Theme | 栏目列表；external 跳转 | Category flush | HTTP 四类型；TD-16① | ACCEPTED |
| CAP-E05 | 面包屑（全站） | PublicUrl + 当前资源 · Site | — | Theme | 面包屑导航；**Schema BreadcrumbList** | 资源 flush | HTTP 层级；Browser | ACCEPTED |

---

## F. 实体 / 关系（Entity / EntityRelation）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 | 缓存失效 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-F01 | Entity 六类型（organization/person/product/service/location/topic） | Entity 模型 · Site；类型白名单（禁 brand/solution/place） | **Entity 管理 CRUD**；Site+Type+Slug 唯一；metadata 按类型 | Theme | Catalog/前台；Schema；GEO；Sitemap | Entity saved/deleted → flush | HTTP CRUD/发布/下架；17B | ACCEPTED |
| CAP-F02 | 实体关系（Product uses Service 等） | **EntityRelation 权威边表** · Site；类型白名单/复合唯一/跨站禁止 | **关系管理**；源/目标仅本站、重复/自关系/反向契约 | Theme | **GEO /geo.json**；Catalog（18A 单向派生）；Schema 关系 | Relation saved/deleted → flush | HTTP 边生命周期；17C/18A | ACCEPTED（TD-04） |
| CAP-F03 | 关系生命周期（两端 Published→边出现 / 任一 Draft→消失） | EntityRelation + 发布态 · Site | 发布/下架 | — | GEO 边；Catalog 读模型 | 发布 → flush | HTTP 边出现/消失/恢复 | ACCEPTED（17C） |
| CAP-F04 | 删除实体级联关系（不删对端实体） | EntityRelation 外键级联 · Site | 删除确认 | — | GEO/ Catalog 同步 | delete → flush | HTTP 删实体边清、对端保留 | ACCEPTED |

---

## G. 表单 / 联系（Inquiry / _lead_form / Contact）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 | 缓存失效 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-G01 | 全站统一咨询表单（称呼/电话/客户类型/需求简述） | `Copy::form()` 文案 · Site；蜜罐 website；归因隐藏字段（landing/referer/utm*） | 文案话术；客户类型选项逐行 | Theme | 首页/联系/底部表单；POST inquiry.store | Copy flush | HTTP 提交/校验/重复锁；Browser | ACCEPTED |
| CAP-G02 | 前端校验（onBlur 必填 + 电话正则，提交锁按钮） | JS（novalidate，后端兜底）；电话 invalid 文案 **Copy::form() 已提供** | 文案 `copy_form_phone_invalid` | Theme | 表单即时反馈 | — | HTTP/正则；Browser 错误态 | ACCEPTED（NON-DEBT，见 R-04） |
| CAP-G03 | 联系页 /contact（联系项全数据驱动，缺失隐藏 + 表单） | Catalog::company() 联系方式 · Site；空站 404 | 联系方式 + 表单文案 | Theme | 联系页；Schema ContactPoint | Setting/Entity flush | HTTP 空站 404；Demo 显示 | ACCEPTED |
| CAP-G04 | 底部统一 CTA（标题/描述/主副按钮 + 电话行） | `Copy::bcta()` · Site；**电话行无号码不渲染（TD-40）** | 文案话术；factory 变体 | Theme | 多页底部 CTA；factory 唯一变体 | Copy flush | HTTP 空电话不渲染；FrontendBackendClosure | ACCEPTED（TD-40） |

---

## H. 公共 Feed / 机器输出（Geo/FeedController）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口·校验 | Locale·Theme | 消费者 | 缓存失效 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-H01 | Sitemap /sitemap.xml | **PublicIndex 准入**：Published + Site 可见 + 有效公开 URL + HTTP 200 + 可索引 | Sitemap enabled 门禁；栏目/实体发布 | 多语言待 18F | sitemap loc（PublicUrl）；不收录 404 | 资源/Setting flush | FeedPublicRenderContract；90 URL 对拍 | ACCEPTED（#86） |
| CAP-H02 | GEO 知识图 /geo.json | Entity + **EntityRelation** + Facts · Site；站点隔离 | 实体/关系/事实管理 | 多语言待 18F | GEO entities/edges；site 块 | Entity/Relation flush | HTTP 边/隔离；17C/18A | ACCEPTED |
| CAP-H03 | LLMS /llms.txt | PublicIndex 同 sitemap 准入 · Site | llms enabled 门禁 | 多语言待 18F | llms 资源列表 | Setting flush | HTTP 准入；FeedContract | ACCEPTED |
| CAP-H04 | RSS /feed.xml | PublicIndex + **geo_rss_enabled 独立门禁**（=0→404） · Site | RSS enabled（TD-20①） | 多语言待 18F | RSS 条目 | Setting flush | HTTP 门禁两态 | ACCEPTED |
| CAP-H05 | robots /robots.txt | Setting（sitemap/llms enabled 联动）· Site | 站点设置→Feed 开关 | — | robots 指令；Disallow | Setting flush | HTTP 联动；SitemapRobots | ACCEPTED |

---

## I. 错误页 / 异常（errors/）

| ID | 前台元素 | 数据源 (Model·Scope) | 后台入口 | Locale·Theme | 消费者 | 缓存 | 测试 | 状态 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| CAP-I01 | 404（代码/标题/描述/主副 CTA/恢复入口） | `Copy::error404()` + mainMenu 同源入口（tel/mailto/外链过滤）· Site；noindex | 文案话术 | Theme + 深色同源 | 404 页；不进 feed；恢复入口 | — | HTTP 不存在 URL；Browser | ACCEPTED |
| CAP-I02 | 403 / 500 / 503 | errors 模板；不泄漏堆栈/路径/debug | 友好 500 页 = TD-17（v1.1） | Theme + 深色 | 错误页 | — | HTTP 错误码；18D 深色覆盖 | PARTIAL（500 友好页 v1.1） |

---

## 对账结论

1. **前台能力 → 后台数据源**：除"系统常量 / 框架默认 / 语义 token"外，首页 16 区块、Header/Footer、产品/场景/知识/联系/表单、Feed 全部由 Site-scoped 正式模型（Site / Entity / EntityRelation / Content / Category / Menu / PageBlock / Setting）驱动，空数据自动隐藏或回落中性欢迎屏；**未发现"前台可见但后台无任何正式数据源"的业务能力**。
2. **后台字段 → 前台 consumer**：Settings 以 17F 64→65 键矩阵为准（4 RETIRE 有边界、无 consumer 不造 UI）；Entity/Relation/Content/SeoMeta/Menu/Block 字段在上述消费者列均有真实去向；无 consumer 的字段已在 17F 裁定 RETIRE/SYSTEM ONLY。
3. **本轮真实修复（3）**：TD-39 factory 部分事实裸输出 0、TD-40 底部 CTA 空电话、TD-41「你的店」→「你的业务」，均补防回归测试（FrontendBackendClosure18ETest，4 用例 / 16 assertions）。
4. **误判撤销（1）**：phone.invalid 一度疑缺失，核实 `Copy::form()` 组装层已兜底（不读 config 该键），判 NON-DEBT，已撤销对 config/copy.php 的多余改动。
5. **遗留正式 DEFERRED（不阻塞 v1.0 核心）**：多语言（18F）、Page/Block/Template 组合（18G）、搜索召回 Entity / 表单深度 / Analytics / RBAC 三角色 / 友好 500 / i18n 等（18H–18J 裁定，见 Registry §7）。
6. **Release Gate**：v1.0 Required 未闭合仍为 **3 项 P0 外部发布工程（TD-01 Cloud CI / TD-02 重建 RC / TD-03 Private→Public）**；18E 未新增发布阻塞。

> 配套：写死项逐条见 `hardcoded-capability-register.md`；债务状态以 `technical-debt-registry.md` 为唯一事实源。
