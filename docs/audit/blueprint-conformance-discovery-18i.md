# P-STEP 18I Discovery — Blueprint Conformance + Historical Debt + Final UAT

> 文档性质：**只审计、不新增功能、不碰发布工程**。本文是 18I 启动前的蓝图对拍现状盘点、RBAC / 版本边界裁定建议与任务清单。
> 基线：HEAD `5e7d84f`（= annotated tag `checkpoint-18H-3`）；Regression **1016 passed / 5176 assertions / 0 failed / 0 skipped**；Worktree clean。
> `v1.0.0-rc1` = `965d63c` HOLD；无 remote、未 push、未 Release。
> 日期：2026-09-24。

---

## 1. 历史蓝图的真实载体（已逐一重读）

仓库中没有单一命名为 "Blueprint" 的文件；蓝图要求由以下文档群共同承载，本轮已重新读取，不以旧报告印象为准：

| 蓝图载体 | 承载的蓝图要求 |
| --- | --- |
| 用户总蓝图《GEO Website OS Final Product Capability Matrix + Design System Blueprint v1.0》（§31 / §33） | 蓝图核对总清单：RBAC 三角色+Site Membership、Theme、Plugin、Installer、Upgrade、Backup、Rollback、SEO、GEO、Entity、Multi-Site、i18n、Search、Content、Forms、Media、Page Composition；18D–18J 任务编号 |
| `docs/audit/admin-management-completion-design.md`（P-STEP 17 蓝图） | §4 权限模型三角色（System Admin / Site Admin / Editor）+ 站点成员；§3 IA；§6 七大管理域；§12 总验收 |
| `docs/audit/runtime-architecture-closure.md`（P-STEP 14 蓝图） | Catalog 站点隔离读模型、插件 per-site 路由隔离、withSite 进程状态隔离 |
| 各 P-STEP Gate 文档（18A–18H-3） | 各能力域的施工与验收证据 |
| `docs/audit/history/CHANGELOG-legacy-pre-productization.md` | 产品化前历史脉络（归档、export-ignore） |

---

## 2. 蓝图 → 当前实现 对拍矩阵（基于真实代码盘点）

状态口径：**MATCH / PARTIAL / MISSING / SUPERSEDED / DEFERRED / WONTFIX**。

| # | 蓝图要求 | 当前实现（真实证据） | 状态 |
| --- | --- | --- | --- |
| 1 | **RBAC 三角色 + Site Membership**（admin design §4 / §12.2） | 两级：`User.is_super_admin` + `EnsureSuperAdmin`（跨站）/ `EnsureAdmin`（登录）；无角色表、无站点成员关系（TD-14） | **PARTIAL**：核心安全边界 MATCH；三角色+成员 **DEFERRED v1.1** |
| 2 | Site 多站基础 | `Site` 模型 + `ResolveSite` / `SiteResolver` / `SiteContext` / `SiteScope` + `BelongsToSite` | **MATCH** |
| 3 | Theme 引擎 | `ThemeManager`（per-site `theme_active`、覆盖+回退）+ `ThemePresets` + `ThemePalette` | **MATCH** |
| 4 | Plugin 引擎 | `PluginManager`（注册/授权分离）+ `EnsurePluginEnabled` per-site 守卫 | **MATCH** |
| 5 | Installer | 命令 `geo:install`（`GeoInstall`，签名含站点名/管理员参数） | **MATCH**（18I-10 全新复跑取证） |
| 6 | Upgrade | 命令 `geo:upgrade`（`GeoUpgrade`，含 view/PageCache 清理） | **MATCH** |
| 7 | Backup | 命令 `geo:backup`（`GeoBackup`） | **MATCH** |
| 8 | Rollback | 命令 `geo:rollback`（`GeoRollback`） | **MATCH** |
| 9 | SEO 统一输出 | `SeoMeta` + `SeoMetaResolver` 单一来源；系统页 SEO 双轨已清零（TD-61 CLOSED） | **MATCH** |
| 10 | GEO（AI-readable） | `GeoGraphBuilder` + Entity / Fact / Relation / Evidence / Context，非 Meta 堆砌 | **MATCH** |
| 11 | Entity 体系 | `Entity` + `EntityRelation` + `Catalog` 单向读模型 | **MATCH** |
| 12 | Multi-Site 隔离 | SiteScope + 跨站隔离测试群（Entity/Catalog/Cache/Search） | **MATCH** |
| 13 | i18n（zh-CN / en） | `LocaleRegistry` + `SetLocale` + URL 前缀契约 + 同表多行翻译模型 + hreflang | **MATCH** |
| 14 | Search | FTS5（`SqliteFtsEngine`）+ `SearchEngineInterface` + Content/Entity 统一、DB 分页 | **MATCH**；Page/Landing 索引 **DEFERRED v1.1（TD-79）** |
| 15 | Content | `Content` + `Category` + `Group`，article/page 收敛 | **MATCH** |
| 16 | Forms 产品化 | `Form` / `FormField`（每 locale 行）/ `FormSubmission`（payload 全量）+ Inquiry 投影 + 可选通知 | **MATCH** |
| 17 | Media | `Media` + `ImageOptimizer` + WebP | **MATCH**；logo media ID 统一 / srcset **DEFERRED v1.1（TD-76）** |
| 18 | Page Composition | `Page` + `TemplateRegistry` + `BlockRegistry` / `CompositionRenderer`（#116 CLOSED） | **MATCH**；首页旧装修器迁移 **DEFERRED v1.1（TD-70）** |

