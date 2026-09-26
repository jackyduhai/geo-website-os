# P-STEP 18L-3b — Template Ecosystem Completion：Discovery

- **阶段**：P-STEP 18L-3b（Template Ecosystem Completion）
- **基线**：HEAD `4012c80`（checkpoint-18L-3a），worktree clean
- **状态**：**Discovery 完成，STOP 等待架构裁定**（未改业务代码）

---

## 1. 18L-3a 已建立的能力（现状）

| 能力 | 现状 |
|---|---|
| 物理包 | `resources/templates/manufacturing-pro/`（唯一包） |
| manifest.json | id / name / version / industry[] / locales[] / theme / requires.components[] / pages[] / author / license |
| template.json | `definitions`：mfg-home、mfg-landing（包级模板） |
| theme.json | 仅引用已安装主题（default），不携带主题代码 |
| recipes/ | homepage / products / contact / about（**已被 template:apply 消费**） |
| defaults/ | settings / menus / seo（**空骨架，当前无任何消费者**） |
| preview/ | desktop.webp / mobile.webp（占位） |
| Validator | `TemplateManifestValidator`：校验 manifest 元数据与依赖 |
| PackageManager | `TemplatePackageManager`：all / exists / invalidPacks / active / activate / deactivate / register / recipes |
| Registry | `TemplateRegistry`：双来源（core + pack），normalizeSlots |
| RecipeApplier | `RecipeApplier::apply`：recipe → Page + PageBlock，槽位级幂等 |
| 命令 | `template:list`、`template:apply {pack}` |

**已确认的良好边界**：包内无 PHP/Renderer/Blade/Controller；渲染统一走 CompositionRenderer；激活态 Site-scoped（Setting `template_active_pack`）。

---

## 2. 缺口盘点（对应用户授权的 5 范围）

### GAP-1：Template Metadata 缺失（purpose / entities / conversion）

- manifest.json **无** purpose / entities / conversion 字段。
- 这些字段是「AI 理解模板 / GEO 推荐模板 / 模板匹配 / 自动生成站点」的依据。
- `TemplateManifestValidator` 也未校验这三类字段。
- **必须用受控词表**（非自由文本），否则 AI 消费时口径发散。

### GAP-2：无深度 template:validate 命令

- 现有 Validator 只校验 **manifest**，不校验 recipe / defaults 内容。
- 18L-3a 真实暴露的问题：recipe `source` 写成字符串（应为 `{"mode":...}`），直到运行 productCards 才 TypeError 500——**声明 → block 参数之间缺 schema 校验**。
- 需要 `template:validate {pack}` 在「应用前」静态对拍：
  - manifest 合法性
  - 每个 recipe 的结构（key/target/template/blocks）
  - 每个 block content 是否符合该 BlockType 的 `fields` schema
  - block type 是否注册、是否允许进入该 target 槽位
  - requires.components 依赖
  - locale map 键是否均为已注册语言
  - defaults 文件 JSON 合法性
- 输出分级：**ERROR（不可应用）/ WARNING（可应用但有风险）**。

### GAP-3：defaults/ 无消费者（包未成为「安装包」）

- defaults/{settings,menus,seo}.json 已占位但**无任何代码读取**。
- 文件内 comment 明确：应由「installer」在显式操作下消费，**RecipeApplier 不得强制覆盖已有站点**。
- 需要一个显式安装机制，把包从「页面骨架」升级为「完整安装包」：
  - `settings.json` → Site Setting（如 site_supported_locales）
  - `menus.json` → 主导航 / 页脚 Menu
  - `seo.json` → site-level SeoMeta
- **硬边界**：
  - defaults 是「建议默认值」，只在 fresh/blank 状态落地；已有值不覆盖。
  - 不产生第二事实源（仍写 Setting / Menu / SeoMeta 正式模型，由对应 Resolver 消费）。

### GAP-4：Business OS 只有 1/8

- 当前仅 manufacturing-pro；`TemplateManifestValidator::INDUSTRIES` 已枚举 8 类，但只有 1 类有真实包。
- 缺：saas-technology、brand-commerce、professional-service、healthcare、education、construction-realestate、international-export。
- **关键纪律（用户强调）**：「不要简单复制 8 套页面」——不是 8 套 Blade / 8 套渲染逻辑，而是 8 个声明式 recipe 包，**统一走 Narrative → Recipe → Composition 同一管线**。

### GAP-5：Admin 无 Template 管理入口

- Admin 控制器中**无 TemplateController**（最接近的是 ThemeController）。
- 管理员目前只能通过命令行 `template:apply` 使用模板，未形成前后台闭环（违背 18E 确立的「前台能力必须有后台来源/入口」原则）。
- 需要 Admin Template 管理：查看 / 预览 / 应用 / 当前状态 / 回滚。

---

## 3. 现有可复用资产（不重新造）

| 需要的能力 | 复用现有 |
|---|---|
| 包发现 / 激活 / 生命周期 | `TemplatePackageManager`（扩展，不重写） |
| manifest 校验 | `TemplateManifestValidator`（扩展字段校验） |
| recipe 落地 | `RecipeApplier`（已闭环） |
| block 字段 schema | `BlockType::$fields`（config/blocks.php 已声明每个 block 字段类型） |
| 模板注册 | `TemplateRegistry`（双来源已就绪） |
| Admin 平行范本 | `ThemeController` + `admin/themes/index.blade.php` |
| settings / menus / seo 落地 | `Setting` / Menu 模型 / `SeoMeta` 模型（正式事实源） |
| 请求级预览 | `SubRequest`（Theme preview 同款） |
| 缓存 | `PageCache`（activate/recipe 已触发 flush） |

**结论：18L-3b 全部是「扩展 + 接线 + 声明式补包」，不需要新渲染/SEO/URL 体系，无第二事实源风险。**

---

## 4. 需要用户裁定的关键决策点

> 详见 `template-ecosystem-completion-architecture-18l3b.md` 中的推荐方案。

1. **Metadata 落点**：放 manifest.json（推荐）还是独立 metadata.json？
2. **Defaults 安装机制**：独立 `template:install` 命令（推荐）还是 `template:apply --with-defaults`？
3. **8 OS 产出方式**：8 个独立自包含 JSON 包、走同一渲染管线（推荐）；还是共享 base + 声明式 overlay？
4. **Admin「应用」语义**：activate + apply recipes，defaults 是否同动作（推荐：defaults 独立按钮，避免误覆盖）？
5. **Gate 切分**：18L-3b 单 Gate 交付，还是内部再拆（建议单 Gate、按任务顺序推进）？

---

## 5. Discovery 结论

- 18L-3a 地基稳固、边界正确。
- 18L-3b 是**产品化收口**：补 Metadata、深度校验、Defaults 安装、8 OS、Admin 入口。
- 全部可在**不新增第二套逻辑、不破坏 GEO/SEO/Composition** 前提下，通过扩展现有支撑层 + 声明式 JSON 完成。
- **Discovery 完成，STOP。** 待用户对第 4 节决策点裁定后，进入 Implementation。
