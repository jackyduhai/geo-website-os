# P-STEP 15 — Git History Sanitization Final Report

> 范围：在**保留完整工程演进史**的前提下，用逐对象历史重写移除原客户资产、客户二进制、客户数据库、密钥与强身份标识，使仓库达到可公开发布的清洁度；随后用全新克隆证明安装、回归与 HTTP 链路可复现。
>
> 本报告只记录方法、证据与豁免，不复述任何真实客户身份值。强身份标识在正文中一律以泛化占位符表示。

## 0. 结论速览

| Gate | 结果 |
| --- | --- |
| 原始仓库离线备份 | PASS |
| 历史重写（保留工程史，非单 commit） | PASS |
| 历史完整性（commit/tag/叙事链/fsck） | PASS |
| 客户资产 / 二进制 / 数据库清除 | PASS |
| 强身份去标识（Runtime 与历史文本） | PASS |
| Secret Scan（历史 + 当前树） | PASS（1 个确认为误报） |
| 大对象扫描 | PASS（≥10MB = 0） |
| 历史 commit 抽样可 checkout / 资源存在 / 代码可读 | PASS（9 锚点） |
| 品牌图 IMG-1 字节替换与引用连续性 | PASS |
| Fresh Clone（无 vendor/node_modules/.env/sqlite） | PASS |
| Fresh Composer Install（生产链） | PASS |
| Fresh geo:install | PASS |
| 全量回归（Fresh Clone） | PASS（661 / 3057 / 0 / 0） |
| HTTP Smoke（空站 + Example 目录 + 详情 + 404） | PASS |
| Runtime 业务污染复扫 | 0 |
| CI 定义（PHP 8.4 全链 + Release provenance） | DEFINED，云端首跑 PENDING |
| Release Artifact / Manifest / SHA-256 | 机制就绪，待云端 tag 首跑产出权威值 |
| push / 建仓 / 转 Public / 发 Release | **未执行（无授权）** |

**阶段状态：GEO OS RELEASE CANDIDATE READY（Git 历史已清洗、全新克隆可复现）。**
在 GitHub Actions 云端首跑全绿、并经 Private 仓库人工观察窗之前，**不宣布 PUBLIC RELEASE READY**。

---

## 1. Original Repository Backup

重写前在仓库外建立完整、原样、离线备份（含 `.git`、工作树、全部历史对象与悬空对象），不进入未来公开仓库与发布包。

- 方式：目录级完整镜像复制；文件 27716、失败 0、约 267.9 MB。
- 备份后验证：`git fsck --full`、`git rev-parse HEAD`、`git count-objects -vH` 均通过；HEAD、refs、分支、tag 数量、仓库体积已记录。
- 重写全程**主工作仓与离线备份只读不动**；所有重写在 `git clone --no-local` 生成的独立副本上进行。

## 2. Rewrite Method

- 工具：`git-filter-repo` 2.47.0；秘密扫描 `gitleaks` v8.30.1。
- 方式：在全新克隆副本上执行**逐对象**处理（`--invert-paths` / `--path-glob` 删路径 + `--blob-callback` 做图片替换与文本去身份），**未**使用 `git filter-branch` / 手工 `rebase --root`，**未**压缩为单个 initial commit。
- 结果：**79 个 commit、28 个 tag 全部保留**，`master` 分支与阶段性 Gate/Audit 提交叙事完整；重写后移除了本地 origin；随后 `git reflog expire --expire=now --all` 与 `git gc --prune=now --aggressive`，`git fsck --full` 干净。
- 演进叙事链可追溯：gate1 基线 → step5.5 Entity → step5.6 SeoMeta → P-STEP 01–13 产品化 → sanitize → standardize → bughunt → P-STEP 14 Runtime Closure。

### 2.1 整路径删除（全历史）

- 审计目录中的客户整站压缩包（约 121.5 MB）。
- 审计目录中的客户数据库快照（`*.sqlite`，共 6 个）。
- 客户截图目录（约 60 张）与对照参考目录。
- 客户微信二维码图片。
- 旧客户专属 URL 生成器类（已被通用 `GenericUrlResolver` 取代后的历史残留路径）。

删除后复核：上述路径在全历史 `commits-touching` 计数为 0；12 个客户二进制对象 `git cat-file -e` 均不存在；审计目录下 `sqlite+zip = 0`。

