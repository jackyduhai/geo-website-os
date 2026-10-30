# 20G-8 D / E / F · 四象限 E2E · Cache 确定性 · 双站契约回归

**日期**：2026-10-03
**状态**：✅ **D 68/68 · E 21/21 · F 30/30 全部通过**
**基线**：`D:\GEO-OS-rewrite\geo-website-os` @ `fd660b0` · 55 migrations

---

## 零、最终结果

| Gate | 结果 | 覆盖 |
|---|---|---|
| **20G-8-D** Site × Locale 四象限 E2E | ✅ **68 PASS / 0 FAIL** | 9 维度 × 4 象限 + 4 条 20G-3 缺口证据 |
| **20G-8-E** Cache 失效确定性 | ✅ **21 PASS / 0 FAIL** | create/update/delete × 3 模型 + 跨站 + 跨语言 + 登记完整性 |
| **20G-8-F** 20G-5 双站契约回归 | ✅ **30/30** | 8 维度历史契约 |

**20G-8 全部闭合**（A 238/238 · B 44/44 · C PASS · D 68/68 · E 21/21 · F 30/30）。

---

## 一、20G-8-D · Site × Locale 四象限

工具：`docs/audit/20G/e2e/site-locale-quadrant-e2e.php`（真实 HTTP 内核）

### canary 设计

四个命名空间各插一行，**slug 完全相同**、内容与语言标记各不相同：

| | zh-CN | en |
|---|---|---|
| **Site A** | `QUAD-A-ZH` | `QUAD-A-EN` |
| **Site B** | `QUAD-B-ZH` | `QUAD-B-EN` |

**关键修正**：zh 与 en 必须是**同一 `translation_group` 的两行**（真实 i18n 形态）。
初版按 `slug + locale` 独立建行、漏了 `translation_group` →
`publishedLocaleCodes()` 各自只返回 1 个语言 → hreflang 只输出当前语言 + `x-default`。
修正后 hreflang 正确输出 zh-CN / en / x-default 三条。

### 覆盖维度

| 维度 | 断言要点 |
|---|---|
| D1 Content | 四象限各看到自己的文章；每象限的响应**不含其它三象限**的标记（16 条交叉检查） |
| D2 Entity | `geo.json` 的 entities 含自己的标记、不含其它象限 |
| D3 Canonical | 随 host + locale 变化（`a.test` / `a.test/en` / `b.test` / `b.test/en`） |
| D4 hreflang | 每象限输出 zh-CN / en / x-default |
| D5 JSON-LD | 含本象限主体名（AOrg / AOrgEN / BOrg / BOrgEN） |
| D6 geo.json | entities 四象限隔离；facts 见下 |
| D7 llms.txt | 跨站泄漏判 FAIL；跨语言泄漏归 20G-3 |
| D8 sitemap | 200 + 语言前缀正确（zh 不含 `/en/`、en 含） |
| D9 Category | 页面渲染出本象限栏目 |

### 结论

- **跨站隔离**：4 象限**零跨站泄漏**
- **跨语言隔离**：Content / Entity / Category / Canonical / hreflang / JSON-LD / sitemap **全部成立**
- **Facts 与 llms.txt 存在 i18n 缺口** —— 已按纪律登记为 20G-3 复现证据，未当场修

### 两条 20G-3 缺口（精确到行号）

| # | 现象 | 根因 |
|---|---|---|
| 1 | `/en/geo.json` 与 `/geo.json` 的 `facts[].label/value` 都是同一语言 | `GeoGraphBuilder::facts()` 直读 `$f->label/$f->value`，`facts` 表无 `locale` 也无 `_en`（`:93-101`） |
| 2 | **中文 `llms.txt` 里列出了英文文章** | `LlmsBuilder::buildGeneric()`（空站通用骨架）的 `contentQuery()` **缺 `forLocale(LocaleContext::current())`**（`app/Services/Geo/LlmsBuilder.php:297`） |

第 2 条是**本轮新发现**（Facts 那条 20G-8-C 阶段已确认）。两条都归 20G-3 P1 范围。

