# Entity Architecture Matrix — Gate 1 Step 5.5-A

## 1. 架构冻结：6 种 Entity Type

| Type | 说明 | Example映射 |
|---|---|---|
| `organization` | 企业/机构 | Example Company |
| `product` | 产品 | 6个核心产品 + 产品线 |
| `service` | 服务/场景 | 6个业务场景 |
| `person` | 人物 | （暂未使用，预留） |
| `location` | 地点 | Sample City/Sample Province销售区域 |
| `topic` | 知识主题 | 知识库栏目 |

**禁止新增类型**：solution / place / brand（除非正式架构变更）。

### Location Entity 边界

`location` Entity **仅用于**具有独立语义、可独立引用/关联的地点实体（如：工厂地址、门店位置、合作伙伴地点）。

**明确排除**：
- ❌ 企业销售/服务覆盖区域（salesRegions）→ 存入 `organization.metadata.area_served`
- ❌ 营销投放区域 → 存入 `organization.metadata.marketing_regions`
- ❌ 业务覆盖省份/城市列表 → 存入 organization metadata

**判断标准**：
- 该地点是否有独立的页面/详情？→ 是 → 可以是 location
- 该地点是否需要被其他 Entity 引用建立关系？→ 是 → 可以是 location
- 该地点是否只是描述"我们覆盖哪些区域"？→ 否 → 存入 organization.metadata

---

## 2. entities 表设计

### 字段

| Field | Type | Constraint | 说明 |
|---|---|---|---|
| `id` | bigIncrements | PK | |
| `site_id` | bigInteger | FK → sites.id, NOT NULL | 所属站点 |
| `type` | string(32) | NOT NULL | organization/product/service/person/location/topic |
| `slug` | string(128) | NOT NULL | URL友好标识 |
| `name` | string(255) | NOT NULL | 显示名称 |
| `summary` | text | nullable | 短摘要 |
| `description` | longText | nullable | 详细描述 |
| `status` | string(16) | NOT NULL, default='draft' | draft/published/archived |
| `metadata` | json | nullable | 扩展数据 |
| `sort_order` | integer | NOT NULL, default=0 | 排序 |
| `published_at` | timestamp | nullable | 发布时间 |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

### 唯一约束

```
UNIQUE(site_id, type, slug)
```

### 索引

- `idx_entities_site_type` (site_id, type)
- `idx_entities_status` (status)
- `idx_entities_slug` (slug)

---

## 3. entity_relations 表设计

### 字段

| Field | Type | Constraint | 说明 |
|---|---|---|---|
| `id` | bigIncrements | PK | |
| `site_id` | bigInteger | FK → sites.id, NOT NULL | 所属站点 |
| `from_entity_id` | bigInteger | FK → entities.id, NOT NULL | 源实体 |
| `to_entity_id` | bigInteger | FK → entities.id, NOT NULL | 目标实体 |
| `relation_type` | string(32) | NOT NULL | produces/offers/uses/located_in/related_to... |
| `metadata` | json | nullable | 关系属性 |
| `sort_order` | integer | NOT NULL, default=0 | |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

### 唯一约束

```
UNIQUE(site_id, from_entity_id, to_entity_id, relation_type)
```

### 禁止字段

- ❌ `from_type` / `to_type`
- ❌ `from_slug` / `to_slug`
- ❌ `from_entity_type` / `to_entity_type`

关系两端永远通过 `entities.id` 解析。

### 跨站保护

- `from_entity.site_id == relation.site_id`
- `to_entity.site_id == relation.site_id`

---

## 4. 现有业务实体梳理

### 4.1 Facts 数据源（config/facts.php）

| Facts 方法 | 数据内容 | Entity 映射 |
|---|---|---|
| `company()` | 公司名称/地址/电话/简介 | organization |
| `brandLanguage()` | 品牌文案 | organization.metadata |
| `productLines()` | 产品线列表 | product（line属性） |
| `products()` | 产品列表 | product |
| `scenes()` | 场景列表 | service |
| `sceneCombo()` | 场景-产品组合 | entity_relations (uses) |
| `workshops()` | 生产车间 | organization.metadata |
| `salesRegions()` | 销售区域 | organization.metadata.area_served（**不创建独立 Location Entity**） |
| `certifications()` | 资质认证 | organization.metadata |
| `cooperation()` | 合作信息 | organization.metadata / Content |
| `cases()` | 案例 | Content |

### 4.2 现有数据库表

| Table | 现有用途 | Entity 关系 |
|---|---|---|
| `contents` | 文章/页面 | 关联 Entity（多态） |
| `categories` | 栏目分类 | topic / Category |
| `groups` | 子栏目 | topic 子分类 |
| `facts` | 业务事实配置 | 待迁移到 Entity |
| `settings` | 站点设置 | organization.metadata / Setting |
| `page_blocks` | 页面区块 | 关联 Entity |
| `menus` | 菜单 | 关联 Entity URL |
| `redirects` | 重定向 | Entity URL 历史 |

