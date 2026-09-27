# Pre-Release Hardening Discovery（v1.0 发布前加固 Discovery）

## 1. 元信息

| 项 | 值 |
|---|---|
| 日期 | 2026-09-27 |
| 权威仓 | `D:\GEO-OS-rewrite\geo-website-os` |
| 分支 | `main` |
| 基线 commit | `043a71c`（docs(v1.1): P0 reality check vs authoritative repo） |
| PHP | `C:\php84\php.exe`（只读 Discovery，未执行） |
| 范围 | 只读代码/迁移/种子/命令审计，不编码、不改业务、不新增 migration/表/模型 |
| 方法 | Grep / Read / Glob；所有结论附 `file:line` 证据 |

---

## 2. 执行摘要

| # | 议题 | 定性 | v1.0 必修？ | 落点 |
|---|---|---|---|---|
| 1 | Media 删除无引用检查 → 物理删文件 | **现存真实 bug**，封面/正文内联/Banner/OG/Entity 资产全部裸删 | **是（P0）** | 18U-1 Implementation |
| 2 | unpublish→draft（Admin）vs unpublish→archived（GeoflowSync） | 架构语义分叉，不能补 if | **否**：v1.0 标注 TODO 缓解；v1.1 统一 | 第 4 节 |
| 3 | 定时发布到点无事件，PageCache 脏最长 6h | 已确认可复现，无调度器 | **否**：v1.0 接受风险+文档化；v1.1 lazy settlement | 第 5 节 |
| 4 | TD-168 backup/upgrade/rollback 零 E2E 证据 | 命令存在但 sqlite-only、零测试 | **是（P1）**：最小证据 E2E | 第 6 节 |
| 5 | Analytics PV/UV = 0 | 仅登记 Missing | **否**：归 18V Analytics P1 | 第 7 节 |

**结论**：议题 1（Media 删除守卫）与议题 4（TD-168 最小 Release Evidence）满足进入 18U-1 Implementation 的条件；议题 2、3、5 登记进 v1.1 backlog，不阻塞 v1.0 发布，但议题 3 必须在发布说明中如实披露。

---

## 3. Media 删除引用 bug

### 3.1 现状确认

`MediaController::destroy` 直接 `Storage::delete` + `$media->delete()`，无任何引用检查：

```php
// app/Http/Controllers/Admin/MediaController.php:118-124
public function destroy(Media $media): RedirectResponse
{
    Storage::disk($media->disk)->delete($media->path);
    $media->delete();
    return back()->with('success', '媒体已删除');
}
```

后果：被引用的文件被物理删除后，前台/OG/JSON-LD/下载链接全部破图/死链，但 Media 行也已删，无法事后定位谁在引用。

### 3.2 完整 Media 引用盘点表

> 引用类型分四类：**FK**（DB 整数列，可 `WHERE col = ?` 反查）、**JSON-int**（JSON 字段内嵌整数 media_id，可 `WHERE json_extract(col,'$.x') = ?`）、**path-string**（Markdown/VARCHAR 存 `/storage/...` 路径或 URL，只能 LIKE 反查）、**orphan-file**（文件落盘但不入 media 表，与 MediaController 无关）。