### 观察到的既有设计行为（非缺陷）

- 内容 URL 规范形态是 `/{category_slug}/{slug}`（`/quad-article` 会 301 到 `/cat-a-zh/quad-article`）
- `Catalog::company()` 为空时产品中心 404 —— 「空站不渲染目录页」的既有设计，
  故 Entity 隔离改用 `geo.json` 验证

---

## 二、20G-8-E · Cache 失效确定性

工具：`docs/audit/20G/e2e/cache-invalidation-determinism.php`

### 判据：恰好一次，不是「至少一次」

| 组 | 内容 | 结果 |
|---|---|---|
| E1 | Content / Entity / Category 的 `create` 与 `delete` 各 **+1** | ✅ 6/6 |
| E2 | `Entity::update` +1；**`EntityRelation::create` +1** | ✅ 2/2 |
| E3 | A 站写入推进 A 站 +1；**B 站版本不动** | ✅ 2/2 |
| E4 | zh 与 en 写入各只推进 +1（互不叠加） | ✅ 2/2 |
| E5 | 登记清单 15 个无重复、5 个关键模型在册、`AppServiceProvider` 只调一次 `register()`、**模型层无遗留 `PageCache::flush()`** | ✅ 9/9 |

### 变异测试（证明断言有牙）

给 `Entity` 注入模型层 `static::saved(PageCache::flush)`：

```
[FAIL] E1.Entity  Entity::create 使版本 +1 | 3203765 → 3203767（delta=2）
[FAIL] E5.no-stale-hooks 模型层无遗留的 PageCache::flush() | 仍有: Entity
PASS: 14   FAIL: 7
```

**精确复现了 20G-8 合并时抓到的那个 bug 形态**（一次写入推进 2 次）。
恢复后 21/21 全绿。

### 一个需要澄清的设计取舍

`PageCache` 版本号是**站点级**，不是「站点 + locale」级。
因此 zh 写入会让该站 en 页面缓存也失效 —— 这是**过度失效**。

**这是正确的设计**：代价只是多生成一次页面；
反过来若做成 locale 级，一旦漏登记就会输出**过期且错误**的页面，那才是危险。
故 E4 断言的是「不输出错内容」（版本确实推进 → 两种语言都重生成），
而非「不误伤」。

---

## 三、20G-8-F · 20G-5 双站契约回归

工具：`docs/audit/20G/e2e/multisite-isolation-e2e.php`（历史脚本，直接复用）

**30/30 全绿** —— 引入 i18n + 55 迁移 + cache 重构后，原 Multi-site 契约无回归。

```
20G-5 historical contract（30/30）
   +
20G-8 i18n transition（A/B/C/D/E全绿）
   ↓
no regression
```

### 修正的两处过时判据

| 断言 | 原判据 | 问题 | 修正 |
|---|---|---|---|
| `CONTENT 004` | 请求 `/knowledge/{slug}` 期望 200 | 内容 URL 规范形态是 `/{category}/{slug}`，旧路径 301 | 改为 `/{slug}`（实测两种路径的站点隔离都正确） |
| `FACTS 003` | 「两站 facts 数量不同」 | 播种每站各 1 条，数量本就相同 | 改为「同 key 的 fact **value** 在两站互不相同」——canary 故意用同一 key（`FACT-E2E-SHARED`）+ 不同 label/value |

> FACTS 判据连续错了两版（先「key 集合不相交」，与 canary 设计直接矛盾；
> 再「数量不同」，依赖播种量）。正确判据是**同 key 下 value 必须不同**。

---

## 四、本轮自身修掉的测试缺陷

