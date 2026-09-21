# Admin Management Completion Design —— 后台管理面补全设计（P-STEP 17 蓝图）

> 文档性质：**只设计、不写代码**。本文是 P-STEP 16A 产品 UAT 后，为补齐"控制面板未产品化"缺口而产出的目标架构与分阶段施工蓝图，作为 **P-STEP 17A–17G** 的依据。
> 所有设计遵循已冻结的架构契约（5.6-B/C、P-STEP 14 Runtime Closure）与自有 **GEO Website OS Design System**，不引用任何第三方设计体系名。
> 日期：2026-09-21｜基线：`v1.0.0-rc1`=`965d63c` + 16A Bug#1–#6 修复（未提交）｜UAT 证据见 `docs/audit/product-uat-final.md`。

---

## 1. 现状结论（来自 16A 实测，不臆测）

引擎 / Runtime 已产品化（安装、升级、回滚、多站隔离、SEO/GEO/Schema、主题 / 插件引擎、Catalog 读模型均有自动化与 HTTP 证据），但**控制面板只覆盖了"内容生产"侧，没有覆盖"站点 / 实体 / SEO 覆盖 / 主题 / 插件 / 大部分设置"**。

| 能力域 | Runtime / 引擎 | 后台 UI（16A 实测） |
|---|---|---|
| Site 多站基础 | 有（SiteResolver / SiteContext / SiteScope） | **无**（`/admin/sites` 404，F5） |
| Entity / EntityRelation | 有（模型、Catalog 投影、跨站保存守卫、GEO 实体图） | **无**（`/admin/entities` 404，F6） |
| SeoMeta 三态绑定 | 有（表级 CHECK、三 partial unique index、Resolver） | **无**（`/admin/seo-metas` 404，F8） |
| Theme 引擎 | 有（ThemeManager、per-site `theme_active`、覆盖 + 回退） | **无切换 UI**（仅 9 个主题色 token 可改，F9/F3） |
| Plugin 引擎 | 有（注册 / 授权分离、EnsurePluginEnabled per-site 守卫） | **无管理 UI**（F9） |
| Settings | 键值引擎完整（7 组） | 仅 general 组 10 行可编辑，其余 6 组 0 字段（F7） |
| Content / Category / Group / Media / Menu / Block / Narrative / Fact / Inquiry / Redirect | 有 | **有且基本成熟**（16A 已全走查，修了 6 个 Bug） |

另两个必须在补全中一并收口的系统性问题：
- **双轨断裂**：Content(type=product) 可发布并进 feed，但前台 `/products/{slug}` 由 Entity Catalog 驱动 → 产品内容详情全 404，sitemap/llms/geo/搜索向外输出 404 URL（16A §6）。
- **默认制造垂直**：空站直接渲染制造首页（blocks/menus/询价 demand_type 种子即制造垂直），空数据不降级（F1）。

---

## 2. 设计目标与硬约束

1. **SiteScope 不可被 Admin UI 绕过**：后台所有列表 / 详情 / 写操作经当前站点上下文与全局作用域；UI 读路径禁止 `withoutSiteScope()`；跨站只允许 System Admin 通过显式站点切换器。
2. **不新增隐式关系**：坚持 5.6-C 冻结——Content 与 Entity 为平行资源，**Content ↛ Entity fallback**；任何 Content↔Entity 关联必须走显式 Relation Contract 并经独立架构阶段，不用 slug/name 猜测、不加 `contents.entity_id`。
3. **注册 / 授权分离沿用**：主题、插件的发现来自文件系统清单，启用态是 per-site Setting；UI 只改"启用态 / 当前选择"，不做运行时动态注册。
4. **最小修复 + 不重构 Core**：补 UI 与必要的控制器 / 路由 / 策略；引擎层已验收逻辑不动。
5. **PRG + 统一反馈 + 字段级校验**：所有写操作 POST→重定向→GET；中文校验（i18n）；字段级 `@error`；危险操作二次确认。
6. **空状态可用**：每个新管理页在无数据时给"是什么 / 怎么开始 / 新建入口"，不裸 0、不病句、不制造垂直幻觉。
7. **设计语言统一**：复用 GEO Website OS Design System 的 Token（`--brand`/`--brand-dark`、surface/text/border/radius/shadow）与既有后台 layout，不引第三方体系。

