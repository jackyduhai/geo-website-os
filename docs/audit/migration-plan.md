# Database Migration Plan

## 现状

### 当前数据库
- 类型：SQLite
- 文件：database/database.sqlite (573440 bytes)
- 表数量：23
- 总行数：224

### 23 张表清单
users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs,
categories, groups, contents, content_revisions, facts, media, banners, menus, page_blocks,
settings, redirects, sync_logs, audit_logs, inquiries

### Migration 统计
- 总数：**26 个**（非之前推测的28个）
- Laravel 基础：3个（users/cache/jobs）
- Schema 创建：7个（categories/groups/contents/facts/display_tables/system_tables/inquiries）
- 小修改：8个（add_lock_manual, add_attribution, add_icon, add_key_to_menus, add_nav_cta_text_setting, unlink_products_block_source, add_slot_to_contents, add_parent_key_to_menus）
- 数据/历史 migration：8个（seed_home_builder_blocks, seed_problems_differentiators, v06_ia_theme_blocks, normalize_terms, v07_home_cases_knowledge_groups, seed_mid_banner_block, backfill_workshop_text, retire_legacy_blocks_and_groups）

### 含业务数据的 Migration（8个）

| 文件 | 类型 | 数据操作 | 业务数据 | 风险 |
|------|------|----------|----------|------|
| seed_home_builder_blocks | Data | PageBlock::firstOrNew / where->update | 首页区块默认内容 | 高 |
| seed_problems_differentiators | Data | PageBlock::firstOrNew / where->update | 痛点/差异化区块 | 高 |
| v06_ia_theme_blocks | Schema+Data | Setting::updateOrCreate | 主题设置 | 中 |
| normalize_terms | Data | Content::all() 循环更新, Fact::all() 循环更新 | 术语标准化 | 中 |
| v07_home_cases_knowledge_groups | Schema+Data | PageBlock/Category/Group/Content 增删改 | 案例/知识分组 | 高 |
| seed_mid_banner_block | Data | PageBlock::firstOrNew / delete | 中部横幅 | 中 |
| backfill_workshop_text | Data | DB::table('page_blocks')->update | 工坊文案回填 | 中 |
| retire_legacy_blocks_and_groups | Data | PageBlock/Category/Group 删除/更新 | 废弃旧区块 | 中 |

### 其他含数据操作的 Migration（非 seed 前缀）
- add_nav_cta_text_setting: DB::table('settings')->insert/delete
- unlink_products_block_source: DB::table('page_blocks')->update
- add_slot_to_contents: DB::table('contents')->update, DB::table('categories')->update

## 目标 Migration 设计（仅设计，不执行）

### M1: create_sites_table
- 目的：Site 模型，预留多站点
- 字段：id, name, slug, domain, is_default, locale, timezone, status, timestamps
- 索引：UNIQUE(slug), UNIQUE(domain), INDEX(is_default)
- 数据：插入1条 default site
- Rollback：drop table
- 风险：低

### M2: add_site_id_to_core_tables
- 目的：所有核心业务表加 site_id
- 涉及表：contents, categories, groups, facts, media, banners, menus, page_blocks, settings, redirects, inquiries, audit_logs, sync_logs, content_revisions
- 字段：site_id BIGINT UNSIGNED, 默认1, FK→sites.id
- 数据迁移：已有数据全部 site_id=1
- Rollback：移除 site_id 列
- **SQLite 风险**：SQLite 不支持 ALTER TABLE ADD COLUMN ... FOREIGN KEY，也不支持 DROP COLUMN。需用 CREATE new → INSERT → DROP old → RENAME 模式重建表。
- MySQL/PostgreSQL：直接 ALTER TABLE，无问题
- 风险：**中**（SQLite 重建表复杂，需小心处理索引和约束）

### M3: create_entities_table
- 目的：Entity 单表模型
- 字段：id, site_id, type(ENUM: organization/product/service/location/topic/person), name, slug, description, status, metadata(JSON), timestamps
- 约束：UNIQUE(site_id, type, slug), FK site_id→sites.id
- 数据：v1.0 空表，由 ExampleSeeder 填充
- Rollback：drop table
- 风险：低

### M4: create_entity_relations_table
- 目的：实体关系图
- 字段：id, site_id, **from_entity_id**, **to_entity_id**, relation_type, metadata(JSON), timestamps
- 约束：FK from_entity_id→entities.id, FK to_entity_id→entities.id, FK site_id→sites.id, **UNIQUE(site_id, from_entity_id, to_entity_id, relation_type)**
- **禁止**：from_type, to_type 列
- 级联：ON DELETE CASCADE（entity 删除时关系自动删除）
- 数据：v1.0 空表
- Rollback：drop table
- 风险：低

### M5: create_seo_metas_table
- 目的：SEO Meta 多态关联
- 字段：id, site_id, seoable_type, seoable_id, title, description, canonical, robots, og_title, og_description, og_image, timestamps
- 约束：**UNIQUE(site_id, seoable_type, seoable_id)**, FK site_id→sites.id
- 关系：MorphOne（非 MorphMany）
- 数据：v1.0 空表
- Rollback：drop table
- 风险：低

