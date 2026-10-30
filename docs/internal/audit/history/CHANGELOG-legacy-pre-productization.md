# 归档说明（Archive Notice）

> 本文件是 **GEO Website OS 产品化之前**的开发变更记录，归档于 `docs/audit/history/`，仅用于工程历史追溯与升级 / 回滚参考。
> 其中包含原始业务项目（改造来源）所处的行业语境与历史内容，**不代表 GEO Website OS 的产品代码或默认数据**；产品化后这些内容已从 Runtime、默认数据与正式文档中移除。
> 本目录随发布包 `export-ignore`、不分发；面向使用者的变更记录见根目录 `CHANGELOG.md`。

---
# 变更记录（CHANGELOG）

遵循语义化版本 SemVer（主.次.修订）。

## [0.9.38] - 2026-09-18

> GEOFlow 对接接口真实通讯联调（临时配置 Token + 开关，curl 全分支 HTTP 实测），修复 1 个 P2 接口缺陷并补回归测试；联调数据与临时配置已全部清理，接口恢复默认全关。

### Fixed
- **P2：upsert 缺 external_id 返回 500**（`app/Services/Sync/GeoflowSync.php`）。缺 external_id 时 `reject(null, ...)` 触发 TypeError（reject 首参声明为 string），对外是 HTTP 500 而非契约规定的 422。改为传空串，reject 首参放宽为 `?string` 并强制转字符串落日志。
- 新增 2 条回归：缺 external_id 的 upsert 必须 422（不得 500）；GET 集合路由必须 405。

### Verified（真实 HTTP 模拟，非单测推断）
- 默认全关：未配置 Token 时 POST/GET 单条 **503**、GET 集合 **405**、/api/v1/health **200**（health 中 geoflow.push_enabled=false）。
- 鉴权：错误/缺失 Bearer **401**（hash_equals 比对）。
- 开关：Token 正确但接收开关关闭 **403** 且不写库。
- 写入：合法 payload 自动发布关 → **200 create/draft**；开自动发布后变更推送 → **200 update/published**，返回 content.url/hash/status 正确。
- 幂等：external_id + content_hash 未变 → **200 skip**，不产生重复内容。
- 门禁：缺结论层 → check 返回 passed:false 不落库；新建缺层 upsert **422**。
- 人工锁：lock_manual 内容遇上游变更 → **409 conflict**，人工版本保留不覆盖。
- 下架：unpublish 存在 ID → **200 archived**（GET 状态随之变更）；不存在 ID **404**；缺 external_id **422**；状态查询存在 **200**/不存在 **404**。
- 每次接收均写 sync_logs（create/skip/update/conflict/unpublish/reject/disabled 全动作有审计记录）。
- 全量 **193 passed / 867 assertions**；后台冒烟 **24/24**。

### Notes（契约行为说明，非缺陷）
- Laravel 默认全局中间件 ConvertEmptyStringsToNull 会把请求中的空字符串转 null：更新已有内容时，显式传 `""` 的字段等同于「未提供」，按 PATCH 合并语义保留旧值（门禁在合并后校验，缺层内容无法发布，安全方向正确）。即 API 不能用空串清空字段，下架请走 unpublish。
- 请求体不是合法 JSON 时解析为空，会落到 external_id 必填的 **422**（而非 400）；上游须保证 Content-Type: application/json 与合法 JSON。
- 联调临时 Token/开关已恢复初始值（token 空、开关关、自动发布空），测试内容、版本快照、sync_logs 已清空（contents 回到 3 篇），sitemap 与前台无测试文章（其 URL 实测 404）。
## [0.9.37] - 2026-09-17

> 成品级全栈系统验收（54 节工程验收任务）：对前台 42 URL、后台 27 路由、留言/搜索/GEOFlow 链路、性能、SEO/GEO、移动端七档、安全与工程质量做真实运行体检，修复 2 个 P1 与一批系统性 P3，并补齐回归测试。

### Fixed
- **P1 尾斜杠规范化与实体解析矛盾**（`app/Http/Middleware/CanonicalizeSlash.php` 整体重写）：新增 `resolveWantsSlash()` 实体级判定（目录型 true / 详情型 false / 无实体 null），匹配顺序与 `PageController::dispatch` 一致；知识频道与扁平文章、统一分发器分别分流；垃圾路径（如 /knowledge/xyznope、/newsxy）两种形态**直接 404，不再发 301**，消除「canonical 声明的 URL 自身被 301」与垃圾路径被 301 洗白的问题。
- **P1 Apache 重定向环隐患**（`public/.htaccess`）：删除框架默认「非目录去尾斜杠 301」块，避免与应用层尾斜杠规则互相打架；新增 mod_deflate 文本压缩块，保留 30 天静态缓存块。
- **错误页 SEO 同类问题统一修复**：404/403/500/503 四个错误页补齐独立 `$seo`（标题 + description + `noindex`），原先 403/500/503 标题只剩品牌名且可被索引。
- **后台资源 ID 枚举**（`bootstrap/app.php`）：调整中间件优先级，`EnsureAdmin` 先于 `SubstituteBindings`；未登录访问任意后台路由（含不存在的资源 ID）统一 302 到登录页，不再先返回 404 泄露 ID 存在性。
- 首页新闻块「查看全部动态」链接由 /news 修正为规范形态 /news/。

### Security
- **上传目录加固**：新增 `storage/app/public/.htaccess`（关闭脚本引擎、RemoveHandler、nosniff、上传区 CSP sandbox、X-Frame-Options DENY、禁 ExecCGI/Indexes），即便绕过上传校验，上传目录内脚本也不能执行、SVG 直访脚本被沙箱拦截（Nginx 等价规则列入上线清单）。
- `SecurityHeaders` 中间件改为 web 组最外层（prepend），鉴权 302、旧链 301、缓存 HIT/MISS/304 全部回程统一补安全头（修复鉴权优先级调整后 302 丢 CSP 的回归）。

### Added
- 回归测试 4 条（`tests/Feature/SlashCanonicalResolverTest.php`）：知识文章=详情型、启用栏目=目录型、未知路径=null、停用栏目=null。
- `public/.htaccess` mod_deflate 压缩（HTML/CSS/JS/SVG/XML/JSON/字体）。

### Audit（实测确认、无需改动的面）
- 安全：CSP nonce（前台）/unsafe-inline（后台历史内联事件）、nosniff、SAMEORIGIN、Referrer-Policy、Permissions-Policy 齐全；未登录伪造 POST 上传 419（CSRF）；后台登录限速 5 次/300 秒；留言 6 次/分钟（实测第 8 次 429）；上传白名单 + evil.php 拒绝测试；全站无 raw SQL、无 dd/dump/var_dump、无调试代码；.env/composer.json/数据库均在 web 根之外；GEOFlow 未配 Token 时全部 503（集合 GET 405），预留接口默认全关。
- 性能：业务页 3–8 条 SQL、无 N+1；整页静态化 HIT 响应头与 ETag 正常；首页 13 图（10 lazy / 3 eager）、10 组 picture WebP 双源、零第三方域名与外部字体；静态资源 TTFB 2–4ms。
- SEO/GEO：sitemap.xml 30 URL 全部 200 且 canonical 形态 0 mismatch；前台 42 URL 死链 0、每页单 H1、缺 alt 0；JSON-LD 按页型匹配且全部可解析；llms.txt / robots.txt / RSS 正常。
- 移动端：360/375/390/414/430/768/1024 七档 × 12 页零横向溢出（产品 Tab 横滑容器与背景图 4px 为有意/无害例外）；桌面下拉与移动抽屉同源、移动端两按钮（400 电话 + 免费获取样品）齐全。

### Verified
- 全量 **191 passed / 864 assertions**（v0.9.36 基线 187/856，+4 测试 / +8 断言）；后台冒烟 **24/24**。
- 真实 HTTP 复测：尾斜杠四种形态、垃圾路径 404、未登录后台 4 组 URL 全 302 且 302 带 CSP、留言空/非法/蜜罐/合法/限流全链路、错误页 view:cache 全量编译通过；无头 Chrome 桌面下拉与移动抽屉截图回归；首页/联系/产品三页 Console 零错误。
- 审计临时脚本与探针（9 个 scripts/audit_*、5 个 public/_*.html）验收后全部删除，public 零残留。
- 未验证项（环境限制，非缺陷）：Core Web Vitals 实验室指标（本机 Node 10 无法运行 Lighthouse、CDP 无 websocket 依赖）、composer audit（环境无 composer）、Apache/Nginx 生产 Web 服务器行为（artisan serve 不读取 .htaccess）。

## [0.9.36] - 2026-09-17

> 沿 v0.9.35「后台可运营数据必须与前台每一个消费面同源」的判据全站复查，修复两处同类分叉：sitemap.xml 漏掉后台启用的自定义栏目（如新闻 /news/）与非知识类已发布文章、且未排除 noindex 文章；404 页快捷入口写死六个栏目，不跟随后台对一级栏目的改名 / 隐藏 / 自定义。

### Fixed
- **sitemap.xml 数据源补全**（`app/Services/Geo/SitemapBuilder.php`）：
  - 新增收录「后台启用且非 single 型」的自定义栏目页（如 /news/，weekly/0.5）；single 型栏目直接渲染其下文章、规范地址是文章 URL，故不重复收录栏目地址。
  - 新增收录归属启用栏目的**全部**已发布文章（知识类仍保持 0.6，其余栏目 0.5，lastmod 取 updated/published），修复首页新闻块链接 /news/ 但 sitemap 与 RSS 自相矛盾的问题。
  - 知识文章与全量文章两处查询均补 `noindex = false` 过滤（原 noindex 文章会泄漏进 sitemap）。
  - 收录闭包增加按 loc 去重，固定 IA 与数据库栏目重合时（如 /knowledge/）只保留高优先级的一条。
- **404 快捷入口同源化**（`resources/views/errors/404.blade.php`）：六张入口卡由硬编码改为遍历 `AppServiceProvider::mainMenu()` 派生，自动跳过外链、`#` 纯父级、tel:/mailto:，末尾若主导航不含联系页则兜底追加「联系我们」；后台改名 / 隐藏 / 新增一级栏目在此同步生效。卡片区增加 `err-entries` 定位类；原固定「合作方式」不再出现在 404（它本就不属于主导航蓝图，仍可经页脚与首页 CTA 到达）。

### Added
- 回归测试 3 条：启用栏目 /news/ 与其已发布文章进入 sitemap（V0911CmsAlignmentTest）；停用旧栏目、single 型栏目与 noindex 文章不进入 sitemap（同文件）；404 快捷入口跟随主导航覆盖层、隐藏一级栏目后入口卡同步消失（MenuOverrideTest，断言范围限定在入口卡片区以避开页脚）。

### Audit（排查后确认同源、无需改动的面）
- 顶部桌面下拉与移动抽屉为同一个 `ul.nav`（checkbox hack），同读 `mainMenu()`；产品 / 知识 / 关于页内 Tab 已在 v0.9.35 与下拉同源；知识分组启停联动下拉 / Tab / sitemap（已有测试覆盖）。
- 首页全部区块 page_blocks 驱动（无内容回退默认）；栏目页与统一分发器完全 Category/Content 模型驱动；站内搜索查全部已发布文章；联系 / 合作页由 settings 与事实库驱动；页脚有多条 MenuOverrideTest 覆盖；面包屑按实体派生；后台栏目列表显示全部栏目（含停用）。
- `/solutions/` 六场景为富内容实体卡、详情页为场景序列翻页，`/factory/` 为同页锚点，均无 Tab 条——挂接的自定义菜单在顶部下拉 / 移动抽屉出现是正确分工，不构造 Tab。
- `llms.txt` 为权威事实 + 常青知识的策展文件（事实库驱动），自定义导航链接与时效性新闻不自动进入；完整可索引清单由 sitemap 承担。

### Verified
- 全量 **187 passed / 856 assertions**（v0.9.35 基线 184/842，+3 测试 / +14 断言）；后台冒烟 **24/24**。
- 实跑验收：线上 /sitemap.xml 含 /news/ 一条、/knowledge/ 仅一条、三篇知识文章各一条，无停用旧栏目（/about/company/、/products/chinese-marinade/）；无头 Chrome 1440 截图 404 页六张入口卡（产品中心 / 应用场景 / 工厂与资质 / 知识中心 / 关于我们 / 联系我们）样式正常。
- 临时排查脚本 scripts/_chk_cat.php 验收后删除，public 目录零残留。

## [0.9.35] - 2026-09-17

> 修复「后台给固定一级栏目追加二级菜单后，只有顶部导航下拉显示，页面内二级 Tab 与后台固定栏目表都不显示」的数据源分叉问题；按全站统一铁律，产品 / 知识 / 关于三类页面的页内 Tab 全部改为与顶部下拉同一数据源，后台挂接项内联展示在所属栏目行下。

### Fixed
- **三套「二级导航」数据源分叉（根因）**：顶部下拉读 `mainMenu()`（固定蓝图 + 自定义挂接项合并），产品页内 Tab 读 `Facts::productLines()`、知识页内 Tab 读内容分组、关于页 Tab 写死，后台挂接项只在页面底部「自定义菜单」卡平铺。自定义二级项因此只出现在下拉里。
- **页内二级 Tab 同源化**：
  - `AppServiceProvider` 新增 `mergedMenuChildren($topKey)`，输出某固定一级栏目合并后的二级子项（固定 / 内容分组动态项 + 自定义挂接项，统一排序，与顶部下拉完全同源）；`mainMenu()` 输出补充内部 `key` 字段（自定义一级为 null），前台视图不消费该字段。
  - `ProductController::subnav()` 改为「全部产品 + products 合并子项」：自定义挂接项作为 Tab 出现，导航中被隐藏 / 改名的体系与下拉同步；系列页、详情页高亮逻辑不变，自定义外链 Tab 不高亮且 `target="_blank" rel="noopener"`。
  - `KnowledgeController::render()` 改为「全部 + knowledge 合并子项」，内容分组与自定义挂接项同源；`AboutController` 改为「about 合并子项」（企业简介 / 发展历程 / 企业文化 / 联系我们 + 自定义挂接项）。
  - `_subnav.blade.php` 支持控制器预算的 `on` 当前态与 `external` 外链属性，旧的 slug 比较方式保留为回退。
- **后台菜单管理结构化**（admin/structure/menus）：挂接到固定一级栏目 / 页脚列的自定义项不再堆在底部「自定义菜单」表，改为内联渲染在所属栏目行下，带「自定义追加」徽标与完整编辑 / 删除表单（新增 partial `admin/partials/menu-anchored-row.blade.php`）；底部表仅保留自定义一级及其二级；帮助文案同步更新。

### Added
- 回归测试 3 条（MenuOverrideTest）：挂接到产品中心的自定义二级出现在产品总览与系列页 Tab；导航覆盖层隐藏固定子项后页内 Tab 同步消失；后台固定栏目表内联展示挂接项且旧「挂于：」平铺不再出现。

### Verified
- 全量 **184 passed / 842 assertions**（原基线 181/828，+3 测试 / +14 断言）；后台冒烟 **24/24**。
- 无头 Chrome 1440 / 390 截图验收：/products/ 与 /products/seasoning/ Tab 条含「测试」且当前态正确（全部产品 / Sample SnackSample Marinade分别高亮），移动端 Tab 条横向滚动正常；后台 /admin/menus 产品中心行下内联显示「测试 · 自定义追加」；临时渲染脚本验收后删除（public 零残留）。

## [0.9.34] - 2026-09-17

> 后台首页装修「首屏主视觉」A/B/C 模式卡选中态不符合全站设计标准（粉底 + 红色描边 + 3px 红光晕 + 实心红圆徽，视觉过重），按统一 Design Token 收敛；全站排查确认仅此一处「可选择卡片」，无同类遗漏。

### Changed
- **选中态重构**（public/css/admin.css `.hmode-card`）：
  - 去掉选中时的浅红粉底（brand-soft）与 3px 外发光环（brand-ring 误用为选中态）；选中 = 白底 + 1px 品牌红描边 + 右上 15px 线性对勾（SVG，替代原字符 ✓）。
  - 字母徽标 A/B/C：默认中性灰底灰字；选中改为浅红底（brand-soft）+ 品牌红字（原为实心红圆白字，过于抢眼）；hover 时文字徽标轻微加深。
  - 未选中 hover：浅灰底 + 发丝边框加深，反馈克制；卡片描边统一 1px（原 1.5px）。
  - 3px brand-ring 外发光仅保留给键盘 `:focus-within` 焦点态（可访问性），不再承担选中语义；过渡统一走 `--ease/--dur` Token。
- 视图 resources/views/admin/display/blocks.blade.php：对勾由字符改为与全站一致的线性 SVG（stroke currentColor）。
- 全站 grep 确认 `hmode-card` 是后台唯一的卡片式单选控件，其余选中态（筛选胶囊 tab、侧栏、复选框）本就符合标准，本次不产生新的风格分叉。

### Verified
- 全量 **181 passed / 828 assertions**；后台冒烟 **24/24**。
- 无头 Chrome 1440 与 390 宽截图验收：选中 C 卡为白底红描边 + 浅红字母徽标 + 线性对勾，A/B 中性；移动端单列堆叠正常；临时渲染文件验收后删除（public 零残留）。

## [0.9.33] - 2026-09-17

> 页脚信息架构与排版优化：「联系我们」移至最右列（符合从产品到联系的阅读/转化习惯），联系信息改为标签/值上下堆叠，修复号码中间折断、地址换行难看的问题；同步平板与移动端布局。

### Changed
- **页脚列顺序调整**：品牌 ｜ 产品中心 ｜ 应用场景 ｜ 关于我们 ｜ **联系我们（最右）**；唯一数据源 `config/copy.php` 页脚列重排，后台「顶部导航与页脚」页脚列顺序同步一致（前后台不产生错位）。
- **联系列排版重做**：全国合作热线 / 业务手机 / 厂区地址改为「小标签在上、值在下」堆叠结构；电话与手机号 `white-space:nowrap` 不再从号码中间折断；地址允许自然换行；值用更高一档文字色建立层级；联系列宽度 1fr → 1.2fr，呼吸感更好。
- **响应式**：≤1024px 平板品牌区整行、四个链接列 2×2，联系列落在右下；≤768px 手机单列时联系列仍排最前（点按拨号优先），品牌区置底。
- 前台页脚模板（layouts/site.blade.php）联系项增加 `.ft-contact-line/.ft-k/.ft-v` 结构类，数据仍全部取自站点设置（电话/手机/地址/二维码），无硬编码回退变化。

### Verified
- 全量 **181 passed / 828 assertions**；后台冒烟 **24/24**。
- 无头 Chrome 1440 / 820 / 390 三档页脚截图验收：桌面联系列最右且号码/地址单行完整，平板 2×2 联系列右下，手机联系列置顶；临时渲染文件验收后删除（public 零残留）。

