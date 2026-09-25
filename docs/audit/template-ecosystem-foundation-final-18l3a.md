# P-STEP 18L-3a — Template Ecosystem Foundation + Manufacturing OS Demo：Final Gate

- **阶段**：P-STEP 18L-3a（Template Package Foundation + Manifest + Recipe Engine + Manufacturing OS Demo）
- **基线起点**：HEAD `253180d`（checkpoint-18L-2b）
- **结论**：**PASS / ACCEPTED / CLOSED**
- **下一阶段**：18L-3b（八大 OS / Admin 管理 / Template Preview / SDK 文档 / Template install / Seeder 替换 + Template Metadata）——**未授权，STOP**

---

## 1. 本阶段目标

18K 只完成 Theme Engine「颜色 / token / 切换」基础闭环。18L 把产品从「主题颜色系统」升级为「品牌体验系统 / 平台化模板生态」。18L-3a 首次建立**可分发、可安装、可被第三方创建**的模板生态地基，并用 Manufacturing OS 端到端证明：

> Fresh Install → 启用双语 → 选择 / 应用模板 → 生成首页 / 关于 / 产品 / 联系页面 → 前台可访问，**全程不改 PHP / Blade / JS / CSS**。

**硬边界（已遵守）**：

- V1 Pack 封闭：Pack 只组合**已注册核心 block**，不携带新 PHP / Renderer / Blade / Controller。
- 渲染仍统一走 CompositionRenderer + Render Context；SEO / Schema / GEO / PublicUrl / PublicIndex / PageCache 不产生第二套逻辑。
- 不进入 18L-3b；不碰 19A / remote / push / rc1 / Release。

---

## 2. 交付物

### 2.1 Template Package 物理结构

`resources/templates/manufacturing-pro/`：

| 文件 | 内容 |
|---|---|
| `manifest.json` | id=`manufacturing-pro`、name=`Manufacturing OS Pro`、version=`1.0.0`、industry=`["manufacturing"]`、locales=`["zh-CN","en"]`、theme=`default`、requires.components（10 个已注册 block）、pages=`[home,products,contact,about]`、author、license |
| `template.json` | 顶层 `definitions`：`mfg-home`（extends base，main 枚举 13 普通 block）、`mfg-landing`（extends base，main=`*`） |
| `theme.json` | theme=`default`、darkMode=true；**只引用已安装主题，不携带主题代码** |
| `recipes/homepage.json` | main 7 block；product_grid source=`{"mode":"all"}` |
| `recipes/products.json` | listing + sidebar 2 block |
| `recipes/contact.json` | main rich_text（sort=2） |
| `recipes/about.json` | main 5 block |
| `defaults/{menus,settings,seo}.json` | 18L-3b 才消费（本阶段不接线） |
| `preview/desktop.webp` | 1280×800 占位预览 |
| `preview/mobile.webp` | 390×844 占位预览 |
| `README.md` | 包说明 |

### 2.2 PHP 基础设施（全部 lint 通过）

- `app/Support/Templates/TemplateManifestValidator.php`：`validate()` 返回可读错误列表；校验 manifest 存在 / JSON、id 与目录名一致、name、宽松 SemVer、industry 在 8 Business OS、locales 经 LocaleRegistry、theme 经 ThemeManager、requires.components 经 BlockRegistry、pages 非空；`isValid()`。
- `app/Support/Templates/TemplatePackageManager.php`（平行 ThemeManager）：SETTINGS_KEY=`template_active_pack`，basePath=`resource_path('templates')`；all / exists / invalidPacks / active / activate / deactivate / register / recipes / recipe / resetRequestMemo。
- `app/Support/Templates/RecipeApplier.php`：apply 包在 SiteContext::withSite 内；localesFor / resolvePage / syncBlocks / resolveContent / resolveValue / isLocaleMap / localizeUrl / nextSort / transKey。
- `app/Console/Commands/TemplateList.php`（`template:list`）、`TemplateApply.php`（`template:apply {pack} {--site=}{--site-id=}{--recipe=}`）。

### 2.3 接线（修改）

