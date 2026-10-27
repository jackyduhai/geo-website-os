# B4 安全依赖升级正式报告

**symfony/http-foundation v7.4.18 → v7.4.19（CVE-2026-108082 修复）**

| 项 | 值 |
|---|---|
| 报告生成日期 | 2026-10-27 |
| 工作范围 | 隔离 worktree `D:\GEO-OS-security-upgrade` |
| worktree HEAD | `3f971e14acff89aa87275b0f1751f8e0c8d4dbeb`（detached，与主仓库 redact-v1 一致） |
| 主仓库 | redact-v1 @ `3f971e14`（工作树干净，升级未合入） |
| 报告状态 | 整理完成，**未提交、未推送、未发布**（STOP，等待下一步授权） |

---

## 1. 变更概述（已验证事实）

将依赖链中的 `symfony/http-foundation` 由 `v7.4.18` 升级至 `v7.4.19`，以修复 `composer audit` 在 CI（run 40280021433）中检出的安全公告 **CVE-2026-108082**。该公告在升级前命中 `v7.4.18`（影响区间 `>=7.4.0,<7.4.19`，修复版本 `7.4.19`）。

- 引入方（升级前 `composer why --locked`）：`laravel/framework ^7.2.0`、`symfony/http-kernel ^7.4`、`fruitcake/php-cors ^7.3`。
- `composer prohibits 7.4.19` 无阻断，纯 patch 升级可行（已验证）。

## 2. 变更范围（已验证事实，精确比对）

仅 `composer.lock` 一个文件变更，`composer.json` 未改动。

`git diff --stat composer.lock`：

```
 composer.lock | 12 ++++++------
 1 file changed, 6 insertions(+), 6 deletions(-)
```

12 行变更（6 增 6 删）**全部位于 `symfony/http-foundation` 单一包条目内**，其它包无任何改动：

- `version`：`v7.4.18` → `v7.4.19`
- `reference`：`d070b716a32fbe3bf04204db0f58ace73b86d133` → `f7b953be7801974a5a0685e1228bbc3b12b35936`（两处：dist 与 source）
- `url`（zipball）对应 reference 同步变更（两处）
- `source` 树路径：`/tree/v7.4.18` → `/tree/v7.4.19`
- `time`：`2026-08-30T20:10:52+00:00` → `2026-09-14T17:41:17+00:00`

`composer.json` 内容哈希（lock 内 content-hash）因 `composer.json` 本身未变而保持不变，故 `composer validate` 不报内容哈希失配。

## 3. 依赖门禁（已验证事实）

| 校验项 | 来源 | 结果 |
|---|---|---|
| 升级前门禁 | 阶段 B4 步骤1 | 通过（升级前 `composer audit` 仅命中本 CVE，无其它阻断） |
| 硬门禁 | 仅 `composer.lock` 变、json 未变 | 通过 |
| `composer audit`（升级后） | `B4-composer-audit.log`（worktree 根） | `No security vulnerability advisories found.`（无公告）；日志未捕获显式退出码，exit 0 系逻辑推断（composer audit 仅在无公告时以 0 退出） |
| `composer validate` | `B4-composer-validate.log`（worktree 根） | `./composer.json is valid`；日志未捕获显式退出码，exit 0 系逻辑推断（composer validate 仅在配置有效时以 0 退出） |

> 本地未能重新执行 `composer audit`（本环境 `composer` CLI 不可用）；升级后审计证据以 worktree 内既有 `B4-composer-audit.log` 为准，与 CI 记录一致。

## 4. 全量测试（已验证事实）

执行环境（per `b4-clean-fullrun.log` 头部）：

- OS：Windows（win32）
- PHP：`8.4.25 (cli)`
- PHPUnit：`11.5.56`
- 测试库：`phpunit.xml` 配置 `DB_DATABASE=:memory:`（SQLite 内存库）
- 执行方式：12 个独立 phpunit 进程分批（规避 Windows 下 `artisan test` 输出缓冲假死），JUnit 逐批落盘

干净重跑（`b4-clean-run.sh`，后台任务 `1PBosS`）逐批计数：

| 维度 | 值 |
|---|---|
| tests | **1714** |
| assertions | **9149** |
| failures | **0** |
| errors | **0** |
| skipped | **0** |

逐批明细见证据索引 `b4-clean-batchNN.xml` 与 `b4-clean-fullrun.log`。`b4-clean-fullrun.log` 逐批记录显式 `=== BATCH NN exit=0 ===`（12 批均为 0），末行 `=== ALL CLEAN DONE 09:03:10Z ===`；后台任务整体退出码 0 来自任务完成通知，日志本身未单独记录脚本整体退出码。

## 5. JUnit 聚合与基线门禁（已验证事实）

- 聚合：将 12 个 `b4-clean-batchNN.xml` 的顶层 `<testsuite name="CLI Arguments">` 用 DOM 合并为 `b4-clean-merged.xml`（532,551 字节）。
- 基线门禁：`php scripts/audit/verify-test-baseline.php --junit=b4-clean-merged.xml`
  - 结果：`[PASS]`，`tests=1714 / assertions=9149 / failures=0 / errors=0 / skipped=0`，与 `scripts/audit/test-baseline.json`（基线提交 `eb768de`：1714/9149）**精确相等**。
  - 证据：`storage/logs/B4-baseline-gate.log`。该日志记录 `[PASS]` 结论文本，未捕获显式退出码；exit 0 系逻辑推断（门禁脚本仅在计数完全一致且无 failures/errors/skipped 时以 0 退出）。

