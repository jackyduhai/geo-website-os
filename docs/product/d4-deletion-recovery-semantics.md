# D4 交付物：Deletion / Recovery Semantics + Trash 对 Public Knowledge Set 的影响

- **状态**：D4 COMPLETE（2026-10-08）· **语义研究，非回收站设计**
- **事实基线**：`d2-public-surface-inventory.md`（D2）· `d1-lifecycle-domain-model.md`（D1）
- **章程**：`docs/product/stage1-discovery-charter.md` D4
- **D4 的真正目标（不是「设计回收站」）**：

> 回答「一个对象从**存在 → 删除 → 恢复 → 永久清除**的全过程，
> 会如何改变 Public Knowledge Set，以及所有派生关系和机器可读出口。」

---

## 零、可提前冻结的原则（本轮证据已足够）

> **删除不是数据库操作，而是 Public Knowledge Set 的变更事件。**

```text
传统 CMS                GEO Web OS
Delete = 数据库行没了     Delete = 公开知识边界改变
                         = Frontend + GEO + LLM + Schema
                           + Sitemap + Search + Relations + Cache
```

**这不是修辞，D4 探针已实证**：删除与恢复**确实**触发全部出口重算（见第四节）。

---

## 一、D4-1：谁是真正的「可删除主体」

**全库唯一有 `deleted_at` 列的表是 `contents`，唯一启用 `SoftDeletes` 的模型是 `Content`。**
（实测遍历 36 张表 + 全部 Model）

| 主体 | 可独立删除 | 删除方式 | 可Restore | 是否经 Trash | 派生自父级 |
|---|---|---|---|---|---|
| **Content** | ✅ | **logical**（SoftDeletes） | ✅ `restore()` 可用 | 半（**无 UI 入口**） | — |
| **Entity** | ✅ | **physical** | ❌ | ❌ | — |
| **Page** | ✅ | **physical** | ❌ | ❌ | — |
| **Page** | ✅ | **physical**（但模型有 `deleting` 钩子级联清理） | ❌ | ❌ | ✅ blocks + SeoMeta 由 `Page::booted()` 清理（**全库最规范**） |
| **Block** | ✅ | **physical** | ❌ | ❌ | ✅ 随 Page（由 Page 钩子级联） |
| **Fact** | ❌ **无独立入口** | 随宿主 | — | — | ✅ 随 Entity/Content |
| **Relation** | ❌ | **随端点 cascade** | ❌ | 自动 | ✅ 随两端实体 |
| **Media** | ✅ | **physical + 删文件** | ❌ | ❌（**有引用保护**） | — |
| **SeoMeta** | ✅ 间接 | 随宿主 | — | — | ✅ |
| **Revision** | ❌ **无独立入口** | 随 Content（FK） | — | — | ✅ |
| **Inquiry** | ✅ | **physical** | ❌ | ❌ **且无任何保护** | — |
| **AuditLog** | ❌ 无入口 | 随站点 | — | — | ✅ |

### 三个必须记录的发现

**① Content 的软删没有任何 UI 入口**

```text
全库检索 restore / trashed（Controller + Blade）：0 处
但ContentController:174 的提示语却写：
  「已删除（**可在回收站恢复**，如需彻底删除请联系技术）」
```

**⇒ 提示语承诺了一个不存在的功能。** 用户会去找，找不到。
这不是技术债，是**产品承诺与实现不符**。

**② Inquiry 可被无保护删除**

```php
// InquiryController:54-61
public function destroy(Inquiry $inquiry): RedirectResponse {
    $id = $inquiry->id;
    $inquiry->delete();                       // 无引用检查、无审计留存、无二次确认
    AuditLog::record('inquiry.delete', "删除询盘 #{$id}");
```

对比 Media 删除有完整的 `MediaReferenceScanner` 引用保护 + 阻断。
**询盘是企业资产，删除却比图片更随意。**

**③ Media 已有引用扫描保护（正向发现）**

```text
MediaController:125-127  MediaReferenceScanner::for($media)
  → 有引用则**拒绝删除**并列出引用位置
  → 历史版本快照引用仅告警不阻断
```

**⇒ 这正是 Roadmap 里「Media Library = Usage Graph」的雏形。**
它已经存在，只是没有作为独立的「谁在引用我」能力被提升。