### 2.2 IMG-1：品牌图历史 Blob 替换（保持路径连续）

对 5 张品牌/图标图（`public/img/logo.png`、`public/img/og-default.png`、`public/favicon.png`、`public/favicon.ico`、`public/apple-touch-icon.png`）的**全部历史 blob**，用当前通用 GEO Website OS 品牌图字节替换：

- 只替换图片资产本身；**未**修改任何引用关系、文件路径或页面结构。
- 替换后每条路径在全历史仅剩 1 个通用 blob；旧 commit checkout 后仍可正常渲染且无客户品牌图。
- 端到端字节交叉验证一致：通用图源文件 raw SHA-1 = 任意历史锚点 checkout 出的文件 raw SHA-1 = `git cat-file` 导出 raw SHA-1：
  - `logo.png` c34b8499…、`og-default.png` ad00e27b…、`favicon.png` 0d6146d2…、`favicon.ico` f516ccd2…、`apple-touch-icon.png` c6a73c14…。

### 2.3 文本去身份口径（决策 C）

- 清除/去标识：公司中英文品牌名、客户域名、400 热线（含历史纠错变体）、业务手机号、具体街道门牌号、第三方设计品牌引用；统一落到 `Example` / `example.com` / `400-000-0000` / `13800000000` / `Example Street` / `Generic` 等中性值。
- **刻意保留**：省 / 市 / 区等泛化地名，以及Sample Snack、Sample Marinade、Sample Breading、撒料、调理鸡、鸡排、食品、制造、OEM/ODM 等**无法单独指向原客户的通用行业词**，不为“历史 0 行业词”而过度改写。全历史保留计数（重写后）：Sample Snack 410、Sample Marinade 448、Sample City 113、Sample Province 58。
- 回调对非法 UTF-8 二进制透传，避免破坏非文本对象。

## 3. Secret Scan

- `gitleaks` 对重写后**全部 79 个 commit** 扫描：仅 **1 个 finding，确认为误报**——`config/facts.php` 中 Example 工业产品的 FAQ 参数键值（键名含 `key` 被 generic-api-key 规则命中，值为通用产品 slug `structural-adhesive-a10`，entropy 3.795，非凭据）。
- 真实 `.env`、API key、token、私钥、数据库/邮件/云凭据**从未入库**；当前树 `.env` 不被跟踪。
- 依赖侧 `composer audit` 在全新克隆生产安装中报告 **No security vulnerability advisories found**。

### 3.1 两处误报（FP）存档

1. `config/facts.php` Example 产品 slug（如上），generic-api-key 误报。
2. `docs/audit/baseline-hashes.txt` 两行文件完整性 SHA-256（大写十六进制）中恰好包含 11 位连续数字子串，被手机号正则命中；经逐字符比对确认为哈希片段而非电话号码；另一个疑似号码在重写后全历史已不存在。

## 4. Large Object Scan

- 重写后历史对象中 **≥10 MB = 0**；最大 blob 为 `composer.lock`（约 310 KB）。
- 121.5 MB 客户整站压缩包、6 个客户 sqlite、客户截图全部消失；distinct blob 数量 834 → 742。

## 5. History Integrity（9 锚点抽样 checkout）

用临时 detached `git worktree` 对 9 个历史锚点逐一 checkout 验证后移除（主工作区始终在 `master`）：
gate1 基线、step5 起点、step5.5 Entity、step5.6 SEO、产品化 step10、sanitize、standardize、bughunt、P-STEP 14 HEAD。

每个锚点均满足：

- 5 张品牌图存在且为通用字节（无客户资产）；
- 整路径删除项不存在、审计目录 sqlite+zip = 0；
- `logo.png` 仍被 3 个视图/资源文件引用（引用连续、结构未改）；
- `php -l artisan` 与 `app/Providers/AppServiceProvider.php` 全部通过、无合并冲突标记；
- checkout 后代码可读、资源可解析。

`git worktree list` 最终只剩主工作区，主仓 `master` 干净。

## 6. Fresh Clone & Fresh Install

全新目录 `git clone --no-local`（无硬链接、不带本地开发产物）：

