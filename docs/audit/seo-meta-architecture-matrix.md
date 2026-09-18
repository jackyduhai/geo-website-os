# Phase 5.6-A: SeoMeta Architecture Design Audit (Final)

## 1. 现状分析

### 1.1 现有 SEO 字段分布

**contents 表（已有 SEO 字段）：**
- `seo_title` — SEO 标题
- `seo_desc` — SEO 描述
- `canonical` — 规范链接
- `og_image_id` — OG 图片（关联 media）
- `cover_id` — 封面图（关联 media）
- `noindex` — 索引控制

**sites 表（已有基础字段）：**
- `name` — 站点名称
- `description` — 站点描述
- `logo` — 站点 Logo
- `metadata` — JSON 扩展

**entities 表（暂无 SEO 字段）：**
- `name` — 实体名称
- `summary` — 实体摘要
- `description` — 实体描述
- `metadata` — JSON 扩展

---

## 2. 目标架构

### 2.1 三层 SEO 继承模型

```
SeoMeta (独立表)
    ↓ 1:1 关联
Content / Entity
    ↓ 继承
Site (站点级默认)
    ↓ 继承
System (系统级安全默认)
```

### 2.2 继承优先级（最终冻结）

| 优先级 | 来源 | 说明 |
|---|---|---|
| 1 (最高) | SeoMeta 显式设置 | 独立 SeoMeta 记录 |
| 2 | Content/Entity 内置字段 | seo_title, seo_desc 等（legacy 过渡） |
| 3 | Site 级默认 | sites 级 SEO 配置 |
| 4 (最低) | System 安全默认 | 兜底值 |

---

## 3. SeoMeta 数据模型设计

### 3.1 表结构

```sql
seo_metas
├── id                  -- 主键
├── site_id             -- 站点隔离 (FK → sites.id, RESTRICT)
├── content_id          -- Content 关联 (nullable, FK → contents.id, CASCADE)
├── entity_id           -- Entity 关联 (nullable, FK → entities.id, CASCADE)
├── title               -- SEO 标题
├── description         -- SEO 描述
├── keywords            -- SEO 关键词 (JSON array)
├── canonical            -- 规范链接（绝对 URL）
├── og_title             -- Open Graph 标题
├── og_description       -- Open Graph 描述
├── og_image_path       -- OG 图片路径（相对路径，自动加域名）
├── og_type              -- Open Graph 类型 (website/article/product...)
├── twitter_card         -- Twitter Card 类型
├── noindex              -- 是否禁止索引 (boolean)
├── nofollow            -- 是否禁止跟踪链接 (boolean)
├── robots              -- Robots 指令 (JSON: index, follow, max-snippet...)
├── schema_type         -- Schema.org 类型覆盖
├── metadata            -- JSON 扩展
├── created_at
└── updated_at
```

### 3.2 绑定关系（最终冻结）

**三类 SeoMeta：**

| 类型 | content_id | entity_id | 说明 |
|---|---|---|---|
| Site-level | null | null | 站点级默认 SEO |
| Content-level | not null | null | 单篇内容 SEO |
| Entity-level | null | not null | 实体 SEO |

**约束（最终冻结）：**
- 不允许 content_id 和 entity_id 同时不为 null
- 允许两种合法组合：
  - 两者都为 null → Site-level
  - 只有 content_id 不为 null → Content-level
  - 只有 entity_id 不为 null → Entity-level

**SQLite CHECK 约束：**
```sql
CHECK (
    (content_id IS NULL AND entity_id IS NULL)
    OR
    (content_id IS NOT NULL AND entity_id IS NULL)
    OR
    (content_id IS NULL AND entity_id IS NOT NULL)
)
```

### 3.3 唯一约束（最终冻结）

```sql
-- Site-level: 每个站点只有一条
CREATE UNIQUE INDEX sites_seo_meta_unique
ON seo_metas(site_id)
WHERE content_id IS NULL AND entity_id IS NULL;

-- Content-level: 每个 Content 只有一条
CREATE UNIQUE INDEX content_seo_meta_unique
ON seo_metas(site_id, content_id)
WHERE content_id IS NOT NULL;

-- Entity-level: 每个 Entity 只有一条
CREATE UNIQUE INDEX entity_seo_meta_unique
ON seo_metas(site_id, entity_id)
WHERE entity_id IS NOT NULL;
```

