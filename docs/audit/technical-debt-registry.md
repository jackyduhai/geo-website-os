# GEO Website OS — Technical Debt & Release Gate Registry

- **定位**：本文件是 GEO Website OS **唯一**的技术债 / 架构债 / 产品化债 / Release Gate 登记与销项台账。所有阶段（P-STEP / 17x / 18x）的 Gate 对账以本文件为准；其他审计文档（product-uat-final、settings-inventory-17f、runtime-architecture-closure、admin-control-plane-final-acceptance、admin-management-completion-design 等）只作为**来源证据**，不再各自维护债务清单。
- **建立时基线**：HEAD `4dbc95a`（= annotated tag `checkpoint-18A`）；Regression **801 tests / 3948 assertions / 0 failed / 0 skipped**；`v1.0.0-rc1` 冻结于 `965d63c`（HOLD）；无 remote、未 push、未发布。
- **阶段口径修正**：P-STEP 17 中 **17A–17F = 六大管理面**（Site / Entity / EntityRelation / SeoMeta / Theme·Plugin / Settings）；**17G = 六大管理面的系统级 Full Admin UAT**，不是第七个管理面。
- **最后更新**：P-STEP 18F **Gate Validation** 后（annotated tag `checkpoint-18F`；Regression **872 passed / 4655 assertions / 0 failed / 0 skipped**）。对切换设备前会话产出的实现 commit `d886d5d` 做独立验收（流程异常已记录），新发现并修复 **TD-51**（英文页内部链接缺 /en）、**TD-52**（英文页可见/属性 UI 中文残留），en 页渲染 visible/属性 CJK=0，均 CLOSED（见 §5）。**v1.0 Required 未闭合仍为 3 项 P0 外部工程依赖（TD-01/02/03）**——18F 未新增发布阻塞。
- **P-STEP 18G-1（Page Composition / Template System）启动**：18G Discovery 已 ACCEPTED，登记父 Epic **#116** 与 **TD-53..TD-59**（Block / Template / Page Registry + Detail·Listing Composition + Composition Admin + Form Block）；18G-1 建立三层并完成 Landing 闭环，18G-2 迁移系统页。

---

## 1. 字段定义

| 字段 | 含义 |
| --- | --- |
| **ID** | 债务编号。可验收子项用 `TD-NN`；跨多子项的父级 Epic 用项目 issue 号 `#NNN`。编号一经分配不复用、不重排。 |
| **Priority** | P0 Release 工程 / P1 架构与数据一致性 / P2 通用化与产品化 / P3 后台与体验 / P4 观察与测试限制。 |
| **Title** | 一句话问题陈述。 |
| **Source** | 首次发现/登记的阶段与审计文档（可追溯证据）。 |
| **Current Status** | 见 §2 状态机。 |
| **Acceptance Criteria** | 可判定销项的具体、可验证条件（测试 / HTTP / 数据 / 文件证据）。 |
| **Blocks v1.0.0?** | `YES` = 公开发布前必须闭合；`DECISION` = 发布前必须做出并记录裁定（不一定补实现）；`NO` = 规划 v1.1+。当前为**建议基线，最终以 Release Gate 裁决为准**。 |
| **Parent / Related** | 父级 Epic 与关联项，体现父子结构，避免重复登记。 |

---

## 2. 状态机（销项走流程，不允许直接删条目）

```
ACTIVE  →  FIXED  →  TESTED  →  ACCEPTED  →  CLOSED
   │
   ├─ PARTIAL      （父项的部分子项已闭合，剩余子项仍 ACTIVE）
   ├─ DEFERRED     （明确裁定到 v1.1+，记录理由与验收条件）
   ├─ WONTFIX      （明确不做，记录理由）
   └─ NON-DEBT     （经核实不属于债，移入 §6 白名单并说明）
```

- **不得为了"看起来债务少"而合并或删除条目**。大项（如 TD-10）可拆多个子项，但保留母项编号。
- 每次阶段 Gate 必须更新本台账：变更 Status、补证据（commit/tag/测试数/HTTP），并在 §8 Changelog 记录。
- CLOSED 项移入 §5 归档区保留销项历史，不从台账抹除。

---

## 3. 父级 Epic（跨子项的架构 / 产品化事项）

| Epic | 范围 | Current Status | Blocks v1.0.0? |
| --- | --- | --- | --- |
| **#86** Public Render Contract / Feed 泄漏 | 任何进入 Sitemap/GEO/LLMS/RSS/Search 的公开资源必须 Published + 当前 Site 可见 + Canonical 有效 + 前台 HTTP 200 | **CLOSED by 17G**（PublicIndex/PublicUrl 七大输出改派，90 URL×3 站全 200） | — |
| **#114** Catalog Read Model / Entity Public Model | 后台生产模型 → Catalog 读模型 → 前台 / Schema / GEO / Sitemap / Search 的权威链路与公开 URL 体系 | **CLOSED for v1.0 by 18A+18C**：关系权威源 TD-04 CLOSED（18A）；TD-05 URL 体系冻结、TD-09 @id/PublicUrl 统一 CLOSED（18C）；TD-06 `/article/` 收敛书面 DEFERRED v1.1（301 桥接已锁定） | —（v1.0 收口；TD-06 转 v1.1） |
| **#115** Default Template Neutralization | 出厂为行业中性空站，系统默认层与 Example Demo（工业材料）彻底分离 | **CLOSED by 18B**（TD-10/11/12/13 + TD-25/26；Blank System ≠ Demo Site 两态 HTTP 对拍） | — |
| **#116** Page Composition / Template System | 把"仅首页可装修"升级为"任意页面可组合"：Block Registry + Template Registry + Page 模型 + Landing 闭环 + Page Composition Manager；18G-2 迁移系统页 | **ACTIVE（P-STEP 18G-1 实现中）** | **YES**（v1.0 产品完整性：Page Composition / Template System） |

---

## 4. 主台账（ACTIVE / PARTIAL / DEFERRED）

### P0 — Release Engineering（发布硬门槛）

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
| **TD-01** | GitHub Actions 云端 Runner 真实首跑未执行 | P13/P16；CI 配置本地已就绪（965d63c） | ACTIVE | Push 后云端 PHP 8.4 流水线真实全绿：composer validate/audit/check-platform-reqs → install → geo:install → 全量测试 → HTTP smoke → artifact → SHA-256 | **YES** | TD-03 |
| **TD-02** | Release Candidate 须基于最终 HEAD 重建 | P15 后历史重写致旧 hash 失效；当前 rc1=965d63c 已落后 | ACTIVE（HOLD） | 代码冻结后：新 RC commit → annotated tag → CI GITHUB_SHA 生成 release-manifest → ZIP → SHA-256，provenance 链清晰且 tag target 一致 | **YES** | TD-01, TD-03 |
| **TD-03** | Private push → 观察 → 转 Public / v1.0.0 未授权、未执行 | P15/P16；空 Private 仓已建（jackyduhai/geo-website-os），未配 remote | ACTIVE（等待外部授权） | 新仓作为全新 source of truth（不与旧远程合并）；先 Private 全验证（Fresh Clone/Secret Scan/CI/Artifact）通过，再由用户决定转 Public | **YES** | TD-01, TD-02 |

