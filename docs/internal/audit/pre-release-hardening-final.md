# Pre-Release Hardening — Final Verification

## 1. 元信息

| 项 | 值 |
|---|---|
| 日期 | 2026-09-27 (Asia/Shanghai) |
| 权威仓 | `D:\GEO-OS-rewrite\geo-website-os`（分支 `main`） |
| 基线 commit | `e091ca3` — *fix(cache): ensure unpublish paths invalidate PageCache immediately* |
| 验证范围 | Pre-Release Hardening 三项必修（① Media 引用删除守卫 ② Unpublish 缓存失效 ③ TD-168 Upgrade/Rollback E2E）+ SEO/GEO 不回归对拍 + 全量回归 |
| PHP | `C:\php84\php.exe` (8.4.25) |
| 浏览器闭环方式 | `php artisan serve --port=8123` 真实 HTTP 闭环（访客 session 直访前台，管理员 session 走后台真实路由），验证后已停服 |

---

## 2. 执行摘要

| 必修项 | 结果 | Gate |
|---|---|---|
| ① Media 引用删除守卫 | **PASS**（引用被拒删除 + 人类可读引用位置 + 审计；无引用正常删除） | ✅ |
| ② Unpublish 缓存失效 | **PASS**（暖缓存 HIT → 下架 → 立即 404 BYPASS，不命中 6h 旧页） | ✅ |
| ③ TD-168 Upgrade/Rollback E2E | **PASS**（成功升级 + 失败升级→整库 sha256 还原） | ✅ |
| SEO/GEO 不回归 | **PASS**（sitemap/llms/geo/canonical/hreflang/JSON-LD/搜索 全部对拍通过） | ✅ |
| 全量回归 | **PASS**（1242 passed / 6651 assertions / 0 failed / 0 skipped） | ✅ |

**Gate 结论：三项必修全部 PASS，证据完整，允许重新进入 Release Gate。**

---

## 3. 必修① Media 引用删除守卫

### 实现说明
- 新增 `app/Support/MediaReferenceScanner.php`：对 `Media` 做六路反查——
  1. `Content.cover_id`（文章封面）
  2. `Content.og_image_id`（文章 OG 分享图）
  3. `Banner.image_id`（轮播位）
  4. `Entity.metadata->media_id / og_image`（实体主图/OG，whereJsonContains）
  5. `Content.body` 内联图 path 串（`/storage/{path}` LIKE）
  6. `SeoMeta.og_image_path` + `Entity.metadata->image` 兜底
  - 附查 `ContentRevision` 历史快照：仅记 `warning=true`，**不阻断**删除（历史快照不对外展示，回滚才可能复活破图，故放行但提示运营）。
- 修改 `app/Http/Controllers/Admin/MediaController::destroy()`：
  - 存在阻断性引用 → `back()->with('error', ...)`（前 5 条 label）+ `AuditLog media.delete_blocked`；文件与 DB 记录**均保留**。
  - 无阻断引用 → 删物理文件 + 删 DB 记录；若有 warning 级历史快照，成功消息附带提示。

### 浏览器真实闭环证据（:8123）
准备：新建 Media #1（真实 PNG 落 `storage/app/public/media/202609/`），设为文章《工业涂料怎么选…》封面（Translatable 同步使其中英两版均指向 cover_id=1）；新建无引用 Media #2。

**(a) 删除被引用 Media #1**（后台媒体库删除按钮 = POST `/admin/media/1` + `_method=DELETE`）：
- HTTP 200（回退媒体库页），页面横幅：
  > 该媒体被 **2 处引用**，**已拒绝删除**：文章《工业涂料怎么选：树脂体系、性能指标与选型逻辑》**封面图**；文章《How to Choose Industrial Coatings: Resin Systems, Performance Indicators and Selection Logic》**封面图**
- 含"被引用"语义（"被 2 处引用，已拒绝删除"）✅
- 含 ≥1 条人类可读引用位置（"文章《…》封面图" ×2）✅
- 文件未删除：DB row `PRESENT`、磁盘文件 `PRESENT`、`cover_id=1` 完好 ✅
- 审计：`media.delete_blocked`，`summary="删除被阻止：guard-referenced.png"`，`detail={total:2, refs:[…两条封面 label…]}` ✅

