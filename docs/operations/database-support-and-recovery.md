# 数据库支持矩阵 · 升级与恢复指南

> 适用版本：GEO Website OS 候选版本（v1.0.0 发布形态之后，62 个迁移）。
> 本文的每一条「支持 / 候选支持」结论都对应**已实际执行的测试**，不是配置推断。
> 未实测的组合一律标注为「未验证」，不作承诺。

---

## 1. 支持矩阵

| 驱动 | 实测版本 | 状态 | 门禁位置 | 覆盖到的验证内容 |
| --- | --- | --- | --- | --- |
| SQLite | 3.53.4（PHP 8.4.25 内置） | **正式支持** | CI `test` job（精确计数门禁，失败即阻断发布） | 空库迁移、安装器、全量回归、升级 / 回滚 / 备份恢复演练、跨库结构一致性断言、真实多进程并发 |
| PostgreSQL | 16.10 | **正式支持** | CI `pgsql` job（postgres:16 服务容器，零失败门禁） | 空库迁移、安装器、核心回归（`PgsqlCore`）、跨库结构一致性断言、真实多进程并发、升级 / 回滚 / 原生备份恢复演练 |
| MySQL | 8.4.8 | **候选支持** | 无 CI 门禁 | 空库迁移、安装器、核心回归、跨库结构一致性断言、真实多进程并发、升级 / 回滚 / 原生备份恢复演练 |

**状态的含义**

- **正式支持**：有自动化门禁，破坏即让流水线变红。
- **候选支持**：已在本机真实服务器上跑通对应验证，但**没有持续门禁**。
  升级前请自行在预发环境跑一遍第 4、5 节的验证步骤。

> **不支持的说法**：本文档不声称「任何 Laravel 支持的数据库都能跑」。
> 三种驱动在**唯一约束的物理形态、外键开关机制、全文检索、事务锁语义**上都有
> 实质差异，见第 2 节。跨库行为一致性由 `tests/Feature/DatabaseStructureParityTest.php`
> 逐项锁定。

---

## 2. 三库的物理差异（逻辑等价 ≠ 物理相同）

### 2.1 唯一约束：MySQL 用生成列，SQLite / PostgreSQL 用部分唯一索引

MySQL 不支持 partial unique index（带 `WHERE` 谓词的唯一索引），
所以凡是需要「只约束非空行」的唯一性，MySQL 侧用 `VIRTUAL` 生成列 + 唯一索引实现。

| 逻辑唯一键 | SQLite | MySQL | PostgreSQL |
| --- | --- | --- | --- |
| `contents` site+slug+locale | `UNIQUE(site_id, slug, locale)` | `contents_site_slug_locale_unique (site_id, slug, locale)` | `contents_site_slug_locale_unique (site_id, slug, locale)` |
| `contents` site+external_id | `UNIQUE(site_id, external_id)` | `contents_site_external_unique (site_id, external_id)` | `contents_site_external_unique (site_id, external_id)` |
| `contents` site+slot | `UNIQUE(site_id, slot)` | `contents_site_id_slot_unique (site_id, slot)` | `contents_site_id_slot_unique (site_id, slot)` |
| `pages` site+entity（entity 非空时） | partial index `(site_id, entity_id)` | `entity_guard` 生成列 | partial index `(site_id, entity_id)` |
| `pages` site+system_key+locale（system_key 非空时） | partial index `(site_id, system_key, locale)` | `system_key_guard` 生成列 | partial index `(site_id, system_key, locale)` |
| `seo_metas` site+content_id+locale（content_id 非空时） | partial index `(site_id, content_id, locale)` | `content_guard` 生成列 | partial index `(site_id, content_id, locale)` |
| `seo_metas` site+entity_id+locale（entity_id 非空时） | partial index `(site_id, entity_id, locale)` | `entity_guard` 生成列 | partial index `(site_id, entity_id, locale)` |
| `seo_metas` site+page_id+locale（page_id 非空时） | partial index `(site_id, page_id, locale)` | `page_guard` 生成列 | partial index `(site_id, page_id, locale)` |
| `seo_metas` site+locale（三类绑定皆空时） | partial index `(site_id, locale)` | `site_guard` 生成列 | partial index `(site_id, locale)` |
| `sites` 唯一默认站点 | partial index `(is_default) WHERE is_default = 1` | `is_default_guard` 生成列 | partial index `(is_default) WHERE is_default IS TRUE` |

