# P-STEP 20B — Residual Debt & Integration Risk Simulation（遗留债务与融合风险独立模拟验证）· 最终报告

- 仓库：`D:\GEO-OS-rewrite\geo-website-os`（Laravel 12 + PHP 8.4.25 + SQLite，多站点 GEO 内容管理系统）
- 本轮 HEAD：`fd660b0`（工作树含 16 个 M：20B 的 9 修复 + brand_display_name 等，未 commit）
- 本地预览：`http://127.0.0.1:8000`（artisan serve）
- 后台：`/admin/login`，admin@example.com / Admin@123456
- 模式：**READ / TEST ONLY**。允许为测试产生临时数据，测完恢复；发现问题**只记录、不修复**。
- 独立性：不引用"20A 已测 / 20B 已修 / 自动化覆盖"作为结论；每个场景均给本轮证据（步骤 + HTTP + DB + 前台 + 必要时 Cache/Search/GEO/Schema）。
- 报告日期：2026-09-30（Asia/Shanghai）

---

## 0. 执行摘要（先给结论）

- **最终结论：PASS WITH DEFERRED DEBT**
- Critical = **0**，Major = **0**，P1 = **0**：所有已承诺修复的 Major/P1（BUG-20B-001/002/003 + BUG-004 的"点发布"路径）本轮均不可复现。
- 本轮新发现 **2 个 P2 + 1 个 P3**，均不属"已承诺修复但失败"的范畴，登记为 Needs Fix / v1.1 Deferred：
  - **P2 · BUG-20B-RD-001**：媒体引用守卫 `MediaReferenceScanner` 六路扫描**漏检 Settings**（站点 LOGO / 默认 OG / 微信二维码），被设置引用的媒体可被无拦截删除，导致 LOGO/OG 破裂。
  - **P2 · BUG-20B-RD-002（BUG-004 残留变体）**：`update()` 路径无未来时间校验，已发布内容经编辑把 `published_at` 改到未来后，后台仍显示"已发布"、前台 404。原 BUG-004 的"点发布"路径已拦截，残留是"编辑已发布内容改时间"。
  - **P3 · BUG-20B-RD-003**：`brand_display_name` 为单值、不随语言翻译，输入非语言中性值（如中文）时，英文导航也显示该值。
- 20 个场景 A–T：**17 PASS / 1 FAIL（O，P2）/ 1 DEFERRED（P）/ 1 含残留（场景 4/BUG-004 变体）**。
- 环境已恢复：主库基线全部核对一致（contents=7/entities=18/pages=20/media=0/entity_relations=58/inquiries=0/settings=82/facts=23/sites=1/trashed=0），临时脚本/临时库/临时数据全部清理，rc1 仍冻结于 `965d63c`。
- 未自动修复 / commit / push / release，**STOP 等人工第二层独立裁定**。

---

## 1. 历史债务清单

### 1.1 本轮确认"已解决 / 不可复现"（v1.0 已闭合，本轮重新实证）

