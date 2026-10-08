# D5 Architecture Contract（D5 架构契约 · 统一编译版）

- **状态**：**PROPOSED（待裁定）**（2026-10-08）· 架构契约，非实现授权
- **来源**：D5 Discovery 全部 DECIDED 项的统一编译，**不新增领域裁决**
- **上游基线**：
  - D5-2 Entity Lifecycle（P11）
  - D5-3 Relation Lifecycle（P12）
  - D5-4 Semantic Reference（P13 / G-7）
  - D5-5 Fact Ownership（P14 / G-8）
  - PKS Boundary（P15 / G-9）
  - D5-1 Content Trash（P16 / G-10）
  - D5-6 Page Lifecycle（P17 / G-11）
  - D5-7 Media Lifecycle（P18 / G-12）
- **纪律**：本契约只做编译与映射；未获授权不改产品代码、不实现 UI、不 push、不发布。

---

## 0. 契约总览（一张图）

```text
Domain Knowledge
  ├─ Entity（知识/语义节点）      ├─ Content（知识陈述）
  ├─ Fact（站点级 canonical）    └─ 结构：EntityRelation / ContentEntity
        │
        ▼  class-specific PKS Eligibility（P15）
PKS（派生投影，非物化集合）
        │
        ▼  PKS Projection
  ├─ GEO / LLMS / Sitemap / RSS / Search（discovery 面）
  └─ surface-specific constraints（只收窄，不扩大）
        │
  Page-local：JSON-LD（独立轨道，非 PKS）
        │
  Asset：Media（引用保护删除，非知识对象）
```

**贯穿全链的三条正交轴**：
① visibility（Entity/Content）· ② publication（业务状态）· ③ reference health（引用健康）
三者互不推导、互不提升。

---

## 1. Domain Ownership（领域所有权）

### 1.1 对象所有权矩阵

| 对象 | 领域定位 | 拥有状态 | 只能引用 |
|---|---|---|---|
| **Site** | 租户边界 | 自身生命周期 | — |
| **organization（site subject）** | Site Knowledge Aggregate Root | 站点知识聚合 | 产品/服务/语义节点 |
| **organization（role=customer）** | 客户案例内容 | 自身案例状态 | — |
| **product / service / case_study** | Public Knowledge Asset | 自身知识状态 | 语义节点 / Media |
| **person / location / topic** | Semantic Node | 图谱节点状态 | — |
| **download_asset** | Asset Node | 自身状态 | Media |
| **Content** | 知识陈述 | 自身发布状态 | Entity / Fact / Media |
| **Fact** | 站点级 canonical knowledge | 自身 review 状态 | —（无 entity_id） |
| **EntityRelation** | 结构声明（structural assertion） | 无独立状态 | 两端 Entity |
| **ContentEntity** | 结构引用（about/mention 边） | 无独立状态 | Content / Entity |
| **Page（composition）** | 结构视图 | 结构绑定状态 | Content / Entity / Media |
| **PageBlock** | 实时读模型 | 无快照 | Content / Entity / Media |
| **Media** | Asset（资产对象） | 资产删除资格 | —（不拥有引用方） |

### 1.2 所有权方向（不可反向）

```text
引用方拥有「我要引用 X」；被引用方拥有「我是否允许被删除」。
Entity 是事件来源（may trigger）；Fact 是事实状态的主人。
生命周期事件只能「向下触发」，不得「反向 mutate」。
```

---

## 2. Source of Truth（事实源）

### 2.1 单一 SoT 清单

| 数据类 | 唯一 SoT | 禁止 |
|---|---|---|
| Business Fact | `facts` 表（Fact 模型） | 第三套快照 / legacy Facts 长期并存 |
| Entity 能力 | `EntityCapabilityRegistry`（config/entities.php） | 控制器硬编码能力数组 |
| 公开可见性 | `PublicIndex` | 各 Builder 各自重判 published/noindex |
| 内容发布门禁 | `ContentGate`（手动 + GEOFlow 共用） | 双标（手动宽松、系统严格） |
| 媒体删除资格 | `MediaReferenceScanner` | 绕过扫描直接 delete |
| 缓存失效登记 | `CacheInvalidationMap` | 重复挂 saved/deleted flush |

### 2.2 派生防 fork

