# Phase 5.6-D: Core Controller SEO Integration — HTTP Evidence & Architecture Audit

> 状态：5.6-D 收尾（HTTP Integration Evidence Closure）
> 前置：5.6-A 冻结契约（seo-meta-architecture-matrix.md）、5.6-B Schema、5.6-C SeoMetaResolver
> 本文档结论：**禁止据此进入 5.6-E，需另行授权。**

---

## 1. 阶段定义与边界（冻结）

```
5.6-D   Core Controller SEO Integration
        范围：HomeController（Site 级）、PageController（Content 级）、
              KnowledgeController（转发 PageController 的 Content 级路径）
        产物：Controller → SeoMetaResolver → SeoResult → Blade → 最终 HTML

5.9–5.12 Remaining Controller SEO Migration
        范围：AboutController / ContactController / FactoryController /
              CooperationController / ProductController / SolutionController /
              SearchController
        以及：列表页（栏目页 / 知识频道列表页）SEO Resolution 契约、
              Blade SEO 输出层重构（5.10）、SchemaBuilder（5.7）、
              LlmsBuilder（5.8）、SitemapBuilder（5.11）、config/seo.php（5.12）、
              ExampleUrlGenerator 完整替换（5.12）
```

**本阶段未修改 5.9–5.12 范围内的任何 Controller。**

---

## 2. Controller Integration

### 2.1 HomeController（Site 级 Resolution）

- SEO 唯一入口：`SeoMetaResolver::resolveSite(SiteContext::currentSite())`
- 已不存在 `Setting::get('site_name')` / `Setting::get('seo_default_desc')` /
  `'Example Food'` 参与 SEO Resolution。
- Controller 中的 Setting / Facts 用法（Facts::company、Facts::product、首页区块等）
  属于普通业务内容渲染，不属于 SEO Resolution，按阶段定义保留。
- SiteContext 缺失时保留开发环境兼容分支（不触达生产链路）。

### 2.2 PageController（Content 级 Resolution）

最终链路（`renderContent`）：

```
HTTP Request
  → ResolveSite 中间件（SiteContext）
  → Content（matchContent，slug + 栏目路径匹配）
  → SeoMetaResolver::resolveContent($content)
  → SeoResult（只读 DTO）
  → view('site.content', ['seo' => SeoResult 字段映射])
```

- `Content::metaTitle() / metaDescription() / canonicalUrl()` 三个 legacy 辅助方法
  **不再被任何 Site Controller 调用**。唯一存量消费方是
  `SchemaBuilder::article()`（GEO 结构化数据层，5.7 阶段处理）。
- 修复：`renderCategory()` 单页型栏目转发调用 `renderContent($page, $schema)`
  缺少第三参数（5.6-D 引入的 `SeoMetaResolver`），单页型栏目渲染会抛
  `ArgumentCountError`。已修正为显式传参。

### 2.3 KnowledgeController

- `/knowledge/{slug}` 非栏目 slug 时转发
  `PageController::dispatch('knowledge/' . $slug)`，随后走
  `resolveContent() → SeoResult → Blade`，与 2.2 完全同源（HTTP 测试证明）。
- 知识**列表页**（`index` / `channel` 命中 group 时）保留既有标题/描述组合，
  属列表页过渡实现：categories / groups 无 SeoMeta 绑定（冻结契约只定义
  Site / Content / Entity 三类绑定），列表页 Resolution 契约未冻结，
  统一归入 5.9–5.12。

---

## 3. HTTP Evidence（tests/Feature/SeoHttpIntegrationTest.php，10 用例）

| # | 用例 | 证据 |
|---|---|---|
| 1 | home html seo matches resolver site result | GET `/`：title / description / canonical / og:title / og:description / og:image / robots 全部等于 `resolveSite()` 返回值 |
| 2 | home canonical has no query string in html | GET `/?utm_source=external`：canonical 仍为 `https://example.com/` |
| 3 | content html seo matches resolver content result | GET `/news/{slug}`：HTML 与 `resolveContent()` 逐字段一致 |
| 4 | content canonical has no query string in html | GET `/news/{slug}?utm_campaign=x`：canonical 无查询串、无尾斜杠 |
| 5 | explicit seo meta overrides all layers in html | SeoMeta.title / description / canonical / og_image_path 四项显式值全部呈现在最终 HTML |
| 6 | content falls back to seo fields then title in html | 无 SeoMeta → HTML 取 Content.seo_title / seo_desc |
| 7 | content falls back to title then site in html | 无 seo_title → HTML 取 Content.title；描述回退 Site.description |
| 8 | knowledge article forwards to dispatcher and resolves seo | GET `/knowledge/{slug}` → 转发分发器 → Resolver → Blade，SEO 与 Resolver 一致 |
| 9 | knowledge index page renders | GET `/knowledge/` 列表页可达 |
| 10 | core controllers do not reimplement seo resolution | 架构守护：HomeController / PageController 源码级断言（见 §4） |

Canonical 断言要点（从最终 HTML 验证，非仅 Resolver 层）：

