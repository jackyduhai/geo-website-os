# P-STEP 18L-4 — GEO Template Experience Layer · Discovery

> 阶段定位：**Discovery（只读）**。本文档不修改任何代码 / 数据库 / 模板 / 测试，
> 仅给出当前能力分析、Gap Analysis、架构提案、TD 台账、v1.0/v1.1 边界与风险。
>
> 角色：GEO Website OS 产品架构师 + Design System 架构师 + CMS 产品经理 + 安全架构师。
>
> 审计基线：HEAD `010f1ed`，annotated tag `checkpoint-18L-3b`；
> 全量回归 1061 tests / 5715 assertions / 0 failed / 0 skipped；worktree clean。
>
> 关联文档：
> - Template Safety Model：`template-safety-model-18l4.md`
> - Template SDK Specification：`template-sdk-specification-18l4.md`
> - GEO Section Semantic Standard：`geo-section-semantic-standard-18l4.md`
> - Section Composer Architecture：`section-composer-architecture-18l4.md`
> - Industry Template Standard：`industry-template-standard-18l4.md`

---

## 0. 执行摘要（TL;DR）

18D–18L-3b 已经把系统从「固定 Blade 官网」推进到「**Theme + Component + Template Package + Page Composition**」
四层齐备、且前台公开契约（URL / SEO / GEO / Schema / Sitemap / Feed / Search / Cache）统一的产品。

但要成为「面向 GEO 时代、可由第三方与 AI 共同扩展的 **Website OS 模板平台**」，仍缺 **5 块治理能力**：

| # | 缺口 | 现状 | 目标 |
|---|---|---|---|
| 1 | **Template Safety Scan & Compatibility** | 校验只查「结构 / 依赖存在」，不查危险内容；manifest 无平台 API 版本约束 | 注册前安全扫描（URL scheme 白名单等）+ `requires.*` 版本契约 |
| 2 | **Template Migration** | 无 `template:migrate`；包升级无 deprecated / renamed 处理 | 声明式迁移：deprecated block / renamed field / token·component 变化 |
| 3 | **GEO Section Semantic Metadata** | block 根仅有 `aria-*`（a11y），**无** `data-section/purpose/entity/conversion` | 受控 Section 词表 + 统一渲染输出（扩展 TD-121） |
| 4 | **Section Composer Closure** | 9 操作 CRUD 已闭环、按钮式排序；缺 variant 快切 / Section 语义可见 | 槽位式 Section Composer（非自由拖拽，重定义 TD-119） |
| 5 | **Template SDK Specification** | 规范散落各 audit 文档，无单一第三方入口 | 单一 SDK：Manifest / Section / Component / Token / Metadata / Compatibility / Migration |

**核心裁定建议**：18L-4 属于「**治理与契约补齐**」，不是新功能堆叠；
所有扩展必须落在现有 Theme / Component / Block / TemplatePackage 体系上，
**不新增第二套渲染、SEO、URL、缓存管线**，且不破坏既有公开契约。

---

## 1. 当前 Template Platform 能力盘点

### 1.1 Theme Layer（`app/Support/Theme/`）

| 能力 | 现状 | 评价 |
|---|---|---|
| Color Token | `ThemePalette::resolve()` 派生品牌簇 / 辅色簇 / Action / CTA / 中性阶 / 语义色（约 60 个颜色变量） | 完整 |
| Typography Token | 12 档字号（display→button），随 `theme_typography` profile（standard/compact/editorial）；含行高 / 字重 / 字距 | 完整 |
| Spacing Token | `--sp-N`（N×4px，4–96px）；位移 token `--lift-*` / `--nudge` / `--rise` | 完整 |
| Radius / Shadow | `--radius-*`（xs→full）、`--shadow-*`（sm/hover/overlay，默认 `--shadow:none`） | 完整 |
| Motion | `--motion-fast/base/slow`（cubic-bezier） | 完整 |
| Dark Mode | `ThemePalette::darkOverrides()` 深色 elevated 体系 + AA 提亮；前台 light/dark/system + SSR 防闪 | 完整 |
| 主题机制 | `ThemeManager`：`resources/themes/{name}/theme.json` + `views/`，激活主题 views **prepend 覆盖**同名 Blade、未提供回退；`activeTokens()` 经 13 键白名单过滤 | **见 1.1.1** |

