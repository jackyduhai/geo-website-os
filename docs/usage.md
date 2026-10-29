# 使用手册

> 本文只描述 **v1.4.0 中已实现并可通过后台 / 路由验证的功能**。
> 涉及尚未验证的页面或命令时明确标注「待验证」；不编造操作步骤。
> 后台路由清单见 `routes/admin.php`（以下操作均有对应路由）。

## 1. 后台入口与登录

- 后台地址：`/admin`（如 `http://127.0.0.1:8000/admin`）。
- 登录：`GET /admin/login` 提交 `POST /admin/login`（`admin.login` / `admin.login.attempt`）。
- 修改密码：`GET /admin/password` → `PUT /admin/password`。
- 退出：`POST /admin/logout`。
- 首次安装后管理员账号为 `geo:install --admin-email=...` 指定的邮箱（未指定默认 `admin@example.com`，密码未指定则随机生成并只打印一次）。
- **初始化向导**：新站首次进入会看到 `GET /admin/wizard` 引导（站点信息、品牌口号、行业模板偏好、管理员等步骤）。向导只记录偏好，**不会在向导内应用模板**；行业模板偏好用于后续模板选择。

## 2. 站点管理（多站点）

| 操作 | 路由 | 说明 |
| --- | --- | --- |
| 站点列表 / 新建 / 编辑 / 删除 | `admin.sites`（resource，无 show） | 站点名称、域名、状态（active / inactive）、默认站 |
| 设为默认站 | `admin.sites.default` | 唯一默认站（数据库约束） |
| 切换当前管理站点 | `admin.sites.switch` | 顶部切换器；有未保存表单时会提示确认 |
| 站点初始化结构 | 建站自动执行 | 栏目骨架、导航可见性、默认设置、系统页、双语开关（`SiteStructureSeeder`，与 `geo:install` 同源） |

- 前台按 `Host` 头解析站点；**公开请求不能用查询参数切换站点**。
- 多站生产环境 `.env` 设置 `SITE_DEFAULT_FALLBACK=false`，未知 Host 返回「站点不存在」。

## 3. 内容管理

| 操作 | 路由 | 说明 |
| --- | --- | --- |
| 内容列表 | `admin.contents.index`（tab: article / page / all） | 文章与页面 |
| 新建 / 编辑 / 删除 | `admin.contents.create/store/edit/update/destroy` | 类型 article / page |
| 发布 / 下线 | `admin.contents.publish/unpublish` | 发布时受 `config/geo.php` 发布门禁校验 |
| 发布前检查 | `admin.contents.check` | 结构化与合规校验 |
| Markdown 预览 | `admin.contents.md-preview` | 后端渲染预览 |
| 修订历史 | `admin.contents.revisions` | 内容修订记录 |
| 回收站 | `admin.contents.trash` | 软删除内容 |
| 恢复 / 彻底删除 | `admin.contents.restore / force-delete` | 回收站操作 |

> 内容发布后前台整页缓存自动失效（PageCache 版本号 +1），无需手动清理。

## 4. 页面组合与区块

| 操作 | 路由 | 说明 |
| --- | --- | --- |
| 页面列表 / 新建 / 编辑 / 删除 | `admin.pages.*` | 结构化页面 |
| 页面组合器 | `admin.pages.composer` | 区块（Blocks）组合编排 |
| 区块增删改 | `admin.pages.addBlock / storeBlock / editBlock / updateBlock / destroyBlock` | 区块级编辑 |
| 区块排序 / 启停 / 变体 / 复制 | `moveBlock / toggleBlock / setVariant / duplicateBlock` | 区块管理 |
| 页面发布 / 预览 | `admin.pages.publish / preview` | 发布动作 + 预览 |
| 区块库 | `admin.blocks.index` | 可复用区块索引 |

> 区块是**语义数据**驱动的（不是自由画布）：类型、字段、变体受服务端字段声明约束（例如 hero 变体 `split/center` 白名单），伪造非法值会被归一化（`BlockSelectWhitelistTest` 覆盖）。

