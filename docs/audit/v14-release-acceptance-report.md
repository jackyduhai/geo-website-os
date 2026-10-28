# V1.4 最终候选版本验收报告

> 授权单：V1.4「最终候选版本验收与必要的收尾修复」
> 验收对象：隔离分支 `feature/v1.4-db-compat`（唯一验收对象）
> 边界：全程**未推送 / 未合并主分支 / 未打 tag / 未发布 / 未部署 / 未触碰真实业务数据库**
> 相关文档：[跨库兼容性报告](./v14-cross-db-concurrency-recovery-report.md) · [数据库支持矩阵](../operations/database-support-and-recovery.md)

---

## 0. 最终判定

**PASS WITH CONDITIONS（有条件通过）**

- **候选版本代码本身达到定稿条件**：三库全量回归用例数完全一致、零失败零错误；
  8 个被 SQLite 基线掩盖的真实缺陷已修复并各自有变异测试证明「新测试咬得住」；
  真实并发、跨库结构一致性、升级 / 回滚 / 故障注入 / 原生备份恢复在三库上全部实测通过；
  新增收紧迁移在存量冲突时**中止报错**，不静默删改数据。
- **但「对外发布」尚不能现在执行**，且**不得**把 PostgreSQL 写成「已通过 CI 门禁」：
  远端 GitHub Actions 在本分支上**一次都没有跑过**（推送冻结）。本地等价复现了
  `pgsql` job 的全部 10 个步骤并全部通过，但「本地等价」不等于「远端流水线绿灯」。
- **最少且必要的阻断项只有 1 条**（解除推送冻结 → 跑完 `test` + `pgsql` 两个 job → 确认全绿），
  详见 §9。其余全部是非阻断残余风险。

---

## 1. 候选版本身份与工作区状态

| 项 | 值 |
| --- | --- |
| 分支 | `feature/v1.4-db-compat` |
| HEAD | 最后一个**含代码或配置变更**的提交是 `034aa05`（`ci(pgsql): include this round's three guard suites in the PgsqlCore gate`）；其后仅追加本报告与运维文档提交 |
| 工作区 | **干净**（`git status --short` 无输出） |
| 领先 main | 8 个提交（其中 6 个含代码 / 配置 / 迁移变更，2 个为文档与基线） |
| 与 main 的文件差异 | 63 个文件，`+5270 / -297`（`+63` 行为验收期补强，见下） |

提交序列（自底向上）：

| 提交 | 内容 |
| --- | --- |
| `b0da0e7` | fix(security): upgrade symfony/http-foundation to 7.4.19（阻断 CVE-2026-108082） |
| `abfc169` | feat(v1.4): cross-database compatibility, real concurrency suite and upgrade/rollback drill |
| `01fef0b` | ci(pgsql): grant public schema privileges explicitly before migrating |
| `bd5d833` | fix(v1.4): guard numeric route bindings so non-numeric segments 404 on all drivers（缺陷 5） |
| `6b90ab1` | fix(v1.4): fact edit path must keep the row's locale and broadcast shared columns（缺陷 6 + 7） |
| `86e62d8` | fix(v1.4): converge the "tables of the current database" contract across drivers（缺陷 8） |
| `378e1cf` | test: update baseline after V1.4 cross-db round (1761/9470) |
| `034aa05` | ci(pgsql): include this round's three guard suites in the PgsqlCore gate（**验收期补强**，见 §6.3） |

### 1.1 生产代码变更摘要（main...HEAD）

