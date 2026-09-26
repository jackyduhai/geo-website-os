# Dependency License Report（P-STEP 18M-A1/A2）

> 审查对象：GEO Website OS 全部直接与传递依赖（Composer / NPM）
> 审查日期：2026-09-26　·　项目许可证：**MIT**
> 方法：`composer licenses`、`composer audit`、`npm audit`、`package-lock.json` 许可证统计

---

## 1. 结论（Executive Summary）

| 检查项 | 结果 |
|---|---|
| 项目本身许可证 | **MIT** |
| GPL / LGPL **强传染**风险 | **无**（多许可包可选宽松条款，见 §3） |
| 未声明许可证的依赖 | 无 |
| 许可证不兼容的依赖 | 无 |
| 安全漏洞 advisory（Composer） | **0**（No security vulnerability advisories found） |
| 安全漏洞 advisory（NPM） | **0**（found 0 vulnerabilities） |

全部依赖许可证落在 **MIT / BSD-3-Clause / Apache-2.0 / ISC / 0BSD / MPL-2.0（弱传染、构建期）**，均与 MIT 开源分发兼容。

---

## 2. Composer（PHP）依赖

生产 `require` 极简：`php ^8.4`、`laravel/framework ^12`、`laravel/tinker`；其余为 `require-dev`（phpunit / mockery / faker / sail / pint / pail / collision），生产 `composer install --no-dev` 不安装。

许可证分布（直接 + 传递）：

| 许可证 | 代表包 | 兼容性 |
|---|---|---|
| **MIT** | laravel/framework、symfony/*、guzzlehttp/*、nesbot/carbon、monolog、league/flysystem、ramsey/uuid、psr/* | 宽松，兼容 |
| **BSD-3-Clause** | phpunit/*、mockery、league/commonmark、league/config、nikic/php-parser、vlucas/phpdotenv、theseer/tokenizer | 宽松，兼容 |
| **Apache-2.0** | phpoption/phpoption | 宽松，兼容 |

`composer audit`：**No security vulnerability advisories found**（exit 0）。

---

## 3. 需特别说明：`nette/schema`、`nette/utils` 的多许可证

这两个包声明为 **`BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only`**（多许可 / disjunctive license）：

- 使用方**可自由选择其中任一许可证**。选择 **BSD-3-Clause** 即按宽松条款使用，**不触发 GPL  copyleft**。
- 不存在"被迫 GPL"的情形；GPL 选项仅为 Nette 提供的选择之一。
- 结论：**无强传染风险**，可随 MIT 项目分发。

---

## 4. NPM（前端）依赖

`package.json` 仅含 **devDependencies（构建期工具）**：`vite`、`tailwindcss`、`@tailwindcss/vite`、`laravel-vite-plugin`、`axios`、`concurrently`。
运行时主样式内联于 Blade，**前端运行不依赖 build 产物**，故这些包不进入运行时分发。

`package-lock.json` 许可证统计（158 个声明）：

| 许可证 | 数量 | 说明 |
|---|---|---|
| MIT | 136 | 含全部直接依赖 |
| ISC | 6 | 宽松 |
| Apache-2.0 | 2 | 宽松 |
| BSD-3-Clause | 1 | 宽松 |
| 0BSD | 1 | 宽松（零条款 BSD） |
| **MPL-2.0** | 12 | **仅 `lightningcss`**（各平台可选依赖计数），见 §5 |

`npm audit`：**found 0 vulnerabilities**。

> 环境注意：本机默认 registry 为 `npmmirror`，其**不实现 audit endpoint**（报 NOT_IMPLEMENTED）；须使用官方源：
> `npm audit --registry=https://registry.npmjs.org`。

---

## 5. 需特别说明：`lightningcss` 的 MPL-2.0

- 唯一的 MPL-2.0 包是 **lightningcss**（CSS 解析/压缩，Tailwind v4 / Vite **构建期**使用；12 个声明来自其按平台分发的可选二进制包）。
- **MPL-2.0 是文件级弱传染（file-level copyleft）**：仅当**修改 MPL 许可的源文件本身**时需公开该文件修改；不传染仅调用/打包它的其它代码，与宽松/专有代码兼容。
- lightningcss 仅在构建期使用、**不进入运行时分发**，且项目不修改其源码。
- 结论：**无许可证风险**。

---

## 6. 建议

- 无需为 v1.0 做任何许可证整改。
- 发布工程中保留本报告，并在依赖升级后重跑 `composer audit` 与 `npm audit --registry=https://registry.npmjs.org` 复核。
- 若未来引入新依赖，准入前确认其许可证为宽松类或弱传染且仅构建期，避免引入 AGPL / 强 GPL 运行时依赖。