---

## 二、D4-2：SoftDeletes 是否应成为统一删除原语

**不采用「所有表都加 SoftDeletes」。** 逐类判断业务语义：

| 类别 | 主体 | 需要可恢复？ | 理由 |
|---|---|---|---|
| **内容资产** | Content | ✅ 必须 | 企业内容投入成本高，误删代价大 |
| **知识资产** | Entity | ✅ **应该** | 实体是 GEO 答案的来源，删了AI 答案就变 |
| **结构资产** | Page | ⚠️ 待 D5 | Page 常由 recipe 生成，可再生 |
| **派生** | Block | ❌ | 随 Page，不应独立进 Trash |
| **派生** | Relation | ❌ | 随端点，独立 Trash 会产生孤儿边 |
| **二进制** | Media | ⚠️ 待裁决 | 引用保护已存在，但无恢复能力 |
| **业务数据** | Inquiry | ❌ **不应有 Trash** | 属业务/合规数据，不是展示内容 |
| **审计** | AuditLog | ❌ **绝不应有 Trash** | 有留存要求 |

### 结论

```text
SoftDeletes 应成为「内容资产类」的原语（Content / Entity）
但不应覆盖全部表——
  派生对象走 cascade
  业务数据（Inquiry）走显式删除 + 审计留存
  审计数据（AuditLog）永不进 Trash
```

**⇒ 「统一删除原语」的真正含义是统一「内容资产类」的行为，
不是给每张表加列。**

---

## 三、D4-3：删除后 Public Knowledge Set 发生什么

### 探针实测（v3，四阶段完整验证）

样本：organization + product（core）+ product（peer）+ EntityRelation
+ knowledge分类 + article + content_entity边

**阳性对照已PASS**（BEFORE 时 llms / geo / sitemap 全部命中目标）

| 阶段 | PublicIndex含 target | 含 knowledge | geo entities 含 target | geo relations | llms 含 target | sitemap 含 knowledge | relation 行 |
|---|---|---|---|---|---|---|---|
| **1 BEFORE** | ✅ | ✅ | ✅ | 1 条 | ✅ | ✅ | 存在 |
| **2 实体删除后** | ❌ | ✅ | ❌ | **[]** | ❌ | ✅ | **已删** |
| **3 内容软删后** | ❌ | ❌ | ❌ | [] | ❌ | **❌** | 已删 |
| **4 内容restore 后** | ❌ | ✅ | ❌ | [] | ❌ | **✅** | 已删 |

### 三个关键结论

**① 实体物理删除会连带清除关系行（FK cascade 实测生效）**

```text
建关系后行数: 1
删除 A 后该关系行数: 0        ← cascade 真的生效（开发库 SQLite 实测）
```

**② Restore 后出口是「重算」而非「恢复快照」—— 这是正确行为**

```text
第 3 阶段：sitemap 含 knowledge = false
第 4 阶段：sitemap 含 knowledge = true← 重新计算得出的
```

**③ 但暴露一个真实的不对称**

```text
删除实体 → relations 行被物理清除（不可恢复）
恢复实体 → **不可能**，因为实体本身已物理删除
⇒ Relation 的可恢复性完全依赖端点，端点物理删除 = 关系永久丢失
```

**这正是 D4-5 必须裁决的核心：Purge Entity 时，Relation 该CASCADE 还是 PRESERVE。**

---

## 四、D4-4：Restore 后是否自动恢复机器可读语义

### 答案：**是，且是重算**（已实证）

```text
content->delete()  → idxHasKnow=false, sitemap=false
content->restore() → idxHasKnow=true,  sitemap=true✅
```

**这符合用户提出的正确答案**：

> 重新计算，而不是复制旧快照。

**为什么必须是重算**：

```text
若restore 走「恢复快照」
  → 快照可能已过期（分类被删/ noindex 被改 / 栏目被停用）
  → 会产生「恢复后仍不该公开却公开」的旧数据泄漏

若 restore 走「重算」
  → 当前状态重新参与判定→ 语义永远一致
```

**但当前实现有一个隐患**：

```php
// ContentController::168-176
$content->delete();
AuditLog::record(...);
return redirect()->...;          // ← 无 PageCache::flush()
```

