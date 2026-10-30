# P-STEP 18H-2 — Form / Submission / Inquiry Productization · Discovery

- **阶段**：P-STEP 18H-2（Operations Productization 第二 Gate）
- **方法**：只读审计真实代码 / 迁移 / 视图 / 路由 / 配置 / 测试，不修改产品代码。
- **基线**：HEAD `3964d17`（= tag `checkpoint-18H-1`），Regression 968 / 4962 / 0 / 0，worktree clean。
- **结论**：现状为「**单一固定表单 + 固定列 Inquiry 直写、无 Form / Field / Submission 层、无通知**」。本文件盘点现状与缺口；目标架构与待拍板点见 `form-productization-architecture-18h2.md`。

---

## 1. 数据层现状

### 1.1 `inquiries` 表（两个迁移累积，**固定列**）

迁移 `2026_09_14_000008_create_inquiries_table.php` + `2026_09_14_000009_add_attribution_to_inquiries_table.php`：

| 类别 | 列 |
| --- | --- |
| 业务（固定） | `name`(50)、`phone`(30)、`company`(120,nullable)、`demand_type`(20,默认「其他」)、`monthly_use`(60,nullable)、`message`(text) |
| 归因 | `source_page`、`landing_url`、`referer`、`utm_source` / `utm_medium` / `utm_campaign` / `utm_term` / `utm_content`、`device_type`、`ip`、`user_agent` |
| 管理 | `status`(16,默认 new)、`handle_note`(text,nullable)、`handled_at`(nullable)、timestamps |
| 索引 | index(`status`,`created_at`) |

### 1.2 `Inquiry` 模型（`app/Models/Inquiry.php`）

- `use BelongsToSite`；`$guarded = []`；cast `handled_at` datetime。
- **写死的制造业常量（中文）**：
  - `TYPES = ['代工合作', '原料采购', '经销代理', '其他咨询']`；
  - `STATUS_LABEL`（待跟进/已跟进/已归档）、`DEVICE_LABEL`。
- 静态 `deviceFromUserAgent($ua)`：服务端 UA 解析 mobile/tablet/desktop/bot（不信任前端）。

### 1.3 缺失的模型 / 表

- **无 `forms` 表 / `Form` 模型**；
- **无 `form_fields` 表 / `FormField` 模型**；
- **无 `form_submissions` 表 / `Submission` 模型**；
- Models 清单（AuditLog…User）中不含任何 Form* 对象。

---

## 2. 前台现状

### 2.1 唯一表单：`resources/views/site/_lead_form.blade.php`

- **4 个可见字段写死**：`name*`(text)、`phone*`(tel)、`demand_type*`(select)、`message`(textarea)；
- 蜜罐字段 `website`（`.hp` 隐藏，真人不可见）；
- 7 个隐藏归因字段（landing_url / referer / utm_*），值来自 `$leadAttr`；
- label / placeholder / 选项 / 成功文案来自 `Copy::form()`；
- 内嵌 `<script nonce>` 极简校验：blur 触发 required / phone 正则、提交锁定防重复点击；可完全降级（novalidate，后端兜底）。

### 2.2 FormReference block：`resources/views/site/blocks/form_reference.blade.php`

- block 包装（title / subtitle，独立 form id），随后 **`@include('site._lead_form', ...)`**；
- **不引用任何表单实例 ID**——无论 block 配置如何，渲染的都是同一个全局固定 lead form。

### 2.3 block 注册：`config/blocks.php`（`form_reference`，:268）

- label「咨询表单」、category `form`、icon `phone`、`per_locale=true`；
- fields 仅 `title` / `subtitle`；**无 `form_id`（无法选择引用哪个表单）**。

### 2.4 Contact 页：`app/Http/Controllers/Site/ContactController.php`

- 走 `SystemPageRenderContext`（system_key `contact`）+ CompositionRenderer；
- `SystemPageSeeder` 为 contact 注入两个 main block：`contact_info`(sort 0) + `form_reference`(sort 1)；
- 无 company 数据时 `abort(404)`；附带 LocalBusiness Schema（电话/地址，未核定坐标则省略 geo）。

> 即：全站所有出现表单的位置，最终都汇聚到同一个字段写死的 `_lead_form`。

---

## 3. 提交流程现状

- 路由 `routes/web.php:97`：`POST /inquiry` → `Site/InquiryController::store`，中间件 **`throttle:6,1`**；en 由 locale 组生成 `/en/inquiry`。
- `Site/InquiryController::store`：
  1. 蜜罐 `website` 命中 → 假装成功、**不落库**；
  2. `Copy::form()` 取 customer 选项，`Rule::in(选项 + Inquiry::TYPES)`；
  3. validate 固定 6 业务字段 + 7 归因字段（含 phone 正则、长度上限）；
  4. `message` 为空时用 demand_type 兜底（避免后台空白 / Undefined key 500）；
  5. **`Inquiry::create([...固定结构...])` 直写**；服务端补 source_page / device_type / ip / user_agent；
  6. `back()->with('lead_success', ...)`。
