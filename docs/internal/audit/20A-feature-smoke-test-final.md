# P-STEP 20A-FBS — Feature-by-Feature Real User Simulation · Final Report

- **巡检日期**：2026-09-28
- **Remediation 日期**：2026-09-29
- **HEAD**：Remediation 后（见 git log）
- **权威仓**：`D:\GEO-OS-rewrite\geo-website-os`
- **性质**：发布前最后一轮真实用户操作级巡检（非静态审查、非普通回归）
- **执行模式**：巡检阶段 READ ONLY；Remediation 阶段经人工授权最小修复 BUG-FBS-001/002、为 BUG-FBS-003 加产品语义提示
- **配套矩阵**：`docs/audit/20A-feature-smoke-test-matrix.md`（190 个 FT 项）
- **最终状态**：**PASS / CLOSED** — 190/190 实际操作，修复后 0 FAIL

---

## 一、Feature Inventory（功能盘点）

### 1.1 盘点来源与计数

| 来源 | 数量 |
|---|---|
| 路由总数 | **194**（Admin 142 + Frontend 47 + API 5） |
| — Admin 路由 | 142（含 CRUD、状态切换、批量、导出、AJAX） |
| — Frontend 路由 | 47（zh/en 双注册 23×2 + robots 1） |
| — API 路由 | 5（health 等） |
| 后台导航项 | **30**（8 直达 + 22 分组链接） |
| 前台页面类型 | 18 类 |
| Entity 类型 | 8 种 |
| 后台设置组 | 8 组 |
| 交互控件 | 30 类（新增/编辑/删除/查看/发布/启停/搜索/筛选/排序/分页/上传/替换/绑关系/选模板/选主题/预览/Modal/Drawer/Confirm/Toast/Empty/Error 等） |
| **测试功能项（FT）** | **190**（Admin 161 + Frontend 29） |

### 1.2 后台实际导航（以运行系统真实出现为准）

- **8 个直达项**：站点管理、仪表盘、客户留言、表单管理、首页装修、组合页面、顶部导航、事实库
- **内容中心（6 项）**：文章管理、栏目管理、分组管理、实体与图谱、实体关系、媒体库
- **搜索与 AI（9 项）**：SEO 覆盖、GEO 健康、实体覆盖、文案叙事、GEO 工具、预览产出、同步日志、模板管理、插件管理
- **外观与扩展（3 项）**：主题管理、首页区块、重定向
- **站点设置（4 项）**：公司基础信息、联系方式、SEO 设置、GEO 设置、Analytics、同步

### 1.3 前台导航

首页、关于（简介/历史/文化）、产品中心（列表+详情）、解决方案（列表+详情）、应用场景、工厂与资质、合作、知识中心（列表+频道+文章）、联系、搜索、语言切换（zh/en）、Sitemap、robots、llms、geo、feed、404、重定向。

### 1.4 盘点结论

系统共有 **190 个用户可见/管理员可操作的测试功能项**，覆盖 194 条路由、30 个后台导航项、18 类前台页面、8 种 Entity 类型、8 组设置。所有功能均已实际操作，无遗漏。

---

## 二、模块覆盖（22 模块逐项）