对比 `PageController::destroy` 有 `PageCache::flush()`，
`EntityController::destroy` 有 `resetReadModels()`（Catalog::flush + SEO memo）。

**⇒ Content 删除不刷缓存、不更新 Search 索引。**
探针中出口正确是因为测试环境每次重新查询，
**真实HTTP 场景下 PageCache 可能仍返回删除前的页面**。

这是**本轮发现的第4 处缺口**，编号 G-5。

---

## 五、D4-5：Purge 的边界（Cascade / Detach / Preserve 矩阵）

### 逐类判断（当前实际行为 vs 应有）

| 关联数据 | 当前 Purge 实体时 | 合理预期 | 判据 |
|---|---|---|---|
| `entity_relations` | **CASCADE**（FK） | ⚠️ **待裁决** | 关系是结构还是数据？ |
| `content_entity` | CASCADE（FK） | DETACH | 内容还在，关系不该随之消失 |
| `facts` | CASCADE（FK） | ⚠️ **待裁决** | **AI 答案的事实来源** |
| `contents` | 不受影响 | PRESERVE | 内容独立生命周期 |
| `pages` / `page_blocks` | 不受影响 | PRESERVE | 页面独立 |
| `media` | 不受影响 | PRESERVE | 二进制资产独立 |
| `content_revisions` | CASCADE（FK） | RETAIN FOR AUDIT | 历史版本有审计价值 |
| `inquiries` / `attribution` | 不受影响 | **PRESERVE** | **业务/合规数据** |
| `audit_logs` | 不受影响 | **RETAIN FOR AUDIT** | 审计留存 |

### 两个必须显式裁决的问题

**① Fact 能否随 Entity 一起物理删除？**

```text
facts 是 Business Fact 唯一 SoT（D-02 架构锁定）
它是 AI 答案的事实来源
删除 Entity → facts 消失 → **AI 的答案静默改变**
```

**这比G-1/G-2/G-3 更危险**：那三处是「多输出了不该输出的」，
这里是「事实消失了，AI 会给出一个「更少事实」的答案，且没有任何错误信号」。

**⇒ 建议：Fact 必须 DETACH 或 PRESERVE，绝不 CASCADE。**
但这是D5 / 架构决策范畴，D4 只提出问题。

**② content_entity 级联是否合理？**

```text
Entity 被 Purge → content_entity 行消失
但 content 还在（published）
⇒ 内容失去「about 哪些实体」的声明
⇒ Schema / GEO 里该内容的 about 节点静默变空
```

**与 G-1/G-2/G-3 同类**：不是数据错，是**语义边界被静默改变**。

---

## 六、D4-6：物理删除是否还能存在

### 必须明确回答的问题

> Entity 当前物理删除，是**历史实现**还是**有意的业务语义**？

**代码证据表明它不是有意设计，而是历史默认**：

```text
① Entity 模型未启用 SoftDeletes（Content 启用了）
② EntityController::destroy 没有任何注释说明为何物理删
   只说「entity_relations 两端外键为 ON DELETE CASCADE，关联关系随实体自动清除」
   → 这是**对现状的描述**，不是对理由的论证
③ 反观ContentController:190对 scheduled 有明确的设计意图注释
   （BUG-20B-004 + 「v1.1 完整支持」）
   → 可见本项目**有能力**写清楚设计意图，Entity 只是没写
```

### 三种可能结论，D5 / 架构决策必须选一个

```text
A. Entity 需要 Trash → Restore
   ⇒ FK cascade 物理删除必须被重新定义
   ⇒ Content 与 Entity 统一用 SoftDeletes

B. Entity 是不可恢复对象（如系统生成的派生实体）
   ⇒ 必须**显式写进 Lifecycle Contract**
   ⇒ 不能让数据库 FK 偶然决定业务规则

C. 按 Entity type 分别决定（如 organization 可删、product 不可删）
   ⇒ 需要 type × 策略矩阵，复杂度最高
```

**当前状态是「A、B、C 都不是，是数据库默认值在替业务做决定」。**

**⇒ D4 结论：必须显式裁决，不允许继续由 FK 偶然决定。**

---

## 七、四份交付物汇总

### D4-1 Deletion Semantics Matrix✅

见第一节。**全库唯一软删是 `contents`；其余全physical。**

### D4-2 Recovery / Restore Matrix ✅

