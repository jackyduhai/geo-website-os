# Site ID Migration Matrix — Phase 5.4-A Design Audit (Corrected v2)

> 本文件是 Phase 5.4-A Correction 的产物：纯设计审计，不修改任何业务表。
> 基于当前 24 张表的真实 SQLite schema 重新计算，修正 v1 中的内部矛盾。
>
> **v2 修正点**：
> 1. 系统表从 9 修正为 8（14+1+8+1=24 ✅）
> 2. REBUILD 表从 8 修正为 7（7 张 unique 调整表全部需 REBUILD）
> 3. ADD COLUMN 表从 6 修正为 7
> 4. redirects 从 LOW/ADD COLUMN 修正为 MEDIUM/REBUILD
> 5. menus 从 MEDIUM/ADD COLUMN 修正为 MEDIUM/REBUILD
> 6. 增加 groups.category_id 跨站一致性说明
> 7. 执行阶段从 7 个调整为 8 个（5.4-B 到 5.4-I）

## 1. Executive Summary

当前系统 **24 张表**，分类如下：

| 分类 | 数量 | 说明 |
|------|------|------|
| Site-scoped（需 site_id） | **14** | 业务数据表 |
| Users（特殊处理） | **1** | 不加 site_id，v1.1 引入 user_site pivot |
| System（不加 site_id） | **8** | Laravel 基础设施表 |
| Sites（Site 本身） | **1** | Phase 5.3 已建立 |
| **合计** | **24** | 14+1+8+1=24 ✅ |

**SQLite 操作统计**：

| 操作类型 | 数量 | 表名 |
|---------|------|------|
| REBUILD（需修改 UNIQUE 约束） | **7** | contents, categories, groups, facts, settings, menus, redirects |
| ADD COLUMN + INDEX（无 unique 调整） | **7** | content_revisions, page_blocks, media, banners, audit_logs, inquiries, sync_logs |
| **合计 Site-scoped** | **14** | 7+7=14 ✅ |

**核心原则**：SQLite 不支持 `ALTER TABLE ADD/DROP UNIQUE CONSTRAINT`，因此任何需要修改唯一约束的表必须使用 table rebuild 策略。即使表为空（如 redirects），也不能仅用 ADD COLUMN 完成 unique 结构调整。

## 2. 24-Table Complete Inventory

以下来自 `SELECT name FROM sqlite_master WHERE type='table' ORDER BY name` 的真实结果：

| # | Table | Rows | Category | Add site_id? | SQLite Operation |
|---|-------|------|----------|-------------|-----------------|
| 1 | audit_logs | 26 | Site-scoped | YES | ADD COLUMN + INDEX |
| 2 | banners | 3 | Site-scoped | YES | ADD COLUMN + INDEX |
| 3 | cache | 8 | System | NO | N/A |
| 4 | cache_locks | 0 | System | NO | N/A |
| 5 | categories | 14 | Site-scoped | YES | **REBUILD** |
| 6 | content_revisions | 0 | Site-scoped | YES | ADD COLUMN + INDEX |
| 7 | contents | 13 | Site-scoped | YES | **REBUILD** |
| 8 | facts | 23 | Site-scoped | YES | **REBUILD** |
| 9 | failed_jobs | 0 | System | NO | N/A |
| 10 | groups | 9 | Site-scoped | YES | **REBUILD** |
| 11 | inquiries | 0 | Site-scoped | YES | ADD COLUMN + INDEX |
| 12 | job_batches | 0 | System | NO | N/A |
| 13 | jobs | 0 | System | NO | N/A |
| 14 | media | 12 | Site-scoped | YES | ADD COLUMN + INDEX |
| 15 | menus | 1 | Site-scoped | YES | **REBUILD** |
| 16 | migrations | 27 | System | NO | N/A |
| 17 | page_blocks | 16 | Site-scoped | YES | ADD COLUMN + INDEX |
| 18 | password_reset_tokens | 0 | System | NO | N/A |
| 19 | redirects | 0 | Site-scoped | YES | **REBUILD** |
| 20 | sessions | 5 | System | NO | N/A |
| 21 | settings | 67 | Site-scoped | YES | **REBUILD** |
| 22 | sites | 1 | Site (itself) | NO | N/A |
| 23 | sync_logs | 0 | Site-scoped | YES | ADD COLUMN + INDEX |
| 24 | users | 1 | Users (special) | NO | N/A (see §5) |

