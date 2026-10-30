# P-STEP 18H-1 — Search Productization Gate

- **阶段**：P-STEP 18H-1（Final Product Completeness · Operations 第一 Gate）
- **范围**：Search Contract v1 → `SearchEngineInterface` → `SqliteFtsEngine`（V1 默认）+ `DatabaseLikeEngine`（回退）+ 统一派生索引；增量同步、`search:reindex`、BM25 标题优先、DB 层分页、安全 highlight/snippet，替换旧 LIKE + 内存分页。
- **基线起点**：HEAD `bf3d576`（= tag `checkpoint-18G-2b`），Regression 950 / 4873 / 0 / 0，worktree clean。
- **结论**：✅ **ACCEPTED / PASS**，完成后 STOP，不自动进入 18H-2。

---

## 1. 交付物

**契约 / 引擎（`app/Support/Search/`）**
- `SearchQuery.php`：readonly（term / locale / siteId / types / page / perPage + offset / normalizedTypes）。
- `SearchResults.php`：items / total / page / perPage + LengthAwarePaginator。
- `SearchResult.php`：resourceId / score / excerpt + `url()` / `snippet()`。
- `SearchEngineInterface.php`：search / name / isAvailable。
- `CjkTokenizer.php`：`tokenize`（汉字 unigram + 相邻 bigram、拉丁/数字整词）、hasHan、highlightTerms。
- `Highlighter.php`：terms / `mark`（**先 `e()` 再 `/iu` 包 `<mark>`，可安全 `{!! !!}`**）/ excerpt。
- `SearchIndexBuilder.php`：rebuildAll / rebuildSite / upsertContent / upsertEntity / removeDocument / clearSite / insertRows / documentFor*；**写入全程查询构造器、不触发 Eloquent 事件**。
- `SearchIndexSync.php`：增量同步 + dirty 懒重建（实例 `$registered`、static `$suppressed`）。
- `SqliteFtsEngine.php`：FTS 匹配 + BM25（title 10 / summary 5 / body 1）+ 回查普通表；search 先 flushDirty。
- `DatabaseLikeEngine.php`：普通表 LIKE 回退、DB 分页（CJK 子串天然命中）。

**迁移 / 接线**
- `2026_09_24_000016_create_search_index.php`：普通表 `search_documents`（原文/展示，含 path）+ FTS5 虚表 `search_index`；双重守卫（驱动 sqlite + 一次性 `_fts5_probe`）。
- `AppServiceProvider`：`SearchEngineInterface` 延迟闭包绑定；boot 注册 SearchIndexSync。
- `SearchReindex`（`search:reindex`）；`GeoInstall` / `GeoUpgrade` 接线（安装/升级末尾自动重建）。
- `Site/SearchController.php` + `resources/views/site/search.blade.php`：只构造 query 调引擎。

**测试**
- `tests/Feature/SearchProductization18HTest.php`：**18 测试 / 66 assertions**（默认引擎、CJK 后缀/中段/单字/非相邻不命中、标题优先、locale 隔离、cross-site 隔离、type 过滤、DB 分页、新增/删除/草稿→发布索引、dirty 懒重建、特殊字符安全、HTTP XSS 转义、HTTP mark + noindex、HTTP en）。
- `tests/TestCase.php`：新增 `tearDown()` 统一 `RequestScopedState::flushAll()` + `SiteContext::clear()`。
- `Localization18FTest.php` / `FeedPublicRenderContractTest.php`：高亮打断连续标题，断言改 `strip_tags` 后连续文本 + 精确 `<mark>`（反增强），否定断言保留 raw。

---

## 2. Gate 证据（逐项）

