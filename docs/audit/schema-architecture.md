# STEP 05 — Schema.org / Structured Data Architecture

> 统一 Schema 输出层：`SeoMeta + Content/Entity/Site → SchemaBuilder → JSON-LD`

## 1. 数据源边界（冻结）

| 数据 | 来源 | 说明 |
|---|---|---|
| SEO 字段（headline / description / canonical / image） | `SeoMetaResolver` → `SeoResult` | article()/entity() 消费 SeoResult，不再调用 legacy 辅助方法 |
| 主体字段（name / summary / dates / status） | `Content` / `Entity` / `Category` | 正式数据模型 |
| 站点级（名称 / 域名） | `SiteContext::currentSite()` | baseUrl 与 Canonical 同源（`https://{site.domain}`），多站不串 |
| 组织补充属性（legalName / address / foundingDate / areaServed / knowsAbout / sameAs / telephone） | `Site.metadata['organization']` 通用 JSON 扩展 + Setting（后台可运营项） | 业务值只存在于 Seeder / 后台 |
| **禁止** | `Facts::` / `config/facts.php` 直读 | Core Schema 层已全部移除（原 FACT-COMPANY-* 兜底与 knowsAbout 硬编码删除） |

## 2. Entity 通用 Schema 映射（冻结枚举，不得新增 Entity Type）

| Entity.type（冻结） | schema.org @type |
|---|---|
| `organization` | Organization |
| `person` | Person |
| `product` | Product |
| `service` | Service |
| `location` | Place |
| `topic` | WebPage |

- 未在映射内的 type → `entity()` 返回 null（不输出该段）。
- `Entity.metadata` 通用扩展支持：`same_as[]`、`address{street,locality,region,country}`、
  `geo{lat,lng}`——均为通用 schema.org 属性承载，不新增业务专属字段。

## 3. 一致性与有效性规则（测试锁定）

- 每个输出片段独立 `json_encode` + 渲染失败不影响其他段（既有铁律）。
- `@context` 恒为 `https://schema.org`；`@id` 恒为 `https://{site.domain}/#...` 绝对 URL。
- `WebSite.publisher @id` ≡ `Organization.@id`（同源生成，天然一致）。
- 全局片段 `@id` 唯一（organization / website）。
- `inLanguage: zh-CN` 恒定。
- Entity description 优先级 = `SeoMeta.description → Entity.summary → Entity.description → Site → ''`
  （与冻结 SEO Resolution 链一致，由 resolveEntity 保证）。

## 4. 本阶段变更清单

1. `SchemaBuilder`：
   - 数据源切换（见 §1），删除 Facts/业务硬编码兜底。
   - `baseUrl()` 改为 Site.domain 优先（原 `config('app.url')` 在多站下与 canonical 域不一致——已修复）。
   - `article()` 签名增加可选 `SeoResult`（PageController 已传入，避免二次 Resolution）；
     `headline/name/description/mainEntityOfPage.@id/image` 全部来自 SeoResult。
   - 新增 `entity(Entity, ?SeoResult)` 通用方法。
   - 移除 legacy `Content::metaDescription()/canonicalUrl()` 消费（STEP 03 L5 关闭）。
2. `DatabaseSeeder`：组织补充属性写入 default site `metadata.organization`
   （业务数据只存在于 Seeder / 后台）。

## 5. 遗留与移交

- `collectionPage()` 仍读 `categories.seo_title/description`（列表页过渡，随 STEP 08 契约统一）。
- `LlmsBuilder / SitemapBuilder` 的 Facts 直读属 GEO 输出层（STEP 06 / 07 处理）。
