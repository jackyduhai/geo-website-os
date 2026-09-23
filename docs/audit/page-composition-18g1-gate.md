# P-STEP 18G-1 Gate — Page Composition / Template System（基础层 + Landing）

- 日期：2026-09-23
- 基线起点：HEAD `a5d2c5e`（= annotated tag `checkpoint-18F`；872 / 4655 / 0 / 0；worktree clean）
- 父 Epic：**#116 — Page Composition / Template System**
- 本 Gate 范围：**Block Registry + Template Registry + Page 模型**三层落地，**Landing Page 可创建 / 组合 / 发布 / 前台访问**，后台由"首页装修器"升级为 **Page Composition Manager**，PageCache 页面级失效，`SeoMetaResolver.resolvePage()`，最小 **FormReference** block。
- 不在本 Gate：系统页（Home / Listing / Detail / Article / Product / Service / Contact）迁入 Template+Block → **18G-2**；完整 Form Builder → **18H**。

---

## 1. 用户裁定（口径基线，不可漂移）

分层模型：

```
Site → Theme → Template → Page → Block → Content / Entity / Media / Form
```

- **Theme = 视觉**（颜色 / 字体 / 间距 / 圆角 / 阴影 / Light·Dark），不含结构与业务事实。
- **Template = 结构**（布局 + 具名 Slot + 每槽允许的 Block 类型 + 布局规则），**不存内容**。
- **Page = 页面实例**（site / template / slug / status / locale / translation_group / route / SEO 绑定），**绝不做成又一套内容表、不存 Product / Article / Organization 等业务事实**。
- **Block = 组成单元**（Block Type + 结构化 JSON/Data + Registered Renderer）。
- **Content / Entity = 业务事实唯一来源**。

安全边界（强制）：

- 不在 DB 存任意 Blade / HTML / PHP；一律 **Block Type + Structured JSON + Registered Renderer**（RichText 存 Markdown，渲染时转安全 HTML）。
- 单向投影：Block 引用并读取数据源，**不向 Content / Entity / Catalog 反写**。
- v1 不做自由拖拽 Builder（v1.1）。

Core Block v1（16 个）：
`hero`、`rich_text`、`image`、`media_text`、`feature_grid`、`stats`、`logo_cloud`、`faq`、`testimonial`、`cta`、`contact_info`、`breadcrumb`、`product_grid`、`service_grid`、`content_grid`、`form_reference`。

Template（层级化降数量）：抽象 `base` → `home` / `listing` / `detail` / `contact` / `landing`。

---

## 2. 实现清单

### 2.1 Block Registry（TD-53）

- `app/Support/Blocks/BlockType.php`：final readonly 值对象——`type / label / category / icon / fields / view / allowedSlots / perLocale / dataSource / hasSchema / defaultContent / help`；`field()`、`allows(templateKey, slot)`、`renderView()`、`fromConfig()`。
- `app/Support/Blocks/BlockRegistry.php`：静态注册表——`boot / flush / all / get / has / forSlot(templateKey, slot) / render(PageBlock, context) / resolveData()`；渲染统一走 `view($type->view, $viewData)->render()`；grid 卡片经 **PublicUrl** 准入、locale 用 **LocaleContext**、limit 切片，防 N+1。
- `config/blocks.php`：16 Core Block 的 label / category / icon / per_locale / fields / default；grid 标 `data_source`、faq 标 schema。字段类型：text / textarea / markdown / number / select / checkbox / media / items(item_fields) / buttons / source。
- 16 个渲染器：`resources/views/site/blocks/{type}.blade.php`。

### 2.2 Template Registry（TD-54）

- `app/Support/Templates/TemplateDefinition.php`：final readonly——`key / label / extends / slots / layout`；`slotNames()`、`slot(name)`、`allows(slot, blockType)`。
- `app/Support/Templates/TemplateRegistry.php`：静态注册表——`boot / flush / all / get / has / selectable()`。
- `config/templates.php`：layout=`layouts.site`；定义抽象 `base`（main `*`）、`home`（main 15 block 白名单）、`landing`（main `*`）、`listing`（header·main·sidebar）、`detail`（header·main·related）、`contact`（header·main）。

### 2.3 Page 模型与迁移（TD-55）

