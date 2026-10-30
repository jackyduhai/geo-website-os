# 20B 前台模拟人测试报告（Frontend User Simulation）

- **执行范围**：仅前台（frontend）zh + en 全部页面对；不含后台 admin CRUD。
- **执行人**：模拟人测试执行员（computer_use_tool / seed_browser_use 驱动真实桌面 Chrome）
- **日期**：2026-09-29
- **方法**：严格按 `docs/audit/standard-user-simulation-sop.md`（v1.0）执行；HTTP 广度与 DB 核对用 PowerShell / PHP 只读脚本。
- **角色边界**：本轮唯一浏览器执行者；全程只记录不修改业务代码（G7）。

---

## 1. 测试指纹（Fingerprint）

| 项 | 值 |
|---|---|
| 仓库 | `D:\GEO-OS-rewrite\geo-website-os` |
| HEAD | `c6f04fd` fix(ui): logo aspect ratio, locale switcher links, responsive nav/feature grid |
| 工作树 | **非 clean**：①`resources/views/layouts/site.blade.php` + `lang/zh-CN/ui.php` 有未提交主题切换修复（执行前已存在，非本轮产生，未改动）；②并行只读代码审查 agent 新增了 `20B-bug-diagnosis-hunt.md`、`20B-codebase-architecture-review.md`、`scripts/audit/`、`M app/Http/Controllers/Admin/SettingController.php`（非本轮产物，未触碰） |
| PHP | `C:\php84\php.exe` 8.4.25 (NTS VS2022) |
| 预览入口 | `http://127.0.0.1:8000`（未另起 serve，复用在跑实例） |
| 浏览器 | Chrome/147.0.0.0（GAC Browser Use 受控实例） |
| 视口（5 档） | 375×812 / 390×844 / 768×1024 / 1280×900 / 1440×900（CDP `Emulation.setDeviceMetricsOverride`） |
| 缓存 | 执行前 `view:clear` + `page-cache:clear`（已清空 1 站点整页缓存） |
| 数据基线 | contents=7, entities=18, pages=20, media=0, entity_relations=58, inquiries=0, users=1, forms=1(slug=contact), form_submissions=0 |

> 说明：agent-hint 基线写 contents=6，实测 contents=7（多 1 条 type=page 的 `_slot_about-profile`）；属基线描述与实际微差，已按实际库测试。

---

## 2. 覆盖率（三口径）

| 口径 | 分母 | 已覆盖 | 覆盖率 | 说明 |
|---|---|---|---|---|
| **路由覆盖率** | 前台 GET 路由（zh+en 成对，约 47 条 + 派生 6 条） | 全量 GET 面逐个请求 | **≈100%** | 列表/详情/派生端点全部取到状态码；写接口 POST inquiry、POST forms/{form}/submit 真实打过 |
| **控件覆盖率** | 页眉导航(含全部下拉项)、语言切换器、主题切换、汉堡菜单、搜索框、contact 表单 4 字段+提交、CTA、面包屑、分页/Tab | 真实操作并验证最终 URL+内容 | **≈90%** | 未深测：主题切换亮/暗视觉、知识中心 Tab/折叠内部展开态、搜索分页 |
| **页面类型覆盖率** | SOP 列约 18 类 | 实测 16 类 | **≈89%** | 已测：首页/产品列表/产品系列/产品详情/方案列表/方案详情/知识列表/知识栏目/文章详情/关于 profile·history·culture/工厂/合作/联系/搜索/404/空列表(cases)。未覆盖：实体详情独立页(无独立 entity 路由)、媒体画廊(media=0) |

---

## 3. 用例矩阵（SIM-F-xxx）

> 四面断言口径：UI（浏览器真实结果）/ HTTP（状态码）/ DB（持久化）/ 派生（sitemap·geo·JSON-LD·feed）。

