# RC-11 提交前 Diff Review 报告

**范围**：`504f71d..5934c6c`（A→H2，10 个 commit）
**HEAD**：5934c6c · **v1.0.0**：504f71d（未动）· **远端 main**：504f71d · **全部未 push**

---

## 结论速览

| # | 维度 | 结论 |
|---|---|---|
| 1 | 范围污染 | 干净 |
| 2 | v1.0.0 边界 | 未动 |
| 3 | G2 业务路径 | 正确，非平行实现 |
| 4 | 预览安全边界 | 严格，未回归 |
| 5 | 销毁逻辑 | 双重保险 + mutation guard 保留 |
| 6 | 测试诚实性 | **原判断错误，已补真测试** |
| 7 | 全站行为回归 | **发现 1 个真实缺陷，已修** |

另修：H1/H2 引入的缩进损伤（3 文件 36 行）。

---

## 一、范围污染：无污染

`.env`、`public/storage/`、`storage/framework/cache/data/*`、`storage/app/backups/` 全部 ignored。
无测试截图、上传文件、preview 临时站、临时 DB、浏览器探针、qa-results 入库。

新增 `.gitignore` 三项，理由正确——运行时上传是**单站点数据**，不是产品资产：

```
/public/img/brand
/storage/sites
/storage/settings
```

入库会让任何人的一次上传变成仓库默认配置，且随发布包分发。

---

## 二、G2 修的是真路径，不是平行实现

`c574081` 调用的是与 `TemplateController::activate()` **完全相同**的 `RecipeApplier::apply()`。

```text
创建预览站 → 骨架Seeder → RecipeApplier::apply()（逐 recipe）
          → copyDemoContent() → TemplateDefaultsInstaller::bootstrap()
```

未调 `TemplatePackageManager::activate()`，理由（写包级激活态、会影响其他站点）成立。

---

## 三、预览安全边界：严格

`SiteResolver::resolveFromRequest()` 两条通道互不干扰：

```php
// 通道 1：admin 路径显式 slug
if ($request->is('admin/*') || $request->is('admin')) { ... }

// 通道 2：前台只认预览前缀
if ($previewSlug !== '' && TemplatePreviewSite::isPreviewSlug($previewSlug)) { ... }
```

`?site_slug=<任意真实站>` 未成为切站入口，有测试守着
（`test_site_resolver_only_accepts_preview_slug`）。

销毁双重保险：`closeLivePreview` 按 `SLUG_PREFIX . $pack` 精确定位，
`discard()` 内二次 `isPreviewSlug` 校验，非预览站直接 `return`。

**附带确认**：`AppServiceProvider` 新增的 `URL::forceRootUrl()` 存在 Host 头投毒疑虑，
但 `PageCache` 缓存键含 host + port（`PageCache.php:66` 明确注释），
攻击者污染的缓存无法命中受害者请求，**风险不成立**。

---

## 四、测试诚实性：G2 的前提被实测推翻（核心发现）

### 原判断

`c574081` commit 正文写：

> 新增的 2 条 recipe 落地测试在 `RefreshDatabase` 下**无法可靠断言**……
> 因此本次不新增无法稳定运行的测试，`TemplateLivePreviewTest` 保持 8 tests 全绿。

### 实测结果：不成立

`RefreshDatabase` 下 `TemplatePreviewSite::ensure('commerce-pro')`：

```text
preview site_id = 2（正常创建）
recipe 区块完整落地：
  page 1 (zh-CN)  hero → media_text → product_grid → testimonial → logo_cloud → cta
  page 2 (en)     hero → media_text → product_grid → testimonial → logo_cloud → cta
  _recipe 标记 = commerce-pro.homepage
recipe 声明（resources/templates/commerce-pro/recipes/homepage.json）：
  slot=main type=hero / media_text / product_grid / testimonial / logo_cloud / cta
→ 逐项一致
```

`contents/entities = 0` 只是测试库默认站本身无内容，**不是事务丢写**。
所谓「站点建得出来但 Seeder 写入全丢」未复现。

### 补了真正有牙齿的测试

新增 `tests/Feature/TemplateRecipeLandingTest.php`（5 tests / 22 assertions）。
判据是**声明 → 落地的结构契约**，不是字节数：

| 测试 | 断言 |
|---|---|
| `test_landed_blocks_match_recipe_declaration` | 逐语言行落地序列 == 模板包声明 |
| `test_two_packs_land_different_structures` | A ≠ B（语义级，非字节级） |
| `test_no_blocks_beyond_recipe_declaration` | Fidelity：不得凭空多出区块 |
| `test_content_copy_does_not_mutate_source_site` | 复制只读，源站行数不变 |
| `test_preview_site_is_noindex` | 预览不进索引 |

### 变异验证（测试有牙齿的唯一证明）

