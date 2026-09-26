# P-STEP 18L-4 — Industry Template Standard（Business OS Template Matrix）

> 专题：定义 8 类 **Business OS** 模板的行业定位、商业叙事、实体 / 转化 / Schema 建议与语言策略。
> 本文为 Discovery（只读）产物。与 TD-127/TD-134 协同；主文档：`geo-template-experience-discovery-18l4.md`。

---

## 1. 定位：按「商业目的 / 叙事」分类，不是按颜色

- 行业模板差异的本质是 **Business Narrative（商业叙事结构）**：
  企业要先讲什么、后讲什么、用什么实体证明、引导什么转化。
- **不是**一套配色：颜色 / 字体由 Theme + Token 决定，可在同一 Business OS 上换品牌。
- 正式命名沿用裁定：**Business OS Template Matrix**（不叫「行业模板」）。

### 1.1 Template Family（Base DNA → OS 变体，避免行业爆炸）

```
                 Base DNA（共享 core 模板 + block registry + 渲染管线）
        ┌────────┬────────┬────────┬────────┬────────┬────────┬────────┐
        ▼        ▼        ▼        ▼        ▼        ▼        ▼        ▼
 Manufacturing SaaS    Commerce Service  Healthcare Education Construction Export
   -pro       -pro     -pro     -pro     -pro      -pro      -pro       -pro
```

- 每个 OS **只差异化**：manifest（identity/purpose/entities/conversion/seo）
  + homepage / 系统页 recipe（叙事 Section 序列）+ 中性 demo data + SEO 建议。
- **不复制** Renderer / Controller / Blade / CSS；新增行业 = 新增声明，而非新增代码。

---

## 2. 八类 Business OS 总览

| OS | industry 键 | 适合企业 | 主转化 | 核心实体 | 推荐 Schema |
|---|---|---|---|---|---|
| Manufacturing | `manufacturing` | 制造 / 工厂 / OEM / 材料 / 供应链 | inquiry | Organization, Product, Service, Factory | Organization, Product, FAQPage |
| SaaS Technology | `saas-technology` | 软件 / AI / 云 / 企业服务 | demo / signup | Organization, Product, Service, Content | Organization, Product(Software), FAQPage |
| Brand Commerce | `brand-commerce` | 消费品牌 / 电商 / 零售 | purchase | Organization, Product, Person | Organization, Product, FAQPage |
| Professional Service | `professional-service` | 咨询 / 律师 / 设计 / 服务机构 | consult | Organization, Service, Person, Case | Organization, Service, Person, FAQPage |
| Healthcare | `healthcare` | 医疗 / 生物 / 健康 | appointment | Organization, Service, Product, Person | Organization, Service, FAQPage |
| Education | `education` | 培训 / 教育 / 学校 | signup | Organization, Content, Person, Service | Organization, Course/Article, Person, FAQPage |
| Construction & Real Estate | `construction-realestate` | 建筑 / 地产 / 工程 | contact | Organization, Service, Location, Case | Organization, Service, Place, FAQPage |
| International Export | `international-export` | 外贸 / 全球化 / 出口 | inquiry / download | Organization, Product, Service, Factory | Organization, Product, FAQPage |

---

## 3. 各类首页叙事（Homepage Section 序列）

> Section 名对齐 `geo-section-semantic-standard-18l4.md`；序列为默认叙事，可在槽位内增删。

### 3.1 Manufacturing OS
`hero → trust(Stats) → product(ProductGrid) → solution(生产能力/Steps) → certificate(认证) → case(客户) → solution(合作流程) → conversion(询盘)`
- 重点证明：工厂实力、产品体系、工艺、认证、交付。
- 语言：zh/en；出口场景可 en-first（与 Export OS 组合）。

### 3.2 SaaS Technology OS
`hero → about(价值主张) → solution(FeatureGrid 核心能力) → solution(应用场景) → trust(集成/生态 LogoCloud) → case(客户) → solution(价格/方案) → conversion(Demo)`
- 重点：价值 → 能力 → 场景 → 信任 → 转化。
- 转化：demo / signup；Schema 可用 SoftwareApplication / Product。

### 3.3 Brand Commerce OS
`about(品牌故事 Hero) → product(爆品 ProductGrid) → solution(使用场景) → case/trust(用户评价 Testimonial) → solution(渠道) → conversion(购买)`
- 图片 / 情绪驱动；重点：品牌叙事 + 爆品 + 口碑 + 购买入口。

