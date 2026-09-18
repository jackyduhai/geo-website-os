# Gate 1 Step 0–4 Final Audit Report

## 1. Executive Summary

当前系统是一个 **Laravel 12 + Blade SSR + SQLite 的 GEO 原生 CMS**，核心引擎质量良好（PageCache、SchemaBuilder、LlmsBuilder、SitemapBuilder 均为真实实现），但存在 **P0 环境阻塞**（PHP 8.1.34 < vendor 要求 8.4.1，artisan 完全无法运行）和 **深度业务耦合**（config/facts.php 155处关键词命中，Facts:: 被10个文件调用66次，8个migration含业务数据，GEO Builder 多处硬编码）。

审计过程严格遵守 READ ONLY，未修改任何业务代码（最终 git diff 确认 app/config/routes/database/resources/public/tests 零变更）。

**是否允许进入 Step 5：NO** — 测试基线 BLOCKED，环境基线 PARTIAL，必须先解决 PHP 8.4 + Composer 环境。

## 2. Environment

| Item | Value | Status |
|------|-------|--------|
| PHP | 8.1.34 (ZTS x64) | **BLOCKED** (requires >= 8.4.1) |
| PHP SAPI | cli | VERIFIED |
| Composer | NOT INSTALLED | **BLOCKED** |
| Laravel | 无法获取 (artisan blocked) | BLOCKED |
| Node | v22.23.2 | VERIFIED |
| npm | 10.9.8 | VERIFIED |
| node_modules | 不完整 (6个依赖 UNMET) | PARTIAL |
| OS | Windows | VERIFIED |
| Git | 2.53.0.windows.2 | VERIFIED |
| Database | SQLite | VERIFIED |

**P0 Evidence**: `vendor/composer/platform_check.php:22` 要求 PHP >= 8.4.1，`php artisan --version` 返回 Fatal error。

## 3. Git Baseline

| Item | Value |
|------|-------|
| Commit | `ae3fc4ff9f4831ee3c9e0ab3d14329d7b9bdbb3f` |
| Branch | master |
| Tag | gate1-pre-change (lightweight) |
| Tag commit | ae3fc4f |
| Working tree | clean (仅 docs/ 为新增未跟踪) |
| Repository initialized | 是（原项目非 git 仓库，本次首次初始化） |

## 4. Test Baseline

**STATUS: BLOCKED**

- `php artisan test` → 无法执行（artisan Fatal error）
- `vendor/bin/phpunit` → 未执行（同样会触发 platform check）
- 测试文件数：25（Feature 22 + Unit 3，实际统计）
- 测试基线数字：N/A

**Resolution**: 安装 PHP >= 8.4.1 + Composer → composer install → 重新执行测试。

## 5. Database Baseline

| Item | Value |
|------|-------|
| Driver | SQLite |
| File | database/database.sqlite |
| Size | 573,440 bytes (~560KB) |
| Tables | 23 |
| Total rows | 224 |
| Schema export | docs/audit/baseline-schema.sql (纯 PHP PDO 导出) |

**表行数分布**:
users(1), contents(13), categories(14), facts(23), settings(67), page_blocks(16), media(12), groups(9), audit_logs(26), banners(3), menus(1), cache(8), sessions(5), migrations(26), 其余空表。

## 6. File Baseline

| Category | Count |
|----------|-------|
| Total files (excl .git/node_modules/vendor/storage cache) | 488 |
| PHP files (non-blade) | 153 |
| Blade files | 79 |
| JS files | 3 |
| CSS files | 2 |
| Migration files | **26** (非之前推测的28) |
| Seeder files | 5 |
| Test files | **25** (非之前推测的26) |
| Config files | 16 |
| Route files | 4 |
| Controller files | 29 |
| Model files | 15 |
| Service files | 5 |
| Support files | 7 |
| Middleware files | 7 |
| Hashed files | 233 |

## 7. Business Coupling

### 7.1 Keyword Scan (413 hits)

| Keyword | Hits |
|---------|------|
| Sample Marinade | 230 |
| Sample Snack | 210 |
| Example | 140 |
| Sample City | 105 |
| Sample Province | 24 |
| Example | 14 |

**Top 15 文件**:
1. config/facts.php (155)
2. config/pages.php (42)
3. database/seeders/ContentSeeder.php (23)
4. database/seeders/SettingSeeder.php (23)
5. config/copy.php (21)
6. resources/views/site/home/hero.blade.php (14)
7. database/seeders/FactSeeder.php (13)
8. resources/views/layouts/site.blade.php (10)
9. app/Services/Geo/SchemaBuilder.php (10)
10. app/Services/Geo/LlmsBuilder.php (9)
11. database/seeders/StructureSeeder.php (7)
12. app/Http/Controllers/Site/HomeController.php (7)
13. app/Http/Controllers/Site/ProductController.php (7)
14. resources/views/admin/auth/login.blade.php (6)
15. app/Http/Controllers/Site/AboutController.php (6)