| ID | 模块 | 入口/操作 | 四面断言要点 | 视口/语言 | 状态 | 证据 |
|---|---|---|---|---|---|---|
| SIM-F-001 | 路由 | GET `/` | 200, title=示例制造有限公司, lang=zh-CN, JSON-LD 3 块 | 全 | PASS | 1280 探针 |
| SIM-F-002 | 路由 | GET `/products/` 与系列 `/coatings/` `/adhesives/` `/additives/` | 均 200 | 全 | PASS | HTTP 扫描 |
| SIM-F-003 | 路由 | GET core 产品详情 epoxy/polyurethane/structural-adhesive/leveling-agent | 200 | 全 | PASS | HTTP+浏览器 |
| SIM-F-004 | 路由 | GET 非 core 产品详情 heat-resistant/silicone-sealant/thickener/curing-agent | 404（**设计行为**：仅 core 有详情页，Controller `abort(404)`） | - | PASS(符合预期) | ProductController:148 |
| SIM-F-005 | 路由 | GET `/solutions/` + 3 场景详情 | 200 | 全 | PASS | HTTP |
| SIM-F-006 | 路由 | GET `/cases/` | 404（**设计行为**：无 case_study 实体不输出空壳，CaseController:35） | - | PASS(符合预期) | CaseController:35 |
| SIM-F-007 | 路由 | GET `/scenarios`、`/scenarios/{slug}` | 301 → `/solutions/`、`/solutions/{slug}/`（旧入口兼容） | - | PASS | 重定向链终态 200 |
| SIM-F-008 | 路由 | GET `/knowledge/` + 3 栏目(selection/process/business) + 3 文章 | 200 | 全 | PASS | HTTP |
| SIM-F-009 | 路由 | GET `/about/profile|history|culture/` | 200 | 全 | PASS | HTTP |
| SIM-F-010 | 路由 | GET `/factory/` `/cooperation/` `/contact/` | 200 | 全 | PASS | HTTP |
| SIM-F-011 | 路由 | GET `/news/` `/en/news/` | 200（catch-all 分发，sitemap 在列） | - | PASS | HTTP |
| SIM-F-012 | 路由 | GET 不存在页 `/this-page-does-not-exist-xyz/` | 自定义 404「这个页面找不到了」，HTTP 404 | 全 | PASS | 浏览器截图 |
| SIM-F-013 | 路由 | en 全部成对路由 `/en` `/en/products/`…`/en/nonexistent` | 200/404 与 zh 对称，lang=en | 全 | PASS | 11 页探针 |
| SIM-F-014 | 派生 | GET `/sitemap.xml` | 27 个 loc，全部为真实可达 URL；含 core 产品/文章/系列 | - | PASS | loc 列表比对 |
| SIM-F-015 | 派生 | GET `/en/sitemap.xml` | 23 个 loc，按 en 已发布实体投影（少 en 未发布产品），一致 | - | PASS | 与 DB en 实体一致 |
| SIM-F-016 | 派生 | GET `/robots.txt` | 200；Disallow admin/api/search；显式放行 AI 爬虫；含两条 sitemap | - | PASS | 全文 |
| SIM-F-017 | 派生 | GET `/geo.json` | geo-os/graph/v1；facts=17, entities=12, relations=**58(与 DB 完全一致)**, contents=3 | - | PASS | 计数比对 |
| SIM-F-018 | 派生 | GET `/en/geo.json` | entities=6, relations=21, contents=3（按 en 投影） | - | PASS | 计数 |
| SIM-F-019 | 派生 | GET `/llms.txt` `/feed.xml` | llms 核心事实完整；feed 为合法 RSS 含 3 文章 item | - | PASS | 内容头 |
| SIM-F-020 | 派生 | 页面 JSON-LD | 首页 Organization+WebSite+FAQPage；产品详情 Organization+BreadcrumbList+ItemPage+**Product**+HowTo+FAQPage | - | PASS | 浏览器 |
| SIM-F-021 | 派生 | OG/Twitter/canonical | og:title/type/image/url、twitter:card=summary_large_image、canonical 自指均正确；en 全英文 | - | PASS | meta 探针 |
| SIM-F-022 | G1 图片 | 首页 LOGO ratio | logo.png nat320×96→render133×40 d=0；logo-icon 见 SIM-F-030 | 1280/1440 | PASS | ratioDelta=0 |
| SIM-F-023 | G4 溢出 | 13 个 zh 关键页 × 5 视口 | `scrollWidth-clientWidth=0` 全部成立 | 375/390/768/1280/1440 | PASS | 逐页探针 |
| SIM-F-024 | G4 溢出 | 11 个 en 关键页 × 5 视口 | 溢出全 0（除 SIM-F-032） | 5 档 | PASS/1 FAIL | 见 BUG-003 |
| SIM-F-025 | G4 导航 | 375px 点击汉堡 `label.nav-toggle` | nav display:none→block，21 个导航项全部出现可点，溢出保持 0 | 375 | PASS | 点击后探针 |
| SIM-F-026 | G2 语言 | zh 首页 → 点 EN | URL `/en/`→`/en`，lang=en，title/导航/meta 全英文，唯一 CJK 为切换按钮「中」 | 1280 | PASS | 往返探针 |
| SIM-F-027 | G2 语言 | en 首页 → 点「中」 | 回到 `/`，lang=zh-CN，导航全中文 | 1280 | PASS | 探针 |
| SIM-F-028 | G2 语言 | 产品详情 zh↔en | `/products/epoxy-primer-100` ↔ `/en/products/epoxy-primer-100` 同 slug 映射 | 1280 | PASS | 探针 |
| SIM-F-029 | G2 语言 | en 页刷新 / 直入 `/en/about/profile/` | 刷新仍 lang=en；直入 URL 语言保持，无裸露翻译键 | 1280 | PASS | 探针 |
| SIM-F-030 | G1 图片 | 全部页 logo-icon 窄屏 | nat69×69 正方形，375/390 被压成 **34×40，ratioDelta=0.15>0.02** | 375/390 | **FAIL** | BUG-20B-F-001 |
| SIM-F-031 | 交互 | 页脚/hero CTA 真实链接 | `产品中心→` `联系我们→` `获取方案→` `应用场景→` href=`http://localhost/...`（无端口），连接被拒 | - | **FAIL** | BUG-20B-F-002 |
| SIM-F-032 | G4 溢出 | `/en/search?q=coating` @375 | 横向溢出 **5px** | 375 | **FAIL** | BUG-20B-F-003 |
| SIM-F-033 | 搜索 | q=结构胶(中文有结果) | 「找到 1 条」→ 双组份结构胶 SA-A10，可点 | - | PASS | 主区文本 |
| SIM-F-034 | 搜索 | q=zzzznothing / 空 | 「没有找到与…相关」空态 | - | PASS | 文本 |
| SIM-F-035 | 搜索 | q=`<script>alert(1)</script>` | 按文本转义展示在「」内，未执行；URL 编码 | - | PASS | body 无脚本执行 |
| SIM-F-036 | G5 表单 | contact 成功路径 UI 提交 | UI「已收到，我们会尽快联系你」；HTTP 302；DB form_submissions=1 + inquiries=1(payload 正确, locale=zh-CN) | - | PASS | DB 行 |
| SIM-F-037 | G5 表单 | 空提交 | HTTP 422，errors.name+phone「必填」，**不落库** | - | PASS | fetch 响应 |
| SIM-F-038 | G5 表单 | 非法邮箱 `not-an-email` | HTTP 422「邮箱必须是有效地址」，不落库 | - | PASS | fetch |
| SIM-F-039 | G5 表单 | name>255 字符 | HTTP 422「称呼不能多于 255 个字符」，不落库 | - | PASS | fetch |
| SIM-F-040 | G5 表单 | honeypot 字段 `website` 填值 | 假成功 200 success:true，**不落库**（反垃圾正确） | - | PASS | DB 仍 1 行 |
| SIM-F-041 | G5 表单 | XSS 字段单独提交 | 200 接受入库（payload 存 JSON，前台不回显；转义在后台渲染层） | - | PASS | 隔离重测 |
| SIM-F-042 | 清理 | 测试后清理 | 删除全部测试 inquiries + form_submissions | - | PASS | 见 §6 |

