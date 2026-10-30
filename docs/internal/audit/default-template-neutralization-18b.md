# P-STEP 18B — Default Template Neutralization（#115）验收报告

- 阶段：P-STEP 18B（Release Closure 第二阶段）
- 范围：TD-10 默认模板 / Copy / IA 行业中性化；TD-11 默认 Menu / Blocks 站点初始化；TD-12 `Site.name` 与 `setting.site_name` 单一事实源；TD-13 垂直内置图标通用化
- 前置：P-STEP 18A PASS（`4dbc95a` / tag `checkpoint-18A`，801 / 3948 / 0 / 0）
- 边界：不改历史 migration、不删 Example 数据、不删 docs/audit、不重设计 Core、不倒灌 #114、不处理 RBAC / i18n / 搜索等 P3、不提前打 RC / push / Release
- 核心验收铁律：**Blank System ≠ Demo Site**

> 结论口径：本报告只声明「已完成当前测试与真实 HTTP / 浏览器范围内的系统性验证」，不宣布"无 Bug"。残留风险见 §11。

---

## 1. 目标与出厂模型

把 fresh install 出厂体验从"天然带工业材料制造 IA 的站点"改为**行业中性空站（Blank System）**；工业材料制造 Example 下沉为**可选 Demo 数据层，仅 `db:seed` 装载**。

```
geo:install（出厂 / Blank System）
  migrate --force → storage:link
  → 默认站点 name = --site-name ?: config('app.name')   （TD-12 收敛）
  → DefaultSettingSeeder（64 键产品中性默认）
  → BlankHomepageSeeder（清空首页 page_blocks，保证空站无垂直区块）
  → 管理员
  （不调用任何 Demo / Catalog / Fact / Structure / Content seeder）

db:seed（可选 / Demo Site）
  DatabaseSeeder → DemoSeeder
  FactSeeder → StructureSeeder（重建 16 个制造区块 + 栏目树）
  → SettingSeeder → ContentSeeder（3 知识文章 + about.profile 叙事插槽）
  → CatalogSeeder（12 Entity + 58 Relation）
  → 末尾默认站点改名 Example 企业并写 metadata.organization
```

## 2. 根因：为什么 fresh install 会带制造首页

不是 `home.blade` 的空态分支问题，而是**历史 data migration 在每个 fresh install（`geo:install` 跑 `migrate`）时播种了 16 个 `is_active=1` 的制造垂直首页 `page_blocks`**：

- `2026_09_14_000011_seed_home_builder_blocks`
- `2026_09_15_000013_v06_ia_theme_blocks`
- `2026_09_15_000015_v07_home_cases_knowledge_groups`
- `2026_09_15_000016_seed_mid_banner_block`
- （`000012` 已被 `000018` 删除）

历史 migration **禁止改 / 删**（工程历史与升级路径）。因此分离点不落在 migration，而落在两层：

1. **出厂层**：新增 `BlankHomepageSeeder`，由 `geo:install` 调用，清空默认站点首页区块（幂等）。
2. **Demo 层**：`StructureSeeder`（仅 `db:seed`）按站重建 16 个区块与栏目树。

> Feature 测试用 `RefreshDatabase` 只 `migrate`、不跑 `geo:install`，测试库仍会有 migration 播种的 16 blocks——这正是必须在 geo:install 出厂层（而非 migration 层）做分离、且必须用真实 fresh install + HTTP 验证的原因。

## 3. TD-10 默认模板 / Copy / IA 中性化

- `config/copy.php`：nav 的 products / solutions children 置空，运行时由 Catalog 动态填充；footer quickLinks 中工厂与资质 / 客户案例 / 合作方式等垂直列保留定义但**整列受 `Catalog::hasProduction()` / company 门控**，空站不渲染；`brandColumn.companyName` 置空、`legal.copyright` 为空（运行时回退产品名）。
- `config/pages.php`：narrative lead、home / about / factory 骨架行业中立；`cooperation_faqs` 为行业中立 FAQ；`home_faqs=[]`。
  - `product_faqs`（4 个 Demo core 产品 slug 键）与 `scene_faqs`（3 个 Demo 场景 slug 键）**原样保留为内置 Example 包**：制造词全部位于 slug 键控数组内，通用站 slug 永不命中、零运行时泄漏；文件头注释写明该纪律。
