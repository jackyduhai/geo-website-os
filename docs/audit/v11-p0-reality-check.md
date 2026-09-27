# v1.1 P0 Reality Check — Gap Matrix / 探查结论 vs 权威仓实况

- **日期**：2026-09-27
- **权威工作仓**：`D:\GEO-OS-rewrite\geo-website-os`（分支 main，HEAD `c16162c`）
- **触发**：一份"对照真实代码"的探查报告称 Gap Matrix 多处与代码不符（540 测试 / 22 表 / 无 locale / 无 Form / 无 Page）。经在权威仓独立核验，**该探查真假参半，含若干致命事实错误，不能整份采信**。
- **用途**：作为 v1.1 P0 各能力 Discovery 的**唯一事实基线**；凡与本文冲突的现状描述，以本文（含 file:line 证据）为准。
- **纪律**：本文为只读核对，不改代码；remote / push / release / rc1 继续 HOLD；未授权不启动开发。

> 附注：会话目录 `D:\73466\GEO官网` 仅为文档目录（无 .git、无 migrations），非代码库；旧副本 `D:\73466\Demo Tenant A官网\demo-tenant-a-site` 永久 ignored。探查报告的错误口径与某个 **2026-09-24 之前的旧快照**（约 22 表 / 540 测试 / 无 Page / 无 locale / 无 form 表）高度吻合。

---

## 一、权威仓基线（硬事实）

- 迁移文件 **54** 个；全量回归 **1225 passed / 6534 assertions / 0 failed / 0 skipped**（非探查所称 540）。
- 模型 26 个，含 `Page`、`Form`、`FormField`、`FormSubmission`、`Inquiry`、`ContentRevision`、`AuditLog`、`EntityRelation`、`ContentEntity`、`PageBlock`、`SeoMeta` 等。
- 工作树 clean；rc1 tag = `v1.0.0-rc1 → 965d63c`，未移动；从未 push。

## 二、探查报告逐条裁决

### 2.1 探查说对的（权威仓确认）

| # | 探查主张 | 证据 | 裁决 |
|---|---|---|---|
| 1 | SoftDeletes 仅 Content 一个模型；无回收站 UI / 恢复端点 | `app/Models/Content.php:8,27`（全 Models 仅此处 use SoftDeletes） | ✅ |
| 2 | 定时发布是"半成品"：靠 `scopePublished()` 按 `published_at` 过滤，无 scheduler、无 `unpublish_at`、无 scheduled 状态 | `app/Models/Content.php:119-123` | ✅ |
| 3 | 定时到点无事件触发，整页缓存最长脏 6 小时 | `app/Support/PageCache.php:26` `TTL=21600`；翻转为查询期过滤、无到期事件 | ✅ |
| 4 | 下线语义分裂：Admin unpublish→draft，Geoflow 同步 unpublish→archived | `app/Http/Controllers/Admin/ContentController.php:199-201`（draft）vs `app/Services/Sync/GeoflowSync.php:163-169`（archived） | ✅ |
| 5 | Media 删除无引用检查、物理删除仍被引用文件（现存 bug，非单纯缺口） | `app/Http/Controllers/Admin/MediaController.php:118-124` | ✅ |
| 6 | 访问统计 PV/UV 完全为零 | grep `page_view/trackVisit/pageview/visit_count/recordHit` 于 app+database = 0 命中 | ✅ |
| 7 | Attribution 采集+入库已完整，P0-7 可销项 | `app/Http/Middleware/CaptureAttribution.php`；迁移 `2026_09_14_000009`（landing_url/referer/utm 五项/device_type） | ✅ |
| 8 | 发布可见性 / 准入 / 缓存失效已是单点 | `Content::scopePublished()`、`ContentGate`、模型事件→`PageCache::flush` | ✅ |
| 9 | backup 仅支持 sqlite、backup/rollback 零测试 | TD-168（Release Evidence Gap） | ✅ |

### 2.2 探查说错的（权威仓推翻）