```text
Canonical fact：独立来源、登记、带 source/owner/reviewed_at。
Derived：可由 Entity Graph 计算（计数/列表/聚合）→ 渲染时计算，不持久化。
  判据：「能否从实体图算出来？」能 → 不存。
PKS：派生投影，不落库为物化集合。
PageBlock：实时读模型，不保存被引用对象生命周期快照。
legacy app/Support/Facts.php：过渡层，退场（独立清理项，不在本轮）。
```

---

## 3. Lifecycle（生命周期）

### 3.1 各类生命周期

| 类 | 生命周期原语 | 恢复 | 终态 |
|---|---|---|---|
| Content | SoftDeletes + status | 数据恢复 + 资格重算 | forceDelete |
| Page（composition） | 硬删（无 SoftDeletes） | 不可恢复 | hard delete |
| Media | 硬删 + 引用保护 | 不可恢复 | protected hard delete |
| Fact | 硬删（无 status/deleted_at） | 无（但不被实体事件删） | 自身删除 |
| Entity（目标） | 角色决定（P11） | 见下 | purge |

### 3.2 Entity 生命周期（角色 → 策略，P11）

```text
site subject organization：不暴露普通 delete → Deactivate/Retire；
  purge 仅 Site teardown / tenant destruction。
customer organization：独立生命周期，可删。
product/service/case_study（Public Knowledge Asset）：必须可恢复
  → delete → unpublished/soft-deleted → recompute PKS；
  → restore → recompute PKS；purge → 物理清除（需引用检查）。
person/location/topic（Semantic Node）：无传统 Trash；
  需要删除后退化规则（不产生公开悬空引用）。
download_asset：引用保护后清除。
```

### 3.3 生命周期触发规则

```text
Entity SoftDelete → EntityRelation 行天然保留（FK 不触发），
  endpoint inactive → 自动不进 PKS；恢复后自动参与。
Entity Purge → EntityRelation CASCADE（现有 FK）；
  ContentEntity 清理（现有钩子）；
  Detail Override Page 级联（现有钩子）；
  Fact re-review 信号（site/business-subject 粒度）。
Content/Page/Media 删除 → 只解除引用，不 mutate 外部知识。
```

### 3.4 Restore 语义（统一）

```text
Restore = 数据状态恢复（无损）+ 公共资格按 CURRENT domain state 重算。
禁止：恢复历史公共快照（replay public/index flags）。
适用：Content restore、Entity restore（同一纪律）。
```

---

## 4. Reference Graph（引用图）

### 4.1 结构引用 vs 语义引用（P7 双轨，不可合并）

| 引用 | 载体 | 职责 | Purge 时 |
|---|---|---|---|
| **结构引用** | content_entity / entity_relations | Schema / GEO 边 | 清理（钩子/FK） |
| **语义引用** | content.entity_refs（待建） | 知识完整性 | 保留，门禁评价 |

### 4.2 媒体引用（七路，MediaReferenceScanner 唯一入口）

```text
ID：Content.cover_id / og_image_id、Banner.image_id、Entity.metadata media_id/og_image
字符串：Content.body、SeoMeta.og_image_path、Entity.metadata image、Settings
附查：ContentRevision 仅 warning。
规则：未来新增媒体引用类型必须进入 Scanner/Contract；
  不得因「仍只有七路」而让新引用自动获得删除资格。
```

### 4.3 事实引用

```text
Content.fact_refs（JSON）→ ContentGate 规则 6（悬空 → ERROR）。
无 fact_entity_refs（永久能力边界）：
  实体 purge 无法自动定位受影响 Fact → 只发 site/business-subject 复核信号。
```

### 4.4 引用删除资格

```text
Media 删除：有阻断引用 → 拒绝（media.delete_blocked）；无引用 → 文件+记录同删。
引用保护 ≠ 反向 mutation（不得为制造未引用而改引用方）。
```

---

## 5. Knowledge Boundary（知识边界）

### 5.1 PKS 三层模型（P15）

```text
Domain Knowledge → class-specific Eligibility → PKS Projection
  → surface-specific constraints（只收窄，不扩大）
```

### 5.2 类特定资格谓词

```text
PKS(Entity) ：registered + status=published + not-noindex + site + locale
PKS(Content)：registered + published(到点) + not-noindex + site + locale
             + 无 UNKNOWN-ID 语义引用
PKS(Fact)   ：is_public + site + locale（无 status，不适用 published）
PKS(Edge)   ：A、B 均 ∈ PKS 且两端当前语言有译文（派生，不独立入集）
Semantic Ref：随宿主 Content 评价，不独立入集
```

