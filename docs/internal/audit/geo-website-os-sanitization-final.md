# GEO Website OS — Sanitization Final Report

> 阶段：P-STEP 14 之后的「基础清洗、业务脱敏、标准统一与技术 Bug 修复」总控收口
> Checkpoint：`checkpoint-sanitize`（CP1）→ `checkpoint-standardize`（CP2）→ `checkpoint-bughunt`（CP3）
> 最终回归：**646 tests / 2929 assertions / 0 failed / 0 skipped**（85 条 PHPUnit 框架层 Deprecation，非失败、不写入应用日志）
> 日期：2026-09-20

本报告只陈述**当前代码、数据库与测试的实际结果**，不宣称“无 Bug”。正确表述为：已完成当前测试范围内的系统性清洗、标准化与 Bug Hunt，并登记仍存在的风险与技术债务。

---

## 0. 结论摘要

| 维度 | 结果 |
| --- | --- |
| 真实客户业务数据从 Runtime 移除 | PASS |
| Runtime / 前端 / 后端 / Config / 正式文档客户污染 | **0 命中** |
| Demo / Example 数据与真实客户语义隔离 | PASS |
| 命名 / 品牌统一为 GEO Website OS | PASS |
| Design / PHP / HTML / CSS 标准文档 | PASS（CP2） |
| 文档清洗、历史归档、LICENSE / CHANGELOG | PASS |
| 技术 Bug Hunt（含 2 个真实多站缺陷修复） | PASS |
| Final Closure 再修真实严重 Bug：后台新建栏目 `is_index` 缺列必 500 | PASS（已修，§11.7 D.1） |
| Browser / HTTP smoke（前台 27 URL + 后台全模块 CRUD/异常 + fresh install） | PASS（§11.7 C） |
| Installer / Upgrade 幂等 | PASS |
| Multi-Site 往复切换与未知 Host 安全边界（新架构 Content/Setting/Theme/SeoMeta） | PASS（A↔B 六跳，§11.7 C.3） |
| 历史 checkpoint 逐点重放 + 测试改弱审计（Final Closure REQUIRED ①） | PASS（§11.7 A） |
| 全量静态 dead-code 分析（Final Closure REQUIRED ②） | PASS（95 类 0 dead，§11.7 B） |
| 完整探索式 HTTP Replay（Final Closure REQUIRED ③） | PASS（§11.7 C/D） |
| 全量回归 | **646 / 2929 / 0 / 0** |
| 旧 Facts 前台多站泄漏 / 插件 per-site 路由 / withSite memo | 架构债登记，**不修**（§16 Issue B 实证 / Issue D / Issue E） |
| Working tree | clean（本 checkpoint 提交后） |

**本总控范围内结论：`GEO WEBSITE OS — SANITIZED PRODUCT BASELINE READY`。**

**Final Closure（§11.7）后维持该结论**：三项此前导致 CONDITIONAL 的 REQUIRED 证据——历史 checkpoint 逐点重放、全量静态 dead-code 分析、完整探索式 HTTP Replay——已全部**重新实际执行**并闭环；探索式 Replay 还抓出并修复了 1 个真实严重 Bug（后台新建栏目 `categories.is_index` 缺列必 500，§11.7 D.1）。须明确结论边界：`SANITIZED PRODUCT BASELINE READY` 针对的是**业务脱敏 / 通用化 / 标准化的产品基线**。探索中实证的旧 Facts 前台多站跨站泄漏（Issue B 多站实证，§11.7 D.2）、per-site 插件路由时序 / 隔离（Issue D，§11.7 D.3）、`withSite()` 同进程 memo（Issue E，§11.7 D.4）属于**架构 / 多租户能力技术债**（见 §16），不构成客户业务污染、不影响单站产品基线，但在「多租户多主体 SaaS」形态与「per-site 插件」能力上是阻塞项，须由后续「前台 Entity/Content 化 + URL 统一」与「插件路由注册 / 授权分离」阶段处理。

需特别区分：上述结论针对“代码库是否已成为独立、通用、无客户污染的产品基线”。**对外 Public Release / 打 v1.0.0** 仍受两项**外部发布条件**约束（见 §16/§17，延续 P-STEP 11–13 登记）：① git 历史中的客户二进制重写；② GitHub Actions 云端 Runner 真实首跑。二者均需仓库地址与推送授权，不属于本次清洗/脱敏/标准/Bug 修复范围。

---

## 1. Business Data Removal（真实业务数据删除）

- 数据库默认数据、Seeder、Fixture、Factory、Config、Blade、Controller、Service、Support、Route、URL、Slug、SEO、Schema、GEO、CSS、JS、注释、文档、脚本、资源文件中的真实客户业务数据已在 CP1 完成语义级清理（非简单查找替换）。
- 全新数据库的最小产品初始化路径 `php artisan geo:install` **不调用任何业务 Seeder**：仅 migrate + 创建 Default Site（slug=`default`）+ 首个 `is_super_admin` 管理员。实测 fresh 库 `/geo.json` 返回 `entities=0, contents=0` 的正确空壳（见 §13）。
- 客户真实资源（Logo、照片、品牌图、favicon、微信二维码、客户截图、数据导出、附件、历史构建产物）已物理移除或重绘为中性品牌资源；`storage/app/public` 客户上传已清空且不被跟踪。
- 6 个历史 `docs/audit/*.sqlite`、121.5MB 旧站打包 `gate1-pre-change.zip`、`wechat-qr.png`、`attach.txt`、`_shots/*` 已从工作树 `git rm`（历史提交中的副本见 §16 Release Blocker）。
- 全量盘点与逐项处置见 `docs/audit/business-sanitization-inventory.md`（CP1）。

## 2. Business Pollution Scan（污染扫描）

最终扫描词表：`Example / Example(+大小写) / Sample City / Sample Province / Sample Snack / Sample Marinade / Sample Road / 通用设计参考 / Generic / 鸡排 / Sample Breading / 撒料 / 调理鸡 / Example Street / 400-000-0000 / marinade / fried chicken / sample-city / sample-province`。

