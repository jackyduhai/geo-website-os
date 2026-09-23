# GEO Website OS Design System

> 适用范围：GEO Website OS 前台站点主题、后台管理界面、Example 主题与所有官方/第三方主题。
> 本文档只描述**产品级设计语言与可用 token**，不绑定任何具体客户行业。所有主题与页面必须使用本系统的 token 与组件，不得引入业务专属配色、命名或视觉资产。
> 真实 token 来源（单一事实）：前台 `resources/views/layouts/site.blade.php` 的 `:root`，后台 `public/css/admin.css` 的 `:root`。本文档与二者保持一致；代码为最终事实，文档随代码更新。

---

## 1. 品牌

- **产品名**：GEO Website OS（中文可称「GEO 网站操作系统」）。
- **定位**：多站点、面向 GEO/SEO 的通用内容与实体（Entity）操作系统。开箱自带的是**中性 Example 示例站**，部署者替换为自有品牌与数据。
- **标识**：默认提供中性占位 logo / favicon / og 图（目标圆环标记 + 蓝绿配色），不含任何客户元素。站点可在后台上传自有标识；未上传时使用中性占位，不回退到任何具体品牌名、电话、地址、二维码。
- **品牌中立**：核心 Runtime、默认主题、Example 数据、错误页、安装页、CLI 输出一律使用产品名与通用文案，不出现客户公司名、行业专名、联系方式。

## 2. 设计原则

1. **8 点网格**：间距、尺寸以 4px 为最小基准、8px 为主节奏。
2. **中性阶为主**：约 70% 界面由白 / 浅灰 / 深色文字构成，品牌色克制使用。
3. **去盒子化**：默认用 1px 边框与背景层次分区，而非重阴影卡片；阴影默认关闭。
4. **品牌色克制**：主色蓝用于链接/选中/主品牌，绿色唯一承担主 CTA，红色只用于错误态。
5. **轻动效**：100–240ms、统一标准缓动；动效服务于反馈，不做装饰性长动画。
6. **空值优雅降级**：任何站点数据（logo、电话、地址、图片、区块）缺失时整块隐藏或显示中性占位，绝不输出写死的客户兜底值。
7. **主题可覆盖**：默认配色为产品中性值，站点可通过后台「外观与主题」覆盖品牌色、表面色、文字色、圆角、容器宽度、字体；覆盖通过 CSS 变量实现，不改写核心样式。
8. **多站隔离**：每个站点的主题设置独立解析与缓存，不跨站泄漏（见 SiteContext / SiteCacheKey 契约）。

## 3. 颜色

### 3.1 前台（站点主题，`layouts/site.blade.php :root`）

| Token | 默认值 | 用途 |
|---|---|---|
| `--brand` | `#2563EB` | 主品牌色：链接、选中态、品牌点缀 |
| `--brand-dark` | `#1D4ED8` | 主色 hover / 深色 |
| `--brand-active` | `#1E40AF` | 主色按下 |
| `--brand-soft` | `#EAF1FE` | 主色浅底（标签、选中通栏） |
| `--accent` | `#0E9F6E` | 辅色：导航下划线、focus 点缀 |
| `--accent-dark` | `#0B7A55` | 辅色深色 |
| `--accent-soft` | `#E4F6F0` | 辅色浅底 |
| `--cta` | `#059669` | **唯一主行动色**（主按钮） |
| `--cta-dark` | `#047857` | 主按钮 hover / active |
| `--cta-soft` | `#E6F6F0` | CTA 浅底 |
| `--bg` | `#FFFFFF` | 页面底色（可主题覆盖） |
| `--surface` | `#FFFFFF` | 卡片 / 表面（可主题覆盖） |
| `--surface-2` | `#F5F5F5` | 次级表面、hero 底 |
| `--surface-3` | `#EDEDED` | 更深一级表面 |
| `--ink` | `#1A1A1A` | 主文字 / 标题（可主题覆盖） |
| `--ink-2` | `#333333` | 正文 |
| `--ink-muted` | `#666666` | 次要文字（可主题覆盖） |
| `--ink-faint` | `#999999` | 弱化 / 占位文字 |
| `--line` | `#E5E5E5` | 默认 1px 边框 |
| `--line-soft` | `#F0F0F0` | 更轻分隔线 |
| `--footer-bg` | `#1A1A1A` | 页脚深底 |
| `--footer-ink` | `#BFBFBF` | 页脚文字 |
| `--warning` | `#E6A23C` | 警告语义 |
| `--error` | `#DC2626` | **错误语义专用，不随品牌色变化** |

