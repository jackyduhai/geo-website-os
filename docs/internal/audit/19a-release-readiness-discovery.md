# P-STEP 19A — Release Readiness Discovery（发布前审查，只读）

- 阶段：P-STEP 19A（架构冻结后发布前审查）
- 基线 HEAD：`9f0f4c1`（`docs(18T): v1.0 architecture freeze + i18n gap TDs`），工作树干净
- rc1 锚点：`v1.0.0-rc1` → `965d63c`（未移动）
- 性质：**只读 Discovery**，不编码、不 push、不 release、不配置 remote、不动 rc1
- 实测环境：`D:\Temp\geo19a`（`git archive HEAD` 导出干净源码树，模拟 release zipball），PHP 8.4.25 / Composer 2.10.3，生产模式 `--no-dev` + `route:cache`

---

## 总览

| 维度 | 结论 | 说明 |
| --- | --- | --- |
| A. 包/仓库完整性 | **PASS** | composer validate --strict 通过；.gitignore 覆盖全面；zipball 洁净 |
| B. 敏感信息扫描 | **PASS** | .env gitignored；.env.example 无真实密钥；geo:install 随机密码；无硬编码凭据 |
| C. License/合规 | **PASS**（附 WARNING） | MIT 一致；无业务品牌残留；README/CHANGELOG 实体数滞后 |
| D. 陌生环境 Fresh Install | **PASS** | 干净 zipball → composer install → geo:install → 生产缓存 → 起服，全链路通畅 |
| E. 升级/回滚 | **WARNING** | 命令设计完善（geo:upgrade/backup/rollback），但无测试覆盖 |
| F. 安全面 | **PASS** | composer audit 0 漏洞；生产无调试泄漏；安全头齐全；错误页美化；私有 storage 不可直接访问 |
| G. 性能/烟测 | **BLOCK** | `/contact` 在生产 route:cache 下 500（核心转化页，P0） |
| H. 版本/发布物 | **WARNING** | config 版本号 2.0.0 与 v1.0 不一致；CI manifest 测试数滞后；文档实体数滞后 |
| I. CI 盘点 | **PASS** | ci.yml 含 test + release job；secrets 需求清晰；smoke 未跑 route:cache（缺口） |

**Release Blocker：1 个（P0）** — 见 §G / §10。

---

## A. 包/仓库完整性 — PASS

### A1. composer.json / composer.lock 一致性

- `composer validate --strict` → `./composer.json is valid`（exit 0）
- composer.lock 存在且与 composer.json 同步（validate 无 lock 过期警告）
- 关键字段：
  - name: `geo-website-os/geo-website-os`，type: `project`，license: `MIT`
  - require: php `^8.4`, laravel/framework `^12.0`, laravel/tinker `^2.10.1`
  - autoload: PSR-4（App/ → app/, Database/Factories/, Database/Seeders/）
  - **注意**：autoload 无 `"files"` 数组（与 §G P0 Blocker 直接相关）

### A2. .gitignore 覆盖

根 .gitignore 覆盖：`.env` / `.env.backup` / `.env.production` / `vendor` / `node_modules` / `*.sqlite` / `*.sqlite-journal` / `*.log` / `/public/build` / `/storage/*.key` / `docs/audit/*.zip` / `docs/audit/*.bak` / `_*.php` / `_*.ps1` / `_*.txt` 等。

storage/ 各子目录（app/public/private、framework/cache/sessions/views、logs）均含 `.gitignore`（`*` + `!.gitignore` 模式），确保目录结构入库但内容不入库。

### A3. 打包 zipball 洁净度

`git archive HEAD` 导出后确认：无 `vendor/`、无 `.env`、无 `*.sqlite`、无 `node_modules/`、无日志文件。808 个 tracked files，均为源码/配置/文档/测试/视图。

### A4. 多余产物

- 工作树根有 `_w.txt`（PHPUnit 弃用警告输出），匹配 `_*.txt` gitignore 规则，**不入库、不进 zipball**。
- `git status` 干净，无 untracked 非忽略文件。

---

## B. 敏感信息扫描 — PASS

### B1. .env 与配置

