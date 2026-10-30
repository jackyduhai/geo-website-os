# 20B 主动猎虫报告（只读诊断模式）

- 仓库：`D:\GEO-OS-rewrite\geo-website-os`，HEAD `c6f04fd`，工作树 clean
- 模式：只读诊断（不修复、不写 DB、不 POST/PUT/DELETE）
- 基线：6 contents / 18 entities / 20 pages / 0 media / 58 relations / 0 inquiries / 1 user；zh-CN + en 双语已播种
- 已闭合 v1.0 债务与 v1.1 延期项均未重复上报
- 验证方式：静态代码路径 + 只读 GET（带 cookie 后台会话）+ 直读 SQLite

---

## 问题清单

| 编号 | 分级 | 模块 | 触发条件 | 机制（locator） | 症状 | 复现步骤 | 扩散范围 | 建议原因级修复（仅建议） |
|---|---|---|---|---|---|---|---|---|
| BUG-20B-001 | Major | 后台·设置 | 进入任意设置分组（常规/外观/联系/SEO/GEO/分析/同步/复制） | `app/Http/Controllers/Admin/SettingController.php:52` 查询 `Media::whereIn('mime_type', [...])`，但 `media` 表列名为 `mime`（见 `database/migrations/2026_09_14_000005_create_display_tables.php` 与 `PRAGMA table_info(media)`）。SQLite 在双引号标识符不存在时退化为字符串字面量，`whereIn` 条件恒假，返回 0 行，**不抛错**。 | 设置页「选择已有图片」面板永远空，即便媒体库已有图片；LOGO/OG/social 只能本地上传，不能从库内挑选。页面本身 200，无报错。 | 1) 上传一张图片到媒体库；2) 进入 `/admin/settings/general`；3) 打开 LOGO 图片选择器 → 列表为空。直读 SQL 验证：`select * from media where mime_type in ('image/jpeg')` 走 PDO::query 报 `no such column`，走 Eloquent（双引号包裹列名）返回 0 行。 | 所有设置分组共用同一查询（line 52）；SeoMetaController:344 用的是正确的 `mime like 'image/%'`，不受影响。 | 把 `mime_type` 改为 `mime`，与 `EntityController.php:334`、`SeoMetaController.php:344`、`MediaController` 保持一致。 |
| BUG-20B-002 | Major | 后台·内容/实体删除 | 后台删除一条**已发布**且有 en 翻译的 Content（或 Entity） | `app/Http/Controllers/Admin/ContentController.php:159-166` `destroy()` 仅对当前行 `$content->delete()`（软删）；`Translatable::saved` 事件只在 save 时同步状态，软删不触发 saved，en 翻译行保持 `status=published, deleted_at=NULL`。前台 `PageController::matchContent` (line 68-71) 按 `locale=当前语言 + slug` 独立查 en 行，en 行未被软删 → 命中。Entity 侧同构：`app/Http/Controllers/Admin/EntityController.php:232-242`。 | 中文文章删除后，`/en/{en-slug}` 仍 200 渲染该文章正文，形成"删了前台还能访问"的泄漏；中文 404、英文 200 不一致。 | 静态复现：挑一条同时有 zh-CN/en 行的 Content（`translation_group` 相同），后台删除 zh-CN 行；GET `/en/{en-slug}` → 200。（本轮只读，未实际删除。） | Content 与 Entity 两类实体全部受影响；Page 是硬删（`Page::booted` 删除事件级联删 PageBlock/SeoMeta），不在此列。 | 删除 anchor 时级联软删同 `translation_group` 的全部翻译行（或在 `SoftDeleting` 模型事件里同步 trashed 到翻译行）；恢复时对称恢复。 |
| BUG-20B-003 | P1 | 前台·栏目页 | 访问未注册专用控制器的 list 型栏目（如 `/en/news/`） | `app/Http/Controllers/Site/PageController.php:205-208`（单页型栏目取首条 Content）与 `:217`（list 型栏目分页查询）均为 `Content::published()->where('category_id', $category->id)`，**未加 `->where('locale', LocaleContext::current())`**。`SetLocale` 中间件只设 `LocaleContext`，不挂全局 scope，控制器必须自行过滤。对比 `KnowledgeController::index/channel`、`ProductController`（Catalog）、`SearchController` 均正确过滤 locale。 | `/en/news/` 会列出 zh-CN 文章（标题/正文为中文），与当前语言不一致；SEO/OG 也跟着错。当前基线 news 栏目 0 篇文章，未现形，但只要往 news 栏目同时发 zh/en 文章即泄漏。 | 静态复现：给 news 栏目发一篇 zh-CN 文章，不发 en 版本；GET `/en/news/` → 列表里出现中文文章。（本轮只读，未发。） | 所有走 `dispatch → renderCategory` 的栏目（即除 knowledge 外、未注册专用 route 的栏目，如 news）。isSinglePage 分支（line 205）同样漏。 | 在 line 205 与 line 217 查询上补 `->where('locale', LocaleContext::current())`，与 matchContent line 70 对齐。 |
| BUG-20B-004 | P1 | 后台·内容状态机 | 编辑内容时填写了**未来时间**的 `publish_date`，然后点「发布」 | `app/Http/Controllers/Admin/ContentController.php:181-183`：`if (! $content->published_at) { published_at = now(); }`——若表单已提交未来时间，`published_at` 已是未来值，分支跳过，status 直接置 published。前台 `Content::scopePublished`（`app/Models/Content.php`）过滤 `published_at IS NULL OR published_at <= now()`，未来时间不命中。仪表盘 `DashboardController.php:22` 按 `status=published` 计数，不看 published_at。 | 后台列表/仪表盘显示「已发布」，前台 404；运营误以为已上线。定时发布本属 v1.1 延期，但当前代码会让"已发布"与"前台可见"在未来时间窗内不一致，且无任何提示。 | 静态复现：新建 Content，状态=草稿，publish_date=明天，点发布；后台列表显示已发布；GET 前台 slug → 404。 | 全部 Content（article/news/article 类）。Entity 走 `published_at=now()` 强制即时发布，不受影响。 | 要么在 publish 时若 published_at 为未来则拒绝并提示"定时发布未上线"，要么在列表/详情页对 `published_at > now()` 的 published 行打"定时"标记。 |
| BUG-20B-005 | P2 | 后台·知识分组 | 同一栏目下新建两个 slug 相同的知识 Group | `app/Http/Controllers/Admin/GroupController.php` `validateData()` 对 `slug` 无 unique 规则（仅 required/max）。前台 `KnowledgeController::channel` 与路由 `{channel}` 按 slug 查 Channel，命中第一条（id 最小）。 | 第二个同名分组静默不可达；后台编辑列表里两个分组 slug 相同，运营难以察觉冲突。 | 静态复现：在同一 category 下建两个 Group，slug 都写 `coating`；GET `/knowledge/coating/` 只显示第一个。 | 全部 Group（知识分组）。CategoryController 有 unique 规则，不受影响。 | 加 `Rule::unique('groups','slug')->where(fn($q)=>$q->where('category_id',$category->id)->where('site_id',...))`，编辑时 ignore 当前 id。 |
| BUG-20B-006 | P2 | 后台·页面 | 新建/编辑 Page 时填写了一个未启用的 locale（如 `fr`） | `app/Http/Controllers/Admin/PageController.php:59` 校验 `locale` 仅 `required|string|max:16`，**未做白名单**。对比 EntityController:359 用 `Rule::in(['zh-CN','en'])`。 | 后台创建出一条 locale=fr 的 Page，前台无 /fr 路由，该页永远 404；但后台列表可见、可编辑，运营以为已上线。 | 静态复现：POST 一条 Page，locale=fr；后台列表出现；前台 `/fr/xxx` 404。 | 全部 Page。Content 走 Translatable trait 在 creating 时强制 locale=默认（`Translatable.php` bootTranslatable），不受影响。 | 把 locale 校验改为 `Rule::in(LocaleRegistry::supported())`。 |
| BUG-20B-007 | P3 | 后台·内容删除提示 | 删除任意 Content | `app/Http/Controllers/Admin/ContentController.php:165` 成功文案「已删除（可在回收站恢复，如需彻底删除请联系技术）」——但完整 Content Lifecycle（trash/restore）是 v1.1 延期项，当前后台无回收站入口、无 restore 路由。 | 用户被告知"可在回收站恢复"，实际找不到入口；信任损耗。 | 删除任意 Content，读 flash 文案。 | 仅 Content 删除文案。 | 文案改为「已删除」或在 v1.1 回收站上线前去掉括号承诺。 |
| BUG-20B-008 | P3 | 后台·安装向导 | Setup Wizard 第 3 步连续录入两个同名产品 | `app/Http/Controllers/Admin/WizardController.php:74` 用 `Str::slug($p['name'])` 生成 slug，无唯一性检查；`entities` 表有 `unique(site_id,type,locale,slug)` 约束。 | 第二个同名产品触发 QueryException 500，向导中断。 | 静态复现：向导第 3 步连续提交两个 name="Coating" 的产品。 | 仅 Wizard 一次性流程；正式 EntityController 有 unique 校验，不受影响。 | Wizard 内 slug 生成时追加短随机后缀，或 try/catch 后跳过重复项。 |

