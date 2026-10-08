# D5-1 裁决：Content Trash（内容回收站）

- **状态**：**DECIDED**（2026-10-08，补遗后冻结）· 领域裁决，非实现设计
- **上游**：D1（生命周期域模型）· D4（P6 禁 CASCADE）· D5-2 Entity Lifecycle
  · D5-4 Semantic Reference · D5-5 Fact Ownership · PKS Boundary
- **章程**：`docs/product/stage1-discovery-charter.md`
- **硬约束**：本裁决**不重定义**已冻结的 PKS / Entity Lifecycle / Reference / Fact 规则。
- **纪律**：不改产品代码、不做 Trash UI（本阶段只定义回收站契约）。
- **冻结补遗**：Restore = 无损恢复数据与生命周期状态 + 重新计算当前 Public/PKS
  资格；**不是**恢复历史公共快照。

---

## 零、取证：Content 的状态与删除机制

### 0.1 状态与删除字段

```text
Content：status draft|published|archived（varchar，默认 draft）
         + SoftDeletes（deleted_at）
         + published_at（定时/实际发布时间）
         + not_slot 全局作用域（slot 片段不参与常规查询）
published() = status=published AND (published_at IS NULL OR published_at <= now)
```

### 0.2 三种「移除」动作（必须分清，不可混为一谈）

| 动作 | 入口 | 实质 | 可见性 |
|---|---|---|---|
| **下架 unpublish** | `unpublish()` | status → draft | 仍在后台列表，可再发布 |
| **软删 soft-delete** | `destroy()` | deleted_at 置位 | 后台默认查询不可见，前台/机器面全撤 |
| **物理删 force-delete** | （无路由，仅技术/DB） | 物理行删除 | 不可逆终态 |

### 0.3 软删「保留物」 vs 物理删「清理物」（探针实测）

| 关联 | 软删（deleted_at） | 物理删（forceDelete） |
|---|---|---|
| 翻译行（同 translation_group） | 级联软删（保留） | 级联物理删（Translatable 钩子） |
| ContentEntity（about/mention 结构边） | ✅ 保留 | forceDeleted 钩子清理 |
| content_tag（标签 pivot） | ✅ 保留 | forceDeleted 钩子清理 |
| SeoMeta（noindex/SEO 覆盖） | ✅ 保留（FK 不触发） | FK CASCADE 删除 |
| ContentRevision（修订历史） | ✅ 保留（FK 不触发） | FK CASCADE 删除 |
| cover / og_image（媒体引用） | ✅ 保留（引用列在内容行） | 随内容行消失；**媒体不删** |
| fact_refs（JSON 事实引用） | ✅ 保留（在内容行） | 随内容行消失；**事实不删** |
| entity_refs（JSON 语义引用，D5-4 未来） | ✅ 保留 | 随内容行消失；**实体不删** |

### 0.4 翻译级联

```text
Translatable::deleting 钩子：
  删 anchor → 同 translation_group 其余语言行级联处理
  软删 → 级联软删；forceDelete → 级联物理删
避免「中文删了、英文仍可访问」的泄漏（BUG-20B-002）。
```

### 0.5 回收站运营缺口（本裁决要补齐的契约）

```text
现状：
  destroy 软删后提示「可在回收站恢复，如需彻底删除请联系技术」
  但：
    ❌ 无回收站列表（Content::index 默认排除软删行）
    ❌ 无 restore 路由/动作
    ❌ 无 force-delete 路由/动作
  即：回收站在模型层成立，在操作层缺失；
      恢复只能直接改库（UPDATE deleted_at=NULL）。
```

### 0.6 已有测试覆盖

```text
Remediation20BTest：
  删 anchor → 翻译行级联软删（保留在回收站）
  软删 → 保留 pivot 供恢复
  forceDelete → 清理 content_entity / content_tag
AdminSeoMetaCrudTest：
  软删保留 SEO 覆盖；forceDelete 后 SEO 被 CASCADE 清除
```

---

## 一、Content Trash 的领域语义

### 软删 = 可恢复的「暂存态」

```text
软删使内容撤出「公共人类面 + PKS / discovery 机器面」，
但完整保留其知识状态与全部关联，以便无损失恢复。
它是一个「持有状态」，不是「销毁」。
软删不 mutate 被引用的知识（事实 / 实体 / 媒体）。

「撤出全部面」不包含后台管理面——回收站恰恰需要反向查询 deleted 行。
三个面分别断言：
  public human surface  → absent
  machine surface       → absent
  admin trash surface   → present
```

### 恢复 = 数据无损恢复 + 公共资格重新判定

