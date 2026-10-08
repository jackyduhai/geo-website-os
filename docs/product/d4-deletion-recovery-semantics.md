# D4 交付物：Deletion / Recovery Semantics + Trash 对 Public Knowledge Set 的影响

- **状态**：D4 COMPLETE（2026-10-08）· **语义研究，非回收站设计**
- **事实基线**：`d2-public-surface-inventory.md`（D2）· `d1-lifecycle-domain-model.md`（D1）
- **章程**：`docs/product/stage1-discovery-charter.md` D4
- **D4 的真正目标（不是「设计回收站」）**：

> 回答「一个对象从**存在 → 删除 → 恢复 → 永久清除**的全过程，
> 会如何改变 Public Knowledge Set，以及所有派生关系和机器可读出口。」

---

## 零、可提前冻结的原则（本轮证据已足够）

> **删除不是数据库操作，而是 Public Knowledge Set 的生命周期变更事件；
> 所有公共出口必须依据最新生命周期状态重新计算或失效。**

```text
传统 CMS                GEO Web OS
Delete = 数据库行没了     Delete = 公开知识边界改变
                         = Frontend + GEO + LLM + Schema
                           + Sitemap + Search + Relations + Cache
```

**注意措辞是「重新计算或失效」，不是单纯「重算」**——
两者是不同层面的保证，见第 0.5 节。

---

## 0.5、证据边界（重要：区分两个不同层面的保证）

### D4 **已证明**

```text
Lifecycle mutation → Public Knowledge Set **语义结果**变化
```

删除 / restore 前后，实际查询得到的 `PublicIndex`、GEO、LLMS、Sitemap 结果
会随当前生命周期状态**正确变化**。

### D4 **尚未完全证明**

```text
Lifecycle mutation → 全部cache / index / read-model **同步失效并重建**
```

**「最终查询结果正确」≠「缓存与索引已经被正确失效和重建」。**

原因：探针在测试环境每次重新查询，**绕过了 PageCache**，
也**不经过真实 HTTP 请求路径**。

### 逐项实测结论（第二轮探针，阳性对照已PASS）

| 派生物 | 是否同步失效 | 机制 |
|---|---|---|
| `PublicIndex` 查询 | ✅ **语义即时正确** | 每次查询现算 |
| GEO / LLMS / Sitemap | ✅ **语义即时正确** | 每次 build 现算 |
| **Search 索引** | ✅ **同步失效（实测 1→0→1）** | `SearchIndexSync` 的 `Content::deleted` 钩子 → `removeDocument()` 同步移除 |
| **PageCache** | ❌ **未失效（唯一真实缺口）** | `ContentController::destroy` 无 `PageCache::flush()` |

探针输出：

```text
search_before_delete  = 1
search_after_delete   = 0        ← 删除时同步移除
search_after_restore  = 1        ← 恢复时同步写回
contentHasDeletedHook = true
contentDestroyCallsFlush = false ← ContentController 里确实没有 PageCache::flush
```

**⇒ G-5 的范围必须缩小**：不是「缓存与索引都没失效」，
而是**只有 PageCache 未失效**。Search 侧有自动机制（这一点原文档也写错了）。

---

## 一、D4-1：谁是真正的「可删除主体」

**全库唯一有 `deleted_at` 列的表是 `contents`，唯一启用 `SoftDeletes` 的模型是 `Content`。**
（实测遍历 36 张表 + 全部 Model）

| 主体 | 可独立删除 | 删除方式 | 可Restore | 是否经 Trash | 派生自父级 |
|---|---|---|---|---|---|
| **Content** | ✅ | **logical**（SoftDeletes） | ✅ `restore()` 可用 | 半（**无 UI 入口**） | — |
| **Entity** | ✅ | **physical** | ❌ | ❌ | — |
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

### 三个必须显式裁决的问题

**① Fact 能否随 Entity 一起物理删除？**

