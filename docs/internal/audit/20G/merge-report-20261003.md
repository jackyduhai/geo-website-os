# 单一基线合并报告

**日期**：2026-10-03
**目标**：把 `D:\GEO-OS-rewrite\geo-website-os` 与 `D:\734666\GEO OS` 两个独立代码库合并为一个，彻底消除「权威工作区是哪个」的歧义。
**结论**：✅ 完成。以 `D:\GEO-OS-rewrite\geo-website-os` 为唯一基线，`D:\734666` 已删除。

---

## 一、基线判定（此前认知错误的纠正）

| | `D:\GEO-OS-rewrite\geo-website-os` | `D:\734666\GEO OS` |
|---|---|---|
| HEAD | `fd660b0` | `91aef17`（20F.1 release） |
| 提交数 | 164 | 87 |
| root commit | `64d074f` | `0ed7402` |
| remote | `git@github.com:jackyduhai/geo-website-os.git` | 无 |
| `app/` 文件数 | **195** | 105 |
| 独有文件 | 91 | 8 |
| i18n 能力 | **完整**（SetLocale / Translatable / 六表 locale / lang 双语包） | 无 |

**关键事实**：两库**无共同祖先**，是两条独立演进的线，无法 `git merge`，只能逐能力移植。

**此前错误**：曾把 `D:\734666\GEO OS` 认定为权威基线，并据此断言「当前基线无英文能力」。该结论完全建立在错误路径上，已作废。真相是：`D:\GEO-OS-rewrite\geo-website-os` 既是超集，**也是唯一具备完整多语言能力的库**。

---

## 二、合并策略

```
备份 → 逐能力移植 → 双验证 → 删除
```

### 移植清单

| 能力 | 处置 | 接入点 |
|---|---|---|
| `LlmsSanitizer` | 移植 | `LlmsBuilder::build()` 出口统一终洗（覆盖中英 × 站点/通用 4 条路径，优于源库只改 2 处） |
| `CacheInvalidationMap` | 移植 | **替换** `AppServiceProvider` 内联数组（非叠加）。15 模型零丢失，新增 Site/Entity/EntityRelation |
| `RejectMalformedUtf8` | 移植 | web + api 双组 prepend，alias `utf8.guard` |
| `SlugSuggester` | 移植 | 新增依赖 `overtrue/pinyin ^6.0`；挂 `EntityController@slugSuggest` + `admin/entities/slug-suggest` |
| `GeoflowSync`（656 行） | 移植 | 零 i18n 耦合，已核实后整文件移植 |
| `ContentFieldContract` + Sync 双异常 + 2 迁移 + 10 测试 | 全量移植 | — |
| 20G 审计台账 + e2e/gates/h7 脚本 | 迁入仓库 | `docs/audit/20G/`（20 个文件） |
| `KnowledgeController` | **故意不移植** | `EntityController` 完全覆盖且更优（走 `EntityCapabilityRegistry` 唯一事实源、支持 8 种 Entity 类型、含 `seedExamples`），移植会引入功能倒退的重复入口 |

---

## 三、拦下的三个陷阱

### 1. 整文件覆盖会摧毁 i18n

源库的 `Content.php` / `SitemapBuilder.php` / `Narrative.php` **删掉了** `Translatable` / `LocaleContext` / `forLocale()`。

若直接 `cp` 覆盖，英文翻译模型、sitemap 语言隔离、页面文案语言隔离会**静默失效**。

**处置**：回滚四个文件，改用 `diff <(git show HEAD:f) <源库f>` 逐文件比对，把20G 增量精准合入现版本。

### 2. 新迁移也埋雷

源库 `2026_10_02_000001_add_external_id_unique_to_contents` 重建 `contents` 表时：

- **漏掉 `locale` / `translation_group` 两列**
- 把 `UNIQUE(site_id, slug)` 写成非 locale 版

结果直接抹掉 18F 的 i18n 成果（`table contents has no column named translation_group`）。

