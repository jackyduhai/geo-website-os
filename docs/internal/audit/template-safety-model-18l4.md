# P-STEP 18L-4 — Template Safety Model（Trust Boundary + Validation Pipeline）

> 专题：定义 GEO Website OS 模板生态的**信任边界、不可信输入、校验管线与失败隔离**。
> 本文为 Discovery（只读）产物，不修改代码。
> 主文档：`geo-template-experience-discovery-18l4.md`；对应 TD：**TD-130**（兼 TD-131/134）。

---

## 1. 为什么需要显式 Safety Model

18L-3 已让模板「可分发、可应用」，18L-4 要让它「**可被第三方与 AI 安全扩展**」。
一旦模板来源从「出厂内置」扩展到「第三方包 / AI 生成包」，manifest / recipe / defaults
就从「受控内部文件」变成「**不可信输入**」。若仍只校验「结构合法、依赖存在」，
危险内容（最典型为 `javascript:` URL）会在应用时落地为可执行 HTML。

Safety Model 的目标：**把所有不可信输入在「激活 / 落地」前收敛为可判定的 PASS / WARNING / BLOCK，
且模板失败绝不拖垮主站。**

---

## 2. Trust Boundary（信任分级）

### 2.1 三类扩展的信任级别（必须显式区分）

| 扩展类型 | 物理位置 | 能力边界 | 信任级别 | 校验要求 |
|---|---|---|---|---|
| **Theme** | `resources/themes/{name}/` | `theme.json` tokens（13 键白名单）+ `views/` 可**同名覆盖** Blade | **高信任**（视图级） | tokens 白名单过滤；当前出厂仅 `default` |
| **Template Package** | `resources/templates/{id}/` | **封闭、声明式**：manifest / theme 引用 / recipe / defaults / preview；**无 PHP / Blade / Controller / JS** | **中信任（不可执行）** | 完整 Validation Pipeline（§4） |
| **Plugin** | `app/Support/Plugins/` | 独立机制（路由 / 启停 / 隔离），不属本模型 | 独立演进 | 走 PluginManager 自身边界 |

### 2.2 Template Package 允许 / 禁止清单（V1 封闭冻结）

**允许（声明数据）**
- `manifest.json`（identity / purpose / entities / conversion / seo / requires）
- `theme.json`（引用已注册主题 token profile，不自带样式管线）
- `recipes/*.json`（页面 ↔ 槽位 ↔ block 组合声明）
- `defaults/{settings,menus,seo}.json`（**中性**建议默认值）
- `preview/{desktop,mobile}.webp`（静态截图）

**禁止（V1 一律 BLOCK）**
- 任何 `.php` / 可执行入口；`.blade.php` / 自定义视图
- 新 Controller / Route / Middleware 注册；SQL 执行 / 迁移脚本写入
- 任意 JS / `<script>` / 事件处理器 / `javascript:`·`data:` URL
- 在 DB 存任意 HTML / Blade（沿用安全边界 ⑫：block 只存结构化 JSON + 注册渲染器）
- 携带行业业务事实作为强制默认（defaults 必须中性；行业内容由 recipe 显式生成）

### 2.3 不可信输入清单（Security Scan 作用对象）

1. `manifest.json`：所有字符串字段（name / audience / theme 引用 / requires）。
2. `recipes/*.json`：block 的全部配置值，**重点是按钮 / 链接 `url`、富文本、自由文本字段**。
3. `defaults/*.json`：settings / menus（label/url）/ seo（title/description/canonical）。
4. 任何 AI 生成 / 第三方导入的包内容（V1 为文件分发，不开放在线上传）。

> 管理员在后台手工录入的 block 数据同样走同一套校验（统一管线，不区分来源），
> 这样第三方 / 手工 / AI 三路径安全策略一致。

---

## 3. 安全原语：URL Scheme Guard（复用，不造第二套）

### 3.1 现状缺口

block 按钮 / 链接在 `cta.blade.php`、`hero.blade.php`、`media_text.blade.php` 直接输出
`<a href="{{ $url }}">`；`RecipeApplier::localizeUrl()` 对带 scheme 的 URL 原样返回。
Blade 做 HTML entity 编码，但**不阻止 `javascript:` 协议** → Stored XSS 面。

### 3.2 方案：抽取通用 `SafeUrl` / `UrlSchemeGuard`

- 现有 `HeadCodeSanitizer::isDangerousValue()`（18H-3）已实现正确范式，应**抽取为共享原语**：
  - **拒绝**：`javascript:` / `vbscript:` / `data:` / `file:`（大小写 / 空白 / 嵌套绕过归一化后判定）。
  - **允许**：`http(s)://`、协议相对 `//`、绝对路径 `/`、页内 `#`、无 scheme 相对路径。
  - 其余带 scheme（`xxx:`）默认拒绝（fail-closed）。
- **两个执行点（纵深防御）**：
  1. 保存 / 应用时（后台 storeBlock、RecipeApplier 落地）：非法 scheme → 校验错误 / 丢弃该值并记录。
  2. 渲染时（统一 SafeUrl 输出 href）：兜底净化，保证即使历史脏数据也不输出可执行协议。
- tel / mailto 按场景区分（contact_info 已用固定 `tel:` / `mailto:` 前缀拼接，用户值只取数字 / 邮箱）。

### 3.3 富文本 / 自由文本

- 富文本统一存 **Markdown**，渲染时由注册转换器生成安全 HTML（转义 + 白名单标签），
  不存原始 HTML；不允许内联事件属性 / 危险 URL（与 SafeUrl 同一套判定）。

