# P-STEP 18L-3 Architecture — Template Ecosystem：Package / Manifest / Recipe / SDK

- **阶段**：P-STEP 18L-3 DISCOVERY（目标架构 to-be，未授权不编码）
- **基线**：HEAD `253180d`（checkpoint-18L-2b）；1028 / 5428 / 0 / 0
- **配套**：`template-ecosystem-discovery-18l3.md`（现状 / Gap）
- **状态**：本文为目标架构与实施契约，供用户裁定；未授权不编码。

---

## 1. 目标架构（Template Pack 在五层中的位置）

```
Site（站点，拥有 supported locales / default locale）
 │
 ├── Theme（resources/themes/{name}） ............ 视觉：tokens / typography profile / variant
 │
 ├── Template Pack（resources/templates/{pack}） .. 结构 + recipe（本阶段建立）
 │      ├── manifest.json   包身份 / 能力声明 / 依赖
 │      ├── template.json   slot 定义 + 每槽允许 block
 │      ├── recipes/        默认页面组合（home/listing/detail/contact/landing）
 │      └── preview/        预览缩略（后台选择器）
 │
 ├── Page（实例） ................................. site_id + template + slug + locale + status
 │      └── PageBlock ............................. 结构化 JSON + 注册 renderer
 │
 └── Content / Entity / Relation / Media / Form ... 业务事实（权威，Pack 不复制）
```

**三条不可逾越的边界**
1. **Theme 管视觉，Template 管结构，Content/Entity 管事实**——三者正交，互不复制。
2. Template Pack **不携带业务事实**、不携带可执行 PHP/Blade/HTML；V1 只组合**已注册核心 block**。
3. Pack 注册的模板与 recipe，渲染**仍走统一管线** `CompositionRenderer` + Render Context——**不造第二套渲染 / SEO / URL**。

---

## 2. Template Package 物理目录结构（冻结建议）

```
resources/templates/{pack}/
 ├── manifest.json            # 【必需】包身份 + 能力 + 依赖（见 §3）
 ├── template.json            # 【必需】模板定义：slot + 每槽允许 block（见 §4）
 ├── recipes/                # 【可选但官方包必需】默认页面组合
 │     ├── home.json
 │     ├── listing.json
 │     ├── detail.json
 │     ├── contact.json
 │     └── landing.json
 ├── preview/                # 【可选】后台模板选择器缩略
 │     ├── home.png
 │     └── listing.png
 └── README.md               # 【可选】模板说明（第三方）
```

设计原则：
- **与 Theme 目录平行、职责分离**：视觉放 Theme，结构 / recipe 放 Template Pack；
  一个 Pack 可在 manifest 声明「推荐搭配的 Theme」，但不内嵌视觉 token（避免第二事实源）。
- Pack 内**全部是声明式 JSON + 静态预览图**，无 PHP / Blade / JS / HTML，杜绝任意代码执行。
- 「Pack 携带新 block / renderer」**V1 不开放**（见 §9 边界），v1.1 受控扩展。

---

## 3. Manifest 契约（manifest.json）

### 3.1 字段定义

| 字段 | 必需 | 类型 | 说明 / 校验 |
| --- | --- | --- | --- |
| `name` | ✅ | string | 包机器名（=目录名，slug 规则：小写字母数字/`-`），全局唯一 |
| `label` | ✅ | string | 后台展示名 |
| `version` | ✅ | string | 语义化版本 semver（`x.y.z`） |
| `description` | — | string | 包说明 |
| `author` | — | string | 作者 / 组织 |
| `homepage` | — | string(url) | 作者主页（url 校验） |
| `industry` | ✅ | enum | 商业目的 OS 标识（见 §7：manufacturing / saas / commerce / service / healthcare / education / realestate / export） |
| `templates` | ✅ | string[] | 本包提供的模板 key（须与 template.json 定义一致） |
| `supports` | ✅ | string[] | 支持的页面用途：home/listing/detail/contact/landing |
| `locales` | ✅ | string[] | 支持语言（须是 LocaleRegistry 已注册 locale：zh-CN/en） |
| `recommendedThemes` | — | string[] | 推荐搭配 Theme（仅提示，不自动激活；须存在） |
| `entityKinds` | — | string[] | 适配的 Entity 类型：product/service/article |
| `recipes` | — | string[] | 携带的 recipe key（须与 recipes/ 文件一致） |

### 3.2 示例