| # | 模块 | 状态 | 说明 |
|---|---|---|---|
| 1 | Dashboard | ✅ PASS | 统计与 DB 一致（published=6/draft=1/article=7/category=2/factGap=6）；tile 可点击跳转；产品计数恒 0 为已知 P2 |
| 2 | Setup Wizard | ✅ PASS | 真实 6 步走完；中文 slug hash 回退非空；持久化正确；step1 无校验/step3 无幂等为已知 P2 |
| 3 | Entity（8 类型） | ✅ PASS | 8 种类型 create 表单全 200；CRUD + 硬删级联 + 状态切换 + Public URL/SEO/JSON-LD 全部四面一致 |
| 4 | Content | ✅ PASS（修复后） | 草稿/发布/下线/门禁/MD 预览正常；**FT-030 版本历史页已修复（BUG-FBS-001）**，user_id 有值/NULL 两种情况均正常 |
| 5 | Category | ✅ PASS | CRUD/排序/启停/绑内容正常，不影响已有内容 |
| 6 | Page | ✅ PASS | 新建/绑模板/发布/访问/删除；Page 独立于 Content(type=page) |
| 7 | Template/Composition | ✅ PASS | A→B→A 切换 blocks 不丢失；200/结构/SEO/JSON-LD/GEO 保留；Blade 无异常 |
| 8 | Media | ✅ PASS | 上传/内联/更新/删除；引用守卫阻止+列来源+审计；无引用删除成功 |
| 9 | Menu | ✅ PASS | 自定义项 CRUD/override/重置；Desktop/Mobile/zh/en 前台验证 |
| 10 | Setting/Block/Fact | ✅ PASS（产品语义确认后） | 设置读写正常、前台联动；**FT-108 Fact 定性为 GEO 图谱专用数据源（BUG-FBS-003）**，已在后台列表页/表单页加 alert 提示，geo.json 行为不变 |
| 11 | Inquiry/表单 | ✅ PASS（修复后） | 默认联系表单提交/Attribution/UTM/审计正常；**FT-119/120 自定义字段 create/edit 已修复（BUG-FBS-002）**，数据真实落库、可回填编辑 |
| 12 | Search | ✅ PASS | CJK bigram 召回；发布搜到/下线搜不到/恢复搜到；空搜索/无结果正常 |
| 13 | Redirect/404 | ✅ PASS | 301/302 正常；尾斜杠 canonicalize；旧 URL 重定向；404 优雅 |
| 14 | SEO/GEO 全链路 | ✅ PASS | title/desc/canonical/hreflang/OG/JSON-LD/Breadcrumb/Organization/Product/Service/Article/sitemap/llms/geo 全链路四面一致 |
| 15 | Health/Coverage | ✅ PASS | 5 项 Check 正常；Coverage 12/12=100%；改 Entity 后指标变化 |
| 16 | i18n | ✅ PASS | zh/en 无串页；canonical/hreflang/inLanguage 正确；SEO/GEO 随语言切换 |
| 17 | Theme | ✅ PASS | Light/Dark/System 切换；Token/Button/Card/Input/Modal 正常；多页验证 |
| 18 | Security/IDOR | ✅ PASS | 访客 302；Editor 403；跨站 IDOR 404；CSRF 419；XSS 转义；Mass Assignment 防护 |
| 19 | Cache | ✅ PASS | MISS→HIT；Edit/Publish/Unpublish/Delete/Template Switch 后立即更新 |
| 20 | Mobile | ✅ PASS | 375/390/768/1440 视口；Nav/Menu/Cards/Form/Table 无横向滚动；多页验证 |
| 21 | Console/Network | ✅ PASS | 无 uncaught error/promise rejection；无 4xx/5xx/重复请求/无限请求 |
| 22 | Golden User Journey | ✅ PASS | 完整客户旅程打通（安装→建站→实体→关系→页面→SEO→菜单→访客→搜索→Inquiry→后台→GEO→i18n→Theme→Cache→Unpublish） |

**模块汇总：22 PASS / 0 FAIL（Remediation 后 Content、Fact、Form 字段全部转为 PASS）**

---

## 三、Bug 清单

### 3.1 本轮新确认的真实 Bug（4 FAIL → 3 个 P2，已全部收口）

**4 FAIL 准确映射**（同一 BUG-FBS-002 导致 FT-119、FT-120 两个功能点 FAIL，这是"4 FAIL / 3 P2"的原因）：

| FT | 功能点 | 对应 Bug |
|---|---|---|
| FT-030 | Content 版本历史页 | BUG-FBS-001 |
| FT-119 | Form 字段创建 | BUG-FBS-002 |
| FT-120 | Form 字段编辑 | BUG-FBS-002 |
| FT-108 | Fact 前台验证 | BUG-FBS-003 |

