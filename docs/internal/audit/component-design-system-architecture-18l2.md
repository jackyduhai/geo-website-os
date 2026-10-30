# P-STEP 18L-2 · Component Design System — Architecture Decision

- 阶段：P-STEP 18L-2（Component Variant Registry + Interaction System）
- 性质：**Architecture 提案，待用户裁定；裁定前不编码**
- 配套：`component-design-system-discovery-18l2.md`（现状 / 缺口）
- 上游原则：18L-1 Token Foundation、18E 前后台能力闭环、18G Composition 三层

---

## 1. 核心契约：Component | Variant | Token | Renderer

把"组件"从散落 CSS 类升级为声明式契约：

```
ComponentDefinition（组件身份）
  ├── key：button / card / hero / section / stats / faq / form / footer ...
  ├── semantic root：允许的根标签（section / article / nav / button / a ...）—— 语义硬约束
  ├── variants：ComponentVariant[]
  │     ├── variant key：default / split / glass / ghost ...
  │     ├── tokens：该变体消费的 token 集合（颜色 / 面 / 描边 / 阴影 / 圆角）
  │     ├── states：default / hover / active / focus / disabled / loading
  │     └── renderer：注册渲染器（block blade / partial），不存任意 HTML
  └── default variant
```

要点：

- **Variant 只决定"消费哪些 token + 根标签"，不复制组件、不内嵌业务事实。**
- Renderer 输出语义 HTML；同一组件不同 variant 的 **h1/h2/p/a/button 层级与可被 GEO 抽取的结构不变**。
- Registry 首先是**契约与单一事实源**：把现有已工作的类名（`.btn*`、`.hero*`、`.card/.pcard/...`）映射登记，再做收敛，而不是另起一套视觉。

---

## 2. 目录与注册方式（新增，最小）

```
app/Support/Components/
  ├── ComponentRegistry.php      // 注册、按 key 取定义、列出变体
  ├── ComponentDefinition.php    // 不可变值对象（key / root / variants / default）
  └── ComponentVariant.php       // 不可变值对象（key / tokens / states / renderer）
config/components.php            // 声明式组件清单（与 config/blocks.php 同风格）
```

- 风格对齐现有 `BlockType::fromConfig()` / `BlockRegistry`，不引入新框架、不引前端构建依赖。
- Block（页面组成单元）与 Component（可复用视觉单元）关系不变：**Block renderer 消费 Component**（如 `feature_grid` block 渲染 Card 组件）。Component Registry 不替代 Block Registry。

---

## 3. 首批 Variant 裁定建议

### 3.1 Button（现状已四级，登记 + 补 loading）

| Variant | 现有类 | 消费 token |
| --- | --- | --- |
| primary | `.btn/.btn-primary` | `--cta` / `--cta-on` / `--cta-dark` |
| secondary | `.btn-o/.btn-secondary` | `--surface` / `--ink` / `--line` |
| outline | 由 secondary 收敛（描边、透明底） | `--surface` / `--line` |
| ghost | `.btn-ghost`（仅反白区） | `--inverse-hover*` / `--on-inverse` / `--inverse-line*` |
| text | `.btn-text` | `--brand` / `--brand-dark` |

- 尺寸：`lg / md(default) / sm`。
- 状态：default / hover / active / focus-visible / disabled / **loading（新增）**。

### 3.2 Card（关键：从"用途 6 套"抽出"视觉变体"，用途由 block 决定）

视觉变体（token 组合，不复制 DOM）：

| Variant | 面 | 描边 | 阴影 |
| --- | --- | --- | --- |
| default | `--surface` | `--line-soft` | hover `--shadow-hover` |
| glass | 半透明 + blur | 透明/发丝 | 无 |
| border | `--surface` | `--line`（明显描边） | 无 |
| shadow | `--surface` | 无/极淡 | `--shadow-sm` 常显 |
| minimal | transparent | 无 | 无（仅排版 + hover 色） |

- 用途（product / knowledge / scene / post / ws）仍由各 block renderer 决定内容结构；**视觉变体横向复用**，从而"一个组件换气质"。
- 深色下各变体由 Dark token 自动承接，不单独写深色色值。

### 3.3 Hero（登记现有 5 布局为 variant，不新增炫技）

`default`(轮播 A) / `image`(图片轮播 B) / `split`(分栏) / `integrated`(一体化 C) / `text`(兜底)。

- 右侧 `.hero-panel` 作为 split/integrated 的可选子部件。
- 所有 variant 保持：恰好一个 `<h1>`、`<p>` 引导、主行动用 primary button、kicker 不承载关键信息。

### 3.4 Section / 通用区块

- 面：`default`(透明) / `tint`(`--surface-2`) / `inverse`(`--surface-inverse` + on-inverse 文字)。
- 头部对齐：`left` / `center` / `row`（标题与"查看全部"同行）。

---

## 4. 状态系统与 Token（含 loading）

### 4.1 状态 token 化

- 颜色态：hover/active 的背景、描边、文字一律走 token（现状按钮已基本满足）。
- 位移动效收敛为语义，不各处手写：
  - hover 上浮统一为一个"轻抬"尺度（`--lift` 或复用 motion 语义），取代散落的 `-1px/-3px`；
  - 箭头位移统一 `--arrow-shift`，取代散落 `translateX(3px)`。

### 4.2 Loading / aria-busy（TD-36，由 v1.1 升级纳入 18L-2）

- 可提交按钮在异步提交期间：加 `.is-busy` + `aria-busy="true"`，显示注册 spinner（`border` 圈，尺寸/颜色取 token），禁用重复提交；结束恢复。
- 服务端渲染 + 前端 JS 辅助；**最终裁决仍在服务端校验**（沿用 18H-2 原则）。
- spinner 在 `prefers-reduced-motion` 下不旋转（静态指示）。
- 不引入全局 loading 遮罩；仅就地反馈，避免遮挡内容。