**保证：**
- 一个 Site 只能有一个 Site-level SeoMeta
- 一个 Content 在一个 Site 只能有一个 SeoMeta
- 一个 Entity 在一个 Site 只能有一个 SeoMeta

**SQLite 实现：** partial unique index（5.4-H 已验证此模式可行）

### 3.4 索引

```sql
INDEX(site_id)
INDEX(content_id)
INDEX(entity_id)
INDEX(canonical)
```

---

## 4. 各字段继承链（最终冻结）

### 4.1 title

```
SeoMeta.title
    ↓ 如果为空
Content.seo_title (如果是 Content 类型，legacy 过渡)
    ↓ 如果为空
Content.title (如果是 Content 类型)
    ↓ 如果为空
Entity.name (如果是 Entity 类型)
    ↓ 如果为空
Site.name (站点级)
    ↓ 如果为空
System Default: "Site"
```

### 4.2 description

```
SeoMeta.description
    ↓ 如果为空
Content.seo_desc (如果是 Content 类型，legacy 过渡)
    ↓ 如果为空
Content.summary (如果是 Content 类型)
    ↓ 如果为空
Entity.summary (如果是 Entity 类型)
    ↓ 如果为空
Entity.description (如果是 Entity 类型)
    ↓ 如果为空
Site.description (站点级)
    ↓ 如果为空
System Default: ""
```

### 4.3 canonical（最终冻结）

**正式继承链：**
```
SeoMeta.canonical (绝对 URL)
    ↓ 如果为空
UrlResolverInterface::generateCanonical(当前资源)
    ↓ 基于当前 Site domain + 当前资源类型 + 当前资源 slug
```

**说明：**
- 不再有 Content.canonical 作为正式继承层
- 现有 `contents.canonical` 字段只是 **legacy 过渡字段**
- 迁移期：SeoMetaResolver 会读取 contents.canonical 作为迁移数据源
- 迁移完成后：contents.canonical 不再作为正式 SEO 来源
- 最终所有 canonical 都由 SeoMeta 显式设置或 UrlResolver 自动生成

### 4.4 og:image（最终冻结）

```
SeoMeta.og_image_path (相对路径)
    ↓ 如果为空
Content.og_image_id → media.path (如果是 Content 类型)
    ↓ 如果为空
Content.cover_id → media.path (如果是 Content 类型)
    ↓ 如果为空
Entity.metadata.og_image (如果是 Entity 类型)
    ↓ 如果为空
Site.logo (站点级)
    ↓ 如果为空
null (不输出 OG image)
```

**说明：**
- 不使用 `featured_image` 字段名
- 沿用现有 `cover_id` 作为 Content 级主图
- `Entity.metadata.og_image` 是 Entity 通用 metadata 扩展，不是业务专用字段
- 不新增任何业务专用字段

### 4.5 og:title

```
SeoMeta.og_title
    ↓ 如果为空
最终 title (同 4.1)
```

### 4.6 og:description

```
SeoMeta.og_description
    ↓ 如果为空
最终 description (同 4.2)
```

### 4.7 noindex / nofollow

```
SeoMeta.noindex / nofollow
    ↓ 如果为空
Content.noindex (如果是 Content 类型)
    ↓ 如果为空
false (默认索引)
```

---

## 5. Canonical Resolution Contract（最终冻结）

### 5.1 接口定义

```php
interface UrlResolverInterface
{
    /**
     * 生成指定实体的绝对 canonical URL
     *
     * @param string $type 资源类型: home / content / entity / page
     * @param array $params 资源参数 (slug, type 等)
     * @return string 绝对 URL (含 https://)
     */
    public function generateCanonical(string $type, array $params = []): string;
}
```

### 5.2 三种场景

**Home Page:**
```
https://{site_domain}/
```

**Content:**
```
https://{site_domain}/{content_type}/{content_slug}
```

**Entity:**
```
https://{site_domain}/{entity_type}/{entity_slug}
```

### 5.3 规则

| 规则 | 说明 |
|---|---|
| Protocol | 统一使用 HTTPS（生产环境） |
| Domain | 使用当前 Site 的 domain |
| Trailing slash | 统一不使用 trailing slash（首页除外） |
| Query string | canonical 中不包含 query string |
| 自定义 canonical 覆盖 | SeoMeta.canonical 优先级最高 |
| 跨站隔离 | 基于当前 Site domain，不会跨站 |

