# V1.4 交付报告 · 跨库兼容性 · 真实并发 · 升级恢复

> 授权单：V1.4「隔离环境连续开发、调试、测试和完善」
> 实施位置：隔离 worktree `D:\GEO-OS-security-upgrade`，分支 `feature/v1.4-db-compat`
> 状态：**候选版本已验证（有明确残余风险）**；推送 / 合并 / tag / 发布 / 部署**保持冻结**
> 配套文档：[`docs/operations/database-support-and-recovery.md`](../operations/database-support-and-recovery.md)

---

## 0. 一句话结论

本轮在三种真实数据库上把 GEO OS 的**迁移、安装、结构、并发、升级、备份恢复**全部跑通，
并修掉 **5 个被 SQLite 基线掩盖的真实缺陷**（4 个生产代码缺陷 + 1 个跨库语义漂移），
另修掉 **2 个测试基础设施缺陷**（并行回归共用工作树导致互相破坏；升级演练脚本未真正切换数据库）。
候选版本在 **SQLite / PostgreSQL 8.0+ / MySQL 8.4** 三库上的行为已实测一致，
但 **CI 流水线尚未取得新证据**（推送冻结），且 **MySQL 尚无持续门禁**。

---

## 1. 变更清单

### 1.1 生产代码（4 处）

| 文件 | 改动 | 原因 |
| --- | --- | --- |
| `app/Support/MigrationPortability.php` | pgsql 分支的外键开关改为 **no-op**（移除 `SET session_replication_role`） | `session_replication_role` 是 `PGC_SUSET`，普通应用角色报 `42501`；且它对「被引用表不能 drop」这一 `2BP01` 场景无效。PG 侧正确做法是既有的 `dropReferencingForeignKeys()` / `restoreReferencingForeignKeys()` |
| `app/Services/Sync/UniqueContentConflict.php` | **新建**。信号异常，承载冲突时的 `$attributes` 与 `$externalId` | 把「唯一键冲突」的处理从事务内移到事务外 |
| `app/Services/Sync/GeoflowSync.php` | 唯一冲突改为抛 `UniqueContentConflict`；新增 `resolveConcurrentDuplicate()`（新事务 + `lockForUpdate` reload 赢家）；新增 `isRetryableTransactionFailure()` + 最多 3 次事务重试 | ① PG 在 23505 后把事务置为 aborted，原「同事务 reload 赢家」在 PG 上**必然失败**，在 SQLite/MySQL 上却能通过；② MySQL InnoDB 的「对拍升级死锁」（1213）未处理，直接 500 |
| `config/database.php` | SQLite 连接项改为 env 可覆盖，新增 `DB_TRANSACTION_MODE`（默认 DEFERRED） | SQLite `DEFERRED` 事务「先读后写」升级写锁不走 `busy_timeout`，直接 `SQLITE_BUSY` |
| `app/Support/GuardsNumericRouteBinding.php` | **新建**。整型主键的路由隐式绑定守卫：URL 片段不是合法整数字面量时，在查询之前判定「未找到」 | 见 §3.5。PG 会对非法字面量抛 `22P02` → **500**，SQLite / MySQL 只是查不到 → **404**；同一请求在不同库返回不同状态码 |
| `app/Models/BaseModel.php` | **新建**。业务模型统一基类，承载上述守卫；26 个 Eloquent 模型改为继承它（`User` 继承 `Authenticatable`，不在范围内） | 一处根治，避免逐个模型重复；改动经 `route:list` 与全量回归核验 |

### 1.2 迁移（1 个）

| 文件 | 作用 |
| --- | --- |
| `database/migrations/2026_10_27_000001_tighten_contents_slot_unique_to_site_scope.php` | 把 `contents.slot` 唯一约束在三库上统一为**站点级** `UNIQUE(site_id, slot)`。收紧前先探测存量冲突，**存在冲突即中止报错**，绝不静默删改数据 |

