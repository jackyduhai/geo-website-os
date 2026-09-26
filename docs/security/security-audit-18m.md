# Security Audit Report（P-STEP 18M-A3/A4）

> 审查对象：GEO Website OS 安全面与权限边界
> 审查日期：2026-09-26　·　方法：磁盘/ Git 跟踪核查、源码静态扫描、真实 HTTP 响应头、授权边界测试
> 范围：Secrets、Debug 面、安全响应头、权限/多站隔离

---

## 1. 结论（Executive Summary）

| 检查项 | 结果 |
|---|---|
| 机密文件（.env）被 Git 跟踪 | **否（未跟踪）** |
| 硬编码 API key / token / 私钥 / 密码 | **无** |
| 数据库 / 备份 / 归档入库 | **无**（仅文档化 schema 基线） |
| 生产 `APP_DEBUG` | **false** |
| 调试函数 / 调试工具包 / console.log | **无** |
| 前台安全响应头（CSP/Frame/nosniff/Referrer/Permissions） | **齐全** |
| Super/Admin 越权、Site 隔离 | **通过**（13 项边界断言） |
| P0 / P1 安全问题 | **无** |

本轮仅发现 1 个 P2 纵深防御项（**TD-137**：admin 区域 CSP script 用 unsafe-inline，前台已 nonce），转 v1.1。

---

## 2. Secrets 与敏感文件

- 磁盘存在 `.env`（本地，含本地 APP_KEY）、`.env.example`、`.env.production.example`；**`.env` 未被 Git 跟踪**，仅跟踪两个 example 模板。
- `.gitignore` 已忽略：`.env`、`.env.backup`、`.env.production`、`*.sqlite`、`*.sqlite-journal`、`/storage/*.key`、`/public/storage`。
- `.env` 内容核查：`APP_DEBUG=false`、DB=sqlite、`MAIL_MAILER=log`、AWS 凭据**为空**、Redis 无密码、`MAIL_FROM` 为 `example.com` 占位——**无外部真实凭据**。
- `.env.example`：`APP_KEY` 为空的干净模板；`.env.production.example`：`APP_ENV=production`、`APP_DEBUG=false`、`SITE_DEFAULT_FALLBACK=false`、`SESSION_SECURE_COOKIE=true`。
- 无数据库 / 备份 / zip 入库；`docs/audit/baseline-schema.sql` 为**无数据**的 schema 基线文档。
- 源码静态扫描：`app/`、`config/` 无私钥（`BEGIN PRIVATE KEY`）、无 `AIza* / ghp_* / xox*` token、无硬编码密码（config 全部走 `env()`/null）。

---

## 3. Debug 面

- `app/` 全目录扫描：**无** `dd() / dump() / var_dump() / print_r() / var_export() / eval() / syslog() / ray()`。
- Blade 模板：**无** `@dd / @dump / @var_dump`、**无** `console.log()`。
- `composer.json` 生产 `require` 中**无** Telescope / Horizon / Debugbar / Ignition 等调试工具；`require-dev`（faker/pail/pint/sail/mockery/collision/phpunit）仅在开发环境、`--no-dev` 不安装。
- 生产环境 `APP_DEBUG=false`（本地 `.env` 同样设为 false）。

---

## 4. 安全响应头（真实 curl 取证）

前台首页（含详情/feed）响应头：

| 响应头 | 值 | 评价 |
|---|---|---|
| Content-Security-Policy | 见下 | script nonce，强 |
| X-Frame-Options | `SAMEORIGIN` | ✅（与 frame-ancestors 一致） |
| X-Content-Type-Options | `nosniff` | ✅ |
| Referrer-Policy | `strict-origin-when-cross-origin` | ✅ |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | ✅ 最小权限 |

前台 **CSP**：

```
default-src 'self';
script-src 'nonce-{per-request}';        /* 无 unsafe-inline script */
style-src 'self' 'unsafe-inline';        /* 内联主样式所需，CSS 风险低 */
img-src 'self' data: blob: http: https:; /* 外链图/编辑器 data URI */
font-src 'self' data:;
connect-src 'self';
object-src 'none';
base-uri 'self';
form-action 'self';
frame-ancestors 'self';
```

- 强约束齐备：**script nonce、object-src none、base-uri、form-action、frame-ancestors**。
- Analytics 第三方域名按**已启用 Provider 动态扩展**（默认零第三方来源），符合最小权限。
- admin login 页与 sitemap 同样具备 X-Frame/nosniff/Referrer/Permissions 头；sitemap 另带 `Cache-Control: max-age=3600, public`。
- `public/` 目录干净（仅 `index.php`、`.htaccess`、favicon、css/img、storage 软链）；`.htaccess` 含 gzip、静态缓存与前端控制器重写。

---

## 5. 权限与多站隔离（A4）

- 未认证访问任何 `admin/*`（templates/settings/forms）→ **302 重定向 `/admin/login`**。
- `SiteAuthorizationBoundaryTest`：**13 passed / 22 assertions**，关键断言：
  - anonymous 不可跨站；regular user / 同邮箱无 flag 不可跨站；
  - **Super Admin（system/admin context）可跨站**，普通 Admin 被 Site scope 限制；
  - 多站模式未知 Host **不回退**、单站模式回退默认站；CLI 属 system context；跨站必须显式调用。
- Template / Theme 管理走同一 admin middleware + Site scope，多站数据不串（前台与测试双重背书）。
- 当前 v1.0 两级权限（Super 跨站 / Admin 单站）满足核心安全边界；**RBAC 三角色 + Site Membership（TD-14）继续 DEFERRED v1.1**。

---

## 6. 本轮发现

| TD | 内容 | 优先级 / 目标 |
|---|---|---|
| **TD-137** | Admin 区域 CSP `script-src 'self' 'unsafe-inline'`（前台已 nonce），后台纵深防御弱于前台；style-src unsafe-inline 一并观察 | P2 / v1.1 |

无 P0/P1；后台为认证区域且所有用户输入已 escaping + SafeUrl + 结构化存储（无任意 HTML/脚本），故 TD-137 不阻塞 v1.0。

---

## 7. 建议

- v1.0 无需安全整改即可进入发布工程；TD-137 随 v1.1 将 admin 内联 script 改 nonce/hash。
- 发布前再次确认生产 `.env`：`APP_ENV=production`、`APP_DEBUG=false`、`SITE_DEFAULT_FALLBACK=false`、`SESSION_SECURE_COOKIE=true`。
- 19A 云端仓库按 P15 已建立的纪律，确保历史与当前树均无机密（.env/db/backup）。