## [0.9.32] - 2026-09-17

> 后台左侧导航按已确认「方案 B」重构（一级 Icon 直达项 + 精简真分组），删除品牌区英文副标题，修改密码移入顶栏用户区；全站后台文件/图片上传控件统一为 Design System 按钮风格。仅改后台视图与 admin.css，路由名 / URL / 控制器 / 权限 / active 判定 / 未读红点 / 前台模板 / GEO / SEO 零改动。

### Changed
- **侧栏信息架构重构**：原 8 分组 / 20 链接（4 个单条目分组标题纯占空间）改为 **5 个带线性图标的一级直达项**（仪表盘、客户留言[未读红点保留]、首页整体装修、顶部导航与页脚、事实库）+ **3 个真分组**（内容中心 4 项、搜索与 AI（SEO/GEO）6 项、站点设置 4 项）；分组头带同规格 14px 线性图标 + 发丝线分区，二级子项纯文字缩进对齐图标文字。
- **视觉规格**：侧栏宽维持 232px；品牌区 58px / logo 30px / 单行标题，**删除英文副标题「EXAMPLE CMS · GEO」**；一级项 36px / 14px / 500，分组头 26px / 12.5px 弱化色，二级子项 32px / 13.5px；图标统一 Feather/Lucide 线性风格 16px / stroke 1.7 / currentColor（新建 `partials/nav-icon.blade.php` 唯一入口，默认中性灰、hover 转深、选中转品牌红）；hover 通栏 #F2F3F5、选中品牌红 600 + 浅红通栏底（无圆角无左条，沿用 Zan 规格）。
- **高度断点**：视口高 ≥820px 标准密度（导航约 530px，1080P / 768px 笔记本均可一屏完整展示）；≤820px 桌面端自动紧凑密度（一级 33 / 子项 29 / 分组头 23 / 品牌 50）；≤900px 移动抽屉恢复触控行高（一级 44 / 子项 40 / 分组头 30），仍不足时才走悬浮细滚动条。
- **修改密码**从侧栏「账户」分组移入顶栏右上角用户区（与查看官网、退出登录同区），删除单条目账户分组。
- 「抓取产出（sitemap/llms）」更名为「抓取产出 / Sitemap」。
- **全站上传控件统一**：所有原生 file 按钮（媒体库主上传、内容封面、设置项图片、首页装修幻灯/横幅/条目图片，含 JS 动态新增行）统一为线框次级按钮（32px 高、8px 圆角、14px 图片线性图标、hover 浅底深边、focus 品牌红描边光环）；媒体库主上传位为虚线拖放区风格；每个上传位旁的推荐尺寸/格式说明保持可见未动。

### Verified
- 全量 **181 passed / 828 assertions**；后台冒烟 **24/24**。
- 无头 Chrome 登录态渲染：桌面 1280×900 与 1280×720（紧凑断点）导航均一屏完整、无滚动；390px 移动抽屉触控行高与遮罩正常；媒体库 / 新建内容封面 / 联系方式二维码 / 首页装修幻灯四处上传位按钮样式统一、尺寸说明保留；临时渲染文件与脚本验收后删除（public 零残留）。

## [0.9.31] - 2026-09-17

> Tooltip 治理第二批（验收补漏）：用户截图指出每页标题下的灰色定位句、首页装修卡片的「前台对应位置」长句仍以整段文字占据页面。本批把这两类说明也统一收进 ⓘ/?/! 图标，全站后台页面不再有常驻说明段落；仅保留上传位旁的尺寸/格式约束与空状态文案。

### Changed
- **页头定位句全部收进标题旁 Tooltip**：布局层统一把 `page-desc` 渲染为 H1 标题旁的 help 型图标（底部弹出、300px），覆盖全部后台页（首页装修、内容管理、页面文案、导航与页脚、事实库、留言、设置、GEO 等 20+ 页）；标题区不再出现灰色长句。
- **首页装修卡片**：「前台对应位置：…」整段改为卡片标题旁 info 型 Tooltip；事实区块的事实库规则说明改 warning 型 Tooltip，保留「事实库管理 →」直达链接；数据区块说明同样收进标题 Tooltip，删除两处常驻 hp-note 段落；空状态文案精简为操作指引，回退规则并入幻灯说明 Tooltip。
- **导航与页脚页**：scope-bar 的「数据来源」长句收为 warning 型 Tooltip，仅保留三个跨页快捷链接。
- **GEO 抓取产出页**：校验器说明段落改为标题 Tooltip，两个外部校验工具改为小按钮行。
- **媒体库**：删除「各使用位推荐尺寸已标注在对应上传处」的元说明（上传约束一句保留）。
- 页面文案编辑页的页头提示改为完整说明，插槽→前台位置定位条保留（属当前编辑对象的核心上下文）。
- **窄屏 Tooltip 边缘钳制**：≤560px 时气泡限宽 260px，并由布局层小脚本按视口左右边缘计算偏移（--tx）、箭头始终对准图标，修复标题旁等非左缘图标在手机端气泡被裁切的问题；桌面端纯 CSS hover 不变。

### Preserved
- 上传位旁尺寸/格式约束（封面 1280×720、幻灯 1920×640 / 2048×900、横幅 2400×600、OG 1200×630、二维码 600²、≤8/10MB 等）、空状态文案、表格数据文本、必填标记与状态徽章保持可见。

### Verified
- 全量 **181 passed / 828 assertions**；后台冒烟 **24/24**。
- 无头 Chrome 登录态渲染验收：首页装修 / 内容管理 / 页面文案 / 导航与页脚四页桌面 1280 下常驻说明段落全部消失、信息在 Tooltip 内完整可读；390px 窄屏气泡不溢出、箭头对准图标；临时渲染文件与脚本验收后删除（public 零残留）。

## [0.9.30] - 2026-09-17

> 后台说明信息全局治理与 Tooltip 交互统一：全站后台盘点辅助说明文字，把非核心的字段解释 / 规则 / 操作提示收敛为统一的「ⓘ / ? / !」小图标 + hover Tooltip，页面只保留核心信息与每页一句定位；业务规则一条不删，只搬位置并精简废话。仅改后台视图与 `admin.css`，路由 / 控制器 / 表单字段 / 前台模板零改动。

### Added
- 统一匿名组件 `<x-admin-tip>`（`resources/views/components/admin-tip.blade.php`）：`type=info/help/warning` 三枚 13px 线性图标，`place=top/bottom/left/right` 四向，`:width` 控制气泡宽度；纯 CSS hover 与键盘 focus 触发（移动端点按可看），无 JS。
- Tooltip 视觉规范写入 `admin.css`：深色气泡 `#1D2129`、白字 12.5px / 1.65、8px 圆角、箭头、160ms ease、z-index 1200（不被 Modal / 表格遮挡）、focus-visible 品牌色焦点环；窄屏（≤560px）气泡统一左对齐并限制在 `100vw - 32px` 内，杜绝边缘裁切；`.label-with-tip` 标签与图标并排辅助类。

### Changed
- **全站后台 17 个视图完成说明迁移**：内容新建 / 编辑（slug、类型、摘要、正文、GEO 四层、证据、FAQ、事实句、SEO、责任人、复核日期、发布说明）、Markdown 编辑器工具栏、页面文案插槽、修改密码、站点设置七组、顶部导航与页脚、栏目分类 / 表单、知识分组、仪表盘、媒体库、首页整体装修（首屏 A/B/C、幻灯、中部横幅、事实区块、数据区块、来源栏目、手动指定）、抓取产出与对接、事实库表单、301 跳转等；长规则用 280–300px 宽气泡承载，删除 / 锁定 / 门禁类用 warning 型。
- 精简 10 个页面过长的 page-desc 为一句定位；删除媒体库「统一尺寸说明长段落」。
- badge 草稿 / 归档暖色改为冷中性（随 v0.9.29 Token 体系统一）。

### Preserved（按既有铁律保留可见，未收进 Tooltip）
- 每个上传位旁的推荐尺寸标注（幻灯 1920×640 / 2048×900、中部横幅 2400×600、条目图、封面 1280×720、OG 1200×630、二维码 600² 等）仍就地可见；首页装修「前台对应位置」映射、每页 scope 提示条、必填标记、状态徽章等核心信息保持可见。

### Verified
- 全量 **181 passed / 828 assertions**；后台冒烟 **24/24**。
- 登录态抓取 10 个关键后台页：tip 组件全部正常渲染（blocks 10 / 内容新建 20 / 设置 6+5 / 导航 35 / 事实 4 / 跳转 3 / GEO 4），旧长段落已移除，尺寸标注仍在。
- 无头 Chrome 截图验收：桌面 1280 下三型图标、四向气泡、长文换行、箭头与默认收起态正常；390px 窄屏气泡不溢出视口；临时探针 / 渲染文件验收后已删除（public 零残留）。

## [0.9.29] - 2026-09-17

> 后台工作区冷中性化 + 悬浮细滚动条：把沿用前台暖色系的后台中性 Token 校准为 Zan/Arco 冷中性标准，并移除 Windows 经典粗滚动条。仅改 `admin.css`，视图/路由/前台零改动。

### Changed
- **工作区底色**：`#F6F5F3` 暖米灰 → `#F7F8FA` 冷中性（Zan/Arco 后台工作区标准）；浅填充 `#F2F3F5`、分隔线 `#E5E6EB/#C9CDD4`、正文/次要文字（#1D2129 / #404652 / #4E5969 / #737A86 / #969CA6）、阴影统一冷中性；表头、表格 hover、scope 提示条、A/B/C 模式圆标等同步去暖。品牌红/绿、状态色不动。
- **悬浮细滚动条**：全站后台统一为 8px 宽、6px 圆角滑块、透明轨道；默认隐藏，鼠标移入可滚动容器或滚动时淡入（rgba(29,33,41,.18)，悬停滑块 .32），Firefox 用 thin 样式兜底；侧栏与工作区同一套规则。
- GEO 代码预览块（sitemap/llms/robots/RSS）改用固定中性深色 `#1D2129`，不再依赖侧栏底色变量（侧栏转白后避免浅底浅字）。

### Verified
- 全量 **181 passed / 828 assertions**；后台冒烟 **24/24**。
- 浏览器实测：首页装修、内容列表表格、GEO 深色预览块配色正常；侧栏粗滚动条消失、悬停淡入细滑块；390px 移动抽屉（iframe 探针实测后删除）白底与选中态正常。

## [0.9.28] - 2026-09-17

> 后台侧边栏按 Generic Design Menu 规范重做：弃用黑色侧栏与折叠分组，改为浅色常驻导航；只动 `admin/layout.blade.php` 与 `admin.css`，路由/控制器/前台零改动。

### Changed
- **侧栏浅色化**：白底 + 右侧 1px 分隔线（原为深棕黑底），品牌区深色文字；移动抽屉同步白底并加投影。
- **分组不再折叠**：移除 `<details>/<summary>` 折叠交互与箭头，八组全部常驻展开，杜绝「点小标题才能看到功能」。
- **层级与规格对齐 Generic Design Menu（实测 example.com/design 计算样式后映射品牌色）**：分组标题 13px 常规弱化色（#9A9084）、不可点，仅作分区；菜单项 14px、行高 40px（项目 Token `--control-h-md`）、通栏底；hover 暖灰 #F5F2ED 通栏；选中 = 品牌红文字（#D70E18，600）+ 品牌浅红通栏底（#FDE8E9），无圆角卡片、无左侧红条等自造样式；过渡统一 160ms / ease-standard。
- 精简过长的菜单项文案（内容管理 / 页面文案 / 栏目与知识分组 / 事实库 / 抓取产出（sitemap/llms）/ GEOFlow 对接 / 同步日志），单行不换行、超出省略。

### Verified
- 全量 **181 passed / 828 assertions**；后台冒烟 **24/24**。
- 浏览器实测：桌面 1280 下选中态、hover 态、分组层级、滚动到底部各分组完整；390px 移动抽屉（iframe 探针实测后已删除）白底滑入、遮罩、选中态正常。

## [0.9.27] - 2026-09-17

> 后台管理系统信息架构（IA）重组与设计系统统一：把碎片化的功能页收口为「总览 / 首页装修 / 内容中心 / 导航与页脚 / 信任与事实 / 搜索与 AI / 站点设置 / 账户」八组运营动线，抽离唯一后台样式系统并清除全部内联样式与硬编码色值，补齐移动端抽屉导航。**零路由、零控制器、零前台模板变更**，GEO/SEO 不受影响。

### Added
- **新后台导航树**（仅侧边栏视图层重组，路由名/URL/控制器全部不动）：总览（仪表盘、客户留言带未读红点）、首页装修（首屏幻灯与全部首页区块一页聚合）、内容中心（内容管理 / 页面文案 / 内容结构[栏目分类·知识分组两 Tab] / 媒体库）、导航与页脚、信任与事实（事实库）、搜索与 AI（SEO 设置、GEO 设置、抓取产出与对接、301 跳转、GEOFlow 对接、同步日志）、站点设置（公司信息 / 联系方式 / 全站文案 / 外观主题）、账户（修改密码）。
- **栏目分类 + 知识分组合并为「内容结构」一页两 Tab**，不新增路由；新建知识分组默认归属「知识中心」栏目。
- 每页统一页头条（页面标题 + 说明 + 操作区，布局层 `@yield('page-desc')/@yield('page-actions')`）。
- **移动端后台抽屉导航**（≤900px）：顶栏汉堡按钮、遮罩、侧栏滑入，点击导航自动收起；桌面端交互完全不变。
- 新增 `scripts/admin_smoke.php` 登录态全路由冒烟脚本（24 个 admin GET 路由逐页实测，参数自动取首条模型/分组/预览类型），作为长期回归工具保留。
- 联系方式补齐真实数据：业务手机 13800000000、高德地图链接（联系页与页脚即时生效，二维码沿用内置图回退）。

### Changed
- 抽离 **`public/css/admin.css` 为后台唯一设计系统**：Design Token 与前台品牌色对齐（品牌红/CTA 绿/中性阶/圆角/间距/阴影），统一按钮、卡片、表格、表单、徽章、下拉、分页、抽屉等组件；25 个后台视图全部重写并逐一与控制器真实字段契约对齐。
- 清除后台视图全部内联 `style=""` 与硬编码 hex 色值（grep 零命中）；Markdown 编辑器私有样式收编进 admin.css。
- 登录页品牌化（品牌色背景 + 白色登录卡），窄屏宽度 `min(380px,100vw-32px)` 防溢出。
- 媒体库描述修正（不再声称可设置栏目图标，栏目图标走内置图标白名单）；内容列表去掉与筛选栏重复的「新建内容」按钮。

### Fixed
- 仪表盘误用不存在的统计口径与错误列名：改为与 `DashboardController` 真实契约一致的八张统计卡（`.stat .n/.l`），操作日志列改用真实的 `summary` 字段中文名。
- 留言筛选链接修正：`route('admin.inquiries.index','new')` 会生成错误的 `?0=new`，统一改为 `['status'=>'new']`（侧栏红点与仪表盘均修）。
- 栏目管理曾引用不存在的 `App\Support\Icon` 类导致 500：统一走 `site._icon` 视图组件；删除视图层臆造的自定义图标上传/description 字段（后端本就无此契约，避免假控件）。

### Verified
- 全量 **181 passed / 828 assertions** 无回归（两轮，视图改动后复跑）；`scripts/admin_smoke.php` 实测 **24/24 admin GET 路由全部 200**（含 7 个设置分组、4 种 GEO 预览、4 个内容 Tab、4 种留言筛选）。
- 浏览器桌面端逐页截图回归通过；真实提交链路实测：首屏区块保存（C 模式 + 3 张幻灯数据零丢失）、场景条目区块保存（6 条目零丢失）、站点设置保存、事实库新增→删除、301 新增→前台实测 301 跳转→删除、栏目显隐开关翻转→前台导航即时跟随→恢复原状。
- 移动端 390px 经同源 iframe 探针实测：汉堡抽屉、遮罩、单栏布局、表格横滚正常；验证探针已删除。登录页桌面/移动截图通过。

## [0.9.26] - 2026-09-17

> 最后一批「后台无界面」边角文案收口：全站底部 CTA（含工厂页变体）、全局咨询表单（字段标题/提示/校验话术/客户类型选项/提交与隐私话术）、404 页面、页脚品牌 slogan 全部接入后台「系统 → 站点设置 → 文案话术」，留空回退默认、零配置零差异；客户类型选项改由同一取数层驱动前台下拉与后端校验，运营增删选项后提交不再被拒。

### Added
- **站点设置新增「文案话术」分组**（28 项）：底部 CTA 标题/说明/主副按钮 + 工厂页变体主副按钮（6 项）；咨询表单姓名/电话/客户类型/需求各字段标题、占位、错误提示与电话格式提示、客户类型选项（多行）、提交按钮/提交中/隐私说明/成功提示（17 项）；404 标题/说明/主副按钮（4 项）；页脚品牌标语（1 项）。所有字段留空即回退默认口径。
- 新增 `App\Support\Copy` 统一文案取数层：设置值非空覆盖、否则回退 `config/copy.php` 与现网默认；请求级 memo 并在 `AppServiceProvider` 每请求复位；客户类型选项按行解析、空行过滤，留空回退默认八类。
- 测试新增 `CopySettingsTest` 9 例：默认值与 config 一致、底部 CTA/工厂变体/页脚 slogan 覆盖渲染、表单标签与自定义选项渲染、**自定义客户类型可真实 POST 通过后端校验**、选项留空回退八类、404 覆盖渲染、后台分组页鉴权与持久化、保存其它分组不清空文案字段。

### Changed
- `site/_bottom_cta.blade.php`、`site/_lead_form.blade.php`（含前端 JS 校验提示与提交中文案）、`errors/404.blade.php`（原先文案硬编码、未消费 config）、`layouts/site.blade.php` 页脚 slogan 统一改消费 `App\Support\Copy`。
- `InquiryController`：客户类型白名单从 `Copy::form()` 选项合并历史四类（`Rule::in`，避免选项含逗号时 `in:` 字符串误判）；姓名/电话/客户类型校验话术与蜜罐、真实成功提示均取自 Copy，前后台话术单一事实源。
- 设置分组路由白名单与 `SettingController::GROUPS` 同步新增 `copy`（不补路由会 404/405，已由测试拦截）。

### Verified
- 全量 **181 passed / 828 assertions** 无回归；浏览器端到端实测：后台改底部 CTA 标题/客户类型选项/页脚 slogan → 产品页、首页页脚、联系页下拉即时跟随 → 用新增「测试渠道-E2E」真实提交留言 → 后台留言列表正确显示该类型与来源归因 → 测试留言删除、设置恢复默认后前台全部还原；404 页面文案取数正常。测试数据已清理。

## [0.9.25] - 2026-09-17

