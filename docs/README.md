# GEO Website OS — 文档导航

> 面向第一次接触本仓库的**中文用户**。英文文档见 [README_EN.md](../README_EN.md)。
> 本文档树与仓库代码、测试、Release 事实保持一致；「规划中 / 候选」内容均显式标注。

## 快速入口

| 我想… | 打开 |
| --- | --- |
| 30 秒了解这是什么、适不适合我 | [项目概述](overview.md) |
| 立刻安装跑起来 | [安装与部署教程](installation.md) · [DEPLOY.md](../DEPLOY.md)（生产） |
| 了解系统架构与设计机制 | [系统架构](architecture.md) |
| 学习后台操作 | [使用手册](usage.md) |
| 了解当前能力边界与路线图 | [路线图与当前边界](roadmap.md) |
| 参与开发 / 跑测试 | [开发与维护](development.md) · [CONTRIBUTING.md](../CONTRIBUTING.md) |
| 遇到问题先查 | [FAQ 与故障排查](faq.md) |
| 报告安全漏洞 | [SECURITY.md](../SECURITY.md) |

## 文档地图

```text
docs/
├── README.md            本文档（导航）
├── overview.md          项目概述：是什么 / 解决什么问题 / 为何不是传统 CMS
├── architecture.md      系统架构：分层结构、数据模型、多站点 / 多语言 / GEO 机制
├── installation.md      安装与部署教程（本地 + 生产指引）
├── usage.md             使用手册（后台真实功能操作）
├── development.md       开发与维护（目录、测试、数据库矩阵、贡献）
├── faq.md               常见问题与故障排查
├── roadmap.md           当前边界与路线图
├── architecture/        架构决策与领域设计文档（历史基线，按需查阅）
├── product/             产品规划与生命周期设计（roadmap-1x.md 等）
├── operations/          运维：数据库支持矩阵、CI 门禁口径
├── security/            安全审查与依赖报告
├── design-system.md     设计系统（前端标准）
├── code-standard.md     代码规范
├── html-standard.md     HTML 规范
├── css-standard.md      CSS 规范
├── localization-design.md  本地化 / 多语言设计
└── audit/               工程审计与 Gate 证据（内部过程记录，不进发布制品）
```

## 阅读顺序建议

1. [概述](overview.md) → 判断是否适合自己；
2. [安装](installation.md) → 本地跑起来（5 分钟）；
3. [架构](architecture.md) → 理解机制（多站点、多语言、GEO/SEO、事实契约）；
4. [使用](usage.md) → 后台操作与发布验证；
5. [开发](development.md) → 参与开发与测试。

## 语言

- 中文（主）：本目录 + [README.md](../README.md)
- English：[README_EN.md](../README_EN.md)

## 相关资源

- Releases：[v1.4.0](https://github.com/jackyduhai/geo-website-os/releases)（含 zip / SHA-256 / manifest）
- CI：`.github/workflows/ci.yml`（test + PostgreSQL 16 + release）· [门禁口径](operations/ci-release-gates.md)
- 数据库支持矩阵：[docs/operations/database-support-and-recovery.md](operations/database-support-and-recovery.md)
