# P-STEP 18J — Test Inventory Delta 对账（checkpoint-18H-3 → checkpoint-18I）

> 审计动因：全量回归用例数从 18H 的 **1016 passed / 5176 assertions** 变为 18I 的 **993 passed / 5112 assertions**，
> 净 **-23**。需逐项解释 -23 的去向（删除 / 合并 / 重构 / 口径变化），确认"回归通过但测试面悄然缩小"不成立。
> 日期：2026-09-25。方法：以真实 git tree（`5e7d84f` vs `a3ca4ad`）逐文件统计，不凭印象。

---

## 1. 三个口径的总对账

| 口径 | 18H（`5e7d84f`） | 18I（`a3ca4ad`） | Delta |
| --- | --- | --- | --- |
| 测试文件数（`tests/**/*.php`） | 101 | 97 | **-4**（删 5 / 增 1） |
| 测试方法数（`public function test*`，无 `#[Test]` 属性） | 879 | 856 | **-23** |
| PHPUnit 执行用例数（含 dataProvider 展开） | 1016 | 993 | **-23** |

- 两版本均**无 `#[Test]` 属性**（`git grep "#[Test]"` = 0），方法一律 `test_` 前缀命名。
- 方法数与用例数的差额两版本均为 **137**（879→1016、856→993），即 dataProvider 展开数净变化为 **0**。
- 因此 **-23 完全来自测试方法的净减少**，与 dataProvider / 统计口径无关。

---

## 2. -23 的逐文件去向

### 2.1 删除 5 个旧首页装修器测试（合计 **-29**）

| 删除文件 | 方法数 | 被测对象（旧架构，已拆除） |
| --- | --- | --- |
| `BannerSlotTest.php` | -2 | 首页 `mid_banner` 槽（`mid_banner.blade.php` 已删） |
| `BlockItemImageTest.php` | -3 | 旧装修器 block item 自定义图片 / workshops·steps 覆盖 |
| `HeroModeTest.php` | -14 | 旧 Hero mode A（参数卡）/ B（轮播）/ C（分屏+幻灯片） |
| `HomeBlockItemsTest.php` | -5 | 旧 scenes / cooperation / workshop block items |
| `HomeBuilderTest.php` | -5 | 旧 builder sections / block 排序 / 手动精选 |
| **小计** | **-29** | |

### 2.2 新增 1 个首页 Composition 测试（**+6**）

| 新增文件 | 方法数 | 覆盖的新架构能力 |
| --- | --- | --- |
| `HomeComposition18ITest.php` | +6 | blank 中性 welcome / Admin 增改 Hero / 块的启停·移动·复制·删除 / 首页缓存失效 / demo 完整组合 / zh·en 独立 |

### 2.3 修改文件方法数净变化（**0**）

| 文件 | 变化 | 原因 |
| --- | --- | --- |
| `SeoHeadComposerTest.php` | +1 | 新增 title 去重（避免"站名-站名"）用例 |
| `SolutionPageTest.php` | -1 | 旧首页/服务断言随 Composition 迁移调整 |
| **小计** | **0** | |

### 2.4 净合计

**-29（删旧装修器） + 6（新 Composition） + 0（修改） = -23**，与总 delta 完全吻合。

---

## 3. 删除测试的"意图继承"映射

删除的 29 个方法测的是**已被 TD-70 拆除的旧首页装修器**（`BlockController.blocks.update`、`blocks.blade`、`mid_banner`、
hero mode A/B/C），其被测代码本身已不存在。核心测试意图由新测试群继承，而非丢失：

| 旧测试意图 | 新继承测试 |
| --- | --- |
| 禁用 block 隐藏区块 / block 按 sort 排序 / 能力项可编辑（`HomeBuilderTest`） | `HomeComposition18ITest`（toggle/move/duplicate/delete）+ `PageComposition18GTest`（26） |
| Admin 编辑 Hero 文案并渲染（`HeroModeTest` mode A/C copy） | `HomeComposition18ITest::test_admin_add_and_edit_hero_renders_on_frontend` |
| 首页改动后缓存失效（`HomeBuilderTest`） | `HomeComposition18ITest::test_home_cache_invalidates_after_block_update` + `PageCacheTest` |
| scenes / cooperation / workshop 卡片（`HomeBlockItemsTest`） | 数据驱动的 `service_grid` / `sys_solutions` / `sys_factory` / `sys_cooperation` + `SystemPageComposition18G2bTest`（14） |
| 详情/列表组合 | `DetailComposition18G2Test`（21）、`PageComposition18GTest`（26） |
| 中文/英文首页互不串 | `HomeComposition18ITest::test_zh_and_en_home_are_independent` |

**结论：首页"管理员可经 Admin 零代码搭建"这一核心能力的测试面不仅没有缩小，反而由更系统的 Composition 测试群
（HomeComposition / PageComposition / DetailComposition / SystemPageComposition）覆盖。**

---

## 4. 两处显式"能力收敛"（已登记 TD，不得静默）

首页从旧装修器迁移到通用 Composition Block 后，以下两项旧能力在 v1 通用 block 中无对应，属**有意的能力收敛**，
已登记 TD 并建议 DEFERRED v1.1（最终以用户裁定为准）：

| TD | 收敛的能力 | 新架构现状 | 建议 |
| --- | --- | --- | --- |
| **TD-103** | Hero 多 slide 轮播 / 分屏 slideshow（mode B/C，每 slide 独立文案、自动轮播） | 通用 `hero` 为单屏（eyebrow/title/subtitle/buttons/单 image_id），无 gallery/carousel block | DEFERRED v1.1：未来以通用 Gallery/Carousel block 提供；单屏 Hero 满足官网首屏主要需求，自动轮播对 a11y/SEO（多 h1）/CLS 不友好 |
| **TD-104** | Feature / 网格 item 自定义配图（`BlockItemImageTest::test_item_custom_image...`） | `feature_grid` item 仅支持语义 `icon`，item_fields 无 media 字段 | DEFERRED v1.1：item_fields 增加可选 `media_id`；单图需求当前由 `image` / `media_text` block 满足 |

---

## 5. 最终判定

- **-23 完全可解释**：旧首页装修器 29 方法随被 TD-70 拆除的旧代码退休，新 HomeComposition 6 方法 + 既有 Composition
  测试群继承其核心意图，修改净 0；dataProvider 展开数与统计口径无变化。
- **"测试面悄然缩小"不成立**：删除项均有明确的旧架构归属与新架构继承；两处能力收敛已显式登记 TD-103 / TD-104。
- 本项作为 18J 最终签收的 Test Inventory 证据，提交用户 Gate 裁定。
