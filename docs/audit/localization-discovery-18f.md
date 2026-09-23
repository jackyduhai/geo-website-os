# P-STEP 18F — Localization DISCOVERY（现状盘点）

- 日期：2026-09-23
- 任务性质：**DISCOVERY（只读审计，不写代码）**
- 声称基线：HEAD `59dd573`（18E，861 / 4590 / 0 / 0），18F 未开始
- **实际审计结论（最重要）**：仓库当前 HEAD 已是 **`d886d5d`「P-STEP 18F: Localization (zh-CN + en frontend i18n)」**——切换设备前的会话已把 18F 完整实现并提交。本次 DISCOVERY 因此不是"从零设计"，而是**对已落地实现做全链路审计，判断是否真实达标、有无第二套逻辑或缺口**。

> 原则：不相信模型描述与报告，一切以真实代码为准。本文所有结论均来自对
> routes / app / lang / config / database / resources / tests 的实际读取。

---

## 1. 全链路现状盘点（Locale → … → Cache）

| # | 链路段 | 现状 | 关键文件 / 证据 | 判定 |
| --- | --- | --- | --- | --- |
| 1 | Locale Registry | 唯一注册表，BCP-47：`zh-CN`/`en`；含 supported/default/fallback/prefixes/x_default | `config/localization.php`；只读口 `app/Support/Localization/LocaleRegistry.php` | ✅ 已建立，无 zh/zh_CN/cn/en-US 混用 |
| 2 | URL Strategy | 中文无前缀 `/`；英文前缀 `/en`；**无 query string 正式语言 URL** | `config/localization.php` prefixes；`routes/web.php` | ✅ 冻结 |
| 3 | Router | 同一组前台路由**双注册**：en 组 `prefix(en)+locale:en` **先**注册，zh 组 `locale:zh-CN` 后注册（避免 zh catch-all 抢 /en） | `routes/web.php:112-120` | ✅ 顺序正确 |
| 4 | Middleware | 单一 `SetLocale`（别名 `locale`）：路由 action 取语言 → 校验官方支持 + 站点 `site_supported_locales` → 未启用 en 则 `/en/*` 全 404 → `App::setLocale` + `LocaleContext::set`；`try/finally` 中 `clear()` | `app/Http/Middleware/SetLocale.php`；`bootstrap/app.php` 别名 | ✅ 单一裁决，无 Controller/Blade 自判 lang |
| 5 | Request Context | `LocaleContext`（request-scoped，仿 `SiteContext`），静态持有当前语言，未设置回退默认 | `app/Support/Localization/LocaleContext.php` | ✅ |
| 6 | 翻译模型（Model） | **方案 C：同表多行 + `locale` + `translation_group`(uuid)**；同一业务对象不复制成两个独立资源；`Translatable` trait 提供 creating 自动分配 group/locale、`translation()`、`forLocale`、`hasTranslation`、`publishedLocales`、`createTranslation` | `app/Support/Translatable.php`；Entity / Content 使用该 trait | ✅ identity ≠ translation 已分离 |
| 7 | DB Schema | entities 加 locale/group、唯一约束 `(site_id,type,slug,locale)`；contents 因 SQLite 限制**建新表→拷数据→改名**，唯一 `(site_id,slug,locale)`；seo_metas 加 locale，三类 partial unique 索引列加入 locale；含可回滚 down() | `database/migrations/2026_09_23_000001_add_locale_to_translatables.php` | ✅ 含升级 backfill 与 down |
| 8 | Catalog 读模型 | `Catalog::locale()` 从 LocaleContext；缓存键 `siteId:locale`；查询 `forLocale`；关系权威基础语言 `baseLocale`（站点无 zh-CN 主体时回退 `site_default_locale`，TD-42）；en 分支完整 | `app/Support/Catalog.php` | ✅ 按语言/站点隔离 |
| 9 | 页面成稿层 | `Pages` 统一按当前 locale 读取页面内容 | `app/Support/Pages.php` | ✅ |
| 10 | Blade 视图 | 固定 UI 文案走 `__('ui.*')`/`__('nav.*')`；`<html lang>` 随 locale；含语言切换器 | `resources/views/site/...`；`partials/locale-switcher.blade.php` | ✅（业务内容写死见 §4） |
| 11 | UI 字典 | `lang/{zh-CN,en}/ui.php`（约 280+ 键，覆盖首页区块/产品/场景/工厂/合作/知识/表单/错误页/搜索/空态）、`nav.php`、`seo.php` | `lang/zh-CN/*`、`lang/en/*` | ✅ 两语成套 |
| 12 | Navigation / Menu / Footer | nav 两语；切换器只换前缀、保留路径与查询；无译项不静默混入中文 | `lang/*/nav.php`；`locale-switcher.blade.php` | ✅ |
| 13 | SEO / Canonical / hreflang | `SeoHeadComposer` 统一计算：canonical 随语言；hreflang = 站点 supported × **资源已发布语言**取交集（避免指向 404），含 x-default；URL 去 query；Blade 纯消费 | `app/Http/View/SeoHeadComposer.php` | ✅ 复用 Resolver 体系，未造 LocalizedSeo |
| 14 | OG | og:locale（zh_CN / en_US）、og:title/description 随语言 | SeoHeadComposer + 测试 | ✅ |
| 15 | Schema | `SchemaBuilder::locale()` 输出 inLanguage；@id 语言中性不随语言变 | `app/Services/Geo/SchemaBuilder.php` | ✅ |
| 16 | GEO | `/geo.json` 与 `/en/geo.json` 两语实体/内容/边分离；`JSON_UNESCAPED_UNICODE|SLASHES`（TD-49） | `FeedController::graph`；GeoGraphBuilder | ✅ |
| 17 | Sitemap | `/sitemap.xml` 与 `/en/sitemap.xml` 各自只列本语言 URL；首页 loc locale-aware（TD-50）；遵守 draft/noindex/跨站准入 | SitemapBuilder；PublicUrl | ✅ |
| 18 | LLMS / RSS / robots | `/llms.txt`、`/feed.xml` 两语；RSS 受 `geo_rss_enabled` 门禁；robots 全站唯一（仅 zh 注册）、引用两套 sitemap | `routes/web.php`；FeedController | ✅ |
| 19 | Search | 合并 content + entity 查询，按 `site_id` + `locale` + published 过滤；中→中、英→英（TD-48） | `SearchController`、`SearchResult` | ✅（深度仍属后续） |
| 20 | PageCache | 键 = 前缀:version:`sha1(getHttpHost().path)`；`path` 含 `/en` 前缀 → 中英键不同；host 含端口区分站点 | `app/Support/PageCache.php:70-75` | ✅ P0 串页风险已从结构上避免 |

