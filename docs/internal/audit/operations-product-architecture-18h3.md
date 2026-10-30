# P-STEP 18H-3 Architecture — Analytics + Audit 架构方案（待裁定）

- **阶段**：P-STEP 18H-3
- **基线**：`checkpoint-18H-2`（HEAD `cea072f`）
- **日期**：2026-09-24
- **配套**：`operations-product-discovery-18h3.md`
- **性质**：本文为推荐方案，列出需用户拍板的关键决策点；裁定后再授权实现，单 Gate 收口。

---

## 1. 总目标

- **Analytics**：建立 `配置层（Site-scoped）→ 统一前端事件层 → Consent 闸门 → Provider 适配（GA4 / GTM / Meta）→ 动态 CSP`，第三方 ID 全部来自设置、Blade 不出现 `gtag / fbq`，默认不加载、未同意不加载。
- **Audit**：把操作审计补齐到 Entity / SeoMeta / Page / Block / Form，并记录 before / after；AuditLog（流水）与 Revision（可恢复版本）边界维持不变。
- 全程复用现有设置 / CSP / 缓存 / 安装升级体系，**不新建第二套管线、不新增数据表**。

---

## 2. Analytics 架构

### 2.1 分层

```
View：CTA / 表单 / 下载（默认智能归类；可选 data-geo-event / data-geo-params 精确覆盖）
        │  事件委托（document 级，捕获 click / submit）
        ▼
GeoAnalytics（前端统一门面 window.GeoAnalytics）
        ├─ .track(event, params)        统一入口（内部写 dataLayer 兼容队列）
        ├─ Consent gate                未同意 → 不加载 provider、不派发第三方
        └─ Adapters（仅在 enabled + consent 时初始化）
              ├─ Ga4Adapter   （gtag, G-XXXX）
              ├─ GtmAdapter   （dataLayer + GTM-XXXX container，container 带 nonce）
              └─ MetaAdapter  （fbq, Pixel ID）
        ▲
        │ 配置（Site-scoped Settings，新增 analytics 组）
AnalyticsConfig（服务端解析：哪些 provider enabled、ID、consent_required）
```

### 2.2 配置层（新增 settings，analytics 组；不新建表）

| key | 类型 | 说明 |
| --- | --- | --- |
| `analytics_ga4_enabled` | bool（默认 0） | 启用 Google Analytics 4 |
| `analytics_ga4_id` | text | Measurement ID（`G-XXXXXX`） |
| `analytics_gtm_enabled` | bool（默认 0） | 启用 Google Tag Manager |
| `analytics_gtm_id` | text | Container ID（`GTM-XXXXXX`） |
| `analytics_meta_enabled` | bool（默认 0） | 启用 Meta Pixel |
| `analytics_meta_id` | text | Pixel ID（数字） |
| `analytics_consent_required` | bool（默认 1） | 未同意前不加载第三方脚本（basic Consent Mode） |

- 校验：enabled 时对应 ID 必填且符合格式（`G-` / `GTM-` 前缀、pixel 数字），否则保存报错。
- 由 DefaultSettingSeeder 补默认、GeoInstall / GeoUpgrade 接线（对齐现有设置升级模式）。

### 2.3 统一事件层与标准事件

- 前端门面 `GeoAnalytics.track(event, params)`；底层维护 `window.dataLayer`（GTM 启用时直接复用，未启用时用内部队列），保证「只调统一事件、不直接调平台函数」。
- **事件委托**（document 级，无需逐元素改模板）：
  - `page_view`：每页一次（page_path / locale / referrer）。
  - `cta_click`：识别 `a.btn*` / `button.btn*` / 导航主 CTA / `[data-geo-event="cta"]`。
  - `form_submit`：document 捕获 `submit`，仅记录 form slug / id，**不采集 payload 字段值**。
  - `download`：`a[download]` 或 href 以 `.pdf/.doc/.docx/.zip/.xls` 结尾。
  - `contact`：contact 表单提交可由 form_submit 归类。
- 元素可用 `data-geo-event="xxx"` 与 `data-geo-params='{"k":"v"}'` 精确命名 / 附加参数；默认归类已覆盖主流程，不强制改模板。

### 2.4 Consent（Consent Mode v2，basic）

- 四个信号：`ad_storage / analytics_storage / ad_user_data / ad_personalization`；`consent_required=1` 时**默认 denied**。
- 最小 consent banner：`接受全部 / 拒绝（仅必要）`，状态写 `localStorage['gwos-consent']`；SSR 首屏不明显闪屏、刷新与跨页保持。
- **basic 模式（V1 推荐）**：用户同意前**完全不加载**第三方脚本 / 不发起第三方请求；同意后更新 granted 并加载已启用 provider；拒绝则保持不加载。最简单、最省资源、最易合规，且不依赖第三方在 denied 态下的高级行为。
- 不把表单 payload 等个人数据写入事件。
- advanced Consent Mode（denied 态也加载、仅屏蔽上报）与服务端 GTM 列入 v1.1。