| 范围 | 结果 |
| --- | --- |
| `app/` | **0** |
| `config/` | **0** |
| `routes/` | **0** |
| `resources/`（含 themes、views、css、js） | **0** |
| `public/`（含 `build/` 构建产物、img、favicon） | **0** |
| `scripts/` | **0** |
| `plugins/` | **0** |
| `database/seeders/` | **0** |
| `.env.example` / `.env.production.example` | **0**（APP_NAME 均为 GEO Website OS） |
| 根 `README.md` / `CHANGELOG.md` / `composer.json` / `package.json` | **0** |

### Historical Audit Exemptions（历史证据豁免，不进入 Runtime / 发布包）

以下命中是**工程历史证据或升级路径**，按既定原则保留，不计入 Runtime 污染：

1. `docs/audit/**`（含 inventory、baseline 清单、历史 CHANGELOG 归档）：`.gitattributes` 已配置 `docs/audit/ export-ignore`，**不进入 release artifact**。
2. `database/migrations/2026_09_15_000014_normalize_terms.php`：旧库术语归一映射（“调理鸡肉”等 3 处）。**fresh 库 0 行命中、不播种任何客户数据**，仅在升级真实旧库时幂等执行。
3. `database/migrations/2026_09_17_000002_add_slot_to_contents.php`：旧库英文 slug（chinese/western/special-marinade/coating-powder/prepared-chicken）的 slot 回填，仅服务旧库升级。
4. `tests/**`：客户词仅作为**反向安全断言黑名单**出现（`assertStringNotContainsString` / `assertDoesNotMatchRegularExpression`），分布于 BusinessPollutionZeroTest、ExampleDatasetIntegrityTest、EntityMetadataSlugLifecycleTest、SiteAwareCacheTest、FactsMigration*Test、GeoIntegrationBoundaryTest、SeoHeadComposerTest、GenericDeploymentTest、GeoInstallTest。这些断言是防回归资产，必须保留。

> 原则：不为追求“全仓 0 关键词”而伪造性修改历史 migration 或审计证据；目标是发布后的 Runtime / 正式文档 / 产品源码层无客户污染。

## 3. Demo / Example Isolation（示例数据隔离）

- 演示数据重建为与任何真实客户无语义关联的通用工业示例：`示例制造有限公司 / Example Manufacturing`（工业材料制造），产品线 coatings / adhesives / additives，产品（环氧富锌底漆、聚氨酯面漆、结构胶、流平剂等通用工业品）、场景、车间、案例、文章均为通用制造语境。
- “代工 / 配方 / OEM·ODM / 打样到量产”等词在**工业制造示例语境**下为合法通用能力描述（HomeBlockDefaults 三条 capabilities，HomeBuilderTest 断言），数据源是 DB PageBlock（data migration 播种），非客户专属。
- 隔离契约明确并经测试：
  - `geo:install` = 空壳（Default Site + 超管，无 Demo）；
  - `migrate:fresh --seed` / `DatabaseSeeder` = 完整 Example 数据集（DemoSeeder + Fact/Structure/Setting/Content Seeder）。
- `tests/Feature/ExampleDatasetIntegrityTest.php`（CP1）持续断言示例数据不含任何客户/地域/行业专属词。

## 4. Naming Standardization（命名统一）

- 对外产品名统一为 **GEO Website OS**：APP_NAME、CLI 三命令输出、install/build 脚本、theme.json、DemoSeeder、composer（`geo-website-os/geo-website-os`，MIT，PHP `^8.4`）、package.json、config fallback、README、CHANGELOG、LICENSE、错误/安装页、favicon/og 品牌图。
- 历史客户命名（`ExampleUrlGenerator`、`Facts`、`HomeBlockDefaults` 等）未机械删除，按“通用能力 / 业务兼容层 / 待删除技术债”分类，加 legacy docblock 标注，交由后续解耦阶段处理（见 §16）。
- 类名、方法名、缓存 key、配置 key 中客户专属标识已通用化（如 settings 缓存 key 不再含客户标识）。

## 5. Design System

- `docs/design-system.md`：Brand、Color（primary/secondary/accent/surface/background/text/border/success/warning/danger）、Typography、Spacing（4/8/12/16/20/24/32/40/48/64/80）、Radius（sm/md/lg/pill）、Shadow（sm/md/lg）、组件（Button/Input/Select/Card/Table/Modal/Drawer/Alert/Badge/Pagination/Navigation/Tabs/Form/Empty/Loading/Error）、交互态（hover/focus/active/disabled/loading/transition）。
- Token 事实依据来自现状 `:root`（`resources/views/layouts/site.blade.php`、`public/css/admin.css`），未机械造 Token、未大规模改 UI。

## 6. PHP / Laravel Standard

- `docs/code-standard.md`：PHP / Laravel / Blade / HTML / CSS / JS / SQL 命名、注释、Docblock、测试、日志、异常处理约定。
- 全量回归在统一标准后保持绿色；未通过“删测试 / 降断言 / skip”换取绿灯（测试数随修复净增）。

## 7. HTML Standard

- `docs/html-standard.md`：语义化 HTML、heading 层级、landmarks、button/link 语义、表单语义、ARIA、alt、SEO head、JSON-LD；禁止无意义 div 海洋、假链接按钮、错误 heading 层级。
- Browser smoke 实测每个公开页**恰好 1 个 `<h1>`**、JSON-LD 2–5 个（见 §13）。

## 8. CSS Standard

- `docs/css-standard.md`：命名架构、Token 使用、响应式断点、Spacing/Typography、状态动画、可访问性；禁止客户业务命名、重复颜色/spacing/radius、`!important` 与内联 style 滥用。
- 客户专属 CSS 命名 / section 注释 / class id 已替换为产品设计语义。

## 9. Documentation Cleanup

- 英文 `README.md`（安装、配置、多站 unknown-host 安全说明）、全新 Keep-a-Changelog `CHANGELOG.md`（1.0.0-rc1，诚实标注 hosted CI pending）、`LICENSE`（MIT, Copyright 2026 GEO Website OS Contributors）。
- 根目录旧客户史 CHANGELOG（733 行）`git mv` 至 `docs/audit/history/CHANGELOG-legacy-pre-productization.md`（export-ignore，不分发）。
- 删除默认 `welcome.blade.php`；正式发布文档无客户业务说明、无客户专属安装指引。

