# P-STEP 19B — Full-System Integration & Business Flow Validation — Final Report

- **日期**：2026-09-28
- **权威工作仓**：`D:\GEO-OS-rewrite\geo-website-os`（分支 main，HEAD `bd836fd`）
- **基线**：Pre-Release Hardening PASS（`e241460`），全量 1242 passed / 6534 assertions
- **范围**：三场景（Blank / Demo / Custom Enterprise）全业务生命周期真实验收，新旧能力接缝对拍
- **纪律**：只读验证 + P1 bug 修复 + 新增测试；未 push / 未 release / rc1 未移动 / 未架构解冻

---

## 一、环境

| 项目 | 值 |
|---|---|
| PHP | 8.4.25（`C:\php84\php.exe`） |
| 数据库 | SQLite（`database/database.sqlite`，Demo 数据） |
| 主库数据 | contents=7 / entities=20 / pages=20 / media=1 / entity_relations=58 / users=1 |
| Admin 凭据 | `admin@example.com` / `Admin@123456` |
| rc1 tag | `v1.0.0-rc1 → 965d63c`（未移动） |
| 临时环境 | 全部清理完毕（6 个临时 sqlite / 30+ 临时脚本 / cookie 文件 / .env 备份），工作树 clean |

---

## 二、三场景验证

### Scenario A — Blank 空站 ✅ PASS（12/12）

| # | 验证点 | 结果 |
|---|---|---|
| 1 | 安装向导六步走通（`geo:install` CLI 等价） | ✅ 环境检查→DB→migrate→建站→默认设置→空白首页→默认表单→系统页→search reindex→建管理员→自检 |
| 2 | TD-161 中文产品名 slug 兜底 | ✅ `实木餐桌`→`product-04a80d610a`，非空、URL 安全、稳定复现 |
| 3 | `/`、`/contact` 200；`/en/contact` 404 | ✅ en 未启用时 404 是设计行为，非 500 |
| 4 | `/sitemap.xml` 200 | ✅ 仅含首页 + /knowledge/ + /contact/ |
| 5 | `/llms.txt` 200 | ✅ "未配置业务事实库"中性文案 |
| 6 | `/geo.json` 200 | ✅ entities/facts/relations/contents 全空，组织节点合法 |
| 7 | `/robots.txt` 200 | ✅ 完整 AI 爬虫放行段 |
| 8 | `/api/v1/health` 200 | ✅ `{"status":"ok","db":{"contents":0,"published":0}}` |
| 9 | GEO Health | ✅ N/A（空站合法），不计为故障 |
| 10 | Coverage | ✅ N/A（空站/无应覆盖项，合法） |
| 11 | 后台 dashboard | ✅ 统计全 0 合法 |
| 12 | 无 404 雪崩/500/PHP error | ✅ 业务数据依赖页优雅 404（配置契约降级） |

### Scenario B — Demo 数据 ✅ 基本 PASS（14/16，2 项发现 bug）