### 1.3 测试与工具（6 个新增 / 3 个修改）

| 文件 | 说明 |
| --- | --- |
| `tests/Support/Concurrency/worker.php`（新） | 独立进程 worker：bootstrap → 标记 ready → 等共享 wall-clock 放行时刻 → 执行场景 → 输出 JSON（含 `started_at` / `finished_at`） |
| `tests/Support/Concurrency/ProcessBarrier.php`（新） | 数据库屏障表 `concurrency_barrier`（slot / ready / ready_at / go / go_at），提供确定性的竞争窗口 |
| `tests/Support/Concurrency/WorkerPool.php`（新） | `proc_open` 启停/回收 worker；**`overlap_ms` = 所有 worker 执行区间交集**，是「真实重叠」的硬证据 |
| `tests/Support/Concurrency/RealConcurrencyTestCase.php`（新） | 真实并发测试基类；SQLite 时切到按 PID 命名的文件型库（WAL + IMMEDIATE + busy_timeout） |
| `tests/Feature/Concurrency/RealConcurrencyP0Test.php`（新） | P0 三场景共 5 个用例，每例均断言 `overlap_ms > 0` **且**断言终态业务状态 |
| `tests/Feature/DatabaseStructureParityTest.php`（新） | 跨库结构一致性断言 7 个用例（唯一键 / 外键 / 表存在性 + 行为断言） |
| `scripts/audit/verify-upgrade-rollback.php`（新） | 升级 / 故障注入恢复 / 回滚 / 原生备份恢复演练，输出 JSON 报告 |
| `phpunit.pgsql.xml`（新） | 独立 PostgreSQL 配置，含 `PgsqlCore` 门禁子集 |
| `phpunit.mysql.xml`（改） | 指向本轮验证实例 |
| `tests/Feature/FactValidationTest.php`（改） | 修复 PG 上的断言不可达问题（见 §3.4） |
| `tests/Feature/RouteBindingGuardTest.php`（新） | 绑定守卫回归：三库各 24 个用例（阳性对照 8 + 阴性对照 14 + HTTP 端到端 + 非整型主键不受影响），见 §3.5 的变异测试 |
| `tests/Feature/MigrationRollbackFidelityTest.php`（改） | 迁移清单基线 61 → 62 |
| `config/cache.php`（改） | file store 路径支持 `CACHE_FILE_PATH` 覆盖（默认不变），用于并行测试隔离 |

### 1.4 CI 与文档

- `.github/workflows/ci.yml`：新增 `pgsql` job（postgres:16 服务容器 + `pdo_pgsql` + 空库迁移 + 安装器 + `PgsqlCore` 回归 + 零失败门禁 + 失败诊断 + artifact）；`coverage` job 补显式 `timeout-minutes: 45` + `continue-on-error: true`。
- `README.md` / `DEPLOY.md`：数据库支持声明改为**分驱动**表述，并链接矩阵文档。
- `docs/operations/database-support-and-recovery.md`：新建（支持矩阵 / 物理差异 / 升级 / 备份恢复 / 验证脚本 / 已知限制）。

---

## 2. 数据库支持矩阵（实测结论）

| 驱动 | 实测版本 | 状态 | 依据 |
| --- | --- | --- | --- |
| SQLite | 3.53.4（PHP 8.4.25 内置） | **正式支持** | 空库迁移 ✓、安装器 ✓、全量回归 ✓、结构断言 7/7 ✓、并发 5/5 ✓、升级回滚演练 22/22 ✓ |
| PostgreSQL | 16.10 | **正式支持** | 同上 + 空库迁移 62 个全通过 ✓、安装器 ✓、结构断言 7/7 ✓、并发 5/5 ✓、升级回滚演练 21/21 ✓、原生 `pg_dump`/`psql` 恢复实测 ✓ |
| MySQL | 8.4.8 | **候选支持** | 同上 + 空库迁移 62 个全通过 ✓、安装器 ✓、结构断言 7/7 ✓、并发 5/5 ✓、升级回滚演练 21/21 ✓、原生 `mysqldump`/`mysql` 恢复实测 ✓。**但无 CI 门禁** |

