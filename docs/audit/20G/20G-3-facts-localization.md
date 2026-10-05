# 20G-3 · Facts Localization + GEO/LLMS Locale Integrity

> **状态**：✅ **CLOSED** · 全量回归 1388/1388 · 0 失败
> **基线**：`fd660b0` · 56 migrations（55 → 56）
> **前置**：20G-8 六组全部 CLOSED（迁移安全 / i18n schema / Site×Locale / Cache / Multi-site 契约已封口）
> **Release 阻塞项**：4 → **2**（Facts 关闭，Site Admin / New Site Bootstrap 仍在）

---

## 〇、结论

`GEO OS v1.0 = Multi-site + Multi-language` 里最后一处**数据正确性缺陷**已关闭。

改造前的事实：

```
/en/geo.json  →  GeoGraphBuilder::facts()  →  Fact::publicRows()（不过滤 locale）
              →  输出中文 label / value
/llms.txt     →  LlmsBuilder::buildGeneric() 的 contentQuery()（缺 forLocale）
              →  中文站列出英文文章
```

两处都不是「翻译缺失」而是「**语言隔离缺失**」—— 前台英文页面可能还看不出问题，
但 AI 检索到的是「英文站点配中文事实」的数据污染。

---

## 一、契约先行（不先改数据库）

### 1.1 `key` 与 `translation_group` 的职责分离

这是本轮最关键的设计约束，两者的语义**不能混**：

| 字段 | 角色 | 跨语言 | 谁引用它 |
|---|---|---|---|
| `key` | **事实语义身份**（`FACT-COMPANY-NAME`） | 恒定 | `Content.fact_refs`、`ContentGate` 存在性校验、GEO 语义节点 |
| `translation_group` | **翻译实体身份** | 相同事实的多行共享 | 仅系统内部归组 |
| `label` / `value` | 翻译内容 | 各语言独立 | 展示层 |

`key` 进 `sharedTranslatableColumns`（中英必须一致，否则引用在另一语言下失效）；
`label` / `value` 刻意**不进**（它们是逐语言内容）。

### 1.2 `translation_group` 确定性派生，不生成随机 UUID

`Translatable` 默认行为是 `Str::uuid()`。facts **必须覆盖**：

```php
Fact::groupForKey('FACT-COMPANY-NAME')  →  'TG-FACT-FACT-COMPANY-NAME'
```

理由：运营人员在后台新增「英文版某事实」时，系统要能**只凭 key** 就把它归到中文行所在的组。
若组 id 是随机 UUID，后台就必须提供「选择翻译组」的下拉，运营人员会看到并可能手工改错
—— 这正是要消除的认知负担。随机 UUID 还会让「先有 en 行、zh 行另生成一组」的历史数据无法自愈。

实现（已有值不覆盖，允许数据修复场景显式指定）：

```php
protected static function boot(): void
{
    static::creating(function (self $fact): void {
        $key = trim((string) $fact->key);
        if ($key !== '' && empty($fact->translation_group)) {
            $fact->translation_group = self::groupForKey($key);
        }
    });

    parent::boot();   // ⚠️ 必须最后
}
```

#### ⚠️ 这里有一个极隐蔽的 hook 顺序陷阱（本轮真实踩到）

要让派生逻辑抢在 `Translatable` 的 UUID **之前**执行，必须理解 Laravel 的 boot 顺序：

```
static::boot()                    ← 模型自己的 boot()
  ├─ static::creating(...)        ← 你在这里注册
  └─ parent::boot()
       └─ static::bootTraits()    ← bootTranslatable() 在这里注册 creating
static::booted()
```

Laravel **按注册顺序**执行 creating 钩子。三种错误写法都会让派生逻辑**永不生效**
（`empty()` 判断恒为 false，因为 trait 已填 UUID）：

| 写法 | 实测结果 |
|---|---|
| 写在 `booted()` 里 | `703e84d7-...`（UUID） |
| `boot()` 里 `parent::boot()` 放**前面** | `703e84d7-...`（UUID） |
| 漏掉 `parent::boot()` | `Undefined array key`（SiteScope 丢失） |
| ✅ 自己钩子在前，`parent::boot()` 最后 | `TG-FACT-FACT-NEW-001` |

