# P-STEP 08 — Installer / Release Artifact

> 目标：Git 仓库 → 可部署 Release Artifact；发布包安装结果与 CI/测试环境一致

## 1. 发布产物构建（scripts/build-release.sh）

- **干净源码**：`git archive HEAD`——天然排除 `.env`、storage 内容、本地缓存、
  dist、未跟踪文件（构建前强制 clean tree，脏树直接失败）。
- **release-manifest.json**（单一事实凭证）：
  - `version`（config/geo.php，单一版本来源）+ `commit`
  - `php_requirement`（composer.json ^8.2）+ `php_built_with`
  - `required_extensions`（pdo/mbstring/openssl/tokenizer/json/ctype/fileinfo）
  - `migrations`（全量迁移清单 = 数据库 schema 期望状态）
  - `artifact_sha256`
- **产物**：`dist/geo-os-{version}-{commit10}.tar.gz`（已 gitignore，不入库）。

## 2. 安装脚本（scripts/install-release.sh）

```
composer install --no-dev --prefer-dist   （依赖 = composer.lock，与 CI 一致）
→ cp .env.example .env（通用默认值，零业务信息）
→ php artisan key:generate
→ touch database/database.sqlite
→ php artisan geo:install --admin-email --admin-password
→ php artisan geo:version（版本/迁移状态报告）
```

## 3. 隔离环境端到端实测

在 `/tmp/release-test`（仓库外隔离目录）：

```
tar -xzf geo-os-2.0.0-*.tar.gz
→ composer install --no-dev  ⚠ 本环境无 composer 二进制（见 §5）
   → fallback：复制开发 vendor 验证（差异已记入风险清单）
→ .env 初始化 + key:generate
→ geo:install exit 0（38 迁移 / Default Site / 管理员 / 自检 ok）
→ geo:version = 2.0.0 / migrations.last = drop_legacy_seo_columns
→ GET / = 200：<title> / canonical / robots / og:title 全部输出
```

## 4. 与 dev 安装的一致性

对比项（dev vs artifact 隔离安装）：geo:install exit 0、迁移数 38、
默认站点 'Default Site'、GET / 200 + SEO head 四项、geo:version 版本一致。

## 5. 风险登记（诚实记录）

- **composer 不可用**：本执行环境没有 composer 二进制，无法运行
  `composer install --no-dev` 验证真实依赖安装；隔离安装使用开发 vendor
  副本（LOCAL-VENDOR fallback）。composer.lock 已随包提供，
  生产/CI 环境必须走标准 `composer install --no-dev` 路径并对比
  `composer show` 输出与 lock 文件。
- manifest 的 migrations 清单为构建时静态快照，upgrade 后实际状态以
  `geo:version` 输出为准。
