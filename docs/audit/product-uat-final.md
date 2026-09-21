# P-STEP 16A — Product UAT & Simulated Data Acceptance（最终报告）

- 阶段：P-STEP 16A（公开发布前插入的产品 UAT / 模拟数据验收）
- 执行模式：**C 为主 + A 并行** —— 把当前后台**可达 UI** 完整 UAT 完；同时产出《Admin Management Completion Design》设计文档（只设计、不写代码）；**严禁开发** Site / Entity / Relation / SeoMeta / Theme / Plugin / Settings 的新管理页。
- 被测基线：`v1.0.0-rc1` = `965d63c`（P-STEP 16 本地闭环产物）
- UAT 工作副本：`D:\GEO-OS-uat\geo-website-os`（`git archive v1.0.0-rc1` 解出的干净源码，无 vendor/.git/.env/sqlite/node_modules）
- 权威清洗仓：`D:\GEO-OS-rewrite\geo-website-os`（所有 Runtime 修复与本文档的落点）
- 日期：2026-09-21

> 结论先行：本轮以"第一次拿到产品的陌生使用者"视角，从干净安装开始用一套**通用模拟制造企业**完整建站并做异常操作，**抓到 6 个自动化测试未覆盖的真实产品 Bug，全部最小修复并补防回归测试**；修复后两仓全量回归一致为 **673 Tests / 3102 Assertions / 0 Failed / 0 Skipped**。
> 同时确认一个关键事实：**引擎 / Runtime 能力已产品化且多站隔离正确，但控制面板对 Site / Entity / EntityRelation / SeoMeta / Theme / Plugin / 大部分 Settings 没有管理 UI** —— 这是**产品完整性缺口（Product Completeness Gap），不是 Runtime 故障**。
> **ADMIN COMPLETION DEVELOPMENT = NOT STARTED**（本轮只设计，不开发），后台补全另立 **P-STEP 17**。

---

## 1. Fresh Environment（干净安装）

| 步骤 | 结果 |
|---|---|
| 源码来源 | `git archive v1.0.0-rc1`（=965d63c），不含 vendor/.git/.env/database.sqlite/node_modules |
| 依赖安装 | `composer install`（dev，含 phpunit 11.5.56）成功 |
| 环境初始化 | `.env.example`→`.env`、`php artisan key:generate`、touch 空 sqlite、`php artisan geo:install --no-interaction` |
| 安装产物 | 全迁移 + Default Site + admin 账号，安装器打印一次性登录密码 |
| 起服 | PHP 内置 server，`http://127.0.0.1:8091` |
| 冒烟 | `/`、`/geo.json`、`/robots.txt` 安装后直接 200 |

> 说明：`git archive` 不含 `.git`，且 `docs/` 被 export-ignore；已用 robocopy `/E` 从权威仓补入 docs（含历史审计文档），不影响 Runtime。

---

## 2. 模拟企业与数据（全部通用、无真实客户信息）

- 模拟主体：**示例制造有限公司**（工业材料制造）
- 栏目（Category，4 个）：products / solutions / knowledge / about
- 内容（Content，7 篇，全部通过 GEO 发布门禁）：
  - 文章 3 篇：《工业涂料选型指南：按工况选择防护涂层》`industrial-coating-selection-guide`、《结构胶粘接工艺与固化参数说明》`structural-adhesive-curing-process`、《功能添加剂在材料配方中的作用与添加比例》`functional-additives-dosage-guide`
  - 单页 1 篇：《关于示例制造有限公司》`about-example-manufacturing`
  - 产品内容 3 篇：`industrial-coatings` / `structural-adhesives` / `functional-additives`
- 每篇均满足门禁：GEO 三层（结论 / 解释 / 边界）非空、≥2 条带来源证据、责任人、复核日期、无极限词 / 占位符。
- 媒体：通用 OG 默认图（media id=2，公开 URL 200）；文章 1 上传封面（生成原图 + webp，写 cover_id）。
- 数据均为通用工业材料语境，不含任何原客户强身份信息。

---

## 3. 盲测能力地图（F1–F9，"陌生用户第一次登录"）

