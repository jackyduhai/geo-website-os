# P-STEP 18H Architecture — Search / Forms / Analytics / Operations 目标架构

- **阶段**：P-STEP 18H（Discovery 后的**架构裁定稿**，待用户拍板）
- **基线 HEAD**：`bf3d576`（checkpoint-18G-2b）；配套现状见 `operations-product-discovery-18h.md`
- **架构纪律**：复用现有 `PublicIndex / PublicUrl / SeoMetaResolver / Catalog / PageCache`，**禁止新建第二套** Localized* 逻辑；
  不破坏 18D Theme（Light/Dark/预设/品牌色）、18F Locale、18G Composition。

---

## 1. 总体分层（18H 新增组件落点）

```
System（框架常量 / 技术默认）
   ↓
Site（Site 模型 + Setting：站点名 / 联系方式 / Locale / Analytics 配置）
   ↓
Theme（视觉 token：色 / 字 / 间距 / 圆角 / 阴影 / Light·Dark / 行业预设 / 品牌色）
   ↓
Template（结构：slot 允许什么）  →  Page（页面实例，不存业务事实）  →  Block（组成单元）
   ↓
Content / Entity / EntityRelation / Media / Form（业务事实与数据结构）
   ↓
Frontend（人类）  +  SEO / GEO / Schema / Sitemap / LLMS / RSS / Search（AI & 爬虫）
```

18H 新增 / 收口：
- **Search**：`app/Support/Search/`（Contract + SearchEngineInterface + SQLite FTS5 Engine）。
- **Forms**：`app/Models/Form.php / FormField.php` + `app/Support/Forms/`（字段类型 registry + 动态验证 + 提交）；Inquiry 增 payload。
- **Analytics**：Setting keys + `app/Support/Analytics/`（注入器 + 事件）；CSP 放行。
- **Audit**：在 Entity / SeoMeta / Page / Block 写操作补 `AuditLog::record`。

---

## 2. Search 架构（TD-71 / TD-72）

### 2.1 分层契约（上层不随引擎变化）
```
SearchQuery（term, locale, site_id, kind?, page, per_page）
SearchFilter（published / Render Contract / site / locale / kind）
SearchResult（title, summary, url, kind, highlights?）   ← 已存在值对象，扩展 highlight
Ranking（相关性 bm25 + 类型权重）
        ↓
SearchEngineInterface { search(SearchQuery): SearchResults; supportsHighlight(): bool; }
        ↓
SqliteFts5Engine（V1 默认）   ← 未来：DatabaseSearchEngine / MeilisearchEngine / ElasticSearchEngine
```
- 绑定到容器（`SearchEngineInterface` → 配置选择，默认 `SqliteFts5Engine`）；
- `SearchController` 只构造 `SearchQuery`、调用接口、映射结果，不再写查询 / 匹配 / 分页细节。

### 2.2 FTS5 索引方案（建议：单一统一虚拟表 + 事件同步）
- 新建 FTS5 虚拟表 `search_index`（migration，**带 IF 守卫 / 重建命令**，FTS5 不可用时降级）：
  ```sql
  CREATE VIRTUALTABLE search_index USING fts5(
      kind,              -- 'content' | entity type（product/service…）
      ref_id UNINDEXED,  -- 对应模型 id
      site_id UNINDEXED,
      locale UNINDEXED,
      title, summary, body,
      tokenize = 'unicode61 remove_diacritics 2'
  );
  ```
- **统一索引 Content + Entity**：每条已准入资源一行；`site_id / locale / kind / ref_id` 为过滤列（UNINDEXED），
  `title / summary / body` 参与全文索引。
- 准入仍以 **PublicIndex 为准**（published + 栏目启用 + 非 noindex + 当前 site + 当前 locale）；
  Draft / noindex / 未发布翻译 **不入索引**（或在状态变更时删除该行）。

### 2.3 索引维护
- 同步时机（Eloquent 事件，复用现有模型接线位置）：
  - Content / Entity `saved`：按准入结果 upsert（重写该行）或删除；`deleted`：删行。
  - 翻译行（Translatable）增删 / publish 状态变化、SeoMeta noindex 变化：重算对应资源行。
  - Site / Setting 影响准入时：重建本站索引。
