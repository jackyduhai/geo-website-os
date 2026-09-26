# P-STEP 18L-4 — Section Composer Architecture

> 专题：定义 Page Composition Manager 升级为 **Section Composer** 的产品模型与交互边界。
> 本文为 Discovery（只读）产物。对应 TD：**TD-133**（重定义 / 吸收 TD-119）；与 TD-132 协同。
> 主文档：`geo-template-experience-discovery-18l4.md`。

---

## 1. 定位：槽位式 Section Composer，不是自由 Page Builder

- **是**：在模板声明的**槽位（Slot）内**，以 Section（block 实例）为单位进行
  添加 / 编辑 / 排序 / 显隐 / 复制 / 删除 / 预览 / 发布。
- **不是**：Elementor 式自由拖拽、Figma 式画布、自由 HTML / 行列网格搭建器。

理由：GEO 官网要求**结构长期稳定、AI 可理解、可维护、可升级**；
完全自由排版会破坏槽位契约、无障碍、SEO 与模板可迁移性。
因此 V1 用「**模板定槽位 + 管理员在槽位内组合 Section**」的受控模型。

---

## 2. 数据模型（沿用，不新增第二套）

```
Site（站点，作用域边界）
  └─ Template（TemplateDefinition：槽位 Slot + 每槽 block 白名单 + 继承）
       └─ Page（页面实例：site_id / template / slug / locale / status / SEO 绑定 / 可选 entity_id）
            └─ PageBlock（区块实例：slot / type / sort / is_active / 结构化 content JSON）
                 └─ 引用 → Content / Entity / Media / Form（权威事实，不在 Page 复制）
```

- **Template 管结构**（这里允许什么），**Page 管实例**（用哪个模板 / 状态 / 路由 / SEO），
  **Block 管组成单元**（结构化数据 + 注册渲染器），**Content/Entity/Media/Form 管事实**。
- system block（entity_*）由 Entity 直驱固定槽，**不进自由添加**（避免第二套 Entity 状态）。

---

## 3. 现有能力盘点（18G / 18L-3b 已具备）

`admin/pages/composer.blade.php` 当前已闭环 **9 类操作**（对应路由 `routes/admin.php`）：

| 操作 | 路由 / 方法 | 状态 |
|---|---|---|
| Add（选择 block 加入槽位） | `pages.addBlock` / `storeBlock` | ✅ |
| Edit（编辑结构化字段） | `pages.editBlock` / `updateBlock` | 已有 13 类字段编辑器（text/textarea/markdown/number/select/checkbox/media/items/buttons/source…） |
| Move Up / Down | `pages.moveBlock {up\|down}` | ✅ 按钮式排序 |
| Hide / Show（显隐） | `pages.toggleBlock` | ✅ `is_active` |
| Duplicate | `pages.duplicateBlock` | ✅ |
| Delete | `pages.destroyBlock` | ✅（提示不删除引用的业务内容） |
| Preview | `pages.preview` | ✅ |
| Publish / Unpublish | `pages.publish {action}` | ✅ |
| 页面设置 | `pages.edit` / `update` | ✅ |

- Add 选择器数据源：`BlockRegistry::selectableForSlot()`（槽位白名单 + 排除 system）。
- 槽位结构来自 `TemplateDefinition::slotNames()` / `allows()`。

**结论**：Block CRUD（TD-58）在 18G-2b 已基本完整；TD-133 的工作是**收口与语义增强**，不是重做。

---

## 4. 18L-4 增补能力（TD-133）

### 4.1 Variant 快速切换
- 在 composer 的 Section 行内提供**组件变体切换**（如 Hero：default/split/center/image/product；
  Card：default/border/shadow/glass/minimal；Button：primary/secondary/outline/ghost/text）。
- 变体来自 `ComponentRegistry`（受控），切换只改展示形态，**不改 H1 / 事实 / SEO / Schema**。
- 与 `ComponentResolver` 同一套解析，不在 composer 写变体分支。

### 4.2 Section 语义可见 / 校验
- 每行展示该 block 的 Section 语义（section / purpose / entity / conversion，来自 TD-132 声明），只读。
- 当配置与语义冲突（如转化区无有效按钮、grid 无数据源）给内联校验提示（接 validate 结果）。