```json
{
  "name": "manufacturing-os",
  "label": "Manufacturing OS",
  "version": "1.0.0",
  "description": "Factory / OEM / materials website composition pack",
  "author": "GEO Website OS",
  "industry": "manufacturing",
  "templates": ["mfg-home", "mfg-listing", "mfg-detail", "mfg-contact", "mfg-landing"],
  "supports": ["home", "listing", "detail", "contact", "landing"],
  "locales": ["zh-CN", "en"],
  "recommendedThemes": ["industrial"],
  "entityKinds": ["product", "service"],
  "recipes": ["home", "listing", "detail", "contact", "landing"]
}
```

---

## 4. Template 定义（template.json）

Pack 的模板定义与现有 `config/templates.php` **同构**（slot + blocks），加载后转为 `TemplateDefinition`：

```json
{
  "templates": {
    "mfg-detail": {
      "label": "Manufacturing Detail",
      "extends": null,
      "layout": "layouts.site",
      "slots": {
        "header":  { "label": "页头", "blocks": ["entity_hero"] },
        "main":    { "label": "主体", "blocks": ["entity_specifications", "entity_steps", "entity_relations", "rich_text", "faq", "contact_info"] },
        "related": { "label": "相关", "blocks": ["entity_relations", "bottom_cta"] }
      }
    }
  }
}
```

校验规则：
- slot 名与 `blocks` 列表中的每个 block type **必须已在 `BlockRegistry` 注册**（拒绝引用不存在 block）；
- system block（entity_*/sys_*/bottom_cta）只能出现在其 `allowed` 声明的 slot；
- `extends` 若指向同包 / 已注册模板，按现有规则合并 slot；
- `layout` 必须是已存在视图（默认 `layouts.site`）。

---

## 5. Recipe 模型 + Applier（TD-118 核心）

### 5.1 Recipe 文件结构

Recipe 声明「某用途页面的默认 block 骨架」（slot + type + 排序 + 可选默认占位），**不含企业事实**：

```json
// recipes/home.json
{
  "purpose": "home",
  "blocks": [
    { "slot": "main", "type": "hero" },
    { "slot": "main", "type": "stats" },
    { "slot": "main", "type": "feature_grid" },
    { "slot": "main", "type": "product_grid", "content": { "limit": 8 } },
    { "slot": "main", "type": "content_grid" },
    { "slot": "main", "type": "faq" },
    { "slot": "main", "type": "cta" }
  ]
}
```

- 每个 block 可带 `content`（部分默认配置，如 limit / source mode）；
- 静态文案类（hero/feature/faq）默认**留空待填**或由「可选 Example Dataset」投影——recipe 与 demo 内容严格分离；
- 数据源 grid（product/service/content grid）由 BlockRegistry 按 locale 自动投影，recipe 只给 source/limit。

### 5.2 RecipeApplier（应用时机）

| 场景 | 行为 |
| --- | --- |
| 后台「新建页面」选择模板 | 可选「套用默认 recipe」→ 生成结构化空 block（管理员再编辑） |
| 激活 / 应用一个 Template Pack | 对该 Site 缺失的标准页面（home/系统页）按 recipe 创建 **draft** Page + 空 block，**不自动发布、不覆盖已有页面** |
| geo:install（出厂） | 仍由内置中性 seeder 保证 blank；**官方 recipe 作为 seeder 的数据来源**（见 §5.3） |

安全 / 边界：
- Applier 只创建 `Page`（结构身份）+ `PageBlock`（结构化 JSON），**不写业务事实**；
- 幂等：按 translation_group / system_key / slot+type 去重，重复应用安全；
- 每语言一行（zh/en），复用 Translatable，translation group 关联，不靠 slug/name 猜测；
- 缺失翻译政策沿用 18F：公开资源无目标语言版本则**不生成该 locale URL**（不跨语言 fallback）。

### 5.3 Seeder 重构方向（降低硬编码，不破坏 blank/demo）

- 将 `SystemPageSeeder` / `BlankHomepageSeeder` 中「默认有哪些 block」的**骨架**改为读取**官方 recipe 数据**
  （recipe 文件成为单一来源），seeder 只负责「在哪个 Site、以什么状态创建」；
- **blank 仍清空区块**（Blank ≠ Demo 不变）；demo 的双语 Example 文案仍由 `StructureSeeder` 独立提供；
- 本项以「不改变 fresh install / blank / demo 最终 HTTP 结果」为验收（迁移前后对拍）。

---

## 6. TemplatePackageManager（生命周期，平行 ThemeManager）

新增 `app/Support/Templates/TemplatePackageManager.php`（建议）：