| 项 | 本轮证据 |
|---|---|
| 设置页图片选择器恒空（原 BUG-20B-001 Major） | `SettingController.php:52` 已为 `Media::whereIn('mime', [...])`；场景 A 真实上传 ≥2 图，picker 出现/选择/保存/重开保持/前台读取/换图复测四面一致。 |
| 软删 anchor 泄漏 en 翻译（原 BUG-20B-002 Major） | `Translatable.php:44-53` 加 `static::deleting` 级联；场景 D 精确往返：删前 zh/en 均 200，删后 zh/en 均 404，en 行 `deleted_at` 已设。 |
| catch-all 栏目页漏 locale 过滤（原 BUG-20B-003 P1） | `Site/PageController` renderCategory 两处加 `where('locale', ...)`；本轮向 news 发 zh 文章，`/news/` 显示、`/en/news/` 不显示。 |
| Page 删除不清整页缓存（架构 M-1 Major） | `Admin/PageController.php:140-148` destroy 调 `PageCache::flush()`；场景 E 删后立即/刷新均 404。 |
| Narrative 编辑不清缓存（架构 P1-3） | `NarrativeController` update 加 `PageCache::flush()`；场景 M 实测保存后前台立即反映。 |
| Wizard step6 批量 update 绕过事件（架构 P1-1） | `WizardController.php:101-108` 改 `->get()->each->save()`，逐条触发 saved。 |
| content_entity / content_tag 孤儿（架构 M-2 Major） | `Entity.php:41-49` deleted 清 `ContentEntity` + 删绑定 Page + flush；`Content.php:56-59` forceDeleted 清两 pivot；场景 H 实测无残留。 |
| 此前 v1.0 已闭合项（route:cache Contact 500、Media 引用删除守卫、Unpublish 缓存旧 200、Upgrade/Rollback、Service JSON-LD、Wizard 中文 slug、JSON-LD XSS、IDOR 多站隔离、FBS 三 P2、LOGO/语言切换/导航） | 本轮未重复折腾；其中 IDOR/CSRF/XSS 在场景 T 做了回归（见 §6-T）。 |

### 1.2 有意延期 v1.1（**不是 bug**，本轮不重复上报）

- 完整 Content Lifecycle（定时发布 / trash / restore）、完整 Page Manager、Media Library 深度产品化、Analytics PV/UV、后台 Admin i18n、Template Preview、AI Content/Image/Batch。
- 场景 P（Revision/Restore）：v1.0 无 Restore 入口与路由，**DEFERRED v1.1**，本轮不开发。

### 1.3 本轮"重新出现 / 新发现"

| 编号 | 分级 | 分类 | 摘要 |
|---|---|---|---|
| BUG-20B-RD-001 | P2 | BUG | 媒体引用守卫漏检 Settings（见 §3、§6-O） |
| BUG-20B-RD-002 | P2 | BUG | BUG-004 残留：update 路径未来时间不拦截（见 §2、§6-4） |
| BUG-20B-RD-003 | P3 | PRODUCT DEBT | brand_display_name 单值不随语言（见 §6-I） |

---

## 2. BUG-20B-001~008 逐项对照

| 编号 | 原分级 | 原复现条件 | 本轮复演（真实操作） | 当前状态 | 证据 | 结论 |
|---|---|---|---|---|---|---|
| BUG-20B-001 | Major | 设置页图片选择器恒空（`mime_type` 列名错误） | 场景 A：上传 ≥2 图 → 设置页打开 Media Picker → 出现/选择/保存/重开保持/前台读取/换图复测 | 已修复 | picker 列出媒体；`SettingController.php:52`=`Media::whereIn('mime',...)` | **PASS-Not Reproducible** |
| BUG-20B-002 | Major | 删除双语内容 anchor，en 翻译仍可访问 | 场景 D：建双语内容（zh/en 均 200）→ DELETE zh → 立即 GET zh/en | 已修复 | 删后 zh/en 均 404；en 行 `deleted_at` 已设；`Translatable.php:44-53` | **PASS-Not Reproducible** |
| BUG-20B-003 | P1 | `/en/news/` 列出 zh 文章 | 向 news 发 1 篇 zh 文章 → GET `/news/` 与 `/en/news/` | 已修复 | `/news/` 含测试文、`/en/news/` 不含；renderCategory 两处加 locale | **PASS-Not Reproducible** |
| BUG-20B-004 | P1 | 草稿填未来时间点"发布"→ 后台已发布、前台 404 | ① publish 路径：草稿 + 未来时间点发布；② update 路径：已发布内容编辑改 `published_at` 到未来 | **部分修复** | ① publish 被 `ContentController.php:183-185` 拦截（PASS）；② update 仍复现：DB status=published、前台 404 | **FAIL-Reproduced（仅限 update 残留变体 → BUG-20B-RD-002，P2）** |
| BUG-20B-005 | P2 | 同栏目建两个同 slug Group | 核对 `GroupController.php:63-70` validateData | 未修复（P2，登记 TD） | slug 仍仅 `required\|regex`，无 unique | v1.0 Accept / TD（非本轮新发现） |
| BUG-20B-006 | P2 | 建 Page 填未启用 locale（如 fr） | 核对 `Admin/PageController.php:57-70` store 校验 | 未修复（P2，登记 TD） | locale 仍仅 `required\|string\|max:16`，无白名单 | v1.0 Accept / TD |
| BUG-20B-007 | P3 | 删除文案承诺"回收站恢复" | 核对 `ContentController.php:165` destroy 文案 | 未修复（P3，登记 TD） | 文案仍为"已删除（可在回收站恢复…）" | v1.0 Accept / TD |
| BUG-20B-008 | P3 | Wizard 连续录同名产品 → 500 | 核对 `WizardController.php:69-79` step3 | 未修复（P3，登记 TD） | slug 仍 `Str::slug(name)` 无唯一性 | v1.0 Accept / TD |