### 3.2 后台（`public/css/admin.css :root`）

| Token | 值 | 用途 |
|---|---|---|
| `--brand` / `--brand-dark` / `--brand-soft` / `--brand-line` / `--brand-ring` | `#2563EB` / `#1D4ED8` / `#EAF1FE` / `#C7D9FD` / `rgba(37,99,235,.12)` | 后台主色、浅底、边线、focus ring |
| `--accent` / `--accent-dark` / `--accent-soft` / `--accent-line` | `#0E9F6E` / `#0B7A55` / `#E4F6F0` / `#B7E4D5` | 成功 / 辅色 |
| `--ok` / `--ok-bg` | `#0E9F6E` / `#E4F6F0` | 成功态 |
| `--warn` / `--warn-bg` | `#B26A00` / `#FCF3E2` | 警告态 |
| `--err` / `--err-bg` / `--err-line` | `#B3261E` / `#FCECEB` / `#E6B8B5` | 错误态 |
| `--info` / `--info-bg` | `#2D4C77` / `#EEF2F7` | 信息态 |
| `--muted` / `--faint` | `#737A86` / `#969CA6` | 次要 / 弱化文字 |
| `--side-bg` / `--side-border` / `--side-text` / `--side-text-dim` / `--side-hover` | `#FFFFFF` / `var(--line)` / `#404652` / `#1D2129` / `#F2F3F5` | 侧栏配色 |

### 3.3 用色规则

- 不得硬编码十六进制颜色到组件里；一律引用 token。
- 不新增「成功蓝 / 警告绿」之类错位用法；语义与颜色一一对应。
- 红色仅用于错误、删除、破坏性操作。
- 品牌色可被站点主题覆盖；语义色（尤其 error）不随品牌色改变。
- 深底 hero / 页脚上的文字使用白色或 `rgba(255,255,255,*)` 透明度阶，保证对比。

## 4. 字体与字阶

### 4.1 字体族

- **前台**（系统字栈，零网络字体依赖）：
  `-apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Helvetica Neue", Arial, sans-serif`，可被后台主题字体覆盖。
- **后台 / Tailwind 入口**（`resources/css/app.css`）：`--font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, …`。
- 图标字体不使用；图标统一线性 SVG。

### 4.2 字阶（前台基准）

| 用途 | 字号 / 行高 / 字重 |
|---|---|
| 正文 body | 16px / 1.75 / 400 |
| 标题 h1–h4 | 行高 1.3 / 700 / 字距 -0.01em |
| 首屏主标题 hero / 大图标题 | `clamp(28px, 3.6vw, 46px)` / 1.16 / 800 / -0.02em |
| 大图副标题 | `clamp(15px, 1.3vw, 18px)` / 1.7 |
| Logo 字 | 20px / 700 / -0.01em |
| 顶部导航 | 15.5px / 500（选中 600） |
| 小标签 / kicker | 大写、字距加大、弱化色 |

- 后台为信息密集型，以 12–14px 字阶为准（一级导航 14px、二级 13.5px、分组标题 12.5px 弱化），具体以 `admin.css` 为准。
- 正文容器建议 `max-width: 72ch`（`.prose`），保证阅读行长。

### 4.3 字阶 Token（`:root`，rem，单一事实）

| Token | rem / px | | Token | rem / px |
|---|---|---|---|---|
| `--fs-display` | 3 / 48 | | `--fs-base` | 1 / 16 |
| `--fs-h1` | 2.75 / 44 | | `--fs-sm` | .9375 / 15 |
| `--fs-h2` | 2 / 32 | | `--fs-xs` | .875 / 14 |
| `--fs-h3` | 1.5 / 24 | | `--fs-2xs` | .8125 / 13 |
| `--fs-h4` | 1.1875 / 19 | | `--fs-label` | .78 / ~12.5 |
| `--fs-lg` | 1.125 / 18 | | `--fs-button` | .9375 / 15 |