| 方法 | 职责 |
| --- | --- |
| `basePath()` | `resource_path('templates')` |
| `all()` | `glob(basePath.'/*/manifest.json')` 扫描有效包（manifest + template.json 合法） |
| `exists($name)` | 包是否有效安装 |
| `invalidPacks()` | 已放置目录但清单 / 模板定义无效 → `[name => 原因]`，后台可见告警 |
| `register()` | 把有效包的模板定义合并进 `TemplateRegistry`（启动时，失败安全回退） |
| `activate($name)` | 写站点设置（如 `template_pack_active`）→ register → `PageCache::flush()` |
| `preview($name, $cb)` | 请求级预览（try/finally 复位，不写设置、不泄漏） |

- Pack 激活态属于 **Site 配置**（每 Site 可不同），不写死系统；
- 模板注册不复制 config，而是让 `TemplateRegistry` 增加「config + Pack」两个来源（Pack 为扩展、不覆盖核心）；
- 加载 / 激活失败**不得阻塞应用引导**（对齐 ThemeManager::register 的 try/catch）。

---

## 7. 八类商业目的 OS（官方 Pack / Recipe）

| OS（industry 值） | 适配 | 首页 recipe 骨架（顺序） |
| --- | --- | --- |
| **Manufacturing OS** `manufacturing` | 工厂 / OEM / 材料 / 装备 B2B | Hero → Stats → FeatureGrid(能力) → ProductGrid → EntitySteps(流程) → LogoCloud(资质) → FAQ → CTA |
| **SaaS / Technology OS** `saas` | 软件 / AI / 云服务 / API | Hero → LogoCloud → FeatureGrid → ServiceGrid(场景) → ProductGrid → Testimonial → FAQ → CTA |
| **Brand Commerce OS** `commerce` | 消费品牌 / 零售 / 电商（含品牌叙事） | Hero(大图) → MediaText(品牌故事) → ProductGrid → ContentGrid(Lookbook) → Testimonial → FormReference(Newsletter) |
| **Professional Service OS** `service` | 咨询 / 设计 / 财税 / 法务 | Hero → ServiceGrid → FeatureGrid(方法论) → ContentGrid(案例) → Stats → Testimonial → CTA |
| **Healthcare OS** `healthcare` | 医疗 / 生物 / 器械 | Hero → Stats(信任) → ServiceGrid → FeatureGrid → ContentGrid(专业) → FAQ → FormReference |
| **Education OS** `education` | 培训 / 学校 / 课程 | Hero → Stats(成果) → ServiceGrid(项目) → FeatureGrid → Testimonial → FAQ → FormReference(报名) |
| **Real Estate / Construction OS** `realestate` | 房地产 / 建筑 / 工程 | Hero → Stats → FeatureGrid → ServiceGrid → ContentGrid(项目案例) → LogoCloud(资质) → FormReference |
| **International Export OS** `export` | 出口 / 跨境 B2B（English-first） | Hero → LogoCloud(认证) → ProductGrid → ServiceGrid → ContentGrid → FAQ → CTA；zh/en × light/dark 全组合 |

铁律：每个 OS Pack **只提供结构 + recipe**，不产生工厂 / 产能 / 价格 / 课程 / 科室等业务数据；
hreflang / canonical / inLanguage 沿用 18F 契约。

---

## 8. 第三方 Template SDK 规范（G7）

交付一份 `docs/templates/template-sdk.md`（建议路径），内容：

1. **快速开始**：目录骨架 + 最小 manifest / template / recipe 模板（可复制）；
2. **Manifest 字段与校验规则**（§3）；
3. **Slot / Block 契约**：只能引用已注册 block；每 slot 的 block 准入；system block 不可手动添加；
4. **Recipe 契约**：默认 block 骨架写法、content 部分配置、留空与 Example Dataset 的区别；
5. **GEO 契约**：
   - 模板不得改变 H1 / JSON-LD / canonical / URL；
   - block 语义保持 `section/h1/h2/p/a/button/nav/details`（data-* 语义属 18L-4，本阶段先不强制）；
6. **本地化**：recipe 对每语言如何提供；公开内容不跨语言 fallback；
7. **打包 / 分发 / 版本**：semver、目录命名、预览图规格；V1 文件放置安装（ZIP / Marketplace 为 v1.1）；
8. **校验清单（提交前自检）**：manifest 合法、所有 block 已注册、fresh install 可激活、GEO 回归通过。

---

## 9. 安全与边界（V1 封闭项）

| 能力 | V1 | 说明 |
| --- | --- | --- |
| Pack 组合已注册核心 block | ✅ | 主能力 |
| Pack 携带新 block 类型 / renderer | ❌ → v1.1 | 避免任意代码 / 安全 / 兼容失控；v1.1 以「注册制 + 白名单 + 沙箱约束」受控开放（新增独立 TD，DEFERRED） |
| Pack 内任意 Blade/HTML/PHP/JS | ❌ 永久禁止 | 只允许声明式 JSON + 静态预览图 |
| Pack 携带视觉 token | ❌ | 视觉归 Theme；Pack 仅推荐 Theme |
| Pack 携带业务事实 | ❌ | 业务事实归 Content/Entity |
| 在线 / ZIP / Marketplace 安装 | ❌ → v1.1（TD-110） | V1 文件放置 + 后台发现 |

