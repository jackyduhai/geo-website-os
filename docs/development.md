# 开发与维护

> 面向参与开发、测试与维护的开发者。版本口径：v1.4.0。
> 代码规范见 [code-standard.md](code-standard.md) · [design-system.md](design-system.md) ·
> [html-standard.md](html-standard.md) · [css-standard.md](css-standard.md)。

## 1. 开发环境

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan geo:install --no-interaction   # 或 php artisan migrate --force + 建站

# 启动
php artisan serve                 # http://127.0.0.1:8000

# 可选：前端资产（仅当你需要自行构建 Tailwind/Vite 时）
npm install && npm run build
```

> 运行与测试**不依赖 Node**：前台主题以内联 CSS 随 Blade 布局发布，后台样式为
> `public/css/admin.css`，无任何 Blade 视图引用 `@vite`。

## 2. 项目目录说明

```text
app/
  Console/Commands/      geo:install / geo:upgrade / geo:rollback / geo:backup /
                         geo:version / geo:backfill-seo / template:* / page-cache:* /
                         search:reindex / media:* …
  Contracts/             接口契约
  Http/Controllers/      Site（前台）、Admin（后台）、Geo（机器输出）、API
  Models/                领域模型（统一继承 BaseModel：跨库路由守卫）
  Repositories/          作用域安全查询
  Services/              Seo/ · Geo/ · Gate/ · Sync/（分层服务）
  Support/               SiteContext · SiteScope · PageCache · PublicUrl · SafeUrl ·
                         MigrationPortability · CurrentDatabaseTables 等
routes/                  web.php（前台）· admin.php（后台 /admin）· api.php · console.php
database/
  migrations/            62 个迁移（三库同集，up/down 均受测）
  seeders/               空站结构 Seeder + 通用 Example 演示数据（DemoSeeder）
resources/themes/        default · example（视图层主题）
plugins/hello/           插件示例（provider + 路由 + plugin.json）
scripts/
  install-release.sh     发布制品安装脚本
  build-release.sh       发布制品构建脚本
  audit/                 test-baseline.json · verify-test-baseline.php ·
                         verify-upgrade-rollback.php（演练）
tests/                   Feature / Unit / Concurrency（含跨库结构一致性断言）
```

## 3. 测试

```bash
# 全量回归（SQLite，CI 同口径；Windows 下按分批独立进程执行并合并 JUnit）
php vendor/bin/phpunit
# 或
php artisan test

# 计数门禁（本地与 CI 同源）
php scripts/audit/verify-test-baseline.php --junit=storage/logs/junit.xml

# PostgreSQL 核心子集（零失败 + 数量下限门禁）
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
  php vendor/bin/phpunit -c phpunit.pgsql.xml --testsuite PgsqlCore

# MySQL（无 CI 门禁，本机实测）
DB_CONNECTION=mysql ... php vendor/bin/phpunit -c phpunit.mysql.xml
```

**质量口径**：

- 全量基线（v1.4.0）：**1761 tests / 9470 assertions / 0 failures / 0 errors / 0 skipped**（SQLite，`scripts/audit/test-baseline.json`）。
- CI 计数门禁：tests / assertions / failures / errors / skipped 五项**精确相等**，测试被删除或未发现会直接红。
- PostgreSQL 门禁：零失败零跳过 + `tests >= 174`（PgsqlCore 子集）。
- 新增测试后更新基线：重跑全量 → 有意更新 `test-baseline.json`（文件头有 runbook）。
- 并行跑多套测试时用独立文件缓存目录（`CACHE_FILE_PATH` / `CACHE_FILE_LOCK_PATH`），见
  [数据库支持矩阵](operations/database-support-and-recovery.md) 第 6.1 节。

## 4. 数据库支持矩阵（选型必读）

| 驱动 | 版本 | 状态 | 门禁 |
| --- | --- | --- | --- |
| SQLite | PHP 内置 | **正式支持**（默认） | CI `test` 精确计数门禁 |
| PostgreSQL | 16.x | **正式支持** | CI `pgsql` 零失败门禁 |
| MySQL | 8.0+（实测 8.4） | **候选支持**（实测通过，无 CI 门禁） | 无 |

详细矩阵、三库物理差异（唯一约束形态 / 外键开关 / FTS5 / 事务锁语义 / 返回行序）、升级 / 备份 / 恢复逐条命令见
[数据库支持矩阵](operations/database-support-and-recovery.md)。**跨库行为一致性由 `DatabaseStructureParityTest` 锁定**。

## 5. 迁移 / 备份 / 恢复 / 回滚

```bash
php artisan migrate --force                      # 升级（62 个迁移）
php artisan migrate:rollback --step=1            # 回滚单个迁移（需 down() 正确实现）
php artisan geo:backup                           # SQLite 备份（带元数据清单）
php artisan geo:rollback --backup=<文件> --force  # SQLite 恢复
php artisan page-cache:clear                     # 清理整页缓存
```

- **没有自动回滚**：迁移失败停在失败点，需人工处置。
- 备份必须覆盖：数据库、`storage/app/public/`（媒体）、`.env`。
- 从 v1.0.0 升级前必须检查 `contents.slot` 存量冲突（见 [安装与部署](installation.md) 第 5 节）。

## 6. CI 与发布

- `.github/workflows/ci.yml`：`test (8.4)` → 精确计数门禁；`PostgreSQL 16` → 零失败门禁；
  `release`（tag `v*` 触发，依赖前两者成功）→ 构建 zip + SHA-256 + manifest（Draft + Pre-release 创建，**不自动发布**）。
- `.github/workflows/coverage.yml`：分支/路径覆盖率证据（非发布门禁，手动/定时触发）。
- 门禁口径细节见 [CI 与发布门禁](operations/ci-release-gates.md)。

## 7. 贡献方式

- 贡献指南见 [CONTRIBUTING.md](../CONTRIBUTING.md)。
- 安全漏洞报告见 [SECURITY.md](../SECURITY.md)（**不要开公开 issue**）。
- 代码与文档规范：`docs/code-standard.md`、`docs/design-system.md` 等。

## 8. 常见维护事项

- 改了 `.env` / `config/` 后生产需重跑 `config:cache`（并 `route:cache` / `view:cache` / `event:cache`）。
- 改了 Blade 模板或 `config/facts` 类文件型内容后需 `view:cache` + `page-cache:clear`。
- 媒体删除失败可 `media:retry-deletes` 重试；图片优化 `media:optimize --dry-run`。
- 搜索索引重建：`search:reindex [--site=slug|--site-id=ID]`。
- 问题排查见 [FAQ 与故障排查](faq.md)。
