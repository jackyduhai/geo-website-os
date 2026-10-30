# P-STEP 18L-2b — Component System Completion：Final Gate Audit

> 阶段：P-STEP 18L（Design System & Template Ecosystem Productization）
> 子阶段：**18L-2b — Component System Completion（TD-123 Spacing / TD-36 Loading + 异步表单）**
> 上游基线：`checkpoint-18L-2a`（HEAD `cc24d31`，1020 / 5396 / 0 / 0）
> 日期：2026-09-25
> 纪律：Discovery → Implement → Real Browser/HTTP → Regression → Gate → **STOP**（不自动进入 18L-3）

---

## 1. 目标与范围

18L-2b 不新增页面、不造第二套样式管线，只把组件系统的两条遗留收口：

| 债务 | 主题 | 本阶段动作 |
| --- | --- | --- |
| **TD-123** | 内联 spacing / 位移 / transition 散落 px | spacing scale 标准化、位移 token 化、内联 gap/padding/margin 批量收敛 |
| **TD-36** | 无统一 loading / `aria-busy`，表单以整页 POST 为主 | 表单异步提交 + 就地 loading / success / error，渐进增强 |

硬边界：
- 复用现有 token 与注册 CSS 类，**不造第二套样式管线**；
- 保持 `section / h1 / p / a / button / nav / details` 语义，不破坏 GEO / Schema / SEO 公开契约；
- 不进入 18L-3 Template Package / Builder；
- 不碰 rc1（`965d63c`）、remote、push、Release。

---

## 2. 实现要点

### 2.1 Spacing scale 标准化（TD-123）

- ThemePalette spacing 由原 8 点网格（仅 sp-1..9，全仓仅 `.sec-compact` 消费）改为 **sp-N = N×4px**：
  `sp-1..16` 连续 + `sp-18 / sp-20 / sp-22 / sp-24`（无 17/19/21/23），覆盖 4–96px。
- Motion 区新增 4 个位移 token：
  `--lift-press:-1px`、`--lift-card:-2px`、`--nudge:3px`、`--rise:10px`。

### 2.2 布局 CSS 位移 / transition 收敛

- 按钮 hover `translateY(-1px)` ×4 → `var(--lift-press)`；
- 散落可点卡片 hover `-2/-3/-4px`（hp-cell / post / card / pcard / wscard / kcard / scene-card / prod-card / case-card / adj-card / coop-mode / ws4-card 等）统一 → `var(--lift-card)`；
- 箭头 `translateX(3px)` → `var(--nudge)`；
- reveal / hi-copy-layer `translateY(10px)` → `var(--rise)`；
- `.nav-toggle` / `.hero-dots` 硬编码时长 → `var(--motion-base)`；
- `.sec-compact` sp-7 → sp-12（保持 48px 视觉）。
- **刻意保留**：居中 `translateX(-50%)`、nav-panel 复合位移、Ken Burns、hi-copy 0.6s 长时长。

### 2.3 内联 spacing 批量 token 化

- .NET 正则（lookbehind + 显式强转 `[Text.RegularExpressions.MatchEvaluator]`）递归 `views/site`：
  **83 处替换、无 UNMAPPED**；
- `cta.blade.php` `margin:0 auto 22px`（auto 打断匹配）手动补 1 处 → `var(--sp-6)`；
- 复扫仅剩 `composed.blade.php` sr-only 的 `margin:-1px`（刻意隐藏，合理豁免）。

### 2.4 TD-36 异步表单

- 两个提交控制器（`Site/FormSubmissionController`、`Site/InquiryController`）重写为 `JsonResponse|RedirectResponse`：
  `wantsJson()` 返回 `{success,message}`，否则保持 `back()->with('lead_success',...)` 整页回退（渐进增强）；
  JSON 校验失败由 ValidationException 自动渲染 422 `{message,errors}`。
- `dynamic_form.blade.php` 脚本整体替换为真异步：
  客户端 required → `fetch`（X-Requested-With + Accept: application/json）
  → 200：`.lead-ok` role=status 就地替换表单；
  → 422：按字段 `.has-error` + `.lf-err.is-inline` 并 focus；
  → 其他/网络失败：`.lead-form-err` role=alert；
  `if(!window.fetch)return` 保留原生整页提交兜底。
- Loading / spinner / 就地错误 CSS：`.btn.is-loading`（color transparent、pointer-events none、::after spinner）、
  `.lead-form[aria-busy=true]`、`.lf-field.has-error`、`.lead-form-err`、`@keyframes btnspin`；
  prefers-reduced-motion 下 spinner 静态。
