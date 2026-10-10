# 阶段 3 · Media / Derived-System Architecture — C1–C4 架构裁决

| 状态 | DECIDED / CLOSED |
| --- | --- |
| 日期 | 2026-10-10 |
| 上游 | Legacy Inventory（`94d761a`）、D5-7 Media Lifecycle（P18/G-12）、B3（`ecdff28`）、B4（`35c6137`） |
| 取证方式 | 直接读取当前仓库（`D:\GEO-OS-rewrite\redact-v1`，main）真实模型 / 配置 / 控制器 / 命令 |
| 本阶段范围 | 只读取证与裁决；**不改产品代码、不新增表 / 字段 / 迁移 / 服务**；不实施 Scanner 第 8 路 |
| MRF 基线 | 61（未变更） |

**两条贯穿约束（冻结）**：① 不以去重 / reconciliation 为由自动删除资产；② 不以缓存优化为由牺牲公共知识输出的正确性。

---

## C1 · 存储身份 / S3

### 取证事实

- `media` 表有 `disk` 列（默认 `public`）；`config/filesystems.php` 已定义 `s3` 盘（占位/参考配置）。
- 磁盘归属处理**不一致**：

| 位置 | 磁盘处理 | 结论 |
| --- | --- | --- |
| `MediaAssetPurger` | `Storage::disk($media->disk)` | ✅ 磁盘感知 |
| `ImageOptimizer::store` | `$file->store($dir, 'public')` | ❌ 硬编码 public |
| `ImageOptimizer::webpUrl` | `Storage::disk('public')` | ❌ 硬编码 public |
| `Media::url()` | 恒拼 `/storage/{path}` | ❌ 无 S3/远程 URL 解析 |
| 各上传控制器（Media/Content/Setting） | `Media::create(['disk'=>'public'])` | ❌ 硬编码 public |

### 裁决

- **S3 为可选能力，当前不强制引入、也不得声称已可用。**
- 当前受支持路径为**本地 public 盘**；S3 配置仅是占位。
- 未来启用 S3 前，必须另开架构决策，先闭合磁盘抽象缺口：上传落盘、`Media::url()` 远程/临时 URL 解析、WebP 生成位置、purge 跨盘处置；并保证一个 Media 记录明确其所属存储资产，不默认假定当前 disk。
- 禁止在未闭合上述缺口时仅靠配置切换 disk（会导致 DB 指向 S3、文件仍在本地、URL 仍拼 `/storage` 的分叉）。

---

## C2 · 去重（Deduplication）

### 取证事实

- 上传走 `$file->store($dir, 'public')`，文件名由 `hashName()` 随机生成；**无内容哈希比对**。
- 同一二进制上传 N 次 → **N 条 Media 记录 + N 份原文件 + N 份 WebP**，彼此独立。

### 裁决

- **当前不启用跨记录 / 跨站自动物理去重；保持「一次上传一条记录」。**
- 去重前提是先建立**内容哈希身份**（如 sha1 列）与**共享 / 所有权策略**；跨站共享媒体会改变删除语义（一个站点删除会影响另一站点）。
- 在缺少哈希身份与引用感知策略前合并记录，会破坏引用关系并误删资产。
- 未来若实施，须：加哈希身份 + 仅同站共享 + 引用感知删除（沿用 MediaReferenceScanner），并另行授权。

---

## C3 · 孤儿文件 Reconciliation

### 取证事实

- 潜在漂移四类：① DB 有记录、文件缺失；② 文件存在、无 DB 记录；③ WebP sibling 残留、原文件已无；④ `delete_failed_at` 非空的失败删除行。
- **当前无 reconciliation 命令**；失败删除行已由 `media:retry-deletes`（`RetryMediaDeletes`）幂等收口。

### 裁决

- **GO：只读 reconciliation 报告**——按上述四类盘点、分类、列出，不修改任何数据。
- **NO-GO：自动删除**——未经明确确认 / 授权，不得自动清理文件或记录（可能误删有效资产）。
- 任何清理动作必须：基于分类报告 → 明确授权 → 留审计；失败删除继续走重试命令。

---

## C4 · 缓存失效精细化

### 取证事实

- `CacheInvalidationMap::register()` 对约 16 个模型的 saved/deleted 统一调用 `PageCache::flush()`（**整站版本 +1、全缓存作废**）——**正确但粗粒度**。
- 路径级能力已存在：`PageCache::forgetPath / forgetPage / forgetTemplate`；`PageController` 已部分使用 `forgetPage`，但通用模型事件接线仍只走整站 flush。

### 裁决

- **优先保证正确失效**：当前整站 flush 是安全的（后台改完前台必生效），予以保留。
- **精细化（路径级）须证据驱动**：仅在有性能 / 缓存命中证据证明整站 flush 成本过高时才推进，且必须保持失效正确性（变异验证：漏失效时旧页面不得继续命中）。
- 不得为优化粒度而引入漏失效风险；具体接线方案留待独立实施授权。

---

## C1–C4 裁决汇总

| 项 | 结论 |
| --- | --- |
| C1 存储 / S3 | 本地 public 盘为当前支持路径；S3 可选，未闭合磁盘抽象前不启用、不声称可用 |
| C2 去重 | 不自动去重；保持一次上传一条记录；需哈希身份 + 同站共享 + 引用感知，另行授权 |
| C3 孤儿 reconciliation | GO 只读报告；NO-GO 自动删除；清理须授权 + 审计 |
| C4 缓存精细化 | 保留正确的整站 flush；路径级精细化证据驱动，不牺牲正确性 |

---

## 关闭结论与 STOP

| 项目 | 状态 |
| --- | --- |
| 阶段 3 C1–C4 架构裁决 | ✅ **DECIDED / CLOSED** |
| 产品代码 / 迁移 / UI | 0 改动 |
| Scanner 第 8 路实施 | ⛔ 下一阶段，须独立授权（B3 已 GO） |
| Release Readiness 审核 | ⛔ 未授权（在 Scanner 实施之后） |
| Remote / Push / Release | ⛔ STOP |

**当前动作：STOP。** 阶段 3 收口，不自行实施 Scanner 第 8 路，不修改产品代码；发布链路保持冻结。串行顺序下一步为 **Scanner 第 8 路实施**，等待明确授权。
