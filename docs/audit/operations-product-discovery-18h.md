# P-STEP 18H Discovery — Search / Media / Forms / Operations 现状盘点

- **阶段**：P-STEP 18H **Discovery**（不含实现）
- **基线 HEAD**：`bf3d576`（= annotated tag `checkpoint-18G-2b`）；Worktree **clean**
- **Regression 基线**：**950 passed / 4873 assertions / 0 failed / 0 skipped**
- **冻结约束**：`v1.0.0-rc1=965d63c` HOLD；未配 remote、未 push、未 Release
- **Discovery 方法**：只 Read / Grep / 轻量实测，不改产品代码；结论附文件路径与行号证据
- **配套文档**：`operations-product-architecture-18h.md`（目标架构与实施任务）

---

## 0. 用户已锁定的两项大方向（Discovery / 架构基线）

1. **Search：V1 = 本地 FTS5 / 索引化 + Engine 接口预留**
   `Search Contract → SearchEngine Interface → SQLite FTS5 Engine（V1 默认）`。
   V1 不引入 Meilisearch / Elasticsearch（避免新增部署依赖、安装复杂度、升级/备份/恢复面）；
   但接口现在必须留正确，未来可换 `DatabaseSearchEngine / MeilisearchEngine / ElasticSearchEngine`，
   上层 `SearchQuery / SearchResult / SearchFilter / Ranking / Locale / SiteScope` 保持不变。
2. **Form Builder：V1 = 结构化字段配置，不做自由排版 / 多步表单**
   `Form → Field(type/name/label/placeholder/required/validation/options/sort_order) + success + consent + spam + notification`。
   V1 字段类型 `text/textarea/email/tel/number/select/radio/checkbox/date/url/hidden`；
   不做自由拖拽 / 复杂 Grid / 多步 Wizard / 条件分支 / 计算字段，但不堵死未来能力。
   **Form 只负责数据结构 + 验证 + 提交行为，不负责视觉布局**；视觉由 Theme + Component + FormReference Block 承担。

---

## 1. 总表：现有能力 → 缺口 → 正确归属 → 是否 V1 Required

| 模块 | 现有能力（证据） | 主要缺口 | 正确归属层 | V1 Required? | 新 TD |
| --- | --- | --- | --- | --- | --- |
| **Search** | Content+Entity 统一搜索（SearchController）、经 PublicIndex 满足 Render Contract、forLocale、分页、noindex | 无 Engine 接口、无 FTS5、LIKE 匹配、内存分页、无 ranking/highlight | Search Contract → Engine Interface → Engine | **YES**（接口 + FTS5） | TD-71, TD-72 |
| **Media（库）** | Media 模型 + Admin MediaController（上传/内联/alt/删除）、ImageOptimizer（限宽/压缩/WebP/EXIF）、site scope、AuditLog | 能力基本完整；仅 logo 双轨、无 srcset（次要） | Media 库 + media ID 字段 | 库本身 NO（已成立）；次要项 v1.1 | TD-76 |
| **Forms / Inquiry** | 固定字段表单（_lead_form）、蜜罐 + throttle、success/privacy 走 Copy、后台留言跟进 | 字段固定、验证写死、无 Form/FormField、Inquiry 固定列、无 notification、consent 仅文案 | Form/FormField（数据结构）+ Theme（视觉） | **YES**（TD-59 完整 Form Builder） | TD-59（细化） |
| **Analytics** | **无任何分析集成**（resources/config 0 命中） | 无 GA/GTM/Pixel/custom、无注入点、无事件、CSP 未放行 | Site Configuration + 注入点 + CSP | **YES（架构/injection point 成立，UI 可后置）** | TD-73 |
| **Audit** | AuditLog 覆盖 9 控制器（Content/Category/Inquiry/Fact/Group/Menu/Media/Setting/Site） | 缺 Entity/EntityRelation/SeoMeta/Page/Block/Theme/Plugin | AuditLog（Core Audit Trail） | **YES（至少补 Entity/SeoMeta/Page/Block）** | TD-74 |
| **Revision** | ContentRevision（Content 修改前快照 + GeoflowSync 留痕） | 仅 Content；Entity/Page 无版本 | Revision | NO（Content 已满足核心，其余 v1.1） | TD-75 |
| **Cache** | 版本化 flush + 页面级 forgetPage/forgetPath + Template flush；匿名外壳 + personalize | Entity/Content 等仍整站 flush（Page 已精确） | PageCache | NO（TD-08 已裁定，正确性优先） | —（观察） |
| **Performance** | Catalog 一次构建缓存；PageCache HIT 跳过渲染/查库 | 搜索内存分页（大数据量风险，FTS5 解决） | — | 基线可接受 | 并入 TD-72 |
| **SEO/GEO 联动** | 四种 RenderContext 全走唯一 SeoMetaResolver | 无第二套（已统一） | SeoMetaResolver / GEO Service | NO（TD-61 闭环保持） | — |

