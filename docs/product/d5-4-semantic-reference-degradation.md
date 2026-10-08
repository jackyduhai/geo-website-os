# D5-4 裁决：Semantic Reference Degradation

- **状态**：D5-4 **DECIDED**（2026-10-08）· 领域裁决，非实现设计
- **上游**：`d5-2-entity-lifecycle-class-matrix.md` · `d5-3-relation-lifecycle.md`
- **章程**：`docs/product/stage1-discovery-charter.md` D5
- **D5-3 已解决**：结构层（Relation / content_entity）
  **D5-4 只剩一个问题**：

> **Entity endpoint 不再有效以后，仍存在的 Content 正文 / Block / metadata
> 里的语义引用应该以什么语义继续存在？**

---

## 零、取证：语义引用的现实边界

### 0.1 语义引用的两个载体

```text
① Content.body            —— 富文本（HTML/Markdown）
② PageBlock.content       —— JSON 配置
```

两者都是**自由文本或自由 JSON**，数据库无法表达「这段话引用了哪个实体」。

### 0.2 系统当前的检测能力：**零**

```text
全库检索 entity_reference / semantic_reference / referenced_entity /
         linked_entity
→ **0 处命中**
```

**⇒ 系统目前完全无法识别「某段文字引用了已删除实体」。**

### 0.3 但系统已有结构化引用的**先例模式**（关键）

```text
contents 表有 fact_refs 字段（实测唯一含 ref 的引用字段）
ContentGate.php:147-153 规则「6. 事实引用校验」：
    $refs = $c->fact_refs ?: [];
    if ($refs) {
        $known = Fact::whereIn('key', $refs)->pluck('key')->toArray();
        foreach (array_diff($refs, $known) as $missing) {
            $errors[] = sprintf('引用了不存在的事实项：%s', $missing);
```

**⇒ 系统已经具备「结构化引用 + 发布门禁校验悬空」的完整机制，
只是只用于 facts，从未用于 entities。**

### 0.4 ContentGate 现有 15 条规则（发布门禁是既有能力）

```text
① 缺少「结论」        ⑥ 事实引用校验   ⭐
② 缺少「解释」        ⑦ 缺少责任人
③ 缺少「边界」        ⑧ 缺少复核日期
④ 证据不足（< 2 条）  ⑨ 标题不能为空
⑤ 证据缺source       ⑩-⑬ slug 规则 / 极限词 / 他方品牌 / 对标表述
                      ⑭ 事实口径冲突
                      ⑮ 引用了不存在的事实项 ⭐
```

**⇒ 门禁是发布前的既有拦截点，语义引用校验应挂在同一位置。**

---

## 一、问题分解：语义引用有三种退化可能

```text
Entity endpoint 失效
   ↓
Content.body 仍写着「A 公司成立于 2012 年……」
   ↓
三种可能的处理：
```

| 方案 | 做法 | 后果 |
|---|---|---|
| **A. 强制改写正文** | 检测到引用即替换/删除该段 | ❌ **静默改变企业已公开的事实** |
| **B. 静默保留** | 什么都不做 | ❌ AI 拿到指向不存在实体的陈述 |
| **C. 保留 + 可识别 + 可告警** | 不改内容，但标记「此内容曾引用已失效实体」 | ✅ 内容不变，缺陷可见 |

### 裁决：**方案 C**

**理由**：

```text
① 内容本身是「已发布事实」，企业可能已经对外承诺过
   → 强制改写是危险操作（A 不可接受）

② 完全不管会让 AI 拿到悬空陈述（B 不可接受 —— 这正是 G-1/G-2/G-3 的同类问题）

③ P7 已冻结「结构引用与语义引用是两个独立生命周期关切」
   → 语义引用无法在数据库层表达，就必须提供**别的可识别手段**
```

---

## 二、裁决的落地方式：复用既有的 fact_refs 模式

### 2.1 不发明新机制，而是扩展既有机制

```text
现有：contents.fact_refs （JSON 数组）→ ContentGate 校验 → 阻止发布

新增：contents.entity_refs （同类字段）→ 同一门禁校验 → 阻止发布 / 或警告
```

**⇒ D5-4 的实现方向是「让语义引用结构化」，而不是「解析自由文本」。**

### 2.2 为什么不做文本解析

```text
❌ 正则/AI 解析 body 找实体名
   → 中文语境下误判率高（「示例制造」可能指 org 也可能指客户案例）
   → 无法区分「提及」与「引用」
   → 每次 Entity 改名都要重新扫全文，成本与误差随内容增长

✅ 结构化 entity_refs
   → 编辑时显式声明「本内容 about 哪些实体」
   → 与既有 content_entity（about 关系）语义一致但面向不同：
        content_entity = Schema/GEO 的 about 节点来源
        entity_refs    = **内容正文里的知识引用**（面向语义完整性检查）
```

