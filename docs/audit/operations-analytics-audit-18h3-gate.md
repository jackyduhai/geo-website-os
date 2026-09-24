# P-STEP 18H-3 Gate — Analytics + Audit / Revision Operations Closure

- **阶段**：P-STEP 18H-3（Search / Media / Forms / Operations Product Closure 的第 3 个子 Gate）
- **父 Epic**：TD-73（Analytics / 转化集成）、TD-74（AuditLog 覆盖缺口）
- **基线 HEAD（父提交）**：`cea072f` = annotated tag `checkpoint-18H-2`
- **本文档性质**：18H-3 代码接线与两个新测试文件完成后，对其进行的**独立 Gate 验收证据汇总**。
- **结论先行**：**P-STEP 18H-3 = PASS / ACCEPTED**（证据见下，最终 Git / tag 见 §10）。

---

## 1. 授权与验收边界

18H-3 Discovery 已由用户全部裁定（6 项 + 工程约束），核心要求：

1. **D1**：新增独立、**Site-scoped** 的 `analytics` 设置组；Blade 不得出现 `gtag` / `fbq` / GTM ID。
2. **D2**：**Basic Consent Mode**——未同意前第三方完全不加载、banner 自身不依赖 analytics、拒绝后零第三方请求 / 标识；内部事件层未同意前不向第三方 dispatch。
3. **D3**：**受控动态 CSP**——不引入 `strict-dynamic`、不用 `unsafe-inline`，仅启用的 provider 进入 `script-src` / `connect-src` / `frame-src` allowlist，默认最小权限。
4. **D4**：`seo_head_code` 从 parser / 数据模型层收窄为仅 `<meta>` / `<link>` + 属性白名单；历史含 `<script>` 的值可见标记 legacy / invalid、不输出。
5. **D5**：before / after 经 `detail` JSON 承载、不改表，但须经白名单 / 脱敏 / 归一化的**审计安全快照**（排除 token / secret / password / smtp / analytics secret / 提交 payload / 隐私字段），非整行 dump。
6. **D6**：TD-88 ~ TD-92 登记，父级 TD-73 / TD-74。
7. 两个横向要求：**Analytics × Multi-Site**（Site A→Provider A、Site B→Provider B 不串站）与 **Analytics × Locale**（事件含 site_id / locale / page / resource，`form_submit` 跟 locale、不发整个 payload；统一事件层不知 provider API）。
8. **AuditLog（操作审计）≠ Revision（可恢复内容版本）**；TD-75 完整 Revision 维持 DEFERRED v1.1，本阶段不重开。

---

## 2. 验证范围（Gate 清单）

| # | 验证项 | 结果 |
| --- | --- | --- |
| 1 | Focused（修复复验） | **42 passed / 203 assertions** |
| 2 | Full Regression | **1014 passed / 5169 assertions / 0 failed / 0 skipped（880.24s）** |
| 3 | Fresh Install（geo:install） | PASS |
| 4 | Blank HTTP（含空站门禁 404→建 company 200 闭环） | PASS |
| 5 | Demo HTTP（中英文核心页 + 全部 feed 200） | PASS |
| 6 | Browser UAT（Console 对拍、零第三方请求） | PASS |
| 7 | Multi-Site × Locale / Analytics | PASS（测试固化 + 18F Gate） |
| 8 | SEO / Canonical / hreflang（英文页对拍） | PASS |
| 9 | Schema（inLanguage / @id / url） | PASS |
| 10 | GEO（语言一致性） | PASS（geo.json 200 + 测试） |
| 11 | Sitemap（locale / published / scope） | PASS |
| 12 | Search（locale / site / published） | PASS（18H-1 基线未破坏） |
| 13 | PageCache（locale 隔离） | PASS（测试固化） |
| 14 | Runtime 权威目录污染扫描 | **0** |
| 15 | Log audit（验证窗口新增产品 ERROR） | **0** |
| 16 | Smoke cleanup（进程 / 端口 / 临时库 / 缓存） | PASS |
| 17 | Git（diff --check / status / commit / annotated tag） | PASS（§10） |

---

## 3. Full Regression

- **修复前首跑**：`3 failed / 1011 passed / 5164 assertions / 846.82s`。三个失败：
  1. 2. `AdminSeoMetaCrudTest` 两个方法：`DELETE seo-metas.destroy` 收到 **HTTP 500**。
  3. `SiteIdCoreTablesTest`：Setting 计数断言期望 73、实际 80。
- 根因与修复见 §4（TD-96）与 §5（NON-DEBT）。
- **Focused 复验**（`AdminSeoMetaCrudTest` + `SiteIdCoreTablesTest`）：**42 passed / 203 assertions / 67.12s**。
- **修复后全量**：