### 7.2 Core Pollution Scan

**STATUS: N/A** — `app/Core/` 目录不存在。当前为 v0.x 扁平结构（app/Http, app/Models, app/Services, app/Support）。Core 层是 v1.2 目标架构，尚未创建。

### 7.3 Config Audit

6个业务配置文件：facts.php（极高风险）、pages.php（高）、copy.php（中）、home_blocks.php（中）、icons.php（低）、geo.php（低）。10个框架配置文件无业务数据。

### 7.4 Facts Dependency

- `Facts::` 调用：**66处**
- `use App\Support\Facts`：**10个文件**（7个Site Controller + 3个GEO Builder）
- `config('facts')`：13处（全部在 app/Support/Facts.php 内部）
- `CORE_PRODUCTS` 常量：定义在 Facts.php:19，被 isCoreProduct() 使用
- 路由间接依赖：web.php L47-49 从 Facts:: 生成 slug 白名单正则

### 7.5 Other Business Dependencies

| Class | Callers | Future Replacement |
|-------|---------|-------------------|
| Copy | InquiryController, AppServiceProvider | settings 表 |
| Narrative | 6个Site Controller + NarrativeController + Content model | Content model + settings |
| HomeBlockDefaults | HomeController (8处) | page_blocks 表 + ExampleSeeder |
| ExampleUrlGenerator | AppServiceProvider (注册) | SeoUrlGenerator (重命名+通用化) |

### 7.6 Routes Coupling

- 路由静态定义（无动态注册）✅ 符合 v1.2 方向
- slug 白名单来自 Facts::（需改为查库）❌
- URL 前缀硬编码 /products/ /solutions/ /knowledge/（需可配置）❌
- catch-all 路由存在（可保留）✅
- 历史 URL /scenarios → /solutions 301（需迁入 redirects 表）⚠️

### 7.7 Blade Hardcoding

- SEO/GEO meta 命中：15处
- **无 ld+json 硬编码**（Schema 全部由 SchemaBuilder 生成）✅
- 业务名称/产品/地址硬编码主要在 hero.blade.php 和 site.blade.php

## 8. Dependency Graph

### 当前架构
```
Request → Route (Facts生成白名单) → Middleware → Controller (7个use Facts)
  → Facts:: / Fact / Setting / Content / Category / PageBlock
  → GEO Engine (Facts + Setting + 硬编码)
  → Blade → Response (PageCache)
```

### v1.2 目标
```
Request → Stable Route → SeoUrlResolver → Entity/Content
  → SeoMeta (继承链) → GEO Engine (Entity + Relation + Site, 无硬编码)
  → Blade → Response
```

### 关键 GAP
- 无 Site/Entity/EntityRelation/SeoMeta 模型
- 无 Core/Cms 目录分层
- GEO 数据含硬编码（Sample City/Sample Province/Sample SnackSample Marinade/Example）
- URL slug 来自代码而非数据库

## 9. GEO Architecture

### SchemaBuilder
- 数据来源：Fact::publicMap() + Setting::allCached() + Facts::company() + Facts::salesRegions() + config('app.url')
- 硬编码：L44 公司名兜底, L83-84 Sample City市/Sample Province省, L134 knowsAbout数组, L145/L192 Example品牌名
- 有 fallback 链：setting → fact → 硬编码（但兜底是业务名，需改为通用兜底）

### LlmsBuilder
- 数据来源：重度依赖 Facts:: (company/brandLanguage/productLines/products/scenes/sceneCombo/cooperation/salesRegions) + Content::published() + Group::knowledgeChannels()
- 硬编码：L27-30 公司描述段落, L40/L134/L144 Example品牌名
- 风险最高：llms.txt 内容几乎完全由 Facts:: 驱动

### SitemapBuilder
- 数据来源：Facts::productLines/products/isCoreProduct/scenes + Category + Content::published()
- URL 前缀硬编码：/products/ /solutions/ /knowledge/
- 无硬编码业务值，但 URL 结构不可配置

### GEO Audit
- **不存在**（v1.2 目标能力，当前未实现）

## 10. SEO Architecture

- Title/Description：由 Controller 从 Content/Setting 读取，注入 Blade
- Canonical：CanonicalizeSlash 中间件处理尾部斜杠
- Robots：robots.txt 动态生成
- Sitemap：SitemapBuilder 动态生成
- OG：Blade 中 og: meta 标签（15处命中）
- Structured Data：SchemaBuilder 生成，Blade 中无硬编码 ld+json ✅
- Redirect：HandleRedirects 中间件 + redirects 表
- 301 历史 URL：/scenarios → /solutions（硬编码在路由中）

