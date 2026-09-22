# GEO Website OS — Technical Debt & Release Gate Registry

- **定位**：本文件是 GEO Website OS **唯一**的技术债 / 架构债 / 产品化债 / Release Gate 登记与销项台账。所有阶段（P-STEP / 17x / 18x）的 Gate 对账以本文件为准；其他审计文档（product-uat-final、settings-inventory-17f、runtime-architecture-closure、admin-control-plane-final-acceptance、admin-management-completion-design 等）只作为**来源证据**，不再各自维护债务清单。
- **建立时基线**：HEAD `4dbc95a`（= annotated tag `checkpoint-18A`）；Regression **801 tests / 3948 assertions / 0 failed / 0 skipped**；`v1.0.0-rc1` 冻结于 `965d63c`（HOLD）；无 remote、未 push、未发布。
- **阶段口径修正**：P-STEP 17 中 **17A–17F = 六大管理面**（Site / Entity / EntityRelation / SeoMeta / Theme·Plugin / Settings）；**17G = 六大管理面的系统级 Full Admin UAT**，不是第七个管理面。
- **最后更新**：P-STEP 18A 收口后。

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
| **#114** Catalog Read Model / Entity Public Model | 后台生产模型 → Catalog 读模型 → 前台 / Schema / GEO / Sitemap / Search 的权威链路与公开 URL 体系 | **PARTIAL**：关系权威源 TD-04 已 CLOSED（18A）；TD-05 / TD-06 / TD-09 仍 ACTIVE | YES（须收口或逐项裁定） |
| **#115** Default Template Neutralization | 出厂为行业中性空站，系统默认层与 Example Demo（工业材料）彻底分离 | **ACTIVE**（17G 仅完成空状态安全降级，未做行业中性出厂） | YES（开源公开形象） |

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
| **TD-05** | Entity 六类型公开 URL 体系未冻结 | 17G §5；现仅 core 产品 `/products`、场景服务 `/solutions` 有页，org/person/location/topic/非 core 产品 url=null | ACTIVE | 形成书面 URL 契约：哪些 Entity 类型有公开页、其余类型 url=null 且不进任何 feed；补页或维持现状均须有测试与文档锁定，Canonical/Schema @id/Sitemap/GEO/Search 一致 | **DECISION**（发布前必须冻结决策；补页可延后） | #114, TD-09 |
| **TD-06** | Content 路径双轨：无 `/article/`，旧路径靠 301 桥接 | 17G §5 | ACTIVE | 裁定 Content 公开路径单一体系；301 桥接行为有测试锁定；或落地 `/article/` 并收敛 canonical/sitemap | NO（现有 301 桥接可用，建议 v1.1 收敛） | #114 |
| **TD-07** | Organization 双载体：organization Entity 与 `sites.metadata.organization` 并存，SchemaBuilder 读后者 | P14 遗留；18A 附录 G | ACTIVE | 单一组织事实源：Schema/GEO/前台统一从 organization Entity（或正式指定的唯一源）读取；多站组织数据隔离；config/facts.php 安装期种子随之退场 | **YES**（影响多站 Schema 一致性） | #114, TD-10 |
| **TD-08** | PageCache 整页缓存失效模型不完整 | 18A 实测发现并部分修复 | **PARTIAL** | 见下方子项；最终所有会改变前台 HTML 的写入路径都失效对应整页缓存，并有"写入→前台立即一致"防回归测试 | **YES**（剩余模型） | — |
| └ TD-08a | EntityRelation 写入失效整页缓存 | 18A | **CLOSED by 18A**（模型 saved/deleted → PageCache::flush） | — | — | TD-08 |
| └ TD-08b | Entity / Site / SeoMeta / Content 写入后整页 HTML 失效未挂接 | 18A 附录 G | ACTIVE | 上述模型 saved/deleted 挂接触发（精确失效或全站 flush），覆盖后台/tinker/import 全写入路径；feed 端点维持不缓存 | **YES**（18C） | TD-08 |
| **TD-09** | Product 自身 `@id` 仍用 `url()` helper；Catalog 早期路径缺 `Schema::hasTable` 守卫 | 17G（manufacturer.@id 已改 PublicUrl，product 自身未改） | ACTIVE | Product/各类型 Schema `@id`/url 统一经 PublicUrl，与 host/canonical 一致，多站不出现 localhost 串站；读模型在缺表时安全降级 | **YES**（小改，随 #114 收口） | #114, TD-05 |

### P2 — 通用化与产品化（#115）

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
> **#115 全部子项（TD-10 / TD-11 / TD-12 / TD-13）已由 P-STEP 18B 销项 CLOSED**，证据见 §5 与 `docs/audit/default-template-neutralization-18b.md`。出厂为行业中性空站（Blank System）；工业材料制造 Example 仅经 `db:seed` 可选装载（Demo Site）。两态经 Fresh Install + 真实 HTTP/浏览器对拍，验证 **Blank System ≠ Demo Site**。

### P3 — 后台与体验