```
Tests:    1014 passed (5169 assertions)
Duration: 880.24s
```

  - 口径：18H-2 基线 987 测试 + 两个新文件（`AnalyticsConsentCsp18H3Test` 15 + `AuditCoverage18H3Test` 12 = 27）= **1014**。
  - **0 failed、0 skipped**；无放宽断言、无 skip。

---

## 4. 本轮真实产品 BUG — TD-96（已 CLOSED）

- **现象**：`DELETE /admin/seo-metas/{seoMeta}` 返回 HTTP 500，`AdminSeoMetaCrudTest` 两个删除用例失败。
- **根因**：`app/Http/Controllers/Admin/SeoMetaController.php` 的 `destroy()` 在 18H-3 补审计点时，审计描述写成 `（{$seo->title}）`，引用了**不存在的变量 `$seo`**——`destroy()` 的形参实际名为 `$seoMeta`。触发 `ErrorException: Undefined variable $seo` → 500。
- **修复**：改为 `（{$seoMeta->title}）`。
- **防回归**：修复后 `AdminSeoMetaCrudTest` 全过；删除路径的审计与重定向均正确。
- **状态**：**TD-96 CLOSED**；发现即修复，**不新增 v1.0 Required**。已登记进 `technical-debt-registry.md` §5。

---

## 5. NON-DEBT — Setting 计数断言随产品新增更新

- **现象**：`SiteIdCoreTablesTest` 断言 `Setting::count()` 期望 73、实际 80。
- **口径核算**：
  - `DefaultSettingSeeder` 原 **72** default + 18H-3 新增 `analytics` 设置组 **7** = **79**。
  - Demo `SettingSeeder` 再加 `contact_hours_en` **1** = **80**。
- **裁定**：断言随产品新增字段更新，属 **NON-DEBT**（非产品缺陷）。已将断言更新为 `assertEquals(80, Setting::count())`，注释注明 18H-3 +7 analytics。

---

## 6. Fresh Install

- 全新空文件 `fresh.sqlite`（Laravel 12 sqlite 连接在 DB 文件不存在时直接报错，须先创建空文件）。
- 执行：`geo:install --admin-password=secret123 --site-name='GEO Website OS'`。
- 安装闭环验证：
  - 全部 migrations 执行成功；
  - 默认 settings（含 analytics 7 项、默认全关）；
  - **Blank homepage（行业中性）**；
  - **Default contact form（行业中性：name / phone / email / message，无 OEM / 原料采购 / 经销字段）**；
  - 9 system_key 系统页（行业中性；默认站点仅 zh-CN）；
  - Search index（FTS5）构建；
  - 管理员账号创建、后台可登录。

---

## 7. Blank HTTP 与空站门禁闭环（serve 8171 → fresh.sqlite）

**默认空站（仅 zh-CN）：**

| 路径 | 结果 | 说明 |
| --- | --- | --- |
| `/` | **200** | 中性欢迎首页 |
| `/en` | **404** | 默认 `site_supported_locales` 仅 zh-CN，正确 |
| `/contact/` | **404** | 空站门禁 |
| `/products/`、`/solutions/`、`/about/profile/` | **404** | 空站门禁 |
| `/knowledge/` | **200** | Content 域页面，空站也可达 |

- **默认 CSP 为最小权限**：`script-src` 仅每请求随机 nonce（`'self'`）、`connect-src 'self'`；**无** google / facebook / googletagmanager 来源。
- 首页 HTML 扫描 `GeoAnalytics | gtag | fbq | google-analytics | connect.facebook | googletagmanager | dataLayer` = **0 match**（analytics 默认全关，正确）。

**门禁根因（有意契约，非路由缺陷）：**

- `ContactController::show()` 开头：`$company = Catalog::company(); if (empty($company)) { abort(404); }`。
- `SystemPageRenderContext` 注释明确：空目录站 products / factory / contact 等由 company / production 门禁裁决 404；knowledge 这类 Content 域页面空站也可达。

**门禁闭环验证：**

- 通过独立 Laravel 引导脚本（putenv 指向 fresh.sqlite → bootstrap → console kernel）创建 organization：
  - **Entity id=1**，name `Acme Corporation`，slug `acme-corp`，type organization，status published，locale zh-CN，summary `A neutral example company.`。
- `Catalog::flush()` + `page-cache:clear` 后重测：

| 路径 | 结果 |
| --- | --- |
| `/contact/` | **200** |
| `/about/profile/` | **200** |
| `/products/` | **200** |

- 证明 blank 404 是**正确的空站契约**；仅建 company（门禁）即放行门面页，逻辑闭环，且不依赖 demo 数据。

---

## 8. Demo HTTP（serve 8172 → demo.sqlite）