> 「正式支持」= 有自动化门禁，破坏即让流水线变红。
> PostgreSQL 的门禁（`pgsql` job）**本轮尚未在 GitHub 上跑过**（推送冻结），
> 本地已用同一套命令与同一 `PgsqlCore` 套件在真实 PG 16.10 上跑通。

---

## 3. 本轮修掉的 4 个真实缺陷

### 3.1 PG 迁移因外键开关权限失败（生产缺陷）

- **现象**：PG 上 `migrate` 报 `42501 permission denied`。
- **根因**：`SET session_replication_role` 是 `PGC_SUSET`，普通应用角色无权执行；
  且即便超级用户也解决不了 `2BP01`（被引用表不能 drop）。
- **修法**：pgsql 分支改 no-op，依赖既有的 `dropReferencingForeignKeys()`。
- **证据**：PG 16.10 上 62 个迁移 `migrate:fresh` 全通过。

### 3.2 PG 上并发重复投递必然失败（生产缺陷）

- **现象**：真实并发 P0 在 PG 上报 `25P02 current transaction is aborted`。
- **根因**：PG 在 23505 之后把**整个事务**置为 aborted；原实现「冲突后在同一事务内
  reload 赢家」在 PG 上必然失败，而 SQLite / MySQL 因约束失败后事务仍可用而**掩盖**了该缺陷。
- **修法**：`UniqueContentConflict` 信号异常 + 事务外新事务 `resolveConcurrentDuplicate()`。
- **证据**：P0 在 PG 上 5/5 通过（34 断言）。

### 3.3 MySQL 并发死锁未处理（生产缺陷）

- **现象**：真实并发 P0 在 MySQL 上报 `1213 Deadlock`。
- **根因**：InnoDB「对拍升级死锁」——多连接同时向同一 UNIQUE 索引插入，
  先各取共享锁做唯一性检查、再同时升级为排他锁，互相等待。
- **修法**：识别 SQLSTATE `40001` / `40P01` 与 `deadlock found` / `lock wait timeout` /
  `database is locked` / `could not serialize access`，有限重试（3 次，退避 20ms×次数）。
- **证据**：P0 在 MySQL 上 5/5 通过（34 断言）。

### 3.4 `contents.slot` 唯一约束跨库语义漂移（数据一致性缺陷）

- **现象**：SQLite 为 `UNIQUE(site_id, slot)`（站点级），
  MySQL / PG 为 `UNIQUE(site_id, slot, locale)`（带 locale）→ **同一份数据在三种库上写入结果不同**。
- **判据来源**：项目既有契约 `MigrationRollbackFidelityTest` MRF-005 / MRF-006 已把
  **站点级**（不含 locale）锁定；应用层语义同向（`ContentSeeder` 以 `updateOrCreate(['slot' => ...])`
  写入，匹配条件只有 slot、没有 locale）。
- **修法**：新增收紧迁移；收紧前探测存量冲突，**有冲突即中止报错**，不静默删改数据。
- **证据**：三库 up → down → up 全部通过；结构一致性用例锁定（变异测试已证明断言有牙齿：
  代码级变异使 DSP-002 / DSP-004 / DSP-006 三个用例失败）。

### 附：另有一个**测试夹具**缺陷（非生产缺陷）

`FactValidationTest::test_unique_constraint_race_is_converted_to_validation_error`
在 PG 上**报错而非通过** —— PG 的 25P02 把 `RefreshDatabase` 的用例事务一并 abort，
导致后续计数断言根本执行不到（等于该行为在 PG 上从未被验证）。
已核查三个捕获唯一冲突的控制器位置均为「catch 后立即 return」，
且项目无请求级事务中间件，**生产路径不受影响**。
修法：在冲突行写入之后建立 SAVEPOINT，断言前 `ROLLBACK TO SAVEPOINT` 恢复事务。