**Token 化结论**：前台主样式内联于 `layouts/site.blade.php` 的 `<style>`，`:root` 只消费
`ThemePalette` 派生令牌；grep 未发现孤立品牌 Hex / 内联 px 字号 / 内联 spacing（18L-1/2 已治理）。
第三方主题 / 模板在视觉层只能消费 token，无 CSS 穿透到核心组件的路径。

#### 1.1.1 关键边界：Theme 与 Template Pack 的信任级别不同（必须显式记录）

- **Theme（`resources/themes/`）**：历史上（P-STEP 05）被设计为「**可视图覆盖**」的一等扩展——
  激活主题的 Blade 目录 prepend 到视图查找器最前，可同名覆盖 `layouts/site`、`site/home` 等。
  这是**高信任**扩展点（当前出厂仅 `default`，无第三方主题）。
- **Template Package（`resources/templates/`）**：V1 **物理封闭、声明式**——
  包内无 PHP / Blade / Controller 入口，`TemplatePackageManager` / `RecipeApplier` 只读写声明数据，
  不 include / require / eval。
- **安全含义**：18L-4 的 Trust Boundary 必须明确区分二者，
  不能把 Theme 的「可覆盖视图」能力误开放给 Template Pack，否则封闭性失效。
  详见 `template-safety-model-18l4.md`。

### 1.2 Component Layer（`app/Support/Components/`）

| 能力 | 现状 |
|---|---|
| 契约 | `config/components.php` 单一事实源；`ComponentRegistry` 从配置加载 |
| 模型 | `ComponentDefinition`（key/label/roots/meta/defaultVariant/variants/sizes，不可变） |
| 变体 | `ComponentVariant`（key/label/classes/tokens，不可变）——只做「token + 已注册 CSS 类」声明映射 |
| 解析 | `ComponentResolver::classes()` 解析变体类名；Blade 不写 `if variant==` 分支 |
| 语义硬约束 | `semantic.roots` 声明允许的根标签（如 button/a、article/div、section/header），禁止退化为纯 `<div>` |
| 已注册组件 | Button 5（primary/secondary/outline/ghost/text）、Card 5（default/border/shadow/glass/minimal）、Hero 5（default/split/center/image/product）、Section 3（default/wide/compact） |

**结论**：组件层是纯声明式契约，不重新生成 CSS、不内嵌业务事实；
「组件描述我支持什么 / 主题描述我长什么样 / Block 描述我在哪里使用」的分层成立。

> **契约漂移（低优先级，纳入 18L-4 顺手修正）**：
> `config/components.php` 中 Card `glass` 与 Section `compact` 变体声明消费
> `--glass-bg/--glass-line/--glass-blur` 与 `--sec-y-compact`，但这些 token **未在 `ThemePalette` 输出**。
> 实际 `.card-glass` / `.sec-compact` 样式存在（直接用 `--surface/--line/--sp-12`），**视觉不失效**，
> 但声明的 token 绑定悬空，属于「声明 ↔ 实现漂移」。建议在 TD-130/132 落地时对齐声明，不单独开 TD。

### 1.3 Template Package Layer（`app/Support/Templates/`）

| 能力 | 现状 |
|---|---|
| 物理结构 | `resources/templates/{id}/`：`manifest.json` + `theme.json` + `recipes/` + `defaults/` + `preview/` + `README.md`；`manufacturing-pro` 额外含 `template.json` |
| 生命周期 | `TemplatePackageManager`：all/exists/active/activate/deactivate/register/recipes；激活态 Site-scoped（Setting `template_active_pack`） |
| Manifest 校验 | `TemplateManifestValidator::inspect()`：id/name/version(SemVer)/industry/locales/theme/requires.components/pages + identity/purpose/entities/conversion/seo，受控词表，ERROR/WARNING 分级 |
| Recipe / Runtime 校验 | `TemplateRecipeValidator::inspect()`：Layer2 recipe（结构 / block 注册 / 必填 / grid source）+ Layer3 runtime（slot 准入 / defaults / preview） |
| Recipe 落地 | `RecipeApplier::apply()`：Site-scoped、槽位级幂等（marker `pack.recipe`），locale map 解析、内部 URL 前缀化，不覆盖管理员手动块、不复制业务事实 |
| Defaults 落地 | `TemplateDefaultsInstaller::bootstrap()`：settings/menus/site-SEO，空值才写、`--force` 覆盖，幂等 |
| 模板注册 | `TemplateRegistry`：双来源（核心 `config/templates.php` + 激活包 `template.json`），继承槽位合并；**包模板不可覆盖同名核心模板** |
| 命令 | `template:validate` / `template:activate` / `template:bootstrap` / `template:deactivate` |
| Admin | `TemplateController` + 视图：Installed 列表 / 截图 Preview / Compare / Activate / Bootstrap / Deactivate |
| 已发布包 | 8 个 `-pro`：manufacturing / saas / commerce / service / healthcare / education / construction / export |

