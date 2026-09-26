# P-STEP 18L-4b-2 — Section Composer Lite · Final Gate

- 父 Epic：**#116 Page Composition / Template System**
- 阶段：**18L-4b-2（吸收 TD-119，重定义为 TD-133）**
- 日期：2026-09-26
- Gate 结论：**✅ ACCEPTED / PASS**

---

## 1. 阶段定位

18L-4b-1 已落地 GEO Section 机器语义（`data-section/purpose/entity/conversion`）。
本阶段把后台 Page Composition Manager 收口为 **Section Composer Lite**：让企业用户
**无需修改 PHP / Blade / JS**，即可安全、结构化地调整页面区块，同时不破坏页面稳定、
SEO、GEO 语义与公开契约。

本阶段**不是**自由拖拽设计器（Elementor / Wix / 商城装修器），而是语义化、受策略
约束的页面结构编排。

---

## 2. 交付能力（5 项）

| # | 能力 | 说明 |
|---|------|------|
| 1 | **Section 语义查看** | composer 每行展示区块的 `section / purpose / entity / conversion` chips，管理员可见区块在 GEO 层的角色 |
| 2 | **顺序调整（键盘可达）** | 复用 18G 的上移 / 下移 POST 按钮（键盘天然可达），排序经 moveBlock 持久化、触发该页缓存失效 |
| 3 | **Variant 快速切换** | Hero 行内下拉快切 `split ↔ center`，只改 Component Variant，不改 HTML 结构 / H1 / 数据 |
| 4 | **增删已有 Block** | 新增区块必经 BlockRegistry（storeBlock），禁止未知 / system block；删除经 destroyBlock |
| 5 | **CompositionPolicy 安全限制** | 槽位上限、block→组件映射、variant 白名单、语义必填，所有约束 **fail-closed** |

---

## 3. 架构与文件

```
Admin Composer (composer.blade)
   │  语义 chips 列（SectionSemantic::for）
   │  Hero variant 快切 form
   ▼
PageController::setVariant  ──► CompositionPolicy::isAllowedVariant（白名单裁决）
   │  写 block content（仅 variant 键）
   │  AuditLog::recordChange('block.variant')
   │  PageCache::forgetPage（页面级精确失效）
   ▼
hero.blade  ──► CompositionPolicy::isAllowedVariant 校验
   │  根 class = hero-{variant}
   │  split：左右两栏 + hero-visual；center：文案居中、不渲染 visual
   ▼
SectionSemantic（data-* 输出，与 variant 无关）
```

| 文件 | 类型 | 内容 |
|------|------|------|
| `app/Support/Blocks/CompositionPolicy.php` | 新增（final） | 槽位上限、block→组件映射、variant 白名单、fail-closed 裁决 |
| `config/blocks.php` | 修改 | Hero fields 增 `variant` select（split/center）、default 增 `variant=split` |
| `resources/views/site/blocks/hero.blade.php` | 修改 | 消费 variant：根 `hero-{variant}`、按钮容器 `hero-actions`、visual 仅 split；**含旧数据兼容（两分支均 `?? 'split'`）** |
| `app/Http/Controllers/Admin/PageController.php` | 修改 | storeBlock 增槽位上限阻断；新增 `setVariant()` |
| `routes/admin.php` | 修改 | 新增 POST `pages/{page}/blocks/{block}/variant`（name `pages.setVariant`） |
| `resources/views/admin/pages/composer.blade.php` | 修改 | GEO 语义 chips 列 + Hero variant 快切 + 排序按钮 aria-label |
| `tests/Feature/SectionComposer18L4b2Test.php` | 新增 | 8 场景、24 assertions |

---

## 4. CompositionPolicy 安全边界

- `MAX_BLOCKS_PER_SLOT = 20`：单槽位（含隐藏）区块上限，防止无限堆叠。
- `COMPONENT_FOR_BLOCK = ['hero' => 'hero']`：v1.0 仅 Hero 打通「选 variant → 渲染」。
- `EXPOSED_VARIANTS = ['hero' => ['split', 'center']]`：
  - **split**：`.hero-split .wrap` 左右两栏（文案 + hero-visual 配图）。
  - **center**：`.hero-center .wrap` 文案居中、不渲染配图。
  - default / image（轮播）/ product（产品主视觉）需额外数据结构，**保留 v1.1**。