- `.env` 被 gitignore，不入库。
- `.env.example` 全部为占位符/空值：DB_PASSWORD 注释掉、REDIS_PASSWORD=null、MAIL_PASSWORD=null、AWS_SECRET_ACCESS_KEY=空、GEOFLOW_TOKEN=空。
- 无真实密钥/token/内部 URL 写入示例配置。

### B2. 代码中硬编码凭据

- 全仓 grep `api_key|secret|password|token|credential`：命中均为误报（CSS themeTokens、AuditSnapshot 脱敏列表、.env 占位符）。
- `DatabaseSeeder.php:32` 含 `Hash::make('Admin@123456')` —— 但这是 **demo seeder 路径**（`db:seed`），**不被 `geo:install` 使用**。
- `GeoInstall.php:31`：管理员密码缺省**随机生成并打印一次**（`--admin-password=` 可选），不使用固定默认密码。

### B3. 内部 URL/业务硬编码

- app/ 目录 grep `demo-tenant-a|Demo Tenant A|localhost:8|internal\.|corp\.` → **0 命中**。
- 运行时代码无业务专属品牌/URL/联系方式（18J productization 已完成通用化）。

---

## C. License/合规 — PASS（附 WARNING）

### C1. LICENSE 与 composer.json 声明一致

- `LICENSE` 文件：MIT License，Copyright (c) 2026 GEO Website OS Contributors。
- `composer.json` license: `"MIT"`。一致。

### C2. 第三方依赖 license 兼容性

- 核心依赖均为 MIT / BSD 兼容：laravel/framework (MIT)、laravel/tinker (MIT)、symfony 组件 (MIT)、monolog (MIT) 等。
- 18M license audit 结论复用：无 GPL/AGPL 传染风险。
- `composer audit`（§F1）0 漏洞。

### C3. 品牌/商标通用化

- 运行时无写死品牌（见 B3）。
- 默认演示数据为通用工业 Example Organization（18J 已去业务化）。
- **WARNING**：`README.md:12` 和 `CHANGELOG.md:24` 仍写「six generic entity types」，当前实际为 **8 种**（18R 新增 case_study / download_asset）。文档滞后，发布前应更新。

---

## D. 陌生环境 Fresh Install — PASS

实测在 `D:\Temp\geo19a`（git archive 干净源码树，模拟 release zipball）完成：