**核心模板（`config/templates.php`）**：base / home / landing / listing / detail / contact，
槽位 blocks 白名单一次定义完整；layout 统一 `layouts.site`。

#### 1.3.1 已识别的小问题（不阻塞，纳入清理）

- `TemplateRecipeValidator::GRID_MODES` 常量在改为「从 block fields 动态读取 sourceModes」后**已无引用**（死常量）。
- 8 包 `preview/{desktop,mobile}.webp` 当前为 GD 占位图；真实模板发布前应替换为真实截图（见 §6 风险）。
- 8 包 recipe 当前主要覆盖 `homepage`；系统页 / 详情 / 列表的 recipe 覆盖不完整（见 §6、行业标准文档）。

### 1.4 全链路数据流（当前真实形态）

```
Public Request (Site × Locale)
        │
        ▼
Render Context  ── PageContext / EntityContext / ListingContext / SystemPageContext
        │           （渲染、SEO、GEO、Schema、URL、Cache 共用同一公共管线）
        ▼
Template Resolver（TemplateRegistry：核心 + 激活包，槽位 + block 白名单）
        ▼
Block Registry / Renderer（BlockType：结构化 JSON + 注册视图，grid 经 Catalog 投影）
        │
        ├──▶ Content / Entity / Relation / Media（权威事实源）
        ├──▶ SEO（SeoMetaResolver 单一来源）
        ├──▶ Schema（JSON-LD，inLanguage 随 locale）
        ├──▶ GEO（geo.json / llms.txt / sitemap / robots / feed）
        └──▶ PageCache（Site × Locale × 模板，页面级失效）
```

**结论**：渲染主链已统一，无「Product / Service / Listing 各自拼 SEO」的双轨（TD-61 已关闭）。

---

## 2. Gap Analysis（已有 / 缺失 / 冲突 / 写死）

### 2.1 缺失能力（Missing）— 本阶段重点

| Gap | 描述 | 归属 TD |
|---|---|---|
| **M1 Security Scan** | Manifest / recipe 注册前无「危险内容扫描」；最典型：block 按钮 `url` 直接输出到 `href`，**无 URL scheme 白名单**——`javascript:` / `data:` 可落地（Stored XSS 面，第三方 / AI 模板为不可信输入时风险显著） | TD-130 |
| **M2 Compatibility 版本约束** | manifest 只有 `requires.components`（block 列表），无 `requires.platform/theme_api/component_api/geo_contract/seo_contract` 版本；旧模板可在新平台静默运行 | TD-130 |
| **M3 Migration** | 无 `template:migrate`；包 1.0→1.1→2.0 升级时 deprecated block / renamed field / token·component 变化无处理 | TD-131 |
| **M4 GEO Section Metadata** | block 根无 `data-section/purpose/entity/conversion`（现有仅 aria/role，服务 a11y 而非 AI 解析）；AI 无法直接读出区块实体 / 用途 / 转化 | TD-132（扩展 TD-121） |
| **M5 Composer 收口** | composer 缺 variant 快速切换、Section 语义在后台的可见 / 校验；TD-119 的「自由拖拽」与官网定位不符，需重定义 | TD-133（重定义 TD-119） |
| **M6 SDK 单一入口** | 第三方模板开发规范散落各 audit 文档，无单一、版本化、可校验的 SDK 规范 | TD-134 |

### 2.2 安全缺口实证：block 按钮 URL 无 scheme 白名单（M1）

- 渲染点（`resources/views/site/blocks/`）：
  - `cta.blade.php:28`、`hero.blade.php:30`、`media_text.blade.php:27` 均为 `<a href="{{ $bUrl }}">`，**无协议净化**。
- 落地链：`RecipeApplier::localizeUrl()` 对带 scheme 的 URL（正则 `^[a-z][a-z0-9+.-]*:`）**原样返回**，
  故 `javascript:alert(1)` 会被保留；Blade `{{ }}` 做 HTML entity 编码，但不阻止 `javascript:` 协议执行。
