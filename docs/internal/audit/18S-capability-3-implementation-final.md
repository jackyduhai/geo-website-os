# P-STEP 18S Capability 3 — Entity Coverage Check · Implementation Final

- 阶段：P-STEP 18S / Product Capability 3（实体知识资产齐备度）
- 基线 Discovery：`dc3fc2a`；Cap2：`71f7ab8`
- 定位：回答「当前站点每个公开实体，按其类型应覆盖的关系 / 必备字段填全了多少」（知识资产填全程度），与 Cap2 GEO Health（输出运行时健康）严格分工。
- 结论：**PASS（P0 = 0）/ CLOSED**。

---

## 1. Changed files

| 文件 | 变更 | 说明 |
| --- | --- | --- |
| `config/entities.php` | 改 | 各类型 `relations`/`metadata` 用兼容新形式标 `required=>true`；旧字符串写法保留，归一为 recommended。 |
| `app/Support/Entities/EntityCapabilityRegistry.php` | 改 | 新增归一化访问器 `relationRequirements/requiredRelations/metadataRequirements/requiredMetadataKeys`；旧 `relations()/metadataKeys()` 输出保持扁平字符串（向后兼容）。 |
| `app/Services/Geo/EntityCoverageService.php` | 新增 | 只读聚合层：`report()` 产出单实体/类型/整体 covered/required 齐备比率 + 缺失项；只消费 Registry，不拥有规则。 |
| `app/Http/Controllers/Admin/GeoController.php` | +12 行 | 新增 `coverage(EntityCoverageService)` → `admin.geo.coverage`。 |
| `routes/admin.php` | +1 行 | `GET geo/coverage`（挂既有 `admin.auth+admin.site`）。 |
| `resources/views/admin/layout.blade.php` | +1 行 | 导航「搜索与 AI（SEO/GEO）」组在 GEO 健康后加「实体覆盖」。 |
| `resources/views/admin/geo/coverage.blade.php` | 新增 | 总览 + 按类型表 + 逐实体缺失项（去编辑链接，只读、无一键修复）。 |
| `tests/Feature/Admin/EntityCoverageTest.php` | 新增 | 11 用例（63 断言）。 |

## 2. Registry 扩展与向后兼容证明

- **契约扩展（最小、向后兼容）**：
  - 新形式：`'relations' => [['type'=>'service','required'=>true], 'organization']`；metadata 同理 `['challenge','solution', ['key'=>'media_id','required'=>true]]`。
  - 旧形式（纯目标类型字符串 / 纯键名字符串）仍被解析，**归一为 `required=false`（recommended）**，绝不意外升级为必备导致覆盖率突变。
- **旧方法行为不变**：`relations($type)` / `metadataKeys($type)` 仍返回扁平目标类型 / 键名字符串列表。
  - 证明：既有 `EntityCapabilityRegistryTest`（8 类型注册、case_study/download_asset 精确值、未知类型 fail-closed、flush）与 `EntityMetadataContractTest` 全部通过（13 passed）。
- **Coverage 专用新访问器**：`requiredRelations($type)` / `requiredMetadataKeys($type)` 返回必备子集；`relationRequirements/metadataRequirements` 返回全量 `{type|key, required}`。
- **必备标记（按 Discovery §2.2）**：
  - organization → produces→product（required）；person/location 建议。
  - product → uses→service（required）；organization/download_asset 建议。
  - service → uses→product（required）。
  - case_study → related_to→product（required）；metadata challenge/solution/result（required），industry/scenario 建议。
  - download_asset → 被 product offers（required）；metadata media_id（required），type/language/version 建议。
- **语义边界**：required/recommended 是 Coverage 专用维度；不改变 relations 现有"允许目标 / 声明式不强制"语义，不影响 SchemaBuilder/PublicIndex/PublicUrl/Sitemap/GeoGraph 等既有 Registry 消费方（全量回归 1219 通过即证明）。

## 3. Architecture compliance

- **唯一事实源**：应覆盖项全部来自 `EntityCapabilityRegistry`。`EntityCoverageService` 内**无任何静态 `$requiredRelations/$requiredMetadata`**（grep 确认：必备集合仅来自 `requiredRelations()/requiredMetadataKeys()`，gaps 里的中文是 UI 文案不是业务规则）。
- **数据流（纯只读）**：PublicIndex（site+locale 公开实体）→ EntityRelation（当前站，两端均公开才计入）→ 比对 Registry 应覆盖项 → covered/required + 缺失项 → Admin View。
- **性能 / 无 N+1**：一次 `PublicIndex::entityQuery()->get()` + 一次 `EntityRelation::where('site_id')->get()`，内存聚合 adjacency；不千级逐条查关系，无新建缓存系统。
- **检测器非修复器**：渲染不写库（`test_rendering_is_read_only` 断言前后 entity/relation 计数不变）；无自动补关系/补字段/一键修复。
- **状态模型**：整体 PASS（全齐备）/ WARNING（有缺口）/ N/A（空站或无应覆盖项）；**无 0–100 加权健康分**，只给"已覆盖/应覆盖"齐备比率。
- **Blank 空站**：0 公开实体 → overall N/A、不报错、不报假 Fail。