**症状极其隐蔽**：代码读起来完全正确，只有用「不传该字段的写入」才暴露。
这也是 FAC-004b 断言存在的理由 —— 最初的 FAC-004 在 `setUp()` 里显式传了
`translation_group`，**完全绕过**了 `creating` 钩子，变异测试立刻漏抓。

### 1.3 唯一约束降级

```
UNIQUE(site_id, key)  →  UNIQUE(site_id, key, locale)
```

否则 `FACT-X / zh-CN` 与 `FACT-X / en` 无法共存。

**依赖旧约束的历史代码已全量审完**，处置如下：

| 位置 | 用法 | 处置 |
|---|---|---|
| `AppServiceProvider:136` | `Fact::publicMap()` 全站注入 | 走新的 locale 默认值，无需改 |
| `GeoGraphBuilder:93` | `Fact::publicRows()` | 加显式 locale 参数 |
| `ContentGate:150` | `whereIn('key', $refs)` 存在性校验 | **不按 locale 过滤**（语义查询，见 FAC-011） |
| `ContentGate:162` | `where('key','FACT-COMPANY-013')->value('value')` | 同上（NAP 口径，语言无关） |
| `FactController` | CRUD | 改为按 `key` 聚合的翻译维护 |
| `ContentController:281` / `DashboardController:29` | 后台展示 / 计数 | 无需改（管理视角看全部语言） |
| `FactSeeder` | `updateOrCreate(['key'=>...])` | **加 `locale` 条件**（见下） |

---

## 二、Schema：`2026_10_03_000001_add_locale_to_facts.php`

```
+ locale             VARCHAR(16) NOT NULL DEFAULT 'zh-CN'
+ translation_group  VARCHAR(80)
  UNIQUE(site_id, key)         → UNIQUE(site_id, key, locale)
+ facts_translation_group_index
  facts_group_sort_index / facts_review_due_index / facts_site_id_index  保留
  FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE RESTRICT          保留
```

存量归组：locale 缺省 `zh-CN`，`translation_group = 'TG-FACT-' || key`。

**SQLite 重建的三个坑（本轮实测踩到）**：

1. `group` 是 SQL 保留字 → 索引 DDL 必须反引号，否则 `near "group": syntax error`
2. 新列的表达式只能依赖**旧表已有列** → 不能写 `CASE WHEN translation_group ...`
   （此刻该列还不存在，会 `no such column`）
3. **必须显式重写 FOREIGN KEY 子句**，否则静默丢外键
   → 本轮 `SiteIdCoreTablesTest` 抓到 facts 丢了 `site_id → sites` RESTRICT 外键。
   修法：从 `PRAGMA foreign_key_list` 读真实定义（含 on_delete / on_update）再重建，
   而非硬编码 —— 这样后续新增的外键也能自动保住。

### up/down 对称性验证（真实 SQLite，有数据）

```
up 后   : 18 列 · UNIQUE(site_id,key,locale) · 4 索引 · FK 在
down 后 : 16 列 · UNIQUE(site_id,key)        · 3 索引 · FK 在 · 仅保留 zh 行
```

down 的清理策略：删掉 `locale != 'zh-CN'` 的行（`UNIQUE(site_id,key)` 无法容纳多语言行），
保留默认语言行 —— 与改造前「一个事实一条」的语义一致。

---

## 三、Model / Query

`Fact` 接入 `BelongsToSite, Translatable`。

**所有公共取数口改为按 locale 确定性**，且**签名上不存在「不按语言过滤」这个选项**：

```php
private static function resolveLocale(?string $locale): string
{
    return $locale ?? (LocaleContext::has() ? LocaleContext::current() : LocaleRegistry::default());
}
```

| 方法 | 改动 |
|---|---|
| `publicRows(?string $locale = null)` | 加 `forLocale`；memo key → `rows:{locale}` |
| `publicMap(?string $locale = null)` | 同上（**P1 不可妥协项**：原 `pluck('value','key')` 遇同 key 多语言会取到任意一条） |
| `publicByLabel(?string $locale = null)` | 同上 |
| `groupMap(string $group, ?string $locale = null)` | 同上 |
| `memo` 分片 | `rows` → `rows:en`；**不分片会语言串档**（FAC-009） |
| `groupForKey(string $key)` | 新增，group 派生唯一出口 |
| `translationRows()` | 新增，供后台翻译界面 |