---

## 2. 已有能力 / 缺失能力 / 冲突能力

- **已有能力**：上表 20 个链路段全部落地，且均复用既有体系（LocaleRegistry / LocaleContext / Translatable / Catalog / PublicUrl / SeoMetaResolver / Schema / GEO / Sitemap）。
- **缺失能力（代码层面）**：**未发现**。用户要求的 zh/en 全链路在代码中均有对应实现。
- **冲突能力（第二套逻辑）**：**未发现**。
  - 没有新增 `LocalizedUrl` / `LocalizedPublicIndex` / `LocalizedSeoResolver` / `LocalizedSchemaBuilder`；locale 全部在**现有** PublicUrl / Catalog / SeoHeadComposer / SchemaBuilder 上以 `LocaleContext` 扩展。
  - URL 仍由 `PublicUrl` 单一裁决；SEO 仍由 Resolver/Composer 单一裁决。
- **双向反写**：未出现 EntityRelation ↔ Catalog 双向同步（18A 单向派生决策保持）。

---

## 3. 已登记并修复的缺陷（来自实现期，复核属实）

实现期自报修复 9 项 + 2 项测试修正（见 `localization-final-audit.md §3`）。本次抽查其中架构性项，代码中确有对应修复：

| ID | 缺陷（性质） | 代码复核 |
| --- | --- | --- |
| TD-42 | Catalog 硬依赖 zh-CN 主体，en-only 站 relationMap TypeError 500 | `Catalog.php` baseLocale 回退、relationMap 首参 `?Entity` ✅ |
| TD-43 | area_served en 分支无 `??`，en-only 站 500 | 已改 `(array)(area_served ?? [])` ✅ |
| TD-44 | blank 兜底单语描述跨语言泄漏 | 优先当前语言 company summary ✅ |
| TD-45 | knowledge/products 默认 SEO 键带工业措辞 | `lang/*/seo.php` 中性化 ✅ |
| TD-47 | 路由文件重复加载 PublicUrlLocalized redeclare fatal | `function_exists` 守卫 ✅ |
| TD-48 | 搜索只覆盖 Content | SearchController 合并 content+entity ✅ |
| TD-49 | geo.json 中文被 `\uXXXX` 转义 | JSON_UNESCAPED_UNICODE\|SLASHES ✅ |
| TD-50 | en sitemap 首页 loc 泄漏中文根 | 首页 loc locale-aware ✅ |
| — | 「LocaleContext 跨请求泄漏 P0」 | 经三重查证裁定 **NON-DEBT（误判）**，根因是对 `\u` 转义的原始 body 做字面匹配 ✅ |

