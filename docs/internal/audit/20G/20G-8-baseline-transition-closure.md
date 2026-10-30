# 20G-8 Baseline Transition & Port Closure

**定义日期**：2026-10-03
**性质**：基线切换后的定向重验（非重新审计）

---

## 零、基线切换声明

### 旧基线已退出

```
D:\734666\GEO OS
HEAD 91aef17（20F.1 release hardening closure）
状态：目录已删除，仅存备份 D:\GEO-OS-BACKUP-20261003\
```

### 新基线（唯一权威）

```
D:\GEO-OS-rewrite\geo-website-os
HEAD fd660b0
remote git@github.com:jackyduhai/geo-website-os.git
提交数 164
app/ 195 个PHP 文件
迁移 55 个
```

### 不是 merge，是能力移植 + 基线重建

两库 **无共同祖先**（root commit `64d074f` vs `0ed7402`），无法 `git merge`。
本次采用「逐能力移植 + 全量回归」，因此：

> **20G-0~20G-7 在旧基线上的 PASS 结论降级为「历史证据」，不作为新基线的验收依据。**

### C-9 状态变更（重要）

C-9 原本是「v1.0 是否支持英文」的**产品决策**。
基线切换后该问题**已消失** —— 英文能力（`/en` 路由、`SetLocale`、`Translatable`、
六表 `locale` 列、双语包 294/294、GEO 派生层 `forLocale` 隔离）随 `fd660b0` 一并进入新基线。

```
C-9：SCOPE DECISION（待产品定义） → CLOSED（产品已定义：Multi-site + Multi-language）
```

C-9 转化为 **20G-3 的实施内容**：现有 i18n 能力的完整性缺口。

---

## 一、1370 全绿到底覆盖了什么（必须说清）

新基线全量回归结果：

```
Tests: 1370, Assertions: 7208
FAILURES: 0    ERRORS: 0
```

**已覆盖**（旧 Gate 的核心链路，全部在新基线上真实重跑过）：

| 旧 Gate | 对应测试 | 新基线状态 |
|---|---|---|
| 20G-1 Security / Rendering | `MarkdownRenderSecurityTest`（9 用例 / 107 断言） | ✅ 重跑通过 |
| 20G-2 GEOFlow Contract | `GeoflowHashTest` / `PayloadValidation` / `NullableSemantics` / `StateSafety` / `ResponseContract` / `Concurrency` / `AuthAndSite` | ✅ 重跑通过 |
| 20G-4 Derived Output | `DerivedOutputIntegrityTest`（6 用例） | ✅ 重跑通过（并修掉 lastmod 编造） |
| 20G-6 Migration 保真 | `MigrationRollbackFidelityTest`（5 用例） | ⚠️ 部分，见第三节新盲区 |
| 多语言 | `Localization18FTest`（10 用例 / 55 断言） | ✅ 通过 |
| Site × Locale | `docs/audit/20G/e2e/i18n-merge-check.php`（38 断言，真实 HTTP） | ✅ 通过 |

**未覆盖**（20G-8 必须定向补）：

1. **真实 HTTP 层的 Site × Locale 四象限**（A/zh、A/en、B/zh、B/en）
   —— `i18n-merge-check` 覆盖了 A/zh + A/en + B/zh 的**抽样**，但未做
   **same slug + different content canary 在四象限全组合下的互不污染证明**。
2. **迁移回滚对 i18n 列的保真度**（见第三节，这是新基线最大新增风险）。
3. **旧 20G-5 双站 E2E 的 30 条断言**在 `fd660b0` 上未重跑。
4. **UX-001 / UX-002**（站点管理、新站初始化）—— 已由你正确定级为 P1 Product Capability。

---

## 二、20G-3 定级修正（基于实测，两处与你建议不同）

### 修正 1：Facts —— P1 成立，但影响面比你描述的窄

你给的链路成立，已实测确认：

```
/en/geo.json → GeoGraphBuilder::facts() → $f->label / $f->value → 中文进入英文 GEO
```

代码位置：`app/Services/Geo/GeoGraphBuilder.php:93-101`，
`Fact::publicRows()` 全库仅此一处调用。

**但实测补充一个事实**：前台模板与 `Site` 控制器**完全不读 `Fact`**
（`grep -rn "Fact::" resources/views/site/ app/Support/Render/ app/Http/Controllers/Site/` → 0 命中）。

这意味着：

| 影响面 | 状态 |
|---|---|
| 人类可见英文页面 | **不受影响**（不渲染 facts） |
| `/en/geo.json`、`/en/llms.txt` 等 AI 面向输出 | **受污染** |

