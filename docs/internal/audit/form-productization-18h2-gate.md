# P-STEP 18H-2 — Form / Submission / Inquiry Productization Gate

- **阶段**：P-STEP 18H-2（Final Product Completeness · Operations Product Closure 第二 Gate）
- **基线**：annotated tag `checkpoint-18H-1`（HEAD `3964d17`）
- **本 Gate tag**：`checkpoint-18H-2`
- **日期**：2026-09-24
- **纪律**：不移动 `v1.0.0-rc1`（`965d63c` HOLD）；不配置 remote、不 push、不 Release；18H-2 收口后 STOP，不自动进入 18H-3。

---

## 1. 目标与范围

把唯一写死制造业表单（固定 name / phone / company / demand_type / monthly_use / message）产品化为：

```
Form
 └─ FormField[]            （每 locale 一行；结构列跨语言一致，展示列按 locale 翻译）
      ↓
FormSubmission            （payload JSON 全量、完整事实源；site / locale / attribution / status）
      ↓
InquiryProjector          （确定性业务投影 → Inquiry；向后兼容；不丢原始 payload）
      ↓
Notification（可选 email；默认关闭；DB 事务 commit 后发送；失败只 warning、不丢数据）
```

**产品化判定**：管理员不修改 PHP / Blade / JS / CSS，仅经 Admin + Form Manager 即可创建并发布字段结构完全不同的 Contact / Demo Request / Download / Appointment 表单，并在前台提交、落库、投影、追溯。

---

## 2. 架构裁定落地（用户 D1–D6）

| 裁定 | 内容 | 落地 |
| --- | --- | --- |
| **D1 = A** | Inquiry 是业务投影，FormSubmission.payload 为完整事实源、保存全量原始数据不丢字段；Inquiry 增 `submission_id` / `form_id` / `email`；不得让 Inquiry 成为 Form 事实源 | `FormSubmissionService` + `InquiryProjector`；迁移 000018 给 inquiries 加链接列；payload 全字段落库，未映射字段（如 doc_type）进 message，不丢弃 |
| **D2 = A** | FormField 每 Locale 一行（对齐 Content / Entity）；结构列（type / name / required / validation）跨语言同步，展示列（label / placeholder / help / options）按 locale 翻译；以 (form_id,name) 标识，不生成 phone_en | `FormController::storeField/updateField` 为每个 supported locale 建结构相同行；字段管理表单写 `translations[locale][...]` |
| **D3 = 接受** | 安装幂等创建中性 Default Contact Form（name / phone / email / message，禁 OEM / 原料采购 / 经销）；`/inquiry` 保留为兼容入口、内部统一进 FormSubmissionService；Blank 可有默认 contact，但是否公开由现有门禁决定、不因建 Form 自动生成公开页 | `DefaultFormSeeder`（幂等）；`InquiryController` 重写为兼容桥接；form 提交路由无 company 门禁、POST 在 blank 可用，但无公开表单页 |
| **D4（明确化）** | 收件人只两层：`Form.notification_recipients`（每表单可不同）→ 未配置 → Site 级 setting `notification_recipients` → 仍无 → **不发送**；不允许 config / Controller 硬编码邮箱。强制 DB transaction（Submission + Inquiry）→ commit → Notification；通知失败不回滚已保存提交 | `FormNotificationSender`；`Form::splitRecipients/recipientList`；service 内 `DB::transaction` 提交后才 `$notifier->send()`，失败 `Log::warning` 不抛 |
| **D5 = 接受** | 后台「表单管理」含 Forms / Fields / Locale / Preview / Publish·Enable / Submissions（只读）；原「客户留言」只展示 Inquiry 投影；可追踪 Inquiry → Submission → Form | `FormController` + admin/forms 四视图；layout 导航「表单管理」；Inquiry 模型加 submission / form 关联 |
| **D6 = 是** | Demo Contact 去行业化，Demo 不再含代工合作 / 原料采购 / 经销代理 / OEM（示例企业 / 产品 / 服务可保留，系统默认能力不携带制造业假设） | #258：SettingSeeder / DemoSeeder / FactSeeder / StructureSeeder / ContentSeeder 全面去行业化；FAQ slug `oem-cooperation-faq` → `customized-solutions-faq` |

其他强制要求均落地：payload 渲染所有用户输入正确 escaping（防 Stored XSS）；Field Registry 统一 11 类型，每类有 HTML rendering + server validation（最终裁决）+ client validation（辅助）+ normalization；Submission.locale 按提交页面（zh→zh-CN、/en→en），不靠浏览器语言猜测。

---

## 3. 交付物

**迁移**
- `2026_09_24_000017_create_form_tables.php`：forms / form_fields / form_submissions
- `2026_09_24_000018_add_form_links_to_inquiries.php`：inquiries 加 submission_id / form_id / email

