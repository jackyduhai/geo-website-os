# P-STEP 18S Capability 2 — GEO Health Dashboard · Implementation Final

- 阶段：P-STEP 18S / Product Capability 2（GEO 内容·语义健康后台视图）
- 基线 Discovery：`246c82d`；Cap1 fixed：`941ad3c`
- 定位：语义健康只读聚合（OG / 落地页 / JSON-LD / noindex / 图谱边），**不是**基础设施 /health（`Api\HealthController` 边界保持）。
- 结论：**PASS（P0 = 0）/ CLOSED**。

---

## 1. Changed files

| 文件 | 变更 | 说明 |
| --- | --- | --- |
| `app/Services/Geo/GeoHealthService.php` | 新增（~330 行） | 极薄只读聚合层：`report()` 跑 5 检查，返回 PASS/WARNING/FAIL/N/A + counts + affected；无写库/持久化/缓存表/migration。 |
| `app/Http/Controllers/Admin/GeoController.php` | +12 行 | 新增 `health(GeoHealthService)` → `view('admin.geo.health')`。 |
| `routes/admin.php` | +1 行 | `GET geo/health`（名 `admin.geo.health`），挂在既有 `admin.auth + admin.site` 组。 |
| `resources/views/admin/geo/health.blade.php` | 新增 | 五卡片：状态徽标 + counts 表 + affected 列表（含去编辑链接、只读、无一键修复）。 |
| `resources/views/admin/layout.blade.php` | +1 行 | 导航「搜索与 AI（SEO/GEO）」组新增「GEO 健康」入口。 |
| `tests/Feature/Admin/GeoHealthTest.php` | 新增 | 9 用例（55 断言）。 |

## 2. Architecture compliance

- **数据流**：DB(Entity/Content/EntityRelation/ContentEntity/SeoMeta) → 既有 PublicIndex / SeoMetaResolver / PublicUrl / SchemaBuilder / Catalog → `GeoHealthService` 聚合 → Admin View。
- **复用、不复制规则**：
  - OG/noindex：`SeoMetaResolver::resolveEntity/resolveContent`（含 `preload*` 消除 N+1）。
  - 公开口径：`PublicIndex::entityQuery/contentQuery`（published + 非 noindex + 栏目启用 + 当前站 + 当前 locale）。
  - 落地页：`PublicUrl::entity()`（真实路由裁决，非拼 `/products/{slug}`）。
  - JSON-LD 覆盖：`SchemaBuilder::entity/article`。
  - 边口径：与 `GeoGraphBuilder` 一致——EntityRelation + ContentEntity，两端均公开才计数（site/locale scoped，不 `count(*)`）。
  - 核心产品判定：`Catalog::isCoreProduct()`。
- **检测器、非修复器**：渲染 Dashboard 不产生任何写库/SQL 变更（`test_rendering_dashboard_is_read_only` 断言前后 entity/relation/seo_meta 计数不变）；无自动补 OG/建关系/改 noindex。
- **状态模型**：无 0–100 评分；总体：有 FAIL→FAIL，否则有 WARNING→WARNING，全过→PASS，空站→N/A。
- **Blank 空站一等场景**：0 公开实体/内容 → 五检查全 N/A、overall N/A，不报假 Fail（`test_blank_site_is_na_without_false_failures`）。

## 3. Check A–E 结果（seeded demo 站实测）

| 检查 | 状态 | 计数（实测） | 口径要点 |
| --- | --- | --- | --- |
| A Missing OG | WARNING | 公开资源 15 / 显式 SEO 覆盖 0 / OG 图回退 logo 0 / 缺描述 0 | 区分 explicit/fallback/missing；fallback 合法→只 WARNING 不造假 Fail；列出未做 SEO 覆盖的 entity/content。 |
| B Published Product Without Public URL | PASS | 公开产品 8 / 非核心无独立页 4（by design）/ missing 0 | 对 published 产品调 `PublicUrl::entity()`；核心+应落地却 null→Fail；非核心→N/A 不误判。 |
| C JSON-LD Emission | PASS | home 1/1、product 8/8、service 3/3、case_study 0/0、article 3/3 | builder-level 覆盖；HTTP 三层证据见 §4。 |
| D noindex Leakage | PASS | 已发布却 noindex 0 | 反向找 published+noindex 矛盾态；draft/archived/admin/private 不纳入；命中→WARNING（人工确认意图）。 |
| E GEO Edge Count | PASS | Entity→Entity 58 / Content→Entity 0 / 孤立实体 0 / by_type 见 `by_type` | 两端公开才计数；自动排除他站/未公开端/orphan pivot；孤立公开实体计数+列表。 |

## 4. HTTP evidence（fresh sqlite `geo3.sqlite` :8130，真实浏览器 + Invoke-WebRequest）

- **三层证据**：
  - Builder-level：`SchemaBuilder::entity/article` 对公开产品/内容均产出非 null 节点（Check C 计数）。
  - Render-path：前台 layout `{!! app(SchemaBuilder::class)->render($schemas) !!}` 消费各 RenderContext::schemaNodes()。
  - HTTP-level：真实 GET 五页型，200 + HTML 含 `application/ld+json` + JSON 可解码 + 含 `@type`（`test_http_jsonld_emitted_on_home_and_product` 抽取脚本块解码断言，非字符串包含方法名）。