| 文件 | 行数 | 作用 |
| --- | --- | --- |
| `app/Services/Sync/GeoflowSync.php` | +484/-… | 唯一冲突改为抛 `UniqueContentConflict`；新增并发赢家解析；新增事务失败有限重试 |
| `app/Support/GuardsNumericRouteBinding.php` | +122（新） | 整型主键的路由隐式绑定守卫（PG 的 `22P02` → 404） |
| `app/Support/Database/CurrentDatabaseTables.php` | +71（新） | 当前库裸表名清单（收敛 MySQL 的跨库返回） |
| `app/Models/BaseModel.php` | +19（新） | 业务模型统一基类，承载上述守卫；26 个模型改为继承 |
| `app/Support/MigrationPortability.php` | +19/-… | PG 外键开关改 no-op（`session_replication_role` 是 `PGC_SUSET`） |
| `app/Services/Sync/UniqueContentConflict.php` | +39（新） | 信号异常，承载冲突时的 `$attributes` / `$externalId` |
| `app/Http/Controllers/Admin/FactController.php` | +64/-… | 事实编辑路径：语言沿用行自身 + 共享列写入路由到权威行 |
| `app/Http/Controllers/Admin/SiteController.php` | +11/-… | 站点删除保护的表清单改用 `CurrentDatabaseTables` |
| `app/Support/Templates/TemplatePreviewSite.php` | +10/-… | 预览站回收同上 |
| `config/database.php` | +13/-… | SQLite 连接项 env 可覆盖 + `DB_TRANSACTION_MODE`（默认 DEFERRED） |
| `config/cache.php` | +10/-… | file store 路径支持 `CACHE_FILE_PATH` 覆盖（并行回归隔离用） |
| `database/migrations/2026_10_27_000001_tighten_contents_slot_unique_to_site_scope.php` | +248（新） | `contents.slot` 唯一约束三库统一为站点级 |
| `scripts/audit/verify-upgrade-rollback.php` | +597（新） | 升级 / 故障注入恢复 / 回滚 / 原生备份恢复演练 |
| `.github/workflows/ci.yml` | +226/-… | 新增 `pgsql` job；`coverage` job 补超时与 `continue-on-error` |
| `scripts/audit/test-baseline.json` | +8/-… | 基线 1714/9149 → 1761/9470 |

其余 27 个 `app/Models/*.php` 各 +3/-1，差异仅是「`extends Model` → `extends BaseModel`」与
`use` 语句顺序，属同一处基类改造的连带修改。

---

## 2. 证据时效性审计

验收要求「所有关键测试必须对应同一最终代码状态」。逐项审计结果：

| 证据 | 产出时间 | 对应代码状态 | 有效性 | 处置 |
| --- | --- | --- | --- | --- |
| v14 / v15 三库批次 | 10-27 21:00 前 | `abfc169` 之前 | **作废** | 三套回归共用同一工作树，互相删除 `plugins/<slug>` 目录 |
| v16 三库批次 | 10-28 06:40-07:20 | `bd5d833` 前后 | **作废** | 本机 PG 实例 autovacuum PANIC 触发崩溃恢复；且运行期改过副本代码 |
| v17 三库批次 | 10-28 08:20-10:12 | `6b90ab1` / `86e62d8` 期间 | **过程参考** | 定位出缺陷 6；但不是最终代码 |
| **v18 三库批次** | 10-28 10:24-11:44 | **最终代码** | **有效** | 三库独立工作树 + 独立输出目录 + 独立缓存目录；代码状态经 blob 比对确认一致 |
| 上轮升级 / 回滚 / 备份恢复演练 | 10-27 21:17 | `abfc169` 提交前 | **过期** | 此后有 3 个生产代码提交（含 `TemplatePreviewSite::discard` 改动）；已在最终代码上重跑（§5） |
| 真实并发 P0 / 跨库结构一致性 | 同上 | — | **有效** | 这两类用例**包含在 v18 全量回归内**，直接取 v18 的 JUnit 证据 |
| CI `pgsql` job | 从未执行 | — | **无证据** | 远端推送冻结；已做本地等价复现（§6） |

### 2.1 「最终代码」的三副本一致性证明

v18 回归在三个独立工作树上执行，需证明它们跑的就是最终代码。
用 `git hash-object` 逐文件比对 HEAD 的 blob（`app/` + `tests/` 共 **414 个文件**）：

| 工作树 | 一致 | 不一致 |
| --- | --- | --- |
| `GEO-OS-security-upgrade`（SQLite） | 414 | 0 |
| `GEO-OS-security-upgrade-pg`（PG） | 414 | 0 |
| `GEO-OS-security-upgrade-my`（MySQL） | 414 | 0 |

HEAD 相对上一提交的额外改动只有 `scripts/audit/test-baseline.json` 与两份 `docs/`，
**不涉及 `app/` 与 `tests/`**，故 v18 的执行结果对应当前 HEAD 的产品代码。

### 2.2 验收期补强提交 `034aa05` 对 v18 证据的影响

`034aa05` 只改了 `phpunit.pgsql.xml` 的 **`<testsuites>` 段**（把三个守护类加入 `PgsqlCore`）。
该文件在 v18 回归中的实际用法是「**按文件路径执行**」（runner 逐批传文件列表，
**不带 `--testsuite` 参数**），因此 `<testsuites>` 段完全未被使用；
被实际使用的 `<php><env>` 段（DB 连接等）**未改动**。
→ **v18 证据对 `034aa05` 仍然有效**，且其执行的产品代码与 HEAD 完全一致。

