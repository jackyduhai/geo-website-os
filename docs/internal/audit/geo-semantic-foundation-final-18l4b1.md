# P-STEP 18L-4b-1 — GEO Semantic Foundation：Final Gate

> 父 Epic：#116 Page Composition / Template System（v1.0）
> 阶段定位：18L-4（GEO Template Experience Layer）第二阶段的第一个 Gate。
> 对应债务：**TD-132 GEO Section 机器语义（吸收 TD-121）**。
> Gate 结论：**✅ PASS / ACCEPTED / CLOSED**。

---

## 1. 目标与边界

18L-4a 完成了模板生态的**安全底座**（SafeUrl / 平台兼容契约 / Template SDK）。本 Gate 解决 TD-132：

> 页面区块此前只有服务无障碍的 `aria` / `role`，缺少面向 AI / 搜索引擎的**机器可读叙事语义**；AI 无法读出一个区块「在讲什么实体、出于什么沟通目的、引导什么转化」。

本阶段为每个渲染区段建立四个**受控**（非自由文本）语义属性：

| 属性 | 含义 |
|---|---|
| `data-section` | 区块在整页商业叙事中的角色 |
| `data-purpose` | 沟通目的 |
| `data-entity` | 关联实体类型（大写，对齐 Schema 实体词表） |
| `data-conversion` | 转化行为（无转化意图时省略） |

硬边界（全程遵守）：

- 语义为**纯增量属性**，不改变 H1、可见结构、JSON-LD、URL、canonical、hreflang；
- 不引入第二套渲染体系，所有输出仍走 Block Registry → Composition Renderer；
- 值全部来自受控词表，非法值 fail-closed；
- 不复制 Entity 数据进 Page。

---

## 2. 架构与落地

### 2.1 统一语义服务 `SectionSemantic`

新建 `app/Support/Blocks/SectionSemantic.php`（`final`）：

- 受控词表常量：`SECTIONS` / `PURPOSES` / `ENTITIES` / `CONVERSIONS`；
- `DEFAULTS`：27 个 block 类型 → 默认 `[section, purpose, entity, conversion]` 映射；
- `for(BlockType, context)`：config 显式 `$type->semantic` 优先，否则取 DEFAULTS；详情块的 `entity=null` 由渲染 Context 细化；
- `attributes(BlockType, context): string`：输出拼好的 `data-*` 字符串；
- **`forSection(section, purpose, ?entity, ?conversion): string`**：供 system block **内部固定叙事区段**显式声明，经受控词表校验、非法值抛 `InvalidArgumentException`（fail-closed）；
- `buildAttributes(array): string`（私有）：统一属性拼装，`attributes()` 与 `forSection()` 共用；
- `validateDeclared(?array): list<string>` / `validateDefaults(): array`：供模板校验命令自检；
- `entityFromContext(context)`（私有）：从 `context['entityType']` 或 `context['entity']`（Entity 实例按 type 映射 Product / Service）解析实体。

### 2.2 BlockType 与渲染注入

- `BlockType` 增 `public readonly array $semantic`（构造函数），`fromConfig()` 读取 `config/blocks.php` 的 `semantic` 覆盖；
- `BlockRegistry::render(BlockContract, context)` 的 viewData 注入
  `'semanticAttrs' => SectionSemantic::attributes($type, $context)`；
- 全部 **27 个 block blade 根标签**加 `{!! $semanticAttrs ?? '' !!}`；system block 的多个互斥分支逐分支根标签都标注。

### 2.3 system block 内部叙事区段（本轮深化重点）

curl 实测发现：仅标 block 根会漏掉 system block **内部多个叙事 section**（例：product 详情 6 个 section 只有 5 个带 `data-section`）。本轮通过 24 个批量作业，用 `SectionSemantic::forSection()` 一次性补齐全部内部区段：

- `sys_factory`（5）：stats / workshops（trust）、process（solution）、certs（certificate）、coverage（trust）；
- `sys_about`（4）：profile / history / culture cards / culture prose（均 about·brand·Organization）；
- `sys_cooperation`（3）：types（conversion）、process（conversion·education）、faq（faq）；
- `sys_solutions`（1）：scene-grid（solution·Service·consult）；
- `sys_knowledge`（1）：列表区段（faq·Content）；
- `sys_products`（2）：single-line 与按产品线循环（product·Product·contact）；
- `entity_relations`（5）：相邻场景 / 痛点（Service）、推荐组合（**Product**）、相关产品（Product）、适用场景（**Service**）；
- `entity_specifications`（2）：service 反向区段（Service）、product 参数区段（Product）；
- `stats`（1）：可选标题区段（trust·Organization）。

`_bottom_cta.blade.php` 根标签改为**固定** `forSection('conversion','conversion','Organization','contact')`（语义恒定，不依赖继承；同时消除 `sys_solutions` 中 `@include` 漏传参导致的错标隐患）。

落地后逐行复扫所有根 `<section>/<nav>`：**miss = 0**。

### 2.4 模板校验自检

`TemplateValidate` 命令合并 `SectionSemantic::validateDefaults()` 与 declared-semantic 检查（遍历 BlockRegistry 校验每个 `type->semantic`），错误计入总错误并 `exit 1`。实跑 8 个模板包：**0 ERROR / 0 WARNING**。

---

## 3. 受控词表