### 4.3 键盘可达排序（无障碍增强，替代「自由拖拽」）
- 保留 Up / Down 按钮为基础 fallback。
- 建议增加**键盘操作**（Tab 聚焦行 + 方向键上 / 下移动），作为鼠标拖拽的无障碍等价物。
- **不做**复杂鼠标自由拖放画布；如加入轻量 drag-and-drop，必须同步保留键盘 / 按钮路径
  （TD-119 的可达要求），且仅在槽位内、按现有顺序持久化。

### 4.4 槽位约束可视化
- 标明每个槽位允许的 block 类型与当前数量；非法组合在添加前即不可选（沿用 forSlot 白名单）。

---

## 5. 交互流程（管理员真实路径）

```
新建/选择 Page → 选择 Template（系统页 / Landing / 空白）
      ▼
进入 Composer（按槽位分区列出 Section）
      ├─ Add：从槽位允许的 block 中选择 → 编辑结构化字段
      ├─ Edit：修改字段（含 variant）
      ├─ Move：Up/Down 或键盘排序
      ├─ Hide/Show：控制 is_active
      ├─ Duplicate：复制 Section（结构化数据）
      └─ Delete：移除 Section（不删引用事实）
      ▼
Preview（只读，不落地）
      ▼
Publish（发布页面 / 解绑前后台门禁）
      ▼
前台经统一 Render Context 渲染
```

**零代码验收主线**：全过程不修改 PHP / Blade / JS / CSS。

---

## 6. 安全与约束边界

1. **结构化数据 + 注册渲染器**：block content 为 JSON；不存任意 Blade/HTML/PHP（边界 ⑫）。
2. **URL scheme 白名单**：Section 内按钮 / 链接 url 走通用 SafeUrl（TD-130），拒绝 javascript:/data:。
3. **槽位白名单**：只能加入模板槽位允许的 block；system block 不可手动添加。
4. **不复制事实**：Page / Block 只引用 Content / Entity / Media / Form，不形成第二事实源。
5. **权限**：沿用 v1.0 两级模型（Super Admin 跨站 / Site Admin 单站）；所有写操作 Site-scoped。

---

## 7. Cache 失效（随操作精确触发）

| Composer 操作 | 缓存失效 |
|---|---|
| Add / Edit / Delete / Move / Toggle / Duplicate | 该 Page（Site × Locale；perLocale 块仅对应语言） |
| Variant 切换 | 该 Page |
| Publish / Unpublish | 该 Page + 公开门禁（sitemap/feed 准入）同步 |
| Template 变更 | 使用该模板的全部 Page |
| 引用的 Entity / Content / Form 变更 | 关联 Page（沿用增量失效） |

原则：页面级精确失效，不退回全站 flush 作为唯一手段。

---

## 8. 明确不做（V1）

- Elementor / Figma 式自由画布、自由行列网格、像素级定位。
- 任意 HTML / Blade / JS 编辑与存储。
- 跨槽位 / 跨页面拖拽、多步 / 条件区块编排。
- 在线模板 / block 市场上传（归 v1.1）。

---

## 9. 验收要点（供 Gate）

**正向**
- 槽位内完成 Add/Edit/Move/Toggle/Duplicate/Delete/Preview/Publish 全操作，前台正确渲染。
- variant 快切生效且 H1/Schema/SEO 不变；键盘排序可达。

**反向 / 对拍**
1. 槽位不允许的 block 在 Add 中不可选；system block 不可手动加。
2. 按钮含 javascript: → 被 SafeUrl 拦截（TD-130）。
3. 删除 Section 后，被引用的 Entity/Content/Media 仍存在。
4. 修改布局后：canonical / JSON-LD / sitemap / llms / Heading 层级逐项对拍一致。
5. zh/en × light/dark、多站：组合结果隔离、Console 0。
6. 排序 / 显隐变更后旧缓存确实失效（真实 HTML 对拍，非只看 cache key）。

---

## 10. 结论

- Section Composer 在已闭环的 9 操作 CRUD 上，增补 variant 快切、Section 语义可见 / 校验、
  键盘可达排序与槽位约束可视化；坚持「槽位受控、非自由拖拽、结构化数据 + 注册渲染器」。
- 落地归入 **TD-133**，**重定义并吸收 TD-119**（不追求传统自由拖拽）。
- **STOP，等待裁定，不编码。**
