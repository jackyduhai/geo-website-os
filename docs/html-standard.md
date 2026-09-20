# GEO Website OS HTML Standard

> 适用：所有 Blade 模板（前台站点、后台、错误页、安装页、官方/第三方主题）。
> 目标：语义正确、可访问、SEO/GEO 友好、数据驱动。视觉规则见 `docs/design-system.md`、`docs/css-standard.md`，本文件只管**结构与语义**。

---

## 1. 文档骨架

每个完整 HTML 文档必须包含：

- `<!DOCTYPE html>`、`<html lang="...">`（按站点语言输出，默认 `zh-CN`）、`<meta charset="utf-8">`。
- `<meta name="viewport" content="width=device-width, initial-scale=1">`。
- 唯一 `<title>`、`meta description`、`canonical`（见 §7 SEO head）。
- favicon / apple-touch / og 默认图引用产品中性资源，站点可覆盖。
- 不加载客户专属第三方脚本、统计、字体、客服组件；第三方脚本由部署者显式配置。

## 2. 语义结构与 Landmark

使用 HTML5 语义标签表达页面骨架，而不是全 `div`：

```html
<header class="hd">            <!-- 站点头部 -->
  <a class="logo" href="/" aria-label="首页">…</a>
  <nav class="nav" aria-label="主导航">…</nav>
</header>
<main>                          <!-- 每页恰好一个 <main> -->
  <section class="hero">…</section>
  <section>…</section>
</main>
<footer class="ft">…</footer>   <!-- 站点页脚 -->
```

- Landmark 齐全：header / nav / main / footer；侧栏用 `<aside>`；独立内容块用 `<article>`（知识文章、案例、产品详情）。
- 通用容器类 `.wrap / .wrap-wide / .wrap-narrow` 只负责宽度居中，不替代语义标签。
- 列表用 `<ul>/<ol>/<li>`，键值对用 `<dl>/<dt>/<dd>`，数据用 `<table>`（见 §6），不要用一堆 `<div>` 模拟。

## 3. 标题层级

- 每个页面**恰好一个 `<h1>`**，描述当前页主题。
- 层级连续不跳级：h1 → h2 → h3，不出现 h1 直接到 h4。
- 区块标题用 `<h2>`，卡片/条目标题按所在层级用 h3/h4；标题用于结构，不用于"调字号"（字号交给 class）。
- logo / 卡片标题不得滥用 h1。

## 4. 链接与按钮（不做假交互）

- **导航、跳转、打开资源 → `<a href>`**；**提交表单、触发动作、切换状态 → `<button type="...">`**。
- 禁止用 `<div onclick>`、`<span onclick>` 模拟按钮/链接；禁止用 `<a href="#">` 充当 JS 按钮（应使用 `<button type="button">`）。
- 外链、文件下载加必要语义：下载用 `download`，新窗口用 `target="_blank" rel="noopener"`。
- 图标按钮必须提供可访问名：`aria-label` 或 visually-hidden 文本。
- 当前导航项使用 `aria-current="page"`（项目当前以 `.on` 表达选中，应同时具备 `aria-current` 语义）。

## 5. 表单

- 每个控件都有关联的 `<label for>`；不要只用 placeholder 当标签。
- 使用正确的 input 类型与属性：`type=email/tel/search/url/number`、`name`、`autocomplete`（如 `name`、`email`、`tel`、`organization`）、`required`、`maxlength`、`inputmode`。
- 表单包含 `@csrf`；防垃圾提交使用既有 honeypot + 时间戳机制，不引入客户专属服务。
- 错误提示就近、与控件用 `aria-describedby` / `aria-invalid` 关联；提交失败保留用户输入与可读错误（422 回填）。
- 必填、计数、协议勾选等约束在前后端一致；后端校验为最终事实，前端约束只改善体验。
- 按钮明确 `type`：提交 `type="submit"`，纯交互 `type="button"`，避免误触发表单。
- 成功/失败状态与重试（429）有清晰、通用、无客户信息的提示。

## 6. 表格与数据

- 真数据表格用 `<table><thead><tbody><tr><th scope="col">`，行标题用 `scope="row"`。
- 窄屏宽表格外包可横向滚动容器（项目 `.prose table{display:block;overflow-x:auto}`），不截断数据。
- 参数表（如产品参数）用语义表格或 `<dl>`，不用截图或图片代替文字数据（利于 GEO/SEO 抓取）。

## 7. SEO head（与 SeoMetaResolver 契约一致）

每页 `<head>` 输出且仅输出一套规范 meta，数据来自统一解析结果（SeoMetaResolver / UrlResolverInterface），Blade 不自行拼接业务 SEO：

- `<title>`：经解析链得到的标题。
- `<meta name="description">`：经解析链得到的描述。
- `<link rel="canonical">`：
  - 绝对 URL、**强制 https**；
  - **剥离 query string**；
  - 首页以 `/` 结尾，其余页面**不带尾斜杠**；
  - 自定义 canonical 优先级最高；
  - 严格使用当前站点域名，**禁止跨站 canonical**。