- `app/Models/Page.php`：用 **BelongsToSite** + **Translatable**；`sharedTranslatableColumns = [template, is_home, status]`；`blocks()`（hasMany，默认外键 page_id，orderBy sort）、`activeBlocks()`、`seo()`；scope `published / home`；`booted` deleting 级联 PageBlock（where page_id）+ SeoMeta。
- 迁移：
  - `..._000010_create_pages_table.php`：列 site_id / template(default landing) / slug(nullable) / is_home / title / status(default draft) / locale(default zh-CN) / translation_group；组合唯一 `(site_id, slug, locale)`（SQLite NULL 不参与）；索引 status / translation_group。
  - `..._000011_add_page_id_and_slot_to_page_blocks_table.php`：page_id nullable + slot 默认 main + index（不加 DB FK）。
  - `..._000012_add_page_id_to_seo_metas_table.php`：page_id + index + 部分唯一 `page_seo_meta_unique (site_id, page_id, locale) WHERE page_id IS NOT NULL`。

### 2.4 前台 Landing 渲染与路由

- `app/Http/Controllers/Site/PageController.php`：catch-all `dispatch` 在「content → category」后增加第 3 分支（**仅单段路径** `count(segments)===1`）→ `matchPage()`（`Page::published()->slug->locale(LocaleContext::current())`）→ `renderPage()`：取 TemplateRegistry（缺省 landing），activeBlocks 按 slot 分组、sortBy sort，逐 block 经 BlockRegistry::render 拼 slotsHtml；无 hero 时输出视觉隐藏 H1。
- 视图 `resources/views/site/page.blade.php`。
- 组合页 block 统一 `page='page'` + `page_id` + `slot`；首页 `page='home'`（page_id=NULL）天然排除，**首页零破坏**。

### 2.5 Admin Page Composition Manager（TD-58 主体）

- `app/Http/Controllers/Admin/PageController.php`：Page CRUD / 发布 / 下架 + Block 在槽位内 add / edit / update / destroy / move / toggle；`TemplateDefinition::allows` 与 `BlockType::allows` 双闸门；slug 组合唯一 `Rule::unique('pages')->where(site_id+locale)`（update ignore）。
- 视图 `resources/views/admin/pages/`：index / composer / add_block / form / block_form / _item_cells；10 个字段子视图 `fields/{text,textarea,markdown,number,select,checkbox,media,items,buttons,source}.blade.php`。
- `routes/admin.php`：pages 组（含 publish、blocks 嵌套、move、toggle）。
- `resources/views/admin/layout.blade.php`：菜单新增「组合页面 / Landing」（nav icon grid）。

### 2.6 缓存页面级失效（#116 验收项，不另立 TD）

- `app/Support/PageCache.php`：新增 path 级版本号——`keyFor` 含 pathVersion 段；`PATHVER_PREFIX` 维护 per-host 的 path→version；`forgetPath() / forgetPage() / forgetTemplate()`（改 template 整站 flush）。

### 2.7 resolvePage / SEO

- `app/Services/Seo/SeoMetaResolver.php`：新增 `resolvePage(Page): SeoResult`（page_id SeoMeta → Page.title → 站点级 fallback；canonical 缺省 = PublicUrl page URL）+ `pageSeoMeta()` + 请求级 memo；`Site\PageController::renderPage` 消费。
- `app/Models/SeoMeta.php`：fillable/casts 加 page_id、`page()` belongsTo、`isPageLevel()`、`isSiteLevel()`。

### 2.8 FormReference（TD-59 最小集成）

- block `form_reference`：title / desc 可配，`@include('site._lead_form')` 引用现有 Inquiry 表单，提交走现有 `/inquiry`。**完整 Form Builder（fields / validation / notification / spam / consent）→ 18H。**

---

## 3. 测试证据

| 项 | 结果 |
| --- | --- |
| Focused `tests/Feature/PageComposition18GTest.php` | **26 passed / 78 assertions / 0 failed** |
| Full regression | **898 passed / 4745 assertions / 0 failed / 0 skipped** |
| 相对 18F 基线（872/4655） | 只增不减（+26 tests / +90 assertions） |

