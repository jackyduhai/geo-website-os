# STEP 09 — Performance / Cache / N+1 Audit

> 原则：数据隔离 > 性能优化；所有缓存 Key 经 SiteCacheKey（含 site_id）；
> 请求级记忆化必须逐请求复位（防 FPM/serve 进程跨请求读到旧快照）。

## 1. 实测（dev sqlite，同进程预热后，2026-09-19）

| 请求 | Before（queries / time） | After（queries / time） | 降幅 |
|---|---|---|---|
| GET `/`（热态） | 8 / 10ms | **6 / 8ms** | 25% / 20% |
| GET `/geo.json` | 60 / 69ms | **32 / 60ms** | 47% / 13% |
| GET `/sitemap.xml` | 46 / 36ms | **18 / 24ms** | 61% / 33% |
| GET `/knowledge/` | 17 / 18ms | **9 / 14ms** | 47% / 22% |

（首次请求另含冷启动开销 ~400ms，属缓存预热，不计入热态基线。）

## 2. 修复项

| # | 问题 | 修复 |
|---|---|---|
| P1 | `SiteScope::apply` 对**每个** site-scoped 查询做 `Schema::hasTable + hasColumn` schema 内省（每表最多 3 条内省 SQL × 每请求几十次查询） | 表资格请求级记忆化 + `resetRequestMemo()`（AppServiceProvider::boot 复位） |
| P2 | `SiteCacheKey::currentSiteId()` 每次拼缓存 key 都内省 sites 表 + 查 defaultId | 请求级记忆化（site_id 单次解析） |
| P3 | `SeoMetaResolver` 无记忆化：同一请求内 site 级 SeoMeta、每个 Content/Entity 的 SeoMeta、每个 Media lookup 各查一次 | 请求级记忆化（含负缓存）+ `preloadContentSeoMetas / preloadEntitySeoMetas / preloadMediaPaths` 批量预载 API |
| P4 | `GeoGraphBuilder` N+1：每实体 2-4 查询、每条关系 2 次 `Entity::find`、每内容 media 查询 | 批量预载（SeoMeta/Media）+ 关系两端实体单次 `whereIn` |
| P5 | `SitemapBuilder` 文章 `url()` 走栏目父链懒加载 | `with('category.parent')` / `with('parent')` 预载 |

## 3. 既有机制确认（无需改动）

| 机制 | 状态 |
|---|---|
| `Setting::allCached()` | 整表永久缓存 + 请求级内存 ✅ |
| `PageCache` 整页静态化 | site-scoped key + 版本号失效 ✅（CachePage 中间件） |
| `SchemaBuilder` | 构造读 `Setting::allCached()`（请求级单次）；article() 由 PageController 传入 SeoResult，**无二次 Resolution** ✅ |
| 导航树 / 主菜单 / Facts | 请求级 memo（boot 复位）✅ |
| HandleRedirects 跳转规则 | 缓存驱动 + 模型事件失效 ✅ |
| SeoResult DTO | 只读、无副作用 ✅ |

## 4. 风险与边界

- 记忆化复位钩子在 `AppServiceProvider::boot()`（FPM/serve 每请求 boot 一次）。
  与既有 `Fact::flushMemo() / Setting::resetRequestMemo()` 同一约定。
- 长驻进程（queue worker）依赖 boot 复位；若未来出现请求外长驻执行，
  需在作业边界补复位（已列 Remaining Technical Debt）。
- SeoMeta 无模型事件失效 PageCache：后台修改 SEO 后由后台内容保存链路
  触发 flush；独立 SeoMeta 写入路径需复核（列入 Remaining Technical Debt）。

## 5. 复测方式

```bash
php /tmp/measure2.php   # before/after 脚本（预热 + 重复查询分组）
```