### 2.3 两者的区别必须说清（P7 的具体化）

```text
content_entity   声明式结构引用
                 → 「本内容 about 这些实体」（产生 Schema about / GEO 边）
                 → Entity purge 时钩子清理

entity_refs      声明式语义引用
                 → 「本内容的正文里谈论这些实体」（用于检测内容完整性）
                 → Entity purge 时**应保留**，因为内容还在谈论它
```

**⇒ 两者不是同一机制，D5-3 处理前者，D5-4 处理后者。**

---

## 三、裁决的完整规则

```text
Entity SoftDelete / Purge
   ↓
结构引用（content_entity / entity_relations）
   → D5-3 已裁决：软删保留自动抑制 / purge 时清理
   ↓
语义引用（content.entity_refs）
   → **保留**（内容仍在谈论该实体）
   → Entity 失效后，该 ref 变为「悬空语义引用」
   → 由门禁检出并标记，供人工复核
   → **绝不自动改写内容**
```

### 退化规则表

| 状态 | entity_refs 中该 ref | 内容可否发布 | 是否告警 |
|---|---|---|---|
| Entity published | 有效 | ✅ 可发布 | — |
| Entity draft | 悬空语义引用 | ⚠️ 可发布但告警 | 「引用了未公开实体」 |
| Entity soft-deleted | 悬空语义引用 | ⚠️ 可发布但告警 | 「引用了已删除实体」 |
| Entity purged | 悬空语义引用 | ⚠️ 可发布但告警 | 「引用了已永久删除实体（该实体不存在）」 |
| Entity noindex | 引用存在但不公开 | ⚠️ 告警 | 「引用了 noindex 实体，其知识不会进入 GEO」 |

**关键设计**：**默认不阻止发布**（保持内容可编辑），
只标记与告警。**阻止**是例外情况（如引用了从未存在的实体 id → 数据错误）。

---

## 四、这可能是 D5 最容易再次发现「数据库正确但语义错误」的地方

```text
Entity deleted
   ↓
structural reference   ✅ D5-3 已收敛（结构干净）
   ↓
semantic reference     ⚠️ **D5-4 的难点**
   ↓
human-readable content  「A 公司成立于 2012 年」—— 仍然公开
   ↓
machine-readable output geo.json / llms.txt / Schema
   ↓
PKS                    AI 拿到「指向不存在实体的陈述」
```

**⇒ 这是 D2 定位的 G-1/G-2/G-3 的「同源问题的第四种形态」**：

```text
G-1/G-2/G-3  结构层：边/节点进了 PKS但端点私有
G-7（新增）   语义层：内容公开但其知识指向已失效实体
```

**建议编号 G-7**，作为 Implementation Gate 的一部分。

---

## 五、冻结结论

```text
P13  语义引用（内容正文对实体的知识引用）无法在数据库层表达，
     必须通过**结构化声明 + 发布门禁校验**使其可识别。

裁决：
  ① 不强制改写已发布正文（会静默改变企业已公开的事实）
  ② 不静默保留（会让 AI 拿到指向不存在实体的陈述）
  ③ 采用「保留 + 可识别 + 可告警」
  ④ 落地方式 = **扩展既有 fact_refs 模式**为 entity_refs，挂同一 ContentGate
  ⑤ 默认不阻止发布，只告警；仅当引用了从未存在的 id 才视为数据错误

★ P7 的具体化（两者不是同一机制）
     content_entity = 声明式结构引用 → 产生 Schema about / GEO 边，
                      Entity purge 时清理（D5-3 裁决）
     entity_refs    = 声明式语义引用 → 用于检测内容知识完整性，
                      Entity purge 时**保留**（内容仍在谈论它）

★ 建议新增缺口编号 G-7（语义层悬空引用）
     与 G-1/G-2/G-3 同源但形态不同：
       G-1~G-3  结构层：边/节点进 PKS 但端点私有
       G-7       语义层：内容公开但其知识指向已失效实体
```

---

## 六、本裁决不做的事

```text
❌ 不解析自由文本做实体识别（误判率与成本都不可接受）
❌ 不改写 / 删除已发布正文
❌ 不加 entity_refs 字段（属 Implementation）
❌ 不改ContentGate（属 Implementation）
❌ 不修G-1~G-7任何一项
❌ 不做 Trash UI
```

**下一步**：D5-5 Fact Ownership / Granularity
（已证 facts 无 entity FK，D5-5 是 Knowledge ownership 决策而非删除保护）。