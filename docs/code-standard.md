# GEO Website OS Code Standard

> 适用：GEO Website OS 全部 PHP / Laravel / Blade / SQL / JavaScript 代码与测试。
> 目标：通用、可多站部署、无客户业务污染、可测试、可回滚。本文与现有架构保持一致；新增代码必须遵守，修改旧代码时就近收敛。

---

## 1. 总原则

1. **产品中立**：核心 Runtime 不包含任何真实客户的公司名、品牌、人名、电话、地址、域名、产品名、行业工艺词。开箱数据只来自中性 Example 数据集。
2. **数据驱动，不硬编码业务**：文案、事实、导航、默认区块来自配置 / 数据库 / Setting，并可被站点覆盖；代码里不写死具体业务值。
3. **多站隔离是默认前提**：一切站点数据模型默认受 SiteScope 约束，跨站访问必须显式且经授权（见 §5）。
4. **单一事实来源**：SEO/GEO/Schema/LLM/Sitemap 从统一的 Site / Content / Entity / EntityRelation / SeoMeta / Media 解析，不各自维护一套数据。
5. **不破坏已验收能力**：不为重构而重构；Migration 历史、升级 / 回滚链、安全断言必须保留。
6. **测试是门禁**：不得删测试、降断言、跳过测试换取绿色；新增能力必须带测试。

## 2. 技术栈与分层

- PHP 8.4+、Laravel 12、Blade SSR、SQLite（开发默认）/ 兼容其他 Laravel 支持的数据库；前端 Tailwind v4（Vite）+ 极简原生 JS。
- 推荐分层（依赖方向自上而下，不反向依赖）：
  - `app/Http/Controllers/Site/*`：前台只读展示，调用 Resolver / Repository / Support，不写跨站查询。
  - `app/Http/Controllers/Admin/*`：后台管理，受管理员鉴权与 SystemAuthorization 约束。
  - `app/Services/*`：领域服务——`Seo/*`（SeoMetaResolver、UrlResolverInterface/GenericUrlResolver）、`Geo/*`（SchemaBuilder、LlmsBuilder、SitemapBuilder、GeoGraphBuilder）、`Gate/*`（ContentGate 等）、Installer / Upgrade / Backup。
  - `app/Repositories/*`：如 EntityRepository，封装实体 / 关系查询，内部仍受 SiteScope。
  - `app/Models/*`：Eloquent 模型与关系、作用域、cast。
  - `app/Support/*`：SiteContext、SiteScope、BelongsToSite、SiteResolver、SiteCacheKey、Copy、Facts（过渡读取器）、Narrative、SystemAuthorization 等横切能力。
  - `app/Console/Commands/*`：`geo:install`、`geo:upgrade`、`geo:version` 等 CLI。
- Controller 保持薄：接收请求 → 调服务/仓库 → 传规范化数据给视图；不把 SQL / 业务事实 / SEO 拼接堆在 Controller 或 Blade。

## 3. 命名

- 类 PascalCase、方法/变量 camelCase、常量 UPPER_SNAKE；表名复数 snake_case、外键 `snake_id`、迁移 `yyyy_mm_dd_hhmmss_verb_subject.php`。
- 命名表达**通用领域概念**：Site、Content、Entity、EntityRelation、SeoMeta、Media、Organization、Product、Service、Location、Person、Topic。
- 禁止客户 / 行业命名（类、方法、配置键、路由、文件名、命名空间、变量）：不得出现任何具体客户品牌名、客户产品名或行业工艺名（形如 `<client-brand>*`、`<client-product>*`、`<industry-process>*` 的命名都不允许）。
- 遗留命名（如 Facts、HomeBlockDefaults 等过渡读取器）允许在其生命周期内保留，但必须在类 docblock 注明"过渡层 / 后续由 Entity/Content 取代"，不得在新代码扩散引用。
- 路由使用语义化、通用的路径（`/products/{slug}`、`/services/{slug}`、`/solutions/{slug}`、`/cases/{slug}`、`/knowledge/{slug}`），URL 一律经 UrlResolver 生成。

## 4. 模型、数据与迁移

