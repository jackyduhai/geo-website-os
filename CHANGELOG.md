# Changelog

All notable changes to **GEO Website OS** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/).

> Pre-productization development history (from the source business project) is archived under `docs/audit/history/` and is not distributed in release artifacts.

## [1.4.0] - 2026-10-28

跨数据库兼容性正式版本：SQLite / PostgreSQL / MySQL 同一迁移集，真实并发与升级/回滚演练落地，并包含多项安全修复与后台体验改善。

### Added

- **跨数据库兼容性（V1.4）**
  - 三库（SQLite / PostgreSQL / MySQL）使用**同一迁移集（62 个）**与同一安装器（`geo:install`）。
  - 新增 CI `PostgreSQL 16` job（`postgres:16` 服务容器，零失败 + 数量下限门禁）。
  - MySQL 8.4 全量实测通过（迁移/安装/回归/并发/演练），维持**候选支持**（无 CI 门禁）。
  - 真实多进程并发套件（独立进程 + 独立数据库连接）。
  - 升级 / 故障恢复 / 回滚 / 原生备份恢复演练脚本 `scripts/audit/verify-upgrade-rollback.php`。
- **`contents.slot` 唯一约束三库统一收紧为站点级**
  - `2026_10_27_000001_tighten_contents_slot_unique_to_site_scope`：MySQL / PG 由
    `UNIQUE(site_id, slot, locale)` 收紧为 `UNIQUE(site_id, slot)`，与 SQLite 现状及项目契约一致。
  - 升级前探测存量冲突：存在「同站同 slot、不同 locale」行则**中止报错**，不自动删改数据。
- **跨库 API 差异收敛**
  - `GuardsNumericRouteBinding`（`BaseModel`）：非数字整型片段统一 404（修复 PG `22P02` → 500）。
  - `CurrentDatabaseTables`：收敛「当前库表清单」跨驱动口径（MySQL 不再返回其他库表）。
  - `MigrationPortability`：PG 外键开关改 no-op（`session_replication_role` 为 `PGC_SUSET`）。
- **事实与 GEO**
  - 事实编辑路径保持**行自身 locale** 并广播共享列（`FactLocaleUpdateTest`）。
  - 事实值校验与 GEO 空输出过滤（`FactValidationTest`）。
- **模板激活并发修复（W3）**：统一激活服务（站点行锁 + 整体事务 + 提交后可恢复缓存失效），
  并发激活与中途异常恢复均有回归（`TemplateActivationConcurrencyW3NVTest`）。
- **后台体验批次**：表单/实体/区块等术语中文化、初始化向导（6 步）、媒体选择器、图标网格、
  select 输入层白名单加固（`BlockSelectWhitelistTest`）、站点切换 UX 等。
- **安全**
  - `symfony/http-foundation` 升级至 7.4.19（阻断 CVE-2026-108082）。
  - `SafeUrl` 回环主机（localhost / 127.0.0.1 / ::1）降级为站内相对路径。
  - JSON-LD 输出 `</script>` 转义（防存储型 XSS，20A P1 修复，v1.0.0 之后随本版本发布）。

### Fixed

- 修复 8 个被 SQLite 基线掩盖的真实跨库缺陷：唯一约束冲突后事务语义（PG 25P02）、
  「取第一条」行序依赖、`getTableListing()` 口径、数值路由 500、事实编辑语言语义等，
  每个修复均有变异测试证明新测试「咬得住」。
- FTS5 清空后索引损坏的维护口径（`INSERT INTO <fts5表>(<fts5表>) VALUES('rebuild')`）。

### Changed

- 测试基线：v1.0.0（1410 tests / 7349 assertions）→ **v1.4.0（1761 tests / 9470 assertions）**，
  0 failures / 0 errors / 0 skipped（SQLite 全量实测）。
- 迁移数：56 → 62。

### Known limitations

- MySQL 为**候选支持**（实测通过，无 CI 门禁）；PostgreSQL / MySQL 全量回归不进 CI
  （CI 只跑 `PgsqlCore` 子集，≥174 例）。
- 9 个用例仅 SQLite 验证（站点创建生产等价 7 项 + 升级/回滚 E2E 2 项），PG / MySQL 上零证据。
- `php artisan geo:version` 输出 `app.version=1.0.0`（`config/geo.php` 默认值），与 v1.4.0 标签
  不一致，属已知版本口径待办。
- Admin UI 仅中文；`settings` 同站跨语言共用 4 键；分类/分组为字段级 `name_en` 翻译。

## [1.0.0] - 2026-09

v1.0 正式 release（架构冻结 + RB-1 修复）。在 rc1 基础上完成 Entity 扩展、产品能力补全、架构冻结与发布前审查。

### Added

- **Entity 类型扩展（18R）**
  - 新增 `case_study`（客户案例，自定义 CaseStudy Schema）与 `download_asset`（下载资料，非公开）两种 Entity 类型，总数从 6 扩展至 **8**。
  - `EntityCapabilityRegistry`：统一能力/必备声明契约，消费方一律经 Registry，禁散点 `if($type===...)`。
  - CaseStudy 前台闭环：`/cases` 列表 + `/cases/{slug}` 详情，含 CaseStudy JSON-LD、五要素发布门禁（TD-156）。
  - DownloadAsset 最小 Admin 面板。