| 编号 | 发现 | 性质 |
|---|---|---|
| F1 | 空站直接渲染**硬编码制造垂直首页**（hero"源头工厂 / OEM·ODM"、裸 0 统计、病句"为等客户提供…"、CTA `/factory//cooperation//solutions/` 实测 404、页脚示例公司名） | 产品通用化缺口（P17 默认主题 / 空状态降级） |
| F2 | 后台 IA 沿用旧概念（事实库 / 页面文案 / 首页装修 / 全站话术 / GEOFlow）；仪表盘有产品计数但**无产品管理菜单** | IA 重设计（P17） |
| F3 | general 组 9 个主题 token 的 **label 种子全为 null**（DB 实锤） | 已登记 P17 |
| F4 | "待补全"链到只有主题色的 settings/general（断链） | 已登记 P17 |
| **F5** | **Site 无任何管理 UI**（不能在后台建 / 改站点、配域名） | **阻塞级产品缺口 → P-STEP 17A** |
| **F6** | **Entity / EntityRelation 无管理 UI**；后台"产品"实为 Content(type=product)，**不写 Entity、不进 Catalog / GEO 实体图** → 新旧双轨 | **阻塞级产品缺口 → P-STEP 17B（含命名冲突决策，见设计文档 §5）** |
| F7 | settings 仅 general 组可编辑；contact/copy/seo/geo/theme/sync **六组字段为 0**（DB 实锤 settings 表仅 general 10 行）；主题色 token 错放 general 而非 theme | P-STEP 17F |
| **F8** | **SeoMeta 无管理 UI**（无法在后台维护三态绑定的 SEO 覆盖） | **阻塞级产品缺口 → P-STEP 17C** |
| F9 | Theme 切换 / Plugin 管理无后台 UI（引擎层能力存在，见 P-STEP 14 测试） | P-STEP 17D / 17E |

正面：**Content 新建表单成熟**（类型 article/page/product、slug、摘要、Markdown、GEO 四层 + FAQ + 关键事实、栏目、知识分组、封面、责任人、发布 / 复核日期、人工锁定、门禁预检 / 草稿）。

---

## 4. 分域 UAT 结果

### 4.1 Content 内容全生命周期 —— PASS
- Create / Read / Update / Publish / Unpublish / Delete 全链路浏览器实测。
- **GEO 门禁实证**：文章 3 首发布被拒（含极限词"最佳"，`ContentGate::BANNED_WORDS`），改为"合适 / 合理窗口"后发布成功 —— 门禁真实生效。
- 生命周期：产品 7 下架→草稿→重新发布闭环；临时文 8 发布→200→删除（软删）→前台 404。
- URL：文章 `/knowledge/{slug}` 无尾斜杠 200；`/article/{slug}` 301→`/knowledge/{slug}`；单页 `/page/{slug}` 301→`/about/{slug}` 200。
- 异常：空标题被 HTML5 `required` 拦截；重复 slug 被后端 unique 拦截（**错误消息为英文 → i18n，登记 P3**），均无 500、无脏数据。

### 4.2 Category / Group / Menu / Block / Narrative / Facts / Inquiry / Redirect（可达后台 UI）—— PASS（含 Bug 修复）
- **栏目**：新建 / 编辑（修复 Bug#1 后）正常；知识分组新建正常。
- **菜单（Menus）**：主导航 / 页脚按 position + key 保存正常。
- **首页装修（Blocks）**：17 个 form / 7 个 table，逐区块 PUT 保存正常。
- **页面文案（Narrative）**：编辑 PUT 写 slot Content、留空保存 = 恢复默认（forceDelete slot），reset 闭环正常。
- **事实库（Facts）**：修复 Bug#6 后新建 / 删除正常。
- **客户留言（Inquiry）**：前台询价表单提交（含蜜罐 / 归因字段 / throttle:6,1 / 前端提交锁）→ 后台列表可见（含来源页 / 设备 / 落地页归因）→ 标记跟进（PUT）→ 删除（DELETE）闭环。
- **301 跳转（Redirects）**：修复 Bug#5 后新建 / 同站重复 / 编辑 / 删除正常。
- UAT 造的 groups / menus / redirects / inquiries / facts 走查数据**测后已全部清空**（groups=menus=redirects=inquiries=facts=0，narrative slot 已 reset）。

