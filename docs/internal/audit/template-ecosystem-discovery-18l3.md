# P-STEP 18L-3 Discovery — Template Ecosystem：现状盘点（as-is）

- **阶段**：P-STEP 18L-3 DISCOVERY（模板生态，未授权不编码）
- **基线**：HEAD `253180d`（checkpoint-18L-2b）；Full Regression **1028 / 5428 / 0 / 0**；worktree clean
- **上游**：18L-1 Token Foundation（PASS）、18L-2a/2b Component Design System（PASS）
- **配套**：`template-ecosystem-architecture-18l3.md`（目标架构 / Manifest 标准 / 任务清单）
- **状态**：本文为现状（as-is）与 Gap 盘点，供用户裁定；未授权不编码。

---

## 1. 本阶段要回答的问题

18L-1/2 已让 token 可被 Theme 消费、组件可被 Registry 管理、组件在交互场景具备完整状态。
**18L-3 要解决的是「可分发、可安装、可被第三方创建」的模板生态**：

- 一个 Template Pack 能否作为一个**物理目录 / 单一产物**被分发、发现、激活、回滚？
- 模板能否携带**开箱页面组合（recipe）**，而不只是抽象 slot？
- 第三方开发者按什么 **Manifest / SDK 规范**创建模板？
- 8 类官网（按**商业目的**）的官方模板如何组织？

---

## 2. 现状盘点（as-is）

### 2.1 模板是纯代码注册，无物理可分发包

| 维度 | 现状 |
| --- | --- |
| 注册来源 | `TemplateRegistry::boot()` 从 **`config/templates.php`** 声明式加载 |
| 模板数量 | 6 个：`base / home / landing / listing / detail / contact` |
| 值对象 | `TemplateDefinition{ key, label, extends, slots, layout }`（只读） |
| 继承 | `extends` + Registry 加载时合并父模板「独有」slot（子顺序为准） |
| 物理包 | **不存在**：根目录与 `resources/` 下无 `templates/`、`packages/` 目录 |
| 元数据 | **缺失**：无 version / author / industry / supported locales / supported themes / 依赖组件 / 默认页面 / 默认区块 / SEO·GEO 元数据 |
| 选择器 | `TemplateRegistry::selectable()` 排除抽象 `base`，供后台「新建页面」 |

> 结论：模板目前是**写死在框架配置里的结构定义**，无法作为独立产物分发，也无法被第三方新增。

### 2.2 模板只声明「结构」，不携带「开箱组合（recipe）」

- `TemplateDefinition` 的 slot 只表达「这里**允许**哪些 block 类型」（`blocks => '*' | type[]`），
  **不表达「默认应该放哪些 block、什么顺序」**。
- 真正的「开箱页面组合」目前**全部硬编码在 seeder PHP**：

| 开箱组合 | 位置 | 内容 |
| --- | --- | --- |
| Blank 首页 | `BlankHomepageSeeder` | 中性 is_home Page（zh/en），**清空区块**，回落欢迎屏 |
| 固定系统页 | `SystemPageSeeder` | 9 system_key × zh/en = 18 Page；contact 另挂 3 block（rich_text/contact_info/form_reference） |
| Demo 首页 | `StructureSeeder::buildHomepage()` | is_home Page 挂 9 个 block（hero/service_grid/feature_grid/product_grid/stats/content_grid/testimonial/faq/cta），双语 |
| 默认表单 | `DefaultFormSeeder` | 中性 contact 表单（name/phone required、email/message optional） |

> 结论：管理员能在后台「从零组合」，但**第三方无法分发一套「装好即有合理默认页面组合」的模板**——
> recipe 知识锁在版本化的 PHP seeder 里，不是可携带的数据。

### 2.3 Block 同样不能由 Pack 携带

- `BlockRegistry::boot()` 从 **`config/blocks.php`** 加载约 24 个类型（普通 + system）；
- 每个 block 的渲染视图写死在 `resources/views/site/blocks/{type}.blade.php`；
- **没有「Pack 注册新 block / 新 renderer」的目录与机制**（无 `components/` 加载、无 Pack 命名空间）。