---

## 4. 写死中文盘点（区分 UI 串 / 业务内容 / 白名单）

- **UI 固定串**：已进 `lang/*/ui.php`（按钮、表单、空态、错误页、区块标题等），两语成套，**不是写死**。
- **业务内容**：产品/场景/文章正文来自 DB（Content/Entity 翻译行），不属于翻译字典，**不应**塞进 lang 文件——边界正确。
- **有意保留 CJK 白名单**（final-audit §7，复核合理，不计缺陷）：
  - CSS/JS 内联中文开发注释（用户不可见）；
  - 中文站「微信」平台名（英文站为 WeChat，英文包无汉字）、`contact_wechat_qr`；
  - Organization `legalName`/`alternateName` 中文法律注册名（法律事实不随语言变）；
  - footer/QR alt 已改用 `__('ui.qr_alt')`。
- **结论**：未发现新的"业务/品牌中文写死"；残留 CJK 均有明确归属或白名单。

---

## 5. 与自报证据的差异（必须复跑确认，DISCOVERY 不代为结论）

以下为实现期**自报**结果，本次 DISCOVERY 未执行，**不作为已验证结论**，需在 Gate 验证阶段真实复跑：

| 自报项 | 自报值 | 待验证方式 |
| --- | --- | --- |
| Focused（Localization18FTest） | 8 tests / 40 assertions | `php artisan test tests/Feature/Localization18FTest.php` |
| Full Regression | **871 / 4653 / 0 / 0**（较 18E +10 / +63） | 全量 `php artisan test` |
| Fresh install 空站 | 默认仅 zh-CN，`/en` 404 | 建 0 字节 sqlite + `geo:install -n` |
| Demo 计数 | settings=73、entities=18(zh12/en6)、relations=58 | `db:seed` 后实查 |
| 两态 / 多站 / 四组合 / cache 对拍 | PASS | 真实 HTTP + 浏览器 |

---

## 6. DISCOVERY 结论与建议

1. **18F 不是"未开始"，而是已在 `d886d5d` 完整实现**；代码审计未发现第二套国际化逻辑、未发现缺失链路段、未发现新写死。
2. 但"代码存在 + 自报全绿"不等于"产品达标"。**建议下一步进入 Gate 验证（而非重新开发）**，按 §5 真实复跑：focused → 全量回归 → fresh install → blank/demo HTTP（含 /en）→ 真实浏览器 → Multi-Site×Locale → Light/Dark×Locale 四组合 → PageCache 中英对拍 → 污染/日志复扫。
3. 验证全过 → 18F PASS；任何一项失败 → 最小修复 + 防回归后重验。

> 待用户裁定：**(A) 认可已实现，授权进入 Gate 验证**；或 **(B) 对实现有调整意见**。
> DISCOVERY 到此 STOP，不自动跑测试、不进入 18G、不移动 rc1、不碰 remote/Release。