| Bug ID | FT | 严重度 | 模块 | 描述 | 根因 | Remediation |
|---|---|---|---|---|---|---|
| **BUG-FBS-001** | FT-030 | **P2** | Content | UI 创建并编辑过的内容，访问版本历史页 500；种子内容正常 | `ContentRevision` 模型未定义 `user()` 关系；视图访问 `$r->user->name`；UI 创建的 revision 有 user_id 触发关系加载，种子 revision user_id=null 跳过 | **已修复**：`ContentRevision` 新增 `user(): BelongsTo` 并 `withDefault(['name'=>'系统'])`；两种 user_id 情况真实浏览器验证均 200 |
| **BUG-FBS-002** | FT-119/120 | **P2** | Form | 自定义表单字段创建/编辑返回 500，字段未写入 | `FormController` storeField/updateField 访问不存在的 `$data['validation']` 键 | **已修复**：两处 `?:` 改为 `?? null`；字段创建/编辑真实提交，zh-CN/en 两条记录落库、可回填编辑 |
| **BUG-FBS-003** | FT-108 | **P2** | Fact | Fact 后台 CRUD 不影响前台 HTML，仅影响 geo.json | 共享的 `$publicFacts` 在 Blade 中零引用；`GeoGraphBuilder` 用 `Fact::publicRows()` 故 geo.json 正常；公司名等走 settings/Catalog | **不改逻辑**：定性为 GEO 图谱专用数据源，已在 facts 列表页/fact-form 加 alert 提示，geo.json 行为不变 |

### 3.2 已知 P2（TD 登记，本轮复确认）

| Bug | 模块 | 描述 |
|---|---|---|
| Dashboard 产品计数恒 0 | Dashboard | 用 `Content::ofType('product')`，产品实际在 entities 表，口径错配 |
| Wizard step1 无校验 | Wizard | 空提交/非法邮箱静默落库，把 site_name 覆盖为 NULL |
| Wizard step3 无幂等 | Wizard | 双击/刷新产生重复 product（slug 加 -2/-3） |
| Markdown 未净化 | Content | 正文未做 Markdown 净化 |
| geo.json 悬空边 | GEO | 删实体后其他实体 metadata 交叉引用残留 |
| og_image_path 坏图 | Media | OG 图片路径健壮性 |

### 3.3 已知 P3（TD 登记，本轮复确认）

Wizard 无完成提示、双击发布冗余 revision、无乐观锁、Media 删除无审计、无 skip-link、`Schema::hasTable` 重复查询、Page slug 尾斜杠不规范化、新站删除保护过严、UX 引导断点（Logo 上传不在公司信息页/产品入口叫"实体"）。

### 3.4 已排除的误报（环境/测试工具问题）

