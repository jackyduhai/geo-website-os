# STEP 01 — Open-source Boundary Audit（Generic vs Business-specific）

> 性质：只读审计，不做代码修改。基准：acceptance-baseline（05a369e，571/2211/0/0）。
> 本文档是后续所有产品化 STEP 的边界依据：**处理方式列 = 决议**。

## 1. 分层总览

| 层 | 判定 | 说明 |
|---|---|---|
| `app/Models`、`app/Services/Seo`、`app/Services/Geo/SchemaBuilder+GeoGraphBuilder+SitemapBuilder`、`app/Http/Middleware`、`app/Http/View`、`app/Contracts`、`app/Repositories` | **通用（Generic）** | 引擎核心；业务关键词 0（SiteCacheKey 中 legacy 清理列表中的历史 key 字符串除外，见 §3） |
| `app/Support/Facts`、`HomeBlockDefaults`、`Copy`、`Narrative` + `config/facts.php`、`config/copy.php`、`config/pages.php`、`config/home_blocks.php`、`config/icons.php` | **业务数据访问/配置层** | 业务数据 API 与文案；开源化需隔离为 Demo/Business 包（STEP 02/04） |
| `app/Http/Controllers/Site`（9 个业务 Controller）、`resources/views/site` + `layouts` + `errors`、`public/img`（logo/wechat-qr） | **业务展示层（= 当前默认主题 + 业务页面）** | 5.6-D/STEP 08 审计的固定 IA 页；STEP 05 Theme 化的对象 |
| `app/Services/Geo/LlmsBuilder` | **业务内容生成器** | llms.txt 叙述口径（v0.7 冻结），STEP 04/05 决定归属（Demo 层或以 Contract 重写） |
| `app/Http/Controllers/Admin` + `resources/views/admin` + `routes/admin.php` | **通用（带业务文案）** | 后台框架通用；页面标题/文案属可运营配置 |
| `database/migrations`、`app/Models` schema | **通用** | 与业务数据无关 |
| `database/seeders`（5 个） | **业务 Demo 数据** | 全部业务内容；STEP 02 重组为 Demo/Example Seeder |
| `routes/web.php` | **混合** | feed/search/admin 通用；products/solutions/factory 等业务路由绑定业务 Controller（Theme/路由边界，STEP 05） |
| `tests/` | **通用回归** | 引擎测试通用；页面文案断言依赖 Demo 数据（STEP 02 保持隔离） |
| `docs/`、`config/geo.php`、`config/site.php`、`config/app.php` 等 Laravel 标准 config | **通用** | geo.php 爬虫策略是通用配置 |

## 2. 业务关键词分布实测（example/sample-city/sample-province/Example/Sample Snack/Sample Marinade/orleans）

| 位置 | 命中 | 定性 |
|---|---|---|
| `config/facts.php` | 192 | 业务事实库（Demo 数据层，设计归属） |
| `config/pages.php` | 34 | 业务页面文案 |
| `config/copy.php` | 16 | 业务文案 |
| `app/Support/Facts.php`（CORE_PRODUCTS 等白名单） | 有 | 业务数据访问层 |
| `app/Support/HomeBlockDefaults.php` | 有 | 业务缺省文案 |
| `app/Http/Controllers/Site/*`（9 个） | 有 | 业务页面 Controller |
| `app/Services/Geo/LlmsBuilder.php` | 6 | 业务叙述生成 |
| `resources/views/site/**` + `layouts` + `errors` + `admin` | 多文件 | 展示文案（主题/后台文案） |
| `app/Support/ExampleUrlGenerator.php` + `AppServiceProvider` 引用 | 有 | **P0-A**（STEP 07 消解） |

## 3. ⚠️ 核心层真实污染点（本次审计新发现，需在后续 STEP 消解）

| # | 位置 | 问题 | 消解 STEP |
|---|---|---|---|
| C1 | `Api/HealthController:19` `'service' => 'example-site'` | 健康检查端点硬编码业务服务名（通用运维接口） | **✅ STEP 02 已修**（→ `geo-os`，FreshBootTest 锁定） |
| C2 | `Support/SystemAuthorization:42` `admin@example.test` 硬编码 email 授权 | 超管判定绑定业务种子账号，安装器无法用其他管理员邮箱 | STEP 03（Bootstrap Contract：授权规则改为角色/标记，不绑邮箱） |
| C3 | `public/img/wechat-qr.png` 等业务资产 | 业务二维码/logo 在通用 public 目录 | STEP 05（Theme 资产边界：随主题归属迁移） |
| C4 | `SiteCacheKey:125` `'example.settings'` | legacy 清理列表中的历史 key 字符串——仅用于 forget 旧 key，属兼容清理数据而非运行时 key 生成；保留但需注释定性 | 无需修改（已注释） |
| C5 | `CanonicalizeSlash` 注释中 `orleans-801` 示例 | 纯注释示例 | **✅ STEP 02 已中性化** |
| C6 | 历史迁移 `000011 / 000012 / 000013 / 000015` 种业务文案 | 迁移层携带业务装修文案（新发现） | **✅ STEP 02 已中性化**（产品无外部部署，无升级兼容风险；ADR 见下） |

> **ADR（P-STEP 02）：历史迁移数据种子原地中性化。**
> 依据：产品未对外发布、无已迁移的外部环境，修改迁移字符串值不产生
> schema/升级兼容风险；而「fresh install 含业务文案」直接违反 STEP 03 Gate。
> 仅替换字符串值，不改结构、不改字段；受影响的两个测试断言随预期行为同步更新。

## 4. Demo / Fixture 边界（现状 → STEP 02 目标）

```text
现状：
  database/seeders/*       = 业务 Demo 数据，且 DatabaseSeeder 无条件装载
  config/facts|copy|pages  = 业务数据，被 Core Support 类硬依赖
  tests/                   = 依赖上述业务数据渲染

目标（STEP 02+）：
  Core Runtime（空库 + migrate + bootstrap）→ 无业务数据可启动
  Demo/Example（ExampleSeeder 等）→ 显式装载才存在
  业务 config → 从 Core 依赖中解耦（STEP 04 契约化）
```

## 5. Gate 自检

- 通用层清单完整 ✅（§1）
- 业务层清单完整 ✅（§1/§2）
- Demo/Fixture 边界明确 ✅（§4，DemoSeeder 落地）
- Core pollution：C1/C5/C6 已消解（STEP 02），迁移种子与运维端点实测 0 业务文案；
  C2/C3 按计划移交 STEP 03/05。
