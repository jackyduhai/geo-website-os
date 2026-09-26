# P-STEP 18L-4 — Template SDK Specification

> 专题：第三方 / AI 开发者创建 GEO Website OS 模板包的**单一、版本化、可校验规范**。
> 本文为 Discovery（只读）产物。对应 TD：**TD-134**；与 TD-130/131/132 协同。
> 主文档：`geo-template-experience-discovery-18l4.md`。

---

## 1. SDK 原则

1. **声明式**：模板只描述「主题引用 + 页面 ↔ 槽位 ↔ block 组合 + 中性默认值」，不含可执行逻辑。
2. **封闭**：V1 包内禁止 PHP / Blade / Controller / JS / SQL；渲染一律走平台统一管线。
3. **可校验**：所有字段经 `template:validate`（含 `--strict`）自检；安全项 fail-closed。
4. **可迁移**：声明版本与迁移规则，平台升级可安全演进（TD-131）。
5. **不复制事实**：只引用 Content / Entity / Media / Form；业务事实不随包强制落地。
6. **GEO 稳定**：不改变 H1 / Schema / URL / SEO 公开契约；Section 语义用受控词表。

---

## 2. 包目录结构（标准布局）

```
{pack-id}/
├── manifest.json                 # 必需：身份 / 目的 / 实体 / 转化 / SEO / 兼容版本
├── theme.json                    # 必需：引用主题 token profile（不自带样式管线）
├── template.json                 # 可选：包级模板（不可覆盖同名核心模板）
├── README.md                     # 必需：适用企业 / 截图 / 使用说明
├── recipes/
│   ├── homepage.json             # 首页配方
│   ├── about.json                # （可选）系统页 / 落地页配方
│   ├── products.json
│   └── contact.json
├── defaults/                     # 中性建议默认值
│   ├── settings.json
│   ├── menus.json
│   └── seo.json
└── preview/
    ├── desktop.webp              # 1280×800 真实截图
    └── mobile.webp               # 390×844 真实截图
```

- `{pack-id}`：小写字母 / 数字 / 连字符（`^[a-z0-9\-]+$`），与目录同名。
- V1 为**文件 / 目录分发**（放入 `resources/templates/`）；不做在线 zip 上传 / Marketplace。

---

## 3. `manifest.json` 规范

### 3.1 字段

| 字段 | 必需 | 说明 |
|---|---|---|
| `id` | ✅ | 与目录同名 |
| `name` | ✅ | 展示名 |
| `version` | ✅ | SemVer（如 `1.0.0`） |
| `identity.name` | ✅ | 模板身份名 |
| `identity.industry` | ✅ | 受控行业词表（8 类，见 §9） |
| `identity.audience` | 建议 | 适用受众字符串数组（缺失为 WARNING） |
| `purpose.primary` | ✅ | 受控主目的（lead_generation / brand_authority / product_showcase / ecommerce_sales / booking / signups / information） |
| `purpose.secondary` | 可选 | 受控目的数组 |
| `entities` | ✅ | 大写实体数组（Organization/Product/Service/Factory/Content/Person/Location/Case） |
| `conversion.primary` | ✅ | 受控主转化（inquiry/download/contact/demo/appointment/purchase/signup） |
| `conversion.secondary` | 可选 | 受控转化数组 |
| `seo.recommended_schema` | 建议 | Schema 类型数组（缺失为 WARNING） |
| `locales` | ✅ | 支持语言（`zh-CN` / `en`） |
| `theme` | ✅ | 引用已注册主题 |
| `requires` | ✅ | 兼容版本 + block 依赖（§7） |
| `pages` | ✅ | 可生成页面（home/about/products/contact…） |

### 3.2 完整示例

