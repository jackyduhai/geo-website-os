# 20B 代码库架构现状审查

> 快照：`geo-website-os@c6f04fd`（工作树 clean），Laravel 12 + PHP 8.4 + SQLite。
> 审查方式：只读静态追踪 `routes → controller → service → model → view` 与模型事件装配；未运行写操作、未跑全量测试。
> 范围：198 条注册路由（Admin 142 + Frontend 47 + API 5 + Closure 8）全部经 `artisan route:list` 验证可解析，无指向不存在方法的死路由。

---

## 1. 身份/范围与证据矩阵

| named stage | literal locator | observed branch/callee | claim scope | status |
|---|---|---|---|---|
| 路由装配 | `routes/admin.php`, `routes/web.php`, `routes/api.php` + `bootstrap/app.php` | 198 路由全部可解析；`route:list` 无异常 | 无 controller 方法缺失死链 | confirmed |
| 前台站点解析 | `app/Http/Middleware/ResolveSite.php` (web group prepend, `bootstrap/app.php:21`) | 按 HTTP_HOST 绑定 SiteContext；API group 不挂此中间件 | 前台多站隔离；API 走 default 站 | confirmed |
| 后台站点切换 | `app/Http/Middleware/SetAdminSiteContext.php` | session `admin_site_slug` → SiteContext::setSite + RequestScopedState::reapply | 后台切站复位全部 static memo | confirmed |
| SiteScope 全局隔离 | `app/Models/Scopes/SiteScope.php` + `app/Support/BelongsToSite.php` | 所有业务模型 boot 时自动加 `where site_id = SiteContext::currentSiteId()` | 查询级多站隔离 | confirmed |
| PageCache 失效主链 | `app/Support/PageCache.php` + `app/Providers/AppServiceProvider.php:93-102` | Content/Category/Group/Menu/PageBlock/Setting/Media/Redirect/Fact/SeoMeta saved/deleted → PageCache::flush | 模型事件驱动整页缓存失效 | confirmed |
| Entity 缓存失效 | `app/Models/Entity.php:38-46` | Entity saved/deleted → PageCache::flush；deleted 还级联删绑定 Page | Entity 写入清整页缓存 | confirmed |
| EntityRelation 缓存失效 | `app/Models/EntityRelation.php:70-75` | saved/deleted → PageCache::flush | 关系写入清整页缓存 | confirmed |
| Page 缓存失效 | `app/Models/Page.php:46-52` + `app/Http/Controllers/Admin/PageController.php` | Page 自身无 deleted hook；update/publish 走 `PageCache::forgetPage()`；destroy 无任何缓存调用 | Page 更新/发布清缓存；**删除不清** | **conflicted** |
| 搜索索引同步 | `app/Support/Search/SearchIndexSync.php:47-85` | Content/Entity saved→upsert；SeoMeta/Category/EntityRelation/Setting/Site saved→markDirty | 增量索引 + 懒重建 | confirmed |
| GEO 产出 | `app/Http/Controllers/Geo/FeedController.php` | sitemap/llms/geo.json/robots/rss 全部动态生成，无静态文件 | 单一 FeedController | confirmed |
| SEO 解析 | `app/Support/Seo/SeoMetaResolver.php` + `app/Services/Geo/SchemaBuilder.php` | 单一 resolver + 单一 SchemaBuilder | 无第二套 SEO 拼装 | confirmed |
| 旧 Facts 类 | `app/Support/Facts.php` | `app/` 目录内零引用（grep 仅命中 tests/seeders/migrations） | 运行时死代码 | confirmed |
| 内容门禁 | `app/Services/Gate/ContentGate.php` | 后台发布与 Geoflow API 共用同一 gate | 单一门禁 | confirmed |
| 目录读模型 | `app/Support/Catalog.php` | 进程内 static memo（按 siteId+locale 键），非持久缓存 | 请求内 memo，切站 reapply 复位 | confirmed |
| 关系级联 | `database/migrations/2026_09_18_000009_create_entity_relations_table.php:17-18` | from/to entity_id ON DELETE CASCADE | DB 级联删关系，**不触发 Eloquent deleted 事件** | confirmed |
| content_entity/content_tag 外键 | `database/migrations/2026_09_26_000001_create_content_hub_tables.php:32-56` | unsignedBigInteger 但**无 constrained()、无 onDelete** | 删 Content/Entity 后残留孤儿行 | confirmed |

---