---

## 10. Cache 失效依赖（随 18L-3 建立）

| 变更 | 失效范围 |
| --- | --- |
| Block 内容 / 排序 / 显隐 | 该 Page（页面级失效，已有） |
| Template slot / recipe | 使用该模板 / 该 Pack 的全部 Page（`forgetTemplate`） |
| Pack 激活 / 切换 | 该 Site 公开页（activate 内 flush） |
| Theme token / typography / variant | 使用该 Theme 的 Site 公开页（18L 已建） |
| Entity / Content / Relation | 对应 detail + 相关 listing / grid（已有） |
| Site / locale 设置 | 该 Site 公开页 + feed（已有） |

- 不全站粗暴 flush，也不留旧页；PageCache key 已含 site + locale + 版本（继续复用）。

---

## 11. 实施任务清单（建议）

> 建议 V1 Pack 只组合核心 block，故无需新增 block；任务聚焦「包 + manifest + recipe + manager + sdk」。

| 任务 | 内容 | 对应 |
| --- | --- | --- |
| 18L-3-01 | 建 `resources/templates/` 根目录 + 目录规范（README / .gitignore） | G1 |
| 18L-3-02 | Manifest 校验器 `TemplateManifestValidator`（字段 / 枚举 / 依赖 / block 存在性） | G2 |
| 18L-3-03 | `TemplatePackageManager`（all/exists/invalid/register/activate/preview） | G3 |
| 18L-3-04 | `TemplateRegistry` 扩展「config + Pack」双来源（不覆盖核心、失败安全） | G5 |
| 18L-3-05 | Recipe 数据模型 + `RecipeApplier`（生成结构化空 block、幂等、每语言一行） | G4 |
| 18L-3-06 | Seeder 骨架改读官方 recipe（blank/demo HTTP 迁移前后对拍不变） | G4 |
| 18L-3-07 | 8 类商业目的 OS 官方 Pack + recipe 数据 + preview 缩略 | G6 |
| 18L-3-08 | 后台 Template Pack 管理 UI（列表 / 激活 / 预览 / 无效告警 / Site 隔离） | G3 |
| 18L-3-09 | 后台「新建页面套用 recipe」接线 | G4 |
| 18L-3-10 | 第三方 `docs/templates/template-sdk.md` | G7 |
| 18L-3-11 | 测试：Pack 发现 / 校验 / 激活 / recipe 应用 / 多站 / locale / cache / GEO 回归 | 验收 |
| 18L-3-12 | 登记新 TD（Pack 携带 block → v1.1）+ 更新 TD-117/118 + Gate 文档 | TD |

### Gate 切分建议（两次，供裁定）

- **18L-3a Foundation + 1 个示范 Pack**：任务 01–05 + 用 **Manufacturing OS** 端到端验证
  （放包 → 发现 → 激活 → recipe 应用 → 前台，零代码），PASS / STOP。
- **18L-3b 8 OS + Admin + SDK + Seeder 收口**：任务 06–12，全量迁移与对拍，PASS / STOP。

---

## 12. 验收标准（PASS 条件）

- 模板包可作为**单一物理目录**分发：放目录即被发现、激活、预览、回滚，非法包可见告警；
- Recipe 可携带并被应用：生成结构化空 block，**不复制业务事实、不写任意代码**；
- 8 类商业目的 OS 官方 Pack 全部可激活，页面 / 列表 / 详情 / 联系 / Landing recipe 成立；
- 第三方 SDK 规范完整，可据以从零创建一个能被校验通过的 Pack；
- GEO / Schema / canonical / URL / Sitemap / LLMS 公开契约**零退化**（迁移前后对拍）；
- Multi-Site × Locale、zh/en × Light/Dark、PageCache 隔离全部通过；
- Runtime 污染 0、新增产品 ERROR 0、Full Regression 0 failed / 0 skipped、worktree clean。

---

## 13. STOP

本架构为 Discovery 建议，**等待用户裁定**：
1. Package 目录结构与 Manifest 字段（§2/§3）；
2. V1 Pack 是否封闭携带 block（§9，建议封闭）；
3. 8 类 OS 范围与命名（§7）；
4. Gate 切分（§11，建议两次）与 TD 编号。

裁定授权后再进入 Implementation；未授权不编码。
19A / remote / push / rc1 / Release 继续 HOLD。
