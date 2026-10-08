# D5-6 裁决：Page Lifecycle（页面生命周期）

- **状态**：**DECIDED**（2026-10-08，收紧后冻结）· 领域裁决，非实现授权
- **上游**：D1（生命周期域模型）· D4（P6 禁 CASCADE）· D5-2 Entity Lifecycle
  · D5-4 Semantic Reference · D5-5 Fact Ownership · PKS Boundary · D5-1 Content Trash
- **章程**：`docs/product/stage1-discovery-charter.md`
- **硬约束**：本裁决**不重定义**已冻结的 PKS / Entity / Reference / Fact / Content Trash 规则。
- **纪律**：不改产品代码、不做 UI、只定义页面生命周期契约。
- **冻结要点**：Page 是结构视图（非知识对象）；系统页/首页 fallback；
  自定义页 human-surface only；Override 随 Entity purge；Block 实时重算。

---

## 零、取证：GEO OS 里的「页面」其实有三种

### 0.1 三种页面概念

```text
① Content（type=page）—— contents 表扁平页
   用 SoftDeletes，走 Content Trash（P16/G-10），与文章同生命周期。
   → 已由 D5-1 覆盖，本裁决不再处理。

② Page（composition）—— pages 表组合页
   用 BelongsToSite + Translatable，【无 SoftDeletes】（硬删）。
   由若干 PageBlock 组合而成，是「页面实例」。
   → 本裁决的核心。

③ Functional / structural pages —— 硬编码控制器
   首页、产品、场景、案例等由控制器直驱（非 DB 页面对象）。
```

### 0.2 Page（composition）模型结构

```text
pages 列：id, site_id, template, slug, is_home, is_system, system_key,
          title, status(draft|published), locale, translation_group, timestamps
唯一：(site_id, slug, locale)；(site_id, system_key, locale) 部分唯一
Page 不存业务事实（用户裁定①，强制）：业务事实来自 Content / Entity / Media。
```

### 0.3 Page 的四个子角色

| 子角色 | 标识 | 绑定 | 入口 |
|---|---|---|---|
| **系统页** | is_system + system_key（9 个） | 固定路由 | 各控制器 |
| **首页** | is_home | `/` | HomeController |
| **自定义落地页** | 非 system / 非 home | 自定义 slug | catch-all `/{path}` |
| **实体详情 override** | entity_id 非空 | 绑定 Entity | EntityRenderContext |

```text
9 个 system_key：products / solutions / knowledge /
  profile / history / culture / factory / cooperation / contact
```

### 0.4 系统页与首页的「代码 fallback」

```text
SystemPageRenderContext::resolve($key)：
  优先取持久化 is_system Page；缺失 → 按 DEFINITIONS 构造未保存的默认 Page。
HomeRenderContext::resolve()：同样 fallback。
结论：系统页 / 首页不会因记录缺失而 404——
      持久化行承载「可编辑定制」，代码保证「路由存在」。
```

### 0.5 Page 删除语义（硬删 + 级联）

```text
Page::deleting：
  PageBlock::where(page_id)->delete()      // 区块
  SeoMeta::where(page_id)->delete()       // 页面级 SEO
Translatable::deleting：级联处理翻译行（硬删）。
Page 无 SoftDeletes → 删除即不可逆（UI 确认「此操作不可恢复」）。
```

### 0.6 Block 是「实时数据源视图」，purge 后自动重算

```text
BlockRegistry 数据源块在渲染时单向读正式领域模型：
  Entity::published() ...      // 实体列表
  Content::published() ...     // 内容网格
  Media::find($id)?->url()     // 媒体引用：缺失返回 null（不报错）
结论：Block 不持有会断裂的引用——
      被引用 Entity / Media 被 purge 时，条目消失 / 图片 null，页面重算。
```

### 0.7 Entity purge 对 override page 的级联

```text
Entity::deleted：
  Page::where('entity_id', $id)->get()->each->delete()  // 详情载体（级联 block/seo）
  ContentEntity::where('entity_id', $id)->delete()
结论：实体详情 override 是 Entity 的「投影」，随 Entity 终态清除，
      不是独立知识。
```

