# Phase 5.6-A: SeoMeta Architecture Design Audit

## 1. 现状分析

### 1.1 现有 SEO 字段分布

**contents 表（已有 SEO 字段）：**
- `seo_title` — SEO 标题
- `seo_desc` — SEO 描述
- `canonical` — 规范链接
- `og_image_id` — OG 图片（关联 media）
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

### 1.2 现有 SEO 逻辑

**Content Model 中的 SEO 方法：**
- `metaTitle()` — seo_title 为空时回退到 title
- `metaDescription()` — seo_desc 为空时回退到 summary
- `canonicalUrl()` — canonical 为空时回退到 url()

**问题：**
1. SEO 逻辑分散在 Content Model 中，没有统一抽象
2. Entity 没有 SEO 字段，无法为 Entity 生成独立 SEO
3. Site 级 SEO 默认值没有结构化
4. Canonical 依赖 ExampleUrlGenerator（业务绑定）
5. 没有统一的 SEO 继承链

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

### 2.2 继承优先级

| 优先级 | 来源 | 说明 |
|---|---|---|
| 1 (最高) | SeoMeta 显式设置 | 独立 SeoMeta 记录 |
| 2 | Content/Entity 内置字段 | seo_title, seo_desc 等 |
| 3 | Site 级默认 | sites.seo_* |
| 4 (最低) | System 安全默认 | 兜底值 |

---

## 3. SeoMeta 数据模型设计

### 3.1 表结构

```sql
seo_metas
├── id                  -- 主键
├── site_id             -- 站点隔离 (FK → sites.id, RESTRICT)
├── entity_type         -- 关联实体类型 (content / entity / site)
├── entity_id           -- 关联实体 ID
├── title               -- SEO 标题
├── description         -- SEO 描述
├── keywords            -- SEO 关键词 (JSON array)
├── canonical            -- 规范链接
├── og_title            -- Open Graph 标题
├── og_description      -- Open Graph 描述
├── og_image_path       -- OG 图片路径
├── og_type             -- Open Graph 类型 (website/article/product...)
├── twitter_card        -- Twitter Card 类型
├── noindex             -- 是否禁止索引 (boolean)
├── nofollow            -- 是否禁止跟踪链接 (boolean)
├── robots              -- Robots 指令 (JSON: index, follow, max-snippet...)
├── schema_type          -- Schema.org 类型覆盖
├── metadata            -- JSON 扩展
├── created_at
└── updated_at
```

### 3.2 唯一约束

```sql
UNIQUE(site_id, entity_type, entity_id)
```

- 每个实体（Content/Entity/Site）只能有一条 SeoMeta 记录
- 跨站可以有相同 entity_type + entity_id（但实际不会出现，因为 entity_id 本身就有 site_id）

### 3.3 索引

```sql
INDEX(site_id)
INDEX(entity_type, entity_id)
INDEX(canonical)
```

---

## 4. SeoMetaResolver 设计

### 4.1 职责

统一解析一个实体的最终 SEO 数据：

```
SeoMetaResolver::resolve($entity)
    ↓
1. 查找 SeoMeta 记录 (site_id + entity_type + entity_id)
2. 按优先级合并：
   - SeoMeta 显式值
   - Content/Entity 内置 SEO 字段
   - Site 级默认 SEO
   - System 安全默认值
3. 返回 SeoResult DTO
```

### 4.2 SeoResult DTO

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

### 4.3 Canonical 解析规则

```
canonical = SeoMeta.canonical
    ↓ 如果为空
canonical = Content.canonical (如果有)
    ↓ 如果为空
canonical = UrlGenerator::currentUrl() (基于 Site domain + entity slug)
```

**关键约束：**
- 不依赖 ExampleUrlGenerator
- 基于当前 Site domain 生成
- 跨站 canonical 不会混淆

---

## 5. OG Image 继承链

```
SeoMeta.og_image_path
    ↓ 如果为空
Content.og_image_id → media.path
    ↓ 如果为空
Content.cover_id → media.path
    ↓ 如果为空
Site.logo
    ↓ 如果为空
null (不输出 OG image)
```

---

## 6. Multi-Site 隔离

### 6.1 数据隔离

- `seo_metas.site_id` FK → `sites.id` RESTRICT
- SiteScope 自动过滤
- SeoMeta 只在当前 Site 范围内查询

### 6.2 跨站禁止

- Site A 的 Content 不能读取 Site B 的 SeoMeta
- Site A 的 SEO 默认值不能影响 Site B
- Canonical 基于当前 Site domain

---

## 7. 迁移策略

### 7.1 数据迁移

**现有 contents 表 SEO 字段迁移：**

| 现有字段 | 目标 SeoMeta 字段 |
|---|---|
| contents.seo_title | seo_metas.title |
| contents.seo_desc | seo_metas.description |
| contents.canonical | seo_metas.canonical |
| contents.og_image_id | seo_metas.og_image_path (解析 media.path) |
| contents.noindex | seo_metas.noindex |