### 4.3 Media 媒体库 —— PASS（1 个未测小项已注明）
- multipart 上传（file input `name=file`）→ `storage/app/public/media/{YYYYmm}/{random}.ext`，公开 `/storage/media/...` 200；alt/title 更新、删除（删除后 URL 404）正常。
- 内容封面：编辑页上传 → 存 `covers/YYYYmm/`（原图 + webp），写 `cover_id`。
- 非法类型：`.txt` 被 `mimes` 拒绝（英文错误）；**封面 SVG 被拒**（Bug#4 修复后，防存储型 XSS）。
- 未测小项：正文 md-editor 的**内联上传**（uploadInline，JSON 返回，存 content/YYYYmm）本轮未走；"替换 / 重引用到 Entity OG / Site Logo"因 Entity / Site 无 UI 而无法测（登记 P17）。

### 4.4 SEO / GEO / Schema / Feed 前台输出 —— 基本 PASS，发现并修复 Bug#3，登记 2 项一致性技术债
- 文章详情 head：title / description(summary) / robots(`index,follow,max-image-preview:large`) / og:title / og:type=article 正确；JSON-LD = Organization + Article + BreadcrumbList。
- **Bug#3 修复后**：自定义封面 og:image / twitter:image / JSON-LD image 均为**绝对 URL** 且 fetch 200（修复前为裸相对路径，相对 /knowledge/{slug} 解析 404）。
- `/geo.json`：结构 `site/facts/entities/relations/contents`，业务词扫描 0；contents 含 7 篇。
- `/sitemap.xml`、`/llms.txt`、`/feed.xml`、`/robots.txt` 均可达；robots.txt 为完整通用规则（显式放行 GPTBot/ClaudeBot/Google-Extended/Perplexity/Bytespider + Sitemap 指令，无客户信息）。
- **登记技术债（P17，非本轮修复范围）**：
  1. sitemap.xml / llms.txt / geo.json / 搜索结果中列出了**实测 404 的 `/products/{slug}` 与 `/products/`、`/solutions/`**（产品 Content 端到端断裂，见 §6 双轨问题）—— 向搜索引擎 / AI 输出 404 URL。
  2. canonical / og:url 用冻结契约路径 `/article/{slug}`、`/product/{slug}`，与实际落地路径 `/knowledge/{slug}`、`/products/{slug}` 靠 301 桥接；host 为 localhost（Site domain 未配，无 Site UI）；SeoMeta 显式 og_image_path 与站点 logo 的绝对化 / 多站 domain 化未在本轮改动。

### 4.5 Theme 主题色（可达部分）—— PASS；整套主题 / 插件管理 UI 缺失
- `/admin/settings/general` 9 个主题 token + CTA 文案可保存、读回；改 `theme_primary=#C0392B` 后前台内联 `<style>` 的 `:root{--brand:#C0392B…}` **即时生效**（CSS 变量名为 `--brand` / `--brand-dark`），已恢复 `#2563EB`。
- 内联 CSS 注释为"GEO Website OS · Design System"，**不引用任何第三方设计体系名**。
- Theme A→B→A 整套切换、Plugin enable/disable per-site **无后台 UI**（F9）；引擎层能力由 P-STEP 14 的 Theme 重放测试、Plugin per-site 路由隔离测试（EnsurePluginEnabled）背书。

### 4.6 Multi-Site 多站隔离（真实 UAT 数据 + 真实 HTTP）—— PASS
- 因后台**无 Site 管理 UI（F5）**，Site B 由**数据层夹具经正式 Eloquent 模型**建立（slug=siteb、domain=b.test、专属栏目 + 专属文章 `bb-only-article` + 站点名标记 BBB_SITE_MARKER），未用原生 SQL 绕过、未改数据模型。
- 单机 hosts 改写需管理员（已证实 Access denied），故采用 **`curl -H "Host:"` 头注入**（TCP 连 127.0.0.1，Host 头分别为 default / b.test），对 `/geo.json`、`/sitemap.xml`、`/` 做 **A→B→A→B→A→B 六跳**：
  - `/geo.json`、`/sitemap.xml`：A 跳只见 A 文章 slug、不见 B；B 跳只见 `bb-only-article`、不见 A —— 6 跳严格隔离。
  - `/`：站点名标记仅在 B 跳出现（BBB_SITE_MARKER=True），A 跳恒 False —— 6 跳正确切换。