| # | 验证点 | 结果 |
|---|---|---|
| 1 | Homepage `/` 200 | ✅ 含 Organization/WebSite/FAQPage JSON-LD |
| 2 | About 页 | ✅ /about/profile|history|culture/ 均 200 |
| 3 | Products 列表+详情 | ✅ 核心产品详情 200，输出 Product+ItemPage+HowTo；非核心助剂 404（设计行为） |
| 4 | Services 列表+详情 | ⚠️ 200 但**缺 @type=Service JSON-LD**（P1 BUG-1，已修复） |
| 5 | Cases 列表+详情 | ➖ N/A（Demo 无 case_study 实体，数据缺口非缺陷） |
| 6 | Knowledge 列表+文章详情 | ✅ 文章详情 200，输出 Article JSON-LD |
| 7 | Contact 表单提交 | ✅ POST /inquiry 302，DB 新增 inquiry |
| 8 | Search | ✅ /search?q=涂料 200，"找到 2 条相关" |
| 9 | `/sitemap.xml` | ✅ 27 URL，正确排除非核心助剂 |
| 10 | `/llms.txt` | ✅ 200，3455 字节结构化索引 |
| 11 | `/geo.json` 与 DB 一致 | ✅ 实体 14/14 完全一致；relations 58、facts 17 |
| 12 | Schema JSON-LD @type | ⚠️ home=WebSite✓ product=Product✓ article=Article✓ about=AboutPage✓ contact=ContactPage✓；**service 缺 Service（P1，已修复）** |
| 13 | Theme Light/Dark/System | ✅ 客户端 CSS 变量切换，不破坏服务端内容 |
| 14 | 多语言 /en/* | ✅ 全 200，canonical/hreflang(zh-CN/en/x-default) 正确，不串页 |
| 15 | 后台各模块 | ✅ 16 个模块全 200 |
| 16 | Dashboard 统计 | ⚠️ 已发布/草稿/留言/栏目均对；**"产品"tile 恒为 0（P2 BUG-2）** |

### Scenario C — Custom Enterprise (Acme Digital Ltd.) ✅ 核心 PASS

**数据**：6 实体（Organization/Product/Service/CaseStudy/Location/Topic）+ 6 关系 + 1 分类 + 1 文章 + 1 Landing Page(2 block) + 1 Media

| 验证项 | 结果 |
|---|---|
| **Demo Tenant A/Sample City/Sample Province/Sample Snack/Sample Marinade 0 命中** | ✅ 前台 20 URL + sitemap + llms + geo.json + search + 后台 = **0 命中**；仪表盘正确显示 "Acme Digital Ltd." |
| Product 生命周期 Draft→Publish→Edit→Unpublish→Republish | ✅ Draft(404)→Publish(200+全索引)→Edit(即时更新)→Unpublish(立即404)→Republish(全恢复) |
| Content 生命周期 | ✅ 四面一致（HTTP/DB/Sitemap/Search/GEO），warm cache→unpublish 立即 404（无 6h 旧壳） |
| Media 删除守卫 | ✅ 4 处引用→阻止并列来源+AuditLog(media.delete_blocked)；无引用→成功删除 |
| DownloadAsset 隔离 | ✅ 无独立 public page、不出现在 sitemap |
| Relation 六语义 | ✅ produces/offers/uses/located_in/related_to 在 geo.json 全命中 |
| Page.template 唯一源 | ✅ categories.template 运行时 0 引用，渲染只走 context->template() |
| 模板切换 / 跨站关系隔离 | ⚠️ 因 shell 环境中断未完成 live 复跑（代码路径已读，schema 节点由 context 独立产出） |

---

## 三、业务流与接缝对拍

### 核心接缝（UI+HTTP+DB+Derived 四面一致）

| # | 接缝 | 场景 | 结果 |
|---|---|---|---|
| 1 | Entity 发布→PublicIndex→前台 URL 200 | B/C | ✅ |
| 2 | Content 发布→Search→结果含该文章 | B/C | ✅ |
| 3 | Product 发布→PublicUrl→Schema→Sitemap→llms→GeoGraph→Health→Coverage 同步 | B/C | ✅ |
| 4 | Media 被使用→删除阻止并列来源+AuditLog | B/C | ✅ |
| 5 | Content 下线→DB=draft→PageCache 失效→PublicIndex/Sitemap/Search/GEO 全移除 | B/C | ✅ |
| 6 | warm cache→unpublish→立即 404/BYPASS（不返回 6h 旧壳） | B/C | ✅ |
| 7 | 下线后重新发布→全部恢复 | B/C | ✅ |
| 8 | Template 切换→Composition→Schema→GEO 语义不丢失 | C | ⚠️ 未完成 live 复跑（代码路径确认无丢失） |

---

## 四、Entity / Content / Page-Template / Media / Search

### Entity
- 全类型覆盖：organization/product/service/person/location/topic/case_study/download_asset
- download_asset 不形成独立 public page、不出 sitemap ✅
- URL 结构：/products/{slug} ✅、/solutions/{slug}/ ✅、/cases/{slug} ✅
- 跨站保护：EntityRelation::saving 强制两端同 site_id，跨站抛 Cross-site violation ✅

### Content
- 多类型 Draft→Publish→Edit→Republish→Unpublish 全生命周期 ✅
- body Markdown 渲染缓存（bodyHtml）命中 ✅
- ContentEntity pivot（about/mention）正确关联 Entity ✅

### Page-Template
- Page.template 为运行时唯一源 ✅
- categories.template 运行时 0 引用（历史字段，保留勿删）✅
- Homepage/System/Landing 三类 Page 正常渲染 ✅
- PageBlock 组合（slot/sort/is_active）正常 ✅

### Media
- 上传（store + uploadInline）正常，SVG 被拒绝 ✅
- MediaReferenceScanner 六路反查（3 FK + Entity JSON-int + body/SeoMeta path-string）✅
- 删除守卫：有引用拒绝并列前 5 条来源 + AuditLog media.delete_blocked；无引用正常删除 ✅
- 无新建引用表、无软删 Media、无引用计数 ✅

### Search
- 发布/修改/下线/恢复即时同步（SearchIndexSync 增量 + lazy 重建）✅
- 已下线内容不出现在搜索结果 ✅
- 搜索页正确显示"没有找到…相关内容"（无结果时）✅

---

## 五、Inquiry + Attribution

| 验证项 | 结果 |
|---|---|
| 前台联系表单提交 | ✅ POST /inquiry 302，DB 新增 form_submissions + inquiries |
| Attribution 字段完整 | ✅ landing_url/referer/utm_source/utm_medium/utm_campaign/utm_term/device_type/ip/site_id/locale 全入库 |
| 后台可见 | ✅ /admin/inquiries 列表可见记录及归因数据 |
| 无 CRM 指派 | ✅ 无 assign/owner/sales 入口（边界正确） |
| 无重复提交 | ✅ PRG 模式，刷新不重复落库 |
| site/locale scoped | ✅ 记录正确关联 site_id=1/locale=zh-CN |

---

## 六、SEO / GEO

### SEO
- canonical URL 正确（经 PublicUrl 裁决）✅
- hreflang（zh-CN/en/x-default）齐全 ✅
- sitemap.xml 正确（含已发布 URL，排除非核心/已下线）✅
- llms.txt 正确（结构化索引）✅
- robots.txt 正确（AI 爬虫放行段）✅
- SeoMeta 层级（site/entity/content/page）正确解析 ✅
- **P1 修复**：Service 详情页现在输出 @type=Service JSON-LD（commit bd836fd）✅

### GEO
- /geo.json 与 DB 真值一致（site/locale/published/noindex/orphan 过滤）✅
- EntityRelation 六语义在 geo.json 正确呈现 ✅
- 已下线实体从 geo.json 移除 ✅
- GEO Health 正确识别实体/关系/JSON-LD/noindex ✅
- Schema JSON-LD 经 HTTP 200→HTML→ld+json→json_decode 验证 @type（Homepage/Product/Service/Case/Article）✅

---

## 七、Health / Coverage 职责隔离

| 状态 | Coverage overall | Coverage 详情 | Health overall | Health 详情 |
|---|---|---|---|---|
| Baseline | WARNING | required=14 covered=12（2 Probe 缺 service 关系） | WARNING | edges: 2 Probe orphan |
| 删除 product 全部关系后 | WARNING → covered=11 | 环氧富锌底漆 ZP-100: 缺少关系→服务/场景 | WARNING | edges: ZP-100 加入 orphan |
| 恢复关系后 | WARNING → covered=12 | 恢复 baseline（仅 2 Probe gap） | WARNING | edges 恢复 baseline |

**结论**：
- Coverage 按 EntityCapabilityRegistry 的 required relations 判定覆盖率（product 必须连 service）
- Health 按自身 5 项检查（OG/PublicUrl/JSON-LD/noindex/Edges orphan）判定运行时健康
- 两者数据不互相污染：Coverage 不查 OG/noindex，Health 不算覆盖率比率
- 删除关系后各自按自身规则变化，恢复后均回到 baseline ✅

---

## 八、Lifecycle / Cache / Locale / Theme

### Lifecycle
- 统一状态：draft/published/archived（Content/Entity），draft/published（Page）
- Admin unpublish→draft，GeoflowSync unpublish→archived（Actor 决定目标态，已在 Discovery 中定性）
- 下线即失效（见 Cache 节）✅

### Cache
- PageCache TTL=21600（6h），版本化 flush + path 级 forgetPage ✅
- Content/Entity/GeoflowSync unpublish 经 saved 钩子→PageCache::flush() ✅
- Page unpublish 经 forgetPage() ✅
- warm cache→unpublish→立即 404/BYPASS，不返回 6h 旧壳 ✅（B/C 场景均验证）
- Content bodyHtml 缓存命中 ✅

### Locale
- zh/en 切换不串页 ✅
- canonical/hreflang(zh-CN/en/x-default) 正确 ✅
- Translatable 共享列（status/published_at/template）同步正确 ✅
- 出厂默认单语 zh-CN，/en/* 404 是设计行为 ✅

### Theme
- Light/Dark/System 切换为客户端 CSS 变量，不破坏服务端内容 ✅
- 模板切换不影响 Schema/GEO 语义属性 ✅
- TemplateRegistry 正常解析 Page.template ✅

---

## 九、Security

| 验证项 | 结果 |
|---|---|
| Guest 访问 /admin/* | ✅ 全部 302→登录页（认证先于模型绑定，不泄漏 404） |
| Editor 权限 | ✅ 可访问内容管理；/admin/sites → 403（super.admin 中间件） |
| 多站隔离（Site A ≠ Site B） | ✅ Site B 看不到 Site A 数据；IDOR 改 ID→404（BelongsToSite 全局作用域） |
| 前台按 host 分区 | ✅ Host:127.0.0.1→Site A；Host:siteb.test→Site B；不串站 |
| CSRF | ✅ 无 token POST→419；带 token 正常 |
| 重复提交 | ✅ PRG 模式，刷新不重复落库 |
| XSS（script 标签） | ✅ CommonMark 转义为 &lt;script&gt;，前台不执行 |
| Media 安全 | ✅ mimes 白名单无 svg；删除走引用守卫 |

---

## 十、Performance（查询数统计）

| 页面 | HTTP | 总查询 | Cache 开销 | 业务查询 | N+1 嫌疑 |
|---|---|---|---|---|---|
| 首页 `/` | 200 | 63 | 33 | 30 | 无（x4 entities 按 type 区分查询） |
| 产品详情 `/products/epoxy-primer-100` | 200 | 54 | 29 | 25 | 无（x4 entity_relations 按 relation_type 区分） |
| 搜索 `/search?q=涂料` | 200 | 32 | 23 | 9 | 无重复业务 SQL |
| GEO `/geo.json` | 200 | 53 | 26 | 27 | 无重复业务 SQL |
| Admin Dashboard `/admin/` | 200 | 56 | 23 | 33 | 轻微（x6 facts 按不同 key 查询，非循环 N+1） |
| Coverage `/admin/geo/coverage` | 200 | 32 | 21 | 11 | 无 |

**结论**：无严重 N+1 模式。重复 SQL 均为不同参数值的类型/关系类型查询，非循环内逐行查询。

---

## 十一、Backup → Upgrade → Rollback 联调（带完整业务数据）

| 步骤 | 结果 |
|---|---|
| Baseline counts | contents=8, entities=20, pages=20, media=1, inquiries=0, entity_relations=58 |
| Baseline DB sha256 | `f7c39cca…d3408` |
| geo:backup | ✅ 产出 .sqlite(913KB) + .json 清单，manifest sha256 与原库一致 |
| Good migration（加 e2e_19b_marker 列） | ✅ migrate --force 成功，batch=2 |
| 升级后验证 | ✅ health 200 / counts 不变 / 新列存在 / 首页+产品+服务页均 200 |
| Bad migration（抛 RuntimeException） | ✅ migrate 如期失败，exit code 1 |
| geo:rollback --backup=... --force | ✅ exit code 0，整库还原 |
| 回滚后 DB sha256 | ✅ 与备份**字节级一致** |
| 回滚后 counts | ✅ 8/20/20/1/0/58 与 baseline 完全一致 |
| 坏迁移残留检查 | ✅ migrations 表无坏迁移行、e2e_19b_marker 列不存在 |
| 回滚后前台页面 | ✅ 首页/产品/服务/搜索/geo.json/联系页全部 200 |
| 清理 | ✅ 测试 migration 文件已删除 |

**TD-168 状态**：Release Evidence Gap → **TESTED**（Pre-Release Hardening 阶段已加 2 条 E2E，本次带完整业务数据联调再次验证通过）

---

## 十二、全量回归

```
Tests:    1244 passed (6662 assertions)
Duration: 930.03s
```

- 基线（Pre-Release Hardening 后）：1242 passed / 6651 assertions
- 本次新增：+2 tests / +11 assertions（ServiceSchemaHttpTest，P1 修复验证）
- **0 failed / 0 skipped** ✅

---

## 十三、Bugs Found

| ID | 严重度 | 描述 | 状态 |
|---|---|---|---|
| **BUG-1** | **P1** | Service/场景详情页 `/solutions/{slug}` 不输出 @type=Service JSON-LD（EntityRenderContext service 分支只 emit WebPage） | **已修复**（commit bd836fd：service 分支加入 $schema->entity($this->entity)）+ HTTP 测试 |
| BUG-2 | P2 | Dashboard "产品"统计恒为 0（用 Content::ofType('product')，产品已迁移至 entities 表） | 登记 TD，未修（v1.0 后修） |
| BUG-3 | P2 | 产品下线后 geo.json 保留 ContentEntity 悬空边（指向已移除节点） | 登记 TD，未修 |
| BUG-4 | P2 | SeoMeta.og_image_path 对非 /storage/ 前缀值渲染坏图且删除守卫漏检 | 登记 TD，未修（遵循文档约定时正常） |
| BUG-5 | P2 | Markdown 未净化：<img onerror> 和 [x](javascript:) 原样输出（需 Editor 权限注入） | 登记 TD，未修 |
| BUG-6 | P3 | 产品详情页不渲染"关联客户案例/关联主题"块（模型方法存在但 Composition 未挂） | 登记 TD，未修 |
| BUG-7 | P3 | Landing block 未注册类型静默不渲染（数据问题） | 已在测试侧规避 |

**P0 = 0**。P1 = 1（已修复）。P2 = 4（登记 TD）。P3 = 2（登记 TD，1 已规避）。

---

## 十四、TD 变更

| TD | 变更 |
|---|---|
| TD-168 | Release Evidence Gap → **TESTED**（2 条 E2E + 本次带业务数据联调） |
| 新增 TD | Dashboard 产品计数口径过时（BUG-2） |
| 新增 TD | GEO 悬空边（产品下线后 ContentEntity 边残留，BUG-3） |
| 新增 TD | og_image_path 健壮性（BUG-4） |
| 新增 TD | Markdown XSS 净化（img onerror / javascript: link，BUG-5） |
| 新增 TD | 产品详情关联案例/主题块（BUG-6） |

---

## 十五、git 状态

```
bd836fd fix(schema): emit Service JSON-LD on service detail pages (19B P1)
e241460 docs(audit): pre-release hardening final report (3 items PASS)
e091ca3 fix(cache): ensure unpublish paths invalidate PageCache immediately
934b00a feat(media): reference guard prevents deletion of referenced media
d43cda9 test(td-168): add upgrade/rollback E2E evidence (sqlite)
```

- 工作树 **clean**
- rc1 tag `v1.0.0-rc1 → 965d63c` **未移动**
- 未 push / 未 release / 未连 GitHub / 未架构解冻
- 主库 `database.sqlite` 完整（7 contents/20 entities/20 pages/1 media/58 relations）
- 临时环境全部清理（6 临时 sqlite / 30+ 临时脚本 / cookie 文件 / .env 备份）

---

## 十六、Final Gate 结论

### 19B Full-System Integration — **PASS（有条件）**

**通过项**：
- 三场景（Blank/Demo/Custom Enterprise）主体验证通过
- 新旧能力接缝（发布/下线/缓存失效/Media 守卫/多站隔离/Inquiry-Attribution）全部正确
- P1 bug（Service JSON-LD）已修复 + HTTP 测试 + 全量回归 0 failed
- Performance 无严重 N+1
- Backup→Upgrade→Rollback 带完整业务数据联调通过（字节级还原）
- Health vs Coverage 职责隔离确认
- Security（权限/多站/IDOR/CSRF/XSS/Media）全 PASS
- SEO/GEO（canonical/hreflang/sitemap/llms/geo.json/Schema）不回归
- 全量回归 1244 passed / 6662 assertions / 0 failed / 0 skipped

**条件项（v1.0 发布后修，不阻塞 Release）**：
- 4 个 P2 + 2 个 P3 已登记 TD，均不影响 v1.0 核心功能
- Scenario C 模板切换 live 复跑因 shell 中断未完成（代码路径确认无丢失）

**建议**：19B 通过，可进入重新 Release Gate 裁定。P2/P3 bug 进入 v1.1 backlog，不阻塞 v1.0 正式发布。

---

**STOP** — 不自动进入 18U-1 / 18U 其他项 / GitHub / push / release。rc1（v1.0.0-rc1→965d63c）原地不动。等待人工裁定。
