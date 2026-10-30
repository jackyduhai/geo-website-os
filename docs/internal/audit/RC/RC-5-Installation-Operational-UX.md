# RC-5 · Installation / Operational UX

> **状态**：✅ PASS（C-18 判定 **INVALID**，见§1）
> **R1–R3**：57 PASS / 0 FAIL · 14 项判据全绿
> **R4**：1410 passed / 7349 assertions / 0 failures / 0 errors / 0 skipped（EXIT 0，2259.72s）
> **基线**：`docs/audit/RC/RC-0-Freeze-Snapshot.md`
> **边界**：产品代码仅因 C-19 / C-20 两处**实证缺陷**变更；临时库全在 `D:/Temp/`
> **⚠️ 边界事故**：开发库曾于 RC-5 期间被误迁移（54 → 56），详见 §9 与 `RC-0-Environment-Drift-Addendum.md`

---

## 0. 结论前置

| 判据 | 结果 | 证据 |
|---|:--:|---|
| Fresh Install | ✅ | I-01 ~ I-05 |
| Create Site A（真实 HTTP） | ✅ | I-06 ~ I-08 |
| Production parity（文件库 + FK=ON） | ✅ | R1-01 ~ R1-13 |
| Bootstrap A | ✅ | B-01 ~ B-07 |
| zh-CN / en | ✅ | B-07 · C-05 · C-06 |
| Create / Edit / Publish | ✅ | C-01 ~ C-04 · C19-03 |
| Create Site B | ✅ | S-01 · S-02 |
| Same-slug isolation | ✅ | S-05 ~ S-07 · C19-01 |
| Bootstrap idempotent | ✅ | BS 组 |
| No manual data overwritten | ✅ | STEP 5 |
| CLI = Admin equivalence | ✅ | SITE-REG-008 |
| **0 SQL 手工干预** | ✅ | 全程 CLI + HTTP |
| **C-18** | ⚠️ **INVALID** | 缺陷不存在，修法已撤销，见 §1 |
| GEO outputs | ✅ | C19-03 · R1-12 |
| Full regression | ✅ | **1410 / 7349 / 0 / 0 / 0** |

---

## 1. C-18 → INVALID（三路对照证伪，我方误判）

### 1.1 变异测试：修法无牙齿

把 `enableDeferredForeignKeyChecks()` 里的 PRAGMA 换成无效名：

```php
DB::statement('PRAGMA defer_foreign_keys_MUTATED = ON');   // 无效 PRAGMA
```

`SiteCreationProductionParityTest` 结果：

```text
✓ site creation succeeds under production fk
✓ structure seeder initializes taxonomy and nav
✓ bilingual locales are enabled for new site
✓ repeat bootstrap preserves manual edits
✓ failed initialization rolls back entire site
✓ new site is immediately operable
✓ cli and admin paths produce equivalent structure
Tests: 7 passed (49 assertions)
```

**禁用修法后仍全绿 → 该修法在测试中无任何作用。**

### 1.2 纯 PDO 层对照（唯一变量）

同一文件库、`FK=ON`、同一连接、同一事务，唯一变量是 `defer`：

```text
control_defer_off  → result=OK   post_commit_site_rows=1  post_commit_settings_rows=1
fixed_defer_on     → result=OK   post_commit_site_rows=1  post_commit_settings_rows=1
defer_is_only_var  = true
both_in_tx         = true
```

`defer=OFF` 也成功 → 失败并非 defer 所能解决。

### 1.3 真实 HTTP 路径

```text
POST /admin/sites  → 302 → /admin/sites
site_persisted     = 1
settings           = 81
categories         = 5
menus              = 2
supported_locales  = ["zh-CN","en"]
```

### 1.4 真实根因：测试探针自身

逐步 trace（`rc5-rootcause-locate.php`）：