> 导航与页脚彻底「去写死」：固定主导航的链接、新窗、排序、显隐、名称全部可在后台覆盖，固定项与自定义项支持统一排序混排；页脚四列（含列标题、列内链接、挂列自定义链接）整体接入同一套覆盖层并由后台运营，联系列取值改由站点设置驱动。为后期按 GEO/SEO 多维需求调整导航结构留出完整可变性，前台零配置时与现状完全一致。

### Added
- **固定主导航全量可编辑**（后台「结构 → 导航菜单 → 固定主导航栏目」）：每个固定一级/二级项支持改名、显隐、排序、**链接地址覆盖**（内链 `/path/`、外链、`tel:`、`mailto:`、锚点均可）与**新窗口打开**；固定一级改为外链后自动退出当前态高亮匹配（patterns 置空）。留空即回退默认地址，删除覆盖行即恢复默认。
- **固定项与自定义项统一混排**：固定一级默认 sort 10/20/30/40/50，自定义项显式给 sort 即可插入任意位置（未给仍默认排在固定项之后）；二级同理，固定子项与挂到固定栏目的自定义子项按 sort 统一排序。
- **页脚可运营化**：新增「固定页脚栏目」编辑区——四列（联系我们/产品中心/应用场景/关于我们）列标题可改、整列可隐藏；列内每个链接可改名、改链接、新窗、隐藏；每个页脚列支持「+ 链接」挂自定义项；无上级的页脚自定义根链接自动进入「快捷入口」列。
- **联系列数据驱动**：全国热线、业务手机、厂区地址、微信二维码统一取站点设置（v0.9.23 字段），后台改设置全站页脚跟随；业务手机为空时该行自动隐藏；二维码走设置上传，缺省回退内置图。
- 自定义菜单「上级菜单」选择器新增页脚固定列分组（optgroup：主导航固定一级 / 页脚固定列 / 自定义一级），选页脚列时位置自动锁定为页脚并写入 `parent_key=ft-col-*`。
- 测试新增 10 例：固定一级改外链新窗、固定子项改内链、知识分组动态子项拒绝链接覆盖、显式 sort 一/二级混排、页脚列标题与链接覆盖、页脚隐藏项/隐藏整列、页脚挂列链接与快捷入口归属、联系列锁定项拒绝链接覆盖、页脚未知 key 404、页脚列覆盖行不泄漏进快捷入口。

### Changed
- `AppServiceProvider` 新增 `footerBlueprint()/footerMenu()`，主导航蓝图与页脚蓝图分别按 `position=main/footer` 读取覆盖行，缓存键 `footer.blueprint/footer.menu` 纳入统一 `forgetNavCache()`；站点设置保存时同步失效导航缓存（联系列依赖设置）。
- `MenuController` 重写：覆盖保存支持 `position/url/target` 与蓝图 key 白名单（列节点、锁定联系项、知识分组动态项拒绝写链接）；自定义项校验支持 `key:ft-col-*` 挂列；删除覆盖行（恢复默认）与删除自定义项走同一控制器收口。
- 前台 `layouts/site.blade.php` 页脚不再直接读 `config/copy.php`，改为消费 `$footerMenu`；列样式按稳定 key（ft-col-*）映射而非中文标题，避免改名后样式错位；`/cases` 死链在蓝图层 forced_hidden（不建案例中心，后台也不渲染该行）。
- 后台菜单页：固定栏目每个表单新增「链接地址」输入框（placeholder 显示默认地址）与「新窗口打开」勾选；固定一级提供「+ 二级」、页脚列提供「+ 链接」快捷按钮（纯 JS 定位并预选上级）；补充 SEO 提示（改导航指向不改页面地址，旧地址下线请配 301；sitemap/Schema/llms.txt 自动跟随数据）。

### Fixed
- **页脚「快捷入口」混入固定列覆盖行**：端到端验证中发现给页脚列改名后，列覆盖行（parent 为空的 ft-col-* 行）被 `footerExtra()` 误当独立根链接渲染进快捷入口；已在查询层排除所有 `ft-` 前缀 key 的覆盖行，并补回归测试。

### Verified
- 全量 **172 passed / 785 assertions** 无回归；浏览器端到端实测：固定一级「关于我们」改外链新窗 → 前台导航即时指向外链 → 一键恢复默认；页脚关于列改名、挂「招贤纳士」自定义链接 → 前台页脚正确渲染且不重复进快捷入口 → 删除后全部恢复；390px 移动抽屉与移动页脚（热线 + 免费获取样品两按钮常驻、二维码/手机/地址）核对正常；测试数据已清理，菜单表回到零覆盖状态。

## [0.9.24] - 2026-09-17

> 自定义导航支持二级菜单，并按「导航 → 栏目 → 分组 → 内容」的运营动线重排后台侧边栏。此前自定义菜单只能追加一级，外链/活动页无法收纳进下拉；本次数据层、后台、前台、移动端全链路打通。

### Added
- **自定义二级导航**：`menus` 表新增 `parent_key`（挂固定一级栏目）与既有 `parent_id`（挂自定义一级菜单）双归属，仅支持两级；页脚维持一级。
  - 二级可挂到任意**固定一级栏目**（产品中心/应用场景/工厂与资质/知识中心/关于我们）的下拉尾部，或挂到**自定义一级菜单**下；自定义一级允许无链接纯父级（URL 留空，渲染为 `#` 且桌面/移动均拦截跳转）。
  - 二级菜单支持关联栏目 / 自定义 URL / 新窗口打开（`target=_blank rel=noopener`，桌面下拉与移动抽屉一致）、排序、启停。
- **后台「导航菜单」改造**：新增表单与所有编辑表单统一提供「上级菜单」选择（无 / 固定一级分组 / 自定义一级分组，optgroup 区分），选择上级后位置自动锁定为主导航；自定义列表按层级缩进展示，挂固定栏目的子项标注「挂于：xx」；删除含子项的一级菜单时后端拦截并提示先转移/删除子项。
- 迁移 `2026_09_17_000003_add_parent_key_to_menus_table.php`（幂等，建表迁移已并入该列与索引）。
- 测试新增 5 例（自定义父+子渲染、固定栏目追加子项、非法父级回退一级、有子项禁删、停用子项隐藏）。

### Changed
- **后台侧边栏分组重排**：新增「结构」组（导航菜单 → 栏目结构 → 内容分组），原「内容」组只留内容管理、页面文案；顺序符合「先搭结构、再填内容」的运营动线。
- `AppServiceProvider::mainMenu()` 一次性加载自定义菜单并在两处合并：固定栏目下拉尾部追加 `parent_key` 子项，自定义一级挂载 `parent_id` 子项；子项节点带 `external` 标记。
- 前台导航子项锚点统一输出外链属性；纯父级一级加 `data-no-jump`，移动抽屉点击不跳转、不关闭抽屉。

### Verified
- 全量 **162 passed / 746 assertions** 无回归；浏览器实测：后台创建「招商合作（纯父级）→ 加盟政策（外链新窗）」「关于我们 → 招贤纳士」，前台桌面下拉与 390px 移动抽屉均正确渲染，删除测试数据后前台恢复；后台菜单页 HTTP 200、空状态正常。

## [0.9.23] - 2026-09-17

> 补齐联系与分享资产：业务手机、地图入口、品牌默认 OG 分享图、微信二维码全部接入前台并在后台「站点设置 → 联系方式」可管理；顺带修复一处系统性数据安全隐患——旧标签页保存设置会把新增字段误清空。

### Added
- **业务手机 `contact_mobile`**：联系页事实列表新增「业务手机 / 微信同号」（`tel:` 直拨，空值自动隐藏），页脚「联系我们」列在 400 热线后自动插入业务手机行；当前值 13800000000，后台可改可清空。
- **地图入口 `contact_map_url`**：联系页地址下新增「查看地图 →」（新窗、`rel=noopener`）；未核定经纬度前不嵌入虚构坐标，使用高德地点搜索链接，后台填高德/百度分享链接即可生效，留空则整块隐藏。
- **微信二维码 `contact_wechat_qr`（image 型）**：内置默认二维码 `public/img/wechat-qr.png`（用户提供的真实二维码原样复制，不重编码以防影响扫码）；联系页新增二维码卡「微信扫码 · 索要样品」，页脚联系列渲染 104px 白底二维码；后台可直接上传替换（就地标注建议 600×600 以上方形 PNG），留空回退内置图。
- **品牌默认 OG 分享图 `public/img/og-default.png`（1200×630）**：GD 合成的品牌分享图（米白底 + logo + 公司名 + 业务定位 + 热线/域名）；布局头 `og:image` 本就有「后台 `seo_og_image` → 默认图」兜底，未配置时自动使用该图，后台仍可逐站覆盖。
- 联系页同时补齐业务邮箱（空则隐藏）、工作时间的前台渲染，均取自站点设置、不再硬编码。

### Fixed
- **设置保存误清空新增字段（系统性隐患）**：`SettingController::update` 原先对分组内每个键执行 `$request->input($key,'')`，当用户用字段新增前打开的旧标签页保存时，表单里不存在的新键会被静默写成空串（本次联调中即由此导致手机号/地图链接被清空）。改为：字段未出现在本次请求中时保留原值（文本框显式提交空串仍可正常清空，bool 复选框语义不变）。已用两组控制器级用例验证：旧表单缺字段时值保留、显式清空时正常置空。

### Changed
- `SettingSeeder` 联系方式分组新增 `contact_mobile`（sort 15）、`contact_wechat_qr`（image，sort 55），`contact_map_url` 提示更新为「高德/百度地图的地点分享链接」；seeder 只增不改已有值。
- 联系页/页脚新增样式（`.contact-wechat`、`.map-link`、`.ft-qr`），≤600px 二维码卡纵向居中，沿用全站 Design Token。

### Verified
- 全量 **157 passed / 724 assertions** 无回归；无头 Chrome 桌面 1280 与真实 390 宽（iframe 实测几何）双端核对：联系页手机/邮箱/工作时间/地图链接/二维码卡、页脚手机行与二维码均正确，390 宽顶部导航 logo + 电话 + 「免费获取样品」+ 汉堡按钮完整无裁切。
- 后台「联系方式」标签实测渲染全部新字段与就地尺寸提示；联系页/首页 HTTP 内容断言通过（手机号、二维码、地图链接、og 默认图）。

## [0.9.22] - 2026-09-17

> 落地「方案 1：结构化页面可运营叙事段落接线 CMS」。关于/工厂/合作/联系/产品/场景六类规范页的 **hero 导语**（及企业简介正文）此前锁死在代码事实源，后台无法运营；本次在不破坏「硬数据单一事实源、GEO 证据一致、防虚构」底线的前提下，新增后台「内容 → 页面文案」，把 25 处可运营叙事片段接线到 CMS，保存即发布、清空即恢复默认，上线前后台零操作时前台与现状完全一致。同时清理双轨残留的 10 篇薄占位内容与 12 个死影子栏目，并把内容编辑器升级为支持 Markdown 工具栏 / GFM 表格 / 正文插图 / 预览 / Word 粘贴转换的共享编辑器。

### Added
- **叙事插槽（Narrative Slot）取数层 `App\Support\Narrative`**：以 `contents.slot` 片段存储覆盖文案（复用 Content 表，天然兼容 Markdown 编辑器、正文插图与后期 GEOFlow 推 Content）；`lead(key,default)` 取纯文本导语、`html(key,default)` 取 Markdown 正文（自动把 H1 降级 H2，保证每页唯一 H1）；未覆盖一律回退 Facts / `config/pages.php` 默认文案，不做 DB 回填。内置 25 个插槽注册表（关于 3、工厂 1、合作 1、联系 1、产品总览 1 + 5 系列 + 6 核心产品、场景总览 1 + 6 场景），每项标注前台页面/锚点位置、可点 URL、是否支持正文、正文插图推荐尺寸。
- **Content 模型 `not_slot` 全局作用域**：slot 片段对全站普通内容查询统一不可见——搜索、catch-all、后台内容列表、仪表盘计数、sitemap、llms.txt、RSS feed、首页选稿默认全部排除；slot 行 `type=page`、category/group/cover 为 NULL、slug 为 `_slot_<key>`、无独立 URL（直输保留 slug 404）。仅 Narrative 取数层 `withoutGlobalScope('not_slot')` 旁路读取。
- **后台「页面文案」管理（`Admin\NarrativeController` + 4 条路由 + 侧栏入口 + 两视图）**：按分组列出全部可运营片段（页面位置 / 对应前台区域 / 默认或已自定义状态 / 编辑）；编辑页可改导语（必填、≤500 字）与（企业简介）正文（Markdown、≤40000 字），可查看系统默认文案；保存即 upsert 已发布片段，清空两字段或点「恢复默认文案」即物理删除覆盖行回退默认。
- **共享 Markdown 编辑器局部 `admin/partials/md-editor.blade.php`**：H2/H3、加粗/斜体、引用、有序/无序列表、GFM 表格、链接、**正文插图直传**、编辑/预览切换、粘贴 Word 自动转 Markdown；容器化、可多实例（内容表单与页面文案共用）。内容管理表单已切换到该局部。
- **迁移 `2026_09_17_000002_add_slot_to_contents.php`**：为存量库补 `contents.slot`（120，nullable，unique）；软删 10 篇不驱动规范页的薄占位（公司简介/工厂产能/发展历程/资质认证/联系我们 + 5 个产品系列），停用 12 个被静态路由遮蔽的死影子栏目（about/company/factory/certification/history/products 及 5 产品系列/contact），并失效导航缓存。
- **测试 `tests/Feature/NarrativeSlotTest.php` 10 例**：默认回退、导语覆盖前台生效、Markdown H1→H2、slot 无独立 URL、不进搜索/sitemap/feed、后台保存与恢复默认、空导语校验与两空恢复、产品 tagline 在 hero/SEO/Schema 多处一致、场景 desc 覆盖、注册表清单。

### Changed
- **slot 列并入建表迁移**：`2026_09_14_000003_create_contents_table.php` 建表即含 `slot` 列。原因是早期迁移 `normalize_terms` 经模型 `Content::all()` 触发全局作用域，若列在后续迁移才添加，`migrate:fresh` 跑到早期迁移会因列不存在而全量失败；000017 保留 `hasColumn` 守卫为存量库补列。
- **六类前台控制器/视图接线叙事覆盖**：About（profile/history/culture 导语，profile 正文）、Factory（页头导语，默认含投产时间硬数据）、Cooperation、Contact、Product（总览导语、5 系列 desc、6 核心产品 tagline）、Solution（场景总览导语、6 场景 desc）。其中产品 tagline / 系列 desc / 场景 desc 的覆盖在 **页头 hero、SEO meta description、Product/Service JSON-LD 三处共用同一值**，保证 GEO 证据一致；配比、参数、资质、时间线、数字、FAQ、产品组合仍锁定 Facts，不在 CMS 提供可造假入口。
- **`config/pages.php`** 新增 `narrative` 段（合作/联系/产品总览/场景总览四句默认导语）。
- **请求级缓存复位**：`AppServiceProvider::boot()` 每请求增加 `Narrative::flush()`，与 Fact/Setting/Group/导航内存复位并列，避免 PHP 内置服务器进程复用导致后台改完读到旧快照。
- **整页静态化缓存联动**：slot 保存走模型事件自动失效 PageCache；恢复默认/清空走查询构造器批量 `forceDelete`（不触发模型事件），控制器内显式 `Narrative::flush()` + `PageCache::flush()`，杜绝恢复默认后前台仍命中旧静态壳。
- **seeder 精简（防 `migrate:fresh --seed` 复活残留）**：`ContentSeeder` 只保留 3 篇真实知识文章，删除 5 篇公司类占位与 5 个产品系列占位及其 `productRow()`；`StructureSeeder` 只保留 knowledge / news 两棵活栏目树，删除 about/products/contact 旧死树，首页 products 区块解绑死栏目来源。
- 旧测试断言随信息架构下线更新：`ExampleTest` 改断言规范页 `/about/profile/`、`/products/`、`/knowledge`；`InquiryTest` 4 处影子地址 `/contact/contact-us` 改为规范 `/contact`。