| # | 模型.列 | 引用类型 | 写入点（证据） | 读取点（证据） | 反查方式 |
|---|---|---|---|---|---|
| 1 | `contents.cover_id` | FK（unsignedBigInteger，**无 DB FK 约束**） | `ContentController:420` 写 cover_id；迁移 `2026_09_14_000003:34` | `Content.php:81-84` belongsTo Media；`SeoMetaResolver:206`；`BlockRegistry:258` | `WHERE cover_id = ?` |
| 2 | `contents.og_image_id` | FK（unsignedBigInteger，**无 DB FK 约束**） | 表单写 og_image_id；迁移 `:58` | `Content.php:86-89`；`SeoMetaResolver:205`；`GeoGraphBuilder:261` | `WHERE og_image_id = ?` |
| 3 | `contents.body`（Markdown 正文） | path-string（内联 `![]( /storage/content/YYYYMM/xxx.jpg )`） | `MediaController::uploadInline:97-104` 返回 `$media->url()` 插入 Markdown；迁移 `:35` longText | `Content::bodyHtml():221-232` 渲染前台 | `WHERE body LIKE '%/storage/{path}%'` |
| 4 | `banners.image_id` | FK（unsignedBigInteger，**无 DB FK 约束**） | Banner 后台表单；迁移 `2026_09_14_000005:43` | `Banner.php:29-32`；`Banner::imageUrl():51-54` | `WHERE image_id = ?` |
| 5 | `seo_metas.og_image_path` | path-string（VARCHAR，**非 FK**） | `SeoMetaController:494-503`：选媒体库时写 `/storage/{media->path}` | `SeoMetaResolver:204` 读取 | `WHERE og_image_path = '/storage/{path}'`（**无法从路径反查 media_id，因写入时 id 已丢弃**） |
| 6 | `entities.metadata.media_id` | JSON-int（仅 download_asset 类型） | `EntityController:503-504` 写 `metadata['media_id'] = $mid` | `EntityRenderContext:245-246` `Media::find($mediaId)->url()` | `WHERE json_extract(metadata,'$.media_id') = ?` |
| 7 | `entities.metadata.og_image` | JSON-int | `EntityController:444-445` 写 `metadata['og_image'] = $ogId` | `SeoMetaResolver:265-266` `(int)$metadata['og_image']`；`GeoGraphBuilder:118`；`GeoHealthService:71` | `WHERE json_extract(metadata,'$.og_image') = ?` |
| 8 | `entities.metadata.image` | path-string（存 `Media::url()` 完整 URL） | `EntityController:434-437` 写 `metadata['image'] = Media::find($cardId)->url()` | 产品卡片渲染（`_product_card`） | `WHERE json_extract(metadata,'$.image') LIKE '%/{path}%'` |
| 9 | `settings.value`（key=`contact_wechat_qr`/`seo_og_image`/`geo_org_logo`） | **orphan-file**：`ImageOptimizer::store` 只返回路径字符串，**不创建 Media 行** | `SettingController:85-86`；`DefaultSettingSeeder:76,82,89` | 前台页脚二维码/OG/logo JSON-LD | 不在 media 表，MediaController::destroy 触不到；但替换时旧文件孤儿 |
| 10 | `content_revisions.snapshot`（JSON，含 body/geo_evidence） | path-string 历史快照 | `ContentController:432-438` 快照含 `body`、`geo_evidence` 等 | 版本回滚查看 | `WHERE json_extract(snapshot,'$.body') LIKE '%/storage/{path}%'`（历史引用，回滚后可能复活） |
| 11 | `page_blocks.content`（JSON） | **当前运行时无 media_id**：block kinds（hero/scenes/capabilities/…）的 `icon` 字段是图标库 key（`config/home_blocks.php:30,34,50,58,66`），非 Media；hero/slides 装修器已退役（`BlockController:11-17`）；mid_banner 读 Banner 记录 | 历史 seed 可能残留，但 v0.9 后无写入路径 | — | 扫描兜底：`WHERE content LIKE '%"media_id"%'`，命中即告警 |
| 12 | `categories.icon` | **非 Media 引用**：string(40) 存图标库 key（`2026_09_14_000010:8-9`） | — | — | 不在范围 |
| 13 | `menus` | **无 icon/image 列**（`2026_09_14_000005:55-70`；`Menu.php` 全字段） | — | — | 不在范围 |
| 14 | `groups` / `redirects` | **无媒体列**（`Group.php`、`Redirect.php`） | — | — | 不在范围 |

### 3.3 关键发现（与基线假设的出入）

1. **比 cover/banner 多 6 个引用点**：基线只点名 cover_id/og_image_id/image_id，但 Discovery 额外发现：
   - `entities.metadata.media_id`（下载资产，`EntityController:504`）
   - `entities.metadata.og_image`（产品/组织 OG，`EntityController:445`）
   - `entities.metadata.image`（产品卡片图，`EntityController:436`）
   - `seo_metas.og_image_path`（**path 反向不可查**，`SeoMetaController:497` 写时丢 id）
   - `contents.body` Markdown 内联图（`MediaController:99`）
   - `settings` 三个 image key（**orphan-file，不入 media 表**）