- **现有可复用范式**：`HeadCodeSanitizer::isDangerousValue()`（18H-3）已实现
  「拒绝 javascript/vbscript/data/file，允许 http(s)/`//`/`/`/`#`/相对」。
  建议**抽取为通用 `SafeUrl`（或 `UrlSchemeGuard`）**，Head 与 Block 共用——不造第二套。
- 信任边界：当前需管理员权限配置，但（a）第三方 / AI 模板包是不可信输入；（b）防御纵深。
  风险定级：**P1（生态开放前必须关闭）**。

### 2.3 冲突 / 易混淆能力（Conflict）

| Conflict | 描述 | 裁定建议 |
|---|---|---|
| Theme 视图覆盖 vs Pack 封闭 | Theme 可覆盖 Blade（高信任）；Pack 不得携带 Blade（封闭） | Safety Model 显式分级，禁止把 Theme 能力下放给 Pack |
| TD-121 vs TD-132 | TD-121 已登记 data-section/entity/purpose（无 data-conversion、无 Section 类型词表） | TD-132 **扩展并吸收** TD-121，最终一起关闭，不重复记账 |
| TD-119（拖拽）vs Section Composer | 用户明确「不做 Elementor / 传统自由拖拽」 | TD-119 **重定义并入** TD-133；按钮 + 键盘可达排序为 V1 形态 |
| 变体 token 声明悬空 | glass/sec-compact 声明消费未定义 token（§1.2） | 对齐声明（改 token 引用或补 token），不改变有效视觉 |

### 2.4 写死 / 硬编码复扫（Hardcoding）

- 视觉层：grep 未发现孤立 Hex / px 字号 / spacing（18L-1/2 已清零，仅 sr-only `-1px` 豁免）。
- 业务 / 品牌：Runtime Core 业务污染扫描词（Demo Tenant A / Sample City / Sample Snack / Sample Marinade等）在 app/views/config/seeders/public 为 **0**。
- 行业假设：默认 Contact Form 已中性化（name/phone/email/message），无 OEM / 制造业字段；Demo 已去行业化。
- 结论：**无新增写死缺口**；写死治理在 18L-4 重点是「防止模板包把行业业务事实当默认值带入」
  （defaults 只允许中性 / 通用结构，行业事实由管理员或行业 recipe 显式生成）。

---

## 3. 架构提案（Architecture Proposal）

### 3.1 目标分层（在现有体系上扩展，不重排）

```
                Brand（品牌）
                   │  Brand Seed
                   ▼
        Design Tokens（ThemePalette：color/typo/spacing/radius/motion/dark/inverse）
                   │
                   ▼
        Component System（ComponentRegistry：variant / roots / meta）
                   │
                   ▼
        Template System（TemplateRegistry：slot / block 白名单 / 继承）
                   │
        ┌──────────┴──────────┐
        ▼                      ▼
  Template Package        Page Composition
  （声明式、封闭、          （Page × Block 实例，
    可校验、可迁移）          Section Composer）
        │                      │
        └──────────┬───────────┘
                   ▼
        Content / Entity / Relation / Media（权威事实）
                   │
        ┌──────────┼──────────┬───────────┐
        ▼          ▼          ▼           ▼
       SEO       Schema      GEO       PageCache
   （Resolver 单一来源 / inLanguage / geo·llms·sitemap / Site×Locale 页面级失效）
```

### 3.2 五个治理子系统（落地形态）

1. **Safety Scan & Compatibility（TD-130）**
   - 抽取 `HeadCodeSanitizer::isDangerousValue` → 通用 `SafeUrl` / `UrlSchemeGuard`；
     block 按钮 / 链接 url 在「保存 + 渲染」两侧走 scheme 白名单。
   - Validation Pipeline 在现有三层前/中插入 **Security Scan**（URL scheme、危险字符串、字段长度）。
   - manifest 增加 `requires.platform/theme_api/component_api/geo_contract/seo_contract`，
     校验器按平台当前版本做 SemVer 兼容判定（不满足 → ERROR；主版本不匹配 → 阻断）。

2. **Migration（TD-131）**
   - 包内可选 `migrations/{from}-{to}.json`（或 manifest 中 `migrations`）声明：
     `deprecate block` / `rename field` / `rename token` / `replace component`。
   - `template:migrate {pack}`：在 Site 作用域、槽位级幂等执行，迁移前后对拍，触发页面级失效。
   - V1 只支持声明式 rename / remove / replace-map，不执行任意逻辑。

