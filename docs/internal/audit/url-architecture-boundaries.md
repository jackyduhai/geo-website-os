# STEP 04 — Generic URL / Route / Redirect Architecture（责任边界）

> 目标：URL 体系必须责任明确，禁止多套系统无边界并存。

## 1. 当前 URL 系统清单与责任边界（冻结）

| 系统 | 实现入口 | 职责 | 消费者 | 生命周期 |
|---|---|---|---|---|
| **Canonical 解析** | `UrlResolverInterface` → `GenericUrlResolver` | SEO 规范地址（HTTPS + Site.domain + 资源类型 + slug；无查询串；首页尾斜杠） | `SeoMetaResolver` → `SeoResult.canonical` → Blade `<link rel="canonical">` / `og:url` | 长期（冻结契约 5.6-A §5） |
| **站内业务 URL** | `ExampleUrlGenerator`（`AppServiceProvider` 中全局替换框架 `url` 服务） | 站内链接/跳转/资源地址（业务方案） | 全部 `url()` 调用（Controller / Blade / Middleware） | 至 5.12（通用 UrlGenerator 替换） |
| **语义公开路径** | `Content::url()` / `Category::url()` | 公开访问地址 = 栏目路径 + slug（面包屑、sitemap、内链、路径校正 301） | `PageController`（301 校正、面包屑）、`SitemapBuilder`、`SchemaBuilder`、`FeedController` | 长期（语义地址即真实地址） |
| **地址规范化** | `CanonicalizeSlash` 中间件 | 尾斜杠 301（目录型带 `/`、详情型不带），全站唯一一处 | HTTP 管道 | 长期 |
| **旧站跳转** | `HandleRedirects` 中间件（后台 301/302 规则，缓存驱动） | 旧 URL → 新 URL，先于一切页面渲染 | HTTP 管道 | 长期 |

## 2. 已知双轨：语义路径 vs Canonical 契约形态

同一内容页存在两个地址形态，**职责不同、不允许混淆**：

```
语义公开路径：/{category_path}/{slug}      —— 用户与内链使用（Content::url()）
Canonical：   /{content_type}/{slug}       —— 搜索引擎规范地址（GenericUrlResolver）
```

- 该形态差异源自 5.6-A 冻结契约（canonical = `/{content_type}/{slug}`），
  本阶段**不得**为消除双轨而擅改契约或擅改语义路径。
- HTTP 层证据（`UrlArchitectureTest`）：语义路径 200 且 canonical 由 Resolver
  生成；错误栏目路径 301 校正到语义路径；canonical 与 301 互不影响。
- 收敛方向（5.12）：通用 UrlGenerator 统一语义路径与 canonical 生成，
  由独立架构阶段处理。

## 3. 行为规则（HTTP 实测锁定）

| 规则 | 锁定测试 |
|---|---|
| 尾斜杠：目录型 301 补 `/`，详情型 301 去 `/` | `CanonicalizeSlashTest`（单测，等价 Request 构造） |
| 后台 301/302 规则先于页面渲染、查询串透传、防自跳 | `RedirectMiddlewareTest` |
| 内容经错误栏目路径 → 301 到语义规范路径 | `UrlArchitectureTest` |
| 未知路径 → 404（内容/栏目双不匹配） | `UrlArchitectureTest` |
| Canonical：HTTPS / 无查询串 / 首页尾斜杠 / 显式覆盖最高 | `SeoMetaResolverTest` + `SeoHttpIntegrationTest`（最终 HTML） |
| 跳转规则缓存随模型事件失效、多站隔离 key 含 site_id | `HandleRedirects` + `SiteCacheKey`（既有实现） |

## 4. 本阶段不做（防越界）

- 不替换 `ExampleUrlGenerator`（5.12；替换前需先建立行为对拍测试）。
- 不修改 Canonical 形态契约。
- 不在 Controller/Blade 新增任何 URL 拼装（已由 STEP 02 消除 Blade 拼装）。