**算术校验**：Site-scoped 14 + Users 1 + System 8 + Sites 1 = 24 ✅
**System 表清单**（8张）：cache, cache_locks, failed_jobs, job_batches, jobs, migrations, password_reset_tokens, sessions

## 3. Unique Constraint Matrix (7 tables, ALL REBUILD)

SQLite 限制：`ALTER TABLE` **不能**添加或删除 UNIQUE 约束。因此以下 7 张表全部需要 table rebuild。

| Table | Current Unique | Target Unique | Rebuild? | Reason |
|-------|---------------|---------------|----------|--------|
| contents | `slug` (global) | `(site_id, slug)` | **YES** | 2个unique均需调整 |
| contents | `slot` (global) | `(site_id, slot)` | **YES** | 同上 |
| categories | `slug` (global) | `(site_id, slug)` | **YES** | |
| groups | `(category_id, slug)` | `(site_id, category_id, slug)` | **YES** | composite unique |
| facts | `key` (global) | `(site_id, key)` | **YES** | |
| settings | `key` (global) | `(site_id, key)` | **YES** | 与缓存策略一致 |
| menus | `key` (global) | `(site_id, key)` | **YES** | |
| redirects | `from_path` (global) | `(site_id, from_path)` | **YES** | 空表也需rebuild |

**REBUILD 表清单（7张）**：
1. contents — 2个 unique（slug, slot），13行数据
2. categories — 1个 unique（slug），14行数据
3. groups — 1个 composite unique（category_id, slug），9行数据，含FK
4. facts — 1个 unique（key），23行数据
5. settings — 1个 unique（key），67行数据，全局缓存依赖
6. menus — 1个 unique（key），1行数据
7. redirects — 1个 unique（from_path），0行数据（空表但仍需rebuild）

**ADD COLUMN 表清单（7张，无 unique 调整）**：
1. content_revisions — 0行，仅 index
2. page_blocks — 16行，仅 index
3. media — 12行，仅 index
4. banners — 3行，仅 index
5. audit_logs — 26行，仅 index
6. inquiries — 0行，仅 index
7. sync_logs — 0行，仅 index

**算术校验**：REBUILD 7 + ADD COLUMN 7 = 14 Site-scoped ✅

### 3.1 redirects 详细策略（修正点）

**当前 schema**：
```sql
CREATE TABLE redirects (
  id INTEGER PRIMARY KEY,
  from_path VARCHAR NOT NULL,
  to_path VARCHAR NOT NULL,
  code INTEGER NOT NULL DEFAULT 301,
  hits INTEGER NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME,
  updated_at DATETIME
);
-- UNIQUE: redirects_from_path_unique (from_path)
```

**v1 错误**：标记为 LOW / ADD COLUMN only。
**v2 修正**：MEDIUM / REBUILD。

**原因**：虽然 redirects 当前 0 行，但目标 unique 从 `from_path` 变为 `(site_id, from_path)`，SQLite 无法用 ALTER TABLE 修改 unique 约束，必须 rebuild。

**Migration 步骤**：
```
1. CREATE TABLE redirects_new (含 site_id, unique(site_id, from_path))
2. INSERT INTO redirects_new SELECT *, 1 FROM redirects  (0行，无数据)
3. DROP TABLE redirects
4. ALTER TABLE redirects_new RENAME TO redirects
5. 验证：PRAGMA table_info, PRAGMA index_list
```

### 3.2 menus 详细策略（修正点）

**当前 schema**：
```sql
CREATE TABLE menus (
  id INTEGER PRIMARY KEY,
  position VARCHAR NOT NULL DEFAULT 'main',
  parent_id INTEGER,
  label VARCHAR NOT NULL,
  url VARCHAR,
  category_id INTEGER,
  target INTEGER NOT NULL DEFAULT 0,
  sort INTEGER NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME,
  updated_at DATETIME,
  key VARCHAR,
  parent_key VARCHAR
);
-- UNIQUE: menus_key_unique (key)
-- INDEX: menus_parent_key_is_active_index, menus_position_is_active_sort_index
```

