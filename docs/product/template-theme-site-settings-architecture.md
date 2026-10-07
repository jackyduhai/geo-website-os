# GEO Web OS 外观与内容架构：模板 / 主题 / 站点设置

> 面向使用者与二次开发者的**心智模型冻结页**。
> 本文只回答三个反复出现的问题：三者分别管什么、优先级如何、为什么不能后台上传。
>
> 背景：完整架构设计过程见 `docs/audit/template-ecosystem-architecture-18l*.md`、
> `template-safety-model-18l4.md`、`theme-architecture.md`（历史归档，不进发布包）。
> 本文是**面向人的那一层**，不是又一份过程记录。

---

## 1. 一句话结论

```text
GEO Web OS
│
├── 模板 Template                    决定「网站是什么」
│resources/templates/{pack}/
│   栏目 · 页面 · 内容结构 · Schema · GEO 口径
│
├── 主题 Theme                决定「网站长什么样」
│   resources/themes/{name}/
│   色彩 · 字体 · 圆角 · 容器 · 视觉变量
│
└── 站点设置 Site Settings             决定「这个站点具体怎么表现」
    后台 → 站点设置 → 主题外观
    对主题变量逐项覆盖
```

**生效优先级（唯一，不可逆）**

```text
模板（结构）
   ↓
主题（视觉）
   ↓
站点设置（参数覆盖，权重最高）
```

---

## 2. 三者职责对照

| 维度 | 模板 Template | 主题 Theme | 站点设置 Site Settings |
|---|---|---|---|
| 物理位置 | `resources/templates/{pack}/` | `resources/themes/{name}/` | 数据库 `settings` 表（`group=theme`） |
| 载体 | 声明式包（JSON） | `theme.json`（CSS 变量） | 数据库行 |
| 管什么 | 栏目结构、页面骨架、内容配方、Schema、GEO 口径 | 配色、圆角、容器宽度、字号等视觉变量 | 该站点对主题变量的**具体取值** |
| 粒度 | 整套（换行业） | 皮肤 | 单站点 |
| 出厂数量 | 8 个 pack | 2 个（`default` / `example`） | 16 项可调参数 |
| 能否后台上传 | **否**（见 §4） | **否**（见 §4） | 不适用（本来就是后台改） |

### 2.1 站点设置里能覆盖哪些（`group=theme`，共 16 项）

```text
theme_primary         品牌主色（Brand Seed）              color
theme_primary_dark    主色（深 · 高级）                   color
theme_accent          辅色（Accent 点缀）                 color
theme_bg              页面底色                color
theme_surface         卡片底色                            color
theme_text            正文色                              color
theme_text_muted      次要文字色                          color
theme_radius          圆角（px）                          number
theme_container       内容区最大宽度px）                number
theme_density         排版密度                            text
theme_shadow          阴影质感                            text
theme_typography      排版气质（Typography Profile）      select
theme_color_mode      默认外观模式                        select
theme_allow_dark      允许深色模式                        bool
theme_font            自定义字体                          text
theme_custom_css      自定义 CSS              textarea  ← 想完全自定义样式用这项
```

> **`theme_custom_css` 是「不写主题也能自定义外观」的官方出口。**
> 日常微调优先用前 15 项（结构化、可校验、可回退）；
> 只有需要写自定义规则时才动 `theme_custom_css`。

改动这里**立即改变前台观感**，且**不需要动模板或主题文件**——这是日常最常用的杠杆。

---

## 3. 换一个网站长什么样：三种操作

| 想做的事 | 走哪条路 | 影响面 |
|---|---|---|
| 换行业 / 换栏目结构 | 激活另一个**模板 pack** | 页面骨架、内容、Schema、GEO 全部变 |
| 换整体配色风格 | 激活另一个**主题** | CSS 变量变，结构不变 |
| 微调本站主色 / 圆角 / 宽度 | 改**站点设置 → 主题外观** | 只改这几个参数 |
| 写后台才能表达的自定义规则 | `theme_custom_css` | 追加 CSS，不改结构与主题文件 |

前三者互不覆盖：模板不携带样式管线（只 `theme.json` 引用一个已注册主题），
主题不携带内容结构，站点设置不携带代码。

---

## 4. 为什么模板包与主题包不允许后台上传

> **模板包含可执行代码与运行时资源，安装入口属于部署侧，不属于 CMS 运行时操作。**

更准确地说（依据 `docs/audit/template-safety-model-18l4.md`冻结的信任模型）：

| 扩展类型 | 能力边界 | 信任级别 |
|---|---|---|
| **Theme** | `theme.json` tokens（13 键白名单）+ `views/` **可同名覆盖 Blade** | **高信任**（视图级） |
| **Template Package** | **封闭、声明式**：manifest / theme 引用 / recipe / defaults / preview，**无 PHP / Blade / Controller / JS** | **中信任（不可执行）** |