- `app/Support/Templates/TemplateRegistry.php`：重写为**双来源**（coreDefs + packDefs）；boot / flush / flushPacks / registerPack / all / get / has / selectable / **normalizeSlots**（`*` / block 列表 / 完整 `['blocks'=>]` 归一化）。
- `app/Support/RequestScopedState.php`：flushAll 加 TemplatePackageManager::resetRequestMemo；reapply 在 Theme/Plugin register 后加 TemplatePackageManager::register。
- `app/Support/Render/SystemPageRenderContext.php`：blocksForSlot 非 contact match 增加 **sidebar** 分支（仅 listing 系统页 products/solutions/knowledge 读 Page 持久化 sidebar blocks）。

### 2.4 防回归测试

`tests/Feature/TemplatePackage18L3aTest.php`：**16 方法 / 88 assertions，全绿**。

---

## 3. 测试首轮抓到的真实问题（已修复 + 防回归）

1. **Recipe source 类型错误 → 500**：BlockRegistry::productCards 期望 source 为 array（含 `mode` 键），homepage recipe 原写字符串 `"source":"all"` 致 TypeError 500 → 改为 `"source":{"mode":"all"}`。
2. **Blank 夹具缺 company → /products/ 404**：ProductController index `abort_if(empty(Catalog::company()),404)`，blank 夹具无 organization → 测试加 `makeCompany()` 辅助（最小双语 organization，metadata `is_site_organization=true`）。

---

## 4. 验证证据

### 4.1 自动化回归

| 项 | 结果 |
|---|---|
| Focused（TemplatePackage18L3aTest） | **16 方法 / 88 assertions / 0 failed** |
| **Full Regression** | **1044 tests / 5526 assertions / 0 failed / 0 skipped** |
| PHPUnit Deprecations | 87（非失败，框架弃用提示） |
| Test Inventory Delta | 较 18L-2b（1028/5428）**增 16 tests / 98 assertions**，与新增 16 测试方法吻合，**无测试面缩减** |

### 4.2 Fresh Install 端到端

- 新建空 sqlite → `geo:install --admin-password=Admin@123456`：38+ migration、DefaultSetting / BlankHomepage / DefaultForm / SystemPage seeder、search:reindex（0 docs）、admin 全部成功。
- 启用 zh/en（site_supported_locales=[zh-CN,en]）。
- `template:apply manufacturing-pro`：
  - about：pages=2，created=10
  - contact：created=2
  - homepage：created=14
  - products：created=4
  - locales=zh-CN,en
- 创建双语 company（Entity id=1，organization，metadata is_site_organization=true）。
- serve：**127.0.0.1:8151**。

### 4.3 HTTP 状态码对拍（全 200）

| 路径 | zh | en |
|---|---|---|
| 首页 | `/` 200 | `/en` 200（`/en/` 301 尾斜杠） |
| 产品列表 | `/products/` 200 | `/en/products/` 200 |
| 联系 | `/contact/` 200 | `/en/contact/` 200 |
| 关于 | `/about-company` 200 | `/en/about-company` 200 |
| Sitemap | `/sitemap.xml` 200 | `/en/sitemap.xml` 200 |
| llms / feed / robots | `/llms.txt` 200、`/feed.xml` 200、`/robots.txt` 200 | — |

### 4.4 首页 SEO 对拍

- zh：`<html lang="zh-CN" data-theme="default" data-color-scheme="light">`；canonical=`http://127.0.0.1:8151/`；hreflang zh-CN / en / x-default（x-default→`/`，双向）；语言切换 EN→`/en/`。
- en：`<html lang="en">`；canonical=`…/en`；hreflang 双向；x-default→`/`；Chinese→`/`。
- **Schema inLanguage**：zh 页 = `zh-CN`、en 页 = `en`。

### 4.5 PageCache locale 隔离往返对拍

连续 zh→en→zh→en 四请求：Req1/3 hero 中文（「值得信赖的制造合作伙伴」）、Req2/4 hero 英文（「Your Trusted Manufacturing Partner」），**无串页**。

### 4.6 Multi-Site × Locale

- Site B（id=2、slug=site-b、domain=site-b.test、supported=[en]、default=en）：9 en pages / 0 zh；en organization（slug=site-organization）。
- curl `-H "Host: site-b.test"`：
  - B `/` 200（根按 default locale=en）、B `/en` 200
  - B `/products/`（zh）**404**、B `/contact/`（zh）**404**
  - B `/en/contact/` 200、B `/en/products/` 200（加 company 后）