2. **所有 FK 列均无 DB 外键约束**：`cover_id`/`og_image_id`/`image_id` 都是裸 `unsignedBigInteger`（迁移 `:34,:58`、`2026_09_14_000005:43`），DB 层不会拦，必须在应用层守卫。
3. **SeoMeta.og_image_path 是"单向丢失 id"的最坏情况**：选媒体库时写入 `/storage/xxx.jpg`，删除 Media 后无法从该路径反查是哪行 SeoMeta 引用了它——只能全表 LIKE 扫。
4. **Setting 图片是另一个问题域**：它们压根不在 media 表里，MediaController::destroy 删不到，也不需要守卫；但换图时旧文件孤儿（v1.1 清理任务，不在本议题）。

### 3.4 Media Usage Graph 设计（反查现有字段，不新建第二张表）

Discovery 确认现有字段**足以反查**，无需新建 `media_references` 表。扫描器按下列 6 路聚合，返回 `[['model'=>Content, 'id'=>x, 'field'=>'cover_id', 'label'=>'文章《...》封面'], ...]`：

```
MediaReferenceScanner::for(Media $m): array
├── 1. Content::where('cover_id', $m->id)->get()          // FK 直查
├── 2. Content::where('og_image_id', $m->id)->get()      // FK 直查
├── 3. Banner::where('image_id', $m->id)->get()           // FK 直查
├── 4. Entity::whereJsonContains('metadata->media_id', $m->id)->orWhereJsonContains('metadata->og_image', $m->id)->get()  // JSON-int
├── 5. Content::where('body', 'like', "%/storage/{$m->path}%")->get()  // path-string（内联图）
└── 6. SeoMeta::where('og_image_path', '/storage/'.ltrim($m->path,'/'))->get()  // path-string 精确
       + Entity::where('metadata->image', 'like', "%/{$m->path}%")  // 兜底
       + ContentRevision::where('snapshot->body', 'like', "%/{$m->path}%")  // 历史快照告警（不阻断）
```

> SQLite 支持 `json_extract`，PostgreSQL 用 `->>'media_id'`；`whereJsonContains` 在两库均可用。

### 3.5 删除守卫拦截策略

- **v1.0 最小实现**（在 `MediaController::destroy` 入口）：
  1. 调 `MediaReferenceScanner::for($media)`；
  2. 结果非空 → **拒绝物理删除**，back 带错误「该媒体被 N 处引用：…（列出前 5 条人类可读标签）」，审计日志记 `media.delete_blocked`；
  3. 结果为空 → 维持现有 `Storage::delete` + `$media->delete()`；
  4. 历史快照（第 6 路 ContentRevision）**只告警不阻断**（回滚时可能复活的引用，提示运营人工确认）。
- **禁止**：`destroy → unlink` 直接物理删；禁止物理删后才发现引用。
- **不做**：软删除 Media、引用计数表——v1.0 范围最小，只做"有引用则拒删"。

---

## 4. unpublish 语义分叉（架构契约问题）

### 4.1 现状

| 入口 | 动作 | 证据 |
|---|---|---|
| Admin 手动下架 | `status = 'draft'` | `app/Http/Controllers/Admin/ContentController.php:199-206` |
| GeoflowSync 上游下架 | `status = 'archived'` | `app/Services/Sync/GeoflowSync.php:163-176`（`:169` `update(['status' => 'archived', ...])`） |

两处都是"下架"语义，但落到不同状态机：draft（可再编辑再发布）vs archived（归档，前台 scopePublished 不显示，但语义上是"上游已废弃"）。

### 4.2 定性

这是 **Publication Lifecycle Contract 未统一**的架构分叉，不能补 if：
- `Content::scopePublished`（`Content.php:119-125`）只认 `status='published'`，所以 draft 和 archived **前台都不可见**——功能上等价；
- 但后台列表、审计、未来的"归档恢复"流程会分叉：Admin 下架的文章进了 draft 草稿箱，Geoflow 下架的进了 archived，运营无法用一个视图看"所有下架内容"。

### 4.3 v1.0 临时缓解（维持现状，不改 GeoflowSync 也不改 Admin unpublish）

- **两个入口的目标态本来就不同，v1.0 维持现状**：
  - Admin Web 手动下线 → `published → draft`（内容仍由本站拥有，可再编辑、再发布；保留 `published_at` 作审计）；
  - GeoflowSync 外部同步下线 → `published → archived`（上游权威下架，本站不得擅自一键复活）；