- 结构性标题（H1–H4）与正文根**必须**消费 token；组件辅助文字就近取 `xs/2xs/label`。
- px→token：12/12.5→label，13/13.5→2xs，14/14.5→xs，15/15.5→sm，16/16.5→base，17/18→lg，19/20→h4。
- **允许保留 px**：`@media` 断点收缩值；落在 token 缝隙的组件微调；品牌标识 `.logo` 20px、大展示数字 `.wsf-n`/`.cta-phone` 34px、FAQ marker 22px；404 inline 72px。

## 5. 间距

4px 基准、8 点网格（前台 `:root`）：

| Token | 值 | 典型用途 |
|---|---|---|
| `--sp-1` | 4px | 图标与文字间隙、紧凑内联 |
| `--sp-2` | 8px | 标签内边距、列表项小间隙 |
| `--sp-3` | 12px | 控件内边距、小网格 |
| `--sp-4` | 16px | 卡片/表单常规内边距 |
| `--sp-5` | 24px | 区块内元素间距、容器左右留白 |
| `--sp-6` | 32px | 卡片之间、小节间距 |
| `--sp-7` | 48px | 区块纵向间距 |
| `--sp-8` | 64px | 大节间距 |
| `--sp-9` | 96px | 首屏 / 页脚级留白 |

- 容器：`--container` 默认 1200px（可主题覆盖）；`.wrap` 左右 padding 24px、居中、`width:100%`。
- 不得使用 5/7/11/13/17/19/23 等脱离网格的魔法数；确需细微偏移用语义化的局部变量并注释。

## 6. 圆角

| Token | 值 | 用途 |
|---|---|---|
| `--radius-xs` | 4px | 小标签、紧密控件 |
| `--radius-sm` | 6px | 输入框、小按钮 |
| `--radius` | 8px（可主题覆盖） | 按钮、常规卡片 |
| `--radius-lg` | 12px | 大卡片、媒体容器 |
| `--radius-xl` | 16px | 最大圆角，**禁止超过 16px** |
| `--radius-full` | 999px | 仅用于 chip / 圆点 / 胶囊控件 |

- 后台：`--radius:10px`、`--radius-sm:8px`、侧栏宽 `--sidebar-w:232px`。
- 同一层级圆角保持一致，不对相邻元素混用多档圆角。

## 7. 阴影与层级

默认**无阴影**，用 1px 边框（`--line` / `--line-soft`）与表面色分层。

| Token | 值 | 用途 |
|---|---|---|
| `--shadow` / `--shadow-sm` | none / `0 1px 2px rgba(26,26,26,.06)` | 静态：默认无，仅极轻卡片 |
| `--shadow-hover` | `0 2px 8px rgba(26,26,26,.08)` | 可悬浮元素 hover |
| `--shadow-overlay`（后台 `--shadow-pop`） | `0 8px 24px rgba(29,33,41,.10)` | 下拉、弹层、popover |

- z-index 约定：吸顶头 50；弹层 / 抽屉 / 模态在其之上并集中管理，不散落魔法数。

## 8. 动效

| Token | 值 | 用途 |
|---|---|---|
| `--motion-fast` | `.1s cubic-bezier(.2,0,.2,1)` | 颜色、边框、小反馈 |
| `--motion-base` | `.16s cubic-bezier(.2,0,.2,1)` | 按钮、卡片、导航（后台 `--dur-fast:160ms` / `--ease`） |
| `--motion-slow` | `.24s cubic-bezier(.2,0,.2,1)` | 较大位移、展开 |

- 统一缓动 `cubic-bezier(.2,0,.2,1)`；hover 上浮 1px、active 回 0，提供按压反馈。
- 尊重 `prefers-reduced-motion`：用户开启减少动态效果时，停用非必要位移/过渡。

## 9. 组件

### 9.1 按钮