- 夹具测后已**完整拆除**（恢复 sites=1 单站）。
- 局限（如实标注）：本轮为 **HTTP Host 头验证，非浏览器双 host 渲染**；Site B 无法经 UI 创建本身即 F5 核心缺口。引擎层多站隔离另有强自动化（CrossSiteMemoLeakTest、EntityMultiSiteIsolationTest、CatalogRuntimeIsolationTest、P-STEP 14 D.2/D.3/D.4）。

### 4.7 异常操作矩阵
| 场景 | 结果 |
|---|---|
| 空标题提交 | HTML5 required 拦截，无 500 |
| 重复 slug | 后端 unique 校验拦截，无 500、无脏数据（错误文案英文，i18n P3） |
| 不存在前台 URL | 友好 404 |
| 后台访问不存在资源 ID（如 contents/99999/edit） | 友好 404"没有找到这个页面"，非 500 |
| 访问不存在的管理域（/admin/sites、/admin/entities、/admin/seo-metas） | 均 404（F5/F6/F8 路由不存在的硬证据） |
| 非法上传 .txt | mimes 拒绝 |
| 封面上传 SVG | 拒绝（Bug#4，防存储型 XSS） |
| 删除被引用对象（临时文发布后删除） | 软删成功，前台 404，sitemap/llms 同步移除 |
| 同站重复 301 规则 | 修复 Bug#5 后友好校验提示，非 500 |
| 新建事实缺列 | 修复 Bug#6 后正常 |
| Narrative 留空保存 | 等同恢复默认（reset），非异常 |
| 询价表单 | 前端 onBlur 校验 + 提交锁按钮防重复 + 蜜罐 + 后端 throttle / novalidate 兜底 |
| 重复提交 / 浏览器后退 / 刷新 | 写操作均为 PRG（POST→重定向→GET），刷新不重复提交；询价按钮有提交锁 |
| 切站编辑 / 切主题刷新 / 禁用插件后访问旧 URL | 因 Site / Theme / Plugin 无管理 UI，无法在后台操作，登记 P17（引擎层 per-site 隔离已由 P-STEP 14 测试覆盖） |

---

## 5. 前台全路由 Crawl（真实浏览器 + HTTP）

- **200**：`/`、`/knowledge/`、3 篇文章、`/search`、`/search?q=coating`、`/geo.json`、`/sitemap.xml`、`/llms.txt`、`/robots.txt`、`/feed.xml`、`/about/about-example-manufacturing`。
- **301 后终态 404**：`/products`→`/products/`、`/solutions`、`/factory`、`/cooperation`、`/contact`、`/about/profile|history|culture`、`/scenarios`→`/solutions/`。
- **产品内容详情 `/products/{industrial-coatings|structural-adhesives|functional-additives}` 全 404**；`/product/{slug}` 301→`/products/{slug}` 仍 404。
- 未知 URL → 404。
- Console / Network：主路径无未捕获 JS 错误；静态资源 / 封面 / OG 图 200（Bug#3 修复后）。

---

## 6. 核心系统性结论：Content(product) 端到端"双轨断裂"

- 文章（article）有 KnowledgeController 落地、单页（page）有 About 落地，**唯独产品（product）这个 Content 类型端到端断裂**：
  - 后台可建、可发布、过 GEO 门禁、进入 geo/sitemap/llms/搜索；
  - 但前台 `/products/{slug}` 由 **Catalog（Entity 投影）** 驱动（`ProductController::index()` 在 `Catalog::company()` 为空时 abort 404；`resolve()` 只认 Catalog、不查 Content(type=product)），空目录站无 organization Entity → 产品总览 / 工厂 / 合作 / 联系 / 产品详情一律 404。
