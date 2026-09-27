# P-STEP 19A — Release Readiness Final Gate

- **阶段**：19A Release Readiness（发布前审查）— Implementation Final
- **基线**：Discovery `857686f` → RB-1 修复 + 发布物收口 → **本提交 `05aac3f`**
- **日期**：2026-09-27
- **结论（Gate）**：**PASS ✅（P0 Blocker = 0）**
- **重要边界**：本 PASS 仅表示"发布前审查通过"，**不构成 push / GitHub 连接 / Release 授权**；remote、push、release、rc1 仍全部 HOLD，直到用户明确口令。

---

## 1. Architecture Impact — Second System = No

| 检查面 | 结论 | 证据 |
|---|---|---|
| 第二套 Entity 体系 | **No** | RB-1 修复未触碰 Entity/Entity Graph |
| 第二套 Renderer | **No** | 未新增/修改任何渲染器 |
| 第二套 SEO/GEO 链路 | **No** | 修复仅改变 helper 的**加载位置**，不改裁决 |
| 第二套关系系统 | **No** | 未新增关系表/模型 |
| 新表 / 新列 / 新 migration | **0 / 0 / 0** | 本批无任何 schema 变更 |
| 新模型 | **0** | 仅新增一个纯函数文件与一个测试文件 |

**RB-1 修复性质（仅"移动加载位置"）**：

- 新建 `app/Support/helpers.php`，把 `localized_route()`、`PublicUrlLocalized()` 两函数**原样移入**：
  - 函数体未改，仍只调用 `LocaleContext` / `LocaleRegistry` 与 `url()` / `route()`；
  - 两函数均以 `if (!function_exists(...))` 包裹；
  - 文件头注释明确：仅移动到稳定加载位置，**不复制 `PublicUrl` / `UrlResolver` 的裁决规则**。
- `composer.json` autoload 增加 `"files": ["app/Support/helpers.php"]`（psr-4 不变）。
- `routes/web.php` 删除两段函数定义（Grep 确认仅 helpers.php 命中）。
- `composer dump-autoload` 重新生成（6838 classes），CLI 验证两函数 `function_exists = 1`。

**根因回顾（RB-1）**：两 helper 原定义在 `routes/web.php`，composer autoload 无 `files` 项；`route:cache` 后框架从 `bootstrap/cache` 加载、不再 require web.php，函数从未声明 → blade（dynamic_form）调用即 500。开发/无路由缓存正常。双重漏网：测试不跑 route:cache、对 contact 零 HTTP 覆盖。

---

## 2. Feature Matrix

| 能力 | Backend | Admin | Frontend | AI/GEO | SEO |
|---|---|---|---|---|---|
| RB-1 helper 稳定加载（route:cache） | ✅ | — | ✅ | — | — |
| 中文 /contact 表单（route:cache 全新进程） | — | — | ✅ | — | ✅ |
| 英文 /en/contact 表单（启用 en 后） | — | — | ✅ | — | ✅ |
| 版本号唯一源对齐（1.0.0） | ✅ | ✅ | ✅ | ✅ | ✅ |
| CI release-manifest 基线（1225/6534） | ✅ | — | — | — | ✅ |
| 防回归测试 RouteCacheHelperRegression19ATest | ✅（6/6） | — | — | — | — |

---

## 3. Evidence

### 3.1 全量回归（收口改动后）

```
Tests:    1225 passed (6534 assertions)
Duration: 990.20s
```

- **0 failed / 0 skipped / 0 errors / exit 0**
- 拆分实测：Unit 61（416 assertions）+ Feature 1164（6118 assertions）
- 回归演进：Cap1 后 1199/6393 → Cap2 后 1208/6450 → Cap3 后 1219/6515 → **现 1225/6534**，无回退。
- 日志：`D:\Temp\final19a.log`；Feature JUnit：`D:\Temp\junit-feature.xml`（errors=0/failures=0/skipped=0）。

### 3.2 四态 E2E（RB-1 闭环，curl.exe，全新进程）

独立临时库 `D:\Temp\geo19a.sqlite`（production、APP_DEBUG=false、CACHE/SESSION=file、QUEUE=sync、geo:install 成功）：

| 状态 | /contact 结果 |
|---|---|
| 无缓存 | 301 → /contact/ → 200 |
| **route:cache（Discovery 复现 500 的同一条件）** | 301 → **/contact/ 200**，form-action=`http://127.0.0.1:8154/forms/contact/submit`（正确、不含 /en） |
| 重启持久化后 | 200 |
| 再缓存 | 200 |

- `/scenarios` 301 → `/solutions/`（PublicUrlLocalized 重定向正常）；首页 200。
- 先前 .NET `Invoke-WebRequest` 报"对象当前状态使该操作无效"是 `-MaximumRedirection 0` 处理 301 的怪癖、非页面问题；状态/重定向核对统一改用 `curl.exe -s -o NUL -w "%{http_code} redir=%{redirect_url}"`。

### 3.3 英文前台核验（链路完好，非 bug）