> 这是一个需要**明确裁定边界**的点：V1 是否允许模板包携带新 block 类型？
> （建议见架构文档：V1 模板包只组合**已注册核心 block**，不携带可执行新 renderer，避免安全 / 兼容失控；
> 「Pack 携带 block」作为 v1.1 的受控扩展点。）

### 2.4 主题包机制已闭环，可作为模板包的平行参考

`ThemeManager`（18K 闭环）提供了完整的「文件分发 + 生命周期」范式：

- 物理目录：`resources/themes/{name}/theme.json`（+ 可选 `views/`）；
- `all()`：`glob` 扫描清单；`exists()`；`invalidThemes()`：**已放置但清单无效**的目录可见告警；
- `activate()`：写站点设置 → 重注册 → `PageCache::flush()`；
- `preview()`：请求级预览（try/finally 复位，不泄漏）；
- `activeTokens()`：主题携带视觉 token，经 **`ThemePresets::allowedKeys()` 白名单**过滤，无法注入非外观键。

> 可复用点：模板包应建立**平行的 `TemplatePackageManager`**（发现 / 校验 / 激活 / 预览 / 回滚 / 无效告警），
> 但职责严格区分——**Theme = 视觉（token）；Template = 结构 + recipe（不复制业务事实）**。
> 现状：`resources/themes` 仅 `default / example`，且只携带 token（无 views）。

### 2.5 渲染管线已统一（关键优势，不应重造）

- 所有页面类型已收敛到统一管线：
  `RenderContext`（`Page / Entity / Listing / SystemPage / Home` 实现）→ `CompositionRenderer`
  → 遍历 template slot → `BlockRegistry::render` → `site.composed`。
- SEO / Schema / GEO / URL / 缓存均已统一：
  `SeoMetaResolver`、`SchemaBuilder`、`GeoGraphBuilder`、`PublicUrl`、`PublicIndex`、`PageCache`。

> 结论：模板包加载后，**只需注册 `TemplateDefinition` + 应用 recipe**，渲染仍走同一管线；
> **不需要、也不允许**第二套渲染 / SEO / URL 逻辑。这是 18L-3 能低成本落地的基础。

### 2.6 行业矩阵现状：按「行业 × 气质」8 类，需改按「商业目的 OS」

- 现有 `industry-template-matrix-18l.md` 已按「行业 × 品牌气质」形成 8 类：
  SaaS/AI、制造工业、新消费品牌、教育培训、医疗健康、企业服务、外贸国际化、品牌官网。
- 用户最新裁定：**改按「官网商业目的」重组为 8 类 OS**（命名 / 定位调整，新增 Real Estate·Construction）：

| 用户裁定的 8 类 OS | 现有矩阵对应 | 差异 |
| --- | --- | --- |
| 1. Manufacturing OS | 2.2 制造工业 | 对齐 |
| 2. SaaS / Technology OS | 2.1 SaaS/AI | 对齐 |
| 3. Brand Commerce OS | 2.3 新消费品牌 | 对齐（含品牌叙事） |
| 4. Professional Service OS | 2.6 企业服务 | 改名 / 定位 |
| 5. Healthcare OS | 2.5 医疗健康 | 对齐 |
| 6. Education OS | 2.4 教育培训 | 对齐 |
| 7. Real Estate / Construction OS | —（原「品牌官网」#8 让位） | **新增**，需补 recipe |
| 8. International Export OS | 2.7 外贸国际化 | 对齐（English-first） |

> 原「品牌官网（极简叙事）」的能力并入 **Brand Commerce OS** 的叙事变体 / 通用排版，不单独成类。
> 8 类 OS 的具体 recipe（页面 + slot + block 序列）在架构文档 §4 重写。

---

## 3. Gap 清单（能力缺口）