- 在 `ContentController::unpublish` 与 `GeoflowSync::unpublish` 加注释，AuditLog 区分 `content.unpublished`（Admin→draft）vs `content.archived_via_sync`（Geoflow→archived）；
- 文档化两状态语义差异，v1.0 接受后台列表需按 status 筛选。

### 4.4 v1.1 统一方向：Actor 决定目标态

Publication Lifecycle Contract 中定义三态，**目标态由触发动作的 Actor 决定，而非统一收敛到同一个状态**：

| Actor | 动作 | 目标态 | 再发布路径 |
|---|---|---|---|
| Admin Web（本站运营） | 手动下线 | `published → draft` | 可直接再编辑→重走 ContentGate→publish；`published_at` 保留作审计 |
| GeoflowSync（上游权威） | 外部同步下架 | `published → archived` | **禁止 Admin 一键再 publish**；必须先显式「取消归档」→draft→重新走 ContentGate→publish |

状态语义：
- `draft` = 本站拥有、暂存/改稿中（可再发布）
- `archived` = 上游权威下架（本地不可擅自复活，需显式 unarchive 动作）
- `published` = 已发布

**v1.0 现状即正确方向**，无需把 Admin unpublish 改成 archived。v1.1 要补的是：
1. archived 状态的「取消归档」显式动作（带 ContentGate 重跑），堵住 Admin 直接 publish archived 内容的旁路；
2. 后台列表提供「全部下线内容」聚合视图（draft + archived 合并展示，按来源 Actor 分列）。

---

## 5. Cache stale（定时发布到点缓存脏 6h）

### 5.1 问题复现路径

1. 运营在后台发布文章，勾选未来时间 `published_at = T+1h`：
   - `ContentController::publish:181-186` 立即写 `status='published'`、`published_at=T+1h`；
   - 模型 saved 事件触发 `PageCache::flush()`（`AppServiceProvider:100`），整站缓存版本+1；
   - 但此时 `scopePublished`（`Content.php:119-125`）因 `published_at > now()` **排除该文**，缓存重建后的首页/列表**不含此文**。
2. 到 T+1h：DB 行**不发生任何写操作**（status 早已是 published，只是查询过滤条件翻转）；
3. `routes/console.php` **无任何 schedule() 调用**（已读全文，仅 inspire 命令），没有 cron 到点触发 flush；
4. 结果：首页/知识列表/相关推荐等缓存页继续展示"不含该文"的旧 HTML，直到 `PageCache::TTL=21600`（6h，`PageCache.php:26`）自然过期。

### 5.2 风险定性

- **影响面**：定时发布的文章到点后，最长 6h 不出现在任何列表页（但直接访问 URL 会 MISS 缓存后正常渲染——因为文章详情页从未被缓存过，或缓存键不含 published_at 过滤）；
- **SEO/GEO 影响**：新文章 6h 内不进 sitemap 列表页、不进 llms.txt 聚合（这些也走 PageCache），但 sitemap/llms 本身是动态路由还是缓存？需在 v1.1 确认；
- **v1.0 可接受**：定时发布是低频运营动作，6h 延迟对 GEO 内容站可接受；且 TTL 是自愈兜底，不会永久脏。

### 5.3 v1.1 策略方向

- **lazy settlement**：前台请求时检查"是否有 published_at <= now() 但尚未被纳入缓存的内容"，若有则触发局部 flush（forgetPath 首页/列表页）；
- **主动失效**：加一个 `geo:publish-due` 调度命令（每分钟），扫描 `status='published' AND published_at <= now() AND cache_settled=0` 的行，flush 相关 path；
- v1.0 不做，仅在发布说明披露"定时发布最长 6h 延迟上首页列表"。

---

## 6. TD-168 最小 Release Evidence

### 6.1 现状盘点