---

## 被排除的疑似项（查了但证实不是问题）

| 疑似点 | 证据 |
|---|---|
| `EntityController.php:127` `Entity::withoutSiteScope()->find($rel->to_entity_id)` 是否越权跨站读实体 | `EntityRelationController::store` 已强约束 `from_entity_id`/`to_entity_id` 同 site_id（`EntityRelationController.php:51-72`），关系边不会跨站；此处 withoutSiteScope 只是省掉冗余条件，无 IDOR。v1.0 已闭合 IDOR 多站隔离。 |
| 未登录访问后台 | `EnsureAdmin` 中间件未登录 redirect 到 `/admin/login`；`/admin/sites` 等超管路由另加 `EnsureSuperAdmin`。GET `/admin/dashboard` 未登录 → 302 登录页（已测）。 |
| `ContentController::unpublish` 不清 `published_at` 是否导致前台仍可见 | scopePublished 先过滤 `status=published`，status=draft 已隐藏；不清 published_at 只影响"再发布时保留原时间"，不是 bug。EntityController 清 published_at 是风格不一致，不影响正确性。 |
| 后退/刷新重复提交表单 | Laravel PRG（redirect()->back()->with）+ session flash，刷新不会重复 POST；表单走 web 中间件组 CSRF 校验。 |
| 软删 Content 后 relations 残留 | `content_relations` 表 site-scoped；前台 `renderContent` 只渲染当前 anchor 的 relations，anchor 软删后中文 404；但 en 翻译行的 relations 仍可达——这正是 BUG-20B-002 的表现，不重复记。 |
| `/en/news/` 当前是否泄漏中文文章 | 直读 DB：news 栏目下 0 篇 Content，列表为空，未现形；BUG-20B-003 是潜伏问题。 |
| `/admin/settings/general` 是否 500 | 实测 200（因 SQLite 把未知列名当字符串字面量，查询恒假返回空，不抛错）；实际表现为图片选择器空（BUG-20B-001），不是 500。 |
| `/cases/`、`/en/cases/` 404 | 符合预期：当前站点无 case_study 实体，Blank 场景下 404 是正确降级（AboutController:36 同类降级）。 |
| `/knowledge/how-to-choose-industrial-coatings/`（带尾斜杠）ERR | CanonicalizeSlash 中间件 301 到无尾斜杠规范地址，PowerShell 未跟随重定向显示为 ERR，行为正确。 |
| 后台登录限速 | `AuthController::login` 按 IP+email 限速 5 次/分钟，无问题。 |
| Form 提交 CSRF/越权 | 路由在 web 中间件组（CSRF 启用）；Form 隐式绑定受站点全局 scope 限制，跨站 404；蜜罐字段已实现。 |

