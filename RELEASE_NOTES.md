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

工程 Gate 文档位于 `docs/audit/RC/`。