### 2.5 Provider 适配与条件加载

- 仅当「provider enabled + ID 有效 + consent granted」时，由带 `nonce` 的注入脚本加载：
  - **GA4**：`https://www.googletagmanager.com/gtag/js?id=G-…`，`gtag('js')/('config', …)`。
  - **GTM**：官方 container snippet，**注入当前 CSP nonce**（`<script nonce=…>(function…GTM-…`）；注入后标准 page_view / 事件经 dataLayer。
  - **Meta**：`https://connect.facebook.net/…/fbevents.js`，`fbq('init', id)/('track','PageView')`。
- 多 provider 可并存；事件由 GeoAnalytics 扇出到各已初始化 adapter。

### 2.6 CSP 动态化（SecurityHeaders 改造）

- 中间件按当前 site **已启用的 provider** 动态追加指令（请求时设置已可读取）；**无 provider 启用时 CSP 维持现状（严格 nonce、零外链）**：

| Provider | script-src 追加 | connect-src 追加 | frame-src 追加 |
| --- | --- | --- | --- |
| GA4 | `https://www.googletagmanager.com` | `https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com https://*.google.com` | — |
| GTM | `https://www.googletagmanager.com` | 同 GA4 | `https://www.googletagmanager.com` |
| Meta | `https://connect.facebook.net` | `https://www.facebook.com` | — |

- img-src 已允许 `https:`，GA / Pixel 的图片上报无需额外放开。
- **V1 不引入 `'strict-dynamic'`**（避免改变现有 nonce 信任模型、避免 'self' / 域名白名单在现代浏览器被忽略）。GTM Custom HTML 标签内联脚本不自动继承 nonce，需用户在 GTM 内用 nonce 变量或自定义处理——属用户 GTM 配置侧，文档说明。
- provider / consent 配置改变 → 清空该站整页缓存（HTML 内注入的 loader 与 CSP 依赖该配置），接入现有页面级 cache invalidation；CSP 响应头本身经 PageCache 外壳回填、不被钉死。

### 2.7 `seo_head_code` 重新定位

- 保留该口子，但**明确仅用于 `<meta>` / `<link>` 类站长验证**（更新 label / hint：声明 `<script>` 会被 CSP 拦截，统计 / 转化请用「Analytics」设置）。
- 维持「不开放任意自定义脚本」的裁定（CSP / XSS / 供应链 / 性能）；未来受控扩展经 Integration / Plugin。

---

## 3. Audit 架构

### 3.1 扩展 record（不改表，detail JSON 承载）

- 签名扩展为：
  `record(action, summary = null, detail = [], targetType = null, targetId = null, changes = [])`
- `changes = ['before' => [...], 'after' => [...]]` 写入 `detail.changes`；仅记录**发生变化的字段**。
- 安全：token / 密码 / 密钥类不记明文（排除或脱敏）；值做长度截断，避免日志膨胀与泄露。

### 3.2 补齐审计点

| 控制器 | 新增动作 |
| --- | --- |
| EntityController | `entity.created / updated / deleted / published`（含类型、slug、归属变化） |
| SeoMetaController | `seometa.created / updated / deleted`（带 locale；含 TD-66 locale SEO 管理） |
| PageController（页面） | `page.created / updated / deleted`（模板、状态、route 变化） |
| PageController（区块） | `block.added / updated / deleted / moved / toggled / duplicated` |
| FormController | `form.created / updated / deleted / toggled`、`field.created / updated / deleted` |

- FormSubmission 是用户前台数据、非后台操作，不进操作审计（其业务留痕已由 Submission / Inquiry 自身承担）。
- 已有的 Content / Media / Site / Menu / Setting / Category / Fact / Inquiry / Group 审计保持不变。

### 3.3 AuditLog ≠ Revision

- 维持两表分离：AuditLog = 谁在何时对什么做了什么（含 before/after）；Revision = 资源可恢复快照。
- Revision 仍仅 Content（ContentRevision 真写入）；Entity / Page / Block / Form / SeoMeta 的可恢复版本继续按 **TD-75 DEFERRED v1.1**，本阶段不重开、不合并。

---

## 4. 涉及文件（实现时）

