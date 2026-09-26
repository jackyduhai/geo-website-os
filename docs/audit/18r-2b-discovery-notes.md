# 18R-2b Discovery Notes（只读，编码前）

基线 commit `0269426`（18R-2a）。目标：CaseStudy 公开闭环 + DownloadAsset 最小闭环。零新表。

## 1. 前台路由注册模式（routes/web.php）
- 所有前台路由在闭包 `$registerFrontend(locale, nameSuffix)` 内定义，注册两次：
  1. `Route::prefix('en')->middleware('locale:en')`（nameSuffix=`.en`）
  2. `Route::middleware('locale:zh-CN')`（nameSuffix=`''`）
- 列表型：`Route::get('products{slash?}', ...)->where('slash','/?')->defaults('_slash',1)`
- 详情型：`Route::get('products/{param}{slash?}', ...)->where('param','[a-z0-9-]+')->where('slash','/?')`
- 2b 新增：`cases{slash?}` → CaseController@index；`cases/{slug}{slash?}` → CaseController@show。
  放在 solutions 段之后、about 之前（保持显式路由先于 `/{path}` catch-all）。

## 2. ProductController 模式（app/Http/Controllers/Site/ProductController.php）
- index：`SystemPageRenderContext::resolve('products')` + resource，CompositionRenderer::render。
- show($slug)：`Entity::published()->forLocale()->ofType(PRODUCT)->where('slug',...)->first()`，abort 404 →
  `EntityRenderContext::forEntity($entity)`（null→404）→ CompositionRenderer::render。
- 列表（系列页）用 `ListingRenderContext('product_line', $resource)`，resource.system_block='sys_products'。

## 3. RenderContext 管线（CompositionRenderer）
- 遍历 template slots，逐块 `BlockRegistry::render($block, $slotContext)` → `site/blocks/{type}.blade.php`。
- BlockRegistry::render 注入：block/blk/data/semanticAttrs（=SectionSemantic::attributes）+ context。
- VirtualBlock(type, content) = 非持久化合成块（系统块标准做法）。
- 列表页用 ListingRenderContext（template='listing'，main 槽 = resource.system_block）。
- 详情页需要 template='detail'（header/main/related 槽）。

## 4. Block 注册（config/blocks.php + BlockRegistry）
- system block 声明：`'system'=>true,'data_source'=>false,'allowed'=>['detail/main']` 等。
- BlockRegistry::resolveData 的 match 仅处理 product_grid/service_grid/content_grid 数据源；
  case_list/case_detail/download_panel 为系统块（data_source=false），数据由 RenderContext 经
  viewContext 注入（同 entity_relations 读 $scenes/$related 模式），block 视图不查库。
- SectionSemantic DEFAULTS 需新增三条受控映射（全部落在受控词表）：
  - case_list → section=case, purpose=comparison, entity=Case
  - case_detail → section=case, purpose=education, entity=Case
  - download_panel → section=product, purpose=conversion, entity=Product, conversion=download
- 视图根节点统一 `{!! $semanticAttrs ?? '' !!}`。

## 5. PublicUrl（app/Support/PublicUrl.php）
- entity() 顶部已有 `if (!Registry::isPublic($type)) return null;`。
- match 中 case_study 当前 fall through→null（TODO 2b 注释）。
- 2b：新增 `caseStudy($slug)` → `/cases/{slug}`（详情型，无尾斜杠，同 product）；
  match 加 `Entity::TYPE_CASE_STUDY => self::caseStudy($entity->slug)`。
- download_asset public=false 永远 null（已锁）。

## 6. SearchIndexBuilder（documentForEntity）
- 已有 isSearchable gate。path 硬编码：`$e->type===SERVICE ? '/solutions/'.$slug.'/' : '/products/'.$slug`。
- 2b：改 match 加 case_study → `/cases/'.$e->slug`。接通 PublicUrl 后 case_study 自动入索引。

## 7. SitemapBuilder
- 遍历 Catalog products/scenes，priority 0.7（产品）/0.8（场景详情）/0.9（目录）。
- 2b：新增 cases 段——`PublicIndex::entityQuery()->forLocale()->ofType(case_study)`，
  过滤 indexableEntitySlugs，add `/cases/{slug}` priority 0.7；另加 `/cases/` 列表 0.8。
- download_asset sitemap=false 永不收录（已锁）。

## 8. LlmsBuilder
- build() 中文 / buildEnglish() 英文；现有注释「不输出已取消的 /cases/」需更新。
- 2b：新增「客户案例」段（中/英），遍历 published case_study（PublicIndex），
  列 `- [name](/cases/slug)：industry - summary`。

## 9. EntityRenderContext（download_panel 注入点）
- fixedMainBlocks() product 分支 = [entity_steps, entity_relations(part=scenes), entity_specifications]。
- 2b：product 分支追加 `new VirtualBlock('download_panel', [])`（无下载资料时视图自空渲染）。
- viewContext() product 分支追加 `'downloads' => $this->productDownloads()`：
  查 EntityRelation from=product, type=offers, to=download_asset，取 to entity + metadata。
- case_study 详情不走 EntityRenderContext（其逻辑产品/场景专用）；2b 新建轻量
  CaseRenderContext（实现 RenderContext，template='detail'，块仍走 BlockRegistry，非第二套渲染器）。

## 10. Admin 表单（form.blade.php + EntityController）
- form.blade.php 已有 `$isProduct/$isOrg/$isLocation/$isService` 分支模式。
- 2b：加 `$isCaseStudy/$isDownloadAsset`；metadata 字段（industry/scenario/challenge/solution/result；
  media_id/type/language/version）。validateData/applyMetadata 加对应分支。
- "在前台查看" 链接加 case_study → /cases/{slug}。
- 关系（product多 / organization客户 role=customer / scenario）由现有 EntityRelationController 管理，
  2b 不改其（确认 case_study 出现在类型下拉——已由 Registry::labels 自动提供）。

## 11. SafeUrl
- app/Support/SafeUrl.php 存在；渲染用 `SafeUrl::sanitize($url,'#')`。下载链接经 Media::url() 后包裹。

## 12. Content / Category / Tag 现状（2c 备案，2b 不实施）
- Content 模型存在（article/page，SoftDeletes+Translatable，not_slot 全局作用域），有 category_id/group_id。
- Category 存在（type: list/product_list/page/external，parent_id 树）。
- **无 Tag 模型**（app/Models 下无 Tag）。Content 与 Entity 之间无直接关系表。
- 2c Content Hub Lite = Category/Tag/Content↔Entity 关系，独立 Gate。

## 约束复核
- 零新表/migration；blocks 一律走 BlockRegistry→CompositionRenderer；download_asset 无独立页/无 JSON-LD/
  不进 sitemap/search；case_study metadata 禁 CRM 字段；locale 双注册；新 block 根节点 semanticAttrs。
