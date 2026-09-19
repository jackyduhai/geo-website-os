# STEP 06 — GEO / LLM Discoverability Architecture

> 统一可机器读取结构：正式数据模型 → `/geo.json`（site-scoped）

## 1. 数据边界（冻结）

| 维度 | 来源 | 规则 |
|---|---|---|
| 主体 | `entities`（type 冻结枚举） | 仅 published；SEO 字段经 `resolveEntity` 统一解析 |
| 关系 | `entity_relations`（显式关系表） | 仅输出两端均 published 且同站的关系；**禁止隐式 slug/名称匹配建关系**（模型层已有跨站守卫） |
| 内容 | `contents`（published） | title/description/canonical/noindex 全部经 `SeoMetaResolver::resolveContent` |
| 事实 | `facts` 表 `is_public` 行 | **只引用、不复制、不创造**；每条含 `source`（依据）与 `reviewed_at / review_due`（核定/复核时间） |
| SEO 覆盖 | `seo_metas` | SeoMeta 显式值必须反映到 GEO 输出（SeoMeta → Metadata 连通，测试锁定） |

**禁止项（已落实）**：为 GEO 重复旧 SEO 字段、再造一套 Entity、再造一套事实库、
GEO 系统自行创造事实。全部输出可追溯到正式数据模型行。

## 2. 输出结构（`GET /geo.json`，`$schema: geo-os/graph/v1`）

```json
{
  "$schema": "geo-os/graph/v1",
  "generated_at": "ISO-8601",
  "site":      { "name", "url", "description", "logo" },
  "facts":     [ { "key", "label", "value", "group", "source", "reviewed_at", "review_due" } ],
  "entities":  [ { "id": "entity/{type}/{slug}", "type", "slug", "name", "summary",
                   "url": canonical, "noindex", "metadata", "updated_at" } ],
  "relations": [ { "from", "to", "relation_type", "sort_order" } ],
  "contents":  [ { "id": "content/{type}/{slug}", "type", "slug", "title", "description",
                   "url", "canonical", "noindex", "published_at", "updated_at" } ]
}
```

- 多站隔离：实体/关系/内容均按当前 Site 过滤（测试锁定 Site A/B 互不污染）。
- AI 可读性：canonical 为绝对 HTTPS URL；noindex 显式暴露；时间字段 ISO-8601；
  主体/内容/事实均为稳定 ID。

## 3. 与既有 GEO 产出的关系

| 产出 | 定位 | 本阶段改动 |
|---|---|---|
| `llms.txt`（LlmsBuilder） | 面向 AI 的叙述口径入口（v0.7 冻结规范，Facts 权威源） | 不改——属内容层而非引擎层 |
| `sitemap.xml` / `robots.txt` | 可抓取地址与爬虫策略 | STEP 07 处理 |
| `geo.json`（新增） | 正式数据模型的机器可读结构 | 本阶段建立 |

## 4. 测试锁定（GeoGraphTest，6 用例）

1. 结构四段齐全 + site 基本信息来自 Site（域名 HTTPS）。
2. 事实来自正式事实库：`source` / `reviewed_at` 透出；`is_public=false` 不输出。
3. 实体 SEO 经 Resolver（SeoMeta 覆盖生效）；关系仅限显式 EntityRelation。
4. draft 主体不输出；关系指向未发布主体时整条不输出。
5. 多站隔离：Site B 的实体/关系/内容不出现在 Site A 的图中。
6. 内容 SEO Resolution 链（SeoMeta → Content.seo_title → …）与 noindex 均反映到输出。