- 根因：P-STEP 14 把旧 Facts 降为安装期 Example 种子、Catalog 改由 **Entity** 投影；而 **Entity 无后台管理 UI（F6）**，盲测用户无法经后台让产品上线。
- 定性：**产品完整性 / 前台内容模型统一缺口，不是多站隔离 Bug**。归 **P-STEP 17**：前台内容模型统一 + **Content Product vs Entity Product 命名冲突决策**（不能有两个"产品"，见设计文档 §5）+ sitemap/llms/canonical 以"前台 200 可访问"为准入。
- **本轮 16A 不改前台架构**（遵守"不借 UAT 重构 Core"边界）。

---

## 7. Bugs Found & Fixed（本轮 6 个，均最小修复 + 防回归测试 + 两仓同步）

| # | 模块 | 现象（真实浏览器 / HTTP） | 根因 | 修复 | 防回归测试 |
|---|---|---|---|---|---|
| 1 | 栏目 | 新建栏目必现 500 | categories 实列为 `description`（表单 / 控制器用 `intro`）；`external_url` 被引用但建表迁移从无此列 | 控制器 / 表单 intro→description；**新增 migration** `...000001_add_external_url_to_categories_table.php`（hasColumn 守卫，nullable） | V0911CmsAlignmentTest +2 |
| 2 | 内容发布 | 编辑页"发布 / 下架"按钮点击无效（Feature 测试直接 POST 路由故抓不到） | save-bar 在主 form `#contentForm` 内嵌套了 inline `<form>`（HTML 禁嵌套，浏览器忽略内层 form，请求永不到 publish()） | 裸按钮 `form=publishForm/unpublishForm` + 主 `</form>` 外的隐藏载体 form | 新增 ContentPublishFormTest（2 测试，正则断言无嵌套） |
| 3 | SEO OG 图 | 自定义封面 og:image / twitter:image / JSON-LD image 输出**裸相对路径**，相对 /knowledge/{slug} 解析 404（默认 OG 图却绝对） | `SeoMetaResolver` 缓存 / 返回 `Media->path`（disk 相对）而非 `Media->url()`；**测试夹具把 path 设成 /uploads/cover.jpg 并断言同值，测试固化了错误契约** | 解析器改 `Media::url()`（http 原样、否则 asset('storage/'.…)）；修正被固化的错误断言 + 新增 disk-relative 绝对化测试 | SeoMetaResolverTest / SeoHttpIntegrationTest 更新 + 新增；浏览器复测 200 |
| 4 | 安全（封面） | 内容封面允许上传 **SVG**，经 /storage 以 image/svg+xml 直出可执行脚本 = 存储型 XSS，与媒体库 / 内联上传明确禁 SVG 矛盾 | ContentController cover_file `mimes` 误含 svg | 去 svg，改 `jpg,jpeg,png,webp,gif` + 中文安全注释 | ContentCoverTest 新增 `test_cover_svg_is_rejected...`（用真实 SVG 内容的 createWithContent，绕过 ->image() 生成 PNG 的假阴性） |
| 5 | 301 跳转 | 同站点重复 from_path 新建直接 500 | redirects 有 UNIQUE(site_id,from_path)，控制器无 unique 校验、未捕获 UniqueConstraintViolationException | `Rule::unique(...)->where(site_id)->ignore($except)`（站点作用域）+ 中文消息；update 传模型 | 新增 RedirectAdminTest（3 HTTP 测试，含编辑自身不误报） |
| 6 | 事实库 | 后台新建事实必现 500 `table facts has no column named unit` | fact-form / FactController 暴露 `unit`、`source_url`，但 facts 建表迁移从无这两列（与 Bug#1 同模式的"幽灵列"），且无任何前台消费 | **新增 migration** `...000002_add_unit_source_url_to_facts_table.php`（hasColumn 守卫，nullable；不改老迁移） | 新增 FactAdminTest（3 测试：PRAGMA 列断言 / 带两字段创建 / 缺 label 回校验非 500） |

