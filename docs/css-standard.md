# GEO Website OS CSS Standard

> 配套文档：`docs/design-system.md`（token 与组件的事实来源）。本文件规定 CSS 的**写法、分层、命名与边界**。
> 适用：前台站点主题、后台管理样式、官方/第三方主题。规则与现状代码一致；新增样式必须遵守，历史样式在接触时就近收敛。

---

## 1. 样式来源与分层（不搞双轨）

项目当前有三条**职责清晰、不混用**的样式来源：

1. **设计 token 层**：前台 `resources/views/layouts/site.blade.php` 的 `:root`、后台 `public/css/admin.css` 的 `:root`。集中定义颜色、间距、圆角、阴影、动效、容器、字体（见 design-system §3–8）。
2. **前台站点样式**：随默认主题布局内联维护的 CSS（`layouts/site.blade.php`），消费 `:root` 变量；主题可覆盖视图，不修改引擎样式。
3. **后台样式**：`public/css/admin.css`（手写、单一文件、消费后台 `:root`）。
4. **Tailwind 入口**：`resources/css/app.css` 仅 `@import 'tailwindcss'` 加 `@theme` 字体与 `@source` 扫描配置，经 Vite 构建。

规则：

- **颜色、间距、圆角、阴影、动效、容器、字体只能引用 token 变量**，不在组件里写死十六进制 / 像素魔法数。
- 同一界面不得同时用两套体系表达同一视觉（例如不要在内联 CSS 定义一个蓝、又在 Tailwind 类里写另一个蓝）；主题色一律走 CSS 变量。
- 后台样式收敛在 `admin.css`；前台站点样式收敛在主题布局；不在多个 Blade 里重复定义同名组件类。
- `public/build` 为构建产物，已被 `.gitignore` 忽略，不手工修改、不入库。

## 2. 命名约定

- 一律**小写 + 连字符**：`.product-card`、`.hero-panel`、`.nav-toggle`；不用驼峰、下划线、`#id` 选择器做样式。
- 类名表达**功能 / 结构**，不表达客户、行业或具体业务：
  - 正确：`.pcard`、`.scn-page`、`.lead-form`、`.bcta`、`.wrap-wide`。
  - 禁止以任何具体客户品牌名、客户产品名或行业工艺名命名（形如 `.<client-brand>-*`、`.<client-product>-*` 的类都不允许）。
- 采用轻量 BEM-lite：块 `.card`、元素 `.card-title` / `.card-body`、状态修饰 `.is-active` / `.on` / `.disabled` / `.open`。状态类以 `is-` / `has-` 或既有单字状态（`.on`）命名，全站统一，不新造同义词。
- 工具性、与 JS 协作的类 / 钩子以约定前缀区分：纯展示类用语义名，JS 钩子必要时用 `js-*` 前缀，且**不在 CSS 里给 `js-*` 加视觉样式**。
- 主题覆盖通过替换 Blade 视图 + 既有类名 / token 完成，不改写核心类名语义。

## 3. 选择器与特异性

- 优先级尽量扁平：单类选择器为主，组合不超过 2–3 层；避免 `.a .b .c .d` 深嵌套。
- 不用元素 + 类堆叠提权（`div.card.card`）、不用 `#id` 选择器、不用 `!important`（覆盖第三方/极特殊兜底除外，且必须注释原因）。
- 需要覆盖时，通过**提高变量层或在主题内明确重声明**解决，而不是堆特异性。
- 全局 reset 只做必要项（项目已有 `*{box-sizing:border-box}`、`p{margin:0}` 等），不引入第二套 reset。

## 4. Token 使用

- 颜色：`var(--brand)` / `var(--ink-2)` / `var(--line)` 等，禁止裸色值（白色文字、黑色遮罩等中性黑白透明度除外，且优先用语义变量）。
- 间距：`var(--sp-1…9)` 或 4 的倍数；禁止 5/7/11/13/17/19/23 等脱离网格的值。
- 圆角：`var(--radius-xs/sm/--radius/lg/xl/full)`，最大 16px，`full` 仅 chip / 圆点 / 胶囊。
- 阴影：默认无；悬浮 `var(--shadow-hover)`、浮层 `var(--shadow-overlay)`；不自造阴影。
- 动效：`var(--motion-fast/base/slow)`，统一缓动；不自造时长 / 贝塞尔。
- 容器：`.wrap`（1200）、`.wrap-wide`（1320）、`.wrap-narrow`（960）、`.text-measure`（720）；不另写 max-width 魔法数。
- 字体 / 字号：标题与正文用既有字阶与 `--fs-*` 变量；正文行长不超过 ~72ch。