- `HomeBlockDefaults`：`capabilities()` 返回 `[]`、`faqs()` 随 `home_faqs=[]` 为空。
- `HomeController::buildStats`：空 company 返回 `[]`，构建后 `array_filter` 过滤 0 值，label 中性，杜绝裸 0 / 病句。
- 五个前台控制器（Home / About / Factory / Cooperation / Contact / Product）SEO、lead、docblock 中性化；history / culture / profile blade 补空守卫。
- 产品详情标题后缀、询价 `demand_type`、copyright / companyName 等写死「示例制造有限公司」处全部去除垂直身份。
- 工厂页 stats / 车间 / 年产能 / 吨单位属于 `hasProduction()` 门控下的**固有生产语义**，在有数据的 Demo 站保留（口径 C 通用生产语义），空站不可见；吨单位最终归并到 TD-07。

## 4. TD-11 默认 Menu / Blocks 按站初始化

- 制造区块只在 `db:seed` 由 Demo `StructureSeeder` 按站重建（`PageBlock::updateOrCreate` by `(page,type)`，全部 site-scoped，末尾 flush）。
- `geo:install` 不播任何区块：空站 `page_blocks = 0`。
- 菜单 / 区块全部 site-scoped；products / solutions / knowledge 子项由 Catalog / Group 数据驱动，空站为空；factory / about 固定子项整列受 Catalog company / hasProduction 门控。
- 实测空站可见导航仅剩「知识中心」，页脚仅「关于我们 / 知识中心 / 联系我们」，无工厂与资质 / 客户案例 / 合作方式列，A / B 不串。

## 5. TD-12 Site.name 单一事实源

**契约**：`Site.name` 是站点显示名唯一权威；`settings.site_name` 仅为单向派生镜像，供 SEO / Schema / 视图等既有消费者读取。

- `Site` 模型 `booted::saved`：任何路径创建 / 更新站点后，`Setting::withoutSiteScope()->updateOrCreate([site_id,key=site_name], value=site.name)` + `Setting::flush()`；`Schema::hasTable` 守卫、空名跳过、Throwable 静默（安装早期 settings 表未就绪）。
- `GeoInstall`：无 `--site-name` 时默认名 = `config('app.name')` 并 `save()`（触发镜像）；`--site-name` 自定义名生效；domain 仅在显式传入时设置（不再用 `array_filter` 跳过 name、也不再让 firstOrCreate 命中既有 migration 占位记录而保留 'Default Site'）。
- `DefaultSettingSeeder`：`site_name` 跟随 `SiteContext::currentSite()?->name ?: Site::default()?->name ?: app.name`，不再硬编码 app.name 覆盖自定义名；`seo_title_suffix` / `geo_org_name` 仍取 app.name（有意独立）。
- `SettingController` general 组：`site_name` 非空回写 `SiteContext::currentSite()->save()`（清空不置空），末尾 `PageCache::flush()`。
- `FeedController` RSS channel title 回退链 `Setting::get('site_name') ?: currentSite()?->name ?? app.name`。

**真实 HTTP 同源验证（Demo 8097，curl 登录后台）**：经 Settings general 把站名改为「演示改名公司 UAT」后——

| 消费端 | 结果 |
| --- | --- |
| DB `sites.name` | 演示改名公司 UAT |
| DB `settings.site_name` | 演示改名公司 UAT（镜像同步） |
| 首页 `<title>` / `og:site_name` | 演示改名公司 UAT |
| 首页页脚品牌名出现次数 | 9 处全部跟随 |
| `/geo.json` `site.name` | 演示改名公司 UAT |
| `/feed.xml` channel title | 演示改名公司 UAT |

验证后已改回「示例制造有限公司」并 DB 确认恢复。

## 6. TD-13 垂直图标通用化