- robots：`<meta name="robots" content="...">`，综合 noindex/nofollow；草稿/归档不进入索引。
- Open Graph：`og:title`、`og:description`、`og:type`、`og:url`、`og:image`（按 OG Image 继承链：SeoMeta → Content 媒体 → Entity metadata → Site logo → null；无图则不输出空标签）。
- Twitter Card：`twitter:card` 及对应标题/描述/图。
- 多语言/多站用规范的 `hreflang`（如启用），不臆造。
- head 中不出现客户公司名、电话、地址等写死兜底；缺失走系统安全默认。

## 8. JSON-LD / 结构化数据

- JSON-LD 由 `SchemaBuilder` 基于 Site / Content / Entity / EntityRelation / SeoMeta / Media 统一生成，**Blade 不手写具体业务 schema**。
- 一个页面的 JSON-LD 与可见内容一致，不输出与页面无关的实体（避免 schema 造假）。
- Entity↔Content 是平行资源：Content SEO 不回退读取 Entity（见 SeoMeta 架构契约）；跨资源关联只能基于正式 Relation。
- 输出位置与格式固定（`<script type="application/ld+json">`），由 GEO/Schema 层负责，模板只负责放置，不内联业务事实。

## 9. 图片与媒体

- 内容图必须有 `alt`（描述性）；纯装饰图 `alt=""`。
- 优先使用 `<picture>` / `components/picture.blade.php`：提供合适格式与尺寸、`loading="lazy"`（首屏图除外）、`width/height` 防止布局偏移（CLS）。
- 图标用内联 SVG（线性、`currentColor`），不用位图当图标、不用 emoji 当功能图标。
- 媒体缺失时优雅降级（占位或整块隐藏），不输出客户 logo / 客户二维码兜底。
- 视频/音频提供可访问替代（字幕、控件、暂停），不自动播放有声媒体。

## 10. ARIA 与可访问性

- **优先原生语义**：原生 `button/nav/main/table/form` 已具语义时不重复加 `role`。
- 仅在原生不足时补 ARIA：折叠导航 `aria-expanded` + `aria-controls`；轮播 `aria-label`、指示器与幻灯片关联；弹层 `role="dialog"`、焦点管理、Esc 关闭。
- 图标按钮 `aria-label`；动态区域（搜索结果、表单消息）必要时 `aria-live="polite"`。
- 颜色不单独承载信息；文字对比达 AA；焦点环可见（见 css-standard §6）。
- 保证键盘可达与合理 Tab 顺序；轮播/菜单可被键盘操作或提供等效入口。

## 11. Blade 模板规范

- 默认转义输出 `{{ $var }}`；仅对**可信、已净化**的 HTML（如经治理的富文本正文）使用 `{!! !!}`，且来源必须经过净化，绝不直接输出用户原始 HTML。
- 复用组件 / 局部视图：`_product_card`、`_scene_card`、`_lead_form`、`_bottom_cta`、`components/picture`、`partials/nav-icon`；不在多页复制同一段结构。
- 数据为空时整块不渲染（`@forelse/@empty`、`@if(!empty(...))`），不输出空 `<ul></ul>`、空标题、空 href。
- 模板只做展示：不在 Blade 写业务查询 / 跨站数据访问 / 直接读引擎内部；数据由 Controller / Resolver 规范化后传入（主题只消费 `$seo` 与视图数据）。
- URL 一律经 UrlResolver / 路由命名生成，不硬编码域名、不拼接客户旧站路径。
- 不在模板写死公司名、产品名、电话、地址、行业词；示例文案来自通用配置 / Example 数据。
- JS 行为与结构分离：不写内联 `onclick=""`，使用外部脚本 + 事件委托 / `data-*` 钩子。

## 12. 错误页与状态

- 403 / 404 / 500 / 503 提供完整、中性、品牌一致的页面：产品名、简短说明、返回首页 / 搜索入口；**不暴露异常堆栈、路径、客户信息**。
- 空结果（搜索无命中、暂无内容）提供 Empty State：说明 + 建议动作，不白屏。
- 加载与失败状态在需要异步的区域可见、可理解。

## 13. 禁止清单（评审硬指标）

- 全 `div` 结构、缺失 main/nav/footer、多个 h1 或标题跳级。
- 假按钮（div/span + onclick）、假链接（`a href="#"` 当按钮）。
- 无 label 控件、placeholder 代标签、错误无关联提示。
- 手写业务 JSON-LD、Blade 内拼接 canonical / 业务 SEO、跨站 canonical、带 query 的 canonical。
- 内联 onclick、内联 style 写视觉、未转义输出用户 HTML。
- 内容图缺 alt、客户 logo / 二维码 / 公司名 / 电话 / 地址写死兜底。
- 错误页泄漏堆栈或客户信息。

## 14. 评审检查单

1. 文档骨架、lang、viewport、SEO head 是否完整规范？
2. 是否恰好一个 h1、标题不跳级、landmark 齐全？
3. 链接与按钮元素是否各归其位、键盘可达、焦点可见？
4. 表单 label / 类型 / CSRF / 校验回填 / 错误关联是否到位？
5. canonical（https、无 query、斜杠规则、当前站点）与 robots/og 是否正确？
6. JSON-LD 是否来自 Builder 且与可见内容一致？
7. 图片 alt / lazyload / 尺寸、媒体缺失降级是否处理？
8. 模板是否纯展示、数据为空不输出空标签、无客户硬编码？
