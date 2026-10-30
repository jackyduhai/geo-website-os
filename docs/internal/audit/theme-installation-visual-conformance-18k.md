# P-STEP 18K — Theme Installation + Visual Conformance Closure

> Gate 文档 · 2026-09-25
> 阶段定位：进入 19A（Private GitHub + Cloud CI）前，补齐「主题作为可发现 / 激活 / 切换 / 预览 / 回滚的产品能力」与「全站视觉一致性（Brand-led）」两类产品级缺口。
> 基线：18D–18J 全部 PASS；本阶段不新增业务 IA，只收口主题生命周期与视觉契约。

---

## 1. 背景与两个缺口

盘点 19A 时发现，18D–18J 的证据只证明了 Token / 预设 / 品牌色 / Light-Dark / SSR 防 FOUC / 多站隔离等**运行时**能力，但：

1. **主题作为「可发现、可激活、可切换、可预览、可回滚」的产品能力未被真实验证**；
2. **前台「蓝 + 绿混搭」**，疑似部分组件绕过 Active Theme Token。

故新开 18K，拆 5 块：01 生命周期、02 全站视觉统一、03 颜色污染反向审计、04 视觉矩阵（Theme × Light/Dark × zh/en）、05 陌生品牌统一视觉测试。

---

## 2. 两项产品裁定

### TD-110 — 主题安装边界 = V1.0「文件分发 + 后台切换」

- V1.0 **不引入** ZIP 上传 / 解压 / 校验 / 动态代码加载。
- V1.0 Theme = 「可分发、可发现、可激活、可切换、可预览、可回滚的**文件系统主题资产**」：
  - 主题 = `resources/themes/{name}/theme.json`（清单 + 可选 tokens/views），随代码文件分发；
  - 「安装」= 放置目录（`ThemeManager::all()` 靠 glob 文件系统发现）；「激活」= per-site 配置切换（setting `theme_active`）。
- **在线上传 ZIP、在线安装、Theme SDK、Marketplace、第三方动态主题代码 → v1.1**（与 Plugin 定位一致，细化已有 TD-18 的主题部分）。

### TD-111 — 配色 = 第三方向「Brand-led + Action-aligned + Accent-controlled」

用户未选 Discovery 的 A（单色统一）/ B（和谐双色），裁定第三方向：

| 角色 | 职责 | 18K 裁定 |
| --- | --- | --- |
| **Brand** | 识别（logo / 链接 / 选中 / 品牌图形 / Hero 少量） | 保持 |
| **Action** | 主行动（按钮 / CTA） | **默认 ≈ Brand 同色系变体，不再默认独立绿**；新增 Action 簇，action=brand |
| **Accent** | 小面积点缀（tag / 数据强调 / 标签 / 装饰） | **降级**，不承担 CTA / 按钮 / 链接 / 标题；默认 accent 绿 `#0E9F6E` → 蓝灰 `#64748B` |
| **Success** | 积极语义 | **固定独立绿**（种子 `#16A34A`，浅色加深 / 深色提亮），不再 = accent |

- CTA 由品牌基色自动派生：`--cta`=mix(brand,黑,4%) 并保证白字 AA、`--cta-dark`、`--cta-soft`、`--cta-on`。
- 视觉权重：Neutral 70–85%、Brand 10–20%、Accent 3–8%；层级主要依靠 typography / whitespace / grid / proportion / imagery / surface / elevation。
- 根因澄清：「蓝 + 绿」**不是组件绕过 Token**（彩色孤立 hex = 0，彩色 100% 走 `var(--*)`），而是 18D「功能色分工」（蓝=品牌、绿=CTA）的设计决策，本阶段做产品修订。

---

## 3. 18K-01..05 验证证据

### 18K-01 主题生命周期（独立 fresh-install 库，真实走通）

库 `D:\Temp\18k-theme\database.sqlite`（`geo:install --no-interaction`）：

| 动作 | 结果 |
| --- | --- |
| 默认 default | brand `#2563EB` / accent `#64748B` / radius 10 |
| 激活 example | brand / action `#7C3AED`、cta `#7738E4`、accent `#A46A07`、radius `16px`（全站变紫） |
| 回滚 default | 恢复默认蓝 / 蓝灰 / radius10 |
| 请求级预览 example | status 200、`X-Theme-Preview: example`、`X-Robots-Tag: noindex,nofollow`、HTML 含紫；预览后激活态不变 |
| 激活不存在 ghost-theme | status **404**、激活态不变（**无半激活态**） |

`activate()` = Setting::set + 重注册视图 + PageCache::flush（不存在返回 false）；`preview()` 不写设置、try/finally 复位、noindex。

### 18K-02 全站视觉统一性

- Demo 库（professional 预设）：20 列表 / 系统页 + 9 详情页扫描，`other={}`、`green=[]`（无绿色按钮面）。
- 主按钮面唯一 `var(--cta)`；正则扫描所有 `.btn` / `.hd-cta` 规则，**无 `var(--accent)` 背景**。

### 18K-03 颜色污染反向审计

- 彩色孤立 hex / rgba = **0**；彩色 100% 消费 `var(--*)`。
- `#fff` / `#000` 仅用于深色反白与 CSS mask（技术常量，豁免）。
- Runtime 权威目录业务污染扫描（app / config / resources / seeders，业务词：Demo Tenant A / Sample Snack / Sample Marinade / Sample City / 400 电话 等）= **0**；唯一命中是 DefaultFormSeeder「**不含** OEM / 原料采购」的中性化说明注释（NON-DEBT）。

### 18K-04 视觉矩阵 Theme × Light/Dark × zh/en