该提交的验证由 §6.2 的 CI 等价复现承担（新 `PgsqlCore` 174 tests / 970 assertions / 零失败零跳过）。

---

## 3. 最终有效测试证据（全量回归）

证据文件：`D:/GEO-OS-rewrite/_v14-tools/junit/merged-{sqlite,pgsql,mysql}-v18.xml`
（分批独立进程跑 PHPUnit，合并 JUnit，再用官方门禁脚本 `scripts/audit/verify-test-baseline.php` 统计）

| 库 | tests | assertions | failures | errors | skipped |
| --- | --- | --- | --- | --- | --- |
| SQLite | **1761** | 9470 | **0** | **0** | 0 |
| PostgreSQL 16.10 | **1761** | 9365 | **0** | **0** | 9 |
| MySQL 8.4.8 | **1761** | 9365 | **0** | **0** | 9 |

- **用例数三库完全一致**（1761）。
- **PostgreSQL 与 MySQL 逐用例完全一致**（含每条用例的断言数与跳过状态）。
- 与 SQLite 的 **105 个断言差已逐用例归因闭合**，无不明差异：

| 差异来源 | 断言差 | 性质 |
| --- | --- | --- |
| `SiteCreationProductionParityTest`（7 例）+ `UpgradeRollbackE2ETest`（2 例）在 PG / MySQL 上 `markTestSkipped` | 100 | 设计使然（依赖 SQLite 文件库备份与生产等价场景） |
| `CurrentDatabaseTablesTest::test_site_scoped_tables_all_have_site_id_column` | 2 | SQLite 多 1 张站点私有表（`search_index`），每表 2 断言 |
| `CurrentDatabaseTablesTest::test_table_names_contain_no_schema_qualifier` | 1 | SQLite 多 1 张表 |
| `SearchProductization18HTest::test_default_engine_is_sqlite_fts5` | 1 | 按驱动分支断言 |
| `TemplateLivePreviewTest::test_discard_removes_all_owned_rows` | 1 | SQLite 多扫 1 张 `search_index` |

  `search_index` 是 **SQLite FTS5 虚拟表**：迁移 `2026_09_24_000016` 明确只在 SQLite 编译
  FTS5 时创建，PG / MySQL 无 FTS5，由 `DatabaseLikeEngine` 兜底。

- **计数门禁自校验**：`verify-test-baseline.php` 对 `merged-sqlite-v18.xml` 返回
  `[PASS]`，tests / assertions / failures / errors / skipped 五项全部 OK，退出码 0。

### 3.1 CI 实际环境（SQLite 文件库）的本地验证

**口径差异**：本地上面的 v18 全量用 `phpunit.xml` 的 `DB_DATABASE=:memory:`（内存库）；
而 CI 的 `test` job 用 **文件库**（`.env.example` 未设 `DB_DATABASE` → 默认
`database_path('database.sqlite')`）。这是两个不同的运行环境，
本节专门补齐该差异。

在文件库上重跑全量（每批 40 文件、独立进程、共用同一文件库）：

| 批次 | 文件范围 | tests | assertions | 结果 |
| --- | --- | --- | --- | --- |
| batch-001 | 1-40 | 379 | 2205 | OK |
| batch-002 | 41-80 | 360 | 1930 | OK |
| batch-003 g1 | 81-90 | 83 | 497 | OK |
| batch-003 g2 | 91-100 | 114 | 547 | OK |
| batch-003 g3 | 101-110 | 87 | 362 | OK |
| batch-003 g4 | 111-120 | 80 | 727 | OK |
| batch-004 | 121-160 | 425 | 1670 | OK |
| batch-005 | 161-189 | 233 | 1532 | OK |
| **合计** | | **1761** | **9470** | **与 `:memory:` 口径逐批完全一致，且与基线精确相等** |

**结论：CI 的计数门禁（精确比对 1761 / 9470）在文件库形态下同样成立**，
不会因为「内存库 vs 文件库」产生计数漂移。

#### 3.1.1 一个必须如实记录的环境现象：Windows 单进程长跑会被中断

在 Windows 上做「单进程 + 文件库」跑全量时，PHP 进程**被外部中断**（退出码 127，
无 PHP 错误输出，JUnit 文件为 0 字节）。这不是测试失败，是进程被杀死。三次证据：

