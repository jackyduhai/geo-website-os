# P-STEP 18G — Page Composition / Template System Discovery

- 日期：2026-09-23
- 基线 HEAD：`a5d2c5e`（= annotated tag `checkpoint-18F`）
- Regression：872 passed / 4655 assertions / 0 failed / 0 skipped；Worktree clean
- 阶段：**18G Discovery（纯只读，未修改任何代码 / 数据 / 配置）**
- 审计链路：`Page → Template → Block → Component → Content / Entity`
- 方法：不相信模型描述，以真实代码为准——Models、Support、Controllers、Blade、routes、后台表单逐文件只读核对。

---

## 0. TL;DR（结论速览）

1. **首页 = 真正的 DB 驱动 Block Composition，链路完整成立 ✅**。区块行（`PageBlock`）+ 类型注册表（`config/home_blocks.php`）+ 控制器按 sort 取数 + Blade `@includeIf` 动态渲染 + 强后台装修器；空站回落到中性欢迎屏。
2. **其余 7 类页面：没有 Page 层、没有 Template 层 ❌**。14 处 `return view` 全部由 Controller 硬编码 Blade，结构（section 的有无 / 顺序）不可在后台重组、不可选模板；仅"内容"与"空则隐藏"是数据驱动。
3. **Landing Page：完全缺失 ❌**。无自定义页面实体 / 路由 / 模板 / block 组合，管理员无法创建自由落地页。
4. **核心判题答案**：换一个完全不同的品牌，不改 PHP / Blade / JS / CSS——**首页可重组**；**列表 / 详情不能重组 / 不能选模板**；**Landing 无法创建**。
5. 当前形态是"**首页可装修 + 其余页数据模板化**"，距离"**任意页面可组合的 Website OS**"差一个通用 Block + Template + Page 层。
6. 登记 **7 项缺口 TD-53..TD-59**（正式优先级 / 验收标准 / v1.0 裁定见 `page-composition-architecture-18g.md`）。

---

## 1. 首页链路（成立，逐段取证）

| 链路段 | 实现 | 证据 |
| --- | --- | --- |
| 区块模型 | `app/Models/PageBlock.php`（site-scoped，`BelongsToSite` + 全局 `SiteScope`） | 字段：`type` / `page` / `sort` / `is_active` / `limit` / `category_id` / `title` / `subtitle` / `content`(JSON)；关系 `category`；scope `active()` / `forPage($page)`；解析 `cfg()` / `items()` / `pickedIds()` / `kind()` / `typeLabel()` |
| 类型注册表 | `config/home_blocks.php` | 16 个 type；`kind` ∈ hero / midbanner / simple / items / steps / source；含 `label` / `front`（前台锚点对照）/ `fields` / `heading` / `subtitle` 开关；建议 `sort` |
| 控制器取数 | `HomeController::index()` | `PageBlock::forPage('home')->active()->with('category')->get()`，`keyBy('type')`；按区块组装 Catalog / Banner / 选稿数据 |
| 动态渲染 | `resources/views/site/home.blade.php` | `@forelse($blocks as $blk) @includeIf('site.home.'.$blk->type, ['blk'=>$blk]) @empty …中性欢迎屏… @endforelse` |
| 区块局部 | `resources/views/site/home/{type}.blade.php` | hero / scenes / capabilities / products / params / stats / workshops / mid_banner / cooperation / steps / cases / knowledge / news / cta / faqs / facts |
| 条目缺省源 | `app/Support/HomeBlockDefaults.php` | items 型区块未自定义时从站点隔离 `Catalog` 投影；一旦保存 `content.items` 即以其为准（单一覆盖，不双向同步） |
| 后台装修 | `resources/views/admin/display/blocks.blade.php` + `Admin/BlockController.php` | hero A/B/C 模式切换 + 眉题 / 文案；items 增删条目 + 内置图标 / 自定义图；source 选来源栏目 / 条数 / 手选稿件；排序、显隐；首屏 / 中部横幅就地同步 |

**结论**：首页的"渲染顺序 / 显隐 / 标题 / 选稿"全部由 `page_blocks` 决定，改首页结构不需要动代码——这一段是真正产品化的组合能力。

---

## 2. 其余页面：链路断裂（14 处硬编码模板）

全局检索 Site 控制器 `return view(`，**14 处全部写死模板、无任何动态模板选择**：

| 控制器 | 行 | 硬编码视图 |
| --- | --- | --- |
| HomeController | 133 | `site.home`（唯一走 Block 组合） |
| ProductController | 92 / 171 / 230 | `site.products.index` / `site.products.line` / `site.products.show` |
| SolutionController | 52 / 104 | `site.solutions.index` / `site.solutions.show` |
| KnowledgeController | 90 | `site.knowledge.index`（channel 复用） |
| PageController | 151 / 211 | `site.content`（文章）/ `site.category`（栏目） |
| AboutController | 78 | `site.about.{profile\|history\|culture}` |
| ContactController | 64 | `site.contact`（固定字段表单） |
| FactoryController | 117 | `site.factory` |
| CooperationController | 61 | `site.cooperation` |
| SearchController | 88 | `site.search` |

**这些页面的真实状态**：
- 组件层面普遍有"数据门控"（空值 / 无数据则隐藏，见 18E 能力对账），所以不会出现裸 0 / 空壳；
- 但**结构本身写死**：管理员不能增删 / 排序 / 重组 section，不能为同类实体（不同产品 / 服务）选择不同展示模板，不能向列表 / 详情 / 联系页插入通用 block。

