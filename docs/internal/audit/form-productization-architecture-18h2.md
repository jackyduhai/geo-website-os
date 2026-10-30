# P-STEP 18H-2 — Form / Submission / Inquiry Productization · Architecture Contract

- **配套**：`form-productization-discovery-18h2.md`（现状与缺口）。
- **目标**：建立 `Form → FormField[] → Submission → Inquiry(投影) → Notification(可选)` 的产品化表单体系；管理员**不改 PHP / Blade / JS** 即可创建并发布 Contact / Demo / Download / Appointment 等异构表单。
- **基线**：HEAD `3964d17`，worktree clean。本文件为契约，待关键决策点拍板后进入实现。

---

## 1. 分层与事实源

```
Site（站点）
  └─ Form（表单定义：结构 + 行为开关，site-scoped）
       ├─ FormField[]（字段：当前 locale 的 label/placeholder/options）
      ...
Public Request（POST 提交）
  └─ FormSubmissionService
       ├─ 1. DB 事务：FormSubmission（payload JSON = 完整事实）
       │                └─ InquiryProjector → Inquiry（best-effort 投影，向后兼容）
       └─ 2. 事务外：Notification（可选，失败仅 warning，不影响数据 / 响应）
```

- **FormSubmission 是完整事实源**：payload JSON 保存全部业务字段；
- **Inquiry 是派生投影**：只填可识别固定列，绝不反向成为事实源、不丢 payload；
- Form / Field 仅描述「数据结构 + 验证 + 提交行为」；**视觉由 Theme + Component + FormReference block 负责**；
- 不存储任意 Blade / HTML / PHP：字段值为结构化数据，HTML 一律由注册 renderer 生成并转义。

---

## 2. 数据模型

### 2.1 `forms`

| 列 | 类型 | 说明 |
| --- | --- | --- |
| id | id | |
| site_id | FK | BelongsToSite |
| name | string(120) | 管理用名称 |
| slug | string(120) | 站点内标识；**unique(site_id,slug)** |
| title | string(160), nullable | 表单标题（block 标题可覆盖） |
| success_message | string(400), nullable | 成功文案；空 → 字典 `form_success` |
| status | string(16), default `enabled` | enabled / disabled |
| consent_required | bool, default 0 | 是否需同意条款 |
| honeypot_enabled | bool, default 1 | 蜜罐开关 |
| notification_enabled | bool, default **0** | 通知总开关（默认关） |
| notification_channels | string(60), default `email` | V1 仅 email |
| notification_emails | string(255), nullable | 收件人（逗号分隔）；空 → 站点管理员 |
| timestamps | | |

### 2.2 `form_fields`（每字段每 locale 一行，对齐 Content/Entity 翻译模型）

| 列 | 类型 | 说明 |
| --- | --- | --- |
| id | id | |
| form_id | FK cascade | |
| site_id | FK | 隔离 / 清理 |
| type | string(20) | 字段类型 registry（§3） |
| name | string(80) | 字段键；逻辑组 **(form_id,name)** |
| label | string(160), nullable | 当前 locale 文案 |
| placeholder | string(160), nullable | |
| help_text | string(255), nullable | |
| required | bool, default 0 | |
| validation | string(160), nullable | 附加规则（如 `max:200`） |
| options | text, nullable | select/radio/checkbox 选项（JSON/行） |
| locale | string(12) | zh-CN / en |
| sort_order | int, default 0 | |
| timestamps | | |
| 索引 | | index(form_id,locale,sort_order) |

> 结构列（type/name/required/validation）在各 locale 行保持一致；label/placeholder/help/options 按 locale 翻译。后台以字段为单位、locale Tabs 编辑。

### 2.3 `form_submissions`

