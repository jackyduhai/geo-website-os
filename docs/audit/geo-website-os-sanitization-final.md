# GEO Website OS — Sanitization Final Report

> 阶段：P-STEP 14 之后的「基础清洗、业务脱敏、标准统一与技术 Bug 修复」总控收口
> Checkpoint：`checkpoint-sanitize`（CP1）→ `checkpoint-standardize`（CP2）→ `checkpoint-bughunt`（CP3）
> 最终回归：**639 tests / 2898 assertions / 0 failed / 0 skipped**（85 条 PHPUnit 框架层 Deprecation，非失败、不写入应用日志）
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
| Browser / HTTP smoke（前台 28 URL + 后台 15 模块 + fresh install） | PASS |
| Installer / Upgrade 幂等 | PASS |
| Multi-Site 往复切换与未知 Host 安全边界 | PASS（本轮补实） |
| 全量回归 | **639 / 2898 / 0 / 0** |
| Working tree | clean（本 checkpoint 提交后） |

**本总控范围内结论：`GEO WEBSITE OS — SANITIZED PRODUCT BASELINE READY`。**

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

- 对外产品名统一为 **GEO Website OS**：APP_NAME、CLI 三命令输出、install/build 脚本、theme.json、DemoSeeder、composer（`geo-website-osos/geo-website-os`，MIT，PHP `^8.4`）、package.json、config fallback、README、CHANGELOG、LICENSE、错误/安装页、favicon/og 品牌图。
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

1. **Issue B — 前台展示层尚未切到新架构**：前台 Controllers/Blade 仍静态读 `config('facts')`，未读 DB Entity/Content；`/geo.json` 的 entities/relations/contents 为空（FeedController 读新模型，旧 config 数据未入 graph）；GenericUrlResolver 输出 `/article/{slug}`、entity 单数 `/product/{slug}`，而旧路由为 `/knowledge/{slug}`、复数 `/products/`，catch-all `PageController::dispatch` 过宽导致同文多 URL；canonical 双轨（旧 Facts 页跟随请求 host/scheme，首页与新 Content 走 GenericUrlResolver 的 https+site.domain）；固定页 title 站名重复。属后续“前台 Entity/Content 化 + URL 统一”阶段。
2. **Issue C — 后台 UI 缺口**：Entity / EntityRelation / SeoMeta / Site / Theme / Plugin 尚无后台管理界面。
3. **常驻运行时插件 ServiceProvider 不可逆**：Octane / 常驻 queue worker 跨站时无法卸载上一站 provider 注册的路由（Laravel 框架限制）。当前 PHP-FPM 每请求重建、无 Job 类、queue 为 sync/database，**当前不可达**；接入 Octane/常驻 worker 前需专门处理。
4. **fallback 内联默认字面不一致（小）**：`config/site.php` 与 ResolveSite 默认 true、`SiteResolver` L54 内联兜底 false；config 恒加载故运行时无分歧，建议后续统一。
5. **版本号不一致**：`config/geo.php` app.version=2.0.0 与 release tag `1.0.0-rc1` 未对齐，交 Release 阶段决定。
6. **图标集**：`config/icons.php` 部分 key（shaker/drumstick/flask/jar）偏具象，CP2 仅中立 3 个 label，未重设计。
7. **两个开发期 migration 豁免**（§2）：normalize_terms / add_slot 保留为旧库升级路径，fresh 不播种客户数据；若要求开源源码层也彻底无食品英文 slug/术语，需评估删除这两个开发期 migration（不可逆，需明确授权）。

## 17. Remaining Risk（对外发布前）

1. **git 历史客户二进制（Release Blocker，需授权）**：被 `git rm` 的 121.5MB zip、6 个 sqlite、客户图片仍存在于既有历史提交。公开 push / 打 v1.0.0 前必须重写历史（建议以产品化基线做单一 initial commit，或 git-filter-repo 移除对应路径）。当前 `git remote` 为空、未公开，具备处理条件。
2. **GitHub Actions 云端真实首跑（Release Blocker，需仓库地址与推送授权）**：CI 模板（PHP 8.4 matrix、manifest 由 CI 用 GITHUB_SHA 动态生成防自引用）已就绪并在本地 fresh composer install 验证，但云端 Runner 首次全绿（validate / install / check-platform-reqs / audit / geo:install / tests / HTTP smoke / artifact + SHA-256）尚未执行。
3. 在上述两项闭环前，准确状态为 **GEO OS RELEASE CANDIDATE READY（v1.0.0-rc1）**；云端 CI 全绿且历史清理完成后，才可提升为 **GEO OS PUBLIC RELEASE READY** 并打 v1.0.0。
4. 本报告不宣称“无 Bug”：已完成当前自动化与探索式测试范围内的系统性 Bug Hunt；Issue B/C 涉及的前台/后台深层行为尚未在新架构下端到端覆盖，应在对应阶段继续以 Gate + Evidence 方式推进。