### 3.5 PG 把非数字路由片段变成 500（**生产缺陷**，跨库状态码漂移）

- **现象**：`POST /admin/contents/check` 携带 `_method=PUT` 时，方法覆写后命中
  `PUT /admin/contents/{content}`；`{content}` = 字面量 `check`。
  - SQLite / MySQL：`where id = 'check'` 只是查不到 → **404**（与项目契约一致）
  - PostgreSQL：`where "id" = check` 触发类型转换，抛 `SQLSTATE 22P02
    invalid input syntax for type bigint: "check"` → **500**
- **根因**：Laravel 的隐式路由绑定把 URL 片段**原样**送进查询；PG 对整型列的
  字面量做严格类型转换，SQLite / MySQL 宽松处理。同一个请求在不同数据库返回
  不同状态码，且 PG 侧是 500（把参数校验失败升级成了服务端错误）。
- **修法**：在**模型绑定层**做根因修复（`GuardsNumericRouteBinding`）——
  主键为整型时，片段不是合法整数字面量即判定「未找到」，**不进数据库**。
  - 判定文法逐条复刻 PG 的 `bigint` 输入规则：可选正负号 + 十进制数字 + 允许首尾空白 +
    不得溢出 `bigint`。**刻意不用** `filter_var(FILTER_VALIDATE_INT)`：
    后者会拒绝 `'010'` / `' 10'` / `'+10'`，而这三个字面量在 PG 与 MySQL 上都能
    正常解析为 10 —— 用它会把「三库一致」改成「三库都不一致」。
  - 只对 `keyType = int` 且未显式指定绑定字段时生效，字符串主键模型不受影响。
- **先证伪的方案**：曾先尝试在**路由层**加 `whereNumber()`（76 条路由全部加数值约束，
  经 `route:list` 前后对比确认 0 增删 / 0 action 变更 / 0 URI 变更），
  但结果是 **405**（路由不匹配降级为方法不允许）而非契约要求的 **404** → **方案作废并回滚**，
  改为在绑定层修。这一段保留在此，说明「路由层约束 ≠ 与既有契约等价」。
- **证据**：
  - 阳性对照（修复前）：PG 上复现出 `22P02` 与 `where "id" = check`。
  - 修复后：三库同一请求均为 **404**。
  - 守卫探针 `probe-binding-guard.php` 在 SQLite / PG / MySQL 上各 **21/21** 一致
    （7 项阳性对照 + 14 项阴性对照，0 异常）。
  - 自动化回归 `RouteBindingGuardTest` 三库各 **24/24** 通过。
  - **变异测试证明有牙齿**：① 移除守卫 → PG 上 12 个用例 error（`22P02` 复现）；
    ② 把判定改成过宽的 `/^\d+$/` → 4 个用例失败（`'011'` / `'+11'` / `' 11 '` 被误拒，
    阳性对照捕获）。

### 3.6 两个**测试基础设施**缺陷（非生产缺陷，但会污染结论）

#### 3.6.1 并行回归共用同一工作树 → 互相删除对方的临时插件目录

- **现象**：三套回归（SQLite / PG / MySQL）并行时，PG 出现间歇性失败与挂起，
  MySQL 出现级联 error，SQLite 侥幸全绿。
- **根因**：`run-test-batches.sh` 对**同一份排序文件列表**做等长切分，
  三套回归的 batch-001 文件集合**完全相同**；而
  `AdminPluginCrudTest::setUp()/tearDown()` 会创建并
  `forceDeleteDir(base_path('plugins/depchild'))`。三个进程在同一工作树上
  互相删除对方的临时插件目录 → `PluginManager::exists()` 的 glob 结果在请求之间
  发生变化 → `enable()` 命中 `abort(404)`。