```text
beginTransaction      txn_level=1   pdo_id=1419
Site::create()        id=2
（之后）              txn_level=0   pdo_id=1423      ← 事务归零 + 连接被换
SELECT 可见性         count=0                          ← 刚插入的行看不见
settings INSERT       FOREIGN KEY constraint failed
```

根因：探针在 **bootstrap 之后**才 `config()` 改库路径并 `DB::purge()`，
重建了 `Connection` 实例，使「写用连接」与「Schema / Setting 读用连接」
不再是同一个，从而**人为制造**出事务边界错位。

改为在 bootstrap **之前**经 `DB_DATABASE` 注入后，事务保持、FK 正常、可见性正常。

### 1.5 处置

- `SiteController::store` 中的 defer 调用与`enableDeferredForeignKeyChecks()`
  **已完全撤销**，仅保留一段注释记录判定过程（净改动 0 行代码）。
- C-18 状态：**INVALID**（判定错误，非修复）。
- `SiteCreationProductionParityTest` **保留**：它验证的是
  「文件库 + FK 开启下建站成功」这一**生产等价条件**，该断言本身正确且长期有价值；
  只是不能再声称它锁住了 C-18。

---

## 2. C-19 · GEOFlow slug 预检缺 locale 维度（P1，已修）

### 2.1 缺陷

20G-3 引入行级翻译模型后，三处口径不一致：

| 位置 | 口径 | 判定 |
|---|---|:--:|
| DB 约束 `UNIQUE(site_id, slug, locale)` | 含 locale | ✅ |
| 后台 `ContentController:301` `Rule::unique(...)->where('locale', ...)` | 含 locale | ✅ |
| **GEOFlow `GeoflowSync:308` `Content::where('slug', ...)`** | **站点级单维** | ❌ |

原注释还写着「与 DB `UNIQUE(site_id, slug)` 同口径」—— 20G-3 改约束时的遗漏。

**后果**：GEOFlow 是 AI 内容管道的**主入口**，却无法发布 zh/en 同 slug 的双语内容，
而后台与 DB 层都允许。同一 slug 的另一语言行被误判为「冲突」。

### 2.2 修法

```php
$conflictLocale = $attributes['locale']
    ?? \App\Support\Localization\LocaleContext::current();
$conflict = Content::where('slug', $attributes['slug'])
    ->where('locale', $conflictLocale)
    ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
    ->first();
```

### 2.3 回归（C19 组）

| 断言 | 结果 |
|---|:--:|
| C19-01 同站 zh+en 同 slug 共存（2 行） | ✅ |
| C19-02 同站**同语言**同 slug 仍被拒（422，校验未放空） | ✅ |
| C19-03 开启 `sync_auto_publish` 后确实发布 | ✅ |

> C19-02 必须用**新的 external_id**：复用已有 id 会走 update 分支，
> 预检的 `when($existing, 排除自身)` 会把自身排除 → 构不成冲突 → **假通过**。
> 该陷阱已在本轮修正。

---

## 3. C-20 · GEOFlow 契约丢弃 locale 字段（P1，已修）

### 3.1 缺陷（比 C-19 更深层）

`ContentFieldContract::FIELDS` 中**没有 `locale`**，
`normalize()` 遍历 `ContentFieldContract::all()` 时该字段被直接跳过，
于是 payload 里的 `locale` **从未进入落库属性**。

落库实测（修复前）：

```text
id=1 ext=rc5-a-zh slug=rc5-article locale=zh-CN status=published
id=2 ext=rc5-b-zh slug=rc5-article locale=zh-CN status=draft
```

**所有 GEOFlow 内容都落成 DB 默认 `locale='zh-CN'`** ——
AI 内容管道根本无法发布非默认语言内容。

### 3.2 修法

在契约中补入`locale`，合法值集合**运行时**从配置注入：

```php
// const FIELDS
'locale' => [
    'type' => 'string', 'nullable' => true, 'max' => 16,
    'hashable' => true, 'revisionable' => true, 'syncable' => true,
],

// all()
if (isset($fields['locale'])) {
    $supported = LocaleRegistry::supported();
    if ($supported !== []) { $fields['locale']['enums'] = $supported; }
}
```

