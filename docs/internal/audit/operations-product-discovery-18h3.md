# P-STEP 18H-3 Discovery — Analytics + Audit / Revision 现状盘点

- **阶段**：P-STEP 18H-3（Operations Product Closure 第三 Gate）
- **基线**：annotated tag `checkpoint-18H-2`（HEAD `cea072f`）
- **日期**：2026-09-24
- **方法**：只读审计（Glob / Grep / Read + 官方资料核对），不改代码；产出 Discovery + Architecture 后 STOP，等用户裁定再实现。

---

## 1. Analytics 现状

### 1.1 前台零第三方脚本

- `Grep resources/ → gtag|googletagmanager|gtm|fbq|dataLayer|google-analytics|analytics`：**0 命中**。
- 即前台当前**完全不加载** GA / GTM / Meta Pixel 或任何第三方统计脚本，也无 `dataLayer`。
- `resources/js/app.js` 仅 `import './bootstrap'`；`bootstrap.js` 只配置 axios。前端交互逻辑全部内联在 `layouts/site.blade.php` 的 `<script nonce>` IIFE（导航收缩 / 下拉 / 外观切换 / 移动抽屉 / 数字滚动 / reveal）。

### 1.2 无 Analytics 配置位

- 设置共 7 组：`general / theme / contact / seo / geo / copy / sync`（见 DefaultSettingSeeder），**无 analytics / integration 组**，无 measurement_id / GTM container / pixel id 等字段。
- 后台无 Analytics / 集成管理界面。

### 1.3 已存在的 raw 口子 `seo_head_code`（断层）

- DefaultSettingSeeder 定义 `seo_head_code`（seo 组，「自定义 head 代码：原样输出到前台 </head> 前（统计 / 验证代码）」）。
- 渲染：`layouts/site.blade.php:1397-1398` 以 `{!! $siteSettings['seo_head_code'] !!}` **raw、无 nonce、无过滤**输出。
- **断层**：前台 CSP 为 `script-src 'nonce-…'`（无域名白名单）。
  - `<meta name="…-verification">` / `<link>` 类站长验证标签 → 正常生效（不执行脚本）。
  - 任何 `<script>`（内联无 nonce，或外链 src 域名不在白名单）→ **被 CSP 静默拦截**。
  - 即管理员按提示填入统计代码，页面看似保存成功，实际**统计脚本不执行、无数据**；且 raw 输出本身有 XSS 风险。

### 1.4 CSP 现状（与第三方脚本直接冲突）

`app/Http/Middleware/SecurityHeaders.php`，前台：

```
default-src 'self'
script-src 'nonce-{per-request}'      # 仅 nonce，无域名白名单、无 strict-dynamic
style-src  'self' 'unsafe-inline'
img-src    'self' data: blob: http: https:
font-src   'self' data:
connect-src 'self'                    # GA4/Meta 上报(XHR/fetch/sendBeacon)会被拦
object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'
```

- nonce 每请求生成、经整页缓存外壳占位回填（缓存命中不钉死 nonce），机制正确。
- 但第三方统计需要：外链脚本域名（script-src）、上报端点（connect-src）、部分图片 / iframe（img-src / frame-src），当前**全部未放开**。

### 1.5 无 Consent 机制

- 无 cookie / analytics consent banner，无 consent 状态存储；`Grep resources/views → consent|cookie-consent|privacy-policy` 仅命中表单内的 consent checkbox（提交授权，非站点级统计同意）。
- 即不存在「未同意前不加载 / 不上报」的闸门，与 Google Consent Mode / 隐私合规要求不符。

### 1.6 前端无统一事件层，CTA / 表单 / 下载零埋点

- 主 IIFE 各功能模块各自绑定，**无事件总线、无统一 track 入口**；CTA 按钮、表单提交、资料 / 文件下载均无任何事件派发。
- 表单（dynamic_form）为原生 server 渲染 + 原生 POST 提交（提交逻辑在 FormSubmissionService）；前端可在 document 层捕获 `submit`，但当前无挂钩。

---

## 2. Audit 现状

### 2.1 AuditLog 模型与表

- 模型 `app/Models/AuditLog.php`（BelongsToSite；`detail` JSON cast）。
- 静态方法 `record(action, summary, detail=[], targetType, targetId)`：写入 user_id / action / summary / detail / target_type / target_id / ip（site_id 由 BelongsToSite 自动填充）。
- 表 `audit_logs`（迁移 2026_09_14_000006:64）：id / user_id / action(60) / target_type / target_id / summary(255) / detail(JSON) / ip / timestamps / site_id；索引 target、created_at、site。
- **无 before / after 专用列**；现有 28 处调用中 `detail` 绝大多数传空 `[]`，仅个别带 warnings / override 片段。

### 2.2 已覆盖资源（`AuditLog::record` 调用点）

| 资源 | 覆盖动作 |
| --- | --- |
| Category | created / updated / deleted |
| Fact | created / updated |
| Content | created / translation.created / updated / deleted / published / unpublished |
| Media | uploaded（含正文内联） |
| Inquiry | handle / delete |
| Group | created |
| Setting | update / token / theme_preset |
| Site | created / updated / deleted / default |
| Menu | created / override / override.reset |
| Dashboard | 读取最近 10 条（展示） |

### 2.3 未覆盖资源（缺口）

以下控制器经 `Grep app → AuditLog::record` 确认**无任何审计**：