## 10. Dead Code Cleanup

- 清理临时调试文件、旧快照、旧生成产物、客户附件与历史打包；`.gitignore` 补强。
- 保留所有属于 Migration / Upgrade / 向后兼容生命周期的代码（未因“暂未调用”而误删）。
- 旧业务兼容层（Facts/HomeBlockDefaults/ExampleUrlGenerator 等）按架构债登记而非删除（见 §16）。

## 11. Bug Hunt（技术缺陷与修复）

本轮以“陌生用户 + 状态污染 + 多站安全”视角做探索式验收，**发现并修复 2 个真实多站缺陷 + 1 个数据一致性缺陷**，并补齐部署安全默认。

### 11.1 跨站请求级 memo 泄漏（已修复，commit `5a4363f`）

- 现象：请求级 static memo（Setting/nav/footer/Fact/Group/Narrative/Copy/Theme/SeoMeta）原本只在 `AppServiceProvider::boot()` 复位，而 boot 早于 `ResolveSite` 中间件（此刻 SiteContext 仍是 default，ThemeManager 会用 default 站预热 memo）；且 boot 在 Octane / 同进程多请求 / 常驻 worker 中只跑一次。单份 static memo 先短路 `memo!==null` 再走按站 Cache，导致同进程第二个跨站请求脏读上一站设置/导航/主题快照。
- 修复：新增 `app/Support/RequestScopedState.php`（`onReset` 注册一次复位回调、`flushAll()` 复位全部请求级 memo、`reapply()`=flush + 重放 Theme/Plugin 注册）；`ResolveSite` 在真实站点确定后 `reapply()`；`AppServiceProvider` 一次性注册 ViewComposer memo 复位（补全此前漏掉的 blueprint memo）；`QueuedBySite` 在恢复站点上下文后 `reapply()`（防御常驻 worker）。
- 测试：`tests/Feature/CrossSiteMemoLeakTest.php`，3 用例 / 32 断言（Setting 同进程 A→B 六次往复、Theme 随站切换、A 200→B 404→B 200 状态恢复）。

### 11.2 多站生产模式未知 Host 仍服务 Default 站点（本轮发现并修复，关键安全缺口）

- 现象：`SITE_DEFAULT_FALLBACK=false` 时，`SiteResolver` 正确对未知 Host 返回 `null`（单元测试一直绿），但 `ResolveSite` 中间件**未拒绝该请求**；控制器调用 `SiteContext::currentSite()`，而该方法在未显式设置时会**惰性绑定 default site（单站兼容）**，导致未知 Host 仍以 200 返回真实 default 站点首页。这违背 5.4-H 冻结的“多站生产 unknown host → 404 / Site Not Found，不得把真实站点返回给未知 Host”。
- 根因：安全边界只在 Resolver 层被测试覆盖，缺少 HTTP 中间件层断言；单站惰性兜底与多站拒绝两种语义没有在中间件汇合。
- 修复（`app/Http/Middleware/ResolveSite.php`，最小改动）：fallback 关闭且最终无解析站点时 `abort(404)`，并纳入 `try/finally` 保证 `SiteContext::clear()` 必执行；fallback 开启（单站/开发）维持 unknown→default 兼容。
- 测试：新增 `tests/Feature/UnknownHostRejectionTest.php`（4 用例，经完整 HTTP kernel）：false+未知 Host→404、false+停用 Host→404、false+已知 active Host→200、true+未知 Host→200。
- 真实 `artisan serve` + 伪造 Host 头端到端复验：`false / known(demo.test)→200`、`false / unknown→404`、`true / unknown→200`（三者符合契约）。

### 11.3 部署模板安全默认补齐

- `config/site.php` 默认 `env('SITE_DEFAULT_FALLBACK', true)`，而 `.env.production.example` 原本未显式声明该项——照生产模板部署多站时会落回不安全的 `true`。
- 修复：`.env.example` 显式 `SITE_DEFAULT_FALLBACK=true`（单站/开发，含英文说明）；`.env.production.example` 显式 `SITE_DEFAULT_FALLBACK=false`（多站生产安全默认，含英文说明）。照模板部署即可得到正确安全姿态。

### 11.4 Demo 数据一致性

- 修复：`DemoSeeder` 此前只更新 sites.metadata 不更新 `name`，导致 `migrate:fresh --seed` 后首页 `<title>` / `og:site_name` / `/geo.json` 的 site.name 是占位 “Default Site”，而内页标题为示例公司名，内外不一致。补齐 `name` 更新后全站一致（示例制造有限公司）；不影响 `geo:install` 空壳（仍为 Example Site / Default）。

### 11.5 异常日志审计

- 清空 `storage/logs/laravel.log` 后：① 跑完整全量回归，日志 **0 行**；② 干净日志窗口内真实 `serve` 抓取 11 个请求（含 404），日志 **0 字节**。HTTP 200 背后无隐藏 ERROR/WARNING/SQLSTATE/Deprecated。

### 11.6 独立 Gate 核验补漏（post-closure，只读复核发现并修复）

- 三 checkpoint 关闭后做独立证据复核，发现两处前序清洗漏网的**非 Runtime** 食品行业残留，已最小修复：
  1. `resources/views/site/_icon.blade.php` 图标库含一个 `chicken`（鸡形）SVG：既不在服务端白名单 `config/icons.php`、也不被任何 Example 数据或测试引用，属食品行业死图标——已删除其 SVG 与 name 列表项。
  2. `app/Http/Controllers/Site/PageController.php` 类注释用旧食品 slug `/products/chinese-marinade` 作 URL 分发示例——已改为通用工业产品 slug `/products/epoxy-primer-100`（仅注释，不含逻辑改动）。