**对拍汇总**：

- **MATCH = 17 项**（#2–#18 的核心能力全部落地）。
- **无 MISSING**。
- **PARTIAL×1（#1 RBAC）**：仅蓝图三角色 + Site Membership 未实现，但"跨站 vs 本站"的核心安全边界已由两级 + 中间件覆盖；增强项 DEFERRED v1.1。
- 其余 DEFERRED 均为各能力的**增强项**（Page/Landing 搜索、logo srcset、首页旧装修器迁移），不削弱核心契约。

---

## 3. Runtime Hardcoding Scan（18I-05 取证，已实跑）

对运行时权威目录扫描强身份词（Demo Tenant A / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Road / 金家街 / Sample SaaS / Sample City / Sample Province / Demo Tenant A / 400-001-3770 / sample-city / sample-province）：

| 目录 | 命中 |
| --- | --- |
| `app/` | **0** |
| `resources/` | **0** |
| `config/` | **0** |
| `routes/` | **0** |
| `database/seeders/` | **0** |

- 业务强身份污染在 Runtime 权威目录 = **0**。
- 视觉 hex / 品牌 / URL / SEO / GEO 写死已在 18D / 18E 清零，并由 `BusinessPollutionZeroTest`、`FrontendBackendClosure18ETest`、`FeedPublicRenderContractTest` 等护栏固化；18I Gate 阶段做一次复核，不重复造审计。
- 豁免区（不判污染）：tests 负向护栏、`docs/audit/**`（export-ignore）、历史 migration、Example Demo 数据。

---

## 4. RBAC 裁定建议（18I-03）

**建议：v1.0 维持两级，三角色 + Site Membership 转 v1.1（TD-14 维持 DEFERRED）。**

依据：

1. 产品定位为**可自部署、单组织开源**系统；v1 主形态是单一组织自建多站、由超级管理员集中管理站点与全局配置。
2. 两级已覆盖最关键安全边界：`is_super_admin`（跨站 / 站点管理 / 用户）vs 普通管理员（本站内容运营）；`EnsureSuperAdmin` 对非超管跨站操作一律 403。
3. 三角色（owner/admin/editor）+ Site Membership 属于"多人、多组织协作"场景，与 Theme/Plugin SDK、Marketplace、Admin i18n 同属 v1.1 协作能力族，机械塞入 v1.0 会引入角色表、成员关系、全站策略重挂的二次改动面。
4. 台账保留 TD-14，重启条件明确：v1.1 多人协作 / 多组织托管需求成立时，补角色模型 + 站点成员关系 + 越权测试。