**v1 错误**：标记为 MEDIUM / ADD COLUMN + INDEX。
**v2 修正**：MEDIUM / REBUILD。

**原因**：menus.key 有 global unique，目标为 `(site_id, key)`，需 rebuild。

### 3.3 groups 跨站一致性（新增说明）

**问题**：groups.category_id → categories.id（现有 FK, ON DELETE CASCADE）。
当 categories 加 site_id 后，groups 的 category_id 引用的 category 必须属于同一个 site。

**数据库层面**：SQLite 不支持复合外键引用非主键列的跨表约束（即无法直接表达 `groups.site_id = categories.site_id`）。

**解决方案**：
1. 数据库层：保留 `groups.category_id → categories.id` 单字段 FK
2. 应用层：在 Group Model 中添加验证，确保 `category.site_id == group.site_id`
3. 迁移时：先迁移 categories（回填 site_id=1），再迁移 groups（回填 site_id=1），由于现有数据全部属于 site 1，一致性自动满足
4. 测试：新增跨站 relation 禁止测试

## 4. Risk Classification (Recalculated)

基于真实 SQLite 操作重新计算，不先定风险再倒推策略。

| Risk | Count | Tables | Criteria |
|------|-------|--------|----------|
| **HIGH** | 5 | contents, categories, groups, facts, settings | 有数据 + REBUILD + 核心业务依赖 |
| **MEDIUM** | 6 | menus, redirects, page_blocks, media, banners, audit_logs | 有数据+ADD COLUMN，或空表+REBUILD |
| **LOW** | 3 | content_revisions, inquiries, sync_logs | 空表 + ADD COLUMN only |
| **合计** | 14 | | 5+6+3=14 ✅ |

### 4.1 HIGH (5 tables)

| Table | Rows | Operation | Why HIGH |
|-------|------|-----------|----------|
| contents | 13 | REBUILD | 核心内容，2个unique，被全站查询依赖，含 soft delete |
| categories | 14 | REBUILD | 核心分类，slug unique，导航/内容均依赖 |
| groups | 9 | REBUILD | 含 FK→categories，composite unique，知识体系依赖 |
| facts | 23 | REBUILD | GEO Engine 数据源，key unique，SchemaBuilder/LlmsBuilder 依赖 |
| settings | 67 | REBUILD | 全局配置，key unique，Setting::allCached() 缓存依赖，View Composer 依赖 |

### 4.2 MEDIUM (6 tables)

| Table | Rows | Operation | Why MEDIUM |
|-------|------|-----------|------------|
| menus | 1 | REBUILD | 有数据但量小，key unique，导航缓存依赖 |
| redirects | 0 | REBUILD | 空表但需rebuild（unique调整），HandleRedirects 缓存依赖 |
| page_blocks | 16 | ADD COLUMN | 有数据，首页装修依赖 |
| media | 12 | ADD COLUMN | 有数据，内容封面/OG图依赖 |
| banners | 3 | ADD COLUMN | 有数据，首页横幅依赖 |
| audit_logs | 26 | ADD COLUMN | 有数据，仅日志，无前台展示依赖 |

### 4.3 LOW (3 tables)

| Table | Rows | Operation | Why LOW |
|-------|------|-----------|---------|
| content_revisions | 0 | ADD COLUMN | 空表，无数据丢失风险 |
| inquiries | 0 | ADD COLUMN | 空表，无数据丢失风险 |
| sync_logs | 0 | ADD COLUMN | 空表，无数据丢失风险 |

## 5. Users Strategy (SPECIAL — NO site_id)

### 5.1 Current State
- users 表：1 行（单管理员）
- 认证：Laravel 默认 Auth + EnsureAdmin 中间件（仅检查 `auth()->check()`）
- 无 RBAC，无角色表，无 user_site 关系

### 5.2 Decision: 不加 users.site_id

**理由**：
1. 一个用户未来可能管理多个站点（super admin 跨站）
2. 直接加 site_id 会限制为"一个用户只能属于一个站"
3. 当前单管理员模式下，加 site_id=1 无实际意义

### 5.3 Target: user_site pivot (v1.1+)

