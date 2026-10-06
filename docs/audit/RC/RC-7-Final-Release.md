# RC-7 · Final Diff Review → Release Commit → Tag

> **状态**：✅ PASS · `v1.0.0` 已打 tag（**未 push**）
> **Release Commit**：`d51b6a0`
> **Tag**：`v1.0.0`（annotated）

---

## 0. 结论前置

```text
RC-7.1  Final Diff Review          ✅  143 暂存项 · 0 违规
RC-7.2  DEPLOY checklist           ✅  5 目录警示 + 完整自检清单
RC-7.3  Dependency Audit           ✅  发现 2 漏洞 → 修复 → 复验 0 advisories
RC-7.4  Final Regression           ✅  1410 / 7349 / 0 / 0 / 0 · EXIT 0
RC-7.5  Commit                     ✅  d51b6a0 · 143 files · +28223 / -321
RC-7.6  Archive Reproducibility    ✅  archive 自身跑通 install→boot→双语→隔离
RC-7.7  Tag v1.0.0                 ✅  annotated tag · **未 push**

OPEN 0 · VERIFY 0 · BLOCKERS 0
```

---

## 1. RC-7 Release Diff Fingerprint

> ⚠️ **不能沿用 RC-6 的数字** —— 本轮发生了依赖变更，指纹必须重新生成。

```text
已跟踪修改：46 files changed, 1951 insertions(+), 321 deletions(-)
含未跟踪完整暂存：143 files changed, 28223 insertions(+), 321 deletions(-)
变更类型：A 97（新增） · M 46（修改） · D 0 · R 0
```

与 RC-6 快照（`43 files / +1846 / -310`）的差异来源：

| 来源 | 说明 |
|---|---|
| `README.md` / `DEPLOY.md` / `RELEASE_NOTES.md` | RC-6 补齐的发布说明 |
| `.gitignore` | RC-7 补入 `.workbuddy/` 与 `qa-results/` |
| `composer.json` / `composer.lock` | commonmark 安全升级 |
| `docs/audit/RC/*` | RC 报告与工具 |
| `files 43 → 46` | `README` / `DEPLOY` / `.gitignore` 转为已跟踪修改 |

### 分类

```text
docs 71 · app 31 · tests 18 · database 10 · resources 3
scripts 1 · routes 1 · lang 1 · composer 2 · bootstrap 1
README.md 1 · DEPLOY.md 1 · RELEASE_NOTES.md 1 · .gitignore 1
```

---

## 2. RC-7.1 Final Diff Review

### 2.1 排除项验证（全部生效）

```text
✅ .workbuddy/         → .gitignore:45（RC-7 新增）
✅ qa-results/         → .gitignore:47（RC-7 新增）
✅ /_*.txt /_*.ps1     → 既有规则，status=ignored
✅ storage/framework/{cache/data,sessions,views}
   storage/logs  ·  bootstrap/cache
   → 各含 .gitignore（`*` + `!.gitignore`）
   → git add -An 确认无运行期残留会被跟踪
   → git archive HEAD 实证 5 个占位全部进制品
```

### 2.2 ⚠️ RC-7 抓到的发布边界问题（已修）

`.workbuddy/`（WorkBuddy 记忆）与 `qa-results/`（QA 截图）**原先未被 `.gitignore` 覆盖**。

若不修，`git add -A` 会把**个人记忆内容与测试截图**提交进开源仓库。
这不是 Git 清洁问题，而是**发布边界问题**。

### 2.3 扫描结果

```text
临时/调试痕迹（dd / dump / var_dump / print_r / error_log / console.log）：0
硬编码密钥：0
旧口径文案（41 migrations / 27 tables / single-language / 55 migrations）：0（扫 77 文件）
分支：main
删除文件：0    重命名：0
```

---

## 3. RC-7.2 DEPLOY 打包 Checklist

`DEPLOY.md` §2 开头新增制品警示块，§9 自检清单新增「目录完整性」段：

```text
storage/framework/cache/data/    ┐
storage/framework/sessions/     │ 缺任一 →
storage/framework/views/        │ Please provide a valid cache path
storage/logs/                   │ （HTTP 500，首页+后台全不可用）
bootstrap/cache/                ┘
```

另补入自检清单：`SITE_DEFAULT_FALLBACK=false` · `composer audit` 已执行且无高危漏洞。

> 这条来自 RC-6 在干净副本上实证复现的制品缺陷，现已固化进部署文档，
> 使其在 **Release Artifact 层永久消失**。

---

## 4. RC-7.3 Dependency Security Finding

### 4.1 RC-6 的判定是错的

RC-6 记录 `Dependency CVE Audit = NOT EXECUTED / composer unavailable`——
**只查了 PATH**。实际 `composer.phar` 位于 `/c/php84/`（Composer 2.10.3）。

若沿用 RC-6 结论强行标 PASS，两个生产级漏洞会带着 v1.0.0 发布。

### 4.2 真实发现

```text
RC-7 Dependency Security Finding
league/commonmark 2.10.1
├─ HIGH    GFM Table 扩展 quadratic-time DoS        (PKSA-m4t9-vsgq-8khn, <=2.10.1)
└─ MEDIUM  DisallowedRawHtml 绕过                    (PKSA-m2dq-1fhr-29b1, <=2.10.1)
```