---

## 3. 目标信息架构（IA）与导航

建议把当前"旧官网运营概念"IA 重组为**站点 → 内容 → 实体与关系 → 渠道与 SEO → 外观 → 扩展 → 设置**：

```
控制台 Dashboard
站点
  ├─ 站点列表 / 新建 / 编辑（域名、状态、默认站、站点元数据）   [17A]
  └─ 当前站点切换器（顶部，仅 System Admin 可见全部）
内容
  ├─ 文章 Articles（Content.type=article）
  ├─ 单页 Pages（Content.type=page）
  ├─ 栏目 Categories / 知识分组 Groups
  ├─ 页面文案 Narrative
  └─ 媒体库 Media
实体与知识图谱（GEO）
  ├─ 组织 Organizations / 产品 Products / 服务 Services / 地点 Locations / 人物 / 主题   [17B]
  └─ 实体关系 Relations                                                    [17B]
渠道与 SEO
  ├─ SEO 覆盖 SeoMeta（站点级 / 内容级 / 实体级，三态）                     [17C]
  ├─ 抓取产出：sitemap / llms / robots / geo.json / RSS（预览 + 准入校验）
  ├─ 301 跳转 Redirects
  └─ 搜索召回（含 feed 200 准入诊断）
外观
  ├─ 主题 Themes（清单、当前主题、预览、激活）                              [17D]
  ├─ 主题色与 Design Token（从 general 归位）                                [17D/17F]
  ├─ 首页装修 Blocks / 导航 Menus
  └─ 默认模板与空状态（制造垂直种子通用化）                                  [17G]
扩展
  └─ 插件 Plugins（清单、per-site 启停、路由可达状态）                       [17E]
运营
  ├─ 客户留言 Inquiries / 事实库 Facts（评估保留 / 退场，见 §6.7）
设置
  ├─ 基础信息 / 联系方式 / 文案话术 / SEO 默认 / GEO 默认 / 集成（七组归位）  [17F]
  ├─ 用户与角色 Users & Roles                                            [17A]
  └─ 修改密码 / 同步日志
```

命名统一：菜单与标题一律用上述通用能力名；"事实库 / 全站话术 / GEOFlow / 首页装修"等旧名按 §6.6/§6.7 重命名或归位。

---

## 4. 权限模型

当前仅有单一 admin（User）。补三角色 + 站点成员关系（**新增能力，归入 17A，不在 16A 实现**）：

| 能力 | System Admin | Site Admin | Editor |
|---|---|---|---|
| 新建 / 编辑 / 删除 Site、设默认站、配域名 | ✅ | ❌ | ❌ |
| 跨站切换、查看所有站点 | ✅ | 仅被授权站点 | 仅被授权站点 |
| 用户与角色管理 | ✅ | 本站成员查看 | ❌ |
| 实体 / 关系、SEO 覆盖、栏目、菜单、Blocks | ✅ | ✅ | ❌（SEO/菜单/Blocks）；实体建议 ✅或可配 |
| 主题激活、插件 per-site 启停、站点设置 | ✅ | ✅ | ❌ |
| 文章 / 单页 / 媒体：新建 / 编辑 / 草稿 | ✅ | ✅ | ✅ |
| 发布 / 下架 / 删除内容 | ✅ | ✅ | 可配置（默认 Editor 可提交、Site Admin 发布） |
| 留言处理、301、事实库 | ✅ | ✅ | ❌ |