## 2. 直接答案（发现清单，按严重度分级）

### Critical
无。v1.0 已闭合项（route:cache Contact 500、Media 引用删除守卫、Unpublish 缓存旧 200、JSON-LD XSS、IDOR 多站隔离等）未在本轮发现回归。

### Major

**M-1. Page 删除不清整页缓存——已删页面仍可匿名访问至 TTL（6h）**
- 位置：`app/Http/Controllers/Admin/PageController.php:140-147`（`destroy()`）；`app/Models/Page.php:46-52`（仅 `deleting` 钩子 mass 删 PageBlock/SeoMeta，无 `deleted` 钩子）。
- 机制：`Page::deleting` 用 `PageBlock::where(...)->delete()` 与 `SeoMeta::where(...)->delete()` 批量删——批量查询删除**不触发 Eloquent 模型事件**，因此 `AppServiceProvider` 注册的 `PageBlock::deleted`/`SeoMeta::deleted` → `PageCache::flush()` 不执行。随后 `$page->delete()` 本身，Page 模型无 `deleted` 钩子，Controller 也未调 `PageCache::forgetPage()`/`flush()`。
- 后果：已发布 Page 被删后，其 path 的静态 HTML 仍驻留 file 缓存（`pagecache:html:{version}:{pathVer}:sha1(host+path)`），匿名访客命中旧 HTML，直至 6h TTL 自然过期或其他模型写入触发整站 flush。同控制器内 `update()`/`publish()`/block 操作均显式 `forgetPage()`，唯独 `destroy()` 遗漏，属不对称接线。
- 证据类型：`SOURCE`（源码字面行号）。

**M-2. content_entity / content_tag 中间表无外键级联——Content/Entity 删除后孤儿行静默堆积**
- 位置：`database/migrations/2026_09_26_000001_create_content_hub_tables.php:31-56`。
- 机制：两表均只声明 `unsignedBigInteger`，未调 `->constrained()`/`->onDelete('cascade')`。`ContentController::destroy()`（软删）与 `Entity::deleted`（硬删）都不清理这些 pivot 行。
- 后果：
  - 软删 Content 后 `content_entity` 行仍指向该 Content；`GeoGraphBuilder::relations()`（`app/Services/Geo/GeoGraphBuilder.php:224-242`）用 `PublicIndex::contentQuery()` 过滤，孤儿边不输出到 geo.json（不泄漏），但表内垃圾数据累积。
  - 硬删 Entity 后 `content_entity.entity_id` 指向不存在的 Entity；`GeoGraphBuilder` 用 `Entity::whereIn('id', ...)->get()` 过滤，孤儿边同样不输出，但成为不可回收垃圾。
  - 与 `entity_relations` 表（有 ON DELETE CASCADE）形成不对称：关系表自动清理，content_entity 不清理。
- 证据类型：`SOURCE`。

**M-3. GEOFlow API 无站点解析——多站部署下只能写 default 站**
- 位置：`bootstrap/app.php:21-24`（ResolveSite 仅 prepend 到 `web` group）；`routes/api.php:1-10`（API 路由挂 `api` middleware group）；`app/Http/Controllers/Api/GeoflowController.php`；`app/Services/Sync/GeoflowSync.php:64,165,180`。
- 机制：API 请求不经过 ResolveSite，`SiteContext::currentSite()` 自动绑定 default site（`app/Support/SiteContext.php:48-53`）。`VerifyGeoflowToken` 读 `Setting::get('sync_geoflow_token')` 取的是 default 站设置；`GeoflowSync` 内 `Content::where('external_id', ...)` 经 SiteScope 限定 default 站；新建 Content 时 `BelongsToSite::creating` 自动注入 default site_id。
- 后果：多租户部署下，GEOFlow 推送的内容永远进入 default 站，无法向其他站推送；其他站的 token 不被 API 识别（token 只配在 default 站）。当前 v1.0 单站模式不暴露，但这是一个未闭合的多站边界——API 契约里没有"目标站点"维度。
- 证据类型：`SOURCE`（中间件挂载事实）+ `inferred`（多站场景下的行为）。

### P1