结论不变，仍是 **P1 Release Blocker** —— 但理由要精确：
> 它的危害不在「访客看到中文」，而在 **AI 检索到的英文站点事实是中文**，
> 这直接损害 GEO 产品的核心价值主张（AI 侧事实抽取的准确性）。
> 且这属于「数据正确性」，不是「扩展性问题」。

**实现方向**：不要复制 `label_en`/`value_en`（你已正确指出）。
应把 `facts` 纳入既有 `Translatable` 行级模型（同表多行 + `translation_group`），
理由是避免第三套双语机制分叉。

### Facts 改造的实施约束（实测发现，需提前设计）

`facts` 比 Content/Entity 复杂，改造前必须处理三处硬约束：

| 约束 | 现状 | 影响 |
|---|---|---|
| **`key` 是全局 UNIQUE** | `$table->string('key', 80)->unique()` | 同一 key 无法存两行（zh + en）。必须改为 `UNIQUE(site_id, key, locale)` |
| **`publicMap()` 按 key 建索引** | `pluck('value','key')` | 多语言行会**随机命中某一语言**。必须加 `forLocale()` 限定 |
| **`publicMap()` 在 View composer 全站注入** | `AppServiceProvider:136` `$view->with('publicFacts', ...)` | 影响所有页面模板，改错会全站污染 |

迁移方式不能沿用 Content 的「建新表→拷贝→改名」简单路径，
因为 `key` 的全局唯一约束必须先降级，且需要按 `key` 把存量行归组
（同一 key 的 zh 行作为 anchor 生成 `translation_group`）。

建议实现顺序：
1. 迁移：`key` 唯一约束降级为 `(site_id, key, locale)`，加 `locale` / `translation_group`
2. `Fact` 模型引入 `Translatable`，声明 `$sharedTranslatableColumns`
   （共享：`is_public` / `group` / `source` / `owner` / `reviewed_at` / `review_due` / `sort`；
   独立：`label` / `value`）
3. `publicMap()` 与 `publicRows()` 加 locale 限定
4. `GeoGraphBuilder::facts()` 改用 `forLocale(LocaleContext::current())`
5. 后台补 Fact 的翻译行维护入口

### 修正 2：Settings —— 不是 P1，暴露面比预估小得多

你担心「Settings 整表加 locale 会制造重复事实源」，这个顾虑成立。
但实测显示当前暴露面**比预期小得多**：

英文路径下实际读取 Setting 的地方只有：

```
GEO 层：geo_org_name / geo_org_en_name / site_default_locale   （3 个）
前台模板（site/ + layouts/）：16 个键
```

前台 16 键按性质分类：

| 类别 | 键 | 是否需 locale |
|---|---|---|
| 语言开关 | `site_default_locale` | **否**（本身是语言选择器） |
| 主题/外观 | `theme_color_mode` `theme_allow_dark` `theme_custom_css` `geo_org_logo` | **否** |
| 法务/标识 | `icp_number` `police_number` | **否**（中国备案，英文站仍应显示） |
| 联系信息 | `contact_phone` `contact_mobile` `contact_wechat_qr` `contact_address` | `contact_address` **是**；前三者**否**（号码本身不翻译） |
| **可翻译** | `site_name` `site_description` `brand_display_name` | **是** |
| 技术标记 | `seo_head_code` | **否**（见下） |

即 **16 键里只有 4 键真正需要 locale**：`site_name`、`site_description`、
`brand_display_name`、`contact_address`。

**修正定级**：
```
Settings localization  →  P2（不是 P1）
理由：仅 4/16 键受影响，且当前英文输出未报错、只是显示中文。
     但其中 site_name / site_description 直接进 <title> 与 meta description，
     影响 SEO 与 AI 理解，故不能无限期延后。
```

实测证据（`resources/views/layouts/site.blade.php:1479-1480`）：
`site_name` 确实作为 `<title>` 回退值输出 —— 这是 P2 而非 INFO 的原因。

**`seo_head_code` 不需要 locale**：它已被 `HeadCodeSanitizer` 收敛为
仅允许 `<meta>` / `<link>` 的技术标记（P-STEP 18H-3 / TD-90），
本身就是语言无关的运维注入点。

**实现方向（认同你的 Setting Field Contract）**：
```
key → scope(site) → localized(bool) → public(bool) → geo_derived(bool)
```
先落契约，再按契约只对 5 个键做 locale 化，避免给整表加 locale。

### 修正 3：Categories / Groups —— 同意你的 INFO 定级

