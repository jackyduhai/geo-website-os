# Environment Baseline

## Runtime

| Item | Value | Status |
|------|-------|--------|
| PHP | 8.1.34 (ZTS Visual C++ 2019 x64) | **BLOCKED** (requires >= 8.4.1) |
| PHP SAPI | cli | VERIFIED |
| PHP architecture | x64 | VERIFIED |
| Composer | NOT INSTALLED | **BLOCKED** |
| Laravel | 无法获取 (artisan blocked) | BLOCKED |
| Node | v22.23.2 | VERIFIED |
| npm | 10.9.8 | VERIFIED |
| Vite | 7.x (from package.json, 未执行) | PARTIAL |
| OS | Windows | VERIFIED |
| Git | 2.53.0.windows.2 | VERIFIED |

## PHP Platform Check Evidence

```
vendor/composer/platform_check.php line 22:
  Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.1.34.
```

## composer.json PHP requirement

```json
"require": {
    "php": "^8.2",
    ...
}
```

Note: composer.json 声明 ^8.2，但 vendor/platform_check 实际要求 >= 8.4.1。说明 vendor 目录是在 PHP 8.4+ 环境下安装的，与当前 8.1 环境不匹配。

## Direct Composer Dependencies

BLOCKED: composer 不可用，无法执行 `composer show --direct`。
可从 composer.json 读取（待补充）。

## Direct npm Dependencies

待执行 `npm list --depth=0`（node 可用，应可执行）。

## Database

| Item | Value | Status |
|------|-------|--------|
| DB_CONNECTION | sqlite (from .env) | VERIFIED |
| Database file | database/database.sqlite | VERIFIED |
| File size | 573440 bytes (~560KB) | VERIFIED |
| Schema export | 见 baseline-schema.sql (纯 PHP PDO 导出) | VERIFIED |