- **产品能力补全（18S）**
  - Setup Wizard（6 步首跑编排）：`admin.wizard`，引导站点/事实/外观/管理员初始化。
  - GEO Health Dashboard（5 检查）：`admin.geo.health`，运行时语义健康只读聚合。
  - Entity Coverage Dashboard（齐备率）：`admin.geo.coverage`，资产齐备度只读聚合。
  - 中文 slug 空串兜底（TD-161，EntitySlug creating 钩子）。
- **架构冻结（18T）**
  - 七契约面冻结：Entity 8 型 / Registry 声明 / 五型关系（produces/offers/uses/located_in/related_to）/ PublicIndex 公开口径 / SEO-GEO 输出 / Admin 三看板 / PublicUrl 落地页裁决。
  - i18n 分维度统一口径：英文前台+SEO+搜索完整（18F PASS 保留）；/en/geo.json 实体图未本地化（TD-163 P2）；Admin UI 仅中文（TD-164 P2，v1.1）。
  - 新增 TD-163~166。
- **发布前审查与修复（19A）**
  - RB-1 P0 修复：`localized_route()` / `PublicUrlLocalized()` 从 routes/web.php 移入 `app/Support/helpers.php`（composer autoload.files），修复生产 route:cache 下 /contact 500。
  - 版本号同源对齐：config/geo.php 默认 1.0.0。
  - 新增 RouteCacheHelperRegression19ATest 防回归（6 项）。

### Fixed

- RB-1：生产模式 route:cache 后 /contact、/en/contact 及含 dynamic_form 的页面 500（Call to undefined function localized_route）。根因为 helper 定义在 routes/web.php，route:cache 后该文件不被加载。
- config/geo.php 默认版本号从 2.0.0 修正为 1.0.0。
- README / CHANGELOG 实体类型数从 6 修正为 8。

### Known limitations (v1.1 planned)

- `/en/geo.json` 实体图/contents 未本地化（TD-163）。
- Admin UI 仅中文，无语言切换（TD-164）。
- `geo:upgrade` / `geo:backup` / `geo:rollback` 无 E2E 自动化测试（TD-168，Release Evidence Gap）。
- CI release manifest 测试数硬编码，长期应改为动态读取（TD-170）。
- `scenario` = `service` 半虚拟别名，启用 recommended coverage 前须统一（TD-166）。

## [1.0.0-rc1] - 2026-09

First release candidate of the productized, business-neutral GEO Website OS.

### Added

- **Multi-site core**
  - Host-based `SiteResolver`, `SiteContext` / global `SiteScope`, and the `BelongsToSite` model contract with automatic site binding.
  - Site-aware cache keys; public requests cannot switch sites via query parameters.
  - CLI site selection via `--site` / `--site-id` (invalid/missing targets fail explicitly); queued jobs serialize `site_id`, restore context before run, and clear it in `finally`.
  - System/admin authorization boundary for cross-site access; no global switch to disable site scoping.
  - Configurable unknown-host behavior (`SITE_DEFAULT_FALLBACK`) for single-site development vs. multi-site production.
- **Entity architecture**
  - Six generic entity types: `organization`, `person`, `product`, `service`, `location`, `topic`; typed `EntityRelation` graph.
  - Database-enforced unique constraints and foreign keys; metadata/slug/lifecycle support; scope-safe `EntityRepository`.
  - Idempotent, zero-loss migrations from legacy Facts to Entities/Relations and from cases to Content.
  - Generic industrial Example dataset (no real-client data).
- **SEO / GEO**
  - `SeoMeta` three-state binding (Site / Content / Entity) via a table-level `CHECK` constraint and partial unique indexes.
  - `SeoMetaResolver` with per-resource inheritance chains; Content and Entity resolved as parallel resources.
  - `UrlResolverInterface` / generic resolver: HTTPS canonical URLs, query strings stripped, consistent trailing-slash rules, no cross-site canonicals.
  - Builders for per-page `schema.org` JSON-LD, `/geo.json`, `/llms.txt`, `/sitemap.xml`, `/feed.xml`, and `/robots.txt` from one shared data source.
- **Themes & plugins** — per-theme view overrides through a documented contract; runtime plugin enable/disable.
- **Lifecycle tooling** — `geo:install` (clean bootstrap), `geo:upgrade` (ordered, idempotent backfill + migrate + verify), `geo:version`, plus backup/restore and release build scripts.
- **Admin console** — sites, content, entities, relations, media, navigation, SEO, GEO preview, inquiries, and governance.
- **Productization & sanitization**
  - Removed all specific-client data, branding, and contact details from runtime code and default data.
  - Neutral design tokens and the GEO Website OS Design System; standards docs for code/HTML/CSS.
  - Reverse business-pollution and generic-deployment tests that run in CI.
- **CI / release** — clean-runner workflow: install, platform checks, dependency audit, full tests, HTTP smoke, and a versioned artifact with CI-generated manifest (commit == tag target == artifact source) and SHA-256.

### Security

- Multi-site isolation enforced at both the database and query layers; cross-site reads/writes/relations are rejected.
- Cross-site ("system") data access requires explicit administrator authorization; there is no environment-level scope bypass.
- Debug details, stack traces, SQL, and user data are never exposed on error pages.

### Notes

- The hosted CI workflow is defined but its first run against a cloud runner is pending a configured git remote and tag push; local and clean-environment runs are verified.