| 步骤 | 结果 | 证据 |
| --- | --- | --- |
| D1 干净源码树 | PASS | 无 vendor/.env/*.sqlite |
| D2 `composer install --no-dev --optimize-autoloader` | PASS | 76 包安装 0 失败，optimized autoload 生成，package:discover 全 DONE |
| D3 `php artisan key:generate` | PASS | Application key set successfully |
| D4 .env 生产配置 | PASS | APP_ENV=production / APP_DEBUG=false / DB_CONNECTION=sqlite |
| D5 `php artisan geo:install --no-interaction` | PASS | 54 迁移全 DONE；storage link；default settings/blank homepage/contact form/system pages seeder 全 OK；search index rebuilt；admin 账号随机密码生成 |
| D6 storage / bootstrap/cache 可写 | PASS | 写入测试通过 |
| D7 生产缓存三命令 | PASS | config:cache / route:cache / view:cache 均 cached successfully |

**结论**：从干净 zipball 到生产可启动的安装链路完整通畅，无缺失步骤、无手动修补需求。

---

## E. 升级/回滚 — WARNING

### E1. 命令存在性与设计

| 命令 | 文件 | 设计评估 |
| --- | --- | --- |
| `geo:upgrade` | `app/Console/Commands/GeoUpgrade.php` | 有序：pre-migrate backfill（geo:backfill-seo）→ migrate --force → 核心表验证 → 逐站补默认设置/表单（幂等、不覆盖自定义）。非破坏性。 |
| `geo:backup` | `app/Console/Commands/GeoBackup.php` | sqlite 整库复制到 storage/app/backups/ + JSON manifest（version/migrations/数据计数/sha256）。mysql/pgsql 诚实输出错误（不假装可备份）。 |
| `geo:rollback` | `app/Console/Commands/GeoRollback.php` | 三边界清晰：DB 回滚=整库还原（需 --backup + --force，校验 manifest sha256，还原前再备份当前库）；Code 回滚=部署层切旧 artifact；Config 回滚=.env 人工对照。不做 migration:rollback 式"智能逆向"。 |

### E2. 升级路径验证

- rc1（965d63c）→ HEAD（9f0f4c1）共 59 commits，迁移从 rc1 时期到当前 54 个迁移，`geo:upgrade` 的 pre-migrate backfill + migrate --force 顺序可处理增量迁移。
- `geo:backup` → 文件系统验证：备份文件生成于 storage/app/backups/，含 .json manifest。
- `geo:rollback` 设计有防误触（--force）、有回滚的回滚（还原前备份当前库）、有 sha256 校验。

### E3. 测试覆盖 — WARNING（缺口）

- `geo:install` 有 `tests/Feature/GeoInstallTest.php` 覆盖。
- **`geo:upgrade` / `geo:backup` / `geo:rollback` 三个命令无任何测试文件**（grep 0 命中）。
- 风险：升级/回滚路径未经自动化验证，生产环境升级依赖人工操作。
- 处置建议：v1.0 发布前至少补 `geo:backup` → `geo:rollback` 往返测试 + `geo:upgrade` 幂等性测试；或在 Release Notes 中明确标注"升级/回滚需人工验证"。登记为新 TD（P2）。

---

## F. 安全面 — PASS

### F1. composer audit

`composer audit` → `No security vulnerability advisories found.`（exit 0）。生产依赖 0 已知漏洞。

### F2. 生产模式无调试泄漏

- APP_DEBUG=false 下，全部页面（含 500/404）body 不含 Whoops / Stack trace / Exception / DEBUG / vendor 路径 / `#0` 等标记。
- 500 页返回美化错误页「页面暂时出了点问题」，无堆栈。
- 404 页返回「这个页面找不到了」，无泄漏。

### F3. 响应头

- 无 `X-Powered-By` 信息泄漏。
- 安全头齐全：`X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy: strict-origin-when-cross-origin`、`Permissions-Policy`、完整 `Content-Security-Policy`（script-src 带 per-request nonce）。

### F4. 默认凭据与目录列举

- geo:install 生成随机管理员密码（非固定默认密码）。
- storage/private 目录含 .gitignore，不通过 web 直接访问（Laravel public 目录仅暴露 public/storage 软链）。
- 无目录列举。

### F5. 私有文件访问

- download_asset 类型实体的 `public=false`（config/entities.php），不进入 sitemap/llms/geo.json 公开输出。
- storage/app/private/ 不被 web 直接访问，需经授权控制器分发。

---

## G. 性能/烟测 — BLOCK（1 个 P0）

### G1. 生产缓存下核心路由（全新 serve 进程 + route:cache ON）

| URL | Status | 耗时 | 判定 |
| --- | --- | --- | --- |
| `/` | 200 | 787ms | PASS（102KB，X-Page-Cache: HIT） |
| `/products` | 404 | 880ms | PASS（空站无 catalog，预期 404） |
| `/solutions` | 404 | 926ms | PASS（空站，预期 404） |
| `/cases` | 404 | 779ms | PASS（seeder 不含 case_study，空态设计行为） |
| **`/contact`** | **500** | 982ms | **BLOCK（P0）** |
| `/sitemap.xml` | 200 | 559ms | PASS |
| `/llms.txt` | 200 | 418ms | PASS |
| `/geo.json` | 200 | 512ms | PASS（`$schema: geo-os/graph/v1`，entities 空数组——fresh install 无业务事实） |
| `/robots.txt` | 200 | 387ms | PASS（含 AI 爬虫放行 + Sitemap: http://127.0.0.1:8150/sitemap.xml） |
| `/en` | 404 | 405ms | PASS（fresh install 默认仅 zh-CN，/en 未启用） |
| `/nonexistent-page-12345` | 404 | 447ms | PASS（美化 404，无泄漏） |

### G2. geo.json locale 状态

- 中文 `/geo.json`：entities/facts/contents 正确输出中文。
- 英文 `/en/geo.json`（在启用 en 的站点上）：路由可达 200，但 facts/entities/contents 段未本地化（实测 1821 CJK 字符，无 locale 字段）——对应 **TD-163**（P2，18T 已登记），不阻断 v1.0（中文完整、英文主流程可用）。

### G3. Release Blocker：/contact 生产模式 500（P0）

**异常**：`production.ERROR: Call to undefined function localized_route() (View: resources/views/site/dynamic_form.blade.php)`

**根因（已确凿）**：
- `routes/web.php` 定义了两个全局 helper：
  - `PublicUrlLocalized()`（web.php:141-149，旧 /scenarios → /solutions 301 重定向用）
  - `localized_route()`（web.php:155-164，dynamic_form.blade.php:19 表单 action 用）
- `composer.json` autoload 没有 `"files"` 数组，二者唯一加载途径是 web.php 被 require。
- 生产执行 `route:cache` 后，Laravel 改从 `bootstrap/cache/routes-v7.php` 加载路由、**不再 require web.php**，两个函数从未声明。
- `/contact` 渲染 `dynamic_form` 时调用 `localized_route()` → `Call to undefined function` → 500。
- 开发/无路由缓存时 web.php 每次请求都被 require，函数存在，页面正常。

**受控复现证据**：
1. 全新 serve 进程 + route:cache ON → `/contact` **500**，`/` 200。
2. `route:clear`（关缓存）→ `/contact` **200**（109KB）。
3. 重新 `route:cache` 但复用同一 serve 进程 → 误判 200（PHP 单进程跨请求持久化了函数定义）；**重启 serve 进程后再次确认 `/contact` 回到 500**。

**影响面**：
- `dynamic_form.blade.php` 被三个视图 include：
  1. `resources/views/site/blocks/form_reference.blade.php:23` → **/contact**（及任何使用 form_reference block 的系统页）
  2. `resources/views/site/home/cta.blade.php:31` → 首页 CTA 区（若页面配置了 lead form block）
  3. `resources/views/site/content.blade.php:108` → 任何内容详情页（若关联了 lead form）