| # | Gate 项 | 结果 | 证据 |
| --- | --- | --- | --- |
| 1 | Focused tests | ✅ | SearchProductization18H + Localization18F + FeedPublicRenderContract = **41 passed / 275 assertions** |
| 2 | Full regression | ✅ | **968 passed / 4962 assertions / 0 failed / 0 skipped**（起点 950/4873；+18 测试、断言 +89） |
| 3 | Fresh install | ✅ | 全新空库 `geo:install -n` 成功；installer 末尾自动 "Search index rebuilt: **0** document(s)"（空站正确） |
| 4 | Blank-site HTTP | ✅ | 空 `/search`=200；`q=test` 显示「没有找到与「test」相关的内容」、无 result posts；`q=<script>alert(1)</script>` raw `<script>alert` count=**0**；`/en/search`（出厂 zh-only）=**404** |
| 5 | Demo-site HTTP | ✅ | `db:seed` 后增量自动建索引：zh「底漆」=**2**、「环氧底漆」=**2**；en「primer」=**2**；标题/snippet `<mark>` 高亮正确（zh「环氧富锌<mark>底漆</mark> ZP-100」、en「Zinc-Rich Epoxy <mark>Primer</mark> ZP-100」） |
| 6 | Real Browser UAT | ✅ | zh-dark「站内搜索 / 找到 2 条」、en-dark「Search / Found 2 results」实拍，黄色 mark 在深色背景清晰、面包屑正确；**两次 console_messages = 0** |
| 7 | Multi-Site × 搜索 | ✅ | SearchProductization cross-site（A/B 站不串）真实 DB 锁定；另修复跨测试类 SiteContext 污染（TD-81） |
| 8 | Feeds 不回归 | ✅ | /feed.xml、/sitemap.xml、/llms.txt、/robots.txt、/geo.json、/en/feed.xml、/en/llms.txt 均 **200** |
| 9 | SEO / GEO / Schema | ✅ | 全量回归（含 Localized SEO / Schema / GEO / Sitemap）通过，搜索改造未触碰公开契约 |
| 10 | Cache（locale 隔离） | ✅ | PageCache 含 locale（全量覆盖）；搜索结果为查询态、不被错误整页缓存串语言 |
| 11 | Runtime pollution | ✅ | BusinessPollutionZeroTest（扫描 app/resources）通过 = **0**；搜索新代码无业务/品牌污染 |
| 12 | Log audit | ✅ | laravel.log 最后写入 **02:26 UTC**（验证窗口 05:05 之前），最近 300 行无 ERROR/CRITICAL；serve 请求零错误 |
| 13 | Smoke cleanup | ✅ | 停 serve 8150、清 storage/framework/{cache/data,page-cache,views}、删临时 DebugSearch18.php、无残留 php serve 进程（81xx） |
| 14 | Git | ✅ | `git diff --check` 干净；commit + annotated tag `checkpoint-18H-1`；worktree clean（见下） |

---

## 3. 本轮真实缺陷（非静态扫描）

全量回归首次跑出 **6 failed**，全部定位并修复：

- **失败④/⑤（FeedPublicRenderContract / Localization18F 搜索断言）**：`<mark>` 高亮打断连续标题（`<mark>Demo</mark> Product`），写于高亮前的旧断言失败。高亮是 18H-1 正确行为、非产品 bug；测试改 `strip_tags` 断言连续文本 + 精确 mark（反增强）。
- **TD-80（失败①②③⑥共同根因，Catalog 半成品 memo）**：SearchIndexSync 在第一个 Product saved 时经 `PublicUrl::entity` → `Catalog::dataset()` 首次构建并 memo（此刻库中尚无 Service → scenes=[]），后续 Service saved 读空 scenes 不被索引。修复：SearchIndexSync 的 Entity saved/deleted 与 EntityRelation 回调在搜索派生（含 catalog 读取）之后 `Catalog::flush()`，下一次 saved 重建 dataset 时当前行已入库。
- **TD-81（跨测试类 SiteContext 污染）**：CatalogRuntimeIsolationTest 最后方法 `setSite(site-b,id=2)` 且无 tearDown，RefreshDatabase 重建内存库后静态仍持旧 Site，BelongsToSite creating 取 `currentSiteId()=2` → facts FK。修复：`tests/TestCase` 统一 tearDown `RequestScopedState::flushAll()` + `SiteContext::clear()`。三类组合手动混跑从 24 failed → **30 passed / 437 assertions**。

---

## 4. 债务状态

- **CLOSED by 18H-1**：TD-71（契约）、TD-72（FTS5）、TD-78（flushDirty 站点上下文）、**TD-80（Catalog 半成品 memo）、TD-81（跨类 SiteContext 污染）**。
- **新增 ACTIVE**：TD-77（Form Submission 层 → 18H-2）。
- **DEFERRED v1.1**：TD-79（Page/Landing 纳入搜索索引，待用户裁定）。
- **v1.0 Required 未闭合 = 7**：P0×3 外部发布工程（TD-01 Cloud CI / TD-02 最终 RC / TD-03 Public·Release）+ 18H 代码层 4（TD-59 Form Builder、TD-77 Submission、TD-73 Analytics、TD-74 Audit）。

---

## 5. 边界纪律确认

- 未移动 `v1.0.0-rc1`（`965d63c` HOLD）；未配置 remote；未 push；未重建 RC；未 Release。
- 搜索索引仅 **Derived Read Model**，权威仍是 Content / Entity / Relation，未形成第二事实源；FTS token 串只用于匹配/排名/定位，原文展示走 `search_documents`。
- highlight/snippet 经安全 HTML escaping；`<mark>` 打断连续文本，相关断言用 `strip_tags`。

## 6. 判定

**P-STEP 18H-1 = ✅ PASS / ACCEPTED → STOP。** 待明确授权后进入 P-STEP 18H-2（Form / Inquiry Productization）。