- 站点数据模型使用 `BelongsToSite` trait：自动 Global SiteScope、`creating` 时绑定当前 Site、提供 `withSite()` 上下文与显式 `withoutSiteScope()` 系统能力。
- 固定核心字段（id、site_id、type、slug、name、status、时间戳等）与可扩展 `metadata`（JSON object）分离；**不得把核心字段塞进 metadata，也不得让 metadata 覆盖核心字段**。
- Entity 类型限定 6 类：organization / person / product / service / location / topic；禁止新增 solution/place/brand 等类型。销售覆盖区域写入 `organization.metadata.area_served`，不建 Location 实体。
- EntityRelation：四元唯一 `(site_id, from_entity_id, to_entity_id, relation_type)`；**不冗余 from_type/to_type**，类型由 entities.id 解析；双向 FK 级联、Site FK RESTRICT；允许自引用（当前架构决策）。
- SeoMeta 三态绑定（Site / Content / Entity）由**表级 CHECK 约束** + 3 个 partial unique index 保证，不用 trigger 模拟；FK 全 CASCADE。
- Content 与 Entity 是**平行资源**：contents 无 entity_id，Content SEO 不回退读取 Entity；任何 Content→Entity 解析必须基于正式 Relation 并经独立架构阶段批准。`test_content_does_not_fallback_to_related_entity` 是正式防回归测试，必须保留。
- Migration 规则：
  - schema migration（建表 / 列 / FK / 索引 / 唯一 / CHECK）一经发布不改写其语义；变更用新迁移。
  - data migration 必须**幂等**（firstOrCreate / updateOrCreate），可重复执行、不产生重复数据；中性化内置种子，不含客户数据。
  - 每个迁移有正确 `up()` / `down()`，保证 migrate → rollback → re-migrate 可逆；SQLite 开启外键（`PRAGMA foreign_keys=ON`）。
  - 不为了"全仓零关键词"篡改历史 migration；仅旧库升级执行、fresh 空表零行的迁移登记为 Upgrade Exemption。
- 查询用参数绑定 / Eloquent，禁止字符串拼接 SQL 值；避免 N+1（用 eager loading / 批量加载）。

## 5. 多站隔离与授权（强约束）

- 默认所有站点数据查询都在当前 SiteContext 内；即使显式 `where('site_id', $other)` 也不能绕过 Global Scope。
- 跨站 / 无站作用域能力 `withoutSiteScope()` 是**显式系统级技术能力**，不是业务权限：
  - 仅 System / 授权 Admin（按 `is_super_admin`，邮箱本身不授权）、CLI、Queue 在显式恢复的 SiteContext 中使用；
  - 普通 Controller / Service / Blade 不得调用；新增调用点必须经 SystemAuthorization 并加测试。
- CLI 通过 `--site / --site-id` 显式指定站点，未指定 / 无效站点必须明确失败，不允许隐式串站。
- Queue Job 序列化 `site_id`，执行前恢复 SiteContext、`finally` 中清理；异常路径也必须清理。
- **禁止**任何全局关闭 Site Scope 的开关或环境变量（如 `GLOBAL_DISABLE_SITE_SCOPE`）。
- 缓存键必须站点感知（SiteCacheKey），多站往复切换（A→B→A→B）不得串缓存、串主题、串设置、串 SEO。
- 系统内部安全检查（如 Group 跨站校验）保留并显式标注用途。

## 6. SEO / GEO / URL

- SEO 解析走 SeoMetaResolver，按资源类型独立的字段继承链（详见 SeoMeta 架构文档）；canonical 走 UrlResolverInterface（https、无 query、首页带斜杠、其余不带、不跨站）。
- `contents.canonical` 等旧字段仅为迁移期 legacy 来源，不是正式继承层。
- OG Image 链：SeoMeta → Content 媒体（og_image_id / cover_id → Media.path）→ Entity metadata.og_image → Site logo → null；不新增持久化图片字段。
- SchemaBuilder / LlmsBuilder / SitemapBuilder 只依赖通用数据源（Site/Content/Entity/Relation/SeoMeta/Media），不读客户 Facts 硬编码。
- 旧 Facts 读取器 / 旧 URL 生成器是过渡兼容层，新代码面向 Entity/Content 与 UrlResolverInterface；替换时保留回归对拍，不复制旧逻辑到新类。

## 7. PHP / Laravel 风格

- 遵循 PSR-12 + Laravel 惯例；严格类型优先：新文件 `declare(strict_types=1)`），方法参数与返回值尽量类型化。
- 方法短小、单一职责；私有辅助方法表达清晰意图，避免深嵌套与超长方法。
- 空值与缺失显式处理（nullsafe / `??` 安全默认），数据缺失优雅降级，不回退客户兜底值。
- 不使用 `dd/ddd/dump/var_dump/print_r/echo` 调试残留；不保留注释掉的代码块。
- 配置走 `config/` 与 `.env` 读取；`.env` 不入库，`.env.example` 只放中性占位，不含真实密钥 / 账号 / 客户信息。
- 服务容器绑定 / 单例无状态或按请求 / 站点隔离；静态属性、memo、单例缓存必须在请求结束 / 上下文切换时可清理，杜绝状态泄漏。

## 8. 注释与 DocBlock