| # | 缺陷 | 修法 |
|---|---|---|
| 1 | 内容 URL 用旧路径导致 301 误报 | 实测规范形态后改用 `/{category}/{slug}` |
| 2 | Entity 隔离依赖前台产品中心（空站 404） | 改用 `geo.json` 的 entities |
| 3 | 播种漏 `translation_group` → hreflang 只输出 1 语言 | zh/en 共享同一 group |
| 4 | `llms.txt` 判据未区分跨站/跨语言泄漏 | 拆成两个断言，跨语言归 GAP |
| 5 | `const` 数组不能用 `[]` 赋值 | 改用 `$GAPS` 全局变量 |
| 6 | 中文全角括号紧跟变量导致 PHP 误解析（`$desc（20G`） | `gap()` 改用 `printf` 拼接 |
| 7 | `RequestScopedState::reset()` 方法不存在 | 实际是 `flushAll()` |
| 8 | 正则分隔符 `#` 与模式内 `//` 冲突 | 逐行剥离注释，不用正则 |
| 9 | `ReflectionClass::getFileName()` 已返回绝对路径却又拼 `$root` | 直接用返回值 + `is_file()` 兜底 |

> 第 6 项是老坑复现：**中文全角括号放在 PHP 双引号插值里会被当标识符**。
> 凡含变量的字符串一律用 `printf` 或单引号拼接。

---

## 五、20G-8 完整状态

```
20G-8-A  全部 i18n 迁移动态往返      ✅ 238/238（15 个迁移）
20G-8-B  55 迁移全链路              ✅ 44/44（回滚链 55 步、重装零漂移）
20G-8-C  Migration i18n 契约✅ 10/10（变异 3/4 捕获）
20G-8-D  Site × Locale 四象限 E2E   ✅ 68/68（+ 4 条 20G-3 缺口证据）
20G-8-E  Cache 失效确定性           ✅ 21/21（变异测试确认有牙）
20G-8-F  20G-5 双站契约回归         ✅ 30/30
─────────────────────────────────────────
20G-8    Baseline Transition       ✅ CLOSED
全量回归                           ✅ 1375/1375（7231 断言）
```

### Release 阻塞项（3 个）

1. **Facts localization**（20G-3，P1）—— D 组已给出精确根因与行号
2. **Site Administration** `/admin/sites`（UX-001，P1）
3. **New Site Bootstrap**（UX-002，P1）

Settings localization（P2，4/16 键）可与 RC 决策并行。

### 本轮修复的迁移缺陷（Ledger 口径）

```
C-20  09_24_000018 downUnique 删不掉表级唯一约束    CLOSED · 20G-8-B
C-21  09_23_000001 down 用 dropUnique 删表级约束    CLOSED · 20G-8-B
C-22  09_17_000002 dropColumn 丢失其他唯一约束      CLOSED · 20G-8-B
DELTA 有数据 rollback 冲突（双语数据 vs 单语约束）   DELTA / expected semantic change
```

---

## 六、交付物

| 文件 | 用途 |
|---|---|
| `docs/audit/20G/e2e/site-locale-quadrant-e2e.php` | D 组四象限（9 维度） |
| `docs/audit/20G/e2e/cache-invalidation-determinism.php` | E 组缓存确定性（5 组） |
| `docs/audit/20G/e2e/multisite-isolation-e2e.php` | F 组双站 30 断言（已复用并修正） |
| `docs/audit/20G/20G-8-AB-migration-fullpath-and-i18n-roundtrip.md` | A/B 组报告 |
| `docs/audit/20G/20G-8-C-migration-i18n-rollback-fidelity.md` | C 组报告 |

---

## 七、下一站：20G-3 Facts localization

D 组已把缺口收敛到**两个具体位置**，Facts 改造的范围因此非常明确：

```
facts 表
├── 加 locale + translation_group（key 唯一约束降级为 (site_id, key, locale)）
├── Fact 模型引入 Translatable
├── Fact::publicRows() / publicMap() 加 forLocale()
│     ⚠️ publicMap() 用 pluck('value','key') 建索引，多语言行会随机命中
│     ⚠️ publicMap() 在 AppServiceProvider:136 通过 View composer 全站注入
├── GeoGraphBuilder::facts() 改用 forLocale(LocaleContext::current())
├── LlmsBuilder::buildGeneric() 的 contentQuery() 补 forLocale()   ← D 组新发现
└── 后台补Fact 翻译行维护入口
```