| 运行方式 | 进度 | 结果 |
| --- | --- | --- |
| 单进程跑全量（1761 用例） | 1346 / 1761（76%） | 进程被中断 |
| 分批 batch-003（40 文件 / 364 用例） | 196 / 364（54%） | 进程被中断（退出码 127） |
| **干净文件库重跑同一批 batch-003** | **通过第 196 个，继续到 323** | **中断位置不同** |

**定位结论：与具体用例无关的累积型中断。**

- 崩溃点附近的用例单独跑全部通过：
  `FreshBootTest` 3/3、`SeoMetaSchemaTest` + `ServiceSchemaHttpTest` 16/16、
  `GeoflowConcurrencyTest` + `OptimizeImagesCommandTest` + `GeoInstallTest` +
  `PageCacheTest` + `PluginArchitectureTest` 33/33；
- 同一批文件在 `:memory:` 口径下（v18 batch-003）**364 用例 / 2133 断言全绿**；
- 拆成 4 组后（每组独立进程）**全部通过**。

**定性：Windows 本地验证路径的资源型限制，不是产品缺陷。**
本项目早已记录「Windows 全量必须分批独立进程跑」，与此一致。

**对发布判定的影响**：

- **不得**把本地这次中断当作「CI 会红」的证据；
- **也不得**把本地绿当作「CI 一定绿」的证据 —— CI 的 `test` job 走
  **Ubuntu + 单进程 + 文件库**，这条路径在本地无法等价复现（Ubuntu 资源限制与
  容器环境不同）。这构成 §10 阻断项 B-1「必须跑首轮远端流水线」的又一个理由。

### 3.2 8 个缺陷的修复证据
| # | 缺陷 | 证据 | 变异测试 |
| --- | --- | --- | --- |
| 1 | PG 迁移因外键开关权限失败（`42501`） | `session_replication_role` 是 `PGC_SUSET` → 改 no-op + `dropReferencingForeignKeys()` | — |
| 2 | PG 上并发重复投递必然失败（`25P02`） | PG 在 `23505` 后把事务置 aborted → 冲突处理移出事务 + 新事务重载赢家 | — |
| 3 | MySQL InnoDB 对拍升级死锁未处理 | `isRetryableTransactionFailure()` 最多 3 次重试（`40001` / `40P01`） | — |
| 4 | `contents.slot` 唯一约束跨库语义漂移 | 收紧迁移统一为 `UNIQUE(site_id, slot)`；`DatabaseStructureParityTest::test_dsp_004` 三库锁死 | — |
| 5 | PG 把非数字路由片段变成 500 | `GuardsNumericRouteBinding`；`RouteBindingGuardTest` 三库各 24/24 | 移除守卫 → 12 个用例 error；判定改宽 → 4 个失败 |
| 6 | 事实编辑撞唯一约束静默失败并误报「key 已占用」 | `FactLocaleUpdateTest` 三库各 5/5 | 还原旧 locale 兜底 → 5 个中 4 个失败 |
| 7 | 共享列在非权威行假生效（中英文发布资格分裂） | 同上（广播用例） | 移除权威行路由 → 2 个广播用例失败 |
| 8 | MySQL「当前库表清单」跨库返回 | `CurrentDatabaseTablesTest` 三库各 6/6 | 改回返回限定名且不去重 → 6 个中 3 个失败 |

三轮变异测试均**只打中各自对应的用例**，说明断言位置精确，没有「一枪打一片」的假牙齿。

---

## 4. 真实并发验收结论

`RealConcurrencyP0Test` 5 个用例在 **SQLite / PostgreSQL / MySQL 三库全部通过**（取自 v18 JUnit）。

| 用例 | 断言数 | 场景 |
| --- | --- | --- |
| `test_p0_1a_concurrent_identical_geoflow_delivery_creates_exactly_one_row` | 9 | 同 external_id 重复投递 → 恰好一行 |
| `test_p0_1b_concurrent_different_geoflow_payloads_under_same_external_id_keep_one_consistent_row` | 7 | 同 external_id 不同载荷 → 留下一行且载荷一致 |
| `test_p0_2_concurrent_template_activation_leaves_exactly_one_active_pack` | 6 | 并发激活模板 → 恰好一个生效包 |
| `test_p0_3a_concurrent_duplicate_slug_and_locale_yields_exactly_one_row` | 7 | 同 slug + locale 并发 → 恰好一行 |
| `test_p0_3b_concurrent_same_slug_in_different_locales_is_allowed` | 5 | 同 slug 不同 locale 并发 → 允许并存 |