| 命令 | 证据 | 能力 | 缺口 |
|---|---|---|---|
| `geo:backup` | `app/Console/Commands/GeoBackup.php:23-67` | sqlite 整库 copy + manifest JSON（含 version/migrations_last/counts/sha256） | **仅 sqlite**（`:25-30` 非 sqlite 直接 FAIL）；零测试 |
| `geo:upgrade` | `GeoUpgrade.php:30-91` | backfill-seo → migrate --force → 补 settings/form → search:reindex → view:clear + PageCache::flush | 无 health check 断言；零 E2E |
| `geo:rollback` | `GeoRollback.php:31-81` | 校验 manifest sha256 → 回滚前再备份 → copy 还原 | 仅 sqlite；零测试；不验证还原后可启动 |

测试现状：`tests/Feature/UpgradeBackfillTest.php` 仅测 backfill 数据迁移，**无 backup→upgrade→rollback 全链路 E2E**。

### 6.2 最小 Release Evidence 方案（不建完整更新平台）

两条 E2E 测试（Pest/PHPUnit，sqlite 内存库）：

**E2E-A：成功升级路径**
```
1. 起一个 v0.9 状态库（跑旧 migrations + 旧 seed，记 baseline counts）
2. geo:backup → 断言 backup 文件存在 + manifest sha256 匹配
3. 跑新 migrations（migrate --force）→ geo:upgrade
4. health check：GET / 200、GET /knowledge 200、GET /sitemap.xml 200
5. 数据完整性：baseline counts 不丢（contents/seo_metas/users 数 >= baseline）
6. 断言新版本号 config('geo.version') 正确
```

**E2E-B：失败升级 → rollback 路径**
```
1. 同 baseline 库
2. geo:backup → 记 backup path
3. 人为制造迁移失败（在 migrate 中途抛异常 / 注入一个坏 migration）
4. geo:rollback --backup=<path> --force
5. 断言：DB 文件 sha256 == backup sha256（还原到位）
6. health check：GET / 200（旧版本可用）
7. 断言：migrations_last 回退到 baseline
```

**验收标准**：
- 两条 E2E 在 CI 绿；
- backup/rollback 对 mysql/pgsql 明确 FAIL 并输出提示（现状已是，保持）；
- 不要求支持 mysql/pgsql 自动 dump（v1.0 接受 sqlite-only，文档化）。

---

## 7. Analytics PV/UV 登记

- PV/UV 计数 = 0：`grep page_view / trackVisit / pv / uv` 全仓 0 命中（基线已确认）；
- `CaptureAttribution` 中间件 + 8 字段入库已完整（attribution 已从 P0 销项）；
- 本阶段**不补半成品统计系统**，仅登记 Missing，归 **18V Analytics P1**。

---

## 8. v1.0 vs v1.1 分类汇总

| 议题 | v1.0 动作 | v1.1 方向 |
|---|---|---|
| Media 删除守卫 | **必修**：实现 MediaReferenceScanner + destroy 拦截（第 3.4-3.5 节） | orphan-file 清理（Setting 旧图）；软删/引用计数 |
| unpublish 分叉 | 不改代码，加注释+AuditLog 区分 | Actor 决定目标态：Admin→draft（可再发布）/ GeoflowSync→archived（需显式 unarchive+重走 Gate）；补归档恢复动作与聚合视图 |
| Cache stale | 接受 6h 风险，发布说明披露 | lazy settlement + `geo:publish-due` 调度 |
| TD-168 | **必修**：两条 E2E（成功/失败 rollback） | mysql/pgsql dump 支持；health check 自动化 |
| Analytics | 登记 Missing | 18V Analytics P1 自建 PV/UV |

---

## 9. Discovery Gate 结论

| Gate | 结论 |
|---|---|
| Media 引用是否可反查？ | **是**：6 路扫描器覆盖全部 FK/JSON-int/path-string，无需新建引用表 |
| Media 删除守卫是否可在 v1.0 最小实现？ | **是**：仅改 `MediaController::destroy` + 新增一个 Scanner 类，不动 schema |
| TD-168 最小证据是否可做？ | **是**：sqlite 内存库两条 E2E，不依赖外部 dump 工具 |
| unpublish/Cache/Analytics 是否阻塞 v1.0？ | **否**：均为语义/体验问题，不产生数据损坏或安全漏洞，文档化即可 |

**结论**：满足进入 **18U-1 Implementation** 的条件。实施范围 = Media 删除引用守卫 + TD-168 两条 E2E；其余登记 v1.1 backlog。
