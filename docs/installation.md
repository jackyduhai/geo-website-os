# 安装与部署教程

> 版本口径：v1.4.0。本文给出一条「本地 5 分钟跑通」路径与配置要点；
> 生产部署（Nginx + PHP-FPM + 宝塔 / 云端）见 [DEPLOY.md](../DEPLOY.md)；
> 三种数据库的安装 / 升级 / 备份 / 恢复逐条命令见 [数据库支持矩阵](operations/database-support-and-recovery.md)。

## 1. 环境要求

| 依赖 | 版本 | 说明 |
| --- | --- | --- |
| PHP | **8.4+** | 扩展：`mbstring`、`openssl`、`tokenizer`、`xml`、`ctype`、`json`；按数据库加 `pdo_sqlite` / `pdo_pgsql` / `pdo_mysql`；建议 `gd`、`bcmath`、`zip` |
| Composer | 2.x | 依赖管理 |
| 数据库 | 见矩阵 | SQLite（默认零配置）或 PostgreSQL 16 / MySQL 8.0+ |
| Node.js | 20+（**可选**） | 仅当你需要自行构建 Tailwind/Vite 前端资产；**运行与发布制品不依赖 Node** |

## 2. 本地快速开始（SQLite，约 5 分钟）

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite

# 一键安装：迁移 → 默认站点 → 出厂结构 → 默认联系表单 → 系统页 → 栏目骨架
# → 搜索索引 → 管理员（未指定 --admin-password 时随机生成并打印一次）
php artisan geo:install \
    --site-name="我的站点" \
    --site-domain="example.com" \
    --admin-email="admin@example.com"

php artisan serve
```

安装完成后：

- 前台首页：`http://127.0.0.1:8000/`
- 后台登录：`http://127.0.0.1:8000/admin`
- 机器可读出口：`/geo.json`、`/llms.txt`、`/sitemap.xml`、`/feed.xml`、`/robots.txt` 均可访问

> 安装器创建的默认站点是**空站**（行业中性的出厂结构，无业务演示内容）。
> 想要通用 Example 演示数据（开发/演示环境），执行：

```bash
php artisan db:seed
```

> 该命令会装载 DemoSeeder（通用 Example 数据集）、默认表单、系统页与演示管理员
> `admin@example.com / Admin@123456`（**仅限本地演示，登录后立即修改**）。
> 代码注释口径：演示数据仅用于演示/开发/测试环境；生产首次初始化请按 [DEPLOY.md](../DEPLOY.md) 操作。

## 3. 环境变量配置（.env）

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `APP_ENV` | `local` | 生产用 `production` |
| `APP_DEBUG` | `false` | 生产必须 `false`（错误页不暴露调试信息） |
| `APP_URL` | `http://localhost` | 站点正式 https 地址 |
| `SITE_DEFAULT_FALLBACK` | `true` | 单站/开发模式未知 Host 回落默认站；**多站生产设 `false`**（未知 Host 返回「站点不存在」） |
| `APP_LOCALE` | `zh-CN` | 默认语言 |
| `DB_CONNECTION` | `sqlite` | `sqlite` / `pgsql` / `mysql` |
| `CACHE_STORE` | `database` | 缓存存储（PageCache 固定 file store，不受此影响） |
| `QUEUE_CONNECTION` | `database` | 队列（当前官网无异步任务，`sync` 亦可） |
| `GEOFLOW_SYNC_ENABLED` | `false` | GEOFlow 上游内容同步 API 开关，配置 Bearer Token 后设为 `true` 开启 |
| `GEO_OS_VERSION` | `1.0.0` | 覆盖 `geo:version` 输出的应用版本号 |

> 生产 `.env` 模板：`cp .env.production.example .env`（见 [DEPLOY.md](../DEPLOY.md)）。

## 4. 数据库迁移

```bash
php artisan migrate --force
```