- 语言键 `ui.form_submitting / form_error / form_success` 在 zh-CN / en 均已存在，无需新增。

### 2.5 本轮真实抓到并修复的缺陷

1. **:root spacing 输出不全**：site.blade.php `:root` 原为显式逐行输出，spacing 仅输出 sp-1..9，
   新 scale 到 sp-24 导致 `var(--sp-12)/var(--sp-14)` 引用**未定义变量**
   → 改为 blade `@foreach`（过滤 `str_starts_with($k,'--sp-')`）自动输出全部 sp 键。
2. **位移 token 未输出**：4 个位移 token 在 ThemePalette 定义但 `:root` 无输出行
   → 在 `--motion-slow` 行后补 4 行。
   修复后所有 blade `view:cache` 编译通过。

---

## 3. 验证证据

### 3.1 Focused

- 新增 `tests/Feature/ComponentSystemCompletion18L2bTest.php`：**8 tests / 32 assertions，全部 PASS**。
- 覆盖：spacing scale、位移 token、loading CSS、异步成功 JSON（locale=zh-CN、payload name=王工）、
  异步 422（errors 含 name/phone、无 submission 落库）、传统 POST（302 + lead_success）、
  dynamic_form 源含 fetch / aria-busy / is-loading / `if(!window.fetch` 兜底、blade 内联 spacing px 复扫（sr-only 豁免）。

### 3.2 Full Regression

- **1028 passed / 5428 assertions / 0 failed / 0 skipped**（基线 1020 + 新增 8 对齐；耗时约 521s）。

### 3.3 Fresh Install（`geo:install --no-interaction`）

- 全 migration 通过；默认 Site、default settings、blank homepage（中性）；
- **default contact form 中性**：name / phone required、email / message optional，无 OEM / 制造业业务字段；
- system pages 中性；search index 0；超管创建（随机密码打印一次）。

### 3.4 Blank 单语 HTTP（serve 8141，locales=["zh-CN"]）

- `/`、`/contact/`、`/knowledge/` = 200；
- `/products/`、`/solutions/`、`/about/profile|history|culture/`、`/factory/`、`/cooperation/` = **404（有意门禁**：
  ProductController::index `abort_if(empty(Catalog::company()),404)`，空目录站无 organization Entity）；
- `/en/` = 404（单语正确）；
- `/sitemap.xml`、`/llms.txt`、`/robots.txt`、`/feed.xml` = 200；`/no-such-page` = 404。

### 3.5 Demo 双语 HTTP（serve 8142）

- zh 10 核心页（/、products/、solutions/、knowledge/、about/profile|history|culture/、factory/、cooperation/、contact/）全 200；
- en 8 核心页（/en、/en/products/、solutions、knowledge、about/profile、factory、cooperation、contact）全 200；
- 4 feeds 全 200。

### 3.6 Browser UAT（真实 Chrome）

**四组合（zh/en × Light/Dark）全部成立**：

| 组合 | lang | scheme | body bg | h1 |
| --- | --- | --- | --- | --- |
| zh-Light | zh-CN | light | rgb(248,250,252) | 工业涂料 · 胶粘剂 · 功能助剂 一体化定制 |
| zh-Dark | zh-CN | dark | rgb(11,18,32) | 工业涂料 · 胶粘剂 · 功能助剂 一体化定制 |
| en-Light | en | light | rgb(248,250,252) | Coatings · Adhesives · Additiv… |
| en-Dark | en | dark | rgb(11,18,32) | Coatings · Adhesives · Additiv… |

**保持性 / 交叉切换**：
- 刷新 `/en` → 仍 en/dark（刷新保持）；
- 切 locale 到 zh → dark 保持（zh/dark）；
- 切 theme 到 light → en 保持（en/light）。

**真实异步提交**（/contact/）：
- `urlChanged=false`（无整页刷新）；`busyObserved=true`（aria-busy/is-loading）；
- 就地 `.lead-ok` role=status，文本"已收到，我们会尽快联系你。"；
- 落库核验：**submission id=1 / form=1 / site=1 / locale=zh-CN**，payload 完整（name/phone/email/message），
  **inquiry id=1** 投影成功；
- Browser Console 全程 **0 error**。

### 3.7 Multi-Site × Locale（真实 Host header）

在 demo db 创建 **Site B id=2**（slug=acme、domain=acme.test、locales=["en"]、default=en、含 en organization "Acme Global"）：