- 全站收敛为**单一通用 SVG 图标库** `resources/views/site/_icon.blade.php`（通用名 registry），无 slug → 垂直图标硬编码。
- `config/icons.php` 为中性 label registry（仅 `factory` 保留「工厂（生产制造）」这一通用生产语义 label）。
- home / products 出厂图标序列改为中性几何 / 商务图标；垂直语义仅随 Demo 数据出现。

## 7. 本轮新发现并修复的真实缺陷（探索式 HTTP 才暴露）

两态真实 HTTP 重放共发现并最小修复 **3 个**自动化测试未覆盖的真实问题，均补防回归测试。

### 7.1 TD-26：SQLite 表名 schema 限定致空站删除保护失效
- 现象：空站无法删除，被误判存在业务数据。
- 根因：Laravel `Schema::getTableListing()` 在 SQLite 返回 `main.settings` / `main.contents` 等带 schema 前缀的表名，`SiteController::resourceCounts()` 的白名单（sites / SYSTEM_TABLES / CONFIG_TABLES）整体失配，`settings`（含 TD-12 镜像行）从未被排除。
- 修复：循环开头 `$table = Str::afterLast($listed, '.')` 去 schema 前缀；新增 `CONFIG_TABLES=['settings']`；非默认空站删除在 `DB::transaction` 内先删 settings 再删站点并 `Setting::flush()`；`index()` resource_total 不含 settings（空站=0）。

### 7.2 TD-12 站名双源分叉（见 §5）
- 现象：`geo:install` 后 `sites.name='Default Site'`（migration 内置占位名）而 `settings.site_name='GEO Website OS'`；且 `DefaultSettingSeeder` 硬编码 app.name 会覆盖 `--site-name` 自定义名。
- 修复：见 §5，统一为 Site.name 单一事实源。

### 7.3 TD-25：PageCache 整页缓存键不含端口，同主机异端口串整页
- 现象：本机并排跑空站 8096 与 Demo 8097（共用同一 file 缓存目录），先访问空站后访问 Demo 首页，Demo 首页返回**空站欢迎屏正文**、canonical 指向 `http://127.0.0.1:8096/`。
- 根因：`PageCache::keyFor = sha1($request->getHost() . $path)`，`getHost()` **不含端口**；两实例 host 同为 127.0.0.1、仅端口不同，命中同一整页 shell（shell 缓存整页正文，personalize 只回填 host / CSRF / 归因，不换正文）。
- 修复：`keyFor` 改用 `$request->getHttpHost()`（含端口）。标准 80/443 端口 HTTP_HOST 不含端口，**生产按域名分区行为不变**；本地多实例 / 同机非标端口反代正确隔离。
- 防回归：`PageCacheTest::test_cache_key_distinguishes_same_host_different_port`（同主机异端口键不同 / 同 origin 仅 UTM 共享一份 / 异域名分区）。
- 修复后两站首页交叉预热均 HIT 且互不串：Blank title/h1=GEO Website OS、canonical=8096；Demo title=示例制造有限公司、h1=制造 hero、canonical=8097。

## 8. Fresh Install 两态 HTTP / 浏览器证据矩阵

两个独立 SQLite + 两个 `artisan serve`（修复 TD-25 后重启、清缓存）：

- Blank：`p18b_blank2.sqlite`，仅 `geo:install -n`
- Demo：`p18b_demo.sqlite`，`geo:install -n` + `db:seed -n`

### 8.1 数据库形态

| 计数 | Blank（仅 install） | Demo（install + seed） |
| --- | --- | --- |
| sites.name | GEO Website OS | 示例制造有限公司 |
| settings.site_name / seo_title_suffix / geo_org_name | GEO Website OS | 示例制造有限公司 |
| settings 行数 | 64 | 64 |
| page_blocks | 0 | 16 |
| entities | 0 | 12 |
| entity_relations | 0 | 58 |
| facts（DB） | 0 | 23（geo 输出可见 17） |
| contents | 0 | 4（3 知识文章 + 1 about.profile slot） |
| categories / groups | 0 | 2 / 6 |

### 8.2 Blank 空站 HTTP（8096）