```
user_site
├── id
├── user_id → users.id (ON DELETE CASCADE)
├── site_id → sites.id (ON DELETE CASCADE)
├── role VARCHAR (admin/editor)
├── created_at
└── updated_at

UNIQUE(user_id, site_id)
```

### 5.4 v1.0 处理
- **不创建** user_site 表
- users 表**不加 site_id**
- 记录为技术债务：v1.1 引入 RBAC + user_site pivot
- 当前所有 admin 操作隐式归属 default site

## 6. System Tables Strategy (8 tables, NO site_id)

| Table | Rows | Why Excluded |
|-------|------|-------------|
| migrations | 27 | Laravel migration 元数据，全局共享 |
| cache | 8 | Laravel 缓存系统，key 已含命名空间 |
| cache_locks | 0 | Laravel 缓存锁，全局 |
| sessions | 5 | Laravel Session，user_id 已关联用户 |
| jobs | 0 | Laravel 队列，payload 内含 site_id（未来） |
| failed_jobs | 0 | Laravel 失败队列，同上 |
| job_batches | 0 | Laravel 队列批，全局 |
| password_reset_tokens | 0 | Laravel 密码重置，email 全局唯一 |

**注意**：jobs/failed_jobs 未来需要在 payload 中携带 site_id，但表结构不加 site_id 列。

## 7. FK Strategy

所有 site-scoped 表的 site_id → sites.id

| Table | site_id Nullable? | ON DELETE | Reason |
|-------|------------------|-----------|--------|
| contents | NOT NULL | RESTRICT | 核心内容，禁止删站时丢失 |
| content_revisions | NOT NULL | CASCADE | 跟随 content |
| categories | NOT NULL | RESTRICT | 核心分类 |
| groups | NOT NULL | CASCADE | 跟随 category |
| facts | NOT NULL | RESTRICT | 核心事实 |
| settings | NOT NULL | CASCADE | 站点设置跟随站 |
| page_blocks | NOT NULL | CASCADE | 页面装修跟随站 |
| media | NOT NULL | RESTRICT | 媒体可能被引用 |
| menus | NOT NULL | CASCADE | 菜单跟随站 |
| banners | NOT NULL | CASCADE | 横幅跟随站 |
| inquiries | NOT NULL | CASCADE | 留言跟随站 |
| redirects | NOT NULL | CASCADE | 重定向跟随站 |
| audit_logs | NOT NULL | CASCADE | 日志跟随站 |
| sync_logs | NOT NULL | CASCADE | 日志跟随站 |

**原则**：核心业务数据（content/category/fact/media）用 RESTRICT 防止误删；派生/日志数据用 CASCADE。

## 8. Index Strategy

### 8.1 REBUILD 表（新表定义中包含）

| Table | New Indexes |
|-------|-------------|
| contents | unique(site_id, slug), unique(site_id, slot), + 现有5个index加site_id前缀 |
| categories | unique(site_id, slug), + 现有2个index |
| groups | unique(site_id, category_id, slug), + 现有1个index |
| facts | unique(site_id, key), + 现有2个index |
| settings | unique(site_id, key), + 现有1个index |
| menus | unique(site_id, key), + 现有2个index |
| redirects | unique(site_id, from_path) |

### 8.2 ADD COLUMN 表（migration 中 CREATE INDEX）

| Table | New Index |
|-------|-----------|
| content_revisions | index(site_id, content_id, created_at) |
| page_blocks | index(site_id, page, is_active, sort) |
| media | index(site_id, mime) |
| banners | index(site_id, position, is_active, sort) |
| audit_logs | index(site_id, created_at), index(site_id, target_type, target_id) |
| inquiries | index(site_id, status, created_at) |
| sync_logs | index(site_id, created_at), index(site_id, direction, status) |

## 9. Setting Cache Strategy (KNOWN RISK)

### 9.1 Current State
```php
// app/Models/Setting.php:22
public const CACHE_KEY = 'example.settings';  // 业务硬编码！

public static function allCached(): array
{
    return Cache::rememberForever(self::CACHE_KEY, function () {
        return static::pluck('value', 'key')->toArray();
    });
}
```

### 9.2 Problems
1. `example.settings` 是业务名称，违反 Core 零业务污染
2. 全局唯一 key，多站点 A 站设置会被 B 站读取
3. `pluck('value', 'key')` 没有 site_id 过滤