```json
{
  "id": "manufacturing-pro",
  "name": "Manufacturing Pro",
  "version": "1.0.0",
  "identity": {
    "name": "Manufacturing Professional",
    "industry": "manufacturing",
    "audience": ["B2B manufacturer", "OEM factory", "industrial supplier"]
  },
  "purpose": { "primary": "lead_generation", "secondary": ["brand_authority", "product_showcase"] },
  "entities": ["Organization", "Product", "Service", "Factory"],
  "conversion": { "primary": "inquiry", "secondary": ["download", "contact"] },
  "seo": { "recommended_schema": ["Organization", "Product", "FAQPage"] },
  "locales": ["zh-CN", "en"],
  "theme": "industrial-blue",
  "requires": {
    "platform": "1.0",
    "theme_api": "1.0",
    "component_api": "1.0",
    "geo_contract": "1.0",
    "seo_contract": "1.0",
    "components": ["hero", "card", "stats", "product_grid", "faq", "cta", "form_reference"]
  },
  "pages": ["home", "about", "products", "contact"]
}
```

---

## 4. `theme.json` 规范

- 只引用 / 声明**已注册主题 token profile**（颜色 / 字体 / 密度等通过平台 token 表达），
  **不自带 CSS 管线、不覆盖核心组件样式**。
- 第三方包不应在 theme.json 写孤立 Hex 作为强制值；品牌差异通过 token 键引用，由 ThemePalette 派生。

---

## 5. `template.json`（可选，包级模板）

- 结构对齐 `config/templates.php`：`label` / `extends` / `slots` / 可选 `layout`。
- 槽位支持简化写法：`"*"`、block 类型数组、`{ "label":, "blocks": }`。
- **限制**：包模板**不能覆盖同名核心模板**；`extends` 可继承核心 / 已注册包模板并合并槽位。
- 不指定 `layout` 时默认 `layouts.site`。

---

## 6. `recipes/*.json` 规范

### 6.1 Recipe 结构

| 字段 | 说明 |
|---|---|
| `key` | 配方标识（如 homepage） |
| `target` | 落地目标**对象**：`{ "type": "home" }` / `{ "type": "system", "key": "contact" }` / `{ "type": "page", "slug": "landing-x" }` |
| `template` | 使用的模板 key（可省略，按 target 推断） |
| `blocks` | **扁平 block 实例数组**（非 slots 嵌套），按数组顺序渲染 |
| block 实例 | `slot`（槽位）/ `type`（block 类型）/ `content`（对齐该 block fields 的数据对象）/ 可选 `sort` |

### 6.2 示例（首页，节选）

```json
{
  "key": "homepage",
  "target": { "type": "home" },
  "template": "home",
  "blocks": [
    {
      "slot": "main",
      "type": "hero",
      "content": {
        "eyebrow": { "zh-CN": "制造实力 · 品质交付", "en": "Manufacturing Strength · Quality Delivery" },
        "title": { "zh-CN": "值得信赖的制造合作伙伴", "en": "Your Trusted Manufacturing Partner" },
        "buttons": [
          { "label": { "zh-CN": "获取方案", "en": "Get a Solution" }, "url": "/contact/", "style": "primary" }
        ]
      }
    },
    {
      "slot": "main",
      "type": "product_grid",
      "content": { "source": { "mode": "all" }, "limit": 6 }
    },
    {
      "slot": "main",
      "type": "cta",
      "content": {
        "title": { "zh-CN": "准备好开始合作了吗？", "en": "Ready to Start?" },
        "buttons": [
          { "label": { "zh-CN": "立即咨询", "en": "Contact Us" }, "url": "/contact/", "style": "primary" }
        ]
      }
    }
  ]
}
```

### 6.3 规则

- 所有 block 必须已注册、且允许进入目标模板槽位（validate Layer2/3）。
- grid 类用 `source` 对象（`mode`: all/line/picked/current/related + 必要参数），不写字符串。
- locale 字段用 `{ "zh-CN":, "en": }` map；内部 URL 由平台按语言前缀化。
- 按钮 / 链接 url 必须通过 scheme 白名单（§安全，`/`、`http(s)://`、相对；禁止 javascript:/data:）。

---

## 7. Compatibility（`requires`，TD-130）

```json
"requires": {
  "platform": "1.0",
  "theme_api": "1.0",
  "component_api": "1.0",
  "geo_contract": "1.0",
  "seo_contract": "1.0",
  "components": ["hero", "card", "form"]
}
```