MySQL 生成列表达式（实测）：

```text
pages.entity_guard      = if(entity_id is not null, concat(site_id,'|',entity_id), NULL)
pages.system_key_guard  = if(system_key is not null, concat(site_id,'|',system_key,'|',locale), NULL)
seo_metas.content_guard = if(content_id is not null, concat(site_id,'|',content_id,'|',locale), NULL)
seo_metas.entity_guard  = if(entity_id is not null,  concat(site_id,'|',entity_id,'|',locale), NULL)
seo_metas.page_guard    = if(page_id is not null,    concat(site_id,'|',page_id,'|',locale), NULL)
seo_metas.site_guard    = if(content_id is null and entity_id is null and page_id is null,
                              concat(site_id,'|',locale), NULL)
sites.is_default_guard  = if(is_default = 1, 1, NULL)
entities.active_slug    = case when deleted_at is null then slug else NULL end
```

> **列清单不同 ≠ 语义不同**：上表每一行的三列形态互为逻辑等价。
> `DatabaseStructureParityTest` 用「逻辑唯一键 → 各驱动物理列集」的显式映射表断言，
> 而不是假设三库结构相同。

### 2.2 外键开关：PostgreSQL 没有会话级开关

| 驱动 | 关闭外键 | 说明 |
| --- | --- | --- |
| SQLite | `PRAGMA foreign_keys = OFF` | 事务内是 no-op，必须在事务外切换 |
| MySQL | `SET FOREIGN_KEY_CHECKS = 0` | 会话级 |
| PostgreSQL | **不存在** | `SET session_replication_role` 是 `PGC_SUSET`，普通应用角色会报 `42501`；即使是超级用户也不能解决 `2BP01`（被引用表不能被 drop） |

PostgreSQL 侧的正确做法是**先删掉引用方外键、做完重建再恢复**
（`MigrationPortability::dropReferencingForeignKeys()` / `restoreReferencingForeignKeys()`）。
V1.4 已把 `app/Support/MigrationPortability.php` 的 pgsql 分支改为 no-op 并写明根因。

### 2.3 全文检索

SQLite 额外拥有 FTS5 虚表 `search_index` 及其影子表
（`search_index_config` / `search_index_data` / `search_index_idx` / `search_index_content` / `search_index_docsize`）。
MySQL 与 PostgreSQL 走普通索引路径，**没有**这些表。属引擎差异，不是漂移。

> ⚠️ FTS5 维护注意：对 FTS5 虚表执行 `DELETE FROM <表>` 会清空内部索引，
> 后续插入报 `invalid fts5 file format (found 0, expected 4 or 5)`。
> 清空后必须执行 `INSERT INTO <fts5表>(<fts5表>) VALUES('rebuild')` 重建索引。
> `delete-all` 只能用于 contentless / external content 表。

### 2.4 事务与锁语义（影响并发代码）

| 差异点 | 表现 | 本项目的处理 |
| --- | --- | --- |
| SQLite `DEFERRED` 事务 | 「先读后写」升级写锁**不走** `busy_timeout`，直接 `SQLITE_BUSY`（`database is locked`） | SQLite 连接使用 `transaction_mode=IMMEDIATE` + `busy_timeout`（可用 `DB_TRANSACTION_MODE` / `DB_BUSY_TIMEOUT` 覆盖） |
| PostgreSQL 唯一冲突 | 23505 之后**整个事务被置为 aborted**，后续语句一律 `25P02 current transaction is aborted` | 唯一冲突必须抛出事务外的信号（`UniqueContentConflict`），在新事务里 reload 赢家；**不能**在同一事务内继续执行 SQL |
| MySQL InnoDB 对拍升级 | 多连接同时向同一 UNIQUE 索引插入，先取共享锁做唯一性检查再同时升级为排他锁 → `1213 Deadlock` | 事务失败按 SQLSTATE `40001` / `40P01` 有限重试（`GeoflowSync::isRetryableTransactionFailure()`，最多 3 次，退避 20ms×次数） |
| PostgreSQL 整型列字面量 | `where "id" = check` 触发严格类型转换，抛 `SQLSTATE 22P02 invalid input syntax for type bigint` → **500**；SQLite / MySQL 只是查不到 → **404** | 整型主键的路由隐式绑定**在进入数据库之前**判定：片段不是合法整数字面量即按「未找到」处理（`App\Support\GuardsNumericRouteBinding`，挂在 `App\Models\BaseModel` 上）。判定文法复刻 PG 的 `bigint` 输入规则（可选符号 + 十进制数字 + 允许首尾空白 + 不溢出），**不用** `filter_var(FILTER_VALIDATE_INT)`（后者会误拒 `'010'` / `' 10'` / `'+10'`） |

