# 20G-7 · Full Human Simulation 报告

> **基线**：`D:\734666\GEO OS` @ `91aef17`（20F.1，未 commit）
> **WORKTREE**：⚠️ DIRTY — 21 items（6 改 + 3 新类 + 1 迁移 + 11 测试）
> **模拟环境**：`D:/Temp/h7_human.sqlite`（**41/41 migrations**，与开发库 39/41 隔离）
> **日期**：2026-10-03
> **开发库**：全程未被修改（contents=3 / entities=12 / sites=2 / migrations=39）

---

## 1. Environment

| 项 | 值 |
| --- | --- |
| 数据库 | `D:/Temp/h7_human.sqlite`，独立于开发库 |
| 迁移 | **41/41 全部执行**（开发库刻意保持 39，未污染） |
| 表| 27 张 |
| 站点 | A 站 `a.h7.test`（默认站）、B 站 `b.h7.test` |
| 管理员 | `admin@example.com` / 密码由安装器生成后设为 `Admin@123456` |
| PHP | 8.4.25 |
| 请求方式 | **真实 HTTP 内核**（`Kernel::handle` + `Request::create`） |

> ⚠️ **为什么不用 Laravel Feature Test**：20G-5 已实测 `$this->get()` / `withHeaders(['Host'=>])` / `withServerVariables(['HTTP_HOST'=>])` 三种方式**全部无法覆盖 Host 头**，`getHost()` 恒为 `localhost`。多站模拟必须走真实 HTTP 内核。

## 2. Operator Profile

模拟一个**没有阅读源码、只按后台 UI 与提示操作**的运营人员：

| 维度 | 设定 |
| --- | --- |
| 角色 | 企业官网内容运营（非技术） |
| 目标 | 建站后发布一篇带 GEO 信息的知识文章|
| 知识 | 知道「填标题、正文、GEO 四要素、点发布」 |
| 不知道 | 字段名、路由、门禁规则、API 契约 |
| 判断标准 | **看到界面能不能知道下一步该做什么** |

## 3. Seed / Fixtures

```
geo:install            → 1 站 + 1 管理员 + 65 条设置
StructureSeeder        → 2 栏目 / 6 知识分组
FactSeeder             → 23 条事实
手工                   → B 站（b.h7.test）+ B 站栏目 2 个 + 分组 6 个
```

> **重要前置**：B 站的栏目与分组是**手工补建**的。系统 `geo:install` 只创建一个站点，
> 且**不为新站初始化栏目**（见 §9 UX-001）。

## 4. Journey Matrix

| Journey | 模拟行为 | 结果 | 关键证据 |
| --- | --- | --- | --- |
| **H1** 登录与站点识别 | 登录 → 进后台 → 识别当前站点 | ⚠️ 部分 | 未登录 302 拦截；错误密码有可理解 flash；后台显示站点身份；**站点管理页404** |
| **H2** 内容创建 | 新建文章 → 填标题/正文/GEO 信息 | ✅ PASS | 302 → 编辑页；DB 落库；初始 draft；site_id 正确 |
| **H3** 内容编辑 | 改内容 → 保存 → 看变更 | ✅ PASS | 摘要/正文持久化；revision 累积；**内容指纹随编辑变化** |
| **H4** 发布流程 | 草稿 → 发布 → 前台查看 | ✅ PASS | status=published；published_at 写入；前台 200；canonical 正确；schema 输出 Organization+Article+BreadcrumbList |
| **H5** 人工锁定 | 精修 → 锁定 → GEOFlow 推送 | ✅ PASS | upsert **409**；unpublish **409**；人工版本保留；提示文案清晰；同步日志记录冲突 |
| **H6** GEO 验证 | 检查 4 类派生输出 | ✅ PASS | geo.json（含 `$schema` 版本标识）/ llms.txt / sitemap.xml / feed.xml 全部 200 |
| **H7** 异常恢复 | 非法输入 / 重复提交 / 误操作 | ✅ PASS | 9/9 全部通过，无 500、无脏数据 |
| **H8** 多站运营 | A 站走完 → 切 B 站同 slug | ✅ PASS | 7/7：A 站看不到 B 站内容、B 站能看自己的、A 站后台列表不含 B 站内容 |

**总计：H1 部分通过（1 项能力缺口），H2–H8 实质全部通过。**

---

## 5. Happy Path Evidence

### H2 内容创建 — 五层证据链