## 5. 响应式

移动优先，断点以代码既有值为准，集中使用、不临时新增：

| 断点 | 含义 |
|---|---|
| `max-width:1024px` / `min-width:1025px` | 桌面 ↔ 平板布局切换（多列降列、侧栏转抽屉等） |
| `min-width:769px` | 桌面导航面板生效；≤768 为移动导航 |
| `max-width:768px` | 主移动断点（导航、hero、网格主适配） |
| `max-width:600px` | 小屏（列表、表单单列、缩略图缩小） |
| `max-width:380px` | 超小屏，仅必要微调 |

- 媒体查询就近放在对应组件样式之后，不集中成一个孤立的"响应式大杂烩"。
- 流式优先：能用 `clamp()`、flex/grid `minmax`、百分比就不写死宽度；固定断点处理布局级重排。
- 图片 `max-width:100%;height:auto`；宽表格 `overflow-x:auto`。

## 6. 状态、动效与可访问性

- 交互状态必须齐全且可感知：`:hover`、`:focus-visible`、`:active`、`disabled`。
- focus 可见：输入框 `outline:2px solid var(--accent); outline-offset:2px`；禁止 `outline:none` 后不提供替代焦点样式。
- 可点区域 ≥ 44×44（移动导航开关、图标按钮）。
- 必须包含并保留：
  ```css
  @media (prefers-reduced-motion: reduce) { /* 停用非必要过渡/位移 */ }
  ```
- 颜色不作为唯一信息载体（错误同时有图标/文字）；文字对比满足 WCAG AA。
- 深底（hero、页脚、CTA 带）文字用白 / 高对比，渐变遮罩保证可读。

## 7. 主题与多站

- 站点主题只通过覆盖 `:root` CSS 变量（后台「外观与主题」→ 渲染注入）换肤；核心 CSS 不针对具体站点写选择器。
- 每个站点主题独立解析、独立缓存（SiteContext / SiteCacheKey），禁止用全局静态 / 单例缓存主题值导致串站。
- 主题 Blade 只消费规范化的 `$seo` 与视图数据，不直接读引擎内部、不写业务事实。
- 主题缺失资源（logo / 图 / 联系方式）时优雅隐藏，不回退写死内容。

## 8. 内联样式与 style 标签

- 禁止用内联 `style` 写常规视觉规则；以下例外允许且应最小化：
  - 由后台主题设置动态生成的 CSS 变量值（在 `:root` 注入）；
  - 个别完全动态、无法用类表达的几何值（如轮播位移、进度宽度），并加注释。
- 组件级 `<style>` 只出现在主题 Blade 内聚样式场景；可复用样式必须回流到布局 / admin.css，避免重复。

## 9. 性能

- 避免通用通配昂贵选择器与超深后代选择器；动画优先 `transform / opacity`（合成层属性），不动画化 `width/height/top/left`。
- 不引入未使用的大型 CSS 框架；Tailwind 经 Vite 按需构建，`@source` 范围已限定。
- 字体优先系统字栈，零网络字体依赖；如引入网络字体须 `font-display:swap` 并控制字重。
- 后台为信息密集型，避免大面积模糊 / 阴影带来的绘制开销。

## 10. 禁止清单（评审硬指标）

- 客户 / 行业命名的类、id、变量、注释、颜色。
- 裸颜色值、裸间距 / 圆角 / 阴影 / 动效魔法数（应走 token）。
- `!important` 滥用、`#id` 样式、深嵌套、双 reset、双轨样式体系。
- 内联 `style` 写视觉、自造断点 / 缓动 / 阴影。
- 去掉焦点框不补回、只用颜色表达状态、不可点的"假按钮"样式绑在非交互元素。
- 在核心 CSS 里写具体站点 / 行业 / 客户的兜底内容。

## 11. 评审检查单（提交前过一遍）

1. 新增视觉值是否全部来自 token？
2. 类名是否功能化、无业务 / 客户词？
3. 选择器是否扁平、无 `!important` / `#id`？
4. 焦点、hover、active、disabled 是否完整？
5. 1024 / 768 / 600 下布局是否成立？
6. `prefers-reduced-motion` 是否仍生效？
7. 主题换肤是否只改变量、多站是否互不串色？
8. 是否有可复用样式被重复写在多个 Blade？
