# 20B 性能基线报告（establish-baseline）

> 流程：性能优化证据链裁决。**无测量不结论；无同合同证据不授权。**
> 本轮只建立可复跑基线 + 命中候选账本，**不实施任何优化**，所有候选状态均为 `EXPERIMENT_REQUIRED`。

---

## 1. Verdict / Proven Scope / 最大未验证项

- **Verdict：`establish-baseline` 已闭合；候选裁决为 `EVIDENCE_REQUIRED`。**
  基线 workload、命令、原始样本、漂移检查已闭合；已定位并经临时库增长验证确证的 N+1 点登记在「候选账本」，但**未做任何 A/B 对比，故不授权优化、不给收益数字**。
- **Proven scope（已被同合同 raw 样本覆盖）：**
  - 53 个代表性 GET 页面（前台 21 + 后台 32），同进程内每页 5 轮稳态样本（首次 warmup 丢弃）。
  - 测量边界：`Application` 内 `HttpKernel::handle()`（不经 PHP-CLI server、不经真实 HTTP 网络），wall time = 单次 dispatch 全程；SQL 条数 = `DB::listen` 逐语句计数；内存 = `memory_get_peak_usage(true)`。
  - 前台匿名页均在 `PageCache::flush()` 后测 **MISS 路径**（真正跑控制器/查库的路径）；后台页天然不进整页缓存。
  - 3 个疑似 N+1 点已在 **workspace 临时 SQLite 副本**上做 +200 篇文章的增长对照。
- **最大未验证项（明确不覆盖）：**
  1. **前端资源/多视口/TTFB 端到端**：本基线测的是服务端 dispatch 时间，不含浏览器下载、CSS/JS 资源、渲染、网络往返、真实并发。前台 HIT 路径（整页缓存命中）只在设计层说明，未单独采样 SQL=0 对照。
  2. **真实并发/长驻进程（Octane/PHP-FPM 多 worker）**：单进程串行 dispatch，无并发竞争、无 opcache 跨请求状态外的差异。
  3. **大数据量下的完整前端列表**：增长验证只跑了 dashboard/llms/knowledge/products 4 个代表页；products.detail 的 entity_relations 重复（×4）只在 7 产品下观测，未做 +200 实体对照。
  4. article.detail（`/how-to-choose-industrial-coatings`）当前返回 301（CanonicalizeSlash），未跟随到规范 URL 测量真实渲染页。

---

## 2. Evidence Card

| 项 | 值 | 证据类型 |
|---|---|---|
| revision | `fd660b043be7d845fcd52453400000862cdf9d7c`（测量时点 HEAD；任务给定基线为 `c6f04fd`，测量期间并行提交推进 1 个 UI commit，关键业务控制器未变） | RUNTIME_ARTIFACT |
| runtime | Windows / PHP 8.4.25（`C:\php84\php.exe -d memory_limit=512M`） | RUNTIME_ARTIFACT |
| app stack | Laravel 12 slim skeleton，DB=SQLite（`database/database.sqlite`），CACHE/SESSION/QUEUE=database，PageCache=独立 file 存储 | SOURCE(.env, bootstrap/app.php, PageCache.php) |
| 主库数据基线 | contents=7（published article=6）、entities=18、relations=58、pages=20、facts=23、media=0、inquiries=0、settings=81 | TEST_OBSERVATION(只读 PDO) |
| 入口 signature | `Illuminate\Contracts\Http\Kernel::handle(Request::create($path,'GET',...))`；后台经真实 POST `/admin/login` 建立 session cookie 后带 cookie 请求 | SOURCE |
| 后台鉴权 | guard=`web`(session)，`admin@example.com` 真实登录，probe 验证非 302-login，`authed=YES` | TEST_OBSERVATION |
| 边界 | 只读测量：GET 请求；前台每请求前 `PageCache::flush()` 强制 MISS；不写业务表；临时库在 workspace，测完删除 | ASSUMPTION/纪律 |

**字面状态转移（增长验证，dashboard 电话口径查询）：**
`new = old + Δ` 。published article 数 `6 → 206`（Δ=+200）；`facts where key=?` 查询次数 `6 → 206`（Δ=+200）。逐式复算：每条 published content 在 gate 循环内触发 1 次电话口径查询，故 `facts_queries = published_articles`，斜率 1:1。

---

## 3. Measurement Contract

- **Fingerprint（每对样本同一）：** revision `fd660b0` + PHP 8.4.25 + SQLite + Host=127.0.0.1 + 匿名/已登录身份 + 前台 MISS(flush) + 同进程串行 + 首次 warmup 丢弃 + 取 5 轮 p50。
- **Workload 矩阵：**
  - 后台（已登录）：Dashboard、contents/article+all、entities、relations、inquiries、media、facts、pages、narrative、forms(+submissions)、categories、groups、menus、redirects、seo-metas/all、8 组 settings(general/theme/contact/copy/seo/geo/analytics/sync)、geo(tools/coverage/health/sync-logs)、themes/templates/plugins。
  - 前台（匿名 MISS）：home、products(list/detail)、solutions(list/detail)、cases、knowledge(index/channel)、article、about×3、factory、cooperation、contact、search、sitemap.xml、geo.json、llms.txt、feed.xml、robots.txt。
