# Business Sanitization Inventory — GEO Website OS

> 阶段：Checkpoint 1（checkpoint-sanitize）前置审计
> 目的：在改动任何代码前，完整盘点从真实企业官网（Sample CityExample Food）继承的业务污染，
> 明确每一处的**类型、是否业务专属、处置动作、状态**，作为语义级清洗（非简单查找替换）的依据。
> 基线 Git HEAD：`60828dc`（P-STEP 14 收尾），working tree clean。
> 扫描方式：UTF-8 PHP/ripgrep 脚本全仓扫描（PowerShell 中文传参不可靠，故不用命令行中文 pattern）。

---

## 1. 扫描词表与范围

### 1.1 业务身份/行业词（Gate 硬指标，Runtime/正式文档必须为 0）

```
Example  Example/example（不分大小写）  Sample City  Sample Province  Sample Snack  Sample Marinade  Sample Road
通用设计参考  Generic/generic
400-000-0000（客户电话）  +86400-000-0000   example.com（客户域名）
Example Street 39（客户注册/生产地址）   年产能  2017（客户成立年，按上下文）
```

### 1.2 食品语义耦合词（语义脱敏参考，用于发现"换行业后仍残留"的耦合）

```
Sample Snack / Sample Marinade / 腌 / Sample Breading / 撒料 / Sample Spice / 鸡架 / 鸡排 / 鸡肉 / 油炸 / 油温 /
主料 / 腌制 / 搅拌 / 车间 / Sample Flavor / OEM / ODM / 代工 / 餐饮 / 门店 / 调料 / 调味 / 食品
```

> 说明：`Sample Snack/Sample Marinade` 同时属于 1.1 硬指标；其余食品词用于识别 Controller/Blade 中
> 按食品工艺写死的逻辑（如按「油炸 / 搅拌 / 适用主料」过滤参数），这些需改为**数据驱动的通用逻辑**。

### 1.3 扫描范围

- 包含：`app/ config/ database/ resources/ routes/ scripts/ public/ tests/ docs/ *.md .env* composer.json package.json release-manifest.json`
- 排除（依赖/构建/运行期）：`vendor/ node_modules/ .git/ public/build/ public/storage/ storage/app storage/framework storage/logs`
- 二进制资产（logo/favicon/og/二维码/字体/图片/视频）单独做**视觉盘点**，不计入文本命中。

---

## 2. 总览：6403 命中分类矩阵

| # | 处置类别 | 命中 | 文件数 | 性质 | 处置动作 |
|---|---|---:|---:|---|---|
| 01 | 构建产物 `dist/`（3 份历史 release 全量副本，889.6 MB） | 4727 | 296 | 历史构建快照，**未被 git 跟踪**（已在 .gitignore） | 删盘，不入库 |
| 02 | 开发参考 `_ref/`、`_shots/` | 67 | 3 | 客户资料附件、客户站开发截图/Blade 备份，**被 git 跟踪** | `git rm` 移出仓库 + .gitignore |
| 03 | Blade 编译缓存 `storage/framework/views/*.php` | 53 | 18 | 运行期产物（未跟踪；目录内仅 .gitignore 入库） | 清缓存，保留目录 .gitignore |
| 04 | 历史审计证据 `docs/audit/` | 921 | 20 | 工程 Gate 历史记录（含本扫描报告自身） | **Historical Audit Exemption，不改写** |
| 04 | `CHANGELOG.md` | 23 | 1 | 历史变更记录（含全部 2 处通用设计参考/Generic） | 历史归档：重置为产品化基线后的干净 CHANGELOG，旧史不进发布包（见 §7） |
| 05 | 历史 migration | 1 | 1 | `2026_09_14_000004_create_facts_table.php` | **Historical Migration Exemption，不改写、不删除**（Upgrade/Rollback 依赖） |
| 06 | 测试 `tests/` | 125 | 27 | 一部分是**反向安全断言（必须保留）**，一部分是客户业务 fixture（替换） | 区分处理，见 §6 |
| 07 | 配置数据 `config/` | 254 | 3 | 客户事实/页面/文案的单一事实源（编译产物） | 重写为通用 Example 数据集 |
| 08 | 数据种子 `database/seeders/` | 92 | 6 | Demo 数据 + 管理员账号 | 重写为通用 Example；admin 通用化 |
| 09 | 应用运行时 `app/` | 57 | 12 | Controller/Service/Support 中硬编码客户 slug、文案、电话、纠错规则 | 去硬编码、数据驱动、通用文案 |
| 10 | 视图 `resources/views/` | 66 | 25 | Blade 中品牌名、页脚电话地址 fallback、错误页标题、食品文案 | 通用化 + 空值安全 |
| 11 | 前端资产 `public/css` | 1 | 1 | admin.css 业务命名/注释 | 通用化 |
| 12 | 构建脚本 `scripts/` | 3 | 1 | compile_facts.php 硬编码Example交付包路径 | 通用化/改示例路径 |
| 13 | 环境模板 `.env*` | 6 | 2 | .env.production.example 客户域名/名称；.env（未跟踪本地） | 通用化 |
| 14 | 发布文档 `DEPLOY.md` | 7 | 1 | 客户专属部署说明 | 重写为通用部署文档 |
| — | **合计** | **6403** | — | — | — |