| 请求（Host: acme.test） | 结果 |
| --- | --- |
| B `/` | **200** lang=en（根按 site default locale，TD-107/109），Acme Global ×18，无示例制造 / demo-product |
| B `/sitemap.xml` | **200**，仅 3 个 `acme.test/en` loc（/en、/en/knowledge/、/en/contact/），无 demo-product |
| B `/knowledge/`（显式 zh 非根） | **404**（en-only 站点未提供 zh） |
| B `/en`、B `/en/sitemap.xml` | **200**，Acme Global 身份 |
| A `/`（默认 host） | **200** lang=zh-CN，无 Acme Global（B 不泄漏 A、A 不泄漏 B） |

### 3.8 SEO / Schema / Sitemap / GEO / Search（zh/en 对拍）

- **SEO**：zh `<html lang=zh-CN>`、canonical=`.../`、3 个 hreflang（zh-CN→/、en→/en、x-default→/）、Schema inLanguage=zh-CN；
  en `<html lang=en>`、canonical=`.../en`、hreflang 同 3 个、inLanguage=en。
- **Sitemap（分语言，非 index）**：`/sitemap.xml` = zh urlset（27 loc、0 en）；`/en/sitemap.xml` = 200（en URL）；
  `/sitemap-index.xml` = 404；robots 声明两套 sitemap。
- **GEO llms.txt**：`/llms.txt` 中文公司事实；`/en/llms.txt` = 200 英文事实，Entity/Content/Fact 不混语。
- **Search locale**：zh 搜「涂料」→ zh URL（/products/leveling-agent-l01、/knowledge/...）；
  en 搜「industrial」→ /en URL（/en/knowledge/...），locale 隔离成立。

### 3.9 PageCache locale 隔离（真实响应正文对拍，双向）

清缓存后：

- **正向 zh→en→zh→en**：c1 zh MISS → c2 en MISS（en 未拿 zh）→ c3 zh HIT → c4 en HIT（en 未拿 zh）；
- **反向 en→zh→en→zh**：d1 en MISS → d2 zh MISS（zh 未拿 en）→ d3 en HIT → d4 zh HIT。

body 大小 zh=143436 / en=142259 稳定，html lang 正确。**英文请求拿不到中文缓存、反之亦然，双向成立。**

### 3.10 Runtime 污染与日志

- **Runtime 强身份词污染 = 0**：app / resources / config / routes / database/seeders 复扫
  Demo Tenant A / Demo Tenant A / 400-001-3770 / Sample City / Sample Province / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Road / 金家街 / admin@demo-tenant-a / Sample SaaS，全部 0。
- **硬编码复扫**：views/site 内联 `font-size:Npx` = 0、内联 spacing px = 0（仅 sr-only -1px 豁免）。
- **日志**：laravel.log 共 56 条历史 ERROR，全部为 CLI / tinker 调试痕迹
  （STDERR 常量、tinker parse error、--columns 选项、SiteContext setSite null 等），**无产品前台运行时缺陷**；
  最后一条 ERROR 14:00，晚于该时点的 Browser/HTTP 验证窗口 Console=0、无新增产品 ERROR。

---

## 4. 债务状态

| 债务 | 状态 |
| --- | --- |
| **TD-123** Spacing / 位移 / transition | **CLOSED by 18L-2b** |
| **TD-36** Loading / aria-busy / 异步表单 | **CLOSED by 18L-2b** |
| TD-117 / TD-118 Template Package / recipe | ACTIVE → 18L-3 |
| TD-119 / TD-121 拖拽 / 区块语义元数据 | ACTIVE → 18L-4 |

v1.0 产品代码未闭合项仍为 **0**；外部发布工程 TD-01 / TD-02 / TD-03 继续 HOLD。

---

## 5. Git 与 Tag

- `git diff --check` 通过；
- 提交本轮产品改动 + 测试 + 台账 + 本 Gate 文档；
- annotated tag **`checkpoint-18L-2b`** 与最终 HEAD 对齐；
- worktree clean。

---

## 6. 结论

**P-STEP 18L-2b — Component System Completion = PASS / ACCEPTED。**

组件系统的 spacing 与 loading/异步表单两条遗留全部收口，且未制造第二套样式管线、
未破坏 GEO / Schema / SEO 公开契约；zh/en × Light/Dark 四组合、Multi-Site × Locale、
PageCache 双向隔离均真实通过。

**STOP** — 不自动进入 18L-3；不碰 rc1 / remote / push / Release。