---

## 4. 问题清单（BUG-20B-F-xxx）

| 编号 | 分级 | 现象 | 证据 | 复现 | 扩散面 |
|---|---|---|---|---|---|
| **BUG-20B-F-001** | **P2** | 移动端（≤390px）页眉方形图标 `logo-icon.png` 被横向压扁：自然 69×69（ratio 1.0），渲染 34×40（ratio 0.85），ratioDelta=0.15 远超 0.02 容差；768px 起恢复 40×40 正方形 | JS：`{nat:"69x69", r:"34x40", d:0.15}`；截图 D:\Temp\gac-runtime\shot.png | 任意页 375/390px 视口读 logo-icon `getBoundingClientRect` | **全站性**：zh+en 全部 24 个关键页在 375/390 一致复现（G6 已横向确认） |
| **BUG-20B-F-002** | **P2** | 页脚/首屏 CTA 绝对链接指向 `http://localhost/...`（无端口），与请求 host `127.0.0.1:8000` 不一致；`Invoke-WebRequest http://localhost/products/` 报「无法连接到远程服务器」死链 | DOM：4 个 CTA href=`http://localhost/products/`、`/contact/`、`/solutions/`；PowerShell 连接被拒 | 首页读 footer CTA href；或直接请求 `http://localhost/products/` | 首屏 hero「产品中心→」「联系我们→」+ 页脚「获取方案→」「应用场景→」共 4 处；**根因偏环境/配置**：这些 CTA 用 `url()` 绝对地址（取 APP_URL），而页眉导航用请求相对 host；当前 APP_URL=`http://localhost` 与预览端口不符。生产环境 APP_URL 正确时不复现，但代码层 host 来源不统一仍应登记 |
| **BUG-20B-F-003** | **P3** | `/en/search?q=coating` 在 375px 出现 5px 横向溢出（其余页/视口均为 0） | JS：`{w:375, overflow:5}` | 375px 打开英文搜索结果页 | 仅 en 搜索结果窄屏 1 处（5px，轻微） |

