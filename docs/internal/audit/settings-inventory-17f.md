# P-STEP 17F — Settings 全量盘点与分组治理（Inventory）

> 阶段：P-STEP 17F Settings Management（Admin Control Plane 第 6 子阶段）
> 方法：逐 key 追踪真实 consumer（app / resources / config），区分「有效设置 / 无 consumer 待 RETIRE / 有 consumer 但缺定义」；不重构键值引擎（`Setting::get/set/flush/allCached` 保持不变），不把 Site 配置塞回 Setting，不处理 #86。
> 基线：HEAD `3348c92`（checkpoint-admin-17E），766 tests / 3633 assertions / 0 / 0。

## 1. 核心产品化缺口（fresh install 七组全空）

- 设置项的「字段定义 + 默认值」此前只存在于 `database/seeders/SettingSeeder.php`，而该 Seeder
  **仅由 `DemoSeeder`（演示 / 开发 / 测试）装载，`geo:install` 不调用**。
- 后台设置页 `SettingController::index()` 按 `settings` 表的实际行渲染，且表单没有「新建字段」入口。
- 结论：**全新生产安装（`geo:install` 不 seed demo）后 `settings` 表为空，后台七个分组全部是空表单，
  管理员无法配置任何站点设置。**
- 处置：新增产品级 `DefaultSettingSeeder`（中性默认，由 `geo:install` 调用）；`SettingSeeder`
  改为「先装载产品默认，再覆盖演示行业示例」。键值引擎与缓存机制不变。

## 2. 逐 key 盘点矩阵

scope 均为 **per-site**（Setting `use BelongsToSite`，唯一键 `(site_id,key)`，缓存键含 site_id）。

### 2.1 general 基础信息

| key | type | 产品默认 | validation | 真实 consumer | 处置 |
|---|---|---|---|---|---|
| `site_name` | text | `config('app.name')` = GEO Website OS | 必填(可空回退) | layout logo/alt/title、home、`SchemaBuilder`、`SeoHeadComposer` suffix/og_site_name | 保留 |
| `site_description` | textarea | `''` | — | home hero/capabilities、`SchemaBuilder` description | 保留 |
| `icp_number` | text | `''` | — | footer 备案、`DashboardController` 待办 | 保留 |
| `police_number` | text | `''` | — | **本阶段补 consumer**：footer 公安备案（与 ICP 并列） | 补 consumer |
| `nav_cta_text` | text | `''` | — | `AppServiceProvider` 共享 `$ctaText`（空回退 `config('copy.nav.cta')`） | 保留 |
| `site_short_name` | text | — | — | **无 consumer** | **RETIRE** |
| `site_slogan` | text | — | — | **无 consumer**（首页副标题用 `site_description`） | **RETIRE** |

> 站点名 / 简介同时存在于 Site 租户根（name）与 Setting（展示层默认）。`SchemaBuilder` 已有
> `setting('site_name') ?: site.name` 回退。本阶段不迁移数据源（避免双源大改，登记后续厘清），
> 仅保证 Setting 是「展示层可覆盖默认」，Site.name 是租户标识。

### 2.2 theme 主题样式（11 键，全部保留）

| key | type | 产品默认 | consumer |
|---|---|---|---|
| `theme_primary` | color | `#2563EB` | layout `:root --brand` |
| `theme_primary_dark` | color | `#1D4ED8` | `--brand-dark`（悬停） |
| `theme_accent` | color | `#0E9F6E` | `--accent` |
| `theme_bg` | color | `#F8FAFC` | `--bg` |
| `theme_surface` | color | `#FFFFFF` | `--surface` |
| `theme_text` | color | `#1F2937` | `--ink` |
| `theme_text_muted` | color | `#6B7280` | `--ink-muted` |
| `theme_radius` | number | `10` | `--radius`（0–48） |
| `theme_container` | number | `1200` | `--container`（800–2400） |
| `theme_font` | text | `''`（系统字体栈） | layout body font-family |
| `theme_custom_css` | textarea | `''` | layout 末尾 `<style>`（高级，仅管理员） |