修复纪律：全部为**最小根因修复**，未借 UAT 重构 Core、未加产品功能、未改历史迁移（新增迁移 + hasColumn 守卫）、未引入新依赖（dom-crawler 缺失即用正则断言）。

**另登记（非阻断、不在本轮扩大处理）**：
- **i18n（P3）**：后台为中文 UI，但 Laravel 校验错误（slug unique、file mimes 等）为英文，缺 zh 验证语言；全后台无字段级 `@error`，仅 layout 顶部统一汇总。
- 首页 / blocks / menus / 询价 demand_type 默认种子为**制造垂直 Copy**（装备制造 / 建筑工程 / 汽车零部件 / 工业品牌方 / 经销商 / 其他；FAQ 起订量 / OEM·ODM 等）——非客户强身份（按口径 C 保留通用行业词），但"默认安装即制造垂直首页、空站不降级"归 P17 默认主题通用化。
- GEOFlow token 前缀 `yhf_`（疑似旧项目缩写），登记通用化 P17。
- category slug 的 unique 规则未按 site_id 限定（多站下偏严，观察项）；category type 枚举在迁移注释与控制器 / 表单间漂移；栏目 seo_title/seo_desc 疑似第三套 SEO；英文搜索对英文 slug / 正文召回弱；27 个制造 / 化工垂直内置图标。

---

## 8. 日志审计

`storage/logs/laravel.log` 全周期仅 **4 条 ERROR，且全部对应本轮已发现并修复的 Bug**：

1. `categories has no column named intro`（Bug#1）
2. `categories has no column named external_url`（Bug#1）
3. `UNIQUE constraint failed: redirects.site_id, redirects.from_path`（Bug#5）
4. `facts has no column named unit`（Bug#6）

修复后复测（含 Narrative / Blocks / Menus / Groups / Inquiry / Facts 复测 / 多站六跳 / 缺失资源 404）**未再出现任何 ERROR / Exception / Deprecated 导致的 500**。HTTP 200 不等于无错误，本轮已专门 drain 日志核实。

---

## 9. 回归测试

| 仓库 | Tests | Assertions | Failed | Skipped |
|---|---|---|---|---|
| 权威清洗仓 `D:\GEO-OS-rewrite\geo-website-os` | **673** | **3102** | **0** | **0** |
| UAT 工作副本 `D:\GEO-OS-uat\geo-website-os` | **673** | **3102** | **0** | **0** |

- 相对 rc1 基线（661 / 3057）：**+12 Tests / +45 Assertions**，全部来自本轮 Bug 防回归测试（ContentPublishForm +2、RedirectAdmin +3、FactAdmin +3、V0911CmsAlignment +2、ContentCover +1，及 SEO 解析器断言扩充）。
- 未删除任何测试、未降低断言、未 skip、未改 expected 求绿；Bug#3 修正的是"测试固化的错误契约"（属允许且必须的修正），并补了正向绝对化断言。
- 85 条 PHPUnit Deprecation 为 PHPUnit 12 元数据风格提示，非失败。

---

## 10. 业务污染复扫

- UAT 模拟数据全部为通用工业材料语境（示例制造有限公司 / 工业涂料 / 结构胶 / 功能添加剂 / OEM·ODM 通用行业词），**不含任何原客户强身份标识**（原企业名 / 品牌名 / 客户域名 / 客户电话 / 具体厂区地址 / 原经营品类等，词表以《business-keyword-scan》审计口径为准）。
- 对 Runtime（app/config/routes/database/resources/tests/plugins）做强身份词全量复扫，结果为 **0**；docs 下的命中仅存在于历史审计证据文档 `docs/audit/business-keyword-scan.md`（Historical Audit Exemption，且 docs 在 release archive 中 export-ignore），不属于 Runtime 或正式发布文档污染。

---

## 11. Git / 交付状态