- 实测 HTTP（:8130）：
  - `/` → 200，ld+json×3，canonical ✓，hreflang ✓
  - `/products/` → 200，ld+json×4，canonical ✓，hreflang ✓
  - `/products/epoxy-primer-100` → 200，ld+json×6，canonical ✓，hreflang ✓
  - `/solutions/` → 200，ld+json×4，canonical ✓，hreflang ✓
  - `/knowledge/` → 200，ld+json×3，canonical ✓，hreflang ✓
  - feeds：`/sitemap.xml` 200、`/llms.txt` 200、`/geo.json` 200、`/robots.txt` 200、`/feed.xml` 200。

## 5. Browser evidence（真实浏览器登录 admin@example.com → GEO 健康）

- 登录后导航「搜索与 AI（SEO/GEO）→ GEO 健康」（`/admin/geo/health`）200，无 PHP/SQL 错误。
- 顶栏显示站点名 + 语言（zh-CN）+ 整体状态徽标（demo 站整体 WARNING）。
- 概览五卡状态徽标：Missing OG=WARNING、Published Product=PASS、JSON-LD=PASS、noindex=PASS、Edge Count=PASS。
- Missing OG 展开列出 affected（示例制造有限公司 org、各产品/内容），原因「未做 SEO 覆盖（当前使用站点兜底 OG）」+「去编辑 →」链接，只读。
- JSON-LD 表：home/product/service/article 应产出=已产出；noindex 0；Edge Count Entity→Entity 58。
- UI 复用现有 `.card/.tbl/.stat/.badge` 与既有 admin layout（dark/light 跟随现有机制），未新增 inline style/token。

## 6. Blank / Demo / Locale / Multi-Site

- **Blank**：全新空站 → Dashboard 200、overall N/A、五检查全 N/A、无假 Fail/虚构实体/边（单测覆盖）。
- **Demo**：计数随 seed DB 真值变化（15 公开资源/8 产品/58 边），无 hardcode 固定数量/名称/结果。
- **Locale**：全部按 `LocaleContext::current()`（zh-CN）；PublicIndex 按 locale 过滤。
- **Multi-Site 隔离**：A 站放实体，切 B 站 → B 为 blank（A 实体不计入），`test_site_isolation_other_site_entities_not_counted`；不串站、不串语言。

## 7. Security / Performance

- **鉴权**：路由挂 `admin.auth + admin.site`；guest 访问 `/admin/geo/health` 302 跳登录（`test_guest_is_redirected_to_login`）。无公开 `/api/geo-health`。
- **Site 隔离 / IDOR**：全部查询来自 `SiteContext::currentSite()` + BelongsToSite；**不读 request('site_id')**。
- **CSRF**：纯 GET 只读聚合，无写端点。
- **N+1**：复用 `preloadEntitySeoMetas/preloadContentSeoMetas/preloadMediaPaths`；边计数用 `whereIn` 批量取端点实体；公开集一次 `get()` 后内存聚合，不千级逐条查关系。

## 8. Full Regression

- `php artisan test`（前台输出 `D:\Temp\geo2_full.log`）：**1208 passed / 6450 assertions / 0 failed / 0 skipped**（约 822s）。
- 对比 Cap1 TD-161 修复后基线 1199/6393：净增 9 用例（GeoHealthTest）/57 断言，无回退。
- Focused：`tests/Feature/Admin/GeoHealthTest.php` 9 passed / 55 assertions。

## 9. Git status

- 本提交前工作树仅含：新增 GeoHealthService / health.blade / GeoHealthTest，改动 GeoController / routes/admin / layout。
- 未 push、未配 remote、未动 rc1（`965d63c`）；19A 保持 HOLD。

## 10. TD impact

- 复用并兼容既有 ContentGate（内容深度诊断），未复制其规则；未触碰 TD-162（P3 不动）。
- TD-158（content_entity pivot 无删除清理/orphan 累积）：本检查用「两端公开」口径，orphan pivot（端已删）被自然排除，不误计；仅记录，不在本能力修。
- TD-148（geo.json site.description 与 JSON-LD 不一致）、TD-155（org 名回退）：观察项，未依赖、未关闭。
- 本能力未新增 TD 编号。

## 11. Known limitations

- Check C 的 live dashboard 为 builder-level 覆盖计数；HTTP 级 ld+json 证据由 Feature 测试真实 GET 提供（未在 live 页对每个实体发内部 sub-request，避免性能开销）。
- Check D 无法区分 noindex 是有意还是无意，故命中报 WARNING 交人工确认（不报 Fail）。
- English locale 的 admin 文案未单独翻译（沿用现有 admin 中文文案，与其他后台页一致）。

## 12. 合规清单（红线核对）

- New Entity = **0**
- New Table = **0**
- New Column = **0**
- New Migration = **0**
- New Renderer = **0**
- Second SEO Pipeline = **No**
- Second GEO Pipeline = **No**
- Second Relation System = **No**
- 0–100 伪评分 = **无**
- Business Hardcoding = **0**（扫描 Demo Tenant A/Demo Tenant A/固定品牌·产品名/行业/URL 均无命中）

**Cap2 PASS（P0=0）/ CLOSED。** 按要求 STOP，不进入 Capability 3，不碰 19A/remote/push/release。