---

## 2. Search 现状详查

### 2.1 链路与数据源
- 控制器：`app/Http/Controllers/Site/SearchController.php`；路由 `GET /search`（`routes/web.php:45`）。
- **18F 已把搜索从“仅 Content”扩成 Content + Entity 统一**：
  - Content：`PublicIndex::contentQuery()->forLocale($locale)`，关键词 LIKE `title/summary/body`，`orderByDesc('published_at')`。
  - Entity：`PublicIndex::entityQuery()->forLocale($locale)`，关键词 LIKE `name/summary/description`，`orderBy('sort_order')`；
    **仅纳入 `PublicUrl::entity($e)` 非 null（有公开落地页）的实体**，避免结果链接 404。
- 两类结果统一映射 `app/Support/SearchResult.php`（值对象：title/summary/url/kind）。

### 2.2 Public Render Contract 过滤（查询层，已成立）
`app/Support/PublicIndex.php`：
- `contentQuery()`：`Content::published()` + 栏目启用（或无栏目独立单页）+ 排除 `SeoMeta.content_id + noindex` + Site Scope。
- `entityQuery()`：`Entity::published()` + 排除 `SeoMeta.entity_id + noindex` + Site Scope。
- `indexableEntitySlugs()`：按当前 locale pluck slug（TD-65 已修）。
- → 搜索不会引导用户到达 Draft / noindex / 他站 / 他语言页面。隔离在查询层成立。

### 2.3 现有参数
- `PER_PAGE=10`；最短查询 **2 字符**；搜索页 **noindex=true**；SEO title 随 q；crumbs。
- **分页：`LengthAwarePaginator` 内存分页**——先 `->get()` 取全量，再 `slice()`（SearchController:79-86）。

### 2.4 缺口（对应 TD-71 / TD-72）
1. **无 Search Engine Interface 抽象**：搜索逻辑（查询、匹配、分页、排序）全在控制器；无 `SearchEngineInterface`、无统一 `SearchQuery / SearchFilter` 对象（TD-71）。
2. **无 FTS5**：关键词为 `LIKE %q%` 子串匹配，无分词 / 相关性 ranking / type ranking。
3. **内存分页**：两类 union 难 DB 分页，先 get 全量再 slice；数据量大有性能 / 内存风险。
4. **无关键词高亮 highlight**。

### 2.5 FTS5 能力实测（已确认可用）
- 探测脚本 `D:\Temp\18h\fts-check.php`（PDO `sqlite::memory:`，`CREATE VIRTUAL TABLE t USING fts5(x)` + INSERT + `MATCH 'hello'`）。
- 结果：**FTS5 OK, match rows=1；sqlite_version=3.53.4**。
- → V1 默认 FTS5 Engine 在当前 PHP 8.4 / SQLite 环境下无需额外扩展即可用。

---

## 3. Media 现状详查

### 3.1 媒体库能力（完整）
- 模型 `app/Models/Media.php`（BelongsToSite；字段 disk/path/original_name/mime/size/width/height/alt/uploaded_by）：
  `url()` = path 以 http 开头则原样，否则 `PublicUrl::base().'/storage/'.path`（TD-09 host 裁决）；`isImage()`。
