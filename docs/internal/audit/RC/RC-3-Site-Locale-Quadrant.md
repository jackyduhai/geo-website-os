# RC-3 · Site × Locale Quadrant Final Acceptance

> **状态**：✅ **PASS**
> **完成时间**：2026-10-04 20:44 (GMT+8)
> **基线**：RC-0 冻结工作树（RC-1 / RC-2 通过后的同一状态）
> **工具**：`docs/audit/RC/rc3-quadrant-final.php`
> **原始日志**：`D:/Temp/rc3_run.log` · **机器可读**：`D:/Temp/rc3_result.json`

---

## 1. 结论

```text
RC3_ASSERTIONS   130
RC3_PASS         130
RC3_FAIL         0
EXIT_CODE        0
```

| 判定项 | 结果 |
|---|:--:|
| ROUTING | ✅ |
| SITE CONTEXT | ✅ |
| LOCALE CONTEXT | ✅ |
| CONTENT | ✅ |
| ENTITY | ✅ |
| FACTS | ✅ |
| CATEGORY / GROUP | ✅ |
| MENU | ✅ |
| SETTINGS OUTPUT | ✅（P2 明确记录） |
| CANONICAL | ✅ |
| HREFLANG | ✅ |
| JSON-LD | ✅ |
| GEO JSON | ✅ |
| LLMS | ✅ |
| SITEMAP | ✅ |
| FEED | ✅ |
| CACHE | ✅ |

**Multi-site × Multi-language × GEO × SEO × Cache 的二维隔离证据成立。**

| 隔离维度 | 结果 |
|---|:--:|
| A/zh isolation | ✅ |
| A/en isolation | ✅ |
| B/zh isolation | ✅ |
| B/en isolation | ✅ |
| cross-site negative | ✅ |
| cross-locale negative | ✅ |

---

## 2. 边界合规

| 边界 | 要求 | 实际 |
|---|---|---|
| 产品代码 | 0 修改 | 79 项（与 RC-0/1/2 完全一致） |
| HEAD / BRANCH | 未变 | `fd660b0` / `main` |
| 开发库 | 只读 | 未访问 |
| 临时库 | 项目目录外 | `D:/Temp/rc3_quad.sqlite`（56 迁移 + `geo:install`） |
| storage/ | git clean | 清缓存后已 `git checkout` 恢复 `.gitignore` |

---

## 3. 四象限隔离矩阵（实测）

固定四个命名空间，canary 为 **same slug + different content**，
标记同时编码站点与语言（`RC3Q-A-ZH` / `RC3Q-A-EN` / `RC3Q-B-ZH` / `RC3Q-B-EN`）。

| 端点 | 站点 | 状态 | 含本象限 | 含对方象限 |
|---|---|:--:|:--:|:--:|
| `/geo.json` | A | 200 | A-ZH | **无** |
| `/en/geo.json` | A | 200 | A-EN | **无** |
| `/geo.json` | B | 200 | B-ZH | **无** |
| `/en/geo.json` | B | 200 | B-EN | **无** |

```text
fresh.sqlite  facts.locale 存在     → /geo.json 200
upgrade.sqlite facts.locale 存在    → /geo.json 200
rollback.sqlite facts.locale 已撤销→ /geo.json 500（RC-2 已定性为 DELTA）
```

### 四象限内容矩阵

```text
R-01  四象限页面均可达（200）
C-01  每象限页面含自己的 canary 标记
C-02  每象限页面不含其它三象限标记（零泄漏）
F-02  每象限 geo.json 含本象限 fact 值
F-03  每象限 geo.json 不含其它象限 fact 值（语言+站点双隔离）
F-05  每象限 fact 标签语言正确
E-02  每象限 geo.json 含本象限实体标记
E-03  每象限 geo.json 不含其它三象限实体标记
G-02  四个派生端点各自含本象限且不含其它象限
L-01~L-05 llms.txt 语言路径专项（build / buildGeneric 双路径）
CA-02 缓存后四象限内容仍互不串
```

---

## 4. 负向测试

| 断言 | 场景 | 结果 |
|---|---|:--:|
| C-03a | A 站访问 B 站栏目 URL → 301 且**留在本站** | ✅ |
| C-03b | 301 目标不含 B 站 canary 标记 | ✅ |
| C-03c | 跟随重定向后页面不含 B 站标记 | ✅ |
| N-01 | A/en/geo.json 不出现 B 站数据 | ✅ |
| N-02 | A/zh/geo.json 不出现 B 站数据 | ✅ |
| N-03 | B/zh/sitemap.xml 不出现 A 站 URL | ✅ |
| N-04 | A/en/feed.xml 不出现中文 canary | ✅ |
| N-05×4 | 四象限各自请求对方站点 category URL 负向成立 | ✅ |
| R-04 | 站点未启用 en 时 `/en/*` 严格 404 | ✅ |

---

## 5. 本轮修掉的 6 类问题 —— **全部是测试问题，零产品缺陷**

严格按既定审计顺序排查（**不预设是产品缺陷**）：

