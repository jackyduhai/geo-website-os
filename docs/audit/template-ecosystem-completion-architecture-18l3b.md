# P-STEP 18L-3b — Template Ecosystem Completion：Architecture

- **配套文档**：`template-ecosystem-completion-discovery-18l3b.md`
- **状态**：架构推荐方案，等待用户裁定后进入 Implementation
- **总原则**：只扩展现有支撑层 + 声明式 JSON 补包；**不新增渲染 / SEO / URL 第二套逻辑**。

---

## 决策 D1：Template Metadata —— 放 manifest.json（推荐）

**裁定建议**：三类元数据直接写入 `manifest.json`，单一清单、Validator 一处校验、AI 读一个文件即可获得全部模板语义。

```json
"metadata": {
    "purpose": "industrial manufacturer website",
    "entities": ["organization", "product", "service"],
    "conversion": ["inquiry", "download"]
}
```

**受控词表（白名单，禁止自由文本）**：

| 键 | 允许值 |
|---|---|
| `entities[]` | organization / product / service / content / person / location / factory / case |
| `conversion[]` | inquiry / download / demo / appointment / contact / signup |
| `purpose` | 每个 Business OS 对应一条标准短语（下表），不允许自由填写 |

**purpose 标准短语（8 OS）**：

| OS | purpose |
|---|---|
| manufacturing | industrial manufacturer website |
| saas-technology | software and AI technology product website |
| brand-commerce | consumer brand and commerce website |
| professional-service | professional services firm website |
| healthcare | healthcare and life sciences website |
| education | education and training website |
| construction-realestate | construction and real estate website |
| international-export | export-oriented international company website |

`TemplateManifestValidator` 增加：metadata 存在、purpose 在标准短语、entities/conversion 值在白名单。

---

## 决策 D2：深度校验 —— 新增 `template:validate`（推荐）

**命令**：`template:validate {pack?} {--strict}`

- 无参 = 校验全部包；`--strict` = warning 也视为失败（CI 用）。
- 复用 `BlockType::$fields` 作为每个 block content 的字段 schema，**不另造 schema**。

**校验项与分级**：

| 层级 | 检查 |
|---|---|
| ERROR | manifest 无效；recipe 缺 key/target；block type 未注册；block 不允许进入该 target 槽位；required 字段缺失；grid block source 非 `{mode}` 对象（18L-3a 真实问题） |
| WARNING | locale map 缺某语言键；defaults 文件缺失；content 含未声明字段；preview 图缺失 |
| INFO | 统计 recipe/block 数量 |

**输出**：每包 `ERROR=n / WARNING=n`；存在 ERROR 时 exit code=1。
**接线**：`template:apply` 在应用前先跑该校验，ERROR 则拒绝应用（把 18L-3a 的运行时 500 提前到应用前）。

实现：新增 `TemplateRecipeValidator`（静态校验服务）+ `app/Console/Commands/TemplateValidate.php`；`TemplateApply` 调用它。

---

## 决策 D3：Defaults 安装 —— 独立显式 `template:install`（推荐）

**裁定建议**：`template:apply` 只做 recipe（页面骨架）；defaults 由**独立、显式、幂等**的 `template:install {pack}` 落地，避免应用骨架时误覆盖站点设置。

| defaults 文件 | 落地目标 | 不覆盖规则 |
|---|---|---|
| settings.json | `Setting` | 仅当键当前为出厂默认 / 空时写入 |
| menus.json | 主导航 / 页脚 Menu | 目标菜单已有项则跳过 |
| seo.json | site-level `SeoMeta` | 已存在 site-level 记录则跳过 |

**硬边界**：

- defaults 是「建议默认值」，全部写入正式模型（Setting / Menu / SeoMeta），由现有 Resolver 消费，**不产生第二事实源**。
- 安装结果返回 `applied / skipped` 计数，skipped 需说明原因。
- 安装是可重复的（幂等），重复执行不产生重复数据。

实现：新增 `TemplateDefaultsInstaller` + `app/Console/Commands/TemplateInstall.php`。

---

## 决策 D4：八大 Business OS —— 8 个独立自包含包、走同一管线（推荐）

**裁定建议**：8 个**物理独立、自包含、可单独分发/版本化/第三方替换**的包目录；「不复制 8 套」指**不复制渲染逻辑**——所有包复用 core 模板（home/listing/detail/contact/landing），统一走 Narrative → Recipe → Composition。

**包结构最小化**：

- PackageManager::register 不强制 template.json（缺失则只用 core 模板）。
- 多数 OS 包不需要自定义模板定义，只需：`manifest.json`（含 metadata）+ `theme.json` + `recipes/` + `defaults/` + `preview/` + `README.md`。

**差异化集中在三处（这是产品价值，不共享）**：