类名：`.btn`（等同 `.btn-primary`）、`.btn-secondary`（等同 `.btn-o`）、`.btn-ghost`（深底反白）、`.btn-text`（文字按钮）、`.btn-lg`（大尺寸 15px 32px / 圆角 `--radius`）。

- 主按钮：`--cta` 底白字，hover `--cta-dark` 并上浮 1px。
- 次按钮：`--surface` 底、`--line` 边、`--ink` 字，hover 换 `--surface-2`。
- 幽灵按钮用于深底（半透明白底白边）。
- 图标后缀 `.arr` 在 hover 时位移表达方向。
- 禁用态使用降透明度 + `not-allowed`，不靠换色表达。

### 9.2 表单

- 文本/选择/多行：`--surface` 底、1px `--line` 边、`--radius-sm`、14px、500；focus-visible 用 `2px solid var(--accent)`、`outline-offset:2px`。
- label 与控件成对、可点击；必填与错误提示就近、可读；错误用 `--error`。
- 示例占位（placeholder）为通用中性词，不放客户公司或行业示例。

### 9.3 卡片 / 媒体

- 去盒子化：默认 1px 边框或 `--surface-2` 底；可悬浮卡 hover 出现 `--shadow-hover`。
- 图片圆角 `--radius` / `--radius-lg`，媒体懒加载并提供 alt。
- 产品卡 `_product_card`、场景卡 `_scene_card` 等为数据驱动组件，不含任何写死产品名。

### 9.4 导航

- 顶部 `.nav a`：15.5px/500，hover 主色、下划线 2px 辅色；当前 `.on` 主色 600 + 辅色下划线。
- 后台侧栏：宽 232px，品牌区 58px，一级项行高 36px（14px/500），分组标题 12.5px 弱化不可点，二级项行高 32px（13.5px）；hover 通栏 `--side-hover`，选中主色 + 浅主色通栏（无圆角无左条）。
- 移动端汉堡 `.nav-toggle` 触控区 44×44。

### 9.5 分页

`.pagination`：页链为 1px 边框小控件；hover 加深边框；当前页为 `--ink` 底白字；禁用态透明弱化。

### 9.6 标签 / 徽章

chip 使用 `--radius-full`、`--*-soft` 浅底 + 对应深色文字；语义与颜色一致。

### 9.7 区块 / Hero / 页脚

- Section 纵向节奏用 `--sp-7/8/9`；kicker 小标签前置 28×2 品牌短线。
- Hero / 大图：深底 + 左至右渐变遮罩保证文字对比；轮播点 8px 圆、选中拉长为 22px 胶囊。
- 页脚 `--footer-bg` 深底 + `--footer-ink` 文字；联系方式缺失时对应条目整块不输出。

### 9.8 状态页与反馈

- Empty（无结果）、Loading（加载中）、Error（错误，含 403/404/500/503）使用统一中性插画/文案与产品名，不暴露堆栈、不出现客户品牌。
- Alert / Toast 语义色与 §3 一致；后台 Alert 提供 ok/warn/err/info 四态。

## 10. 图标

- 统一线性风格：Feather / Lucide 语汇，16px、`stroke-width:1.7`、`currentColor`，随文字着色。
- 后台导航图标经 `partials/nav-icon.blade.php` 唯一入口注册，默认中性灰、hover 转深、选中转品牌色。
- 不使用客户 logo 形状、不使用 emoji 充当功能图标。

## 11. 可访问性

- 键盘可达：所有交互元素可 Tab 到达，focus 可见（focus-visible 2px、足够对比、不 `outline:none` 后不补回）。
- 对比：正文/控件文字满足 WCAG AA（深底白字、浅底深字）。
- 触控：可点区域 ≥ 44×44（导航开关、移动菜单项）。
- 图像：内容图必须有 alt；装饰图 `alt=""`。
- 动效：遵循 `prefers-reduced-motion`。
- 语义与 ARIA：见 `docs/html-standard.md`，不凭视觉补 ARIA，优先原生语义。

## 12. 主题覆盖契约