- `/en/contact` 同样 500（同一 blade、同一函数调用）。
- `/scenarios`（legacy 兼容路由）的 301 闭包调用 `PublicUrlLocalized()`，route:cache 下同样未定义，访问会 500（影响较低）。

**为什么测试没抓到（双重漏网）**：
1. 测试环境 APP_ENV=testing，**不启用 route:cache**，routes/web.php 每次请求都被加载，函数存在。
2. 测试目录中**无测试对 GET /contact 路由做 HTTP 200 断言**（/contact 零 HTTP 覆盖；含 "contact" 字样的测试均为表单提交/Inquiry 相关，不覆盖 /contact 页面 GET）。
3. CI smoke test（ci.yml:69-77）跑 `php artisan serve` 但**未执行 route:cache**，因此绕过此缺陷。

**最小修复方案（不触碰架构冻结）**：

这是普通 bug 修复（函数加载位置），不新增 Entity/Contract/第二套规则，不违反架构冻结。

1. **新建 `app/Support/helpers.php`**，把 `PublicUrlLocalized()` 和 `localized_route()` 两个函数原样移入（逻辑不变、签名不变、保留 `function_exists` 守卫）。
2. **`composer.json` autoload 增加** `"files": ["app/Support/helpers.php"]`。
3. **从 `routes/web.php` 删除这两段函数定义**（调用点不变）。
4. 运行 `composer dump-autoload` 使 autoload.files 生效。
5. **新增测试**：
   - `GET /contact` → 200；`GET /en/contact` → 200（需 en 站点/翻译行）。
   - `route:cache` 后 `GET /contact` → 200（再 `route:clear` 清理）。
   - `function_exists('localized_route')` 和 `function_exists('PublicUrlLocalized')` 断言。
6. **Fresh 生产模式复验**：config:cache + route:cache + view:cache + 全新 serve 进程下，`/contact` 200；表单 action zh 无 /en 前缀、en 含 /en 前缀。

改动文件：`app/Support/helpers.php`（新增）、`composer.json`（autoload.files）、`routes/web.php`（删除两段函数定义）、新增测试文件。

---

## H. 版本/发布物 — WARNING

### H1. 版本号一致性