```
ACTION           POST /admin/contents（运营人员填完表单点保存）
   ↓
UI FEEDBACK      302 → /admin/contents/{id}/edit（跳转到编辑页，flash「内容已保存」）
   ↓
PERSISTED STATE  contents.status=draft / site_id=1 / title 正确落库
   ↓
DERIVED OUTPUT   （draft 不进 GEO 输出，符合预期）
   ↓
UNDERSTANDING    运营人员看到「已保存 + 跳到编辑页」，知道可以继续编辑或发布
```

### H4 发布流程 — 完整闭环

```
ACTION           POST /admin/contents/{id}/publish
   ↓
UI FEEDBACK      302 + flash「内容已发布」
   ↓
PERSISTED STATE  status=published, published_at 已写入, audit_logs 记录 content.published
   ↓
DERIVED OUTPUT   前台 /knowledge/{slug} → 200
                 canonical = https://a.h7.test/knowledge/h7-human-simulation-article
                 JSON-LD = Organization + Article + BreadcrumbList
   ↓
UNDERSTANDING    运营人员看到「内容已发布」，切到前台能访问到，canonical 与 schema 均正确
```

**门禁生效验证**：证据不足时发布被拒，提示「证据不足：当前 0 条，至少需 2 条（label + value + source）」——
这是**正确的业务约束**，且提示具体，运营人员知道该去补什么。

### H5 人工锁定 — GEOFlow 不得覆盖

```
[运营人员] 精修内容 + 勾选「人工锁定」→ PUT → lock_manual=1
   ↓
[GEOFlow]  upsert 同 external_id → HTTP 409
           {"ok":false,"action":"conflict","code":"conflict",
            "message":"该内容已被人工修改并锁定，未覆盖；请在后台人工合并"}
   ↓
[GEOFlow]  unpublish → HTTP 409（同文案，明确说明「未下架」）
   ↓
[DB 证据]  title 保持人工版本 / content_hash 未变 / status 仍为 published
   ↓
[人类可理解] 运营人员可在「GEO 工具 → 同步日志」看到冲突记录，知道发生了什么
```

**这是 20G-2 修复的 C-6 在人类操作层的复验：从「技术断言」变成「运营人员能感知到的正确行为」。**

---

## 6. Negative Path Evidence

### H7 异常恢复（9/9 全通过）

| # | 操作 | 预期 | 实际 | 判定 |
| --- | --- | --- | --- | --- |
| 1 | `title` 传数组 | 不 500 | 302（校验拒绝） | ✅ |
| 2 | 缺标题 | 不 500 | 302 | ✅ |
| 3 | 校验失败后查库 | 无脏数据 | 0 条 | ✅ |
| 4 | 同一slug 提交两次 | 不重复 | 1 条 | ✅ |
| 5 | 重复提交 | 不 500 | 302 | ✅ |
| 6 | 仅浏览编辑页 | 无写入副作用 | `updated_at` 未变 | ✅ |
| 7 | 访问不存在的 ID | 404 | 404 | ✅ |
| 8 | 发布 → 下架 | 状态正确流转 | published → draft | ✅ |
| 9 | 误下架后恢复 | 可重新发布 | published | ✅ |

> **「下架 = 转草稿」是产品既定语义**（`ContentController::unpublish` 设 `status='draft'`），
> 不是 `archived`。这一区分对运营人员有意义：下架后内容仍可编辑、重新发布。

### 门禁拒绝的正确行为

证据不足时发布被 302 回退 + flash 说明具体缺什么。
**这不是UX缺陷**——GEO 四要素是产品的核心承诺，门禁拒绝是设计意图，且提示足够具体。

---

## 7. Multi-site Human Isolation

两站使用**相同 slug、不同内容**（最隐蔽的泄漏形态）：

| 检查项 | A 站（`a.h7.test`） | B 站（`b.h7.test`） |
| --- | --- | --- |
| 登录 | ✅ 独立 session | ✅ 独立 session |
| 创建内容 | ✅ site_id=1 | ✅ site_id=2 |
| 发布 | ✅ published | ✅ published |
| 前台访问 `/{shared-slug}` | ✅ 看到 A 的内容（**看不到 B 的**） | ✅ 看到 B 的内容 |
| 后台列表 | ✅ **不含** B 站内容 | ✅ 含自己的内容 |
| `geo.json` | ✅ **不含** B 站内容 | ✅ 含自己的内容 |