### 5.3 不传递性

```text
PKS membership is NOT transitive：
  Content ∈ PKS ≠ referenced Entity ∈ PKS
  Entity A ∈ PKS ≠ Relation(A,B) ∈ PKS ≠ Entity B ∈ PKS
```

### 5.4 AI 可消费 vs 资产/展示

| 类别 | AI discovery 可消费 |
|---|---|
| Entity / Content / Fact / 合格 Edge | ✅（经 PKS） |
| Media | ❌（资产，非知识） |
| Custom Landing Page | ❌（human surface only） |
| JSON-LD | 独立轨道（page-local，非 PKS discovery） |
| 直访 / 草稿预览 / 后台 | ❌（人类面） |

### 5.5 人类面 vs 机器面

```text
HTTP 200 ≠ machine-surface membership。
noindex：人类直访 200，机器 feed 排除。
两个面分别表达、分别断言。
```

---

## 6. Guards & Mutation（门禁与变异）

### 6.1 规则 → Gate → 测试映射

| Gate | 守护内容 | Contract Test | Mutation Test |
|---|---|---|---|
| **G-7** | 语义引用五分支 | 五分支 + 无副作用 | 短路 validator → FAIL |
| **G-8** | Fact review-state | 状态可判定 + 不 mutate | 跳过 no-mutate → FAIL |
| **G-9** | PKS projection | 各出口派生 + 不扩大 | 短路 eligibility → FAIL |
| **G-10** | Content trash | 撤出/保留/恢复/终态 | 短路软删过滤 → FAIL |
| **G-11** | Page lifecycle | fallback/override/block/menu | 删护栏 → FAIL |
| **G-12** | Media lifecycle | 引用保护/一致性/降级 | 绕过扫描 → FAIL |

### 6.2 G-7 五分支（语义引用）

```text
valid                          → PASS
draft / soft-deleted /
KNOWN-DELETED / noindex（身份存在）→ PASS + WARNING
UNKNOWN-ID（身份不存在）         → BLOCK
BLOCK 原因：引用身份本身不存在（数据错误），非「实体不可见」。
测试原则：断言 WARNING/BLOCK 之外，必须断言业务状态无副作用。
```

### 6.3 变异纪律

```text
恒真检查器 = 没检查。
每条 Guard 必须证明能抓住故意制造的违规（mutation testing）。
Discovery 护栏：tools/check-discovery-docs.sh（meta-test）。
```

---

## 7. Failure & Degradation（失败与降级）

### 7.1 失败模式与处置

| 失败 | 领域要求 |
|---|---|
| 被引用 Media 缺失 | 降级 null / fallback，不 500 / 不悬空 / 不伪造 |
| OG/Cover 缺失 | fallback 链：SeoMeta→og→cover→site logo |
| Entity purge | Block 重算（条目消失）；Content 不自动改 |
| 文件删除失败 | 不宣告删除、不无条件删记录；留可重试/可审计状态 |
| 外部引用失效 | 门禁 WARNING/BLOCK；不改宿主业务状态 |
| Storage 异常 / 跨磁盘 | 记录须明确所属存储，不默认当前 disk |
| Cache/Index/Projection 异常 | PKS 变化须失效；精细化属 Implementation |
| 历史快照回滚 | 可能复活破图（warning，已提示） |

### 7.2 文件 / DB 一致性（结果性不变量，不冻结原子事务）

```text
成功终态：DB 记录不存在 + 属于该 Media 的文件/衍生文件已处置。
失败终态：不宣告删除 + 可重试 / 可审计。
技术选择（delete-first / record-first / pending-delete / retry /
  reconciliation）由 Implementation 决定。
```

---

## 8. Implementation Mapping（实现映射）

### 8.1 Domain → Model → Service → Controller/Job → Test