### 0.8 自定义组合页不在 discovery 面（探针实测）

```text
自定义落地页（非 system / 非 home）：
  ✓ catch-all 可访问（人类面 200）
  ✗ 不在 sitemap（SitemapBuilder 不收 Page）
  ✗ 不在 geo.json / llms.txt / search（均只读 Content/Entity）
结论：自定义组合页是「人类面 only」，不进任何 discovery 面。
```

### 0.9 菜单引用不校验（dangling link）

```text
菜单 href 是静态路径（resolveMenuHref），无引用完整性校验。
删除被菜单指向的页面 → 菜单仍渲染，点击 404（悬空导航链接）。
```

---

## 一、Page 的领域定位：结构视图，不是知识对象

```text
Page = 把 Template + 若干 Block 绑定为「页面实例」的结构视图。
它组合 Content / Entity / Media，但：
  - 不存业务事实（与 D5-5 P14 一致：事实在 facts / Entity）
  - 不复制 Entity / Content 的状态
  - Block 是渲染时的实时读模型，不是第二套 SoT
因此 Page 的生命周期是「结构绑定的生命周期」，
不涉及知识权威性。
```

---

## 二、四类 Page 的生命周期（正式冻结）

| Page 类型 | 生命周期 | Discovery |
|---|---|---|
| **System Page** | 持久化可编辑层可删除；代码 fallback 保证路由 | 按既有 PKS/domain 规则，不因本裁决改变 |
| **Home Page** | 与 System Page 同语义，fallback 保证 `/` | 按既有 PKS/domain 规则 |
| **Custom Landing Page** | 硬删、无 Trash、不可恢复 | **human surface only，不进入 discovery / PKS** |
| **Entity Detail Override** | 归 Entity 所有，随 Entity purge 级联清除 | 不独立成为知识对象 |

```text
冻结边界：「Custom Landing Page 不进 discovery」不是 Implementation 偏好，
而是 Page 领域定位的一部分。未来若要让 Landing Page 成为 AI 可发现对象，
必须重新开产品/领域裁决，不能在实现时偷偷扩大其 discovery 资格。

两种 Entity 关联必须严格区分：
  Entity Detail Override = Entity 的结构投影 → 随 Entity purge 消亡
  普通 Page + Entity Block = 独立 Page + 实时读取 Entity
    → Entity 消亡后 Page 继续存在，只是重算结果
```

---

## 三、Page 删除语义（正式冻结）

```text
Page 无 SoftDeletes：
  DELETE Page → 不可逆结构终态

允许清理（Page 自有结构）：
  PageBlock · Page-level SeoMeta · translation rows · 其他 Page 自有结构

禁止：
  删除 Content ❌  修改 Content 状态 ❌
  删除 Entity  ❌  修改 Entity 状态  ❌
  删除 Media   ❌  修改 Fact         ❌

判据与 D5-1 一致：自有结构随终态清理；外部知识只解除引用，
不随 Page 生命周期被 mutate。

Entity purge：
  仅级联「投影型」Detail Override；普通 Page 因 Block 是实时读模型，
  自动重算（被 purge 实体不再出现），Page 本身继续存在。
```

---

## 四、Block 语义（正式冻结）与已冻结规则关系

### Block 语义

```text
Page → Block → 当前 published domain model
不是：Page → Block → 历史快照

  Entity purge → Block 再查询 → Entity 不再出现
  Media purge  → Block 再解析 → media = null

这是正确的运行时重算行为。
不得为了「页面稳定」重新保存实体/媒体快照，把已死亡对象继续显示。
Block 不保存被引用对象的生命周期快照或计算结果。
```

### 与已冻结规则的关系（硬约束，不重定义）

```text
PKS（P15/G-9）：Page Lifecycle 不重定义 PKS；
  但本裁决冻结的 Custom Landing Page discovery isolation 属于 Page 现行领域约束。
  即：PKS 仍是上层资格模型，Page Lifecycle 冻结自身 surface eligibility boundary。
Entity Lifecycle（D5-2）：override page 随 Entity purge 级联，不改变 Entity 规则。
Semantic Reference（D5-4/G-7）：Page 不引入新语义引用；Block 实时读模型不悬空、不告警。
Fact（P14/G-8）：Page 不存事实，Block 不持久化可计算值。
Content Trash（P16/G-10）：Content(type=page) 走 Content Trash，与本裁决分离。
```