**人类层的负向验证**：A 站运营人员在列表里**看不到** B 站内容，因此**不会误编辑他站**。
这比技术层的 Scope 检查更贴近真实风险——技术隔离正确但运营人员能看见，照样会误操作。

---

## 8. UX / Interaction Findings

### UX-001 · 站点管理能力缺失（P1 · 产品能力缺口）

**现象**：后台**完全没有站点增删改查入口**。

**证据**：`GET /admin/sites` → HTTP 404；`routes/admin.php` 中无任何 `sites` 路由，
只有 `settings/{group}`（当前站设置）。

**影响**：多站是本产品的核心卖点，但运营人员**只能操作当前这一个站**——
新站如何创建？站点如何改名/换域名？**当前后台无法完成**。

**根因**：`geo:install` 只创建一个站点（`firstOrCreate`），系统无 `SiteController`，
也无站点级初始化钩子。

**建议**：
1. 新增 `SiteController` + 超管可见的站点列表/新建/编辑页；
2. 新建站点时**自动初始化栏目与分组**（否则新站是空站，运营人员无法创建内容——见 UX-002）；
3. 若 v1.0 明确单站定位，则须在 README 与后台显式声明「当前版本不支持多站管理」。

### UX-002 · 新建站点无栏目初始化（P1 · 与 UX-001 同源）

**现象**：为 B 站补建 `categories` / `groups` 后，B 站才能创建内容。
未补建时，`category_id` 无可选值，保存被校验拒绝。

**证据**：B 站初始 `categories=0 / groups=0`；手工补建后创建成功。

**影响**：即使加了站点管理入口，新站仍是「空站」，运营人员会困惑「为什么什么都建不了」。

### UX-003 · 门禁拒绝提示可理解（✅ 正向发现）

「证据不足：当前 0 条，至少需 2 条（label + value + source）」
——明确指出缺什么、需要几条。**这是好的错误提示设计**，值得保留。

### UX-004 · GEOFlow 冲突提示可理解（✅ 正向发现）

「该内容已被人工修改并锁定，未覆盖；请在后台人工合并」
——说明了**发生了什么** + **下一步该做什么**。这是 20G-2 修复带来的 UX 收益。

### UX-005 · `sitemap.xml` 未收录知识文章（INFO · 待产品确认）

已发布的知识文章未出现在 sitemap 中。
`sitemap URL 7 条` 均为固定 IA 页+ Catalog 页。

**这不是我判定的缺陷** —— sitemap 是否应收录知识文章属于产品范围决策。
若应收录则是遗漏；若只收录 IA 则是设计取舍。**建议在 C-9 产品范围决策时一并明确。**

---

## 9. Content / GEO Integrity

| 检查 | 结果 |
| --- | --- |
| `geo.json` 结构 | ✅ 含 `$schema: geo-os/graph/v1` 版本标识 |
| `geo.json` 站点归属 | ✅ A/B 两站内容严格隔离 |
| `llms.txt` | ✅ 200，含站内实质信息（非空壳） |
| `sitemap.xml` | ✅ 200；lastmod 真实性由 20G-4 C-8 保证（已发布内容为今天，合理） |
| `feed.xml` | ✅ 200 |
| 前台 canonical | ✅ 指向本站规范地址 |
| JSON-LD | ✅ Organization + Article + BreadcrumbList |
| 内容指纹 | ✅ 编辑后变化（GEOFlow 幂等依据正确） |

---

## 10. Findings Ledger

| 编号 | 级别 | 发现 | 终态 |
| --- | --- | --- | --- |
| **UX-001** | **P1** | 后台无站点管理入口（`/admin/sites` 404），多站核心卖点无 UI 支撑 | **OPEN**（需产品决策：v1.0 单站 or补建站点管理） |
| **UX-002** | **P1** | 新建站点无栏目/分组初始化，新站为空站无法创建内容 | **OPEN**（与 UX-001 同源） |
| UX-005 | INFO | `sitemap.xml` 未收录知识文章 | **SCOPE DECISION**（并入 C-9 产品范围决策） |

### 未产生 P0 的领域

- **无 unexplained 500**：H7 全部 9 项异常输入均被正确拒绝（302/404），无 500
- **无数据不一致**：H7 校验失败零脏数据；重复提交零重复
- **无跨站泄漏**：H8 全部 7 项通过
- **无 GEO 输出漂移**：派生输出与 SoT 一致

---

## 11. Gate Decision

### 判定标准逐条核对