| 变异 | 改动前 12 tests | 新增测试 |
|---|---|---|
| 删掉 `RecipeApplier` 循环 | **全绿** | ①② **失败**，信息直指「RecipeApplier 漏调或落地错乱」 |
| 撤掉 noindex 补丁 | — | ⑤ **失败** |

这正是你要求的长期护栏：不写 `assertNotSame($htmlA, $htmlB)`（字节级、脆弱、
受缓存与渲染差异干扰），而写**模板 recipe 关键结构落地契约**。

---

## 五、全站行为回归：发现真实缺陷（预览站缺 noindex）

### 问题

预览站经 `?site_slug=` 暴露在**当前真实域名**下，且 `copyDemoContent()`
复制了真实站的全部内容。**页面没有 noindex**。

后果：搜索引擎收录「同内容、不同 URL」的重复页，稀释真实站的
canonical 与抓取信号。对 GEO 产品是实打实的损害。

项目对 `ThemeController::preview()` 早有 `X-Robots-Tag: noindex, nofollow`，
活站预览却没有——同一类「预览」行为，两种处理，不一致。

### 修复

`app/Http/View/SeoHeadComposer.php`：

```php
'noindex' => TemplatePreviewSite::isPreviewSlug(
                (string) (\App\Support\SiteContext::currentSite()?->slug ?? '')
            )
    || (bool) ($seo['noindex'] ?? ($siteResult->noindex ?? false)),
```

**必须前置 `||`，不能用 `??`**：上游 `PageController` 恒显式传 `noindex => false`
（`PageController.php:177,249`），`??` 会被短路，补丁静默失效。
此坑已写入代码注释。

---

## 六、已确认无问题的项

| 项 | 结论 |
|---|---|
| `SafeUrl::relativize()` | 同源转根相对，跨站不动，危险协议仍被 `isSafe()` 拦 |
| `forceRootUrl` + PageCache | 缓存键含 host+port，Host 投毒不成立 |
| C（`/storage` 前缀） | 最小正确修复 |
| D（方形 logo 回落） | 与前台对齐，CSS 无需改 |
| i18n nav 键补齐 | 覆盖 `SiteStructureSeeder` 全部分组 slug |
| `ImageOptimizer` 新方法 | 纯读取，无副作用 |
| `copyDemoContent` | 只复制 contents/entities，结构由 recipe 决定；映射按 id 顺序对应 |

---

## 七、缩进损伤（已修）

H1/H2 编辑时破坏缩进，共 3 文件 36 行：

- `ThemeController.php` — `SEED_OVERRIDABLE` 13 项中 9 项 + `activate()` 整块
- `TemplatePreviewSite.php` — recipe 循环 + `copyDemoContent()` 全方法
- `SafeUrl.php` — `sanitize()` docblock 脱离类缩进

blade 文件是 2 空格制（历史既有风格），不算问题。PHP 类文件已全部归位 4 空格。

---

## 八、关于 `sys_*`：维持原样

同意你的判断，且补一条判据依据：

预览站 5 段 vs 真实站 9 段，差的 4 段是 `sys_*`。但 commerce-pro 的 recipe
**只声明了 6 个 block**，`RecipeApplier` 已把这 6 个**全部、精确**落地
（见上节实测）。所以：

```text
Template recipe = 6
Preview rendered = 6   ← 契约成立
```

真正的不可接受是 `recipe = 6 / rendered = 3`（G2 那个缺陷），现已不可能再发生。

应为模板生态确立 **Template Preview Fidelity**：

> 预览站必须忠实反映模板包定义的 recipe，
> 不得为了视觉效果自行增加模板未声明的区块。

这条已固化为 `test_no_blocks_beyond_recipe_declaration`。

---

## 九、经验沉淀

**① 自动化测试必须验证「结果语义」，不能只验证「控制流安全」。**

G 的 8 个测试验证了隔离 / 幂等 / 回收 / 安全边界，
但从没有验证「Template A → A 的内容」。这就是全绿却漏缺陷的原因。

**② 写「某测试写不了」之前，先实测。**

G2 commit 正文断言的测试环境限制，实测不成立。
以「环境限制」为由放弃测试的判断，必须有探针数据支撑，
否则会变成永久的技术债借口。

**③ 验收分层（已确立）**

```text
自动化        状态/数据/隔离/回收/安全边界 + 声明→落地契约
HTTP 验收     status / headers / 可见文本量 / block 数量 / 数据来源
人工视觉      是否真渲染 / 首屏 / 排版 / 图片 / CTA / hover / 主题实际效果
```

`HTTP 200 不够`，`字节差异不够`，`可见文本量更好但仍不是最终视觉证据`。

---

## 十、待办

```text
· 全量回归结果（Feature + Unit）
· 本轮 5 处改动按主题拆commit
· 浏览器人工确认：主题色实际变化、预览站视觉、noindex meta
· v1.0.0 保持 504f71d 不动
· A→H2 + G3 是否 push，等你决定
```