> 这三条在 SQLite 上**不会暴露**（SQLite / MySQL 的约束失败后事务仍可用），
> 是真实并发缺陷被 SQLite 基线掩盖的典型形态。跨库验证因此是必需的。

**应用层规则（已在 V1.4 全面核查）**：捕获唯一约束冲突后必须**立即返回**，
不得在同一请求里继续执行任何 SQL。V1.4 已核查全部三个捕获点
（`FactController::store` / `FactController::update` / `EntityRelationController::save`），
均为「catch 后立即 return」，且项目**没有**请求级数据库事务中间件，
因此生产路径不会残留 aborted 事务。

> 但这条规则在**测试**里极易踩坑：`RefreshDatabase` 把整个用例包在一个事务中，
> 用例内的唯一冲突会 abort 掉这个事务，导致后续断言全部抛 25P02。
> V1.4 实测发现 `FactValidationTest::test_unique_constraint_race_is_converted_to_validation_error`
> 在 SQLite / MySQL 上通过、在 PostgreSQL 上直接报错——**断言根本没跑到**，
> 等于该行为在 PG 上从未被验证。
> 修法：在「冲突行写入之后、失败 INSERT 之前」建立 SAVEPOINT，
> 断言前用 `ROLLBACK TO SAVEPOINT` 恢复事务；冲突行写在 SAVEPOINT 之前，
> 因此不会随回滚丢失，断言语义保持不变。


### 2.5 主键

SQLite 的 `id INTEGER PRIMARY KEY AUTOINCREMENT` 是 rowid 别名，
`PRAGMA index_list` **不会**列出它；MySQL / PostgreSQL 有主键索引。
因此三库唯一索引「总数」天然不同，比较结构时必须排除主键。

### 2.6 返回行序不保证一致：同一份数据，三库可能先返回不同的行

三库的**默认返回行序都没有保证**，且在没有 `ORDER BY` 时实际表现可能不同：

| 数据库 | 常见成因 |
| --- | --- |
| PostgreSQL | 计划器可能选索引扫描，结果顺序按**索引键**（如 `(site_id, key, locale)` → `'en' < 'zh-CN'`）；也可能选顺序扫描，按物理插入顺序 |
| MySQL | InnoDB 聚簇索引顺序 + 二级索引回表顺序；`ORDER BY` 缺失时结果不稳定 |
| SQLite | 无 `ORDER BY` 的查询通常按 rowid，但受查询计划与索引选择影响 |

**后果（本轮实证）**：一个「取第一条记录就改写」的测试与逻辑，在 SQLite 上取到
权威行、在 PostgreSQL 上取到翻译行，两边给出相反结论。若产品代码里存在
「缺省即默认」「取第一条」这类隐含语义，**SQLite 单库基线无法暴露它**。

**纪律**：

1. 业务代码不得依赖无 `ORDER BY` 的结果顺序。取「权威行」必须用显式条件
   （如 `where('locale', 默认语言)`），而不是「取第一条」。
2. 跨语言资源的编辑路径必须以**行自身**的语言为准，不得用「请求缺省」推导语言。
3. 跨库回归里凡涉及「多行同 key」「多语言」「多币种」的断言，必须**显式定位行**，
   不允许依赖 `first()` / `firstOrFail()` 的返回顺序。

---

## 3. 安装

### 3.1 SQLite（默认）

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan geo:install --no-interaction
```

### 3.2 PostgreSQL

```bash
# .env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=geo_os
DB_USERNAME=geo_os
DB_PASSWORD=***