`Theme` 能覆盖 Blade 视图，**它有能力改变系统代码的行为**。若允许后台上传，
等于把「能改视图」的能力开放给任何持有后台账号的人。

因此路由层只有 `index / activate / deactivate / bootstrap / preview`，
**没有 `create / upload / store`**——这是**刻意的架构决策，不是功能缺失**。

### 4.1 模板包允许与禁止清单（V1 冻结）

**允许（纯声明数据）**

```text
manifest.json     identity / purpose / entities / conversion / seo / requires
theme.json        引用已注册主题的 token profile（不自带样式管线）
recipes/*.json    页面 ↔ 槽位 ↔ block 组合声明
defaults/*.json   中性默认值（settings / menus / seo）
preview/*.webp    静态截图（desktop / mobile）
```

**禁止（V1 一律 BLOCK）**

```text
任何 .php / 可执行入口 / .blade.php 自定义视图
新Controller / Route / Middleware 注册；SQL / 迁移脚本
任意 JS / <script> / 事件处理器 / javascript: · data: URL
在 DB 存任意 HTML / Blade
携带行业业务事实作为强制默认（defaults 必须中性）
```

### 4.2 已知实现差距（如实记录）

`TemplateRecipeValidator::inspect()` 当前**只遍历 `*.json`**
（`manifest.json` / `recipes/*.json` / `defaults/*.json`），
**尚未对 `.php` / `.blade.php` 做显式 BLOCK 检查**。

也就是说：「禁止任何 `.php`」这条规则目前由
「出厂 8 个包内全是声明式文件」这一事实维持，
**尚未由代码强制**。

- 现状风险：低（无上传入口，文件只能由部署侧放入）
- 待办：若将来开放第三方包分发，需先补`inspect()` 的扩展名白名单校验
- 关联审计项：`docs/audit/template-safety-model-18l4.md` §4 Validation Pipeline

---

## 5. 我想定制一个模板，该怎么做

按当前 V1 边界（**不走后台上传**）：

```text
1. 复制一个现有包作骨架
   resources/templates/commerce-pro/  →  resources/templates/{你的包名}/

2. 改manifest.json
   id / name / industry / locales / theme（引用已注册主题）
   identity / purpose / entities / conversion / seo

3. 写 recipes/*.json
   页面 ↔ 槽位 ↔ block 组合（只允许已注册的 block 类型）

4. 写 defaults/*.json
   settings / menus / seo —— 必须中性，不带行业事实

5. 放 preview/desktop.webp + preview/mobile.webp（各必需，否则校验报错）

6. 本地跑一次校验
   php artisan tinker
   → App\Support\Templates\TemplateRecipeValidator::inspect(packPath)

7. 后台「模板生态」刷新 → 该 pack 出现在列表 → 激活
```

**约束提醒**：包内**不能出现** `.php` / `.blade.php` / `.js`；
`theme` 字段只能引用**已注册**主题（当前只有 `default` / `example`）。

---

## 6. 常见误解澄清

| 误解 | 事实 |
|---|---|
| 「模板预览长一个样，说明没生效」 | 预览图是**出厂占位图**（8 个包 md5 相同，同一张复制），代码机制正常，缺的是真实截图资产 |
| 「主题管理里没有上传主题」 | 主题 `theme.json` 能覆盖 Blade 视图，属高信任代码资产，**刻意不开后台上传**（见 §4） |
| 「站点设置的主题样式和主题管理重复」 | 不重复：主题定义**变量**，站点设置**逐项覆盖取值**（见 §2.1） |
| 「装了模板包还要在主题管理里激活主题吗」 | 模板 `manifest.json` 的 `theme` 字段会**自动引用**已注册主题；主题管理是给**独立切换视觉**用的 |
| 「后台上传的 Logo 会进 git 吗」 | 不会。`public/img/brand/`、`storage/sites/`、`storage/settings/` 已在 `.gitignore` 中显式排除 |

---

## 7. 一页速查

```text
管内容结构          → 模板 Template
管配色圆角字体      → 主题 Theme
管本站具体取值      → 站点设置 → 主题外观（16 项）
改颜色 / 圆角      → 站点设置（最轻，不用动代码）
写自定义样式        → 站点设置 → theme_custom_css（不用写主题文件）
换行业整套结构      → 模板生态 → 激活 pack
自定义模板          → 放文件到 resources/templates/ + 跑校验（见 §5）
为什么不能上传      → Theme 可覆盖 Blade，属代码资产（见 §4）
预览图都一样        → 出厂占位图，非渲染缺陷（见 §6）
```