- 修复后对 `app/ resources/ config/ database/seeders/` 复扫 `chicken / marinade / fried-chicken / Sample Snack / Sample Marinade` **0 命中**；全量回归复跑 **639 / 2898 / 0 / 0**。
- 保留并说明：`drumstick / shaker / jar / flask` 等图标在白名单内，label 已工业中立化（摇瓶=混合调配、棒件=成型加工、罐体=容器包装、烧瓶=研发），`shaker` 已被工业 Example 首页合理复用；其图形偏具象、未重画，列入 §16.6 技术债，不属业务数据污染。

### 11.7 Final Closure — 独立 Gate 核验（Historical Replay / Dead-Code / Exploratory HTTP）

> 本节对应 P-SANITIZE 被改判 CONDITIONAL 后的 9-STEP Final Closure：三项 REQUIRED（历史 checkpoint 重放、全量静态 dead-code、完整探索式 HTTP 重放）均**重新实际执行**，不引用前序结论。所有数字来自本轮真实执行；原始证据留存仓库外临时目录（`D:\734666\_replay\*`，fresh 环境由 `git archive` 生成、不含 `.git`，验收后可整体丢弃）。

#### A. Historical Checkpoint Replay（历史 checkpoint 逐点重放）

方法：真实 `git checkout` 每个锚点 commit 并运行当时的回归（不是只看 tag），随后做测试改弱审计（`git diff <old>..HEAD -- tests/`，对演进中消失的测试方法逐一比对）。

| Checkpoint | Commit | Tests | Assertions | Failed | Skipped |
| --- | --- | --- | --- | --- | --- |
| acceptance-baseline | `05a369e` | 571 | 2211 | 0 | 0 |
| checkpoint-product-step-10 | `96c4f98` | 607 | 2571 | 0 | 0 |
| checkpoint-sanitize | `4422ad0` | 632 | 2860 | 0 | 0 |
| checkpoint-standardize | `26dd7d5` | 632 | 2860 | 0 | 0 |
| checkpoint-bughunt | `53e22f9` | 639 | 2898 | 0 | 0 |
| **Final Closure（本提交）** | 见 §15/§F | **646** | **2929** | **0** | **0** |

- 测试改弱审计：演进中消失的 13 个测试方法逐一比对，全部为**合理泛化 / 架构收敛**（旧 Facts 直读断言被 Entity / Facts→Entity 迁移完整性 / Repository 测试取代），未发现删除边界条件、降低 assertion、修改 expected result 或绕过真实实现来换绿灯。
- 原始证据：`_replay_log.txt`、`_replay.ps1`、`_anchors.txt`、`_testweak2.txt`、`_gone.txt`、`_audit/bl/*`、`_audit/bg/*`。

#### B. Full Static Dead-Code Analysis（全量静态 dead-code 分析）

- 工具：基于 vendor 内 `nikic/php-parser` 自研 AST 扫描器做类 / 方法定义与引用计数；另核对路由动作、中间件、config key、Blade include、资源文件。范围：`app/ routes/ config/ database/ resources/ tests/ bootstrap/`。
- 局限（如实记录）：容器字符串绑定、动态 / 魔术调用、按约定解析的方法、以及需要 Tailwind purge 才知是否使用的静态 selector，纯静态扫描可能漏报。
- 结果：
  - **95 个类：0 dead**。
  - 约 200 个方法中，唯一能同时满足「无 runtime / test / migration / upgrade / 兼容 / 公共契约消费者」的真 dead 是 `SchemaBuilder::seo()`，**已删除**（−7 行）。
  - 83 个路由动作 `PROBLEMS: 0`；中间件、config、Blade、资源、命令、Repository 全部可达。
  - `CliSiteContext` / `QueuedBySite` 当前无业务消费者，但属多站 CLI / 队列生命周期的**安全护栏预留**，判定保留而非 dead。
- 配套补强：`EntityRepository` 增补 4 个剩余 Entity 类型快捷 helper 测试（+32 行）；`ContentGate::assertPassable()` docblock 订正。
- 原始证据：`_deadscan*.php`、`_deadscan_all.txt`、`_deadscan_methods2.txt`、`_routescan.txt`、`_dc_assets.txt`、`_dc_cfg.txt`、`_dc_blade.txt`。

#### C. Exploratory HTTP Replay（陌生用户视角，端到端）

环境：`git archive HEAD` 解压到全新目录（无 `vendor/ node_modules/ .env *.sqlite .git`，且 `docs/audit/` 被 export-ignore 实测不入包）→ `composer install --no-dev`（53 包）→ `geo:install`（空壳）→ `migrate:fresh --seed` 装载完整 Example 工业数据集；PHP 8.4.25 / Laravel 12 / 4-worker `artisan serve`。

**C.1 前台 crawl**：sitemap 27 个 URL 全 200；斜杠边界（列表 / 固定页无斜杠 301→带斜杠，详情相反）；旧链 `/scenarios`→`/solutions` 301；随机路由自定义 404（无堆栈 / 框架泄漏 / 客户词）；`/feed.xml`、`/search`、静态资源全 200；未登录后台 302→login；`POST /` 405；JSON-LD 类型正确；下载 HTML 业务污染 0。空壳安装 5 端点全 200、`/geo.json` entities/contents 全空（安装器不播种 Demo，契约正确）。

**C.2 后台真实 CRUD + 异常输入**（curl cookie jar + 每页新鲜 CSRF）：