- 同 major、`>=` 要求 → 兼容；major 不匹配 / 低于最低 → **BLOCK**。
- `components` 中列出的 block 若平台未注册 → BLOCK。

---

## 8. Migration（TD-131）

包可在升级时声明迁移（建议 `migrations/{from}__{to}.json` 或 manifest `migrations`）：

```json
{
  "from": "1.0.0",
  "to": "1.1.0",
  "steps": [
    { "op": "renameBlock", "from": "old_hero", "to": "hero" },
    { "op": "renameField", "block": "stats", "from": "num", "to": "value" },
    { "op": "deprecateBlock", "block": "legacy_band" },
    { "op": "replaceComponent", "from": "card.old", "to": "card.default" }
  ]
}
```

- V1 仅支持声明式 rename / deprecate / replace-map（槽位级幂等、可对拍 / 回滚）。
- `template:migrate {pack}` 在 Site 作用域执行，仅管理带 marker 的配方块，结束触发页面级失效。

---

## 9. `defaults/` 规范（必须中性）

- `settings.json`：键 → 建议值；只在空值时写入（`--force` 覆盖）。
- `menus.json`：`main` / `footer` 项（label 用 locale map / 中性、url 走 SafeUrl）。
- `seo.json`：`site` 数组为**站点级** SeoMeta（content/entity/page 关联全 null），按 locale。
- **禁止**把特定行业业务事实（OEM / 采购 / 垂直话术）作为强制默认。

---

## 10. `preview/` 规范

- `desktop.webp`（约 1280×800）、`mobile.webp`（约 390×844），须为**真实渲染截图**。
- 后台截图预览受控读取（realpath 防目录穿越、view 白名单、`X-Robots-Tag: noindex`）。
- 占位图必须带可见 placeholder 标记，不得冒充成品（发布 Gate 检查）。

---

## 11. Section 语义声明（TD-132）

- block 的 Section 语义（section/purpose/entity/conversion）由平台 `config/blocks.php`
  的 BlockType `semantic` 默认提供（见 `geo-section-semantic-standard-18l4.md`）。
- 第三方包**不需要、也不允许**在 recipe 里自由写 Section 类型；如需覆盖，只能取受控词表值，
  且经 validate 校验。

---

## 12. 校验 / 自检 / 分发

```bash
php artisan template:validate {pack}            # 三层 + 安全扫描 + 版本兼容
php artisan template:validate {pack} --strict   # CI：WARNING 也失败
php artisan template:activate {pack}            # 校验通过后激活 + apply recipes
php artisan template:bootstrap {pack}           # 显式落地中性 defaults
php artisan template:deactivate                 # 回滚到核心模板
php artisan template:migrate {pack}             # 版本升级迁移
```

- 分发：V1 直接以目录形式随仓库 / 文件交付；放入 `resources/templates/` 后 `template:list` 可见。
- 不开放在线上传 / Marketplace（v1.1）。

---

## 13. 发布前 Checklist

- [ ] 目录名 == manifest.id，命名符合 `^[a-z0-9\-]+$`
- [ ] manifest 必填字段齐全、SemVer 合法、受控词表无越界
- [ ] `requires.*` 版本与当前平台兼容、components 全部已注册
- [ ] recipes 中 block / slot / source / locale / url 全部通过校验，无危险 scheme
- [ ] defaults 中性、无行业业务事实
- [ ] preview 为真实截图（非占位）
- [ ] `template:validate --strict` 退出码 0
- [ ] activate → 前台 200，H1 / Schema / canonical / sitemap / llms 对拍不变
- [ ] zh/en × light/dark、多站隔离通过；Console 0；页面级缓存失效正确

---

## 14. 结论

- 本 SDK 把模板包的结构、Manifest、theme/template、recipe、defaults、preview、
  Section 语义、兼容性、迁移、校验与分发一次定义为单一入口。
- 落地归入 **TD-134**，是第三方 / AI 模板生态的契约基础。
- **STOP，等待裁定，不编码。**
