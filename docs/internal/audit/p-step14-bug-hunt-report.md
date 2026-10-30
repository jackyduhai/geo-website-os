# P-STEP 14 — Release Candidate Exploratory Bug Hunt Report

> 阶段定位：**不改架构、不扩功能，只找 Bug**；发现确定性缺陷 → 修复 → 回归 → 全新安装复测。
> 审核对象：v1.0.0-rc1（修复前 `27ac7b2`）；修复后 `be4e0c2`。
> 方法：以"第一次拿到产品的陌生用户"视角，在与主项目隔离的全新安装环境中，
> 走完整安装 → 前台 crawl → 异常输入 → 后台 CRUD → 状态往复切换 → 日志审计，而非按测试代码思路操作。

---

## 1. 执行概述

| 项 | 值 |
| --- | --- |
| 探索环境 | 独立全新安装（`git archive v1.0.0-rc1` + `composer install` + `php artisan geo:install`），`php artisan serve` |
| 修复复测环境 | 再次从修复后源码 `be4e0c2` 全新归档、全新 `composer install`、全新 `geo:install`（第二个干净环境） |
| 运行栈 | PHP 8.4.25 / Laravel 12.69.2 / Composer 2.10.3 / SQLite |
| 回归基线（P-STEP 13） | 609 tests / 2600 assertions / 0 failed / 0 skipped |
| 修复后全量回归 | **618 tests / 2625 assertions / 0 failed / 0 skipped** |
| 新增测试 | **+9 tests / +25 assertions** |
| 修复 commit | `be4e0c2`（working tree clean） |

探索覆盖：23 个前台公开路由 crawl、4 个 GEO/SEO 端点、搜索异常输入、留言表单、
后台登录 / 媒体上传 / 内容 CRUD、多站 Host 往复 6 次切换、安装器、上传安全、storage/logs 全量审计。

---

## 2. 发现汇总

| ID | 严重度 | 模块 | 问题 | 处置 |
| --- | --- | --- | --- | --- |
| Bug 1 | 高·功能 | 留言表单 | 选填项 `message` 留空提交 → HTTP 500，留言无法提交 | **已修复 + 测试 + fresh 复测** |
| Bug 7 | 中·安全 | 媒体上传 | SVG 上传存储型 XSS（可内嵌 `<script>`/`onload`） | **已修复 + 测试 + fresh 复测** |
| Bug 3 | 中·产品化 | 安装器 | `geo:install` 不创建 storage 软链，上传媒体前台全 404 | **已修复 + 测试 + fresh 复测** |
| Bug 2 | 中·前端 | SEO 头部 | 站点名/后缀为空时内页 title 尾部悬挂空分隔符 `" - "` | **已修复 + 测试 + fresh 复测** |
| Bug 4 | 低·产品化 | 配置 | `APP_NAME` 默认 `Laravel`，泄露到 feed.xml / robots.txt | **已修复 + fresh 复测** |
| Bug 5 | 中·SEO | Canonical | 内页 canonical scheme 跟随 request（http），首页强制 https，不统一 | 记录，留待 Controller/URL 解耦（见 §5） |
| Bug 6 | 低·SEO | 兼容重定向 | `/scenarios/` 双跳 301（→ `/scenarios` → `/solutions/`） | 记录，留待 URL 解耦（见 §5） |
| Issue A | 高·产品化 | Runtime 展示层 | fresh install 整站前台仍是Example专用内容（硬编码 Runtime，非数据库） | **需架构决策**（见 §6） |
| Issue B | 高·产品化 | GEO 核心 | fresh install 下 `/geo.json` 完全为空（新 Entity 体系与旧 Facts 展示层断裂） | **需架构决策**（见 §6） |
| Issue C | 产品覆盖 | 后台 UI | 无 Entity / EntityRelation / SeoMeta / Site / Theme / Plugin 后台管理界面 | **需产品决策**（见 §6） |

定性结论：**数据库 / 多站隔离 / Entity / SeoMeta 基础层通用且干净；测试体系未覆盖到的真实缺陷集中在
"安装器收尾、上传安全、表单选填项、展示层兜底"这层薄边**，均为小而确定的修复，未触及任何已冻结架构契约。

---

## 3. 已修复并验证的代码缺陷

### Bug 1 — 留言选填项 message 留空提交导致 HTTP 500【高】

- **复现**：全新安装后，前台 `/contact/` 只填姓名 / 手机 / 客户类型，不填"需求简述"提交。
- **实际结果（修复前）**：HTTP 500；`storage/logs/laravel.log` 记录
  `local.ERROR: Undefined array key "message"`（探索期限流复测中共计 5 次）。这是陌生用户的高频正常路径。