验证：`fields count = 21`，`locale.enums = ["zh-CN","en"]`。

**为何运行时注入**：PHP 的 `const` 数组不能调用静态方法；
而语言列表的事实源是 `localization.supported` 配置，
写死会产生第二份事实源，与 `SetLocale` 门禁 / `site_supported_locales` 漂移。

**为何不设 `format` 正则**：语言码形如 `zh-CN` / `zh-TW`，含连字符且大小写有语义，
正则化会误拒合法值；合法性由 `enums` 兜底。

---

## 4. 断言方向修正（测试问题，非产品缺陷）

| 现象 | 真实语义 |
|---|---|
| 发布后仍 draft | `GeoflowSync:242` `$derived['status'] = $autoPublish ? 'published' : 'draft'` —— GEOFlow **刻意忽略 payload 的 status**，发布态由站点 `sync_auto_publish` 治理（AI 管道不能自行决定发布）。已改判据并用 C19-03 正向复验。 |
| 建站需已知密码 | 改用编程式 `auth()->login()`：本Gate 验证建站链路，非认证本身；表单登录引入与本 Gate 无关的假失败。 |
| 后台表单页 405 | `Route::resource('sites')` 的 create 路径是 `/admin/sites/create`，非 `/new`。 |

---

## 5. RC-5-R1 · 连接与事务边界取证

你明确要求排除的替代原因：「事务内 SELECT 看不到新行」也可能意味着
**连接或事务上下文不一致**，而 `defer_foreign_keys` 不能替代对连接边界的证明。

逐项结果：

| 断言 | 内容 | 结果 |
|---|---|:--:|
| R1-01 | 文件库（非`:memory:`） | ✅ |
| R1-02 | `PRAGMA foreign_keys = ON`（未被绕过） | ✅ |
| R1-03 | 建站返回 302（控制器 store 正常结束） | ✅ |
| R1-04 | 请求结束 `transactionLevel = 0`（事务正常提交，无泄漏） | ✅ |
| R1-05 | 连接实例保持同一（无 reconnect / purge 换连接） | ✅ |
| R1-06 | 取证脚本返回结构完整 | ✅ |
| R1-07 | 文件库 + `FK=ON` | ✅ |
| R1-08 | 事务内 `transactionLevel >= 1` | ✅ |
| R1-09 | Site INSERT 前后同一 PDO 实例 | ✅ |
| R1-10 | **事务内 SELECT 能看到刚插入的行** | ✅ |
| R1-11 | Settings 与 Site 同一事务边界（level 未归零） | ✅ |
| R1-12 | 提交后 level 归零**且数据真实落库**（非 rollback 掩盖） | ✅ |
| R1-13 | 对照：defer 关闭时同样成功 → C-18 INVALID | ✅ |

**「提交 → 落库 → 再读复核」三段齐备**，证明数据真实持久化，
不存在「事务被回滚但进程正常退出」的假成功。

---

## 6. 本轮方法论沉淀

### 6.1 探针库路径必须在 bootstrap 之前注入

```text
❌ bootstrap 后 config(['database.connections.sqlite.database' => ...]) + DB::purge()
   → 重建 Connection → 写用连接 ≠ 读用连接 → 人为制造事务边界错位

✅ 外层 DB_DATABASE=... php，子进程 bootstrap 前即拿到库路径
```

这是本轮 C-18 误判的直接原因，也是所有跨进程取证脚本的通用纪律。

### 6.2 变异测试是判定「修法是否有牙齿」的唯一手段

本轮第三次应用该方法（20G-3 `upSqlite` 变异 → 3/4 捕获；20G-7.1 → 5/5 捕获）。
若无变异测试，C-18 的无效修法会一路带进 v1.0，
而所有 7 个 Production-Parity 测试都会给它盖章通过。