- **Entity**（`Admin/EntityController`）：CRUD / 发布、类型 / slug 变更无记录。
- **SeoMeta**（`Admin/SeoMetaController`，含 18G-2b TD-66 locale SEO 管理）：SEO 增改无记录。
- **Page**（`Admin/PageController`）：页面创建 / 更新 / 删除、模板 / 状态变更无记录。
- **Block**（PageController block 动作）：add / update / delete / move / toggle / duplicate 无记录。
- **Form / FormField**（`Admin/FormController`，18H-2 新建）：表单 / 字段 CRUD、启停无记录；FormSubmission 为用户前台数据（非后台操作，不纳入操作审计，但可在业务层留痕）。

### 2.4 缺 before / after

- 用户要求操作审计至少回答「when / what / before / after」。当前只有 when（created_at）/ who（user_id）/ what（action + summary），**变更前后值未记录**，无法追溯「具体改了什么」。

### 2.5 AuditLog ≠ Revision（两表已分离，方向正确）

- `content_revisions` 表（同迁移:80）：content_id / user_id / note / snapshot(JSON)。
- **ContentRevision 确实写入**：`ContentController:385`（内容保存）、`Services/Sync/GeoflowSync:130`；Content 模型 `revisions()` hasMany。
- 即 **Revision 目前仅覆盖 Content**；Entity / Page / Block / Form / SeoMeta 无可恢复版本（对应已 DEFERRED v1.1 的 TD-75，本阶段不重开）。
- 结论：AuditLog（操作流水）与 Revision（可恢复快照）在架构上已正确分表；本阶段只补 Audit，不把二者合并。

---

## 3. 缺口登记建议（TD-88 起，最终编号 / 裁定以用户为准）

> TD-73（Analytics Integration）、TD-74（AuditLog Coverage）作为**父项**保持 ACTIVE；下列为其落地子缺口，全部 CLOSED 后父项再 CLOSED。

| 建议 ID | 缺口 | 优先级 / 版本 | 父项 |
| --- | --- | --- | --- |
| **TD-88** | 前端无统一 Analytics 事件层：无 `GeoAnalytics.track` / dataLayer，CTA、表单提交、下载、联系零埋点 | P1 / V1 | TD-73 |
| **TD-89** | 无 Consent 管理：无 consent banner 与状态存储，未同意即可能加载 / 上报；缺 Consent Mode v2（ad_storage / analytics_storage / ad_user_data / ad_personalization） | P1 / V1 | TD-73 |
| **TD-90** | 严格 CSP 与第三方脚本冲突、未按启用 provider 动态放开 script/connect/img/frame；`seo_head_code` 填统计脚本被 CSP 静默拦截（断层） | P1 / V1 | TD-73 |
| **TD-91** | AuditLog 未覆盖 Entity / SeoMeta / Page / Block / Form 的后台操作 | P2 / V1 | TD-74 |
| **TD-92** | AuditLog 无 before / after 变化记录（detail 多为空），无法追溯具体变更 | P2 / V1 | TD-74 |

---

## 4. 关键架构张力（需用户裁定）

1. **严格 CSP（设计目标"前台零外链脚本"）↔ 接入 GA / GTM / Meta 外链**：需决定是否、以及如何按 provider 动态放开 CSP；GTM 动态注入脚本还涉及 nonce 传播 / `'strict-dynamic'`（会改变现有 nonce 信任模型）。
2. **`seo_head_code` 的定位**：当前 raw 输出 + CSP 拦截，对统计脚本名存实亡。需决定是保留为「仅 meta / link 验证」并把统计收编进正式 Analytics 能力，还是其他方案。
3. **Consent 前置**：默认 consent 状态（建议未同意前 analytics / ad storage = denied、不加载第三方脚本），以及最小可用 consent banner 形态。
4. **不造第二事实源 / 不硬编码 ID**：provider ID 属 Site Configuration（Setting），Blade 不得出现 gtag / fbq 调用；复用现有设置与 CSP 体系扩展，不新建 Localized* / 第二套管线。

---

## 5. 官方依据（可追溯）

- Google Tag / GA4 / GTM CSP 官方指南：`https://developers.google.com/tag-platform/security/guides/csp`
  - script-src：`https://www.googletagmanager.com`
  - connect-src：`https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com https://*.google.com https://*.google.`（广告类 doubleclick / pagead2 仅广告功能需要）
  - img-src：`https://www.googletagmanager.com https://*.google-analytics.com https://*.google.com …`；frame-src：`https://www.googletagmanager.com`
  - GTM container 推荐带 nonce；Custom HTML / Custom JS 变量、Preview 可能需 `unsafe-eval` 或额外指令。
- Strict CSP（nonce + strict-dynamic）：`https://web.dev/articles/strict-csp`
- Meta Pixel CSP 官方说明：`https://developers.facebook.com/docs/meta-pixel/advanced`
  - 允许 `https://connect.facebook.net` 加载 `fbevents.js` 与 `/signals/config/{pixelID}`；Pixel 上报主要经 `https://www.facebook.com/tr`（img GET）。
- Google Consent Mode：`https://support.google.com/analytics/answer/10000067?hl=en`

---

## 6. Discovery 结论

- **Analytics**：前台为「零第三方脚本 + 严格 nonce CSP + 无配置位 + 无 consent + 无事件层」的干净初始态；唯一 raw 口子 `seo_head_code` 对脚本被 CSP 静默拦截。需从零建立「配置层 + 统一事件层 + Consent + 动态 CSP + Provider 适配」。
- **Audit**：AuditLog 已覆盖 Content / Media / Site / Menu / Setting / Category / Fact / Inquiry / Group，但 Entity / SeoMeta / Page / Block / Form 无审计，且全站缺 before / after；AuditLog 与 Revision 已正确分表，Revision 仅 Content（TD-75 v1.1，不重开）。

Discovery 完成，**STOP**；待用户裁定架构（见 `operations-product-architecture-18h3.md`）后再授权实现，不进入编码。