- `config/geo.php:14`：`'version' => env('GEO_OS_VERSION', '2.0.0')`
- `php artisan geo:version` 输出：`app.version = 2.0.0`
- 但项目当前为 **v1.0.0-rc1 / v1.0 架构冻结**，git tag 为 `v1.0.0-rc1`。
- **WARNING**：默认版本号 2.0.0 与实际发布版本 1.0.0 不一致。CI release manifest 使用 git tag（不受影响），但用户运行 `geo:version` 会看到错误版本。发布前应将默认值改为 `1.0.0`（或在 .env 中设 GEO_OS_VERSION=1.0.0）。

### H2. CHANGELOG

- `CHANGELOG.md` 存在，遵循 Keep a Changelog 格式。
- `[Unreleased]` 节为空——18R–18T 的变更（case_study/download_asset 类型、Setup Wizard、GEO Health/Coverage 看板、架构冻结）未写入 CHANGELOG。
- `[1.0.0-rc1]` 节写「six generic entity types」，实际已为 8 种。
- **WARNING**：发布前需补全 [Unreleased] → [1.0.0] 变更记录并修正实体数。

### H3. README / 部署文档

- `README.md` 存在且全面：Requirements（PHP 8.4+ / Composer 2.x / Node 20+ / SQLite）、Quick start、Multi-site model、Machine-readable outputs。
- `DEPLOY.md` 存在（引用自 README）。
- **WARNING**：README 实体数滞后（6 → 8）。

### H4. CI Release Manifest 滞后

- `.github/workflows/ci.yml:129-131` 硬编码 `"tests": 993, "assertions": 5118`。
- 当前 HEAD 实际测试数：**1307**（`php artisan test --list-tests` 计数）。
- **WARNING**：release manifest 会报告错误的测试数。发布前应更新 ci.yml 中的硬编码值，或改为从测试输出动态读取。

### H5. artifact/SHA 现状

- rc1 tag `v1.0.0-rc1` → `965d63c`，annotated，未移动。
- 当前无 release artifact（未 push、未 release）。
- CI release job 设计完善：`--no-dev` install → zip artifact → sha256 → release-manifest.json → draft prerelease（不自动发布）。

---

## I. CI 盘点 — PASS

### I1. 现有 CI 配置

`.github/workflows/ci.yml` 含两个 job：

**test job**（ubuntu-latest, PHP 8.4）：
1. Checkout → Setup PHP（extensions: pdo_sqlite, mbstring, openssl, tokenizer, xml, ctype, json）
2. Composer cache → `composer validate --strict` → `composer install`
3. `composer check-platform-reqs` → `composer audit`
4. Prepare app（cp .env.example → key:generate → touch sqlite → migrate）
5. `php artisan geo:install --no-interaction`
6. `php artisan test`（顺序执行，注释明确不切 parallel）
7. HTTP smoke（serve + curl /、/geo.json、/sitemap.xml、/llms.txt、/robots.txt）

**release job**（needs test, 仅 tag v* 触发）：
1. `composer install --no-dev`
2. 构建 zip artifact（app/bootstrap/config/database/plugins/public/resources/routes/storage/vendor + artisan/composer.* /.env.example/README/LICENSE/CHANGELOG/package.json/vite.config.js）
3. sha256 + release-manifest.json
4. `softprops/action-gh-release@v3`：draft=true, prerelease=true, make_latest=false（观察窗口，不自动发布）

### I2. 所需 secrets

- test job：无需额外 secrets（GITHUB_TOKEN 隐式可用）。
- release job：`GITHUB_TOKEN` 隐式可用。
- 无第三方 secrets（无 Slack/SSH/部署密钥配置）。

### I3. CI 缺口（与 §G P0 关联）

- HTTP smoke 步骤**未执行 route:cache**，因此未捕获 /contact 500。
- 建议修复 P0 后，在 CI smoke 步骤增加 `php artisan route:cache` 并在全新进程下断言 /contact 200。

---

## 10. Release Blocker 汇总

