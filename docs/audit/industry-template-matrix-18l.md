# P-STEP 18L — Industry Template Matrix（行业 × 品牌气质）

- **阶段**：P-STEP 18L DISCOVERY（行业矩阵建议，未授权不编码）
- **基线**：HEAD `2d2fbaf`（checkpoint-18K）；1001 / 5247 / 0 / 0
- **原则**：行业包**不按颜色分类、按「行业 × 品牌气质」**；只改视觉语言 + 结构 recipe，**不产生行业业务数据**（工厂 / OEM / 产能 / 配方属 Demo Content / Example Dataset）。
- **配套**：`design-system-template-discovery-18l.md`、`template-ecosystem-architecture-18l.md`

---

## 1. 口径映射：8 类业务页面 ↔ 6 模板

现有 TemplateRegistry 为 6 模板（base / home / landing / listing / detail / contact）。用户列举的 8 类页面通过 **Entity / Content Context** 收敛，不复制独立 Blade：

| 业务页面 | 使用模板 | Context | 说明 |
| --- | --- | --- | --- |
| Home | home | Page（is_home） | 首页 Composition |
| Landing | landing | Page | 自由组合落地页 |
| Product Listing | listing | Listing（kind=product） | 产品列表 |
| Service Listing | listing | Listing（kind=service） | 服务/场景列表 |
| Article / Knowledge Listing | listing | Listing（kind=article） | 内容/知识列表 |
| Product Detail | detail | Entity（product） | 产品详情 |
| Service Detail | detail | Entity（service） | 服务详情 |
| Article Detail | detail | Content（article） | 文章详情 |
| Contact | contact | System Page | 联系 + FormReference |

> 行业包在这些模板之上，通过 **tokens + typography profile + component variants + recipe** 形成差异化气质；**不新增行业专属模板 / 行业专属 block**。

---

## 2. 八类「行业 × 品牌气质」矩阵

> 每个 Industry Package = `theme.json`（tokens/typography/components）+ `template.json`（recipe）+ manifest；下列「视觉参数」为气质方向建议，具体取值在实现时按 AA 对比度与 token 派生确定。

### 2.1 SaaS / AI 科技

- **适合**：软件、AI、云服务、API、数据平台
- **气质**：深色可选、大留白、数据感、渐变微光、未来感
- **视觉语言**：
  - color：冷调靛蓝 / 紫蓝，深色 hero + 微光渐变；accent 青色小面积
  - typography：紧凑 display、字距略负、数字等宽
  - spacing：大留白、section 宽松
  - radius / shadow：中半径、柔和微光阴影
  - component：hero `split-wide`/`full-bleed`、card `soft-elevated`、button `solid`/`pill`
- **首页 recipe**：Hero → Trust LogoCloud → FeatureGrid → Scenario(ContentGrid) → Pricing/ProductGrid → Testimonial → FAQ → CTA
- **不自动产生**：任何软件功能 / 模型 / 订阅档位业务数据

### 2.2 制造工业

- **适合**：制造、装备、零部件、材料、工业 B2B
- **气质**：稳定、精密、工程感、可信
- **视觉语言**：
  - color：深蓝 / 钢灰为主，橙色 / 琥珀 CTA 点缀（action 仍由品牌基色派生保证 AA）
  - typography：规整、信息密度中高、数字清晰
  - spacing：紧凑、结构化
  - radius / shadow：小半径、硬朗轻阴影
  - component：hero `split`、card `bordered`、button `solid` 直角/小圆角
- **首页 recipe**：Hero → Capability(FeatureGrid) → Process(EntitySteps) → ProductGrid → Quality/Stats → Certification(LogoCloud) → FAQ → CTA
- **不自动产生**：工厂 / 车间 / OEM / 配方 / 产能 / 报价（这些属 Content / Example）

### 2.3 新消费品牌

- **适合**：消费品、零售、美妆、食品饮料、生活方式
- **气质**：高级、情绪化、图片驱动、轻奢
- **视觉语言**：
  - color：暖色 / 奶油 / 低饱和，品牌主色柔和
  - typography：编辑感 serif/display 可选、行距舒展
  - spacing：宽松、图片占比大
  - radius / shadow：大半径或直角（依气质）、柔和阴影
  - component：hero `full-bleed` 大图、card `soft`、button `pill`
- **首页 recipe**：Hero(大图) → Brand Story(MediaText) → ProductGrid → Lookbook/ContentGrid → Testimonial → Newsletter(FormReference)
- **不自动产生**：具体 SKU / 门店 / 价格数据

### 2.4 教育培训

- **适合**：学校、培训、教育机构、课程平台
- **气质**：清晰、亲和、成长感
- **视觉语言**：
  - color：明快但不刺眼（蓝绿 / 暖黄点缀），大面积柔和
  - typography：清晰易读、标题亲和
  - spacing：舒适、分区明确
  - radius / shadow：大圆角、软阴影
  - component：hero `split`/`centered`、card `soft`、button `rounded`