- Site A（default host）中英全 200。
- **B sitemap**：8 loc 全部 `http://site-b.test/en/...`，不含 A、不含 zh。
- **B llms.txt**：英文、Example Company B、Website `http://site-b.test/en`，不串站。

### 4.7 Search locale 对拍

- 创建双语 core product（slug=example-product，metadata **`core`=true**）；reindex=**2 documents**。
  - 排障：夹具一度误写 `is_core`（正确键为 `core`），致 PublicUrl::entity 返回 null、reindex 0；修正后 2 docs。这是验证夹具问题，非 18H-1 产品回归。
- 中文 `/search?q=示例`：`<h2><mark>示例</mark>产品</h2>`，链接 `/products/example-product`，中文 summary。
- 英文 `/en/search?q=example`：`<h2><mark>Example</mark> Product</h2>`，链接 `/en/products/example-product`，英文 summary。
- 中文结果不含英文、英文结果不含中文；`<mark>` 高亮经安全处理；CJK 分词、site/locale scope、DB 层分页均成立。

### 4.8 Runtime 污染 / 日志

- **Runtime 强身份词污染扫描**：数据库全表全列 + 前台落盘 HTML/XML/TXT，均 **0 hits**（Demo Tenant A / Demo Tenant A / Sample City / Sample Province / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Road / 金家街 / 400-001-3770 / admin@demo-tenant-a.local / Sample SaaS）。
- **日志审计**：laravel.log 唯一 ERROR 为 **2026-09-25 15:15:53** 的 CLI 误调用（`--store option does not exist`，历史时间、非产品运行态）；**本轮验证窗口（09-26 serve）新增 ERROR = 0**。

---

## 5. 债务裁定

| TD | 状态 |
|---|---|
| **TD-117** 模板非可分发 Package / 缺 SDK | **CLOSED**（PackageManager + ManifestValidator + 物理包） |
| **TD-118** 模板不携带 recipe / 无行业目录 | **CLOSED**（RecipeApplier + 4 recipe + template:apply） |
| TD-119 排序无拖拽 | ACTIVE → 18L-4 |
| TD-121 区块语义 metadata | ACTIVE → 18L-4 |

**保留观察（18L-3b 处理）**：

- Pack 新模板 key（mfg-*）的 system block 准入：system block 与核心模板 key（detail/listing/contact）硬绑定，mfg-* 无法获得 entity_*/sys_*；当前所有 recipe 复用核心模板 key（home/listing/contact/landing），mfg-home/mfg-landing 仅作额外包模板，方向正确，18L-3b 需在「V1 封闭、不造第二套」前提下最终收口。
- defaults/（menus/settings/seo）本阶段不消费，18L-3b 接线。
- Template Metadata（purpose / entities / conversion）18L-3b 增加。
- 两份 18L-3 Discovery 文档写于用户增强裁定之前，18L-3b 按用户最新 Package/Manifest 字段与 Business OS 8 类口径同步。

---

## 6. PASS 条件核对

- [x] Full Regression PASS（1044 / 5526 / 0 / 0）
- [x] Focused PASS（16 / 88）
- [x] Fresh Install PASS
- [x] template:apply 四 recipe 成功、双语
- [x] 核心 HTTP 中英 200
- [x] 首页 SEO / canonical / hreflang / x-default 正确
- [x] Schema inLanguage 正确
- [x] PageCache locale 隔离
- [x] Multi-Site × Locale（含 sitemap / llms 跨站）
- [x] Search locale 隔离 + 安全高亮
- [x] Runtime 污染 = 0
- [x] 验证窗口新增 ERROR = 0
- [x] 无业务 / 品牌 / URL / SEO / GEO 新增写死
- [x] V1 Pack 封闭（无新 PHP/Renderer/Blade/Controller 入包）
- [x] Worktree CLEAN（commit + tag 后）

---

## 7. 最终状态

- **HEAD**：与 annotated tag `checkpoint-18L-3a` 对齐
- **Tag**：`checkpoint-18L-3a`（annotated，与 HEAD 对齐）
- **Regression**：1044 / 5526 / 0 failed / 0 skipped
- **Worktree**：clean
- **rc1**：`v1.0.0-rc1` = `965d63c`，**HOLD（不动）**
- **Remote**：未配置；**Push**：未执行；**Release**：HOLD

**P-STEP 18L-3a = PASS / STOP。** 不自动进入 18L-3b / 18L-4 / 19A。