### P1 — 架构与数据一致性

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
| **TD-04** | 关系读模型双轨：前台 Catalog 读 `Entity.metadata` slug 数组，而非权威边表 EntityRelation | 17C 发现；17G §5 登记 | **CLOSED by 18A**（见 §5） | — | — | #114 |
| **TD-05** | Entity 六类型公开 URL 体系未冻结 | 17G §5；现仅 core 产品 `/products`、场景服务 `/solutions` 有页，org/person/location/topic/非 core 产品 url=null | **CLOSED by 18C（DECISION 冻结）**（见 §5） | — | — | #114, TD-09 |
| **TD-06** | Content 路径双轨：无 `/article/`，旧路径靠 301 桥接 | 17G §5 | **DEFERRED v1.1（18C 书面裁定）** | 维持 `Content::path()`=`/{栏目路径}/{slug}`，不引入 /article/；301 桥接测试锁定；schemaType 仅 page→WebPage/default→Article | NO（v1.1 收敛） | #114 |
| **TD-07** | Organization 双载体：organization Entity 与 `sites.metadata.organization` 并存，SchemaBuilder 读后者 | P14 遗留；18A 附录 G | **CLOSED by 18C**（见 §5） | — | — | #114, TD-10 |
| **TD-08** | PageCache 整页缓存失效模型不完整 | 18A 实测发现并部分修复 | **CLOSED by 18A+18C**（08a/08b 均闭合，见 §5） | — | — | — |
| └ TD-08a | EntityRelation 写入失效整页缓存 | 18A | **CLOSED by 18A**（模型 saved/deleted → PageCache::flush） | — | — | TD-08 |
| └ TD-08b | Entity / Site / SeoMeta / Content 写入后整页 HTML 失效未挂接 | 18A 附录 G | **CLOSED by 18C**（见 §5） | — | — | TD-08 |
| **TD-09** | Product 自身 `@id` 仍用 `url()` helper；Catalog 早期路径缺 `Schema::hasTable` 守卫 | 17G（manufacturer.@id 已改 PublicUrl，product 自身未改） | **CLOSED by 18C（#143）**（见 §5） | — | — | #114, TD-05 |
| **TD-53** | 无通用 Block Registry：block 类型 / 字段 / 渲染器散落，首页 16 section 与通用 block 未统一 | 18G Discovery（page-composition-discovery-18g） | ACTIVE（18G-1） | BlockRegistry + BlockType/Definition（type/label/category/fields schema/data source/renderer view/allowed slots/per-locale/cacheable）；16 Core Block 全部注册并有渲染器；`if type==` 不散落 Controller/Blade；测试覆盖 | **YES** | #116 |
| **TD-54** | 无 Template 模型 / 注册表 / 选择器，"模板"即 Blade、由 Controller 硬编码 | 18G Discovery | ACTIVE（18G-1） | TemplateRegistry + TemplateDefinition + Slot（继承 base）；Base→Home/Listing/Detail(Article·Product·Service)/Contact/Landing；槽位声明允许 block、不存内容；模板可在 Admin 选择 | **YES** | #116, TD-53 |
| **TD-55** | 无 Page 模型、Landing Page 完全缺失（"单页"只能 Category(type=page)+Content 正文驱动、非组合） | 18G Discovery | ACTIVE（18G-1） | pages 表 + Page 模型（site/template/slug/status/locale/translation_group，**不存业务事实**）；Landing 全流程：新建→选模板→加 block→排序/隐藏→preview→publish→前台 200，不改 PHP/Blade/JS/CSS；draft 404 | **YES** | #116, TD-53, TD-54 |

### P2 — 通用化与产品化（#115）

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
> **#115 全部子项（TD-10 / TD-11 / TD-12 / TD-13）已由 P-STEP 18B 销项 CLOSED**，证据见 §5 与 `docs/audit/default-template-neutralization-18b.md`。出厂为行业中性空站（Blank System）；工业材料制造 Example 仅经 `db:seed` 可选装载（Demo Site）。两态经 Fresh Install + 真实 HTTP/浏览器对拍，验证 **Blank System ≠ Demo Site**。

| **TD-56** | Detail 页面（Article/Product/Service）结构写死，未迁入 Template+Block 组合 | 18G Discovery | ACTIVE（18G-2） | Article/Product/Service 统一 Detail Template + blocks，读 Entity/Content/Media/Relation/SEO，**不把业务内容复制进 Page**；模板默认 + 页面 block 覆盖；不为每个产品做独立模板 | **YES** | #116, TD-53, TD-54 |
| **TD-57** | Listing 页面（Products/Solutions/Knowledge/Content 列表）结构写死 | 18G Discovery | ACTIVE（18G-2） | Listing Template + blocks（header/grid/sidebar/pagination）；数据 site+locale+published 过滤；结构不写死 | **YES** | #116, TD-53, TD-54 |
| **TD-58** | 后台仅"首页装修器"，无 Page Composition Manager；BlockController 仅 index/update、无 create/store/destroy | 18G Discovery | ACTIVE（18G-1 PARTIAL→18G-2） | Page CRUD + block 编排（add/edit/move up/down/hide/duplicate/delete/preview/publish）；动态 block 编辑器（按 registry fields）；非自由拖拽；18G-1 覆盖 Landing/Page，18G-2 覆盖系统页 | **YES** | #116, TD-53..TD-55 |