- **指标：** wall_ms（dispatch 全程，p50）、SQL 条数（p50）、SQL 累计 ms、峰值内存(MB)、HTTP 状态码。
- **配对顺序：** 全页 warmup 一轮丢弃 → 每页顺序 5 轮，p50。重复 MISS 样本可直接比较，因 ResolveSite 每请求 `reapply()/clear()` 复位数据型 memo。
- **门槛（来源）：** N+1 判据 = 同一 SQL 模板出现 ≥2 次，且在临时库 +200 行对照中**线性增长**（斜率>0.5）；固定重复（随数据量持平）只记为「重复 SQL/固定开销」，不记 N+1。门槛来源：本报告自定（预登记）。
- **失效动作：** 状态码非 2xx 记 status 列；临时库副本测完即删；主库行数测量前后核验未变（contents=7/published article=6/entities=18）。

---

## 4. 实测 Artifact 链（每页面 p50 原始样本，5 轮）

> 完整逐轮 raw 样本与逐指纹 SQL 见运行产物 JSON（脚本同目录可复跑生成）。wall=dispatch 全程；sql=SQL 条数；sqlms=SQL 累计耗时。

### 4.1 后台（已登录，无整页缓存）

| 页面 | 状态 | wall ms | SQL 数 | SQL ms | 备注 |
|---|---|---|---|---|---|
| admin.dashboard | 200 | 88.4 | **55** | 37.2 | 见账本#1（facts 逐内容查） |
| admin.contents | 200 | 71.7 | 36 | 30.9 | |
| admin.contents.all | 200 | 73.9 | 36 | 34.3 | |
| admin.entities | 200 | 74.2 | 33 | 43.9 | |
| admin.relations | 200 | 69.9 | 34 | 26.1 | |
| admin.inquiries | 200 | 55.9 | 28 | 31.7 | |
| admin.media | 200 | 53.9 | 27 | 20.7 | |
| admin.facts | 200 | 57.5 | 25 | 25.9 | |
| admin.pages | 200 | 62.5 | 27 | 22.7 | |
| admin.narrative | 200 | **107.7** | **57** | 52.4 | 见账本#2（slot 逐行查 ×18） |
| admin.forms | 200 | 62.7 | 34 | 38.1 | |
| admin.forms.submissions | 200 | 59.0 | 32 | 24.4 | |
| admin.categories | 200 | 57.6 | 28 | 20.2 | |
| admin.groups | 200 | 54.3 | 32 | 22.6 | |
| admin.menus | 200 | 95.5 | 34 | 24.6 | 内存峰值首个抬升点 40MB |
| admin.redirects | 200 | 58.6 | 27 | 21.3 | |
| admin.seo-metas.all | 200 | 51.3 | 28 | 20.4 | |
| settings.general | 200 | 72.0 | 31 | 23.5 | 8 组 settings 均 31 SQL（同模板） |
| settings.theme | 200 | 70.8 | 31 | 21.3 | |
| settings.contact | 200 | 64.4 | 31 | 24.2 | |
| settings.copy | 200 | 83.4 | 31 | 23.2 | |
| settings.seo | 200 | 58.9 | 31 | 22.6 | |
| settings.geo | 200 | 68.7 | 31 | 24.9 | |
| settings.analytics | 200 | 62.1 | 31 | 22.0 | |
| settings.sync | 200 | 57.1 | 31 | 21.7 | |
| geo.tools | 200 | 48.8 | 23 | 17.8 | |
| geo.coverage | 200 | 59.0 | 31 | 23.2 | |
| geo.health | 200 | **123.2** | **73** | 46.8 | 全库 SQL/耗时最高（含 category/content_entity 逐行查） |
| geo.sync-logs | 200 | 53.6 | 27 | 22.3 | |
| themes | 200 | 49.8 | 25 | 20.0 | |
| templates | 200 | 89.8 | 25 | 22.1 | |
| plugins | 200 | 54.3 | 39 | 15.1 | |

### 4.2 前台（匿名，PageCache MISS 路径）

| 页面 | 状态 | wall ms | SQL 数 | SQL ms | 备注 |
|---|---|---|---|---|---|
| home | 200 | 98.8 | 62 | 26.2 | 见账本#4（BlockRegistry entities ×4） |
| products.list | 200 | 77.0 | 55 | 20.5 | slot lead ×4 |
| products.detail | 200 | 83.9 | 54 | 22.2 | 见账本#5（entity_relations ×4） |
| solutions.list | 200 | 63.1 | 52 | 20.7 | |
| solutions.detail | 200 | 102.8 | 50 | 26.1 | |
| cases.list | **404** | 32.7 | 29 | 11.3 | 当前无 case_study 实体，预期空态 |
| knowledge.index | 200 | 60.7 | 55 | 21.3 | |
| knowledge.channel(selection) | 200 | 58.3 | 44 | 16.9 | |
| article.detail | **301** | 19.8 | 22 | 8.6 | CanonicalizeSlash 跳转，未测渲染页 |
| about.profile | 200 | 62.8 | 52 | 20.6 | |
| about.history | 200 | 69.3 | 52 | 23.4 | |
| about.culture | 200 | 61.3 | 52 | 22.1 | |
| factory | 200 | 68.6 | 52 | 22.7 | |
| cooperation | 200 | 71.7 | 52 | 23.4 | |
| contact | 200 | 77.6 | 61 | 25.1 | |
| search?q=coating | 200 | 53.3 | 44 | 19.0 | 不进整页缓存，恒动态 |
| sitemap.xml | 200 | 48.9 | 41 | 19.8 | |
| geo.json | 200 | 71.3 | 53 | 27.1 | |
| llms.txt | 200 | 48.2 | 43 | 19.3 | 见账本#3（category 逐行懒加载） |
| feed.xml | 200 | 33.0 | 31 | 14.0 | |
| robots.txt | 200 | 8.5 | 10 | 3.3 | |

