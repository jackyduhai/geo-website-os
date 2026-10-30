# P-STEP 18D — Design System 2.0 Final Audit

- **阶段**：P-STEP 18D（Final Product Completeness Program 的第一阶段）
- **审计性质**：对 Design Token / Theme Engine / Light·Dark / 行业预设 / 自定义品牌 / 组件状态 / 全站视觉 / 响应式 / 可访问性做**真实代码 + 真实 HTTP + 真实浏览器（含 headless 多断点）**审计，不接受"模型描述 / 单测绿 = 产品完整"。
- **起始基线**：HEAD `d9ba2f0`（tag `checkpoint-18C`）；Regression 826 / 4139 / 0 / 0。
- **本阶段提交**：
  - `862ff1d` — Design System 令牌层、行业主题预设与前台 Light/Dark/System 深色模式。
  - 收尾提交（见文末 Git 段，含 8 行业预设契约、Typography 阶梯、组件状态、Global Visual Refactor、Responsive/A11y、geo:upgrade 缓存清理）。
- **冻结纪律**：未移动 `v1.0.0-rc1`（`965d63c` HOLD）；未配置 remote；未 push；未发布。

---

## 1. Design Token 四层模型

全站统一为四层，禁止页面/组件直接写孤立视觉值：

```
Primitive（原始值，如 #2563EB / 4 / 8）
   ↓
Semantic Token（语义：--brand / --bg / --ink / --line / --focus …）
   ↓
Component Token（组件级：.btn / .card / .input …）
   ↓
Page（页面只消费组件与语义 token）
```

- Token 单一事实源：前台 `resources/views/layouts/site.blade.php` 的 `:root`（由 `app/Support/Theme/ThemePalette.php` 按当前主题/外观解析注入）；后台 `public/css/admin.css :root`。
- 已 token 化并由 ThemePalette 派生的族：**颜色**、**间距**（`--sp-1..9`）、**圆角**（`--radius-xs..full`）、**动效**（fast/base/slow）、**阴影**（sm/default/hover/overlay）、**字号**（本阶段补齐，见 §3）。

---

## 2. 颜色与语义色

### 2.1 语义 Token

前台语义色覆盖：品牌族（`--brand` / `-dark` / `-active` / `-soft` / 品牌上文字）、辅色族（`--accent` / `-soft`）、表面族（`--bg` / `--surface` / `-2` / `-3` / 反相表面）、文字族（`--ink` / `-2` / `-muted` / `-faint` / 反相文字 / disabled）、边框族（`--line` / `-soft` / 强边框）、语义态（`--warning` / `--error` / 成功 / 信息）、`--focus`、遮罩层。

- **CTA 唯一主行动色**（绿色族），品牌蓝用于链接/选中/品牌点缀，红色仅错误/破坏性操作；语义 error 不随品牌色改变。
- **自定义品牌**：用户给一个品牌基色（Brand Seed），ThemePalette 自动派生 hover / active / soft / surface / 对比文字 / focus / 边框 / disabled，并对品牌色、深底反白做对比度处理（见 §5），无需手填几十个颜色。

### 2.2 Global Visual Refactor（彩色硬编码清零）

全样式扫描结果：

| 类别 | 数量 | 说明 |
| --- | --- | --- |
| 彩色孤立 `#hex` | **0** | 全部改由 token 消费 |
| 彩色 `rgb()/hsl()` | **0** | — |
| `#fff`（24 处） | 保留 | 深底反白区 / 图片轮播文字 / ghost 按钮，属语义反白 |
| `#000`（8 处） | 保留 | 全部用于 CSS `mask-image` 渐变；`#000` 在 mask 中代表完全不透明，是技术常量、与视觉色无关 |
| `rgba(0,0,0,*)` | 保留 | 阴影 / 遮罩 / mask |
| `rgba(255,255,255,*)` | 保留 | 反白 / ghost / 轮播 |

---

## 3. Typography 字阶（本阶段补齐）

此前字号是唯一未 token 化的缺口（200+ 处 `font-size`、20+ 种散落 px）。在 `:root` 建立 **12 档 rem 字阶**：

