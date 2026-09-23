# P-STEP 18F — Localization Architecture Contract（架构裁定确认）

- 日期：2026-09-23
- 性质：DISCOVERY 产物——对**已落地于 `d886d5d`** 的国际化实现做架构契约确认。
- 配套：详细设计见 `docs/localization-design.md`；现状盘点见 `localization-discovery-18f.md`。
- 目的：冻结"谁拥有语言事实、语言如何流经各层、缺失翻译如何处理"，**防止出现第二套国际化逻辑**。

---

## 1. 语言事实的分层（四层事实源）

```
System（config/localization.php：官方 supported / 语言能力）
   ↓
Site（Setting：site_supported_locales / site_default_locale，租户级语言开关）
   ↓
LocaleContext（request-scoped，由 SetLocale 在请求开始唯一裁决）
   ↓
Content / Entity（translation_group 多行 representation）/ Menu / Block / SeoMeta
   ↓
Frontend（Blade + lang/*/ui.php）
   ↓
SEO · hreflang · Schema(inLanguage) · GEO · Sitemap · LLMS · RSS · Search
```

**裁定**：

- **语言能力**（系统支持哪些语言）唯一来源 = `config/localization.php`，经 `LocaleRegistry` 只读访问。
- **某站点启用哪些语言**唯一来源 = Site 级 Setting（`site_supported_locales` / `site_default_locale`），**不写死在系统、不塞进全局 config**。
- **当前请求语言**唯一来源 = `LocaleContext`，由 `SetLocale` 中间件设置；任何层（PublicUrl / Catalog / Pages / PageCache / Feed）只读 `LocaleContext`，**禁止** Controller/Blade 自判 `$request->get('lang')`。
- 一个语言事实只允许一个 runtime source；其余只能是 fallback / computed / example / migration。

---

## 2. Locale Registry（冻结枚举）

| 项 | 值 |
| --- | --- |
| 官方支持（BCP-47） | `zh-CN`、`en` |
| 默认语言（无前缀） | `zh-CN` |
| UI / 系统串回退 | `zh-CN`（仅 UI 串可回退） |
| URL 前缀 | zh-CN → `''`；en → `'en'` |
| x-default hreflang | 统一指向默认语言根 `/` |

禁止项目中出现 `zh` / `zh_CN` / `cn` / `en-US` 等混用（OG 的 `og:locale=zh_CN/en_US` 属 OpenGraph 协议规定格式，是唯一明确例外）。

---

## 3. URL 契约（冻结）

- 中文：`/`（默认，无前缀）；英文：`/en`、`/en/...`。
- **禁止** `?lang=en` / `?locale=en` 作为正式语言 URL。
- 路由双注册，**en 组先于 zh 组**，避免 zh catch-all `/{path}` 抢先匹配 `/en/*`。
- 语言 URL 仍由**现有 `PublicUrl`** 裁决（`localePrefix()` 注入前缀）；**未新造** `LocalizedUrl` / `LocalizedPublicIndex`。
- 尾斜杠规则沿用现有 CanonicalizeSlash：目录型带 `/`，详情型无 `/`；`/en` → 200，`/en/` → 301 → `/en`。

---

## 4. 翻译模型：identity ≠ translation

**裁定采用方案 C：同表多行 + `locale` + `translation_group`(uuid)**。

- 一个业务对象（Content / Entity）= 一个 `translation_group`，**不复制成两个彼此无关的资源**。
- 每个 locale 一行，拥有**独立** slug / 翻译字段（title·name / summary / body·description）/ SeoMeta。
- **共享字段**（status / type / 治理 / 对接 / Entity.metadata）由默认语言（zh-CN）权威行单向拥有；其他语言行不独立维护共享事实。
- 中英文 **slug 允许不同**，不靠"slug 相同 / 名称相似 / AI 猜测"关联，只靠 `translation_group`。
- 由 `Translatable` trait 统一承载：creating 自动分配 group/locale、`translation()`、`forLocale`、`hasTranslation`、`publishedLocales()`、`createTranslation()`。

### 字段翻译规则

| 字段类 | 规则 |
| --- | --- |
| title/name、summary、body/description、caption/alt、CTA 文案 | **按 locale 独立翻译** |
| slug | 按 locale 独立（可不同） |
| status / published_at / type / sort_order / owner / 治理字段 | 语言中性，默认语言权威行单向拥有 |
| Entity.metadata（结构化事实 / 关系对接） | 语言中性共享；展示文案由读模型按 locale 呈现 |

---