`demo.sqlite` 经 `migrate --force` + `db:seed --force`（`DatabaseSeeder` = DemoSeeder + DefaultFormSeeder + SystemPageSeeder + admin）。

**中英文核心页全部 200：**

| 中文 | 结果 | 英文 | 结果 |
| --- | --- | --- | --- |
| `/` | 200 | `/en/`（301 尾斜杠规范化后） | 200 |
| `/products/` | 200 | `/en/products/` | 200 |
| `/solutions/` | 200 | `/en/solutions/` | 200 |
| `/knowledge/` | 200 | `/en/knowledge/` | 200 |
| `/contact/` | 200 | `/en/contact/` | 200 |
| `/about/profile/` | 200 | `/en/about/profile/` | 200 |

**全部 Feed 端点 200：**

| Feed | 中文 | 英文 |
| --- | --- | --- |
| Sitemap | `/sitemap.xml` 200 | `/en/sitemap.xml` 200 |
| LLMS | `/llms.txt` 200 | `/en/llms.txt` 200 |
| RSS | `/feed.xml` 200 | `/en/feed.xml` 200 |
| GEO | `/geo.json` 200 | `/en/geo.json` 200 |
| Robots | `/robots.txt` 200（仅 zh-CN 注册一次） | — |

**英文 Contact 页 SEO 链路对拍（`/en/contact/`）：**

- `<html lang="en" data-color-scheme="light">`；
- `<title>Contact Us - Example Manufacturing Co., Ltd.</title>`；
- **canonical** = `http://127.0.0.1:8172/en/contact/`（英文页指英文 URL，未错指中文）；
- **hreflang** 双向齐全：`zh-CN` → `/contact`、`en` → `/en/contact`、`x-default` → `/contact`；
- **Schema ContactPage**：`inLanguage: "en"`，`url` 与 `@id` 均为英文地址。
- 小观察（非阻塞）：hreflang zh href 无尾斜杠、canonical 带尾斜杠，均经 301 规范化可达。

---

## 9. Browser UAT（真实浏览器，本次 Gate）

- **Demo 中文首页**（干净重载 `?cb=gatefresh1`）：
  - **Console = 0**（无 error、无 verbose）；
  - 本次加载**无任何新第三方请求**——8172 自身 `/`、`/img/logo.png`、`favicon` 均 ok。
- **英文 Contact 页**（`?cb=gateen1`）：
  - title = `Contact Us - Example Manufacturing Co., Ltd.`（英文）；
  - **Console = 0**。
- **历史缓冲甄别**：首次快照中出现的 `google-analytics.com/g/collect`（测试 ID `G-TEST123456` / `G-TEST888888`）与 4 条 `ERR_CONNECTION_TIMED_OUT`，经时间戳（`_p=1790241044…1790242565`）与 drain 后干净重载对拍，确认全部为**实现期 UAT（启用测试 provider）残留在网络日志中的历史记录**，并非 8172（analytics 全关）本次发出；drain 后 Console 清空、无新外部请求。
- Light / Dark × zh / en 四组合在 18F / 18G Gate 已反复验证；18H-3 仅新增 head 资源与 consent partial、未改布局 / 主题结构，全量 1014 + 27 个专用测试（含真实 HTTP 断言）覆盖，未破坏既有能力。

**Multi-Site × Locale / Analytics**：en-only Site B（中文未启用 → zh 404、en 200）在 18F Gate 验证、由本地化与 analytics 测试固化；analytics 配置 Site-scoped，`AnalyticsConsentCsp18H3Test` 覆盖跨站不串。

---

## 10. Runtime 污染、日志审计、Cleanup、Git

### Runtime 权威目录污染

- 扫描 `app/`、`resources/`、`config/`、`database/seeders/` 四个运行时权威目录的强身份词（Demo Tenant A / Demo Tenant A / 400-001-3770 / Sample Road / 金家街 / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Province 等）= **全部 0 match**。

### Log audit

- `storage/logs/laravel.log` 全日志共 12 条 ERROR header：
  - [1]–[11]：`Undefined variable $brandDisplayName (View: layouts/site.blade.php)`，属**更早开发阶段**的 blade 缺陷、**已修复**（`$brandDisplayName` 现于所有使用点之前的 `@php` 块定义；本次大量 HTTP 200 不可复现）。
  - [12]：`tinker --execute` 内联引号被 PowerShell 剥离导致的 `Unexpected end of input`，系**工具误调用、非产品代码、非 HTTP**（后改用独立引导脚本）。
- **本次 Gate 验证窗口无产品 ERROR。**

### Smoke cleanup（已完成）