- Admin `app/Http/Controllers/Admin/MediaController.php`：
  index（latest / image·other 过滤 / paginate24）、store（**禁止 SVG 防存储型 XSS**；mimes jpg/jpeg/png/webp/gif/pdf/doc/docx/xls/xls/xlsx/mp4，max10240；ImageOptimizer 落 `media/YYYYMM`；getimagesize 记尺寸；AuditLog）、
  uploadInline（正文编辑器异步，`content/YYYYMM`，max8192，JSON 返回 url/alt/id/width/height）、update（alt/title）、destroy（Storage 删文件 + 记录）。
- `app/Support/ImageOptimizer.php`（全站唯一图片处理）：
  常量 MAXW_BANNER2048 / MAXW_CONTENT1600 / MAXW_LOGO1200，JPEG_Q82 / WEBP_Q80；
  store()、optimize()（限宽等比 + 压缩、PNG 保 Alpha、JPEG EXIF 转正、GD 不可用静默回退）、
  ensureWebp()（JPEG/PNG 同尺寸 .webp 兄弟，幂等）、webpUrl()、needsRecompress()。

### 3.2 前台图片数据源（grep `<img` resources/views/site）
| 位置 | 数据源 | 是否 Media 引用 |
| --- | --- | --- |
| blocks `product_grid / service_grid / content_grid` | `$card['image']`（block/数据源准备的卡片数组） | 间接：Entity/Content metadata（见 3.3） |
| blocks `logo_cloud` | `$lg['media']->url()` | **是（Media 模型，media ID）** |
| blocks `contact_info:67` | `asset($ciQr)`（二维码，setting 提供路径） | 否（setting 路径） |
| home `mid_banner:27` | `$mbSrc`（block 数据） | 旧首页链（TD-70） |
| layouts/site `logo:1415/1533` | `asset($siteSettings['geo_org_logo'] ?: 'img/logo.png')` | **否（setting 路径 + 系统默认 fallback）** |
| favicon（layouts/site:77-79） | `asset('favicon.ico'/'favicon.png'/'apple-touch-icon.png')` | 系统静态资源（合理） |
| admin login / layout | `asset('img/logo.png')` | 系统默认（合理） |

### 3.3 产品卡片图链路（已闭环，非缺口）
- 后台 Entity 表单 `resources/views/admin/entities/form.blade.php:195-225`：
  `card_image`（media ID select）→ EntityController:362-364 写 `metadata.image = Media url`；`og_image` → `metadata.og_image`。
- Catalog `app/Support/Catalog.php:150-161`：产品数组 `array_merge($e->metadata, [slug/name/summary...])`，
  `normalizeProduct()`（Catalog:409-420）的 `image` 默认 null、metadata.image 存在则填充。
- product_grid blade：image 非空显示图、为空渲染品牌色图标占位（不裂图，安全降级）。
- Demo 未给产品图故 image=null（图标占位），但**能力链路完整**。

### 3.4 block 字段类型（config/blocks.php，已含 media）
- 字段类型支持 `text/textarea/markdown/number/select/checkbox/media（媒体 ID，配媒体库）/items/buttons/source`。
- 新 Composition block 已用 media ID：hero.image_id、image.media_id、media_text.media_id、logo_cloud items.media_id。
- 数据源 grid：product_grid（product，modes all/line/picked/current/related）、service_grid（all/picked/related）、content_grid（latest/picked/current + category_id）。
- `form_reference` 仅 title/subtitle（**无字段配置，印证完整 Form Builder 缺失**）。

### 3.5 结论与次要缺口
- **前台无业务图用 /assets 写死**（仅系统 logo.png / favicon 技术默认 fallback，属白名单允许）。
- 媒体库与新 block / Entity 配图已通过 media ID 打通；空图安全降级。
- 次要（→ TD-76，v1.1）：① header/footer logo 走 setting.geo_org_logo 路径字符串、不经 media ID（双轨）；
  ② 无 responsive `srcset`（现有 picture + WebP 兄弟渐进增强，但无多尺寸 srcset）；③ og_image 已支持 media 选择（无缺口）。