### M6: add_role_to_users_table
- 目的：admin/editor 角色
- 字段：role VARCHAR(20) DEFAULT 'admin'
- 数据：已有用户 role='admin'
- Rollback：移除列
- 风险：低

### M7: create_backup_logs_table（可选，v1.0 可延后）
- 目的：备份记录
- 字段：id, site_id, type, path, size, checksum, status, created_at
- 风险：低

## Facts → Entity 映射设计

### config/facts.php 数据映射

| facts.php 数据 | Entity type | Entity 字段 | Relation | metadata |
|---------------|-------------|-------------|----------|----------|
| company (名称/地址/电话/成立时间) | organization | name, description | - | address, phone, founded_at, brand, tech_experience_years |
| productLines (产品线) | product | name, slug | - | line=true, sort |
| products (产品详情) | product | name, slug, description | belongs_to line (EntityRelation) | spec, is_core, icon, sort |
| scenes (应用场景) | service | name, slug, description | - | icon, sort |
| sceneCombo (场景推荐产品) | - | - | service produces product (EntityRelation) | - |
| salesRegions (销售区域) | organization.metadata | - | - | area_served (数组) |
| cooperation (合作方式) | content 或 organization.metadata | - | - | cooperation_flow |
| brandLanguage (品牌语言) | organization.metadata | - | - | slogan, values, usage_rules |
| CORE_PRODUCTS (核心产品) | product.metadata | - | - | is_core=true |

### 数据迁移策略
1. **不删除** config/facts.php 和 facts 表
2. ExampleSeeder 读取 config/facts.php，转换为 entities + entity_relations 插入
3. 现有 facts 表保留为通用事实库，不强制迁移到 entities
4. 升级时：Example站点数据保留，新增 entities 表为空，由管理员或 seeder 按需填充
5. 全新安装（非 --demo）：entities 表为空，config/facts.php 不加载（或改为示例）

## ExampleSeeder 设计

`database/seeders/ExampleSeeder.php`（Step 5 实施，当前仅设计）
- 调用：`php artisan geo:install --demo` 或 `php artisan db:seed --class=ExampleSeeder`
- 负责：
  1. 创建 default Site
  2. 从 config/facts.php 读取 → 创建 Organization Entity
  3. 创建 Product Entities（产品线 + 产品）
  4. 创建 Service Entities（场景）
  5. 创建 EntityRelations（produces/offers）
  6. 创建 Categories（知识/产品/场景）
  7. 创建 Contents（从 ContentSeeder）
  8. 创建 Settings（从 SettingSeeder）
  9. 创建 PageBlocks（从 StructureSeeder + home_blocks.php 默认值）
  10. 创建 Menus

## 现有数据处理决策

| 数据 | 决策 | 方式 |
|------|------|------|
| facts 表数据 | **保留** | 加 site_id，继续作为通用事实库 |
| config/facts.php | **废弃（默认安装）** | 仅 --demo 时由 ExampleSeeder 读取 |
| contents 表 | **保留** | 加 site_id |
| categories 表 | **保留** | 加 site_id |
| groups 表 | **保留** | 加 site_id |
| media 表 | **保留** | 加 site_id，文件不变 |
| settings 表 | **保留** | 加 site_id，业务文案从 config/copy.php 迁入 |
| page_blocks 表 | **保留** | 加 site_id，默认值从 config/home_blocks.php 迁入 |
| menus 表 | **保留** | 加 site_id |
| users 表 | **保留** | 加 role 字段 |
| inquiries/redirects/audit_logs/sync_logs | **保留** | 加 site_id |
| content_revisions | **保留** | 加 site_id |

## SQLite 风险与应对

| 操作 | SQLite 限制 | 应对策略 |
|------|------------|----------|
| ADD COLUMN with FK | 不支持 | 先 ADD COLUMN（无FK），应用层保证引用完整性；或重建表 |
| DROP COLUMN | 不支持 (3.35.0之前) | 重建表：CREATE new → INSERT → DROP old → RENAME |
| RENAME COLUMN | 不支持 (3.25.0之前) | 重建表 |
| 生产环境建议 | SQLite 适合 small site | 文档推荐生产用 MySQL/PostgreSQL，但 SQLite zero-config 作为默认 |

## Migration 执行顺序

1. M1 create_sites_table
2. M2 add_site_id_to_core_tables（最复杂，SQLite 需重建表）
3. M3 create_entities_table
4. M4 create_entity_relations_table
5. M5 create_seo_metas_table
6. M6 add_role_to_users_table
7. (可选) M7 create_backup_logs_table

## 测试方案

- SQLite：全新安装 → migrate → 验证表结构 → 验证数据完整性
- MySQL：全新安装 → migrate → 验证 FK 约束
- 升级测试：v0.x (当前) → migrate → 验证旧数据保留 + site_id=1
- Rollback 测试：migrate:rollback → 验证表结构恢复

## 最大风险

1. **M2 add_site_id 在 SQLite 下需重建 14 张表**：操作复杂，容易丢失索引或约束。必须在事务中执行，且有完整备份。
2. **8个 seed migration 数据剥离**：这些 migration 中 Schema 和 Data 混合，剥离时需保留 Schema 部分，将 Data 移入 ExampleSeeder。
3. **facts.php → entities 映射**：嵌套数组映射到 entities + entity_relations + metadata，需仔细设计，避免数据丢失。
