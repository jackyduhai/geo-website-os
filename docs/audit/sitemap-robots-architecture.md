# STEP 07 — Sitemap / Robots / Indexability

> 规则统一来源：`Published + 可索引判定 + Canonical/语义地址 → SitemapBuilder → sitemap.xml`

## 1. 可索引判定（统一来源，冻结链）

```
SeoMeta.noindex → Content.noindex → false
```

- `SitemapBuilder::filterIndexable()`：SQL 层排除 `contents.noindex`（legacy 层），
  另行排除 `seo_metas.noindex = true`（第 1 优先级层）——修复了此前 SeoMeta 层
  noindex 不生效于 sitemap 的双源缺口。
- 收录范围：`Content::published()`（draft / archived 排除）+ 归属启用栏目；
  停用栏目、单页型栏目地址不重复收录（去重 `$seen`）。

## 2. 地址规则

- Sitemap 使用**语义公开路径**（`Content::url()` / `Category::url()`），目录型带
  尾斜杠、详情型不带，与 `CanonicalizeSlash` 一致。
- 语义路径 vs canonical 契约形态的双轨已在 `url-architecture-boundaries.md`
  （STEP 04）冻结记录，收敛于 5.12；Sitemap 不自行拼 URL。
- 多站隔离：全部查询经 SiteScope（按 SiteContext 过滤），测试锁定 Site B
  地址不出现在 Site A 的 sitemap。

## 3. robots.txt

- 爬虫策略来自 `config/geo.php`（robots_disallow / ai_crawlers / robots_extra）。
- AI 检索爬虫显式放行（GEO 前提）；`Sitemap:` 指向当前站 sitemap.xml。

## 4. 本阶段变更

1. `SitemapBuilder`：新增 `filterIndexable()`，两处文章收录统一过该判定；
   SeoMeta 导入。
2. 新增 `SitemapRobotsTest`（6 用例）：收录/排除（legacy noindex、SeoMeta
   noindex、draft、停用栏目）、语义 URL 斜杠规则、XML 有效性、多站隔离、
   robots Disallow/AI 爬虫/Sitemap 指令。

## 5. 移交

- 404/410/redirect 的 sitemap 联动：跳转规则由 HandleRedirects 执行层负责
  （旧 URL 本就不在 sitemap）；410 语义当前无数据模型支撑，不擅自新增。
- Entity 无公开路由，暂不入 sitemap；有实体详情页后按 canonical 收录（随 STEP 08+）。