- **Content**：8 例创建矩阵（正常 / XSS 标题 / 空标题 / 超长 / 中文 slug / `Bad_Slug!` / 重复 slug / 非法 type），6 个非法例全部 302 回退、未落库、无 500；XSS 标题 DB 原文存储、edit 输入框 Blade 转义为 `&lt;script&gt;`；发布门禁（四层 geo_* + ≥2 条带来源证据 + owner + reviewed_at 可发布；极限词「最好」可建 draft 但 publish 被拒）；软删后 edit 404；GET/PUT id=999999 404；门禁 JSON 返回 6 errors / 1 warning；md-preview 正确 HTML。
- **Category**：合法创建首次 **500**（根因与修复见 D.1），修复后创建 / 重复 slug / 空名 / toggle / 非法字段 / 删除保护 / 删除 / 404 全部正确。
- **Group**：新建 / 删除 / 非法 FK / 坏 slug 拒绝 / 有内容分组不可删。
- **Fact**：建改删 / 校验 / `flushMemo`。
- **Redirect**：建改、code=999 与空 from 拒绝；前台 `HandleRedirects` 实测 302（`/old-explore-2`→`/cooperation/`），改名后旧链 404。
- **Media**：1×1 PNG 上传成功；含 `onload`/`<script>` 的恶意 SVG 被 mimes 白名单拒绝、计数不增（防存储型 XSS）；PUT alt 成功。
- **Inquiry**：正常留言 302 落库（status=new、中文 demand_type「代工合作」UTF-8 正确、message 正确）；蜜罐 `website` 非空静默不落库；非法 phone 不落库；后台 handle→handled、非法 status 被拒、删除；前台 `/contact` 301→`/contact/`。
- **Narrative**：edit / PUT 生效、非法 key 404、DELETE reset 清空 slot。
- **Setting**：`site_slogan` 即时生效；`sync/regenerate-token` 生成 `yhf_` 前缀、长度 52 的 token。
- **Auth**：完整密码往返（改密→旧密码登录被拒→新密码登录→改回）；弱密码（无大写 / 数字）被 `Password::min(8)->mixedCase()->numbers()` 拒绝；登录限流 5 次 / 300s，第 6 次后渲染「尝试次数过多，请 N 秒后再试」（HTTP 302 回 login 带 flash）。
- 18 个后台页面 GET 全 200。
- 记录一个**客户端陷阱（非产品 Bug）**：`curl -L` 与同一 cookie jar 一起用于登录 POST 时，初始 POST 不带有效会话 Cookie 导致 419；改为不跟随或分离请求即正常，真实浏览器无此问题。

**C.3 多站往复 A→B→A→B→A→B**（A=default 域名空走 fallback、B=`b.test` 新建空站仅 1 篇文章）：

- DB 写入隔离：A/B 各行 slogan / site_name / theme / plugins 值正确；A_marker=[aaa]、B_marker=[bbb]、`A_owns_BBB=false`、`B_owns_AAA=false`。
- HTTP 首页（6 跳稳定）：A 仅见 `AAA Site Name Marker` + default 主题（无 `data-theme=example`）；B 仅见 `BBB Site Name Marker` + `data-theme="example"`。
- Content 文章：自有 `/{slug}` 200 且含正确标记（B `/bbb-marker-article` 200 含 BBB、A `/aaa-marker-article` 200 含 AAA）；**交叉访问一律 404**（A 看 bbb 404、B 看 aaa 404）。
- sitemap：host 归属正确（B 全 `b.test`、A 不含 `b.test`），A=27 loc、B=20 loc；`/geo.json` 按站隔离且往复稳定（A=8383 B、B=560 B）。
- 主题 per-site HTTP 生效（视图晚绑定，中间件 reapply 后渲染）。
- **但旧 Facts 路径出现跨站泄漏（D.2），插件路由出现时序 / 隔离缺陷（D.3）**。

**C.4 异常日志审计**：探索窗口 `storage/logs/laravel.log` 共 336 行，仅 2 条 ERROR，均为修复前 12:57:57 / 12:58:00 的 `table categories has no column named is_index`；执行修复迁移后，后续全部 Content / Category / Group / Fact / Redirect / Media / Inquiry / Narrative / Setting / Auth / 多站 / 主题 / 插件请求**零新增 ERROR / WARNING**。注意：HTTP 200 不等于无问题——D.2 / D.3 都表现为正常 200 / 404 而不写异常日志，是靠行为测试而非日志抓到的。

#### D. Issues Found（本轮新发现与处置）

**D.1【真实严重 Bug，已最小修复并回归】后台新建栏目必 500 —— `categories.is_index` 缺列**

- 根因：建表迁移 `2026_09_14_000001_create_categories_table` 只有 `is_nav/is_active`，**漏建 `is_index`**；而 `CategoryController::store`（create 默认 false、toggle 白名单、validate、boolean 赋值）、`category-form.blade.php`（「首页推荐」复选框）、`categories.blade.php`（toggle）都在使用，模型 `$casts` 也缺该列。全新部署后台对任何合法栏目输入的 INSERT 必 500，且无后台 category 写入测试覆盖。fresh 日志：`SQLSTATE[HY000]: General error: 1 table categories has no column named is_index`。
- 最小修复（未用 `after()` 以规避 SQLite 列位置问题）：
  1. 新增 `database/migrations/2026_09_20_000001_add_is_index_to_categories_table.php`：up 用 `Schema::hasColumn` 守卫加 boolean `is_index` default false（幂等），down `dropColumn`；
  2. `app/Models/Category.php` 的 `$casts` 增加 `'is_index' => 'boolean'`；
  3. 新增 `tests/Feature/AdminCategoryCrudTest.php`，6 测试 / 22 断言（带 is_index 创建、默认 false、非法 payload 拒绝且不落库、toggle 翻转、白名单外字段与 `deleted_at` 被拒且不改数据、有内容栏目 knowledge 不可删）。
- 验证：主项目聚焦 6/6 PASS；修复同步 fresh 后 `migrate` DONE、`hasColumn` 为 bool(true)，HTTP 复测合法创建 302→index（不再 500）、重复 slug / 空名 302→create、toggle / 非法字段 / 删除保护 / 删除 / 404 全部正确。

**D.2【架构级，本轮只记录、不擅自重构】旧 Facts 前台 Runtime 多站跨站数据泄漏（Issue B 的多站实证，严重度高）**

