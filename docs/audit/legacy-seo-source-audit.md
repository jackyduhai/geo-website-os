# STEP 03 — Legacy SEO Source Audit & Migration Boundary

> 性质：只读审计，不删除任何字段、不做数据迁移。
> 核心纪律：**Legacy 数据迁移（backfill）与 Legacy 字段删除必须分开执行**，
> 且仅当某来源的所有 Runtime Consumer 已迁移后，才允许规划删除。

## 1. Legacy 来源总表

| # | 来源 | Runtime Consumer（当前实测） | 用途 | 运行时依赖 | 迁移计划 | 可删除边界 |
|---|---|---|---|---|---|---|
| L1 | `contents.seo_title` | ① `SeoMetaResolver::resolveContent`（继承链第 2 层）② `Admin\ContentController`（编辑表单 214/316 行）③ `Content::metaTitle()` | SEO 标题 legacy 层 | **是**（Resolver 契约冻结层） | 保留为冻结继承链第 2 层；数据 backfill 到 `seo_metas.title` 为独立数据迁移阶段 | backfill 完成且全量 Content 有 SeoMeta 后，经架构批准删除字段 + 移除链层 |
| L2 | `contents.seo_desc` | 同 L1（表单 + `resolveContent` 第 2 层 + `metaDescription()`） | SEO 描述 legacy 层 | **是** | 同 L1 | 同 L1 |
| L3 | `contents.noindex` | `SeoMetaResolver::resolveContent`（noindex 第 2 层）；Admin 表单 | 索引控制 legacy 层 | **是** | 同 L1 | 同 L1 |
| L4 | `contents.canonical` | `Content::canonicalUrl()` → 唯一消费方 `SchemaBuilder::article('@id')`；Admin 表单 | 规范链接 legacy 字段（冻结契约已声明**不是**正式层） | 是（仅经 SchemaBuilder） | 值迁移到 `SeoMeta.canonical`；`SchemaBuilder` 改读 SeoResult（STEP 05） | SchemaBuilder 断开 + 数据迁移后删除 |
| L5 | `Content::metaTitle() / metaDescription() / canonicalUrl()` | 唯一消费方 `SchemaBuilder::article()`（GEO 层）。**Site Controller 已零调用**（STEP 01 守护测试锁定） | legacy 辅助方法 | 是（仅 SchemaBuilder） | STEP 05：SchemaBuilder 消费 SeoResult 后，三个方法变死代码，随后删除 | STEP 05 完成后可删 |
| L6 | `categories.seo_title / seo_desc` | `Admin\CategoryController`（表单）、`PageController::renderCategory`（列表页过渡 SEO） | 栏目列表页 SEO | 是（列表页过渡实现） | STEP 08：确定列表页 SEO 契约（可能扩展 SeoMeta 绑定或保留栏目字段为正式来源——**开放决策**） | STEP 08 契约冻结后 |
| L7 | `ExampleUrlGenerator` | `AppServiceProvider` 全局替换 Laravel `url` 服务（`extends BaseUrlGenerator`）→ 全站 `url()` 均经它 | 业务 URL 方案生成器 | **是（全局）** | STEP 04 划清 URL 责任边界；完整替换为通用 UrlGenerator 属冻结矩阵 5.12 | 5.12 替换完成后 |
| L8 | `Facts::` / `config/facts.php` / `config/copy.php` | ① 7 个 Site Controller（业务页面内容）② `SchemaBuilder / LlmsBuilder / SitemapBuilder`（GEO 层直读）③ Blade 局部（home hero / cta / 卡片）④ AppServiceProvider（CTA 文案） | 业务事实单一源 | 是（业务层合法） | Controller/Blade 的 Facts 读取属业务内容渲染，**不在 SEO 范围**；GEO builders 直读 Facts 在 STEP 05/06 收敛为 Entity/Content/SeoMeta 优先 | GEO 层收敛后按 5.7/5.8 计划 |
| L9 | Setting `seo_default_desc / seo_og_image / seo_title_suffix / site_name` | `SeoHeadComposer`（文档化过渡兜底，STEP 02 收敛） | 头部 SEO 兜底遗留 | 是（仅兜底层） | STEP 08 全部 Controller 迁移 SeoResult 后从 Composer 链移除 | STEP 08 后 |

## 2. 数据迁移（backfill）与字段删除的分离计划

```
现状：seo_metas 表已建（5.6-B），但无 contents.seo_* → seo_metas 的 backfill migration。
运行时正确性：由 Resolver 继承链第 2 层（contents.seo_*）兜住，无需提前 backfill。

规划（不在本阶段执行）：
  Backfill 阶段（独立 migration + 独立测试）：
    1. 为每个 seo_title / seo_desc / canonical / og_image_id / noindex 非空的
       Content 创建 Content-level SeoMeta（site_id / content_id / 对应字段）
    2. 幂等：已有 SeoMeta 的 Content 跳过
    3. 回归：迁移前后 SEO Resolution 结果逐字段不变（HTTP + Resolver 双层断言）

  字段删除阶段（backfill 验证 + 全链路确认后，独立架构批准）：
    - 移除 Resolver 链中 contents.seo_* 层
    - 移除 Admin 表单 legacy 字段
    - 删除 contents.seo_* / contents.canonical 列
```

## 3. 已确认无第二 SEO Resolution 的证据链

- Site Controller 层：仅 HomeController（resolveSite）与 PageController::renderContent
  （resolveContent）执行 SEO Resolution；其余 7 个 Controller 自建过渡数组
  （STEP 08 迁移）。
- Blade 层：布局头部为纯消费（STEP 02 守护测试 `blade head is pure consumer`
  锁定无 Setting / url()->current() / 业务名兜底）。
- Model 层：`metaTitle/metaDescription/canonicalUrl` 仅剩 SchemaBuilder 消费。
- GEO 层：SchemaBuilder 读 legacy 辅助方法 —— 属 5.7（STEP 05）处理范围。

## 4. 开放决策（移交后续阶段，不擅自决断）

| 决策 | 归属 | 说明 |
|---|---|---|
| 列表页（Category / 知识频道）SEO 契约 | STEP 08 | SeoMeta 目前只有 Site/Content/Entity 三类绑定；为列表页扩展绑定类型或保留栏目字段，需架构批准 |
| `ExampleUrlGenerator` 替换节奏 | STEP 04 / 5.12 | 它全局替换了框架 `url` 服务，任何替换必须先建立行为对拍测试 |
| GEO builders 的 Facts 直读 | STEP 05/06（=5.7/5.8） | 冻结矩阵将 SchemaBuilder/LlmsBuilder 划为 5.7/5.8 |
