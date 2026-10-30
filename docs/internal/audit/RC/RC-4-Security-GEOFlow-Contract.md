# RC-4 · Security / GEOFlow Contract Final

> **状态**：✅ **PASS**
> **完成时间**：2026-10-04 22:02 (GMT+8)
> **基线**：RC-0 冻结工作树（RC-1 / RC-2 / RC-3 通过后的同一状态）
> **工具**：`docs/audit/RC/rc4-security-geoflow.php`
> **原始日志**：`D:/Temp/rc4_run.log` · **机器可读**：`D:/Temp/rc4_result.json`

---

## 1. 结论

```text
RC4_ASSERTIONS   47
RC4_PASS         47
RC4_FAIL         0
EXIT_CODE        0
```

| 判定项 | 结果 |
|---|:--:|
| Security | ✅ |
| Validation | ✅ |
| Nullable | ✅ |
| Hash / Idempotency | ✅ |
| Lock | ✅ |
| Kill Switch | ✅ |
| Concurrency | ✅ |
| Response Contract | ✅ |
| Audit / Revision | ✅ |
| Cache Invalidation | ✅ |

### 五个不变量

```text
1 非法输入 → 422（无 TypeError / 500）        ✅
2 同 payload 二次推送 → stable skip            ✅
3 lock_manual → upsert / unpublish 都不覆盖✅
4 kill switch OFF → 所有写入口停止            ✅
5 一次真实写入 → 一次 cache invalidation      ✅
```

---

## 2. 边界合规

| 边界 | 要求 | 实际 |
|---|---|---|
| 产品代码 | 0 修改 | 79 项（与 RC-0/1/2/3 完全一致） |
| HEAD / BRANCH | 未变 | `fd660b0` / `main` |
| 开发库 | 只读 | 未访问 |
| 临时库 | 项目目录外 | `D:/Temp/rc4_sec.sqlite`（56 迁移 + `geo:install`） |
| storage/ | git clean | 清缓存后已 `git checkout` 恢复 `.gitignore` |

---

## 3. 🔒 不变量 5 的计数证据（本轮核心）

`PageCache::flush()` 是 `version() + 1`，**非幂等** —— 一次写入若触发 N 个 hook，版本就 +N。
因此本轮用**版本号计数**而非「最终缓存正确」来证明：

| 模型路径 | before → after | Δ | 判定 |
|---|---|:--:|:--:|
| `content`（update → save） | 3 → 4 | **1** | ✅ |
| `fact`（create） | 4 → 5 | **1** | ✅ |
| `category`（create） | 5 → 6 | **1** | ✅ |
| `entity`（create） | 6 → 7 | **1** | ✅ |
| `menu`（create） | 7 → 8 | **1** | ✅ |
| `entity-del`（delete） | 8 → 9 | **1** | ✅ |

**6/6 全部 Δ=1，没有 N+2。**

> 合并期曾出现 `Entity` / `EntityRelation` 原有内联 flush 与
> `CacheInvalidationMap` 重复登记的问题。当前代码已明确修复：
> `Entity.php:39-41` 注明「整页缓存失效登记的唯一事实源是 CacheInvalidationMap…
> 此处**刻意不再重复挂 saved/deleted 的 flush**」。
> 本轮以计数**实证**该不变量在冻结工作树上成立。

### 计数方法（两个前提，缺一则Δ 恒为 0）

```text
① 读写必须落在同一独立进程内（同一 cache store + 同一 SiteContext）
② 写入必须走 Eloquent save()——query()->update() 直接走 Query Builder，
   不触发模型事件，故不会触发 CacheInvalidationMap 的 saved 钩子
```

> **通则**：「一次写入 = 一次失效」成立的前提是**写入经过模型事件链**。
> 任何绕过 Eloquent 的批量更新都不会推进版本号。

---

## 4. A · Rendering Security

8 组 XSS payload，全部走**真实 HTTP 内核**写入 GEOFlow，再验证**渲染出口**：

