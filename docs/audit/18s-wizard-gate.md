# P-STEP 18S Capability 1 — Setup Wizard 独立 Gate

- 阶段：P-STEP 18S / Product Capability 1（首次运行 Setup Wizard）
- 基线 HEAD：`7828750`（18S Discovery，read-only）
- 本 Gate 范围：Wizard 代码收尾 + 独立回归 + Fresh 浏览器盲测 + SEO/GEO 对拍
- 结论：**PASS（P0 = 0）**

---

## 1. Architecture Impact

**Second System = No。**

Wizard 是一层纯编排（orchestration）控制器，不引入第二套事实源、Renderer、Builder 或领域模型：

| 复用对象 | 用途 |
| --- | --- |
| `Setting`（site-scoped，get/set/flush） | site_name / contact_* / brand_color / brand_slogan / wizard_template / wizard_completed |
| `Entity`（TYPE_ORGANIZATION / TYPE_PRODUCT，STATUS_DRAFT / STATUS_PUBLISHED） | step1 站点组织、step3 草稿产品 |
| `EntityRelation`（五型冻结 produces/offers/uses/located_in/related_to） | step4 Organization —produces→ Product |
| `SiteContext` / `admin.site` 中间件 | 当前管理站点解析，全部写入按站点隔离 |
| `session` | 当前步骤 `wizard_step` 内存态（完成后复位） |
| `admin.layout` | 单一视图 `admin/wizard/step.blade.php` 按 step 渲染 |

- **零新表 / 零新列 / 零 migration**（`git diff` 仅 `routes/admin.php` +4 行路由接入）。
- **零新模型 / 零新 Service / 零新 Renderer**：不复制 Catalog / SeoMeta / Schema 既有链路，只是按既有契约批量调用。
- 完成标记 `wizard_completed` 落既有 `settings` 键值表，不新增字段。
- 路由挂在既有 `admin.auth + admin.site` 组内，复用既有鉴权与站点切换会话。

## 2. 五维 Feature Matrix

| 维度 | 状态 | 说明 |
| --- | --- | --- |
| Backend | Verified | WizardController 6 步编排；step1 幂等建组织、step3 建草稿产品、step4 firstOrCreate 关系、step6 批量发布草稿 + 完成标记；全部走既有 site-scoped 写入。 |
| Admin | Verified | `GET /admin/wizard`（名 `wizard`）/ `POST /admin/wizard/{step}`（名 `wizard.save`）接入；单视图按 step 渲染、含 CSRF 与「跳过」链接（→ dashboard）；未登录 302 到登录。 |
| Frontend | Verified | 向导产出的产品 draft→published 后，`/products/{slug}` 详情页 200；step1 联系方式流入前台 header CTA 与 footer；空站平铺产品列表正确显示新产品。 |
| AI · GEO | Verified | 新发布产品正确流入 `geo.json` / `sitemap.xml` / `llms.txt`；产品页 JSON-LD、canonical、hreflang 正常，无回归。 |
| Docs | Verified | 本 Gate 报告 + TD 登记入库（TD-161 / TD-162）。 |

## 3. Evidence

### 3.1 代码与 diff 统计
- `app/Http/Controllers/Admin/WizardController.php`（新增，108 行）
- `resources/views/admin/wizard/step.blade.php`（新增，46 行）
- `tests/Feature/Admin/SetupWizardTest.php`（新增，164 行）
- `routes/admin.php`（+4 行：2 条路由接入 `admin.auth + admin.site` 组）
- migration / 表结构 / 模型改动：**0**

### 3.2 测试
- 新增 `SetupWizardTest`：4 用例 / 51 断言，全部通过（访客跳登录、引导页+跳过链接、6 步全链路落库并验证前台、完成标记仅 step6 落）。
- **全量回归：1198 passed / 6381 assertions / 0 failed / 0 skipped**（约 1033s）。相对 18R-3 的 1194 净增 4（即本能力新增用例）。
- 日志：`D:\Temp\full_regression.log`。

### 3.3 Fresh 浏览器盲测（独立 sqlite 库 `D:\Temp\geo18S\geo.sqlite`）
- 全新库 migrate + db:seed，独立 `php artisan serve --port=8127`。
- 真实浏览器登录 `admin@example.com`（Admin@123456），逐页走完 6 步：
  1. 基础信息（Demo Tenant A Living / 邮箱 / 电话 / 地址）→ 跳 step2
  2. 品牌色 #1a56db + Slogan → 跳 step3
  3. 产品「Solid Wood Dining Table」→ 跳 step4
  4. 自动 produces 关系 → 跳 step5
  5. 模板选 saas → 跳 step6
  6. 「完成并发布」→ 重定向 dashboard
- DB 真值核验：8 项 Setting 全部落库；产品 id=19 `solid-wood-dining-table` 由 draft 转 **published**；Organization id=1 —produces→ 产品 id=19 关系存在。
- 前台：首页 200；产品详情页 200 并正确显示产品名 / 简介；footer 地址与 header 电话即 step1 录入值。

### 3.4 SEO / GEO 对拍（向导完成后 live 端点）
| 端点 | 状态 | 结果 |
| --- | --- | --- |
| `/geo.json` | 200 | 可解析（$schema/site/facts/entities/relations/contents），含新产品实体 |
| `/sitemap.xml` | 200 | 含新产品详情 URL |
| `/llms.txt` | 200 | 含新产品 |
| `/robots.txt` | 200 | 正常 |
| 产品详情页 | 200 | 含 `application/ld+json`、`rel=canonical`（自指）、hreflang；无 Whoops/SQLSTATE |
- 对比向导前后：既有回归套件（SchemaJsonLdTest / SitemapRobotsTest / SeoHttpIntegrationTest / Localization18FTest 等）锁定输出契约且全绿；live 端点结构一致、新实体被正确摄入，**无异常 / 无回归**。

### 3.5 登记的技术债（仅登记，不在本 Gate 修复）
- **TD-161（P2）**：step3 纯中文产品名经 `Str::slug()` 得到空串（实测 `Str::slug('实木餐桌') === ''`），可能命中 `entities.slug` NOT NULL 约束。本 Gate 测试与盲测均以 ASCII 名规避；需在控制器对空 slug 兜底（如 `product-<id>` 回退或 translit）。
- **TD-162（P3）**：向导 step3 创建的产品未归入产品系列/分组；在已有分组目录（演示站）的 `/products/` 分组列表中不出现（详情页仍 200），空站平铺列表正常。

## 4. Release Impact
- 不改变既有数据结构、路由对外契约、SEO/GEO 输出结构；纯新增后台入口。
- 对已上线/既有站点无强制迁移、无破坏性变更；`wizard_completed` 默认空值不影响现有流程。
- 红线遵守：未 push、未配 remote、未触碰 rc1 / 19A（19A 永久 HOLD，除非明确放行）；未修改 DIR B。

## 5. 结论
**PASS（P0 = 0）**。Capability 1 Setup Wizard 工程收尾完成，按要求 STOP，不自动进入 Capability 2（GEO Health）。