- **根因**：`app/Http/Controllers/Site/InquiryController.php` 中
  `trim((string)$data['message'])` 直接取键；`message` 为 nullable 选填，表单未填写时该键不存在，
  第 58–60 行本意做空值兜底却未判空。
- **修复**：`trim((string)($data['message'] ?? ''))`；为空时按原设计以客户类型兜底，
  写入 `客户类型：{demand_type}（未填写需求简述）`。
- **自动化回归**：`tests/Feature/InquiryTest.php` 新增 2 例
  （键完全缺失 / 显式空白字符串），断言 302、落库、兜底文案。
- **fresh HTTP 复测（修复后）**：
  - `POST /inquiry`（不含 message 键）→ **302 → /contact/**（不再 500）；
  - 数据库实测：`count=1`、`name=陌生用户`、`demand=其他`、
    `msg=客户类型：其他（未填写需求简述）`、`status=new`；
  - fresh 环境日志中 **Undefined array key = 0、500 = 0**。

### Bug 7 — SVG 上传存储型 XSS【中·安全】

- **复现**：后台媒体库上传含 `<svg ... onload="alert(1)"><script>...</script></svg>` 的 `.svg`，
  修复前可原样入库并经 `/storage/...` 以 `image/svg+xml` 直出，在浏览器执行脚本（存储型 XSS；多站 SaaS 下 A 站管理员可攻击访问者）。
- **根因**：`app/Http/Controllers/Admin/MediaController.php` 的媒体库 `store()` 与正文内联
  `uploadInline()` 上传白名单均包含 `svg`。（探索同时确认 `.php` 与 `.php.png` 双扩展伪造已被 `mimes` 内容校验正确拦截。）
- **修复（取舍）**：从两处白名单**移除 svg**（媒体库保留 `jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,mp4`；
  内联保留 `jpg,jpeg,png,webp,gif`），并加安全注释。不采用手写净化（易绕过）。
  **功能变更说明**：系统不再接受 SVG 上传；若后续确需矢量图，应引入专业净化库并以安全 `Content-Type`/`Content-Disposition` 提供。
- **自动化回归**：新建 `tests/Feature/MediaUploadSecurityTest.php`（4 例）：
  媒体库拒绝 SVG、内联编辑器拒绝 SVG（422）、伪造 `.php`（即使声称 image/png）被拒、合法 PNG 仍可上传（防误伤）。
- **fresh HTTP 复测（修复后）**：
  - 上传 `evil.svg` → 302（校验失败回退）；实测 `media_count=0`，磁盘递归扫描 **无任何 .svg 文件**；
  - 上传合法 PNG → 成功，`media_count=1`，`mime=image/png`。

### Bug 3 — geo:install 不创建 storage 软链【中·产品化】

- **复现**：全新安装后 `public/storage` 不存在；媒体上传成功但前台访问 `/storage/...` 返回 404。
  陌生用户上传的 logo / 封面 / 插图在前台全部不可见。（此缺陷同时使 Bug 7 在修复前"偶然"不可利用。）
- **根因**：`app/Console/Commands/GeoInstall.php` 引导流程未调用 `storage:link`。
- **修复**：在迁移之后、站点创建之前新增步骤，`public/storage` 缺失时调用 `storage:link`，已存在则跳过（幂等）。
- **自动化回归**：`tests/Feature/GeoInstallTest.php` 新增 1 例，断言安装后 `public/storage` 存在且重复安装不报错。
- **fresh HTTP 复测（修复后）**：
  - 安装日志实测：`The [public/storage] link has been connected to [storage/app/public]`、`[ok] storage link`；
  - `public/storage` 实测为 Junction；
  - 上传 PNG 后 `GET /storage/media/202609/xxx.png` → **200 / image/png / 281 bytes**。

### Bug 2 — 内页 title 尾部悬挂空分隔符【中·前端】

- **复现**：fresh install（未配置站点名 / SEO 后缀）访问内页，
  实测 `<title>文章标题 - </title>`，尾部多出孤立的 `" - "`；而 `og:site_name` 能正确取到站点名，两数据源不一致。
- **根因**：`app/Http/View/SeoHeadComposer.php` 中
  `(($title !== '' ? $title.' - ' : '').$suffix)` 在 suffix 为空时仍无条件拼接分隔符。
- **修复**：`array_filter([标题, 后缀])` 后 `implode(' - ', …)`，仅当后缀非空才拼接；`title_full` 显式传入时仍优先。
- **自动化回归**：`tests/Feature/SeoHeadComposerTest.php` 新增 2 例
  （后缀为空不得出现 ` - </title>`；后缀存在时正常 `标题 - 站名`）。
- **fresh HTTP 复测（修复后）**：
  - `/products/` → `<title>Sample SnackSample Marinade、Sample Breading撒料、调理鸡肉产品中心</title>`（无悬挂分隔符）；
  - `/solutions/` → `<title>你的店属于哪一类？六类门店Sample Marinade组合方案</title>`（无悬挂分隔符）；
  - 首页 → `<title>Default Site</title>`。
  - 注：内页文案本身含业务词属 Issue A（硬编码 Runtime），与本缺陷无关；本缺陷验证的是分隔符拼接行为。

### Bug 4 — APP_NAME 默认 Laravel 泄露【低·产品化】

- **复现**：`.env.example` 与 `config/app.php` 默认 `APP_NAME=Laravel`，导致 fresh install 的
  `/feed.xml` 频道 title/description 为 "Laravel"、`/robots.txt` 首行注释含 "Laravel"。
- **修复**：`.env.example` → `APP_NAME="GEO OS"`；`config/app.php` fallback → `'GEO OS'`。
- **fresh HTTP 复测（修复后）**：
  - `/feed.xml` → `<title>GEO OS</title>`、`<description>GEO OS</description>`；
  - `/robots.txt` 首行 → `# robots.txt · GEO OS · …`。
  - （feed 在无内容时 0 item 属 Issue B 的表现，不在本缺陷范围。）

---

## 4. 修复改动清单与业务污染

运行时 / 配置 6 个文件 + 测试 4 个文件（含 1 个新建），合计 +86 / −8 行：

```
.env.env.example                              APP_NAME → GEO OS
config/app.php                                name fallback → GEO OS
app/Console/Commands/GeoInstall.php           幂等创建 storage 软链
app/Http/Controllers/Site/InquiryController.php   message ?? '' 防空键 500
app/Http/View/SeoHeadComposer.php             array_filter 防悬挂分隔符
app/Http/Controllers/Admin/MediaController.php    两处上传白名单移除 svg
tests/Feature/InquiryTest.php                 +2
tests/Feature/SeoHeadComposerTest.php         +2
tests/Feature/GeoInstallTest.php              +1
tests/Feature/MediaUploadSecurityTest.php     新建，+4
```

- **业务污染扫描：0**。对全部改动文件逐一审查 diff，无 `Example / Example / Sample City / Sample Province / Sample Snack / Sample Marinade`
  进入 Core Runtime / 安装器 / 新增测试逻辑（既有测试 fixture 中的业务样例属允许范围，本次未新增）。
- 未删除 / 跳过 / 弱化任何既有测试；未违反任何已冻结架构契约（Content↔Entity 平行、SeoMeta 三态、多站隔离等均未触碰）。

---

## 5. 记录但本阶段不修复（需在后续阶段处理）

### Bug 5 — 内页 canonical scheme 与首页不统一【中·SEO】

- 现象：首页 canonical 由 `GenericUrlResolver` 强制 `https://{site.domain}/`；内页 canonical 由各前台 Controller
  基于 request 生成（本地为 `http://host/...`），scheme/host 来源不一致，不符合 5.6-A "HTTPS only" 契约。
- **不在 Bug Hunt 阶段修的原因**：彻底修复需要内页 Controller 统一接入 `UrlResolverInterface`
  （按站点 domain 而非 request host 生成），这属于已登记的 STEP 08 Controller/URL 迁移，且与旧 Facts Runtime 解耦绑定，
  在"只找 Bug、不改架构"阶段动它风险过大。
- **生产影响评估**：在正确配置 HTTPS 反向代理 + TrustProxies 的生产环境，request scheme 为 https，canonical 自然正确，
  该问题在生产不自伤；主要影响本地 / http 预览。建议随 Controller URL 解耦阶段统一收口。

### Bug 6 — /scenarios/ 兼容重定向双跳 301【低·SEO】

- 现象：`curl -L` 实测 `/scenarios/` →(301) `/scenarios` →(301) `/solutions/`，共 2 跳。
- 根因：兼容路由 `scenarios{slash?}` 未声明 `->defaults('_slash',1)`，全局 `CanonicalizeSlash` 中间件
  先去尾斜杠，再命中业务重定向。给该路由补 `_slash=1` 会让"无斜杠"方向反过来变双跳（中间件先补斜杠），
  无法靠单行声明同时消除两个方向的双跳。
- **不修的原因**：彻底修复需在被 `CanonicalizeSlashTest` 锁定的全局中间件中对"纯兼容重定向路由"做特例排除，
  风险 / 收益不划算；双跳 301 仍能正确传递与收敛，搜索引擎可正常跟随，无实质 SEO 损害。建议随 URL/路由解耦统一处理。

---

## 6. 需用户决策的架构级 Issue（超出"只找 Bug"边界，未改动）

> 三者均源于同一事实：**数据库层已通用干净，但前台展示层 / GEO 输出仍由旧 Facts 体系驱动，
> 新 Entity / SeoMeta 体系尚未与前台打通**。这是原计划 5.9–5.12 的旧业务 Runtime 解耦工作，不应在 Bug Hunt 顺手做。

### Issue A【高·产品化】fresh install 整站前台仍是"Sample CityExample Food"

- `geo:install` 的**数据库层是干净的**（契约明确不装业务演示数据；实测 entities=0、categories=0、media=0、
  contents 仅测试自建、sites=1 Default Site、users=1 admin、settings=10、page_blocks=16）。
- 但前台 23 个页面与 `llms.txt` 全文（公司名 / 2017 成立 / Sample City / Sample SnackSample Marinade / 年产能 / 400 电话 / 七大销售区域）
  全部来自**硬编码 Runtime 代码**：`config/facts.php`（约 153 处业务词）、`config/pages.php`（约 50 处）、
  `config/copy.php`（约 21 处），以及 Blade 中的Example fallback（`layouts/site.blade.php`、`admin/layout.blade.php`、login 页等）。
- 后果：安装器契约（"通用、无业务数据"）与前台实际表现自相矛盾，陌生用户安装后看到的是别人的公司。
- **建议**：单独立项做"旧业务 Runtime 解耦 / 通用空白安装 + 可选 Demo Seeder"，把 Facts/config/Blade fallback
  改为从 Entity/Content/Setting 读取，业务样例仅通过显式调用的 DemoSeeder 提供。**需用户确认范围与优先级。**

### Issue B【高·产品化】核心 /geo.json 在 fresh install 完全为空

- 实测 `GET /geo.json` →
  `{"$schema":"geo-os/graph/v1",…,"facts":[],"entities":[],"relations":[],"contents":[]}`（site.name="Default Site"）。
- `llms.txt` 走旧 Facts 体系有数据，`geo.json` 走新 Entity/EntityRelation 体系无数据——
  **新旧 GEO 数据源在全新安装下断裂**，GEO OS 的核心卖点安装后无内容。
- **建议（供决策）**：明确 fresh install 的 GEO 数据冷启动路径——安装器是否应内置一套**通用、无业务词**的
  示例 Entity/Relation（generic demo seeder），或提供"从 Facts 映射到 Entity"的引导，或保持空白但在安装向导中提示。
  与 Issue A 同属解耦大阶段。**需用户拍板。**

### Issue C【产品覆盖】新架构能力暂无后台管理 UI

- `routes/admin.php` 仅有 Content / Category / Group / Menu / Block / Media / Fact / Inquiry /
  Redirect / Setting / Narrative / Geo tools；**Entity、EntityRelation、SeoMeta、Site、Theme、Plugin 无管理界面**，
  目前只能通过 artisan / config / Repository 操作。
- **建议**：作为独立的产品化阶段规划后台 UI（与权限边界 5.4-I 对齐）。**需产品决策与排期。**

---

## 7. 已验证健壮、未发现问题的关键路径

- **公开路由**：23 个 sitemap URL 全部 200；favicon / favicon.png / apple-touch-icon / logo.png /
  wechat-qr.png / og-default.png 全 200 且 content-type 正确。
- **搜索 `/search`**：空查询、中文、`<script>`、SQL 注入片段、5000 字符、特殊引号 / 通配符均 200 无 500；
  XSS 经 Blade `{{ }}` 转义未反射。
- **后台内容 CRUD**：空标题 302 回表单、缺失 ID 404、重复 slug 302 拒绝、中文 + 空格 + `!!` 非法 slug 302 拒绝、
  正常中文内容创建成功且默认 draft；title 中 `<script>` 原样存储但前台输出转义。
- **多站隔离回归**：Host `a.test → b.test → a → b → a → b` 往复 6 次，首页 og:site_name / canonical 完全隔离，
  无缓存串站、无 Context 泄漏（P-STEP 10 的 SiteScope memo / SiteCacheKey 修复持续有效）。
- **上传安全（除 SVG 外）**：`.php` 直接上传、`.php.png` 双扩展伪造均被 `mimes` 内容校验拦截；蜜罐、
  `throttle:6,1`、字段校验、正常落库、中文客户类型选项均正常。
- **后台访问控制**：未登录访问 `/admin` → 302 跳登录；尾斜杠 301 规范化正常；大写路径 404（符合 Linux 预期）。
- **日志审计**：fresh 复测环境唯一 1 条 ERROR 为测试者使用 tinker 时 shell 转义造成的自伤 parse error
  （非应用缺陷）；应用 HTTP 路径 **0 SQLSTATE、0 Deprecated、0 N+1、0 个 500、0 个 Undefined array key**。

---

## 8. 最终回归与工程证据

### 全量自动化回归（主项目，修复后）

```
Tests:       618   （基线 609，+9）
Assertions:  2625  （基线 2600，+25）
Failed:      0
Skipped:     0
Time:        ~109s，Memory ~80MB
```

新增测试分布：Inquiry +2、SeoHeadComposer +2、MediaUploadSecurity（新文件）+4、GeoInstall +1。
（输出中的 85 条 PHPUnit Deprecations 为 PHPUnit 11 对既有测试写法的弃用提示，非失败、非错误，exit code 0。）

### 全新安装 HTTP 复测矩阵（修复后源码 be4e0c2，第二个干净环境）

| 验证项 | 结果 |
| --- | --- |
| 干净源码 `composer install` | ✅ package discover DONE |
| `php artisan geo:install`（38 migrations） | ✅ exit 0，Default Site + admin |
| storage 软链自动创建 | ✅ Junction，`[ok] storage link` |
| Bug 1 空 message 留言 | ✅ 302 + 落库 + 兜底文案，无 500 |
| Bug 2 内页 / 首页 title | ✅ 无悬挂 `" - "` |
| Bug 3 上传图经 /storage 访问 | ✅ 200 / image/png |
| Bug 4 feed.xml / robots.txt 名称 | ✅ GEO OS（非 Laravel） |
| Bug 7 SVG 上传 | ✅ 拒绝，0 落库 / 0 落盘；PNG 正常 |
| 复测期应用日志 | ✅ 0 业务 ERROR / 0×500 / 0 Undefined key |

### Git

- 修复前 HEAD：`27ac7b2`（v1.0.0-rc1）
- 修复后 HEAD：**`be4e0c2`** — `fix(P-STEP14): RC exploratory bug hunt fixes`
- Working tree：**clean**（本报告提交后亦保持 clean）
- 未改动任何 migration / 已冻结架构 / GEO Engine / Builder / Resolver 契约。

---

## 9. 结论与发布建议

1. **5 个确定性代码缺陷（含 1 个高频 500、1 个存储型 XSS、1 个安装器收尾缺陷）已全部修复，
   并通过"自动化回归 + 全新安装 HTTP 复测"双重验证。** 这些正是标准单元测试不易覆盖、而陌生用户首次使用就会踩到的真实问题，
   本轮 Bug Hunt 达成了既定目标。
2. Bug 5 / Bug 6 为**生产环境可自愈或无害**的 SEO 打磨项，已定位根因并明确归属后续 URL/Controller 解耦阶段，
   不阻塞 Release Candidate。
3. **Issue A / B / C 是比代码 Bug 更重要的产品化发现**：它们表明"数据库 / 引擎层通用化"已完成，
   但"前台展示层与 GEO 输出从旧 Facts 切换到新 Entity 体系"这一步尚未做。这**不影响多站 / Entity / SeoMeta 基础层的正确性**，
   但直接决定"陌生用户安装后看到的是不是一个通用、空白、可用的 GEO OS"。建议在打正式 v1.0.0 前，
   至少对 Issue A + B 给出明确产品决策（通用空白安装 + 可选 generic demo seeder 是推荐方向）。
4. 云端 GitHub Actions 首跑仍为 NOT VERIFIED（本地无 git remote），需在配置仓库并 push tag 后补完，
   方能从 RELEASE CANDIDATE READY 升级为 PUBLIC RELEASE READY。

**P-STEP 14 结论：探索式 Bug Hunt 完成，确定性缺陷清零并回归通过；
是否进入"旧业务 Runtime 解耦 / 通用空白安装"以及云端 CI 首跑，等待授权与产品决策。本轮不自行进入新功能开发、不打 v1.0.0。**
