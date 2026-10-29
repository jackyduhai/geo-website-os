# FAQ 与故障排查

> 版本口径：v1.4.0。命令与行为均可在仓库中复核。

## 安装与运行

### Q1：最小依赖是什么？
PHP 8.4+（`mbstring/openssl/tokenizer/xml/ctype/json` + 对应 `pdo_*`）+ Composer 2 + 数据库。
**Node.js 不是必需的**——前台主题以内联 CSS 随 Blade 布局发布，无任何视图引用 `@vite`；仅当你自行构建 Tailwind/Vite 资产时才需要 `npm install && npm run build`。

### Q2：为什么我访问出现「Please provide a valid cache path」500？
发布制品缺失 Laravel 缓存目录占位：`storage/framework/{cache/data,sessions,views}`、`storage/logs`、`bootstrap/cache/`。官方 Release zip 与 `git archive` 导出会携带占位；手工拷贝代码请补齐（见 [DEPLOY.md](../DEPLOY.md) 第 2 节）。

### Q3：上传图片前台 404？
缺 `public/storage` 软链。执行 `php artisan storage:link`（`geo:install` 会自动创建）。

### Q4：`geo:install` 报 `APP_KEY is missing`？
先执行 `php artisan key:generate`。安装器要求 APP_KEY 有效（会做加解密自检）。

### Q5：安装器创建的站点是空的？
是的。`geo:install` 创建**行业中立的空站**（默认设置、出厂首页、默认联系表单、系统页、栏目骨架），不装载任何业务演示数据。需要通用 Example 演示数据时执行 `php artisan db:seed`（开发/演示用；演示管理员 `admin@example.com / Admin@123456`，登录后立即修改）。

## 多站点 / 多语言

### Q6：为什么我用 IP 或 localhost 访问时显示的是默认站？
公开请求按 `Host` 头解析站点；未知 Host 在 `SITE_DEFAULT_FALLBACK=true`（开发默认）时回落默认站。多站生产必须设 `SITE_DEFAULT_FALLBACK=false`，未知 Host 返回「站点不存在」。

### Q7：为什么访问 `/en/...` 返回 404？
语言按站启用；请求了站点未启用的语言会返回 404，这是设计（不静默回落），不是错误。

### Q8：同一个站点内 `site_name` 等设置为什么中英文只有一个值？
`settings` 表无 `locale` 列（P2 已知限制）：同站跨语言共用 `site_name / site_description / brand_display_name / contact_address` 四个键。站点之间的隔离是完整的。

### Q9：分类名只有 `name_en`，这是不是没做完？
不是。分类 / 分组采用**字段级** `name_en` 翻译（v1.0 架构选择）；内容 / 实体 / 事实采用**行级**翻译模型，两者并存是刻意的。

## GEO / SEO

### Q10：`geo.json` 和 `llms.txt` 里的事实从哪来？
唯一权威源是 `facts` 表（`Fact::publicRows($locale)`）。SchemaBuilder（JSON-LD）不直接读 facts——Schema.org 字段集与业务事实表不是一一对应，实体属性类字段由站点 metadata 驱动。所有 AI 出口不得互相矛盾，由 `GeoFactConsistencyTest` 强制。

### Q11：新增事实后需要做什么？
新增公开事实必须同时在 `database/seeders/FactSeeder.php` 与 `app/Services/Geo/FactLabels.php` 登记，否则契约测试会失败并指出缺口。

### Q12：robots.txt 里的 AI 爬虫白名单在哪维护？
`config/geo.php` 的 `ai_crawlers`（OpenAI/Anthropic/Google/Bing/Perplexity/Apple/豆包/DeepSeek/元宝/百度/阿里/Kimi/智谱/360/华为等）；未列出的爬虫由 `User-agent: *` 覆盖。后台可追加 `robots_extra`。

## 缓存与性能

### Q13：改了内容前台不更新？
后台保存内容 / 栏目 / 媒体 / 设置等会自动失效整页缓存（版本号 +1）。只有改了 `config/`、Blade 模板等**文件型**内容才需手动 `view:cache` + `page-cache:clear`。

### Q14：`X-Page-Cache: MISS / HIT / BYPASS` 是什么意思？
MISS=本次动态渲染并写入缓存；HIT=命中整页缓存；BYPASS=个性化页面（如留言成功提示）主动旁路。`/admin`、`/api`、`/search`、表单提交、登录态不缓存。

## 数据库

### Q15：MySQL 是正式支持吗？
**候选支持**：MySQL 8.4 已实测通过（迁移 / 安装 / 回归 / 并发 / 演练），但**无 CI 门禁**，不进入发布阻断管线。PostgreSQL 16 与 SQLite 为正式支持（均有 CI 门禁）。

### Q16：升级报唯一约束冲突怎么办？
v1.4.0 的 `contents.slot` 收紧迁移会在 MySQL/PG 上探测「同站同 slot 不同 locale」的存量行并**中止报错**。先按 [安装与部署](installation.md) 第 5 节的 SQL 自查，人工确认后清理再升级。

### Q17：迁移失败会自动回滚吗？
不会。失败会停在失败点（失败迁移不进台账），需人工处置；详见数据库矩阵第 4.3 节。恢复操作请落到**空库**，并以业务数据核对为准，不要只看命令退出码。

## 版本与发布

### Q18：`php artisan geo:version` 显示 1.0.0，但 Release 是 v1.4.0？
属实。`config/geo.php` 的版本默认值为 `1.0.0`（`GEO_OS_VERSION` 环境变量可覆盖），未随 v1.4.0 标签更新，属**已知版本口径待办**。发布标签 / Release 以 git tag 与 GitHub Releases 为准。

### Q19：v1.4.0 的发布资产有哪些？
`geo-os-v1.4.0.zip`（10,256,380 B）、`geo-os-v1.4.0.zip.sha256`、`release-manifest.json`（CI 生成，含 commit / tag / ci_run_id / artifact_checksum / test_results），SHA-256 已实测比对一致。仓库内的 `release-manifest.json` 是模板，权威清单以 Release 资产为准。

### Q20：还有哪些已知边界？
- MySQL 无 CI 门禁；PG/MySQL 全量回归不进 CI（CI 只跑 PgsqlCore 子集）。
- 9 个用例仅 SQLite 验证（站点创建生产等价 7 + 升级/回滚 E2E 2），PG/MySQL 上零证据。
- `content_entity / content_tag / pages` 三表无外键（既有设计现状，三库一致）。
- Admin UI 仅中文；向导 / 后台尚无英文界面。
- 路线图能力（定时发布、内容引擎、分析面板等）均未实现，见 [路线图](roadmap.md)。

## 其他

### Q21：FTS5 搜索索引被清空后报错？
对 SQLite FTS5 虚表执行 `DELETE FROM <表>` 会清空内部索引，后续插入报 `invalid fts5 file format`。清空后必须执行 `INSERT INTO <fts5表>(<fts5表>) VALUES('rebuild')` 重建；正常维护用 `search:reindex`。

### Q22：如何报告安全漏洞？
见 [SECURITY.md](../SECURITY.md)：**不要开公开 issue**，请私密报告。错误页不会暴露调试详情 / 堆栈 / SQL / 用户数据（生产 `APP_DEBUG=false`）。