---

## 五、冻结结论（P17 / G-11 最终版）

### P17

```text
P17  Page（composition）是绑定 Template 与 Block 的结构视图，
     不是知识对象。
     ① Page 不存业务事实，不复制 Entity / Content 状态，
       不成为第二套知识 SoT。
     ② 系统页与首页的固定路由由代码 fallback 保证；
       持久化 Page 只是可编辑定制层。
       删除持久化行不得导致固定路由消失。
     ③ 自定义组合页属于 human surface，
       当前领域规则下不进入 discovery / PKS。
     ④ Entity Detail Override 是 Entity 的投影型结构载体，
       不构成独立知识对象；Entity purge 时必须随之清除。
     ⑤ Page 硬删除只清理 Page 自有结构及页面级关联，
       不得 mutate 被引用的 Content / Entity / Media。
     ⑥ Block 是实时读模型 / 数据源视图，
       不保存被引用对象的生命周期快照或计算结果。
     ⑦ 被引用对象发生 purge / 不再 published / 不可用时，
       Block 必须依据当前领域状态重新计算，
       不得形成 stale snapshot、dangling object 或渲染异常。
```

### G-11（Implementation Gate）：Page Lifecycle Integrity

```text
① System/Home fallback
   持久化 Page 缺失时，固定路由必须仍可通过代码 fallback 解析；
   不得因定制层删除导致固定页面 404。
② Page hard delete isolation
   删除 Page 必须清理 Page 自有结构：
   PageBlock / 页面级 SeoMeta / 翻译等；
   不得 mutate Content / Entity / Media / Fact。
③ Entity override purge
   Entity purge 必须清除其所属 Detail Override，
   且同时清理该 Override 的自有结构。
④ Block live recomputation
   Block 必须从当前 published domain model 实时读取；
   被引用对象 purge / unpublish / 不可用后必须重新计算，
   不得继续读取历史快照。
⑤ Discovery isolation
   Custom Landing Page 默认属于 human surface only，
   不得进入 discovery / PKS；
   绕过该隔离的实现必须通过变异测试被发现。
⑥ Structural reference integrity
   删除 Page 后，不得产生新的有效 dangling menu target；
   菜单对已不存在 Page 的引用必须被识别并进入既定治理流程。
⑦ External-reference isolation
   Page 删除、Override 删除、Block 重算均不得反向 mutate
   被引用的 Content / Entity / Media / Fact。
⑧ Mutation verification
   对 fallback、Page cascade、Entity override purge、
   Block live recomputation、discovery isolation、
   reference isolation 实施变异验证；
   删除关键护栏后对应测试必须 FAIL。
```

---

## 六、开放边界（区分「未知业务决策」与「当前默认语义」）

```text
保持开放（业务决策，未来改变须另开裁决）：
① Custom Page 是否未来需要 Trash —— 当前：硬删、无 Trash。
② Custom Page 是否未来进入 discovery —— 当前：不进入。

不再作为「是否成立」的开放问题（生命周期已冻结）：
③ Menu dangling link：G-11⑥ 已冻结——必须被识别，不得无声产生结构性悬空。
   具体实现（删除前阻断 / 删除后解除引用 / 标记 invalid）属 Implementation Contract。
④ Block 引用门禁：「不得悬空、必须实时重算」已冻结；
   具体门禁方式留 Implementation。
```

---

## 七、本裁决不做的事

```text
❌ 不改产品代码、不做 UI、不加路由
❌ 不重定义 PKS / Entity / Reference / Fact / Content Trash 已冻结规则
❌ 不把 Page 变成第二套内容 / 事实 / 知识 SoT
❌ 不保存 Block 引用对象的生命周期快照
❌ 不自动进入下一阶段
```

**下一步**：D5-6 已冻结 → **D5-7 Media Lifecycle（最后一项 D5，等授权）**
→ Architecture Decision → Implementation。