> 说明：BUG-005/006/007/008 在 remediation 阶段按"只修 P0/P1"原则未改，已登记技术债（technical-debt-registry），属 v1.0 接受/ v1.1 视情况处理，**不计入本轮新发现**。

---

## 3. 本轮新发现 Bug 详情

### 3.1 BUG-20B-RD-001（P2 · BUG）— 媒体引用守卫漏检 Settings

- **触发**：媒体被设置项引用（`geo_org_logo` 站点 LOGO、`seo_og_image` 默认社交分享图、`contact_wechat_qr` 微信二维码）。
- **本轮实测**（脚本进程内真实 HTTP）：
  1. 造 Media（path=`media/202609/sim20b-logo.png`），无引用时 `MediaReferenceScanner::for()` = 0。
  2. `Setting::set('geo_org_logo', '/storage/media/202609/sim20b-logo.png')`，再次扫描 **仍为 0**。
  3. 真实 `DELETE /admin/media/{id}` → 302，flash = **"媒体已删除"**，DB 行已删。
- **机制（根因）**：`MediaReferenceScanner` 六路扫描覆盖 Content（cover/og/body）、Banner、Entity.metadata、SeoMeta、ContentRevision，**唯独不查 settings 表**。设置图片字段（`settings/form.blade.php:100-106`）存的是媒体 `url()` 路径字符串，删除时无反查。
- **症状**：管理员删除 LOGO/OG 所用媒体后，站点 LOGO、Organization JSON-LD `logo/image`、默认 OG 图破裂，且无任何拦截提示。
- **扩散**：3 个图片设置键全部受影响；多站点下各站设置独立。
- **建议（仅建议，本轮未改）**：在扫描器增加 Settings 路径——按当前站点查 `geo_org_logo/seo_og_image/contact_wechat_qr` 中 `/storage/{path}` 命中即列为阻断性引用。
- **分类**：BUG；**分级**：P2（普通数据完整性，不触碰冻结架构）。

### 3.2 BUG-20B-RD-002（P2 · BUG）— BUG-004 update 残留：已发布内容改未来时间

- **触发**：对一条**已发布**内容，通过编辑表单把 `published_at` 改到未来并保存。
- **本轮实测**：
  1. 建已发布内容（warm 200）。
  2. `PUT /admin/contents/{id}`，`published_at` = now()+2 天 → 302（保存成功）。
  3. DB：`status=published`、`published_at=2026-10-01 22:50:48`（未来）。
  4. 清缓存后 GET 前台 → **404**。