落地要点：
- Laravel Policy / Gate 资源级授权；路由 `admin.*` 统一挂 `auth` + 角色 / 站点策略中间件。
- admin 路径已支持 `site_slug`（见 SiteResolver：仅 admin 前缀允许显式切站）；站点切换器写 `site_slug`，普通前台 HTTP 不允许 query 切站。
- 写库统一经 `BelongsToSite` 的 creating 注入 + 显式 `site_id` 校验；EntityRelation 已有跨站保存守卫（保留并在 UI 层前置友好报错）。
- 所有列表查询默认 SiteScope；System Admin 跨站视图是显式"全部站点"模式，而非关闭作用域。

---

## 5. 领域命名裁决：Content "产品" vs Entity "产品"（必须二选一）

现状两个"产品"并存：Entity.type=product（Catalog / GEO 实体，驱动 `/products/{slug}`）与 Content.type=product（富文本产品内容，发布后前台 404）。**不能保留两个前台"产品"。**

**推荐方案 A（实体为准，内容归位）：**
- 对外"产品 / 服务 / 组织 / 地点"= **Entity**（按 type 分后台视图与前台路由，Catalog 驱动，`/products/{slug}` 由 Entity Product 落地，200 可访问）。
- **Content 收敛为 article / page 两类**（知识文章与单页）。现有 Content(type=product) 数据迁移为：与某 Entity Product 关联的"产品知识文章"——但因 Content↛Entity 已冻结，**不新建临时隐式关联**；17B 设正式"实体 ↔ 内容"关联契约前，先把它们归为 article 并置于产品知识栏目（URL 走 `/knowledge/...`），不再占用 `/products/{slug}`。
- 后台菜单不再出现 Content 侧"产品"；产品的创建 / 编辑 / 上线在 **实体 → 产品** 完成，盲测用户由此可让产品真正上线（根治 F6/双轨）。

备选方案 B（保留 Content product 但改名"产品资料"，URL 归 `/knowledge` 或 `/products/{slug}/docs`，实体仍是图谱产品）：保留两套编辑面，认知负担更大，不推荐。

> 该裁决影响数据迁移与路由，**须在 17B 架构 Gate 拍板后实施**；本文档不擅改数据模型。无论 A/B，feed 准入契约会强制"只有前台 200 可访问才进 sitemap/llms/geo/搜索"。

---

## 6. 七大管理域设计

### 6.1 Sites（17A）
- 列表（Table：name / domain / status / 默认站 / 实体数 / 内容数 / 更新时间；搜索 + 状态 Filter）。
- 表单：name*、slug*（`^[a-z0-9-]+$`，创建后不可改或仅 System Admin 可改）、domain（唯一，http(s) 剥离、去端口 / 路径，给出"一个域名对应一个站"提示）、status（active/inactive/maintenance）、is_default（同一时间仅一个默认站，切换需确认）、metadata（站点 logo / 联系方式等 JSON，用结构化字段而非裸 JSON）。
- 删除保护：站点存在内容 / 实体时**禁止硬删**或要求先清空；明确 FK 影响（CASCADE / RESTRICT 各表不同，逐一在确认框列出）。
- 校验：domain 唯一（含 NULL 多站规则）、slug 唯一；未知 host 在生产模式（default_fallback=false）404 的行为在编辑页有说明。
- 空状态：引导"创建你的第一个站点 → 配域名 → 设为当前"。

