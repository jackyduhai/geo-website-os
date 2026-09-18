# Gate 1 Pre-Change Rollback Point

## Git Rollback
- Tag: `gate1-pre-change`
- Commit: `ae3fc4ff9f4831ee3c9e0ab3d14329d7b9bdbb3f`
- Branch: `master`
- Command: `git reset --hard gate1-pre-change`
- Note: 工作区在审计开始时为 clean。审计过程中仅创建了 docs/audit/ 下的文件，未修改业务代码。

## Database Rollback
- File: `docs/audit/gate1-pre-change.sqlite`
- Size: 573,440 bytes
- Verified: YES (23 tables, 224 rows, PDO readable)
- Command: `Copy-Item docs/audit/gate1-pre-change.sqlite database/database.sqlite -Force`
- Tables: users(1), contents(13), categories(14), facts(23), settings(67), page_blocks(16), media(12), groups(9), audit_logs(26), banners(3), menus(1), cache(8), sessions(5), migrations(26), others(0)

## Code Rollback
- File: `docs/audit/gate1-pre-change.zip`
- Size: 127,387,440 bytes (~121MB)
- ZIP entries: 4214
- Verified: YES (ZIP readable, contains app/config/routes/database/resources/tests)
- Excluded: .git, node_modules, vendor, storage/logs, storage/framework/cache, storage/framework/views, storage/framework/sessions
- Command: `Expand-Archive docs/audit/gate1-pre-change.zip -DestinationPath . -Force`

## Verification Summary
- [x] Git tag exists and points to baseline commit
- [x] Database backup file exists and is readable
- [x] Database backup contains all 23 tables with correct row counts
- [x] Code backup ZIP exists and is valid
- [x] Code backup contains 4214 entries (all source files)
- [ ] Full restore test (not performed — would overwrite current state)

## Warning
- 本回滚点仅覆盖 Step 0-4 审计开始时的状态。
- 执行回滚前必须确认当前工作区没有需要保留的修改。
- 数据库回滚会覆盖当前 database/database.sqlite，所有未备份的修改将丢失。
- 代码回滚会覆盖当前源码，docs/audit/ 目录不在备份排除列表中（会被恢复到审计前状态，即空目录）。