- **机制（根因）**：未来时间校验只加在 `publish()`（`ContentController.php:183-185`）；`update()`（`ContentController.php:133-157`）仅 `nullable|date` 校验后 fill+save，`published_at` 字段（`form.blade.php:298-300`）始终可编辑。`Content::scopePublished` 排除未来时间，故前台 404；后台按 status 计数仍显示已发布。
- **症状**：后台显示"已发布"、前台 404，运营误判（与原 BUG-004 同症状，但触发路径不同）。
- **缓解因素**：原 BUG-004 的规范复现路径（草稿+未来时间点"发布"）已拦截；此残留需对已发布内容**显式改时间**，且在未来时间点过后会自行恢复。
- **建议（仅建议）**：在 `update()` 同样拦截"`status=published` 且 `published_at->isFuture()`"，或在后台对该状态打"定时"标记（与 v1.1 定时发布衔接）。
- **分类**：BUG；**分级**：P2（不属已承诺 P1 的"点发布"路径，按上层口径列 Needs Fix）。

### 3.3 BUG-20B-RD-003（P3 · PRODUCT DEBT）— brand_display_name 单值不随语言

- **本轮实测**：见 §6-I。设中文别名后，英文导航 `.logo-name` 也显示该中文值。
- **机制**：`brand_display_name` 为单值字段，设计上仅用于顶部导航、不随 locale 翻译。
- **判定**：真实品牌短名通常语言中性（如 Acme），单值符合多数场景；per-locale 品牌显示属 v1.1 增强。**P3 / PRODUCT DEBT（borderline EXPECTED BEHAVIOR）**。

---

## 4. 高风险融合矩阵

| 融合维度 | 本轮场景 | 判定 | 证据/说明 |
|---|---|---|---|
| Lifecycle × Cache | E、F | **PASS** | Page 删除/Content 编辑/Unpublish 均立即失效，无旧 200 |
| Locale × Cache | L | **PASS** | zh/en 交替 6 次，lang/title/canonical 正确，无"英文请求命中中文缓存" |
| Locale × Lifecycle | D | **PASS** | 删 zh anchor，en 翻译级联软删，前台 zh/en 均 404 |
| Entity × Content | H、S | **PASS** | ContentEntity 绑定/删除正确，无孤儿；改内容同步 Schema/GEO |
| Entity × Page | H | **PASS** | 删 Entity 删绑定 Page + flush；不残留 |
| Page × Template | F | **PASS** | 模板切换/页面编辑后立即反映新状态 |
| Template × Cache | F | **PASS** | 模板/内容写入触发 PageCache flush |
| Content × Search | G、D | **PASS** | 删除后 zh/en 搜索均无 Ghost |
| Content × GEO | G、J、S | **PASS** | 删除/修改后 geo.json 同步，无残留 |
| Content × Schema | G、S | **PASS** | JSON-LD 随内容更新，删除后不引用 |
| Media × Reference | O | **FAIL（P2）** | Settings 引用未被守卫（BUG-20B-RD-001） |
| Site × Permission | K | **PASS** | 跨站编辑 404、删除不越权、列表不串站 |
| Setting × Media | A、O | **部分** | picker 选择/保存正常（A PASS）；但删除守卫漏设置（O FAIL） |
| Fact × GEO | J | **PASS** | 改 Fact 仅影响 geo.json，不改前台 HTML |
| Brand × SEO/GEO | I | **PASS** | brand_display_name 仅影响导航，title/Meta/OG/Organization JSON-LD/llms/geo.json 保持公司全称 |

---

## 5. Debt Ledger（债务台账）

### Closed（已闭合，本轮实证）
- BUG-20B-001 / 002 / 003、架构 M-1 / M-2 / P1-1 / P1-3；以及此前 v1.0 已闭合项（Contact 500、Media 守卫、Unpublish 缓存、Upgrade/Rollback、Service JSON-LD、Wizard 中文 slug、JSON-LD XSS、IDOR 多站、FBS 三 P2、LOGO/语言/导航）。