**结论**：升级后测试计数与既有基线完全一致，该 patch 升级在 Windows + PHP 8.4 + SQLite 内存库口径下对业务零回归影响。

## 6. 隔离与未泄漏核验（已验证事实）

- 主仓库 `redact-v1` 工作树 `git status --short` 为空，未受升级影响。
- 主仓库 `composer.lock` 仍含 `symfony/http-foundation v7.4.18`（行 4016），升级**仅存在于隔离 worktree 的未提交 `composer.lock`**。
- 隔离 worktree `git status --short` 仅 ` M composer.lock` 一项，`composer.json` 等均未改动。

## 7. 哈希记录（已现场核验）

文件 SHA-256 与 Git blob SHA-1 是两种不同的哈希标识，必须分开记录，不得混用。下表全部数值经本次 `sha256sum` 与 `git hash-object` 现场计算核实：

| 文件 | SHA-256（文件哈希） | Git blob SHA-1（Git 对象标识） |
|---|---|---|
| `composer.json`（worktree 与主仓库一致） | `f9359bf91545b734b2332b5f04506d0245f2390b56f52d9f18ca17a4f97ac96c` | `e9043408482c59c4fc232e36eb8f4c522d7add96` |
| 主仓库 `composer.lock`（v7.4.18） | `a1dbfd84a345bcf23ff2f8cbff14af677bab6a454276a6f23f79bbc56371a659` | `0be02e2fcbc9e9f438a1d3366365a90f7c36331d` |
| 隔离 worktree `composer.lock`（v7.4.19，未提交） | `60c2ecc0ebf40703d5374471202fc3b1256c7b61411ef4ae12909c8b4b50ad00` | `46cd8e667e6f7aef68c6a444bc6f6918cef8a0a0` |

**历史记录更正**：此前（阶段 B4 期间）曾用 `git cat-file -t` 查询上述文件 SHA-256，返回 NOT FOUND，据此将 `f9359bf…`/`a1dbfd…` 误判为"文书误差 / 不应作为证据"。该核验方法错配：Git 对象库使用 SHA-1 存储 blob，不可能包含 SHA-256 哈希值，故 `git cat-file -t` 对 SHA-256 必然返回 NOT FOUND。本次改用 `sha256sum` 现场计算，证实 `f9359bf…` 是 `composer.json` 的有效 SHA-256、`a1dbfd…` 是主仓库 `composer.lock`（v7.4.18）的有效 SHA-256，两组旧哈希恢复为有效证据。

- `e904340…`/`0be02e2…` 为 Git blob SHA-1 标识（即 `HEAD:composer.json`/`HEAD:composer.lock` 的 git 对象哈希），不是文件 SHA-256，与上表 SHA-256 列属不同算法，不存在"更正/替代"关系。
- 主仓库 `composer.json`/`composer.lock` 自基线提交 `3f971e1` 起未变（`git status --short` 为空），升级隔离结论成立。

## 8. 竞态污染批次隔离处置与干净重跑采信（已验证事实）

- **污染成因**：首批后台任务 `gdCfZA` 因系统 429 信用错误中断，日志停于 BATCH 07；AI 误启续跑脚本 `b4-resume.sh`（任务 `u7ccrw`，批次 07–12），与 `gdCfZA` 恢复后产生竞态，污染 JUnit（batch12 空、tests 巧合为 1714、assertions=9143、failures=2、errors=1）。
- **隔离处置**：将污染现场整包归档至 `storage/logs/b4-suspect-evidence/`（13 项：`b4-fullrun.RACE-CORRUPTED.log` + `b4-junit-batch01..12.xml`），**未用于任何判定**。
- **干净重跑采信依据**：
  1. 确认无残留 phpunit 进程（ps 检查为空）；
  2. 以单一脚本 `b4-clean-run.sh` 顺序执行 12 批次独立 phpunit 进程，写入 `b4-clean-batchNN.xml` + `b4-clean-fullrun.log`，整体 exit 0；
  3. 12 批 JUnit 的 CLI Arguments 顶层计数聚合为 `1714/9149/0/0/0`，与基线精确相等；
  4. 该干净重跑结果（`b4-clean-*` 系列）为**唯一采信口径**，污染批次（`b4-junit-batchNN.xml`、`b4-fullrun.RACE-CORRUPTED.log`）仅作隔离留痕。

## 9. 总体判级

**在隔离 worktree 内，本次安全依赖升级（v7.4.18 → v7.4.19）通过全部本地门禁：依赖审计清洁、配置有效、全量测试计数与基线精确一致、变更范围最小且未泄漏至主仓库。** 升级具备合入主仓库的本地质量依据。

## 10. 未验证事项（NOT VERIFIED，继续标记）

以下项**不在本次隔离 worktree 工作范围内**，维持原判定，不得据此推定发布资格：

1. **Linux CI 全量测试**：CI run 40280021433 因 `composer audit` 失败，测试/计数门禁/smoke 步骤被 skipped，Linux 侧未实测。
2. **PostgreSQL 后端**：测试仅在 SQLite 内存库口径执行，PostgreSQL 迁移/运行未验证。
3. **真实并发**：本环境无 redis/pcntl/posix，真多进程并发不可实测。
4. **发布资格**：B2/本地门禁通过 ≠ 发布资格已确认；未打 tag、未建 PR、未发布、未部署。
5. **本地 `composer audit` 重新执行**：本环境 `composer` CLI 不可用，升级后审计以既有 `B4-composer-audit.log` 与 CI 记录为准。

## 11. 证据索引

详见同目录 `B4-evidence-index.md`。