- 站点后台「外观与主题」可覆盖：主色、主色深、辅色、背景、表面色、主文字、次要文字、圆角、容器宽、字体（对应 `theme_*` 设置项）。
- 覆盖在渲染时注入 `:root` CSS 变量；核心样式只引用变量，因此覆盖无需改 CSS 文件。
- 默认值即本文档中性产品配色；未配置时输出默认值，不输出客户值。
- Example 主题（`resources/themes/example`）只演示主题契约（覆盖 `layouts/site` 与 `site/home`、消费规范化的 `$seo` 与视图数据、不触碰引擎内部）。

## 13. 明确禁止

- 客户/行业专属颜色、类名、注释、图片、字体、品牌元素。
- 组件内硬编码十六进制颜色 / 间距 / 圆角 / 阴影（必须用 token）。
- 滥用 `!important`、内联 `style` 写视觉规则（布局性极少值除外，且需注释）。
- 自造阴影、自造圆角档位、自造缓动曲线。
- 用红色表达非错误信息、用主 CTA 绿表达次要操作。
- 数据缺失时回退到写死的客户名称、电话、地址、二维码、品牌图。

---

## 14. Light / Dark / System（v1 能力）

- 三种外观：**Light / Dark / System**（跟随 `prefers-color-scheme`）；前台必须完整覆盖 Header/Footer/Hero/Card/Form/Button/Input/Modal/Dropdown/Table/Empty/404/500/Media/Article/Product/Service。
- 深色拥有**独立语义令牌**（不是简单反色）：中性表面/文字子集 + 品牌/辅色经对比提亮（向白 mix 至 AA ≥ 4.5）。
- SSR 首屏即正确（`<html data-color-scheme>` + 内联防 FOUC），选择存 `localStorage`（键 `gwos-color-mode`），刷新/新页保持。
- 后台 v1 不要求深色，但可配置默认外观与是否允许深色（`theme_color_mode` / `theme_allow_dark`）。
- 图片 / Logo / SVG 如需区分浅深版本，应随主题提供，不得在深底丢失对比。

## 15. 行业主题预设（只改视觉，不改 IA）

- 提供 8 类通用视觉预设：**professional（默认）/ industrial / commerce（消费者·零售电商 Consumer）/ technology / education / lifestyle / finance / healthcare**。
- 预设只改变颜色 / 字阶 / 圆角 / 阴影 / 视觉密度；**不得注入任何行业业务数据或 IA**（选 industrial 不会出现工厂/OEM/车间/配方/产能/报价）。
- 行业业务内容只属于经 `db:seed` 可选装载的 Example Demo；Theme 与 Demo Content 严格解耦。
- 预设契约：`primary_dark` 恒空、tokens 仅含白名单键、动态从 config 读取（无硬编码数量）。

## 16. 焦点环、必填语义与组件状态

- 全局 `:focus-visible`（仅键盘）：`outline:2px solid var(--brand); outline-offset:2px; border-radius:var(--radius-xs)`，覆盖链接/按钮/[role=button]/[tabindex]/卡片/summary/分页。
- 表单控件用专门 `:focus`（border 辅色 + 2px ring、outline none），不在全局焦点环内。
- 必填字段除视觉 `*` 外必须标 **`aria-required="true"`**；错误就近、成功 `role="status"`、蜜罐 `aria-hidden"`。
- disabled 态全站一致（降透明 + not-allowed）；组件 loading/aria-busy 模式 v1.1 补齐。

## 17. 部署缓存与响应式契约

- **视图 / CSS / 模板随发布变更后必须清缓存**：`geo:upgrade` 在迁移与验证后执行 `view:clear` + `PageCache::flush()`，否则旧整页快照持续返回。
- **CSS 媒体缩小规则的源顺序**：媒体查询不增加特异性；同特异性下后出现的规则胜出。媒体缩小规则必须放在对应基础规则**之后**（或提高特异性），否则基础规则压住媒体值（`.ph-h` 案例）。
- **响应式断点不随行业预设变化**：预设只改视觉 token，布局断点（360/375/768/1280/1440）行为对所有预设一致，抽 light/dark + 默认即可，无需预设×断点全组合。
- 任何断点禁止横向滚动；长标题/长型号需 `overflow-wrap:anywhere` 等安全换行。