---

## 5. Motion 边界（信息优先，不炫技）

- 保留三档 motion token 与现有 reveal / 数字滚动 / scroll-snap；**本阶段不新增动画种类**。
- 收敛动作：把散落 transition 时长统一引用 token（绝大多数已引用），位移数值语义化（§4.1）。
- 全程保持 `prefers-reduced-motion` 与 JS `matchMedia` 双降级。

---

## 6. 语义硬约束（GEO 不可破坏）

1. 每个 ComponentDefinition 声明 `semantic root` 白名单；renderer 必须使用，禁止把 section/article/nav/h 改成纯 `<div>`。
2. Variant 切换**不得**改变：标题层级（h1 唯一 / h2 / h3）、列表语义、`<a>`/`<button>` 的选择（导航用 a、动作用 button）、原生 `<details>`。
3. 视觉层与 GEO 层解耦：Schema / `inLanguage` / canonical / PublicUrl / sitemap / llms 不随 variant 改变（18L-4 的 data-* 语义元数据在后续阶段处理，本阶段只保证不破坏）。
4. 富文本仍存 Markdown、由注册渲染器出安全 HTML；DB 不存任意 Blade/HTML/PHP（沿用 18G 安全边界）。

---

## 7. 与 Theme / Template / Block 的分层（不造第二套）

```
Theme      → 视觉 token（颜色 / 字号 profile / 圆角 / 阴影 / motion）
Component  → 可复用视觉单元 + variant（消费 Theme token）
Block      → 页面组成单元（消费 Component + Content/Entity/Media）
Template   → 结构 / 槽位（18L-3 处理 Package/SDK）
Page       → 页面实例
```

- 复用现有 `ThemePalette` / token 注入 / `CompositionRenderer`，**不新增** LocalizedComponent / 第二套样式管线。
- 不碰 18L-3（Template Package/SDK、recipe）与 18L-4（拖拽、区块 data-* 语义）。

---

## 8. Gate 切分建议

### 18L-2a — Registry 契约 + Button/Card 变体 + 字号 token 化

- 新增 `app/Support/Components/*` + `config/components.php`，登记现有组件/变体（契约成立）。
- Button 五变体登记、补 loading；Card 五视觉变体落地并被现有 grid block 复用。
- **27 处内联 px 字号全部改为消费 `--fs-*` / 语义 class**，验证三 profile 切换无死角。
- 输出：focused + 全量回归 + zh/en × Light/Dark 浏览器对拍。
- **PASS → STOP**。

### 18L-2b — 状态/spacing 收口 + 其余组件映射

- Hero / Section / Stats / FAQ / Form / Footer 等全部进入 Registry 映射。
- 内联 spacing、位移数值收敛 spacing scale / 语义 motion（TD-123）。
- loading/aria-busy 在真实表单提交链路验证（含 reduced-motion）。
- 输出：全量回归 + 真实浏览器 + GEO/SEO/Schema 公开契约不破坏核对。
- **PASS → STOP**，不自动进入 18L-3。

---

## 9. TD Acceptance Criteria（汇总，与台账对齐）

| ID | 验收标准 |
| --- | --- |
| TD-116 | ComponentRegistry 成立（`app/Support/Components` + `config/components.php`）：Button 5 / Card 5 / Hero 5 / Section 3 变体登记，变体→token 映射可查；每组件声明语义根标签，renderer 按 variant 输出但 section/h1/p/a/button/nav/details 不变；不按行业建组件、不造第二套样式管线 |
| TD-122 | 27 处内联 px 字号清零，统一消费 `var(--fs-*)` / 语义 class；standard/compact/editorial 切换在 content/search/各 block 生效 |
| TD-123 | 内联 spacing 与 hover/箭头位移收敛 scale / 语义；transition 统一引用 motion token |
| TD-36 | 异步提交有 `.is-busy`/`aria-busy`/spinner 就地反馈、禁用重复提交；reduced-motion 静态可降级；服务端校验为最终裁决 |

---

## 10. 验收矩阵（Gate 必查）

- [ ] Component Variant Registry 为单一事实源（无第二套组件/样式管线）
- [ ] Button 五变体 + 六状态（含 loading）；Card 五视觉变体
- [ ] 27 处内联 px 字号清零；三 typography profile 全站生效
- [ ] spacing / 位移动效语义化；motion token 统一引用
- [ ] 语义标签零退化（section/h1-4/p/a/button/nav/details）
- [ ] GEO / Schema / SEO / canonical / sitemap / llms 公开契约不破坏
- [ ] zh/en × Light/Dark 四组合真实浏览器通过，Console 0 error
- [ ] Multi-Site 不串、PageCache 不串
- [ ] 全量回归 0 failed / 0 skipped；Fresh Install / Blank / Demo 正常
- [ ] Runtime 污染 0、无新增产品 ERROR、临时环境清理、worktree clean、annotated tag 对齐

---

## 11. 待裁定事项（请用户拍板）

1. 是否同意 **Component | Variant | Token | Renderer** 契约与 `app/Support/Components` + `config/components.php` 目录方案。
2. 首批变体范围是否按 §3（Button 5 / Card 5 / Hero 5 / Section 3）。
3. 是否同意两次 Gate（18L-2a 契约+Button/Card+字号；18L-2b 状态/spacing+其余映射）。
4. loading 态是否采用就地 `.is-busy`/`aria-busy`/spinner（不做全局遮罩）。

**裁定前保持 STOP，不编码、不进入 18L-3/18L-4，不碰 remote/rc1/Release。**
