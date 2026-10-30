# P-STEP 09 — Update / Rollback / Deployment Engineering

> 生命周期契约：Install → Backup → Update → Verify → Rollback(故障时) → Recovery

## 1. 回滚三边界（冻结，不做假装的"智能逆向"）

| 边界 | 机制 | 工具 |
|---|---|---|
| **DB 回滚** | 整库文件还原（非 migration 逆向——数据迁移后的列结构逆向不保证无损） | `geo:backup` + `geo:rollback` |
| **Code 回滚** | 部署层：重新部署上一版 Release Artifact（产物按 version+commit 命名，天然可回切） | 部署脚本/运维 |
| **Config 回滚** | `.env` 属环境资产（含密钥，不进备份）；变更需对照 `.env.example` 人工核对 | 运维 |

## 2. 命令契约

**geo:backup**
- 仅 sqlite 自动化（整库复制到 `storage/app/backups/`）；mysql/pgsql 诚实拒绝并
  给出 mysqldump/pg_dump 约定——**不假装可回滚**。
- 清单 JSON：version / migrations_last / migrations_count / 数据计数 / sha256。

**geo:rollback --backup=<file> --force**
- 清单 sha256 校验（损坏/篡改拒绝）；
- 无 --force 拒绝执行（实测验证）；
- 还原前自动保存当前状态（回滚的回滚）；
- 明确提示 Code 回滚是部署层职责。

## 3. 隔离环境全链路演练（release artifact 环境）

| 步骤 | 结果 |
|---|---|
| Release N 安装（38 迁移） | ✅ exit 0 |
| geo:backup | ✅ 清单 version=2.0.0 migrations=38 |
| **故障注入**：坏迁移（throw）→ geo:upgrade | ✅ 迁移 FAIL（事务内回滚），**GET / 仍 200**（站点可服务） |
| geo:rollback 无 --force | ✅ 拒绝 |
| geo:rollback --backup --force | ✅ 还原至备份（migrations_last=drop_legacy…），当前态已另存 |
| **恢复**：修复迁移（新增 marker 表）→ geo:upgrade | ✅ 39 迁移完成，marker 表建立 |
| 升级后验证 | ✅ GET / 200 + SEO head 四项齐全 |

## 4. 迁移安全策略（Migrations Safety Policy）

1. 任何 drop/改列 migration 必须先有对应 backfill 且纳入 `geo:upgrade` 的
   pre-migrate 阶段（P-STEP 07 P0-B 顺序即契约）。
2. 升级前 `geo:backup` 为强制步骤（upgrade 流程文档要求，未自动化强制——
   由运维规程保证，记录为本项已知边界）。
3. 迁移 up() 必须可独立失败（事务包裹由 Laravel 保证，单迁移失败不污染全批）。
4. 逆向（down()）只保证结构重建，不承诺数据恢复——数据恢复唯一途径是备份还原。

## 5. 残余边界

- backup 为本机文件系统复制；跨机部署需将 storage/app/backups 纳入运维备份链。
- 升级前备份的自动化强制（geo:upgrade 内嵌 backup 调用）列为后续增强。
