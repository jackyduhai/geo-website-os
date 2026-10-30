# P-STEP 18L-4a — Safety Foundation：Final Gate 报告

- 阶段：P-STEP 18L-4a（GEO Template Experience Layer · 安全地基）
- 范围：TD-130（安全扫描 + 平台版本兼容）、TD-134（Template SDK 1.0）、TD-135（Safe Runtime Boundary，P0）
- 基线起点：HEAD `010f1ed` / tag `checkpoint-18L-3b`（1061 tests / 5715 assertions）
- 结论（见 §8）：**PASS / ACCEPTED**

---

## 1. 目标

模板生态在 18L-3 已可文件分发，但来源一旦扩展到官方 / 第三方设计师 / 企业自定义 / AI 生成，
manifest / recipe / defaults 即成为**不可信输入**。18L-4a 不新增渲染能力，只回答：

1. 坏模板能不能在注册 / 渲染前被拦下（安全扫描 + 版本兼容）？
2. 第三方 / AI 模板如何被规范地创建与自检（Template SDK）？
3. 所有 URL 入口是否统一净化，`javascript:` / `data:` 无法落地执行（Safe Runtime Boundary）？

---

## 2. 实现内容

### 2.1 SafeUrl（TD-135，P0）

新增 `app/Support/SafeUrl.php`（final，无外部依赖）：

- `normalize(?string)`：① `html_entity_decode`（ENT_QUOTES|ENT_HTML5, UTF-8）阻断 `&#106;` / `&colon;`
  等实体绕过；② 正则删除控制字符 / tab / 换行 / 零宽 / FEFF / U+2028-2029 / NBSP；③ trim。
- `isSafe(?string): bool`：允许 `http` / `https` / `mailto` / `tel`、protocol-relative `//host`、
  站内 `/path` 与 `#anchor`、无 scheme 相对路径；拒绝 `javascript` / `vbscript` / `data` / `file`。
- `sanitize(?string, fallback='#')`：渲染侧，不安全回退 fallback。
- `validate(?string)`：保存侧，不安全抛 `InvalidArgumentException`。

接入点（保存 + 渲染双侧，fail-closed）：

| 入口 | 接入方式 |
| --- | --- |
| block 渲染：hero / cta（`$bUrl`）、media_text（`$mtBtnUrl`）、contact_info（`$ciMap`） | blade 输出前 `SafeUrl::sanitize(...)` |
| 菜单（主 / 页脚 / children / extra） | `AppServiceProvider::resolveMenuHref()` 统一裁决，危险回退 `#` |
| 菜单后台保存 | `MenuController::validateData()` url 闭包，危险 → 校验错误 |
| 页面按钮保存 | `PageController::extractButtons()` 逐行 `SafeUrl::sanitize(...)` |
| recipe 应用 | `RecipeApplier::localizeUrl()` 开头裁决，危险 scheme **直接 `return '#'`（不加语言前缀）** |

### 2.2 Compatibility Contract（TD-130）

- 新增 `app/Support/Platform/PlatformVersion.php`（final）：五契约键
  `platform` / `theme_api` / `component_api` / `geo_contract` / `seo_contract`，当前均 `1.0.0`。
- `TemplateManifestValidator` 增加版本兼容校验：缺字段 / 非 SemVer / **主版本不匹配** /
  平台版本过低 → ERROR（阻断注册）。
- 8 个模板包 `manifest.json` 的 `requires` 全部写入五版本字段（JSON 已验证合法）。

### 2.3 Template SDK 1.0（TD-134）

- `docs/audit/template-sdk-specification-18l4.md` 为单一契约入口：Manifest / Section /
  Component / Token / Metadata / Compatibility / Migration 规范。
- `template:validate --strict` 可由模板作者自检；本轮在 `TemplateRecipeValidator` 中补充
  **recipe URL 安全校验**：
  - `buttons` 字段逐行检查 `url`（危险 → ERROR「按钮链接含不被允许的协议」）；
  - 键名匹配 `url|href|link` 的文本字段检查内容（危险 → ERROR「链接字段含不被允许的协议」）。
- §6 重写为与真实包一致的**扁平 blocks 结构**（target 对象、block = slot/type/content/sort）。
- 实跑 `php artisan template:validate --strict`：**8 包全部 0 ERROR / 0 WARNING**。

---

## 3. 安全测试矩阵（真实证据）

新增：

- `tests/Unit/SafeUrlTest.php`：**11 方法**（允许 / 危险 scheme、大小写、HTML 实体、嵌入 tab·换行、
  零宽·FEFF、相对 / 锚点 / protocol-relative、空值、sanitize fallback、validate 异常）。
- `tests/Feature/TemplateSafety18L4aTest.php`：**18 方法**，覆盖：
  - A 组 manifest 版本：主版本不符（platform 2.0.0）、平台过低（1.5.0）、缺契约（geo_contract null）、
    坏 SemVer（1.x）、匹配通过；
  - B 组 recipe URL：hero buttons `javascript:`、HTML 实体 `&#106;avascript:`、
    media_text `button_url=data:`、安全 URL 放行；
  - C 组坏 JSON：manifest / recipe；
  - D 组真实 HTTP：landing hero / cta 危险 URL → 输出不含危险串且 `href="#"`；
    `<script>` / `<?php` 内容被转义（`&lt;script&gt;`）不执行；
  - E 组入口：反射调用 `resolveMenuHref` / `localizeUrl` / `extractButtons`，
    菜单后台 POST 危险 URL → `assertSessionHasErrors('url')`。