**并发是真的**：方法为 `proc_open` 启动独立 PHP 进程 → 各持独立数据库连接 → 数据库表作屏障 →
主进程等全部 worker `ready` 后发布共享 wall-clock 放行时刻 → 每个用例都断言
`overlap_ms > 0`（所有 worker 执行区间的**交集**），即操作确实在时间上重叠。

**未覆盖**：真多机分布式竞争（本机无 `pcntl` / `posix`，无 `redis`）。**非阻断**。

---

## 5. 升级 / 回滚 / 故障恢复 / 备份恢复验收结论

### 5.1 三库演练（在最终代码上重跑）

`scripts/audit/verify-upgrade-rollback.php`，产物：`D:/GEO-OS-rewrite/_v14-tools/drill-final/report-upgrade-*.json`

| 库 | 断言 | 通过 | 失败 | errors |
| --- | --- | --- | --- | --- |
| SQLite | 22 | **22** | 0 | 0 |
| PostgreSQL | 21 | **21** | 0 | 0 |
| MySQL | 21 | **21** | 0 | 0 |

演练覆盖 7 步，全部通过：

1. `migrate:fresh` → 候选版本完整 schema（62 个迁移）；
2. `migrate:rollback --step=6` → 回到上一发布形态（56 个迁移，**并执行了新迁移的 DOWN 路径**）；
3. 插入业务 fixture（contents / facts）；
4. `migrate` → 升级到候选版本；
5. 断言：数据存活、content_hash 未变、新增表 / 列 / 能力齐备（`entity_tombstones`、
   `fact_review_markers`、`entities.role`、`contents.entity_refs`、`media` 删除失败列）；
6. **故障注入**：故意失败的迁移不得被记入 `migrations` 账本；移除后重跑迁移数恢复为 62；
   业务数据保持完整；
7. **原生备份 / 恢复**：PG 用 `pg_dump` / `psql`，MySQL 用 `mysqldump` / `mysql`，
   SQLite 用项目自带的 `geo:backup` + `geo:rollback`。

备份恢复的验证是**破坏 → 恢复 → 断言**的真实往返：先删掉目标 contents 行并断言计数为 0，
再从备份恢复并断言计数回到 1（`backup.restore_recovered_data`）。

### 5.2 新增收紧迁移的安全性（负向验证）

`2026_10_27_000001_tighten_contents_slot_unique_to_site_scope` 把 `contents.slot` 的唯一约束
从 `UNIQUE(site_id, slot, locale)` 收紧为 `UNIQUE(site_id, slot)`。
**收紧前的存量冲突处理是本次验收的重点**，用负向手法验证（`verify-migration-conflict-guard.php`）：

1. 迁移到候选版本 → 回滚 1 步（恢复旧的宽松约束）；
2. 插入两行「同站同 slot、不同 locale」的 contents（模拟旧库遗留）；
3. 重新 `migrate` → **必须中止**；
4. 断言：迁移失败、错误信息点名冲突行、无任何删除动作、数据行数不变（2 → 2）、
   **新的站点级唯一索引未被创建**（即迁移在探测阶段就中止，没有先 drop 旧索引再失败）、
   旧约束完好、失败的迁移未被记入账本。

| 库 | 断言 | 结果 |
| --- | --- | --- |
| PostgreSQL | 10 | **10/10 通过** |
| MySQL | 10 | **10/10 通过** |

结论：**不存在未经处理的存量数据冲突**（有则中止报错并给可执行指引）、
**不存在静默数据修改**（迁移不含任何 UPDATE / DELETE）、
**不存在半途改写结构**（探测在 drop 之前）。

### 5.3 恢复承诺的边界（如实记录）

- PG / MySQL 的原生恢复演练是「**恢复到已有库**」：`psql --file=dump` 会对已存在的表报
  `relation "x" already exists` 并继续执行后续 INSERT，因此 `restore_rc` 不可作为恢复成败的判据。
  **真正的判据是 `backup.restore_recovered_data` 断言（恢复后目标行计数回到 1），该断言有效且已通过。**
  运维上正确的做法是恢复到**空库**，这一点须写进运维手册。
- SQLite 没有原生 dump，唯一自动路径是项目自带的 `geo:backup` + `geo:rollback`
  （这是**备份恢复**，不是迁移逆操作），已实测通过（3 条断言）。
