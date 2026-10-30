# D-02 Architecture Decision Lock

> 目的：在实施修复**之前**，把「哪个源是 GEO/AI 事实 SoT」这件事查清楚并锁定，
> 避免又一轮「改了一处、另一处仍然分叉」。
>
> 结论前置：**原D-02 定性需要修正**，且「三个出口全部改走 Fact」与代码中已冻结的
> Schema 设计边界直接冲突，必须先裁决。

---

## 0. 修正后的定性

### 0.1 原判定（错）

> `LlmsBuilder` 走 `Catalog::company()`，**完全不查 facts 表**，
> 属于**绕过 locale 门禁的越权输出**。

**这个说法不成立。** `Catalog::company()` 的数据来自 `organization.metadata['company']`，
该结构**设计上就支持双语**：

```text
organization.metadata.company
├─ 38 个字段
├─ 13 个 _en 变体
│   founded_display_en                = 'June 2014'
│   area_display_en                = 'About 12,000 m2'
│   annual_capacity_display_en        = 'About 1,200 tons of finished products'
│   total_investment_display_en    = 'About 8 million CNY'
│   tech_experience_display_en        = 'Over 10 years'
│   address_en                = '1 Example Avenue, ...'
└─ Catalog::normalizeCompany() 有 $isEn 分支，正确读取 _en 字段
```

所以 `llms.txt` 输出英文事实是**按设计工作**，不是越权。

### 0.2 真实根因

**同一批事实在系统里存了两份，且两份各自完整但覆盖范围不同。**

```text
                    成立时间   厂区面积   年产能   总投资   地址   电话
organization.metadata   ✓✓✓✓      ✓      ✓      ✓✓
facts 表                ✓      ✓      ✓      ✓      占位   占位
                        （占位 = 【待企业提供】且 is_public=0）
```

- `metadata`：38 字段，含 `_en`，地址/电话有值，但属于**实体 JSON 扩展**
- `facts 表`：24 行（18 公开 + 6 待补充），**只有 `zh-CN`**，地址/电话是未提供的占位

### 0.3 设计意图（注释已写明）

`FactSeeder.php:14-15`：

> 这些是演示站点、llms.txt、JSON-LD 三处输出的**共同口径来源**，
> 任何页面不得写出与本表冲突的数字。

**所以 Fact 本应是唯一 SoT，但实现从未真正接线** —— `LlmsBuilder` 与 `SchemaBuilder`
都没读 facts 表。这不是「有人绕过门禁」，是**原设计从未落地**。

---

## 1. Fact 表当前 canonical keys（24 行）

| key | label | group | is_public |
|---|---|---|---|
| FACT-COMPANY-001 | 公司全称 | company | 1 |
| FACT-COMPANY-002 | 品牌名 | company | 1 |
| FACT-COMPANY-003 | 成立时间 | company | 1 |
| FACT-COMPANY-004 | 正式投产 | company | 1 |
| FACT-COMPANY-005 | 注册与生产地址 | company | **0** |
| FACT-COMPANY-006 | 厂区占地面积 | company | 1 |
| FACT-COMPANY-007 | 年产能 | company | 1 |
| FACT-COMPANY-008 | 总投资 | company | 1 |
| FACT-COMPANY-009 | 生产车间 | company | 1 |
| FACT-COMPANY-010 | 销售大区 | company | 1 |
| FACT-COMPANY-011 | 核心产品线 | company | 1 |
| FACT-COMPANY-012 | 技术积累 | company | 1 |
| FACT-COMPANY-013 | 官方电话 | company | **0** |
| FACT-PRODUCT-001 | 产品体系 | product | 1 |
| FACT-PRODUCT-002 | 质量体系 | product | 1 |
| FACT-BIZ-001 | 业务模式 | business | 1 |
| FACT-BIZ-002 | 核心客户群 | business | 1 |
| FACT-BIZ-003 | 服务区域 | business | 1 |
| FACT-BIZ-004 | 起订量 MOQ | business | **0** |
| FACT-BIZ-005 | 交付周期 | business | **0** |
| FACT-SVC-001 | 服务口径 | service | 1 |
| FACT-TRUST-001 | 生产资质 / 认证证书编号 | trust | **0** |
| FACT-TRUST-002 | 执行标准号 | trust | **0** |

**locale 分布：`zh-CN` 24 行，`en` 0 行。**

---

## 2. metadata.company 的 38 字段与 _en 覆盖