### 5.4 异常处理

| 场景 | 行为 |
|---|---|
| Site domain 为空 | 返回 `/` 相对路径（开发环境兼容） |
| 实体 slug 为空 | 返回 404 页面 canonical |
| 自定义 canonical 不是绝对 URL | Resolver 自动补全为绝对 URL |

### 5.5 与 ExampleUrlGenerator 的关系

**5.6 阶段：**
- SeoMetaResolver 依赖 `UrlResolverInterface` 接口
- 暂时仍由 ExampleUrlGenerator 实现该接口
- 但 SeoMeta 代码不直接依赖 ExampleUrlGenerator

**后续阶段（5.9-5.12）：**
- 创建通用 `UrlGenerator` 实现 `UrlResolverInterface`
- 替换 ExampleUrlGenerator
- 业务 URL 规则迁移到配置层

---

## 6. SeoMetaResolver 设计

### 6.1 职责

统一解析一个实体的最终 SEO 数据：

```
SeoMetaResolver::resolve($entity)
    ↓
1. 确定实体类型 (Site / Content / Entity)
2. 查找 SeoMeta 记录 (site_id + content_id 或 entity_id)
3. 按继承链合并各字段
4. 调用 UrlResolver 生成 canonical
5. 返回 SeoResult DTO
```

### 6.2 SeoResult DTO

```php
class SeoResult
{
    public string $title;
    public string $description;
    public array $keywords;
    public string $canonical;
    public string $ogTitle;
    public string $ogDescription;
    public ?string $ogImage;
    public string $ogType;
    public string $twitterCard;
    public bool $noindex;
    public bool $nofollow;
    public array $robots;
    public ?string $schemaType;
}
```

---

## 7. Multi-Site 隔离

### 7.1 数据隔离

- `seo_metas.site_id` FK → `sites.id` RESTRICT
- SiteScope 自动过滤
- SeoMeta 只在当前 Site 范围内查询

### 7.2 跨站禁止

- Site A 的 Content 不能读取 Site B 的 SeoMeta
- Site A 的 SEO 默认值不能影响 Site B
- Canonical 基于当前 Site domain
- OG image 自动加当前 Site domain

---

## 8. 迁移策略

### 8.1 数据迁移

**现有 contents 表 SEO 字段迁移：**

| 现有字段 | 目标 SeoMeta 字段 | 说明 |
|---|---|---|
| contents.seo_title | seo_metas.title | legacy 迁移 |
| contents.seo_desc | seo_metas.description | legacy 迁移 |
| contents.canonical | seo_metas.canonical | legacy 迁移（迁移后 SeoMeta 接管） |
| contents.og_image_id | seo_metas.og_image_path | 解析 media.path |
| contents.noindex | seo_metas.noindex | legacy 迁移 |

**迁移规则：**
1. 为每个有 SEO 字段的 Content 创建 SeoMeta 记录
2. site_id = contents.site_id
3. content_id = contents.id
4. entity_id = null
5. 保留 contents 原有字段（过渡期双读，后续阶段删除）

### 8.2 SQLite Rebuild 必要性

**结论：不需要 rebuild。**

原因：
- seo_metas 是新表，CREATE TABLE 即可
- 现有 contents 表不需要修改结构（保留原有 SEO 字段作为过渡）
- 后续阶段再考虑删除 contents.seo_* 字段

---

## 9. 旧系统依赖审计

### 9.1 必须在 5.6 解耦的

| 依赖 | 说明 |
|---|---|
| Content::metaTitle() | 迁移到 SeoMetaResolver |
| Content::metaDescription() | 迁移到 SeoMetaResolver |
| Content::canonicalUrl() | 迁移到 SeoMetaResolver |
| ExampleUrlGenerator 直接调用 | 替换为 UrlResolverInterface 接口依赖 |

### 9.2 留到 5.9-5.12 的

| 依赖 | 说明 |
|---|---|
| SchemaBuilder | GEO Schema 生成，5.7 处理 |
| LlmsBuilder | LLMs.txt 生成，5.8 处理 |
| Blade SEO 输出 | 模板层，5.10 处理 |
| SitemapBuilder | Sitemap 生成，5.11 处理 |
| config/seo.php | 配置文件，5.12 处理 |
| ExampleUrlGenerator 完整替换 | 通用 UrlGenerator，5.12 处理 |