- 迁移总数 62；回退到 v1.0.0 发布形态（56 个迁移）需 6 步。

---

## 6. PostgreSQL CI job 核实

### 6.1 job 配置（`.github/workflows/ci.yml` 的 `pgsql` job）

| 项 | 配置 |
| --- | --- |
| 触发 | 与 `test` job 相同（push / PR） |
| 运行时 | `ubuntu-latest`，PHP 8.4，扩展含 `pdo_pgsql` |
| 服务容器 | `postgres:16`，`POSTGRES_USER/DB=geo_os_ci`，`POSTGRES_PASSWORD=geo_os_ci_pwd`，端口 5432，带 `pg_isready` 健康检查 |
| 注入变量 | `DB_CONNECTION=pgsql` / `DB_HOST=127.0.0.1` / `DB_PORT=5432` / `DB_DATABASE=geo_os_ci` / `DB_USERNAME` / `DB_PASSWORD` |
| 阻断性 | **是**（无 `continue-on-error`） |

步骤（10 步）：
`composer validate --strict` → `composer check-platform-reqs` → `composer audit` →
准备 `.env` 与 app key → **验证实际生效的连接**（`config('database.default') !== 'pgsql'` 即 `exit 1`）→
**显式授予 public schema 权限** → `migrate --force` → `geo:install --no-interaction` →
`phpunit --testsuite PgsqlCore --log-junit` → **零失败门禁** → 上传 artifact。

**零失败门禁口径**：`failures != 0 || errors != 0 || skipped != 0` 任一成立即 `exit 1`；
计数不与 SQLite 基线比对（不同子集，比对会把「子集差异」误判为「测试丢失」）。

### 6.2 本地等价复现（远端 CI 仍未执行）

按 job 的步骤顺序，在本机 PG 隔离实例上逐条复现（`D:/GEO-OS-rewrite/_v14-tools/ci-equivalent-pgsql.sh`）：

| 步骤 | 本地等价结果 |
| --- | --- |
| `composer validate --strict` | 通过 |
| `composer check-platform-reqs` | 通过 |
| `composer audit` | 通过（`symfony/http-foundation` 已锁 7.4.19，命中 CVE-2026-108082 的 7.4.18 已不在 lock 中） |
| 验证生效连接为 pgsql | 通过 |
| 授予 public schema | 通过 |
| 空库 `migrate --force` | 通过（62 个迁移） |
| `geo:install --no-interaction` | 通过 |
| `PgsqlCore` 回归 | 见下 |
| 零失败门禁 | **通过**（skipped = 0） |
| 上传 artifact | 跳过（远端机制） |

### 6.3 验收期间发现并修复的 CI 配置缺口

`PgsqlCore` 套件**原本不含**本轮三个修复的守护类 → CI 门禁守不住本轮修复：

| 守护类 | 守护的缺陷 | 处置 |
| --- | --- | --- |
| `tests/Feature/RouteBindingGuardTest.php` | 缺陷 5（路由绑定守卫） | **已加入 PgsqlCore** |
| `tests/Feature/FactLocaleUpdateTest.php` | 缺陷 6 + 7（事实编辑路径） | **已加入 PgsqlCore** |
| `tests/Feature/CurrentDatabaseTablesTest.php` | 缺陷 8（当前库表清单） | **已加入 PgsqlCore** |

### 6.4 CI 证据缺口（必须如实声明）

- **远端 GitHub Actions 在本分支上从未执行**，且这不是「查不到」而是「客观不可能」：
  本分支 8 个提交中只有 `b0da0e7` 已在 `origin/main`；其余 7 个
  （`abfc169` / `01fef0b` / `bd5d833` / `6b90ab1` / `86e62d8` / `378e1cf` / `034aa05`）
  **不被任何远端分支包含**，且 `feature/v1.4-db-compat` **没有 upstream**
  （`git branch -vv` 无 `[origin/...]`）→ 该分支从未推送，代码从未到达远端，
  因此不可能存在针对它的 CI run。

  | 提交 | 是否在远端任一分支 |
  | --- | --- |
  | `b0da0e7` | 是（`origin/main`） |
  | 其余 7 个 | **否** |

- 上一轮 CI（提交 `3f971e1`）的 `test` job 曾因 `composer audit` 失败（lock 中
  `symfony/http-foundation` 为 7.4.18，命中 CVE-2026-108082）；该问题已由 `b0da0e7` 修复，
  本地 `composer audit` 通过，**但修复后的流水线绿灯仍未取得**。