实测 `Catalog.php` 有 30+ 处 `*_en` 字段回退，覆盖 `name_en`/`desc_en`/`title_en`/
`image_alt_en`/`sales_regions_en`/`pain_points_en` 等。这是**字段级双语言模型**，
对 zh + en 完全够用。

```
Categories / Groups *_en  →  INFO / Architecture Debt（不阻塞 v1.0）
```
理由：未造成当前 zh/en 任何功能错误，仅影响未来加ja/ko/de/fr 时的扩展成本。

---

## 三、新盲区（你未提及，但风险高于 Settings）

### 迁移回滚测试对 i18n 列零覆盖

`MigrationRollbackFidelityTest` 5 个用例中，`locale` / `translation_group` 出现 **0 次**。

而本次合并我恰好改过 `2026_10_02_000001_add_external_id_unique_to_contents`：
该迁移重建 `contents` 表，**源库版本漏掉了 `locale` / `translation_group` 两列**
并把唯一约束写成非 locale 版 —— 已修复。

**风险**：若 `downSqlite()` 有同类遗漏，回滚后 i18n 静默失效，
而现有测试**不会报警**。

**20G-8 必做**：
- 迁移回滚保真度断言扩展到 `locale` / `translation_group` /
  `UNIQUE(site_id, slug, locale)` / `contents_translation_group_index`
- 迁移总数已变：**旧基线 41个 → 新基线 55 个**，
  旧 20G-6 报告的迁移数字**全部失效**，必须重跑

### 为什么这是最高优先级

回滚是唯一会在**生产环境**静默破坏 i18n 的路径：
测试环境的 up 迁移有 1370 用例兜着，
但 down 迁移只在回滚演练时才执行 —— 一旦漏列，英文站当场退化为不可用且无告警。

---

## 四、20G-8 受影响面清单（你列的方向全部认同，补齐遗漏）

我改动过的区域（与你列的一致）：`Content` `Sitemap` `Narrative` `Cache`
`Migration` `GEOFlow` `Middleware` `Routing` `Locale` `Models` `Services`

### 有效迁移契约标准（Gate 制度，2026-10-03 确立）

本轮在 20G-8-C 中发现旧 `G6-RB-005` 是**假阳性断言**（匹配到注释文字而恒真）。
由此确立迁移契约的准入标准 —— **四条同时满足才算有效**：

```
Valid Migration Contract =
    Static Assertion          静态源码契约（CI 长期守）
  + Branch-specific Assertion 按重建分支分别校验（up/down 各自独立）
  + Dynamic Round-trip        真实跑 up → down，比对 schema/数据/约束行为
  + Mutation Verification     故意破坏，确认断言必然红
```

**缺任一条就会退化成「字符串存在即 PASS」**：

| 缺失项 | 后果 | 本轮实证 |
|---|---|---|
| 静态断言 | 完全没有防护 | — |
| 分支级断言 | 同一 DDL 在 up/down 各出现一次，一侧正确掩盖另一侧错误 | 删掉 `up()` 的 i18n 列后文件级断言仍全绿 |
| 动态往返 | 只验「最后一次 rollback」，中间迁移全盲 | 20G-6 只验了 41 迁移的末步 |
| **变异验证** | **无法知道断言是否真的在守** | 旧 `G6-RB-005` 恒真却一直「通过」 |

**变异测试是判定断言有效性的唯一手段**。本轮做了三轮：
坏 `upSqlite` / 坏 `downSqlite` / 坏唯一约束 —— 全部被捕获才算数。

### 静态层的固有边界（不假装能覆盖）

| 情形 | 处置 |
|---|---|
| DDL 由**类属性**驱动（`09_23_000001::rebuildContents()` 的 `$contentColumns`） | 静态层豁免 + 断言「必须由该属性驱动」+ 交由动态层守住 |
| DDL 由**运行时变量**拼表名（`CREATE TABLE \`'.$temp.'\``） | 同上，静态不可知 |
| DDL 跨行字符串拼接 | 正则须允许 `[\s\S]{0,80}?` 跨行 |
| 列声明两种写法 | 内联 DDL 与数组驱动（`'locale' => "VARCHAR(...)"`）都要认 |
| 一文件改多张表 | 必须按 `Schema::table('x', fn)` 块逐个判断表归属，否则误报 |

### 定向重验矩阵