### Verified
- 全量 **157 passed / 724 assertions**（基线 147 + 新增 10），无回归；临时调试文件与代码根 `tmp_inspect.php` 已删除。
- 浏览器端到端实测：后台「页面文案」改合作页导语 → 保存提示成功、列表「已自定义 1 处」→ 前台 `/cooperation/` hero 导语实时变为自定义文案（每页仍唯一 H1）；「恢复默认文案」后（含静态化缓存失效）前台还原为系统默认；内容编辑页 Markdown 工具栏 12 个按钮齐全，预览接口正确渲染标题/加粗/**GFM 表格**，正文插图与预览端点指向正确（`/admin/media/inline`、`/admin/contents/md-preview`，CSRF meta 已在后台布局）。
- 隔离临时库 `migrate:fresh` 成功、slot 列存在且列位正确；开发库迁移已执行，活内容 3 篇、启用栏目仅 knowledge/news。
- `view:clear` 已执行、整页缓存已清空。

## [0.9.21] - 2026-09-17

> 以 v0.9.20「导航后台改不了前台」为戒，对全站做「后台能改什么 ↔ 前台是否真读这个值」的系统性一致性审计，逐模块核对内容/分组/栏目/菜单/首页装修/媒体库/事实库/留言/301/站点设置/GEO。修复 1 处死功能、1 处死控件、2 处错误/自跳链接、1 处缓存键错误；其余模块核对一致。不推倒架构、不引入新 bug。

### Added
- **301/302 跳转执行层（修复「后台 301 规则前台从不执行」死功能，P1）**：新增 `App\Http\Middleware\HandleRedirects`，把后台「治理 → 301 跳转」维护的规则真正接入前台请求——永久缓存启用规则（键 `redirects.active`，后台增删改即时失效），命中返回 301/302 并自增命中数（用查询构造器 `increment`，不触发模型事件、不会误清整页缓存）；兼容带/不带尾斜杠、内/外链、旧站 `?id=` 查询串透传，防自跳死循环，放过后台/接口/XHR/JSON/带扩展名静态文件，仅处理安全的 GET/HEAD。注册于 web 中间件组 `SecurityHeaders → HandleRedirects → CaptureAttribution → CanonicalizeSlash → CachePage`（旧链先跳转，不做尾斜杠规范化、不入整页缓存）。`AppServiceProvider` 为 RedirectRule 的 saved/deleted 增加 `HandleRedirects::flushRules()`。新增 `tests/Feature/RedirectMiddlewareTest.php` 8 例。

### Changed
- **首页「产品体系」区块去除死控件（P2）**：该区块前台固定渲染结构化产品数据（五大体系/产品数/链接，产品中心单一事实源），但后台此前是「来源栏目 + 条数 + 手动选稿」的 source 型，这些控件在前台完全不生效。改为 `simple` 型（仅区块标题/副标题/排序/显隐），并在区块说明中明确「产品线来自核定结构化数据，不在此逐条选稿」；迁移 `2026_09_17_000001_unlink_products_block_source.php` 清空其历史残留的 `category_id` 与选稿配置。
- **首页「主体事实」区块错误链接（P2）**：「查看全部企业事实」原指向 232 字薄占位 DB 内容 `/about/company/company-profile`（与规范的富事实页 `/about/profile/` 重复），改为指向 `/about/profile/`，文案改为「查看企业概况」；该占位内容在前台已无任何入口、不进 sitemap。
- **首页「新闻动态」区块自跳（P3）**：「查看全部动态」原为 `/news/`，会被尾斜杠规范化 301 到 `/news`，改为规范无斜杠地址，消除一次多余重定向。
- **栏目缓存失效键错误（P3，潜在）**：`Admin/CategoryController::forgetNav()` 原 `Cache::forget('nav_tree')` 与真实缓存键 `nav.tree` 不符（等于没清），且未清主菜单/蓝图/页脚缓存；统一改调 `AppServiceProvider::forgetNavCache()`。

### Notes / 架构边界（需运营知悉，未擅改）
- 结构化实体页（产品/场景/工厂/合作/关于/联系）刻意由 `config/facts.php`、`config/pages.php` 经 `App\Support\Facts` 单一事实源驱动，不读数据库 CMS，目的是 GEO 证据一致与防虚构；首页「参数级交付 / 工厂数据条 / 主体事实」等数字区块同理（只给排序/显隐，不给可造假的数字输入）。
- 数据库中仍存在与富页面平行的短占位文章（公司简介/工厂与产能/发展历程/资质认证/联系我们、5 篇产品系列，54–232 字），可在后台「内容管理」编辑但不驱动规范页面；修复后它们在前台无入口、不进 sitemap，仅直接输 URL 可达。是否把这些可编辑叙事段落接线到规范页、或下线占位文，属独立决策，本轮不擅自迁移以免危及 GEO 证据。

### Verified
- 全量 **147 passed / 676 assertions**（基线 139 + 新增 8），无回归。
- 浏览器端到端实测：后台新增 `/old-page → /products/` 301，前台 `/old-page` 与 `/old-page/` 均真实 301 到 `/products/`、正常页 `/products/` 不受影响，删除规则后 `/old-page` 恢复 404；后台首页装修中「产品体系」卡片已无来源栏目/条数/手动选稿控件，仅标题/副标题/排序/显隐并附说明。
- 首页实测：事实区块链接为 `/about/profile/`（文案「查看企业概况」）、无 `company-profile` 残留；新闻 0 篇时「新闻动态」整块收起、无自跳链接；产品体系五大卡片正常。
- 关键路由冒烟全 200（首页、产品列表/详情、场景列表/详情、工厂、合作、知识频道/文章、关于、联系、新闻栏目、搜索、sitemap/llms/robots/feed、后台登录）；`view:cache` 已重建、整页缓存已清空。

## [0.9.20] - 2026-09-16

> 后台「结构 → 导航菜单」此前只能新增/管理自定义外链，固定栏目（产品中心/应用场景/工厂与资质/知识中心/关于我们及其子项）不在后台显示、也无法改名或显隐。本次以「覆盖层（override）」方式把固定栏目纳入后台可视化管理，不推倒既有信息架构，前后台共用同一份导航蓝图。
### Added
- **menus 表新增 `key` 列**（迁移 `2026_09_16_000001_add_key_to_menus_table.php`，nullable + unique）：`key` 非空表示对某一固定栏目的覆盖（改名/隐藏/排序，可一键恢复默认，链接由信息架构锁定不可改）；`key` 为空即原有的自定义追加项，行为不变。
- **AppServiceProvider 导航蓝图**：新增 `mainMenuBlueprint()`（前后台共用，缓存键 `main.menu.blueprint`）、`keyForHref()`（由 href 推导稳定 key，锚点子项如 `factory-workshops`）、`navPattern()`；`mainMenu()` 改为消费蓝图的可见节点，再追加 `key` 为空的自定义项。知识中心子项仍由「内容分组」`Group::knowledgeChannels()` 动态驱动。
- **MenuController 覆盖接口**：`saveOverride()`（白名单校验 key 必须在蓝图内，label 留空=默认名、sort 0=默认、is_active 勾选才显示）与 `resetOverride()`（删除覆盖行恢复默认），均写操作日志；新增路由 `admin.menus.override`（POST）与 `admin.menus.override.reset`（DELETE `menus/override/{key}`）。
- **后台菜单页重写**：顶部「固定导航栏目」整宽表，顶级/子项缩进展示，每行可展开内联编辑（显示文字、排序、显示勾选、保存；已自定义时出现「恢复默认」），含状态徽标与排序值；下方保留「新增自定义菜单」与「自定义菜单（追加）」管理。
### Fixed
- 覆盖保存时复选框未勾选（字段缺失）被错误默认为「显示」，导致隐藏不生效：`saveOverride`/`store` 统一改为 `$request->boolean('is_active')`（勾选=显示/启用，未勾选=隐藏/停用）。
### Verified
- 浏览器端到端实测：顶级改名（产品中心→产品体系）、隐藏子项（Sample Spice）、顶级重排（关于我们置顶）均即时联动前台桌面/移动导航（共用 `mainMenu`），三项覆盖随后全部「恢复默认」，前台还原、覆盖记录归零。
- 新增功能测试 `tests/Feature/MenuOverrideTest.php` 8 例（蓝图列出、默认结构、改名、隐藏、重排、恢复、非法 key 404、自定义项追加且排在固定栏目之后）；全量 **139 passed / 661 assertions**（基线 131 + 新增 8），无回归。`view:cache` 已重建、整页缓存已清空。

## [0.9.19] - 2026-09-16

> 系统修复移动端汉堡抽屉导航的两类问题：① 菜单项触摸/悬停/聚焦/按下时文字发白、浅底上对比不足；② 抽屉打开后背景页面仍可滚动而导航不动，导致菜单与页面错位、菜单项叠到深色首屏上「看不见」。按全站统一标准处理。

### Fixed
- **移动导航抽屉交互态白字（P2）**：`layouts/site.blade.php` 移动断点（≤768px）下，抽屉子项此前仅定义默认色（`--ink-muted`），触摸按下/聚焦态无显式颜色，叠加桌面下拉规则在移动视口的部分命中，出现「浅灰底 + 文字发白看不清」。现以高优先级选择器 `.hd-in nav .nav(…)` 显式锁定一级项与 `.nav-panel` 子项的 default / visited / hover / focus / active 各色：默认一级深墨、子项次级灰，交互态统一为「浅灰底 `--surface-2` + 品牌红字 `--brand`」，与桌面端下拉 hover 完全同源；`focus-visible` 给品牌绿描边兼顾键盘可达性，并加 `-webkit-tap-highlight-color:transparent` 去除移动端原生点击高亮闪烁。任何状态都不可能再出现白字。
- **抽屉打开后背景滚动错位 + 抽屉内部滚动局部白块（P1，根因修复）**：原抽屉是吸顶 header（`position:sticky` + `backdrop-filter:blur`）下的绝对定位面板，存在两个连锁问题——① 打开后 body 仍可滚动，面板与背景错位、菜单项叠到深色首屏上「看不见」；② 抽屉内部滚动时，中间整组菜单项（如「应用场景」6 子项）在部分移动 WebView 中滚动经过某区域后整块不重绘、变成空白（`overflow:auto` 滚动容器嵌套在 `backdrop-filter` 合成层祖先内的典型滚动不重绘 bug，桌面 headless 无法复现，仅真机/移动 WebView 出现）。最终修复（移动断点 ≤768px）：① **移动端 header 改为不透明白底并移除 `backdrop-filter`**（桌面端保留毛玻璃），消除滤镜形成的合成层与 fixed 包含块；② 抽屉由「sticky 内 absolute」改为**相对视口 `position:fixed;top:56px;left:0;right:0;bottom:0;overflow-y:auto`**，成为独立、简单的滚动容器，菜单超高时在抽屉内部滚动（含 iOS 惯性、`overscroll-behavior:contain` 边界收敛），并加 `transform:translateZ(0)` 独立合成层进一步杜绝滚动不重绘；③ 打开时 JS 拦截抽屉**外部**的 `touchmove`/`wheel` 锁定背景，抽屉内部放行；不改 body 的 overflow/position（实测 overflow:hidden 与 body position:fixed 都会破坏 sticky header，已弃用）；④ 跨断点回桌面或点抽屉内链接（含页内锚点）自动关闭并解锁。打开后「导航固定、背景不滑、菜单在抽屉内独立滚动且不再白块、关闭还原位置」。

### Verified
- 独立 headless Chrome 移动视口渲染：顶部打开、抽屉内部滚动到中段/底部，全部菜单项（产品中心 5、应用场景 6、工厂与资质 3、知识中心 3、关于我们 4，共 26 个链接）均完整绘制、文字清晰、白底遮背景、无白字；程序化检测抽屉 `position:fixed;top:56px;bottom:0;overflow-y:auto`、内部 `scrollTop` 可设、滚动后 6 个应用场景项仍有正常布局尺寸（491×39）、header `getBoundingClientRect().top=0`；移动 header 计算样式 `backdrop-filter:none`、背景纯白。
- 小宽度顶栏适配：360/375/390 测量 `documentElement.scrollWidth==innerWidth`（无横向溢出），电话/CTA/汉堡均在边界内。
- 全站无 `color:#fff !important`（唯一 `!important` 颜色为品牌红）；无调试参数/临时代码残留；桌面端（>768px）毛玻璃与下拉菜单不受影响。
- 全量测试 **131 passed / 636 assertions**，无回归；`view:cache` 已重建、整页缓存已清空。
- 说明：内部滚动白块仅在移动 WebView 出现、桌面 headless 复现不到，已从结构上移除其触发条件（滤镜合成层 + 嵌套滚动）；需在真机强刷确认。

## [0.9.18] - 2026-09-16

> 主线：落地 v0.9.17 审计报告第九节遗留的 P2/P3 非阻断项——CSP 内容安全策略、WebP 渐进增强、知识正文渲染缓存、产品首屏参数卡留白与移动端锚点越界加固。均为系统级统一处理，含缓存联动与回归测试。

### Added
- **内容安全策略 CSP（nonce 模式，P3）**：`SecurityHeaders` 中间件每请求生成 16 字节随机 nonce，前台 `script-src 'nonce-{n}'`（5 处前台内联脚本全部带 nonce，JSON-LD 数据块无需 nonce），锁定 `default-src 'self' / object-src 'none' / base-uri 'self' / form-action 'self' / frame-ancestors 'self'`；图片允许同源与 http(s)/data/blob（CMS 可外链图）。后台历史模板含 17 处内联事件，`script-src` 保留 `'self' 'unsafe-inline'` 但同样锁死 object/base/frame/form-action，避免为过 CSP 重构后台引入新风险。
  - **与整页缓存联动**：nonce 每请求变化，而 PageCache 会钉死 HTML。仿 CSRF/归因占位机制，缓存外壳把 `nonce="..."` 替换为占位符 `{{PC_CSP_NONCE}}`，命中后用当前请求的 nonce 回填（`PageCache::P_CSP` + `toShell/personalize`），HIT 页脚本 nonce 与本次响应头始终一致、不串号。
- **WebP 渐进增强（P2）**：`ImageOptimizer` 在重压/限宽与「已达标早退」两条路径都为 JPEG/PNG 产出同目录同名 `.webp` 兄弟文件（原图永不删、不改扩展名）；新增匿名组件 `<x-picture>`（`resources/views/components/picture.blade.php`），仅当 webp 兄弟存在时输出 `<source type="image/webp">`，否则等价普通 `<img>`，缺失/外链/非 /storage 自动回退原图，**绝不裂图**。前台 13 处内容图片位统一改用该组件。`media:optimize` 增加「补 WebP」计数与回填。本轮已为存量 33 张图补齐 webp。
- **知识正文 Markdown 渲染缓存（P3）**：`Content::bodyHtml()` 用文件缓存按 `id:updated_at:正文长度` 缓存 7 天，正文页改用该方法，避免每次请求重复 Parsedown 解析；后台预览仍实时渲染。输出与 `Str::markdown` 完全一致（有测试断言）。
- 回归测试新增 7 例：前台 CSP 在 MISS/HIT 的 nonce 一致性与占位回填、HIT nonce 不被缓存钉死、后台 CSP 放宽脚本但锁导航类指令（`PageCacheTest`）；webp 兄弟生成/幂等/GIF 跳过（`ImageOptimizerTest`）；webpUrl 映射/缺失/外链/空值与 bodyHtml 一致性（新建 `WebpAndRenderCacheTest`）。

### Changed
- **产品详情首屏「关键参数一览」卡片留白（P2）**：根因是全站第二套 `.param-table` 给单元格固定 56px 行高（为宽幅规格表设计），首屏仅 3 行的侧栏卡因此空高。新增桌面端（≥1025px）紧凑变体（顶对齐、行高自适应、内边距收紧）；≤1024px 恢复整宽拉伸并沿用全站统一的移动「参数卡片」布局，不污染共享规格表。
- **移动端横滑子导航加固（P3，结论非 bug）**：390/375/360 实测 13 个页面页级均无横向滚动（溢出 -15），越界元素仅为 `.subnav-in` 横滑标签条内的项（设计内的容器内横滑）。补 `flex:0 0 auto / overscroll-behavior-x:contain / -webkit-overflow-scrolling:touch`，消除边界回弹串滚。

### Notes / 取舍
- **AVIF 本轮不做**：GD 编码慢、收益在 WebP 之后边际递减，且 WebP 已覆盖现代浏览器；`<x-picture>` 后续可低成本再加 AVIF `<source>`。
- per-request nonce 使 body 随 nonce 变化，弱 ETag 每请求不同、304 基本不再命中；但 HIT 仍跳过控制器/Blade/查库，核心性能收益保留，可接受。
- 生产 `expose_php`：中间件已移除 `X-Powered-By`（含 `header_remove` 兜底），`DEPLOY.md` 已要求 `php.ini expose_php=Off`，复核通过。

### Verified
- 全量测试 **131 passed / 636 assertions**（v0.9.17 基线 124，新增 7），无回归。
- 浏览器实测：首页 10 个 picture 全部带 webp source、0 裂图；前台 Console 对 CSP **零违规**（轮播/交叉淡入/导航/留言校验脚本均执行）；后台 /admin、/admin/blocks、/admin/contents/create 内联脚本与 onclick 正常、零违规；MISS/HIT 的 CSP 头与脚本 nonce 一致。
- 390/375/360 探针复测 13 页无页面级横滚后已删除临时探针；桌面/移动截图核对 hero、产品卡、参数卡、封面图布局正常。

## [0.9.17] - 2026-09-16

> 主线：全站体验/视觉/交互/性能/安全深度体检后的系统性修复——图片上传与历史资源统一压缩限宽、安全响应头在整页缓存命中时不再丢失、站点图标 404 修复。均为根因级统一处理，非页面补丁。

### Added
- **统一图片优化管线 `app/Support/ImageOptimizer.php`**：所有后台图片上传入口在落盘前统一「按场景限宽 + 重压 + EXIF 方向转正」，保持原格式/扩展名/URL/透明通道不变；GD 不可用或处理失败时静默回退原图、绝不阻断上传；GIF/AVIF/SVG 与非图片原样保留。限宽口径：Banner/Hero 2048、内容/封面/媒体/装修图 1600、Logo/设置图 1200；JPEG q82、WebP q80、PNG 保 Alpha 无损压；已达标（不超宽且 JPEG/WebP ≤300KB）的图不二次重编码，避免二次损失。
- **批量治理命令 `php artisan media:optimize [--dry-run]`**（`app/Console/Commands/OptimizeImages.php`）：递归处理 storage/app/public 下 banners/blocks/covers/media/content，幂等可重复，输出前后对比并在实跑后自动清整页缓存。
- 回归测试：`tests/Unit/ImageOptimizerTest.php` 3 例（超宽 JPEG 限宽、小图不动、非图片透传）；`PageCacheTest` 新增「安全头在 MISS/HIT 都保留且不暴露 X-Powered-By」1 例。

### Changed
- **5 个上传入口共 6 处全部接入统一管线**：`BlockController`（装修图 1600、幻灯 2048）、`ContentController`（封面 1600）、`MediaController`（媒体库/正文内联 1600，非图片安全跳过）、`SettingController`（Logo/OG 1200）；媒体库记录的宽高/体积改为读取优化后的成品文件，`Storage::disk('public')->path()` 解析路径以兼容测试 fake 磁盘。

### Fixed
- **安全响应头在整页缓存 HIT/304 时丢失（P1）**：根因是 `bootstrap/app.php` 中 `SecurityHeaders` 中间件位于 web 组最内层，而 `CachePage` 命中时用缓存 body 直接新建响应提前返回，内层加的头未进入缓存文件（缓存仅存 body）。已将 `SecurityHeaders` 调到 web 组最外层，MISS/HIT/304/BYPASS 回程统一补 `X-Content-Type-Options / X-Frame-Options / Referrer-Policy / Permissions-Policy`；并在该中间件移除 `X-Powered-By`（含 `header_remove` 兜底），不再暴露 PHP 版本。
- **favicon 空文件/404（P2）**：`public/favicon.ico` 原为 0 字节、`apple-touch-icon.png` 404、`favicon.png` 为 70KB 长形 logo。已基于品牌 logo 重新生成方形多尺寸 `favicon.ico`（16/32/48，2.9KB）、`favicon.png`（128，5.5KB）、`apple-touch-icon.png`（180 白底，8.6KB），并在 `site.blade.php` head 同时声明 ico/png/apple-touch。

### Performance
- 历史大图批量治理实测：hero 476→165KB、hero-2 636→224KB、integrated 528→191KB、4096×1024 横幅 953→120KB（限宽 2048×512）、1920 媒体图 358→191KB（1600×542）；**合计 2.88MB → 0.87MB，下降 69.8%**，首页 LCP 图片体积降约 65%，重压后无头浏览器目视画质无可见劣化。

### Verified
- 全量测试 **124 passed / 590 assertions**（原 120 + 新增 4），无回归。
- 清缓存后对 sitemap 全量 30 个 URL 做 MISS→HIT 两遍爬取：全部 200、每页 H1 唯一、安全头齐全、零异常；favicon.ico/png/apple-touch-icon 均 200；线上 Console 无本站资源报错（仅有浏览器壳扩展噪声）。

## [0.9.16] - 2026-09-16

### Fixed
- 首页「主体事实」8 个格子在 PC 宽屏被 `.facts.auto` 的 `auto-fit/minmax(210px)` 排成 5+3（末行 3 个、不齐）。首页固定取 8 条，改为确定性网格：移除该区块的 `auto` 修饰类，PC 统一 **4 列 × 2 行**，平板（≤900）2 列，手机（≤768）1 列，长文本不再挤压。
- 顺带修复一个系统性响应式缺陷：`.facts.auto`（文章关键事实、工厂资质等条数不固定处）因类选择器优先级高于媒体查询里的 `.facts`，导致 ≤900/≤768/≤600 的列数覆盖对其**从不生效**；已在各断点同时覆盖 `.facts.auto`，保证这些页面在平板/手机正确降为 2 列/1 列。

## [0.9.15] - 2026-09-16

> 主线：前台整页静态化（服务端全页响应缓存）落地，补齐云端生产配置与部署手册。SSR 直出本就保证 SEO/GEO 可抓取可理解，本次补的是 TTFB/稳定性/抗并发这一层。

### Added
- **整页静态化缓存（PageCache）**：新增 `app/Support/PageCache.php`（文件存储、版本化失效、匿名外壳）与 `app/Http/Middleware/CachePage.php`，在 `bootstrap/app.php` 的 web 组中置于归因/规范化之后、安全头之前。
  - 仅缓存匿名 `GET` 的前台 200 HTML；命中直接返回 SSR HTML，跳过控制器/Blade/查库。
  - **CSRF 与归因不串号**：缓存前把留言表单 `_token` 与 landing/referer/utm 隐藏字段替换为占位符，命中后用当前会话值回填，一份缓存服务所有访客。
  - **发布即失效**：内容、栏目、分组、Banner、菜单、首页装修、设置、媒体、跳转、事实等模型 `saved/deleted` 时缓存版本号 +1（在 `AppServiceProvider` 统一挂钩）；留言、操作日志、同步日志、用户不触发。
  - 不缓存：`/admin`、`/api`、`/search`、`POST /inquiry`、AJAX/JSON、登录态、带非白名单查询参数（UTM/gclid 等追踪参数不产生重复副本）、留言成功/校验错误的 PRG 个性化页。
  - 响应头：`X-Page-Cache: MISS/HIT/BYPASS`、弱 `ETag` + `Cache-Control: private, no-cache, must-revalidate`（同会话未变返回 304；含按会话回填的 token，故不交给共享 CDN）。
  - 新增命令 `php artisan page-cache:clear`（改 config/facts/Blade 等文件型内容后手动刷新）。
- **生产部署物料**：`.env.production.example`（production/debug 关闭/安全 cookie/文件缓存等）、`DEPLOY.md`（宝塔/Nginx+PHP-FPM vhost、静态资源与 /storage 长缓存、OPcache、发布流程、静态化验证、队列与备份、上线自检清单）；`public/.htaccess` 补静态资源长缓存（Apache 兜底）。

### Verified
- 新增 `tests/Feature/PageCacheTest.php` 7 例：MISS→HIT、搜索/后台不缓存、UTM 共用外壳且各自回填、CSRF 不被缓存钉死、设置变更自动失效、PRG 成功页旁路、外壳占位/回填单元验证。
- 现网实测：首页/产品/场景/知识/关于/联系二次访问均 `HIT`，sitemap/llms 保持 `public, max-age=3600` 不受影响；两个独立会话从 HIT 页取各自 CSRF 提交留言均 302 成功入库、不串号；ETag 命中返回 304、错误 ETag 返回 200。
- 全量测试 **120 passed / 573 assertions**（原 113 + 新增 7），无回归。

## [0.9.14] - 2026-09-16

> 主线：全站主 CTA 文案统一由「免费索取样品」更名为「免费获取样品」；复核顶部导航绿色 CTA 的 hover 与全站按钮同一标准。

### Changed
- **全站主 CTA 文案统一更名「免费索取样品 → 免费获取样品」**：覆盖运行库实际值（`settings.nav_cta_text`、`banners.link_text` id=1、相关 `settings.hint`）与全部源码默认值——`config/copy.php`（`nav.cta`、`nav.ctaMobile`、`bottomCta.primaryCta`、工厂变体 `secondaryCta`）、`AppServiceProvider` View Composer 回退、迁移与 `SettingSeeder` 默认值、导航/首屏 A·C/参数区/产品详情/关于页 Blade 回退、首页 S08 eyebrow、Banner 后台 placeholder/hint、联系页 SEO title，以及对应测试断言。复扫活动源码与数据库无「免费索取样品」残留（仅 CHANGELOG 历史条目保留）。
- **顶部导航绿色 CTA hover 与全站统一**：复核确认其仅使用共享 `.btn` 类、无任何头部专属 hover/transform 覆盖，与首屏/参数区/产品/关于/底部 CTA/表单提交按钮共用同一规则（hover 变 `--cta-dark` + `translateY(-1px)`、active 回弹、箭头右移），不做特例化以保证全站交互一致。后台 `admin/layout.blade.php` 的红色按钮为管理端独立体系，不与前台混用。

## [0.9.13] - 2026-09-16

> 主线：回退顶部导航两处多轮叠加的动效并清理冗余代码；移动端导航直接保留「400 电话 + 免费索取样品」两个入口。

### Removed
- **移除 400 电话胶囊的「边框流光/光龙」动效**：删除 `resources/views/layouts/site.blade.php` 中全部相关 CSS（`.tflow`、`.light-glow`、`.light-band`、`@supports (offset-path…)`、`@keyframes telFlowNew`、仅服务动效的 `.hd-tel{position/isolation/overflow}` 与 `.hd-tel>svg{z-index}`、专属 `prefers-reduced-motion` 块）及头部标记里的 `<span class="tflow">…</span>` 与大段动效注释。电话恢复为最初的静态 1px 描边胶囊（保留 hover 变品牌红描边/文字）。
- **绿色「免费索取样品」CTA 恢复为纯静态按钮**：确认无 `::before/::after`、无扫光/光泽动画残留（更早一轮已删，本轮复核）。
- **删除移动端下拉导航内重复的 `.nav-actions` 行为区**（HTML 块 + 基础 `display:none` 与 768 断点下的 4 条规则），消除与顶栏按钮的重复。

### Changed
- **移动端（≤768px）顶栏直接常驻两个动作入口**，不再需要先展开汉堡菜单：
  - 400 电话收为 **38×38 仅图标圆形描边按钮**（号码包进 `.hd-tel-num`，移动端隐藏；完整号码写入 `aria-label`，点按即 `tel:` 拨号，桌面端仍显示「图标+号码」胶囊不变）；
  - 「免费索取样品」为紧凑绿按钮（`padding:8px 12px;font-size:13px`），汉堡按钮同步收到 38×38，`.hd-right` 间距 8px；
  - 桌面端（>768px）电话胶囊、绿色 CTA 的尺寸/颜色/字体/布局/交互完全保持原样。

### 验证
- 同源 iframe 在 360/375/390 三档实测 `documentElement.scrollWidth == clientWidth`（无横向溢出）；390 下电话 `tel:+86…` 38px、CTA `#s08` 104px、汉堡 38px 均 `display:flex/block` 可见，勾选汉堡后下拉导航正常展开（26 个链接）；`.hd-tel-num` 在移动端 `display:none`。
- 桌面 1440 截图确认电话为静态描边胶囊、CTA 纯绿，无光带/扫光/残影；`php artisan test` **113 passed / 537 assertions**；`view:cache` 编译通过；活动代码全站检索无 `tflow/light-band/light-glow/telFlow/offset-path/nav-actions` 残留（仅 `_shots/` 历史快照与文档保留）。

## [0.9.12] - 2026-09-16

> 主线：①后台能力与前台一一对应审计，修复「导航菜单」死面板、主 CTA 文案不可配两处真实前后端不一致；②顶部导航两处精致动效（400 电话边框流光、绿色 CTA 高光扫光），只增不改原尺寸/颜色/字体/布局/交互，全部可降级。

### Added
- **「结构 → 导航菜单」真正生效（修复死面板）**：此前 `/admin/menus` 写入的 menus 表前台从不读取（主导航由 config 单一驱动、页脚由 config 驱动），后台增删菜单完全不生效。现以「追加自定义入口」方式接通，不改动锁定 IA：位置=主导航/移动端的启用项追加到顶部导航（桌面与移动共用同一导航列表），位置=页脚的启用项在底部生成「快捷入口」列；链接解析复用 `Menu::link()`（关联栏目优先，其次 URL），外链/新窗自动 `target="_blank" rel="noopener"`，内部路径走 `url()`，停用项不渲染；新增/编辑/删除后统一调用 `AppServiceProvider::forgetNavCache()` 同时失效 `nav.tree`/`main.menu`/`footer.extra`（原代码只 forget 了一个名字写错的 `nav_tree` 键）。
- **主 CTA 文案可在后台配置**：新增设置项「基础信息 → 主 CTA 按钮文案」（key `nav_cta_text`，留空回退默认），顶部导航、首屏 A/C、参数区、产品详情、关于页的主按钮统一读取同一来源（View Composer 共享 `$ctaText`），消除此前 3 处硬编码「免费索取样品」、2 处读 config 的不一致。
- **顶部导航动效（仅视觉增强，可降级；两处形成层级差异：电话更动态负责吸引注意，CTA 更克制负责诱导行动）**：
  - 400 电话区「边框流光·光龙 Border Glow」（**注：该动效已于 0.9.13 应用户要求整体移除，以下仅作历史记录**）：保留原 1px 描边与全部既有静态样式（位置/尺寸/圆角/图标/号码/字体/布局/点击均不变），改用 **CSS Motion Path**：胶囊内一层 `.tflow`，15 段短光条以 `offset-path: inset(.5px round 999px)` + `offset-rotate:auto` 沿真实胶囊边框锁步游走，靠负 `animation-delay` 排成一条连续光带；最前缘是暖白亮头（`.seg.head` + 红色 `box-shadow` 光晕），其后两段模糊红光为光身过渡，其余 12 段透明度按指数单调衰减到零形成自然光尾；4.5s 线性无限循环，闭合 inset 路径 0% 与 100% 同点、首尾无缝，四个圆角由 `offset-rotate` 自动切向、连续不断。纯 CSS 合成层、无 JS、无第三方库；`@supports (offset-path:…)` 不成立时不渲染光层，降级为静态胶囊边框。（早期 `conic-gradient` 与 SVG `stroke-dasharray/pathLength` 两版均废弃：实测 Chrome 在 CSS/SMIL 动画 `stroke-dashoffset` 时会忽略 `pathLength` 归一，导致光带按 px 周期重复、断成多段。）
  - 「免费获取样品」绿色 CTA「柔和表面光泽 Soft Sheen」（**注：该动效已于 0.9.13 应用户要求整体移除，以下仅作历史记录**）：绿色/尺寸/圆角/文字/布局完全不变。`::before` 为静态、极淡的顶部表面质感（峰值约 8% 白）；`::after` 是一条**窄（宽 20%）、低透明（峰值白仅 12%）、边缘 blur(2.5px)、skewX(-12°)** 的斜向微光泽，6s 周期 `ease-in-out`：从按钮左外扫到右外约 2.5s，随后停顿约 3.5s，两端在按钮外被 `overflow:hidden` 裁切、复位不可见；按钮始终是绿色、不发白，光泽压在绿底之上、文字（`.hd-cta-label` z-index:2）之下，文字全程清晰稳定——只做高级 SaaS 式注意力暗示，不做电商促销闪光/手电筒效果。
  - 两者均在 `@media (prefers-reduced-motion: reduce)` 下关闭动效层（CTA 保留静态表面质感）；移动端（≤718px，电话/CTA 本就 `display:none`）不渲染、不耗性能。
- 新增回归测试 `tests/Feature/V0912NavCtaAlignmentTest.php`（6 例）：CTA 设置播种、设置驱动前台且留空回退、设置接口可保存、主导航自定义外链渲染+新窗+停用即隐藏、页脚自定义项渲染为快捷入口、菜单缺少文字校验报错。

### Changed
- `AppServiceProvider`：主导航项结构新增 `external` 标记；新增 `footerExtra()`（缓存 600s）与 `resolveMenuHref()`（栏目/内链/外链/tel/mailto/锚点统一解析）；View Composer 额外共享 `footerExtra`、`ctaText`；boot 增加 `footer.extra` 请求级 memo 复位。
- 菜单管理页说明与空状态文案改为准确描述「在固定栏目基础上追加导航/页脚入口」。

### 刻意锁定（非缺失，保证 GEO 真实性）
- 产品五体系/六核心详情、六应用场景、工厂、合作方式、关于页事实、配比参数、产能数据等**结构化且需核实的目录数据**仍由 `config/facts`（facts.yaml 编译）+ 事实库锁定，不改成自由富文本 CMS，以防虚构与口径漂移；其键值在「治理→事实库」可维护，首页对应条目（场景/能力/车间/合作/流程/剪影）在「首页装修」可增删改、换图换图标换链接。知识文章/新闻/产品系列总览/通用长文为数据库内容，完整 CMS；首页全部区块走 page_blocks 可装修；主题/联系/SEO/GEO/对接走站点设置。

### Database / API
- 新增迁移 `2026_09_16_000001_add_nav_cta_text_setting.php`（幂等插入 `nav_cta_text`，可回滚）；SettingSeeder 同步该键，全新库自带。

### 验证
- `php artisan test` **113 passed / 537 assertions**（原 107 + 新增 6）；`view:cache` 全 Blade 编译通过、改动文件 `php -l` 无语法错误。
- 动效真实浏览器（内置 bu）运行时采样：①电话光龙——`getComputedStyle` 确认 `offset-path: inset(0.5px round 999px)` 生效、动画 `telFlow 4.5s infinite`，光头 `offset-distance` 每 0.5s 线性推进约 11.1%（44.4→55.7→…→89.4→0.67→…），在闭合路径 100%/0% 处无缝回环；无头冻结 8 相位网格 + 线上多帧截图逐边核验顶边/右上圆角/右侧/右下圆角/底边/左下圆角/左侧/左上圆角均连续、亮头在前、光尾渐隐、无断档跳变。②CTA——`::after` transform 采样为恒定 skewX(-12°)、translateX 约 -27.6px→14.3→84.8→121.7→122.9（约 2.1–2.5s 扫过）后保持场外停顿（6s 周期），冻结中央相位截图确认按钮仍为绿色、文字清晰、无发白/手电筒感。
- 降级与移动端：`--force-prefers-reduced-motion` 无头截图确认电话仅剩静态 1px 边框、CTA 纯绿无扫光；390px 移动端截图确认电话/CTA 及其光层均隐藏、无横向溢出。
- 移动端 390/360 实测 `documentElement.scrollWidth == clientWidth`、全量元素扫描无任何元素超出视口（无横向溢出）；内置浏览器确认后台「主 CTA 按钮文案」字段与菜单页新文案正常显示。

## [0.9.11] - 2026-09-15

> 主线：后台/CMS 五项系统级对齐——图片尺寸改为逐位就地标注、首页「主体事实」接通单一事实源、停用并清理两个旧区块且为每个装修区块标注前台位置、正文编辑器升级为「Markdown 工具条 + 插图 + 表格 + 预览 + 粘贴转换」、知识子栏目全面数据驱动，消除前后端不一致。

### Added
- **正文编辑器升级（内容管理）**：正文仍以 Markdown 为唯一规范存储（对 GEOFlow/Workflow 推送与 AI 最友好，前台本就用同一 `Str::markdown` 渲染、支持 GFM 表格），在此之上提供富文本体验：工具条（H2/H3、加粗、斜体、引用、有序/无序列表、表格模板、链接、上传图片、预览/继续编辑）、正文内联图片异步上传（`POST /admin/media/inline`，限 jpg/png/webp/gif/svg ≤8MB，入媒体库统一管理并回传宽高）、服务端预览（`POST /admin/contents/md-preview`，与前台同一渲染器，所见即前台所得）、从 Word/网页粘贴富文本自动转 Markdown（标题/列表/表格/链接/图片/引用/加粗斜体）。
- **首页装修逐位标注前台位置**：`config/home_blocks.php` 每个区块类型新增 `front` 字段（中文「前台对应位置」，锚点以各 Blade 真实 id 为准），后台每张区块卡片渲染该说明，运营可直接知道改的是首页哪一段。
- **「主体事实(facts)」区块接通单一事实源**：`Fact::publicRows()` 供首页读取（带请求级 memo），首页精选 8 条公开事实（排除页眉页脚已有的公司全称/品牌名/官方电话），后台 facts 卡片给出「去治理→事实库维护」指引，避免两处编辑口径不一致。
- 新增回归测试 `tests/Feature/V0911CmsAlignmentTest.php`（9 例）：知识栏目数据驱动渲染与导航一致、旧空栏目不再播种、后台停用栏目即从前台+导航消失、分组简介持久化、Markdown 预览含 GFM 表格且需登录、内联图片上传成功并拒绝非图片、首页事实渲染且旧区块不在注册表。

### Changed
- **知识子栏目全面数据驱动（修复前后端不一致）**：此前子栏目在 6 处写死（KnowledgeController 白名单、路由正则、config/copy 主菜单、AppServiceProvider、SitemapBuilder、LlmsBuilder），后台启用 6 组、前台只显示 3 组。统一改为由 `Group::knowledgeChannels()`（category=knowledge、is_active、按 sort，带请求级 memo 并在每请求 boot 复位、分组写操作后精确失效）驱动：频道控制器、主导航下拉、sitemap、llms.txt 全部启用几个显示几个；路由正则放宽为 `[a-z0-9-]+`，非栏目的 slug 自动转交统一内容分发器渲染扁平文章，不误杀文章详情。
- **StructureSeeder 成为全新库/测试库知识栏目的唯一来源**：锁定 IA 三栏目 选料指南(selection)/工艺与配方(process)/开店与经营(business) 改由 seeder 创建（原先只靠依赖栏目已存在的迁移创建，全新库竞态缺失）；ContentSeeder 三篇知识文章按 slug 绑定对应 group_id，保证频道页有内容。
- 图片尺寸说明从「后台顶部统一速查表」改为「每个上传位就地标注」（统一速查表删除），编辑时在对应位置直接看到推荐尺寸。

### Fixed
- **分组简介存不进/回显空白**：`GroupController` 校验用 `intro`，而 groups 表列名是 `description`，后台编辑简介被丢弃；统一改为 `description`（控制器 + 分组管理视图两处），并在新建/更新/删除后统一清知识栏目与导航缓存。

### Removed
- 停用并清理两个无对应前台的旧首页区块：「客户痛点→解决方案(problems)」「差异化(differentiators)」——从 `config/home_blocks.php` 注册表与排序移除、删除两个 Blade、删除 HomeController 两个私有取数方法、迁移清理 page_blocks 残留行，并清除 site 布局中仅它们使用的 `.ps-*`/`.diff-*` 死样式。
- 删除后台首页装修顶部的「全站图片尺寸统一速查表」及其样式（改为逐位标注）。

### 透明取舍（可逆）
- 旧的三个空知识栏目「腌制工艺(craft)/餐饮应用(application)/行业观察(industry)」与锁定 IA 重复且 0 篇文章，已用迁移**软停用（is_active=0，非硬删，可逆）**；现在前台显示与后台启用完全一致。若需保留，后台重新启用并发文章即自动显示，无需改代码。
- 「富文本」落地为 Markdown 工具条 + 插图 + 粘贴转换 + 服务端预览，而非所见即所得 HTML 直接入库——HTML 入库会破坏 GEOFlow/LLM 契约与前台统一渲染，Markdown 纯文本存储对 AI 与自动推送最友好。

### Database / API
- 新增迁移 `2026_09_15_000018_retire_legacy_blocks_and_groups.php`（删除 problems/differentiators 区块行；仅当旧知识分组零文章时软停用）。
- 新增后台接口 `admin.media.inline`（POST media/inline）、`admin.contents.md-preview`（POST contents/md-preview），均在鉴权与 CSRF 保护内。

### 验证
- `php artisan test` **107 passed**（原 98 + 新增 9）；`view:cache` 全 Blade 编译通过；curl 实测首页 facts 渲染 8 条、三知识频道 200、旧 craft 404、知识文章详情经统一分发器 200、sitemap/llms 正常；内置浏览器实测后台 16 个装修区块均带前台位置说明、正文工具条 12 键齐全、预览请求发出且表格/加粗正确渲染、继续编辑与加粗包裹正常。

## [0.9.10] - 2026-09-15

> 主线：按《官网系统全栈体检 Master Prompt》做真实运行的全站体检，系统性修复重复查询、补齐安全响应头、清理孤儿文件，全量回归通过。

### Fixed（系统性，非局部补丁）
- **修复请求级重复查询（N+1 类性能问题）**：`Fact::publicMap/publicByLabel/groupMap`、`Setting::allCached`、`AppServiceProvider` 的 navTree/mainMenu 每次调用都查库，叠加首页约 45 个视图 `@include`，导致首页 202 次查询、产品详情 270 次。统一加「请求级内存 memo」：Fact/Setting 模型静态缓存并在写操作（FactController store/update/destroy、Setting::set）后精确失效，导航 Composer 静态 memo 且 forgetNavCache 同步清。修复后首页降到 19 次、产品/方案/知识等多数页 2–3 次，重复模式归零。
- **补齐全站安全响应头**：新增 `App\Http\Middleware\SecurityHeaders` 并挂到 web 组（前台+后台统一生效），下发 `X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`（防点击劫持）、`Referrer-Policy: strict-origin-when-cross-origin`、`Permissions-Policy: camera=(),microphone=(),geolocation=()`；不覆盖上游同名头。HSTS 留给 HTTPS 反代、强 CSP 需 nonce 改造，列入后续专项。

### Removed
- 清理 7 个未入媒体库、且全库（page_blocks/banners/settings/media）零引用的历史直传残留图（banners/202609 下哈希命名、各约 10KB）；保留被引用的 hero-cinematic×2、sample 中幅与媒体库内 hero-integrated。

### 体检实测（真实运行，非读代码推断）
- 85 条路由、23 张表；38 个前台 URL 全 200（仅设计内 301 旧链跳转与非核心产品 404），后台游客全 302、登录后 26 页+13 内容编辑页全 200，13 个后台内部链接全 200，全站无 500。
- 留言链路 8 场景（合法/必填/手机/白名单/超长/XSS 转义/蜜罐/来源归因）全部符合预期；CSRF、登录限流、bcrypt cost12、geoflow 无 token fail-closed 503 均在线。
- 27 个前台页 meta/OG/JSON-LD 全部合法且 @type 与页型匹配，sitemap 29 条全可达，robots/llms/feed 正常。
- 响应式以同源 iframe 实测 360/375/390/430 与桌面 1440：均无页面级横向溢出（产品/知识子导航为设计内 `overflow-x:auto` 横向标签条，body `overflow-x:clip` 兜底）；5 个关键页滚动加载后前端 JS 零报错。
- 真实 TTFB 0.4–0.9s（SQLite 单线程开发服）；Hero 首图 fetchpriority=high、其余 lazy+decoding=async。

### 验证
- `php artisan test` **98 passed / 474 assertions**（安全头与性能改动前后均全绿，零回归）；`optimize:clear` 通过；curl 实测前台与后台均已带上安全头。

## [0.9.9] - 2026-09-15

> 主线：后台每个图片上传位都标注推荐尺寸（按前台真实渲染比例换算，非拍脑袋），运营出图一次到位。

### Added
- **首页装修逐位尺寸说明**：
  - 首屏幻灯：B 全幅 1920×640（3:1，移动按 4:3 裁）、C 一体化背景 2048×900（约 2.3:1，暗调主体在右），既有行与「＋添加一张」动态行都在选择文件按钮下方标注；
  - 中部横幅：通栏 2400×600（4:1，移动按 16:9 裁）；
  - 条目型区块「自定义图片/图标」列头按区块类型给出比例：应用场景 720×240（约 3:1）、合作剪影 720×300（约 5:2）、能力点/车间/合作方式等小图标位 256×256（1:1）。
- 内容封面标注 1280×720（16:9）；系统设置图片按 key 标注（OG 分享图 1200×630、Organization Logo 512×512）；媒体库上传处补一张「各用途推荐尺寸速查」。
- 新增统一样式 `.size-tag`（小号弱化说明，不与表单控件抢视觉）。
- **全站图片上传位遍历审计**：从控制器反查，确认后台图片上传仅 4 个入口——BlockController（首页条目/首屏与中部幻灯）、ContentController（内容封面）、MediaController（媒体库）、SettingController（OG/Logo），已全部标注；栏目图标为内置线性 SVG 库、非位图上传，无需尺寸。首页装修顶部新增可折叠「全站自定义图片尺寸速查」表（9 类用途 × 尺寸/比例/说明），编辑时任一区块都能对照出图。

### Fixed
- 首页装修纯数据区块的提示仍指向已删除的「展示 → Banner 轮播」，改为“核定数据展示”的准确说明。

### 验证
- `view:cache` 全部 Blade 编译通过；浏览器实测 10 处尺寸标注正确、动态新增首屏/中部行也带标注、无残留 Banner 轮播字样；`php artisan test` **98 passed / 474 assertions**，无回归。

## [0.9.8] - 2026-09-15

> 主线：首屏/中部横幅已能在「首页装修」内就地维护，移除冗余的独立「Banner 轮播」后台模块，并清理随之失去入口、且零数据的 category_top 栏目横幅接线，避免同一能力两处控制。

### Removed
- **删除独立 Banner 管理模块**：`BannerController`、`admin/display/banners.blade.php`、5 条 `admin/banners*` 路由与 `use`；后台左侧「展示 → Banner 轮播」菜单、Dashboard「管理 Banner」快捷入口（改为「首页装修」）。首屏（home_top）与中部横幅（home_mid）统一在 首页装修 内传图/改文案/排序/增删。
- **移除未使用的 category_top 栏目横幅**：该投放位数据库零条、前台不渲染，且唯一管理入口就是被删的独立模块。同步删除 Product/Solution/Knowledge 三个控制器的 `catBanners` 查询与 `Banner` import、三个列表页的 `@include('site._cat_banner')`、`site/_cat_banner.blade.php` 局部，以及 `layouts/site.blade.php` 中 `.catbanner` 死样式。
- 测试：删除 BannerSlotTest 中已失效的 category_top 数据驱动用例（3 例）。

### Kept
- **Banner 模型、banners 表与迁移保留**：`BlockController::syncSlides()` 仍以它作为 home_top/home_mid 的存储引擎，`HomeController` 照常读取；表 position 字段保持通用，未来若要做栏目页横幅可作为独立装修点重建。

### 验证
- `php -l` 改动文件无语法错误；`optimize:clear` 清编译缓存。
- `php artisan test`：**98 passed / 474 assertions**（v0.9.7 为 101/483，差值恰为移除的 3 例 category_top 用例，无其它回退）。
- `route:list` 已无 admin/banners，仅保留 admin/blocks；实测 /products/、/solutions/、/knowledge/ 均 200，旧 /admin/banners 返回 404；浏览器确认后台菜单与 Dashboard 不再出现 Banner 轮播，首页装修内嵌幻灯编辑器正常。

## [0.9.7] - 2026-09-15

> 主线：首页装修系统性对齐——首屏幻灯改为「一张图=一个主题」（图文按钮一起切换）、Banner 管理并入首页装修就地维护、模式顺序改为 A/B/C；应用场景/合作方式/合作剪影/四大车间全部升级为可增删改、可换图、可配链接的条目型区块，首页每个模块都能在后台改文案与图片。

### Changed
- **首屏幻灯「一图一主题」**：C 一体化主视觉由“一套固定文字+换背景图”改为每张幻灯自带主标题/正文/按钮文字/跳转，切换时背景层与文案层同 index 交叉淡入（`.hi-copystack` grid 同格堆叠，高度取最高层不跳动）；**第一张标题为唯一 `<h1>`，其余幻灯标题用 `div.hi-title` 且 `aria-hidden`，整页始终唯一 H1**；单图时无圆点/脚本、外观不变。B 全幅轮播本就逐张压字，保持一致。
- **模式顺序 A → B → C**：后台首屏点选卡由 A/C/B 调整为 A（价值主张+参数卡）、B（全幅轮播）、C（一体化主视觉，推荐）。
- **Banner 管理并入首页装修**：首屏（home_top）与中部横幅（home_mid）不再需要跳到独立「Banner 轮播」模块——在首页装修对应区块内即可逐张传图、改主标题/正文/按钮/链接、排序、启用、删除，并可「＋添加一张」；BlockController 新增 `syncSlides()/storeBannerImage()` 统一处理增删改与图片入媒体库。独立 Banner 模块保留给栏目横幅等其它投放位。
- **场景/合作/剪影/车间升级为条目型**：`config/home_blocks.php` 中 scenes/cooperation/cases 由 simple 改为 items，workshops 字段补 text；新增 `App\Support\HomeBlockDefaults` 作为前台与后台**共用的唯一缺省内容源**（未自定义时前台按此渲染、后台按此预填，保存后以区块条目为准），消除前后台默认值不一致。场景条目支持跳转链接、自定义图/图标；合作条目保留要点列表；剪影保留业态/组合标签。
- **条目编辑器补「链接」列与派生字段回传**：场景等条目可配跳转；默认条目自带的 tags/points/reveal/sub/cta 等派生字段以隐藏域原样回传，避免“只点一次保存就丢组合标签/揭示参数”；图标选择新增「（无图标）」项，空图标不再被强塞默认图标。
- 新增迁移 `..._000017_backfill_workshop_text`：按车间名回填四大车间说明（来源仍为核定 Facts），修复字段升级后已存车间条目缺说明的问题。

### Added
- 前台新增场景图/图标（`.sc-img/.sc-ic`）、剪影图（`.case-img`）、逐幻灯文案层（`.hi-copystack/.hi-copy-layer`）样式，移动端同步。
- 测试：HeroModeTest 新增 2 例（C 每图独立文案仍单 H1、后台内嵌幻灯增改删）；新增 HomeBlockItemsTest 5 例（场景默认六卡带链接、后台覆盖、保存保留派生标签且空图标为 null、合作/剪影默认渲染、车间说明回填）。

### 刻意锁定（不开放自由编辑，防虚构、保单一事实源）
- 信任数字带 stats（20 年/约 9000㎡/约 8000 吨/四大车间/七大区）只从 Facts 派生；参数级交付配比表与产品页共用同一数据源；主体事实 facts 为 GEO 实体事实。三者标题/副文案仍可改，数字与配比不做后台自由录入，避免与产品页不一致或产生未核定数字。

### 验证
- 真实浏览器走查：后台模式顺序 A/B/C、首屏内嵌 2 张幻灯字段齐全、中部横幅可加图、场景 6 行预填且有链接列；首屏点第 2 个圆点时背景图+标题+正文+按钮（→/products/）同步切换；真实“保存首屏区块”返回成功且数据不丢失。
- `php artisan test`：**101 passed / 483 assertions**（基线 94/447，只增不减）；核心页状态码 200、整页唯一 H1、无异常。

## [0.9.6] - 2026-09-15

> 主线：后台「首页装修 → 首屏主视觉」交互重构——模式由下拉改为点选卡，露出可编辑文案，并按所选模式直接预览对应图片。

### Changed
- **首屏模式改为点选卡**：A / C / B 由 `<select>` 下拉改为三张可点击的单选卡片（选中红边+柔红底+对勾，支持键盘方向切换），点选即切换下方“配图说明面板”，无需保存即可看清当前模式。
- **首屏文案全部可在后台编辑**：原 hero 区块不显示标题输入、说明正文还写死在模板。现露出「主标题 H1」「眉题/定位行」「说明正文（textarea）」三项；新增 `hero_lead` 落库到区块 content.lead，A/C 前台读取（留空回退核定默认口径），B 作为无文字图时的 H1 兜底。BlockController 增加 `hero_lead` 校验（max:500）并在 hero 分支持久化。
- **按模式显示对应图片**：后台首屏卡片内直接列出当前「首页顶部（首屏）」启用 Banner 的缩略图与序号（C：第一张为默认背景、多张自动交叉淡入；B：按序全幅轮播、逐张可压字），并提供「上传/管理首屏图」入口；A 明确提示无需配图。

### Added
- 测试：HeroModeTest 新增 2 例——首屏标题/眉题/正文可编辑并在 C 渲染且单 H1；正文留空回退默认口径。

### 验证
- 真实后台走查：三张卡点选高亮与面板联动正常；实际“改说明正文→保存→首页出现→清空回退默认”闭环通过，模式 C 与自动播放状态保持；前台 A/B/C 无回归。
- `php artisan test`：**94 passed / 447 assertions**。

## [0.9.5] - 2026-09-15

> 主线：首屏 C「一体化主视觉」支持多图**交叉淡入幻灯**——文字/CTA 固定不动，多张底图平滑轮播。

### Added
- **C 模式背景幻灯**：当「展示 → Banner 轮播」里“首页顶部（首屏）”启用图片 ≥2 张时，C 首屏自动变为背景层叠 crossfade 轮播（`.hi-bgstack` 层叠 + `.hi-bg.on` 透明度 0.9s 淡入，另带 6s 极缓慢 Ken Burns 放大）；右下角（移动端右上角）出圆点 `.hi-dots` 可手动切换，鼠标悬停暂停；自动播放复用首页装修 hero 区块的 `autoplay` 开关（默认关，尊重“方案1：默认不自动轮播”与 prefers-reduced-motion）。文字/眉题/H1/三 CTA/信任点始终固定，整页仍唯一 H1、SSR 全量输出（首图 fetchpriority=high，其余 lazy）。
- 生成并接入第二张同系列电影感暗调底图（木勺浇撒Sample Marinade粉到Sample Snack，2048×900），登记 Media#14、Banner#5（home_top，sort=1，文案留空）用于演示双图幻灯。
- 测试：HeroModeTest 新增 2 例——C 多图渲染双背景层+圆点+autoplay 标记且单 H1；C 单图不出圆点/脚本。

### Changed
- 羽化 `mask-image` 从单张 `.hi-bg` 上移到 `.hi-bgstack` 容器，避免逐张羽化在淡入时抖动；移动端竖向羽化同步改到容器、圆点移右上角。
- 后台「首页装修 → 首屏样式」自动播放勾选与 C 说明同步：明确 B/C 均适用、C 多图自动交叉淡入。

### 验证
- 真实浏览器逐秒采样：约每 5.5s 自动 0→1→0 稳定循环，圆点状态同步；点圆点可手动切回并暂停；桌面/移动融合与文字对比正常，A/B 模式无回归。
- `php artisan test`：**92 passed / 438 assertions**。临时脚本/截图已清理。

## [0.9.4] - 2026-09-15

> 主线：①首页中部横幅改通栏全宽，消除宽屏两侧大片灰底留白；②首屏 C 由“左文字/右图片两个割裂盒子”
> 重构为通用式「一体化主视觉」——图片、视觉、可编辑文字、CTA 与交互融合在同一块 banner 内。

### Changed
- **首屏 C 模式重构为通栏「电影感一体化主视觉」**：摒弃“整图 + 一块硬遮罩”的圆角卡片做法，改为通栏四层融合——①深暖酒红→暗褐品牌渐变铺底；②产品图铺满整块并用 CSS `mask-image` 把左缘**渐变羽化**进底色（非硬边遮罩）；③右侧一层克制暖色 radial 光晕增加层次；④文字区仅做向左渐隐的轻压暗保证对比。可编辑眉题/H1/说明/三 CTA/信任点压在左侧纯色渐变区，hover 时图在块内极缓慢放大（不影响布局/导航）；移动端图在顶部、向下羽化进深色渐变，文字落于下方。文案仍来自首页装修区块、SSR 直出，单 H1，保 SEO/GEO。
- **首页中部横幅（mid_banner）通栏**：去掉内层 1200 限宽 `.wrap`，单张横幅左右贴边铺满（宽屏不再有灰底留白）；图上压字内容用 `.mb-cap-in`（max-width:1200）重新对齐到全站内容栅格；多图横滑场景保留卡片圆角与页边距。
- 后台「首屏样式」C 选项与说明同步改为“一体化主视觉”，并给出配图要求（横图 16:9~3:1、电影感暗调、左侧自然沉入深色阴影做文案区、主体放右侧）。

### Added
- 新增 `--radius-xl:16px` 圆角 Token（未超 16 上限，供大面板场景使用）。
- 生成并接入一张电影感暗调宽幅底图（左侧自然沉入深酒红阴影、右侧暖光Sample Snack+Sample MarinadeSample Spice、无文字、无竖直接缝），登记为 Media#13 并替换 home_top 首屏 Banner（同时移除此前烧录“1万家+/10年”等与核定事实不符数字的红海报）；Banner id=1 主/副标题保持留空。
- 测试：HeroModeTest 的 C 用例选择器同步到一体化结构；BannerSlotTest 中部横幅断言保持通过。

### 验证
- CDP/真实浏览器实测：中部横幅桌面 x=0、宽=视口宽（通栏），压字与 1200 栅格左缘对齐；一体化首屏桌面 1440 深酒红向左、暖光食物向右无缝融合（无可见接缝/硬遮罩），移动 390 图在上向下羽化、文字清晰、按钮整行；A/B 模式无回归。
- `php artisan test`：**90 passed / 430 assertions**。临时脚本/截图已清理。

## [0.9.3] - 2026-09-15

> 主线：首屏新增「左文案 + 右图片」图文混排模式（C），解决“非纯文字即纯图片”的极端问题；
> 系统级修复 B 全幅模式下鼠标在大图上移动导致顶部导航下拉反复闪烁/抖动的问题。

### Added
- **首屏第三种样式 C · 图文混排（推荐）**：后台「首页装修 → 首屏主视觉 → 首屏样式」新增 `C · 左文案 + 右图片`。左侧价值主张（眉题/H1/说明/三个 CTA/信任点）与 A 同源、可后台编辑并在初始 HTML 输出（保 SEO/GEO），右侧读取「Banner 轮播·首页顶部」第一张图，圆角轻投影框承载（桌面 4:3、移动 16:10，移动文字在上图在下）。至此首屏三模式：A 参数卡（默认、无需配图）/ C 图文混排 / B 全幅轮播；C、B 无可用 Banner 时统一回退 A，绝不空首屏。
- 新增 `.hero-media` 样式（hover 放大仅在圆角框内、`overflow:hidden`，不影响导航与布局）。
- **测试**：HeroModeTest 新增 3 例（C 可切换落库、C 图文同屏且单 H1、C 无图回退 A）。

### Fixed
- **B 全幅模式下导航抖动（系统性，全站导航统一修复）**：根因是桌面下拉只靠 CSS `:hover` 瞬时开关，而弹出层较深地压在紧贴导航的首屏大图上、相邻弹层横向重叠且一级项与弹层间存在物理间隙，鼠标在大图顶部移动/斜切时反复进出 hover，下拉便反复开关，表现为整条导航闪烁。改为：桌面端（≥769px）由极小原生 JS 接管——兄弟菜单互斥、离开后 120ms 延时关闭，穿过间隙或在大图上移动都不再抖动；无 JS 时 CSS `:hover` 仍兜底，移动端静态手风琴完全不受影响。
- **滚动收缩阈值抖动**：顶栏 80→64 的收缩原在 `scrollY>8` 单点切换，顶部惯性滚动跨阈值会反复跳变；改为迟滞（>16 收缩、<8 还原），消除临界点抖动。

### Changed
- `BlockController` 首屏模式白名单与校验由 `A,B` 扩为 `A,B,C`；后台首屏说明文案重写为三模式对比与配图建议（C 取首张、建议 4:3~1:1；B 多张、约 3:1）。

### 验证
- 独立 CDP 实测：打开菜单后移入大图，弹层只关闭一次且横移大图不再弹出（无抖动）；C 桌面 1440 左文案+右图同屏、移动 390 文字在上图在下、按钮整行；B 全幅渲染无回归。
- `php artisan test`：**90 passed / 429 assertions**（v0.9.2 基线 87/418，只增不减）。临时脚本/截图已清理。

## [0.9.2] - 2026-09-15

> 主线：修复首页中部宽幅横幅（及同类 Banner）在桌面端被放大裁切、右侧产品出框的适配问题。

### Fixed
- **Banner 图片与 `aspect-ratio` 冲突导致裁切（系统性，三处统一修）**：`home/mid_banner.blade.php`、`site/_cat_banner.blade.php`、`home/hero.blade.php`（B 模式两张）的 `<img>` 上写死了 HTML 呈现属性 `width/height`，与 CSS `aspect-ratio + object-fit:cover` 冲突，图片在部分加载时序下按固定属性高度渲染，导致 4:1 宽幅被放大、右侧产品被裁。统一移除写死的 `width/height` 属性，响应式高度完全交给 CSS `aspect-ratio`（加载前同样能占位防 CLS）。
- **手机端中部横幅裁切取向**：16:9 框承载 4:1 宽图时改为 `object-position:right center`，优先保住右侧产品主体，优先裁掉左侧留白；图上压字为独立 HTML 层不受影响。

### 验证
- 独立 CDP 在真实页面实测：桌面 1920 下横幅 `1152×288 = 4.00`（与源图 4096×1024 一致，右侧黄盖瓶/麦穗完整、左侧留白与压字正常）；移动 390 下 `479×269 = 16/9`、产品完整、压字清晰。
- `php artisan test`：**87 passed / 418 assertions**，无回归。已清理本轮全部临时排查脚本与截图（仅保留 compile_facts.php）。

## [0.9.1] - 2026-09-15

> 主线：前后台「能力对齐」系统审计与修复——消灭“后台能配、前台不渲染”的死控件/断链投放位；
> 并为所有已支持自定义图片的首页位置生成统一风格品牌插画，经真实存储/媒体库链路挂接演示。

### Added
- **Banner 三个投放位全部接线**：此前后台 Banner 可选「首页顶部 / 首页中部 / 栏目顶部」，但前台只消费首页顶部。
  - 首页中部：新增可装修区块 `mid_banner`（迁移 000016 幂等播种，sort=57，位于四大车间与合作方式之间），新增局部 `home/mid_banner.blade.php`（单张全宽圆角、多张横向 scroll-snap 不自动播、无图不渲染、SSR、支持图上压字与整图链接），`HomeController` 补查 `home_mid`。
  - 栏目顶部：产品中心 / 应用场景 / 知识中心三个列表页统一经新局部 `site/_cat_banner.blade.php` 输出 `category_top` 横幅，三个 Site 控制器补查，无图不占位。
  - 新增 `.midbanner/.mb-*`、`.catbanner/.cb-*` 样式（含 768px 响应式）。
- **测试**：新增 `BannerSlotTest`（5 例：首页中部有图才渲染/停用隐藏；栏目顶部在三个列表页有图才渲染），使用 PHPUnit 12 属性式 DataProvider。
- **样例视觉资产（可后台替换）**：为四大车间、三大能力点生成 7 张统一扁平品牌插画（48px 图标位），为首页中部生成 1 张 4:1 宽幅横幅，全部经 public 盘 + 媒体库真实挂接（区块条目仅补 `image`、不改一字；中部横幅走“无烧字底图 + 图上压字”的正确方式）。

### Fixed
- **后台死控件（系统性）**：区块装修表单按 `config/home_blocks.php` 声明的 `heading/subtitle` 开关渲染——纯数字带 `stats` 与纯横幅 `mid_banner` 不再显示“填了也不生效”的标题/副标题输入（改为说明文案），主体事实 `facts` 只显示标题（前台本就不渲染其副标题）。

### 验证
- `php artisan test`：**87 passed / 418 assertions**（v0.9.0 基线 82/405，只增不减）。
- 8 张样例图 HTTP 200；真实浏览器（bu）确认四大车间/能力点出图、中部横幅压字正常；PC1440 与移动 390 回归；后台 `/admin/blocks`、`/admin/banners` 200 且无异常。临时脚本与下载中间件已清理。

## [0.9.0] - 2026-09-15

> 主线：首屏做成「可运营双模式」（方案 1）——A 价值主张+参数卡（默认）/ B 图片 Banner 轮播，后台一键切换；
> 打通 v0.5 后“后台 Banner 在、查询在、前台没接线”的断链。

### Added
- **首屏双模式**：首页装修「首屏主视觉」区块新增「首屏样式」切换（A 参数卡 / B 图片 Banner）与「B 模式自动轮播」开关，配置存入 hero 区块 content（`mode/autoplay`）。B 模式读取「展示 → Banner 轮播」中投放位置=首页顶部且启用、有图的条目，多图 SSR 全量输出为横向轮播（scroll-snap，无 JS 也可滑动），圆点胶囊切换、悬停暂停、尊重 `prefers-reduced-motion`，自动轮播默认关。
- **智能压字，杜绝叠字**：只有当某张 Banner 填了“图上主标题/副标题”时才在图上压白色标题与 CTA；主副标题留空（图片自带文字）时只出干净大图，有链接则整图可点；首图始终保留唯一 H1（纯图时为仅供读屏/SEO 的 `.sr-only`），多图也只有第一个标题用 H1，保证整页单 H1。
- **永不空首屏**：B 模式下若没有可用（启用且有图）的首页 Banner，自动回退 A 参数卡。
- **测试**：新增 `HeroModeTest`（5 例：默认渲染 A、后台切换并落库、B 有图轮播且单 H1、B 无图回退 A、纯图 Banner 不压字且保留 sr-only H1）。

### Changed
- 复用既有 Banner 直传/媒体库能力与 `HomeController` 已查询的 `$banners`，补齐前台 `home/hero.blade.php` 分支渲染；新增 `.hb-*` 轮播样式（深色渐变压字、深浅图皆清晰的圆点胶囊、移动端 4:3 安全裁切）与 `.sr-only` 无障碍类。

### 验证
- `php artisan test`：**82 passed / 405 assertions**（v0.8 基线 77/386，只增不减）。
- curl + 无头 Chrome 验证 A/B 两态、单图/多图、单 H1、无叠字、PC1440 与移动 390 呈现；后台 `/admin/blocks` 渲染模式选择器与 Banner 管理跳转正常。验证后首屏恢复默认 A，临时数据/脚本已清理。

## [0.8.0] - 2026-09-15

> 主线：补齐后台「自定义图片 + 图标」装修能力（首页条目与文章封面），让首页/知识/新闻可动态换图；
> 系统性复查移动端对比问题；统一无实拍图时的产品卡占位视觉。

### Added
- **首页条目支持自定义图片（优先于内置图标）**：能力点 / 四大车间 / 痛点 / 差异化 / 合作流程等 items 型装修区块，每条可在后台直接上传图片（存 `storage/blocks/Ym`、同步入媒体库、条目 JSON 写 `image`），前台统一经 `site/_feat_media.blade.php` 出口渲染——有图用图、无图回退线性 SVG 图标。后台装修表单新增「自定义图片 / 图标（优先）」列（当前缩略图 + 移除勾选 + 上传），新增行 JS 同步带上传单元。
- **车间 / 流程区块真正可装修**：修复 `HomeController` 此前对 workshops/steps 直接用 Facts 写死、忽略后台保存条目的问题，新增 `workshopItems()/stepItems()`（后台条目优先、缺省回落 Facts），与其它区块一致。
- **知识 / 新闻文章封面图**：`Content` 模型补 `cover()/ogImage()` 关联；内容编辑表单侧栏新增封面上传（存 `storage/covers/Ym`、入媒体库、写 `cover_id`，可勾选移除）；首页知识卡、知识中心列表卡支持 16:9 出血封面，首页新闻卡支持横向缩略图，无封面时保持原纯文字卡、不破图。列表查询预载 category/group/cover，避免 N+1。
- **测试**：新增 `BlockItemImageTest`（3 例：条目图上传落库并前台渲染、旧图保留/勾选移除、车间与流程后台覆盖 Facts）、`ContentCoverTest`（2 例：封面上传建媒体写 cover_id、勾选移除清空）。

### Changed
- 产品卡（产品总览/相关产品/首页产品/场景组合共用 `_product_card`）无实拍图时，由大块灰底空框改为白底圆形品牌字形徽章（绿标 + 极浅暖底 + 轻 hover 放大），避免“破图/未完成”观感；有 `image` 时仍铺满图位。
- **精简全站页脚**：品牌列原每页重复 8 行公司事实（全称/成立/投产/地址/厂区面积/产能/四大车间/七大销售区）收敛为仅法定主体名 + slogan；成立/投产权威出处保留在关于页与发展历程、面积/产能/车间保留在工厂页、销售区在 Organization 结构化数据 areaServed、地址电话保留在页脚“联系我们”列。SEO/GEO 信号不丢失，页脚高度显著下降。sitemap.xml / llms.txt 为机器文件，经 robots.txt 与根路径发现，不进入可见页脚导航（当前也未放入）。

### Fixed
- **内容保存 500（系统性）**：封面上传字段 `cover_file`/`cover_remove` 非数据表列，却随校验数据进入 `fill()` 触发 “no such column” 真实后台报错；在 `hydrate()` 内统一剔除后再 fill。
- 全站深色容器复查：仅 `.is-inverse` 参数表存在“深底→移动白卡”对比缺口（v0.7 已修），页脚、文章底部 CTA、Hero 遮罩在移动端仍保持深底浅字，无同类问题。
- **顶部导航二级菜单 hover 抖动（系统性）**：`.nav-panel` 用 `top:calc(100% + 6px)` 定位，一级项与弹出层之间存在真空区，鼠标从一级项移入面板途中穿过该间隙会瞬间丢失 `:hover`，面板淡出又立即淡入，表现为整层抖动/闪烁。新增透明 hover 桥接带 `.nav>li::after`（覆盖触发项下方 16px 走廊，视觉位置不变），移动端静态展开面板下关闭。CDP 真实鼠标逐 2px 下移采样 68 帧，面板 opacity 全程 1.0、0 帧隐藏。

### 验证
- `php artisan test`：**77 passed / 386 assertions**（v0.7 基线 72/367，只增不减）。
- 运行中服务真实 multipart 上传验证：登录态 PUT 装修表单 → 图片落 `storage/blocks/202609/*.png`、条目 JSON 写 URL、`/storage/...` 返回 200 image/png，验证后已还原区块并清理测试文件。
- 无头 Chrome 移动 600 复查：首页/产品总览/产品详情/场景/合作/知识/联系，参数白卡深字、绿圈步骤、表单、页脚对比均正常，无横向溢出。

## [0.7.0] - 2026-09-15

> 按六份页面规范（全站通用 / 首页线框 / 产品与场景 / 信任与转化 / 内容与公司 / 设计策略）与
> 「Example Website开发交付包」（facts.yaml 权威数据源、copy-global、design tokens、组件、schema、feeds）
> 完成全站 IA 对齐、单一事实源落地、产品/场景三视图、工厂/合作/关于/知识/联系页重建，
> 以及 SEO/GEO feeds 与 Schema 重建；同步修复尾斜杠规范化死循环这一系统性缺陷。

### Added
- **单一事实源（Facts）**：新增 `scripts/compile_facts.php`（可复现构建，含 line/related/scenes/combo 引用一致性校验），由交付包 `facts.yaml`/`copy-global.json` 编译出 `config/facts.php`、`config/copy.php`；新增 `app/Support/Facts.php` 统一访问层（25 个静态方法：公司、品牌语言、五大产品体系、31 款产品、6 场景及组合、四大车间、七销售区、资质就绪判定、合作模式、合规词表等）。结构化事实走 config，编辑型长文走 DB/CMS，边界清晰。
- **产品中心三视图**：`/products/` 总览（五大体系）、`/products/{line}/` 体系页（仅产品数 ≥4 的「Sample SnackSample Marinade（固态调味料）」23 款建独立体系页，其余体系 301 回总览锚点，避免空壳页）、`/products/{slug}` 扁平详情页（仅 6 款核心产品建独立页：Sample Flavor 801、美式/韩式/Sample City鸡架/台式鸡排Sample Marinade、金牌脆鳞Sample Breading；非核心详情 404）。详情含关键参数、标准化使用工艺、适用场景、相邻产品与 FAQ。
- **应用场景重建（/solutions）**：场景总览 + 六个场景详情（P0 Sample Snack创业小店/夜市烧烤摊/连锁快餐外卖，P2 中餐酒楼食堂/轻食健身/卤味烤串），每场景含痛点、推荐产品组合（combo，逐款可解析）、可直接复现的投料/工艺参数、相邻场景；旧 `/scenarios[/{slug}]` 永久 301 到 `/solutions`。
- **信任与转化页**：`/factory/` 工厂与资质（约 9,000㎡/8,000 吨/四大车间/五步生产流程/七大销售区；SC 编号等资质未核齐前「资质与标准」区块整体隐藏，连标题都不渲染；无实拍图时车间卡用统一线性图标、不渲染占位图；CTA 为「预约工厂参观」）；`/cooperation/` 合作方式（定制研发/OEM·ODM/经销三模式 + 五步流程 + 6 条 FAQ + HowTo/FAQPage 结构化数据）。
- **内容与公司页**：关于我们三子页 `/about/profile|history|culture/`（企业简介 7:5 + 9 项事实卡、发展历程时间线、企业文化 2×2）；`/knowledge/` 知识中心与「选料指南/工艺与配方/开店与经营」三栏目（仅 knowledge 分类文章入列，12/页分页）；`/contact/` 联系页（5:7 左事实右独立表单，移动端表单提前）；重写 `errors/404`（真 404 状态码、自包含 6 入口）。
- **URL 尾斜杠规范化中间件**：`CanonicalizeSlash` 按「目录型带斜杠 / 详情型不带」统一 301，规则抽为纯静态方法 `targetFor()` 便于单测；新增 `ExampleUrlGenerator`（保留目录型 URL 尾斜杠，补 query/fragment，详情型与带扩展名路径不动）。
- **测试**：新增 `SolutionPageTest`（10 例，替代旧 ScenarioPageTest）、`Unit\CanonicalizeSlashTest`（5 例纯规则）、`Feature\V07PageRenderTest`（20 例 / 184 断言：14 核心页单 H1、6 核心产品、6 场景、非核心 404、全站合规词扫描、无案例中心、feeds 新 IA）。

### Changed
- **Sitemap/Llms/RSS 全部 Facts 驱动重建**：`sitemap.xml` 收敛为 29 个规范 URL（仅核心产品、仅 ≥4 体系页、仅 knowledge 文章，剔除 /scenarios、/cases）；`llms.txt` 重写为核心事实/五大体系/6 核心产品关键参数/6 场景组合/合作五步/知识三栏目，剔除案例；`robots.txt` 补 Disallow（/admin//api//search 等）与 DeepSeekBot/元宝/阿里云/月之暗面/ChatGLM/Claude-Web 等 AI 爬虫分组；RSS 收敛为 knowledge/news 分类文章。
- **Schema 补全**：Organization 补 legalName/foundingDate(2017-03)/areaServed 七区/contactPoint；全站 9 类页型 JSON-LD（Org+WebSite+FAQPage、Product+HowTo+FAQPage+Breadcrumb、场景 FAQPage、合作 HowTo、联系 LocalBusiness geo 留空、文章 Article、列表 ItemList），与页面实际内容一致、无虚假结构化数据。
- 首页 S01–S10 与案例局部按终稿重写并校准；案例中心按决策不建（无 /cases 路由、不进导航、不进 sitemap/llms），首页仅保留 3 张匿名、无引述、无详情入口的合作剪影卡。
- 移动端补强：场景推荐组合在 ≤600 由两列改单列横向卡（图标左、文案右，消除 1:1 大图位留白）；联系页表单在移动端提到信息之前；参数表继续在移动端转键值卡，全站无横向溢出。
- 合规清扫：facts.yaml「接受度最广」→「大众接受度高」并重编译；合作页「降到最低」→「尽量降低」；合作 FAQ「区域独家」→「区域保护」；全站前台绝对化用语与竞品名零命中（测试锁定）。

### Fixed
- **尾斜杠 301 死循环（系统性 P1）**：原 `redirect()` 经 Laravel UrlGenerator 被 `trim($path,'/')` 剥掉尾斜杠，「补斜杠」301 跳回无斜杠自身形成无限重定向（curl -L 实测 8 跳仍 301），且所有目录型内部链接多一跳。改为手工拼 `scheme://host/target` 的 Symfony Response 直发 Location，并由 `ExampleUrlGenerator` 让内部链接直接输出规范地址（零跳）。另处理了 Laravel 测试客户端 `trim(url($uri),'/')` 剥尾斜杠导致的测试自跳（测试环境跳过 301，规则正确性由纯方法单测 + 生产 curl 双重锁定）。
- 企业文化页引导文案「三个车间」更正为「四大车间」，与事实口径一致。
- **移动端反白区参数表白底浅字看不清（系统性）**：`.is-inverse` 深色区把参数文字设为浅色，移动端 ≤600 参数行转为白卡片后未覆盖回深色，导致白底浅字。统一在移动端媒体查询内把反白区参数卡的标签/值/注释覆盖回中性深色并左对齐；同一规则同时修复首页 S04 与六个场景详情（共用 `_param_table` + `.is-inverse`）。

### Removed
- 删除已被 Facts/Solutions 完全取代的死代码：`ScenarioController`、`site/scenarios/{index,show}.blade`、`config/scenarios.php`、`config/process_params.php`、`config/cooperation.php`（旧 /scenarios 兼容 301 改由路由闭包承担，零死链）。

### 验证
- `php artisan test`：**72 passed / 367 assertions**（v0.6 基线 52/183，只增不减）。
- 全站 17 类路由 curl 实测：13 个前台页 + sitemap/robots/llms 全 200，旧 /scenarios 301 到 /solutions/；目录型零跳带斜杠、详情型零跳不带斜杠。
- 无头 Chrome 视觉回归：桌面 1440（首页/产品/场景/工厂/合作/关于三页/知识/联系）与移动 600、511（首页/场景/产品列表/产品详情/工厂/联系）截图核对，单 H1、无横向溢出、反白区 ≤3、CTA 与表单可用、统计数字 data-count 终值 20/9000/8000/4/7 正确（截图定格为滚动动画过程，非缺陷）。

## [0.6.0] - 2026-09-15

> 按《Example Website设计策略建议书 v1.0》系统级落地：VI 标准色校正、主 CTA 红改绿、
> 暖米底改中性阶、信息架构按客户生意类型分诊、首页重排为 S01–S10 叙事、新增参数级交付组件、合规清扫。

### Added
- **应用场景中心（按客户生意类型分诊）**：新增 `/scenarios` 总览与六个场景详情页（Sample Snack创业小店 / 夜市烧烤摊 / 连锁快餐外卖 / 中餐酒楼食堂 / 轻食健身渠道 / 卤味烤串店），数据驱动于 `config/scenarios.php`；每类含适用对象、常见痛点、推荐产品组合、真实投料配比与工艺参数、相关产品线、上一/下一场景。
- **合作方式页 `/cooperation`**：定制研发 / OEM·ODM 代工 / 原料供应经销三种模式 + 五步合作流程 + 五条 FAQ（输出 FAQPage JSON-LD）+ 侧栏行动卡。
- **参数级交付体系**：`config/process_params.php` 落七条真实工艺参数（事实母稿 6.2，逐条可核、无他方对标）；新增 `ParamTable`（响应式，≤600 自动转键值卡）与 Hero 右侧 `ParamCard`（Sample Flavor 801 标准化配比示例 + 五大产品线 chip）。
- 首页新增四个可装修区块（迁移 `000013`，幂等）：`scenes`(S02 场景自选)、`params`(S04 参数级交付)、`cooperation`(S06 合作方式)、`faqs`(S09 首页 FAQ，items 型，后台可改问答并输出 FAQPage 结构化数据)。
- 新增 `ScenarioController` / `CooperationController`、`SchemaBuilder::faqPageFromList()`；sitemap.xml 收录场景七页与合作页，llms.txt 增「应用场景（按客户生意类型）」段。
- 新增回归测试 `ScenarioPageTest`（7 用例 / 37 断言）：场景总览、六页单 H1、未知场景 404、参数无竞对词、合作页 FAQ schema、sitemap 新路由、首页场景/参数段与绿 CTA。

### Changed
- **VI / Token 校正（A 级标准）**：品牌红 `#D70E18`（hover `#B50C15`/active `#9C0A12`）、鲜萃绿 `#00943F`，新增主 CTA 绿 `#007A33`；暖米底整体替换为中性阶（#FFF / #F5F5F5 / #EDEDED / 墨黑文字阶 / 发丝线阶）；圆角收敛为 4/6/8/12（禁 >16），阴影压扁为三级轻阴影，动效统一 cubic-bezier(.2,0,.2,1) 100/160/240ms。CSS `:root` 默认与 `settings` 表双写一致（迁移 `000013`）。
- **主 CTA 由红改绿**：`.btn-primary`、表单提交、Header 主 CTA、分页/焦点环统一走 `--cta` 绿；红只保留品牌强调与错误态，同页红绿不并列；底部 CTA 色带由深红渐变改墨黑反白收口。后台 VI 色同步（登录主按钮保留品牌红）。
- **导航信息架构重构**：按客户认知顺序组装为 产品中心 → 应用场景 → 合作方式 → 工厂与资质（聚合工厂/资质，URL 不变零死链）→ 知识中心 → 新闻动态 → 关于我们 → 联系我们；Header 与 Footer 统一由 `AppServiceProvider::mainMenu()` 驱动（缓存键 `main.menu`，与 `nav.tree` 同步清理），Footer 重构为品牌/产品体系/应用场景/合作与联系四列。
- **首页重排为 S01–S10**：Hero → 场景自选 → 我们是谁 → 产品体系 → 参数级交付 → 数据 → 四大车间 → 合作方式 → 五步流程 → 知识 → 新闻 → 墨黑 CTA → FAQ → 主体事实；区块底色按白/浅交替形成节奏。Hero 重写为「眉题 + 两行 H1 + A 级事实支撑 + 绿主/中性次/电话 + 右侧 ParamCard」。
- 全站 CTA 文案价值化并去除泛化词（免费索取样品 / 获取定制方案 / 看看这类店用什么 / 获取产品规格表 / 查看产品 / 进入知识中心 / 预约工厂参观）。
- 术语统一（迁移 `000014`，幂等）：公开内容与事实库的「鸡肉半成品 / 半成品 / 预制鸡肉」统一为「调理鸡肉」，与 SC 类别口径一致。

### Removed
- 首页旧 `problems`（痛点对照）、`differentiators`（差异化）区块默认收起（数据保留、后台可再开；其前三条要点并入 S04 参数级交付左侧）。
- 清理暖米硬编码（#FBFAF8 等）、pcard/facts/footer 暖色与残留旧 CTA 措辞「查看详情」。

### 验证
- `php artisan test`：**44 passed / 154 assertions**（v0.5 基线 37/117，只增不减）。
- 关键路由实测 200：/、/scenarios、六个场景、/cooperation、/products、/about/factory；首页单 H1、场景卡 6、参数表 1、合作三列、FAQ 5 条且 FAQPage JSON-LD=1、导航 8 项顺序正确、旧暖色 0 命中。
- sitemap.xml 含场景 7 条 + 合作页；llms.txt 含应用场景段。
- 桌面 1440 与移动 600（命中 ≤600 断点）截图回归：Hero/场景总览/场景详情/合作页/首页全段正常，ParamTable 移动端转键值卡、SceneCard 移动端默认展开参数、主 CTA 移动端全宽、无横向溢出。

## [0.5.0] - 2026-09-15

### Added
- **首页叙事升级为「认知→问题→产品→能力→差异化→流程→信任→行动」**，新增两个可装修区块（迁移 `000012`，幂等）：
  - `problems(sort35)`「客户痛点 → 解决方案」：4 组痛点与Example解法对照（风味漂移/研发慢/量产掉链/合规溯源），kind=items，后台可增删改与选图标。
  - `differentiators(sort55)`「为什么选择Example，而不是通货拌料」：4 条编号差异化点（定向研发/打样到量产一体/五大体系协同/稳定批次溯源），同样可装修。
- Hero 右侧改为 **A 方案中性产品体系能力面板**（`.hero-panel`）：五大产品线图标+名称（点击进栏目）+ 底部「20年/四大车间/OEM·ODM」锚点，替代原红色营销海报，不使用占位假图；H1 更锋利、lead 明确「为谁/解决什么/结果」，主 CTA 改价值化「获取定制方案与样品」。
- 四大车间段升级为**重音段**：浅底非对称、放大车间卡，左侧新增 9000㎡/8000吨 关键数据条（数字只取自已核定事实）。
- 统一图标库新增 `arrow`（指向箭头，含后台白名单）。

### Changed
- 区块底色按「米→浅→米」交替重排，形成重—轻节奏：products 改回中性底，problems/workshops/steps/knowledge 用浅面，避免相邻同色。
- CTA 文案全站价值化：能力段「看研发与工厂实力 / 获取定制方案·打样」、底部 CTA「获取定制方案与样品」，去除泛化「了解Example」。
- Hero 去红色径向光晕，整体更克制；产品面板/问题对照/差异化均补齐平板与 ≤600 移动端响应式（单列、数据条换行）。

### 验证
- `php artisan test`：37 passed / 117 assertions（HomeBuilderTest 覆盖区块机制，新增区块不破坏既有）。
- sitemap 28 URL 全部 200/301；首页单 H1，真实知识文章 JSON-LD=4；后台「首页装修」200 且两个新区块条目编辑器正常渲染；区块顺序 hero>stats>capabilities>problems>products>workshops>differentiators>steps>facts>knowledge>news>cta。
- 桌面 1440 与移动 600 截图回归：Hero 面板、问题对照、车间重音、差异化堆叠正常，无横向溢出。

## [0.4.0] - 2026-09-15

### Added
- **全站视觉体系系统级升级（表现层重构，不改路由/数据模型/CMS/GEO）**：补齐暖调中性色阶（surface-2/3、ink-2/faint、line-soft、footer 色、warning）、8 点间距令牌、分级圆角（6/8/12/16/999）、两级轻阴影、150/220/320ms 动效令牌与完整字阶（Display/H1/H2/H3/Body-L/Label/大数字 tabular-nums）。
- 首页由 10 个等宽模块重组为 S1–S7 认知故事线：分栏 Hero（去红铺底，左价值主张 + 双 CTA + 绿色信任项，右产品视觉圆角面板）、浅色大数字信任行、价值主张无边框结构行、产品**主次网格**（首条横向主打 + 其余规整）、四大车间图文非对称、描边数字流程、资质与产能精选（首页 8 条、全量在关于页）、知识浅面卡、深红克制 CTA。
- 新增轻入场动效：单一原生 IntersectionObserver 驱动 `.reveal` 与数字滚动；**渐进增强**（仅 `html.js` 下初始隐藏，无 JS/异常/减少动态偏好时内容照常可见）。
- 新增 `.prose` 正文排版与 `.pagination/.page-link` 分页样式；`AppServiceProvider` 注册 `Paginator::useBootstrapFive()`，让分页套用统一设计系统（原默认 Tailwind 标记在本站无样式）。
- Header 加「配方定制 / 打样」主 CTA、电话改中性幽灵按钮；去红绿顶条、毛玻璃吸顶、当前态细指示。

### Changed
- 按钮由「主次都实心红」改为**四级体系**：`.btn` 实心红主按钮（每屏唯一主行动）、`.btn-o` 白底中性描边次按钮、`.btn-ghost` 深底幽灵、`.btn-text` 文字按钮（区块「查看全部」）；复用现类名，模板改动最小。
- 全站去盒子化：卡片默认浅面 + 极浅发丝线（`--line-soft`），静态无重影，仅可点击卡 hover 统一微抬 + 轻阴影；能力点去外框改发丝分隔行；步骤数字由实心红块改描边圆；结论块 `.answer` 由左红边盒改浅面 + 顶部细品牌线；信任带由红色渐变改浅色大数字行。
- 标题字重由一律 800 收为 600/700；正文上调至 16/1.75；Section 纵向节奏加大到 96/64/48；`.eyebrow` 改 12px 大字距标签；CTA 色带收敛为深红。
- 首页 Hero 标题回退逻辑：区块/Banner 标题为空或仅为公司名时使用价值主张，后台真正自定义标题照常生效。

### 验证
- `php artisan test`：37 passed / 117 assertions（基线只增不减）。
- sitemap 28 URL：23 直接 200、5 个父栏目 301 跳首个子栏目后均 200（既有设计）；robots/llms/feed/sitemap/搜索/404 正常；核心页每页单 H1，文章页 JSON-LD 4 个完整。
- 桌面 1440 与移动 600（命中 ≤600 断点）截图回归：Hero/产品/知识/联系/首页故事线视觉统一，无横向溢出。



### Fixed
- 修复后台「内容管理」`/admin/contents`（不带 tab）与「站点设置」`/admin/settings`（不带 group）返回 500 的缺陷：控制器方法把可选标量参数排在 `Request` 之前，缺段时参数数量不足。统一改为 `Request` 在前、可选参数带默认值；全站控制器排查无同类隐患，并新增 2 个回归测试锁定。
- 产品叶子栏目（五条产品线页）的系列内容由朴素 `.post` 列表改为与产品中心一致的图标富卡片 `.pcard`，消除视觉断层；知识/新闻文章列表维持原时间线样式不变。

### 审计
- 前台 28 个公开 URL 全部 200、每页单 H1、无 Whoops/SQL 报错；空栏目（新闻）、搜索有/无结果、产品系列详情、联系表单空态均正常。
- 后台全部 GET 页面（仪表盘/内容/栏目/分组/菜单/Banner/首页装修/媒体库/事实库/留言/301/6 组设置/GEO 工具与预览）逐一登录态核验全部 200。
- 留言闭环 8 项测试通过（存储、手机号校验、蜜罐、来源与设备归因、首触 UTM、后台鉴权、标记处理）；sitemap.xml(28)/llms.txt/robots.txt/feed.xml 全部 200，首页与 FAQ 内容页 JSON-LD 类型正确。
- 移动端在 ≤600 断点核验：汉堡菜单、信任带 2×2、产品/能力/车间/流程单列、表单单列，无横向溢出。

## [0.3.0] - 2026-09-14

### Added
- **首页整体装修系统**：首页不再写死，按 `page_blocks.sort` 动态渲染区块；新增 stats/capabilities/workshops/steps 区块（迁移 `000011`，幂等灌入默认条目）。
- 后台「首页装修」升级：区块开关/排序/标题副标题；能力点、四大车间、合作流程支持增删条目并从统一图标库选图标；产品/知识/新闻支持选来源栏目、条数或手动指定具体文章与顺序。
- `config/home_blocks.php` 区块类型注册表；`PageBlock::items()/pickedIds()/kind()/typeLabel()` 配置解析；首页区块局部 `resources/views/site/home/*.blade.php`。
- 新增 `HomeBuilderTest`（区块显隐、条目编辑、手动选文章、排序驱动）5 个用例。

### Changed
- 统一可点击卡片悬停反馈：静态保持扁平，悬停时产品卡/知识卡/文章卡统一轻微上浮 + 柔和动态阴影（`--shadow-hover`）作为选中反馈。
- 修复次级按钮只写 `btn-o`、未继承按钮基础形态导致像"文字加框"的问题：按钮基础形态改为 `.btn/.btn-o/.btn-ghost` 共享；主、次按钮统一为实心红、白字、同尺寸（含区块右上"全部…"按钮）。全站按钮审计：首页/栏目/内容/联系/搜索/错误页逐一核对，无其它缺底座按钮；幽灵按钮仅用于 Banner/红带深底；错误页冗余双类名精简。

### Removed
- 移除产品卡悬停时从左侧冒出的红色竖条，改为全站统一的动态阴影反馈。

### Database
- 新增迁移 `2026_09_14_000011_seed_home_builder_blocks.php`（幂等，可回滚）。

## [0.2.1] - 2026-09-14

### Changed
- 全站扁平化：阴影令牌统一置 `none`，去掉卡片/按钮/步骤数字的投影与悬停上浮，悬停仅改描边；保留焦点环与首屏文字压暗。
- 次级按钮 `.btn-o` 由裸描边改为浅红实底；按钮在任何状态都不再出现下划线。
- 产品卡 `.pcard` 改为「图标与标题同一行」的紧凑横向排版，降低卡片高度、消除右侧留白（首页与栏目页一致）。
- 事实表分隔线改用更浅的 `--line-soft #F1ECE5`。

### Added
- 全站唯一线性图标库 `site/_icon.blade.php` + `config/icons.php` 选项表；能力点、四大车间、产品系列图标全部替换为统一专业图标。
- 栏目可在后台「栏目结构 → 编辑 → 前台图标」从图标库选择图标（迁移 `000010` 给 categories 增 `icon`，服务端白名单校验，留空按 slug 回退）；五条产品线已显式配置图标。

### Fixed
- 描边按钮 hover 出现下划线、步骤数字红色光晕、能力点对勾图标过于简陋等问题。

### Database
- 新增迁移 `2026_09_14_000010_add_icon_to_categories_table`（categories 增 `icon`，幂等、可回滚）。

## [0.2.0] - 2026-09-14

### Added
- 全站统一 Design System：设计令牌（色板/间距/圆角/三级阴影/字号层级）、统一按钮/卡片/表单/标题/FAQ/CTA 组件，见 `docs/DESIGN_SYSTEM.md`。
- 首页视觉与信息层级重构：首屏、全幅红色信任数据带（20 年 / 9,000㎡ / 8,000 吨 / 七大区，数字滚动增长、终值可被 SEO/GEO 读取）、价值主张、五大产品体系图标卡、四大车间核心能力、四步合作流程、知识卡、红色 CTA 色带、深色页脚。
- 内页统一 `.page-head` 页头；栏目页产品系列使用差异化图标；纯父栏目不再显示多余「暂无内容」。
- 留言来源归因：新增迁移 `000009`（landing_url/referer/utm_source/utm_medium/utm_campaign/utm_term/utm_content/device_type），`CaptureAttribution` 中间件记录首次落地页、外部来源与 UTM，表单隐藏字段带回，服务端按 UA 解析设备；后台留言列表展示来源与设备。
- 图片 `lazy-load`/`fetchpriority`/`decoding` 优化；响应式覆盖 1920/1440/1280/1024/768/430/390/375，无横向溢出。

### Changed
- 共享布局 `layouts/site.blade.php` 重写为统一设计系统层；首页/栏目/内容/搜索视图全部接入。
- 产品图标抽取为唯一局部 `site/_product_icon.blade.php`，首页与栏目页共用。
- 内容页底部非联系页统一为 CTA 色带；FAQ 改为统一折叠样式。

### Fixed
- 页脚 logo 反白成白块的问题，改为使用原品牌标识。

### Database
- 新增迁移 `2026_09_14_000009_add_attribution_to_inquiries_table`（inquiries 增 8 列，可回滚）。

### Test
- 测试由 27 增至 **30 个 / 95 断言**：新增留言归因落库、首次触达中间件、UA 设备解析 3 个用例，全量通过。

## [0.1.0] - 2026-09-14
- 初版：Laravel 12 + Blade SSR + SQLite 骨架，后台 CMS、ContentGate、GEO（JSON-LD/sitemap/llms/robots/feed）、GEOFlow 预留接口、本地留言闭环、品牌色统一、Banner 直传与首屏渲染、移动端适配。