### 9.3 Target Design
```php
public static function cacheKey(int $siteId): string
{
    return "settings:site:{$siteId}";
}

public static function allCached(?int $siteId = null): array
{
    $siteId = $siteId ?? Site::defaultId();
    return Cache::rememberForever(self::cacheKey($siteId), function () use ($siteId) {
        return static::where('site_id', $siteId)->pluck('value', 'key')->toArray();
    });
}
```

### 9.4 与 settings 表 unique 约束的一致性
- 表 unique: `(site_id, key)`
- 缓存 key: `settings:site:{siteId}`
- 两者一致：每个站点独立的设置集

## 10. PageCache Strategy

### 10.1 Current State
```php
// key: pagecache:html:{version}:{sha1(host + path)}
// version: pagecache:version (全局)
```

### 10.2 Analysis
- **key 已含 host**：多站点不同 domain 时天然隔离 ✅
- **version 全局**：A 站改内容 → version+1 → B 站缓存也全部失效 ❌
- **同 domain 多站点**（未来）：key 无法区分，需加 site_id

### 10.3 Target Design
```php
private const VERSION_KEY = 'pagecache:version:{siteId}';

public static function version(int $siteId): int { ... }
public static function keyFor(Request $request, int $siteId): string
{
    return self::KEY_PREFIX . self::version($siteId) . ':' . $siteId . ':' . sha1($host . $path);
}
```

### 10.4 v1.0 简化
- 单站点模式下，version 全局无实际危害
- 但 key 中加入 site_id 预留多站点
- PageCache::flush() 改为按站 flush

## 11. AppServiceProvider Global Cache Audit (9 caches)

| Cache Key | TTL | Used By | Site-aware? | Target Key |
|-----------|-----|---------|-------------|------------|
| example.settings | forever | View Composer, SchemaBuilder, Copy | ❌ | settings:site:{id} |
| nav.tree | 600s | View Composer | ❌ | nav.tree:{siteId} |
| main.menu | 600s | View Composer | ❌ | main.menu:{siteId} |
| main.menu.blueprint | 600s | View Composer | ❌ | main.menu.blueprint:{siteId} |
| footer.blueprint | 600s | View Composer | ❌ | footer.blueprint:{siteId} |
| footer.menu | 600s | View Composer | ❌ | footer.menu:{siteId} |
| footer.extra | 600s | View Composer | ❌ | footer.extra:{siteId} |
| redirects.active | forever | HandleRedirects | ❌ | redirects.active:{siteId} |
| pagecache:version | forever | PageCache | ❌ | pagecache:version:{siteId} |

**共 9 个全局缓存需改为 site-aware**。Phase 5.4-F（缓存层改造）处理。

## 12. Site Context Strategy

### 12.1 How Laravel Knows Current Site

| Context | Source | Implementation |
|---------|--------|---------------|
| Web Request | domain → SiteResolver | 中间件解析，v1.0 直接返回 default |
| Admin Request | 同 Web | 复用 Web 的 Site Context |
| CLI | --site= 选项或 default | Command 显式指定，无参数时用 default |
| Queue Job | payload 携带 site_id | Job 构造时传入，handle 时恢复 |
| Tests | RefreshDatabase → default site | 测试中 default site 始终存在 |

### 12.2 禁止
- Model 自己猜当前 Site
- 零散使用 `withoutGlobalScopes()`
- 在 Controller 中硬编码 `site_id = 1`

## 13. Global Scope Strategy

### 13.1 Models Requiring SiteScope (14个)
Content, ContentRevision, Category, Group, Fact, Setting, PageBlock, Media, Menu, Banner, Inquiry, Redirect, AuditLog, SyncLog

### 13.2 Scope Implementation
```php
trait BelongsToSite
{
    protected static function bootBelongsToSite(): void
    {
        static::addGlobalScope('site', function ($builder) {
            $siteId = app(SiteContext::class)->id();
            if ($siteId !== null) {
                $builder->where('site_id', $siteId);
            }
        });
    }
}
```

### 13.3 Bypassing Scope (Explicit Only)