## 5. 实体与关系（知识图谱）

| 操作 | 路由 | 说明 |
| --- | --- | --- |
| 实体列表 / 新建 / 编辑 / 删除 | `admin.entities.*` | 8 类：organization / person / product / service / location / topic / case_study / download_asset |
| 发布 / 下线 | `admin.entities.publish/unpublish` | 实体发布 |
| 停用 / 退役 | `admin.entities.deactivate/retire` | 生命周期操作 |
| 恢复 / 彻底删除 | `admin.entities.restore / force-delete` | 回收站 |
| slug 建议 | `admin.entities.slug-suggest` | 自动生成 slug |
| 示例数据 | `admin.entities.seed` | 实体示例种子 |
| 关系管理 | `admin.relations.*` | 五型关系（produces / offers / uses / located_in / related_to） |

## 6. 事实管理（AI 事实契约）

| 操作 | 路由 | 说明 |
| --- | --- | --- |
| 事实列表 / 新建 / 编辑 / 删除 | `admin.facts.*` | **facts 表是业务事实唯一权威源** |
| 事实翻译 | `admin.facts.translations.store` | 行级翻译（编辑路径以**行自身的语言**为准） |

- 新增公开事实必须同时在 `database/seeders/FactSeeder.php` 与 `app/Services/Geo/FactLabels.php` 登记，否则 `GeoFactConsistencyTest` 会失败。
- `geo.json` 与 `llms.txt` 的事实出口都经 `Fact::publicRows($locale)` 取得，禁止另建事实源。

## 7. 多语言操作

- 语言：`zh-CN`（默认）与 `en`，**建站时按站点启用**。
- 内容 / 实体 / 事实：**行级翻译模型**（`locale` + `translation_group`），同一 slug 跨语言共存；编辑翻译时保持行自身语言。
- 前台：语言前缀路由 `/en/...`；请求未启用语言返回 **404**（不静默回落）。
- 分类 / 分组：字段级 `name_en`。
- 已知边界：`settings` 同站跨语言共用 4 键；Admin UI 仅中文。

## 8. SEO 配置

| 操作 | 路由 | 说明 |
| --- | --- | --- |
| SEO 元数据列表 | `admin.seo-metas.index`（scope: site / content / entity / page / all） | 按绑定范围筛选 |
| 新建 / 编辑 / 删除 | `admin.seo-metas.create/store/edit/update/destroy` | 三态绑定（Site / Content / Entity / Page） |

- canonical、Open Graph、hreflang、JSON-LD 由 `SeoMetaResolver` 按继承链解析，URL 由 `UrlResolverInterface` 统一生成（HTTPS canonical、去 query、统一尾斜杠、不产生跨站 canonical）。
- robots.txt 的 AI 爬虫白名单在 `config/geo.php`（后台可覆盖 `robots_extra`）。

## 9. GEO 功能配置

| 入口 | 路由 | 说明 |
| --- | --- | --- |
| GEO 工具 | `admin.geo.tools` | GEO 相关工具页 |
| GEO 预览 | `admin.geo.preview/{kind}` | 预览 GEO 输出形态 |
| GEO 健康 | `admin.geo.health` | 运行时语义健康只读聚合 |
| 实体覆盖度 | `admin.geo.coverage` | 资产齐备度只读聚合 |
| 同步日志 | `admin.geo.sync-logs` | GEOFlow 同步日志 |

- 机器可读出口：`/geo.json`（实体/知识图谱）、`/llms.txt`（站点摘要与引用）、`/sitemap.xml`、`/feed.xml`、`/robots.txt`、页面 JSON-LD。
- GEOFlow 上游同步：`config/geo.php` 的 `sync.enabled` 默认 `false`；启用后接口仍可访问但写入需 token 与开关（kill switch）。后台「设置 → 同步」可重新生成 token（`admin.settings.sync.regenerate-token`）。