- 同步修复 `TemplateEcosystem18L3bTest::makeTempPack`（requires 补五版本字段）。

本轮 focused 抓到并修复 1 个真实实现问题：`localizeUrl('javascript:…','en')` 危险 URL 被赋 `#` 后
仍继续走 locale 前缀逻辑返回 `/en/#`；改为危险时**直接 `return '#'`**，重跑全绿。

---

## 4. 全量回归与 Test Inventory Delta

| 口径 | Tests | Assertions | Failed | Skipped |
| --- | --- | --- | --- | --- |
| 18L-3b 基线 | 1061 | 5715 | 0 | 0 |
| **18L-4a** | **1090** | **5778** | **0** | **0** |
| Delta | **+29** | **+63** | 0 | 0 |

- +29 tests = SafeUrl 11 + TemplateSafety 18；测试面**扩大无收缩**，无删除 / 合并既有测试。
- SafeUrl + 4a focused：**29 tests / 59 assertions / 0 failed**。
- 87 条 PHPUnit Deprecations 为 doc-comment metadata 弃用提示（TD-23，已登记 v1.1），非失败。

---

## 5. Fresh install + GEO/SEO 公开契约对拍

全新安装到 `D:\Temp\geo18L4a\database.sqlite`（中性：default settings / blank homepage /
default contact form / system pages，search 0 documents）。

### 5.1 Blank 单语（启用 en 前）

| 路径 | 结果 |
| --- | --- |
| `/` | 200，H1「GEO Website OS」（中性），canonical 正确，inLanguage zh-CN |
| `/contact` → `/contact/` | 301 尾斜杠归一（正常），最终 200 |
| `/sitemap.xml` `/llms.txt` `/feed.xml` `/robots.txt` | 全部 200 |
| `/products/` | 404（blank 无 company，有意设计） |
| `/en` | 404（当时单语，正确） |

### 5.2 启用双语后（同一库）

| 检查项 | 结果 |
| --- | --- |
| `/` | 200 |
| `/en` | **200**，H1「GEO Website OS」，canonical `…/en`，inLanguage **en** |
| `/en/contact/` | **200**（contact_info 经 SafeUrl 正常渲染） |
| 根 hreflang | **zh-CN / en / x-default** 三项齐全 |

结论：SafeUrl / 版本校验接入**未改变正常 URL、canonical、hreflang、inLanguage、feed 等公开契约**；
危险 URL 仅在实际命中攻击串时回退 `#`。

> 工程踩坑（已记录复用）：自定义引导脚本若在 `Kernel::bootstrap()` 之后才 `config([...database...])`，
> 写入可能落到 `.env` 默认库而非目标库。可靠做法是通过环境变量 `DB_DATABASE` 在 bootstrap 前指定，
> 并在脚本内 `DB::purge()` 强制重连。

---

## 6. Runtime 污染与日志

- 强身份词（Demo Tenant A / Demo Tenant A / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Road / 金家街 / 400-001-3770 等）
  在运行时权威目录（app / resources / config / database 运行态）命中 **0**；命中仅在 `docs/audit`
  历史扫描报告（豁免）。
- 验证窗口（本轮 fresh install / curl）**无新增产品 ERROR**；日志中仅 09-25 探索期命令误用历史记录，
  与产品运行时无关。
- Browser / Console：本轮以 HTTP + 渲染断言取证（沿用 18L-3b 浏览器矩阵），无新增脚本错误。

---

## 7. 工程状态

- Worktree：提交后 clean。
- HEAD / tag：见 §8（annotated tag `checkpoint-18L-4a` 与 HEAD 对齐）。
- RC1 tag `v1.0.0-rc1`（965d63c）：**未移动，HOLD**。
- Remote / Push / Release：**未配置 / 未执行，HOLD**。

---

## 8. Gate 裁定

| 验收项 | 结果 |
| --- | --- |
| SafeUrl 统一所有 URL 入口（TD-135，P0） | PASS |
| Compatibility Contract 五契约版本校验（TD-130） | PASS |
| Template SDK 1.0 + `validate --strict`（TD-134） | PASS |
| 恶意模板 / URL 攻击防回归（29 安全测试） | PASS |
| GEO/SEO 公开契约双语对拍不回退 | PASS |
| 全量回归 1090 / 5778 / 0 / 0 | PASS |
| Fresh install（Blank / 双语） | PASS |
| Runtime 污染 0 / 新增产品 ERROR 0 | PASS |
| Worktree clean / annotated tag 对齐 | PASS |

**P-STEP 18L-4a = PASS / ACCEPTED / CLOSED。**

---

## 9. 下一步（未授权，不自动进入）

- **18L-4b — GEO Semantic + Section Composer Lite**：
  - TD-132：BlockType 增 `semantic` 声明，统一渲染层输出受控 `data-section/purpose/entity/conversion`；
  - TD-133 Lite：variant 快切 / Section 语义可见 / 模块增删排序（不做自由拖拽）；
  - TD-131 Lite：block rename / token rename / deprecated warning（完整迁移平台 v1.1）。
- 18L-4b PASS 后，再回到 Release Engineering（19A / 19B / 19C）。
