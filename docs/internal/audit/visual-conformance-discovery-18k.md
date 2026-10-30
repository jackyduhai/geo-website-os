# P-STEP 18K — Visual Conformance Discovery（蓝绿混搭核查）

- **阶段**：P-STEP 18K，Discovery 子步（只读）
- **核查问题**：前台是否存在「蓝 + 绿混搭」、是否有组件 / 页面绕过 Active Theme Token；切换主题时是否全站统一变化。
- **基线**：HEAD `84656ab`；serve `http://127.0.0.1:8162/`（blank 库，site 1）。

---

## 1. 审计方法（三层）

1. **硬编码颜色扫描**：`resources/` 全量正则 `#hex`、`rgb()/rgba()`，区分彩色 / 中性 / 主题定义 / 后台回退。
2. **真实浏览器 computed style**：提取按钮、链接、kicker 的实际渲染颜色。
3. **切换联动取证**：应用 `technology` 预设 → 提取 token 与按钮实际背景 → 恢复 `professional`。

---

## 2. 关键事实

### 2.1 彩色硬编码 = 0，组件全部消费 Token

**`#hex` 共 39 处，分类如下：**

| 类别 | 数量 | 处置 |
| --- | --- | --- |
| 彩色孤立 hex（蓝 / 绿 / 其他色相） | **0** | 无 |
| `#fff`（layouts/site.blade） | ~26 | 深色反白区（hero / CTA band / footer / inverse）白字、ghost 按钮——语义反白 |
| `#000` | 少量 | 全部用于 CSS `mask-image`（技术常量） |
| 主题定义（example/theme.json 紫 + 琥珀） | 2 | 主题定义层，允许 |
| Admin 视图（`var(--danger,#c0392b)` 等带回退） | ~9 | 后台不要求 token 化 / 深色，v1 允许 |

**`rgb()/rgba()` 共 28 处：全部为 `rgba(255,255,255,*)` 或 `rgba(0,0,0,*)`**（反白 / ghost / 遮罩 / 阴影 / 边框），**无彩色 rgba**。

> 结论：前台彩色 100% 走 `var(--brand / --accent / --cta …)`，**不存在组件绕过 Active Theme Token**。18D「彩色孤立值清零」经独立复核成立。

### 2.2 切换预设时全站统一联动（实测）

| 预设 | `--brand` | `--accent` | `--cta` | 主 CTA 按钮实测背景 | kicker 实测色 |
| --- | --- | --- | --- | --- | --- |
| professional（默认） | `#2563EB` | `#0C875E` | `#0B7F58` | rgb(11,127,88) 绿 | rgb(37,99,235) 蓝 |
| technology | `#4F46E5` | `#0E7490` | `#0D6D87` | **rgb(13,109,135) 青** | rgb(79,70,229) 靛蓝 |

- 「获取方案 / 浏览内容」两个 CTA 在 technology 下实际背景同步变为深青，无残留绿色；恢复 professional 后回到绿色。
- **技术机制完全正确**：切一个预设，品牌簇 / 辅色簇 / CTA 簇及所有组件统一变化。

### 2.3 「蓝 + 绿」是 18D 的明确设计决策，不是 bug

- `design-system-final-audit.md` §2.1 明确：**「CTA 唯一主行动色（绿色族），品牌蓝用于链接 / 选中 / 品牌点缀，红色仅错误 / 破坏性操作」**。
- `ThemePalette::DEFAULTS`：brand `#2563EB`（蓝）、accent `#0E9F6E`（绿），CTA 簇从 accent 派生 → 出厂即「蓝品牌 + 绿 CTA」。
- 8 个预设**全部**是 primary + accent 两个不同色相（蓝绿 / 蓝琥珀 / 玫红橙 / 靛蓝青 / 紫绿 / 青碧玫红 / 海军蓝金 / 深青深绿）。

---

## 3. 结论与定性

- 用户观察到的「蓝 + 绿混搭」**现象属实**（默认 professional：蓝色品牌 / kicker / 链接 + 大面积绿色 CTA）。
- 但**根因不是技术缺陷**：
  - 不是组件绕过 Token（彩色硬编码 0、全部消费 Token）；
  - 不是切换不统一（切换预设全站联动，实测成立）。
