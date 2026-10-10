# 阶段 2 · B3 media_refs / taxonomy_refs — 架构裁决

| 状态 | DECIDED / CLOSED |
| --- | --- |
| 日期 | 2026-10-10 |
| 裁决 | ① media_refs 独立通用表 = **NO-GO**；② 扩展现有 MediaReferenceScanner 覆盖 PageBlock 媒体引用 = **GO（实施另行授权）**；③ taxonomy_refs 独立通用表 = **NO-GO** |
| 上游 | B1 Reference Inventory（`db8aba5`，R6/R9 GAP）、D5-7 Media Lifecycle（P18/G-12）、D5-6 Page Lifecycle（P17/G-11） |
| 取证方式 | 直接读取当前仓库（`D:\GEO-OS-rewrite\redact-v1`，main）真实模型 / 迁移 / 配置 / Blade |
| 本阶段范围 | 只读取证与裁决；**不改产品代码、不新增表 / 字段 / 迁移 / 服务**；不提前进入 B4 |
| MRF 基线 | 61（未变更） |

---

## 1. 裁决问题

B3 要解决 B1 定位的 R6/R9 GAP：**`PageBlock.content` JSON 中的媒体 ID 不在 MediaReferenceScanner 七路内，导致删除媒体时「删除未阻断 + 页面图片静默消失」。**

同时复核：现有 Taxonomy（Category / Group / Tag）是否存在需要新增 taxonomy_refs 表的缺口。

---

## 2. 源码级事实基线

### 2.1 媒体引用点是声明式契约，可确定性提取

`config/blocks.php` 中字段 `type = 'media'` 明确声明了全部组合块媒体引用：

| Block 类型 | 媒体字段路径 | 形态 |
| --- | --- | --- |
| `hero` | `image_id` | 顶层 int |
| `image` | `media_id` | 顶层 int |
| `media_text` | `media_id` | 顶层 int |
| `logo_cloud` | `items[].media_id` | items 嵌套 int |

- 写入路径：`PageController::extractConfig` / `extractItems` 按字段类型把 media 存为 int（顶层 `media` → `(int)`；items 内 `media` → `(int)`）。
- 渲染路径：`site/blocks/{hero,image,media_text,logo_cloud}.blade.php` 用 `Media::find()` 解析，均容错（缺失不 500）。
- 类型注册由 `BlockRegistry`（`app/Support/Blocks/BlockRegistry.php`）从 `config('blocks.types')` 加载，`BlockType` 暴露 `fields`。

**结论：媒体 ID 可由「遍历 BlockRegistry 字段 schema → 定位 media 字段路径（含 items 嵌套）→ 从 content JSON 读取」确定性提取，不依赖 NLP 或纯字符串猜测。**

### 2.2 page_blocks 表结构

`page_blocks`：`id / page / type / title / subtitle / content(JSON) / category_id / limit / sort / is_active / site_id / page_id / slot`，无 locale 列；媒体引用直接存于 content JSON。

### 2.3 Taxonomy 现有结构（关系型，无媒体引用缺口）

- `Category`：`parent_id` 自引用层级；`content.category_id`（DB FK CASCADE）；`Group` 归属 category（FK CASCADE）。
- `content_tag`：Content ↔ Tag 多对多 pivot（唯一约束）。
- `Category` 仅有 `icon`（图标库 key，非 Media），**无 image / thumbnail / media 字段**。
- 这些关系均有 DB FK、明确所有权与删除语义，B1 已标 VERIFIED。

---

## 3. 裁决一：media_refs 独立通用表 = NO-GO

- 问题本质是「现有删除资格权威（MediaReferenceScanner）漏了一条真实引用路径」，不是「缺少一张通用引用表」。
- 新建通用 media_refs 表会：复制一份本应由各领域拥有的引用关系，制造第二 SoT；并把 content 内联图、实体 metadata、组合块等不同语义的引用抹平。
- 正确做法是**补全现有权威入口**，而非新增并行结构。

---

## 4. 裁决二：扩展 MediaReferenceScanner 覆盖 PageBlock = GO（实施另行授权）

在 MediaReferenceScanner 增加**第 8 路**，把组合块媒体引用纳入统一删除资格判定。

### 4.1 提取契约（结构化，主路径）