| Token | rem | px | 用途 |
| --- | --- | --- | --- |
| `--fs-display` | 3 | 48 | 超大展示 |
| `--fs-h1` | 2.75 | 44 | H1 |
| `--fs-h2` | 2 | 32 | H2 |
| `--fs-h3` | 1.5 | 24 | H3 |
| `--fs-h4` | 1.1875 | 19 | H4 |
| `--fs-lg` | 1.125 | 18 | 大号正文 |
| `--fs-base` | 1 | 16 | 正文根 |
| `--fs-sm` | .9375 | 15 | 小号正文 |
| `--fs-xs` | .875 | 14 | 辅助文字 |
| `--fs-2xs` | .8125 | 13 | 更弱辅助 |
| `--fs-label` | .78 | ~12.5 | 标签 / 标注 |
| `--fs-button` | .9375 | 15 | 按钮文字 |

**消费契约**：
- 结构性标题层级（H1–H4）与正文根**必须**消费 token；本阶段结构性替换 27 处。
- 组件辅助文字就近取 `xs / 2xs / label`（归并 82 处，px→token：12/12.5→label，13/13.5→2xs，14/14.5→xs，15/15.5→sm，16/16.5→base，17/18→lg，19/20→h4）。
- **允许保留 px 的缝隙**（不强行抹平）：
  - 落在 token 缝隙的组件微调（17 / 16.5 / 13.5 等）；
  - `@media` 响应式覆盖内的固定 px（断点收缩值）；
  - 品牌标识字 `.logo` 20px、大展示数字 `.wsf-n` / `.cta-phone` 34px、FAQ `summary::after` 装饰加号 22px；
  - 404 页 inline 72px（独立错误页）。
- 主样式区（@media 块之前）逐行核对，仅剩上述 4 个 px 保留项。

---

## 4. Spacing / Radius / Shadow

- **间距**：8 点网格、4px 基准，`--sp-1..9`（覆盖 2/4/8/12/16/20/24/32/40/48/64/80/96/120），页面不得自创 spacing。
- **圆角**：`--radius-xs/sm/md/lg/xl/full`，由主题密度/预设派生。
- **阴影**：`sm/default/hover/overlay`；默认去盒子化（1px 边框 + 表面层次），阴影克制。
- **动效**：fast/base/slow 统一时长与缓动，100–240ms，服务反馈。

---

## 5. Light / Dark / System（v1 产品能力）

深色模式是 v1 正式能力，不是简单反色：

- **独立深色语义令牌**：`ThemePalette::darkOverrides()` 提供深色中性表面/文字子集；品牌色与辅色经 `lightenForContrast()`（向白 mix 至对比比 ≥ 4.5 / AA）提亮，保证深底可读。
- **三种模式**：Light / Dark / System（跟随 `prefers-color-scheme`）。
- **前台完整覆盖**：Header / Footer / Hero / Card / Form / Button / Input / Modal / Dropdown / Table / Empty / 404 / 500 / Media / Article / Product / Service。
- **SSR 防闪 + 记忆**：`<html data-color-scheme>` 首屏即正确、内联防 FOUC 脚本；选择存 `localStorage`（键 `gwos-color-mode`），刷新/新页保持，切换不破坏 layout。
- **后台**：v1 不要求后台深色；后台可配置默认外观与是否允许深色（`theme_color_mode` select / `theme_allow_dark` bool）。
- **深色逐页验证**：12 个正常页全 200 且含正确 `data-color-scheme`；404/403/500/503 同源继承深色（curl 直抓确认，排除探针 catch 误报）。
- **变异证据**：删除状态恢复 `reapply()` 即有测试失败，证明状态恢复责任真实存在（非"为测试绿色"）。

---

## 6. 行业主题预设（Industry Theme Presets）

预设**只改变视觉语言**（颜色 / 字阶 / 圆角 / 阴影 / 视觉密度），**不改变业务 IA，不注入任何行业业务数据**：

| key | Label | 主色 | 辅/CTA | 圆角 | 密度 | 质感 |
| --- | --- | --- | --- | --- | --- | --- |
| `professional` | Professional | `#2563EB` | `#0E9F6E` | 10 | comfortable | flat（默认） |
| `industrial` | Industrial | `#1D6FA5` | `#B45309` | 6 | compact | flat |
| `commerce` | 消费者 / 零售电商（Consumer） | `#E11D48` | `#C2410C` | 14 | comfortable | soft |
| `technology` | Technology | `#4F46E5` | `#0E7490` | 10 | comfortable | flat |
| `education` | Education | `#7C3AED` | `#059669` | 14 | comfortable | soft |
| `lifestyle` | Lifestyle | `#0D9488` | `#BE185D` | 16 | comfortable | soft |
| `finance` | Finance | `#1E3A8A` | `#8A5A06` | 8 | comfortable | flat |
| `healthcare` | Healthcare | `#0E7490` | `#047857` | 12 | comfortable | soft |