3. **GEO Section Semantic Metadata（TD-132，吸收 TD-121）**
   - `BlockType` 增加 `semantic`（section）声明：`section`（类型词表）/ `purpose` / `entity` / `conversion`。
   - 渲染层（`BlockRegistry::render` 或统一根包装）把声明输出为
     `data-section` / `data-purpose` / `data-entity` / `data-conversion`；
     **受控词表、非自由文本**，与 JSON-LD 互补，不改可见结构 / H1 / Schema。
   - Section 类型、purpose / entity / conversion 词表见 `geo-section-semantic-standard-18l4.md`。

4. **Section Composer（TD-133，重定义 TD-119）**
   - 保持「槽位内列表式」组合（非自由拖拽 / 非 Elementor）。
   - 现有 9 操作（add/edit/move up·down/toggle/duplicate/delete/preview/publish）保留；
     增补：variant 快速切换、Section 语义只读展示 + 校验提示、键盘可达（方向键排序，作为无障碍增强）。
   - 约束：改布局不改 H1 / Schema / Entity / SEO；变更触发该 Page 页面级缓存失效。
   - 模型见 `section-composer-architecture-18l4.md`。

5. **Template SDK（TD-134）**
   - 单一规范：Manifest（含 identity/purpose/entities/conversion/seo/requires）、
     Section 语义、Component 调用、Token 调用、Metadata、Compatibility、Migration。
   - 提供 `template:validate --strict` 作为 SDK 自检；第三方包文件分发、V1 不做在线上传 / Marketplace。
   - 见 `template-sdk-specification-18l4.md`。

### 3.3 Cache 依赖矩阵（随 18L-4 复核，不扩大）

| 变更 | 应失效范围 | 现状 |
|---|---|---|
| Block 内容 / 排序 / 显隐 | 该 Page（Site × Locale） | 已接线（页面级） |
| Template（槽位 / 白名单） | 使用该模板的全部 Page | activate 已 `PageCache::flush`；精确 tag 失效可在 18L-4 增强 |
| Page（状态 / slug / SEO） | 该 Page | 已接线 |
| Entity / Content | 关联详情 / 列表 / 含其 grid 的 Page | 已接线（增量） |
| Theme / Theme token | 该 Site 全部 Page | ThemeManager::activate 已 flush |
| Pack activate / deactivate / migrate | 该 Site 相关 Page | 已 flush；migrate 需新增页面级失效（TD-131） |

**原则**：不退回全站 flush 作为唯一手段，也不允许旧页面继续缓存；新增 migrate 路径必须带页面级失效。

---

## 4. TD 登记（TD-130 ~ TD-134）

> 编号以台账现状（已到 TD-129）续接；状态在 Discovery 阶段登记为 ACTIVE，
> 优先级为建议值，最终由 Gate 裁定。

| TD | 标题 | 优先级 | 关联 / 吸收 |
|---|---|---|---|
| **TD-130** | Template Safety Scan & Compatibility：注册前危险内容扫描（URL scheme 白名单，抽取通用 SafeUrl）+ `requires.platform/theme_api/component_api/geo_contract/seo_contract` 版本约束 | **P1** | 复用 HeadCodeSanitizer；TD-125 |
| **TD-131** | Template Migration：`template:migrate`，声明式处理 deprecated block / renamed field / token·component 变化，带页面级缓存失效 | P2 | TD-126 |
| **TD-132** | GEO Section Semantic Metadata：block 根统一输出 data-section/purpose/entity/conversion（受控词表），BlockType 增 semantic 声明 | **P1** | **吸收 TD-121**（一并关闭） |
| **TD-133** | Section Composer Closure：variant 快切、Section 语义可见 / 校验、键盘可达排序；槽位式而非自由拖拽 | P2 | **重定义 / 吸收 TD-119** |
| **TD-134** | Template SDK Specification：单一第三方开发规范（Manifest/Section/Component/Token/Metadata/Compatibility/Migration）+ validate 自检 | **P1** | TD-124~130 |

**不单独开 TD 的清理项**（写入对应 TD 的验收）：
- glass / sec-compact 变体 token 声明悬空 → TD-130/132 落地时对齐。
- `TemplateRecipeValidator::GRID_MODES` 死常量 → 清理。
- 8 包 preview GD 占位 → 真实截图（§6，发布前）。

---

## 5. v1.0 / v1.1 边界（建议冻结）

### 5.1 v1.0（18L-4 收口，发布前必须）