---

## 10. 性能考虑

### 10.1 N+1 风险

**风险：** 列表页每页查询 N 个 Content，每个都查 SeoMeta → N+1

**解决方案：**
```php
// Eager loading
Content::with('seoMeta')->get();
```

### 10.2 Request-level Memoization

**策略：**
```php
// 同一请求内，同一个实体只解析一次
SeoMetaResolver::memo($entityType, $entityId, function() {
    return $this->resolve($entity);
});
```

### 10.3 与 PageCache 兼容

- SeoMeta 解析结果作为 PageCache 的一部分
- PageCache key 已包含 site_id（5.4-F 已完成）
- SeoMeta 变更时自动失效对应 PageCache

---

## 11. 测试矩阵规划

### 11.1 核心测试

| 测试 | 说明 |
|---|---|
| SeoMeta 创建 (Site-level) | 可以创建站点级 SEO |
| SeoMeta 创建 (Content-level) | 可以创建内容级 SEO |
| SeoMeta 创建 (Entity-level) | 可以创建实体级 SEO |
| SeoMeta 唯一约束 | 同一实体不能有两条 SeoMeta |
| SeoMeta Site 隔离 | Site A 不能读取 Site B 的 SeoMeta |
| 绑定约束 | content_id 和 entity_id 不能同时不为 null |

### 11.2 继承链测试

| 测试 | 说明 |
|---|---|
| title 继承 | SeoMeta → Content → Site → System |
| description 继承 | SeoMeta → Content → Site → System |
| canonical 继承 | SeoMeta → UrlResolver |
| og:image 继承 | SeoMeta → Content.og_image → Content.cover → Site.logo → null |
| noindex 继承 | SeoMeta → Content → false |

### 11.3 Canonical 测试

| 测试 | 说明 |
|---|---|
| Home canonical | 首页 canonical 正确 |
| Content canonical | 内容页 canonical 正确 |
| Entity canonical | 实体页 canonical 正确 |
| 自定义 canonical 覆盖 | 显式 canonical 优先级最高 |
| 跨站 canonical | Site A 和 Site B 的 canonical 不混淆 |
| Protocol 统一 | 统一 HTTPS |
| Query string 剥离 | canonical 不包含 query |

### 11.4 迁移测试

| 测试 | 说明 |
|---|---|
| 现有 SEO 字段迁移 | contents.seo_* 正确迁移到 seo_metas |
| 数据完整性 | 迁移前后 SEO 数据一致 |
| Migration rollback | migration → rollback → migrate 闭环 |

---

## 12. 风险清单

| 风险 | 等级 | 说明 | 缓解措施 |
|---|---|---|---|
| 现有 SEO 字段双写过渡 | 中 | contents.seo_* 和 seo_metas 同时存在 | 过渡期双读，后续阶段删除旧字段 |
| Canonical 接口抽象 | 中 | UrlResolverInterface 需要后续实现 | 5.6 只定义接口，现有实现临时适配 |
| N+1 查询 | 中 | 列表页 SEO 解析 N+1 | Eager loading + memoization |
| OG Image 关联复杂 | 低 | 多层继承 | 统一在 Resolver 中处理 |
| 与 PageCache 冲突 | 低 | 缓存失效 | SeoMeta observer 自动失效 |

---

## 13. 结论

**SeoMeta Architecture 设计可行。**

**核心决策（已冻结）：**
1. 独立 `seo_metas` 表，三种绑定类型（Site-level / Content-level / Entity-level）
2. 统一 `SeoMetaResolver` 解析继承链
3. `UrlResolverInterface` 接口抽象 canonical 生成，不直接依赖 ExampleUrlGenerator
4. Canonical 继承链：SeoMeta → UrlResolver（不再有 Content.canonical 作为正式层）
5. OG image 继承链：SeoMeta → Content.og_image_id → Content.cover_id → Entity.metadata.og_image → Site.logo → null
6. SiteScope 自动隔离
7. 现有 contents.seo_* 字段保留作为过渡，后续阶段删除

**下一步：** 5.6-B SeoMeta Schema / Migration