**产品源码层清洗工作面（类别 06–14，排除历史/构建/缓存）：611 命中、约 78 个文本文件 + 6 个二进制品牌资产。**

---

## 3. 污染类型分层（不混淆）

| 层级 | 含义 | 本仓代表 | 清洗后目标 |
|---|---|---|---|
| **Runtime 业务数据** | 代码运行即输出客户内容 | config/facts.php、pages.php、copy.php；Controller/Blade 硬编码 | 默认 Runtime 输出通用 Example，无任何客户身份/行业专名 |
| **业务默认值** | 缺省 fallback 直接是客户值 | layouts/site.blade.php 页脚电话/地址 fallback；Setting CACHE_KEY=`example.settings`；AppServiceProvider 热线 | 缺省为通用/空（空值整块隐藏），缓存 key 通用化 |
| **Demo/Example 数据** | 显式装载的演示数据 | DemoSeeder 链（Fact/Content/Setting/Structure） | 虚构、行业中性、自洽的 Example 数据集 |
| **测试数据** | fixture/断言 | tests/ 27 文件 | 业务 fixture → Example fixture；反向安全断言保留且加强 |
| **历史审计证据** | Gate/扫描/迁移历史报告 | docs/audit/*.md | 豁免，不伪造修改；不混入 Runtime |
| **历史 migration** | 已执行的数据库迁移 | create_facts_table 等 | 豁免，保证 Upgrade/Rollback 链完整 |
| **构建/临时产物** | release 副本、截图、缓存、附件 | dist/、_shots/、_ref/、编译视图 | 删除/移出仓库 |

---

## 4. 二进制品牌资产盘点（视觉确认）

| 文件 | 现状（已逐一打开确认） | 处置 |
|---|---|---|
| `public/img/logo.png` | Example红绿品牌 Logo（白字「Example」） | 替换为中性 GEO Website OS 占位 Logo |
| `public/favicon.png` | Example Logo 缩略 | 替换为中性占位 favicon |
| `public/favicon.ico` | 同上（多尺寸 ico） | 替换为中性占位 favicon |
| `public/apple-touch-icon.png` | 同源品牌图标 | 替换为中性占位图标 |
| `public/img/og-default.png` | 完整客户品牌社交图：公司全称「Example Food Co., Ltd.」+ 英文名 + 「中式Sample SnackSample Marinade·调理鸡肉 OEM 代工」+ 产能 + 电话 + 域名 | 替换为中性 GEO Website OS 默认 OG 图（无客户任何元素） |
| `public/img/wechat-qr.png` | **客户微信二维码**（真实可扫） | 删除（默认不提供任何二维码；需要时由站点自行上传） |

> 这些不含文本关键词，文本扫描无法发现，属最易遗漏的品牌泄漏，必须在 CP1 全部替换/删除。

---

## 5. Runtime / 配置 / 种子 / 视图逐项清单与 Action

### 5.1 config（254）— 重写为通用 Example

| 文件 | 命中 | 内容性质 | Action |
|---|---:|---|---|
| `config/facts.php` | 164 | compile_facts 产物：公司身份/地址/电话/域名、5 产品体系、31 产品、6 场景、车间/大区/资质、合作、3 案例、违禁竞品名、品牌口号 | 重写为**精简但覆盖全部页面类型**的虚构中性 Example 数据集（见 §8） |
| `config/pages.php` | 62 | narrative、首页/合作/产品/场景 FAQ（按产品 slug 键）、about、factory_steps | 重写为通用 Example 文案，FAQ 按 Example slug 键 |
| `config/copy.php` | 28 | nav/footer/bottomCta/form/states/error404/compliance UI 文案；footer 含客户电话/地址 | 保留 UI 结构，文案通用化；电话/地址缺省为空 |

### 5.2 database/seeders（92）— 通用 Example + 通用管理员

| 文件 | 命中 | Action |
|---|---:|---|
| `FactSeeder.php` | 21 | 17 项Example"已核定事实" → 虚构 Example 企业事实（或仅保留事实表结构的最小演示行）；缺口项结构保留 |
| `ContentSeeder.php` | 30 | Example骨架文章 → Example Article（通用标题/摘要/正文） |
| `SettingSeeder.php` | 28 | 站点设置/主题/联系（电话 400、Example Street地址）→ 通用默认，联系方式留空 |
| `StructureSeeder.php` | 7 | 栏目树/分组/首页区块 → 通用 IA（产品/服务/方案/关于/联系） |
| `DemoSeeder.php` | 3 | 编排器注释与 organization metadata（含Sample SnackSample Marinade knows_about、销售区域拼"地区"）→ Example |
| `DatabaseSeeder.php` | 3 | 管理员 `admin@example.test` / `Example@2026` → `admin@example.com` / 通用初始密码（与 geo:install 对齐） |

> 保持 P-STEP 02 已建立的隔离契约：**geo:install / Core Runtime 不调用 DemoSeeder**；Demo 仅显式装载。

### 5.3 app/ Runtime（57 + 词表外硬编码）— 去硬编码、数据驱动

| 文件 | 命中 | 问题 | Action |
|---|---:|---|---|
| `Services/Geo/LlmsBuilder.php` | 14 | 客户 llms.txt 文案（Sample City/Sample Snack/车间/电话/Example fallback） | 客户文案全部改为**仅由 facts 数据派生**，删除写死的城市/行业/电话/品牌 fallback；保留已有的 `buildGeneric()` 空事实降级 |
| `Http/Controllers/Site/ProductController.php` | 8 | 产品页客户文案/SEO | 文案由数据驱动，SEO 描述通用化 |
| `Http/Controllers/Site/AboutController.php` | 7 | 关于页客户叙述 | 数据驱动/通用 |
| `Http/Controllers/Site/ContactController.php` | 5 | SEO description 写死客户名+电话+地址 | 改为由站点设置/通用模板派生，无联系方式时不输出 |
| `Http/Controllers/Site/FactoryController.php` | 4 | 工厂页客户叙述 | 数据驱动/通用 |
| `Http/Controllers/Site/SolutionController.php` | 4 | 场景页客户叙述 | 数据驱动/通用 |
| `Http/Controllers/Site/KnowledgeController.php` | 3 | 知识中心客户文案 | 通用化 |
| `Http/Controllers/Site/CooperationController.php` | 2 | 合作页客户文案 | 通用化 |
| `Http/Controllers/Site/HomeController.php` | 2（+词表外） | **硬编码 `Facts::product('orleans-801')`、s04Rows 六个客户 slug、paramDifferentiators 食品文案、buildStats 标签"中式Sample Snack调味深耕/年成品产能"、heroParams 按「油炸/搅拌/主料」过滤** | 全部改为数据驱动：hero/参数取"标记为核心的前 N 个产品"，统计标签通用化，工艺参数按结构化字段而非中文食品 label |
| `Services/Gate/ContentGate.php` | 5 | 写死客户纠错规则（Example Street 5→39 号、400-000-0000→3770） | 移除客户专属纠错映射，保留通用合规闸门机制 |
| `Support/SiteCacheKey.php` | 2 | legacy forget 列表含 `example.settings` | 历史缓存清理字符串：随 Setting CACHE_KEY 通用化一并处理（兼容清理保留一个版本周期，注释定性） |
| `Support/HomeBlockDefaults.php` | 1 | 首页区块缺省客户内容 | 重写为通用缺省（与 Example 数据一致） |
| `Models/Setting.php`（词表外，grep 发现） | — | `CACHE_KEY = 'example.settings'` | 改为通用 key（如 `settings:site` 形态，与多站改造对齐），并保留旧 key 一次性 forget |
| `Support/Facts.php`（词表外） | — | `CORE_PRODUCTS` 写死 6 个客户 slug | 改为由数据标志（如产品 `is_core`/`core=true`）驱动，不写死 slug |
| `Providers/AppServiceProvider.php`（词表外） | — | 页脚热线 `tel:+86400-000-0000` 写死 | 改为由站点设置派生，缺省不输出热线项 |

### 5.4 resources/views Blade（66）— 通用化 + 空值安全

| 文件 | 命中 | Action |
|---|---:|---|
| `site/home/hero.blade.php` | 17 | 首屏客户品牌/产品/参数文案 → 数据驱动，空数据时整块优雅降级 |
| `layouts/site.blade.php` | 9 | 页眉品牌名、**页脚电话 `?: '400-000-0000'`、地址 `?: 'Example Street…'` fallback** → 取站点设置，缺省隐藏，不写死客户值 |
| `admin/auth/login.blade.php` | 6 | 标题/品牌、邮箱 placeholder `admin@example.test` → GEO Website OS / admin@example.com |
| `admin/display/blocks.blade.php` | 6 | 区块编辑器客户示例文案 → 通用示例 |
| `admin/layout.blade.php` | 3 | 后台品牌名/标题 → GEO Website OS |
| `admin/contents/form.blade.php` | 2 | 通用化 |
| `site/contact.blade.php`、`site/home.blade.php`、`site/products/index.blade.php`、`site/search.blade.php` | 各 2 | 客户文案/空结果通用化 |
| `errors/{403,404,500,503}.blade.php` | 各 1 | `title_full='暂时无法访问｜Example Food'` 等 → GEO Website OS 通用错误页 |
| `site/about/{history,profile}.blade.php`、`site/factory.blade.php`、`site/knowledge/index.blade.php`、`site/home/{capabilities,cta,params,products,scenes}.blade.php`、`site/_icon.blade.php`、`admin/display/media.blade.php` | 各 1 | 客户文案/示例 → 通用，空值安全 |

### 5.5 其他

| 文件 | 命中 | Action |
|---|---:|---|
| `public/css/admin.css` | 1 | 业务命名/注释 → 通用 |
| `scripts/compile_facts.php` | 3 | 默认输入路径硬编码Example交付包、注释客户名 → 改为占位/示例路径与通用说明 |
| `.env.production.example` | 5 | 客户域名/APP_NAME/站点名 → 通用占位（example.com、GEO Website OS） |
| `.env`（未跟踪本地） | 1 | 本地文件，改 APP_NAME/域名通用（不入库） |
| `DEPLOY.md` | 7 | 客户专属部署说明 → 通用部署文档 |
| 品牌名散落（README、composer.json、package.json、config/app.php、.env.example、release-manifest.json、CLI 输出等） | 词表外 | 统一为 **GEO Website OS**（CP1 先清 Runtime/安装器；CP2 做全站命名一致性收口） |

### 5.6 路由（已核查，无客户 slug）

- `routes/web.php` 栏目路径 `products / solutions / factory / cooperation / knowledge / about / contact` 为**通用 B2B IA**，非客户专属，保留。
- `/scenarios → /solutions` 301 是 IA 迁移兼容（通用词，非客户旧 URL），保留并在技术债中登记。
- 客户业务 slug（`orleans-801`、`fried-chicken-shop` 等）只存在于 facts 数据/Controller 硬编码，不在路由表；随数据重写与 Controller 数据驱动化移除。

---

## 6. 测试（125 / 27 文件）处置原则

### 6.1 必须原样保留语义的"反向安全断言"（守门员，只增不减）

| 文件 | 保留内容 |
|---|---|
| `BusinessPollutionZeroTest.php`（32） | 业务污染零扫描测试本身；随词表更新，**加入新品牌资产/联系方式检查** |
| `GeoInstallTest.php:95`（7） | 断言 geo:install 产物不含 example/Example/Sample Snack/Sample Marinade/Sample City；保留并对齐 admin@example.com |
| `FactsEntityMappingTest.php:300-301`（16） | `assertStringNotContainsString(example/Example)` 反向断言保留；同文件内 example **fixture** 改为 Example |
| `SiteAuthorizationBoundaryTest.php`、`SiteAwareCacheTest.php` | 跨站授权/缓存边界断言保留；其中 admin@example.test、example.settings 等**输入夹具**通用化 |

### 6.2 客户业务 fixture → Example fixture（断言条数/强度不得降低）

`AdminContentTest`、`HomeBlockItemsTest`、`MenuOverrideTest`、`FreshBootTest`、`SiteTest`、`GeoflowApiTest`（"Sample Marinade知识文章"）、`InquiryTest`、`NarrativeSlotTest`、`CopySettingsTest`、`SchemaJsonLdTest`、`SolutionPageTest`、`EntityMetadataSlugLifecycleTest`、`FactsMigrationCompletenessTest`、`GenericDeploymentTest`、`GeoGraphTest`、`GeoIntegrationBoundaryTest`、`HeroModeTest`、`ProductizationAcceptanceTest`、`SeoHeadComposerTest`、`UrlArchitectureTest`、`V0912NavCtaAlignmentTest`、`Unit/UrlGeneratorGoldenTest`、`HomeBuilderTest` 等：
- 客户公司名/产品名/场景名/slug/电话/地址 → Example 对应物；
- 依赖"31 产品 / 6 场景 / 具体 slug"的数值断言，按 Example 数据集新数值更新，但**断言语句数量与覆盖维度不减少**；
- 不得删除测试、不得 skip、不得把 `assert*` 弱化为宽松匹配来换绿灯。

---

## 7. Historical Audit Exemptions（豁免清单，不伪造历史）

以下命中是**工程历史真实性证据**，CP1 不为追求"全仓 0 关键词"而改写；最终扫描时单独列示，不计入 Runtime/发布文档污染：

- `docs/audit/business-keyword-scan.md`（811，本类扫描的历史报告，含词表与样例）
- `docs/audit/` 其余 19 份 Gate/迁移/架构报告（gate1-final-report、p-step14-bug-hunt-report、site-id-migration-matrix、open-source-boundary、dependency-graph、productization-final-acceptance、seo-meta-architecture-matrix、seo-http-integration-5.6-d、entity-architecture-matrix、final-acceptance-report、legacy-seo-source-audit、p0-resolution-versioning、url-architecture-boundaries、baseline-files/hashes、config-business-data、migration-plan、routes-coupling、site-bootstrap-contract）
- `database/migrations/2026_09_14_000004_create_facts_table.php`（历史迁移，Upgrade/Rollback 链）
- `database/migrations/2026_09_15_000014_normalize_terms.php`（旧客户库一次性术语规范化 data migration，含「调理鸡肉 / 半成品」映射；fresh 空表 0 行、仅旧库升级时执行；迁移只进不改，**Upgrade Exemption**）
- `database/migrations/2026_09_17_000002_add_slot_to_contents.php`（`retireContentSlugs/retireCategorySlugs` 含旧英文 slug 如 chinese-marinade/prepared-chicken；fresh 空表 0 行、仅旧库升级；**Upgrade Exemption**）
- 其余历史 migrations 中如出现业务词，同理豁免（迁移只进不改）。
- 注：`2026_09_14_000011/000012/000016/000017` 等 seed/backfill data migration 命中的仅为「车间 / 工厂」等**通用工业词**（新 Example 数据集同样使用），其 fresh 输出已经 fresh-install HTTP 验证为中性工业内容，不属于客户污染，无需豁免。

**CHANGELOG.md 单独处理**：它面向发布，不属于"内部审计证据"。处置为——把产品化基线（GEO Website OS 起点）之后整理为干净、面向产品的 CHANGELOG；
产品化之前的客户开发史不进入发布包（归档到 docs/audit/history 或在发布打包时排除），从而既不伪造、也不向开源使用者泄漏客户历史。最终方案在 CP2 文档清洗时落地，本 Inventory 先行登记。

> 通用设计参考/Generic：全仓仅 CHANGELOG 历史 2 处（Generic Design Menu、通用式主视觉）。Runtime/CSS/设计标准中为 0；
> 产品自有设计标准统一命名为 **GEO Website OS Design System**，不引用 Generic Design。

### 7.1 含真实客户数据的二进制快照（不适用文本豁免，已移除）

文本审计报告适用上文豁免，但**可直接消费的客户数据二进制不属"描述性历史证据"，不得进入开源工作树**。CP1 已 `git rm` 并在 .gitignore 设防：

- `docs/audit/gate1-pre-change.zip`（约 121.5 MB 全站备份，含客户代码/数据库/资源）— 已移除。
- `docs/audit/gate1-pre-change.sqlite`、`docs/audit/step5-pre-migration.sqlite`、`docs/audit/step5-4{b,c,d,e}-pre-migration.sqlite`（6 个完整客户数据库快照，PDO 实测每个 239–246 行，含Example/Sample City/Sample Snack/Sample Marinade/400 电话/Example Street地址/鸡肉/Sample Breading等真实数据）— 已移除。
- 保留：`docs/audit/baseline-schema.sql`（实测 0 条 INSERT、0 客户词，纯 DDL）、`baseline-*.txt`（提交/文件清单/哈希/测试结果文本，属审计叙述）。
- 无任何 Runtime/测试/脚本代码依赖这些二进制（仅 .md 报告文字提及文件名）；Upgrade/Rollback 能力由 migrations 的 `down()` 链保证，不依赖静态快照。

> **发布前必办（历史清理，列为 Release Blocker，不在 CP1 内擅自动历史）**：上述 zip/sqlite 仍存在于既有 git 历史提交中。公开发布（push 到公开仓库 / 打 v1.0.0）前必须重写历史，使客户数据不可被检出——推荐以产品化基线创建全新仓库历史（单一 initial commit，不带客户开发史），或用 git-filter-repo 移除这些路径；同时 release artifact 通过 `.gitattributes export-ignore` 排除整个 `docs/audit/`。当前 `git remote` 为空、尚未公开，具备清理条件。

---

## 8. 目标架构与通用 Example 数据规范（CP1 策略）

### 8.1 不重造架构（边界）

CP1 **不**把旧 Facts 前台切换到新 Entity 体系（那是 P-STEP 14 登记的 Issue B，属后续独立阶段），
也**不**删除 Facts 表/migration、不改 Schema/GEO 的正式数据源契约。CP1 只做：**移除真实客户数据与身份 → 替换为通用 Example → 消除 Runtime 硬编码**。

### 8.2 默认 Runtime 与 Example 的关系

- 旧前台页面（home/products/solutions/factory/cooperation/about/knowledge/contact）当前静态读取 `config/facts.php`。
- 因此 `config/facts.php / pages.php / copy.php` 重写为一份**虚构、行业中性、自洽、精简但覆盖全部页面类型**的内置 Example 数据集（开源 exampleSite 惯例：clone 即可见完整示例站，部署者替换为自有数据）。
- `LlmsBuilder::buildGeneric()` 的"空事实 → 通用骨架"降级保留：部署者清空 facts 时不报错、不编造。
- DemoSeeder（DB 演示数据）与 config Example 对齐为同一套中性 Example 口径。

### 8.3 Example 数据命名规范（与真实客户无语义关联）

- 组织：**Example Organization / 示例组织**（虚构，如 "Acme Example Manufacturing"）；**禁止**保留Sample City/Sample Province/食品/Sample Snack/Sample Marinade/Example任何元素。
- 产品：**Example Product**（通用工业/中性品类，参数 label 通用化：配比/温度/工时，而非油温/腌制/主料）。
- 服务/场景：**Example Service / Example Solution**（通用客户类型，不含餐饮/Sample Snack店）。
- 地点：**Example Location**（example 地域，不对应真实地址）。
- 文章/案例：**Example Article / Example Case**（虚构匿名，不伪装真实客户证言）。
- 域名：`example.com` / `example.test`；电话、邮箱、地址、二维码**默认留空**（触发空值隐藏）；示例邮箱 `admin@example.com`。
- 规模字段（面积/产能/投资/成立年/车间数）用明显的示例占位或留空，不使用客户真实数字。

### 8.4 数据驱动去硬编码

- 核心产品/首屏产品/参数表：由产品数据中的显式标志（core/hero）与顺序决定，删除 `CORE_PRODUCTS` 写死 slug、`product('orleans-801')`。
- 首页统计标签、差异化卖点、工艺参数过滤：通用文案或结构化字段，删除食品行业写死逻辑。
- 联系方式/地址/品牌：一律取站点设置（Site/Setting），缺省隐藏，删除 Blade/Provider 中的客户 fallback。

---

## 9. CP1 验收标准（Sanitized Baseline Gate）

1. 硬指标扫描（§1.1）在以下范围**全部为 0**：Runtime（app/）、config/、database/seeders/、resources/views/、public/（除豁免资产）、scripts/、.env 模板、正式发布文档。
2. 二进制品牌资产：无客户 logo/favicon/og/二维码；默认 OG 图与图标为中性 GEO Website OS。
3. 构建/临时产物：dist/ 删除；_ref/_shots 移出仓库；编译缓存清空；.gitignore 补齐。
4. 默认站点为通用 GEO Website OS / Example，无客户身份、联系方式、地址、行业专名。
5. Example Demo 显式装载可完整演示所有页面类型；geo:install 仍不装载业务 Demo。
6. 历史 migration 与 docs/audit 完整保留（豁免清单），Upgrade/Rollback 链不破坏。
7. 测试：Tests / Assertions **不低于基线 618 / 2625**，Failed=0，Skipped=0；反向安全断言保留并加强；不删测试、不降断言。
8. fresh install（git archive + composer install + geo:install）可启动，前台无客户内容，HTTP 主路径 200。
9. Git：commit + tag `checkpoint-sanitize`，working tree clean。

---

## 10. 风险与边界

- **风险 A（页面空数据报错）**：旧 Blade 部分区块假设 facts 必有值。重写为精简 Example 后需逐页验证空值安全；Example 已覆盖全部页面类型以规避，同时保留空事实降级。
- **风险 B（测试数值断言）**：31 产品/6 场景等断言需随 Example 数据更新；逐条改期望值，保证断言维度不减。
- **风险 C（缓存 key 改名）**：Setting CACHE_KEY 通用化需配合旧 key 一次性 forget，避免部署后读到陈旧缓存；在 SiteCacheKey 兼容清理中处理。
- **不做**：不切 Entity 前台（Issue B）、不删 Facts 表/迁移、不做后台 UI（Issue C）、不大改 UI 视觉、不重造 Core。

---

## 11. 状态跟踪

| 工作块 | 文件数 | 状态 |
|---|---:|---|
| Inventory 扫描与矩阵（本文档） | — | ✅ 完成 |
| 构建/临时产物清理（dist/_ref/_shots/缓存/.gitignore） | — | ✅ 完成 |
| config 三文件重写为 Example | 3 | ✅ 完成 |
| Seeder 六文件通用化 | 6 | ✅ 完成 |
| app Runtime 去硬编码 | 12(+) | ✅ 完成 |
| Blade 通用化/空值安全 | 25 | ✅ 完成 |
| 二进制品牌资产替换 | 6 | ✅ 完成（wechat-qr 删除，logo/favicon/og/apple-touch 替换） |
| css/scripts/env/DEPLOY | 4(+) | ✅ 完成 |
| 测试 fixture 通用化 + 反向断言加强 | 27+ | ✅ 完成（新增 ExampleDatasetIntegrityTest 14 tests/347 assertions） |
| 品牌名 GEO Website OS（Runtime/安装器先行） | 多处 | ✅ Runtime/安装器完成；全站命名一致性收口在 CP2 |
| 全量回归 + fresh install 验证 | — | ✅ 632 tests / 2860 assertions / 0 failed / 0 skipped；fresh install 10 路径 200、全表 0 污染 |
| commit + tag checkpoint-sanitize | — | ✅ 本次提交 |

### 11.1 CP1 收尾实测结论

- **全量回归**：632 tests / 2860 assertions / 0 failed / 0 skipped（85 个为既有 PHPUnit Deprecation 提示，非失败）；较旧绿色基线 618/2625，测试 +14、断言 +235。
- **测试 fixture 二次清扫**：tests/ 内正向客户/食品 fixture 已全部通用化；残留的Example/Example/Sample Snack/Sample Marinade/Sample City等命中**仅存在于反向安全断言词表**（BusinessPollutionZero、ExampleDatasetIntegrity、FreshBoot、GeoInstall、SiteTest、SeoHeadComposer、SiteAwareCache、Facts*、GenericDeployment、GeoIntegrationBoundary、ProductizationAcceptance 等的 `assertNot*` / banned 词表 / 反向密钥检查），是防回归资产，只增不减。
- **fresh install 真实 HTTP**：`geo:install` 产出 Default Site + admin@example.com、不装载 Demo；`/ /products/ /solutions/ /factory/ /cooperation/ /about/profile/ /contact /geo.json /sitemap.xml /llms.txt` 全 200；抓回 HTML 与 fresh DB 全表 PDO 扫描客户身份词/广义食品词 **0 命中**；主题色为新蓝绿（#2563EB/#0E9F6E），旧红绿（e1251b/2e7d32）0 命中。
- **根级正式文档/配置**：README、DEPLOY、release-manifest、composer/package、.env 模板客户身份词 **0 命中**。
- **仍按计划后置（不在 CP1 范围）**：CHANGELOG.md 旧客户开发史的归档/重置（CP2，见 §7）；全站品牌命名一致性收口（CP2）；Issue B（旧 Facts 前台切到新 Entity/Content）、Issue C（新架构后台 UI）为后续独立阶段。

_Historical Exemptions（docs/audit 20 文件、CHANGELOG 旧史、历史 migration）不在清洗工作块内，最终扫描单列。_