**共享列**：`key / group / source / owner / reviewed_at / review_due / is_public / sort / unit / source_url`
由默认语言权威行单向同步 → 一次改「是否公开 / 排序 / 依据」，所有语言同时生效。

**禁止跨语言 fallback**（20G-3 硬约束）：缺翻译时宁可少一条事实，也不回退。
GEO 场景下「英文站点输出中文事实」是数据污染，不是优雅降级。

---

## 四、派生层

### 4.1 `GeoGraphBuilder::facts()`

改为 `Fact::publicRows($locale)`，`$locale` 缺省 `LocaleContext::current()`。

### 4.2 `LlmsBuilder` —— **两处**，不是一处

20G-8-D 只定位到 `buildGeneric()` 的一处。实测发现**同文件里两处**都缺：

| 行 | 所在方法 | 症状 |
|---|---|---|
| 250 | `build()` 中文主骨架（知识文章段） | 中文 llms.txt 混入英文文章 |
| 302 | `buildGeneric()` 空站通用骨架 | 同上（`Catalog::company()` 为空时走这条） |

英文侧对应的 446 / 488 两处本来就有 `forLocale('en')` —— 两条路径不一致才漏掉。
**教训**：`grep contentQuery` 找到的是「某几处」，必须按「每个方法都查一遍」的方式审。

真实复现（修复前）：

```
p.test/llms.txt      →  - [ZH Article]  - [EN Article]   ← 混入
p.test/en/llms.txt   →  - [EN Article]
```

修复后：

```
p.test/llms.txt      →  - [ZH Article]
p.test/en/llms.txt   →  - [EN Article]
```

---

## 五、后台：翻译关系维护入口

运营视角的语义是「**一条事实有几种语言**」，而不是「有几条 fact 记录」。

| 页面 | 设计 |
|---|---|
| 列表 | 按 `key` 聚合，一行一个事实；每种语言一列；缺失显式标「未翻译」**让缺口可见** |
| 编辑 | 按语言区块维护 label / value；共享字段只出现一次，改后自动同步 |
| 补录 | 「未翻译：en」下拉 + 展示名 + 值 → 系统按 key 派生 group 建行 |
| 归组 | `translation_group` **不接受人工输入** |
| 删除 | 连带删除各语言行（避免「中文已删、英文仍在」的口径泄漏，BUG-20B-002 同款） |

新增路由：`POST admin/facts/{fact}/translations`。

---

## 六、验证

### 6.1 单元/契约：`FactLocaleIntegrityTest`（FAC-001~013）

13 用例 / 30 断言。全部用**真实写入 + 真实读取**判定，不用「源码里有没有 forLocale」。

```
FAC-001  schema 有 locale / translation_group
FAC-002  UNIQUE(site_id,key,locale) 存在，且旧的 UNIQUE(site_id,key) 已移除
FAC-003  行为型约束：同站同语言同 key 被拒 / 同站跨语言同 key 允许
FAC-004  translation_group 按 key 确定性派生（TG-FACT-{key}）
FAC-005  同 key 的多语言行共享同一 group
FAC-006  publicRows 语言确定性
FAC-007  publicMap 语言确定性（P1 项）
FAC-008  禁止跨语言 fallback
FAC-009  memo 按 locale 分片
FAC-010  共享列同步、翻译列不跟随
FAC-011  语义 key 查询与 locale 无关（ContentGate 依赖它）
```

### 6.2 变异测试（断言有效性证明）

固化为永久 Gate：`docs/audit/20G/gates/fac-mutation-test.sh`