**(b) 删除无引用 Media #2**：
- 页面提示"媒体已删除" ✅
- DB row `GONE`、磁盘文件 `GONE` ✅

### 测试结果
`tests/Feature/Admin/MediaControllerTest.php`（3）+ `tests/Feature/Admin/MediaReferenceScannerTest.php`（8）= **11 passed**，覆盖六路扫描 + 阻断/放行 + 历史快照仅告警 + 审计摘要。

---

## 4. 必修② Unpublish 缓存失效

### 四条写路径核对（实现：Content/Entity/GeoflowSync 经 `saved` 钩子 → `PageCache::flush()`；Page 经 `PageController::forgetPage()`）
| 路径 | 入口 | 失效机制 | 测试断言 |
|---|---|---|---|
| (a) Content Admin | `POST admin/contents/{id}/unpublish` → status=draft | `Content::saved` → flush（全局版本号 +1） | 下线前 200+标记；下线后版本 +1、前台 404、非 HIT、无旧标记 |
| (b) Entity Admin | `POST admin/entities/{id}/unpublish` | `Entity::saved` → flush | 同上（`/products/{slug}`） |
| (c) Page Admin | `POST admin/pages/{id}/publish?action=unpublish` | `forgetPage()`（path 级） | 下线后 404、非 HIT、无旧标记 |
| (d) GeoflowSync | `GeoflowSync::unpublish` → status=archived | `Content::update` → saved → flush | 版本 +1、前台 404 |

### 浏览器真实闭环证据（:8123，访客 session）
发布探针文章（slug `hardening-cache-probe`，标题/正文标记 `ZZZCACHEPROBE2026`）：

| 步骤 | 结果 |
|---|---|
| 第 1 次前台 GET `/knowledge/hardening-cache-probe` | **200**，`X-Page-Cache=MISS`，含标记 ✅ |
| 第 2 次前台 GET（确认整页缓存已暖住） | **200**，`X-Page-Cache=HIT`，含标记 ✅ |
| 后台点击"下架" `POST admin/contents/8/unpublish` | 302 重定向 ✅ |
| **立即** 前台再次 GET | **404**，`X-Page-Cache=BYPASS`（未命中 6h 旧缓存）✅ |
| 首页 `/` | 200，**不含**探针标题 ✅ |
| 列表页 `/knowledge` | 200，**不含**探针标题 ✅ |
| DB 复核 | `status=draft` ✅ |

### 测试结果
`tests/Feature/UnpublishCacheInvalidationTest.php` = **4 passed**（对应 a/b/c/d 四路径），**无 app 代码变更**（e091ca3 仅补回归证明既有钩子覆盖全部路径）。

---

## 5. 必修③ TD-168 Upgrade/Rollback E2E

状态：**TESTED**（Release Evidence Gap 关闭）。隔离策略：`sys_get_temp_dir()` 下独立 sqlite 文件，临时迁移写 `tests/tmp/`，绝不碰开发库。

### 测试 A — 成功升级（backup → migrate → health → counts → version）
`test_a_successful_upgrade_preserves_data_and_reports_version`：
- `geo:backup` 产出 `.sqlite` + `.json` 清单；清单 `sha256` 为 64 位 hex 且与 `hash_file` 一致 ✅
- 应用一条纯增量迁移（`contents` 加可空列 `e2e_upgrade_marker`）exit 0 ✅
- `GET /api/v1/health` → 200 `{status:ok}`；home/sitemap/knowledge 路由已注册；关键表存在 ✅
- baseline counts（contents/entities/pages/media）升级前后完全一致 ✅
- 版本号（`config('geo.version')`）与备份清单一致；新列存在；新迁移写入 `migrations` 表且 batch ≥2 ✅

### 测试 B — 失败升级 → rollback（sha256 一致 → health 200 → migrations 回退）
`test_b_failed_upgrade_then_rollback_restores_exact_database`：
- `geo:backup` 记录备份 sha256 ✅
- 注入 `up()` 抛 `RuntimeException` 的坏迁移 → `migrate` 必须失败 ✅
- `geo:rollback --backup=… --force` exit 0 ✅
- 还原后 DB 文件 `sha256` 与备份**完全一致**（整库还原）✅
- counts 与 baseline 一致；`/api/v1/health` 200 ✅
- 坏迁移**无残留**：`migrations` 表无该行，坏列 `e2e_upgrade_marker` 不存在 ✅

