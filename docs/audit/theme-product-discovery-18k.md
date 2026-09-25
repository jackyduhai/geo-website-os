# P-STEP 18K — Theme Installation / Lifecycle Discovery

- **阶段**：P-STEP 18K（Theme Installation + Visual Conformance Closure），Discovery 子步
- **审计性质**：只读盘点，不改代码；核实「主题作为可安装 / 启用 / 激活 / 切换 / 禁用 / 回滚的产品能力」是否真实成立。
- **起始基线**：HEAD `84656ab`（tag `checkpoint-18J`）；Regression 993 / 5118 / 0 / 0；19A 临时修正 `.github/workflows/ci.yml`（661/3057→993/5118）未提交。
- **冻结纪律**：不配置 remote、不 push、不移动 rc1、不 Release；19A / 19B / 19C 全部 HOLD。

---

## 1. 审计方法与对象

- 代码：`app/Support/Theme/ThemeManager.php`、`ThemePalette.php`、`ThemePresets.php`、`app/Http/Controllers/Admin/ThemeController.php`、`SettingController.php`、`config/theme-presets.php`、`resources/themes/*/theme.json`。
- 取证：真实 `php artisan serve` + curl + 浏览器 computed style，应用 / 恢复预设，验证切换链路。

---

## 2. 关键事实（以代码为准）

### 2.1 主题是「文件系统资产」，不落库

- 主题目录：`resources/themes/{name}/theme.json`（清单：name / version / description / capabilities / 可选 `tokens`）+ 可选 `views/`。
- `ThemeManager::all()` 每请求 `glob(resources/themes/*/theme.json)` 扫描发现；清单含合法 name 才算「已安装」。
- 当前文件系统仅两个主题：
  - `default` —— 无 tokens、无 views/（纯基础视图）。
  - `example` —— 有 tokens（紫 `#7C3AED` + 琥珀 `#F59E0B`、radius 16、shadow soft），**无 views/**。
- **主题没有任何数据库行**：不记录「安装状态 / 版本 / 启用时间」，发现完全依赖文件扫描。

### 2.2 不存在「安装」动作，「安装」= 放置目录

- 后台 `ThemeController` 只暴露 **发现 / 预览 / 激活**；明确不提供上传、删除、在线编辑（注释归 Theme SDK / 文件部署，避免后台写文件的安全面）。
- 因此「安装一个新主题」当前等价于**部署者把主题目录放到 `resources/themes/`**（开发者 / 部署动作），Admin 运营无法在线安装。
- `invalidThemes()` 会把「目录已放置但清单无效」可见告警（缺 theme.json / JSON 非法 / 缺 name），避免运营误以为已安装。

### 2.3 「激活 / 切换」是真实、原子的产品能力

- `activate($name)`：不存在 → 返回 false（不写设置、不留半激活态）；存在 →
  1. `Setting::set('theme_active', $name)`（**per-site**，随 Setting 站点作用域）；
  2. 清激活 memo、`register()` 重注册视图查找器；
  3. `PageCache::flush()` 清旧主题 HTML。
- `register()`：把激活主题 `views/` prepend 到视图查找器最前（同名视图覆盖、未提供回退基础视图）；当前两主题均无 views/，故视觉只通过 `tokens` 生效。
- `activeTokens()`：读激活主题 theme.json 的 `tokens`，按 `ThemePresets::allowedKeys()` 白名单过滤（主题无法注入非外观键），作为**默认视觉层**；default 无 tokens → []。

### 2.4 「预览」是请求级、不泄漏

- `preview($name, $cb)`：仅设请求级 `$previewName` + 重注册，**不写设置、不改激活态**；try/finally 复位；前台预览响应带 `X-Robots-Tag: noindex`。

### 2.5 「禁用 / 回滚」

- 无显式 disable 开关；「禁用当前主题」= 激活 `default`，「回滚」= 激活此前主题。切换即回滚，状态唯一（`theme_active`）。

### 2.6 行业预设是另一条并行机制（不是 ThemeManager 主题）

- 8 个行业预设在 `config/theme-presets.php`，是一组**纯视觉种子**；`SettingController::applyPreset()` 遍历白名单把 tokens 写入当前站点 `theme_*` Setting（**站点显式层**），再 `Setting::flush()` + `PageCache::flush()`。
- 它不改变 `theme_active`，也不是 resources/themes 下的主题。

### 2.7 视觉三层优先级（ThemePalette）

```
站点显式 theme_* Setting（Custom Brand / applyPreset，最高）
   ↓ 未设置
激活主题 theme.json 的 tokens（默认层）
   ↓ 未设置
ThemePalette::DEFAULTS（兜底：brand #2563EB / accent #0E9F6E …）
```

---

## 3. 主题生命周期对照表

| 生命周期动作 | 当前是否真实成立 | 证据 / 实现 |
| --- | --- | --- |
| Discover（发现已装） | ✅ | `all()` glob + 清单校验；`invalidThemes()` 告警 |
| **Install（安装新主题）** | ❌ 无在线能力 | 仅能部署时放置目录；无上传 / zip / 包管理 / DB 安装记录 |
| Enable / Activate（激活） | ✅ | `activate()` 原子写 per-site `theme_active` + 重注册 + 清缓存 |
| Render（前台渲染） | ✅ | tokens → ThemePalette → `:root` CSS 变量 → 组件 |
| Preview（预览） | ✅ | 请求级 `preview()`、不写设置、noindex、try/finally |
| Disable（禁用） | △ 无独立开关 | 激活 default 等效 |
| Switch（切换） | ✅ | 同 activate，实测全站联动（见视觉 Discovery） |
| Rollback（回滚） | ✅ | 激活原主题；状态唯一 |
| Site 隔离 | ✅ | `theme_active` 与 `theme_*` 均 per-site |
| 缓存失效 | ✅ | activate / applyPreset 均 PageCache::flush |

---

## 4. 缺口登记

- **TD-110（Architecture / Product Gap）**：Admin 无「在线安装 / 上传主题」能力，主题只能随代码 / 文件分发；Admin 仅能在已分发主题间发现 / 预览 / 激活 / 切换。
  - 建议裁定：**V1.0 冻结为「主题随发布包分发，Admin 负责发现 / 预览 / 激活 / 切换 / 回滚」**，在线安装 / 上传、主题包格式、Theme SDK、Marketplace 归 **v1.1**——与 Plugin 定位一致（Plugin 同样是文件目录、Admin 只启停；蓝图本就把 Theme/Plugin SDK、Marketplace 标 v1.1）。
  - 18K-01 要交付的是：用 **Fresh Install + 真实浏览器**把「发现 → 激活 → 渲染 → 切换 → 回滚」这条**真实存在的链路**验证闭环，而不是虚构一个「安装器」。

---

## 5. 待用户裁定

1. **V1.0 主题安装边界**：是否同意「在线安装 / 上传主题 → v1.1（TD-110 DEFERRED），V1.0 只验证发现 / 激活 / 切换 / 回滚真实闭环」？
2. 视觉统一性（蓝绿）结论见 `visual-conformance-discovery-18k.md`，需一并裁定配色方向。

> Discovery 完成后 STOP，不进入实现；待两项裁定后再授权 18K-01 / 视觉整改。