### 4.3 跨页「重复 SQL」背景（非行级 N+1，固定开销）
- 几乎每页都有 `select * from "cache" where "key" in (?)` ×5~×10（database cache 驱动，`CACHE_STORE=database`）。这是框架每请求的缓存键读取，**不随业务行数增长**，记为固定开销，不计入 N+1 账本。
- 后台每页有 `select * from "sites" ...` ×1~×2（ResolveSite/CachePage），固定。

---

## 5. 候选账本（全部 `EXPERIMENT_REQUIRED`，不授权实施）

> N+1 判据：同一 SQL 模板逐行重复，且临时库 +200 行对照线性增长。重复 SQL 但不随数据量增长者单列。

| # | 页面/位置 | SQL 模板 | 基线重复次数 | 增长验证(6→206篇) | locator | 状态 |
|---|---|---|---|---|---|---|
| 1 | admin.dashboard | `select "value" from "facts" where "key"=? and "facts"."site_id"=? limit 1` | **×6** | **×6 → ×206（斜率 1:1，确证 N+1）** | `app/Services/Gate/ContentGate.php:145`（由 `DashboardController.php:42` 的 published-content 循环逐条调用） | EXPERIMENT_REQUIRED |
| 2 | admin.narrative | `select * from "contents" where "status"=? and (...published_at...) and "slot"=? and "locale"=?` | **×18** | ×18（对照持平）——随**产品/场景数**而非文章数增长；slot key 数 = 运营插槽数 | `app/Support/Narrative.php:54`（`Narrative::find()`，由 `NarrativeController@index → Narrative::registry()` 每插槽调一次） | EXPERIMENT_REQUIRED |
| 3 | llms.txt / 文章列表 | `select * from "categories" where "categories"."id"=? and "categories"."site_id"=? limit 1` | **×6**（llms.txt） | ×6 → **×20**（确证逐行懒加载；分页上限约 20/页） | `app/Models/Content.php:184`（category 关系懒加载） | EXPERIMENT_REQUIRED |
| 4 | home / geo.health | `select * from "entities" where "status"=? and "locale"=? and "type"=? ...` | ×4（home） | 未做 +200 实体对照（未验证项） | `app/Support/Blocks/BlockRegistry.php:174` | EXPERIMENT_REQUIRED |
| 5 | products.detail | `select * from "entity_relations" where "from_entity_id"=? and "relation_type"=? ...` | ×4 | 未做 +200 实体对照（未验证项） | `app/Support/Render/EntityRenderContext.php:226` | EXPERIMENT_REQUIRED |
| 6 | admin.dashboard（固定重复，非 N+1） | `select count(*) from "contents" where "status"=?` ×3 / `where "type"=?` ×3 | ×3 | 固定 6 个聚合计数，不随行数线性放大 | `DashboardController.php:22-30` | EXPERIMENT_REQUIRED |

**最重的 3 个点（按 SQL 条数与增长确证度）：**
1. **账本#1 Dashboard facts 逐内容查** — 基线 ×6，+200 篇即 ×206（1:1 线性），wall 88ms→345ms。
2. **账本#2 Narrative 插槽逐行查** — 基线 ×18（单页最重重复），随产品/场景数增长。
3. **账本#3 Content category 懒加载** — llms.txt/列表页逐行 ×6→×20。

---

## 6. 不授权优化声明

本轮为 `establish-baseline` + `diagnose`（机制假设已被增长对照命中），**未做同 fingerprint 的单变量 A/B，无 oracle/mutant 证据**。因此：
- 不给出任何优化收益、优先级、实施步骤；
- 账本#1~#5 的 eager-load/批量查询改造需在下一轮以同合同 A/B + 语义 oracle 验证后另行授权；
- 本脚本为只读测量工具，未修改任何业务代码。

## 复跑方式

```powershell
cd D:\GEO-OS-rewrite\geo-website-os
C:\php84\php.exe -d memory_limit=512M scripts/audit/perf-baseline.php --only=all --iter=5 --out=<输出.json>
# 可选 --only=admin|front
```
脚本位置：`scripts/audit/perf-baseline.php`。原始样本（逐轮 wall/sql/逐指纹重复 SQL/locator）写入 `--out` 指定 JSON。