---

## 4. Forms / Inquiry 现状详查（TD-59 核心）

### 4.1 前台表单（固定）
- 表单 HTML：`resources/views/site/_lead_form.blade.php`，action `route('inquiry.store')`。
- **4 个可见字段**：称呼 `name`（必填）、联系电话 `phone`（必填，tel）、客户类型 `demand_type`（select，必填）、需求简述 `message`（textarea，选填）。
- 蜜罐字段 `website`（真人不可见）；来源归因隐藏字段（landing_url/referer/utm_*，由 CaptureAttribution 写入）。
- 前端极简原生校验（onBlur，novalidate 后端兜底）；privacy 文案（`Copy::form()['privacy']`，**纯展示、无勾选**）。
- 字段 label / placeholder / error / success / submit 全部来自 `Copy::form()`（可运营取数层）。

### 4.2 提交处理（验证写死）
`app/Http/Controllers/Site/InquiryController.php::store`：
- 蜜罐命中假装成功；`demand_type` 选项 = `Copy::form customerType.options` + `Inquiry::TYPES`。
- **验证规则写死控制器**：name required/max50；phone required + `regex:/^[0-9+\-\s()wx微信,，]{6,30}$/`；
  company nullable/max120；demand_type required/in(options)；monthly_use nullable；message nullable/max1000；归因字段长度限制。
- message 为空用 demand_type 兜底（防 Undefined key 500）；落库 + source_page / device_type（UA 解析）/ ip / user_agent / status=new。
- **无邮件 / 无后台通知 / 无事件**（grep `Notification|Mail::|->notify`：仅 User 模型 Notifiable trait）。

### 4.3 数据模型（固定列）
- `app/Models/Inquiry.php`（BelongsToSite，guarded=[]）：
  **`TYPES = ['代工合作','原料采购','经销代理','其他咨询']`（写死中文）**；STATUS_LABEL / DEVICE_LABEL 固定。
- Inquiry 表为固定列，**无 payload / meta JSON** 承载自定义字段。

### 4.4 后台留言管理（完整，但只针对固定字段）
`app/Http/Controllers/Admin/InquiryController.php`：index（status 过滤 / paginate20 / counts）、handle（status + handle_note + handled_at + AuditLog）、destroy。

### 4.5 缺口（并入 TD-59 验收标准细化）
1. 无 `Form / FormField` 模型，表单结构未数据化；不能后台增删字段 / 改类型 / 排序。
2. 验证规则写死控制器 + blade JS（需由 Field 配置驱动前后端校验）。
3. Inquiry 固定列、无 payload JSON（自定义字段需结构化落库）。
4. `Inquiry::TYPES` 写死中文（demand_type 应由 Form select options 提供）。
5. consent 仅 privacy 文案、无勾选机制。
6. **无提交通知（邮件 / 后台通知）**。
- 已有可复用：success 走 Copy、蜜罐 + throttle  spam、归因、后台跟进。

---

## 5. Analytics 现状（完全缺失 → TD-73）

- grep `analytics|gtag|GTM-|googletagmanager|dataLayer|pixel|G-[A-Z0-9]{6,}` 于 `resources/`：**0 命中**。
- grep `analytics|ga_id|gtm|tracking|gtag|pixel` 于 `config/`：**0 命中**。
- → **当前无任何分析 / 转化集成**：无 GA / GTM / Meta Pixel / custom script，无 settings key，无 head/body injection，无事件。
- CSP（`app/Http/Middleware/SecurityHeaders.php`）：前台 `script-src 'nonce-…'`、`default-src 'self'`——
  第三方分析域名 / 内联 GA 脚本默认会被 CSP 拦截，集成时须动态放行 + nonce。