| # | 级别 | 描述 | 根因 | 最小修复 | 修复后验证 |
| --- | --- | --- | --- | --- | --- |
| RB-1 | **P0** | `/contact`、`/en/contact` 及所有含 dynamic_form 的页面（首页 CTA、内容页 lead form）在生产 route:cache 下 500；`/scenarios` 重定向同样受影响 | `localized_route()` / `PublicUrlLocalized()` 定义在 routes/web.php，route:cache 后 web.php 不被 require，函数未定义 | 两函数移入 `app/Support/helpers.php` + composer.json autoload.files；routes/web.php 删除定义；composer dump-autoload | route:cache ON + 全新进程下 /contact、/en/contact、/scenarios 均 200；表单 action zh 无 /en、en 含 /en；补生产模式 smoke 测试 + function_exists 断言 |

### WARNING 清单（非阻断，发布前建议修复）

| # | 级别 | 描述 | 建议 |
| --- | --- | --- | --- |
| W-1 | P2 | `geo:upgrade` / `geo:backup` / `geo:rollback` 无测试覆盖 | 补 backup→rollback 往返 + upgrade 幂等测试，或 Release Notes 标注需人工验证 |
| W-2 | P2 | `config/geo.php` 默认版本号 2.0.0 与 v1.0 不一致 | 改为 1.0.0 或 .env 设 GEO_OS_VERSION |
| W-3 | P3 | CI release manifest 硬编码 993 tests / 5118 assertions，实际 1307 tests | 更新 ci.yml 或改为动态读取 |
| W-4 | P3 | README / CHANGELOG 实体数写 6，实际 8；CHANGELOG [Unreleased] 未记录 18R–18T | 发布前更新文档 |
| W-5 | P3 | CI smoke 未跑 route:cache，无法捕获生产专属缺陷 | 修复 RB-1 后在 CI 增加 route:cache smoke |

### 新 TD 登记建议

- **TD-167**：`localized_route()` / `PublicUrlLocalized()` 定义在 routes/web.php，route:cache 生产模式下未定义导致 /contact 500（P0，修复后关闭）。
- **TD-168**：`geo:upgrade` / `geo:backup` / `geo:rollback` 无自动化测试覆盖（P2）。
- **TD-169**：config/geo.php 默认版本号 2.0.0 与发布版本 v1.0 不一致（P2）。
- **TD-170**：CI release manifest 测试数硬编码滞后 + CI smoke 未覆盖 route:cache 生产模式（P3）。

---

## 11. 最终建议

**当前不具备 Push/Release 条件。**

理由：存在 1 个 P0 Release Blocker（RB-1）——核心转化页 /contact 在生产模式（route:cache）下 500，且影响所有含 dynamic_form 的页面及 /en/contact。这是陌生环境真实安装发现的生产专属缺陷，dev/test 均无法捕获（无 route:cache + 无 /contact HTTP 测试，双重漏网）。

**进入「配置 GitHub / Push」的前置条件**：
1. 修复 RB-1（两 helper 移入 `app/Support/helpers.php` + composer autoload.files），改动不触碰架构冻结。
2. 在「route:cache ON + 全新 serve 进程」下复验 /contact、/en/contact、/scenarios 均 200；表单 action zh 无 /en、en 含 /en。
3. 补生产模式 smoke 测试（GET /contact 200 + route:cache 后 200 + function_exists 断言）防回归。
4. 建议同步修复 W-2（版本号），其余 WARNING 可在 Release Notes 标注或 v1.0.1 处理。

修复 RB-1 并复验通过后，**即可进入「配置 GitHub / Push」阶段**（其余维度 A/B/C/D/F/I 均 PASS，E/H 为非阻断 WARNING）。

---

## 12. 执行纪律确认

- 本轮全程只读：未修改业务代码、未修改 composer.json、未新增能力。
- 未 push、未 release、未配置 remote、未移动 rc1 tag（`v1.0.0-rc1` → `965d63c` 不变）。
- 临时环境 `D:\Temp\geo19a` 已清理，权威仓库 `git status` 干净，HEAD=`9f0f4c1`。
- 起服均为前台短时（Start-Process → 测试 → Stop-Process + 清理 php 子进程），未使用 run_in_background。
- 架构冻结继续有效：RB-1 修复方案为函数加载位置 bug fix，不改变任何 Entity / Contract / 关系语义 / 公开口径 / 输出格式。

完成后 STOP，交人工审阅裁定修复。