## 11. URL Architecture

当前：路由静态定义 + Facts:: 生成 slug 白名单正则 + URL 前缀硬编码
目标：稳定路由 + SeoUrlResolver 查 entities 表 + 可配置 URL 前缀
GAP：slug 来源（代码→数据库）、URL 前缀（硬编码→可配置）、历史URL（路由→redirects表）

## 12. Migration Architecture

- 总数：**26个**（实际统计，非28）
- Schema-only：15个
- Data-containing：**11个**（8个seed/历史 + 3个小修改含数据操作）
- 含业务数据的8个：seed_home_builder_blocks, seed_problems_differentiators, v06_ia_theme_blocks, normalize_terms, v07_home_cases_knowledge_groups, seed_mid_banner_block, backfill_workshop_text, retire_legacy_blocks_and_groups
- 目标 Migration 设计：7个（sites, add_site_id, entities, entity_relations, seo_metas, add_role, backup_logs可选）
- 最大风险：M2 add_site_id 在 SQLite 下需重建14张表（SQLite 不支持 ADD COLUMN FK / DROP COLUMN）

## 13. Rollback

| Item | Status | Evidence |
|------|--------|----------|
| Git tag | VERIFIED | gate1-pre-change → ae3fc4f |
| DB backup | VERIFIED | 573,440 bytes, 23表224行, PDO可读 |
| Code backup | VERIFIED | 127,387,440 bytes, 4214 entries, ZIP有效 |
| Business code unchanged | VERIFIED | git diff -- app config routes database resources public tests = 空 |

## 14. Critical Risks

### P0 (2)
1. **PHP 8.1.34 < 8.4.1** — artisan/phpunit/migrate 全部无法运行，测试基线无法建立
2. **Composer 不可用** — 无法重新安装依赖，无法 composer show

### P1 (6)
1. **config/facts.php 深度耦合** — 155处关键词命中，66处Facts调用，是最大业务耦合点
2. **8个migration含业务数据** — 全新安装会自动灌入Example数据
3. **GEO Builder 硬编码** — SchemaBuilder/LlmsBuilder 含Sample City/Sample Province/Sample Snack/Example硬编码
4. **路由 slug 白名单来自代码** — web.php 从 Facts:: 生成正则，非数据库查库
5. **node_modules 不完整** — 6个依赖 UNMET，前端构建无法进行
6. **原项目非 git 仓库** — 无版本历史，已初始化但仅有1个commit

### P2 (4)
1. ExampleUrlGenerator 命名含业务名，需重命名为 SeoUrlGenerator
2. Copy/Narrative/HomeBlockDefaults 三个 Support 类含业务默认值
3. config/icons.php 产品图标映射为业务数据
4. login.blade.php 含业务名称（6处命中）

## 15. Audit Coverage vs Gate Readiness

### 15.1 Audit Coverage Checklist (18/18)

审计工作覆盖度：**18/18 项已执行**。注意："已执行"不等于"通过"。

| # | Audit Item | Coverage | Result | Evidence |
|---|-----------|----------|--------|----------|
| 1 | Git baseline | COMPLETED | VERIFIED | baseline-commit.txt, commit ae3fc4f |
| 2 | Test baseline | COMPLETED | **BLOCKED** | PHP 8.1 < 8.4.1, artisan Fatal |
| 3 | Environment baseline | COMPLETED | PARTIAL | PHP/Node/npm VERIFIED, Laravel/Composer BLOCKED |
| 4 | Schema baseline | COMPLETED | VERIFIED | baseline-schema.sql, 23表224行 |
| 5 | File baseline | COMPLETED | VERIFIED | baseline-files.txt, 488文件+统计 |
| 6 | Hash baseline | COMPLETED | VERIFIED | baseline-hashes.txt, 233文件 |
| 7 | Business coupling scan | COMPLETED | VERIFIED | business-keyword-scan.md, 413命中 |
| 8 | Core pollution scan | COMPLETED | N/A | app/Core/ 不存在，有依据 |
| 9 | Config audit | COMPLETED | VERIFIED | config-business-data.md, 6业务配置 |
| 10 | Facts dependency graph | COMPLETED | VERIFIED | facts-dependency.md, 66调用10文件 |
| 11 | GEO data source graph | COMPLETED | VERIFIED | dependency-graph.md, 3个Builder分析 |
| 12 | URL data flow | COMPLETED | VERIFIED | routes-coupling.md, 当前vs目标GAP |
| 13 | Migration inventory | COMPLETED | VERIFIED | 26个migration, 11个含数据 |
| 14 | Migration plan | COMPLETED | VERIFIED | migration-plan.md, 7个目标migration |
| 15 | Git rollback point | COMPLETED | VERIFIED | tag gate1-pre-change |
| 16 | DB backup | COMPLETED | VERIFIED | gate1-pre-change.sqlite, 已验证可读 |
| 17 | Code backup | COMPLETED | VERIFIED | gate1-pre-change.zip, 4214 entries |
| 18 | All audit docs | COMPLETED | VERIFIED | 17个文档/备份全部生成 |