```text
可恢复：Content（技术可行，**无 UI 入口**）
不可恢复：Entity / Page / Block / Media / Inquiry / Relation
```

### D4-3 Cascade / Detach / Preserve Matrix ✅

见第五节。**最需裁决：Fact 与 content_entity。**

### D4-4 Trash → Public Knowledge Set Impact ✅

```text
删除 → 全部出口撤出（已实证）
恢复 → 全部出口**重算**回来（已实证，正确）
隐患 → Content 删除不刷PageCache、不更新 Search 索引（G-5）
```

---

## 八、总表（用户要求的合并矩阵）

| 主体 | Delete | Trash | Restore | Purge | Related Data | Public Knowledge |
|---|---|---|---|---|---|---|
| **Content** | soft ✅ | 半（无 UI） | ✅ 技术可行 | ✅ | revisions CASCADE | remove / **recompute** ✅ |
| **Entity** | **physical** | ❌ | ❌ | FK cascade | relations CASCADE<br>content_entity CASCADE<br>**facts CASCADE ⚠️** | remove / **不可恢复** |
| **Page** | physical | ❌ | ❌ | — | blocks **物理残留 ⚠️** | remove |
| **Block** | derived | derived | derived | derived | — | inherit |
| **Fact** | derived | derived | derived | **CASCADE ⚠️ 需裁决** | host-bound | inherit（但宿主删除即消失） |
| **Relation** | derived | derived | derived | cascade | endpoint-bound | inherit |
| **Media** | physical + 删文件 | ❌ | ❌ | 引用保护 | 有 Usage Scanner | remove |
| **Inquiry** | physical | **不应有** ❌ | ❌ | — | attribution | **不参与**（非展示内容） |
| **AuditLog** | 无入口 | **绝不应有** | — | — | — | 不参与 |
| **SeoMeta** | derived | derived | derived | cascade | host-bound | inherit |

### 探针查出的两个新问题（须记入台账）

```text
G-5  Content 删除不刷 PageCache、不更新 Search 索引
     （Entity 有 resetReadModels，Page 有 PageCache::flush，Content 两个都没有）
     探针中出口正确是因为测试环境每次重新查询；
     真实 HTTP 场景下 PageCache 可能仍返回删除前的页面。

G-6  ❌ **已撤销（误报）** —— 原怀疑「Page 删除后 page_blocks 残留」
     追查发现 `Page::booted()` 有 deleting 钩子：
       static::deleting(fn(Page $p) => PageBlock::where('page_id',$p->id)->delete());
     开发库实测：删 Page 后 block 行确实消失。
     且探针 v3 观察到的 17 → 16 正是这个级联在工作。
     ⇒ **Page 反而是全库删除语义最规范的主体**（模型层自动级联，
       不依赖数据库 FK，也不依赖控制器记得调用）
     记录此误报是为了留痕：**「缺口」必须追到根，不能停在现象。**
```

---

## 九、D4 移交给 D5 / D3 的裁决项

```text
→ D5① Fact 是否可随 Entity CASCADE（AI 答案会静默变少，最危险）
→ D5② content_entity CASCADE 是否合理（内容失去 about 声明）
→ D5③ Entity 物理删除是历史实现还是业务语义（A/B/C 三选一）
→ D5④ Inquiry / AuditLog 是否应有 Trash（我倾向：不应）
→ D5⑤ scheduled 未到点 / trash 状态下 Frontend Access 语义
→ D3  Scheduled 语义已存在于 Content::published()，是「解锁」不是「加字段」
```

**G-6 已在 D4 内追清并撤销**（Page 有模型级级联钩子，是全库最规范者）。

---

## 十、本文档不做的事

```text
❌ 不设计回收站 UI / API        —— D4 只研究语义
❌ 不改任何代码 / 测试          —— 按章程要求
❌ 不新增字段（含 trashed_at）   —— D1 已确认应推广 SoftDeletes 而非加列
❌ 不修 G-1~G-3 / G-5 / G-6
❌ 不给「Entity 该不该有 Trash」下结论 —— 那是 D5 / 架构决策
❌ 不打 tag
```

**验证方式**：第四节探针的四阶段行为必须可复现。
若后续实现后行为改变（尤其是 restore 从「重算」变成「快照恢复」），视为契约破坏。