**P1-1. Setup Wizard 第 6 步批量 update 绕过所有模型事件——产品发布不清缓存、不重建索引**
- 位置：`app/Http/Controllers/Admin/WizardController.php:99-102`。
- 机制：
  ```php
  Entity::where('type', Entity::TYPE_PRODUCT)
      ->where('site_id', $siteId)
      ->where('status', Entity::STATUS_DRAFT)
      ->update(['status' => Entity::STATUS_PUBLISHED]);
  ```
  这是查询构造器批量 update，**不触发 Eloquent `saved`/`updated` 事件**。因此：
  - `Entity::saved` 钩子（`app/Models/Entity.php:38-40` → `PageCache::flush()`）不执行；
  - `SearchIndexSync::Entity::saved`（`app/Support/Search/SearchIndexSync.php:60-63` → `upsertEntity` + `Catalog::flush()`）不执行；
  - 第 3 步 `Entity::create()` 在循环里逐条创建会触发 saved 事件（清缓存），但第 6 步批量翻状态不触发。
- 后果：向导完成后，若前台已有任何产品页缓存（例如向导前预览过），仍显示 draft 状态；搜索索引仍把这些产品标为 draft。首跑空库时无缓存、无索引，问题不暴露；但向导重跑或对已存在产品执行时，缓存/索引与 DB 状态分叉。
- 证据类型：`SOURCE`。

**P1-2. Entity 被删时其 EntityRelation 行经 DB CASCADE 删除——不触发关系侧 Eloquent 事件**
- 位置：`database/migrations/2026_09_18_000009:17-18`（ON DELETE CASCADE）；`app/Models/EntityRelation.php:70-75`（saved/deleted 钩子）。
- 机制：删 Entity 时，DB 自动删除 from/to 指向该 Entity 的 EntityRelation 行，但这是 DB 级联，**不触发** `EntityRelation::deleted` 事件。因此 `EntityRelation::deleted → PageCache::flush()` 不执行。
- 缓解：`Entity::deleted` 钩子（`app/Models/Entity.php:41-46`）自身调用了 `PageCache::flush()`，且 `SearchIndexSync::Entity::deleted`（`SearchIndexSync.php:64-67`）调用了 `Catalog::flush()`。所以整页缓存与 Catalog memo 仍被 Entity 删除路径清掉，关系级联本身不造成可见脏数据。
- 残余风险：如果未来有人在 `EntityRelation::deleted` 里加新副作用（例如通知、计数更新），DB 级联路径不会触发，形成隐性漏接。
- 证据类型：`SOURCE`（DB 级联不触发 Eloquent 事件是 Laravel 确定行为）。

**P1-3. NarrativeController 保存路径与清空/重置路径的缓存清理不对称**
- 位置：`app/Http/Controllers/Admin/NarrativeController.php:90-113`（保存）vs `:66-75`（清空）vs `:116-129`（重置）。
- 机制：
  - 清空/重置路径显式调 `PageCache::flush()` + `Narrative::flush()`；
  - 保存路径只调 `Narrative::flush()`，依赖 `Content::updateOrCreate` 触发的 `Content::saved` 事件（经 `AppServiceProvider.php:100` → `PageCache::flush()`）间接清缓存。
- 现状：当前能工作（模型事件确实接线），但保存路径未显式清缓存，是"靠 AppServiceProvider 全局列表兜底"的隐式依赖。若 Content 从该列表移除或事件被 suppress，保存叙事后前台不刷新。
- 证据类型：`SOURCE`（不对称调用）+ `inferred`（未来接线变更时的脆弱性）。

### P2

**P2-1. 进程内 static memo 不按 site_id 键——依赖 RequestScopedState 复位，CLI/常驻进程跨站有残留风险**
- 位置：`app/Models/Fact.php:31`（`$memo` 键为 'map'/'label'/'rows'/'group:X'）；`app/Models/Group.php:54`（`$knowledgeMemo` 单值）；`app/Models/Setting.php:34`（`$requestMemo` 单值）；`app/Support/Catalog.php:32-38`（按 siteId+locale 键，正确）。
- 机制：Fact/Group/Setting 的 static memo 未在键中含 site_id，全靠 `RequestScopedState::flushAll()`（`app/Support/RequestScopedState.php:52-75`）在 ResolveSite 之后、`SiteContext::withSite` 进出时复位。
- Web 请求链：ResolveSite → reapply → 复位，安全。
- 残余风险：queue worker / Octane / CLI 命令若在同一进程内切换站点而不走 `SiteContext::withSite`（即直接 `SiteContext::setSite()`），Fact/Group/Setting memo 会残留上一站数据。`SiteContext::withSite` 本身会调 reapply，但直接 `setSite()` 不会（`app/Support/SiteContext.php:32-35`）。
- 证据类型：`SOURCE`。