### 6.2 Entities + Relations（17B）
- 实体列表按 type 分 Tab（组织 / 产品 / 服务 / 地点 / 人物 / 主题），Table：name / type / slug / status / 关系数 / 更新时间；搜索 + 状态 Filter + 批量（Bulk）发布 / 归档。
- 实体表单：type*、name*、slug*（站点内按 type 唯一）、status（draft/published/archived）、summary/description、metadata 结构化字段（按 type 动态：Organization=NAP/Logo/同价；Product=image/sku 类别；Location=address/geo；Service=服务范围）、OG 图（从媒体库选，写 metadata.og_image）、published_at、sort_order。
- **关系管理**：在实体详情内嵌关系表（from / 关系类型 produces/offers/uses/located_in/related_to / to / sort / metadata），from/to 下拉**只列本站实体**（后端 EntityRelation 跨站守卫已有，UI 前置拦截并友好提示）；关系图只读可视化（点 / 线，按 type 着色）作为 P1 增强。
- Catalog 闭环：实体保存 / 发布后 Catalog 投影即时刷新（flush 对应 memo / 缓存），前台 `/products/{slug}` 等 200；提供"在前台查看"。
- 数据来源治理：旧 Facts / config(facts) 仅安装期种子（P-STEP 14）；提供"从示例种子生成实体"的一次性演示入口（Example 数据，非客户数据），运行期不以 Facts 为源。
- 验收：盲测可不经 SQL 建出 Organization + 3 Product + 2 Service + Location 并在前台 / geo.json 见到。

### 6.3 SeoMeta（17C）
- 三个作用域统一管理：**站点级**（content_id/entity_id 均 NULL）、**内容级**（绑 Content）、**实体级**（绑 Entity）。列表用作用域 Filter + 关联对象选择器。
- 表单严格反映表结构（20 列）与三态 CHECK：绑定对象二选一或都不选，**UI 不允许同时选 content 与 entity**（前端约束 + 后端表级 CHECK 兜底）；站点内三枚 partial unique index 对应"每站一条站点级 / 每内容一条 / 每实体一条"，重复给中文唯一校验（同类 Bug#5 处理）。
- 字段：title/description/keywords/canonical/og_title/og_description/og_image_path（媒体库选择，写 path，输出经 Media::url() 绝对化）/og_type/twitter_card/noindex/nofollow/robots/schema_type/metadata。
- canonical：默认由 GenericUrlResolver 生成（只读预览，显示 HTTPS / 无 query / 斜杠契约），自定义 canonical 最高优先级（可覆盖并标注"自定义"）。
- 提供"解析预览"：选定对象后实时展示 Resolver 最终 title/description/canonical/og/robots（按 5.6-C 冻结的**分资源独立链**：Content→Content 字段→Site→System；Entity→Entity 字段→Site→System；Content 不读 Entity）。
- noindex/nofollow 三态（true/false/未设置）语义在 UI 明示。

### 6.4 Themes（17D）
- 数据源 `ThemeManager::all()`（扫描 `resources/themes/*/theme.json`），卡片网格：截图 / name / version / description / 当前激活标记。
- 操作：预览（新窗口以该主题渲染，不改激活态）、激活（调 `ThemeManager::activate()`，写本站 Setting `theme_active`，重注册 + 清整页缓存）；**主题是文件资源，UI 不提供上传 / 删除（归入后续 Theme SDK / 文件部署）**，避免后台写文件的安全面。
- per-site：顶部当前站点切换后，激活状态互不影响（引擎已支持，UI 显式呈现"A 站主题 / B 站主题"）。
- 主题色 Token 管理迁入本域（见 6.6）。

### 6.5 Plugins（17E）
- 数据源 `PluginManager::all()`（扫描 `plugins/*/plugin.json`），列表：name/version/provider/description/本站启用开关/路由清单。
- 操作：启用 / 停用（写本站 Setting `plugins_enabled` JSON 数组，幂等）；**不做运行时 provider 动态注册**（沿用 D.3 注册 / 授权分离）。
- 关键可观测：每个插件展示其注册路由，并提供"本站访问应 200 / 他站或停用应 404"的**实时探测结果**（发本站上下文请求校验 EnsurePluginEnabled），把 D.3 的 per-site 隔离从测试变成运营可见。
- per-site 矩阵（System/Site Admin）：行=插件、列=站点、格=开关，便于核对 A 启用 / B 停用。
- 插件为文件资源，UI 不做上传安装（归 Plugin SDK / 部署），仅启停与状态。

### 6.6 Settings（17F）—— 七组逐字段处置
键值引擎 Setting（per-site，get/set/flush）保留；把"只有 general 10 行、其余空组、主题色错位、label 为 null、旧前缀"一次性归位。