validation：color 必须为 `#rrggbb`；radius/container 为非负整数且在合理区间。

### 2.3 contact 联系方式（7 键，全部保留，默认空 → 前台按空值整块隐藏）

| key | type | validation | consumer |
|---|---|---|---|
| `contact_phone` | text | 可选字符串 | footer / 错误页 / content / category / search CTA、`SchemaBuilder` telephone |
| `contact_mobile` | text | — | footer、contact 页 |
| `contact_email` | text | email 格式 | contact 页、`SchemaBuilder`、`DashboardController` 待办 |
| `contact_address` | text | — | footer、contact 页、`SchemaBuilder` streetAddress |
| `contact_hours` | text | — | contact 页 |
| `contact_map_url` | text | URL | contact 页「查看地图」 |
| `contact_wechat_qr` | image | 图片 | footer、contact 页二维码 |

### 2.4 seo SEO 默认（4 保留 + 1 补定义）

| key | type | 产品默认 | consumer | 处置 |
|---|---|---|---|---|
| `seo_title_suffix` | text | app.name | `SeoHeadComposer` title suffix | 保留 |
| `seo_default_desc` | textarea | `''` | `SeoHeadComposer` description 回退 | 保留 |
| `seo_og_image` | image | `''` | `SeoHeadComposer` og:image 回退 | 保留 |
| `seo_robots_extra` | textarea | `''` | **本阶段补 consumer**：`FeedController::robots()`（setting 优先，`config('geo.robots_extra')` 回退） | 补 consumer |
| `seo_head_code` | textarea | `''`（**原缺定义**） | layout `<head>` `{!! !!}` 输出（已被消费但无设置行） | **补定义**（高级项，默认空，hint 警示） |

### 2.5 geo GEO 默认（5 保留）

| key | type | 产品默认 | consumer | 处置 |
|---|---|---|---|---|
| `geo_org_name` | text | app.name | `SchemaBuilder` Organization name | 保留 |
| `geo_org_en_name` | text | `''` | `SchemaBuilder` alternateName（非空才输出） | 保留 |
| `geo_org_logo` | image | `''` | `SchemaBuilder` logo、layout logo | 保留 |
| `geo_llms_enabled` | bool | `1` | **本阶段补 consumer**：`FeedController::llms()` 门禁，关闭返回 404 | 补 consumer |
| `geo_sitemap_enabled` | bool | `1` | **本阶段补 consumer**：`FeedController::sitemap()` 门禁，关闭返回 404 | 补 consumer |

### 2.6 copy 文案话术（28 键，全部保留，产品默认空 → Copy 层回退中性 config）

- 底部 CTA 6：`copy_bcta_title/desc/primary/secondary/factory_primary/factory_secondary`
  （`Copy::bcta()` → `site/_bottom_cta.blade.php`，factory 变体用于工厂页）
- 全局咨询表单 17：`copy_form_name_{label,placeholder,error}`、`copy_form_phone_{label,placeholder,error,invalid}`、
  `copy_form_type_{label,placeholder,error,options}`、`copy_form_note_{label,placeholder}`、
  `copy_form_{submit,submitting,privacy,success}`（`Copy::form()` → `InquiryController` + `_lead_form.blade.php`）
- 404 四件套 4：`copy_404_{title,desc,primary,secondary}`（`Copy::error404()` → `errors/404.blade.php`）
- 页脚 slogan 1：`copy_footer_slogan`（`Copy::footerSlogan()` → layout footer）

零配置中性默认在 `config/copy.php` / `Copy.php` 内置兜底；本阶段把其中**制造垂直**的零配置默认
（试样 / 报价 / 工厂参观 / 装备制造等客户类型）通用化为行业中性占位。导航菜单 / 页脚链接结构
（`config('copy.nav.menu')`、`footer.columns`、`factBlock`）属 Menu / Structure / Facts 层，
**不在 Settings 范围**，登记 17G / §6.7 处理。