### P3 — 后台与体验

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
| **TD-14** | RBAC 仅两级（super admin / admin），蓝图 §12.2 要求三角色 + 站点成员 | 蓝图 admin-management-completion-design §12.2 | ACTIVE | 角色模型（如 owner/admin/editor）+ 站点成员关系 + 越权测试；普通管理员不可跨站 | NO（v1.1；单组织开源 v1 两级可接受） | — |
| **TD-15** | 校验 i18n 缺失 + 无字段级 `@error` | 16A；Laravel 校验文案为英文，仅顶部汇总 | ACTIVE | zh-CN 校验语言包；关键字段字段级错误展示；不削弱后端校验 | NO（v1.1） | — |
| **TD-16** | Category 多项不规范：slug unique 未按 site_id、type 枚举漂移、外链接线、栏目 seo_title/seo_desc 疑似第三套 SEO | 17F/17G 遗留 | **PARTIAL：① CLOSED by 18C；②③④ DEFERRED v1.1** | ① slug 站点作用域唯一 + type 四类型常量/访问器收敛（18C 完成，AdminCategoryCrud +4 测试）；② 外链接线、③ 栏目 SEO 归并 v1.1 | ① 已闭合；②③④ NO（v1.1） | SeoMeta(17D) |
| **TD-17** | 缺后台友好 500 错误页 | 16A/17F | ACTIVE | 后台/前台异常展示友好错误页，绝不泄漏堆栈/路径；有模拟 500 的验证 | NO（v1.1；安全上已不泄漏堆栈） | — |
| **TD-18** | Theme/Plugin 上传安装 / SDK / 应用市场缺失 | 蓝图明确划出 v1.x | ACTIVE（规划中） | 蓝图定义的上传安装、SDK 规范、市场能力（按蓝图里程碑） | NO（v1.x） | Theme/Plugin(17E) |
| **TD-19** | GEOFlow 等旧概念后台 IA 命名未清理 | 16A F2；token 前缀 `yhf_` 已在 17F 中性化 | PARTIAL | token 前缀已中性化（DONE）；后台菜单/术语重命名为 Site/Content/Entity/Relation/SEO/GEO/Theme/Plugin/Settings 体系 | NO（v1.1） | — |
| **TD-20** | md-editor 内联上传未测；RSS 未接独立 enabled 门禁；搜索不召回 Entity 且英文召回弱 | 16A/17F | **PARTIAL：① CLOSED by 18C；②③④ DEFERRED v1.1** | ① RSS `geo_rss_enabled` 独立门禁（默认开，=0 时 /feed.xml 404），设置 64→65（18C 完成）；② md-editor 上传、③ 搜索召回 Entity、④ 英文召回 v1.1 | ① 已闭合；②③④ NO（v1.1） | #86, #114 |
| **TD-36** | 全站无统一组件 loading / `aria-busy` 模式（当前以整页 POST 刷新为主） | 18D-07 | DEFERRED v1.1 | 建立统一 loading 组件、aria-busy 与提交/加载反馈；不影响当前整页刷新可用性 | NO（v1.1） | TD-32 |
| **TD-37** | 产品列表系列卡在窄屏两列、每卡约 165px 偏密 | 18D-09 | DEFERRED v1.1 | 窄屏单列或密度/间距优化；当前不横向溢出、信息可读 | NO（v1.1） | TD-33 |
| **TD-38** | example 极简主题（31 行、零设计系统）未对齐深色/响应式体系 | 18D | DEFERRED v1.1（说明项） | example 为"零引擎依赖"极简示范主题；完整设计系统在 default。评估是否补齐或在主题文档标注能力边界 | NO（v1.1） | TD-28 |
| **TD-46** | factory / cooperation Core 路由与 IA 命名制造业特定：URL factory、概念 Factory & Certifications / workshops / annual capacity in Tons | 18F（en-only B 对拍登记） | **DEFERRED v1.1** | 数据驱动可见：无 production / facility 数据的站点 FactoryController 实质 404、sitemap/feed 不输出 URL，非制造业不暴露；重命名 factory→facilities、单位 Tons 中性化涉及路由 / sitemap / 翻译键，需独立 IA 阶段。验收：非制造业 Core 默认不出现 factory 概念，或路由 / 文案中性（Facilities & Certifications） | NO（v1.1；数据驱动 404 已保证不串行业） | TD-19 |
| **TD-59** | 无可被页面组合引用的 Form block；Inquiry 表单固定、无法作为 block 嵌入 Landing | 18G Discovery；用户裁定 | ACTIVE（18G-1 最小 FormReference；完整 Form Builder→18H） | 18G：FormReference block 引用现有 Inquiry 表单（title/desc 可配）、提交可用；18H：完整 Form Builder（fields/validation/notification/spam/consent） | **YES**（18G 最小 + 18H 完整） | #116, TD-55 |

### P4 — 观察与测试限制（默认 NON-BLOCKING，记录在案）

| ID | Title | Source | Status | 说明 / 验收 | Blocks v1.0.0? |
| --- | --- | --- | --- | --- | --- |
| **TD-21** | 尾斜杠 301 在 Feature 测试层无法复现（CanonicalizeSlash runningUnitTests skip，客户端剥尾斜杠） | 多阶段 | NON-DEBT（测试限制） | 真实 HTTP（serve/curl）已验证 301；保留为已知测试框架限制，若未来引入 HTTP 级集成测试再覆盖 | NO |
| **TD-22** | release archive export-ignore docs 后，个别守护测试仅在完整 checkout 成立 | 15/16 | NON-DEBT（打包限制） | 发布包测试矩阵中注明"完整 checkout vs release archive"差异，CI 跑完整 checkout | NO |
| **TD-23** | PHPUnit doc-comment metadata deprecation | 多阶段 | DEFERRED（v1.1） | 升级 PHPUnit 12 时改用 attribute 语法 | NO |
| **TD-24** | PageCache file store 在 `cache:clear` 后磁盘回收不即时 | 18A 观察 | DEFERRED | 功能不影响正确性（flush 逻辑生效）；磁盘回收策略优化延后 | NO |
| **TD-27** | `GeoflowApiTest` 测试顺序依赖：自定义分批顺序下 `insert facts site_id=2` FK failed（RefreshDatabase 同进程 autoincrement / 静态 SiteContext memo 与测试 site id 假设叠加） | 18C 分批回归时发现 | DEFERRED（测试隔离） | 单独跑 12 passed、全量固定顺序 826 全绿，非产品 Runtime bug、CI 固定顺序不受影响；v1.1 加固夹具（显式取 site id / 每类重置 memo） | NO |

---

## 5. 已 CLOSED 归档（销项历史，禁止删除）