| # | 变异 | 结果 |
|---|---|---|
| 1 | `publicRows` 去掉 `forLocale` | ✅ 被抓（3 个 FAIL） |
| 2 | `publicMap` 去掉 `forLocale` | ✅ 被抓（1 个 FAIL） |
| 3 | `publicRows` 硬编码 `zh-CN`（还原 GEO 缺陷形态） | ✅ 被抓（6 个 FAIL，全是 en 象限） |
| 4 | `memo` 去掉 locale 维度（模拟语言串档） | ✅ 被抓 |
| 5 | `translation_group` 改为随机 UUID | ✅ 被抓 |
| 6 | `parent::boot()` 提前（钩子顺序错） | ✅ 被抓 |
| — | `LlmsBuilder:302` 去掉 `forLocale` | ✅ 精确复现原缺陷（中文混入 EN Article） |

**最终 6/6 全部捕获。**

第 4 轮变异（第 5 项）最初是**漏抓**的：FAC-004 在 `setUp()` 里显式传了
`translation_group`，完全绕过 `creating` 钩子 →
「钩子失效」这个变异测不出来。补FAC-004b（不传该字段的真实写入路径）后捕获。

### 6.3 E2E：`facts-locale-e2e.php`（真实 HTTP 内核）

四象限（A/zh、A/en、B/zh、B/en），**31 断言全绿**：

```
G1  geo.json facts 段存在且含本象限 value
G2  不含其它象限 value（跨站 + 跨语言都不许泄漏）
G2  label 语言正确（en 输出零 CJK 字符 / zh 输出有 CJK）
G3  facts key 集合在四象限下完全一致（语义身份不随语言/站点变）
G4  禁止 fallback：删掉 A/en 的某条后，en 输出里它消失、zh 仍完整
L1  llms.txt 含本象限文章、不含其它象限文章
L2  四象限 llms.txt 两两不同（site 与 locale 都生效）
```

### 6.4 迁移往返：复用 20G-8 验证器（零改动）

```
GEO_ONLY=2026_10_03_000001  →  36/36 PASS
```

并把 `facts` 加入 `I18N_TABLES` 资产清单 —— 以后 A 组跑全部 i18n 迁移时会**自动覆盖**它，
不需要为新迁移单独写工具。

### 6.5 相关既有测试

```
FactAdminTest                 OK (3)
AdminContentTest              OK (11)
GeoGraphTest                  OK (7, 45)
ExampleDatasetIntegrityTest   OK (14, 352)
SiteIdCoreTablesTest          OK (15)   ← 抓到了外键丢失
Localization18FTest           OK (10, 55)
```

---

## 七、过程中被拦下的问题

| # | 问题 | 归因 |
|---|---|---|
| 1 | `group` 是 SQL 保留字，索引 DDL 报语法错 | 我的迁移缺陷 |
| 2 | 重建路径里引用尚未存在的新列 → `no such column` | 我的迁移缺陷 |
| 3 | **SQLite 重建静默丢失 `facts` 的 site_id 外键** | 我的迁移缺陷（`SiteIdCoreTablesTest` 抓到） |
| 4 | `LlmsBuilder` **两处**缺 `forLocale`，20G-8-D 只报了一处 | 20G-8-D 定位不完整 |
| 5 | 第一次「修复」llms 时只加了注释没改代码（被 `cp` 恢复覆盖） | 我的操作失误，靠真实 HTTP 探测才发现 |
| 6 | `Site` 模型没有 `withoutSiteScope()`（它不用 `BelongsToSite`） | 我的脚本缺陷 |
| 7 | `FactSeeder::updateOrCreate(['key'=>...])` 会覆盖任意语言行 | **我的设计遗漏**（旧调用点审计时只标了「待复核」） |
| 8 | MRF-001 迁移基线仍写 55、MRF-010 未豁免新迁移 | 契约需随schema 变更更新（预期行为） |
| 11 | 全量回归 4 个 `PageCacheTest` 失败，单文件跑却全绿 | **环境缺陷**：`PageCache` 硬编码 `file` store，`storage/framework/cache` 积 8486 个残留文件 |

第 3 项值得单独说：如果没有 `SiteIdCoreTablesTest` 这条既有断言，
**丢外键会完全静默** —— 迁移 up 成功、测试全绿，但生产上站点删除时不再级联保护。
这正是 20G-8 强调「非本迁移的约束必须 100% 保留」的现实例证。

第 5 项同样值得记：`grep` 看到 `forLocale` 存在就以为改好了，
是**真实 HTTP 探测**（`/llms.txt` 里有没有混进 EN Article）才抓住它其实没生效。
代码审查不能替代行为验证。