- Fresh production 临时库中 /en/* 曾全 404。查 `pages` 表证实**英文数据存在**（en 10 页、zh-CN 10 页，双语系统页 seeder 都建、含 en slug=contact/）。
- `/en/*` 是否放行取决于 Setting **`site_supported_locales`**；fresh 出厂为 **["zh-CN"]**（hint 明确"默认仅 zh-CN、可启用 en"）。未启用时 /en/* 严格 404 是**设计行为**。
- 改为 ["zh-CN","en"] 后初次仍 404：`Setting::allCached()` 用 `Cache::rememberForever`，直接改库不清缓存读旧值；执行 **`cache:clear`** 后：
  - `/en/` **200**、`/en/contact/` **200**，form-action=`http://127.0.0.1:8160/en/forms/contact/submit`（含 /en，正确）。
- `/en/products/`、`/en/solutions/`、`/en/cases/` 404：对照中文同路径**同样 404**——根因是 fresh 出厂无业务实体（无产品/场景/案例），空栏目按三控制器统一规则 abort 404（CaseController.php:35、ProductController.php:32、SolutionController.php:28，同类 TD-165），**中英一致**。
- **结论**：英文链路完好；404 = 单语出厂开关 + 空栏目空态，均为设计行为。

### 3.4 Fresh / Upgrade / Rollback

- **Fresh install**：Discovery 阶段已在陌生环境实测 composer install（生产模式 exit 0）→ key:generate → geo:install（迁移、storage link、seeder、admin、exit 0）→ config/route/view:cache → 起服烟测 PASS；本轮四态 E2E 在独立 fresh 库再次确认。
- **Upgrade / Backup / Rollback**：`geo:upgrade` / `geo:backup` / `geo:rollback` 命令**已实现/存在**，但**零自动化测试、未做 Release-level E2E 验证** → 登记 **TD-168（P2）**，最终 Release Gate 须列为 **Release Evidence Gap**，本批不处理、不假装已验证。

### 3.5 发布物一致性收口（低风险、无需解冻架构）

| 项 | 结果 |
|---|---|
| 唯一版本源 | `config/geo.php` `env('GEO_OS_VERSION','1.0.0')`（原默认 2.0.0 已修正） |
| CHANGELOG | 补齐完整 [1.0.0] 段：RB-1、实体 8、18S/18T、Known limitations |
| README | line 12 改为 **eight generic entity types**（含 case_study/download_asset） |
| CI manifest | `.github/workflows/ci.yml` test_results 993/5118 → **1225/6534**，注释同步 |
| agent-guide | `docs/ai/agent-guide-v1.md` 平台版本 2.0.0 → **1.0.0** |
| Productization 注释 | `tests/Feature/ProductizationAcceptanceTest.php` 2.0.0 → 1.0.0 |

- 2.0.0 残留中，`composer.lock` / `package-lock.json` / `package.json`（phpstan ^2.0.0、laravel-vite-plugin ^2.0.0、node engine）为**依赖版本约束、与产品版本无关**，保留；`docs/audit`、`docs/product` 下的 2.0.0 为**历史点时间快照**，保留。
- 版本号收口遵循"先找唯一 canonical source 再对齐"，未做盲目全局替换。

### 3.6 Git / 发布链路状态

- 本地提交：**`05aac3f`**（11 files changed, 220 insertions, 37 deletions）；工作树 clean。
- **remote**：`.git/config` 中**已存在** `origin` = `git@github.com:jackyduhai/geo-website-os.git`（应为更早 P16/19A 阶段配置）；但**无任何 remote-tracking 分支、main 未设 upstream、从未 fetch/push**。本次**保留该 URL、不删除、不连接、不 push**，是否使用等待用户裁定。
- **未 push、未 Release、rc1 tag `965d63c` 未移动。**

---

## 4. Release Impact

### Blocker / Debt 汇总

| 等级 | 数量 / 项 | 处理 |
|---|---|---|
| **P0 Blocker** | **0**（唯一 P0 RB-1 / TD-167 已修复并 E2E 闭环） | — |
| **P1** | **0** | — |
| P2 | TD-163（/en/geo.json 未本地化）、TD-164（Admin 仅中文）、TD-168（upgrade/backup/rollback 零测试）、TD-158（孤儿关系清理）、TD-156（CaseStudy 五要素） | v1.1 / RC，不阻断本 Gate |
| P3 | TD-165、TD-166、TD-170、TD-162 等 | 后续版本 |
| 已 CLOSED | TD-167（RB-1）、TD-169（版本号）、TD-161 | 已销项 |

### Release Evidence Gap（必须在最终 Release 前如实列明，不得标 PASS）

- **TD-168**：升级 / 备份 / 回滚的 Release-level E2E 验证缺失（命令存在、零测试）。

### Decision

```
P0 Blocker: 0
P1: 0
Decision: PASS ✅
```

- **19A Release Readiness Gate = PASS / CLOSED**。
- 该 PASS 仅证明：架构冻结后的系统作为可交付软件包，全新安装可在陌生环境安装、配置、运行，且唯一生产阻断（route:cache /contact 500）已消除。
- **仍保持 HOLD（需用户明确口令）**：连接/使用 origin remote、`git push`、Release、移动 rc1。
- 下一可能动作（均需明确授权，不自动推进）：
  1. 「允许配置/连接 GitHub」→ 核对 remote 并做 Fresh checkout 验证；
  2. 「允许 push」→ 推送基线、首跑 Cloud CI；
  3. 「准备发布」→ 进入 Release Gate（届时须先处理或显式接受 TD-168 Release Evidence Gap）。

---

## 5. 强制 STOP

本 Gate PASS 后 **STOP**。不自动 push、不连接 remote、不 Release、不移动 rc1、不开启新阶段，等待用户明确口令。