1. `recipes/homepage.json` 的区块组合与顺序（Business Narrative）。
2. block content 文案（语气 / 关键词，zh-CN + en locale map）。
3. manifest metadata（purpose / entities / conversion）与可选 theme 引用。

**范围控制（V1）**：

- manufacturing-pro 已含完整 4 recipe（homepage/products/contact/about），作为完整范本。
- 其余 7 包：**各自提供差异化 homepage recipe**；contact/products/about 复用 SystemPageSeeder 已初始化的系统页（包内不重复提供），`template:apply` 只应用包内实际存在的 recipe。
- 这样 8 OS 的工作量聚焦在 7 条 homepage 叙事，不复制其余页面。

**8 OS homepage 叙事（区块顺序，全部用已注册 block）**：

| OS | homepage 区块链 |
|---|---|
| Manufacturing | hero → stats → feature_grid → product_grid → logo_cloud → faq → cta |
| SaaS Technology | hero → feature_grid → product_grid → logo_cloud → stats → faq → cta（Demo 转化） |
| Brand Commerce | hero（品牌故事） → media_text → product_grid → testimonial → logo_cloud → cta（购买） |
| Professional Service | hero → feature_grid（服务体系） → media_text（方法论） → testimonial → faq → cta（咨询） |
| Healthcare | hero（可信背书） → feature_grid → service 区块 → testimonial → cta（预约） |
| Education | hero → feature_grid → content_grid → testimonial → stats → cta（报名） |
| Construction & Real Estate | hero → stats → project/content_grid → feature_grid → faq → cta |
| International Export | hero（English-first） → stats → feature_grid（certification/factory） → product_grid → faq → cta（inquiry） |

**新增 7 包目录**：`resources/templates/{saas-technology, brand-commerce, professional-service, healthcare, education, construction-realestate, international-export}/`。

---

## 决策 D5：Admin Template 管理（平行 Theme 管理）

**新增 `app/Http/Controllers/Admin/TemplateController.php` + `resources/views/admin/templates/index.blade.php`**。

| 动作 | 路由 | 说明 |
|---|---|---|
| 列表 | GET `admin/templates` | 卡片显示 preview、name/version、metadata 标签、active 态、invalid 告警 |
| 应用 | POST `admin/templates/{pack}/apply` | activate + apply 全部 recipe（**不含 defaults**） |
| 安装默认值 | POST `admin/templates/{pack}/defaults` | 显式调用 DefaultsInstaller（独立按钮，防误覆盖） |
| 预览 | GET `admin/templates/{pack}/preview` | 请求级 SubRequest 预览，带 noindex |
| 回滚 | POST `admin/templates/deactivate` | deactivate，恢复 core 模板 |

- 写操作经 `AuditLog::record('template.*', ...)`。
- 超管可看 cross-site matrix（各站激活包），普通 admin 仅本站（同 ThemeController）。
- 侧边栏在「外观 / 主题」附近新增「模板」入口。

---

## 实施任务清单（与 task #349–354 对齐）

| 任务 | 内容 | 对应 TD |
|---|---|---|
| 18L3b-1 | manifest metadata + Validator 受控词表，manufacturing-pro 补齐 | 新 TD（metadata） |
| 18L3b-2 | TemplateRecipeValidator + template:validate；apply 前置校验 | 新 TD（validate） |
| 18L3b-3 | TemplateDefaultsInstaller + template:install | 新 TD（defaults） |
| 18L3b-4 | 7 个 OS 包（差异化 homepage + metadata + defaults + preview） | 新 TD（8 OS） |
| 18L3b-5 | Admin TemplateController + 视图 + 路由 + 导航 | 新 TD（admin） |
| 18L3b-6 | TemplateEcosystem18L3bTest + Gate 全验证 + tag checkpoint-18L-3b | — |

**新债务登记**：上述能力缺口在 Implementation 开始时统一登记 TD-122+（从台账当前最大编号续编，不覆盖旧项）。

---

## Gate（单 Gate）

**18L-3b PASS 条件**：

- [ ] template:validate 对 8 包全部 0 ERROR
- [ ] metadata 8 包齐全且通过受控词表校验
- [ ] template:install defaults 在 blank 站正确落地、不覆盖已有
- [ ] 8 OS 包均可 apply，homepage narrative 真实差异化、前台 200
- [ ] Admin Template 管理：查看 / 应用 / 预览 / 默认值 / 回滚闭环
- [ ] Fresh install、Browser（zh/en × light/dark）、Multi-Site、cache、locale 隔离通过
- [ ] GEO / SEO / Schema / Sitemap 未被破坏（迁移前后对拍）
- [ ] Runtime 污染 = 0、新增 ERROR = 0
- [ ] Full Regression 0 failed / 0 skipped，worktree clean，tag checkpoint-18L-3b 对齐

**完成后 STOP，不自动进入 18L-4 / 19A。**