| metadata key | _en 变体 | 有 _en | Fact 对应 |
|---|---|---|---|
| `name` | `name_en` | ✓ | FACT-COMPANY-001 |
| `brand` | `brand_en` | ✓ | FACT-COMPANY-002 |
| `founded_display` | `founded_display_en` | ✓ | FACT-COMPANY-003 |
| `established_production_display` | `..._en` | ✓ | FACT-COMPANY-004 |
| `address.full` | `address_en` | ✓ | FACT-COMPANY-005（占位） |
| `area_display` | `area_display_en` | ✓ | FACT-COMPANY-006 |
| `annual_capacity_display` | `..._en` | ✓ | FACT-COMPANY-007 |
| `total_investment_display` | `..._en` | ✓ | FACT-COMPANY-008 |
| `phone` | — | ✗ | FACT-COMPANY-013（占位） |
| `industry` | `industry_en` | ✓ | —（facts 无对应） |
| `business_model` | `business_model_en` | ✓ | FACT-BIZ-001 |
| `target_customers` | `target_customers_en` | ✓ | FACT-BIZ-002 |
| `served_stores_display` | `..._en` | ✓ | FACT-BIZ-003 |
| `tech_experience_display` | `..._en` | ✓ | FACT-COMPANY-012 |
| `area_sqm` / `founded` / `annual_capacity_tons` / `total_investment_wan` / `tech_experience_years` | — | 数值型，无需翻译 | — |
| `short_name` / `website` / `domain` / `phone_tel` / `email` / `served_stores` | — | ✗ | — |

**结论：13 个需要翻译的字段全部有 `_en` 变体，无一缺失。**

---

## 3. 三个AI 出口的当前取数（**这里有冲突**）

| 出口 | 取数 | locale 感知 | 受 Fact 门禁 |
|---|---|---|---|
| `geo.json` facts | `Fact::publicRows($locale)` | ✅ | ✅ |
| `llms.txt` | `Catalog::company()` → organization.metadata | ✅ `_en` | ❌ |
| JSON-LD Organization | `Catalog::company()` + `site.metadata['organization']` | ✅ | ❌ |
| JSON-LD foundingDate | `site.metadata['organization']['founding_date']` | ❌ | ❌ |

## 4. ⚠️ 与已冻结设计边界的冲突

`SchemaBuilder.php:34` 明文写着：

```
* 数据源边界（冻结）：
*   - 主体字段：Entity / Content / Category / Site（正式数据模型）
*   - 站点扩展：Site.metadata['organization' / 'web_site']（通用 JSON 扩展…）
*   禁止：直读业务事实库（Facts / facts 配置）作为 Schema 数据源
```

**这与「所有 AI 出口统一走 Fact」直接矛盾。**

`SchemaBuilder` 的禁令有其道理：Schema.org 的字段集（`foundingDate` / `areaServed` /
`knowsAbout` / `legalName`）与业务事实表（`FACT-COMPANY-*`）**不是一一对应**，
硬映射会产生「schema 里有、页面上没有」的字段 —— 这正是该禁令要防的。

---

## 5. 三个可选架构（需裁决）

### 方案 A · Fact 独占SoT（当前指令的字面含义）

```text
Fact::publicRows(locale) ──→ geo.json
        │
        ├──→ llms.txt        （改写LlmsBuilder）
        └──→ JSON-LD        （违反 SchemaBuilder 冻结边界）
```

**代价**：
- 必须废除或重写 `SchemaBuilder` 的数据源边界声明
- 需为 `foundingDate`（ISO 日期）、`areaServed`（Place 数组）、`legalName` 建立 facts key
- `llms.txt` 输出会明显变少（metadata 独有的 industry / address / website 等不再出现）
- **风险最高**：改的是Schema.org 标准字段映射，出错会让搜索引擎解析异常

### 方案 B · 分层 SoT（推荐）

承认两个源各有不可替代的职责，用**契约**而非**单一表**保证一致：

```text
business facts（成立时间/面积/产能/投资/经验）
    SoT = facts 表
    ├─ geo.json facts✓ 已经是
    ├─ llms.txt       ← 改走 facts
    └─ JSON-LD foundingDate ← 改走 facts（仅此字段）

entity attributes（legalName / address / areaServed / knowsAbout / logo）
    SoT = site.metadata['organization']
    └─ SchemaBuilder 保持不变 ✓ 不违反冻结边界
```

**一致性测试改为「重叠字段校验」**：

```text
for locale in [zh-CN, en]:
    for site in [A, B]:
        # facts 表有值时，llms.txt 与 JSON-LD 的对应字段必须一致
        if facts['FACT-COMPANY-003'] != null:
            assert llms.founded  == facts['FACT-COMPANY-003'].value
            assert jsonld.foundingDate == facts['FACT-COMPANY-003'].iso
```

**优点**：不碰 Schema.org 映射，风险可控；真正消除数值分叉。

### 方案 C · 只加测试锁现状（不修）

**已按你的指示排除**，理由是你说过的：「不能只是让两个出口都返回空」。

---

## 6. 架构裁决（已锁定 · 2026-10-06）

裁决人明确选择 **方案 B · 分层 SoT**，并修正了「所有 AI 出口统一走 Fact」的过度泛化表述。

### 6.1 冻结的三条规则