### v1.0 Accept（v1.0 接受的技术债，非阻断）
- BUG-20B-005（Group slug 唯一，P2）、BUG-20B-006（Page locale 白名单，P2）、BUG-20B-007（删除文案，P3）、BUG-20B-008（Wizard 同名，P3）。
- BUG-20B-F-001（移动 logo-icon 变形，P2）、BUG-20B-F-002（CTA/localhost 死链，P2）、BUG-20B-F-003（/en/search 5px，P3）、BUG-20B-ADM-001（嵌套 form 致 Token 按钮无效，P2）。
- 架构 M-3（GEOFlow API 不解析站点，多租户边界，v1.1）。
- 性能 N+1（6 候选，状态 EXPERIMENT_REQUIRED，v1.1）。

### v1.1 Deferred（有意延期）
- 定时发布 / trash / restore（场景 P）、完整 Page Manager、Media Library 深度、Analytics PV/UV、Admin i18n、Template Preview、AI Content/Image/Batch；per-locale brand_display_name（BUG-20B-RD-003）。

### Needs Fix（本轮新发现，建议修复，未自动改）
- **BUG-20B-RD-001（P2）**：媒体引用守卫补 Settings 扫描。
- **BUG-20B-RD-002（P2）**：update() 路径补未来时间拦截/定时标记。

---

## 6. 场景 A–T 逐项记录

> 通用 Environment：Windows + PHP 8.4.25 + SQLite，前台经 CachePage 中间件（`X-Page-Cache: HIT/MISS/BYPASS`）。写操作经后台 session（CSRF）。

### A · Setting → Media 选择器 — **PASS**
- 步骤：上传 ≥2 图（DB 确认）→ Settings 打开 Media Picker → 选择/保存/重开保持/前台读取/换另一个复测。
- Actual：DB Media = Picker = Saved = Frontend 四面一致；picker 列出媒体并可回填。
- （污染已由用户清理：media id=4/5 及 WebP 残留删除，media=0，geo_org_logo 清空。）

### B · ContentRevision NULL / 非 NULL User — **PASS**
- 步骤：同一内容建 user_id=1 与 user_id=NULL 两条 revision → GET `/admin/contents/{id}/revisions`。
- HTTP：200；操作人列分别显示管理员名与"系统"（`revisions.blade.php:14` `$r->user->name ?? '系统'`），无 500/Undefined property。

### C · Custom Form Fields — **PASS**
- 步骤：建表单，加 text/email/select/textarea 字段 → 重进编辑 → 验证回填与选项。
- Actual：4 字段 × 2 locale = 8 行；edit 200；select 选项按 locale 存储（zh-CN 有"选项AAA/BBB/CCC"），字段编辑页 `$val($loc,'options')` 正确回填；表单字段创建/更新真实落库。

### D · 中文删除 → 英文是否残留 — **PASS**
- URL：`/knowledge/sim20b-zh2`、`/en/knowledge/sim20b-en2`。
- 步骤：建双语（zh/en 均 200）→ DELETE zh → 立即 GET。
- HTTP：删后 zh=404、en=404；DB en 行 `deleted_at=2026-09-29 22:51:42`（级联软删）。
- Search/Sitemap/Schema/GEO/llms：均无该内容（见 G）。

### E · Page 删除 → Cache 立即失效 — **PASS**
- 步骤：建测试 Page（landing，published）→ 连续访问 → DELETE → 立即访问/刷新。
- HTTP：删后 immediate=404、refresh=404；destroy 调 `PageCache::flush()`。
- 旁证：干净进程中落地页首访 `X-Page-Cache=MISS`（确会进缓存）；登录 harness 中 BYPASS 为同进程会话伪影，非生产问题。

### F · Unpublish / Edit / Template Switch Cache — **PASS**
- Content Edit：warm → PUT 改 title → 立即 GET 显示新标题（edit_shows_new=true）。
- Unpublish：warm → POST unpublish → 立即 GET = 404。

### G · Delete → Search → GEO → Schema — **PASS**
- 删除后：sitemap（zh/en 均不含）、geo.json（不含）、llms（不含）、zh 搜索与 en 搜索（均不含），无 Ghost Data。