| ID | Title | Source | Status | Acceptance Criteria | Blocks v1.0.0? | Parent/Related |
| --- | --- | --- | --- | --- | --- | --- |
| **TD-14** | RBAC 仅两级（super admin / admin），蓝图 §12.2 要求三角色 + 站点成员 | 蓝图 admin-management-completion-design §12.2 | ACTIVE | 角色模型（如 owner/admin/editor）+ 站点成员关系 + 越权测试；普通管理员不可跨站 | NO（v1.1；单组织开源 v1 两级可接受） | — |
| **TD-15** | 校验 i18n 缺失 + 无字段级 `@error` | 16A；Laravel 校验文案为英文，仅顶部汇总 | ACTIVE | zh-CN 校验语言包；关键字段字段级错误展示；不削弱后端校验 | NO（v1.1） | — |
| **TD-16** | Category 多项不规范：slug unique 未按 site_id、type 枚举漂移、外链接线、栏目 seo_title/seo_desc 疑似第三套 SEO | 17F/17G 遗留 | ACTIVE | ① slug 站点作用域唯一（多站正确性）；② type 枚举收敛；③ 外链栏目前台接线或移除；④ 栏目 SEO 归并 SeoMeta/Resolver，不造第三套 | **MIXED**：①建议 YES（多站正确性，18C 评估）；②③④ NO（v1.1） | SeoMeta(17D) |
| **TD-17** | 缺后台友好 500 错误页 | 16A/17F | ACTIVE | 后台/前台异常展示友好错误页，绝不泄漏堆栈/路径；有模拟 500 的验证 | NO（v1.1；安全上已不泄漏堆栈） | — |
| **TD-18** | Theme/Plugin 上传安装 / SDK / 应用市场缺失 | 蓝图明确划出 v1.x | ACTIVE（规划中） | 蓝图定义的上传安装、SDK 规范、市场能力（按蓝图里程碑） | NO（v1.x） | Theme/Plugin(17E) |
| **TD-19** | GEOFlow 等旧概念后台 IA 命名未清理 | 16A F2；token 前缀 `yhf_` 已在 17F 中性化 | PARTIAL | token 前缀已中性化（DONE）；后台菜单/术语重命名为 Site/Content/Entity/Relation/SEO/GEO/Theme/Plugin/Settings 体系 | NO（v1.1） | — |
| **TD-20** | md-editor 内联上传未测；RSS 未接独立 enabled 门禁；搜索不召回 Entity 且英文召回弱 | 16A/17F | ACTIVE | ① RSS enabled 门禁与 feed 一致（小改）；② md-editor 内联上传实测；③ 搜索召回 Entity；④ 英文分词/召回改善 | **MIXED**：①建议 YES（feed 一致性，18C）；②③④ NO（v1.1） | #86, #114 |

### P4 — 观察与测试限制（默认 NON-BLOCKING，记录在案）

| ID | Title | Source | Status | 说明 / 验收 | Blocks v1.0.0? |
| --- | --- | --- | --- | --- | --- |
| **TD-21** | 尾斜杠 301 在 Feature 测试层无法复现（CanonicalizeSlash runningUnitTests skip，客户端剥尾斜杠） | 多阶段 | NON-DEBT（测试限制） | 真实 HTTP（serve/curl）已验证 301；保留为已知测试框架限制，若未来引入 HTTP 级集成测试再覆盖 | NO |
| **TD-22** | release archive export-ignore docs 后，个别守护测试仅在完整 checkout 成立 | 15/16 | NON-DEBT（打包限制） | 发布包测试矩阵中注明"完整 checkout vs release archive"差异，CI 跑完整 checkout | NO |
| **TD-23** | PHPUnit doc-comment metadata deprecation | 多阶段 | DEFERRED（v1.1） | 升级 PHPUnit 12 时改用 attribute 语法 | NO |
| **TD-24** | PageCache file store 在 `cache:clear` 后磁盘回收不即时 | 18A 观察 | DEFERRED | 功能不影响正确性（flush 逻辑生效）；磁盘回收策略优化延后 | NO |

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

---

## 7. Release Gate 视图（发布时只看本节）

> 下表 `Blocks v1.0.0?` 为当前**工程建议基线**，最终以用户 Release Gate 裁决为准；裁定结果回写本列并记入 §8。

### v1.0.0 Required（公开发布前须 CLOSED 或完成 DECISION）

| 项 | 类别 | 计划阶段 |
| --- | --- | --- |
| TD-01 GitHub Actions 云端首跑全绿 | P0 | Private GitHub + Cloud CI |
| TD-02 基于最终 HEAD 重建干净 RC + Manifest + SHA-256 | P0 | Final RC |
| TD-03 Private 全验证 → 授权转 Public / v1.0.0 | P0 | Release |
| TD-05 Entity 公开 URL 体系**冻结决策**（补页可延后） | P1 / DECISION | 18C |
| TD-07 Organization 单一事实源 | P1 | 18C |
| TD-08b Entity/Site/SeoMeta/Content 整页缓存失效 | P1 | 18C |
| TD-09 Product/Schema `@id` 统一 PublicUrl | P1 | 18C（随 #114） |
| TD-16① Category slug 站点作用域 | P3（多站正确性） | 18C 评估 |
| TD-20① RSS enabled 门禁 | P3（feed 一致性） | 18C |

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

### 计数（当前）

- CLOSED：#86、TD-04、TD-08a、TD-10、TD-11、TD-12、TD-13、TD-25、TD-26、P17 六管理面 + 17G
- v1.0.0 Required 未闭合：**9**（P0×3、P1×4 含 1 个 DECISION、P3 拆分×2）；#115（TD-10/11/12）已由 18B 闭合移出
- v1.1+ Planned：**10**（TD-13 已由 18B 闭合移出）
- NON-DEBT / DEFERRED 观察项：TD-21 / TD-22 / TD-23 / TD-24

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
