# Gate 1 Step 5 Environment Preflight Report

## 1. Executive Summary

**STATUS: PASS**

PHP 8.4.25 NTS + Composer 2.10.3 + Laravel 12.69.2 + Node v22.23.2 / npm 10.9.8 环境已恢复。
所有平台要求满足，composer.lock 验证通过，193 个测试全部通过（867 assertions）。

**环境恢复前**: PHP 8.1.34 < vendor 要求 8.4.1，artisan 完全无法运行，测试基线无法建立。
**环境恢复后**: PHP 8.4.25，artisan 正常，测试全部通过。

## 2. Environment Verification

### 2.1 PHP

| Item | Value | Status |
|------|-------|--------|
| PHP Version | 8.4.25 (NTS Visual C++ 2022 x64) | PASS (>= 8.4.1) |
| PHP SAPI | cli | PASS |
| PHP Path | C:\php84\php.exe | PASS |
| Thread Safety | disabled (NTS) | PASS |

**安装方式**: 从 windows.php.net 手动下载 php-8.4.25-nts-Win32-vs17-x64.zip，解压至 C:\php84。
**php.ini**: 基于 php.ini-development，extension_dir = "C:\php84\ext"。

**已启用扩展**:
- pdo_sqlite ✅
- sqlite3 ✅
- mbstring ✅
- openssl ✅
- curl ✅
- fileinfo ✅
- gd ✅
- intl ✅
- zip ✅
- exif ✅

**注意**: bcmath 扩展不在 PHP 8.4 NTS 发行包中，已从 php.ini 移除。Laravel 核心不依赖 bcmath。

### 2.2 Composer

| Item | Value | Status |
|------|-------|--------|
| Composer Version | 2.10.3 | PASS |
| Install Path | C:\php84\composer.phar + composer.bat | PASS |
| composer validate --strict | ./composer.json is valid | PASS |
| composer check-platform-reqs | 全部 success | PASS |

**check-platform-reqs 详细结果**:
```
composer-runtime-api 2.2.2      success
ext-ctype            *          success (symfony/polyfill-ctype)
ext-dom              20031129   success
ext-fileinfo         8.4.25     success
ext-filter           8.4.25     success
ext-hash             8.4.25     success
ext-iconv            8.4.25     success
ext-json             8.4.25     success
ext-libxml           8.4.25     success
ext-mbstring         *          success (symfony/polyfill-mbstring)
ext-openssl          8.4.25     success
ext-pcre             8.4.25     success
ext-phar             8.4.25     success
ext-session          8.4.25     success
ext-tokenizer        8.4.25     success
ext-xml              8.4.25     success
ext-xmlwriter        8.4.25     success
php                  8.4.25     success
```

### 2.3 Laravel

| Item | Value | Status |
|------|-------|--------|
| Laravel Version | 12.69.2 | PASS |
| artisan --version | Laravel Framework 12.69.2 | PASS |
| package:discover | 6 packages discovered (pail, sail, tinker, carbon, collision, termwind) | PASS |

### 2.4 Node / npm

| Item | Value | Status |
|------|-------|--------|
| Node Version | v22.23.2 | PASS |
| npm Version | 10.9.8 | PASS |
| package-lock.json | 不存在（npm install 后已生成） | PASS |
| node_modules | 87 packages installed | PASS |

**注意**: 项目原无 package-lock.json，使用 `npm install` 安装依赖并生成 lock 文件。
未修改 package.json。

### 2.5 Git

| Item | Value | Status |
|------|-------|--------|
| Git Version | 2.53.0.windows.2 | PASS |
| Current Branch | master | PASS |
| HEAD Commit | ae3fc4ff9f4831ee3c9e0ab3d14329d7b9bdbb3f | PASS |
| Tag | gate1-pre-change | PASS |

## 3. Composer Install Result

```
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating optimized autoload files
> Illuminate\Foundation\ComposerScripts::postAutoloadDump
> @php artisan package:discover --ansi

INFO  Discovering packages.
  laravel/pail .................................................................................................. DONE
  laravel/sail .................................................................................................. DONE
  laravel/tinker ................................................................................................ DONE
  nesbot/carbon ................................................................................................. DONE
  nunomaduro/collision .......................................................................................... DONE
  nunomaduro/termwind ........................................................................................... DONE
```

**结论**: vendor 目录已存在且与 composer.lock 完全一致，无需安装新包。
仅重新生成了 optimized autoload files 和 package discovery。

**未执行**: composer update, composer require（严格遵守环境恢复原则）。

## 4. Test Baseline

### 4.1 php artisan test

```
Tests:    193 passed (867 assertions)
Duration: 17.57s
```

| Metric | Value |
|--------|-------|
| Total Tests | 193 |
| Passed | 193 |
| Failed | 0 |
| Skipped | 0 |
| Errors | 0 |
| Assertions | 867 |
| Duration | 17.57s |