- 重建命令 `php artisan search:reindex [--site=]`（清空重建，风格对齐 `Catalog::flush / page-cache:clear`）；
  geo:install / geo:upgrade 调用（空站即空索引）。
- 幂等：以 `(kind, ref_id, site_id, locale)` 定位，先删后插，避免重复行。

### 2.4 查询 / 排名 / 分页 / 高亮
- 查询：
  ```sql
  SELECT kind, ref_id, title, summary,
         bm25(search_index, 5.0, 2.0, 1.0) AS rank,
         snippet(search_index, 4, '<mark>', '</mark>', '…', 18) AS hl
  FROM search_index
  WHERE search_index MATCH :term
    AND site_id = :site AND locale = :locale
  ORDER BY rank,
    CASE kind WHEN 'product' THEN 0 WHEN 'service' THEN 1 ELSE 2 END
  LIMIT :per_page OFFSET :offset;
  ```
- **ranking**：FTS5 内置 `bm25`，title 列权重高于 body；叠加类型权重（产品 / 场景优先）。
- **分页**：SQL `LIMIT/OFFSET`（**DB 分页，取代 get 全量 + 内存 slice**）；返回总数用于 paginator。
- **高亮**：FTS5 `snippet/highlight`（term 命中包裹 `<mark>`，受 Theme 控制样式）；引擎不支持时省略。
- 中文：unicode61 对 CJK 按字符切分（配合 FTS5 可满足子串 / 单字召回）；V1 不引入额外分词器依赖。
- 最短查询长度 / noindex / empty state 沿用现有行为。

### 2.5 降级与隔离
- FTS5 不可用（环境探测）：容器回退 `DatabaseSearchEngine`（LIKE，保留现有逻辑作为 fallback），并在日志 / 后台提示。
- Site × Locale 隔离在 SQL 过滤列保证；缓存键含 locale（18F 已建立），搜索结果不串语言 / 不串站。

---

## 3. Form Builder 架构（TD-59）

### 3.1 数据模型（建议新增，结构化、不存任意 HTML）
- **forms 表**：
  `id / site_id / name(机器标识 slug) / title / status / locale 策略 /
   success_message / consent_enabled / consent_text / spam_honeypot / spam_throttle /
   notification_enabled / notification_email / timestamps`。
- **form_fields 表**：
  `id / form_id / type / name / label / placeholder / required / validation /
   options(JSON: select/radio/checkbox) / sort_order / timestamps`。
- 安全：DB **不存任意 Blade / HTML / PHP**；字段为结构化 JSON，由注册渲染器生成 HTML（与 Block 同一边界）。

### 3.2 字段类型 registry（`app/Support/Forms/FieldTypeRegistry`）
- V1 支持：`text / textarea / email / tel / number / select / radio / checkbox / date / url / hidden`。
- 每个类型注册：`input component`（Theme 表单组件）+ `默认 validation 规则`（如 email→email、number→numeric、url→url）+ 选项结构。
- 类型 → 组件 / 校验统一映射；新增类型只需注册（不堵死未来，不做自由拖拽 / 多步 / 条件 / 计算）。

### 3.3 提交与动态验证
- 提交端点按 Form 配置**动态生成 validation rules**（required / 类型默认规则 / 字段自定义 validation / options in）。
- 错误文案 / label 来自 FormField；前后端校验同源（前端由同一配置渲染，后端兜底，不再写死控制器规则）。
-蜜罐（spam_honeypot）+ 路由 throttle 复用现有；consent：consent_enabled 时输出**必勾 checkbox** + consent_text。

### 3.4 Inquiry 落库（固定核心 + payload，不复制结构）
- Inquiry 增列：`form_id`（nullable）+ **`payload`（JSON, cast array）**。
- 核心归因 / 联系字段（name / phone / source / device / ip / ua / status / 归因）沿用现有列；
  其余自定义字段统一进 `payload`（结构化、可后台查看）。
