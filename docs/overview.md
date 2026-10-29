# 项目概述：GEO Website OS 是什么

> 版本口径：本文描述 **v1.4.0**（2026-10-28 发布）。所有「已实现」能力均有代码 / 测试 / Release 证据；「规划中 / 候选」均显式标注。

## 一句话定位

**GEO Website OS（GEO 网站操作系统）是一个多站点、多语言、GEO/SEO 优先的企业内容与实体操作系统**，基于 Laravel 12 / PHP 8.4 构建：一套代码运行多个相互隔离的站点，每个站点拥有自己的内容、实体、知识图谱与机器可读的 GEO/SEO 输出，同时面向**人类访客**与**生成式搜索 / 大模型（AI）**两类消费者。

## 它解决什么业务问题

传统建站系统解决的是「把页面做出来」。GEO Website OS 解决的是**「让企业事实被 AI 正确读到、引用、信任」**：

- 传统 SEO 面向搜索引擎爬虫；GEO（Generative Engine Optimization）面向生成式检索引擎与大模型（ChatGPT、豆包、Kimi、文心、Perplexity、Bing Copilot 等）。
- 企业官网的内容同时被两类消费者读取，若两者看到的「事实」不一致（例如 AI 出口缺少某个语言的经营事实），会产生矛盾答案、损害信任。
- 该系统从数据模型层把「业务事实」与「内容」分离，用一个**唯一权威源（facts 表）**派生所有 AI 可读出口，并靠契约测试强制跨出口一致。

## 为什么需要它，而不是传统 CMS 或简单 SEO 插件

| 维度 | 传统 CMS / SEO 插件 | GEO Website OS |
| --- | --- | --- |
| 多站点 | 通常需要多套安装或插件拼凑 | **原生多站点**：Host 解析 + 站点级数据隔离 + 站点感知缓存，查询参数也无法绕过隔离 |
| 多语言 | 插件级翻译，内容模型常不一致 | **行级翻译模型**（`locale` + `translation_group`），同一 slug 跨语言共存，数据库级约束 |
| AI 可发现性 | 通常只有 sitemap / robots | **6 类机器可读出口**：`sitemap.xml`、`llms.txt`、`feed.xml`、`geo.json`、`robots.txt`、页面 JSON-LD，从同一事实源派生 |
| 事实一致性 | 无约束，页面与结构化数据可互相矛盾 | **AI 事实契约**：facts 表为唯一权威源，跨出口 / 跨语言 / 跨站一致性由测试强制 |
| 实体与知识图谱 | 通常没有 | 8 类通用实体 + 类型化关系（EntityRelation），支撑 Schema.org 与知识图谱 |
| 主题与扩展 | 主题系统各异 | 主题（Theme）+ 声明式模板包（Template）+ 插件（Plugin），运行时可启用/停用 |

## 核心工作机制

```text
请求进入
  → Host 解析站点（SiteResolver / SiteContext）
  → 站点级数据隔离（BelongsToSite + 全局 SiteScope）
  → 内容 / 实体 / 页面解析（含本地化，/en/ 前缀按站启用）
  → 统一 SEO 解析（SeoMetaResolver 继承链 + canonical）
  → GEO 输出构建（JSON-LD / geo.json / llms.txt / sitemap / feed / robots）
  → 整页缓存（PageCache）加速返回
```

关键机制详见 [系统架构](architecture.md)，包括：

- **多站点隔离**：数据库层 + 查询层双重隔离；CLI 与队列任务显式携带站点上下文。
- **多语言**：行级翻译模型；未启用的语言返回 404 而不是静默回落。
- **GEO/SEO 同源派生**：`geo.json` 与 `llms.txt` 的事实必须经 `Fact::publicRows($locale)` 取得，禁止散点读取另一套事实源。
- **发布门禁**：内容 / 实体发布时强制校验结构化完整性（`config/geo.php` 的 `gate.enforce_on_publish`）。

## GEO、SEO、CMS 之间的关系

```text
CMS（内容与实体管理）
   │  内容 / 实体 / 事实 / 分类 / 媒体 / 表单 / 询盘 / 治理
   ▼
SEO（面向传统爬虫）            GEO（面向生成式引擎 / 大模型）
   ├─ canonical / hreflang      ├─ geo.json（实体 / 知识图谱）
   ├─ Open Graph                ├─ llms.txt（站点摘要与引用）
   ├─ sitemap.xml / robots.txt  ├─ JSON-LD（schema.org）
   ├─ feed.xml                  └─ AI 爬虫放行（robots.txt 白名单）
   └─ 页面 JSON-LD（schema.org）
   └───────────── 同一事实源（facts 表） ─────────────┘
```