- **证据**：`AdminPluginCrudTest` 单独在 PG 上跑 10/10 通过；在并发批次里报
  `Expected 201/301/302/303/307/308 but received 404`（`AdminPluginCrudTest.php:216`）。
- **修法**：`run-test-batches.sh` 增加 `REPO_ROOT` 覆盖，三套回归各用**独立工作树副本**
  （`GEO-OS-security-upgrade` / `-pg` / `-my`），彻底隔离 `base_path('plugins')` 与
  `storage/` 下的共享文件状态；输出目录与文件缓存目录依旧各自独立。
- **影响面声明**：在共用工作树期间取得的批次结果（sqlite-v14 / pgsql-v15 / mysql-v15）
  **不能作为证据**，已全部作废并在独立副本上重跑（v16）。

#### 3.6.2 升级/回滚演练脚本未真正切换数据库

- **现象**：`verify-upgrade-rollback.php` 读到的 argv 未配置连接，SQLite / MySQL 两路演练
  实际都落到默认 SQLite 连接，两个进程并发写同一个 SQLite 文件 →
  `database disk image is malformed`。
- **修法**：显式 `config([...])` + `DB::purge($driver)`，并把 `actual_driver` /
  `actual_database` 写进报告，让「演练跑在哪个库上」成为可断言的事实。
- **附带的去硬编码**：工具路径改为 `PG_DUMP` / `PSQL` / `MYSQLDUMP` / `MYSQL`
  环境变量 → PATH → Windows 常见安装目录 → 明确异常，脚本不再绑定本机路径。

---

## 4. 真实并发验证（P0）

**方法**：`proc_open` 启动独立 PHP 进程 → 各持独立数据库连接 → 数据库表作屏障 →
主进程等全部 worker `ready` 后发布**共享 wall-clock 放行时刻** `go_at` →
worker 忙等至该时刻后同时进入业务操作。

**硬证据**：每个用例都断言 `overlap_ms > 0`（所有 worker `[started_at, finished_at]` 区间的**交集**），
即操作确实在时间上重叠，而非「看起来像并发」。

| 场景 | SQLite | PostgreSQL 16.10 | MySQL 8.4.8 |
| --- | --- | --- | --- |
| P0-1a 相同 `external_id` 并发投递 → 仅 1 行 | ✅ | ✅ | ✅ |
| P0-1b 相同 `external_id` 并发**不同** payload → 仅 1 行且内容一致 | ✅ | ✅ | ✅ |
| P0-2 同一站点并发激活不同模板 → 仅 1 个激活包 | ✅ | ✅ | ✅ |
| P0-3a 并发创建重复 slug+locale → 仅 1 行 | ✅ | ✅ | ✅ |
| P0-3b 相同 slug 不同 locale → 允许 | ✅ | ✅ | ✅ |
| **合计** | **5/5（34 断言）** | **5/5（34 断言）** | **5/5（34 断言）** |

---

## 5. 升级 · 故障恢复 · 回滚

演练脚本：`scripts/audit/verify-upgrade-rollback.php`

| 断言项 | SQLite | PostgreSQL | MySQL |
| --- | --- | --- | --- |
| 候选版本迁移数 = 62 | ✅ | ✅ | ✅ |
| `migrate:rollback --step=6` 回到 v1.0.0 发布形态（56 个迁移） | ✅ | ✅ | ✅ |
| 升级后数据存活（内容 / 事实 / hash） | ✅ | ✅ | ✅ |
| 新能力表 / 列到位（entity_tombstones / fact_review_markers / entities.role / entity_refs / media.delete_failed_at） | ✅ | ✅ | ✅ |
| 约束一致（`UNIQUE(site_id, external_id)`、`UNIQUE(site_id, slot)`，旧 locale 维度形态已移除） | ✅ | ✅ | ✅ |
| 故障注入：失败迁移不进台账 | ✅ | ✅ | ✅ |
| 故障注入：移除后恢复到 62，数据不损坏 | ✅ | ✅ | ✅ |
| 原生备份 → 破坏数据 → 原生恢复 → 数据回来 | ✅（`geo:backup` + `geo:rollback` 整库恢复） | ✅（`pg_dump` / `psql`） | ✅（`mysqldump` / `mysql`） |
| **合计** | **22/22** | **21/21** | **21/21** |

