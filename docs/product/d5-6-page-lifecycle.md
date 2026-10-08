# D5-6 裁决建议：Page Lifecycle（页面生命周期）

- **状态**：**PROPOSED（待裁定）**（2026-10-08）· 领域裁决，非实现设计
- **上游**：D1（生命周期域模型）· D4（P6 禁 CASCADE）· D5-2 Entity Lifecycle
  · D5-4 Semantic Reference · D5-5 Fact Ownership · PKS Boundary · D5-1 Content Trash
- **章程**：`docs/product/stage1-discovery-charter.md`
- **硬约束**：本裁决**不重定义**已冻结的 PKS / Entity / Reference / Fact / Content Trash 规则。
- **纪律**：不改产品代码、不做 UI、只定义页面生命周期契约。

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

## 二、Page 四个子角色的生命周期

```text
① 系统页：路由由代码保证（fallback），持久化行 = 可编辑定制层。
   删除持久化行 → 定制（page-level SEO / 区块）丢失，但路由不消失。
② 首页：同系统页，代码 fallback。
③ 自定义落地页：硬删、无回收站、不可恢复；
   人类面 only，不进 discovery。
④ 实体详情 override：归 Entity 所有，Entity purge 时级联清除。
```

---

## 三、删除 / 级联语义

```text
Page 删除（硬删）：
  清理「页面自己拥有」的结构：PageBlock · 页面级 SeoMeta · 翻译行
  不 mutate 被引用的 Content / Entity / Media（Block 仅解除读取）。
  判据与 D5-1 一致：自有关联随终态清理，外部知识只解除引用。
Entity purge：
  仅级联「投影型」override page；其余页面因 Block 是实时读模型，
  自动重算（被 purge 实体不再出现）。
```

---

## 四、与已冻结规则的关系（硬约束，不重定义）

```text
PKS（P15/G-9）：系统页 / 首页 / 自定义页的 discovery 资格仍由 PKS 裁决；
  本裁决只定义「页面结构如何持有 / 重算 / 终态」。
Entity Lifecycle（D5-2）：override page 随 Entity purge 级联，不改变 Entity 规则。
Semantic Reference（D5-4/G-7）：Page 不引入新的语义引用；Block 实时读模型
  不悬空、不告警。
Fact（P14/G-8）：Page 不存事实，Block 不持久化可计算值。
Content Trash（P16/G-10）：Content(type=page) 走 Content Trash，与本裁决分离。
```

---

## 五、建议冻结结论

### P17（建议编号）

```text
P17  Page（composition）是绑定 Template 与 Block 的结构视图，
     不是知识对象：不存业务事实、不复制 Entity / Content 状态。
     系统页与首页的路由由代码 fallback 保证，
     持久化行是可编辑定制层——删除它重置定制，不消除路由。
     自定义落地页为硬删、无回收站；实体详情 override 归 Entity
     所有并随其 purge 级联。Page 的生命周期不 mutate
     被引用的 Content / Entity / Media。
```

### G-11（建议编号 · Implementation Gate）：Page Lifecycle Integrity

```text
① 系统页 / 首页在持久化行缺失时必须经 fallback 解析
   （路由不因记录缺失而 404）。
② 删除 Page 必须级联其 PageBlock 与页面级 SeoMeta，
   且不 mutate 被引用的 Content / Entity / Media（断言被引用对象不变）。
③ Entity purge 必须删除绑定的 override page。
④ Block 数据源必须在渲染时从 published 领域模型读取；
   被引用 Entity / Media 消失时重算（条目消失 / 图片 null），不得报错或悬空。
⑤ 自定义组合页默认不进 discovery 面（人类面 only）；
   须变异验证（短路 fallback / 绕过 published 读取 → 对应测试 FAIL）。
```

---

## 六、开放边界（显式记录，不静默解决）

```text
① 自定义组合页是否需要「可恢复回收站」（当前硬删）：
   属产品/Implementation 决策；若引入，应与 D5-1 对齐而非新造。
② 自定义组合页是否应进入 discovery（当前排除）：
   若要纳入，须作为显式产品规则，不改变 PKS 模型。
③ 菜单 dangling link 是否需要引用校验（当前无）：
   属结构引用治理，留待后续。
④ Block 对 Form / Media / Entity 的引用是否需要门禁式校验，留待 Implementation。
```

---

## 七、本裁决不做的事

```text
❌ 不改产品代码、不做 UI、不加路由
❌ 不重定义 PKS / Entity / Reference / Fact / Content Trash 已冻结规则
❌ 不把 Page 变成第二套内容 / 事实表
❌ 不自动进入下一阶段
❌ 本文件在裁定通过前不加入 check-discovery-docs.sh
```

**下一步**：D5-6 经用户裁定后 → D5-7 Media Lifecycle（最后一项 D5）
→ Architecture Decision → Implementation。