**P2-2. `Facts` 类为运行时死代码——config('facts.php') 旧全局读模型残留**
- 位置：`app/Support/Facts.php`（193 行）。
- 机制：grep `app/` 目录内 `Facts::` 零引用；仅 tests/seeders/migrations/docs 引用。它读 `config('facts.php')` 全局文件，绕过 SiteScope，是 P-STEP 14 之前的旧读模型。
- 后果：不是第二套活跃系统（不被调用），但留在 `app/Support/` 内易被未来开发者误用为"现成 helper"，重新引入跨站泄漏。Catalog 已替代其全部运行时职责。
- 证据类型：`SOURCE`（grep 边界）。

**P2-3. DashboardController 每次加载对全部已发布内容跑门禁——O(n) 重复计算**
- 位置：`app/Http/Controllers/Admin/DashboardController.php:42-47`。
- 机制：`foreach (Content::where('status','published')->with('category')->get() as $c) { $gate->check($c); }`——每次仪表盘加载都对所有已发布内容跑完整门禁（关键词、占位符、联系方式等规则）。
- 后果：内容量增长后仪表盘响应线性变慢；不影响正确性。
- 证据类型：`SOURCE`。

### P3

**P3-1. Page::deleting 批量删 PageBlock/SeoMeta 不触发模型事件**
- 位置：`app/Models/Page.php:48-51`。
- 机制：`PageBlock::where('page_id',$page->id)->delete()` 与 `SeoMeta::where('page_id',$page->id)->delete()` 为批量查询删除，不触发各自模型的 `deleted` 事件。
- 现状：PageBlock/SeoMeta 在 `AppServiceProvider.php:95,98` 的列表里，本应 `deleted → PageCache::flush()`；批量删时不触发。但因 Page 自身删除路径本就不清缓存（见 M-1），这里不是独立 bug，而是 M-1 的放大因素。
- 证据类型：`SOURCE`。

**P3-2. Content 软删后 content_entity 行不清理**
- 位置：`app/Http/Controllers/Admin/ContentController.php:159-166`（`destroy` 仅 `$content->delete()` 软删）。
- 机制：软删不触发 hard delete 事件；`ContentEntity::where('content_id', $content->id)` 行保留。
- 后果：PublicIndex 过滤 published 内容，软删内容的 content_entity 边不输出到 geo.json（不泄漏），但成为不可回收垃圾。回收站恢复时这些行仍在，不影响功能。
- 证据类型：`SOURCE`。

---

## 3. 关键主链与旁路（端到端调用链实测追踪）

### 链 A：内容发布 → 前台可见
```
admin.contents.publish (ContentController.php:171)
  → ContentGate::check(candidate)                    [app/Services/Gate/ContentGate.php]
  → $content->status='published'; $content->save()
      → Content::saved Eloquent event
          ├─ AppServiceProvider:93-102 → PageCache::flush()        [整页缓存失效]
          ├─ SearchIndexSync::upsertContent($c)                    [搜索索引增量]
          └─ AppServiceProvider:113 → forgetNavCache() (if Setting)
  → ContentRevision::create()                          [快照]
  → AuditLog::record('content.published')
  → redirect to edit
前台：匿名访客 → CachePage 中间件 miss → 重新渲染 → 新 HTML 入缓存
```
- 闭合：是。门禁、缓存、索引、审计四点均有观察点。
- 旁路：Geoflow API 推送走同一 ContentGate（`GeoflowSync.php:102`），同一 saved 事件链，无分叉。

### 链 B：实体（产品）发布 → 产品详情页可见
```
admin.entities.publish (EntityController.php:244)
  → $entity->status='published'; $entity->save()
      → Entity::creating slug 兜底 (Entity.php:28-32)
      → Entity::saved (Entity.php:38-40) → PageCache::flush()
      → SearchIndexSync::upsertEntity($e) + Catalog::flush()
  → Catalog::flush() (EntityController.php:540 显式)
前台：/products/{slug} → ProductController::show (Site/ProductController.php:208)
  → Entity::published()->forLocale()->ofType(PRODUCT)->where('slug',$slug)->first()
  → EntityRenderContext::forEntity($entity) → CompositionRenderer
```
- 闭合：是。但注意向导第 6 步批量翻 draft→published 绕过此链（见 P1-1）。