### 测试结果
`tests/Feature/UpgradeRollbackE2ETest.php` = **2 passed**。

---

## 6. 变更文件清单

**必修①（Media 引用删除守卫）**
- `app/Support/MediaReferenceScanner.php`（新增）
- `app/Http/Controllers/Admin/MediaController.php`（修改 `destroy()`）
- `tests/Feature/Admin/MediaReferenceScannerTest.php`（新增，8）
- `tests/Feature/Admin/MediaControllerTest.php`（新增，3）

**必修②（Unpublish 缓存失效）**
- `tests/Feature/UnpublishCacheInvalidationTest.php`（新增，4；**无 app 代码变更**，证明四条既有路径已覆盖）

**必修③（TD-168 E2E）**
- `tests/Feature/UpgradeRollbackE2ETest.php`（新增，2）

---

## 7. 全量回归结果

本次在 `e091ca3` 实跑（`php artisan test --no-coverage`）：

```
Tests:    1242 passed (6651 assertions)
Duration: 898.36s
```

- 0 failed / 0 skipped（无 skipped 报告行）。

**基线对比：**

| 指标 | 基线 | 当前 | Δ |
|---|---|---|---|
| 测试数 | 1225 | 1242 | **+17**（11 Media + 4 Unpublish + 2 TD-168） |
| 断言数 | 6534 | 6651 | **+117** |

---

## 8. SEO/GEO 不回归对拍结果（:8123）

| 检查 | 结果 |
|---|---|
| `GET /sitemap.xml` | **200**；含已发布 URL（如 `/knowledge/how-to-choose-industrial-coatings`）；**不含**已下线探针 `/knowledge/hardening-cache-probe` ✅ |
| `GET /llms.txt` | **200**；不含探针 ✅ |
| `GET /geo.json` | **200**（top-level: `$schema, generated_at, site, facts, entities, relations, contents`）；不含探针 ✅ |
| `GET /robots.txt` | **200** ✅ |
| 已发布文章页 canonical | `<link rel="canonical" href="…/knowledge/how-to-choose-industrial-coatings">` 正确 ✅ |
| hreflang（多语言已启用） | `zh-CN` / `en` / `x-default` 三组 alternate 齐全 ✅ |
| Schema.org JSON-LD | 文章页 4 个、产品页 6 个 `application/ld+json` 块 ✅ |
| 产品/案例详情 URL 结构 | `/products/{slug}`（如 `/products/epoxy-primer-100`）✅ |
| 已下线内容是否泄漏 | 不在 sitemap / llms.txt / geo.json；搜索页显示"没有找到与「ZZZCACHEPROBE2026」相关的内容"，无指向探针文章的链接 ✅ |

---

## 9. git 状态与 rc1 确认

| 项 | 值 |
|---|---|
| 当前 HEAD | `e091ca3` ✅ |
| 工作树 | clean（验证前临时恢复了被运行时删掉的 `storage/framework/cache/data/.gitignore`）✅ |
| rc1 tag | `v1.0.0-rc1` → `965d63c0ceb6a9cb645b97addc28be2cf2217846`，**未移动** ✅ |
| 领先 rc1 | 71 commits |
| push / release / GitHub 连接 | 未 push、未 release、未连 GitHub（origin 仅本地配置，无 upstream 跟踪）✅ |

---

## 10. Final Release Gate 结论

**PASS —— 允许重新进入 Release Gate。**

依据：
1. 三项必修全部有「实现 + 真实浏览器/HTTP 闭环证据 + 自动化测试」三重佐证；
2. 全量回归 1242/6651 全绿，无失败无跳过，净增 +17 测试 / +117 断言；
3. SEO/GEO 端点与前台语义化标签无回归，已下线内容在所有公开发布面（sitemap/llms/geo/搜索/列表/详情）均不可见；
4. git 状态干净、rc1 tag 未漂移、未对外发布。

---

## 11. STOP 声明

本次验证到此结束。**不自动进入 18U-1**，等待 Release Gate 人工放行指令后再继续。