**后台 Block 能力边界（已取证）**：
- `Admin/BlockController` 只有 `index()` / `update()`，且 `index()` 固定 `forPage('home')`；
- **没有 `create / store / destroy`**，不能新增 / 删除区块行，也不能给 `page ≠ home` 的页面组合区块；
- 路由 `routes/admin.php` 仅注册 `GET blocks` + `PUT blocks/{block}`。

**Block 注册表边界**：`config/home_blocks.php` 的 16 个 type 全部是"首页 section"，**没有可在任意页面复用的通用 block**（富文本 / 标题、特性网格、媒体横幅、CTA 条、规格 / 参数表、相关列表、客户评价、Logo 墙、FAQ 折叠、表单块、团队、图库、价格、步骤等）。

---

## 3. Landing Page：完全缺失

- 没有"自定义页面"模型（无 `Page` 模型）、没有对应后台路由 / 表单、没有前台落地路由 / 模板；
- 现有的"单页"只能用 `Category(type=page)` + 其下一条 `Content` 实现，`PageController::renderCategory` 命中 `isSinglePage()` 后渲染 `site.content`——**正文（body）驱动，不是 block 组合**，无法自由拼 hero / 特性 / CTA / 表单。
- 因此"活动页 / 产品落地页 / 下载页 / 预约页"等典型营销落地页无法仅靠 Admin 搭建。

---

## 4. 八类模板对账

| # | 模板类型 | 当前实现 | 内容 / 显隐数据驱动 | 结构可重组 / 可选模板 | 状态 |
| --- | --- | --- | --- | --- | --- |
| 1 | **Home** | `home.blade.php` + PageBlock 循环 | ✅ | ✅ | **MATCH** |
| 2 | **Listing** | products/index、knowledge/index、solutions/index、category | 部分 ✅ | ❌ | **PARTIAL** |
| 3 | **Detail** | products/show、solutions/show | 部分 ✅ | ❌ | **PARTIAL** |
| 4 | **Article** | `site.content`（PageController） | 正文驱动 | ❌ | **PARTIAL** |
| 5 | **Product** | products/show（八区块写死） | 部分 ✅ | ❌ | **PARTIAL** |
| 6 | **Service** | solutions/show | 部分 ✅ | ❌ | **PARTIAL** |
| 7 | **Contact** | `site.contact`（固定字段表单） | 部分 ✅ | ❌ | **PARTIAL** |
| 8 | **Landing** | **不存在** | — | — | **MISSING** |

---

## 5. 核心判题（产品终极标准）

> 换成一个完全不同的企业 / 品牌 / 行业 / Logo / 颜色 / 语言 / 内容，不改 PHP / Blade / JS / CSS，仅靠 Admin + Theme + Content + Entity + Media + Menu + Block + Setting，能否完成官网的主要内容与视觉定制？

| 页面族 | 结论 |
| --- | --- |
| 首页 | ✅ 可重组（区块显隐 / 排序 / 标题 / 选稿均可运营） |
| 列表页（产品 / 知识 / 场景 / 栏目） | ❌ 布局结构写死，只能改内容与（已有的）显隐，不能重组 / 选模板 |
| 详情页（产品 / 服务 / 文章） | ❌ section 顺序与槽位写死，不能选模板 / 不能增删板块 |
| Landing Page | ❌ 无法创建自由组合页 |

**判定**：GEO Website OS 当前在"首页"上证明了组合能力，但"任意页面组合"尚未产品化。

---

## 6. 分层验证（Theme / Content / Template）

| 层 | 期望职责 | 现状 |
| --- | --- | --- |
| Theme | 只负责视觉（token / 字体 / 间距 / 外观） | ✅ 成立（18D Design System；主题不含业务事实 / IA） |
| Content | 只负责内容（Content / Entity / Media 数据） | ✅ 成立 |
| Template | 只负责结构（布局 / 槽位 / block 编排，可选择） | ❌ **不存在独立 Template 层**，结构耦合在 Controller → Blade，无法后台选择 / 组合 |

---

## 7. 缺口清单（预览，正式登记见架构文档）

| ID（拟） | 缺口 | 优先级（拟） |
| --- | --- | --- |
| TD-53 | 跨页面通用 Block 类型 registry 缺失（现仅首页 section） | P1 |
| TD-54 | Template 模型 / 注册表 / 选择器缺失 | P1 |
| TD-55 | Page / 自定义落地页模型与路由缺失 | P1 |
| TD-56 | 详情 / 列表页结构可组合化（写死 section → 模板槽位 + 默认 block） | P2 |
| TD-57 | Block 后台 CRUD 扩展（create / store / destroy、page 选择） | P2 |
| TD-58 | 组合页 Block 缓存与失效契约 | P2 |
| TD-59 | Contact / 表单 block 化（与表单能力债对齐） | P3 |

---

## 8. Discovery 边界声明

- 本轮仅做只读审计与文档产出，**未修改** app / config / resources / routes / database 任何文件；
- 未新增 / 删除任何数据，未运行会改变状态的命令（仅 Glob / Grep / Read）；
- 不重开 18A–18F 已 CLOSED 的债务；新缺口从 **TD-53** 起连续编号；
- **Discovery 完成后 STOP**：不进入实现，等待用户对架构裁定与实施授权。