**模型**：`app/Models/Form.php`、`FormField.php`、`FormSubmission.php`；`Inquiry.php`（加关联、删除 TYPES 常量）

**领域服务**：`app/Support/Forms/FieldTypeRegistry.php`、`FormResolver.php`、`FormSubmissionService.php`、`InquiryProjector.php`、`FormNotificationSender.php`；`app/Mail/FormSubmissionMail.php`

**配置 / 视图**：`config/forms.php`；`resources/views/site/dynamic_form.blade.php`、`site/partials/form_field.blade.php`、`site/blocks/form_reference.blade.php`、`mail/form-submission.blade.php`；admin/forms 四视图（index / form / field / submissions）

**控制器 / 路由**：`Site/FormSubmissionController.php`；`Admin/FormController.php`；`Site/InquiryController`（兼容桥接）；`routes/web.php`（forms.submit zh/en + 全局 `localized_route()`）、`routes/admin.php`（forms 全套）

**安装 / 种子**：`DefaultFormSeeder.php`；GeoInstall / GeoUpgrade 接线；DatabaseSeeder 补 DefaultFormSeeder

**翻译**：新建 `lang/en/validation.php`、`lang/zh-CN/validation.php`

**Demo 中性化（#258）**：SettingSeeder / DemoSeeder / FactSeeder / StructureSeeder / ContentSeeder

**删除**：`resources/views/site/_lead_form.blade.php`

**测试**：`FormProductization18H2Test.php`（新增）；更新 InquiryTest / CopySettingsTest / PageCacheTest / HomeBuilderTest / ExampleDatasetIntegrityTest / PageComposition18GTest

**文档**：`form-productization-discovery-18h2.md`、`form-productization-architecture-18h2.md`、本 Gate 文档；更新 `technical-debt-registry.md`

---

## 4. 验证证据

### 4.1 Focused

```
PageComposition18GTest      26 passed
FormProductization18H2Test  21 passed
Tests: 47 passed (166 assertions) / 0 failed / Duration 77.17s
```

含 TD-86 防回归（`test_edit_block_content_reflects_in_frontend` 断言编辑表单含 `name="_method" value="PUT"`）；拦截类用例（consent / select 白名单 / required / email）全部覆盖。

### 4.2 Full Regression

```
Tests:    987 passed (5067 assertions) / 0 failed / 0 skipped
Duration: 840.54s / exit 0
```

证据 `D:\Temp\18h2\full-regression-3.txt`。较修复前（987 / 5065）净增 2 assertions（TD-86 block PUT 防回归断言），无新增 / 删除测试。

### 4.3 Fresh Install

- `geo:install -n` 幂等创建中性 Default Contact Form：zh-CN / en 各 4 行（name / phone / email / message），无 OEM / 原料采购 / 经销字段。
- 删除 Inquiry TYPES 写死中文；contact 表单字段、验证、成功提示全部走 Form / 语言包。

### 4.4 Blank / Demo HTTP（curl 状态码）

| 路径 | demo :8140 | blank :8141 |
| --- | --- | --- |
| `/` | 200 | 200 |
| `/en`（`/en/`） | 200 | 404（出厂 zh-only） |
| `/products/` `/solutions/` | 200 | 404（company 门禁） |
| `/knowledge/` | 200 | 200 |
| `/contact/` | 200 | 404（company 门禁） |
| `/factory/` `/cooperation/` | 200 | 404 |
| `/about`（`/about/`） | 404（正常，仅 about/profile·history·culture 子页落地） | 404 |
| sitemap / llms / robots / feed / geo | 200 | 200 |

### 4.5 Browser UAT（真实 Chrome，console = 0）

- **四组合**：zh-light / zh-dark / en-light / en-dark 全过；外观与 locale 互不干扰、刷新保持。
- **中文 contact（张三，sub#1）**：中性字段提交，redirect 回 `/contact/`，中文成功提示；payload 4 字段完整、inq#1 投影正确。
- **英文 contact（Jane Doe，sub#3）**：修复 TD-85 后 action 含 `/en`，提交 locale=en、英文成功提示「Received. We will contact you shortly.」、inq#3 关联正确。
- **零代码 Download 表单 + Landing**：Admin 创建 form id=2（5 字段 × zh/en = 10 行：name / email / company / doc_type(select) / consent(checkbox)），新建 Landing Page id=19（slug `download-center`）+ block 41（form_reference，form_id=2）；修复 TD-86 后前台 `/download-center` 正确渲染 Download 字段（action=/forms/download/submit）。
- **李四提交（sub#4）**：payload=`{"name":"李四","email":"lisi@example.com","company":"测试科技有限公司","doc_type":"产品手册","consent":"1"}`（全字段不丢）；inq#4 投影正确、doc_type 拼进 message；自定义成功提示「资料链接已发送，请查收。」。
- **英文 Download Landing `/en/download-center` = 404**：page 19 仅中文、无英文版本，符合 18F Missing Translation Policy（不 fallback）；英文 Download 提交由自动化测试覆盖。