| # | 缺口 | 现状 | 目标 |
| --- | --- | --- | --- |
| G1 | **Template Package 物理结构** | 无包目录 / 无 manifest | 标准化可分发目录（manifest + template + recipe + 预览图） |
| G2 | **Manifest 契约 + 校验器** | 无 | name/version/author/industry/supports/locales/themes/slots/recipe 校验，非法包可见告警 |
| G3 | **TemplatePackageManager（生命周期）** | 无 | glob 发现 / exists / invalid / activate / preview / rollback（平行 ThemeManager） |
| G4 | **Recipe 数据化 + Applier** | recipe 锁在 seeder | recipe 声明默认 block 骨架；应用包 / 新建页面时生成结构化空 block |
| G5 | **Pack 与核心渲染接线** | 仅 config 模板 | 包注册 TemplateDefinition 进 Registry，渲染仍走 CompositionRenderer（不造第二套） |
| G6 | **8 类商业目的 OS 官方 Recipe** | 行业×气质 8 类 | 重组为 8 OS，每类含 home/listing/detail/contact/landing recipe |
| G7 | **第三方 Template SDK / 规范文档** | 无 | 「如何创建模板」的 manifest / slot / recipe / GEO 契约规范 |
| G8（待裁定） | Pack 是否可携带新 block / renderer | 不支持 | 建议 V1 封闭（仅核心 block），v1.1 受控开放 |

---

## 4. TD 映射与新增建议

| TD | 主题 | 现状 | 18L-3 动作建议 |
| --- | --- | --- | --- |
| **TD-117** | Template 非可分发 Package / 缺 SDK | ACTIVE | G1 + G2 + G3 + G7（Package 结构 / Manifest / Manager / SDK） |
| **TD-118** | Template 不携带 recipe | ACTIVE | G4 + G6（Recipe Applier + 8 OS recipe） |
| **TD-119** | 排序无拖拽 | ACTIVE（→18L-4） | 不在 18L-3 |
| **TD-121** | 区块语义元数据 | ACTIVE（→18L-4） | 不在 18L-3 |
| **新增 TD（建议）** | Template Package 生命周期管理器（G3） | 未登记 | 建议作为 TD-117 子项或独立 TD |
| **新增 TD（建议）** | Recipe Applier（G4） | 未登记 | 建议作为 TD-118 子项或独立 TD |
| **新增 TD（建议）** | Pack 携带 block 的受控扩展（G8） | 未登记 | 建议 **DEFERRED v1.1** |

> 说明：为避免 TD 数量膨胀，建议 G3/G4 作为 TD-117/TD-118 的验收子项，
> 仅 G8（Pack 携带 block）作为独立 DEFERRED 项登记；最终编号由用户裁定。

---

## 5. v1.0 / v1.1 边界建议

**v1.0（18L-3，建议纳入）**
- Template Package 物理结构 + Manifest 契约 + 校验器；
- TemplatePackageManager（发现 / 激活 / 预览 / 回滚 / 无效告警）；
- Recipe 数据化 + Applier（生成结构化空 block，不复制业务事实）；
- 8 类商业目的 OS 官方 recipe；
- 第三方 Template SDK 规范文档。

**v1.1（建议 DEFERRED）**
- Pack 携带新 block / 新 renderer 的受控扩展（G8）；
- 模板在线安装 / ZIP 上传 / Marketplace（沿用 TD-110）；
- 行业包的 AI 自动生成（依赖未来 embedding / 模板生成）。

**铁律（继续生效）**
- Pack 只改结构 + 组合，**不产生行业业务数据**（工厂 / OEM / 产能 / 价格仍来自 Content / Entity / Example）；
- 视觉 / 模板演进**不得改动** Entity Fact / Schema / URL / SEO / Sitemap / LLMS 公开契约；
- 一个事实只有一个 runtime source，Pack 不得形成第二事实源。

---

## 6. STOP

本现状盘点为 Discovery 结论，**等待用户裁定**：
1. Template Package 目录结构与 Manifest 字段；
2. V1 是否允许 Pack 携带新 block（建议否）；
3. 8 类商业目的 OS 的最终范围；
4. Gate 切分与 TD 编号。

裁定后再授权进入 18L-3 Implementation；未授权不编码。
19A / remote / push / rc1 / Release 继续 HOLD。
