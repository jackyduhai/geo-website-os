# P-STEP 18F — Localization Design（zh-CN + English）

> 状态：AUTHORIZED / 实施蓝图。目标是把"加一个 English 按钮"升级为可维护、可索引、
> 可被 AI 理解的完整前端国际化：Locale → URL → Page → Content/Entity →
> Navigation/Block → SEO → Canonical → hreflang → Schema → GEO → Sitemap → Search。
>
> 延续原则：18E「前台能力必须有后台来源」、18D「所有视觉进 Design System」。
> 复用而非新造：扩展 SeoMetaResolver / PublicUrl / PublicIndex / Schema / GEO，
> **禁止** LocalizedSeoResolver / LocalizedPublicUrl / LocalizedSchemaBuilder 第二事实源。

## 1. DISCOVERY 结论（现状）

| 维度 | 现状 |
| --- | --- |
| Locale 基础设施 | **无**：无 `resources/lang`，`app` 内无 `setLocale/getLocale`，路由无语言前缀 |
| config/app.php | `locale=en`、`fallback=en`（框架默认；前台实际中文靠 Blade 写死） |
| 数据模型 | contents / entities / seo_metas / menus / page_blocks / banners **均无** locale / translation 字段 |
| 前台文案 | 中文业务文案写在 Blade；少量固定 UI 词直接写中文 |
| Feed | sitemap/geo/llms/rss 单一语言、单一 URL |

结论：从零搭建，但现有数据与 861 测试可作为默认语言（zh-CN）基线，**增量改造、不破坏现状**。

## 2. Locale Contract（冻结）

- 统一注册表 `config/localization.php` + `App\Support\Localization\LocaleRegistry`：
  - **supported**：`zh-CN`, `en`（BCP-47 规范码）
  - **default**：`zh-CN`
  - **fallback**：`zh-CN`（仅用于系统/UI 字符串，见 §8）
- 全站只允许 `zh-CN` / `en`；禁止 `zh` / `zh_CN` / `cn` / `en-US` 混用。
- `<html lang>`、`hreflang`、Schema `inLanguage` 一律使用上述规范码。

## 3. URL Strategy（冻结）

| Locale | 前缀 | 示例 |
| --- | --- | --- |
| zh-CN（默认） | 无 | `/products/epoxy-primer-100` |
| en | `/en` | `/en/products/epoxy-primer-100` |

- 稳定、无 query string（**禁止** `?lang=en` / `?locale=`）、HTTP 直达、可 canonical /
  sitemap / hreflang / 分享 / 抓取 / AI 识别。
- Feed：
  - `/sitemap.xml`（zh）+ `/en/sitemap.xml`（en）
  - `/geo.json` + `/en/geo.json`；`/llms.txt` + `/en/llms.txt`；`/feed.xml` + `/en/feed.xml`
  - `/robots.txt` **唯一**，同时引用两个 sitemap
  - `/search` + `/en/search`；`/` + `/en/`

### 路由双注册
- routes/web.php 内用 `registerFrontendRoutes()` 闭包承载全部前台路由：
  1. 无前缀调用（zh-CN）；
  2. `Route::prefix('en')->middleware('locale:en')->group(registerFrontendRoutes)`。
- `robots` 仅在 zh 注册；catch-all `PageController::dispatch` 双注册。

## 4. Locale Resolver / Middleware（单一）

- `App\Support\Localization\LocaleContext`：request-scoped（仿 SiteContext），
  `current() / set() / clear()`。
- `App\Http\Middleware\SetLocale`（别名 `locale`）：
  - 从路由 action（`:en` / 默认 zh-CN）取 requested locale；
  - 校验是否在**当前 Site supported locales**内：站点未启用 en → `/en/*` 全部 **404**；
  - `App::setLocale()` + `LocaleContext::set()`；
  - 请求结束 `LocaleContext::clear()`。
- 禁止 Controller / Blade 各自判断 `$request->get('lang')`。

## 5. Site Locale Configuration（Site Configuration，不写死）

- 新增 Site 级 Setting：
  - `site_supported_locales`（数组，默认 `['zh-CN']`）
  - `site_default_locale`（默认 `zh-CN`）
- 不假设所有站点双语；官方能力完整支持 zh-CN/en。
- 空站 `geo:install`：默认仅 zh-CN；**Demo 站**：zh-CN + en（演示完整双语）。

## 6. Content / Entity 翻译模型 —— 同表多行 + translation_group

采用**方案 C：同表多行 + `locale` + `translation_group`**（增量、现有 zh-CN 行与
861 测试行为不变；每语言独立 slug/URL/SEO）。

- contents / entities 新增列：
  - `locale` VARCHAR(16) DEFAULT 'zh-CN'
  - `translation_group` VARCHAR(36)（uuid，同一逻辑资源共享）
- 唯一约束：
  - entities：`(site_id,type,slug)` → `(site_id,type,slug,locale)`
  - contents：slug 唯一 → 按 `(site_id,slug,locale)`
- 新行回填：存量行 `locale='zh-CN'`、`translation_group=uuid`。

### 字段归属（冻结）
- **共享（group 级，zh-CN 权威行编辑，单向同步 en 行）**：
  type, status, published_at, category_id, group_id, cover_id, og_image_id,
  owner, reviewed_at, review_due, source_note, external_id/source/synced_at/hash,
  lock_manual, slot；Entity 的 `metadata`（结构/数值；scenes 已由 18A 从关系派生）。