### 边界声明（明确区分三类「回退」）

| 动作 | 含义 | 本项目支持情况 |
| --- | --- | --- |
| **数据库迁移逆操作** | `migrate:rollback` 把 schema 退回上一个迁移点 | **支持**（逐个迁移的 `down()` 需正确实现，由 `MigrationRollbackFidelityTest` 守护） |
| **备份恢复** | 用备份文件覆盖当前库 | **支持**（各库原生工具；SQLite 走 `geo:backup` / `geo:rollback`） |
| **应用版本回退** | 换回旧代码 + 旧 schema | **需人工编排**：备份 → 回退迁移 → 换代码 → 清缓存 |
| **自动回滚** | 迁移失败自动回到迁移前状态 | **不支持自动**。迁移失败停在失败点，需人工处置 |

> 本项目**没有**「数据库自动回滚」能力，任何相反说法都需要额外测试证据，目前没有。

---

## 6. CI 变更

| job | 变更 | 状态 |
| --- | --- | --- |
| `test` | 未改（SQLite 全量 + 精确计数门禁） | **已有**，但计数基线需按本轮新增用例更新 |
| `pgsql` | **新增**：`postgres:16` 服务容器 + `pdo_pgsql` + 空库迁移 + `geo:install` + `PgsqlCore` 回归 + **零失败门禁**（failures/errors/skipped 均须为 0）+ JUnit artifact + `always()` 失败诊断 | **本轮新增，未在 GitHub 上跑过**（推送冻结） |
| `coverage` | 补显式 `timeout-minutes: 45` 与 `continue-on-error: true` | **本轮修改，未在 GitHub 上跑过** |
| `release` | 未改（`needs: test`） | 已有 |

### coverage job 卡住的根因与处理

- **事实**：CI run `40452884719` 的 `test` job 全绿（8 分钟），
  `coverage` job 自 `2026-10-27T05:09:28Z` 起卡在 `Generate branch/path coverage`，
  持续 **4h25m** 未结束；该 job **没有** `timeout-minutes`，GitHub 默认 360 分钟（6 小时）。
- **根因判定**：`--path-coverage` 的路径差集计算是「每个测试 × 每个被插桩文件」级别的，
  成本随插桩文件数快速放大；而 `app/Models` 下是大量声明式数据搬运层，
  分支度量价值低。**没有**证据表明是门禁写错或配置缺失导致，
  而是运行时长不可预期且没有上界。
- **处理（三项，均不删除门禁、不伪造成功）**：
  1. 加显式 `timeout-minutes: 45` —— 把「无限挂起」收敛成「可预期的失败」；
  2. job 级 `continue-on-error: true` —— 落实该 job 注释里**既有的**
     「Non-blocking for the release gate」语义；
  3. `phpunit-cov-branch.xml` 的 `<source>` 移出 `app/Models`，
     保留业务判定层（`app/Services/Gate`、`app/Services/Cache`、
     `app/Http/Middleware`、`SiteContext`、`SiteCacheKey`）。
- ⚠️ **第 3 项是测量范围的收窄，属于需要用户知情的取舍**：
  - 该 job 本来就声明为「scoped measurement, not whole-repo coverage」，
    收窄后仍是覆盖率门禁（分支 / 路径覆盖率照常产出，artifact 照常上传，
    `if-no-files-found: error` 仍会在无产物时让 job 变红）；
  - 但 `app/Models` 的分支度量**确实不再被测量**，这是本次改动中
    唯一可能被解读为「削弱门禁」的一处，**需单独决策是否接受**；
  - 若不接受，退路是保留原范围 + 仅保留 45 分钟超时，
    代价是 coverage job 大概率每次都超时失败、产出为零。