三者不是三个独立模块，而是**一套数据模型的三类出口**：CMS 负责生产与维护事实，SEO 与 GEO 从同一事实源派生，由 `AI Fact Contract` 保证两类出口不矛盾。

## 适合什么类型的企业、网站和业务场景

**适合：**

- 需要**一套系统管理多个站点 / 多语言站点**的企业（集团官网、多品牌、多区域）。
- 面向 **AI 搜索时代**、希望被大模型正确引用与推荐的企业官网（制造业、SaaS、品牌电商等）。
- 内容团队需要**事实可审计、发布可控制**（发布门禁、修订、回收站、询盘归因）的站点运营者。
- 需要给 AI 消费方提供结构化资料（企业信息、产品、服务、案例、知识内容）的公开知识入口。

**不适合 / 需自行评估：**

- 需要复杂电商交易流程（购物车、支付、订单）的场景——本项目是**内容与知识操作系统**，不是交易系统。
- 需要海量插件生态、市场化的第三方扩展——模板 / 插件 SDK 处于 [路线图](roadmap.md) 阶段。
- 需要视觉自由拖拽的建站体验——本项目以 Blocks / Sections / 语义数据为基础，不是自由画布。
- 多语言需求若只是「少量文案翻译」，行级翻译模型的收益可能被高估——请先阅读 [本地化设计](localization-design.md) 评估。

## 当前版本具备的能力（v1.4.0，均有证据）

| 能力域 | 说明 |
| --- | --- |
| Multi-site | Host 解析、站点级隔离、站点感知缓存、未知 Host 策略（`SITE_DEFAULT_FALLBACK`） |
| Multi-language | `zh-CN`（默认）+ `en`，行级翻译模型，语言前缀路由按站启用 |
| CMS | 内容（文章/页面）、实体、关系、分类、分组、媒体、导航、表单、询盘、治理 |
| SEO | canonical、Open Graph、hreflang、JSON-LD、sitemap.xml、feed.xml、robots.txt |
| GEO | geo.json、llms.txt、AI 爬虫白名单、发布门禁 |
| GEOFlow | 上游内容同步 REST API：article/page/product 创建、更新、下架、预检；幂等、人工锁保护、ContentGate 发布门禁、sync_logs 审计；108 项测试通过。配置 Bearer Token 后开启 |
| 主题 / 模板 / 插件 | Theme（默认 + example）、声明式 Template 包（激活/停用/迁移/校验）、插件示例 `plugins/hello` |
| 生命周期 | 安装（geo:install）、升级（62 个幂等迁移）、回滚、备份/恢复、内容回收站 |
| 搜索 | `/search`（SQLite FTS5 / 其他库普通索引） |
| 性能 | 整页静态化缓存（PageCache，匿名外壳 + CSRF 回填） |
| 质量 | 全量 1761 tests / 9470 assertions 零失败；PostgreSQL 16 CI 门禁；跨库结构一致性；真实并发 |

## 仍处于规划、实验或候选状态（明确标注）

- **MySQL：候选支持**——8.4 已实测（迁移/安装/回归/并发/演练），**尚无 CI 门禁**，不进入发布阻断管线。
- **PostgreSQL / MySQL 全量回归不进 CI**——CI 只跑 `PgsqlCore` 子集（174 例）；全量由本地隔离实例定期执行留证。
- **9 个测试用例仅在 SQLite 验证**（站点创建生产等价 7 项 + 升级/回滚 E2E 2 项），PG/MySQL 上这两条能力**目前零证据**。
- **Admin UI 仅中文**，无语言切换（规划中）。
- **同站 settings 跨语言共用**（`site_name` 等 4 键无 locale 列，P2 已知限制）。
- **分类/分组的翻译为字段级 `name_en`**——v1.0 的架构选择，与内容/实体的行级模型并存是刻意的。
- **应用内版本号口径待统一**：`php artisan geo:version` 输出 `app.version=1.0.0`（config/geo.php 默认值），与发布标签 v1.4.0 不一致，属已知待办。
- **路线图规划能力**（定时发布、内容引擎、分析面板等）见 [路线图](roadmap.md)，**均未实现**。

## 如何快速判断是否适合你

1. 需要多站点 / 多语言统一管理吗？→ 是则本项目原生支持。
2. 需要让 AI 搜索引擎正确引用你的企业事实吗？→ 是本项目的核心设计目标。
3. 需要电商交易、丰富插件市场、自由视觉拖拽？→ 本项目不适合。
4. 需要 MySQL 作为正式支持数据库？→ 当前为候选支持，请先阅读[数据库支持矩阵](operations/database-support-and-recovery.md)。

## 相关文档

- [系统架构](architecture.md) · [安装与部署](installation.md) · [使用手册](usage.md) · [路线图与当前边界](roadmap.md)
- 英文：[README_EN.md](../README_EN.md)