---

## 4. Validation Pipeline（注册 → 激活 全链路）

### 4.1 目标管线（在现有三层校验上插入 Security Scan）

```
[1] Register / Discover（文件落位 resources/templates/{id}）
        │  template:list 可见；读取目录，不执行任何文件
        ▼
[2] Layer 1 — Manifest Schema Validation（TemplateManifestValidator）
        │  必填 / SemVer / 受控词表（industry/purpose/entities/conversion/schema）
        ▼
[3] Security Scan（新增，TD-130）
        │  URL scheme guard（manifest/recipe/defaults 全量）、危险字符串、字段长度、
        │  是否夹带禁止文件（*.php / *.blade / *.js）
        ▼
[4] Layer 2 — Recipe Validation（TemplateRecipeValidator）
        │  recipe 结构 / block 已注册 / slot 准入 / 必填字段 / grid source 对象 / locale map
        ▼
[5] Layer 3 — Runtime Compatibility（TemplateRecipeValidator）
        │  requires.* 版本兼容（平台 / theme_api / component_api / geo_contract / seo_contract）、
        │  defaults JSON 可解析、preview 存在
        ▼
[6] Preview（只读，不落地）
        │  V1 = 包自带静态截图（desktop/mobile.webp，受控读取、X-Robots noindex）；
        │  Theme 仍可用实时 preview；Pack 不做在线子请求 sandbox
        ▼
[7] Activate（template:activate）
        │  仅当无 BLOCK：注册包模板（不可覆盖核心）→ apply recipes（槽位级幂等）
        ▼
[8] Bootstrap（template:bootstrap，显式）
        │  中性 defaults 落地（空值才写）；与 activate 分离，避免误覆盖
        ▼
[9] Rollback（template:deactivate）
           停用注册、恢复核心模板；保留已落地数据，由管理员显式清理
```

### 4.2 判定分级

| 级别 | 含义 | 典型项 | 结果 |
|---|---|---|---|
| **BLOCK / ERROR** | 安全或硬契约不满足，**禁止激活** | 危险 URL scheme、夹带 PHP/Blade/JS、block 不存在、required 缺失、slot 不支持、invalid locale、**主版本不匹配 / unsupported token** | 阻断，exit 非 0 |
| **WARNING** | 不影响安全与运行，**放行** | 缺 preview 截图、缺行业描述、SEO metadata 不完整、缺 audience | 列出，可继续；`--strict`（CI）下视为失败 |
| **PASS** | 全部满足 | — | 允许 activate |

**原则（沿用 18L-3b 裁定）**：不过度严格，给第三方生态留空间；
但**安全项（scheme / 可执行内容 / 版本主契约）始终 fail-closed，不接受 WARNING 放行**。

### 4.3 Compatibility 版本契约（TD-130）

manifest 增加：

```json
"requires": {
  "platform": "1.0",
  "theme_api": "1.0",
  "component_api": "1.0",
  "geo_contract": "1.0",
  "seo_contract": "1.0",
  "components": ["hero", "card", "form"]
}
```

- 平台暴露当前各 API 版本（单一常量来源）；校验按 **SemVer 兼容**：
  同一 major 内、`>=` 要求版本 → 通过；major 不匹配或低于最低要求 → BLOCK。
- `requires.components` 保留（block 依赖存在性），与 API 版本互补。

---

## 5. Failure Isolation（失败隔离）

- **模板校验失败**：不写任何 Setting / Page / Block，主站与后台照常（已在 activate 前阻断）。
- **Provider / 第三方端点失败**（沿用 18H-3 结论）：Analytics 等旁路能力失败不影响前台 200 / 表单 / CTA。
- **recipe 部分失败**：槽位级幂等 + 事务边界；失败页不产生半落地状态，可重新 activate 对齐。
- **migrate 失败**（TD-131）：仅管理带 marker 的配方块，迁移可对拍 / 回滚；不触碰管理员手动块。
- **日志**：安全拦截应产生可解释的 warning / 拒绝记录（不记录敏感正文），纳入验证窗口审计。

---

## 6. 验收要点（供 Gate 使用）

**正向**
- 合法包：validate PASS → activate → recipes 落地 → bootstrap，前台 200、公开契约不变。

**反向（必须逐项真实验证）**
1. recipe 按钮含 `javascript:` / `data:` / `vbscript:` → 被拦截，前台 HTML 无可执行协议。
2. 包内夹带 `.php` / `.blade.php` / `.js` → Security Scan BLOCK。
3. `requires.platform` 主版本不匹配 / 高于当前 → BLOCK；同 major 兼容版本 → PASS。
4. 缺 preview / 缺 SEO metadata → WARNING 放行；`--strict` → 失败。
5. 历史脏数据（绕过保存校验）→ 渲染侧 SafeUrl 兜底不输出危险协议。
6. 第三方端点不可用 / 配错 → 主站 200、Console 0、表单正常。

**对拍**：安全治理前后，HTTP status / canonical / Schema / GEO / links / H1 逐项一致（安全只移除危险项）。

---

## 7. 结论

- Template Safety Model 把模板生态的信任边界（Theme 高信任 / Pack 封闭 / Plugin 独立）、
  不可信输入、URL scheme 安全原语、九步校验管线、分级判定与失败隔离一次定义清楚。
- 所有安全能力**复用现有 `HeadCodeSanitizer` 范式与统一渲染管线**，不造第二套。
- 本文为 Discovery 产物；落地归入 **TD-130**，与 TD-131/134 协同。
- **STOP，等待裁定，不编码。**