- **缺口**：可配置 integration point（第三方 ID 存 Setting、不写 Blade）、脚本安全注入（head/body）、
  事件（page_view / CTA click / form_submit / download / contact）、CSP 动态放行、consent。分析 UI 本身可后置。

---

## 6. Audit / Revision 现状

### 6.1 AuditLog 覆盖（9 / 主要模块）
- 模型 `app/Models/AuditLog.php`（BelongsToSite；detail cast array；`record(action,summary,detail,targetType,targetId)` 自动记 user/ip）。
- `AuditLog::record` 分布（27 处 / 9 控制器）：
  Content(6)、Menu(4)、Site(4)、Category(3)、Setting(3)、Inquiry(2)、Fact(2)、Media(2)、Group(1)。
- **未覆盖**：Entity、EntityRelation、SeoMeta、Page、PageBlock（Composition）、Theme、Plugin、Narrative、Redirect。
- → TD-74：用户要求关键修改（Settings / SeoMeta / Entity / Content / Menu / Block）留痕；V1 至少补 **Entity / SeoMeta / Page / Block**。

### 6.2 ContentRevision（仅 Content → TD-75）
- 模型 `app/Models/ContentRevision.php`（belongsTo Content，snapshot cast array）。
- 使用位置仅：Admin ContentController:385（人工修改前快照）、Services/Sync/GeoflowSync:130（推送覆盖留痕）。
- → **Revision 只覆盖 Content**；Entity / Page 无版本快照。Content revision 已满足 V1 核心，Entity/Page revision DEFERRED v1.1（TD-75）。

---

## 7. Cache Dependency Matrix（现状）

| 资源变更 | 失效动作（证据） | 粒度 |
| --- | --- | --- |
| **Page / Block / 排序 / 显隐 / duplicate / preview** | Admin PageController 7 处 `PageCache::forgetPage($page)`（:123/139/223/232/256/266/293） | **页面级精确** |
| Content / ContentRevision / Category / Group / Banner / Menu / PageBlock / Setting / Media / RedirectRule / Fact / **SeoMeta** | AppServiceProvider:84-93 模型 `saved/deleted → PageCache::flush()` | 整站 |
| Entity | Entity 模型 :30/36 `saved/deleted → flush`；EntityController:447 `Catalog::flush` | 整站 |
| EntityRelation | 模型 :71/74 `saved/deleted → flush`；EntityRelationController:100/201 `Catalog::flush` | 整站 |
| Site | 模型 :64/80 `saved/deleted → flush` | 整站 |
| Theme | ThemeManager:131 `flush` | 整站 |
| Plugin | PluginController:75/94 `flush` | 整站 |
| Narrative | NarrativeController:70/124 `flush` | 整站 |
| Template | `PageCache::forgetTemplate()` = flush（结构变更罕见，可接受） | 整站 |
| Inquiry / AuditLog / SyncLog / User | **刻意不纳入**（不影响前台展示） | — |

- PageCache（`app/Support/PageCache.php`）：强制 file store（`cache:clear` 清不到，须 `page-cache:clear`）；
  keyFor = version + pathVersion + sha1(getHttpHost + path)（含端口，TD-25）；缓存匿名外壳（CSRF/CSP nonce/归因占位，personalize 回填）；TTL 21600。
- **结论**：契约功能正确（不漏失效、不发旧页、不相关正确排除）；Page/Block 已精确，其余整站 flush。
  Entity/Content 等的“整站 flush → 页面级精确”在 18C TD-08 已裁定（小型站正确性优先、可接受），**18H 不重开、不扩大重构**。

---

## 8. Performance 基线（实测）

- 环境：fresh demo sqlite（`D:\Temp\18h\perf.sqlite`）+ `artisan serve` 127.0.0.1:8160；curl 冷（MISS）/ 热（HIT）。

