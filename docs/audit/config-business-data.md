# Config Business Data Audit

## 总览

config/ 目录共 16 个 PHP 文件。其中 6 个包含Example业务数据，10 个为 Laravel 框架标准配置。

## 业务配置文件详情

| 文件 | 数据类型 | 业务数据摘要 | 使用位置 | 应迁移到 | 风险 |
|------|----------|-------------|----------|----------|------|
| `facts.php` | 公司/产品/场景/合作完整数据 | 公司信息(名称/地址/电话/成立时间)、5大产品线、N款产品(含slug/描述/规格)、6类应用场景、场景推荐产品组合、销售区域、合作方式、品牌语言 | Facts:: 全局调用(10个文件66处)、web.php路由白名单 | **Entity表**(organization/product/service) + **EntityRelation** + ExampleSeeder | **极高** |
| `pages.php` | 页面结构配置 | 产品/场景/关于/工厂等页面的slug、标题、描述、元信息 | PageController、路由、导航 | categories + menus 表 + SeoMeta | 高 |
| `copy.php` | 文案默认值 | 首页/产品页/场景页/关于页的固定文案段落 | Copy::类(InquiryController, AppServiceProvider) | settings 表 + ExampleSeeder | 中 |
| `home_blocks.php` | 首页装修默认值 | 首屏/能力/案例/FAQ/CTA等区块的默认内容和结构 | HomeBlockDefaults::类(HomeController 8处) | page_blocks 表 + ExampleSeeder | 中 |
| `icons.php` | 产品图标映射 | 产品slug → SVG icon path/class 映射 | Blade 模板 | entities.metadata 或 media 表 | 低 |
| `geo.php` | GEO 配置 | GEO 相关开关和默认值(待确认具体内容) | GEO Engine | settings 表 | 低 |

## 框架配置文件（无业务数据）

`app.php`, `auth.php`, `cache.php`, `database.php`, `filesystems.php`, `logging.php`, `mail.php`, `queue.php`, `services.php`, `session.php`

## 关键发现

1. **facts.php 是最大业务耦合点**：155 个关键词命中，包含完整的业务数据模型，被 10 个文件引用 66 次。
2. **数据重复**：facts.php 中的产品/场景数据与 facts 数据库表、seeders 中的数据存在三重来源。
3. **配置即数据**：home_blocks.php 和 copy.php 本质上是"默认内容"，应该在安装时写入数据库，而不是作为配置文件存在。
4. **icons.php 是业务映射**：产品 slug 与图标的绑定是业务数据，不是通用配置。

## .env 审计（补充）

需检查 .env 中 APP_URL / APP_NAME 是否含业务标识。当前 .env 存在且 DB_CONNECTION=sqlite。