| 组 | 现状（16A DB 实锤） | 处置 |
|---|---|---|
| general 基础信息 | 9 个 theme_* token + nav_cta_text | **拆分**：theme_* 归 theme 组；nav_cta_text 归 copy/导航；general 改为站点名 / 简介 / logo / 默认 OG 图等真正基础字段 |
| theme 外观 | 0 行 | 接收 9 个 token（primary/primary_dark/accent/bg/surface/text/text_muted/radius/container），**补全 label/type/hint（修 F3 null label）**，Token 语义对齐 Design System（`--brand`/`--brand-dark`…），加色板 / 数值校验与"恢复默认" |
| contact 联系方式 | 0 行 | 补字段：电话 / 邮箱 / 地址 / 社交链接 / 联系表单收件配置；与 Site metadata / Entity Organization NAP 的单一事实源厘清（避免三处填电话） |
| copy 文案话术 | 0 行 | 承接首页 / 全局 CTA、询价表单 labels / 成功文案 / 隐私语；**制造垂直默认（装备制造等 demand_type、OEM FAQ）通用化**，默认改为行业中性占位 + 空状态 |
| seo SEO 默认 | 0 行 | 站点级默认 title 模板 / description / 默认 OG 图 / robots 默认 / Twitter card；与 SeoMeta 站点级关系厘清（避免第三 / 第四套 SEO） |
| geo GEO 默认 | 0 行 | 默认 $schema 版本、llms/robots 开关与默认放行、空站安全文案（"未配置事实库、未公示勿推测"保留为通用安全默认） |
| sync 集成（GEOFlow） | 0 行，token 前缀 `yhf_` | **评估 RETIRE / 默认隐藏**：若无通用产品价值则从 IA 移除为可选插件式集成；保留则重命名为通用集成，**regenerateToken 前缀 `yhf_` 改为产品中性前缀（如 `gwos_`）** |

补充：栏目 seo_title/seo_desc（疑似第三套 SEO）在 17F/17C 厘清归属；category slug 的 unique 加站点作用域（修 16A 观察项）；category type 枚举在迁移注释 / 控制器 / 表单统一；外链栏目 external_url（16A Bug#1 已补列）在 17F/前台接线完成跳转。

### 6.7 既有模块改造点（不重写，补齐 / 正名）
- **Facts 事实库**：P-STEP 14 已降级为安装期种子。二选一：(a) 作为"开发者 / 演示数据"入口移出日常运营菜单；(b) 若保留运营可见，则明确它不再驱动 Runtime，并修 unit/source_url 的前台消费决策（16A Bug#6 仅补列消除 500）。建议默认 (a)。
- **Blocks 首页装修 / Menus / 询价 demand_type**：默认种子从制造垂直改为行业中性模板 + 空状态降级（F1）；保留装修能力本身。
- **Content 表单**：保留成熟的 GEO 门禁 / 证据 / FAQ / 关键事实 / 封面 / 复核流；Bug#2 载体 form 模式推广为全站"主表单 + 独立动作按钮"规范；封面禁 SVG（Bug#4）保持。
- **Media**：补 md-editor 内联上传的后台用例（16A 未测）；Entity OG / Site logo 的媒体选择在 17A/17B 接入。
- **Redirects**：Bug#5 的站点作用域 unique 校验作为全站"唯一约束前置友好校验"范式（Facts/Entity/SeoMeta 同法）。
- **全局**：后台 500 错误页用后台自身样式（16A 观察到渲染前台错误样式）；全后台字段级 @error + 中文验证（i18n P3）。

---

## 7. Admin UI 设计规范（基于自有 GEO Website OS Design System）