第 9 项是最容易误判的：**「单文件跑全绿、全量跑失败」看起来像我的改动引入了缓存回归，
实际是环境问题**。判据链条：

```
单文件跑 PageCacheTest           → OK（12/81）
过滤跑（3 个用例）               → OK
PageCache + FactLocale 两个文件  → OK
全量 1386 用例                   → 4 个 PageCache 用例 FAIL
查 storage/framework/cache       → 8486 个残留文件
php artisan cache:clear后 重跑   → 需全量确认
```

`PageCache` 与 `Content::bodyHtml()` 都硬编码 `Cache::store('file')`，绕过了
`phpunit.xml` 里的 `CACHE_STORE=array` → 缓存跨运行残留。
**教训：判定「是否我的回归」前，先排掉环境。缓存类断言尤其如此。**

---

## 八、遗留（不阻塞 v1.0）

| 项 | 说明 | 处置 |
|---|---|---|
| `categories` / `groups` 仍是 `name_en` 字段级 | 双语言够用，无法加第三语言 | INFO · 架构债（维持 20G-8 定级） |
| `settings` 4 个键无 locale | `site_name` 进 `<title>`，影响 SEO | P2 · 与 RC 并行 |
| `ContentGate` 事实引用校验 | 保持跨语言（语义查询，正确） | 已由 FAC-011 锁定 |

### `FactSeeder` 的隐性缺陷（本轮已修）

```php
Fact::updateOrCreate(['key' => $key], [...]);   // ❌ 只按 key 匹配
```

facts 变成行级翻译后，同一 `key` 会有 zh / en 两行。Eloquent 只按 `key` 查找会取
「第一条」—— 可能是 `en` 行，于是**中文演示数据会覆盖英文翻译**。

修法：匹配条件加 `locale`，并用 `SEED_LOCALE` 常量显式声明：

```php
private const SEED_LOCALE = 'zh-CN';

Fact::updateOrCreate(['key' => $key, 'locale' => self::SEED_LOCALE], [...]);   // ✅
```

实测：Seeder 跑完 23 行全部 `locale = zh-CN`，`ExampleDatasetIntegrityTest` 14/352 全绿。

**通则：`updateOrCreate` / `firstOrCreate` 的匹配条件必须包含新引入的维度列**，
否则行级翻译模型下会静默写错行。

---

## 九、最终结果

| Gate | 结果 | 明细 |
|---|---|---|
| FAC 契约 | ✅ **13/13** | 30 断言 · 真实写入+真实读取 |
| FAC 变异测试 | ✅ **6/6捕获** | 永久 Gate `gates/fac-mutation-test.sh` |
| 四象限 E2E | ✅ **31/31** | 真实 HTTP 内核 · 零跨站/跨语言泄漏 |
| 迁移往返（A 组） | ✅ **301/301** | 18 个 i18n 迁移，facts 已入清单自动覆盖 |
| Schema 验收 | ✅ **6/6** | locale / tg / UNIQUE / 旧约束移除 / FK / Index |
| 派生层验收 | ✅ **4/4** | publicRows · publicMap · GEO · LLMS 零串档 |
| MRF 契约 | ✅ **10/10** | 基线 55→56 · 白名单加 facts 迁移 |
| **全量回归** | ✅ **1388/1388** | **7261 断言 · 0 失败 · 0 错误** |

---

## 十、Gate 状态

```
20G-8-A ✅ 238/238     20G-8-B ✅ 44/44     20G-8-C ✅ 10/10
20G-8-D ✅ 68/68       20G-8-E ✅ 21/21     20G-8-F ✅ 30/30
20G-8   ✅ CLOSED
20G-3   ✅ **CLOSED**（Facts Localization + GEO/LLMS Locale Integrity）
20G-7.1 ▶ NEXT —— Site Administration（UX-001）+ New Site Bootstrap（UX-002）

Release 阻塞项：
  UX-001  /admin/sites 不存在，多站无法由运营创建/管理      P1
  UX-002  新站不自动初始化 Category/Group/Settings，空站无法运营 P1
  Settings localization（4/16 键）                        P2（并行）
```