| 条件 | 结果 |
| --- | --- |
| 核心运营闭环可完整跑通 | ✅ H2→H3→H4→发布→前台→GEO 全链路 |
| 无 P0| ✅ |
| 无 P1 | ❌ **2 项 P1**（UX-001 站点管理缺失、UX-002 新站无栏目） |
| 无 unexplained 500 | ✅ |
| 错误输入可恢复 | ✅ 9/9 |
| 发布/下架状态一致 | ✅ published ↔ draft 双向正确 |
| lock_manual 行为符合预期 | ✅ upsert/unpublish 均 409，人工版本保留 |
| GEO 派生输出与 SoT 一致 | ✅ |
| A/B 双站人工操作不串站 | ✅ 7/7 |
| 87/87 回归继续通过 | ✅ 实测 87 文件全绿 0 失败（见 §12） |
| 新发现全部进入 Ledger | ✅ UX-001 / UX-002 / UX-005 |
| OPEN = 0 | ❌ **OPEN = 2**（UX-001 / UX-002，均为 P1） |

### 结论

> **20G-7 · CONDITIONAL PASS**
>
> 运营闭环、人工锁定、异常恢复、多站人工隔离**全部通过**——
> 这些是产品的**核心能力**，已得到真实操作层面的证明。
>
> 但发现 **2 项 P1 产品能力缺口**，二者同源：**多站虽是核心卖点，后台却无法管理站点，
> 且新站无栏目初始化导致无法运营**。
>
> 这不是 bug，而是**产品范围与 UI 能力不匹配**。需项目方决策：
> - 若v1.0 定位单站 → 关闭 UX-001/002，在 README 与后台显式声明；
> - 若 v1.0 支持多站 → 需补建站点管理 + 新站初始化（属 v1.1 功能增量）。
>
> **Release 继续 HOLD。**

---

## 12. Regression

**87 个测试文件全绿，0 失败**（20G-6 的 C-16 / C-17 迁移修复与 20G-7 环境搭建均未破坏既有测试）。

开发库全程未被修改（contents=3 / entities=12 / sites=2 / migrations=39）。

---

## 13. 我的方法失误（本轮）

| # | 失误 | 修法 |
| --- | --- | --- |
| 13 | **CSRF token 与 session cookie 不同源**：用 `csrf_token()` 直接生成，未先 GET 登录页取配套 token，导致所有写操作 419 | 正确流程：GET 页面 → 拿 cookie + 从 HTML 解析 `_token` → POST 时带同一 cookie |
| 14 | **猜路由**：以为内容详情在 `/knowledge/{slug}`、编辑用 POST、更新用 POST `/contents/{id}` | 真实路由：详情走 catch-all `/{path}`；更新用 **PUT**；证据字段是**动态行** `ev_label[]`/`ev_value.N`；编辑页栏目字段是 **`category_id`** 而非 `category_slug` |
| 15 | **同进程跑 8 个旅程导致 session/状态污染**：H5 在主脚本里失败，隔离进程却全通过 | 拆成独立进程，每个旅程自建状态；旅程间用 reset 脚本归位 |
| 16 | **`fresh()` 误用**：它会把 `SiteContext` 重置为惰性兜底的 default 站，导致后续请求以 A 站身份操作 B 站数据 | 跨站操作前只`SiteContext::clear()`，不调 `fresh()` |

> **这 4 项都不是产品缺陷。** 我严格遵守了 20G-7 纪律：
> `USER ERROR → PRODUCT/UX DEFECT → TECHNICAL DEFECT → TEST DEFECT`，
> 只有最后一种才改测试。本轮**没有出现需要修改产品代码的技术缺陷**，
> 因此**未改动任何产品代码**。

---

## 14. 取证脚本

`D:\734666\GEO-OS-AUDIT\h7\`（5 个，可独立复跑）

```bash
GEO_ROOT="D:/734666/GEO OS" GEO_DB="D:/Temp/h7_human.sqlite" php h7_reset.php        # 状态归位
GEO_ROOT="..." GEO_DB="..." php h7_h1_h4.php       # H1-H4 核心闭环
GEO_ROOT="..." GEO_DB="..." php h7_h5_only.php     # H5 人工锁定
GEO_ROOT="..." GEO_DB="..." php h7_h7_h8_only.php  # H7 异常 + H8 双站
GEO_ROOT="..." GEO_DB="..." php h7_b_full.php      # B 站完整链
```