```text
【规则 1】GEO/AI Fact SoT Rule
  facts 表是「业务事实（Business Fact）」的唯一 Canonical SoT。
  geo.json 与 llms.txt 的事实出口必须经 Fact::publicRows($locale) 取得，
  不得自行读取另一套事实数据源。

【规则 2】Schema Source Boundary（不变）
  SchemaBuilder 不直接读 Facts 作为 Schema 数据源。
  实体属性类字段（legalName / address / areaServed / knowsAbout）
  仍由 organization.metadata 驱动。
  依据：SchemaBuilder.php:34 已冻结此边界，未重过架构评审不得推翻。

【规则 3】Cross-Output Consistency
  SoT 分层≠ 允许事实分叉。
  所有 AI 出口不得产生互相矛盾的事实；同一条事实的取值与标签
  必须可对齐，由契约测试保证。
```

### 6.2 「唯一 SoT」的精确含义

```text
Fact 是「Business Fact」的唯一 SoT，不是整个系统所有数据的唯一 SoT。

Site    └─ site_name / description         （站点配置）
Entity  └─ legal_name / organization 属性（实体身份）
Content └─ page_content                    （页面内容）
Fact    └─ founding_year / factory_area
        └─ annual_capacity / total_investment   ← 只有这类走 Fact

不要把所有数据都塞进 Fact。
```

### 6.3 D-02 重新定性

原表述「llms.txt 绕过 Facts」**不准确**。更准确的表述：

> **P0：业务事实存在多个独立数据源（dual-write），导致 AI 输出存在事实口径分裂风险。**

```text
organization.metadata ┐
                      ├─→ 同一批事实（成立时间 / 面积 / 产能 / 投资）
facts 表             ┘
   ↓ locale 覆盖不同（metadata 有 _en，facts 只有 zh-CN）
geo.json ≠ llms.txt
   ↓
AI truth divergence
```

### 6.4 实施内容

| 项 | 文件 | 内容 |
|---|---|---|
| 标签契约 | `app/Services/Geo/FactLabels.php`（新增） | 17 个公开事实的权威双语标签；`geo.json` 与 `llms.txt` 共用，key 未登记时回落 `Fact.label` |
| 事实出口 | `app/Services/Geo/LlmsBuilder.php` | 新增 `businessFacts()` / `businessFact()` / `businessFactLines()`；中英文段落的数值事实全部改走 Fact contract |
| geo.json 标签 | `app/Services/Geo/GeoGraphBuilder.php` | label 优先取 `FactLabels` 契约，与 llms.txt 同源 |
| 英文数据 | `database/seeders/FactSeeder.php` | 新增 `$rowsEn`（17 条），按 `metadata._en` 逐字段语义映射；待补充项（is_public=0）不补 en 行 |
| 契约测试 | `tests/Feature/Geo/GeoFactConsistencyTest.php`（新增） | 9 测试 / 111 断言 |
| 文档 | `README.md` | 前端构建从「必需」改为「可选」并说明原因 |

### 6.5 契约测试的三个关键设计（变异测试得出）

实施过程中做了两轮变异测试，暴露了三个**会导致护栏失效**的陷阱，已全部修正：

| 陷阱 | 现象 | 修正 |
|---|---|---|
| fixture 无 organization 实体 | `Catalog::company()` 为空 → `llms.txt` 走 `buildGeneric()` 降级分支，**根本不输出事实段** → 测试假绿 | fixture 必须建双语 organization 实体，确保走 `compose()` / `buildEnglish()` 事实路径 |
| `preg_match` 前缀条件断言 | 变异使值格式完全改变时，条件不匹配 → **断言被跳过** → 假绿 | 去掉前缀过滤，对**全部**事实值断言归属格式 |
| 宽松解析 `- x: y` | Markdown 链接行、实体说明类自由文本被当成事实 → 假失败 | 只认 `FactLabels` 契约内的标签；契约通过反射取，**不复制**（复制会与实现漂移） |

变异验证结果（把 `businessFacts()` 改回读 `Catalog::company()`）：

```text
Tests: 4 failed, 5 passed（50 assertions）
失败的正是 4 个跨出口一致性测试，其余 5 个安全类测试不受影响
→ 护栏有牙齿，且不误伤
```

最终态：`Tests: 9 passed (111 assertions)`。

### 6.6 已确认不需要改的部分

| 文件 | 结论 |
|---|---|
| `DEPLOY.md` | **无失准**。全文不提 npm / node，生产部署手册本就正确 |
| `README.md` Requirements 段 | **本来就正确**：`Node.js 20+ / npm (only for building front-end assets)` |
| `SchemaBuilder.php` | 边界声明保留，由契约测试 `test_schema_builder_keeps_its_frozen_source_boundary` 守护 |

---

## 7. Release Decision

```text
修复 + 测试 + 回归 + 重新制品 + 重新安装 + 最终验收
全部完成之前：RELEASE BLOCKED
禁止 tag / push
```