- **未验证项**：本地无 Xdebug / PCOV（`php -m` 已确认），
  因此 **path coverage 的实际耗时在本地无法实测**，
  45 分钟这一阈值与收窄后的实际耗时**均属未经验证的估算值**，
  需在 CI 上首次运行后按实测调整。


---

## 7. 未验证事项与残余风险

| # | 事项 | 性质 |
| --- | --- | --- |
| 1 | **CI 流水线未取得新证据**：推送冻结，本轮所有改动均未进 GitHub Actions | 未验证 |
| 2 | **MySQL 无持续门禁**：仅本机实测 | 残余风险（已在支持矩阵中降级为「候选支持」） |
| 3 | **path coverage 耗时未实测**（本地无 Xdebug/PCOV），45 分钟阈值为估算值 | 未验证 |
| 4 | **覆盖率测量范围被收窄**（`app/Models` 移出 `phpunit-cov-branch.xml` 的 `<source>`），以换取 coverage job 能在超时上界内产出 | **需用户知情取舍**：门禁未被删除，但该目录的分支度量不再测量 |
| 5 | **真多机分布式竞争未覆盖**：本机验证为「多进程 + 独立连接」，Windows 无 `pcntl` / `posix`，`redis` 相关路径无法验证 | 残余风险 |
| 6 | **`content_entity` / `content_tag` / `pages` 三表在三库上均未设外键** | 既有设计现状（三库一致，非漂移），本次未改动 |
| 7 | **收紧迁移会持有写锁**（MySQL `ALTER TABLE` / PG `DROP INDEX`），大表需停写窗口 | 运维约束（已写入升级说明） |
| 8 | 本机 14 核并行跑三套回归，**耗时显著长于单跑**，测试总时长不可直接外推到 CI | 说明项 |
| 9 | **本机 PostgreSQL 临时实例不稳定**：autovacuum 进程曾在 `VACUUM public.contents` 时 PANIC（`could not open file "pg_wal/000000010000000000000022": Permission denied`，exception `0xC0000409`），导致整个实例崩溃恢复，期间所有连接报 `the database system is in recovery mode` | **环境限制**，与产品代码无关；已在该临时实例上关闭 `autovacuum` 后重跑。**结论：凡在该实例崩溃窗口内取得的 PG 结果一律作废重跑**；这也意味着「PG 上出现过挂起 / 级联失败」不能作为产品缺陷的证据 |

---

## 7.1 关于「PG 上曾经挂起」的结论修正

本轮早期观察到 PG 回归挂起（`idle in transaction` 持锁 5 分钟、另一会话
`insert into facts` 卡在 `Lock/transactionid`），以及 MySQL 上从某个用例起级联 error。
**这两项的根因本次未取得结论性证据**，已知事实如下：

- 挂起点附近的用例（`AdminSeoMetaCrudTest::test_guest_is_redirected_from_seo_admin`）
  单独跑、以及所在类单独跑均通过；
- 排查期间确认过一个**与之无关、但同样会污染结果**的缺陷：三套回归共用同一工作树
  （见 §3.6.1），该缺陷已修复并作废了相关批次；
- PG 实例本身在本轮发生过一次 autovacuum PANIC 导致的崩溃恢复（见 §7 第 9 项），
  崩溃窗口内所有连接都会失败。

因此：**不得把「PG 上出现过挂起」写入产品缺陷结论**。修掉 §3.6.1 并重跑后，
若挂起/级联失败在独立工作树 + 稳定实例上复现，再作为独立问题立项。
当前状态记入 §9 门禁表。

---

## 8. 复现方式