- **首页 recipe**：Hero → Stats(成果) → Program(ServiceGrid) → FeatureGrid → Testimonial → FAQ → CTA(报名 FormReference)
- **不自动产生**：课程 / 师资 / 课表业务数据

### 2.5 医疗健康

- **适合**：医疗、诊所、健康、医药器械
- **气质**：安全、信任、清洁
- **视觉语言**：
  - color：洁净蓝 / 青绿、白色软面，状态色克制
  - typography：高可读、稳重
  - spacing：通透、洁净
  - radius / shadow：中圆角、极轻阴影
  - component：hero `split`、card `bordered`/`soft`、button `solid`
- **首页 recipe**：Hero → Trust(Stats) → ServiceGrid → FeatureGrid → Professional(ContentGrid) → FAQ → Contact(FormReference)
- **不自动产生**：科室 / 医生 / 诊疗项目数据

### 2.6 企业服务

- **适合**：咨询、法律、财税、B2B 服务、专业机构
- **气质**：专业、商务、权威
- **视觉语言**：
  - color：深蓝 / 藏青、中性灰，克制点缀
  - typography：传统稳重、信息层级清晰
  - spacing：规整、密度适中
  - radius / shadow：小/中半径、轻阴影
  - component：hero `split`、card `bordered`、button `solid`
- **首页 recipe**：Hero → ServiceGrid → FeatureGrid(方法论) → Case(ContentGrid) → Stats → Testimonial → CTA
- **不自动产生**：业务领域 / 顾问 / 案例数据

### 2.7 外贸国际化

- **适合**：出口贸易、跨境 B2B、面向多语市场的企业
- **气质**：国际、简洁、英文友好
- **视觉语言**：
  - color：中性 + 单一品牌色，跨语言一致
  - typography：**优先英文排版优化**（标题长度 / 行高 / 按钮宽度）、多语切换显著
  - spacing：宽松、简洁
  - radius / shadow：中半径、轻阴影
  - component：hero `split`、card `bordered`、button `solid`
- **首页 recipe**：Hero → Trust LogoCloud → ProductGrid → ServiceGrid → Certification → FAQ → CTA；zh/en × light/dark 全组合
- **不自动产生**：产品目录 / 市场 / 单证数据；hreflang / canonical 沿用 18F 契约

### 2.8 品牌官网

- **适合**：集团、品牌形象站、单一旗舰产品
- **气质**：极简、高端、内容叙事
- **视觉语言**：
  - color：极简黑白灰 + 单一品牌色
  - typography：强排版层级、大量留白、叙事感
  - spacing：超大留白、比例驱动
  - radius / shadow：直角或极小圆角、几乎无阴影
  - component：hero `full-bleed`、card 极简、button `outline`/`solid`
- **首页 recipe**：Hero(全屏) → MediaText 叙事段落 ×N → ProductGrid/ServiceGrid → Stats → CTA
- **不自动产生**：品牌故事文案 / 组织架构数据

---

## 3. 现有 8 视觉预设与行业包关系

18D 已建立 8 个**视觉预设**（按品牌基色 hue 区分），18K 统一为 Brand-led 配色。18L 的「行业包」是其**上层组合**，而非替换：

| 视觉预设（18D/18K，颜色） | 品牌基色 / hue | 可被哪些行业包默认引用 |
| --- | --- | --- |
| professional | `#2563EB` / hue221 | 企业服务、外贸 |
| industrial | `#1D6FA5` / hue204（accent 琥珀） | 制造工业 |
| commerce | `#E11D48` / hue347 | 新消费品牌 |
| technology | `#4F46E5` / hue243 | SaaS/AI |
| education | `#7C3AED` / hue262 | 教育培训 |
| lifestyle | `#0D9488` / hue175 | 新消费、品牌官网 |
| finance | `#1E3A8A` / hue224 | 企业服务、金融 |
| healthcare | `#0E7490` / hue193 | 医疗健康 |

> 关系：**行业包 = 视觉预设（颜色）+ typography profile + component variants + recipe + manifest**。自定义品牌仍可通过 Brand Seed 覆盖任意行业包的颜色，而保留其排版 / 结构气质。

---

## 4. 解耦验证（必须成立）

- **Theme A（SaaS）↔ Theme B（制造）切换**：内容 / Entity 不变，仅视觉语言 + recipe 形态变化；
- **选制造工业包**：不出现工厂 / OEM / 产能数据，除非 Example Dataset / 管理员自建；
- **选教育培训包**：不出现制造内容；
- **行业包 × zh/en × light/dark**：四组合（实际八组合）全部成立；
- **GEO 回归**：换行业包后 Schema / sitemap / llms / entity / canonical / URL 公开契约不变，区块仅新增 data-* 语义。

---

## 5. STOP

本矩阵为 Discovery 建议，**等待用户裁定行业包范围、优先级与 Gate 切分**；未授权不编码，19A / remote / push / rc1 / Release 继续 HOLD。