## 4. Coverage 实测（seeded demo 站，真实 DB 真值）

- 总览：12 公开实体，必备 12/12，齐备率 100%，整体 **PASS**。
- 按类型：组织 1（1/1）、产品 8（8/8）、服务/场景 3（3/3），未填全实体均 0。
- 逐实体缺失项：「所有公开实体的必备关系与必备字段均已填全。」
- 单实体缺失（单测覆盖）：孤立产品缺 uses→service → 列出「缺少关系 → 服务/场景」+ 去编辑链接；case_study 缺 product 关系 + challenge/solution/result → 两类缺口均列出。

## 5. HTTP / Browser 证据

- HTTP（fresh sqlite `geo4.sqlite` :8132）：`/` 200 ld+json×3、`/products/` 200×4、`/solutions/` 200×4、`/sitemap.xml` `/llms.txt` `/geo.json` 全 200——前台无回归。
- 浏览器：admin 登录 →「搜索与 AI（SEO/GEO）→ 实体覆盖」(`/admin/geo/coverage`) 200，无 PHP/SQL 错误；总览/按类型表/逐实体缺失项渲染正常；导航高亮正确；整体徽标、齐备率、缺口 badge 均用既有 `.badge/.tbl/.stat/.line-list`，无 inline style/新 token。
- 缺口感知：单测 `test_single_entity_missing_required_relation_is_listed` 与 `test_case_study_missing_required_metadata_listed` 验证缺口文案、kind（relation/metadata）、去编辑 URL。

## 6. Site / Locale / Security

- **Site/Locale 隔离**：全部经 `SiteContext::currentSite()` + `LocaleContext::current()`（PublicIndex 公开口径）；不读 `request('site_id')`；多 locale 按当前 locale 公开行计算，不跨 translation_group 聚合；`test_site_isolation`（A 站实体不计入 B 站 blank）通过。
- **AuthZ**：路由挂 `admin.auth+admin.site`；guest 访问 302 跳登录（`test_guest_is_redirected`）；无公开端点；视图用户内容 `{{ }}` 转义。
- **只读**：见 §3。

## 7. Full Regression

- `php artisan test`（前台输出 `D:\Temp\geo3_full.log`）：**1219 passed / 6515 assertions / 0 failed / 0 skipped**（约 583s）。
- 对比 Cap2 基线 1208/6450：净增 11 用例 / 65 断言，数字只增不减，无回退。
- Focused：`EntityCoverageTest` 11 passed / 63 assertions（`D:\Temp\geo3_focused.log`）。

## 8. Git status

- 提交前工作树仅含：新增 EntityCoverageService / coverage.blade / EntityCoverageTest，改动 Registry / config/entities / GeoController / routes / layout。
- 未 push、未配 remote、未动 rc1（`965d63c`）；19A 保持 HOLD。

## 9. TD impact

- **TD-156**（CaseStudy Coverage Completeness Rule）：本能力 case_study 必备 metadata challenge/solution/result 与发布门禁口径一致，复用 Registry 声明，不重复定义规则。
- **TD-158**（content_entity/content_tag orphan pivot）：Coverage 经"两端公开"口径自然排除孤儿行，不修复。
- **TD-162**（P3，Wizard 产品未入系列分组）：保持不动。
- 本能力未新增 TD 编号。

## 10. Known limitations

- Coverage 只覆盖 Entity 类型必备关系 + 必备 metadata；Content 侧必备字段不纳入本能力（属内容门禁 ContentGate 职责）。
- required 关系只判"是否存在连到目标类型的公开边"，不强制具体 relation_type 方向（方向语义由既有 Catalog::relationMap 承载，Coverage 不复制）。
- recommended（建议）项只在 config 声明，Dashboard 当前只展示必备齐备率，不另算建议完成率（按 Discovery 口径避免与 Health 加权分混淆）。

## 11. 合规清单（红线核对）

- New Entity = **0**
- New Table = **0**
- New Column = **0**
- New Migration = **0**
- New Renderer = **0**
- Second SEO·GEO Pipeline = **No**
- Second Coverage 事实体系 = **No**（应覆盖项唯一来自 Registry）
- Second Relation System = **No**
- 0–100 综合 / 加权健康分 = **无**
- 写库 / 持久化 / cache 表 = **No**
- Business Hardcoding = **0**（扫描 Demo Tenant A/Demo Tenant A/固定品牌·产品名/行业/URL 无命中）

**Cap3 PASS（P0=0）/ CLOSED。** 按要求 STOP，不进入后续能力，不碰 19A/remote/push/release/rc1。