## 10. 媒体 / 菜单 / 表单 / 询盘 / 重定向

| 模块 | 路由 | 说明 |
| --- | --- | --- |
| 媒体库 | `admin.media.*`（index / store / inline / update / destroy） | 上传、内联上传、编辑、删除；**被引用的媒体禁止删除**（引用守卫），删除失败可 `media:retry-deletes` 重试 |
| 菜单 | `admin.menus.*`（含 override / override.reset） | 菜单管理 + 覆盖与重置 |
| 表单 | `admin.forms.*`（字段管理、提交记录、启停） | 自定义表单与字段 |
| 询盘 | `admin.inquiries.*`（index / handle / destroy） | 前台提交的询盘处理；含 utm_* / landing_url / referer 归因 |
| 重定向 | `admin.redirects.*` | 301 重定向管理 |

## 11. 主题 / 模板 / 插件

| 模块 | 路由 / 命令 | 说明 |
| --- | --- | --- |
| 主题 | `admin.themes.index`（activate / preview） | 视图级主题切换（默认 / example） |
| 模板包 | `admin.templates.*`（activate / deactivate / bootstrap / preview / live-preview / screenshot / compare） | 声明式模板包：激活为整体事务 + 站点行锁，支持实时预览与对比 |
| 插件 | `admin.plugins.*`（enable / disable） | 运行时启用 / 停用（示例 `plugins/hello`） |

> 模板激活的并发安全（站点行锁 + 整体事务 + 提交后可恢复缓存失效）是 v1.4 重点修复能力，见 `TemplateActivationConcurrencyW3NVTest`。

## 12. 内容发布及验证

**发布**：内容 / 页面 / 实体在后台「发布」后，前台立即可见（发布门禁校验通过的前提下）。

**验证清单（发布后）**：

```bash
# 前台页面
curl -I http://127.0.0.1:8000/            # X-Page-Cache: MISS（首次）/ HIT（再次）
# 机器可读出口（应返回 200 且内容为当前站点）
curl -s http://127.0.0.1:8000/geo.json
curl -s http://127.0.0.1:8000/llms.txt
curl -s http://127.0.0.1:8000/sitemap.xml
curl -s http://127.0.0.1:8000/feed.xml
curl -s http://127.0.0.1:8000/robots.txt
# 语言前缀（未启用语言的站点返回 404）
curl -I http://127.0.0.1:8000/en/          # 已启用 en：200；未启用：404
```

- 页面 JSON-LD：查看页面源码中的 `schema.org` 结构化数据，应与可见内容一致（不一致即触发契约测试口径问题）。
- 内容变更后整页缓存自动失效；只有改了 `config/`、Blade 模板等文件型内容才需手动 `view:cache` + `page-cache:clear`。

## 13. 常见业务场景操作指南

**场景 A：新建一个站点**
1. 后台「站点」→ 新建（名称、域名、状态）。
2. 建站自动完成初始化结构（栏目骨架、导航、默认设置、系统页、双语开关）。
3. 顶部切换器切到新站点。
4. 建内容 / 实体 / 事实 → 发布 → 按第 12 节验证。

**场景 B：双语内容**
1. 建站时启用 `zh-CN` + `en`。
2. 为内容 / 实体 / 事实分别维护两语言行（行级翻译）。
3. 前台 `/en/...` 访问英文版；未启用语言返回 404。

**场景 C：让 AI 正确引用企业事实**
1. 在「事实」中维护经营事实（成立时间、面积、产能等）——**唯一权威源**。
2. 在「SEO 元数据」为对应资源配置 title / description / canonical。
3. 发布后验证 `/geo.json` 与 `/llms.txt` 输出一致（`GeoFactConsistencyTest` 口径）。
4. 通过「GEO 健康 / 覆盖度」观察语义健康与资产齐备度。

> 提示：上述场景均基于 v1.4.0 已验证路由；若在后台找不到某入口，请核对版本与 `routes/admin.php`。