- 所有预设 `primary_dark` 恒空、tokens 仅含 12 个白名单键；ThemePresets 动态从 config 读取、无硬编码数量。
- 契约测试 `test_all_blueprint_industry_presets_exist_with_valid_visual_tokens`：断言 8 个蓝图必需预设齐全、键合法、主色非空（单文件 6 passed / 60 assertions）。
- **解耦原则**：选 Industrial 主题**不会**自动出现工厂 / OEM / 车间 / 配方 / 产能 / 报价；这些只属于经 `db:seed` 可选装载的 Example Demo。

---

## 7. 组件状态系统

- **焦点环统一**：全局 `:focus-visible`（仅键盘导航显示）`outline:2px solid var(--brand); outline-offset:2px; border-radius:var(--radius-xs)`，覆盖 a / button / [role=button] / [tabindex] / 各级按钮 / a.card / summary / `.page-link` / `.pcard .go`。
  - 表单 `input/select/textarea` **不在**全局规则，沿用专门 `:focus`（border 辅色 + `box-shadow:0 0 0 2px var(--accent-ring)`、outline none）。
  - 图片轮播指示点保留 2px 白环（深图合理）。
  - 实测：键盘 Tab 后 activeElement `:focus-visible` = true，outline = `rgb(37,99,235)` = `var(--brand)`。
- **disabled**：补齐 `.btn-ghost:disabled` / `.btn-text:disabled`，全站禁用态一致。
- **hover / active**：统一到 token。
- **loading**：全站当前以整页 POST 刷新为主、无 `is-loading/aria-busy` 组件模式 → **DEFERRED v1.1**（不阻塞 v1）。

---

## 8. Responsive（含 `.ph-h` 缺陷修复）

- headless Chrome（`--headless=new --window-size`）对首页 / 产品列表 / 产品详情 / 文章 / 联系页在 **360 / 375 / 768 / 1280 / 1440** 多断点截图核对。
- 结果：各断点布局正常、**无横向滚动**；768 导航折叠汉堡、内容单列堆叠；桌面 1200 容器居中留白。
- **`.ph-h` 移动产品标题缺陷（真实修复闭环）**：
  - 现象：移动产品详情 H1 仍 44px，长型号贴边/裁切。
  - 三层排查：① 中途插入媒体规则时漏写闭合 brace（已补）；② PageCache 旧整页快照（flush 后返回最新 HTML）；③ **CSS 同特异性源顺序 = 真根因**——媒体缩小规则被放在基础规则之前，媒体查询不增加特异性，后出现的基础 44px 规则胜出。
  - 修复：删除靠前媒体行，在基础 `.ph-h`（含 `overflow-wrap:anywhere`）紧后新增独立 `@media(max-width:600px){.ph-h{font-size:26px;line-height:1.2;}}`，媒体匹配时后者胜出；重截确认标题 26px、完整不裁切。
- **响应式契约**：布局响应式 CSS 不随行业预设变化（预设只改视觉 token），故断点行为对 8 预设一致，抽 light/dark + 默认即可，无需 8×断点全组合。
- 已知移动密度项：产品列表系列卡片在窄屏两列（每卡约 165px，不横向溢出但偏窄）→ **DEFERRED v1.1**（移动单列/密度优化）。

---

## 9. Accessibility 基线

- **标题层级**：首页 h1 唯一（hero-title），其后 H2(section)→H3(卡片) 不跳级；产品详情 h1 唯一，H1→H2→H3（搭配项）不跳级。
- **表单**：可见字段均 `<label for="id">` + 控件 `id` 正确关联；本阶段为三个必填字段补 **`aria-required="true"`**（此前仅视觉 `*` + `data-required` 自定义校验，屏幕阅读器无语义）；具备 autocomplete / inputmode / maxlength、字段级错误、蜜罐 `aria-hidden`、成功 `role="status"`、提交防重复。
- **图片**：统一 `components/picture.blade.php`（alt 经属性透传）；内容图有意义 alt、装饰图 `alt=""`；logo 有 alt 且 logo 链接有 `aria-label`；导航 `nav aria-label`。
- **对比度**：ThemePalette 对深色/品牌色保证 AA（≥4.5）；浅色正文近黑 on 近白天然高对比。
- **触达尺寸**：移动 header min-height 62px、主 CTA btn-lg。
- 结论：**无 critical 可访问性 / 响应式缺陷**，v1 基线达成。

---

## 10. Blank System ≠ Demo Site（视觉再确认）