- **TD-130 Safety & Compatibility**：生态开放前的安全底线 + 版本契约。
- **TD-132 GEO Section Metadata**：AI 可解析的区块语义，是「GEO Website OS」定位的核心，不放 v1.1。
- **TD-134 Template SDK**：第三方 / AI 扩展的单一契约入口。
- TD-131 / TD-133：建议在 v1.0 完成最小闭环（声明式迁移 + Composer 收口），
  以避免 v1.0 发布后立即面临一次模板契约二次迁移。

### 5.2 v1.1（明确 Deferred，不阻塞发布）

- TD-14 RBAC + Site Membership（沿用既有裁定）。
- TD-76 Logo srcset（沿用）。
- TD-79 Page / Landing 统一搜索（沿用）。
- Template Marketplace / 在线 zip 上传 / 第三方代码执行 / 在线模板安装。
- 高级推荐 / 语义向量搜索、条件分支 / 多步表单（沿用）。
- 自由拖拽 / Figma 式画布（明确不做；键盘 / 轻量增强可入 v1.1）。

### 5.3 Release Engineering（HOLD，18L-4 全 PASS 后才解锁）

- TD-01 Cloud CI 首跑、TD-02 Final RC 重建、TD-03 Private→Public / v1.0.0。
- 19A / 19B / 19C 与 remote / push / rc1（965d63c）/ Release 全部继续 HOLD。

---

## 6. 风险分析（Risk）

| 风险 | 等级 | 缓解 |
|---|---|---|
| 生态开放后不可信模板注入 `javascript:` URL | 高 | TD-130 在「保存 + 渲染」双侧 scheme 白名单 + 安全扫描 + 防回归测试 |
| 平台演进后旧模板不兼容、静默出错 | 中高 | TD-130 `requires.*` 版本契约，主版本不匹配阻断 |
| Section 语义标注演变成自由文本 / 噪声 | 中 | 受控词表 + Validator；只从 registry 声明输出，禁止手写散落 |
| 为补能力而新造第二套渲染 / SEO / URL / 缓存 | 高（流程） | 强制复用现有管线与 SafeUrl；每个 Gate 做公开契约对拍 |
| 模板视觉升级破坏 GEO / Schema / H1 / Sitemap | 高 | 硬边界：只改视觉与结构声明；迁移前后逐项对拍（HTTP/canonical/Schema/GEO/links） |
| 8 包占位截图被当成真实预览交付 | 中 | 发布前替换真实截图；占位图本身带可见 placeholder 标记 |
| 迁移（migrate）误删管理员手动块 | 中 | 仅管理带 marker 的配方块、槽位级幂等 + 对拍 + 可回滚（deactivate 保留数据） |
| 回归测试面悄然缩小 | 低（沿用 18J 教训） | Test Inventory Delta 对账，新增能力同步新增防回归 |

---

## 7. Gate 拆分建议

建议 18L-4 拆为 **2 个 Gate**（与 18G/18L-3 同纪律，降低一次性重构面）：

- **18L-4a — Safety + GEO Section + SDK 契约**
  TD-130（安全扫描 + 版本约束）、TD-132（Section 语义，吸收 TD-121）、TD-134（SDK 规范）。
  验收：URL scheme 白名单双向测试、版本不匹配阻断、Section data-* 全 block 输出且 Schema/H1/公开契约不变、
  `template:validate --strict` 全绿、Fresh / 浏览器 zh-en×light-dark、全量回归。
- **18L-4b — Migration + Composer Closure**
  TD-131（template:migrate）、TD-133（Composer 收口，吸收 TD-119）。
  验收：声明式迁移对拍、variant 快切、键盘可达排序、页面级缓存失效、多站隔离、全量回归。

> 若裁定单 Gate 收口，则内部按上述顺序执行、一次性提交证据。

---

## 8. 结论与 STOP

- 当前系统在 **Theme / Component / Template Package / Page Composition** 四层已产品化，
  公开渲染契约统一；18L-4 的工作是**治理与契约补齐**，而非新功能 / 新管线。
- 5 个缺口已映射为 **TD-130 ~ TD-134**（含吸收 TD-121 / TD-119），
  并给出 v1.0 / v1.1 边界、风险与 Gate 拆分建议。
- **Discovery 在此 STOP**：不编码、不改 DB / 模板 / 测试，不进入 19A，
  remote / push / rc1 / Release 全部 HOLD。
- 等待用户对「架构方向、TD 优先级、Gate 拆分、v1.0/v1.1 边界」做正式裁定后，再授权实施。