---

## 未验证项与原因

| 项 | 原因 |
|---|---|
| BUG-20B-002（软删 anchor 泄漏 en 翻译）的实际 GET 复现 | 本轮只读，禁止 POST/DELETE 写 DB；基线库 0 条 trashed content。结论基于代码路径静态推演 + `Translatable::saved` 事件只在 save 时触发（软删不触发 saved）这一事实链。修复阶段应在测试库删一条双语 content 后 GET `/en/{slug}` 验证。 |
| BUG-20B-003（栏目页 locale 泄漏）的实际 GET 复现 | 同上，需往 news 栏目发 zh-CN 文章才能观察；基线 news 0 篇。 |
| BUG-20B-004（未来发布时间）的实际 GET 复现 | 需 POST 一条未来时间的 Content 并 GET 前台；本轮不写。 |
| 多站切换后会话残留 | 基线仅 1 个 site，无法测切站后越权；SiteController::destroy 已在删站时 `session()->forget('admin_site_slug')`，逻辑正确。 |
| 全量数据（数千篇）下分页/排序性能 | 本轮不做性能压测（属另一 skill 范围）；分页用 Laravel paginate(12)，SQL 走索引，未见明显问题。 |
| Blank 场景（0 content/0 entity）前台各页 | 已测 `/cases/` 404、`/` 200（首页有 homepage 种子）；About 子页在空数据下 abort 404（AboutController:36），降级正确。 |
| 视觉渲染类问题 | 不在本轮范围（后续模拟阶段负责）。 |
| v1.1 延期项（定时发布/trash/restore/Media Library 深度等） | 按口径不报为 bug。 |