```bash
# 跨库结构一致性（三库通用）
php vendor/bin/phpunit --filter DatabaseStructureParityTest

# 真实并发 P0（三库通用）
DB_CONNECTION=pgsql DB_HOST=... php vendor/bin/phpunit -c phpunit.pgsql.xml --testsuite PgsqlCore

# 升级 / 故障恢复 / 回滚演练
php scripts/audit/verify-upgrade-rollback.php <driver> <host> <port> <db> <user> [password] [rollback-steps]

# 全量回归（Windows 必须分批独立进程）
bash run-test-batches.sh <phpunit-config> <out-dir> <label> [chunk]

# 并行跑多套时**必须**同时隔离三样东西，缺一都会得到不可信的计数：
#   1. 工作树：REPO_ROOT=<独立副本>   —— 部分测试会增删 base_path('plugins/<slug>')
#   2. 文件缓存：CACHE_FILE_PATH / CACHE_FILE_LOCK_PATH=<独立目录>
#      —— PageCache 强制 file store，版本计数器跨进程共享
#   3. 输出目录：<out-dir> 各自独立（脚本内置 PID 互斥锁，重复启动会拒绝）
REPO_ROOT=/path/to/copy-pg DB_CONNECTION=pgsql ... bash run-test-batches.sh phpunit.pgsql.xml out/pgsql pgsql 40
```

---

## 9. 发布门禁评估（**评估，未执行发布**）

| 门禁 | 状态 | 阻断项 |
| --- | --- | --- |
| SQLite 全量 + 精确计数门禁 | 需按本轮新增用例更新基线后复跑确认 | 基线计数待最终实测值落定 |
| PostgreSQL 核心回归（零失败） | 本地等价命令已通过；**CI 首次运行未验证** | CI 未跑 |
| `composer audit` | **本地实测通过**（当前基线 b0da0e7 的 lock 已是 `symfony/http-foundation v7.4.19`，`composer audit` 返回「No security vulnerability advisories found」，退出码 0）。上一轮 CI run `40280021433`（提交 3f971e1）的失败**已由该基线修复** | **仍需 CI 确认**：未取得 b0da0e7 上的流水线证据 |
| 覆盖率 | 非阻断（`continue-on-error`） | 阈值待实测校准 |
| 合并到 `main` / tag / 发布 | **冻结**（未授权） | — |

> `composer audit` 的历史阻断详情：CI run `40280021433`（提交 3f971e1）的 test job
> 在第 9 步 `composer audit` 失败 —— 当时 lock 中 `symfony/http-foundation` 锁定
> `v7.4.18`，命中 `CVE-2026-108082`（修复版 `7.4.19`）。
> 当前 worktree 的基线提交 b0da0e7「fix(security): upgrade symfony/http-foundation to 7.4.19」
> 已包含该修复，本地复跑 `composer audit` 已通过。
> 本轮**未**修改任何依赖（授权范围不含无关依赖升级）。


---

## 10. 遗留建议

1. **确认 `composer audit` 在 CI 上转绿**：当前基线 b0da0e7 的 lock 已是
   `symfony/http-foundation v7.4.19`，本地 audit 已通过；仍需一次 CI 运行确认，
   因为上一轮 CI 的失败证据是在旧 lock（v7.4.18）上取得的。
2. **推送授权后优先跑一次 `pgsql` job**：确认 `postgres:16` 服务容器下的
   `PgsqlCore` 零失败，并按实测校准 coverage 的 `timeout-minutes`。
3. **补 MySQL CI job**：本轮 MySQL 已有全套实测证据，只差把证据搬进流水线
   （`mysql:8` 服务容器 + `PgsqlCore` 同构套件），即可把 MySQL 从「候选支持」升为「正式支持」。
4. **考虑把 `RealConcurrencyP0Test` 纳入 CI 的 `test` job**：目前它只在
   `PgsqlCore` 里跑，SQLite 侧的全量回归也会跑到它，但 CI 的精确计数门禁
   需要同步更新基线。
5. **收紧迁移的生产演练**：在预发环境用第 4.2 节的排查 SQL 确认无
   「同站同 slot 多行」，再执行升级。