`PageComposition18GTest.php` 覆盖 7 类契约：A 访客跳登录；B Page CRUD / 发布 / 删除级联 / slug 组合唯一；C Composer + Block 增删改 / 排序 / 显隐 / 槽位·类型闸门；D 前台 Landing（draft 404 / published 200 / 无 hero sr-only H1 / en 前缀 / 内联脚本不被二次转义）；E 页面级缓存失效（改 block 仅失效该页、不波及他页）；F resolvePage（canonical / title / page-level SeoMeta 覆盖）；G 陌生品牌零代码搭站全程仅经 Admin HTTP，以及解耦（删 grid block 保留 Entity / 删 page 保留业务实体）、多站隔离、翻译组。

### 首轮发现并修复（focused 首次 22 passed / 3 failed）

1. **form.blade create 返回 500（Undefined `$exists`）**：内联 `@php($exists = ... && ...)` 编译异常 → 改标准 `@php ... @endphp` 块。
2. **block_form update 返回 500（unexpected endswitch）**：`@switch` 内相邻复杂 @case 触发 ParseError → 重构为按字段类型 `@include('admin.pages.fields.*')`，@switch 移除，新建 10 个字段子视图。
3. **断言误判**：`assertStringNotContainsString('hero-title')` 命中编译后 CSS 选择器 → 改断言 hero section 结构 `class="hero-split`。

---

## 4. Fresh Install / 两态 HTTP

- Fresh `geo:install -n`（空站）：成功；空站 sites=1 / entities=0 / contents=0 / pages=0 / page_blocks=0 / seo_metas=0，settings 含默认。
- Blank site HTTP：首页中性欢迎屏，无制造业 IA；核心路由 contract 正常。
- Demo（`geo:install` + `db:seed --force`）HTTP：双语（zh-CN / en），首页 16 区块、产品 / 场景 / 知识 / 联系正常。
- Feed 端点：`sitemap.xml`、`llms.txt`、`feed.xml`、`geo.json`、`robots.txt` 两态均正常；公开 URL 全部 HTTP 200，Draft / noindex / 跨站不泄漏。

---

## 5. 真实浏览器 UAT（本 Gate 最重要产出）

在 demo 库建验证 Landing：zh slug `browser-launch`（hero + feature_grid + cta，3 blocks）、en slug `browser-launch`（hero + cta，2 blocks），均 published。

- 首版截图：hero / cta 正常，**feature_grid 位置大片空白**。
- 逐层排查（HTTP 抓取关键词 PRESENT、tinker 单独渲染 feature_grid 正确、activeBlocks 返回 3 blocks 均正常）排除数据层。
- 真实 Chrome（CDP）：feature_grid 为 `grid reveal`（无硬编码 `in`），opacity 0；手动 `classList.add('in')` 后完美渲染；新建 IntersectionObserver 验证元素完全进入视口（intersecting / ratio=1），但**产品自带 observer 未加 in**。
- `console_messages()` 抓到 **3× `Uncaught SyntaxError: Unexpected token '&'`** → 主脚本整块解析失败、observer 从未建立。

### TD-60（根因与修复）

- 根因：`layouts/site.blade.php` 主 `<script>` 内 labels / ariaCurrent 用 `{{ json_encode(...) }}`；Blade `{{ }}` = e()（htmlspecialchars，ENT_QUOTES），把 JSON 双引号转义成 `&quot;`，JS 首个 token 即 `Unexpected token '&'`，整个初始化 IIFE（导航收缩 / 下拉 / 移动抽屉 / 深色切换 / 数字滚动 / IntersectionObserver）全部失效。
- 修复：两处改 `{!! json_encode(...) !!}`；随后 `view:clear` + `PageCache::flush()`（旧整页缓存含 SyntaxError）。
- 复验：滚动后 feature_grid 自动 `grid reveal in` / opacity 1（产品 observer 自行触发），**console 零错误**，三卡片自然横排。
- 防回归：`test_frontend_inline_theme_script_is_not_double_escaped`（断言 `var labels={light:"` 存在、`var labels={light:&quot;` 不存在）。
- 影响面：该缺陷影响**全站前台主脚本**（不止组合页），本 Gate 已彻底修复。

---

## 6. 多站 / Locale 隔离