- 现象：B 是无任何产品 / 场景的空站，却在 `b.test` 域名下完整 **200** 渲染全局（default 站 config）产品列表 `/products/`、产品线 `/products/coatings/`、产品详情 `/products/epoxy-primer-100`（`<title>环氧富锌底漆 ZP-100…`、描述、工艺参数、FAQ、Product / HowTo / Breadcrumb JSON-LD 全部以 `b.test` 为 host 输出）、场景 `/solutions/equipment-manufacturing/`、固定业务页 `/cooperation/`（OEM/ODM 文案）；B 的 sitemap 20 loc 含全部全局产品 / 产品线 / 场景。页面**外壳**按站正确（BBB 站名、example 主题、canonical=`b.test`），但**业务数据内容全局共享**。
- 根因：`App\Support\Facts` 只从站点无关的 `config('facts')` 取数（类注释自述「后续前台展示层切换到 Entity / Content 后本类与 config('facts') 一并退场」）；前台产品 / 场景 / 固定页控制器、`SitemapBuilder`（`Facts::productLines()/productsByLine()/products()`）等走此路径，未接 BelongsToSite / SiteScope。新架构的 Content / Entity（DB + BelongsToSite）隔离正确（文章交叉 404）。
- 边界判定：**不是新架构多站隔离被打破**，而是「前台展示层尚未从 Facts 切到 Entity / Content」（已登记 Issue B）在真多站下的确定泄漏。其修复主体属于后续「前台 Entity/Content 化 + URL 统一」大阶段，超出本 Closure「只补证、不重构」授权。
- 影响 / 建议：定位为单站 / 同主体多语言站时可接受；定位为多租户多主体 SaaS 时为**阻塞项**。下一阶段须把产品 / 场景 / 固定页 / sitemap / schema 切到站点隔离的 Entity 查询，并新增「跨站产品 / 场景 / sitemap 不可见」的 HTTP 回归测试。

**D.3【架构级，本轮只记录、不擅自重构】per-site 插件路由 HTTP 不可达且无法按站隔离（新 Issue D）**

三组对照（每组之间干净重启 serve，排除 worker boot 缓存）：

1. 仅 B 启用 hello、A 停用：A ping 404（正确）、**B ping 404（错误，启用站自己也不可达）**；
2. 默认站 A 启用、B 也启用：A 200、B 200；
3. A 启用、**B 停用**后干净重启：A 200、**B 仍 200（未启用站也能访问）**。

- 根因：`ResolveSite` 虽是 web 组首个中间件，但 Laravel **路由匹配先于 web 中间件**；在中间件 `reapply()` 里 `PluginManager::register()` 注册的路由对当前请求已太晚（情形 1：非默认站启用，路由不可达）。而默认站在 `AppServiceProvider::boot()`（此刻站点惰性解析为 default）注册的路由进入**全局路由单例**——Laravel 路由无 per-site 概念、ServiceProvider 注册不可逆、ping 闭包无站点守卫，导致默认站启用即所有站可达（情形 3：隔离失效）。既有 `ProductizationAcceptanceTest` 是单站、在**同一进程** `enable()` 后立即 `get()`（应用实例复用、路由已入全局集合），因而通过，掩盖了真实 HTTP 时序。
- 建议（后续阶段，不在此重构）：在 boot 阶段**站点无关地注册全部「已安装」插件路由**，并在插件路由上挂 per-site 守卫中间件，运行时按 `SiteContext` + `PluginManager::isEnabled($slug)` 决定 404——把「注册（全局、尽早）」与「授权（按站、请求期）」分离。主题之所以 per-site 正常，是因为视图晚绑定（中间件 reapply 后渲染）；插件路由是早绑定，二者机制不同。本条同时把 §16.3 的常驻运行时限制扩展到了 FPM / serve 形态。

**D.4【使用约束级，记录】`SiteContext::withSite()` 同进程不复位 static memo（新 Issue E）**

- 现象：单进程内连续 `withSite(A)` 写、`withSite(B)` 写后，再 `withSite(A)` 读 Setting，会读到 B 的 slogan / theme / plugins（memo 脏读）；在每个 withSite 闭包内先 `RequestScopedState::flushAll()` 后 A/B 全部读对；**raw DB 行始终正确**（BelongsToSite 写入隔离无误）。
- 根因：`withSite()` 只切换 `$currentSite` 并在 finally 恢复，不调用 `flushAll()/reapply()`；Setting / Fact / Theme / Plugin 等 static memo 的复位只发生在 HTTP（ResolveSite reapply）与 Queue（QueuedBySite reapply）边界。
- 影响面：HTTP / Queue 主路径有保护（CrossSiteMemoLeakTest 覆盖 HTTP 往复，本轮 6 跳 HTTP 全对）；风险面是**不经中间件 / Queue、在同一进程直接连续 withSite 跨站读取 memo 服务**（CLI 批量、Admin 跨站、Job 内嵌套）。
- 建议（后续）：明确 withSite 契约——进入 / 退出时 `flushAll()`（数据 memo 隔离；但主题视图路径 / 插件 provider 不可逆，需配套 reapply 语义），或在文档与静态分析上约束「跨站边界统一走 `RequestScopedState::reapply()`」。本轮不改 withSite（影响测试 / 安装器 / 队列面广，flush 与 reapply 语义需专门设计）。

**D.5 经攻击 / 异常演练未发现问题的面**：XSS 标题 Blade 转义；恶意 SVG 上传拒绝；表单校验 / 发布门禁 / 极限词；重复 slug / 坏 slug / 非法 FK / 非法枚举拒绝；删除引用保护（栏目 / 分组有内容不可删）；Redirect 302 生效；登录限流与密码规则；蜜罐与非法留言不落库；未知 Host（fallback=false）404（见 §11.2）。

#### E. Final Regression

- 命令（主项目 `D:\734666\GEO OS`）：`php -d memory_limit=1G vendor/bin/phpunit`。
- 结果：**Tests 646 / Assertions 2929 / Failed 0 / Skipped 0**，进程 exit 0；结果行 `OK, but there were issues!` 仅指 85 条 PHPUnit 框架层 Deprecation。
- 较 STEP2 dead-code 基线 640 / 2907 净增 **+6 tests / +22 assertions**（即 AdminCategoryCrudTest）；`SchemaJsonLdTest` 仅把正向地址 fixture 由 Sample City / Sample Province 通用化为 Example City / Example Region（断言逻辑不变、仍通过）。全程未删测试、未降断言、未 skip、未改 expected 绕绿。
- fresh 端 is_index 修复后 HTTP 端到端复测通过（D.1）。