- **Table**：密度 / 对齐 / 排序 / 列显隐；空态有引导；数字右对齐；状态用 Badge（draft/published/archived、active/inactive）。
- **Form**：label + 必填星号 + hint + 字段级错误（@error 实时 / 提交后定位首个错误）；分区卡片；危险字段与常规字段分离；长表单草稿保护。
- **Drawer / Modal**：次级编辑（关系、SEO 覆盖快速编辑）用 Drawer；阻断式确认（删除、停用、切换默认站）用 Modal；不用 Modal 承载长表单。
- **Toast / 内联反馈**：成功 / 失败统一走 layout 顶部 alert + 必要 Toast；写操作一律 PRG，刷新不重复提交。
- **Empty / Loading / Error**：每个列表空态="说明 + 主行动作按钮"；提交 Loading 并禁用按钮（防重复，参照询价表单）；错误页区分 403/404/500，后台 500 不泄漏堆栈（APP_DEBUG=false 已验证）。
- **Confirm**：所有 DELETE / 停用 / 级联 / 默认站切换用二次确认，并列明影响（如"将同时移除 N 条关系 / 缓存将重建"）。
- **Pagination / Search / Filter / Bulk**：列表统一分页器、关键词搜索、站点 / 状态 / 类型 Filter、批量发布 / 归档 / 删除（带选择计数与确认）。
- **可访问性**：label 关联、按钮 vs 链接语义正确（Bug#2 同类问题预防）、键盘可达、焦点管理、aria-live 反馈。
- **一致性**：命名 / 日期 / 时区 / slug 规则 / 图标统一；制造 / 化工垂直图标（27 个 flame/droplet/flask 等）在通用后台替换为中性图标。

---

## 8. 安全与隔离约束（实现时必须保留 / 满足）

- 多站：SiteContext / SiteScope / RequestScopedState::reapply() / withSite 进出复位（P-STEP 14 D.2/D.4）；Admin UI 不引入新的 static memo 污染；每请求复位主题 / 插件 / 设置记忆。
- 实体关系：保留 EntityRelation 跨站 saving 守卫；UI 下拉只列本站实体。
- 插件：EnsurePluginEnabled per-site 守卫是唯一运行时闸门；UI 只改启用 Setting。
- 文件上传：统一 mimes 白名单、**禁 SVG**（存储型 XSS）、尺寸 / 大小限制、媒体库与内联上传策略一致（Bug#4）。
- 表单：CSRF、throttle（询价 6,1 范式）、蜜罐、PRG、提交锁。
- 唯一约束：所有 DB 唯一索引（redirects、seo_metas 三枚 partial、category/entity slug 等）在控制器前置站点作用域校验，杜绝重复提交 500（Bug#1/#5/#6 同类）。
- 不泄漏：500 不显示堆栈；日志不记录密码 / token；GEOFlow 等 token 仅创建时明文展示一次。

---

## 9. Feed / 前台准入契约（17G 收口）

- **唯一准入规则：仅当前台实际 200 可访问时，URL 才允许进入 sitemap.xml / llms.txt / geo.json / 搜索结果 / RSS。** 新增"Feed 准入诊断"（抓取产出页）：批量探测候选 URL 的状态码，列出将被收录 / 将被剔除（404）项，消除 16A 发现的"输出 404 产品 URL"。
- canonical / og:url 与**实际落地路径统一**（冻结契约的 /article|product/ 与落地 /knowledge|products/ 双路径 301 必须收敛为单一规范路径）；host 由 Site domain 驱动而非全局 APP_URL；SeoMeta 显式 og_image_path、站点 logo 全部绝对化、按站 domain。
- geo.json contents 节点补 image（封面）字段（16A 发现缺失）。
- 空站：feed 输出安全空集 + 通用说明，不输出制造垂直 / 裸 0 营销首页。

---

## 10. P-STEP 17 分阶段计划（每阶段独立 Gate、STOP、不跨阶段）