| 列 | 类型 | 说明 |
| --- | --- | --- |
| id | id | |
| form_id | FK | |
| site_id | FK | |
| locale | string(12) | 提交语言 |
| payload | json / text | **全量业务字段（完整事实）** |
| source_page | string(255), nullable | 提交所在页 |
| landing_url / referer | string(255), nullable | 归因 |
| utm_source/medium/campaign/term/content | string | 归因（沿用 inquiries 口径） |
| device_type | string(16), nullable | 服务端 UA 解析 |
| ip | string(45), nullable | |
| user_agent | string(255), nullable | |
| status | string(16), default `new` | new/read/archived |
| inquiry_id | FK nullable | 投影出的 Inquiry |
| timestamps | | |
| 索引 | | index(form_id,created_at)、index(site_id,status,created_at) |

### 2.4 `inquiries` 改造（向后兼容）

- 新增 `submission_id` FK nullable（一个 submission 至多一条投影）、`form_id` FK nullable；
- **保留全部现有列**（name/phone/company/demand_type/monthly_use/message + 归因 + 跟进列）与后台跟进 UI / AuditLog；
- 投影规则（确定性，§4.2）：payload 按字段 name 约定映射；映射不到的列留空 / 默认，**全量以 submission.payload 为准，不丢数据**；
- `demand_type` / `monthly_use` 在通用表单无对应值时给默认 / 空（不再强制 OEM 语义）。

> 迁移编号从 `2026_09_24_000017` 起：建议 000017 建 forms/form_fields/form_submissions，000018 给 inquiries 加关联列。

---

## 3. 字段类型 Registry

`app/Support/Forms/FieldTypeRegistry.php`（+ `config/forms.php`）：

| type | 渲染 | 基础规则 |
| --- | --- | --- |
| text | input text | string |
| textarea | textarea | string |
| email | input email | email |
| tel | input tel | string（电话正则由字段 validation 承载，不写死核心） |
| number | input number | numeric |
| select | select + options | Rule::in(options) |
| radio | radio group + options | Rule::in(options) |
| checkbox | checkbox（单=accepted / 多=array） | bool/array + options |
| date | input date | date |
| url | input url | url |
| hidden | hidden | string |

- 每类型注册「渲染 partial + 基础规则」；前台一个 `dynamic_form` 视图按类型 switch（受控、注册式）；
- 不做自由排版 / 多步 Wizard / 条件分支 / 计算字段（接口不堵死，V1 不实现）。

---

## 4. 提交流程

### 4.1 路由与入口

- 通用路由：`POST /forms/{form:slug}/submit`（name `forms.submit`，`throttle:6,1`；locale 组生成 `/en/forms/...`）；
- **兼容保留** `POST /inquiry`（name `inquiry.store`）：解析站点默认 contact form，走同一 service；旧引用 / 外部链接不破；
- disabled / 跨站 / 缺失表单 → 404（不泄露存在性）。

### 4.2 `FormSubmissionService::record()`

1. **蜜罐**（字段名可配，默认 `website`；可开关）命中 → 静默返回成功、不落库；
2. 按当前 locale 的 form_fields **动态构建规则**：类型基础规则 + required + validation + options in；错误属性名用字段 label；
3. **DB 事务**：
   - `FormSubmission::create()`：payload = 验证后业务字段 JSON；+ 归因（landing/referer/utm，复用 CaptureAttribution）+ source_page/device_type（服务端 UA）/ip/user_agent + locale + status=new；
   - `InquiryProjector::project(submission)`：
     - name←payload.name，phone←payload.phone/tel，company←payload.company，
       email←payload.email，message←payload.message（空则用其余文本字段确定性摘要拼接）；
       demand_type/monthly_use 无值→默认；
     - 创建 Inquiry（写 submission_id/form_id），回填 submission.inquiry_id；
4. 事务提交后（事务**外**）执行通知（§5）；
5. 返回 back / 表单 redirect，flash `success_message`（空走字典）。

### 4.3 关键纪律