- 根因是**配色风格决策**：18D 选择「功能色分工」（蓝=品牌 / 链接，绿=主行动 CTA），且 8 预设多为跨色相（部分为高对比撞色，如蓝+绿、紫+绿、青+玫红）。
- 用户的诉求是对该设计决策的**产品修订**：希望色系更统一、颜色数量减少、层级更多（与蓝图 §12「Primary→Secondary→Accent→Neutral，颜色数量减少、层级增加」一致）。

---

## 4. 缺口登记

- **TD-111（Design System Gap / 视觉方向）**：默认与部分行业预设采用跨色相撞色（primary 与 accent 色相距离过大），视觉上呈「混搭」，不符合「统一品牌网站」目标。ACTIVE，18K 整改。

### 两个可选整改方向（需用户裁定）

- **方向 A — 单色统一（CTA 跟随品牌）**：CTA 默认改为品牌主色的同色系派生（primary 深色 / 同色相），accent 退为小面积点缀；全站主行动与品牌同色，最统一。
- **方向 B — 和谐双色（保留 accent，但改为邻近色）**：保留 primary + accent 两语义 token（换色灵活、专业），把撞色组合改为邻近 / 和谐色相（蓝→青、靛蓝→蓝、紫→蓝、青→蓝绿、玫红→暖橙等），保留适度对比但不跨色相撞。

> 建议：**方向 B**（保留语义抽象、避免 CTA 与链接同色导致层级混淆，同时消除撞色）；若希望最极简统一则选 A。选定后调整 `ThemePalette::DEFAULTS` + `config/theme-presets.php` 8 组选色，并做 Theme × Light/Dark × zh/en 矩阵复核。

---

## 5. 待用户裁定

1. 配色方向：**A 单色统一** 还是 **B 和谐双色（推荐）**？
2. 主题安装边界（TD-110）见 `theme-product-discovery-18k.md`。

> Discovery 完成后 STOP，不进入实现。


---

## 6. 用户最终裁定（2026-09-25）

用户未选 A / B，而是裁定**第三种方向 — Brand-led + Action-aligned + Accent-controlled**：

- **Brand = 识别**：logo / 链接 / 选中态 / 品牌图形 / Hero 少量品牌色。
- **Action 默认 ≈ Brand 同色系变体（不再默认独立绿）**：新增 Action 簇（`--action/--action-dark/--action-active/--action-soft/on-action`，action=brand）；CTA 由品牌基色自动派生（`--cta`=mix(brand,黑,4%)、`--cta-dark`=mix(brand,黑,16%)），不再从独立 accent 派生。
- **Accent 降级为小面积点缀**：tag / 数据强调 / 标签 / 装饰，**不承担** CTA / 按钮 / 链接 / 标题；默认 accent 由绿 `#0E9F6E` 改为蓝灰 `#64748B`。
- **Success 固定独立绿**（种子 `#16A34A`，浅色加深 / 深色提亮），不再 = accent；error / warning 同样固定语义色。
- **视觉权重**：Neutral 70–85%、Brand 10–20%、Accent 3–8%。层级主要依靠 typography / whitespace / grid / proportion / imagery / surface / elevation，而非增加颜色。

### 配套工程裁定

- 建立 **Theme Visual Conformance Test**（`VisualConformance18KTest`），锁定：action/cta 对齐 brand、主按钮面唯一 `var(--cta)`、accent 永不做按钮背景、8 预设 CTA 始终与各自 brand 同色系、success 固定。
- 实施中真实发现并修复：**TD-112**（json 设置 site_supported_locales 保存 500，双重编码 + 数组强转）、**TD-113**（color input 无法清空回退默认，占位灰 #E5E7EB + clear_color[] checkbox）。
- **TD-111 于 18K CLOSED**；陌生品牌 Northwind Labs 品红 `#BE185D` 四组合实测 brand/action/cta 全品红、accent 蓝灰、真实按钮 rgb(182,23,89) 白字。