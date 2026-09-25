# Manufacturing OS Pro

GEO Website OS · Business OS Template Pack —— 制造业官网模板包。

本包是一个**可分发、可安装、可被第三方创建**的 Template Package。它不携带任何
PHP / Blade / Controller / Renderer 代码，只通过「Manifest 声明 + Recipe 组合」
复用系统已注册的核心 Block 与统一渲染管线，体现制造业官网的业务叙事。

## 目录结构

```
manufacturing-pro/
├── manifest.json        # 包元数据与依赖契约（见下）
├── template.json        # 包级模板（mfg-home / mfg-landing）
├── recipes/             # 业务配方：页面 → block 组合
│   ├── homepage.json    # 首页（Hero→实力→能力→产品→FAQ→询盘）
│   ├── products.json    # 产品总览（侧栏选型 / 询盘）
│   ├── contact.json     # 联系页（服务承诺）
│   └── about.json       # 企业故事落地页
├── preview/             # 模板预览图（desktop / mobile）
└── README.md
```

## Manifest 字段

| 字段 | 说明 |
| --- | --- |
| `id` | 包唯一标识（与目录名一致） |
| `name` | 展示名称 |
| `version` | 语义化版本（SemVer） |
| `industry` | 适用行业（Business OS 分类） |
| `locales` | 支持的前端语言（zh-CN / en） |
| `theme` | 引用的已安装主题 |
| `requires.components` | 依赖的已注册 Block |
| `pages` | 提供的页面类型 |
| `author` / `license` | 作者与许可证 |

## 安装与应用

```bash
# 发现并校验所有模板包
php artisan template:list

# 激活本包并应用全部配方（为站点每个已启用语言生成页面 / 区块）
php artisan template:apply manufacturing-pro

# 只应用单个配方
php artisan template:apply manufacturing-pro --recipe=homepage
```

配方应用是**幂等**的：重复执行不会产生重复页面或区块；由配方管理的区块带
`_recipe` 标记，管理员手动添加的区块不会被覆盖。

## 边界

- 数据源 Block（如 ProductGrid）由站点 Catalog 自动投影，本包不复制业务事实；
- 系统页固定槽（main 的 sys_*）由系统直驱，配方只作用于可组合槽；
- 静态文案以 locale map（`{ "zh-CN": "...", "en": "..." }`）提供双语，不写入
  全局翻译字典。
