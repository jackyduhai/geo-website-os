# P-STEP 18R-2c — Content Hub Lite · Gate Report

| 项 | 值 |
| --- | --- |
| 阶段 | P-STEP 18R-2c Content Hub Lite |
| 代码 commit | `c89dec7`（基于 2b `3e600e`，未打 tag） |
| Gate 修复 | 与本报告同提交（翻译键补全、无效样式占位清理） |
| 日期 | 2026-09-26 |
| 核验方式 | MainAgent 独立核验（非仅采信实现方报告） |

本阶段目标不是建 CMS 分类系统，而是让 **Content 成为 Entity Graph 中的语义节点**：文章不再孤立，经受控关系关联 Product / Industry / Scenario，并沿统一 Composition → Schema → GEO → Search 链路输出，使 AI 能读出"这篇内容讨论什么产品、什么行业、什么场景"。

---

## ① Architecture Impact — Second System: **No**

| 关注点 | 结论 |
| --- | --- |
| 关系体系 | 仅新增**单一 pivot `content_entity`**（`site_id, content_id, entity_id, relation_type` 枚举 `about/mention`）；未扩展 entity_relations、未建知识图谱层 |
| 黑名单扫描 | `content_relations / content_entity_relations / knowledge_nodes / topic_graph / semantic_edges / knowledge_relations / topic_relations / semantic_relations` 在 migrations + app 全量扫描 **命中 = 0**（独立 grep 取证） |
| relation_type 受控 | 双重白名单：ContentController 校验 `entity_links.*.relation_type = in:about,mention`；syncRelations 对非白名单值回落 `mention`，**无 custom_relation 入口** |
| Renderer / Composition | 复用既有 CompositionRenderer + content.blade，未写第二套渲染器/控制器体系 |
| SEO / GEO | SchemaBuilder::article() 增 about/mentions（节点 @type 经 EntityCapabilityRegistry 映射）；GeoGraphBuilder 增 content_about/content_mention 边——**同一链路、无旁路** |
| Search | SearchIndexBuilder 将 tag 名 + 关联实体名并入既有 FTS 文档 body，复用 FTS5，**未建第二搜索引擎** |
| 存储 | 3 张 site-scoped 表（`tags` / `content_tag` / `content_entity`），migration 幂等（hasTable 守卫）、可回滚（dropIfExists） |
| Template | 8 个 -pro 模板包**零新增文件**；新增能力经核心 block/链路承载，模板包不获得代码执行能力 |

**结论：Entity Graph 扩展（非新增体系），Renderer / Composition / SEO·GEO 单链路保持，Second System = No。**

---

## ② Feature Matrix

| 能力 | Backend | Admin | Frontend | Search | SEO | GEO-AI |
| --- | --- | --- | --- | --- | --- | --- |
| Tag（site-scoped、多对多 Content） | ✅ tags + content_tag | ✅ 标签多选 | ✅ `.tag` 标签芯片 | ✅ tag 名并入 body | — | ✅ |
| Content ↔ Entity（about / mention） | ✅ content_entity pivot | ✅ 动态关联行 + 白名单校验 | ✅ 相关实体块（PublicUrl） | ✅ 实体名并入 body | ✅ Article about/mentions | ✅ geo.json content→entity 边 |
| Content 生命周期同步 | ✅ 状态机 | ✅ 发布/归档/删除 | ✅ draft 不公开 | ✅ 状态联动索引 | ✅ | ✅ 边随状态建/清 |

---

## ③ Evidence（MainAgent 独立核验）

| 项 | 结果 |
| --- | --- |
| git commit | `c89dec7` on main，基于 `3e6000e`（14 文件 +583 行）✅ |
| 第二体系扫描 | 黑名单 8 类表名在全仓 `*.php` **0 命中** ✅ |
| Migration | `2026_09_26_000001_create_content_hub_tables.php`，3 表全部 site-scoped（content_tag 经 content/tag 间接隔离）、hasTable 幂等、down() dropIfExists ✅ |
| Migration 独立实证 | 临时空库（`D:\Temp\migtest`，独立 PDO 脚本 `check_tables.php`）：fresh = tags/content_tag/content_entity 均 **Y:0**；rollback = 三表 **N**；remigrate = 三表重建 **Y:0** ✅ |
| 新增测试 | ContentHubLiteTest 6 + ContentLifecycleTest 3 = **9 passed（17 assertions）** ✅ |
| **全量回归** | **1194 passed, 6328 assertions, 0 failed, 0 skipped**（独立复跑，757.54s，落 `D:\Temp\reg-18r2c.txt`）✅ |
| Fresh | tags = 0 / content_entity = 0（RefreshDatabase + 生命周期测试），无 demo 污染 ✅ |
| Upgrade | 旧 content / product / organization 关系与 URL 不变（全量回归含既有测试）✅ |
| Rollback | `git revert c89dec7` + migration down() 即完整回退 ✅ |
| Content 生命周期 | draft → 不公开/不索引/不输出（关系内部保留）；published → 全链路生效；archived/unpublished/delete → geo 边自动清除，**无 AI 残留** ✅ |
| Tag 删除安全 | 删除 tag 仅 cascade 断 content_tag 关系，**不删 Content、不删实体** ✅ |
| Site 隔离 | Tag site-scoped、unique(site_id, slug)，跨站不污染；syncRelations 仅默认语言 anchor 执行（标签/关系站点级不随翻译重复）✅ |

### Gate 修复（c89dec7 之后、本报告同提交）

独立核验中发现 2c 引入的两处展示层瑕疵，已修复（不触及任何逻辑/契约）：

1. **翻译键缺失**：`content.blade.php:136` 引用 `ui.c_related_entities`，但该键在 zh/en `ui.php` 不存在；`__()` 对缺失键返回键名本身、`?? '相关实体'` 永不触发 → 相关实体块标题会显示原始键名。已在 zh/en `ui.php` 补键（中「相关实体」/ 英「Related Entities」）。
2. **无效样式占位**：`content.blade.php:131` tag span 残留 `style="... "`（浏览器忽略）；`.tag` 已有完整 CSS（site.blade.php:642），已移除该占位。

修复后重跑 ContentHubLiteTest + ContentLifecycleTest = **9 passed（17 assertions）**。全量回归 1194 针对 `c89dec7`；本次修复仅纯展示层（新增翻译键 + 删除本被忽略的 style 属性），不影响任何逻辑/契约，故未重跑全量。

---

## ④ Release Impact

| 项 | 值 |
| --- | --- |
| **P0 Blocker** | **0** |
| **Decision** | **PASS** |
| TD 收口 | TD-154 CLOSED（by 2c `c89dec7`） |
| 新增 TD | **TD-157** Tag 展示名未按 locale 本地化（P3，不阻断） |

**Release Debt（如实声明）**：用户默认方案 A 要求 `site_id + identity_key(语言中立 slug) + localized_names{zh,en} + status`。当前 tags 落地为单 name（`site_id + name + slug`）、locale 中立——**slug 已提供语言中立 identity，"AI 视为同一主题、不产生跨语言孤立 tag"的核心红线满足**；仅展示名不随 zh/en 切换，记 TD-157（P3）。本阶段未做：知识库/知识图谱平台、AI 自动写作、CRM、工作流审批、DAM、高级搜索、Marketplace、Canvas（均不在 v1.0 范围）。

---

**STOP，等待用户对 18R-2c 做 Gate 裁定。** 不自动进入 18R-3 / 19A；rc1(`965d63c`) / remote / push / release 全 HOLD，DIR B ignored。