- **翻译（每行独立）**：
  Content: title, slug, summary, body, geo_conclusion/explanation/evidence/boundary/faq/key_facts；
  Entity: name, slug, summary, description。
- `App\Support\Translatable` trait：生成 group、`translations()` 关系、
  `scopeForLocale`、`createTranslation()`；zh-CN 行保存共享字段时 `saveQuietly` 同步 en 行。
- Admin 共享字段仅在"中文"Tab 编辑；英文 Tab 只编辑翻译字段。

## 7. SeoMeta 本地化

- seo_metas 新增 `locale`；唯一索引加 locale（site / site+content / site+entity）。
- 每绑定每语言一行；title/description/keywords/canonical/og_*/robots 按语言独立。
- SeoMetaResolver 按 `LocaleContext::current()` 查对应行；
  无显式行时用**该语言内容字段**（en 行的 title/summary）派生，canonical 走 PublicUrl。

## 8. Missing Translation & Fallback Policy（冻结）

- 站点未启用 locale → 该前缀全部 **404**。
- 启用 locale 但资源无翻译：列表只列有翻译项；直接访问该资源 locale URL → **404**
  （Public Content **不**无条件回退另一语言，避免英文页 title 变中文）。
- **UI system string** 允许 fallback（翻译键缺失回退默认）。

## 9. Navigation / Block / Banner

- **固定栏目菜单**（parent_key 蓝图项）：label 走 `__('nav.xxx')`，不建翻译行。
- **自定义菜单**（手填 label）：menus 加 locale+group，en 为翻译行。
- PageBlock：配置（page/type/category/limit/sort/is_active）共享；手填
  title/subtitle/content 加 locale+group；数据驱动 block 内容来自已本地化对象。
- Banner：首页当前由 page_blocks 承载；若 banner 未被前台消费，其深度本地化登记
  DEFERRED（不阻塞 v1）。

## 10. Schema / GEO / Sitemap / Search 本地化

- SchemaBuilder：`inLanguage`=当前 locale；`url/@id/isPartOf/mainEntityOfPage/breadcrumb`
  使用当前 locale URL（PublicUrl 注入前缀）。
- GeoGraphBuilder：按 locale 过滤实体/内容（仅该 locale 行）；中文与英文不混。
- LlmsBuilder：按 locale；SitemapBuilder：zh URL 与 en URL 两套，内含 hreflang alternate。
- Search：query + site_id + **locale** + published；中文页返回中文、英文页返回英文。
  Entity Search 深度若未完成，如实登记（属 TD-20③/18H），不假装。

## 11. PublicUrl / PageCache

- PublicUrl：`base()` 不变；新增 locale 前缀，en 时所有 path 前加 `/en`。
- PageCache：`keyFor` 必须含 locale（否则 en 请求返回 zh 页）—— P0。

## 12. UI Dictionary / 语言切换器 / html lang

- `resources/lang/zh-CN/{ui,nav}.php`、`resources/lang/en/{ui,nav}.php`。
- Blade 固定 UI 词（了解更多 / 联系我们 / 提交 / 上一篇 / 下一篇 / 搜索 /
  暂无结果 / 加载 / 返回首页）→ `__('ui.xxx')`，消除 `__()` 与直写中文混用。
- `_lang_switcher` partial：列出 site supported locales，链接当前页对应 locale URL；
  键盘可达、屏幕阅读器可懂、focus visible。
- `<html lang="{{ app()->getLocale() }}">` 动态。

## 13. Admin 翻译 UX（后台本阶段保持中文）

- Content/Entity 表单加「中文 | English」Tabs + ✓已译/○未译状态；可创建/编辑 en 行。
- Site Settings 增加 locale 组（supported/default）。

## 14. 实施顺序与 Gate

1. Locale 基础设施（config/Registry/Context/middleware/路由双注册）
2. 数据模型 locale+group（migration + Translatable trait + 回填）
3. Site locale Setting
4. PublicUrl 前缀 + PageCache key
5. Catalog locale 投影
6. SeoMeta locale + Resolver
7. Schema/GEO/Llms/Sitemap locale
8. Search locale
9. UI dictionary + 切换器 + html lang + Blade UI 词
10. Admin 翻译 Tabs
11. Demo seeder en 翻译
12. 测试（Localization/LocaleRouting/LocalizedSeo/LocalizedSchema/LocalizedSitemap/LocalizedSearch）
13. 全量回归（≥861/4590，只增不减）+ Fresh install + 两态 + 四组合 + Multi-Site×Locale
14. `docs/audit/localization-final-audit.md` + commit + annotated tag `checkpoint-18F` + clean → **STOP**

### PASS 硬条件
0 failed / 0 skipped；中英文主流程真实可访问；canonical 分语言；hreflang 双向
（zh-CN/en/x-default，绝对 URL）；Schema inLanguage 正确；GEO/sitemap 分语言；
无 locale cache 串页；无跨站 locale 泄漏；无新增未解释 ERROR；无新业务/品牌硬编码；
关键前台能力不因语言切换失效。

### x-default（冻结）
`x-default` → 中文默认根 `/`（全站一致）。