| Scenario | Method |
|----------|--------|
| Migration/Seeder | `Model::withoutGlobalScope('site')->insert(...)` |
| CLI 指定站点 | `--site=2` → SiteContext::set(2) |
| Super Admin 跨站 | 显式 `withoutGlobalScope('site')` + 权限检查 |
| Queue Job | Job payload 携带 site_id → handle 前 SiteContext::set() |

## 14. SQLite Rebuild Procedure (Standard)

适用于 7 张 REBUILD 表：

```
1. BEGIN TRANSACTION
2. PRAGMA foreign_keys = OFF  (临时关闭，避免 rebuild 期间 FK 检查)
3. CREATE TABLE {table}_new (新结构：含 site_id NOT NULL, 新 unique, 新 index)
4. INSERT INTO {table}_new SELECT *, 1 as site_id FROM {table}
5. VERIFY: SELECT COUNT(*) FROM {table}_new == SELECT COUNT(*) FROM {table}
6. VERIFY: SELECT COUNT(*) FROM {table}_new WHERE site_id != 1 OR site_id IS NULL = 0
7. VERIFY: 关键字段抽样对比 (slug, key, title 等)
8. DROP TABLE {table}
9. ALTER TABLE {table}_new RENAME TO {table}
10. CREATE INDEX 补充索引 (如未在表定义中)
11. PRAGMA foreign_keys = ON
12. PRAGMA foreign_key_check  → 必须 0 rows
13. COMMIT
```

**Rollback (down())**：反向 rebuild，去掉 site_id 列，恢复原 unique 约束。

## 15. Data Migration Verification Matrix

### 15.1 Per-table Verification (14 tables)

| Table | Before Rows | After Rows | site_id=1 Count | Key Fields |
|-------|------------|-----------|-----------------|------------|
| contents | 13 | 13 | 13 | slug, title, status |
| categories | 14 | 14 | 14 | slug, name, type |
| groups | 9 | 9 | 9 | slug, category_id |
| facts | 23 | 23 | 23 | key, value |
| settings | 67 | 67 | 67 | key, value |
| page_blocks | 16 | 16 | 16 | page, type, sort |
| media | 12 | 12 | 12 | path, mime |
| menus | 1 | 1 | 1 | key, label |
| banners | 3 | 3 | 3 | position, sort |
| audit_logs | 26 | 26 | 26 | action, target_type |
| content_revisions | 0 | 0 | 0 | N/A |
| inquiries | 0 | 0 | 0 | N/A |
| redirects | 0 | 0 | 0 | N/A |
| sync_logs | 0 | 0 | 0 | N/A |
| **合计** | **184** | **184** | **184** | |

### 15.2 Post-migration Checks
- `PRAGMA foreign_key_check` → 0 rows
- `PRAGMA foreign_keys` → 1
- 每张表 `SELECT COUNT(*) WHERE site_id IS NULL OR site_id != 1` → 0
- 应用启动无报错
- 203 测试全部通过

## 16. Rollback Strategy

### 16.1 Migration Rollback
- Type A (ADD COLUMN): `ALTER TABLE DROP COLUMN site_id` + `DROP INDEX`（SQLite 3.35+ 支持，PHP 8.4 内置版本满足）
- Type B (REBUILD): reverse rebuild（去掉 site_id，恢复原 unique）
- 所有 migration 必须实现 down()，并测试 rollback

### 16.2 Database Rollback
- 迁移前完整备份：`docs/audit/step5-4-pre-migration.sqlite`
- rollback 失败时从备份恢复

### 16.3 Git Rollback
- Phase 5.4 每个子阶段独立 commit
- tag: `gate1-step5-4-pre-change`

### 16.4 Rollback Test
- migrate → verify → rollback → verify → migrate → verify
- rollback 后：site_id 列全部移除，unique 恢复 global，数据完整
- 再次 migrate 后：site_id 全部回填，unique 恢复 site-scoped

## 17. Execution Phases (5.4-B through 5.4-I)