**Audit Coverage: 18/18 COMPLETED**
**Result breakdown: 15 VERIFIED / 1 N/A / 1 PARTIAL / 1 BLOCKED**

### 15.2 Gate Readiness

Gate 1 Step 0–4 通过条件：18 项全部为 VERIFIED（或有依据的 N/A）。当前存在 1 项 BLOCKED + 1 项 PARTIAL，因此：

**Gate Readiness: BLOCKED**

阻塞项：
- Test baseline = BLOCKED（PHP 8.1.34 < 8.4.1，artisan 无法启动，测试基线无法建立）
- Environment baseline = PARTIAL（Composer 未安装，Laravel 版本无法获取）

## 16. Final Decision

**Audit Status: COMPLETED (18/18 coverage)**

**Gate Status: BLOCKED**

**Step 5 = NOT ALLOWED**

### Blocker
- PHP runtime incompatible (8.1.34 < 8.4.1 required by vendor)
- Composer not installed
- 测试基线无法建立

### Required Action Before Step 5
1. 安装 PHP >= 8.4.1 (Non-Thread Safe, Windows) 到系统 PATH
2. 安装 Composer
3. 执行 `composer install` 重新生成 vendor
4. 验证 `php artisan --version` 正常输出
5. 执行 `php artisan test` 建立真实测试基线
6. 更新 baseline-test.txt 和 baseline-environment.md

### Verification Method
- `php -v` 显示 >= 8.4.1
- `composer --version` 正常
- `php artisan --version` 显示 Laravel 12.x
- `php artisan test` 输出真实测试数字
- 所有18项 Gate 条件变为 VERIFIED

## 17. Audit Artifacts

```
docs/audit/
├── baseline-commit.txt          ✅
├── baseline-test.txt            ✅ (BLOCKED记录)
├── baseline-environment.md      ✅
├── baseline-schema.sql          ✅
├── baseline-files.txt           ✅
├── baseline-hashes.txt          ✅
├── business-keyword-scan.md     ✅
├── config-business-data.md      ✅
├── facts-dependency.md          ✅
├── routes-coupling.md           ✅
├── blade-hardcoding.md          ✅
├── dependency-graph.md          ✅
├── migration-plan.md            ✅
├── gate1-pre-change.md          ✅
├── gate1-pre-change.sqlite      ✅ (DB backup)
├── gate1-pre-change.zip         ✅ (Code backup)
└── gate1-final-report.md        ✅ (本文件)
```

## 18. 15 Questions Answers

1. **当前 Git commit**: ae3fc4ff9f4831ee3c9e0ab3d14329d7b9bdbb3f
2. **测试通过/失败**: BLOCKED — artisan 无法运行，无测试数字
3. **未提交业务修改**: 无（git diff 业务目录为空，仅 docs/ 新增）
4. **多少 migration**: 26个（实际统计）
5. **当前数据库**: SQLite, 23表, 224行, 560KB
6. **哪些 migration 含业务数据**: 8个（seed_* 3个 + v06/v07 2个 + normalize/backfill/retire 3个）
7. **业务数据分散在哪**: config/facts.php(最大), config/pages.php, config/copy.php, config/home_blocks.php, config/icons.php, 8个seed migration, 5个seeder, SchemaBuilder/LlmsBuilder硬编码, Blade模板
8. **Facts 被谁调用**: 10个文件（7个Site Controller + 3个GEO Builder），66处调用，路由间接依赖
9. **Core 业务污染**: N/A（app/Core/ 不存在）
10. **CMS 业务污染**: N/A（app/Cms/ 不存在，当前 Models 在 app/Models/）
11. **GEO Engine 真实数据来源**: Fact表 + Setting表 + Facts::(config) + 硬编码（Sample City/Sample Province/Sample Snack/Example）
12. **URL 与目标差异**: 当前slug白名单来自Facts代码，目标为数据库查库；当前URL前缀硬编码，目标可配置
13. **最大迁移风险**: SQLite 不支持 ADD COLUMN FK / DROP COLUMN，add_site_id 需重建14张表
14. **回滚点是否完整**: Git tag ✅ + DB备份(已验证) ✅ + Code备份(已验证) ✅
15. **是否具备进入 Step 5 条件**: **NO** — PHP/Composer P0 阻塞未解决，测试基线未建立