- 本地等价复现证明的是「配置正确且门禁会放行」，**不等于**远端流水线已绿：
  远端是 ubuntu + `postgres:16` 容器，本地是 Windows + 本机 PG 16.10 实例，
  操作系统与容器镜像差异只能由远端流水线暴露。
- `release` job 目前是 `needs: test`，**PG 门禁不参与 tag 发布的决策**。
  若要对宣称「PostgreSQL 正式支持」的版本打 tag，应先把 `pgsql` 加入 `release.needs`
  （待首轮流水线验证通过后再改，本次不改）。

---

## 7. 最终数据库支持矩阵

| 数据库 | 版本 | 本地三库验证 | CI 持续门禁 | **支持结论** |
| --- | --- | --- | --- | --- |
| **SQLite** | 3.53.4 | 全量 1761/9470 零失败（**内存库与文件库两种口径均已实测，逐批计数一致**）；升级/回滚/备份恢复 22/22；并发 5/5 | 有（`test` job，计数门禁精确比对 1761/9470；该门禁已在文件库口径下本地验证成立） | **正式支持（维持现状）** |
| **PostgreSQL** | 16.10（CI 用 16 容器） | 全量 1761/9365 零失败零错误；升级/回滚/备份恢复 21/21；并发 5/5；结构一致性 7/7；迁移冲突守卫 10/10；CI job 本地等价复现全通过 | **门禁配置已就绪，但远端流水线从未执行，无绿灯证据** | **正式支持（条件性）**：本地三库证据完整、CI 门禁配置已核实且本地等价复现通过，**但 CI 门禁尚未取得远端实测证据**；在首个 `pgsql` job 绿灯之前，不得对外宣称「已通过 CI 门禁」，建议对内标注为「正式支持 · CI 首轮待验」 |
| **MySQL** | 8.4.8 | 全量 1761/9365 零失败零错误；升级/回滚/备份恢复 21/21；并发 5/5；结构一致性 7/7；迁移冲突守卫 10/10 | **无持续门禁**（仅本机实测） | **候选支持（维持）**：直到具备有效证据的持续门禁（CI job 或定时任务）为止，不升级为正式支持 |

---

## 8. 残余风险逐项判断

| # | 残余风险 | 是否阻断发布 | 理由 |
| --- | --- | --- | --- |
| 1 | **远端 CI 从未在本分支执行** | **阻断「对外发布」** | 唯一能自动暴露 Linux / 容器环境差异的机制尚未运转；`composer audit` 在上一轮 CI 上正是这里红过。解除冻结后首轮必须绿 |
| 2 | PostgreSQL 无远端 CI 绿灯 | **阻断「对外宣称 PG 正式支持」**，不阻断候选版本定稿 | 本地三库证据完整且 CI job 已核实；需首轮绿灯补齐声明依据 |
| 3 | MySQL 无持续门禁 | **不阻断** | 已明确降级为「候选支持」；对外不承诺 |
| 4 | 9 个用例只在 SQLite 验证（站点创建生产等价 7 + 升级/回滚 E2E 2） | **不阻断** | 源码内显式 `markTestSkipped`（依赖 SQLite 文件库备份与生产等价场景），属设计而非失败。但 PG / MySQL 上这两条能力**目前零证据**，写进已知限制 |
| 5 | MySQL 上 `contents.slot` 收紧迁移的 DDL 非事务性（drop 与 create 不在同一事务） | **不阻断** | 冲突探测在 drop 之前，create 失败只会因「探测与创建之间有并发写入」这一极低概率；且失败必报错、不会静默通过。须写进运维手册 |
| 6 | PG / MySQL 原生恢复是「恢复到已有库」，`restore_rc` 不可作判据 | **不阻断** | 真正的判据是恢复后数据计数断言，已通过。运维须恢复到空库 |
| 7 | 收紧迁移会持有写锁（MySQL `ALTER TABLE` / PG `DROP INDEX`） | **不阻断** | 运维约束（已写入升级说明），大表需停写窗口 |
| 8 | 覆盖率 45min 阈值为估算（本地无 Xdebug / PCOV），且 `app/Models` 已移出 `source` | **不阻断** | coverage job 非阻断（`continue-on-error`）；分支度量收窄需用户知情 |
| 9 | 真多机分布式竞争未覆盖 | **不阻断** | P0 场景已用多进程 + 独立连接 + `overlap_ms > 0` 硬证据覆盖 |
| 10 | `content_entity` / `content_tag` / `pages` 三表无外键 | **不阻断** | 既有设计现状，三库一致，非本轮引入 |
| 11 | 本机 PG 临时实例曾因 autovacuum PANIC 崩溃 | **不阻断** | 环境问题，已关闭 autovacuum；崩溃窗口内结果全部作废重跑 |
| 12 | 本机三库并行回归耗时不可外推 CI | **不阻断** | 说明项；CI 是顺序执行 |
| 13 | Windows 上单进程长跑 SQLite 文件库全量会被中断（非确定性，与用例无关） | **不阻断** | 三次证据定位为 Windows 资源型限制（崩溃位置不固定、相关用例单跑全通过、`:memory:` 与文件库拆分口径均全绿）。CI 走 Ubuntu，不受此影响 |