- 停止本次两个 serve：8171（blank）、8172（demo）。
- 清理更早开发期遗留的孤儿 serve：端口 8166（PID 20884 / 15164）。
- 端口 **8166 / 8171 / 8172 全部释放**；无残留 `artisan serve` / `php -S` 进程。
- 删除临时 Gate 目录 `D:\Temp\18h3-gate`、`D:\Temp\18h3`（含临时 sqlite、取证 HTML、引导脚本）。
- 仓库 `cache:clear` + `page-cache:clear`（缓存版本 635438）+ `view:clear` 完成。
- 仓库内 `database/database.sqlite` 经 `git check-ignore` 确认被忽略、未跟踪，是本地开发库（本次 Gate 全程使用临时库、未触碰），**保留、不进 commit**。

### Git / tag

- `git diff --check` exit **0**（仅 CRLF→LF 行尾规范化提示，非 whitespace error）。
- 本次提交包含 18H-3 全部改动：
  - **Modified**：9 个 Admin Controller（Entity / Form / Page / Plugin / SeoMeta / Setting / Site / Theme）审计点、`SecurityHeaders`、`AuditLog` model、`GeoUpgrade`、`DefaultSettingSeeder` / `DefaultFormSeeder`、`routes/admin.php`、`layouts/site.blade.php`、`site/dynamic_form.blade.php`、`lang/{en,zh-CN}/ui.php`、`SiteIdCoreTablesTest`、`technical-debt-registry.md`。
  - **New**：`app/Support/Analytics/`、`app/Support/Audit/`、`app/Support/Head/`、`site/partials/analytics.blade.php`、`site/partials/consent-banner.blade.php`、`AnalyticsConsentCsp18H3Test`、`AuditCoverage18H3Test`、Discovery / Architecture 文档与本 Gate 文档。
- 提交后创建 **annotated tag `checkpoint-18H-3`**，并以 `git cat-file -t checkpoint-18H-3 = tag`、`git rev-list -n1 checkpoint-18H-3` 验证 tag 类型与目标；最终 worktree clean（最终 commit hash 与 tag 对齐结果见 Gate 回复）。

---

## 11. 能力归属与单一事实源确认

- **Analytics 事实源**：Site-scoped `analytics` 设置组（enabled / provider / measurement_id / container_id / pixel_id / consent_required）；第三方 ID 全部在 Setting，Blade 无 `gtag` / `fbq` / GTM ID；provider adapter 与统一 `GeoAnalytics` 事件层分离，事件层不知 provider API。
- **Consent 事实源**：`gwos-consent` 记忆 + 每请求 consent gate；未同意不加载、拒绝零第三方请求；banner 自身不依赖 analytics。
- **CSP 事实源**：`SecurityHeaders` 每请求随机 nonce + 仅启用 provider 的受控动态 allowlist；无 `unsafe-inline` / `strict-dynamic`。
- **Head verification 事实源**：`HeadCodeSanitizer` 仅允许 `<meta>` / `<link>` + 属性白名单；`<script>` / `javascript:` 剔除并标 invalid。
- **Audit 事实源**：`AuditLog` + `AuditSnapshot`（白名单 / 脱敏 / 归一化）；`recordChange` 覆盖 Entity / SeoMeta / Page / Block / Form / Settings / Theme / Plugin，`detail.changes` 带 before / after；敏感字段 `[REDACTED]`。
- **未产生第二事实源 / 第二套 Resolver**：18H-3 复用既有 SeoMetaResolver / PublicUrl / Schema / GEO / Search 管线，仅做接线与扩展。
- **AuditLog ≠ Revision**：操作审计与可恢复版本分表；TD-75 完整 Revision 维持 DEFERRED v1.1。

---

## 12. Gate 裁定

全部 Gate 项成立：

- Full Regression **1014 / 5169 / 0 / 0**；Focused **42 / 203**；
- Fresh Install PASS；Blank（门禁 404→建 company 200 闭环）PASS；Demo（核心页 + 全部 feed 200）PASS；
- Browser Console = 0、analytics 全关零第三方请求；Multi-Site × Locale / Analytics 隔离 PASS；
- 英文页 SEO / canonical / hreflang / Schema(inLanguage=en) 对拍正确；
- Runtime 污染 = 0；验证窗口新增产品 ERROR = 0；
- Smoke cleanup 完成；`git diff --check` 通过；annotated tag `checkpoint-18H-3` 对齐、worktree clean。

> **P-STEP 18H-3 = PASS / ACCEPTED / STOP。**
>
> **v1.0 Required 未闭合 = 3**：仅 P0 外部发布工程 TD-01（Cloud GitHub Actions 首跑）、TD-02（基于最终 HEAD 重建 RC + Manifest + SHA-256）、TD-03（Private → Public / v1.0.0）。
>
- 18H-3 不自动进入 18I；不移动 `v1.0.0-rc1`（`965d63c` HOLD）；不配置 remote、不 push、不 Release。
