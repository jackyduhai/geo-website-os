# STEP 04 — Configuration & Environment Contract

> 配置优先级（冻结）：Environment(.env) → System Config(config/) →
> Site Config(sites/settings) → Content/Entity → Runtime fallback

## 1. config 分层定性

| config | 定性 | 消费方 | 契约 |
|---|---|---|---|
| app/cache/database/filesystems/session/queue/auth/mail/logging/services | Laravel 通用 | 框架 | 保留 |
| geo.php / site.php | 通用（爬虫策略/站点模式） | FeedController / SiteResolver | 保留 |
| facts.php | **业务数据**（Demo） | `Facts` 访问器（全部 `?? []` 降级） | 缺席 → 访问器返回空集，Core 不抛错 |
| copy.php | **业务文案** | `Copy` + AppServiceProvider CTA | 缺席 → 空集；CTA 兜底改为通用 `Contact us` |
| pages.php / home_blocks.php / icons.php | **业务页面配置** | 业务 Controller / HomeBlockDefaults | 缺席 → 空集 |

## 2. 降级契约（测试锁定）

业务页（固定 IA 页）在其数据源（facts/pages config）缺席时的行为是
**404 不渲染**，而非 500 抛错——数据缺席 = 页面无意义。

本轮修复的降级缺口：

| 位置 | 原行为 | 修复 |
|---|---|---|
| `LlmsBuilder::build()` | 空 facts 下 `$company['name']` 抛 ErrorException | 无业务事实 → 输出通用骨架（站点名 + 真实数据库内容入口 + 引用须知），不编造事实 |
| `FactoryController` | 硬取 `$company['tech_experience_years']` 等 → 500 | 空 company → 404 |
| `CooperationController` | 视图层假设 $coop 键存在 → 500 | 空 coop → 404 |
| `ContactController` | `$company['name']` → 500 | 空 company → 404 |
| `AboutController` | 空 $copy 时页面空壳 | 空 company/copy → 404 |
| `AppServiceProvider` | CTA 兜底文案硬编码业务词 | → `Contact us` |

## 3. 实测证据（ConfigResilienceTest，3 用例）

运行时卸载 `facts/copy/pages/home_blocks` 四个业务 config：

- GET `/` = 200，title / canonical / robots 完整（站点级 Resolution 供给）；
- sitemap.xml / robots.txt / llms.txt / geo.json / api/v1/health 全部 200；
- 业务路由（products/solutions/factory/cooperation/about/contact）
  全部 ∈ {200, 404}，零 500。

`php artisan optimize` / `optimize:clear` 循环正常（config 缓存可构建）。

## 4. 移交与不做

- **不删除业务 config 文件**：它们是 Demo/业务数据层的正式载体（P-STEP 01 审计决议），
  STEP 05 Theme 化后随主题包/示例站点归属；删除后系统已可启动（本 STEP 证据）。
- `Facts::CORE_PRODUCTS` 白名单在 routes/web.php 构建路由白名单——config 缺席时
  白名单为空 → 产品详情路由自然 404（优雅降级，无需改动）。