```
A. Security / Rendering
   XSS 三层（HTML injection / Protocol / Output Sink）
   Markdown 唯一出口收敛（Content::renderMarkdown）
   GEOFlow UTF-8 守卫（RejectMalformedUtf8）

B. GEOFlow
   hash（Schema v2 + ContentFieldContract）
   nullable 四态（missing / null / valid / invalid）
   lock_manual（409 + action=conflict）
   kill switch（写开关关闭时 upsert 与 unpublish 均拒绝）
   concurrency（UNIQUE(site_id,external_id) 竞态兜底）
   response contract（data / changed_fields / action / request_id / errors 恒为数组）

C. Migration（新基线最高风险）
   up / down 对称性
   locale / translation_group 保真
   UNIQUE(site_id, slug, locale)
   FK / AUTOINCREMENT / 索引全量
   迁移总数 55 → 必须重跑 fresh / upgrade / rollback

D. Site × Locale 四象限（重点，此前未做全组合）
   A/zh  A/en  B/zh  B/en
   canary：same slug + different content
   逐项验证互不污染：
     Content / Facts / Entity / Category / Setting / Cache
     Canonical / hreflang / JSON-LD / geo.json / llms.txt / sitemap

E. Cache 失效确定性
   一次写入 = 一次 PageCache 版本 +1
   （本次已修Entity / EntityRelation 重复 flush，delta 从 2 回到 1，需锁死）

F. Multi-site 回归（旧 20G-5 的 30 条断言）
```

---

## 五、Gate 顺序（认同你的排序，插入 20G-8 的位置说明）

```
20G-3  i18n Integrity
       ├─ Facts 行级locale（P1，Release Blocker）
       └─ Setting Field Contract + 5 键 locale 化（P2）
          ↓
20G-8  Baseline Transition & Port Closure← 本文档定义
       ├─ A~F 六组定向重验
       └─ 重点：Migration i18n 列保真（新盲区）
          ↓
20G-7.1  Site Administration + New Site Bootstrap
         （/admin/sites + 新站栏目/分组/设置初始化）
          ↓
Multi-site × Multi-language 四象限 E2E
          ↓
Release Candidate
```

**为何 20G-8 排在 20G-7.1 之前**：20G-8 是「基线换血后的止血」，
不确认地基完好就往上盖功能，风险会叠加。故20G-8 先做。

---

## 六、最终产品模型（固定口径）

```
GEO OS v1.0
│
├── Multi-site                ✅ 技术隔离已验证
│   ├── Site A  zh-CN ✅ / en ✅
│   └── Site B  zh-CN ✅ / en ✅
│
├── CMS                ✅
├── GEOFlow                    ✅ 20G-2 契约完成
├── GEO derived output         ✅ 20G-4 lastmod 已修
├── Multi-site isolation       ✅
├── Upgrade / Rollback         ⚠️ 需按 55 迁移重跑（20G-8 C组）
│
├── Facts localization         🔴 P1  必须修（20G-3）
├── Settings localization      🟡 P2  4/16 键（20G-3，可与 RC 决策并行）
├── Categories/Groups *_en     ⚪ INFO 架构债，不阻塞
├── Site Administration        🔴 P1  UX-001
└── New Site Bootstrap         🔴 P1  UX-002
```

**Release 阻塞项收敛为 4 个**：
1. Facts localization（20G-3，P1）
2. Migration i18n 列回滚保真（20G-8 C 组，P1，**本轮新识别**）
3. Site Administration / `/admin/sites`（UX-001，P1）
4. New Site Bootstrap（UX-002，P1）

Settings localization（P2，4 键）可与 RC 决策并行。

---

## 七、本文档的证据边界（诚实声明）

| 结论 | 证据强度 |
|---|---|
| Facts 未按locale 隔离 | **实测确认**（代码 + 全库调用点扫描） |
| Facts 不影响人类可见页面 | **实测确认**（前台 0 命中） |
| Settings 仅 4 键需 locale | **实测确认**（16 键全量分类+ 使用点） |
| `site_name` 进 `<title>` | **实测确认**（blade:1479-1480） |
| Categories `*_en` 30+ 处 | **实测确认**（Catalog.php 扫描） |
| 迁移回滚对 i18n 列零覆盖 | **实测确认**（测试文件 0 命中） |
| 四象限全组合未验证 | **实测确认**（现有巡检覆盖抽样，非全组合） |
| 旧 20G-5的 30 条断言未在 fd660b0 重跑 | **实测确认**（本轮未执行） |

**尚未验证、需要 20G-8 实跑才能定论的**：
- 55 个迁移的 fresh / upgrade / rollback 实际行为（旧结论基于 41 个，已失效）
- 四象限 canary 在 same slug + different content 下是否真的互不污染