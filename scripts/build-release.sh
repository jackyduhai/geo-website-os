#!/usr/bin/env bash
# GEO Website OS Release Artifact 构建脚本（P-STEP 08）
# ---------------------------------------------------------------
# 产物 = 干净源码（git archive，天然排除 .env / storage 内容 / 本地缓存）
#       + composer.lock（保证依赖 = CI 锁定版本）
#       + release-manifest.json（version / commit / PHP / extensions / 迁移清单 / 校验和）
#
# 依赖环境必须有：git、tar、php、composer（安装侧执行 composer install --no-dev）。
set -euo pipefail

cd "$(dirname "$0")/.."
REPO="$PWD"
VERSION=$(php -r 'echo config_version(); function config_version(){ return "cli"; }' 2>/dev/null || true)
# 版本单一来源：config/geo.php 的 version
VERSION=$(php -r 'define("LARAVEL_START", microtime(true)); $app=require "vendor/autoload.php"; $c=require "config/geo.php"; echo $c["version"];')
COMMIT=$(git rev-parse HEAD)
BUILD="dist/release-${VERSION}-${COMMIT:0:10}"

if [ -n "$(git status --porcelain)" ]; then
  echo "ERROR: working tree not clean — release must be built from a clean tree" >&2
  exit 1
fi

rm -rf "$BUILD"
mkdir -p "$BUILD"

# 1) 干净源码（git archive 只含已跟踪文件）
git archive HEAD | tar -x -C "$BUILD"

# 2) Release Manifest
PHP_REQ=$(php -r '$c=json_decode(file_get_contents("composer.json"),true); echo $c["require"]["php"];')
MIGRATIONS=$(php -r '
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("database/migrations"));
foreach (new RegexIterator($dir, "/\.php$/") as $f) { echo basename($f->getPathname()), "\n"; }' | sort)
MANIFEST="$BUILD/release-manifest.json"
php -r '
$out = [
    "name" => "geo-os",
    "version" => $argv[1],
    "commit" => $argv[2],
    "php_requirement" => $argv[3],
    "php_built_with" => PHP_VERSION,
    "required_extensions" => ["pdo", "mbstring", "openssl", "tokenizer", "json", "ctype", "fileinfo"],
    "migrations" => array_values(array_filter(explode("\n", $argv[4]))),
    "generated_at" => date("c"),
];
file_put_contents($argv[5], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$VERSION" "$COMMIT" "$PHP_REQ" "$MIGRATIONS" "$MANIFEST"

# 3) 打包 + 校验和
TAR="dist/geo-os-${VERSION}-${COMMIT:0:10}.tar.gz"
tar -czf "$TAR" -C dist "release-${VERSION}-${COMMIT:0:10}"
SHA=$(sha256sum "$TAR" | cut -d' ' -f1)
php -r '
$m = json_decode(file_get_contents($argv[1]), true);
$m["artifact_sha256"] = $argv[2];
file_put_contents($argv[1], json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$MANIFEST" "$SHA"

echo "artifact: $TAR"
echo "sha256:   $SHA"
echo "manifest: $MANIFEST"
