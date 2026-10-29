# 贡献指南（Contributing Guide）

感谢你考虑为 **GEO Website OS** 贡献代码、文档或反馈。请先阅读本指南与仓库标准文档。

## 项目纪律（最重要）

**保持核心通用（Business-neutral by construction）。**

- 运行时代码（`app/`、`config/`、`routes/`）、默认数据（`database/seeders/`）与发布的主题
  （`resources/themes/`）中**不得**包含：
  - 任何客户名称、品牌、真实联系方式、真实地址；
  - 任何特定行业、特定公司的业务数据。
- 示例数据必须来自 Seeder（通用 Example 数据集），禁止硬编码业务内容。
- 反向业务污染断言在 CI 中执行（`tests/` 中的 pollution 相关用例），新增内容不得引入污染词。
- 新增公开事实必须同时在 `database/seeders/FactSeeder.php` 与 `app/Services/Geo/FactLabels.php`
  登记，否则 `tests/Feature/Geo/GeoFactConsistencyTest.php` 会失败。

## 标准文档

提交前请通读：

- [code-standard.md](docs/code-standard.md) — 代码规范
- [design-system.md](docs/design-system.md) · [html-standard.md](docs/html-standard.md) · [css-standard.md](docs/css-standard.md)
- [development.md](docs/development.md) — 环境、目录、测试方法
- [architecture.md](docs/architecture.md) — 架构与机制

## 如何提交

1. Fork 本仓库并创建特性分支（`feature/xxx`）。
2. 每个新行为必须配套测试；测试必须是**结果语义断言**，不是控制流断言。
3. 本地跑通全量回归：
   ```bash
   php vendor/bin/phpunit
   ```
   v1.4.0 基线：**1761 tests / 9470 assertions / 0 failures / 0 errors / 0 skipped**。
4. 若测试计数变化，按 `scripts/audit/test-baseline.json` 文件头的 runbook 有意更新基线
   （CI 计数门禁会精确比对，防测试被静默删除或未被发现）。
5. 提交信息遵循 Conventional Commits 风格（`feat:` / `fix:` / `docs:` / `test:` / `ci:` / `chore:`）。
6. 开 Pull Request，说明改动、证据（测试结果）与影响范围。

## 测试纪律

- 新增用例无需改动 CI 数字；**删除 / 重命名导致漏收集、路径写错少收都会红**，确需缩减必须先改
  `.github/workflows/ci.yml` 中的 `--min-tests` 并说明理由（仅 PostgreSQL 门禁）。
- 跨库（SQLite / PostgreSQL / MySQL）行为差异请先读
  [数据库支持矩阵](docs/operations/database-support-and-recovery.md) 第 2 节，
  不要假设三库 API 语义相同。
- 涉及「多行同 key / 多语言 / 多币种」的断言必须**显式定位行**，不依赖 `first()` 返回顺序。

## 文档贡献

- 面向用户的文档（`docs/` 与 README）以**中文为主**，英文维护在 `README_EN.md`；
- 版本、命令、兼容性与限制的中英文描述必须一致；
- 「已实现」必须对应代码 / 测试 / Release 证据；规划内容必须显式标注「未实现 / 规划中」。

## 发布与 Gate 纪律

本项目按 Gate 纪律推进（Discovery → Architecture Review → Implementation → Regression → Gate → STOP）。
不经过授权流程，**不**：配置 remote、push、创建 Release、移动标签、发布新版本。
详见 [docs/roadmap.md](docs/roadmap.md) 与仓库项目规则。