```text
restore 分两个层面，不可画等号：

  数据恢复（Data State）：
    deleted_at → NULL，翻译行 / 修订 / SEO / 标签 / refs 等保留数据恢复
    —— 这才是「无损」。

  公共资格恢复（Public / PKS Eligibility）：
    是否重新进入机器面，须依据当前 PKS / G-7 / 引用完整性等规则重新计算，
    不保证恢复历史上的公共表现。

原因：软删期间 Entity 生命周期、Fact review、ContentGate 规则、
      published_at、引用（UNKNOWN-ID）、PKS / visibility policy 都可能变化。
与 D4 一致：Restore = recompute against CURRENT domain state。
```

### 物理删 = 不可逆终态

```text
forceDelete：内容与翻译行物理消失，
清理「内容自己拥有」的关联（结构边 / 标签 / SEO / 修订），
但绝不 mutate 被引用的实体 / 事实 / 媒体（P14、D5-4、D5-7）。
```

---

## 二、关联去留的判定原则（逐类）

```text
判据：该关联是「内容自己拥有」还是「引用了外部知识」？

  内容自己拥有（随内容终态清理）：
    ContentEntity 结构边 · content_tag · SeoMeta · ContentRevision
  引用外部知识（只解除引用，不 mutate 目标）：
    fact_refs → 事实行不动（P14）
    entity_refs → 实体不动（D5-4）
    cover/og_image → 媒体不动（D5-7）

软删阶段一律保留——因为恢复必须无损失；
物理删阶段才清理/解除——这是已实现且已测试的正确语义。
```

---

## 三、与已冻结规则的关系（硬约束，不重定义）

```text
PKS（P15/G-9）：
  软删内容不是 PKS 成员（SoftDeletes + published 作用域自动排除）；
  本裁决不改变 PKS 资格判定，只定义「撤出后如何持有/恢复/终态」。
Semantic Reference（D5-4 / G-7）：
  entity_refs 的生命周期评价只对在 PKS 中的内容生效；
  软删内容不在 PKS，refs 随内容保留但不参与门禁。
Fact（P14/G-8）：
  内容的软删/物理删都不增删改事实；
  物理删只带走内容自己的 fact_refs。
Entity Lifecycle（D5-2）：
  被引用实体的生命周期独立于内容回收站。
```

---

## 四、冻结结论（P16 / G-10 最终版）

### P16

```text
P16  Content 软删是"可恢复的暂存态"：
     撤出公共人类面与机器面，但完整保留内容自身的
     生命周期数据及其关联，以支持无损数据恢复。
     软删与恢复不得 mutate 被引用的外部知识。
     forceDelete 是不可逆终态：
     清理内容自有关联并解除引用，但绝不 mutate
     被引用的 Entity / Fact / Media。
     Restore 的"无损"仅指内容及其生命周期关联数据恢复；
     恢复后的 Public / PKS 资格必须依据当前生命周期、
     引用完整性及其他现行规则重新判定，
     不保证恢复历史上的公共表现。
     Content Trash 必须提供列表、恢复、物理删除三项能力。
```

### G-10（Implementation Gate）：Content Trash Integrity

```text
① 软删内容必须从公共人类面 + PKS / discovery 机器面撤出；
   后台回收站面必须仍可查询 deleted 行。
② 软删必须保留：翻译行、结构边、标签、SEO、修订、媒体引用、
   fact_refs / entity_refs。
③ restore 必须无损失恢复内容及其生命周期关联数据（含翻译行）。
④ forceDelete 必须清理内容自有关联，且不 mutate 被引用知识；
   副作用清单与 D5-4「无副作用」原则一致（断言被引用对象不变）。
⑤ 回收站必须提供列表 / 恢复 / 物理删除操作；
   须变异验证（短路软删过滤 / 恢复保留 → 对应测试 FAIL）。
⑥ restore 后不得直接复制历史公共资格快照；
   必须依据 CURRENT domain / PKS / reference rules 重新判定其公共资格。
   若实现成「softDelete snapshot → restore snapshot → 直接恢复原
   public/index flags」，测试必须失败。
```

---

## 五、开放边界（显式记录，不静默解决）

```text
① 回收站保留/自动清理策略（如 N 天后自动物理删）：
   属产品/Implementation 决策；不得静默自动物理删（等于静默知识损失）。
② 恢复时必须重新判定公共资格（已冻结，非可选）；
   重跑门禁的具体调用方式 / 如何处理已失效引用，属 Implementation Contract。
③ 回收站列表的语言/站点范围、批量操作留待 Implementation。
```

---

## 六、本裁决不做的事

```text
❌ 不改产品代码、不实现 Trash UI、不加路由
❌ 不重定义 PKS / Entity / Reference / Fact 已冻结规则
❌ 不改变媒体、事实、实体的生命周期（分别由 D5-7 / D5-5 / D5-2 裁决）
❌ 不静默自动清理回收站
❌ 不把恢复实现成复制历史公共资格快照
```

**下一步**：D5-1 已冻结 → **D5-6 Page Lifecycle**（下一步，等授权）
→ D5-7 Media Lifecycle → Architecture Decision → Implementation。