> 此为 Discovery 建议，**最终由用户裁定**；若用户要求 v1.0 补三角色，再进入独立实现阶段。

---

## 5. v1.0 / v1.1 边界建议（18I-04）

- **v1.0 代码层产品能力已全部 CLOSED**：Design System、Light/Dark、行业预设、自定义品牌、前后台闭环、i18n、Page Composition、Search(FTS5)、Forms、Media、Analytics、Audit、Cache、Installer/Upgrade/Backup/Rollback。
- **v1.0 仅剩发布工程（非功能开发）**：TD-01（Cloud CI 首跑）、TD-02（基于最终 HEAD 重建 RC + Manifest + SHA-256）、TD-03（Private 全验证 → 转 Public / v1.0.0）。
- **v1.1+ Planned（不阻塞 v1.0）**：TD-06、TD-14（RBAC）、TD-15、TD-16②③④、TD-17、TD-18、TD-19、TD-20②③④、TD-36、TD-37、TD-38、TD-46、TD-70、TD-75、TD-76、TD-79、TD-87。
- 边界原则：若某项 v1.1 被证伪为直接破坏 v1.0 核心产品完整性（换品牌/行业/语言必须改核心代码），不得机械降级，凭证据重新裁定。当前未发现此类项。

---

## 6. 18I 正式任务清单（Gate 阶段执行）

| 任务 | 内容 |
| --- | --- |
| 18I-01 | Blueprint audit：以本对拍矩阵为基线，Gate 复核证据 |
| 18I-02 | Historical debt audit：复核 TD-01~98 全台账 + 历史 CHANGELOG，确认无遗漏 / 无擅自销项 |
| 18I-03 | RBAC decision：记录用户对两级 / 三角色的最终裁定 |
| 18I-04 | P3 / v1.1 boundary：冻结 v1.0 / v1.1 边界 |
| 18I-05 | Runtime hardcoding scan：强身份词已 =0；Gate 补视觉 / URL / SEO 复核 |
| 18I-06 | Browser UAT：真实浏览器全站 + zh/en × Light/Dark 四组合 + **陌生品牌零代码搭站**全链路 |
| 18I-07 | Multi-site UAT：Site A / B 隔离（内容 / 设置 / 主题 / Search / Feed 不串） |
| 18I-08 | Blank / Demo separation：两态 Fresh + HTTP 对拍 |
| 18I-09 | Full regression：以 1016 为基线，只增不减、0 failed / 0 skipped |
| 18I-10 | Fresh install：全新空文件 `geo:install`，验证中性出厂 + 后台可登录 |

**Gate 必交证据**：focused / full regression、fresh install、blank site、demo site、real HTTP、real browser、multi-site、SEO、GEO、Schema、Feed、performance、pollution、logs、cleanup、git diff/status。

---

## 7. Discovery 结论

1. 蓝图 18 项核心能力 **17 MATCH、1 PARTIAL（RBAC 增强项）、0 MISSING**；所有 PARTIAL/DEFERRED 均为增强项，不破坏"换品牌 / 行业 / 语言 / 视觉 / 页面结构不改核心代码"的 v1.0 产品契约。
2. Runtime 强身份污染 = 0；运维四命令（install/upgrade/backup/rollback）真实注册。
3. 建议 RBAC v1.0 维持两级、三角色转 v1.1；v1.0 仅剩 TD-01/02/03 发布工程。

> **Discovery 完成 → STOP。** 汇报对拍结论与 RBAC / 边界建议，待用户裁定并授权后，进入 18I Gate 验证；不自动开始，不进入 19A，不碰 rc1 / remote / push / Release。