### 4.6 Multi-Site × Locale

- `test_forms_are_isolated_across_sites`：Site A / Site B 表单与提交不串站（跨站 count 用 `withoutGlobalScopes`）。
- en-only Site B：中文未启用 404、英文 200，sitemap / Search / Schema / GEO 不串 locale、不串站。

### 4.7 SEO / GEO / Schema / Sitemap

- 表单所在页面 title / description / canonical / OG / hreflang / Schema `inLanguage` 随 locale 正确；英文表单 action / 提交链路不回中文（TD-85）。
- FormSubmission 为内部数据，不进入 sitemap / feed / Schema；公开页面契约不变。

### 4.8 Cache

- block / 页面级失效：修改 block 内容、排序、隐藏仅失效该页（`test_block_edit_invalidates_only_that_page` / `test_other_page_cache_is_not_invalidated`）。
- PageCache key 含 locale，zh / en 不串页；contact / Download 提交为 POST 不命中整页缓存。

### 4.9 Runtime pollution / Log audit

- Runtime business pollution = 0；blank 首页实测无配方定制 / OEM / 车间 / 代工等词。
- 验证窗口 storage/logs 无修复后新增产品 ERROR（仅历史 / 命令构造类，已排除）。

### 4.10 Smoke cleanup

- 临时 DB（demo-gate / blank-gate.sqlite）、两个 serve（:8140 / :8141）、cookie / cache / process / port 在 Gate 后清理；storage/framework 只清内容、保留 `.gitignore`。

### 4.11 Git

- `git diff --check`、`git status` → commit + annotated tag `checkpoint-18H-2`；验证 `git cat-file -t` = tag、与 HEAD 对齐；worktree clean。

---

## 5. TD 裁定

| ID | 问题 | 裁定 |
| --- | --- | --- |
| **TD-59** | 完整 Form Builder 缺失 | **CLOSED by 18H-2** |
| **TD-77** | Form Submission 层缺失 | **CLOSED by 18H-2** |
| **TD-82** | payload 双重编码 | **CLOSED by 18H-2**（service 传 array，cast 单次编码） |
| **TD-83** | admin.forms.index 路由缺失 | **CLOSED by 18H-2** |
| **TD-84** | validation.php 翻译缺失 | **CLOSED by 18H-2** |
| **TD-85** | 英文 form action 缺 /en | **CLOSED by 18H-2**（localized_route，Jane Doe 验证） |
| **TD-86** | block_form 缺 @method PUT 致 405 | **CLOSED by 18H-2**（修复 + 防回归，李四验证） |
| **TD-87** | Core migration 000011 静态制造业字符串、运行时不可达 | **DEFERRED v1.1**（随 TD-70） |

---

## 6. 两个边界裁定

1. **config/facts.php（cooperation OEM / ODM、经销合作、business_model）**：属配合**旧首页装修器**的 Example **demo 数据集**。D6 明确允许 demo 保留示例企业 / 产品 / 服务内容；实测 blank 不消费 facts.php（blank 首页无制造业词）。裁定 **Example demo 正当内容（NON-DEBT / 设计如此）**；其代码层 demo 事实随 **TD-70**（首页旧装修器 v1.1 迁 Page Composition）一并收口，不单独登记。
2. **migration 2026_09_14_000011（seed_home_builder_blocks）**：Core 迁移层默认 items 含制造业静态字符串，与其 docblock「Core 仅通用缺省文案」声明不符；但**运行时不可达**（blank 首页实测干净、demo 被 StructureSeeder content=null 覆盖走 config/facts）。裁定登记 **TD-87 DEFERRED v1.1**，随 TD-70 迁移时中性化 / 退场，本阶段（Form）不展开，避免范围蔓延。

---

## 7. Gate 判定

| 项 | 结果 |
| --- | --- |
| Focused | ✅ 47 / 166 / 0 |
| Full Regression | ✅ 987 / 5067 / 0 |
| Fresh Install | ✅ |
| Blank / Demo HTTP | ✅ |
| Browser UAT（四组合 + contact 中英 + Download） | ✅ console 0 |
| Multi-Site × Locale | ✅ |
| SEO / GEO / Schema / Sitemap | ✅ |
| Cache（页面级 + locale） | ✅ |
| Runtime pollution | ✅ 0 |
| Log audit | ✅ 无新增产品 ERROR |
| Smoke cleanup | ✅ |
| Git（commit + annotated tag + worktree clean） | ✅ |

**P-STEP 18H-2 = ACCEPTED / PASS**，收口 STOP；不自动进入 18H-3，不碰 remote / push / RC / Release。