| 报告来源 | 报告 Bug | 核实结果 |
|---|---|---|
| Batch 4 BUG-005 | 前台所有页面无 HTML 表单 | **误报**：`/contact/` 200，含 `<form>`、`_token`、submit |
| Batch 5 BUG-02 | 英文站整体不可达 /en/* 404 | **误报**：`/en/` 200（len=135886），`/en/contact/` 200 含表单 |
| Batch 5 BUG-03 | /factory/、/cooperation/ 断链 | **误报**：`/factory/` 200、`/cooperation/` 200 |
| Batch 4 BUG-004 | 知识频道 /knowledge/news/ 404 | **误报**：正确 URL 用 group slug，`/knowledge/selection/` 200、`/knowledge/process/` 200；news 组无内容 404 属预期 |
| Batch 4 FT-134/135/136 | 模板预览/截图/对比 302/404 | **误报**：preview 200、compare 200；截图 URL 是 `/preview/desktop`（非 `/preview/home`），200 |
| Batch 4 FT-138/139 | 插件 enable/disable 419 | **误报**：干净会话 enable=302；419 为 curl cookie 管理问题（token 每次 POST 轮转） |

> 误报根因：Batch 4/5 测试期间 `artisan serve` 进程崩溃/重启，叠加多会话共享 .env 导致 DB 指向漂移，产生瞬时错误。

---

## 四、Evidence（关键功能证据）

### 4.1 四面对拍（UI = HTTP = DB = 前台）

| 功能 | HTTP | DB | 前台 |
|---|---|---|---|
| Entity 创建（8 类型） | 302 | 行新增 | 详情页 200 |
| Entity 删除级联 | 302 | relations 58→34 | 详情 404 |
| Relation 增删 | 302 | 58↔59 | geo.json 58↔59 同步 |
| Content 发布/下线 | 302 | status 切换 | 200↔404 |
| Page 模板切换 | 302 | template 变更 | 200，blocks 不丢 |
| Media 引用删除 | 302 | 行在 | 文件在，审计记录 |
| Setting site_name | 302 | sites.name 镜像 | 前台出现新名 |
| Menu override | 302 | 行新增 | 导航显示新名 |
| Inquiry + UTM | 302 | 行新增 | 后台 Attribution 完整 |
| Cache 写穿 | — | DB 新值 | 立即显示新值 |

### 4.2 关键 HTTP 状态（主库验证）

| URL | 状态 | URL | 状态 |
|---|---|---|---|
| / | 200 | /en/ | 200 |
| /products/ | 200 | /factory/ | 200 |
| /solutions/ | 200 | /cooperation/ | 200 |
| /knowledge/ | 200 | /knowledge/selection/ | 200 |
| /knowledge/process/ | 200 | /contact/ | 200（含表单） |
| /en/contact/ | 200（含表单） | /sitemap.xml | 200 |
| /llms.txt | 200 | /geo.json | 200 |
| /robots.txt | 200 | /feed.xml | 200 |
| /nonexistent | 404 | /scenarios/ | 301 |

### 4.3 GEO 指标

- **Entity Coverage**：12/12 公开实体必备项 100% 覆盖（组织 1/产品 8/服务 3）
- **GEO Health**：整体 WARNING（唯一扣分项 Missing OG，12 实体未做独立 SEO，回退站点兜底）
- **0 命中扫描**：前台 12 页 + geo.json + sitemap + llms.txt 扫描Demo Tenant A/Sample City/Sample Province/Sample Snack/Sample Marinade = **0 命中**

---

## 五、Coverage Calculation

| 指标 | 巡检时 | Remediation 后 |
|---|---|---|
| 应测试功能总数 | 190 | 190 |
| 实际操作功能数 | 190 | 190 |
| PASS | 186 | **190** |
| FAIL | 4（FT-030/108/119/120） | **0** |
| BLOCKED | 0 | 0 |
| NOT-COVERED | 0 | 0 |
| **实际操作覆盖率** | 100% | **100%** |
| **功能通过率** | 97.9% | **100%** |

---

## 六、最终结论

### 6.1 PASS 条件逐项核对

| 条件 | 要求 | 实际 | 结果 |
|---|---|---|---|
| 实际操作覆盖率 | 100% | 100% | ✅ |
| 未覆盖 | 0 | 0 | ✅ |
| BLOCKED | 0 | 0 | ✅ |
| Critical | 0 | 0 | ✅ |
| Major | 0 | 0 | ✅ |
| P0 | 0 | 0 | ✅ |
| P1 | 0 | 0 | ✅ |
| 关键链路全 PASS | 是 | Golden Journey 全链路打通 | ✅ |

### 6.2 判定

**PASS / CLOSED** — 实际操作覆盖率 100%（190/190），Remediation 后 0 FAIL / 0 BLOCKED / 0 Critical / 0 Major / 0 P0 / 0 P1，Golden User Journey 全链路打通。

巡检发现的 3 个 P2 已全部收口：
1. **BUG-FBS-002 已修复**：自定义表单字段 create/edit 恢复，数据真实落库。
2. **BUG-FBS-001 已修复**：版本历史页两种 user_id 情况均正常。
3. **BUG-FBS-003 已定性 + UI 提示**：Fact 为 GEO 图谱专用数据源，后台已明确提示。

### 6.3 Remediation 验证证据

- **真实浏览器验证**：FT-030（版本历史 user_id=1 显示"管理员"、user_id=NULL 显示"系统"）、FT-119/120（字段创建后 zh-CN/en 两条记录、编辑回填并更新）、FT-108（facts 列表页/表单页 alert 提示、geo.json 行为不变）
- **定向回归**：AdminContentTest + FormProductization18H2Test 共 32 passed（115 assertions）
- **全量回归**：**1246 passed / 6668 assertions / 0 failed / 0 skipped**（EXIT=0）

### 6.4 收尾确认

- ✅ 临时库 `database/20a_remed.sqlite`、`D:\Temp\*.bak`、临时脚本已清理
- ✅ 主库 `database.sqlite` 恢复基线（**6 contents / 18 entities / 20 pages / 0 media / 58 relations / 0 inquiries / 1 user**）
- ✅ 所有 PHP 子进程已停止，端口已释放
- ✅ 工作树 clean（修复 + 文档已本地 commit）
- ✅ rc1 tag `v1.0.0-rc1` 仍指向 `965d63c`（全程未移动）
- ✅ 未配置 remote / 未 push / 未 release

**STOP — 等待人工最终发布裁定。**
