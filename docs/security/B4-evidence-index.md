# B4 证据索引（Evidence Index）

> 关联报告：`B4-http-foundation-v7.4.19-upgrade-report.md`
> 范围：隔离 worktree `D:\GEO-OS-security-upgrade`
> 生成日期：2026-10-27（含阶段 D-R 修订）
> 状态：证据仅整理、未提交、未推送。

> **证据交付边界（R3）**：本索引引用的 JUnit、运行日志、污染证据及 VOID 文件属于**本地审计留档**，不纳入当前候选提交（候选提交仅含 `composer.lock`、`B4-http-foundation-v7.4.19-upgrade-report.md`、`B4-evidence-index.md` 三份）。其他审查者能否复核本索引引用的本地结论，取决于这些本地留档文件是否仍可访问；本索引不声称审查者仅凭 Git 提交即可复现全部结论。

> **无扩展名文件说明（R4）**：`storage/logs/b4-clean-batch-00` 至 `b4-clean-batch-11` 共 12 个无扩展名文件，是 `b4-clean-run.sh` 第 7 行用 `split -n l/12` 从 `b4-clean-files.txt` 切分生成的**测试文件路径清单**（每行一个 `tests/…` 路径），供 phpunit 命令行参数使用，**不是 JUnit XML**，不作为测试结果证据。每批 JUnit 结果为同名 `b4-clean-batchNN.xml`（01–12，注意编号前缀差异：清单为 `batch-00` 起，JUnit 为 `batch01` 起）。

## 一、采信证据（用于判定）

| # | 证据文件（相对 worktree 根） | 类型 | 证明内容 | 状态 |
|---|---|---|---|---|
| 1 | `storage/logs/b4-clean-batch01.xml` … `b4-clean-batch12.xml`（12 个） | JUnit XML | 干净重跑逐批测试结果（CLI Arguments 顶层计数） | 采信，聚合为 1714/9149/0/0/0 |
| 2 | `storage/logs/b4-clean-merged.xml` | JUnit XML（合并） | 12 批 DOM 合并产物，基线门禁输入 | 532,551 字节 |
| 3 | `storage/logs/b4-clean-fullrun.log` | 执行日志 | 干净重跑全过程，`=== ALL CLEAN DONE 09:03:10Z ===`，exit 0 | 采信 |
| 4 | `storage/logs/b4-clean-run.sh` | 脚本 | 干净重跑可复现脚本（单一顺序 12 批） | 可复现依据 |
| 5 | `storage/logs/B4-baseline-gate.log` | 门禁输出 | `verify-test-baseline.php` 结果：`[PASS]`，1714/9149/0/0/0；日志未捕获显式退出码，exit 0 系逻辑推断 | 采信 |
| 6 | `B4-composer-audit.log`（worktree 根） | 审计输出 | 升级后 `composer audit`：`No security vulnerability advisories found.`；日志未捕获显式退出码，exit 0 系逻辑推断 | 采信 |
| 7 | `B4-composer-validate.log`（worktree 根） | 校验输出 | `composer validate`：`./composer.json is valid`；日志未捕获显式退出码，exit 0 系逻辑推断 | 采信 |
| 8 | `docs/security/B4-http-foundation-v7.4.19-upgrade-report.md` | 报告 | 正式报告本体 | 本交付物 |
| 9 | `docs/security/B4-evidence-index.md` | 索引 | 本文件 | 本交付物 |

## 二、隔离留痕（未采信，仅存档）

| # | 证据文件（相对 worktree 根） | 类型 | 说明 |
|---|---|---|---|
| 10 | `storage/logs/b4-suspect-evidence/b4-fullrun.RACE-CORRUPTED.log` | 污染日志 | 竞态污染批次执行日志（gdCfZA + u7ccrw 重叠） |
| 11 | `storage/logs/b4-suspect-evidence/b4-junit-batch01.xml` … `b4-junit-batch12.xml`（12 个） | JUnit XML | 竞态污染批次原始 JUnit，**不用于任何判定** |

## 三、外部/非本地证据（记录但不以本地文件佐证）

| 项 | 来源 | 说明 |
|---|---|---|
| CVE-2026-108082 命中记录 | CI run 40280021433（第 9 步 `composer audit` 失败） | 升级前主仓库（v7.4.18）被审计命中，导致 CI 失败；本地 `composer` CLI 不可用，未重新执行 |
| 主仓库 v7.4.18 隔离确认 | `redact-v1` `composer.lock` 行 4016（`"version": "v7.4.18"`） | 升级未泄漏至主仓库（已读验证） |

## 四、哈希记录（现场核验，SHA-256 与 Git blob SHA-1 分开记录）

文件 SHA-256 与 Git blob SHA-1 是两种不同的哈希标识，必须分开记录，不得混用。下表全部数值经本次 `sha256sum` 与 `git hash-object` 现场计算核实：

| 文件 | SHA-256（文件哈希） | Git blob SHA-1（Git 对象标识） |
|---|---|---|
| `composer.json`（worktree 与主仓库一致） | `f9359bf91545b734b2332b5f04506d0245f2390b56f52d9f18ca17a4f97ac96c` | `e9043408482c59c4fc232e36eb8f4c522d7add96` |
| 主仓库 `composer.lock`（v7.4.18） | `a1dbfd84a345bcf23ff2f8cbff14af677bab6a454276a6f23f79bbc56371a659` | `0be02e2fcbc9e9f438a1d3366365a90f7c36331d` |
| 隔离 worktree `composer.lock`（v7.4.19，未提交） | `60c2ecc0ebf40703d5374471202fc3b1256c7b61411ef4ae12909c8b4b50ad00` | `46cd8e667e6f7aef68c6a444bc6f6918cef8a0a0` |

**历史记录更正**：此前（阶段 B4 期间）曾用 `git cat-file -t` 查询上述文件 SHA-256，返回 NOT FOUND，据此将 `f9359bf…`/`a1dbfd…` 误判为"文书误差 / 不应作为证据"。该核验方法错配：Git 对象库使用 SHA-1 存储 blob，不可能包含 SHA-256 哈希值，故 `git cat-file -t` 对 SHA-256 必然返回 NOT FOUND。本次改用 `sha256sum` 现场计算，证实 `f9359bf…` 是 `composer.json` 的有效 SHA-256、`a1dbfd…` 是主仓库 `composer.lock`（v7.4.18）的有效 SHA-256，两组旧哈希恢复为有效证据。

`e904340…`/`0be02e2…` 为 Git blob SHA-1 标识（即 `HEAD:composer.json`/`HEAD:composer.lock` 的 git 对象哈希），不是文件 SHA-256，与上表 SHA-256 列属不同算法，不存在"更正/替代"关系。