```text
facts 是 Business Fact 唯一 SoT（D-02 架构锁定）
它是 AI 答案的事实来源
删除 Entity → facts 消失 → **AI 的答案静默改变**
```

**这比 G-1/G-2/G-3 更危险**：那三处是「多输出了不该输出的」，
这里是「事实消失了，AI 会给出一个「更少事实」的答案，且没有任何错误信号」。

**⇒ CASCADE = prohibited（直接列为禁止项，不再作为开放问题讨论）**

这是典型的 **silent knowledge loss**：

```text
Entity purge → Fact purge → AI knowledge silently shrinks
                              → no exception
                              → no broken relation
                              → no obvious UI error
```

**② 但 DETACH 也不是简单的 FK 策略，而是新的领域语义**

```text
Fact.entity_id = NULL          （或换某种 owner-neutral 结构）
```

数据不丢，但会产生一系列新问题：

```text
事实还在，但它已经不知道属于哪个业务实体
  · 谁负责它？
  · 是否还能公开？
  · 是否还能被 GEO 检索？
  · 能不能重新绑定？
  · 是否只是历史知识？
  · Entity 已 purge 且不可恢复时，它还有没有公共意义？
```

**这些都需要另一个生命周期定义。因此 D5 的裁决问题不应写成
「Fact：DETACH / PRESERVE / CASCADE？」，而应写成：**

> **Fact 在宿主 Entity 永久清除后，是否仍作为历史知识资产保留；
> 如果保留，其 owner、public visibility、rebind、audit semantics 是什么？**

**③ PRESERVE（历史知识资产）是更值得优先研究的方案**

```text
Entity purge → Fact 不删除 → 进入 preserved / orphaned / historical 状态
             → 默认不进入 Public Knowledge Set
             → 保留审计与知识恢复价值
```

```text
Entity → Fact CASCADE   AI 答案静默变少（禁止项）
Entity → Fact NULL      事实失去归属，需要另一套生命周期才能解释
Entity → Fact PRESERVE  知识资产保留下，默认不公开，语义可解释
```

**④ `content_entity` 级联：结构引用 ≠ 语义引用**

```text
Entity 被 Purge → content_entity 行消失（结构层干净了）
但 content 还在（published）
```

**但数据库关系干净 ≠ 知识引用消失。** Content X 正文可能仍写着：

> 「A 公司成立于 2012 年……」

所以 `content_entity` 实际存在**两个层面**：

```text
Structural relation   →  content_entity（FK 行）
Semantic reference    →  正文 / blocks / metadata / facts / JSON-LD / link
```

**⇒ D5 的裁决问题不能只到「FK 用 DETACH」为止，还必须问：**

> **Entity purge 后，仍然存在的 Content 中对该 Entity 的显式知识引用，
> 是否仍允许进入 Public Knowledge Set？**

这揭示了一个更深的事实：

> **Public Knowledge Set 并不是简单的数据库 join。**

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
| **Page** | physical（模型钩子级联） | ❌ | ❌ | — | ✅ blocks + SeoMeta 由 `Page::booted()` 自动清理 | remove |
| **Block** | derived | derived | derived | derived | — | inherit |
| **Fact** | derived | derived | derived | **CASCADE ⚠️ 需裁决** | host-bound | inherit（但宿主删除即消失） |
| **Relation** | derived | derived | derived | cascade | endpoint-bound | inherit |
| **Media** | physical + 删文件 | ❌ | ❌ | 引用保护 | 有 Usage Scanner | remove |
| **Inquiry** | physical | **不应有** ❌ | ❌ | — | attribution | **不参与**（非展示内容） |
| **AuditLog** | 无入口 | **绝不应有** | — | — | — | 不参与 |
| **SeoMeta** | derived | derived | derived | cascade | host-bound | inherit |

### 探针查出的两个新问题（须记入台账）

