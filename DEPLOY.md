# GEO Website OS · 生产部署与运维手册（云端 / 宝塔 / Nginx + PHP-FPM）

> 适用：Laravel 12 + PHP 8.4+ + SQLite（可换 MySQL），前台为 Blade SSR + 整页静态化缓存，后台 CMS。
> 本机开发用 `php artisan serve`；**生产环境务必用 Nginx + PHP-FPM**，不要用内置服务器对外。

---

## 1. 环境要求

- PHP 8.4 及以上，扩展：`mbstring、openssl、tokenizer、xml、ctype、json、fileinfo、gd（或 imagick）、pdo_sqlite`（换 MySQL 则 `pdo_mysql`）、`bcmath、zip`（建议）。
- Nginx（1.20+）；Composer 2；可选 Supervisor（队列）。
- 目录可写：`storage/`、`bootstrap/cache/`；上传目录 `storage/app/public/`（通过 `public/storage` 软链对外）。

---

## 2. 首次部署

```bash
# 1) 上传代码到站点目录（示例 /www/wwwroot/geo-website-os），Web 根目录指向 .../geo-website-os/public
cd /www/wwwroot/geo-website-os

# 2) 依赖（不含开发包，优化自动加载）
composer install --no-dev --optimize-autoloader

# 3) 环境配置
cp .env.production.example .env
php artisan key:generate            # 若 .env 里 APP_KEY 为空
#   然后编辑 .env：APP_URL、DB（SQLite 路径或 MySQL）、邮件、SESSION_SECURE_COOKIE 等

# 4) 软链上传目录（媒体/Banner/封面图对外访问）
php artisan storage:link

# 5) 数据库（首次）
php artisan migrate --force         # SQLite 会自动建库文件；确保 database/ 目录可写
php artisan db:seed --force         # 仅首次：写入默认设置/栏目/事实数据

# 6) 目录权限（宝塔通常 www:www）
chown -R www:www storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 7) 缓存与静态化（配置/路由/视图/事件，提升性能）
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan page-cache:clear
```

> 改了 `.env` 或配置后必须重跑 `config:cache`；SQLite 部署务必**定期备份** `database/database.sqlite` 与 `storage/app/public/`（上传文件）。

---

## 3. Nginx 站点配置（含静态资源长缓存 + gzip）

```nginx
server {
    listen 80;
    server_name www.example.com example.com;
    root /www/wwwroot/geo-website-os/public;
    index index.php;

    client_max_body_size 20m;          # 后台图片/Banner 上传上限，按需调整

    # gzip 压缩
    gzip on;
    gzip_types text/plain text/css application/json application/javascript
               application/xml text/xml application/xml+rss image/svg+xml;
    gzip_min_length 1024;

    # 上传的媒体/图片：长缓存（文件名按月份目录，更新会换新路径）
    location ^~ /storage/ {
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable";
        access_log off;
        try_files $uri =404;
    }

    # 静态资源长缓存
    location ~* \.(?:css|js|woff2?|ttf|png|jpg|jpeg|gif|webp|avif|svg|ico)$ {
        expires 30d;
        add_header Cache-Control "public, max-age=2592000";
        access_log off;
        try_files $uri =404;
    }

    # 主路由：交给 Laravel（整页静态化缓存在应用层命中）
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 禁止访问敏感文件
    location ~ /\.(?!well-known) { deny all; }
    location ~* /(?:composer\.(json|lock)|\.env|package\.json)$ { deny all; }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-84.sock;   # 以宝塔实际 PHP-FPM socket 为准
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }
}
```

配置好后用 HTTPS（宝塔申请 Let's Encrypt 证书），并在 Nginx 开启 HSTS（确认全站 HTTPS 后再加）：

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

> 说明：HTML 页面在应用层做了「匿名外壳 + 按会话回填 CSRF」的整页缓存，响应头是
> `Cache-Control: private, no-cache`，**不要再让 Nginx/CDN 对 text/html 做共享缓存**，
> 否则可能把某位访客的表单 token 缓存给其他人。`/storage`、静态资源可以放心长缓存。

---

## 4. OPcache（生产必开，显著降低 TTFB）

在 PHP-FPM 的 `php.ini`（或宝塔 → PHP 设置 → 配置修改）中：

```ini
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.revalidate_freq=60        ; 每次发布后 reload php-fpm 即生效；追求极致可设 0 时间戳校验
opcache.validate_timestamps=1    ; 稳定后可设 0，发布代码后必须 reload php-fpm
opcache.save_comments=1
opcache.fast_shutdown=1
```

---

## 5. 日常更新 / 发布流程

```bash
cd /www/wwwroot/geo-website-os
git pull   # 或上传新代码
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan page-cache:clear     # 改了代码/配置/事实库后强制刷新整页缓存
# 如 validate_timestamps=0：systemctl reload php-fpm（宝塔：重启 PHP）
```

**静态化失效是自动的**：后台保存内容、栏目、Banner、菜单、首页装修、设置、媒体等，
整页缓存版本号会自动 +1，前台立即更新，无需手动清。
只有改了 PHP 配置、`config/`、`config/facts` 类文件型内容或 Blade 模板，才需要手动
`view:cache` + `page-cache:clear`。

---

## 6. 验证静态化是否生效

```bash
curl -I http://127.0.0.1:8010/        # 首次 X-Page-Cache: MISS，再次 HIT
```

- `X-Page-Cache: MISS`：本次动态渲染并写入缓存；
- `X-Page-Cache: HIT`：命中整页缓存（跳过渲染/查库）；
- `X-Page-Cache: BYPASS`：个性化页面（如留言成功提示）主动旁路；
- 响应头含 `ETag` 与 `Cache-Control: private, no-cache`，同一会话内容未变返回 `304`；
- `/sitemap.xml`、`/llms.txt`、`/robots.txt`、`/feed.xml` 为 GEO 输出，`Cache-Control: public, max-age=3600`。

不缓存：`/admin`、`/api`、`/search`、`POST /inquiry`、AJAX、登录态、带非白名单查询参数的请求。

---

## 7. 队列 / 计划任务（按需）

官网当前无异步任务，`QUEUE_CONNECTION=sync` 即可。后续若 GEOFlow 推送或邮件改为异步：

- `.env` 设 `QUEUE_CONNECTION=database`，执行 `php artisan queue:table && php artisan migrate`；
- 用 Supervisor 常驻：`php artisan queue:work --tries=3 --max-time=3600`；
- 需要定时任务时加 crontab：`* * * * * cd /www/wwwroot/geo-website-os && php artisan schedule:run >> /dev/null 2>&1`。

---

## 8. 备份与回滚

- **必须备份**：`database/database.sqlite`（或 MySQL 库）、`storage/app/public/`（上传图片）、`.env`。
- 建议每日定时备份数据库与上传目录，保留最近 7–14 份。
- 回滚：还原代码 + 还原数据库/上传目录，执行第 5 节缓存命令并 `page-cache:clear`。

---

## 9. 上线前自检清单

- [ ] `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL` 为正式 https 域名
- [ ] HTTPS 可访问，HSTS 按需开启，后台 `/admin` 使用强密码
- [ ] `config:cache / route:cache / view:cache / event:cache` 已执行
- [ ] OPcache 已开启，`X-Page-Cache` 第二次访问为 HIT
- [ ] 上传图片可访问（`public/storage` 软链正常），Nginx 静态缓存生效
- [ ] 留言提交全链路成功（前台提交 → 后台留言列表可见）
- [ ] `/sitemap.xml`、`/llms.txt`、`/robots.txt` 可访问且地址为正式域名
- [ ] 数据库与上传目录已纳入备份
