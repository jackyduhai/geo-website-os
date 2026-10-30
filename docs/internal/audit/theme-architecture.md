# STEP 05 — Theme Architecture（Engine ≠ Theme）

> 核心链：Core / Engine → 公开视图契约（归一化 $seo + 控制器变量）→ Theme → Blade

## 1. 主题机制（覆盖式 + 回退）

```
resources/themes/{name}/
  theme.json        清单：name / version / description（清单无效不算已安装主题）
  views/            同名视图覆盖基础视图（layouts/site、site/home ...）
```

- `ThemeManager::register()`：把激活主题 views 目录置于视图查找器最前
  （`setPaths` 重置，幂等可重入）；主题未提供的视图自动回退基础视图。
- 激活态：站点设置 `theme_active`（admin 可运营），缺省/未知 → default。
- 激活动作：写设置 → 重注册查找器 → `PageCache::flush()`（Setting 模型
  本身在整页缓存失效清单内，双保险）。
- 请求级复位：`resetRequestMemo()` 挂入 boot 复位链（与既有 memo 同约定）。

## 2. 主题契约（禁止项测试锁定）

主题视图只能消费公开数据：归一化 `$seo`（SeoHeadComposer 产物）、
`$siteSettings`、`$navTree`、控制器注入变量。

禁止：`Facts::` / `SeoMetaResolver` / `SchemaBuilder` / `DB::` / `Cache::` /
`Setting::get(` / `PageCache::` —— 静态扫描
`test_theme_views_have_no_engine_leakage` 锁定。

## 3. 双主题实测（ThemeArchitectureTest，5 用例）

1. 发现：default + example 两个主题，清单有效。
2. default：基础视图渲染，无 example 标记，canonical 由引擎输出。
3. example：同名覆盖生效（`data-theme="example"` / `example-home`），
   SEO head 仍由引擎供给；未覆盖链路（sitemap.xml / geo.json）回退正常。
4. **往返切换**：default → example → default，
   canonical 与 sitemap.xml 输出逐字节一致（引擎输出与主题无关）。
5. 引擎泄漏扫描：示例主题视图 0 命中禁止符号。

## 4. 与 C3（public/ 业务资产）的关系

`public/img/logo.png / wechat-qr.png` 是基础（业务演示）主题视图的兜底资产，
随基础视图归属保留在主题边界内；Theme 化后可整体随主题包迁移
（开源纯净发行版剥离 Demo 主题包即可），不构成引擎层污染。