### 链 C：Page 删除 → 前台
```
admin.pages.destroy (PageController.php:140)
  → Page::deleting hook (Page.php:48-51)
      → PageBlock::where('page_id',$id)->delete()   [批量，不触发 PageBlock::deleted]
      → SeoMeta::where('page_id',$id)->delete()     [批量，不触发 SeoMeta::deleted]
  → $page->delete()
      → Page 无 deleted hook
  → Controller 无 PageCache::forgetPage / flush
  → redirect to index
前台：匿名访客命中 /{slug}
  → CachePage::get() —— 旧 HTML 仍在 file 缓存（version 未变、pathVer 未变）
  → 直接返回旧 HTML，不重新渲染
  → 直到 TTL 6h 或其他模型写入触发整站 flush
```
- 闭合：**否**。缓存未失效是断点。
- 对比：同控制器 `update()`（:135）、`publish()`（:156）、所有 block 操作（:256/266/291/303/336/365）均显式 `PageCache::forgetPage($page)`，唯独 `destroy()` 遗漏。

### 链 D：多站切换（后台）
```
admin.sites.switchSite (SiteController.php:168)
  → session()->put('admin_site_slug', $site->slug)
  → SiteContext::setSite($site)
  → RequestScopedState::reapply()
      → flushAll(): Fact/Setting/Group/Narrative/Copy/Catalog/SiteScope/SiteCacheKey/
                    SeoMetaResolver/ThemeManager/PluginManager/TemplatePackageManager memo 全清
      → ThemeManager::register(); PluginManager::register(); TemplatePackageManager::register()
  → back() 重定向 → 新请求 SetAdminSiteContext 中间件再读 session → setSite → reapply
```
- 闭合：是。切站后所有请求级 static memo 复位。
- 旁路：API 请求不经此链（见 M-3），永远 default 站。

---

## 4. 跨组件效果主张责任闭合卡

### 卡 1：内容发布后整页缓存自动失效
| 格 | 值 |
|---|---|
| 对象/范围 | 所有已发布 Content 的前台 path 的静态 HTML |
| 触发者 | ContentController::publish / GeoflowSync::upsert（status=published） |
| 当前装配 | Content::saved 事件 → AppServiceProvider.php:100 闭包 → PageCache::flush() |
| 实际执行者 | `AppServiceProvider::boot` 中 foreach 列表（Content::class 在第 94 行） |
| 成功副作用与观察点 | `pagecache:version` 键 +1（file store）；旧 key 不再命中 |
| 失败是否返回被检查 | 不适用（事件闭包内无外部 IO 可失败；Cache::forever 静默） |
| 重试/重放来源 | 无；TTL 6h 自愈兜底 |
| 不能覆盖的对象 | 不经过 Content::save 的批量 update（如 WizardController:99-102）、forceDelete（不触发 deleted） |
| status | `confirmed`（经模型事件路径）；`conditional`（批量/forceDelete 路径不覆盖） |

### 卡 2：Page 删除后该 path 缓存失效
| 格 | 值 |
|---|---|
| 对象/范围 | 被删 Page 对应 path 的静态 HTML |
| 触发者 | PageController::destroy |
| 当前装配 | **无**——Page 不在 AppServiceProvider 模型列表；Page 模型无 deleted hook；Controller 无 forgetPage |
| 实际执行者 | （缺失） |
| 成功副作用与观察点 | （缺失）——旧 HTML 仍可命中 |
| 失败是否返回被检查 | 不适用 |
| 重试/重放来源 | 仅 6h TTL 或其他模型写入触发整站 flush |
| 不能覆盖的对象 | 该 path 的所有缓存副本 |
| status | **`conflicted`**（主张"删除后前台立即 404"不成立；旧 HTML 可访问至 TTL） |