- 注释解释**为什么**，不复述"做了什么"；过时 / 错误注释一律删除或更新（宁可无注释，不留错误注释）。
- 类 / 复杂方法写 DocBlock：用途、参数、返回、副作用（如切换 SiteContext、写缓存）。
- 过渡层 / 技术债用统一格式标注：`// TODO(tech-debt): 由 X 取代，计划于某阶段移除`，并在审计文档登记；不写客户名、不写无主 TODO。
- 注释中不出现客户公司名、品牌、行业专名，也不引用任何外部企业的设计体系名称作为标准；产品自有规范统一称 "GEO Website OS Design System"。

## 9. 异常与日志

- 面向用户的错误返回通用、中性信息与正确状态码（403/404/422/429/500），**不向页面泄漏堆栈、路径、SQL、客户信息**；debug 细节仅在非生产日志。
- 业务校验失败用 ValidationException / 422 回填；权限失败 403；资源缺失 404；限流 429。
- 日志分级合理（error/warning/info），上下文充分但不记录密码、密钥、个人敏感信息；日志消息通用、不含客户名。
- 捕获异常要处理或转译，不吞异常；`finally` 用于清理 SiteContext / 锁 / 临时状态。
- 安装 / 升级 / 备份失败必须可回滚并给出可操作的中性错误提示。

## 10. 前端（Blade / JS / CSS）

- Blade / HTML 遵循 `docs/html-standard.md`，CSS 遵循 `docs/css-standard.md` 与 `docs/design-system.md`。
- JS 保持轻量：`resources/js/app.js` 经 Vite 构建，axios 引导统一带 `X-Requested-With` 与 CSRF；页面交互用原生脚本 + 事件委托 / `data-*` 钩子，不内联事件。
- JS 同样遵守多站隔离：不缓存跨请求 / 跨站可变数据；任何站点相关字符串由后端注入，不写死客户信息。
- 不引入客户专属第三方脚本 / 统计 / 客服 / 字体。

## 11. 测试

- 使用 PHPUnit + Laravel Feature/Unit 测试；数据库测试统一 `RefreshDatabase` 并按需 `$this->seed()`（DatabaseSeeder → DemoSeeder 提供中性 Example）。
- 全量回归以项目根 PHPUnit 直跑为准（CI 同构）：
  - Windows：`php vendor/bin/phpunit`；要求 Failed=0、Skipped=0，断言数与测试数只增不减（有合理解释除外）。
- 必须覆盖并长期保留：
  - 多站隔离（读 / 写 / 删 / 关系 / 搜索 / 缓存 / 上下文切换与异常清理）；
  - Entity / Relation / SeoMeta 的约束、三态、CHECK / 唯一索引、跨站拒绝；
  - SEO 字段继承链、canonical 规则、OG 链、Content 不回退 Entity；
  - 安装 / 升级 / 回滚、主题 / 插件、GEO/Schema/llms/sitemap；
  - **反向业务污染断言**（BusinessPollutionZero、ExampleDatasetIntegrity、FreshBoot、GeoInstall 等）：这些断言以客户词作为"不得出现"的反向密钥，是防回归资产，必须保留。
- 测试 fixture 一律通用（Example Organization / Product / Service、跨行业中性占位），不使用真实客户数据；正向数据中不得出现客户 / 食品行业专名。
- 一个行为一个清晰断言意图；测试名描述行为与期望；不依赖测试执行顺序、不依赖全局可变状态。

## 12. 提交与版本

- 提交信息 Conventional Commits：`feat / fix / refactor / test / docs / chore(scope): ...`，scope 用语义模块（sanitize、seo、geo、installer…）。
- 每个 checkpoint 单独提交并打 annotated tag（如 `checkpoint-sanitize`）；提交前 working tree clean、全量回归通过。
- release-manifest 的 version / commit / tag / checksum 必须与实际产物一致（由 CI 动态生成，避免自引用 hash）。
- 不在仓库提交：`.env`、`vendor/`、`node_modules/`、`public/build`、`*.sqlite/*.db`、客户数据 / 备份 / 截图、一次性脚本（项目根 `_*`）。

## 13. 评审检查单

1. 是否引入任何客户 / 行业硬编码（名称、域名、电话、地址、产品、工艺）？
2. 站点数据是否默认受 SiteScope？有无新增未授权 `withoutSiteScope` / 原生表查询绕过点？
3. 数据是否数据驱动、缺失优雅降级、无客户兜底？
4. Migration 是否幂等、可回滚、约束（FK / 唯一 / CHECK）在数据库层落实？
5. SEO/GEO 是否走统一数据源与 Resolver，未复制旧逻辑？
6. 类型 / 命名 / 注释 / 异常 / 日志是否符合规范且无敏感泄漏？
7. 是否新增 / 保留了对应测试（含反向污染断言），全量回归 0 failed / 0 skipped？
8. 是否误删了 Migration / 升级 / 回滚 / 兼容代码？