#### F. Final Git / 污染复扫 / 反向自审

- 本 Closure 改动（提交后 HEAD 见 §15）：
  - 新增 `database/migrations/2026_09_20_000001_add_is_index_to_categories_table.php`；
  - 修改 `app/Models/Category.php`（is_index cast）；
  - 新增 `tests/Feature/AdminCategoryCrudTest.php`（6 测试 / 22 断言）；
  - `app/Services/Geo/SchemaBuilder.php`（删除唯一确认为真 dead 的 `seo()`）；
  - `app/Services/Gate/ContentGate.php`（docblock 订正）；
  - `tests/Feature/EntityRepositoryTest.php`（+4 个类型 helper 测试）；
  - `tests/Feature/SchemaJsonLdTest.php`（地址 fixture 通用化）；
  - 本报告 `docs/audit/geo-website-os-sanitization-final.md`（export-ignore，不入发布包）。
- 二次污染复扫：`app/ config/ routes/ resources/ plugins/ scripts/ public（非 build）/ database（非 migrations）/ docs（非 audit）/ README / CHANGELOG / DEPLOY / composer.* / package*.json / .env* / release-manifest.json` 对全部业务词表 **0 命中**；`tests/` 仅余反向断言黑名单（防回归资产，保留）；豁免项 = `docs/audit/**`（export-ignore，`git archive` 实测不入包）+ 2 个开发期历史 migration（§2）。
- 反向自审（以第一次拿到源码的开发者视角）：install / bootstrap / theme / plugin / content / entity / seo / geo / upgrade / rollback 均可从源码直接运行；Runtime 与正式文档无法判断原客户身份，只看得出「一个带通用工业 Example 数据集的 CMS / GEO 产品」。来源痕迹仅存于不入发布包的 `docs/audit/` 与 2 个升级用历史 migration。
- 本 Closure **不宣称无 Bug**：D.1 已修复；D.2 / D.3 / D.4 为已实证、登记交后续架构阶段处理的问题（见 §16）。

## 12. Multi-Site Verification

- 往复切换 `Site A → B → A → B → A → B`（CrossSiteMemoLeakTest，覆盖 Content/Setting/Theme/状态恢复），无串站。
- 未知 / 停用 Host 安全矩阵（§11.2）在 HTTP kernel 与真实 serve 双路径验证。
- 既有隔离资产保持绿色：BelongsToSite / SiteScope / SiteContext、SeoMeta 三态绑定与跨站隔离、Entity/EntityRelation 跨站拒绝、Site-aware Cache、CLI `--site/--site-id` 显式无效失败。
- 审计确认：所有业务 Model 均 `use BelongsToSite`（仅 Site、User 例外）；SiteScope 仅缓存“表是否有 site_id 列”的结构布尔（与站无关）；SeoMetaResolver memo 全部按 site_id / 全局 media 主键复合 key。
- 无全局关闭 Site Scope 的 env 开关；`withoutSiteScope()` 维持系统级显式技术能力（SystemAuthorization 按 is_super_admin，邮箱本身不授权）。

## 13. Browser / HTTP Verification（真实浏览器/HTTP 视角）

基于独立临时库（完整 Example 库 + geo:install 空壳库）起真实 `artisan serve` 验证：

- **前台全站 crawl：28 个公开 URL 全部 200**；每页恰好 1 个 h1；JSON-LD 2–5 个；堆栈/框架泄漏特征（SQLSTATE / Stack trace / Whoops / Symfony / Illuminate）**0**；渲染 HTML 客户词扫描 **0**。
- **机器端点**：`/geo.json`、`/sitemap.xml`（20 loc）、`/llms.txt`、`/robots.txt`、`/feed.xml` 全 200；随机路由返回自定义 404 页（无泄漏、无客户词）。
- **后台真实登录**：GET 登录页取 CSRF → POST 登录 302→/admin；15 个后台模块页全 200；登录后从 create 页取新鲜 token 提交空 Content → **302 校验回退（非 500/419）**；错误密码访问 /admin 仍被拦截（302→login）；未登录访问 /admin 302→login；后台 HTML 客户词 0。
- **Fresh install**：`geo:install --site-name --site-domain --admin-email --admin-password -n` 非交互成功；安装后 `/`、`/geo.json`（site.name=Example Site，entities=0/contents=0 空壳）、sitemap/llms/robots/admin-login 全 200，零泄漏零客户词。
- **Upgrade**：`geo:version`（app.version=2.0.0，38 migrations，末迁移 drop_legacy_seo_columns）；`geo:upgrade` 在最新库幂等（`[skip] legacy SEO columns not present` / `Nothing to migrate` / exit 0）。Backup/Rollback 故障注入由 P-STEP 08 与自动化测试背书，本轮未重复破坏性演练。

## 14. Regression

| 节点 | Tests | Assertions | Failed | Skipped |
| --- | --- | --- | --- | --- |
| 产品化基线（P-STEP 13） | 609 | 2571/2600 | 0 | 0 |
| CP2 checkpoint-standardize | 632 | 2860 | 0 | 0 |
| CP3 #15 跨站 memo 修复后 | 635 | 2894 | 0 | 0 |
| **CP3 最终（含未知 Host 修复）** | **639** | **2898** | **0** | **0** |
| 独立核验补漏后复跑（§11.6） | 639 | 2898 | 0 | 0 |
| **Final Closure（is_index 修复 + dead-code 收口，§11.7）** | **646** | **2929** | **0** | **0** |

- 净增测试来自真实修复（CrossSiteMemoLeakTest +3、UnknownHostRejectionTest +4）与 CP1 ExampleDatasetIntegrityTest / CP2 标准化测试；未删除或弱化任何既有测试。
- 85 条 PHPUnit Deprecation 为测试框架层弃用提示，不计失败、不写入 laravel.log。
- 内存峰值约 78 MB（< 256 MB 约束）。

## 15. Git Checkpoints