- 仓库当前含 **62 个迁移**（v1.0.0 为 56 个，v1.4.0 新增 6 个）。
- 三库使用同一迁移集；跨库物理差异（唯一约束 / 外键 / FTS5 / 事务语义）与安装差异见
  [数据库支持矩阵](operations/database-support-and-recovery.md)。

## 5. 升级

```bash
# 备份先行（见下节）
php artisan migrate --force
php artisan page-cache:clear
```

> **v1.0.0 → v1.4.0 升级特别注意**：迁移
> `2026_10_27_000001_tighten_contents_slot_unique_to_site_scope` 会把
> MySQL / PostgreSQL 上 `contents.slot` 唯一约束收紧为站点级（`UNIQUE(site_id, slot)`）。
> 若库中已存在「同站同 slot、不同 locale」的存量行，迁移会**中止报错、不自动删改**。
> 升级前必查：

```sql
SELECT site_id, slot, COUNT(*) AS c
FROM contents
WHERE slot IS NOT NULL AND slot <> ''
GROUP BY site_id, slot
HAVING COUNT(*) > 1;
```

- 空集 → 可直接升级；
- 有返回行 → 人工确认后清理，再升级；
- MySQL/PG 上该迁移持有写锁，数据量大请在停写窗口内执行；
- MySQL DDL 非事务性，迁移异常后须按矩阵文档第 4.2 节人工确认索引与台账状态。

## 6. 备份 / 恢复 / 回滚

| 数据库 | 备份 | 恢复 |
| --- | --- | --- |
| SQLite | `php artisan geo:backup`（或文件复制） | `php artisan geo:rollback --backup=storage/app/backups/geo-backup-<时间戳>.sqlite --force` |
| PostgreSQL | `pg_dump --format=custom --file=…` | `pg_restore --dbname=… --clean --if-exists …`（建议恢复到空库） |
| MySQL | `mysqldump --single-transaction …` | `mysql … < dump.sql`（建议恢复到空库） |

> 必须备份：数据库、`storage/app/public/`（上传媒体）、`.env`。
> 本项目**没有**「迁移失败自动回滚」能力；失败会停在失败点，按矩阵文档第 4.3 节人工处置。
> 恢复判据是业务数据核对（`migrate:status` + 关键行数），不是命令退出码。

## 7. 常见安装错误排查

| 现象 | 原因与处理 |
| --- | --- |
| `APP_KEY is missing` | 未执行 `php artisan key:generate` |
| `Database unreachable` | SQLite：确认 `database/database.sqlite` 已创建且 `database/` 可写；PG/MySQL：核对 `.env` 的 `DB_*` |
| `Please provide a valid cache path`（HTTP 500） | 发布制品缺 `storage/framework/{cache/data,sessions,views}`、`storage/logs`、`bootstrap/cache` 的占位目录——用官方 zip 或 `git archive` 导出不会缺失；手工拷贝请补齐 |
| 上传图片 404 | 缺 `public/storage` 软链：`php artisan storage:link` |
| 升级迁移报「duplicate key / 约束冲突」 | 按第 5 节排查 `contents.slot` 存量冲突，清理后重试 |
| 前台 500 且错误页空白 | `APP_DEBUG=true` 打开调试定位；生产排查 `storage/logs/laravel.log` |

> 更多故障排查见 [FAQ 与故障排查](faq.md)。

## 8. 部署前检查与恢复注意

- 完整上线前自检清单见 [DEPLOY.md](../DEPLOY.md) 第 9 节。
- 生产环境三项硬要求：`APP_ENV=production`、`APP_DEBUG=false`、`SITE_DEFAULT_FALLBACK=false`。
- 恢复操作**必须落到空库**；恢复到非空库会产生 DDL 冲突噪声，不能以退出码判断成败。
- 升级前建议在预发环境完整演练一次（`scripts/audit/verify-upgrade-rollback.php`，用法见矩阵文档第 6 节）。