- 新增提取器（如 `PageBlockMediaRefExtractor`）：
  1. 遍历 `BlockRegistry::all()`；
  2. 对每个 BlockType，收集 media 字段路径 —— 顶层 `fields` 中 `type='media'` 的 key，以及 `type='items'` 字段的 `item_fields` 中 `type='media'` 的嵌套路径；
  3. 对本站全部 `PageBlock` 行（见 §4.3），解码 content，按上述路径读取媒体 ID；
  4. 命中即返回 `{model: PageBlock, id, field: 路径, label: 页面/块类型}`，计入阻断性引用。
- 该路径与现有七路同构、同站点隔离，`hasReferences()` 自动纳入。

### 4.2 无法可靠解析时的保守兜底（安全网）

- 字段契约提取为**主路径**；对**未注册 block 类型**或 content 中出现**契约外 media 形态键**（残留 / 历史 / 未知数据），以保守的原始列扫描兜底：`content LIKE '%"media_id":N%' OR content LIKE '%"image_id":N%'`，命中即视为潜在引用（阻断或至少告警）。
- 该 LIKE **只用于无法契约解析的异常数据**，不作为全系统的完整引用谓词；方向是「不确定时保护」，只会更保守、不会放宽删除。
- 健康检查可另用 `WHERE content LIKE '%"media_id"%'` 列疑似异常行，但不替代判定。

### 4.3 覆盖范围

- 纳入**全部 PageBlock 行（含 is_active=false）**：inactive 是可恢复的持有态、可重新启用，引用仍存在；删除保护应保守阻断。
- 「媒体缺失时页面可降级渲染」≠「允许删除被引用媒体」：降级是渲染容错，不解除删除保护。

### 4.4 新引用类型的纪律

- 新增组合块媒体引用时，只要在 `config/blocks.php` 以 `type='media'` 注册，提取器即自动覆盖（注册表是唯一事实源）；不得因提取器漏注册而获得删除资格。

---

## 5. 裁决三：taxonomy_refs 独立通用表 = NO-GO

- Category 层级、Content-Category、Content-Tag、Group 已分别由自引用 / FK / pivot 表达，所有权与删除语义清晰，且均有 DB 约束。
- 不存在媒体引用或其他需要 taxonomy_refs 才能处理的缺口（Category 无 image 字段）。
- 「存在多种关系」不构成归并为通用引用模型的理由；统一框架的 Go/No-Go 留给 B4，B3 不预设。

---

## 6. 实施验收矩阵（GO 项，须另行授权与独立验收）

| 验收项 | 要求 |
| --- | --- |
| 正常路径 | hero/image/media_text/logo_cloud 引用媒体时，删除被阻断，返回 PageBlock 引用记录 |
| items 嵌套 | logo_cloud 多条目媒体 ID 全部被提取 |
| 未注册 / 契约外 | 兜底扫描命中残留 media 形态键，保守阻断 / 告警 |
| inactive 块 | 停用块的媒体引用仍被保护 |
| 分类回归 | 内联图 / 封面 / OG / Banner / 实体 / SEO / 设置七路不受影响 |
| 变异验证 | 移除第 8 路或绕过字段契约时，对应测试 MUST FAIL（证明可抓住漏扫） |
| 外部知识隔离 | 扫描与阻断不 mutate 任何 PageBlock / Page / 内容 |
| 回归 | 定向测试 + 全量串行回归 + 污染扫描 + Discovery 护栏 |
| 证据 | 本地提交 SHA、工作树状态、测试通过/断言/失败/退出码 |

---

## 7. B3 关闭结论与 STOP

| 项目 | 状态 |
| --- | --- |
| media_refs 独立通用表 | ✅ **NO-GO** |
| 扩展 MediaReferenceScanner 覆盖 PageBlock（第 8 路） | ✅ **GO（实施另行授权）** |
| taxonomy_refs 独立通用表 | ✅ **NO-GO** |
| 产品代码 / 迁移 / UI | 0 改动 |
| B4 统一 Reference Framework Go/No-Go | ⛔ 未授权，最后才裁决 |
| Remote / Push / Release | ⛔ STOP |

**当前动作：STOP。** B3 收口，不自行进入 B4，也不自行实施第 8 路；发布链路保持冻结。等待明确授权后：要么实施 Scanner 第 8 路，要么进入 B4。