- 克隆后 **无** `vendor/`、`node_modules/`、`.env`、`database.sqlite`；被跟踪的 `vendor/`、`public/build/`、`*.sqlite`、`.env` 计数均为 0。
- 视图**无 `@vite` 引用**，运行不依赖前端构建产物（`package.json`/`vite.config.js` 仅为二次开发保留）。

生产安装链（全新克隆、`composer install --no-dev`）：

- `composer validate --strict`：PASS；
- `composer install --no-dev --prefer-dist --no-interaction`：成功（Laravel v12.69.2，生产包 53 个，post-autoload package discovery 正常）；
- `composer check-platform-reqs`：全部 success（PHP 8.4.25，pdo_sqlite/mbstring/openssl/tokenizer/xml/ctype/json 等齐备）；
- `composer audit`：0 安全公告；
- `cp .env.example .env` → `php artisan key:generate` → 创建空 `database/database.sqlite` → `php artisan geo:install`：
  全量迁移执行（含 sites / entities / entity_relations / seo_metas）、storage 软链连接、默认站点 **GEO Website OS**、管理员 **admin@example.test**。
- 安装器按设计**不装载业务演示数据**（Demo/Example 数据须显式调用）。

### 6.1 Example 目录端到端

显式运行 `CatalogSeeder`（安装期 Example 种子，幂等）投影出 **12 个实体（1 organization / 8 product / 3 service）+ 39 条关系**，全部绑定默认站点，均为通用工业示例（结构胶、环氧底漆、聚氨酯面漆、固化剂/流平剂/增稠剂、装备制造/基建/汽车零部件服务等），无原客户业务。

## 7. Full Regression（Fresh Clone）

在全新克隆上干净切换到完整（含 dev）依赖后执行全量测试：

```
Tests:    661 passed (3057 assertions)
Failed:   0
Skipped:  0
Duration: 78.78s
```

达到并持平 P-STEP 14 基线（661 / 3057 / 0 / 0），未删除测试、未降低断言、未 skip、未改预期。

## 8. HTTP Smoke（真实服务器）

以 `php artisan serve` 起真实服务器，分别在**空站**与 **Example 目录**两态验证：

- 端点：`/`、`/geo.json`、`/sitemap.xml`、`/robots.txt`、`/llms.txt`、`/products/`、产品详情、服务详情、分类页：全部 **HTTP 200**；未知路径返回 **404**（自定义错误页）。
- 空站：首页完整布局（约 107 KB），`<title>GEO Website OS</title>`、canonical 为 `https://` 首页、robots/OG 完整、3 个 JSON-LD；`geo.json`/`llms.txt` 输出合法空骨架并明确“未配置业务事实库、不得推测补全”。
- Example 态：`/geo.json` 增至约 33.6 KB（12 实体 / 关系非空）、sitemap 约 3.5 KB、llms 约 2.5 KB；产品详情 `og:type=product`，含 5 个 JSON-LD（Product、FAQPage、HowTo/HowToStep、BreadcrumbList、Organization、Brand、Answer、PropertyValue 等）。
- 对全部响应体（含 404 页）扫描：**强身份 = 0、原客户食品业务词 = 0、堆栈/异常泄漏（Stack trace / Whoops / SQLSTATE / Symfony / Illuminate 路径）= 0**；服务器 stderr 无错误。`.env.example` 默认 `APP_DEBUG=false`。

## 9. Business Pollution（Fresh Clone 复扫）

- **Runtime（app / config / routes / resources / plugins / scripts / database seeders·factories / public / README / CHANGELOG / LICENSE / composer·package 清单 / .env.example）：强身份 0、食品业务词 0。**
- 豁免区（单列，不要求为 0）：
  - **tests**：命中均为**负向防回归护栏**（关键词黑名单 + “不得包含”断言，如 BusinessPollutionZeroTest 等 13 个文件）与**合成夹具**（`.local` 测试域、example slug、虚构密码、legacy settings key 迁移注释）；3 个含真实身份的早期正向 fixture blob 已在重写中去身份。
  - **database/migrations**：强身份 0。
  - **docs/audit**：仅 3 处审计方法学中的“扫描词表”描述（本目录 export-ignore，不进入发布包）。

## 10. Scope Boundary

