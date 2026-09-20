#!/usr/bin/env bash
# GEO Website OS Release 安装脚本（P-STEP 08）
# ---------------------------------------------------------------
# 在全新环境中把 Release Artifact 安装为可运行站点：
#   composer install --no-dev（依赖 = composer.lock 锁定，与 CI 一致）
#   → .env 初始化（不带任何业务默认值）
#   → php artisan key:generate
#   → php artisan geo:install（迁移 + 站点 + 管理员 + 自检）
#
# 用法: ./install-release.sh <admin-email> <admin-password>
set -euo pipefail
cd "$(dirname "$0")/.."

ADMIN_EMAIL="${1:?usage: install-release.sh <admin-email> <admin-password>}"
ADMIN_PASSWORD="${2:?usage: install-release.sh <admin-email> <admin-password>}"

# 1) 依赖安装（--no-dev：生产 artifact 不含测试/开发工具）
if [ ! -d vendor ]; then
  composer install --no-dev --prefer-dist --no-interaction --no-progress
fi

# 2) 环境初始化（通用默认值，零业务信息）
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --force
fi

# 3) 数据库文件（sqlite 全新安装；其他驱动由 .env 配置）
mkdir -p database
if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
fi

# 4) 引导安装（迁移 + 站点 + 管理员 + 自检）
php artisan geo:install --admin-email="$ADMIN_EMAIL" --admin-password="$ADMIN_PASSWORD"

# 5) 安装验证
php artisan geo:version

echo "release install complete"