### 4.3 Action

```text
composer update league/commonmark --with-dependencies

league/commonmark       2.10.1  →  2.10.3
symfony/polyfill-php80  v1.37.0 →  v1.43.0   （连带）
```

仅 2 个包变更。`laravel/framework` 仅要求 `^2.8.1`，升级安全。

### 4.4 Validation

```text
composer audit                → No security vulnerability advisories found ✅
定向回归（Markdown/Security/ContentGate/DerivedOutput）
                              → 31 passed / 178 assertions ✅
最终全量回归                   → 1410 passed / 7349 assertions / 0 failures ✅
```

### 4.5 方法论（永久保留）

> **「工具不可用」必须先穷尽 PATH 之外的常见安装位置**：
> `/c/php84/composer.phar`、项目内 `composer.phar`、`vendor/bin/`。
> `command not found` 不等于工具不存在。

---

## 5. RC-7.4 Final Regression

```text
START2026-10-05 20:44:26
END      2026-10-05 21:30:27
EXIT_CODE 0

Tests:    1410 passed (7349 assertions)
Duration: 2753.96s
```

交叉验证：

```text
✓ 标记总数 1410   ⨯ 失败 0   FAIL 段落 0
ERROR 段落  0     SKIPPED 0    PASS 段落 145
```

与 RC-5 R4 基准（`1410 / 7349 / 0 / 0 / 0`）**逐项一致**
→ commonmark 升级**零退化**。

---

## 6. RC-7.5 Commit

```text
d51b6a0release(v1.0.0): finalize GEO OS multi-site multi-language release
143 files changed, 28223 insertions(+), 321 deletions(-)
```

单一 release commit，**未拆分为多个「顺手修复」**。
commit message 完整记录：能力范围 · Gate 证据 · Release Gate 发现与修复 ·
判定为 INVALID 的历史结论 · Known Non-blocking。

---

## 7. RC-7.6 Archive Reproducibility

`git archive d51b6a0` →全新目录 `D:/Temp/rc7_artifact`（**724 文件**）。

### 7.1 制品结构核查

| 项 | 结果 |
|---|---|
| migrations | **56** ✅ |
| `RELEASE_NOTES.md` | present ✅ |
| `DEPLOY.md` | present ✅ |
| `README.md` | present ✅ |
| `composer.lock` | present ✅（commonmark = **2.10.3**） |
| `LICENSE` | present ✅ |
| 5 个 storage/bootstrap 占位 | 全部 present ✅ |
| `.workbuddy/` · `qa-results/` | absent ✅ |
| `database/database.sqlite` | absent ✅ |
| `_verify_curl.ps1` · `_fulltest_out.txt` | absent ✅ |

### 7.2 安装链

```text
migrate  → 56/56 DONE
geo:install → complete（81 settings · 5 categories · 搜索索引 · admin）
```

### 7.3 HTTP 实证（7/7 全通过）

| 用例 | Host | 路径 | 期望 | 实得 | 判定 |
|---|---|---|:--:|:--:|:--:|
| zh 前台 | v1.test | `/` | 200 | 200（111742 B） | ✅ |
| en 前台 | v1.test | `/en` | 200 | 200（111785 B） | ✅ |
| zh GEO | v1.test | `/geo.json` | 200 | 200 · 含中文 ✅ · 不含英文 ✅ | ✅ |
| en GEO | v1.test | `/en/geo.json` | 200 | 200 · 含英文 ✅ · 不含中文 ✅ | ✅ |
| zh sitemap | v1.test | `/sitemap.xml` | 200 | 200 · 不含英文标题 ✅ | ✅ |
| B 站 GEO | v1b.test | `/geo.json` | 200 | 200 · 含 B 站中文 ✅ · 不含 A 站 ✅ | ✅ |
| 未知 Host | nope.test | `/` | 404 | **404** | ✅ |

**语言隔离 + 站点隔离 + 未知 Host 拒绝，全部在 tag 制品上成立。**

---

## 8. RC-7.7 Tag

```text
git tag -a v1.0.0 -m "GEO Website OS v1.0.0 ..."
v1.0.0  GEO Website OS v1.0.0
```

**当前状态：tag 已创建，未 push。**

```text
HEAD      d51b6a0
BRANCH    main
TAG       v1.0.0
PUSHED    no
```

---

## 9. RC-7 判定

```text
Final Diff              ✅
DEPLOY checklist        ✅
Dependency audit        ✅  0 advisories
Final regression        ✅  1410 / 7349 / 0 / 0 / 0
Archive reproducibility ✅  制品自身跑通完整链路
Open-source boundary    ✅  禁止项全部 absent
Working tree review     ✅  clean
Commit                  ✅  d51b6a0
Tag v1.0.0              ✅

OPEN 0 · VERIFY 0 · BLOCKERS 0
```

### 遗留待办（需人工执行）

```text
git push origin main --tags
```

**RC-7 未执行 push** —— 按流程需你确认后再进行。
