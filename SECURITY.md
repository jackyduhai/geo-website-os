# 安全政策（Security Policy）

## 报告漏洞

**请不要为漏洞开公开 issue。** 请将安全相关问题**私密报告**给维护者。

当前私密报告渠道：

- GitHub 私有安全公告（Private Security Advisory）：仓库主页 → Security → Report a vulnerability
- 或直接通过仓库维护者的 GitHub 账号联系（`jackyduhai`）

请在你的报告中包含（能提供的越多越好）：

- 影响版本（提交 hash / Release 标签）
- 漏洞类型与复现步骤
- 影响范围评估（哪些部署形态受影响）
- 你建议的缓解或修复方向

## 处理承诺

- 维护者会尽快确认报告并回复；
- 在补丁可用前，**不**公开展示漏洞细节；
- 修复后将在发布说明（CHANGELOG / Release Notes）中标注，并视严重程度决定是否单独发布修复版本。

## 安全设计基线（本项目已内置）

- **多站点隔离**：数据库层 + 查询层双重隔离；公开请求无法用查询参数切换站点；跨站（system）访问是显式的管理员授权能力。
- **错误页不泄露**：调试详情、堆栈、SQL 与用户数据不会出现在错误页（生产 `APP_DEBUG=false`）。
- **依赖审计**：CI 中 `composer audit` 与 `composer check-platform-reqs` 为发布门禁。
- **结构化数据安全**：JSON-LD 输出对 `</script>` 转义（防存储型 XSS，20A P1 修复）；`SafeUrl` 对回环主机等地址降级为站内相对路径。
- **发布门禁**：内容/实体发布时强制校验结构化与合规表述（`config/geo.php` 的 `gate`）。
- **限流**：表单提交与询盘提交带 `throttle:6,1`。
- **已知依赖修复记录**：v1.4.0 将 `symfony/http-foundation` 升级至 7.4.19（阻断 CVE-2026-108082），见 `docs/security/B4-http-foundation-v7.4.19-upgrade-report.md`。

## 边界与声明

- 生产环境必须 `APP_ENV=production`、`APP_DEBUG=false`、`SITE_DEFAULT_FALLBACK=false`，并遵循 [DEPLOY.md](DEPLOY.md) 的上线前自检清单。
- 本政策不替代各部署环境的安全加固；请自行评估防火墙、HTTPS/HSTS、备份与访问控制。