**8 行业预设四组合矩阵全部 PASS**（PowerShell apply + 浏览器 getComputedStyle 对拍 brand hue 与实心按钮 bg hue；offhue 判定 dh>30 且饱和度/亮度阈值）：

| Preset | Brand | Accent（点缀） | Brand hue（light/dark） |
| --- | --- | --- | --- |
| professional | `#2563EB` | `#64748B` | 221 / dark `#4479EE` |
| industrial | `#1D6FA5` | `#D97706` | 204 / `#3D83B2` |
| commerce | `#E11D48` | `#F59E0B` | 347 / `#E53D62` |
| technology | `#4F46E5` | `#0891B2` | 243 / `#7771EB` |
| education | `#7C3AED` | `#06B6D4` | 262 / `#9661F1` |
| lifestyle | `#0D9488` | `#F97316` | 175 / `#0D9488` |
| finance | `#1E3A8A` | `#0D9488` | 224 / `#6D7FB3` |
| healthcare | `#0E7490` | `#14B8A6` | 193 / `#3087A0` |

每个预设下 action / cta 均与各自 brand 同色系（hue 差 ≤15），success 固定绿。

### 18K-05 陌生品牌统一视觉测试（全程 Admin UI，零代码）

Fresh 库，通过后台：站点名改为 **Northwind Labs** → 启用 en → 品牌基色改为品红 `#BE185D` → 不改任何 PHP / Blade / JS / CSS。

zh/en × light/dark 对拍（getComputedStyle）：

| Token | LIGHT（zh/en 一致） | DARK（zh/en 一致） |
| --- | --- | --- |
| brand / action | `#BE185D` | `#CF5487`（提亮） |
| cta | `#B61759` | `#B61759` |
| accent | `#64748B`（蓝灰） | `#707F94` |
| success | `#12863D`（固定绿） | 固定绿提亮 |

- **真实按钮背景**：`.hd-cta` 与 `.btn-primary.btn-lg` 均 `rgb(182,23,89)` = `#B61759`、白字（无绿色）。
- 证明「换品牌 = brand / action / cta 全站一起换，accent 保持中性点缀，success 不变」。

---

## 4. 本轮真实发现并修复的缺陷

| TD | 缺陷 | 根因 | 修复 |
| --- | --- | --- | --- |
| **TD-112** | 后台启用 English（json 字段 `site_supported_locales`）保存 **500** | Controller 提前 `json_encode` 数组、再经 `Setting.value` json cast **双重编码**；`applySetting` 对读回数组 `(string)` 强转触发 Array to string conversion | json 字段全程保持数组（`$value=$decoded`，与 seeder 传数组一致）、校验加 `is_string` 守卫、`applySetting` 加 type 参数并对 json 用规范化 JSON 比较 |
| **TD-113** | color 设置无法在后台清空回退默认 | HTML `type=color` 不支持空值，清空被浏览器重置 `#000000` 并显式保存，致 `--accent` 变黑 | color input 空值用占位灰 `#E5E7EB` + 「回退默认」`clear_color[]` checkbox；Controller color 分支识别占位 / 清除 |
| （CTA AA） | 亮种子（琥珀）加深到白底 AA 后，`onCta` 误用 brand 亮度选字、临界选了黑字，白/黑字均不达标 | CTA mix 黑 4% 处于白 / 黑字临界 | cta 经 `deepenForContrast` 保证**白字 AA**、`onCta = onColor($cta)`（用 cta 自身亮度）；正常深色品牌原样返回 |

防回归测试：
- 新增 `tests/Feature/VisualConformance18KTest.php` — **6 测试 / 116 assertions**（action/cta 对齐 brand、主按钮面唯一 var(--cta)、accent 永不做按钮、8 预设 CTA 同色系、dark、自定义品牌派生）。
- `tests/Feature/SettingsGovernanceTest.php` 新增 2 方法（json locales 数组不双重编码、color 占位 / 清除回退），全文件 **12 测试 / 108 assertions**。

---

## 5. 回归与工程收尾

| 项 | 结果 |
| --- | --- |
| **全量回归** | **1001 passed / 5247 assertions / 0 failed / 0 skipped**（924s） |
| Fresh install（`geo:install`） | 中性 contact form + blank homepage + system pages + search index（0 docs）+ 超管，全 ok |
| Blank HTTP（fresh，默认 zh-CN） | `/` 200、`/en` **404**、`/contact/` 200、`/products/`·`/solutions/` 404（空模块）、`/knowledge/` 200；sitemap/robots/llms/geo/feed 200 |
| Demo HTTP | zh 核心页 + en 正式 URL（带斜杠）全 200（products/solutions/knowledge/contact/search） |
| 品红库 HTTP | `/`·`/en` 200、空模块 404，与 blank 契约一致 |
| Runtime 污染 | **0** |
| 验证窗口（TD-112 修复后）新增产品 ERROR | **0**（最后一条产品 ERROR 10:09:50 即 TD-112 修复前；其余历史 ERROR 为 tinker / probe / bu.js 工具误调用噪音） |
| Worktree | 收尾后 clean |

---

## 6. 裁定

- **TD-110** → DEFERRED v1.1（主题在线安装 / ZIP / SDK / Marketplace）；
- **TD-111 / TD-112 / TD-113** → CLOSED；
- 18K 两个产品级缺口（主题生命周期、全站视觉一致性）已闭环。

### P-STEP 18K = ✅ PASS / ACCEPTED / CLOSED

> 18K 收口后 STOP。v1.0 产品代码未闭合仍为外部发布工程 **TD-01 / TD-02 / TD-03**；是否恢复进入 P-STEP 19A（Private GitHub + Cloud CI）待授权。
> 未移动 v1.0.0-rc1（`965d63c`）、未配置 remote、未 push、未 Release。
