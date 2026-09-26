# GEO Website OS · AI Agent Guide（v1）

> 面向 Claude / Cursor / Codex / ChatGPT 等 AI coding agent。
> 目标：让 AI 在本系统上完成建站、换品牌、换行业、改页面、接表单等任务时，**遵循同一套架构契约与安全边界**，不破坏 GEO/SEO，不制造第二事实源。
>
> License: MIT　·　Platform version: `config('geo.version')`（2.0.0）　·　Stack: Laravel 12 / PHP 8.4 / SQLite（FTS5）/ Vite + Tailwind（构建期）

---

## 1. 这是什么

**GEO Website OS** 是一个多站点、GEO/SEO-first 的**内容与实体操作系统**，不是普通企业官网：

- **开源自部署、默认零外部依赖**：SQLite 数据库 + 本地 FTS5 搜索；不强制用户维护 Meilisearch / Elasticsearch / 外部缓存。
- **一半给人看，一半给 AI 看**：页面同时输出人类视觉体验、Schema 结构化数据与机器可读的区块语义。
- **多站点原生**：一套代码服务多个品牌/域名，数据、设置、主题、模板按 Site 严格隔离。

判断任务对错的唯一标准：**陌生用户拿到系统后，能否不改核心代码就稳定地安装、建站、运行、升级、维护。**

---

## 2. 心智模型：分层与单向依赖

| 层 | 职责 | 关键位置 |
|---|---|---|
| **Site** | 多站点边界（域名、默认/支持语言） | `app/Models/Site`、`BelongsToSite` + SiteScope |
| **Theme** | 一套视觉主题的 **token 值** | Theme 包 / `ThemePalette` |
| **Design Token** | color / typography / spacing / radius / motion | `:root` CSS 变量，由 Theme 解析 |
| **Component** | Button/Card/Hero/Section 的**变体能力** | `app/Support/Components/`、`config/components.php` |
| **Block** | 业务**区块能力**（27 个注册块） | `app/Support/Blocks/`、`config/blocks.php` |
| **Template Package** | 声明式行业模板（结构叙事） | `resources/templates/{key}/` |
| **Page Composition** | 页面 = slots → blocks 实例 | Page / PageBlock、`CompositionRenderer` |
| **SEO / GEO** | canonical / Schema / sitemap / llms / search | 各 Resolver + 派生索引 |

**依赖方向只允许从上到下，禁止反向控制。**
记忆口诀：**Template = 声明，Composition = 结构，Block = 能力，Renderer = 输出。**

---

## 3. 单一事实源（最高优先级）

任何数据只允许一个权威源，其余都是**派生读模型**，禁止再建第二套：

| 事实 | 权威源 | 派生 / 投影（不得反写为事实） |
|---|---|---|
| 内容 / 实体 | Content、Entity | Catalog 读模型、Search 索引 |
| 目录关系 | EntityRelation | Catalog 单向派生 |
| 搜索 | Content / Entity | `search_documents` + `search_index`（`search:reindex`） |
| 站点名 | `Site.name` | setting 展示值 |
| 公开 URL | PublicUrl 契约 | canonical / sitemap / Schema 引用 |
| 表单提交 | **FormSubmission**（payload 完整） | **Inquiry**（业务投影） |
| 通知收件人 | Form 级 → Site 级（两层） | 不允许 config/常量邮箱 |
| 组织信息 | Organization facts | 各前台消费者 |

---

## 4. Template 规范（声明式，不含可执行代码）

物理结构（`resources/templates/{key}/`）：

```
{key}/
├── manifest.json        # 身份 + 契约版本（见下）
├── theme.json           # 引用/主题值
├── recipes/*.json       # 页面 = sections → { block, variant, data }
├── defaults/            # settings.json / menus.json / seo.json
├── preview/             # desktop.webp / mobile.webp
└── README.md
```

- **manifest** 字段：`identity`（name/industry/audience）、`purpose`、`entities`、`conversion`、`seo`，以及 `requires` 的五个契约版本：`platform / theme_api / component_api / geo_contract / seo_contract`。
- **生命周期**：`validate → install → activate → bootstrap → deactivate`；升级走声明式 `template:migrate`。
- **红线**：模板包**禁止**携带 PHP / Blade / Controller / Route / Middleware / SQL / JS / 新 Renderer。模板不是插件，否则安全、升级、兼容都会失控。
- 提交/分发前必须 `php artisan template:validate {key}`（`--strict`）：ERROR 阻断、WARNING 放行。

---

## 5. Theme / Component / Token 规则

- **Theme 决定 token 值，Component 定义变体并消费 token，Block 决定使用场景**——三者不混。
- **禁止组件硬编码** `#hex`、固定 `px` 字号/间距；只能引用 `var(--color-*)`、`var(--sp-*)`、Typography token。
- 变体按**语义**命名（`button.primary / outline / ghost`、`card.glass / border`、`hero.split / center / image`），**不按视觉或行业复制组件**（不做 `product-card`、`factory-card`）。
- Spacing 用 `--sp-N`（N×4px）；空间与动效位移分离（`lift/nudge/rise/press`）。
- Typography 按 profile 派生：`standard / compact / editorial`，并区分 zh / en 字体与排版。
- Dark Mode：`<html data-color-scheme>`、localStorage 键 `gwos-color-mode`（light/dark/system），首帧防 FOUC；暗色用语义 token（surface/text/inverse），不直接写黑/白。