## 5. Missing Translation & Fallback（关键区分）

| 类别 | 策略 |
| --- | --- |
| **UI / 系统串**（按钮、表单标签、错误页框架） | 允许 fallback 到回退语言 |
| **Public Content / Entity 内容** | **不允许无条件回退成另一语言**（英文产品页 title 不得显示中文） |
| 公开内容无目标语言版本 | **不生成该 locale 的公开 URL**（资源级 hreflang 只列已发布语言）；直接访问该 locale 路径 → **404** |
| 站点未启用某语言 | 该语言整组路由 → **404**（SetLocale 闸门） |

- 资源级 hreflang = 站点 supported locales × 该资源**已发布**语言取交集，**不得指向 404**。

---

## 6. 关系（EntityRelation）的语言归属

- **关系语言中性**：只在默认语言权威行为实体建边；其他语言经 `translation_group` 自动映射到对应语言行，**不为每种语言重复建边**。
- Catalog 关系权威基础语言 `baseLocale`：默认 zh-CN；站点无 zh-CN 主体（en-only）时回退 `site_default_locale`（TD-42）。
- **禁止** EntityRelation ↔ Catalog 双向反写（18A 单向派生决策保持）；不新增 ProductCategory/ProductLine/ProductGroup 另一套分类。

---

## 7. Cache 契约（P0）

- `PageCache` 键 = `pagecache:html:{version}:sha1(getHttpHost().path)`。
  - `path` 含 `/en` 前缀 → **中英缓存键天然不同**，英文请求不可能命中中文 HTML。
  - `getHttpHost()` 含端口 → 区分同机不同站点/实例。
  - version 经 SiteCacheKey 站点隔离。
- 语言不要求单独进 key——path 前缀已等价表达；但**必须实测** `zh → en → zh` 对拍确认。
- 翻译/语言切换不改变 version（语言由 path 区分）；内容修改仍按现有失效模型 flush。

---

## 8. Theme × Locale 解耦

- Theme 只承载视觉 token（color / typography / spacing / radius / shadow / appearance）；**不含任何语言或业务事实**。
- Locale 只决定 content representation；**不改变视觉 token**。
- 必须四组合全部成立：**zh+Light / zh+Dark / en+Light / en+Dark**；切换语言不丢外观模式，切换外观不丢语言。
- 行业 Theme Preset 只改视觉语言、不改业务 IA（18D 决策在 18F 保持）。

---

## 9. 各消费端的语言接入（均为扩展，非新造）

| 消费端 | 语言接入方式 | 是否新造系统 |
| --- | --- | --- |
| Canonical / OG / hreflang | SeoHeadComposer 读 LocaleContext + 资源 publishedLocales | 否（扩展现有） |
| Schema（inLanguage / url / @id） | SchemaBuilder::locale()；@id 语言中性 | 否 |
| GEO（geo.json） | 按 locale 输出实体/内容/边 | 否 |
| Sitemap | 各语言只列本语言 URL，首页 loc locale-aware | 否 |
| LLMS / RSS / robots | locale-aware；robots 唯一引用两套 sitemap | 否 |
| Search | site_id + locale + published 过滤 | 否 |
| PageCache | path 前缀区分语言 | 否 |

> 明确：**未创建** `LocalizedSeoResolver` / `LocalizedSchemaBuilder` / `LocalizedPublicUrl` 等任何第二套系统。

---

## 10. 验收（Gate）需真实复跑

本契约在代码中已落地，但是否达标以**真实运行**为准（DISCOVERY 不代结论）：
focused Localization18FTest → 全量回归（自报 871/4653）→ fresh install 空站（/en 404）→ demo（中英）→ 真实浏览器全页类 → Multi-Site×Locale（A zh+en / B en-only）→ Light/Dark×Locale 四组合 → PageCache zh↔en 对拍 → 污染/日志复扫。

---

## 11. 架构裁定小结

1. 语言能力归 System、语言开关归 Site、当前语言归 LocaleContext、语言 representation 归 translation_group 多行——**四层各有唯一事实源**。
2. identity 与 translation 已分离；共享字段默认语言权威行单向拥有。
3. UI 串可 fallback，Public 内容不回退、无译文即 404/不生成 URL。
4. 关系语言中性、Theme 与 Locale 解耦、Cache 键经 path 天然区分。
5. 全部在现有体系上扩展，**无第二套国际化逻辑**。

> DISCOVERY 到此 STOP，提交用户裁定：认可后进入 Gate 验证；不自动开发、不进 18G、不移动 rc1、不碰 remote/Release。