### 6.3 断言被「排除自身」的分支骗过

```php
->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
```

用**同一个 external_id** 复测冲突 → 命中 update 分支 → 自身被排除 → 假通过。
测「唯一性冲突」必须用**新的 external_id**。

### 6.4 三处口径必须同源

DB 约束 / 后台校验 / API 预检任一不同源，就会出现
「某条路径能写、某条路径不能写」的不一致。
20G-3 只改了前两者，漏了第三者 —— 这是 RC 该抓的东西。

---

## 8. C-20 修复引发的连锁：DB 默认值列参与 hash（RC-5-R4 首轮实证）

R4 首轮全量回归：**9 failed / 1399 passed（7314 assertions）**，
失败全部集中在 GEOFlow hash / idempotency 五个测试文件。

### 8.1 变异测试确认因果

把契约中 `locale` 的 `hashable` 改为 `false`：

```text
GeoflowHashTest → 11 passed / 61 assertions（原本 5 failed）
```

因果链闭合：**`locale` 参与 hash 是唯一变量**。

### 8.2 根因（探针 `rc5-locale-hash-probe.php` 实证）

```text
push1_model_locale = NULL     → hash 8da6983b1d931630
push2_model_locale = 'zh-CN'  → hash df9e509453a3d2ce
hash_stable = false
```

`contents.locale` 是 **DB 默认值列**（`DEFAULT 'zh-CN'`），而 GEOFlow payload 通常**不传** locale：

- 首次推送：新建内存模型**无该属性** → hash 侧读到 `NULL`
- 二次推送：从库读回 `'zh-CN'` → hash 侧读到 `'zh-CN'`
- ⇒ hash 不稳定 ⇒ 幂等 `skip` 退化为 `changed` ⇒ 每次推送都写 revision + synclog

**与 20G-2 修掉的 `published_at` 缺陷完全同型**
（同一份payload 连续两次推送，hash 必须相同）。

### 8.3 语义依据：为什么 `locale` 应当进 hash

| 事实 | 含义 |
|---|---|
| DB 约束是 `UNIQUE(site_id, external_id)`，**不含 locale** | `external_id` 是内容身份键 |
| 一个 `external_id` 只对应一行内容 | `locale` 是该行的**属性**，不是身份维度 |
| 故换语言 = 内容变更 | 必须进 hash |

反之若把 locale 当身份维度，DB 约束需改成 `UNIQUE(site_id, external_id, locale)`——
那是另一个设计决策，不该由 RC 悄悄引入。

### 8.4 修法：算hash 前把 locale 解析到最终态

`GeoflowSync::resolveFinalPersistedState()` 新增三态规则（与 `published_at` 同一手法）：

```text
missing/null + existing 有值 → 保留 existing
                （换语言必须显式声明，否则一次「漏传字段」
                  就会把已发布的英文内容静默改成中文）
missing/null + 无 existing    → 取 LocaleRegistry::default()
explicit value           → 使用提供值（进 hash）
```

### 8.5 新增锁死断言

| 断言 | 内容 |
|---|---|
| GF-HASH-012 | payload 不传 locale → 连续推送恒为 `skip`（hash 稳定） |
| GF-HASH-013 | 已指定 `locale=en` 后省略 → 保留 `en`，不得被静默改回默认语言 |
| GF-HASH-004 补样本 | `'locale' => 'en'`（契约自审要求「声明 hashable 必须有突变样本」） |

结果：`GeoflowHashTest → 13 passed / 71 assertions`。
R1–R3 重跑仍为 **57 PASS / 0 FAIL**，14 项判据全绿。

### 8.6 方法论：新增 hashable 字段必查「DB 默认值列」

任何 `hashable=true` 且带 DB `DEFAULT` 的列进入契约时，必须先回答：

> **首次创建的内存模型有值吗？**

若没有（`NULL` vs DB 默认值），第二次推送的 hash 输入就与第一次不同 ⇒ 幂等失效。
检查方法：该列是否 `DEFAULT` 非 NULL，且模型不会在 `create` 后自动回填。