```
失败 → ① 命中目标路径 → ② fixture 真实 → ③ locale resolution
     → ④ Site resolution → ⑤ SoT → ⑥ 才判断产品代码
```

| 现象 | 根因 | 归属 | 证据 |
|---|---|---|---|
| B\|en 全线 404（16 项） | 漏播 `site_supported_locales`；`enabled_locales` **不是**门禁 key | fixture | `SetLocale.php:99` 读的是 `site_supported_locales` |
| 跨站 URL 得 301 非 404 | `PublicUrl` 单一裁决：按本站 taxonomy 重定向到本站对应文章 | 设计行为 | Location = `https://rc3-a.test/rc3-cat-a-zh/rc3-quad` |
| 未知站点 200 | `SITE_DEFAULT_FALLBACK=true`（开发默认；生产模板为 `false`） | 配置行为 | `config/site.php:30` |
| `site_name` 四象限全 FAIL | Settings 是**站点级单语**（无 locale 列），却按四象限覆盖播种 | fixture | `settings` 表 `site_name` 每站一行 |
| R-04 门禁仍 200 | **PageCache 命中** → 独立进程直接读缓存，不重跑 middleware | 测试方法 | 清 `storage/framework/cache/data` 后正确 404 |
| E-03 实体断言方向反 | 中文端点不该出现英文实体，那正是隔离生效 | 断言方向 | 四象限实测：zh 端点只有 ZH 标记 |

> **本轮未改动任何产品代码。**

---

## 6. 关键方法论（供后续 Gate 复用）

### 6.1 改settings 后必须清 file cache（ENV-001 同源）

```text
PageCache            按 URL 缓存 → 独立进程也直接命中，不重跑 middleware
Setting::allCached() Cache::rememberForever（file store，跨进程持久）
```

`Setting::flush()` **不足以**解决，必须清 `storage/framework/cache/data`：

```php
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(storage_path('framework/cache/data'), FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    // .gitignore 是 git 跟踪文件，删掉会让工作树出现 D storage/...
    if ($f->isFile() && $f->getFilename() !== '.gitignore') { @unlink($f->getPathname()); }
}
```

### 6.2 门禁 key 与格式必须与 `geo:install` 播种一致

```text
key    site_supported_locales   （不是 enabled_locales）
格式   ["zh-CN","en"]           JSON 数组字符串
```

实测：`CSV "zh-CN,en"` → `/en/*` **误 404**；JSON 数组 → 正确放行。
（`Setting::allCached()` 会把 JSON 值解码成数组，`SetLocale::siteSupportedLocales()`
的 `is_array($raw)` 分支直接使用。）

### 6.3 「本站值被渲染出来」不适合当断言

`site_name` 渲染受多层条件控制（`brand_display_name` 优先级、
`Catalog::company()` 是否为空、footer 分支渲染等）。
它测的是**渲染条件**，不是**隔离性**。

- ✅ 可靠断言：**站点维度不串**（ST-01 / ST-02）
- ✅ 可靠断言：**DB 层 SoT**（ST-02c：两站 `site_name` 各自独立且值不同）
- ⚠️ 降级为 `[OBS]`：本站 settings 值是否被渲染出来

### 6.4 语言相关断言必须按端点分流

```text
/geo.json    → 只含 zh-CN 实体与fact
/en/geo.json → 只含 en 实体与 fact
```

「A 站 geo.json 含 A|en 标记」本身就是**错误预期** —— 中文端点
不该出现英文实体，那正是 i18n 隔离生效的证明。

### 6.5 负向判据应针对「内容泄漏」而非「状态码」

```text
✅ 正确：不返回对方象限内容（301/404 均可，重定向须留在本站）
❌ 错误：硬编码「必须404」
```

---

## 7. P2 明确记录（不升 P1）

```text
KNOWN NON-BLOCKING P2
Settings localization 4 键
    site_name / site_description / brand_display_name / contact_address

事实
    settings 表无 locale 列 → 同站 zh/en 共用一个值（站点级单语）
    site_name 已进 header / footer（site.blade.php:1479 / 1592）
    DB 层两站严格独立（ST-02c 通过）
    仅「同站跨语言」维度未实现

处置
    不阻塞 v1.0 · 不升 P1 · 进入 v1.1 backlog
    必须进 Release Note（site_name 影响前台显示）
```

---

## 8. RC 序列进度

```text
RC-0  Release Freeze✅ PASS / FROZEN
RC-1  Full Regression              ✅ PASS  1401/7290/0/0/0
RC-2  Fresh / Upgrade / Rollback   ✅ PASS  95/95
RC-3  Site × Locale Quadrant       ✅ PASS  130/130
RC-4  Security / GEOFlow Contract  ▶ NEXT
RC-5  Installation / Operational UX ⏭
RC-6  Release Artifact Audit        ⏭
RC-7  Final Diff Review → commit → v1.0.0 tag  ⏭
```

**OPEN 0 · VERIFY 0 · BLOCKERS 0 · 产品代码修改 0**