### 卡 3：实体/关系变更后 Catalog 投影与 geo.json 刷新
| 格 | 值 |
|---|---|
| 对象/范围 | Catalog 进程内 memo；/geo.json 与 /llms.txt 动态输出 |
| 触发者 | EntityController / EntityRelationController 写入 |
| 当前装配 | Entity::saved → SearchIndexSync.php:62,66 Catalog::flush()；EntityRelation::saved/deleted → SearchIndexSync.php:75,81 Catalog::flush()；Controller 末尾显式 Catalog::flush() |
| 实际执行者 | `Catalog::flush()` 清空 `$datasetsBySite`/`$productMapBySite`/`$sceneMapBySite` |
| 成功副作用与观察点 | 下一次 Catalog::company()/products() 重新查 DB |
| 失败是否返回被检查 | 不适用（纯内存操作） |
| 重试/重放来源 | 无（每次请求 ResolveSite → reapply → flushAll 已清） |
| 不能覆盖的对象 | 经 DB CASCADE 删除的 EntityRelation 行（不触发 EntityRelation 事件，但 Entity::deleted 已清 Catalog）；Wizard 批量 update（P1-1 不清 Catalog） |
| status | `confirmed`（经 Eloquent 事件路径）；`conditional`（批量 update / DB 级联路径） |

### 卡 4：GEOFlow API 写入后多站内容同步
| 格 | 值 |
|---|---|
| 对象/范围 | API 推送的 Content 落到哪个站 |
| 触发者 | api/v1/geoflow/upsert（Bearer token 鉴权） |
| 当前装配 | **ResolveSite 未挂 api group**；SiteContext 自动绑定 default 站 |
| 实际执行者 | `GeoflowSync::upsert` 内 `Content::where('external_id',...)` 经 SiteScope 限定 default；新建时 BelongsToSite 注入 default site_id |
| 成功副作用与观察点 | Content 行 site_id = default 站 id |
| 失败是否返回被检查 | token 不匹配返回 401；gate 不通过返回 422 |
| 重试/重放来源 | 上游按 external_id 幂等重推 |
| 不能覆盖的对象 | 非 default 站——无法向其他站推送；其他站的 token 不被识别 |
| status | **`conditional`**（仅 default 站生效；多站为未闭合边界） |

### 卡 5：设置保存后前台主题/导航/SEO 刷新
| 格 | 值 |
|---|---|
| 对象/范围 | 设置变更后的前台 HTML |
| 触发者 | SettingController::update / applyPreset / regenerateToken |
| 当前装配 | Setting::set → updateOrCreate → Setting::saved → AppServiceProvider:100 PageCache::flush()；Controller:169-170 显式 Setting::flush() + PageCache::flush() |
| 实际执行者 | Setting::flush() 清请求 memo + 持久缓存；PageCache::flush() 版本+1 |
| 成功副作用与观察点 | 下次请求读到新设置；旧 HTML 缓存失效 |
| 失败是否返回被检查 | 不适用 |
| 重试/重放来源 | 无 |
| 不能覆盖的对象 | 不适用 |
| status | `confirmed` |

---

## 5. 未知项与最小验证动作

| 未知项 | 影响 | 最小验证动作 |
|---|---|---|
| Page 删除后旧缓存实际驻留多久、是否有其他模型写入顺带触发整站 flush | M-1 的实际用户感知 | 本地 `curl` 一个已发布 Page → 记录 200 → 后台删 Page → 立即同 URL curl，观察是否仍返回旧 HTML |
| Wizard 批量 update 路径在重跑场景下是否真的造成索引/缓存分叉 | P1-1 的实际触发概率 | 跑一次向导到第 6 步前，先访问某产品详情页入缓存，再完成向导，观察产品页是否仍 draft |
| API 在多站域名下是否真的只写 default | M-3 | 多站部署时从非 default 域名带 default token 推一条内容，观察落到哪个 site_id |
| Octane/queue worker 下 static memo 跨站泄漏是否实际发生 | P2-1 | 当前部署为 `php artisan serve`（每请求新进程），不暴露；若未来切 Octane，需压测跨站请求 |
| content_entity 孤儿行实际累积量 | M-2 | `SELECT count(*) FROM content_entity LEFT JOIN contents ON content_entity.content_id = contents.id WHERE contents.id IS NULL` |

---

## 6. 未覆盖范围（诚实声明）

- 未逐行审查所有 Blade 视图；仅追踪到 controller→view 名的接线。
- 未审查 `resources/views/` 内是否有硬编码 slug 或绕过 Catalog 的第二套数据读取。
- 未审查 `config/entities.php`、`config/geo.php`、`config/icons.php` 的完整性。
- 未运行任何写操作或全量测试；所有结论来自静态源码阅读与 `route:list` 只读命令。
- 未审查 plugins/ 目录下第三方插件的行为。
- v1.1 延期项（Content Lifecycle trash/restore、Page Manager 深度、Media Library 产品化、Analytics PV/UV、Admin i18n、Template Preview、AI Content）不作为缺陷报告。