- **空站**（`geo:install -n`，settings=69、entities=0）：首页为行业中性欢迎屏——eyebrow「GEO WEBSITE OS」、标题「GEO Website OS」、空状态引导「在后台创建内容与实体、完成站点设置并发布后，首页将展示你的信息」；导航仅通用「知识中心 / 联系我们 / 关于我们」；无示例制造、无工业涂料/OEM/工厂/产能、无制造业 Hero/统计/CTA。
- **Demo 站**（`db:seed`）：才出现「示例制造有限公司」工业材料数据。
- headless 空站首页（375 / 1280）确认完全行业中性；**Blank System ≠ Demo Site** 视觉边界成立。

---

## 11. 部署缓存清理（本阶段新发现并修复）

- 发现：`geo:upgrade` 流程（backfill → migrate → verify）**未清理旧编译视图与整页缓存**；部署含 blade/CSS 变更的新代码后，旧整页快照持续返回（正是 `.ph-h` 排查中遇到的问题）。
- 修复：`GeoUpgrade::handle()` 在迁移与验证后增加 `view:clear` + `\App\Support\PageCache::flush()`，确保视图/CSS/模板随发布即时生效。
- 验证：`php -l` 通过；upgrade focused（UpgradeBackfillTest + ProductizationAcceptanceTest）**6 passed / 26 assertions / 0**，升级契约不被破坏。

---

## 12. 测试与证据

| 项 | 结果 |
| --- | --- |
| 全量回归（本阶段 Gate 基线） | **857 tests / 4574 assertions / 0 failed / 0 skipped**（最终 Gate 回归结果见 Git 提交时记录） |
| 行业预设契约 | 单文件 6 passed / 60 assertions |
| upgrade focused | 6 passed / 26 assertions |
| Fresh 空站 | settings=69 / entities=0 |
| 深色逐页 | 12 正常页 + 404/403/500/503 同源正确 |
| headless 断点 | 360 / 375 / 768 / 1280 / 1440 无横向滚动 |
| 业务污染（Runtime） | **0**（强身份词；通用行业词按口径 C 保留，见 §13） |

---

## 13. Remaining Risks / DEFERRED（不阻塞 v1.0，转 v1.1）

| 项 | 处置 | 重开 / 验收条件 |
| --- | --- | --- |
| 组件 loading / `aria-busy` 模式 | DEFERRED v1.1 | 建立统一 loading 组件与 aria-busy |
| 产品列表移动系列卡密度（窄屏两列偏窄） | DEFERRED v1.1 | 窄屏单列或密度优化，不横向溢出 |
| example 极简主题（31 行、零设计系统） | 说明项 | example 为"零引擎依赖"极简示范主题；完整设计系统在 default 主题。是否对齐 v1.1 评估 |
| 后台深色模式 | v1 不要求 | 后台外观配置已具备，深色后台 v1.1 |

> 业务污染口径：Runtime 强身份词（Demo Tenant A / Demo Tenant A / 客户域名 / 400 电话 / 具体地址 / 客户 logo 等）= 0；通用行业词（食品 / 制造 / OEM / ODM / 工业涂料 / 胶粘剂 / 工厂 / 车间等，口径 C）无法单独指向原客户，保留于历史 migration / docs/audit / tests 负向护栏 / Example Demo，不作为 Runtime 污染。

---

## 14. Gate 判定

| Gate | 结果 |
| --- | --- |
| Design Token 四层 | PASS |
| Semantic Color / 彩色硬编码清零 | PASS（彩色 hex=0、彩色 rgba=0） |
| Typography 字阶 | PASS（结构性消费 token，缝隙保留并记录） |
| Spacing / Radius / Shadow | PASS |
| Light / Dark / System | PASS |
| Industry Presets（8 类，只改视觉不改 IA） | PASS |
| Custom Brand Seed 派生 | PASS |
| Component State（focus / disabled / aria-required） | PASS（loading v1.1） |
| Global Visual Refactor | PASS |
| Responsive 360–1440（`.ph-h` 已修） | PASS |
| Accessibility 基线 | PASS |
| Blank ≠ Demo 视觉分离 | PASS |
| 部署缓存清理（geo:upgrade） | PASS |
| Runtime 业务污染 | 0 |
| RC / remote / push / Release | 未触碰（HOLD） |

**P-STEP 18D = PASS。** 收尾提交与 annotated tag `checkpoint-18D` 见 Git 段；完成后 STOP，不自动进入 P-STEP 18E。