- 归因采集：`app/Http/Middleware/CaptureAttribution.php` 在首次访问写 `attr.*` session（落地页 / 外部 referer / UTM，内部跳转不覆盖首个外部来源）。
- **无 Submission 中间层：提交字段 → 固定 Inquiry 列一一对应，无 payload JSON。**

---

## 4. 后台现状

- `app/Http/Controllers/Admin/InquiryController.php`：
  - `index`：status 过滤 + 分页(20) + 三态 counts；
  - `handle`：更新 status / handle_note / handled_at + `AuditLog::record('inquiry.handle', ...)`；
  - `destroy`：删除 + AuditLog。
- 视图 `resources/views/admin/inquiries/index.blade.php`（跟进表单 + 删除）。
- 侧边导航（`admin/layout.blade.php`）：顶层「客户留言」指向 inquiries；**无「表单管理」入口，无法创建 / 编辑表单或字段。**

---

## 5. 通知 / Mail 现状

- **`app/Mail` 目录不存在**（无任何 Mailable / Notification）；
- `config/mail.php`：`default = env('MAIL_MAILER', 'log')`——默认 mailer 为 **log**（写日志、不真实发送）；
- 表单提交后**无任何邮件 / 通知动作**，也无 notification 开关或收件人配置。

---

## 6. 测试现状

- `tests/Feature/InquiryTest.php`（11 测试）：
  - `validLead()` 写死 `demand_type='代工合作'`、`monthly_use='每月 1 吨'`；
  - 覆盖：有效留言落库、非法 phone 拒绝、message 缺失/空白兜底、蜜罐丢弃、归因 + 设备落库、Capture 中间件首触、UA 解析、后台登录拦截、后台标记 handled。
- 这些测试与固定列 / 行业字段强耦合，18H-2 引入新模型后需同步演进（保持 Inquiry 投影向后兼容）。

---

## 7. 缺口清单（→ 架构契约）

| GAP | 描述 | 对应债务 |
| --- | --- | --- |
| **GAP-1** | 无 Form 模型 / 表：表单的字段集合、必填、验证、选项、成功文案、consent、spam、通知开关无法在后台定义，散落在 blade + controller | TD-59 |
| **GAP-2** | 无 FormField：无法为不同表单配置不同字段集合与排序 | TD-59 |
| **GAP-3** | 无 Submission：提交直写固定 Inquiry 列，无法承载 Download（email+文件）、Appointment（日期）、Demo、Contact 等异构表单；无全量 payload JSON | TD-77 |
| **GAP-4** | Inquiry 不是投影：业务上 Inquiry 应从 Submission 派生（后台列表 / 跟进 / AuditLog 向后兼容），而非提交直写 | TD-77 |
| **GAP-5** | 行业字段写死：`Inquiry::TYPES`（代工/采购/经销）、`demand_type` / `monthly_use` 是 OEM 业务字段，通用产品不应内置 | TD-59 |
| **GAP-6** | 通知能力缺失：需可选 email 通知、**默认关闭**、与数据保存解耦、通知失败不丢数据（进测试） | TD-59 |
| **GAP-7** | FormReference block 不引用具体表单：需按 `form_id` 渲染指定表单；空站 / 无表单时有合理表现 | TD-59 |
| **GAP-8** | 多语言：Form / Field 的 label / placeholder 需按 locale 管理（前台 zh/en），Submission 记录提交 locale | TD-59 |

---

## 8. 现状 → 目标对照（概览）

| 维度 | 现状 | 18H-2 目标 |
| --- | --- | --- |
| 表单定义 | 写死 blade + controller validate | 后台 Form + FormField 结构化配置 |
| 字段类型 | name/phone/select/textarea 固定 | 字段类型 registry（text/textarea/email/tel/number/select/radio/checkbox/date/url/hidden） |
| 提交落点 | 直写固定列 Inquiry | Submission（payload JSON 全量）→ Inquiry 投影 |
| 原始数据 | 仅固定列、无 payload | payload 保存完整结构，不丢字段 |
| 行业假设 | TYPES / demand_type / monthly_use 写死 OEM | 核心零行业字段；行业表单靠 Example / 自建 |
| 通知 | 无 | 可选 email、默认 false、解耦、失败不丢数据 |
| block 引用 | include 固定全局表单 | 按 form_id 引用 |
| 多语言 | 仅 Copy 字典 | 字段 label/placeholder 可双语、Submission 记 locale |
| 表单形态 | 仅 1 种 | Contact / Demo / Download / Appointment 等零代码创建 |

---

## 9. 边界（本阶段不做）

- 不做自由拖拽排版 / 多步 Wizard / 条件分支 / 复杂计算字段（架构不堵死，V1 不实现）；
- 不顺手做 Analytics（TD-73）与完整 Audit 覆盖扩展（TD-74）——留 18H-3；
- 不删历史 migration、不删 Example、不删 docs/audit；不重设计 Core；
- 不移动 rc1、不配 remote / push、不 Release。

**下一步**：见 `form-productization-architecture-18h2.md`（目标架构 + 待拍板决策点 + 实施任务清单）。Discovery 完成后 STOP，待裁定再进入实现。
