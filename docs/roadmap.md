# 路线图与当前边界

> 版本口径：本文以 **v1.4.0**（2026-10-28 发布）为基准描述「当前边界」；
> 未来规划引用 [`docs/product/roadmap-1x.md`](product/roadmap-1x.md)（该文基于 v1.0.0 盘点，正文未随 v1.4.0 重写，其结论仍作为规划基线）。

## 1. 当前状态（v1.4.0，已发布）

| 能力域 | 状态 | 说明 |
| --- | --- | --- |
| Multi-site / Multi-language | 已实现 | 站点级隔离 + 行级翻译模型（见 [架构](architecture.md)） |
| CMS | 已实现 | 内容 / 实体 / 关系 / 媒体 / 导航 / 分类 / 表单 / 询盘 / 治理 |
| SEO | 已实现 | canonical / OG / hreflang / JSON-LD / sitemap / feed / robots |
| GEO | 已实现 | geo.json / llms.txt / AI 爬虫白名单 / 发布门禁 / 事实契约 |
| GEOFlow | 预留 | 上游内容同步 API（幂等指纹、人工锁、kill switch），默认关闭 |
| 主题 / 模板 / 插件 | 已实现 | Theme + 声明式 Template 包（激活/预览/迁移）+ 插件 |
| 生命周期 | 已实现 | 安装 / 升级（62 迁移）/ 回滚 / 备份恢复 / 内容回收站 |
| 数据库 | SQLite + PG 正式支持；**MySQL 候选支持** | 见 [数据库矩阵](operations/database-support-and-recovery.md) |
| 质量 | 1761 tests / 9470 assertions 零失败 | CI 精确计数门禁 + PG 零失败门禁 |
| Admin UI 语言 | **仅中文** | 无语言切换（规划中） |
| 应用内版本号 | `geo:version` 输出 1.0.0 | 与 v1.4.0 标签不一致，**已知待办** |

## 2. 规划中的能力（均未实现）

依据 [`docs/product/roadmap-1x.md`](product/roadmap-1x.md) 的三阶段路线（该文为 v1.0.0 盘点的 PROPOSED 规划）：

### 阶段一：Content Operations（内容运营）
| 优先级 | 能力 | 说明 |
| --- | --- | --- |
| P0 | 定时发布（Scheduled Publish） | `publish_at / unpublish_at`，且 sitemap / schema / GEO / 搜索必须同步跟随——「发布改变的是整个机器可读知识集合」 |
| P0 | 内容生命周期统一契约 | Entity / Content / Page / Block 统一生命周期 |
| P1 | 媒体库运营层 | 核心是 Usage Graph（图被哪些实体 / 页面引用） |
| P1 | 询盘运营闭环 | 数据层（utm/落地页归因）已有，补运营界面 |
| P2 | RBAC | Owner / Admin / Editor / SEO / Content 权限 |

### 阶段二：GEO Operations（GEO 运营）
| 优先级 | 能力 | 说明 |
| --- | --- | --- |
| P1 | GEO 内容引擎 | Entity + Facts + Evidence → Draft → Health → Coverage → Human Review → Publish；AI 产物必须可追溯依据 |
| P1 | 分析面板 | Traffic / Content / GEO / Conversion |
| P1 | 翻译工作流 | Source → Translate → Review → Publish，源改动自动标记译文 Outdated |
| P2 | FAQ 语义内容 / 存储适配器 | FAQ 直接进实体图谱；Local / S3 / OSS 统一存储 |

### 阶段三：Platform Operations（平台运营）
| 优先级 | 能力 |
| --- | --- |
| P2 | 模板中心增强（版本契约 / 升级） |
| P2 | 视觉编辑 × 组合器融合 |
| P3 | Admin i18n |
| P3 | 模板市场 / 插件 SDK |

## 3. 明确不做的事（产品纪律）

```text
❌ 为「功能数量」堆功能            —— 判据是运营闭环
❌ 无限级栏目树                   —— Category + Entity Graph 已能表达
❌ 传统富文本中心（UEditor 类）    —— 以 Blocks / Sections / 语义数据为基础
❌「AI 写文章」作为产品            —— AI 产物必须过 Evidence → Review 才能发布
❌ 传统 SEO Score                 —— GEO Health 有更真实的判据
```

## 4. 发布与演进纪律

- 新增能力遵循：独立 Discovery → 架构影响评估 → 必要时 Architecture Change Review → Implementation → Gate。
- 每个版本打 tag 前走完整发布闭环（先推 main → 正式 version tag → tag 触发 CI → test + pgsql 绿 → release 构建资产）。
- 发布链路（remote 配置 / push / release）受项目 Gate 纪律约束，未获授权不执行。

## 5. 相关文档

- 规划基线：[`docs/product/roadmap-1x.md`](product/roadmap-1x.md)（PROPOSED，v1.0.0 盘点）
- 成熟度差距矩阵：[`docs/product/v1.1-product-maturity-gap-matrix.md`](product/v1.1-product-maturity-gap-matrix.md)
- 验收原则：[`docs/product/engineering-acceptance-principles.md`](product/engineering-acceptance-principles.md)