- 方法：`maxReached / componentForBlock / allowedVariants / isAllowedVariant / semanticRequired`。
- 所有裁决 **fail-closed**：未在白名单内的 variant 一律拒绝并回退。
- 边界：只做策略裁决，不渲染、不保存、不复制业务事实；槽位是否允许某 block 仍由
  `TemplateDefinition::allows + BlockType::allows` 决定。

---

## 5. Variant 切换对拍（真实浏览器）

切换首页 Hero `split → center`，前后逐项对拍：

| 对拍项 | split | center | 结果 |
|--------|-------|--------|------|
| 根 class | `hero-split reveal in` | `hero-center reveal in` | 布局变化（预期） |
| `data-section/purpose/entity` | `hero / brand / Organization` | `hero / brand / Organization` | **不变 ✅** |
| H1 文案 | 工业涂料 · 胶粘剂 · 功能助剂 一体化定制 | 同左 | **不变 ✅** |
| canonical | `http://127.0.0.1:8171/` | 同左 | **不变 ✅** |
| hero-visual 配图 | 渲染 | 不渲染 | 预期（center 无图） |
| Browser Console | 0 error | 0 error | **✅** |

验证后已切回 `split` 恢复原状。

---

## 6. 验收证据

### 6.1 全量回归

- **1118 passed / 5971 assertions / 0 failed / 0 skipped**（Duration 441.36s）
- 对比 4b-1 基线 1110 / 5945：**新增 8 测试、断言 +26**，无测试缩减。
- 首次全量曾 63 failed（旧 seed/demo hero content 无 `variant` 键、true 分支直接访问
  `$c['variant']` 报 Undefined array key）——这是真实向后兼容缺陷，已修复（true 分支
  同样加 `?? 'split'`），受影响 38 测试复跑全过后重跑全量。

### 6.2 浏览器矩阵（真实 Chrome）

| 场景 | 结果 |
|------|------|
| zh Light：hero-split + 语义 + H1 + canonical | ✅ Console 0 |
| en Light（html lang=en）：hero-split + 英文 H1 + canonical=/en | ✅ Console 0 |
| zh Dark（data-color-scheme=dark、body rgb(11,18,32)、H1 浅色 rgb(232,237,244)） | ✅ Console 0 |
| en Dark：同上 + 语义不变 | ✅ Console 0 |
| composer 语义 chips（10 个区块逐行 section/purpose/entity/conversion） | ✅ |
| Hero variant 快切 split↔center | ✅ 契约不变 |

### 6.3 模板包校验

- 8 个 Business OS 包 `template:validate` **全部 exit=0**（manufacturing / saas / commerce
  / service / healthcare / education / construction / export），ERROR=0、WARNING=0。

### 6.4 工程卫生

- Runtime 强身份词污染：**0**。
- 验证窗口新增产品 ERROR：**0**。
- 工作区：提交后 clean。

---

## 7. TD 关闭

| TD | 状态 | 说明 |
|----|------|------|
| **TD-133** Section Composer Lite | **CLOSED（18L-4b-2）** | 5 项能力全部落地、对拍通过 |
| **TD-119** 区块排序拖拽 | **CLOSED（由 TD-133 重定义吸收）** | v1.0 采用键盘可达的上移 / 下移 + 语义编排；自由拖拽 Canvas 归 v1.1 |

---

## 8. 硬边界 / 明确不做（v1.0）

- ❌ 自由拖拽画布 / Canvas 像素级编辑
- ❌ 在线 HTML 编辑、自定义 CSS / JS
- ❌ 上传模板代码 / 模板包携带 Blade·PHP·JS·Controller
- ❌ 修改 Schema 结构、URL 规则
- ❌ Hero default / image（轮播）/ product variant 的 block 暴露（v1.1）

页面结构调整始终：不改 H1、不改 GEO 语义、不改 canonical/SEO/Schema，仅在受控白名单内
改变布局，并做页面级精确缓存失效。

---

## 9. 基线

- HEAD（本 Gate tag）：**checkpoint-18L-4b-2**
- 上一基线：checkpoint-18L-4b-1（HEAD `3052b86`）
- Regression：**1118 / 5971 / 0 / 0**
- `v1.0.0-rc1`（`965d63c`）：**保持不动 / HOLD**
- Remote / Push / Release：**HOLD**

---

## 10. 下一阶段

- **18L-4b-3 — Template Migration Lite（TD-131，HOLD）**：声明式 block rename / token
  rename / deprecated warning（replace-map），槽位级幂等、可对拍回滚、页面级失效。
  需用户明确授权后方可开始。
- 本 Gate PASS 后 **STOP**，不自动进入下一阶段，也不进入 19A 发布工程。