---

## 6. GEO 要求：视觉升级不得破坏机器可读结构

- **区块语义**：每个区块根输出受控的 `data-section / data-purpose / data-entity / data-conversion`（词表见 `app/Support/Blocks/SectionSemantic.php`，**fail-closed**，不可自由填值）。
- **结构化输出**：JSON-LD Schema、`sitemap.xml`、`llms.txt`、`robots.txt`、`feed.xml`。
- **URL 契约**：PublicUrl / canonical / hreflang 按 Site + Locale 解析。
- 换主题、换模板、改变体**不得改变**：唯一 H1、Schema、URL、实体关系、Sitemap/LLMS。
- 保持**语义化 HTML**（`section / h1–h3 / p / a / button / nav / details`），禁止 `div soup`，禁止纯 Canvas 承载内容。

---

## 7. 常见任务的零代码路径

| 任务 | 操作位置（不改 PHP/Blade/JS） |
|---|---|
| 换品牌色 / 换主题 | Admin → Theme / 品牌设置 |
| 换行业模板 | Admin → Templates：Compare → Activate → Bootstrap |
| 改首页（增/删/排序区块、切变体） | Admin → Page Composer（Section Composer Lite） |
| 添加语言 | Admin → Settings：site_supported_locales（添加后 `/en` 由 404 变 200） |
| 建表单（字段/验证/通知） | Admin → Forms；提交进入 FormSubmission → Inquiry |
| 改 SEO / GEO | Admin → SeoMeta / Settings |
| 搜索为空/内容更新后 | `php artisan search:reindex` |

---

## 8. 安全边界（必须遵守）

- **SafeUrl**：所有外部可配置 URL（模板/Block/CTA/Media/Menu）统一经 `App\Support\SafeUrl`；允许 http/https/mailto/tel/内部路由，**拒绝** `javascript: / vbscript: / data: / file:`。
- **防 Stored XSS**：所有用户输入在渲染/展示时正确 escape；`FormSubmission.payload` 为 JSON，输出到 Blade/后台前必须 escape。
- **seo_head_code 收窄**：只允许 `<meta>` / `<link>` 验证类元素；禁止 `<script>/<style>/<iframe>/event handler/javascript:`。
- **CSP**：前台 `script-src` 用 **nonce**（无 unsafe-inline script）；Analytics 第三方域名按已启用 Provider **动态扩展**，默认最小权限。
- **Analytics 解耦**：前台只调用统一事件 `GeoAnalytics.track(...)`，经 **Consent Gate** 再到 GA4/GTM/Meta 适配器；**禁止** Blade 直接写 `gtag()/fbq()` 或硬编码第三方 ID。
- **通知解耦**：表单通知默认**关闭**；DB 事务提交后再发送，**通知失败不丢提交**。
- **不入库机密**：`.env`、`*.sqlite`、backup、API key/token/私钥一律不提交（`.gitignore` 已覆盖）。

---

## 9. 命令清单

| 命令 | 用途 |
|---|---|
| `php artisan geo:install` | 全新安装（migrate、默认 Site/设置/表单/系统页、reindex、建超管、自检） |
| `php artisan geo:upgrade` | 升级（backfill → migrate → 补默认 → reindex → 清视图/整页缓存） |
| `php artisan geo:backup` / `geo:rollback` | SQLite 整库备份（含 manifest/sha256）/ 校验后整库还原 |
| `php artisan geo:version` | 版本、schema 状态、环境报告 |
| `php artisan search:reindex` | 重建派生全文索引（全站或单站） |
| `php artisan template:validate` | 模板 manifest/recipe/运行时三层校验（`--strict`） |
| `template:activate/bootstrap/deactivate` | 模板生命周期 |
| `php artisan template:migrate` | 模板版本间声明式迁移（dry-run/备份/回滚） |
| `cache:clear` / `view:clear` | 清缓存 / 清编译视图 |

---

## 10. 红线清单（出现即判错）

1. 不修改核心架构契约，不引入第二事实源、第二渲染体系。
2. 模板包不变成插件：不携带任何可执行代码。
3. v1.0 **不做**（属 v1.1，禁止提前引入）：自由拖拽 Canvas、Template Marketplace / 在线 zip 上传、RBAC 三角色 + Site Membership、Page/Landing 搜索、Logo srcset、完整自动迁移平台。
4. 不硬编码第三方 analytics ID、行业业务字段、旧品牌信息。
5. 不为让测试变绿而改弱断言或缩减测试面；测试数量变化须可解释。
6. 安全策略 **fail-closed**，但不过度严格（WARNING 允许放行），以支撑第三方生态。

---

*本指南随版本演进；契约字段以对应 `manifest.json`、`config/blocks.php`、`config/components.php` 与 `app/Support/` 下实现为准。*