- 本轮 Runtime / schema 改动（Bug#1–#6）与新增测试**已在两仓同步，尚未提交**；权威仓 `git status --short` 为 10 modified + 5 untracked（2 个新 migration、3 个新测试文件）。
- 本文档与《Admin Management Completion Design》为 rc1 之后的**文档提交**（docs 在 release archive 中 export-ignore，不进入 ZIP）。
- **RC Provenance 提示（需用户决策，不自行移动 tag）**：Bug#3 改了 SeoMetaResolver、Bug#4 改了 ContentController、Bug#5 改了 RedirectController、Bug#1/#6 新增 2 个 migration，均属 Runtime / schema 改动，**rc1（965d63c）是否重建为含修复的新 RC commit / tag，留待 Gate 后决定**；在决定前不 push、不发布。

---

## 12. Remaining Risk / 技术债务（移交 P-STEP 17 与发布 Gate）

**P0 / 阻塞产品完整性（无 UI，引擎能力已具备）**
- Site 管理 UI（17A）、Entity + EntityRelation 管理 UI（17B，含 Content Product vs Entity Product 命名决策）、SeoMeta 管理 UI（17C）、Theme 管理 UI（17D）、Plugin 管理 UI（17E）、Settings 七组补全（17F）、补全后的全后台 Admin UAT（17G）。

**P1 / 前台内容模型与输出一致性**
- 产品 Content 端到端落地（或明确产品只由 Entity 承载并在后台收口创建入口）；sitemap / llms / geo / 搜索**以"前台 200 可访问"为准入**，停止输出 404 URL。
- canonical / og:url 路径与实际落地统一、Site domain 驱动 host、SeoMeta 显式 og_image_path 与站点 logo 绝对化 / 多站 domain 化。
- 默认首页 / blocks / menus / 询价选项的制造垂直种子通用化 + 空站空状态降级（F1）。

**P2 / 后台体验与一致性**
- 中文校验语言（i18n）+ 字段级 @error；settings 主题色从 general 归位 theme、9 token 补 label、空六组处置、`yhf_` 前缀通用化；category slug 站点作用域、type 枚举统一、外链栏目前台接线、栏目 SEO 与 SeoMeta 关系厘清；后台 500 错误页样式；GEOFlow 等旧概念 IA 重命名。

**P3 / 观察项**
- 英文搜索召回、制造垂直图标通用化、md-editor 内联上传补测、release archive 缺 docs 致部分守护测试仅在完整 checkout 成立。

> 本轮不宣布"系统无 Bug"。正确表述：**已完成当前测试范围内的系统性产品 UAT 与 Bug Hunt，修复了 6 个真实缺陷并补防回归；仍存在的风险与技术债务已在上文登记并规划到 P-STEP 17。**

---

## 13. Gate 判定

| 项 | 结果 |
|---|---|
| Fresh Install | PASS |
| 模拟数据建站（Content / Category / Media / 发布 / 前台） | PASS |
| 可达后台 UI 全走查（Content/Category/Group/Media/Menu/Block/Narrative/Inquiry/Redirect/Facts/主题色） | PASS |
| 前台全路由 / SEO / GEO / Schema / Feed | PASS（含 Bug#3 修复；产品双轨 / canonical 一致性登记 P17） |
| Multi-Site（真实数据 + HTTP 六跳） | PASS（HTTP Host 头方式；UI 不可建 Site 登记 F5） |
| 异常操作矩阵 | PASS（覆盖项；UI 不可达项登记） |
| Bugs Found → Fixed → 防回归 | 6 / 6 修复并测试 |
| 日志审计 | PASS（4 ERROR 全处置，无遗留） |
| Business Pollution | 0 |
| Full Regression | **673 / 3102 / 0 / 0（两仓一致）** |
| **Admin 管理面完整性（Site/Entity/Relation/SeoMeta/Theme/Plugin/Settings）** | **NOT STARTED（产品完整性缺口，见设计文档 + P-STEP 17）** |

**阶段判定：P-STEP 16A = PASS（针对"可达 UI 的产品 UAT + 模拟数据验收"范围）**
附带明确前置条件：**ADMIN COMPLETION DEVELOPMENT = NOT STARTED**；在 P-STEP 17A–17G 完成并通过 17G 全后台 Admin UAT 之前，控制面板不具备完整的"陌生用户独立建站"能力，**不进入 Public Release**。
本轮**暂停一切 GitHub 动作**（不 push、不转 Public、不发 v1.0.0）。