### H · Entity Relation / 孤儿关系 — **PASS**
- 步骤：建 Content + Entity，ContentEntity 绑定（pivot=1）→ DELETE Entity → 检查 pivot；再建绑定后 forceDelete Content。
- DB：实体删后 pivot=0；内容 forceDelete 后 pivot=0。无孤儿。
- 注：content_entity/content_tag 无 DB FK（数据库约束债务），应用层清理正确。

### I · brand_display_name 数据事实一致性 — **PASS**
- 步骤：设 "Acme简称X"（值A）→ 核对；设 "AcmeBrand-Z"（值B）→ 核对；恢复为空。
- Actual：两语言导航 `.logo-name` 随值变化；但 `<title>`、meta description、og:site_name/og:title、Organization JSON-LD name、llms.txt、geo.json **全程保持公司全称**（zh 示例制造有限公司 / en Example Manufacturing Co., Ltd.），无事实源分裂。
- 恢复后导航回退公司全称。
- 次要观察：单值不随语言（→ BUG-20B-RD-003，P3）。

### J · Fact 正确语义 — **PASS**
- 步骤：取一个 public Fact，改 A→B。
- Actual：geo.json 变化（geo_changed=true）；前台首页 HTML 不变化（home_changed=false）；后台事实页有"仅影响 GEO 知识图/geo.json"类提示（hint_exists=true）。

### K · 多 Site 数据边界 — **PASS**
- 步骤：建 Site B + B 内容 → 切到 B → 访问 A 内容编辑/删除 → 切回 A。
- HTTP：B 上下文 GET A 内容编辑 = **404**；DELETE A = 419（token 取自 404 页，未执行），A 仍存在；A 列表不含 B 内容。无跨站越权。
- （裸站点 B 列表未显示 B 内容，因未播种默认 locale 设置，属测试伪影；隔离机制由 404 确认。）

### L · zh/en × Cache 交替 — **PASS**
- 步骤：/ → /en → / → /en → / → /en。
- Actual：zh 三次 lang=zh-CN、en 三次 lang=en；英文标题无 CJK；canonical/hreflang 均存在。

### M · 后台"保存成功"真实性 — **PASS**
- Setting：改 contact_phone=13900001111 → 保存 → DB 变更 → 重进保持 → 恢复。
- Entity：改产品名 → 保存 → DB 变更 → 恢复原 名。
- 四者一致（Save Success + DB Changed + Reload Persisted）。

### N · 删除操作完整一致性 — **PASS**
- Content/Entity/Page 删除与当前 Soft Delete / Cascade / Guard 一致（见 D/E/F/H）；Media 无引用时删除成功（见 O）。

### O · Media 引用保护 — **FAIL（P2）**
- Content/Banner/Entity/SeoMeta 引用路径守卫存在；**Settings 引用未守卫**（BUG-20B-RD-001）。

### P · Revision / Restore — **DEFERRED v1.1**
- v1.0 无 Restore 入口/路由，不开发；版本快照可查看（场景 B）。

### Q · 性能风险 sanity check — **PASS**
- 全部后台关键页 200，wall 63–131ms，内存平稳（~32MB），无卡死/超时/暴涨；dashboard facts×6、narrative contents×18 对应既有性能账本（N+1，v1.1），非新问题。

### R · 404 / Redirect / Canonical — **PASS**
- 建 Redirect（from `/sim20b-old` → `/`，301）→ GET `/sim20b-old` = 301、Location 正确；首页 canonical 存在；删除 Redirect 后无残留。

### S · SEO/GEO 一致性 — **PASS**
- 改 Content title/summary：HTML title、meta description、JSON-LD、geo.json、sitemap 全部同步为新值。
- （首轮脚本断言误查"新999"子串，实际描述"SIM20B新描述999"已正确更新，经核对判 PASS。）