### 2.7 sync GEOFlow 对接（3 保留含 1 补定义 + 2 RETIRE）

| key | type | 产品默认 | consumer | 处置 |
|---|---|---|---|---|
| `sync_geoflow_enabled` | bool | `0` | `GeoflowSync::upsert` 总开关、`HealthController`、Admin `GeoController` | 保留 |
| `sync_geoflow_token` | text | `''` | `VerifyGeoflowToken` 中间件 | 保留；**重新生成前缀 `yhf_` → `gwos_`** |
| `sync_auto_publish` | bool | `0`（**原缺定义**） | `GeoflowSync` line「门禁通过后自动发布 / 进草稿」 | **补定义** |
| `sync_geoflow_endpoint` | text | — | 主动拉取（pull）**未实现，无 consumer** | **RETIRE** |
| `sync_pull_enabled` | bool | — | 仅 `HealthController` 状态展示，pull 功能未实现 | **RETIRE**（UI 隐藏；缺失时健康检查恒为 pull=false，语义正确） |

GEOFlow「接收推送（inbound）」是完整功能（upsert / check / unpublish / status + token 校验 +
sync_logs + ContentGate），保留并通用化；「主动拉取（outbound pull）」未实现，其两个键 RETIRE。

## 3. RETIRE 清单（不新增 UI、不删 migration / 不删历史 DB 行）

| key | 原组 | RETIRE 原因 |
|---|---|---|
| `site_short_name` | general | 全仓无 consumer |
| `site_slogan` | general | 全仓无 consumer（首页副标题用 site_description） |
| `sync_geoflow_endpoint` | sync | 主动拉取未实现，无 consumer |
| `sync_pull_enabled` | sync | 主动拉取未实现（仅健康检查展示，缺失即 false） |

机制：`SettingController` 增加 `RETIRED` 清单，`index()` 不渲染、`update()` 不写入（旧库残留行
对日常运营不可见、不可改，但不删除数据 / 不新增删除 migration）；两个 Seeder 不再创建这些键。

## 4. 本阶段变更一览

1. 新增 `DefaultSettingSeeder`（64 个保留键的产品中性默认），`geo:install` 安装末尾调用（幂等）。
2. `SettingSeeder`（demo）改为先调 `DefaultSettingSeeder`，再覆盖工业材料行业演示值；移除 4 个 RETIRE 键。
3. `SettingController`：RETIRE 黑名单（隐藏 / 拒写）；字段级中文校验（color / number / email / URL）；token 前缀 `gwos_`。
4. `FeedController`：sitemap / llms 增加 per-site 开关门禁（关闭 404）；robots 自定义追加读 setting（config 回退）。
5. layout footer：补 `police_number` 公安备案展示。
6. `config/copy.php` / `Copy.php`：copy 零配置兜底文案去制造垂直、行业中性化（保持客户类型 6 项数量等既有契约）。
7. 新增 `SettingsGovernanceTest` 覆盖：fresh 默认装配、七组可编辑保存、RETIRE 隐藏 / 拒写、
   feed 开关、robots 追加、公安备案、token 前缀、字段校验、跨站隔离、权限。

## 5. 明确不在本阶段（登记后续，不扩大范围）

- #86 Public Render Contract / Catalog Read Model（Release Blocker，17G 前后专门处理）。
- `config/copy.php` 的 nav.menu / footer.columns / factBlock / compliance 等 Menu / Structure / Facts
  制造垂直默认（属 §6.7 Blocks / Menus 治理与 17G，非 Settings 七组）。
- category slug 站点唯一 / type 枚举统一 / 外链接线、后台 500 错误页样式、全站 i18n（蓝图行 228「体验一致性」，用户本轮未授权）。
- Site.name 与 `site_name` 展示层双源的最终单一事实源迁移。
- 不新增依赖、不重构键值引擎、不移动 rc1、不配置 remote / push / Release。