**迁移规则：**
1. 为每个有 SEO 字段的 Content 创建 SeoMeta 记录
2. site_id = contents.site_id
3. entity_type = 'content'
4. entity_id = contents.id
5. 保留 contents 原有字段（过渡期双写，后续阶段删除）

### 7.2 SQLite Rebuild 必要性

**结论：不需要 rebuild。**

原因：
- seo_metas 是新表，CREATE TABLE 即可
- 现有 contents 表不需要修改结构（保留原有 SEO 字段作为过渡）
- 后续阶段再考虑删除 contents.seo_* 字段

---

## 8. 旧系统依赖审计

### 8.1 必须在 5.6 解耦的

| 依赖 | 说明 |
|---|---|
| Content::metaTitle() | 迁移到 SeoMetaResolver |
| Content::metaDescription() | 迁移到 SeoMetaResolver |
| Content::canonicalUrl() | 迁移到 SeoMetaResolver |
| ExampleUrlGenerator (canonical 部分) | 替换为通用 UrlGenerator |

### 8.2 留到 5.9-5.12 的

| 依赖 | 说明 |
|---|---|
| SchemaBuilder | GEO Schema 生成，5.7 处理 |
| LlmsBuilder | LLMs.txt 生成，5.8 处理 |
| Blade SEO 输出 | 模板层，5.10 处理 |
| SitemapBuilder | Sitemap 生成，5.11 处理 |
| config/seo.php | 配置文件，5.12 处理 |

---

## 9. 性能考虑

### 9.1 N+1 风险

**风险：** 列表页每页查询 N 个 Content，每个都查 SeoMeta → N+1

**解决方案：**
```php
// Eager loading
Content::with('seoMeta')->get();
```

### 9.2 Request-level Memoization

**策略：**
```php
// 同一请求内，同一个实体只解析一次
SeoMetaResolver::memo($entityType, $entityId, function() {
    return $this->resolve($entity);
});
```

### 9.3 与 PageCache 兼容

- SeoMeta 解析结果作为 PageCache 的一部分
- PageCache key 已包含 site_id（5.4-F 已完成）
- SeoMeta 变更时自动失效对应 PageCache

---

## 10. 测试矩阵规划

### 10.1 核心测试

| 测试 | 说明 |
|---|---|
| SeoMeta 创建 | 可以为 Content/Entity/Site 创建 SeoMeta |
| SeoMeta 唯一约束 | 同一实体不能有两条 SeoMeta |
| SeoMeta Site 隔离 | Site A 不能读取 Site B 的 SeoMeta |
| 继承链测试 | SeoMeta → Content → Site → System |
| Canonical 生成 | 基于 Site domain 生成正确 canonical |
| OG Image 继承 | 按优先级解析 OG image |
| Noindex 控制 | noindex=true 时正确输出 robots |
| 跨站 canonical | Site A 和 Site B 的 canonical 不混淆 |

### 10.2 Resolver 测试

| 测试 | 说明 |
|---|---|
| Resolve Content | Content SEO 正确解析 |
| Resolve Entity | Entity SEO 正确解析 |
| Resolve Site | Site 级默认 SEO 正确解析 |
| Fallback | 缺少字段时正确回退 |
| Override | 显式设置覆盖默认值 |
| Memoization | 同一实体只解析一次 |

### 10.3 迁移测试

| 测试 | 说明 |
|---|---||
| 现有 SEO 字段迁移 | contents.seo_* 正确迁移到 seo_metas |
| 数据完整性 | 迁移前后 SEO 数据一致 |
| Migration rollback | migration → rollback → migrate 闭环 |

---

## 11. 风险清单

| 风险 | 等级 | 说明 | 缓解措施 |
|---|---|---|---|
| 现有 SEO 字段双写过渡 | 中 | contents.seo_* 和 seo_metas 同时存在 | 过渡期双读，后续阶段删除旧字段 |
| Canonical 依赖旧 UrlGenerator | 高 | ExampleUrlGenerator 是业务绑定 | 5.6 内创建通用 UrlGenerator |
| N+1 查询 | 中 | 列表页 SEO 解析 N+1 | Eager loading + memoization |
| OG Image 关联复杂 | 低 | 多层继承 | 统一在 Resolver 中处理 |
| 与 PageCache 冲突 | 低 | 缓存失效 | SeoMeta observer 自动失效 |

---

## 12. 结论

**SeoMeta Architecture 设计可行。**

**核心决策：**
1. 独立 `seo_metas` 表，1:1 关联 Content/Entity/Site
2. 统一 `SeoMetaResolver` 解析继承链
3. Canonical 不依赖业务 UrlGenerator
4. SiteScope 自动隔离
5. 现有 contents.seo_* 字段保留作为过渡，后续阶段删除

**下一步：** 5.6-B Entity Schema / Migration