**新增**
- `app/Support/Analytics/AnalyticsConfig.php`（provider / ID / consent 解析、校验）
- `resources/views/site/partials/analytics.blade.php`（配置注入 + 条件 loader，带 nonce）
- `resources/views/site/partials/consent-banner.blade.php`
- 前端事件层：`public/js/geo-analytics.js`（构建 / 直接分发，带 nonce 外链同源）或内联 nonce partial（实现时按构建现状二选一，不引入新构建依赖）

**修改**
- `app/Http/Middleware/SecurityHeaders.php`（动态 CSP）
- `resources/views/layouts/site.blade.php`（挂 analytics / consent partial；事件委托）
- `database/seeders/DefaultSettingSeeder.php`（analytics 组默认值；seo_head_code label）
- `app/Console/Commands/GeoInstall.php` / `GeoUpgrade.php`（设置接线）
- `app/Models/AuditLog.php`（record 扩展 changes）
- Entity / SeoMeta / Page / Form 控制器（补审计点）
- 设置后台（analytics 组按现有分组机制自动渲染，无需独立页面）

**无新数据表**；settings 与 audit detail 复用现有结构。

---

## 5. 任务清单（单 Gate 18H-3，按生命周期推进）

| # | 任务 |
| --- | --- |
| 18H-3-01 | analytics settings + AnalyticsConfig 解析与校验（含 install / upgrade 接线） |
| 18H-3-02 | GeoAnalytics 门面 + 事件委托 + 标准事件（page_view / cta / form / download / contact） |
| 18H-3-03 | Consent 状态 + banner + basic Consent Mode v2（默认 denied、记忆） |
| 18H-3-04 | GA4 / GTM / Meta adapter 与条件加载（GTM nonce） |
| 18H-3-05 | SecurityHeaders 动态 CSP + provider 配置变更清缓存 |
| 18H-3-06 | seo_head_code 重新定位（仅 meta / link） |
| 18H-3-07 | AuditLog record 扩展 changes（before / after、脱敏截断） |
| 18H-3-08 | 补 Entity / SeoMeta / Page / Block / Form 审计点 |
| 18H-3-09 | 测试：Analytics / Consent / CSP / Audit 覆盖真实契约 |
| 18H-3-10 | 全验证（见 §7）+ Gate 文档 + commit / annotated tag |

---

## 6. v1.0 / v1.1 边界

- **V1.0（本阶段）**：Analytics 配置 + 统一事件层 + basic Consent + GA4/GTM/Meta 适配 + 动态 CSP + Audit 五资源覆盖 + before/after。
- **v1.1+**：advanced Consent Mode、服务端 GTM、更多 / 自定义 adapter、Analytics 报表 UI、`strict-dynamic` 下 GTM Custom HTML 完整支持、Revision 扩展到 Entity/Page/Block/Form（TD-75）。

---

## 7. Gate 验收标准

**Analytics**
- 所有 provider ID 来自 settings；Blade / JS 无 `gtag / fbq / GTM-` 硬编码。
- 默认（未启用或未同意）：**零第三方请求**、CSP 不放开、console 0。
- 启用 + 同意：真实加载，page_view / cta_click / form_submit / download 在浏览器 network / dataLayer 真实可见。
- consent 拒绝：不加载、刷新 / 跨页保持；banner 可键盘操作、焦点可见。
- CSP 无违规（无 CSP 拦截 console 报错）；GTM container 带 nonce。

**Audit**
- Entity / SeoMeta / Page / Block / Form 后台操作全部留痕，含 before / after；敏感字段脱敏。
- 不影响既有审计与 Dashboard 展示。

**横向（沿用统一 Gate）**
- Full Regression 全绿；Fresh Install；Blank / Demo；真实 Browser；Multi-Site（provider / consent 不串站）；缓存精确失效；Runtime pollution = 0；日志无新增产品 ERROR；Smoke cleanup；`git diff --check` + commit + annotated tag `checkpoint-18H-3`；worktree clean。
- 不移动 `v1.0.0-rc1`、不配 remote / push、不 Release。

---

## 8. 需用户拍板的决策点

1. Analytics 配置是否采用新增 **analytics 设置组**（推荐）而非并入 sync / seo。
2. Consent 是否采用 **basic 模式**（同意前完全不加载第三方，推荐）。
3. V1 是否**不引入 `strict-dynamic`**（推荐；GTM Custom HTML 非 nonce 部分由用户 GTM 侧处理）。
4. `seo_head_code` 是否**收窄为仅 meta / link 验证**（推荐）。
5. Audit before/after 是否经 **detail JSON 承载、不改表**（推荐）。
6. TD-88 ~ TD-92 的编号与归属是否照此登记。

裁定后授权实现；18H-3 完成后独立 Gate、STOP，不自动进入 18I。
