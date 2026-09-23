# P-STEP 18F — Localization Final Audit（zh-CN + English 前端国际化）

- 日期：2026-09-23
- 基线起点：HEAD `59dd573`（18E，861 / 4590 / 0 / 0）
- 收尾产物：commit + annotated tag `checkpoint-18F`
- 最终全量回归：**871 passed / 4653 assertions / 0 failed / 0 skipped**
- 发布冻结：`v1.0.0-rc1`（`965d63c`）HOLD；无 remote；未 push；未 Release

> 本阶段目标不是"加一个 English 按钮"，而是建立可维护、可索引、可被 AI 理解的
> zh-CN + en 前端国际化体系，并把 Locale 正式纳入 Site 配置、URL、缓存键与
> SEO / Schema / GEO / Sitemap / Search 的全链路。

---

## 1. Locale 契约（已冻结，详见 `docs/localization-design.md`）

| 项 | 决策 |
| --- | --- |
| 官方支持语言 | `zh-CN`、`en`（BCP-47），统一经 `LocaleRegistry`，禁止散落 zh / zh_CN / cn / en-US |
| 默认语言 | `zh-CN`（无前缀） |
| UI / 系统串回退 | `zh-CN`（UI 串允许 fallback） |
| URL 策略 | 中文无前缀 `/`；英文前缀 `/en`；**禁止 query string（`?lang=en`）作为正式语言 URL** |
| 根路径形态 | `/en` → 200；`/en/` → 301 → `/en`（`CanonicalizeSlash`） |
| 翻译模型 | 方案 C：同表多行 + `locale` + `translation_group`（uuid），同一业务对象不复制成两个独立资源 |
| Missing Translation | 公开内容无目标语言版本 → **404，不回退成另一语言**；UI 串可 fallback |
| `x-default` | 统一指向 `/` |
| Site 级配置 | `site_supported_locales` / `site_default_locale`（空站默认仅 `zh-CN`） |
| 关系建模 | 关系语言中性，只在默认语言权威行建边，其他语言经 `translation_group` 自动映射 |
| 双向反写 | **禁止** EntityRelation ↔ Catalog 双向反写（18A 单向派生决策保持） |

### 中间件 / 上下文

- `LocaleContext`（request-scoped，仿 `SiteContext`，`app/Support/Localization/`）：
  静态属性持有当前语言，未设置时回退默认；`SetLocale` 中间件从路由 action 取语言、
  校验在当前 Site `supported locales` 内（未启用 en 则 `/en/*` 全 404），
  `app()->setLocale()` + `LocaleContext::set()`，`try/finally` 中 `clear()`。
- 路由注册顺序：**en 组（prefix en + `locale:en`）先于 zh 组（`locale:zh-CN`）注册**。
- 缓存键：`PageCache` key 经 path（含 `/en`）区分语言、经 `getHttpHost()`（含端口）区分站点。

---

## 2. 双语能力验收

| 能力域 | 结果 | 证据 |
| --- | --- | --- |
| Locale Routing（zh 默认 / en 前缀 / 未启用 404） | PASS | Localization18FTest 1–3 |
| 双语首页（`<html lang>` 正确） | PASS | Localization18FTest、两态 HTTP |
| Content 翻译（同 group 多行） | PASS | translation_group 关联，zh/en 标题独立 |
| Entity 翻译（name/slug/summary/description） | PASS | zh/en slug 可不同，同 group |
| Navigation / Menu / Footer | PASS | nav.php 两语；无译项不静默混入中文 |
| Block 翻译（Hero/CTA/FAQ/Stats/Feature） | PASS | 内容按 locale 分离，Theme 样式共享 |
| Canonical（中→中 URL / 英→英 URL） | PASS | Localization18FTest |
| hreflang（zh-CN / en / x-default，绝对 URL，无 query） | PASS | SeoHeadComposer 统一计算、Blade 纯消费 |
| OG（og:locale / og:title / og:description 随语言） | PASS | SEO 对拍 |
| Schema `inLanguage` / `url` / @id | PASS | Localization18FTest；@id 语言中性不随语言变 |
| GEO `/geo.json` + `/en/geo.json` | PASS | 两语实体 / 内容 / 边分离 |
| Sitemap `/sitemap.xml` + `/en/sitemap.xml` | PASS | 各自只列本语言 URL |
| LLMS `/llms.txt` + `/en/llms.txt` | PASS | locale-aware |
| RSS `/feed.xml` + `/en/feed.xml` | PASS | `geo_rss_enabled` 门禁 + locale |
| robots（唯一，引用两套 sitemap） | PASS | 仅 zh 组注册 |
| Search（中→中结果 / 英→英结果） | PASS | Localization18FTest |
| Multi-Site × Locale | PASS | 见 §5 |