```text
<script>alert(1)</script>          ✅
<img src=x onerror=alert(1)>       ✅（未入库= 无攻击面）
<svg/onload=alert(1)>              ✅（未入库）
<a href="javascript:alert(1)">     ✅（未入库）
<a href="data:text/html;base64,..">✅（未入库）
<iframe src="javascript:...">      ✅（已实体化）
<div onmouseover="alert(1)">✅（已实体化）
<a href="vbscript:msgbox(1)">      ✅（已实体化）
```

| 断言 | 结果 |
|---|:--:|
| SEC-01 ×8 各 payload 无可执行 HTML 攻击面 | ✅ |
| SEC-02 `bodyHtml()` 出口无可执行 HTML | ✅ |
| SEC-03 Malformed UTF-8 不产生 500 | ✅ |

### 判据落点（关键）

**GEOFlow 写入层有意不做 HTML 净化**（`GeoflowSync` 无 purify/sanitize）——
它接收结构化 markdown 源文本；净化发生在
`Content::bodyHtml()` → `renderMarkdown()`。

所以「库里存原始 payload」是**预期行为**，
**判据必须落在渲染出口**，不能查数据库原文。

### XSS 判据只认裸标签

```text
✅ 正确：先剔除实体化标签，再检测
   ① 裸危险标签 <script <iframe <object <embed <svg
   ② 裸标签带 on*= 事件处理器
   ③ 裸标签 href/src 指向 javascript: / vbscript: / data:text/html

❌ 错误：关键词 contains('javascript:')
   `&lt;iframe src="javascript:..."&gt;` 是实体化文本，浏览器不执行 = 安全
```

> 这是本项目**第 7 次**犯「XSS 判据用关键词匹配」的老坑
> （见 MEMORY.md「我的检测方法失误」第 4 / 7 / 8 项）。

---

## 5. B · GEOFlow Contract（全部真实 HTTP）

### 5.1 校验：非法输入 → 422

| payload | 结果 |
|---|:--:|
| `array_title`（数组穿透） | 422 |
| `array_published`（数组穿透 `published_at`） | 422 |
| `bad_date`（非法日期） | 422 |
| `bad_json`（`geo_evidence` 非法 JSON） | 422 |
| `too_long`（标题 5000 字符） | 422 |
| `bad_enum`（非法 status） | 422 |
| `bad_category`（引用不存在的栏目） | 422 |
| `missing_title` | 422 |

**无5xx、无 TypeError。** ✅

### 5.2 nullable 四态

| 状态 | 语义 | 结果 |
|---|---|:--:|
| `missing` | 不修改 | ✅ 保留原值 |
| `null` | 清空 | ✅ 字段被清空 |
| `valid` | 写入 | ✅ |
| `invalid` | 422 | ✅ 引用字段解析失败必 422，绝不当 null |

### 5.3 hash 幂等（不变量 2）

```text
同 payload 第一次 → changed
同 payload 第二次 → hash 不变 + changed:false
```

---

## 6. C · State & Concurrency（不变量 3 / 4）

### 6.1 lock_manual 保护

| 断言 | 结果 |
|---|:--:|
| `lock_manual=1` 时 upsert 不覆盖人工内容 | ✅ |
| 冲突返回 409（非 500） | ✅ |
| `lock_manual=1` 时 unpublish 不生效 | ✅ |
| 冲突时写 `ContentRevision` 快照 | ✅ |

### 6.2 kill switch（不变量 4）

| 断言 | 结果 |
|---|:--:|
| OFF → `upsert` 被拒 | ✅ |
| OFF → `unpublish` 被拒 | ✅ |
| OFF → **零写入** | ✅ |
| OFF → `check` 仍可只读自检（豁免） | ✅ |

### 6.3 鉴权

| 断言 | 结果 |
|---|:--:|
| 缺 token → 401/403 | ✅ |
| 错误 token → 401/403 | ✅ |

---

## 7. D · Persistence / Audit