### 非缺陷澄清（防误报）
- **`/cases` 404 / 非 core 产品详情 404 / `scenarios` 301**：均为代码注释明示的设计行为（空列表不输出空壳、仅 core 产品有详情页、旧入口 301 兼容），非 bug。
- **测试中途一次「XSS→500」**：系本轮并发生成 5 个 fetch 触发 SQLite `database is locked`（日志 PDOException HY000:5），**非 XSS 漏洞**；隔离单测重放 XSS 返回 200、超长返回 422。已排除，不登记为安全问题。

---

## 5. 通过率与发布结论

- **分母**：PASS+FAIL+BLOCKED = SIM-F-001…041（不含 SIM-F-042 清理项）。
- PASS = 38，FAIL = 3，BLOCKED = 0，NOT-COVERED 见 §2（控件约 10%、页面类型约 2 类，已注明原因）。
- **通过率 = 38/(38+3) ≈ 92.7%**（NOT-COVERED 不计入分母）。
- **Critical = 0，Major = 0**。
- 3 个问题分级：P2×2（图标变形、CTA 死链）、P3×1（en 搜索窄屏 5px 溢出）。

**结论：不具备直接放行条件（按 SOP §14 严格口径）**——虽 Critical/Major=0，但存在 2 个 P2 前台缺陷（移动 LOGO 变形为 FBS 曾漏检同类、CTA 死链影响可点性），建议：
1. BUG-001：修窄屏 logo-icon 宽高（`width=height` 或 `aspect-ratio:1`），回归全部页 375/390。
2. BUG-002：确认生产 APP_URL；代码层统一 CTA 使用相对/请求 host 地址。
3. BUG-003：en 搜索结果窄屏消去 5px 溢出。
最终放行由发布裁定人人工决定。

---

## 6. 污染扫描与基线复原

- 写操作仅 contact 表单：成功路径 + 隔离 XSS 各产生 1 条 form_submissions + inquiries。
- 已执行清理：`DELETE` 全部 inquiries 与 form_submissions。
- **最终核对：inquiries=0，form_submissions=0（恢复基线）**。
- 临时脚本（`_audit_*.php` / `_audit_*.ps1` / `_v.php`）已全部删除。
- 浏览器视口 override 已复位；无残留卡死 php 进程（复用在跑 serve，未起新服务）。
- 工作树：本轮**未新增/修改任何业务代码**；现存 diff 为并行 agent 与执行前既有改动，非本轮产物。

## 7. 未覆盖项（NOT-COVERED 显式列示）
- 主题切换亮/暗模式视觉对比、知识中心 Tab/折叠内部展开态、搜索分页边界。
- 媒体画廊（media=0，无 OG 产品图可测，仅静态 og-default.png 已 200）。
- Blank 空站点（0 数据）临时 DB 副本验证：本轮未搭建临时副本；按要求列为**未验证**（主库未动）。
- 多浏览器（Edge/Firefox）：本轮仅 Chrome 主测（SOP §8.4 为发布前建议项）。