- 数据写入与通知**解耦**：先确保 Submission + Inquiry 落库并提交；
- 控制器 / 视图不自行实现第二套校验或 SEO；动态规则是唯一校验来源；
- payload / 选项 / 归因全部限长、转义，无任意 HTML / 代码执行入口。

---

## 5. Notification（V1：email，默认关闭）

- `FormSubmissionMail`（Mailable）：安全渲染 payload（表格化、转义）；
- 开关 `notification_enabled`（默认 **false**）；Fresh install 无 SMTP 不触发任何 mail failure；
- 默认 mailer `log`（`config/mail.php`），未配 SMTP 时仅写日志；
- 收件人：`notification_emails` ?? 站点管理员邮箱 ?? config；
- 发送在 try/catch 内：失败 `Log::warning`，**不影响已提交事务与响应**；
- **防回归测试（强制）**：令 Mail 抛异常，断言 Submission / Inquiry 仍落库、响应正常成功、无数据丢失。

---

## 6. FormReference Block 改造

- `config/blocks.php` form_reference 增加字段 **`form_id`**（select 站点 enabled 表单；空 = 默认 contact form）；
- block blade：解析 form_id → 渲染 `dynamic_form`（字段来自当前 locale form_fields）；表单缺失 / disabled → 不渲染表单区（不白屏、不报错）；
- 保留 block title/subtitle（可覆盖 form.title）；
- 每个 block 独立 form id，避免同页多表单冲突。

---

## 7. 默认 Contact Form 与初始化

- 新增 `DefaultFormSeeder`（**幂等**，`geo:install` 调用，类似 SystemPageSeeder）：
  - 创建 slug `contact` 的中性默认表单，字段：**name / phone（或 email）/ message**；
  - **不含 demand_type / monthly_use / 代工·采购·经销等 OEM 字段**；
  - blank（出厂 zh-only）建 zh-CN 字段；demo（zh+en）补 en 字段；
- contact 页是否公开仍由现有 **company 门禁**控制（无 company → 404），表单存在与否不改变该门禁；
- 原固定表单的行业字段与 `Inquiry::TYPES` 从核心默认移除（§9）；行业询盘表单若需要，由 Example / 用户自建，不进 Core。

---

## 8. 多语言

- form_fields 按 locale 行；渲染当前 locale，结构共享；
- 默认 contact form：blank 仅 zh-CN；demo 双语齐全；
- FormSubmission 记录提交 locale；payload 不跨语言混显；
- 自定义字段的翻译由管理员负责；某 locale 缺字段行时不渲染该字段（UI 结构可回退、不把正文静默翻成另一语言）；
- success_message 留空时走当前 locale 字典。

---

## 9. 旧行业写死清理

- 删除 / 中性化 `Inquiry::TYPES`（代工合作/原料采购/经销代理/其他咨询）；
- 旧 `Site/InquiryController` 的固定 6 字段 validate 与 Copy+TYPES merge：迁移为默认 contact form → 统一 service（控制器可改造为兼容桥接或退休）；
- `_lead_form.blade.php`：由 `dynamic_form` 渲染取代（可保留为默认 contact form 的 include 别名，内部动态）；
- 清理后确保无残留 `Rule::in(Inquiry::TYPES)` / 行业正则写死；电话正则从核心移入字段 validation（默认 contact form 可携带）。

---

## 10. 后台 Form Manager

- `Admin/FormController`：index / create / edit / store / update / destroy / duplicate / toggle；
- 表单编辑页：Form 属性 + 字段管理（add / edit / reorder up·down / delete / required / type），**locale Tabs（中文/English，标注完成度）**；
- submissions 查看：表单详情 / submissions 子页只读展示 payload + 归因 + status；
- 「客户留言」保持 Inquiry 投影列表（跟进 UI 不变，显示来源表单）；
- 侧边导航（`admin/layout.blade.php`）顶层「组合页面 / Landing」附近新增 **「表单管理」**；
- 关键操作写 AuditLog（form.save / field.* / submission 状态）。