- `test_page_is_site_scoped`：Site B（host b.test）前台看不到 A 的组合页（404）；切到 B 上下文后编辑 A 的 Page，模型绑定跨站 **404**。
- `test_translation_links_pages_by_group`：zh / en Page 经 translation_group 关联，同一逻辑页不复制。
- zh/en × Light/Dark 四组合在 18D/18F 已验证，本 Gate 未破坏；组合页 en 走 `/en/{slug}`、zh 走 `/{slug}`，PageCache 不串 locale。

---

## 7. Runtime 污染 / 日志 / 清理

### Runtime 业务污染

- 强身份词（Demo Tenant A / Demo Tenant A / demo-tenant-ashipin / 400-001-3770 / Sample Road / 金家街 / demo-tenant-a.local / Sample SaaS）扫描 `app` / `config` / `resources` / `routes`：**0 命中**。
- 豁免：docs/audit、tests 负向护栏、历史 migration、口径 C 通用行业词。

### 日志审计（storage/logs/laravel.log）

历史 3 条，全部归因、无遗留：

| 时间 (UTC) | 级别 | 内容 | 归因 |
| --- | --- | --- | --- |
| 08:42:31 | local.ERROR | PHP Parse error unexpected '='（Psy / psysh） | **tinker shell 吞 `$`/引号的命令构造问题，非产品缺陷** |
| 12:44:08 | testing.ERROR | Undefined `$exists`（form.blade） | 本 Gate 首轮发现的视图缺陷，**已修复** |
| 12:44:11 | testing.ERROR | unexpected endswitch（block_form.blade） | 本 Gate 首轮发现的视图缺陷，**已修复** |

- 基线（最后时间戳行）后重跑 focused 26/78：**NO NEW ERRORS**。
- 无未解释、未修复的 ERROR / CRITICAL / EMERGENCY。

### Smoke / 环境清理

- 删除临时库 `D:\Temp\18g\{blank,demo,test}.sqlite`（含 browser-launch smoke Page/Block，zh+en）。
- 删除 serve 日志（*.out / *.err）。
- 结束全部 9 个残留 serve（8131–8139）及 PHP 进程，端口全部释放。
- `view:clear` + `PageCache::flush()`。
- 主仓 `database/database.sqlite` 复核：无 pages 表、零 smoke。
- 保留证据：`D:\Temp\18g\full-regression.txt`、landing-*.png 截图。

---

## 8. Git / Tag

- `git diff --check`：通过（exit 0；仅 CRLF→LF 的 Windows 常规提示，无 whitespace error）。
- 变更全部属于 18G-1 范围（8 modified + Block/Template/Page 三件套 + 3 迁移 + 16 block 视图 + Admin pages 视图 + 2 Discovery 文档 + 测试），无意外文件。
- Commit：见 Gate 输出。
- Annotated tag：**`checkpoint-18G-1`**，target 对齐最终 HEAD。
- 提交后 `git status --short`：**clean**。

---

## 9. 结论与遗留

### 结论

**P-STEP 18G-1：PASS** —— 基础 Composition Layer（Block / Template / Page Registry）成立，Landing Page 可零代码创建 / 组合 / 发布 / 前台访问，Page Composition Manager 落地，缓存页面级失效与 resolvePage 闭环，真实浏览器 UAT 发现并修复影响全站主脚本的 TD-60。

### #116 子项状态

| TD | 状态 |
| --- | --- |
| TD-53 Block Registry | **CLOSED by 18G-1** |
| TD-54 Template Registry | **CLOSED by 18G-1** |
| TD-55 Page / Landing Model | **CLOSED by 18G-1** |
| TD-56 Detail Composition | ACTIVE → **18G-2** |
| TD-57 Listing Composition | ACTIVE → **18G-2** |
| TD-58 Page Composition Manager | PARTIAL（Landing/Page 完成；系统页 → 18G-2） |
| TD-59 Form Block | 最小 FormReference 完成；完整 Form Builder → **18H** |
| TD-60 主脚本二次转义 SyntaxError | **CLOSED by 18G-1** |

### 下一阶段

- **P-STEP 18G-2**：系统页（Home / Listing / Detail / Article / Product / Service / Contact）迁入 Template + Block 组合，缓存与表单收口。
- 继续冻结：`v1.0.0-rc1`（965d63c，HOLD）；不配置 remote / push；不 Release。
- 18G-1 Gate 后 **STOP**，未经明确授权不进入 18G-2。