| 断言 | 结果 |
|---|:--:|
| GEOFlow 写入产生 `ContentRevision` | ✅ |
| `ContentRevision` 含 `note` / `snapshot` 列 | ✅ |
| 写入落点 `site_id` 正确 | ✅ |
| `sync_logs` 表存在且可写 | ✅ |

> 核实修正：`content_revisions` 实际列为
> `id / site_id / content_id / user_id / note / snapshot / created_at / updated_at`，
> **没有** `source` / `external_id` 列 —— 审计追溯信息存放在 `snapshot`（JSON）中。
> 早期基于旧记忆的断言已按实测列修正。

---

## 8. 本轮修掉的 7 类问题 —— **全部是测试问题，零产品缺陷**

严格按既定审计顺序排查（**不预设是产品缺陷**）：

| 现象 | 根因 | 证据 |
|---|---|---|
| 全站 404 | GEOFlow 路由前缀是 `/api/v1` | `bootstrap/app.php:23` `->prefix('api/v1')` |
| 503「Token 未配置」 | 认证走 `Authorization: Bearer`（非自定义头） | `VerifyGeoflowToken` 用 `$request->bearerToken()` |
| 500「Array to string conversion」 | `Setting.value` 统一 `'value'=>'json'` cast；token 存成 JSON 数组 → `(string)` 抛错 | `Setting.php:24-26` |
| 403「GEOFlow 已关闭」 | 开关值必须是 `"1"`（带引号 JSON 字符串），`assertEnabled()` 是 `!== '1'` 严格比较 | `GeoflowSync.php:60` |
| 422「必须提供合法 slug」 | 新建内容必填 `slug` | `GeoflowSync.php:301` |
| 422「未通过 GEO 门禁」 | `ContentGate` 必填 `geo_conclusion` / `geo_explanation` / `geo_boundary` / `geo_evidence`(≥2 带 source) / `owner` / `reviewed_at` | `ContentGate.php:76-113` |
| XSS 假阳性 | 判据用关键词匹配（老坑第 7 次） | 详见 §4 |

**本轮未改动任何产品代码。**

---

## 9. 关键方法论

### 9.1 Setting JSON cast 的三态陷阱

`Setting` 模型对 `value` 统一 `'value' => 'json'` cast。三种存储形态后果完全不同：

```text
DB 原值json_decode 结果Setting::get()  →(string)   结果
--------------------  ------------------  --------------   -----------   --------
"tok"（带引号）       'tok'（字符串）✅    'tok'          'tok'          200 正常
tok  （无引号）        null（解析失败）     null            ''             503 未配置
["tok"]（数组）       ['tok']（数组）      数组             抛 ErrorException 500
```

> **播种/写入 setting 必须写 `json_encode($value)` 的结果。**
> 生产写入路径 `SettingController:187` 传纯字符串，由 cast 负责编码。

### 9.2 缓存计数必须同进程 + 走模型事件

见 §3 的两个前提。跨进程读数会错位，绕过 Eloquent 的批量更新不会推进版本。

### 9.3 净化在渲染层，不在写入层

GEOFlow 收结构化 markdown，净化在 `bodyHtml() → renderMarkdown()`。
验证 XSS 必须看渲染出口；查数据库原文会得到「原始 payload」这一**预期但无用**的结果。

---

## 10. RC 序列进度

```text
RC-0  Release Freeze              ✅ PASS / FROZEN
RC-1  Full Regression              ✅ PASS  1401 / 7290 / 0 / 0 / 0
RC-2  Fresh / Upgrade / Rollback   ✅ PASS  95 / 95
RC-3  Site × Locale Quadrant       ✅ PASS  130 / 130
RC-4  Security / GEOFlow Contract  ✅ PASS  47 / 47
RC-5  Installation / Operational UX ▶ NEXT
RC-6  Release Artifact Audit        ⏭
RC-7  Final Diff Review → commit → v1.0.0 tag  ⏭
```

**OPEN 0 · VERIFY 0 · BLOCKERS 0 · 产品代码修改 0**