---

## 11. 待拍板决策点（推荐已标注）

| # | 决策 | 推荐 |
| --- | --- | --- | --- |
| D1 | Inquiry 与 Submission 关系 | **A：保留 inquiries 作投影（+submission_id/form_id），向后兼容；Submission 为完整事实**（授权已倾向） |
| D2 | 字段多语言模型 | **A：form_fields 每 locale 行（与 Content/Entity 一致）** |
| D3 | 默认 contact form / 旧路由 | **安装幂等建中性 contact form（name/phone/email/message，无 OEM 字段）；旧 POST /inquiry 作兼容路由走新 service；blank 也建表单，公开仍由 company 门禁** |
| D4 | 通知收件人 | **notification_emails ?? 站点管理员 ?? config；默认关闭；mailer 默认 log** |
| D5 | 后台 submissions 入口 | **「表单管理」含 CRUD + 字段 + 该表单 submissions（payload 只读）；「客户留言」保持投影列表** |
| D6 | demo contact 是否去行业化 | **是：默认表单中性化后 demo contact 不再出现代工/采购下拉；行业表单靠 Example** |

---

## 12. 实施任务清单

| ID | 任务 |
| --- | --- |
| 18H2-01 | 迁移：forms / form_fields / form_submissions（000017）+ inquiries 关联列（000018） |
| 18H2-02 | 模型 Form / FormField / FormSubmission（relations、casts、BelongsToSite） |
| 18H2-03 | FieldTypeRegistry + config/forms.php |
| 18H2-04 | FormSubmissionService（动态规则 + DB 事务 record） |
| 18H2-05 | InquiryProjector（payload → 固定列，确定性） |
| 18H2-06 | FormSubmissionMail + 通知解耦（默认 false、try/catch） |
| 18H2-07 | 前台 dynamic_form 视图（按类型渲染 + 蜜罐 + 归因 + 提交锁定） |
| 18H2-08 | 路由 forms.submit + /inquiry 兼容桥接 |
| 18H2-09 | FormReference block 改 form_id |
| 18H2-10 | DefaultFormSeeder + GeoInstall/Upgrade 接线 |
| 18H2-11 | 后台 Form Manager（控制器/视图/字段 locale Tabs/submissions 查看）+ 导航 + AuditLog |
| 18H2-12 | 清理旧行业写死（TYPES、固定 validate、_lead_form 动态化） |
| 18H2-13 | 测试：四类表单 + 投影 + Site/Locale 隔离 + 通知失败不丢数据 + 向后兼容 |
| 18H2-14 | Gate 全验证 + commit + annotated tag `checkpoint-18H-2` |

---

## 13. Gate 标准（PASS 条件）

- Focused + **Full regression**（0 failed / 0 skipped）；
- **Fresh install**（含默认表单幂等创建）；Blank / Demo HTTP；
- **四类表单 UAT**：Contact / Demo / Download / Appointment —— 不同字段 → 发布 → 前台真实提交 → Submission 落库（payload 全量）→ Inquiry 可查（投影）→ Site 不串 / Locale 不串；
- **通知失败 ≠ 数据丢失**（真实测试）；
- Real Browser（zh/en × light/dark，console=0）；Multi-Site × Locale；
- feeds / SEO / GEO / Schema / Sitemap 不回归；Cache 失效正确；
- Runtime pollution = 0；验证窗口日志无新增 ERROR；cleanup；
- `git diff --check`、status、commit、annotated tag、**worktree clean**。

## 14. 边界

- 不做 Analytics（TD-73）/ Audit 覆盖扩展（TD-74）——留 18H-3；
- 不移动 rc1、不配 remote / push、不 Release；
- 不删历史 migration / Example / docs/audit。

> Discovery + Architecture 完成，**STOP**：待对 §11 决策点拍板后，按 §12 任务清单进入实现。