| Domain | Model | Service/Support | 入口 | 测试 |
|---|---|---|---|---|
| Entity 生命周期 | Entity | PublicIndex | EntityController | Entity/Trash tests |
| 结构边 | EntityRelation / ContentEntity | —（FK/钩子） | — | Graph tests |
| 语义引用 | Content（entity_refs） | ContentGate（G-7） | ContentController | Gate tests |
| Fact | Fact | Fact::publicRows（G-8） | FactController | Fact tests |
| PKS 投影 | — | PublicIndex / Builders | Geo/LLMS/Sitemap/RSS/Search | Outlet tests |
| Content Trash | Content | ContentGate（G-10） | ContentController | Trash tests |
| Page | Page / PageBlock | Render Context（G-11） | Home/System/Catch-all | Page tests |
| Media | Media | MediaReferenceScanner / ImageOptimizer（G-12） | MediaController | Media tests |

### 8.2 工作包（current → target 差距）

| WP | 内容 | 现状 | 目标 Gate |
|---|---|---|---|
| **WP-1** | Entity 生命周期原语（可恢复 + 角色保护 + Deactivate/Retire） | 硬删、零保护 | P11 |
| **WP-2** | 统一 endpoint 可见性（Content-Entity 边去 G-1） | 裸 Entity 查询 | G-9/G-11 |
| **WP-3** | entity_refs 字段 + ContentGate 生命周期规则 | 无字段/无检测 | G-7 |
| **WP-4** | identity/tombstone registry 或显式降级 | 无 registry | G-7 |
| **WP-5** | Content Trash 列表/恢复/物理删 | 操作层缺失 | G-10 |
| **WP-6** | Page menu dangling 识别 | 不校验 | G-11 |
| **WP-7** | Media WebP 清理 + 删除失败检查 | 不清理/不检查 | G-12 |
| **WP-8** | Purge → Fact re-review 信号 | 未实现 | G-8 |
| **WP-9** | 全 Gate 变异测试 + 护栏 | 部分 | 全部 |

### 8.3 必须实现（MUST）

```text
- Entity 角色化生命周期（Public Knowledge Asset 可恢复；site subject 保护）
- 统一 endpoint 可见性（消除 G-1）
- entity_refs 结构化语义引用 + ContentGate 五分支
- identity/tombstone 能力，或显式按 UNKNOWN-ID→BLOCK
- Content Trash 三操作（list/restore/force-delete）
- Media 衍生物清理 + 删除失败一致性
- Page menu dangling 识别
- Purge → Fact re-review 信号
- 全部 Gate 的 Contract + Mutation 测试
```

### 8.4 明确禁止（MUST NOT）

```text
- CASCADE 删除知识（P6）
- 自动改写 / 删除已发布正文
- 合并结构引用与语义引用
- PKS 成员资格传递
- Restore 复制历史公共快照
- 无 fact_entity_refs 时声称自动 drift detection
- 以 AuditLog 充当 identity/tombstone registry
- 引用门禁反向 mutate 引用方 / 把 Content 拉 draft
- 绕过 MediaReferenceScanner 删除媒体
- 静默自动清理 Trash / 孤儿文件
- 扩大 PKS（surface 只能收窄）
- 自动进入下一阶段 / push / release
```

### 8.5 分层归属（非 Domain，留后续）

```text
Architecture/Implementation：
  retired 状态、review_due 到期出口行为、缓存失效精细化、
  JSON-LD 独立 Surface Policy、去重、孤儿 reconciliation、
  S3/外部存储、organization role 字段是否落库。
独立 Discovery 授权（不在本轮）：
  fact_entity_refs、统一 Reference Framework、
  media_refs / taxonomy_refs 等泛化。
```

---

## 9. 永久能力边界（契约级，禁止实现阶段脑补）

```text
① 无 fact_entity_refs → 无自动 fact-entity drift detection；
   purge 只发 site/business-subject 复核信号。
② 无可实时查询 identity/tombstone registry → 无法区分
   KNOWN-DELETED 与 UNKNOWN-ID；不建 registry 则 purged 按 UNKNOWN-ID→BLOCK。
③ AuditLog ≠ identity/tombstone registry。
```

---

## 10. 本契约不做的事

```text
❌ 不新增 / 重开任何领域裁决
❌ 不改产品代码、不做 UI、不加路由
❌ 不决定具体存储一致性技术（delete-first / pending-delete 等）
❌ 不提前抽象 Reference Framework / 不立项 fact_entity_refs
❌ 不自动进入 Implementation
❌ 不 push / release
```

**下一步**：本契约经用户裁定后 → 逐 WP 编写 **Implementation Contract**
（每个 WP 的具体契约、改动面、测试、回归、污染扫描），再进入 Implementation；
进入前仍须按 Gate 纪律等用户明确授权。
