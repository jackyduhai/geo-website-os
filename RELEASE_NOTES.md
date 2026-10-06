# GEO Website OS — Release Notes

## v1.0.0

首个正式发布。架构冻结，全部工程 Gate 通过。

---

## 范围

| 能力 | 说明 |
|---|---|
| **Multi-site** | 主机名解析 + 站点级数据隔离 + 站点感知缓存。跨站查询参数也无法绕过隔离 |
| **Multi-language** | `zh-CN`（默认）+ `en`，建站时按站点启用。内容/实体/事实/分类采用**行级翻译模型**（`locale` + `translation_group`），同一 slug 可跨语言共存（`UNIQUE(site_id, slug, locale)`）。未启用的语言请求返回 404，不静默回落 |
| **CMS** | 内容、实体、关系、媒体、导航、分类、分组、表单、询盘、治理后台 |
| **SEO** | canonical、Open Graph、JSON-LD、sitemap.xml、feed.xml、robots.txt、hreflang |
| **GEO** | `geo.json`、`llms.txt`，与 SEO 同源派生 |
| **GEOFlow** | 上游内容同步 API：幂等指纹、字段契约校验、人工锁、kill switch、修订快照、同步日志 |
| **Site Administration** | 站点列表/新建/编辑/启停/默认站切换/域名 |
| **Site Bootstrap** | 新站自动初始化：分类骨架、分组、导航可见性、默认设置、系统页、双语开关。**零 SQL 手工干预** |
| **Fresh install** | `php artisan geo:install` 一键安装 |
| **Upgrade** | 56 个有序幂等迁移，up/down 均受测|
| **Rollback** | 迁移可逆，schema / 数据 / i18n / 站点隔离保真 |

---

## 技术基线

```text
PHP          8.4+
Laravel      ^12.0
SQLite       默认（零配置），或任何 Laravel 支持的数据库
Node.js      20+（仅用于构建前端资源，发布制品不依赖）
Migrations   56
```

---

## 安装

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan geo:install --site-name="Your Site" --site-domain="example.com" \
    --admin-email="admin@example.com" --admin-password="<strong-password>"
```

建站（在后台完成，或直接 `POST /admin/sites`）后即自动完成 bootstrap：
分类骨架、导航、默认设置、系统页与双语开关全部就绪，可直接开始建内容。

> **生产环境**请在 `.env` 中设置 `SITE_DEFAULT_FALLBACK=false`，
> 使未知 Host 返回「站点不存在」而不是静默回落到默认站。

---

## 升级与回滚

```bash
php artisan migrate            # 升级
php artisan migrate:rollback --step=1   # 回滚
```

详见 `DEPLOY.md`。

---

## 已知非阻塞项

以下均为**已分类的已知项**，不构成发布阻塞：

| 级别 | 项 | 说明 |
|---|---|---|
| **P2** | Settings localization 4 键 | `site_name` / `site_description` / `brand_display_name` / `contact_address` 在 `settings` 表中无 `locale` 列，故同站 zh/en 共用一个值。站点之间的隔离是完整的（DB 层严格独立），仅「同站跨语言」这一维度未实现 |
| **INFO** | Categories / Groups 翻译模型 | 采用**字段级** `name_en` 形式，属v1.0 的**架构选择**，并非「未完成的第三语言模型」。内容与实体采用行级模型，两者并存是刻意的 |
| **P3** | PHPUnit `@test` 元数据弃用 | 测试写法在 PHPUnit 12 的兼容性提醒，不影响运行 |
| Env | Laravel file-cache 残留 | 测试环境问题，清理缓存目录即可，非产品缺陷 |
| Env | 开发库迁移漂移 | 记录于 `docs/audit/RC/RC-0-Environment-Drift-Addendum.md`，无数据丢失、无产品影响 |

---

## 质量证据

| Gate | 结果 |
|---|---|
| RC-1 全量回归 | 1401 tests / 7290 assertions / 0 failures |
| RC-2 Fresh / Upgrade / Rollback | 95 assertions 全绿（56 迁移三态往返 + 四象限） |
| RC-3 Site × Locale 四象限 | 130 assertions 全绿（17 维度，二维隔离零泄漏） |
| RC-4 Security / GEOFlow Contract | 47 assertions 全绿（含「一次写入 = 一次缓存失效」计数验证） |
| RC-5 Installation / Operational UX | 57 + 1410 tests / 7349 assertions / 0 failures |
| RC-6 Release Artifact Audit | 9 审计面通过 ·干净副本完整复现安装→GEO→回滚链 |
| RC-7 Final Release | 1410 tests / 7349 assertions / 0 failures · `composer audit` 0 advisories |
| RC-8 Public Boundary Redaction | 165 commits / 61 tags / 1961 blobs 全历史重写，真实业务标识残留 **0** |
| RC-9 Final Acceptance | 25 Gate 验收；发现并修复 GEO 事实源分裂P0，新增跨出口契约测试 |

工程 Gate 文档位于 `docs/audit/RC/`。

---

## AI 事实口径契约

GEO WebsiteOS 同时服务两类消费者：访客（Human）与检索引擎 / 大模型（AI）。
为保证两者看到**同一个真实世界**，系统冻结了三条口径规则：

```text
1. facts 表是「业务事实」的唯一权威源（Business Fact Canonical SoT）。
   geo.json 与 llms.txt 的事实出口都必须经Fact::publicRows($locale) 取得，
   不得自行读取另一套事实数据源。