**处置**：`upSqlite()` 与 `downSqlite()` 均补齐两列 + `contents_translation_group_index`，唯一约束改为 `UNIQUE(site_id, slug, locale)`。

### 3. 重复 flush 破坏确定性

`Entity` / `EntityRelation` 模型层原有 `PageCache::flush()`（18A/18C 时期），与 `CacheInvalidationMap` 叠加后单次写入把缓存版本号推进 **2**，破坏 TD-08b 依赖的「一次写入 = 一次失效」不变量。

**处置**：移除模型层重复登记，以 `CacheInvalidationMap` 为唯一事实源（`Entity::deleted` 内的级联清理保留）。

---

## 四、连带补齐的缺口

合并过程暴露并修复了 6 处「两库各自不完整」的接口缺口：

| 缺口 | 影响 | 处置 |
|---|---|---|
| `ContentGate::isEnforced()` / `minEvidence()` 缺失 | 移植的 `GeoflowSync` 直接 fatal error | 补入，读`config/geo.gate.*` |
| `Content::renderMarkdown()` 缺失 | Markdown 安全收敛（C-1）无法落地 | 补入唯一出口；`mdPreview` 与 `Narrative` 均收敛到它 |
| `SitemapBuilder` 用 `$lastmod ?? $today` 编造日期 | 编造 lastmod（C-8） | 改为省略，并给产品/场景/栏目补真实 `updated_at` |
| `GeoflowController` 响应字段不符 | 缺 `data` / `changed_fields` / `action` / `request_id` | 全部对齐；`check()` 补 422 契约分支 |
| `Content::forceDeleted` 未清 `content_entity` / `content_tag` | M-2 孤儿行（本仓库既有缺陷） | 补 `forceDeleted` 钩子（软删保留关联，语义正确） |
| `UnpublishCacheInvalidationTest` 契约过时 | 假设 unpublish 不受写开关约束，与 `assertEnabled()` 冲突 | 更新为显式开开关，并**新增反向断言**：开关关闭时必须被拒且不改数据 |

---

## 五、验证结果（双验证）

### 全量回归

```
Tests: 1370, Assertions: 7208
OK, but there were issues!  (PHPUnit Deprecations: 87)
FAILURES: 0    ERRORS: 0
```

`1370 / 1370` 全绿，耗时 12 分 44 秒。

### i18n 功能巡检（真实 HTTP 内核）

`docs/audit/20G/e2e/i18n-merge-check.php` — **38 / 38 全绿**

覆盖：
- `/en` 前缀路由真实可访问，返回英文内容
- 中英 sitemap 按语言隔离（各自只收录本语言 URL）
- 中英 `geo.json` 的 entities 按 locale 隔离
- 站点未启用 en 时 `/en/*` 严格 404
- 产品页 lastmod 使用实体真值（无编造 today）
- 合并引入的 6 项能力均在位

---

## 六、最终状态

```
D:\734666                          →已删除（517M）
D:\GEO-OS-rewrite\geo-website-os   → 唯一基线，HEAD fd660b0，app/ 195 文件
D:\GEO-OS-BACKUP-20261003\         → 备份 167M（可回滚）
D:\73466\.workbuddy                → WorkBuddy HOME，未受影响
```

**产品定义**：GEO OS v1.0 = **Multi-site + Multi-language**（中文 / 英文）。

### i18n 已知覆盖缺口（20G-3 待办）

| 缺口 | 证据 | 影响 |
|---|---|---|
| facts 层不区分语言 | `GeoGraphBuilder::facts()` 直读 `$f->label/$f->value`；`facts` 表无 `locale`/`_en` | `/en/geo.json` 输出中文事实标签 |
| settings 层无语言维度 | `settings` 表无 `locale`，靠 `geo_org_en_name` 单字段打补丁 | 英文站只能多覆盖组织名一个字段 |
| categories/groups 用字段级翻译 | `Catalog.php:539` 走 `name_en`/`desc_en`；`Category` 无 `Translatable` | 与 Entity/Content 的行级模型不一致，无法加第三语言 |