### 3.4 Professional Service OS
`hero(价值定位) → solution(服务体系 ServiceGrid) → solution(方法论 Steps) → trust(专家团队 Person) → case(案例) → conversion(咨询)`
- 重点：专业能力 + 方法论 + 专家背书 + 案例。

### 3.5 Healthcare OS
`hero(可信) → solution(技术/研发) → certificate(认证/合规) → solution(服务方案) → trust(专家) → case → conversion(预约)`
- 安全 / 信任 / 合规优先；转化多为 appointment。

### 3.6 Education OS
`hero → solution(课程体系 ContentGrid/Service) → trust(师资 Person) → solution(教学方法) → case(成果/学员) → conversion(报名)`
- 重点：课程 + 师资 + 方法 + 成果；Schema 可用 Course / Article。

### 3.7 Construction & Real Estate OS
`hero → case(项目案例) → certificate(资质) → solution(工程能力/服务) → trust(团队) → solution(流程) → conversion(咨询)`
- 重点：项目 + 资质 + 能力；实体含 Location / Place。

### 3.8 International Export OS
`hero(English-first) → trust(Global Stats/LogoCloud) → product(ProductGrid) → certificate(国际认证) → solution(出口能力/物流) → case → conversion(Inquiry/Download)`
- **English first**：全球信任、认证、出口 / 物流能力；多语言为核心，可加 zh。
- 转化：inquiry / download（资料下载）。

---

## 4. 系统页 / 详情 / 列表覆盖要求

每个 Business OS 除 homepage 外，应能通过 core 模板 + recipe 组合出：

| 页面 | 模板 | 数据来源（不复制事实） |
|---|---|---|
| 产品 / 服务 / 内容详情 | detail（Entity Context） | Entity + 固定槽 + 可选 Page override |
| 产品 / 服务 / 内容列表 | listing（Listing Context） | Entity / Content 经 grid block + 分页 |
| 联系 | contact | Site/Setting 事实 + ContactInfo + FormReference |
| 关于 / 主体 | 系统页（about 等） | Organization / Content / Media |

- V1 8 包 recipe 主要覆盖 homepage；**系统页 recipe 覆盖应在 18L-4 补齐或明确为通用 core 默认**，
  不应要求每个行业复制系统页。

---

## 5. 不复制渲染逻辑（硬约束）

1. 8 OS 共享 core 模板、block registry、Component 变体与统一 Render Context。
2. 新增 OS = 新增 manifest + recipe + 中性 demo + 截图，**不新增** Blade / Controller / CSS / Renderer。
3. 不为单一行业建垂直 block（如 ChemicalSpecifications / FactoryBlock）；
   行业差异用 Generic Block + Entity metadata / schema 表达。
4. 行业 demo data 必须可被 Blank 状态替代（Blank ≠ Demo，沿用 18B）。

---

## 6. 截图与品牌适配

- 每个 OS 的 preview 必须为**真实渲染截图**（desktop/mobile），替换当前 GD 占位。
- 同一 OS 上换品牌色 / 换 Theme，叙事结构不变、视觉整体切换（验证 Theme ↔ Template 解耦）。

---

## 7. 验收要点（供 Gate）

**核心：换行业不改代码**
- Fresh Blank → 选择某 Business OS → activate（生成首页 / 系统页骨架）→ 前台访问，全程不改 PHP/Blade/JS/CSS。
- 连续切换两个 OS（如 Manufacturing → SaaS），叙事 Section 序列正确、数据不串、可回滚。

**解耦对拍**
1. 换 Theme：内容 / 叙事不变；换 Content：Theme 不变；换 Template(OS)：Entity 数据不变。
2. 删除某 block：对应 Entity/Content 事实仍存在。
3. 公开契约：canonical / hreflang / Schema(inLanguage) / GEO / sitemap / llms / feed 逐 OS 对拍一致。
4. zh/en × light/dark、多站（A 双语 / B en-only）隔离通过、Console 0、缓存页面级失效。

---

## 8. 结论

- Business OS Template Matrix 以 Base DNA + 8 类叙事变体收敛，
  每类只差异化声明（manifest/recipe/demo/SEO），不复制渲染逻辑。
- 落地对齐 TD-127 与 **TD-134（SDK）**；Section 序列遵循 TD-132 词表。
- **STOP，等待裁定，不编码。**