| 阶段 | 范围 | 关键验收 | 不做 |
|---|---|---|---|
| **17A Sites + 权限基础** | Site CRUD、域名 / 状态 / 默认站、站点切换器、User 角色 + 站点成员、SiteScope 在 Admin 全链路强制 | 盲测可建 2 站并切换；A/B 数据后台不串；删除保护生效 | 不动实体 / SEO / 主题插件 |
| **17B Entities + Relations + 产品双轨收口** | 实体 / 关系 UI、Catalog 后台闭环、§5 命名裁决落地（产品前台 200）、Content product 归位迁移 | 不经 SQL 建出 Org/3 Product/2 Service/Location 且前台 + geo.json 200；feed 无 404 | 不新增 Content↔Entity 隐式关系 |
| **17C SeoMeta** | 三态绑定 UI、解析预览、canonical/OG 绝对化对接、唯一约束友好校验 | 三作用域 CRUD + CHECK/partial index 违规变中文校验；预览与前台一致 | 不改 Resolver 冻结链 |
| **17D Themes** | 主题列表 / 预览 / 激活（per-site）、Token 管理归位 + label 补全 | A→B→A 主题切换后台可操作且不串站；Token 改即时生效 | 不做主题上传 / SDK |
| **17E Plugins** | 插件清单 / per-site 启停 / 路由可达探测矩阵 | A 启用 200、B 停用 404 后台可见可切 | 不做插件上传 / 动态注册 |
| **17F Settings + 体验一致性** | 七组归位 / 补字段 / RETIRE、i18n 中文校验 + 字段级错误、yhf_ 前缀通用化、category 站点唯一 / type 统一 / 外链接线、后台错误页 | 七组均可编辑或明确 RETIRE；全后台无英文裸校验、无 500 样式错误 | 不重构键值引擎 |
| **17G Full Admin UAT + Feed 收口** | 重跑 16A 全量用例（这次 Site/Entity/SEO/Theme/Plugin 全部经 UI）、盲测独立建站、Feed 准入诊断、canonical 收敛、默认模板通用化 + 空状态 | 陌生用户仅靠后台独立搭出双站企业官网；feed 全 200；回归不低于当基线、0 failed/skipped；污染 0 | 全部通过前不 Public Release |

每阶段门禁：focused test → 全量 `php artisan test`（断言 / 用例数只增不减，不删测试 / 不降断言 / 不 skip）→ 多站 A↔B 往复 → 污染复扫 → storage/logs 无新增未处置 ERROR → Git clean → STOP 等授权。

---

## 11. 边界（本设计明确不做）

- 不在 17 阶段做 Theme SDK / Plugin SDK / 应用市场 / 上传安装（后续独立阶段）。
- 不新增 Content→Entity 隐式关系、不加 `contents.entity_id`、不用 slug 匹配猜关系。
- 不改写已冻结的 SEO Resolver 分资源链、不改表级 CHECK / partial index 契约（只在其上做 UI）。
- 不为"干净"删除历史 migration / 升级回滚链 / docs/audit 历史证据。
- 不在后台引入对具体客户 / 具体行业的硬编码；制造垂直仅作为可替换的**示例模板**，默认行业中性。

---

## 12. 总验收标准（P-STEP 17 全部完成时）

1. 陌生使用者仅经后台（无 SQL / 无命令行）可：建多站 → 配域名 / Logo / 联系方式 → 建组织 / 产品 / 服务 / 地点与关系 → 建文章 / 单页 / 栏目 → 传媒体 → 配三态 SEO → 选主题 → 启停插件 → 发布，并在前台 / sitemap / llms / geo.json / 搜索得到**全部 200、严格按站隔离、无制造垂直幻觉、无客户痕迹**的结果。
2. 权限三角色与站点成员生效，越权 / 跨站读写被拒并有友好反馈。
3. feed 候选 URL 100% 前台可达；canonical/OG 与落地一致且按站 domain 绝对化。
4. 全量回归只增不减、0 failed / 0 skipped；storage/logs 无未处置异常；业务污染 0；Git 历史保持清洁。
5. 满足后才具备从 `RELEASE CANDIDATE` 进入 **Public Release（v1.0.0）** 的产品完整度前提（云端 CI 首跑为并行的发布工程 Gate，不在本文范围）。