| 路径 | 状态 | 说明 |
| --- | --- | --- |
| `/` | 200 | 中性欢迎屏：H1=GEO Website OS，「站点已就绪…」，按钮「浏览内容→」（/knowledge/）、「联系我们」（#s08 锚点） |
| `/products/`、`/solutions/`、`/factory/`、`/cooperation/`、`/about/{profile,history,culture}/`、`/contact/` | **404** | 无 Catalog company，整站垂直 IA 不存在 |
| `/knowledge/` | 200 | 空列表 + 中性空状态文案 |
| `/geo.json` | 200（222B） | site.name=GEO Website OS，facts/entities/relations/contents 全 `[]` |
| `/sitemap.xml` | 200（426B） | 仅 2 URL：首页 + /knowledge/ |
| `/feed.xml` | 200（186B） | channel=GEO Website OS，无 item |
| `/llms.txt`、`/robots.txt`、`/search` | 200 | 中性；robots 放行 AI 爬虫 |
| 随机不存在路径 | 404 | 中性 404 页（title「页面未找到｜GEO Website OS」，提供首页 / 知识中心入口，无堆栈泄漏） |

浏览器（真实渲染）可见文本：导航仅「知识中心 / 联系我们」，页脚仅「关于我们 / 知识中心 / 联系我们」+ 中性 slogan「以专业可靠，服务每一位客户」+「© 2026 GEO Website OS」。**无工厂与资质 / 客户案例 / 合作方式列，无任何制造身份。**

### 8.3 Demo 站 HTTP（8097）

- 核心页全部 200：首页、`/products/`、3 产品线、4 个 core 产品、`/solutions/` + 3 场景、`/factory/`、`/cooperation/`、`/knowledge/` + 3 频道 + 3 文章、`/about/{profile,history,culture}/`、`/contact/`、`/news/`、5 个 feed、`/search`。
- 非 core 产品 `/products/heat-resistant-coating-300` = **404**（符合 TD-05 决策：仅 core 产品有公开页，不进 feed）。
- **Sitemap 27 个 URL 逐一对拍全部 200，NON-200 = 0**（Public Render Contract 满足）。
- 产品详情 `epoxy-primer-100`：title/h1=环氧富锌底漆 ZP-100，JSON-LD = Organization / BreadcrumbList / Product / HowTo / FAQPage。
- `/about/profile/`：title/h1=示例制造有限公司（Demo about.profile 叙事插槽）。
- `/geo.json`：site.name=示例制造有限公司，entities=12、relations=58、facts 可见 17、contents=3（about.profile slot 不进 geo）。
- `/feed.xml`：channel=示例制造有限公司，3 个 item（3 篇知识文章）。
- 浏览器（真实渲染）可见完整工业材料制造 Example：hero、3 产品线、3 应用场景、参数级交付、统计、4 个车间（原料处理 / 配料混合 / 成型加工 / 品控包装）、OEM/ODM 合作方式——**全部来自 Example 数据层，仅 db:seed 后出现**。

> 截图说明：本轮两次 `bu.screenshot()` 均返回 GAC viewport unavailable（工具侧限制），视觉证据以浏览器 `get_page_text()` 真实渲染文本 + curl 抓取的 HTML/JSON 为准，不影响结论。

## 9. 业务污染复扫

- 强身份词（Demo Tenant A / Demo Tenant A / demo-tenant-a / demo-tenant-ashipin / Sample City / Sample Province / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Road / 400-001-3770 / Sample SaaS / Sample SaaS / sample-saas / yhf_）：**Runtime = 0**。全仓命中仅存在于 `tests/`（负向护栏）与 `docs/audit/`（Historical Audit Exemptions，release archive export-ignore）。
- 「示例制造有限公司 / Example Manufacturing / EXAMPLEMFG」：仅存在于
  - Demo 数据层 `database/seeders/{FactSeeder,SettingSeeder,ContentSeeder}.php`（仅 db:seed）；
  - `config/facts.php`（**仅安装期 Example 种子定义，Runtime 不消费**，随 TD-07 退场）；
  - tests 护栏与 docs/audit。
  - **空站安装链路 `GeoInstall` / `DefaultSettingSeeder` / `BlankHomepageSeeder` 零命中**，StructureSeeder 经 Facts::company() 动态取名、无字面垂直身份。