- **data-section（11）**：hero、about、trust、product、solution、case、certificate、faq、contact、conversion、navigation。
- **data-purpose（5）**：brand、trust、education、comparison、conversion。
- **data-entity（8）**：Organization、Product、Service、Factory、Content、Person、Location、Case。
- **data-conversion（7）**：contact、download、consult、purchase、demo、appointment、signup。

---

## 4. 验收证据

### 4.1 测试

- **Focused**：`SectionSemanticTest`（11）+ `GeoSemantic18L4b1Test`（9）= **20 passed / 165 assertions / 0 failed**。
- 中途 2 个 Feature 断言失败，已**正确修复**（非放宽断言）：
  - 旧断言要求 product 详情不含 Service、service 详情不含 Product；
  - 内部区段标注后，product 页「适用场景」正确引用 Service、service 页「推荐组合」正确引用 Product（跨实体推荐，本属设计内）；
  - 已将两个测试重写为 `..._entities_center_on_product/service`：保留「主体含本类型」断言，并**新增正则把跨实体引用显式锁定为推荐意图**
    （product 页 `data-section="solution" … data-entity="Service"`；service 页 `data-section="product" … data-entity="Product"`）。主体 hero 身份仍由专门测试锁定。
- **Full Regression：1110 passed / 5945 assertions / 0 failed / 0 skipped**。
  - 较 18L-4a（1090 / 5778）增加 20 测试 / 167 断言，恰好等于本轮两个测试文件，测试面增量可解释、无缩减。

### 4.2 独立 demo 库 + curl

独立库 `D:\Temp\geo4b1\database.sqlite`（blank 安装 → DemoSeeder + DefaultFormSeeder + SystemPageSeeder），serve 于 8171：

| 页面 | HTTP | data-section | data-entity |
|---|---|---|---|
| Home `/` | 200 | 9 | 9 |
| Product `/products/epoxy-primer-100` | 200 | 6 | 6 |
| Service `/solutions/equipment-manufacturing/` | 200 | 8 | 8 |
| Factory `/factory/` | 200 | 6 | 6 |
| Cooperation `/cooperation/` | 200 | 5 | 5 |

每个区段 `data-section` 数 = `data-entity` 数，全部标注完整。

### 4.3 真实浏览器（Chrome / bu）

zh/en 六页（zh-home、en-home、zh-product、en-product、zh-service、en-service）：

- **Browser Console = 0 error**（六页一致）；
- 语义区段数：9 / 9 / 6 / 6 / 8 / 8（zh 与 en 一致）。

**Dark Mode**：设置 `localStorage['gwos-color-mode']='dark'` 重载后
`data-color-scheme=dark`、body 背景 `rgb(11,18,32)`、区段数不变、**Console = 0**。Light / Dark 语义输出一致。

### 4.4 缓存现象（已定位，非产品缺陷）

首次浏览器访问出现 2 个 `ERR_CONNECTION_REFUSED`（`http://localhost/img/logo.png`、`/favicon.png`）。定位：`asset()` 生成**绝对 URL 依赖请求 Host**，旧 PageCache 缓存页（早前以 localhost 无端口渲染）中的绝对 URL 指向 80 端口。`page-cache:clear` 后复测 Console = `[]`。生产固定域名 Host 恒定，不受影响；仅 `artisan serve` 动态端口 / localhost vs 127.0.0.1 触发。

### 4.5 Runtime 污染与日志

- 强身份污染词（Demo Tenant A / Demo Tenant A / demo-tenant-ashipit / 400-001-3770 / Sample City / Sample Province / Sample Snack / Sample Marinade / Sample Breading / 撒料 / Sample Road / 金家街 / Sample SaaS）：
  - `app` / `resources` / `config` / `database` 目录：**0 命中**；
  - demo 库与仓库默认库实际内容（PDO 全表扫描）：**CLEAN / 0 命中**；
  - docs/audit、tests 反向护栏、历史 migration 命中属豁免。
- 日志：`2026-09-26`（本轮）**0 条新增 ERROR**；laravel.log 中仅有的 2 条 ERROR 时间戳为 2026-09-25，属历史遗留。
- Runtime pollution = **0**。

---

## 5. 债务裁定

| TD | 状态 |
|---|---|
| **TD-132 GEO Section 机器语义（吸收 TD-121）** | **CLOSED（18L-4b-1）** |
| TD-131 Template Migration Lite | ACTIVE → 18L-4b-3 |
| TD-133 Section Composer Lite | ACTIVE → 18L-4b-2 |

---

## 6. 基线

- 上一基线：HEAD `cf1de31` / annotated tag `checkpoint-18L-4a`（1090 / 5778）。
- 本 Gate：commit 后打 **annotated tag `checkpoint-18L-4b-1`**，与 HEAD 对齐。
- `v1.0.0-rc1`（`965d63c`）**不动（HOLD）**；Remote 未配置、Push / Release 未执行。

---

## 7. Gate 结论

**P-STEP 18L-4b-1 — GEO Semantic Foundation = ✅ PASS / ACCEPTED / CLOSED。**

页面区段从此同时服务于人与 AI：人看到稳定、可访问的视觉区块，AI 与搜索引擎可读出每个区段的叙事角色、实体、目的与转化，且未牺牲任何既有 SEO / GEO / Schema / URL 公开契约。

下一 Gate（需用户授权）：**18L-4b-2 — Section Composer Lite（TD-133）**。本 Gate 完成后 STOP，不自动进入。