本阶段只改动：Git 历史对象、发布工程文件（`.github/workflows/ci.yml`、`release-manifest.json` 模板）与本审计报告。
**未**修改 `app/` Runtime、SEO/GEO/Schema、Resolver、Blade、Controller 等任何业务逻辑，**未**修改测试断言。

## 11. CI 与 Release Provenance

- `.github/workflows/ci.yml`：PHP 矩阵统一为 **8.4**（与 `composer.json` `^8.4`、`composer.lock` 平台要求 ≥8.4.1 对齐；不承诺 8.3）。
- **test job**：checkout → setup PHP 8.4 → `composer validate --strict` → `composer install` → `check-platform-reqs` → `composer audit` → key:generate / touch sqlite / migrate → `geo:install` → `php artisan test --parallel` → HTTP smoke（`/`、`/geo.json`、`/sitemap.xml`、`/llms.txt`、`/robots.txt`）。
- **release job**（仅 `v*` tag）：`composer install --no-dev` → 组装**可部署包**（runtime、生产 vendor、示例插件 `plugins/`、`.env.example`、README/LICENSE/CHANGELOG、前端源）→ 打 zip → `sha256sum` 生成 `.sha256` → **在 zip 之外**生成权威 `release-manifest.json`（`commit=$GITHUB_SHA`、`tag`、`artifact_checksum=sha256:<zip>`、构建时间、CI run id、测试基线 661/3057）→ 随 GitHub Release 上传 zip、.sha256、manifest。
- **自引用规避**：仓库内 `release-manifest.json` 仅为模板（无 BOM、合法 JSON）；权威 manifest 在 CI 于 zip 构建**之后**、于 zip **之外**生成，因此其 `artifact_checksum` 可如实记录该 zip 的 SHA-256，且 `tag target commit == manifest commit == artifact source commit`。
- 旧的 pre-sanitization `v1.0.0-rc1` 仅作为内部归档留存于离线备份；重写后在最终清洁提交上重建 `v1.0.0-rc1`。
- **状态：CI 已定义但云端首跑 PENDING**（未配置 remote、未获 push/建仓/发布授权）。

## 12. Historical / Tests Exemptions

- 历史 migration 与历史审计证据**未**为追求“全仓 0 关键词”而伪造性修改；工程演进史完整保留。
- 测试中的合成品牌词、`.local` 测试域、example slug、虚构密码与 legacy key 注释是**防回归护栏与迁移史证据**，属刻意保留的 Tests Historical Exemption。
- `docs/audit/` 通过 `.gitattributes` `export-ignore`，不进入 release artifact；其中仅含方法学描述，不含真实身份值。

## 13. Remaining Risks

1. **云端 CI 未首跑**：全新克隆已在本地等价复现安装/审计/回归/HTTP，但 GitHub Actions 云端 Runner 的外部可复现性尚待 tag push 后证明。
2. **跨平台 artifact 非确定性**：Linux zip 与本地打包在时间戳/压缩上可能产生不同字节，CI 与本地 SHA-256 不要求相同；但同一次构建中 `manifest.artifact_checksum` 必须等于该次 zip 的 SHA-256，且 commit/tag/version 严格对应。
3. 公开历史的测试代码中可检出 example/旧品牌 slug 的**负向用例与合成夹具**，属防回归设计而非数据泄漏。
4. `docs/audit/` 不进发布包但存在于公开仓库树/历史（仅方法学、无真实身份）；若后续要求公开仓库也不可见，需另行授权移除（当前决策为保留工程史）。

## 14. Final Status

- 工程产品基线（P-STEP 14 Generic Runtime Baseline）在重写后功能不变：Fresh Clone 全量回归 661 / 3057 / 0 / 0，HTTP 全绿。
- Git 历史已无客户资产、客户二进制、客户数据库、密钥与强身份标识，工程演进史与 Gate 叙事完整保留。

**GEO OS — RELEASE CANDIDATE READY（history sanitized；fresh clone reproducible）。**

下一步（需单独授权）：配置 GitHub remote → **先建 Private 仓库** push tag → 云端 Actions 首跑全绿 → 核对 `GITHUB_SHA == tag target == manifest commit == artifact source`、下载 artifact 复算 SHA-256 → Private 人工观察窗通过后再决定转 Public / 发布正式 v1.0.0。当前**未 push、未建仓、未转 Public、未发布 Release**。