- 空站不可见内联 `<style>` CSS 注释中的「工厂 / 车间 / 资质」区块名，按口径 C 通用生产语义保留（空站不渲染这些区块）；`llms.txt` 中「资质 / 产能」仅出现在中性免责声明（"站点未公示的数据…请勿推测或补全"）。

## 10. 日志审计

- 两个 serve stdout 日志：0 条 error / fatal / exception / warning。
- `storage/logs/laravel.log`：`production.ERROR=0、CRITICAL=0、EMERGENCY=0、SQLSTATE=0、Deprecated=0`。
- 历史 Exception / Undefined 条目时间戳全部早于本轮两态 HTTP 重放窗口（UTC 07:00 之后 **local.ERROR = 0**），均为当日开发迭代期已定位修复项（Blade 语法、undefined array key、临时探针 `$this` 误用、命令行重定向误用、AuditLog 类名等），最终全量回归全绿可证产品代码无这些错误。

## 11. 残留风险与边界（不宣布无 Bug）

- **TD-07（Organization 双载体，v1.0 Required，未处理）**：organization Entity 与 `sites.metadata.organization` 并存，SchemaBuilder 读后者；年产能 / 吨单位等归此。`config/facts.php` 待其完全 Entity 化后退场。18B 明确不处理。
- **TD-05 / TD-06 / TD-09 / TD-08b / TD-16① / TD-20①** 等仍按 Registry 留 18C 裁定，本阶段未触碰。
- **SiteContext 静态 memo 陈旧性**：同进程内先解析 currentSite 再改名，memo 持有旧名对象（本轮在隔离单测中遇到，已在测试中 `SiteContext::setSite($site->fresh())` 复刻真实安装顺序）。真实 geo:install 为全新单进程、先收敛站名再 seed，两态探针证实无此问题；后台改名走新请求（memo 每请求重建）。与 D.4 同族，CLI / Queue / 嵌套上下文的统一复位仍由 RequestScopedState 负责，建议 18C 结合 TD-07 一并复核。
- 截图工具 GAC viewport 本轮不可用，视觉证据为渲染文本 + HTML，未做像素级截图留档。
- 本阶段未改 Runtime 架构、未移动 v1.0.0-rc1（仍冻结于 `965d63c`）、未配置 remote / push / Release。

## 12. 回归与 Git

- Focused：
  - `BlankSystemDemoSeparationTest` 8 passed / 101 assertions（两态分离护栏）；
  - `PageCacheTest` 12 passed / 80 assertions（含 TD-25 端口隔离新测试）；
  - `GeoInstallTest` + `SettingsGovernanceTest` 14 passed / 124 assertions（TD-12 契约修正后）。
- 全量回归：见文末「最终回归」（由收口流程填入，要求 ≥ 801 基线、0 failed / 0 skipped，且不得删测试 / 降断言）。
- Tag：`checkpoint-18B`（annotated）。Working tree：clean。

---

### 最终回归

- **全量：810 passed / 4053 assertions / 0 failed / 0 skipped**（`php artisan test`，SQLite :memory:）。
- 相对 18A 基线（801 / 3948）：**+9 测试、+105 断言**，全部来自 18B 新增两态分离护栏与三个真实缺陷的防回归测试；无删除测试、无降断言、无 skip。
- 两处既有测试按 TD-12 新契约更新（非削弱）：
  - `GeoInstallTest`：默认站名期望由 migration 占位名 'Default Site' 改为 `config('app.name')`（geo:install 出厂收敛后的真实契约）；
  - `SettingsGovernanceTest::test_default_seeder_alone...`：单独 seed DefaultSettingSeeder 前先复刻 geo:install 的站名收敛并 `SiteContext::setSite($site->fresh())`，反映"site_name 是 Site.name 镜像"而非 seeder 硬编码 app.name。
- 本轮两态 HTTP 重放窗口（UTC 07:00 后）local.ERROR = 0；两 serve stdout 零异常。