| 页面 | MISS（渲染） | HIT（PageCache） |
| --- | --- | --- |
| 首页 zh `/` | 0.367 s / 200 | 0.176 s / 200 |
| 产品详情 `/products/epoxy-primer-100` | 0.226 s / 200 | 0.173 s / 200 |
| 场景详情 `/solutions/equipment-manufacturing/` | 0.231 s / 200 | 0.169 s / 200 |
| 搜索 `/search?q=环氧` | 0.203 s / 200 | 0.198 s / 200（搜索 noindex 不缓存） |
| 首页 en `/en` | 0.289 s / 200 | 0.167 s / 200 |

- Catalog 一次构建并静态缓存；PageCache HIT 跳过 Blade 渲染与查库（serve 单进程 HIT ~0.17s，生产 LAMP/LEMP 通常更快）。
- 无明显 N+1 灾难；主要性能隐患是**搜索内存分页 + get 全量**（大数据量风险），由 FTS5 + DB 分页（TD-72）解决。

---

## 9. SEO / GEO 联动（出口一致性已保持）

- 四种 RenderContext 的 `seo()` 全部经唯一 `app/Services/Seo/SeoMetaResolver`：
  PageRenderContext:57 `resolvePage`、EntityRenderContext:112 `resolveEntity`、
  ListingRenderContext:60 `resolveListing`、SystemPageRenderContext:143 `resolvePage`；
  CompositionRenderer:48 统一取 `$context->seo()`；RenderContext 接口声明 `seo()`。
- → 无第二套 SEO（TD-61 CLOSED 保持）；18H 新增 Search/Form/Analytics 时**必须复用 / 扩展现有 Resolver，禁止新建 Localized* 第二套**。
- GEO 输出（geo.json / llms.txt）经 18C/18F 收口，语言一致性已验证；18H 不重开。

---

## 10. 新登记 TD（TD-71 起，不覆盖旧编号）

| ID | Pri | Title | Blocks v1.0? | Parent |
| --- | --- | --- | --- | --- |
| **TD-71** | P1 | SearchEngine 契约 / 接口缺失（无 SearchEngineInterface、无统一 SearchQuery/SearchFilter，逻辑全在控制器） | **YES** | — |
| **TD-72** | P1 | FTS5 默认引擎缺失（LIKE + 内存分页，无 ranking / DB 分页 / highlight；FTS5 已实测可用） | **YES** | TD-71 |
| **TD-73** | P2 | Analytics / 转化集成完全缺失（无注入点 / 事件，CSP 未放行；须可配置 integration point） | **YES（架构成立，UI 后置）** | — |
| **TD-74** | P2 | AuditLog 覆盖缺口（缺 Entity / SeoMeta / Page / Block，Theme/Plugin 同） | **YES（Core Audit Trail）** | — |
| **TD-75** | P3 | Revision 仅 Content，Entity / Page 无版本快照 | NO（DEFERRED v1.1） | — |
| **TD-76** | P3 | 品牌 / 标识图片引用双轨（logo setting 路径 vs media ID）+ 无 responsive srcset | NO（v1.1） | — |
| TD-59 | P3→P1 | 完整 Form Builder（验收标准细化：Form/FormField、字段类型、验证、payload、consent、notification） | **YES（18H）** | #116 |

> v1.0 Required 未闭合（18H Discovery 后）= **8**：外部 P0×3（TD-01/02/03）+
> 18H 代码层 5（TD-59 Form Builder、TD-71 接口、TD-72 FTS5、TD-73 Analytics、TD-74 Audit）。
> TD-75 / TD-76 转 v1.1。

---

## 11. STOP（Discovery 完成，等待裁定）

- 本 Discovery **未改产品代码**（仅 `D:\Temp\18h\` 探测脚本 + 临时 perf 库；仓库 worktree 仍 clean、HEAD 仍 `bf3d576`）。
- 请用户裁定：① 目标架构（见 `operations-product-architecture-18h.md`）；
  ② FTS5 索引具体形态（统一 FTS 虚拟表 / content-row 同步策略）；③ Form / FormField 表结构；
  ④ 18H 的子阶段 Gate 拆分。裁定并授权后才进入实现，不自行开工。