| ID / Epic | Title | Closed By | 销项证据 |
| --- | --- | --- | --- |
| **#86** | Public Render Contract / Feed 泄漏（DB 有 / Feed 有 / 前台 404） | **P-STEP 17G** | 新增 PublicUrl + PublicIndex，七大输出（Schema/GEO/Sitemap/LLMS/RSS/Search/Canonical）统一准入：Published + 当前 Site 可见 + 有效公开 URL + HTTP 200 + 可索引；90 URL×3 类站点对拍全 200，NON-200=0 |
| **TD-04** | 关系读模型双轨（Catalog 读 metadata slug 数组而非 EntityRelation） | **P-STEP 18A**（commit `4dbc95a` / tag `checkpoint-18A`） | Catalog 产品场景/相关、场景 combo/相邻/关键参数改为**单向**从 EntityRelation 派生，与 /geo.json 同源，禁止双向反写；CatalogSeeder 对齐后 58 边；新增 CatalogRelationAuthorityTest 12 测试；全量 801/3948/0/0；uses 有向不对称、跨站隔离、Draft 排除、删边均有测试 |
| **TD-08a** | EntityRelation 写入后旧整页缓存不失效 | **P-STEP 18A** | EntityRelation 模型 saved/deleted → PageCache::flush()（模型层覆盖后台/tinker/import 全写入路径）；test_new_manual_relation_edge_reaches_frontend_and_geo_graph 锁定缓存失效契约 |
| **P-STEP 17 P0** | 六大管理面（Site/Entity/EntityRelation/SeoMeta/Theme·Plugin/Settings）无 Admin UI | **17A–17F** | 逐阶段 Gate PASS（68e2b03 / 0d6872b / d839d5a / 220947f / 3348c92 / f783af6） |
| **P-STEP 17G** | 六大管理面系统级 Full Admin UAT + 产品双轨收口 | **17G**（200b5ae，789/3889） | Content 收敛 article/page、Product 成为正式 Entity；中间件顺序修正；4 类真实问题修复 |
| **（17F 子项）** | GEOFlow token 前缀 `yhf_` 中性化 | **17F**（f783af6） | token 前缀改产品中性；IA 重命名余项见 TD-19 |
| **TD-10** | 默认模板 / Copy / IA 行业垂直痕迹 | **P-STEP 18B**（tag `checkpoint-18B`） | geo:install 出厂层 BlankHomepageSeeder 清空历史 migration 播种的 16 个制造区块；config/copy.php、config/pages.php、HomeController、五个前台控制器、home/* blade、产品后缀、询价、copyright 全面行业中性；空站首页中性欢迎屏、导航/footer 经 Catalog 门控（无工厂/合作/案例列）；制造 copy 仅以 slug 键控 Example 包（product_faqs/scene_faqs）保留，通用站零运行时命中；两态 HTTP 对拍 |
| **TD-11** | 默认 Menu / Blocks 未按站初始化 | **P-STEP 18B** | 制造区块只在 `db:seed` 由 Demo StructureSeeder 按站重建；geo:install 不播任何区块（空站 page_blocks=0）；菜单/区块全部 site-scoped + Catalog/Group 数据驱动，空站导航仅知识中心，A/B 不串；BlankSystemDemoSeparationTest 锁定 |
| **TD-12** | `Site.name` 与 setting `site_name` 双源 | **P-STEP 18B** | Site.name 成为唯一权威：Site booted `saved` 单向镜像 site_name；GeoInstall 默认站名收敛为 app.name/--site-name 并 save；DefaultSettingSeeder site_name 跟随 Site.name（不再硬编码 app.name 覆盖自定义名）；SettingController general 回写 Site.name；真实 HTTP 后台改名后 title/OG/footer(9 处)/geo.json/RSS 四端同源跟随，DB 双源一致 |
| **TD-13** | 27 个制造/化工垂直内置图标 | **P-STEP 18B** | 全站收敛为单一通用 SVG 图标库 `site/_icon.blade.php`（通用名 registry）+ config/icons.php 中性 label registry，数据驱动、无 slug→垂直图标硬编码；出厂图标序列中性，垂直语义仅随 Demo 数据出现 |
| **TD-25** | PageCache 整页缓存键只用 `getHost()`（不含端口），同主机异端口多实例（本地并排 / 同机非标端口反代）命中同一 shell，正文与 canonical 串站 | **P-STEP 18B**（两态 HTTP 实测发现） | keyFor 改 `getHttpHost()`（含端口；标准 80/443 行为不变，生产按域名分区不受影响）；新增 test_cache_key_distinguishes_same_host_different_port（同主机异端口键不同 / 同 origin UTM 共享 / 异域名分区）；修复后 blank 8096 与 demo 8097 首页 HIT 互不串 |
| **TD-26** | SQLite 下 `Schema::getTableListing()` 返回 `main.<table>` 限定名，SiteController 删除保护动态表白名单整体失配，空站（含 settings 镜像行）被误判有业务数据无法删除 | **P-STEP 18B**（空站删除复现发现） | resourceCounts 循环开头 `Str::afterLast($listed,'.')` 去除 schema 前缀；settings 列入 CONFIG_TABLES 并在事务内随空站删除后 Setting::flush()；空站可正常删除、有数据站点仍受保护 |
| **#114** | Catalog Read Model / Entity Public Model 权威链路与公开 URL 体系 | **P-STEP 18A + 18C**（tag `checkpoint-18C`） | 关系权威源 TD-04（18A）、Entity URL 冻结 TD-05、Schema @id/PublicUrl TD-09（18C）均 CLOSED；Catalog 单向从 EntityRelation 派生；TD-06 `/article/` 收敛书面 DEFERRED v1.1（301 桥接锁定）。v1.0 权威链路收口 |
| **TD-05** | Entity 六类型公开 URL 体系未冻结 | **P-STEP 18C（DECISION）** | 书面契约 + ReleaseResidual18CTest 锁定：core product→`/products/{slug}` 无斜杠；有场景 service→`/solutions/{slug}/` 带斜杠（301 契约）；organization/person/location/topic/非 core product/无场景 service→url=null 且不进 feed；v1.0 不补 org/person/location 详情页。实测 core 200 / 非 core 404 / 单数 /product/ 404 |
| **TD-07** | Organization 双载体（organization Entity vs sites.metadata.organization） | **P-STEP 18C** | 裁定 **Site 聚合（Site.name + geo_org_* Setting + Site.metadata.organization）为主体组织唯一事实源**，organization Entity 仅为挂边目录节点；GeoGraph site 块 organization 锚点 @id=`home()#organization` 对齐 SchemaBuilder，主体 org 节点 `is_site_organization` 输出 same_as 且节点 id 不变；facts 仅 seeder 消费、geo:install 不装载，前台 Runtime 零 `Facts::`（grep 证实） |
| **TD-08 / TD-08b** | Entity/Site/SeoMeta/Content 写入后整页 HTML 失效未挂接；Site 改名同进程 stale memo | **P-STEP 18C**（08a=18A） | Entity booted saved/deleted → PageCache::flush；Site saved 独立 try/catch flush 并在 currentSite 为本站时 setSite 刷新 memo、deleted flush；改名连带 Setting 镜像 +2 幂等（断言 ≥+1）；Entity 写入只失效本站；4 个 feed 端点不命中整页缓存；缓存批 90 tests 绿 |
| **TD-09 / #143** | 声明性绝对 URL 用 `url()` 随请求 origin，与 PublicUrl 按站点 domain 裁决形成 host/scheme 分叉；Catalog 缺表守卫 | **P-STEP 18C** | 新增 PublicUrl::url()；canonical/og:url/og:image/JSON-LD @id/url/item/image、sitemap loc、llms、robots、rss、crumbs 全部改派 PublicUrl；功能性同源 URL（导航/卡片/表单 action/favicon/重定向/后台 label）显式保留 url()/asset()；sitemap 首页 loc=base()（无斜杠）与首页 canonical=home()（带斜杠）契约分离并注释；SitemapRobotsTest host 预期改 example.com（强化排除断言）；Catalog 缺 entities 表安全降级；ReleaseResidual18CTest 锁定所有 ld+json 无 localhost |
| **#144** | Release Gate 单一事实源反向审计 | **P-STEP 18C** | 倒推 Fresh→Admin→Entity/Relation/SEO→Theme/Plugin/Settings→Frontend→Schema/GEO/Feed→Cache→Multi-Site→CLI，未发现新双源；组织/关系/公开 URL/SEO/站点名/feed 准入各有唯一事实源 |
| **TD-16①** | Category slug 未按 site_id 唯一、type 枚举漂移 | **P-STEP 18C** | Category 冻结 list/product_list/page/external 常量+访问器（single=page、product=product_list 只读别名）；slug/parent Rule::unique/exists 带 where site_id、type Rule::in、external 强制 external_url；PageController/CanonicalizeSlash/SitemapBuilder/blade 统一访问器；AdminCategoryCrud +4 测试（②③④ v1.1） |
| **TD-20①** | RSS 无独立 enabled 门禁 | **P-STEP 18C** | FeedController::rss 读 geo_rss_enabled，=0 时 404，与 sitemap/llms 同标准；DefaultSettingSeeder 默认 '1'，设置 64→65，fresh geo:install settings=65 实测；空站/Demo /feed.xml 均 200（②③④ v1.1） |
| **TD-28** | 前台无 Light/Dark/System 深色模式 | **P-STEP 18D**（`862ff1d`） | ThemePalette darkOverrides 独立深色语义令牌 + lightenForContrast 向白提亮至 AA；SSR `<html data-color-scheme>` 防 FOUC、localStorage 记忆（gwos-color-mode）；外观切换器 light/dark/system；12 正常页 + 404/403/500/503 同源深色；新增 ThemeColorModeTest 10 用例、ThemePalette dark 测试 |
| **TD-29** | 行业视觉预设不足（蓝图要求 8 类） | **P-STEP 18D** | 新增 finance / healthcare，commerce label 承载 Consumer，共 8 预设；预设只改视觉 token、不改 IA 不注入行业数据；契约测试断言 8 预设齐全、primary_dark 恒空、tokens 仅白名单键（6 passed/60 assertions） |
| **TD-30** | 字号未 token 化（200+ 处散落 px） | **P-STEP 18D** | :root 建立 12 档 rem `--fs-*`；结构性标题/正文 27 处消费 token、组件辅助文字归并 82 处；缝隙 px 与 @media 收缩值按契约保留（见 design-system §4.3） |
| **TD-31** | 组件/页面存在彩色孤立硬编码色 | **P-STEP 18D** | Global Visual Refactor：彩色 hex=0、彩色 rgba=0；仅保留语义反白 #fff、mask 技术常量 #000、阴影/遮罩中性 rgba；深色逐页验证 |
| **TD-32** | 焦点环不统一、部分按钮缺 disabled | **P-STEP 18D** | 全局 `:focus-visible`（键盘 only）2px brand 环，覆盖链接/按钮/卡片/summary/分页；表单控件专门 :focus；补 ghost/text disabled；键盘 Tab 实测 activeElement 环 = var(--brand) |
| **TD-33** | 移动产品详情 H1 `.ph-h` 不缩小、长型号裁切 | **P-STEP 18D** | 三层排查（缺 brace / PageCache 旧快照 / CSS 同特异性源顺序）；基础规则加 overflow-wrap:anywhere，媒体缩小块移至基础规则之后（@media600 26px）；headless 重截标题完整不裁切 |
| **TD-34** | 必填字段仅视觉 `*`、屏幕阅读器无必填语义 | **P-STEP 18D** | _lead_form 三个必填字段（name/phone/type）补 `aria-required="true"`；label for/id 关联、autocomplete、错误/成功语义原有 |
| **TD-35** | geo:upgrade 部署新代码后不清旧编译视图与整页缓存 | **P-STEP 18D** | GeoUpgrade 迁移验证后加 view:clear + PageCache::flush；php -l 通过、upgrade focused 6 passed/26 assertions，升级契约不破坏 |
| **TD-39** | 工厂页对缺失生产事实裸输出 0：H1「自有约 0 ㎡…年产能约 0 吨」、数据条渲染 0 值项、SEO 拼出空 / 0 片段（Demo 三项齐全故未暴露） | **P-STEP 18E**（FactoryController + factory.blade） | H1 改为按真实事实（area / workshops / capacity）逐项拼接、缺失不写入；stats 过滤 `num>0`；SEO title/description 按数据拼接。新增 FrontendBackendClosure18ETest 锁定（部分生产站点无 0 ㎡ / 年产能约 0 / 空厂区标题） |
| **TD-40** | 底部统一 CTA「或直接致电」行无电话门控，空站（/knowledge/ 可访问）渲染空号码行 | **P-STEP 18E**（_bottom_cta） | 电话行以 `@if(!empty($bcPhone))` 包裹，未配置电话整行不渲染；test_bottom_cta_hides_phone_row_when_no_phone 锁定 |
| **TD-41** | 应用场景总览 H1「你的**店**属于哪一类？」零售 / 餐饮口径 | **P-STEP 18E**（solutions/index） | 中性化为「你的**业务**属于哪一类？」；test_solutions_index_uses_neutral_business_wording 锁定 |
| **TD-42** | Catalog 硬依赖 zh-CN organization：en-only 站点无 zh-CN 主体行时 relationMap() 传 null，非 nullable 签名在进方法体前 TypeError，首页 / sitemap / geo 全 500 | **P-STEP 18F** | buildDataset 新增关系权威基础语言 baseLocale：默认 zh-CN，站点无该语言主体时回退 site_default_locale（前提该语言主体存在），关系 base 行查询改用 baseLocale；relationMap 首参改 ?Entity（方法体本有 ! zhOrganization 守卫，nullable 后可触达）。en-only 站 B 不再 500 |
| **TD-43** | SchemaBuilder 服务区域 area_served 三元 true 分支（非默认语言）直接访问无 ??，en-only 站点无 production 时 Undefined array key 500 | **P-STEP 18F** | 改 (array) (area_served ?? [])，与 else 分支一致；en-only B 首页 200 |
| **TD-44** | 首页 blank 兜底分支（无 page_blocks）<p> 直接用单语 site_description，en-only / 跨语言站点显示另一语言（中文）描述 | **P-STEP 18F** | blank 描述优先当前语言组织摘要 Catalog::company()['summary']（站点隔离 + locale-aware），其次 site_description，最后 ui.blank_home_lead；B en 首页显示英文自身描述 |
| **TD-45** | 默认 SEO 翻译键带行业特定：knowledge「Selection, Process & Construction / materials / production」、products「Mixing Parameters / Process Parameters / Application Process」 | **P-STEP 18F** | lang/{en,zh-CN}/seo.php 中性化：knowledge_index_title=Knowledge Center / 知识中心、knowledge_desc 通用引导；product line/show/howto 去掉 mixing/process/application 工业措辞（Product Series / Specifications / Product Overview）。factory IA / 单位余项见 TD-46 |
| **TD-47** | routes/web.php 顶层函数 PublicUrlLocalized() 在同一进程路由文件被重复加载（多测试 / 路由重载）时 Cannot redeclare fatal | **P-STEP 18F** | 函数声明以 if (!function_exists('PublicUrlLocalized')) 守卫包裹；全量测试进程不再 fatal |
| **TD-48** | 站内搜索只覆盖 Content（contentQuery），不搜索 Entity，蓝图 §23 要求 V1 统一搜索 Content/Entity/Product/Service | **P-STEP 18F** | 新增 SearchResult 值对象，SearchController 合并 contentQuery + entityQuery（仅纳入 PublicUrl::entity() 有公开落地页的产品 / 场景），LengthAwarePaginator 手动分页，查询层满足 Public Render Contract；双语 / en-only 对拍通过 |
| **TD-49** | geo.json 默认 JSON 编码把中文转义为 `\uXXXX`、URL 斜杠转义，AI 直读不友好（输出质量项，非功能 bug） | **P-STEP 18F** | FeedController::graph 加 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`，中文与 URL 直出；GeoGraphTest `test_graph_emits_unescaped_unicode_for_ai_friendly_output` 锁定（原始 body 含中文、不含 `\u793a`）；两态对拍直出 |
| **TD-50** | 英文 sitemap 首页 loc 用 `PublicUrl::base()`（无 locale 前缀），输出中文首页根地址而非英文首页 | **P-STEP 18F**（两态对拍发现） | SitemapBuilder 首页 loc 改 locale-aware：默认语言 base()、非默认 base()/{locale}（/en，无尾斜杠契约）；Localization18FTest 补两语首页 loc 断言（en 含 base/en、不含无根 base）；真实 HTTP 首项已为 /en |
| **TD-51** | 英文页 header mega menu / footer / 首页与列表正文内部链接用 `url()` 不带 locale 前缀，点击跳回中文站（zh 路由），en 访客被带回中文页 | **P-STEP 18F Gate**（真实链接审计 + HTTP 点击发现） | 两层修复：显式改 PublicUrl（AppServiceProvider resolveMenuHref、layouts/site、search 共约 8 处）+ GeoUrlGenerator 新增 `withLocalePrefix()` 兜底（en 请求相对 url() 自动补 /en；console/后台/默认/外链/tel/mailto/锚点/已带前缀幂等）；新增 `test_en_internal_links_keep_en_prefix`；中英 13 页链接审计 bad=0；Focused 9/42 |
| **TD-52** | 英文页可见/属性 UI 中文残留：header/footer logo alt·aria 用中文 site_name、nav/checkbox/tel aria-label 经 config/copy 中文兜底、系列页 eyebrow「产品系列」、搜索空状态、_subnav「栏目导航」 | **P-STEP 18F Gate**（剥离 style/script/注释的渲染 CJK 审计发现） | config/copy nav.ariaLabels 中文值改 null（翻译键生效，不造第二事实源）、布局 logo alt/aria 改 `$brandDisplayName`、telBase 兜底改 `__('ui.phone_aria')`、line eyebrow/search 空状态/_subnav 改翻译键；en/zh ui.php 补键；PageCache flush 后 en 7 页可见/属性 CJK=0；Focused 9/42、Full 872/4655 |
| **TD-60** | 前台主脚本 SyntaxError：layout `<script>` 内 `{{ json_encode() }}` 被 Blade e()（ENT_QUOTES）二次转义，JSON 双引号→`&quot;`，主脚本 `Unexpected token '&'` 整块失效，导航收缩/下拉/抽屉/数字动画/IntersectionObserver 全不建立，首屏以下 `.reveal` 区块永久 opacity:0 | **P-STEP 18G-1 Gate**（真实浏览器 UAT 发现） | labels/ariaCurrent 改 `{!! json_encode() !!}`（json_encode 本身即合法 JS 字面量）；新增 test_frontend_inline_theme_script_is_not_double_escaped；Focused 26/78；真实 Chrome 滚动后 feature_grid 自动加 `in`/opacity1、console 零错误 |

---

## 6. NON-DEBT / 有意保留白名单（不是债，禁止当作污染清理）

| 项 | 保留理由 |
| --- | --- |
| 4 个 RETIRE 设置（site_short_name / site_slogan / sync_geoflow_endpoint / sync_pull_enabled） | 17F 裁定无 consumer，SettingController 黑名单 const 标记；不设 is_retired 列、不删 migration |
| 历史 migration | 工程历史真实性与升级路径，不为"全仓 0 词"伪造修改 |
| `docs/audit/**`（含 docs/audit/history） | Historical Audit Exemptions，release archive export-ignore，不进发布包 |
| tests 负向护栏断言 | 主动断言旧业务词/旧 slug **不得**出现，是防回归资产 |
| 口径 C 通用行业词（食品/制造/OEM/ODM/工业涂料/胶粘剂/装备制造/工厂/车间/产能/打样/配方等） | 无法单独指向原客户，按口径 C 保留；强身份词必须清零 |
| `config/facts.php` | 现仅作**安装期 Example Seed 来源**（P14 已降级，Runtime 不消费）；随 TD-07 演示数据完全 Entity 化后退场 |
| 「示例制造有限公司」等 Example Demo 数据 | 通用虚构示例企业，与真实客户无语义关联；18B 将其与系统默认层分离 |
| 「LocaleContext 跨请求泄漏」疑似 P0（en 预热后 zh /geo.json 被初判含英文） | 经 SetLocale trace（每请求正确 set / finally clear）+ json_decode 各层（全中文）+ 编码检查三重查证为**误判**：中文在 JSON body 被 Unicode 转义（字面 str_contains 必然 N），「Example Manufacturing」命中实体 metadata 的 `name_en` 字段而非英文 GEO；TD-49 修复转义后断言恢复 |

---

## 7. Release Gate 视图（发布时只看本节）

> 下表 `Blocks v1.0.0?` 为当前**工程建议基线**，最终以用户 Release Gate 裁决为准；裁定结果回写本列并记入 §8。

### v1.0.0 Required（公开发布前须 CLOSED 或完成 DECISION）

| 项 | 类别 | 计划阶段 |
| --- | --- | --- |
| TD-01 GitHub Actions 云端首跑全绿 | P0 | Private GitHub + Cloud CI |
| TD-02 基于最终 HEAD 重建干净 RC + Manifest + SHA-256 | P0 | Final RC |
| TD-03 Private 全验证 → 授权转 Public / v1.0.0 | P0 | Release |
| TD-53 Block Registry | P1 | P-STEP 18G-1 |
| TD-54 Template Registry | P1 | P-STEP 18G-1 |
| TD-55 Page / Landing Model | P1 | P-STEP 18G-1 |
| TD-56 Detail Composition（Article/Product/Service） | P2 | P-STEP 18G-2 |
| TD-57 Listing Composition | P2 | P-STEP 18G-2 |
| TD-58 Page Composition Manager | P2 | P-STEP 18G-1 / 18G-2 |
| TD-59 Form Block（18G 最小 FormReference / 18H 完整 Form Builder） | P3 | P-STEP 18G-1 / 18H |

> 18C 已将原 v1.0 Required 中的 TD-05（DECISION 冻结）、TD-07、TD-08b（TD-08 整体）、TD-09（#143）、TD-16①、TD-20① 全部 CLOSED（见 §5）；#114 v1.0 收口。**v1.0 Required 未闭合仅剩上述 3 项 P0 外部工程依赖。**

### v1.1+ Planned（不阻塞 v1.0.0，须有明确验收条件）

| 项 | 类别 |
| --- | --- |
| TD-06 Content 路径收敛（现有 301 桥接可用） | P1 |
| TD-14 RBAC 三角色 + 站点成员 | P3 |
| TD-15 校验 i18n + 字段级 @error | P3 |
| TD-16②③④ Category type/外链/栏目 SEO | P3 |
| TD-17 友好 500 页 | P3 |
| TD-18 Theme/Plugin 上传安装 / SDK / 市场 | P3（v1.x） |
| TD-19 旧概念 IA 重命名（token 已中性化） | P3 |
| TD-20②③④ md-editor 上传 / 搜索召回 Entity / 英文召回 | P3 |
| TD-23 PHPUnit 12 attribute 迁移 | P4 |
| TD-24 PageCache file store 回收 | P4 |
| TD-27 GeoflowApiTest 测试顺序隔离加固 | P4 |
| TD-36 组件 loading / aria-busy 模式 | P3 |
| TD-37 产品列表移动系列卡密度优化 | P3 |
| TD-38 example 极简主题对齐 / 能力边界标注 | P3 |
| TD-46 factory/cooperation IA 与 URL 命名制造业特定（数据驱动 404，v1.1 中性化） | P3 |

### 计数（当前）

- CLOSED：#86、**#114（18A+18C）**、**#143 / TD-09**、**#144**、TD-04、**TD-05（DECISION）**、**TD-07**、TD-08a / **TD-08b（TD-08 整体）**、**TD-16①**、**TD-20①**、TD-10、TD-11、TD-12、TD-13、TD-25、TD-26、P17 六管理面 + 17G、**TD-28..TD-35（18D Design System）**、**TD-39..TD-41（18E 能力对账）**、**TD-42..TD-45（18F 本地化）**、**TD-47..TD-48（18F 路由守卫 / Entity 搜索）**、**TD-49..TD-50（18F geo.json 直出 / en-sitemap 首页 locale）**、**TD-51..TD-52（18F Gate：en 链接 locale 前缀 / en UI 中文残留清零）**、**TD-60（18G-1 Gate：主脚本二次转义 SyntaxError 修复）**
- v1.0.0 Required 未闭合：**10** = P0×3 外部发布工程（TD-01 / TD-02 / TD-03）+ Page Composition ×7（TD-53/54/55 计划 18G-1 闭合；TD-56/57 计划 18G-2；TD-58 18G-1 PARTIAL→18G-2；TD-59 18G-1 最小 / 18H 完整）；18G-1 进行中
- v1.1+ Planned：TD-06（/article/ 收敛）、TD-14、TD-15、TD-16②③④、TD-17、TD-18、TD-19、TD-20②③④、TD-23、TD-24、TD-27、**TD-36、TD-37、TD-38**、**TD-46**
- NON-DEBT / DEFERRED 观察项：TD-21 / TD-22 / TD-23 / TD-24 / TD-27

---

## 8. 维护规则与变更日志

**维护规则**
1. 每个阶段 Gate 必须更新本台账：Status、证据（commit/tag/测试数/HTTP 对拍）、Blocks 列裁定。
2. 销项严格走 §2 状态机；CLOSED 移入 §5，不删表行。
3. 新发现债务先分配新 `TD-NN`（续号），写清 Source 与 Acceptance Criteria，再开始修。
4. 父级 Epic（#86/#114/#115）只在其全部子项 CLOSED/裁定后才标 CLOSED。
5. 本文件与代码同仓、随阶段提交；它是发布 Gate 的唯一对账基线。

**Changelog**

| 日期 | 阶段 / commit | 变更 |
| --- | --- | --- |
| 2026-09-22 | P-STEP 18A（`4dbc95a` / `checkpoint-18A`） | 建立唯一 Registry；汇总 16A/17F/17G/P14/蓝图散落债务为 TD-01..TD-24 + Epic #86/#114/#115；#86 与 TD-04、TD-08a 登记 CLOSED；#114 标 PARTIAL；#115 标 ACTIVE；锁定 v1.0.0 Required / v1.1 Planned 建议基线 |
| 2026-09-22 | P-STEP 18B（`checkpoint-18B`） | #115 子项 TD-10/11/12/13 全部 CLOSED：出厂 Blank System 与 db:seed Demo Site 分离（BlankHomepageSeeder / Demo StructureSeeder）、Site.name 单一事实源、图标 registry 中性化；两态 Fresh Install + HTTP/浏览器对拍；新发现并修复 TD-25（PageCache 键不含端口致同机异端口串整页）、TD-26（SQLite getTableListing 返回 main. 限定名致空站删除保护失效），各补防回归测试；v1.0 Required 12→9 |
| 2026-09-22 | P-STEP 18C（`checkpoint-18C`，826/4139/0/0） | Release Residual Audit：TD-05 Entity URL 体系书面冻结（DECISION）、TD-07 Organization 裁定 Site 聚合为唯一事实源（facts 降为安装期种子、前台零消费）、TD-08/08b Entity·Site 缓存失效+stale memo、TD-09/#143 声明性绝对 URL 全改派 PublicUrl（功能性 URL 显式保留 url()/asset()，sitemap loc 与首页 canonical 斜杠契约分离）、TD-16① Category slug 站点作用域+type 收敛、TD-20① RSS geo_rss_enabled 门禁（设置 64→65）全部 CLOSED；#114 v1.0 收口；#144 单一事实源反向审计无新双源；TD-06 /article/ 书面 DEFERRED v1.1；新登记 TD-27 GeoflowApiTest 测试顺序依赖（P4，CI 固定顺序绿）；fresh geo:install settings=65、空站/Demo 两态真实 HTTP 对拍；**v1.0 Required 未闭合 9→3（仅 P0 TD-01/02/03 外部发布工程）**；报告 `docs/audit/release-residual-audit-18c.md` |
| 2026-09-23 | P-STEP 18D（`862ff1d` → 收尾提交 / `checkpoint-18D`，857/4574/0/0） | Final Product Completeness 第一阶段 Design System 2.0：新增 TD-28 Light/Dark/System 深色（独立深色令牌 + AA 提亮 + SSR 防闪 + 记忆）、TD-29 行业预设扩 8 类（finance/healthcare/Consumer，只改视觉不改 IA）、TD-30 12 档 rem 字阶 token（结构性 27 处 + 辅助 82 处归并）、TD-31 彩色硬编码清零（彩色 hex/rgba=0）、TD-32 全局 focus-visible 焦点环 + disabled、TD-33 `.ph-h` 移动缩小（CSS 源顺序根因）、TD-34 aria-required、TD-35 geo:upgrade 部署清 view/PageCache，全部 CLOSED；新登记 DEFERRED TD-36（loading）、TD-37（移动系列卡密度）、TD-38（example 极简主题对齐）；Blank System ≠ Demo Site 视觉再确认；v1.0 Required 未闭合仍为 3（TD-01/02/03），18D 未新增发布阻塞；报告 `docs/audit/design-system-final-audit.md` |
| 2026-09-23 | P-STEP 18E（收尾提交 / `checkpoint-18E`，861/4590/0/0） | Frontend ↔ Backend Capability Closure 能力对账：通读首页 16 区块 + Header/Footer/导航 + 全部列表/详情/表单/关于页 + Catalog 投影 + 组件，确认绝大多数前台元素数据驱动、空则隐藏；发现并最小修复 TD-39（factory 部分生产事实裸输出 0：H1/stats/SEO 按数据拼接）、TD-40（_bottom_cta 电话行门控）、TD-41（solutions「你的店」→「你的业务」），新增 FrontendBackendClosure18ETest 4 用例（16 assertions）；疑似 phone.invalid 缺失经核实 `Copy::form()` 组装层已兜底（不读 config 该键），判 NON-DEBT、撤销对 config/copy.php 的多余改动；后台字段 consumer 反查复用 17A–17G / 17F 64 键矩阵结论；v1.0 Required 未闭合仍为 3（TD-01/02/03），18E 未新增发布阻塞；产出 `frontend-backend-capability-matrix.md`、`hardcoded-capability-register.md` |
| 2026-09-23 | P-STEP 18F（`checkpoint-18F`） | Localization 前端 zh-CN + en：Locale Registry / SetLocale / URL（zh 无前缀、en /en）/ 同表多行 translation_group 翻译模型 / 双语 feed / hreflang / 本地化 Schema·GEO·Sitemap·Search；Multi-Site × Locale：en-only Site B 验证 zh 404、en 200、双向隔离；发现并修复 TD-42（Catalog 硬依赖 zh-CN org → baseLocale 回退 + nullable）、TD-43（area_served ??）、TD-44（blank 描述单语泄漏 → 优先 Catalog company summary）、TD-45（knowledge/products SEO 翻译键工业措辞中性化），TD-47（路由 PublicUrlLocalized 重复声明 fatal → function_exists 守卫）、TD-48（搜索补齐 Entity：SearchResult + 合并 entityQuery），均 CLOSED；新登记 TD-46（factory/cooperation IA 制造业命名，数据驱动 404 不暴露，DEFERRED v1.1）；收尾另修 TD-49（geo.json 中文/URL Unicode 转义 → JSON_UNESCAPED 直出 + GeoGraph 防回归）、TD-50（英文 sitemap 首页 loc 缺 /en → locale-aware + 断言）；「LocaleContext 跨请求泄漏 P0」经三重查证裁定 NON-DEBT（误判）；v1.0 Required 未闭合仍为 3（TD-01/02/03）；报告 `docs/audit/localization-final-audit.md` |
| 2026-09-23 | P-STEP 18F **Gate Validation**（`checkpoint-18F`，**872/4655/0/0**） | 对切换设备前会话产出的实现 `d886d5d` 做独立验收（流程异常已记录，≠直接认可 PASS）：Focused 9/42、Full 872/4655、Fresh blank/demo install、Blank/Demo HTTP、Browser 四组合（zh/en × light/dark）、Multi-Site × Locale、PageCache zh↔en 内容对拍全过；真实链接/渲染审计发现并修复 **TD-51**（en 内部链接缺 /en：PublicUrl 显式改约 8 处 + GeoUrlGenerator `withLocalePrefix()` 兜底 + 防回归测试）、**TD-52**（en 可见/属性 UI 中文残留：config/copy ariaLabels 中文值改 null + logo `$brandDisplayName` + tel/eyebrow/空状态/subnav 翻译键 + 字典补键），均 CLOSED，en 7 页渲染 visible/属性 CJK=0；唯一 `local.ERROR` 系本轮 tinker 命令被 shell 剥离双引号的 ParseError（命令构造问题、非产品缺陷，无引号写法重跑成功）；serve(8111/8112)/端口/临时 sqlite/smoke 缓存全清；v1.0 Required 未闭合仍 3（TD-01/02/03），18F 未新增发布阻塞 |
| 2026-09-23 | P-STEP 18G-1 START（Discovery ACCEPTED / 实现授权） | 登记父 Epic **#116** Page Composition / Template System 与 **TD-53..TD-59**：TD-53 Block Registry、TD-54 Template Registry、TD-55 Page/Landing（P1，18G-1）；TD-56 Detail、TD-57 Listing Composition（P2，18G-2）；TD-58 Page Composition Manager（18G-1 PARTIAL→18G-2）；TD-59 Form Block（18G-1 最小 FormReference，完整 Form Builder→18H）。v1.0 Required 未闭合 3→10（Page Composition 7 项在 18G/18H 闭合，P0×3 仍待外部授权） |
| 2026-09-23 | P-STEP 18G-1 Gate Validation（进行中） | 真实浏览器 UAT 发现并修复 **TD-60**：layout 主脚本 `{{ json_encode() }}` 二次转义 `&quot;` 致全站前台 JS SyntaxError、IntersectionObserver 不建立、`.reveal` 永久 opacity0；改 `{!! json_encode() !!}` + 防回归（Focused 26/78）；console 零错误、feature_grid 滚动自然显现。v1.0 Required 未闭合仍 10（TD-60 发现即 CLOSED，不新增阻塞） |