php artisan migrate --force
php artisan geo:install --no-interaction
```

### 3.3 MySQL

```bash
# .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=geo_os
DB_USERNAME=geo_os
DB_PASSWORD=***

php artisan migrate --force
php artisan geo:install --no-interaction
```

> 三种驱动下 `migrate` 与 `geo:install` 均已实测通过（见第 6 节）。

---

## 4. 升级

### 4.1 迁移数量

| 形态 | 迁移数 |
| --- | --- |
| v1.0.0 发布形态 | 56 |
| 候选版本 | 62 |

从发布形态升级：`php artisan migrate --force`（依次执行 2026_10_08 … 2026_10_27 的 6 个迁移）。

### 4.2 ⚠️ 升级前必查：`contents.slot` 唯一约束收紧

`2026_10_27_000001_tighten_contents_slot_unique_to_site_scope` 把
MySQL / PostgreSQL 上 `contents` 的 slot 唯一约束从
`UNIQUE(site_id, slot, locale)` **收紧**为站点级 `UNIQUE(site_id, slot)`，
与 SQLite 现状及项目契约一致（`contents.slot` 是站点级结构槽位，
`contents.slug` 才是带 locale 的可翻译资源标识）。

**收紧前会先探测存量冲突**。若存在「同站同 slot、不同 locale」的多行，
迁移会**中止并报错**，不会自动删除或改写任何数据：

```sql
-- MySQL / PostgreSQL 通用：升级前先自查
SELECT site_id, slot, COUNT(*) AS c
FROM contents
WHERE slot IS NOT NULL AND slot <> ''
GROUP BY site_id, slot
HAVING COUNT(*) > 1;
```

- 返回**空集** → 可直接升级。
- 返回多行 → 需人工确认每行是否应保留（同一叙事插槽只应保留一条），清理后再升级。

SQLite 分支不改动结构，只做校验；若 SQLite 上的结构不是站点级，迁移会**报错**而不是静默放过。

> ⚠️ **停写窗口**：MySQL / PostgreSQL 上的收紧是「删旧索引 + 建新索引」，
> 两者都会**持有写锁**（MySQL 为 `ALTER TABLE`，PG 为 `DROP INDEX` / `ADD CONSTRAINT`）。
> `contents` 数据量大时请在停写窗口内执行，或按第 5 节先做备份。
> 该迁移**不做数据搬迁**，只换约束，风险主要在锁表时长而非数据本身。

### 4.3 迁移失败怎么办

迁移失败时：

1. 该迁移**不会**出现在 `migrations` 表里（失败即回滚，不留半应用状态）；
2. 修正问题后直接重跑 `php artisan migrate --force`；
3. 若失败迁移已建了对象，用 `php artisan migrate:rollback --step=N` 回退，
   或按该迁移的 `down()` 语义手工清理。

演练脚本 `scripts/audit/verify-upgrade-rollback.php` 会用一个**故意失败**的迁移
验证这条性质：失败迁移不进台账、数据不损坏、移除后可恢复。

---

## 5. 备份 · 恢复 · 回滚

### 5.1 SQLite

```bash
# 备份（项目自带命令，带元数据清单）
php artisan geo:backup

# 恢复（整库回滚到备份点）
php artisan geo:rollback --backup=storage/app/backups/geo-backup-<时间戳>.sqlite --force

# 或直接文件级备份（停写窗口内）
cp database/database.sqlite /backup/database-<日期>.sqlite
```

### 5.2 PostgreSQL

```bash
# 备份（自定义格式，支持并行恢复）
pg_dump --format=custom --file=/backup/geo_os-<日期>.dump "$DATABASE_URL"

# 恢复
pg_restore --dbname="$DATABASE_URL" --clean --if-exists /backup/geo_os-<日期>.dump

# 校验（恢复后）
php artisan migrate:status
```

### 5.3 MySQL

```bash
# 备份
mysqldump --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 geo_os > /backup/geo_os-<日期>.sql

# 恢复
mysql --default-character-set=utf8mb4 geo_os < /backup/geo_os-<日期>.sql