```text
G-5  **P1，范围已缩小** Content lifecycle mutation 不失效 PageCache
     （**仅 PageCache**；Search 索引已实测自动失效 1→0→1，见 0.5 节）

     证据链：
       Page delete    → PageCache::flush()    ✅
       Entity delete  → resetReadModels()      ✅（Catalog + SEO memo）
       Content delete → SoftDelete ✅ / PageCache::flush ❌
     而 Content 恰是企业最核心的公共知识内容资产之一。

     Risk: 已删除或已恢复的内容，在真实 HTTP / 已索引场景下
           可能暂时偏离实际 Public Knowledge Set。

     Severity: **P1** —— 属 GEO/SEO 公共知识一致性问题，非普通优化项。

     精确表述（两者不冲突，是不同层面的保证）：
       探针已证明 **semantic recomputation path**（查询层语义正确）
       G-5 证明的是 **production-like invalidation path 尚不完整**

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

## 九之零、D4 冻结的母契约（P1–P10）

**后续 D5、D3、Implementation、测试全部以这组原则为母契约。**

```text
P1  Deletion is a Public Knowledge Set lifecycle event,
    not merely a database mutation.

P2  Public outputs must reflect the current lifecycle state.
    No deleted object may remain publicly represented
    through stale cache, index, or derived output.

P3  Restore must recompute public representations from current
    source-of-truth state; it must never restore stale snapshots.

P4  SoftDeletes is a domain-specific lifecycle primitive,
    not a universal persistence rule.

P5  Derived objects follow their owning aggregate lifecycle and
    must not create independent Trash semantics unless explicitly justified.

P6  Business Facts are protected knowledge assets.
    Fact CASCADE on Entity purge is prohibited.

P7  Structural relations and semantic knowledge references
    must be treated as separate lifecycle concerns.

P8  Audit data is retained and is never represented as Trash.

P9  Business/regulated records such as Inquiry may use permanent
    deletion semantics, but deletion must still obey authorization,
    retention and audit requirements.

P10 Every lifecycle mutation must have an explicit policy for:
     visibility, cascade/detach/preserve, cache invalidation,
     search indexing, GEO/SEO regeneration, and recovery.
```

---

## 九、D5 的 Domain Decisions（D4 移交）

**D4 留下的不是「7 个删除功能」，而是 7 个 Domain Decisions。**

| Decision | 必须裁决的问题 | 优先级 |
|---|---|---:|
| **D5-1** | Content Trash 是正式能力，还是移除错误承诺？ | P1 |
| **D5-2** | Entity 是否改为可恢复生命周期？ | **P0** |
| **D5-3** | Entity purge 后 Relation：CASCADE / DETACH / PRESERVE？ | **P0** |
| **D5-4** | Entity purge 后 `content_entity`：是否 DETACH？<br>**且：仍存 Content 中的语义引用是否仍可公开？** | **P0** |
| **D5-5** | Entity purge 后 Fact 如何处理？<br>**（CASCADE 已列为禁止项；PRESERVE 的 owner / visibility / rebind / audit 语义是什么？）** | **P0** |
| **D5-6** | Page 是可恢复资产还是可再生结构？ | P1 |
| **D5-7** | Media 是可恢复资产，还是引用保护 + 永久删除？ | P1 |

**另：G-5（Content mutation cache invalidation）单独作为 Implementation Gate，
不是 Decision**——它不是「业务应该怎样」，而是**已经确定应该怎样但当前没做到**。

### D5 的决策顺序（不先讨论 Trash UI）

```text
Entity
  ↓
Relation
  ↓
Fact
  ↓
Content reference（语义引用）
  ↓
Public Knowledge Set
  ↓
Cache / Search / GEO / SEO
```

即先回答：

> **一个 Entity 被永久删除后，这个系统允许「什么知识继续存在」？**

这是 D4/D5 的真正核心，而不是「回收站页面长什么样」。

---

## 十、D4 移交给 D5 / D3 的裁决项

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

## 十一、本文档不做的事

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