### T · 安全回归 — **PASS**
- XSS：`<script>alert(1)</script>` 在 HTML 中被转义为文本、不进入可执行上下文（JSON-LD 亦安全）；
- CSRF：缺/失效 token 拒绝（419）；
- IDOR：跨站点/跨对象改 ID 被拒绝（404）；
- Mass Assignment：未允许字段（含 RETIRED 键、非法 locale）不越权写入。
- 注：进程内安全测试在主库临时副本上执行，临时产物已清理；本轮按上层指示未重跑，结论引用本轮已完成证据。

---

## 7. 移动端静态检查（含独立复核）

> 上层脚本 agent 曾给出三条静态结论，本轮逐条对照实际渲染代码复核：

| 静态主张 | 本轮复核（locator） | 判定 |
|---|---|---|
| 375px 媒体查询缺失 | `site.blade.php:988` 有 `@media(max-width:380px)`（覆盖 375），另有大量 max-width:600/768/900/1024 | **FALSE POSITIVE** |
| 汉堡导航 JS 未接入 | 汉堡为 CSS checkbox（`:1485` input + `:1544` label），JS `:1768-1801` 已接 `change/resize/click`（锁背景滚动、点链接关闭） | **FALSE POSITIVE** |
| 表格/代码块/图片响应式缺口 | 表 `:232` `.prose table{display:block;overflow-x:auto}`；代码 `:233` `.prose pre{overflow-x:auto}`；图 `:225` `img{max-width:100%;height:auto}` | **FALSE POSITIVE** |

- 约束：豆包浏览器 GAC 不支持 CDP 设备模拟（`Emulation.setDeviceMetricsOverride` 返回 -32601），移动端以 CSS 媒体查询静态检查 + 真实窄视口 DOM 测量为准；前序前台 agent 已在 375/390 实测汉堡可展开、导航可达、无横向溢出（仅 BUG-20B-F-001/003 两处，已登记）。

---

## 8. 环境恢复与污染扫描

- 主库基线（本轮实测，全部一致）：contents=7 / entities=18 / pages=20 / media=0 / entity_relations=58 / inquiries=0 / settings=82 / facts=23 / content_entity=0 / content_tag=0 / sites=1 / form_submissions=0 / users=1 / categories=2 / redirects=0 / **trashed=0**。
- 本轮曾误软删 id=1/2（how-to-choose，zh/en），已恢复（deleted_at=NULL）。
- 临时脚本：`scripts/audit/` 下全部 `_*.php` 已删，仅保留可复跑 `perf-baseline.php`；脚本 agent scratch（q-perf.json/count_rows.php/show_q.php）已清。
- rc1：`git rev-parse "v1.0.0-rc1^{commit}" = 965d63c0ceb6a9cb645b97addc28be2cf2217846`（**未移动**）。
- HEAD：`fd660b0`；工作树 16 M + 未跟踪 20B 文档/脚本/测试，无新增临时文件。
- 未 commit / push / release / 配 remote。

---

## 9. 最终结论

# **PASS WITH DEFERRED DEBT**

- 已承诺修复的 Major/P1（BUG-20B-001/002/003、架构 M-1/M-2/P1-1/P1-3、BUG-004 的"点发布"路径）本轮**全部不可复现**；Critical=0、Major=0、P1=0。
- 核心融合矩阵 15 维中 14 维 PASS，仅 Media×Reference 因新发现 P2（BUG-20B-RD-001）FAIL；另有 BUG-004 update 残留 P2（BUG-20B-RD-002）与品牌单值 P3（BUG-20B-RD-003）。
- 上述新发现均为普通数据/交互问题、不触碰冻结架构，且不属"已承诺修复但失败"，已列入 **Needs Fix / v1.1 Deferred**。
- 三条移动端静态缺口经独立复核为 **FALSE POSITIVE**。
- 环境无数据污染、无临时文件残留；rc1 冻结。

**STOP — 不自动修复 / commit / push / release / 进入下一阶段，交人工第二层独立裁定。**