---

## 5. 数据迁移映射

### 5.1 Facts → Entity 映射

| Facts 源 | Entity Type | 映射方式 |
|---|---|---|
| `company.name` | organization.name | 直接映射 |
| `company.slug` | organization.slug | 直接映射 |
| `company.address` | organization.metadata.address | 存入 metadata |
| `company.phone` | organization.metadata.phone | 存入 metadata |
| `products[].slug` | product.slug | 直接映射 |
| `products[].name` | product.name | 直接映射 |
| `products[].line` | product.metadata.line | 存入 metadata |
| `products[].features` | product.metadata.features | 存入 metadata |
| `products[].applications` | product.metadata.applications | 存入 metadata |
| `scenes[].slug` | service.slug | 直接映射 |
| `scenes[].name` | service.name | 直接映射 |
| `scene.combo[]` | entity_relations | service → product (uses) |
| `salesRegions[]` | organization.metadata.area_served | 存入 organization metadata（不创建独立 Location Entity） |

### 5.2 不迁移的内容

| 内容 | 目标位置 | 原因 |
|---|---|---|
| 系统配置（SEO/模板等） | settings | 非业务实体 |
| 页面区块配置 | page_blocks | 展示层配置 |
| 菜单结构 | menus | 导航配置 |
| 合规规则 | settings / config | 系统级规则 |

---

## 6. 影响面分析

### 6.1 需要修改的组件

| 组件 | 影响程度 | 说明 |
|---|---|---|
| `Facts.php` | 🔴 高 | 逐步废弃，改为 Entity 查询 |
| `SchemaBuilder` | 🔴 高 | 从 Facts 改为 Entity |
| `LlmsBuilder` | 🔴 高 | 从 Facts 改为 Entity |
| `SitemapBuilder` | 🔴 高 | 从 Facts 改为 Entity |
| Controllers | 🟡 中 | 产品/场景页改为 Entity 查询 |
| Blade 模板 | 🟡 中 | 从 `Facts::product()` 改为 `$entity` |
| Routes | 🟢 低 | URL 结构不变，Resolver 内部改 |

### 6.2 不受影响的组件

- ✅ 用户认证
- ✅ 后台管理框架
- ✅ 页面缓存机制（已 site-aware）
- ✅ 文件上传
- ✅ 表单提交

---

## 7. 迁移策略

### 阶段拆分

| Phase | 内容 | 风险 |
|---|---|---|
| 5.5-B | entities + entity_relations 表创建 | 低 |
| 5.5-C | Entity Model + Site Isolation | 中 |
| 5.5-D | EntityRelation Model + 跨站保护 | 中 |
| 5.5-E | Entity Metadata / Slug / Lifecycle | 低 |
| 5.5-F | Facts → Entity 数据迁移 | 高 |
| 5.5-G | Entity Query / Resolver | 高 |
| 5.5-H | GEO Integration Boundary | 高 |
| 5.5-I | Multi-Site Isolation & Security | 中 |
| 5.5-J | Generic Deployment Test | 高 |

---

## 8. 风险评估

| 风险 | 影响 | 缓解措施 |
|---|---|---|
| Facts → Entity 数据丢失 | 🔴 高 | 完整备份 + 逐字段映射验证 |
| GEO Builder 依赖 Facts | 🔴 高 | 双轨过渡，逐步替换 |
| URL 结构变化 | 🟡 中 | 保持现有 URL，Resolver 内部改 |
| 性能下降 | 🟡 中 | Eager loading + 缓存 |
| 测试覆盖不足 | 🟡 中 | 新增 Entity 专项测试 |

---

## 9. 测试方案

### 9.1 单元测试

- [ ] Entity CRUD
- [ ] Entity Slug 唯一性
- [ ] Entity Status 转换
- [ ] Entity Metadata JSON cast
- [ ] EntityRelation 创建/删除
- [ ] EntityRelation 跨站保护

### 9.2 Feature 测试

- [ ] Entity Site Isolation
- [ ] EntityRelation Site Isolation
- [ ] Entity → Content 关联
- [ ] Entity → SeoMeta 关联（后续）
- [ ] GEO Schema 生成（后续）

### 9.3 集成测试

- [ ] Facts → Entity 数据迁移验证
- [ ] 产品页正常渲染
- [ ] 场景页正常渲染
- [ ] Sitemap 正常生成
- [ ] llms.txt 正常生成

---

## 10. STOP 条件

本阶段（5.5-A）仅输出设计文档，不修改任何 Runtime 代码。

**下一步**：等待授权进入 5.5-B（Entity Schema / Migration）。