### 4.2 测试文件分布（25个测试文件）

- Feature tests: 22 files
- Unit tests: 3 files

**关键测试覆盖**:
- 前端页面渲染（首页、产品、场景、知识、关于、联系）
- SEO/GEO（sitemap、robots、Schema、llms、canonical、OG）
- CMS 管理（内容、分类、菜单、媒体、设置、块）
- 缓存（PageCache、WebP、渲染缓存）
- 安全（认证、授权、上传验证）
- 业务对齐（V0911 CmsAlignment、V0912 NavCtaAlignment）

**结论**: 0 失败，测试基线健康。Step 5 修改后必须保持 193 测试通过（或明确记录因架构变更而修改的测试）。

## 5. Database State

| Item | Value |
|------|-------|
| Driver | SQLite |
| File | database/database.sqlite |
| Size | 573,440 bytes |
| Tables | 23 |
| Total Rows | 224 |
| Migrations | 26 (all executed) |

**环境恢复过程中未修改数据库**。

## 6. PATH Configuration

### 当前会话 PATH
- C:\php84 (优先，PHP 8.4.25)
- ... (PHP 8.1 WinGet 路径在后面，不影响)

### 永久 PATH
**状态**: 尝试将 C:\php84 加入用户永久 PATH 时命令超时（用户 PATH 可能过长）。
**当前状态**: 当前会话 PATH 已生效，所有后续命令使用 PHP 8.4.25。
**建议**: 如需要永久生效，手动执行：
```powershell
[Environment]::SetEnvironmentVariable("Path", "C:\php84;" + [Environment]::GetEnvironmentVariable("Path", "User"), "User")
```
或通过系统设置 → 环境变量手动添加。

## 7. Business Code Integrity

环境恢复过程中**未修改任何业务代码**:
- app/ — 未修改
- config/ — 未修改
- routes/ — 未修改
- database/migrations/ — 未修改
- resources/ — 未修改
- public/ — 未修改
- tests/ — 未修改
- composer.json — 未修改
- composer.lock — 未修改
- package.json — 未修改

**新增文件**:
- C:\php84\ (PHP 运行时，不在项目目录内)
- package-lock.json (由 npm install 自动生成)
- docs/audit/step5-environment-preflight.md (本文件)

## 8. Preflight Gate Checklist

| # | Condition | Status | Evidence |
|---|-----------|--------|----------|
| 1 | PHP >= 8.4.1 | PASS | PHP 8.4.25 |
| 2 | Composer available | PASS | Composer 2.10.3 |
| 3 | composer validate --strict | PASS | ./composer.json is valid |
| 4 | composer check-platform-reqs | PASS | 全部 success |
| 5 | php artisan --version | PASS | Laravel 12.69.2 |
| 6 | Node/npm available | PASS | Node v22.23.2 / npm 10.9.8 |
| 7 | Test baseline established | PASS | 193 passed, 0 failed |
| 8 | vendor directory intact | PASS | composer install "Nothing to install" |
| 9 | node_modules installed | PASS | 87 packages |
| 10 | Business code unchanged | PASS | git diff 业务目录为空 |

**Result: 10/10 PASS**

## 9. Known Limitations

1. **bcmath 扩展不可用**: PHP 8.4 NTS 发行包不含 php_bcmath.dll。Laravel 核心不依赖 bcmath，如未来安装需要 bcmath 的包需单独处理。
2. **永久 PATH 未设置**: 当前会话 PATH 已生效，但新终端会话可能仍默认 PHP 8.1。需手动设置永久 PATH。
3. **PHP 8.1 仍存在**: WinGet 安装的 PHP 8.1.34 仍在系统中，但 PATH 优先级低于 C:\php84，不影响当前项目。
4. **npm run build 未验证**: 环境预检未执行前端构建，Step 5 完成后需验证 `npm run build`。

## 10. Next Step

**Environment Preflight: PASS**

可以进入 Gate 1 Step 5 Phase 5.1（Test Baseline Recovery，已在本阶段完成）→ Phase 5.2（Database Snapshot）→ Phase 5.3（Site Architecture）。

执行 Step 5 前需确认:
- [x] PHP 8.4.25 环境就绪
- [x] Composer 2.10.3 就绪
- [x] Laravel 12.69.2 artisan 可运行
- [x] 193 测试基线建立（0 失败）
- [x] 数据库备份存在（gate1-pre-change.sqlite）
- [x] 代码备份存在（gate1-pre-change.zip）
- [x] Git tag gate1-pre-change 存在
- [ ] 创建 Step 5 pre-change snapshot（gate1-step5-pre-change tag）

**建议**: 进入 Step 5 前先创建 `gate1-step5-pre-change` Git tag，作为 Step 5 的独立回滚点。