| Checkpoint | Commit | 内容 |
| --- | --- | --- |
| checkpoint-sanitize | `4422ad0` | 业务数据/语义/资源清洗 + 通用 Example 数据（194 files，+2498/−5965） |
| checkpoint-standardize | `26dd7d5` | 品牌统一、四类标准文档、LICENSE/CHANGELOG、README（27 files，+1819/−1309） |
| 跨站 memo 修复 | `5a4363f` | RequestScopedState + 中间件 reapply + CrossSiteMemoLeakTest（5 files，+216/−24） |
| **checkpoint-bughunt** | 见 tag（本提交） | 未知 Host 404 安全修复 + UnknownHostRejectionTest、fallback 部署模板、DemoSeeder 一致性、本报告 |

## 16. Remaining Technical Debt（本总控不修，登记）

1. **Issue B — 前台展示层尚未切到新架构**：前台 Controllers/Blade 仍静态读 `config('facts')`，未读 DB Entity/Content；`/geo.json` 的 entities/relations/contents 为空（FeedController 读新模型，旧 config 数据未入 graph）；GenericUrlResolver 输出 `/article/{slug}`、entity 单数 `/product/{slug}`，而旧路由为 `/knowledge/{slug}`、复数 `/products/`，catch-all `PageController::dispatch` 过宽导致同文多 URL；canonical 双轨（旧 Facts 页跟随请求 host/scheme，首页与新 Content 走 GenericUrlResolver 的 https+site.domain）；固定页 title 站名重复。属后续“前台 Entity/Content 化 + URL 统一”阶段。**§11.7 D.2 已取得该问题的多站跨站泄漏实证**：空站 B 在 `b.test` 下完整 200 渲染全局产品 / 场景 / sitemap / schema（页面外壳按站正确、业务内容全局共享）。单站 / 同主体多语言形态不受影响；多租户多主体 SaaS 形态为**阻塞项**，须随本阶段把产品 / 场景 / 固定页 / sitemap / schema 切到站点隔离的 Entity 查询，并补「跨站产品 / 场景 / sitemap 不可见」HTTP 回归。
2. **Issue C — 后台 UI 缺口**：Entity / EntityRelation / SeoMeta / Site / Theme / Plugin 尚无后台管理界面。
3. **常驻运行时插件 ServiceProvider 不可逆**：Octane / 常驻 queue worker 跨站时无法卸载上一站 provider 注册的路由（Laravel 框架限制）。当前 PHP-FPM 每请求重建、无 Job 类、queue 为 sync/database，**当前不可达**；接入 Octane/常驻 worker 前需专门处理。
4. **fallback 内联默认字面不一致（小）**：`config/site.php` 与 ResolveSite 默认 true、`SiteResolver` L54 内联兜底 false；config 恒加载故运行时无分歧，建议后续统一。
5. **版本号不一致**：`config/geo.php` app.version=2.0.0 与 release tag `1.0.0-rc1` 未对齐，交 Release 阶段决定。
6. **图标集**：`config/icons.php` 的 shaker/drumstick/jar/flask/beaker 等 key 图形偏具象，但 label 已全部工业中立化（混合调配 / 成型加工 / 容器包装 / 研发 / 检测），不绑定行业；食品形死图标 `chicken` 已在 §11.6 删除。剩余具象图形（如 drumstick 棒件）尚未重画，后续可替换为更中性的工业线性图标。
7. **两个开发期 migration 豁免**（§2）：normalize_terms / add_slot 保留为旧库升级路径，fresh 不播种客户数据；若要求开源源码层也彻底无食品英文 slug/术语，需评估删除这两个开发期 migration（不可逆，需明确授权）。
8. **Issue D — per-site 插件路由 HTTP 时序与隔离缺陷**（§11.7 D.3，Final Closure 新发现，架构级、本轮不修）：非默认站启用插件时，中间件内注册路由已晚于路由匹配导致启用站自己 404；默认站在 boot 注册的插件路由进入全局路由单例且无 per-site 守卫，导致默认站启用即所有站可达（B 停用仍 200）。建议后续把「注册（boot 阶段站点无关、注册全部已安装插件路由）」与「授权（路由挂 per-site isEnabled 守卫中间件）」分离。
9. **Issue E — `SiteContext::withSite()` 同进程不复位 static memo**（§11.7 D.4，Final Closure 新发现，使用约束级）：同进程连续 withSite 跨站读 Setting/Fact/Theme/Plugin 等 static memo 会脏读上一站（闭包内先 `RequestScopedState::flushAll()` 即正确；raw DB 写入始终隔离）。HTTP（ResolveSite reapply）与 Queue（QueuedBySite reapply）主路径有保护，风险面为 CLI 批量 / Admin 跨站 / Job 内嵌套等不经中间件的同进程跨站读取；后续需明确 withSite 的 flush/reapply 契约。

## 17. Remaining Risk（对外发布前）

1. **git 历史客户二进制（Release Blocker，需授权）**：被 `git rm` 的 121.5MB zip、6 个 sqlite、客户图片仍存在于既有历史提交。公开 push / 打 v1.0.0 前必须重写历史（建议以产品化基线做单一 initial commit，或 git-filter-repo 移除对应路径）。当前 `git remote` 为空、未公开，具备处理条件。
2. **GitHub Actions 云端真实首跑（Release Blocker，需仓库地址与推送授权）**：CI 模板（PHP 8.4 matrix、manifest 由 CI 用 GITHUB_SHA 动态生成防自引用）已就绪并在本地 fresh composer install 验证，但云端 Runner 首次全绿（validate / install / check-platform-reqs / audit / geo:install / tests / HTTP smoke / artifact + SHA-256）尚未执行。
3. 在上述两项闭环前，准确状态为 **GEO OS RELEASE CANDIDATE READY（v1.0.0-rc1）**；云端 CI 全绿且历史清理完成后，才可提升为 **GEO OS PUBLIC RELEASE READY** 并打 v1.0.0。
4. 本报告不宣称“无 Bug”：已完成当前自动化与探索式测试范围内的系统性 Bug Hunt；Issue B/C 涉及的前台/后台深层行为尚未在新架构下端到端覆盖，应在对应阶段继续以 Gate + Evidence 方式推进。
