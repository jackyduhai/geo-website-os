# Routes Coupling Analysis

## 文件清单

- `routes/web.php` — 前台路由
- `routes/admin.php` — 后台路由
- `routes/api.php` — API 路由
- `routes/console.php` — 命令行路由

## web.php 业务耦合分析

| 检查项 | 结果 | 位置/证据 |
|--------|------|-----------|
| 业务 slug 白名单 | **存在** | L47-49: `$lineRe`, `$coreRe`, `$sceneRe` 从 `Facts::productLines()`, `Facts::CORE_PRODUCTS`, `Facts::scenes()` 生成正则 |
| 直接依赖 Facts | **存在** | L47-57 产品/场景路由的 where 条件使用 Facts 生成的 slug 集合 |
| Example 专属 URL 前缀 | 存在 | `/products/`, `/solutions/`, `/factory/`, `/cooperation/`, `/about/` |
| 硬编码产品 | 存在 | `Facts::CORE_PRODUCTS` 常量定义6款核心产品 slug |
| 硬编码页面 | 存在 | `/about/profile`, `/about/history`, `/about/culture`, `/factory`, `/cooperation` |
| 动态 Route 注册 | **不存在** | 无 boot 动态注册，路由在文件中静态定义 |
| catch-all route | 存在 | L96: `Route::get('/{path}', [PageController::class, 'dispatch'])` |
| Route Model Binding | 不存在 | 控制器手动查库 |
| 历史 URL 兼容 | 存在 | `/scenarios` → `/solutions` 301 重定向 |
| Controller 直接读业务配置 | 存在 | ProductController, SolutionController, HomeController 等 use Facts |

## 当前 URL 结构

```
/                          → 首页
/products/                 → 产品列表
/products/{line}/          → 产品线（Facts 生成 slug 白名单）
/products/{slug}           → 产品详情（Facts::CORE_PRODUCTS 白名单）
/solutions/                → 场景列表
/solutions/{slug}          → 场景详情（Facts::scenes() 白名单）
/knowledge/                → 知识列表
/knowledge/{slug}          → 知识文章
/about/profile             → 公司简介
/about/history             → 发展历程
/about/culture             → 企业文化
/factory                   → 工厂实力
/cooperation               → 合作方式
/contact                   → 联系我们
/{path}                    → catch-all → PageController
```

## 与 v1.2 稳定 URL 设计的 GAP

| 维度 | 当前 | v1.2 目标 | GAP |
|------|------|-----------|-----|
| 路由注册 | 静态文件定义 | 静态文件定义 | ✅ 一致 |
| slug 来源 | Facts:: 代码生成 | 数据库 entities 表查询 | ❌ 需改 |
| URL 前缀 | 硬编码 /products/ /solutions/ | 可配置，默认 /products/ /services/ | ❌ 需抽象 |
| 白名单机制 | 路由 where 正则 | SeoUrlResolver 查库 | ❌ 需改 |
| catch-all | 存在，PageController | 保留，PageResolver | ✅ 可保留 |
| 历史 URL | /scenarios → /solutions 301 | redirects 表管理 | ❌ 需迁移 |

## admin.php / api.php

- admin.php：后台路由，无业务 slug 硬编码，使用 EnsureAdmin 中间件保护。
- api.php：GEOFlow API 等，无业务 slug 硬编码。

## 结论

路由结构本身是稳定的（静态定义，无动态注册），符合 v1.2 方向。核心问题是 **slug 白名单来自 Facts:: 代码而非数据库**，以及 **URL 前缀硬编码**。这两处需要在 Step 5 中改为 SeoUrlResolver 查库 + 可配置前缀。