2. SchemaBuilder 不直接读 facts。JSON-LD 的实体属性类字段
   （legalName / address / areaServed / knowsAbout）仍由站点 metadata 驱动。
   这样做的原因是 Schema.org 字段集与业务事实表不是一一对应，
   硬映射会产生「schema 里有、页面上没有」的字段。

3. 口径分层不等于允许事实分叉。所有 AI 出口不得输出互相矛盾的事实，
   同一条事实的取值与标签必须可对齐 —— 由契约测试强制。
```

新增公开事实时必须同时在 `database/seeders/FactSeeder.php` 与
`app/Services/Geo/FactLabels.php` 登记，否则
`tests/Feature/Geo/GeoFactConsistencyTest.php` 会失败并指出缺口。

> 这套契约是在 RC-9 验收中发现的真实 P0 演化而来：修复前，同一批事实
> （成立时间 / 面积 / 产能 / 总投资）同时存在于 `facts` 表与
> `organization.metadata` 两处，且 locale 覆盖不同 ——
> 英文站 `geo.json` 的 facts 为空、`llms.txt` 却能输出英文事实，
> AI 同时消费两端会得到矛盾答案。
> 完整根因与方案见 `docs/audit/RC/D-02-Architecture-Decision-Lock.md`。

---

## 公开边界说明

本仓库在首次公开推送前做过一次完整的历史脱敏重写，详见 `docs/audit/RC/RC-8-Public-Boundary-Redaction.md`。

```text
重写范围      165 commits · 61 tags · 1961 blobs
替换口径      客户身份 → Demo Tenant A/B/C；行业/地点/第三方 → Sample *
产品代码      0 处改动（app/ config/ database/ resources/ routes/ scripts/ public/ 零命中）
全历史残留    0
```

需要如实说明的一点代价：测试中的业务污染黑名单词表，在脱敏后守护的是**中性 fixture 词**而非原始标识。结构性约束（示例数据必须来自 Seeder、禁止硬编码）完整保留，但词表级护栏的特异性有所下降。恢复特异性的正确做法是把可疑词表外置为本地配置，而不是把真实标识写回公开仓库。

`docs/audit/` 通过 `.gitattributes` 的 `export-ignore` 不进入发布制品包。

---

## 运行依赖

必需：PHP 8.4+、Composer 2、数据库（SQLite 零配置或任何 Laravel 支持的数据库）。

**Node.js / npm 不是运行必需**。前台主题以内联 CSS 形式随 Blade 布局发布，
后台样式为 `public/css/admin.css`，无任何 Blade 视图引用 `@vite`。
仅当你要自行构建 Tailwind / Vite 资产时才需要：

```bash
npm install && npm run build
```

生产部署不需要 Node.js，详见 `DEPLOY.md`。
