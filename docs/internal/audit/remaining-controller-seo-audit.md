# STEP 08 — Remaining Controller / Page SEO Migration Audit

> 结论：七个 Controller 均为**配置驱动固定 IA 页**，不强行改造为 Content / Entity；
> 页面级 SEO 已经由 SeoHeadComposer 归一化并消费站点级 Resolution。

## 1. 资源类型与 SEO 来源逐页审计

| Controller / 页面 | Resource Type | SEO Source（页面级） | Canonical | OG | Robots |
|---|---|---|---|---|---|
| AboutController（/about/{profile,history,culture}/） | 配置驱动固定页（config/copy + 后台装修） | 页面 SEO 数组（业务文案单一源） | `url('/about/{page}/')`（业务 URL 生成器） | title/description 页面级；image/description 缺省键由 Composer 从 `resolveSite` 补齐 | index |
| ContactController（/contact/） | 配置驱动固定页 | 同上 | `url('/contact/')` | 同上 | index |
| FactoryController（/factory/） | 配置驱动固定页 | 同上 | `url('/factory/')` | 同上 | index |
| CooperationController（/cooperation/） | 配置驱动固定页 | 同上 | `url($url)` | 同上 | index |
| ProductController（/products/、/products/{line}/、/products/{slug}） | 配置驱动固定页 + Facts 产品数据 | 同上 | `url('/products/...')` | 同上 | index（非核心产品 404，不产出页面） |
| SolutionController（/solutions/、/solutions/{scene}/） | 配置驱动固定页 + Facts 场景数据 | 同上 | `url('/solutions/...')` | 同上 | index |
| SearchController（/search） | 动态结果页（无资源） | 页面 SEO 数组 | `url('/search')` | 同上 | **noindex** |

## 2. 迁移判定（架构决策）

**为什么不做 Content/Entity 绑定：**

1. 冻结契约（5.6-A §3.2）只允许 Site / Content / Entity 三类 SeoMeta 绑定；
   这七个页面没有 DB 资源，强行绑定=把业务配置页塞进不匹配的资源模型。
2. 这些页面的 SEO 文案与页面可见内容同源（config/copy + Facts，经 ContentGate
   与合规测试约束）——页内 SEO 数组即其唯一来源，不构成「第二套 SEO Resolution」
   （没有继承链计算，只是静态映射）。
3. 归一化缺口已闭合：缺失键（og:image、description 兜底、og:site_name、twitter）
   由 `SeoHeadComposer` 从 `SeoMetaResolver::resolveSite()` 站点级 Resolution 补齐
   （STEP 02），站点级 SeoMeta 可影响这些页面（测试
   `site_level_seo_meta_feeds_unmigrated_page_gaps` 锁定）。

**迁移完成度对照冻结架构：**

| 要求（总控 STEP 08） | 状态 |
|---|---|
| 每页明确 Resource Type / SEO Source / Canonical / OG / Robots | ✅ §1 审计表 |
| SEO 不再散落（Blade 零计算、兜底统一） | ✅ STEP 02 完成，遗留设置兜底仅存于 Composer 单点 |
| robots 统一 | ✅ index 默认 / search noindex（HTTP 验证） |
| canonical 责任边界 | ✅ 固定页=真实公开地址（业务 URL 生成器，5.12 替换时统一） |
| HTTP / HTML / Regression 验证 | ✅ `RemainingControllerSeoTest` 12 用例 + 全量回归 |

## 3. 附带关闭项

- **STEP 03-L5 关闭**：`Content::metaTitle() / metaDescription() / canonicalUrl()`
  已删除（STEP 05 起 SchemaBuilder 改消费 SeoResult 后无任何消费方）。
- contents.seo_title / seo_desc / canonical / noindex 列保留（冻结继承链
  legacy 层 + Admin 编辑 UI），backfill/删除按 STEP 03 计划独立执行。

## 4. 开放决策（移交）

- 若未来这些固定页需要后台逐页 SEO 运营（逐页 SeoMeta），
  需先建立「Fixed Page → 可绑定资源」的正式架构（如 page 资源类型），
  经架构审查后扩展 SeoMeta 绑定契约——不擅自实施。
