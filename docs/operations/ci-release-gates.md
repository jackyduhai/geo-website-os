# CI 与发布门禁口径

本文记录 `main` 分支所依赖的 CI 门禁结构、发布作业的依赖关系，以及覆盖率测量的定位。
写作时间 2026-10-28，对应 V1.4 跨库兼容性整改（分支 `feature/v1.4-db-compat`）。

## 1. 门禁清单

| 工作流 | 作业 | 触发 | 判定口径 | 是否发布门禁 |
| --- | --- | --- | --- | --- |
| `ci.yml` | `test (8.4)` | push 到 main/master、指向 main 的 PR | 与 `scripts/audit/test-baseline.json` 精确相等（tests / assertions / failures / errors / skipped 五项） | 是 |
| `ci.yml` | `PostgreSQL 16` | 同上 | failures / errors / skipped 全为 0，且 tests ≥ 174 | 是 |
| `ci.yml` | `release` | tag `v*` | 依赖 `test` 与 `pgsql` 均成功 | 发布执行体 |
| `coverage.yml` | `coverage` | 手动触发、每日定时 | 产出分支/路径覆盖率报告 | 否，仅证据 |

## 2. 发布作业的依赖与放行行为

```yaml
  release:
    needs: [test, pgsql]
    if: startsWith(github.ref, 'refs/tags/v')
```

放行矩阵（GitHub Actions 语义，配合本文配置）：

| `test` 状态 | `pgsql` 状态 | `release` 结果 |
| --- | --- | --- |
| success | success | 执行 |
| failure | 任意 | skipped |
| 任意 | failure | skipped |
| cancelled | 任意 | skipped |
| 任意 | cancelled | skipped |
| skipped | 任意 | skipped |
| 任意 | skipped | skipped |

关键点：`release` 的 job 级 `if` 只判 tag，没有 `always()`，因此依赖作业未成功时不会被执行。
文件内唯一的 `always()` 位于 pgsql 作业内部的诊断步骤，不影响作业本身结论。

历史观测旁证：run 40280021433 中 `test` 因 `composer audit` 失败，`release` 结论为 skipped。
`pgsql` 作为发布依赖后的失败场景尚无历史运行，其拦截能力由上述结构保证。

## 3. PostgreSQL 门禁的两段判定

1. **零失败**：`failures = 0 且 errors = 0 且 skipped = 0`。
2. **数量下限**：`tests >= 174`。

第二条存在的原因：只判零失败时，一个什么都没跑的空 JUnit（tests=0，各计数为 0）同样通过，
门禁会给出假绿。下限把「没跑」与「跑了且全过」分开。

阈值来源（可复核）：

```
php vendor/bin/phpunit -c phpunit.pgsql.xml --testsuite PgsqlCore --list-tests
```

列表为 174 条，覆盖 `tests/Unit` 全目录、`tests/Feature/Concurrency` 全目录、
`DatabaseStructureParityTest` 以及 `ContentLifecycle`、`EntityLifecycleMatrix`、
`FactValidation`、`SiteManagement`、`GeoflowApi`、`MigrationRollbackFidelity`、
`RouteBindingGuard`、`FactLocaleUpdate`、`CurrentDatabaseTables` 九个体检文件。
JUnit 用例数与方法数一致（`RouteBindingGuardTest` 实测 24 方法 / 24 testcase）。

维护约定：新增用例无需改数字；**删除、重命名导致漏收集、路径写错少收都会红**，
确需缩减必须先改 `ci.yml` 中的 `--min-tests` 并说明理由，使其成为显式决策。

## 4. 覆盖率的定位

`coverage` 从 `ci.yml` 移出，成为独立工作流 `coverage.yml`。

依据（远端实测）：run 40659217487 中该作业进入真正的路径覆盖生成后，跑到作业级
`timeout-minutes: 45` 被终止，注解为 `The job has exceeded the maximum execution time of 45m0s`，
作业结论 cancelled。cancelled 不被 `continue-on-error` 吸收，会把整条 workflow 拖成 cancelled，
导致每次推送都没有绿灯，而覆盖率本身一条数据都没产出。

迁移口径：

- 测量命令与范围不变（`phpunit-cov-branch.xml` + `--path-coverage`，范围为
  `app/Services/Gate`、`app/Services/Cache`、`app/Http/Middleware` 与两个 Support 类）。
- 预算放宽到 180 分钟，触发方式为手动与定时，不占用推送与 PR 的关键路径。
- 它从来不是发布门禁，产出是证据。不得把它的失败或取消表述为通过。
- 首次远端实测待该文件进入默认分支后进行（`workflow_dispatch` 与 `schedule` 都要求
  工作流存在于默认分支或可被选中）。

## 5. 相关命令

```bash
# 主计数门禁（本地与 CI 同源）
php scripts/audit/verify-test-baseline.php --junit=storage/logs/junit.xml

# 子集门禁：零失败口径 + 数量下限
php scripts/audit/verify-test-baseline.php \
  --junit=storage/logs/junit-pgsql.xml --no-baseline --min-tests=174 \
  --summary=storage/logs/pgsql-summary.json

# 发布产物里的计数（release 作业同源，不硬编码）
php scripts/audit/verify-test-baseline.php --junit=test-results/junit.xml --no-baseline
```
