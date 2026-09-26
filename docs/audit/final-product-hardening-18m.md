# Final Product Hardening Report（P-STEP 18M）

> 阶段定位：v1.0 发布前**最后一道产品硬化 Gate**——只做只读审查与交付体验验证，**不新增业务能力、不扩大 v1.0、不改架构契约**。
> 审查日期：2026-09-26　·　基线 HEAD（审查开始）：`aeac914`
> 分组：A 安全合规（A1–A4）、B 交付体验（B1–B7）

---

## 1. 总览判定矩阵

| 编号 | 审查项 | 结果 | 证据文档 |
|---|---|---|---|
| A1 | Composer 依赖许可证 / audit | ✅ PASS（0 advisory，无强传染） | license-report.md §2–3 |
| A2 | NPM 依赖许可证 / audit | ✅ PASS（0 vulnerability，MPL 仅构建期） | license-report.md §4–5 |
| A3 | 安全面（secrets / debug / header） | ✅ PASS（无 P0/P1；TD-137 P2） | security-audit-18m.md |
| A4 | 权限回归 / 多站隔离 | ✅ PASS（13 边界断言） | security-audit-18m.md §5 |
| B1 | Fresh Install | ✅ PASS（blank 单语纯净） | first-run-experience-18m.md §2 |
| B2 | Upgrade Path | ✅ PASS（幂等契约） | first-run-experience-18m.md §4 |
| B3 | Backup / Restore | ✅ PASS（DB 闭环；TD-138 P2） | first-run-experience-18m.md §5 |
| B4 | Demo 数据策略 | ✅ PASS（blank/demo 分离） | first-run-experience-18m.md §6 |
| B5 | 默认 Theme / 8 Template | ✅ PASS（8 包 validate 全过；TD-139 P3） | first-run-experience-18m.md §7 |
| B6 | 新用户首用 | ✅ PASS（零代码、无死路） | first-run-experience-18m.md §8 |
| B7 | AI Agent Guide | ✅ 已交付 | docs/ai/agent-guide-v1.md |

**Gate 判定：无 P0/P1，Fresh Install / Upgrade / Restore 均 PASS，Demo 无污染，体验无阻断，文档完整 → 建议 P-STEP 18M = PASS。**
P2/P3 项（TD-137/138/139）按规则进入 v1.1 / 发布工程，不阻塞发布。

---

## 2. 关键工程证据

- **许可证**：项目 MIT；Composer 全 MIT/BSD-3/Apache-2.0（nette 多许可可选 BSD-3）；NPM 仅 lightningcss 为 MPL-2.0（构建期、弱传染、不分发）。`composer audit` 与 `npm audit`（官方 registry）均 **0**。
- **机密**：`.env` 未跟踪、`.gitignore` 完整、无 db/backup 入库、无硬编码 key/token/私钥/密码。
- **Debug**：`APP_DEBUG=false`，无 dd/dump/eval、无 Telescope/Horizon/Debugbar/Ignition、无 console.log。
- **安全头**：前台 CSP script **nonce**、object-src none、base-uri/form-action/frame-ancestors 齐全；X-Frame SAMEORIGIN、nosniff、strict-origin-when-cross-origin、Permissions-Policy 最小权限。
- **权限**：未认证 admin → 302 login；`SiteAuthorizationBoundaryTest` 13 passed，Super 跨站 / Admin 单站边界成立。
- **交付链**：fresh blank（blocks=6/forms=1、zh 200、/en 404 符合 TD-109）；添加 en 后 /en 200；`geo:upgrade` 幂等；`geo:backup/rollback` 差异恢复实证；8 模板包 `validate --strict` 全 ERROR=0/WARNING=0。

---

## 3. 本轮新登记 Technical Debt

| TD | 内容 | 优先级 / 目标 |
|---|---|---|
| **TD-137** | Admin 区域 CSP `script-src 'unsafe-inline'`（前台已 nonce），后台纵深防御偏弱 | P2 / v1.1 |
| **TD-138** | `geo:backup` 仅覆盖 SQLite，灾难恢复缺 media 物理文件 | P2 / v1.1 |
| **TD-139** | 8 模板包 preview 为 GD 占位截图，发布前需替换真实截图 | P3 / 发布工程 |

既有 v1.1 项继续沿用：TD-14 RBAC、TD-76 Logo srcset、TD-79 Page/Landing 搜索、TD-136 菜单 URL 相对化等。

---

## 4. v1.0 / v1.1 边界（再次确认，未改变）

- **v1.0 产品能力已收口**：多站、Theme/Token、Component、Block、Template Package、Page Composition、Search(FTS5)、Form/Submission/Inquiry、Analytics+Consent、Audit、Install/Upgrade/Backup/Rollback、SEO/GEO。
- **v1.1**：TD-14（RBAC + Site Membership）、TD-137（admin CSP nonce）、TD-138（全量备份含 media）、TD-76、TD-79、TD-136、Marketplace/在线 zip、自由拖拽 Canvas、完整自动迁移平台。
- **发布工程残项**：TD-01 Cloud CI 首跑、TD-02 Final RC 重建、TD-03 Private → Public / v1.0.0；TD-139 截图替换在发布前完成。

18M 未对产品运行态做任何改动（仅新增文档；locale 改动限于临时测试库 geo4b3，不影响主仓库与产品基线）。

---

## 5. 结论与下一步

- **P-STEP 18M — Final Product Hardening：建议 PASS / CLOSED。**
- 产品建设与发布前硬化均已完成；系统具备作为独立产品**可交付、可安装、可升级、可维护**的工程完整性。
- 下一步进入发布工程，需明确授权：**P-STEP 19A — Private GitHub + Cloud CI**（仓库完整性 → Private Remote → Push v1.0 基线 → Cloud CI 首跑 → fresh checkout/install/test 对账 → Gate STOP）。
- 在 19A 授权前：rc1（`965d63c`）不动、不配置 remote、不 push、不 Release。

---

### 附：18M 交付物清单

| 文件 | 内容 |
|---|---|
| `docs/audit/final-product-hardening-18m.md` | 本总报告 |
| `docs/security/license-report.md` | Composer/NPM 许可证与 audit |
| `docs/security/security-audit-18m.md` | 安全面与权限审计 |
| `docs/product/first-run-experience-18m.md` | 安装/升级/灾备/首用体验 |
| `docs/ai/agent-guide-v1.md` | AI Agent 架构与安全边界指南 |