# 校验
php artisan migrate:status
```

### 5.4 三者的边界（不要混淆）

| 动作 | 含义 | 本项目是否支持 |
| --- | --- | --- |
| **迁移逆操作** | `migrate:rollback` 把 schema 退回上一个迁移点 | 支持（逐个迁移的 `down()` 需自行正确实现，见 `MigrationRollbackFidelityTest`） |
| **备份恢复** | 用备份文件覆盖当前库 | 支持（各库原生工具，见上） |
| **应用版本回退** | 换回旧代码 + 旧 schema | 需人工编排：先备份 → 回退迁移（`migrate:rollback --step=N`）→ 换代码 → 恢复缓存 |
| **自动回滚** | 迁移失败自动回到迁移前状态 | **不支持自动**。迁移失败会停在失败点，需人工处置（见 4.3） |

> 明确声明：本项目**没有**「数据库自动回滚」能力。
> 任何声称某数据库支持自动回滚的说法都需要额外的测试证据，目前没有。

---

## 6. 验证脚本

| 脚本 | 作用 | 用法 |
| --- | --- | --- |
| `scripts/audit/verify-upgrade-rollback.php` | 升级 / 故障恢复 / 回滚 / 原生备份恢复演练，输出 JSON 报告 | `php scripts/audit/verify-upgrade-rollback.php <driver> <host> <port> <db> <user> [password] [rollback-steps]` |
| `scripts/audit/verify-test-baseline.php` | JUnit 计数门禁（CI 与本地同源） | `php scripts/audit/verify-test-baseline.php --junit=storage/logs/junit.xml` |
| `tests/Feature/DatabaseStructureParityTest.php` | 跨库结构一致性断言（唯一约束 / 外键 / 表存在性 + 行为断言） | `php vendor/bin/phpunit --filter DatabaseStructureParityTest` |

跨库跑测试的配置：

```bash
# PostgreSQL
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
  php vendor/bin/phpunit -c phpunit.pgsql.xml --testsuite PgsqlCore

# MySQL
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
  php vendor/bin/phpunit -c phpunit.mysql.xml
```

### 6.1 并行跑多套测试时必须隔离文件缓存

`PageCache` **强制**使用 file store（不跟随 `CACHE_STORE`），它的「页面缓存版本计数器」
是跨进程共享的全局状态。默认所有进程共用 `storage/framework/cache/data`，
于是并行跑多套测试时：

- 一个进程的 `flush()` 会让另一个进程刚写入的页面缓存立刻失效
  → 断言 `HIT` 得到 `MISS`；
- 版本号被别的进程 +1 → 断言「版本 1→2」错位。

**这不是产品缺陷，是测试隔离性问题**（V1.4 实测确认：单独复跑同样通过）。
根治办法是给每个并行实例一份独立的文件缓存目录：

```bash
CACHE_FILE_PATH=/tmp/geo-cache-pg CACHE_FILE_LOCK_PATH=/tmp/geo-cache-pg \
  php vendor/bin/phpunit -c phpunit.pgsql.xml
```

`config/cache.php` 的 file store 路径与 lock 路径已支持这两个环境变量覆盖，
**未设置时行为与之前完全一致**（生产环境不需要设置）。

> 除此之外，并行实例之间还有两处共享状态，已确认无冲突：
> SQLite 并发测试库按进程 PID 命名（`storage/framework/testing/concurrency-<pid>.sqlite`）；
> 编译后的视图文件按内容寻址，共享无副作用。

---

## 7. 已知限制

- **MySQL 无 CI 门禁**：MySQL 的验证是本机实测 + 演练脚本产出，未进入持续集成。
  若要把 MySQL 提为「正式支持」，需要补一个 `mysql` CI job（`mysql:8` 服务容器）。
- **PostgreSQL / MySQL 的全量回归**未纳入 CI 的时间预算，
  CI 只跑 `PgsqlCore` 子集（139 个用例）；全量回归由本地隔离实例定期执行并留证。
- **真实并发验证**依赖操作系统级的独立进程与独立数据库连接。
  Windows 上无 `pcntl` / `posix`，仍可做（`proc_open` 起独立 PHP 进程），
  但本机**无法**验证 `pcntl` / `redis` 相关路径，也未覆盖多机分布式竞争。
- **`content_entity` / `content_tag` / `pages` 三表在三库上均未设外键**，
  属既有设计现状（三库一致，非跨库漂移），本次未改动。