---

## 3. 本阶段发现并修复的缺陷

18F 全流程共发现并修复多类真实缺陷（自动化测试此前未覆盖），全部 CLOSED：

| ID | 缺陷 | 修复 |
| --- | --- | --- |
| TD-42 | Catalog 硬依赖 zh-CN organization，en-only 站点无 zh-CN 主体行时 relationMap 传 null、非 nullable 签名 TypeError，首页/sitemap/geo 全 500 | buildDataset 新增关系权威基础语言 `baseLocale`（默认 zh-CN，站点无该语言主体时回退 site_default_locale），relationMap 首参改 `?Entity` |
| TD-43 | SchemaBuilder 服务区域 `area_served` en 分支无 `??`，en-only 站点无 production 时 Undefined array key 500 | 改 `(array) (area_served ?? [])` |
| TD-44 | 首页 blank 兜底 `<p>` 直接用单语 site_description，en-only / 跨语言站点泄漏另一语言描述 | blank 描述优先当前语言 `Catalog::company()['summary']`，其次 site_description，最后 ui.blank_home_lead |
| TD-45 | knowledge / products 默认 SEO 翻译键带工业措辞（Mixing / Process / Application） | lang 两语 seo.php 中性化（Knowledge Center / Product Series / Specifications） |
| TD-47 | routes/web.php 顶层函数 `PublicUrlLocalized()` 路由文件重复加载时 Cannot redeclare fatal | `if (!function_exists())` 守卫 |
| TD-48 | 站内搜索只覆盖 Content，不搜索 Entity（蓝图 §23 要求统一搜索） | 新增 `SearchResult`，SearchController 合并 contentQuery + entityQuery（仅公开落地页实体），手动分页，满足 Public Render Contract |
| TD-49 | geo.json 默认 JSON 编码把中文转义为 `\uXXXX`、URL 斜杠转义，AI 直读不友好 | FeedController::graph 加 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`；GeoGraphTest 防回归 |
| TD-50 | 英文 sitemap 首页 loc 用 `PublicUrl::base()`（无 locale 前缀），输出中文首页根地址 | SitemapBuilder 首页 loc 改 locale-aware（默认 base()、非默认 base()/{locale}）；Localization18FTest 补两语首页断言 |
| —（测试修正） | Catalog key_param_display 跨语言时连 base 语言 display 也丢失 | relationMap 新增 `$isBaseLocale`，base 语言保留手填 display |
| —（测试修正） | manual edge 测试无 ORDER BY 误返回 en 行 id（SQLite 非确定） | 显式 `where('locale', default)`；关系下拉只列默认语言实体（+防回归） |

> 另有 HeroMode 默认标题断言随 18B 中性化更新；GeoGraphBuilder site.name /
> organization.name 口径统一（新增 `siteOrgName()`，不读 org entity，与 SchemaBuilder 一致）。

---

## 4. 「LocaleContext 跨请求泄漏 P0」调查结论：**NON-DEBT（误判）**

对拍脚本在 en 预热后请求中文 `/geo.json`，用 `str_contains($body, '示例制造')` 得 N、
`str_contains($body, 'Example Manufacturing')` 得 Y，初判存在跨请求语言残留。
经三重查证**确认为误判**：

1. **SetLocale trace**：`/en/geo.json` 正确 set current=en，紧接 `/geo.json` 正确
   set current=zh-CN；中间件每请求正确重置、`finally clear()` 正常。
2. **json_decode 各层**：site.name、organization.name、entities[0].name、
   contents[0].title 全部为中文。
3. **编码检查**：原始 body 中文被 Unicode 转义（`\u793a\u4f8b...`），故对**原始 body**
   做字面 `str_contains('示例制造')` 必然失败；`Example Manufacturing` 的上下文是
   实体 metadata 的 `name_en` 字段（中文实体携带的英文名），并非返回英文 GEO。

**根因教训**：断言 JSON 中的中文必须先 `json_decode`，不能对可能被 `\u` 转义的
原始 body 做 UTF-8 字面匹配。TD-49 修复转义（中文直出）后，该类误判不再发生。
裁定已记入 Technical Debt Registry §6 NON-DEBT。

---

## 5. Multi-Site × Locale

- Site A（bilingual，zh-CN + en）：中文首页 / 英文首页、中文产品 / 英文产品、
  中文 GEO·Sitemap / 英文 GEO·Sitemap 各自正确。
- Site B（en-only，`Acme Global`）：
  - `/`（zh）→ 404；`/sitemap.xml`（zh）→ 404；
  - `/en` → 200（Acme 身份）；`/en/sitemap.xml` → 200（仅 /en paths）；`/en/geo.json` → 200；
  - B 不泄漏 A，A 不出现 B。
- A → B → A → B 多跳往复，Content / Entity / Relation / SeoMeta / Canonical /
  hreflang / Schema / GEO / Sitemap / Search / Cache / Theme / Plugin / Settings 均不串站。

---

## 6. Fresh Install / 两态

- Fresh `geo:install -n`（空站）：默认仅 zh-CN，首页中性欢迎屏，不呈现制造业 IA；
  `/en` → 404（未启用）。
- 加载 Demo（`db:seed`）：示例制造数据出现，仅来自 Example 数据层，不污染默认模板。
- 再次确认 **Blank System ≠ Demo Site**。
- Fresh demo seed 计数：settings=73、contents=7、categories=2、groups=6、facts=23、
  entities=18（zh-CN=12 / en=6）、relations=58。

---

## 7. 残留有意 CJK / 非缺陷（白名单）

以下 CJK 经裁定**有意保留**，不计缺陷：

- CSS/JS 内联开发注释中文（用户不可见）；
- 表单电话正则与中文 UI「微信」：允许中文站填微信号，微信是通用平台名；
  英文站为 "WeChat"，英文语言包无汉字；
- `contact_wechat_qr`（微信二维码，中国企业通用）；
- Organization `legalName` / `alternateName` 中文注册名（法律实体事实不随语言变，
  英文名在 name / JSON-LD 内）；
- footer / 联系 QR alt 已改用翻译键 `__('ui.qr_alt')`。

---

## 8. 回归与 Gate 证据

| Gate 项 | 结果 |
| --- | --- |
| Focused（Localization18FTest 等） | PASS（Localization18FTest 8 / 40） |
| Full Regression | **871 passed / 4653 assertions / 0 failed / 0 skipped**（497.34s） |
| Fresh Install（空站） | PASS |
| Demo Site | PASS |
| 两态真实 HTTP | PASS（Blank ≠ Demo） |
| Multi-Site × Locale | PASS |
| SEO / Canonical / hreflang / OG | PASS |
| Schema inLanguage / @id | PASS |
| GEO 两语 | PASS |
| Sitemap 两语（含首页 loc） | PASS |
| Search locale | PASS |
| Runtime 强污染复扫 | **0** |
| 日志审计（清空基线后两态请求） | **0** ERROR / Exception / Warning |
| Smoke / 临时环境清理 | 完成（serve 停止、临时 sqlite 与脚本不进 commit） |
| Git | commit + annotated tag `checkpoint-18F`，worktree clean |

---

## 9. Remaining Risk

- v1.0 Required 未闭合仍为 **3 项 P0 外部发布工程**：TD-01（GitHub Actions 云端首跑）、
  TD-02（基于最终 HEAD 重建干净 RC + Manifest + SHA-256）、TD-03（Private→Public / v1.0.0）。
- TD-46 factory/cooperation IA 制造业命名：数据驱动 404、非制造业不暴露，DEFERRED v1.1。
- 其余 v1.1 Planned（RBAC 三角色、Category 子项、500 页、Theme/Plugin SDK、
  高级搜索、Admin i18n 等）见 Technical Debt Registry §7，不阻塞 v1.0。

---

## 10. 结论

**P-STEP 18F = PASS。**

GEO Website OS 已具备真正的 zh-CN + en 前端国际化基础能力：Locale 纳入 Site 配置与
URL，Content / Entity 同 group 翻译，双语 feed / hreflang / canonical / Schema / GEO /
Sitemap / Search 全链路随语言正确变化，Multi-Site × Locale 隔离成立。

18F 结束后 **STOP**：不自动进入后续阶段，不移动 `v1.0.0-rc1`，不配置 remote / push，
不执行 Release；后续待 18G–18J（或按用户调整后的路线）全 PASS 后，才恢复
P-STEP 19A Private GitHub + Cloud CI。