---

## 9. 未解决问题对客户使用的实际影响

| 问题 | 客户侧影响 |
| --- | --- |
| PG 无 CI 绿灯 | 客户在 PG 上遇到问题的概率**低于**「SQLite 已绿但 PG 未验」的常规风险（本地三库全量零失败），但**存在**平台差异导致的未知风险。若客户是 PG 生产环境，建议先在预发环境跑一次安装 + 冒烟 |
| 9 个用例仅 SQLite 覆盖 | PG / MySQL 客户若使用「站点创建的生产等价路径」或「升级回滚 E2E」，这两条路径**在 PG/MySQL 上未经验证**。升级客户须格外谨慎：先在预发做一次完整升级演练 |
| MySQL 候选支持 | 不应对 MySQL 客户承诺正式支持级别；可作为「已知可用但无持续保障」提供 |
| MySQL 迁移 DDL 非事务性 | MySQL 客户执行收紧迁移时，若迁移因异常中断，库可能暂时缺少 `slot` 唯一约束；**必须**确认迁移成功后再放开写入，并在异常时检查 `migrations` 表与索引实际状态 |
| PG / MySQL 恢复方式 | 恢复必须落到**空库**；恢复到非空库会产生 DDL 冲突噪声，不能以命令退出码判断成败，应以业务数据核对为准 |

---

## 10. 最少且必要的阻断项与解决路径

**阻断项只有 1 条**（其余均为非阻断残余风险或已知限制）：

> **B-1：解除推送冻结 → 推送 `feature/v1.4-db-compat` → 确认 GitHub Actions 的 `test` 与
> `pgsql` 两个 job 全绿。**

解决路径：

1. 解除推送冻结（需用户授权）；
2. `git push origin feature/v1.4-db-compat`（**只推分支，不合并 main、不打 tag、不发布**）；
3. 等待两个 job 结束，确认：
   - `test` job：全量 1761/9470，计数门禁 `[PASS]`（该计数门禁在**文件库**口径下已本地验证
     成立，但 Ubuntu + 单进程这条路径只能在远端确认）；
   - `pgsql` job：10 个步骤全过，零失败零跳过门禁通过；
   - `composer audit` 步骤通过（上一轮曾在此红过）；
4. 绿灯后，把「PostgreSQL 正式支持」的表述从「CI 首轮待验」改为「CI 门禁已建立并通过」；
5. 如需对外发布 PostgreSQL 正式支持版本，把 `pgsql` 加入 `release.needs` 后再打 tag。

若 B-1 首轮为红，按失败步骤定位即可（`pgsql` job 已内置失败诊断：
打印实际生效的连接配置、迁移记录数与表清单）。

---

## 11. 复现方式

```bash
# 三库全量回归（Windows 必须分批独立进程 + 独立工作树 + 独立缓存 + 独立输出目录）
REPO_ROOT=<独立副本> DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
  DB_DATABASE=geo_os_v14 DB_USERNAME=geo_os_v14_test \
  bash run-test-batches.sh phpunit.pgsql.xml <out-dir> pgsql 40

# 官方计数门禁
php scripts/audit/verify-test-baseline.php --junit=<merged>.xml

# 升级 / 回滚 / 故障注入 / 原生备份恢复演练（三库）
php scripts/audit/verify-upgrade-rollback.php <driver> <host> <port> <db> <user> [password] [steps]

# CI pgsql job 的本地等价复现
bash ci-equivalent-pgsql.sh

# 收紧迁移的存量冲突守卫（负向验证）
php verify-migration-conflict-guard.php <driver> <host> <port> <db> <user> [password]
```