| Phase | Tables | Risk | Description |
|-------|--------|------|-------------|
| 5.4-B (LOW) | content_revisions, inquiries, sync_logs | LOW | 3张空表 ADD COLUMN |
| 5.4-C (MEDIUM) | page_blocks, media, banners, audit_logs | MEDIUM | 4张有数据表 ADD COLUMN + INDEX |
| 5.4-D (HIGH) | contents, categories, groups, facts, settings | HIGH | 5张核心表 REBUILD + unique调整 |
| 5.4-E (MEDIUM) | menus, redirects | MEDIUM | 2张表 REBUILD（1行+0行） |
| 5.4-F (HIGH) | Setting cache, PageCache, AppServiceProvider 9个缓存 | HIGH | 缓存层 site-aware 改造 |
| 5.4-G (MEDIUM) | All 14 tables + Global Scope + SiteContext | MEDIUM | 全量 site isolation 验证 |
| 5.4-H (MEDIUM) | All migrations | MEDIUM | migration rollback 测试 |
| 5.4-I (-) | Full regression | - | 203+ 测试，业务污染扫描，Final Gate |

**调整说明**：v1 中 5.4-B 含 redirects（错误），v2 将 redirects 移至 5.4-E（与 menus 一起，均为 REBUILD 但数据量小）。

## 18. Business Pollution Check

本阶段新增代码禁止出现：
- Example / Example / example
- Sample City / Sample Province / Sample Snack / Sample Marinade
- `$siteId = 1; // Example` 隐性绑定

允许：
- migration 中 `UPDATE table SET site_id = 1`（legacy data backfill，明确注释）
- 测试 fixture 中的业务数据

## 19. Known Technical Debt

1. **users 无 site_id**：v1.1 引入 user_site pivot + RBAC
2. **jobs 表无 site_id 列**：payload 携带 site_id，表结构不加
3. **sessions 无 site_id**：user_id 已关联，不需要
4. **ExampleUrlGenerator**：业务命名，需重命名为 SeoUrlGenerator（Phase 5.10）
5. **Setting::CACHE_KEY = 'example.settings'**：Phase 5.4-F 改造
6. **AppServiceProvider 9个全局缓存**：Phase 5.4-F 改造
7. **PageCache version 全局**：Phase 5.4-F 改造
8. **groups.category_id 跨站一致性**：数据库层无法表达复合约束，应用层验证

## 20. Arithmetic Consistency Summary

| Metric | Count | Verification |
|--------|-------|-------------|
| Total tables | 24 | sqlite_master 查询确认 |
| Site-scoped | 14 | 14张业务数据表 |
| Users special | 1 | users |
| System | 8 | cache, cache_locks, failed_jobs, job_batches, jobs, migrations, password_reset_tokens, sessions |
| Sites | 1 | sites |
| **Subtotal** | **24** | 14+1+8+1=24 ✅ |
| REBUILD | 7 | contents, categories, groups, facts, settings, menus, redirects |
| ADD COLUMN | 7 | content_revisions, page_blocks, media, banners, audit_logs, inquiries, sync_logs |
| **Site-scoped subtotal** | **14** | 7+7=14 ✅ |
| Unique adjustments | 7 | 与 REBUILD 表完全一致 ✅ |
| HIGH risk | 5 | contents, categories, groups, facts, settings |
| MEDIUM risk | 6 | menus, redirects, page_blocks, media, banners, audit_logs |
| LOW risk | 3 | content_revisions, inquiries, sync_logs |
| **Risk subtotal** | **14** | 5+6+3=14 ✅ |
| Global caches | 9 | Setting + 6 nav/menu + redirects + pagecache |

## 21. Conclusion

**Phase 5.4-A Correction: PASS**

- 24 张表全部从真实 sqlite_master 重新盘点
- 分类算术一致：14+1+8+1=24 ✅
- REBUILD 7 张（全部因 unique 约束调整），ADD COLUMN 7 张，7+7=14 ✅
- redirects 修正为 MEDIUM/REBUILD（空表也需 rebuild）
- menus 修正为 MEDIUM/REBUILD
- 系统表修正为 8 张
- 风险分级重新计算：5 HIGH + 6 MEDIUM + 3 LOW = 14 ✅
- 增加 groups.category_id 跨站一致性说明
- 执行阶段调整为 8 个（5.4-B 到 5.4-I）
- 零业务代码修改，仅审计文档更新

**下一步**：Phase 5.4-B（3张空表 ADD COLUMN），等待授权。