| # | 探查主张 | 权威仓实况（证据） | 裁决 |
|---|---|---|---|
| E1 | "Form / FormField / FormSubmission 不存在，全库 22 表无 form 表，只有硬编码表单" | 三模型俱在：`app/Models/Form.php`、`FormField.php`、`FormSubmission.php`；迁移 `2026_09_24_000017_create_form_tables.php`、`2026_09_24_000018_add_form_links_to_inquiries.php`；后台全套路由 `routes/admin.php:187-201`（表单 CRUD + 字段 CRUD + submissions）；提交服务 `app/Support/Forms/FormSubmissionService.php`；前台 `routes/web.php:108`；`Inquiry belongsTo FormSubmission`（`app/Models/Inquiry.php:24`） | ❌ 错误 |
| E2 | "无 Page 模型，页面 = Content(type=page)" | `app/Models/Page.php` 存在；6 个 page 迁移：`2026_09_23_000010_create_pages_table`、`..._11_add_page_id_and_slot_to_page_blocks`、`..._12_add_page_id_to_seo_metas`、`..._13_add_entity_id_to_pages`、`2026_09_24_000015_add_system_fields_to_pages` 等 | ❌ 错误 |
| E3 | "多语言不成立：无 locale 路由、无翻译列、无 lang 目录、全站硬编码 zh-CN" | 路由：`routes/web.php:128` `Route::prefix('en')->middleware('locale:en')`、`:133` zh-CN 组、SetLocale 中间件、注释 :28-29；模型：`Content` 使用 `Translatable` trait（`Content.php:27`）；迁移 `2026_09_23_000001_add_locale_to_translatables.php`。**出厂默认单语（`site_supported_locales=["zh-CN"]`），/en/* 404 是设计行为，开启 setting 后中英主链完整（18F PASS）** | ❌ 错误 |
| E4 | "540 个测试" | 权威基线 **1225** passed / 6534 assertions | ❌ 错误 |
| E5 | "22 张表" | 54 迁移，表数量显著更多 | ❌ 错误 |

### 2.3 错误一旦被采纳的后果

- 会把**已完成并验收的 18F 中英前台主链**误判为"地基为零、需重建"，错误地把 Translation 降为从零项；
- 会**无视已完整存在的 Form Builder 体系**，错误地把"建表单"当成要不要开头的问题；
- 会把 Page Manager 错误地建在 `Content(type=page)` 上，而真正的 `Page` 模型与 `PageBlock` 已存在。

## 三、修正后的 v1.1 P0 真实清单与批次

原矩阵 P0 七项，按权威仓修正：

- **Attribution（U-7）销项**：first-touch 采集 + 8 字段入库已完成；其"归因聚合/分析"并入 P1 Analytics。
- 其余 6 项保留，重组为 4 个批次：

| 批次 | 能力 | 关键约束 |
|---|---|---|
| 批次 1 | **Publication Lifecycle Contract**（合并 U-1 Lifecycle + U-2 Scheduled + U-3 Trash） | 单一状态机服务，统一 unpublish→draft、到期→archived；lazy 到期结算 + 可选 scheduler 双轨；tick 翻转后显式刷新 PageCache 与 sitemap/geo 聚合缓存 |
| 批次 2 | **Media Usage Graph + Media Library Lite**（U-5，提前） | 单一引用扫描器同时供：删除守卫（修复 destroy bug）、未使用筛选、Trash Purge 引用检查 |
| 批次 3 | **Page Manager**（U-4） | 基于 **Page 模型 + PageBlock**（非 Content type=page）；复制/预览/发布/下线/归档全部走批次 1 Contract |
| 批次 4 | **Inquiry Center 运营闭环**（U-6） | 基于**已有** Form/FormSubmission/Inquiry；只补 spam 状态、可追加备注、按来源/UTM/设备/状态筛选；**不建表单、不做 CRM 指派** |

> 数量上 P0 = 6 个能力（与探查报告"6 项"巧合），但内涵不同：Attribution 销项，同时**保留 Form / Page / 多语言既有事实**，不做错误否认。

## 四、对探查报告"4 个待拍板决策"的意见

1. **定时建模**：同意推荐——新增 `scheduled` 状态、复用 `published_at` 为生效时间、新增 `unpublish_at` 为下线时间。补充：到期 tick 内须显式 `PageCache::flush` 受影响 URL 及 sitemap/geo/llms 聚合输出，不可只依赖 saved 事件。
2. **回收站范围**：同意 v1.1 只做 Content（唯一软删模型）。**前置必查**：Content 软删时 `ContentEntity`（pivot）、`PageBlock`、`EntityRelation` 等外键的 `onDelete` 行为——若被 DB cascade 物理删，Restore 将失败；关系必须保留。
3. **媒体删除策略**：同意——有引用一律阻止并列出引用清单，仅 Trash Purge 在通过扫描后物理清理；同批修复 `MediaController::destroy` 现存 bug。
4. **单页模板**：**前提需修正**——`Page` 模型已存在，模板选择应挂在 **Page** 上，而非"激活 `categories.template`"或给 contents 加列。Discovery 须核对 pages 表是否已有 template 字段，并以 ADR 厘清 Page vs Content 职责边界。

## 五、多语言的正确口径（纠正探查 E3）

- **已完成**：中英文前台主链、SEO/Schema/Sitemap/llms/搜索（18F PASS，开启 en setting 后）。
- **v1.1 真正缺口**：
  - TD-163（P2）：`/en/geo.json` 实体图内容未完全 locale 化；
  - TD-164（P2）：Admin UI 仅中文（Admin i18n）；
  - 内容翻译工作流：源文改动后译文标记 Outdated（属 P1，是"工作流增强"而非"地基重建"）。
- 不应表述为"多语言地基为零"。

## 六、各能力 Discovery 仍须锁死的风险

1. 统一 Lifecycle 用受控状态机 + 归一现有 `status`，**不新增第二套状态字段**；
2. Scheduled 采用 lazy 到期 + 可选 scheduler 双轨（自部署常无 worker）；
3. Trash 软删与 FK cascade 冲突——关系/区块引用须保留以支持 Restore；
4. Attribution 隐私边界——IP/设备属 PII，需脱敏/consent，不引入重统计 SDK；
5. Media Usage Graph 复用现有引用关系反查，不新建引用表。

## 七、状态

- 本文为只读核对产物，未改业务代码，工作树保持 clean。
- **未授权启动任何 v1.1 开发**；等待口令（如「开始 18U-1 Discovery」）。
- remote / push / release / rc1（`v1.0.0-rc1 → 965d63c`）继续 HOLD。