- demand_type 不再依赖 `Inquiry::TYPES` 写死中文；由 Form 的 select options 提供（旧常量仅作历史兼容 / 迁移）。
- 后台留言查看：按 form_id 呈现 payload 字段；跟进 / 归档逻辑不变。

### 3.5 notification
- notification_enabled 且配置 notification_email：提交后经 `Mail`（队列可选）发送通知（含字段摘要 + 归因）。
- 未配置则只落库（保持当前行为）；不强制 SMTP（避免新部署门槛，文档说明）。

### 3.6 FormReference Block 升级
- `form_reference` block 由“仅 title/subtitle + 固定 inquiry 表单”升级为**引用 `form_id`**：
  block 渲染所选 Form 的字段（经字段组件 + Theme），提交到对应 Form 的处理端点。
- Contact Template（ContactInfo + FormReference）由此真正组合完整；视觉仍由 Theme / Component 负责，Form 不做布局。

### 3.7 Locale 处理（不复制整套 Form）
- Form / FormField 为站点级**结构**（name / type / required / options 结构共享）；
- 文案（form title / success / consent_text / field label / placeholder）支持按 locale：
  推荐**字段文案存 locale-keyed**（简单 JSON / 翻译键兜底），而非新建第二套 Form。
- 缺翻译策略对齐 18F：public 内容不无条件 fallback 成另一语言；UI 系统串可 fallback。

---

## 4. Analytics 架构（TD-73）

### 4.1 配置（Site Setting，不写 Blade）
- Setting keys：`analytics_ga_id`（G-XXXX）、`analytics_gtm_id`（GTM-XXXX）、
  `analytics_meta_pixel_id`、`analytics_consent_mode`、（super admin 限定）`analytics_custom_head/body`。
- 第三方 ID 一律存 Setting / Site，经注入器输出；**禁止在 Blade 硬编码 ID**。

### 4.2 注入器（`app/Support/Analytics/AnalyticsInjector`）
- 按配置在 `<head>` / `<body>` 输出**官方标准 snippet**（GA4 / GTM / Pixel），脚本带 CSP nonce。
- 自定义脚本（custom_head/body）默认关闭 / 仅 super admin，严格过滤（XSS 风险），V1 可不开放自由输入。

### 4.3 事件
- 统一事件层（dataLayer / gtag event）：`page_view`（自动）、`cta_click`（CTA 加 data-track 属性）、
  `form_submit`（与 Form Builder 提交联动）、`download`、`contact`。
- 事件不依赖具体厂商（先写抽象 data-track / dataLayer，厂商 snippet 消费），换分析服务不改业务模板。

### 4.4 CSP 放行
- `SecurityHeaders` CSP 按已配置的集成动态追加域名：
  `script-src` 放行 `*.googletagmanager.com / www.google-analytics.com / connect-src 同 / *.facebook.com`；
  未配置的不放行（保持最小权限）。

### 4.5 边界
- V1 要求**架构 / injection point 成立**（可配置 + 输出 + 事件 + CSP）；分析数据看板 / 复杂转化 UI 后置 v1.1。

---

## 5. Audit 补点（TD-74）

- 在以下写操作补 `AuditLog::record`（action / target / 关键变更）：
  - **Entity**（create/update/delete + publish）、**SeoMeta**（create/update/delete）、
  - **Page / PageBlock**（页面 / block 增删改、排序、显隐、publish）。
- Theme / Plugin 激活 / 变更同补（17E 已有 preview 不污染，补激活态变更记录）。
- 复用现有 `AuditLog::record(action, summary, detail, targetType, targetId)`，不新建日志体系。
- 目标：Settings / SeoMeta / Entity / Content / Menu / Block 关键修改均可追溯（Core Audit Trail）。

---

## 6. v1.1 边界（本阶段不实现，登记验收条件）

- **TD-75 Revision 扩展**：Entity / Page 版本快照（对照 ContentRevision），含 diff / 回滚；V1 仅 Content 有 revision。
- **TD-76 Media 统一 / srcset**：logo 由 setting 路径改为 media ID 引用（消除双轨）；media 渲染补多尺寸 `srcset`。
- 均不阻塞 v1.0（当前 logo 可配置、图片经 ImageOptimizer + picture/webP 已可用）。

