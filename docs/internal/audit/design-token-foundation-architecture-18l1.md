# P-STEP 18L-1 — Architecture Decision（Design Token Foundation）

- **阶段**：18L-1（已授权）；范围 **TD-114 / TD-115 / TD-120**
- **基线**：HEAD `2d2fbaf`（checkpoint-18K）；1001 / 5247 / 0 / 0
- **纪律**：Discovery（已完成 DISC-1~8）→ 本 Architecture Decision → Implementation → Real Browser → Regression → Tag → STOP
- **硬边界**：不改 SEO/GEO/Schema/H1 语义/URL/PublicUrl/PublicIndex/Sitemap/Feed；hero 仍是 section/h1/p/a、整页唯一 H1。

---

## 1. Typography Token（TD-115）

### 1.1 四类字阶 + 四属性

字号 token 从 `site.blade :root` 硬编码迁入 `ThemePalette::resolve()`，按 **typography profile** 派生；新增行高 / 字重 / 字距 token。

**字阶（语义命名，消费统一）**：

| Token | 语义 |
| --- | --- |
| `--fs-display` | 全屏 overlay 主标题 / 超大数字 |
| `--fs-h1` `--fs-h2` `--fs-h3` `--fs-h4` | Hero/区块/卡片标题 |
| `--fs-lg` `--fs-base` | 引导段 / 正文根 |
| `--fs-sm` `--fs-xs` `--fs-2xs` `--fs-label` | 导航/按钮、辅助、元信息、eyebrow |
| `--fs-button` | 按钮文字 |

**行高**：`--lh-tight` 1.1 / `--lh-snug` 1.25 / `--lh-normal` 1.5 / `--lh-relaxed` 1.7；另设随 profile 的 `--lh-heading`、`--lh-body`。
**字重**：`--fw-normal` 400 / `--fw-medium` 500 / `--fw-semibold` 600 / `--fw-bold` 700。
**字距**：`--ls-tight` -.02em / `--ls-snug` -.01em / `--ls-normal` 0 / `--ls-wide` .02em / `--ls-caps` .08em。

### 1.2 Typography Profile（新增 `theme_typography`，Site-scoped）

| profile | display/h1/h2/h3 | base | --lh-heading | --lh-body | 气质 |
| --- | --- | --- | --- | --- | --- |
| **standard**（默认） | 3 / 2.75 / 2 / 1.5 rem | 1 | 1.25 | 1.7 | 当前基线 |
| **compact** | 2.5 / 2.25 / 1.75 / 1.375 | .9375 | 1.2 | 1.55 | 工业 / 企业，信息密集 |
| **editorial** | 3.25 / 3 / 2.125 / 1.625 | 1.0625 | 1.12 | 1.8 | SaaS / 品牌，大留白 |

> 其余档（h4/lg/sm/xs/2xs/label/button）在 ThemePalette 内按 profile 显式给出（纯函数、可测）。

### 1.3 zh / en 字体策略（系统字体优先，不下载 web font，无外部依赖）

- `--font`（zh/基础，`theme_font` 可覆盖）：现有 CJK 栈
  `-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Arial,sans-serif`
- `--font-en`：`Inter,"SF Pro Text","Segoe UI",Roboto,Helvetica,Arial,sans-serif`
  （Inter 若系统安装则用，否则按 SF Pro / Segoe UI / Roboto 在 Mac/Win/Android 分别回退）
- `html[lang="en"]{ --font: var(--font-en); }`；body / 标题统一 `font-family:var(--font)`。

---

## 2. Inverse Token（TD-114）

| Token | 值 | 用途 |
| --- | --- | --- |
| `--surface-inverse` | 深色面（DEEP 派生） | hero 深色 / CTA band / footer 等反白底 |
| `--on-inverse` | `#fff` | 反白主文字 |
| `--on-inverse-soft` | `rgba(255,255,255,.9)` | 反白次级文字 |
| `--on-inverse-faint` | `rgba(255,255,255,.62)` | 反白弱化文字 |
| `--inverse-line` | `rgba(255,255,255,.15)` | 反白边框 / 分隔 |
| `--inverse-line-strong` | `rgba(255,255,255,.4)` | 反白强调边框 |
| `--inverse-hover` | `rgba(255,255,255,.10)` | ghost 按钮 / 反白面 hover |
| `--inverse-hover-strong` | `rgba(255,255,255,.20)` | ghost 按钮 hover 加深 |

- inverse token **不随深色模式覆盖**（白字在浅色 / 深色两种模式的深色 inverse 面上都成立）。
- 47 处 `#fff` / `rgba(255,255,255)` 手写逐一替换为对应 inverse token；`rgba(0,0,0)`（mask / text-shadow）保留。

---

## 3. Locale 排版（TD-120）

`html[lang="en"]` 在**组件 token 层**最小覆盖（不改结构），浏览器实测调参：

- `--font: var(--font-en)`；
- 桌面英文 display/h1 字号或容器微调、`--lh-heading` / 标题字距，消除长标题 3 行与 "One" 孤字；
- 按钮最小宽度 / padding、导航项间距按英文词长微调；
- UI 系统串允许 fallback，**公开内容不跨语言 fallback**（沿用 18F）。

---

## 4. 文件改动清单

1. `app/Support/Theme/ThemePalette.php`：resolve() 增 typography（fs/lh/fw/ls + font-en）+ inverse token；新增 profile 派生（纯函数）。
2. `app/Support/Theme/ThemePresets.php`：allowedKeys 增 `theme_typography`。
3. `database/seeders/DefaultSettingSeeder.php`：增 `theme_typography`（select，theme 组，默认 standard）。
4. `resources/views/admin/settings/form.blade.php`：select 分支增 theme_typography 选项。
5. `resources/views/layouts/site.blade.php`：
   - :root 消费新 token、删硬编码字号（L164–175）；
   - h1–4 / body / button 改消费 lh/fw/ls；
   - 47 处白色 → inverse token；
   - 增 `html[lang="en"]` locale 覆盖。
6. 新增 / 更新测试（typography profile、inverse token、locale 排版、字体切换）。

---

## 5. 验收

- 浏览器 zh/en × light/dark：换 profile 排版真实变化、inverse 面无白色硬编码、英文无孤字；
- GEO 回归：Schema / sitemap / llms / entity / canonical / URL / H1 结构不变；
- 全量回归 0 failed / 0 skipped、fresh install、污染 0、日志无新 ERROR、cleanup、tag `checkpoint-18L-1`、STOP（不进 18L-2）。