- HTTPS（`https://` 前缀，来自 GenericUrlResolver + Site.domain）
- 无查询串（带 `?utm_*` 请求时 canonical 不变）
- Home 带尾斜杠 `https://domain/`；Content 无尾斜杠 `https://domain/{type}/{slug}`
- SeoMeta.canonical 显式覆盖时原样呈现

→ 证明 Controller + View 未对 Resolver 结果做二次修改。

OG Image 断言要点：

- `SeoMeta.og_image_path` > `Content.og_image_id` > `Content.cover_id` > `Site.logo`
  的优先级在 HTML `<meta property="og:image">` 上逐层得到验证
  （用例 3 验证 cover_id 层，用例 5 验证 og_image_path 层，
  用例 1 验证 Site.logo 层；og_image_id 层由
  SeoMetaResolverTest::test_og_image_from_content_og_image_id 覆盖）。

---

## 4. SEO Single Source（双源扫描结论）

扫描范围：`resources/views/`、`app/Http/Controllers/Site/`、`app/Support/`、
`app/Services/Seo/`；关键词：`seo_title` / `seo_desc` / `seo_default_desc` /
`canonical` / `og_image` / `metaTitle` / `metaDescription` / `site_name`。

### 4.1 合法（Resolution 唯一来源）

| 位置 | 说明 |
|---|---|
| `app/Services/Seo/SeoMetaResolver.php` | 继承链唯一实现（SeoMeta → Content/Entity/Site → System） |
| `app/Services/Seo/GenericUrlResolver.php` | canonical 唯一生成器（经 UrlResolverInterface） |
| `app/Services/Seo/SeoResult.php` | 只读 DTO |
| `app/Http/Controllers/Site/HomeController.php` | 仅消费 `resolveSite()` |
| `app/Http/Controllers/Site/PageController.php`（renderContent） | 仅消费 `resolveContent()` |
| `app/Support/` | 无 SEO 关键词命中 |

### 4.2 过渡实现（有明确归属阶段，不在 5.6-D 修改）

| 位置 | 现状 | 归属 |
|---|---|---|
| `resources/views/layouts/site.blade.php` 头部 `$siteSettings` 兜底 | title 后缀拼接、description / og:image / canonical 的 `??` 兜底分支。**仅对未迁移 Controller 可达**；核心页（Home/Content/知识文章）已由 HTTP 测试证明不触达兜底。移除兜底 = 强制同步迁移全部未迁移 Controller，违反本阶段禁令 | 5.10 Blade SEO 输出层 |
| `PageController::renderCategory()` 栏目列表页 seo 数组 | categories 无 SeoMeta 绑定，列表页契约未冻结 | 5.9–5.12 列表页迁移 |
| `KnowledgeController::render()` 知识列表页 seo 数组 | 同上 | 5.9–5.12 列表页迁移 |
| `Content::metaTitle() / metaDescription() / canonicalUrl()` | legacy 辅助方法，Site Controller 已不调用；唯一消费方 SchemaBuilder | 5.7 SchemaBuilder |
| About / Contact / Factory / Cooperation / Product / Solution / Search 七个 Controller 自建 seo 数组 | 未迁移 | 5.9–5.12 |

### 4.3 架构守护测试

`SeoHttpIntegrationTest::test_core_controllers_do_not_reimplement_seo_resolution`：

- HomeController 源码必须包含 `SeoMetaResolver` + `resolveSite(`
- HomeController 源码不得包含 `Setting::get('site_name'` / `Setting::get('seo_default_desc'`
- PageController 源码必须包含 `resolveContent(`
- PageController 源码不得包含 `metaTitle()` / `metaDescription()` / `canonicalUrl()`

---

## 5. Business Pollution（本轮新增代码）

扫描对象：本轮新增/修改的 SEO 集成代码
（PageController 修复、SeoHttpIntegrationTest、本文档代码引用）。

结果：业务关键词（example / sample-city / sample-province / Example / Sample Snack / Sample Marinade）**0 命中**。

存量命中说明（非本轮新增，均有归属）：

- `HomeController` 内 Facts 驱动的产品 slug 与区块文案：普通业务内容，豁免。
- `KnowledgeController:100` 知识列表页描述文案：列表页过渡 SEO，
  随 5.9–5.12 列表页迁移一并处理。

---

## 6. Regression

```
php artisan test
Tests:      510 passed (500 基线 + 10 新增 HTTP 集成测试)
Assertions: 1797 (1738 基线 + 59 新增)
Failed:     0
Skipped:    0
```

新增测试说明：本阶段目标即为补齐 HTTP 层真实集成证据，
10 个新用例覆盖 Home / Content / SeoMeta Override / Fallback 链 /
Canonical 最终 HTML / OG Image 最终 HTML / Knowledge 转发链路 /
核心 Controller 架构守护。

---

## 7. Git

```
4124510 feat(step5.6-d): integrate SeoMetaResolver into HTTP controllers  ← 5.6-D 主体
<本次>  test(step5.6-d): add HTTP integration evidence + fix renderCategory resolver arg
```

Working tree clean（提交后）。
