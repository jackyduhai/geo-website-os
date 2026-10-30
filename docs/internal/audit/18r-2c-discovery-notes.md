# 18R-2c Content Hub Lite — Discovery Notes（只读）

基线 commit 3e6000e。目标：Content ↔ Entity 关联 + Tag（TD-154），让文章成为"关于某产品/行业/场景"的知识资产，全链路经既有 Composition/Schema/GEO/Search，不建第二套。

## 1. Content 模型现状（app/Models/Content.php）
- SoftDeletes + BelongsToSite + Translatable（title/slug/summary/body/geo_* 按语言）。
- 共享列含 category_id, group_id, cover_id, og_image_id；**无 tag 列、无 entity 关联**。
- casts: geo_evidence/geo_faq/geo_key_facts/fact_refs = array。
- 全局 scope `not_slot`（slot 非空的叙事片段不进常规查询）。
- path() = /{category 层级}/{slug}；url() 经 PublicUrl::content()。
- schemaType(): page→WebPage，默认→Article。

## 2. Category 现状（app/Models/Category.php）
- 层级导航栏目（parent_id），type∈ list/product_list/page/external。
- 与 Content 一对多（category_id）。**语义=导航栏目，非扁平标签**，不复用为 tag。

## 3. Tag 现状
- 全库 grep 无 tags 表/模型/迁移。**需新建**。

## 4. Content↔Entity 存储选型
- 候选：a) 新 pivot content_entity；b) metadata JSON；c) morphToMany。
- **推荐 a：新表 `content_entity`**（id, site_id, content_id, entity_id, relation_type, timestamps；unique(content_id,entity_id,relation_type)）。
  理由：需反向查询"哪些文章讲产品 X"、需 typed relation_type（about/mention）、需进 GEO 图与 Schema about。JSON 弱查询、morph 对本系统（Content 已 site-scoped）无额外收益。

## 5. Tag 存储选型
- 候选：a) tags + content_tag pivot；b) 复用 Category；c) entity type=topic；d) metadata JSON。
- **推荐 a：新表 `tags`（site_id,name,slug）+ `content_tag` pivot**。
  理由：tag 是扁平多标签，与层级栏目语义正交；用 entity 承载会误触发 GEO/Schema/Registry（tag 不应成为独立 JSON-LD 实体）。

## 6. SchemaBuilder::article()（行 257-291）
- 目前输出 Article 基础字段，无 about/mentions。**扩展点**：在 return 前加 about[]（关联 Product/Service）+ mentions[]（其他实体），@id 指向 PublicUrl::content。

## 7. GEO 接入点
- GeoGraphBuilder::relations()（行 166）现仅遍历 entity_relations。**扩展**：把 content_entity 转成 content→entity 边（from=`content/article/{slug}`）。
- contents() 节点（行 228）可加 tags/related entity 摘要。

## 8. Search 接入点
- documentForContent()（行 170）输出 path/title/summary/body。**不新增表列**：把 tag 名 + 关联实体名追加进 body 文本，即可经既有 FTS 实现"按 tag/实体名聚合搜索"。

## 9. Admin 接入点
- ContentController::validateForm()（行 268）已有 category_id/group_id。**加** tag_ids[] + entity_ids[] + entity_type 映射，save 后 sync 透视表。
- 表单 resources/views/admin/contents/form.blade.php。

## 10. 前台渲染
- content.blade.php 文章页；加 tags 芯片行 + 相关实体卡片（核心 blade 直接渲染，模板包零代码）。

## 结论
最小新增 3 张 site-scoped 表（tags / content_tag / content_entity），全部幂等可回滚；复用既有 Composition / SchemaBuilder / GeoGraphBuilder / SearchIndexBuilder / PublicUrl。