此外，契约新增 hashable 字段时，`GeoflowHashTest` 的 GF-HASH-004 会主动 fail
（`契约字段 X 缺少 hash 突变样本`）—— 这是**契约与测试防脱钩的设计意图**，
应补样本而不是把 `hashable` 改回 false。

---

## 7. 产物

| 文件 | 用途 |
|---|---|
| `rc5-installation-ux.php` | RC-5 主脚本（R1/R2/R3 + C19 组） |
| `rc5-http-runner.php` | 真实后台 HTTP 建站 runner |
| `rc5-txn-boundary-pdo.php` | 连接/事务边界纯 PDO 对照探针 |
| `rc5-txn-boundary-probe.php` | ORM 版边界探针（Windows 下会挂起，保留作对照） |
| `rc5-rootcause-locate.php` | 根因逐步 trace（逐点落盘，防挂起丢证据） |
| `rc5-c18-verdict.php` | C-18 三路裁定脚本 |
| `tests/Feature/SiteCreationProductionParityTest.php` | 生产等价回归护栏（7 用例 / 49 断言） |
---

## 9. 边界事故如实记录：开发库被误迁移（ENV-002）

### 9.1 事实

| 时点 | 开发库 `migrations` 行数 |
|---|---|
| RC-0 ~ RC-4 期间（多次只读核验） | **54**（lag 2） |
| RC-5 期间（07:27，文件 mtime） | **56**（lag 0，`MAX_BATCH=2`） |

新增的两条正是 RC-2~RC-5 期间反复验证的两个迁移：

```text
#55 batch=2  2026_10_02_000001_add_external_id_unique_to_contents
#56 batch=2  2026_10_03_000001_add_locale_to_facts
```

### 9.2 根因

同一条命令串里，**独立库建库**与**探针执行**共用同一个 shell，
其中一次 `php artisan`调用漏带 `DB_DATABASE`：

```text
touch /d/Temp/rc5_min2.sqlite \
  && DB_CONNECTION=sqlite DB_DATABASE="D:/Temp/rc5_min2.sqlite" php artisan migrate --force
```

`DB_DATABASE` 缺失时Laravel 回落到 `.env` 的
`DB_DATABASE=D:/GEO-OS-rewrite/geo-website-os/database/database.sqlite`，
于是迁移被施加到了**开发库**。

这与 §1.5 记录的 C-18 误判**同源**：
「跨进程/跨命令改库路径时，库标识必须显式且逐条传递」——
本轮我在探针侧记了纪律，却在 shell 串里漏了一次。

### 9.3 数据影响评估：无损坏

```text
sites=1  contents=7  facts=23  settings=83  categories=2
facts.locale                        = YES   （迁移 #56 产物已正确应用）
sqlite_autoindex_contents_3         = (site_id, external_id)  （迁移 #55 产物）
```

两个迁移均为**增量且幂等**（加列 / 加唯一索引），不涉及数据删除或重写。
开发库现有数据与结构完整，**无需回滚**。

### 9.4 处置与新增纪律

- 已在 RC-0 快照与记忆中的「开发库 = 54 / lag 2」口径**作废**，
  当前权威值为 **56 / lag 0**。
- 该变化**不影响 RC-1~RC-5 任何结论**：
  PHPUnit 用 `DB_DATABASE=:memory:`，与开发库无关；
  RC-2~RC-5 的取证全部走项目外独立库。
- 新增纪律：**每一条 `php artisan` 调用都必须显式带 `DB_DATABASE`**，
  不得依赖 `.env` 回落；核验开发库状态时用只读 `SELECT count(*)`，
  且核验后**记录值到快照**（若值与快照不符，说明期间有写入，须追因）。

### 9.5 分类

`ENV-002 · Environment / Test Harness` —— **不是产品缺陷，不入 C 系列台账**。