---

## 7. Cache 矩阵更新（18H 新资源接入）

| 变更 | 失效动作 |
| --- | --- |
| Form / FormField 结构变更 | 引用该 Form 的页面（Contact / 含 FormReference 的 Page）失效（页面级 forgetPage / 必要时 flush） |
| Analytics 配置变更 | 仅脚本注入变化 → flush（罕见，整站可接受） |
| Search 索引更新 | 搜索页 noindex、不进 PageCache（无需失效静态页） |
| Entity / SeoMeta / Page / Block（补 Audit 同时） | 沿用现有失效（Page 页面级、Entity/Seo 整站），不改变缓存契约 |

- 不把 Form / Analytics 改成“一改全 flush”之外的新耦合；Form 结构变更优先页面级。

---

## 8. 实施任务清单与子阶段 Gate 拆分（建议）

> 18H 范围大，建议**三个子阶段、各自独立 Gate / STOP**（沿用现有闸门：focused → full regression →
> fresh install → blank + demo HTTP → browser → multi-site → SEO/GEO/Schema → feed → cache →
> pollution=0 → logs → cleanup → git diff/status → commit → annotated tag → worktree clean）。

### 18H-1 Search Engine + FTS5（TD-71 / TD-72）
- Search Contract + SearchEngineInterface + 容器绑定；FTS5 迁移 + `search:reindex`；
  事件同步（Content/Entity/翻译/SeoMeta noindex）；bm25 ranking + DB 分页 + snippet 高亮；
  LIKE 引擎降级；SearchController 改调接口。
- 测试：SearchEngineContract / Fts5Index / LocalizedSearch / Pagination / Highlight / Reindex；
- Tag：`checkpoint-18H-1`；**STOP**。

### 18H-2 Form Builder（TD-59）
- forms / form_fields 迁移 + 模型；FieldTypeRegistry；动态验证；Inquiry form_id + payload；
  consent；notification（Mail，可关）；FormReference block 引用 form_id；locale 文案。
- 测试：FormBuilderCrud / DynamicValidation / InquiryPayload / FormReferenceRender / Notification / 双语；
- Tag：`checkpoint-18H-2`；**STOP**。

### 18H-3 Analytics + Audit（TD-73 / TD-74）
- Analytics settings + Injector + 事件 + CSP 放行；Entity/SeoMeta/Page/Block（含 Theme/Plugin）补 AuditLog。
- 测试：AnalyticsInjection / CSP / Events / AuditCoverage；
- Tag：`checkpoint-18H-3`；**STOP**。

> TD-75 / TD-76 不在 V1 实现（转 v1.1，登记验收条件）。

---

## 9. 18H 整体验收（成功定义）

- SearchEngineInterface 成立、FTS5 默认引擎工作（ranking / DB 分页 / 高亮，LIKE 可降级）；
- Form Builder：换企业可仅经后台定义字段 / 验证 / success / consent / notification，不改 PHP/Blade；
- Analytics：可配置注入 + 事件 + CSP 放行，ID 不写 Blade（架构成立）；
- Audit：Entity / SeoMeta / Page / Block 关键修改可追溯；
- 业务 / 品牌 / URL / SEO / GEO 硬编码保持 0；Blank System ≠ Demo Site 保持；
- Multi-Site × Locale × Theme 隔离保持；Full Regression 全绿、Runtime pollution=0、日志干净、worktree clean。
- 18H 三个子 Gate 全 PASS 后，才进入 P-STEP 18I（Blueprint / 历史债 / UAT）；
  **仍不**配 remote / push / 重建 RC / Release。

---

## 10. 待用户拍板项

1. FTS5 索引形态：**单一统一虚拟表 `search_index` + 事件同步 + `search:reindex`**（本稿建议）是否认可。
2. Form / FormField 表结构与 **Inquiry payload JSON** 方案是否认可；notification 默认是否启用。
3. Analytics V1 范围（GA + GTM + Pixel + 抽象事件，自定义脚本默认不开放）是否认可。
4. 子阶段拆分 **18H-1 / 18H-2 / 18H-3 各自 Gate / STOP** 是否认可。
