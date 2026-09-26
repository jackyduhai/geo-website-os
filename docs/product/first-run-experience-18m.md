# First-Run Experience Report（P-STEP 18M-B1–B7）

> 审查对象：陌生用户从安装到发布的完整交付体验
> 审查日期：2026-09-26　·　方法：真实 `geo:install / geo:upgrade / geo:backup / geo:rollback`、HTTP 对拍、模板批量校验
> 核心问题：**陌生用户拿到系统后，能否无隐藏步骤地安装、建站、运行、升级、灾备。**

---

## 1. 结论（Executive Summary）

| 场景 | 结果 |
|---|---|
| Fresh Install（blank 默认） | **PASS**（blocks=6 / forms=1，单语 zh-CN，纯净） |
| 默认安装纯净度（无 localhost / 旧品牌 / 测试邮箱） | **PASS**（0 残留） |
| 添加语言（zh → zh+en） | **PASS**（`/en` 由 404 变 200） |
| Upgrade Path | **PASS**（幂等、完整契约） |
| Backup / Restore（DB 边界） | **PASS**（整库备份/校验/还原，差异恢复实证） |
| Demo 数据策略（blank/demo 分离） | **PASS** |
| 默认 Theme / 8 Template 完整性 | **PASS**（8 包 `validate --strict` 全 0/0） |
| 阻断性体验问题 | **无** |

P2/P3 观察项转 v1.1 / 发布工程：**TD-138**（全量备份缺 media）、**TD-139**（模板预览为占位截图）。

---

## 2. Fresh Install（B1）

`geo:install` 标准链路：环境检查 → migrate --force → storage:link → 默认 Site → 默认设置 / Blank 首页 / 默认联系表单 / 系统页 → `search:reindex` → 建超管 → 自检。

Fresh blank 库实测：

- 默认 **单语 `zh-CN`**：`site_default_locale="zh-CN"`、`site_supported_locales=["zh-CN"]`。
- 出厂内容：blocks=6、forms=1（中性 Default Contact Form：name/email/phone/message，无制造业字段）。
- 中文首页 `/` → **200**；`/en` → **404**（en 非该站支持语言，符合 **TD-109** 契约：显式非支持 locale 根路径 404）。
- 纯净度扫描：页面 **0 个 localhost**、**0 个旧品牌 / 测试邮箱 / 默认密码**残留。
- 无隐藏步骤：安装器自带自检；不自动创建公开页面（默认表单是否公开由公开/站点门禁决定）。

> 安装前提：SQLite 需先存在空数据库文件（安装器不自动建文件）；此前提已在安装文档说明。

---

## 3. 添加语言（首用关键操作，B6）

模拟用户需要英文站：

1. 在设置中将 `site_supported_locales` 由 `["zh-CN"]` 改为 `["zh-CN","en"]`；
2. 清应用缓存与整页缓存（locale 变更失效）；
3. `/en` 由 **404 → 200**，英文首页正常渲染。

结论：语言扩展链路正常，**无需改代码**；用户在后台即可增加语言。

---

## 4. Upgrade Path（B2）

`geo:upgrade` 契约（顺序即契约）：backfill-seo → `migrate --force` → 核心表校验 → 为**每站**幂等补默认设置/中性联系表单（不覆盖自定义）→ `search:reindex` → legacy SEO 列检查 → `view:clear` + `PageCache::flush`。

在已是最新的库上实测：`Nothing to migrate`、reindex 正常、缓存清理、`upgrade — complete`，**幂等无报错**。迁移顺序、模板/搜索/缓存重建均被覆盖。

---

## 5. Backup / Restore（B3）

- `geo:backup`（SQLite）：整库复制到 `storage/app/backups/` + manifest（version / migrations / 计数 / **sha256**）。实测 version=2.0.0、migrations=53。
- `geo:rollback`：需 `--backup` + `--force`，校验 manifest **sha256**（防篡改/损坏），还原前先备份当前（回滚的回滚），再整库还原。
- **差异恢复实证**：将工作副本站点名改为 `CORRUPTED-MARKER-XYZ` → 用备份 `geo:rollback` → 站点名恢复为 `GEO Website OS`。
- **边界（TD-138，P2/v1.1）**：备份仅覆盖 DB（含 settings、media 引用），**不含 media 物理文件**；升级回滚不受影响（升级不动 media），灾难恢复需另行备份 media。templates/themes 随代码分发。

---

## 6. Demo 数据策略（B4）

- **默认安装 = blank**：纯净、单语、无示例内容 / localhost / 测试邮箱 / 旧品牌。
- **Demo 独立**：示例数据经 `DemoSeeder` 单独生成（独立库），可整体删除，不影响 blank 与生产。
- Demo 内容已去行业化（无 OEM / 原料采购 / 经销代理等制造业业务假设），仅保留通用 Example 企业 / 产品 / 服务示例。

---

## 7. 默认 Theme / Template（B5）

- 默认 Theme 品牌中性，zh/en × Light/Dark 在 18K 已验证一致。
- **8 个 Business OS 模板包**（manufacturing / saas / commerce / service / healthcare / education / construction / export）物理结构一致：manifest + theme + README + recipes/homepage + defaults(settings/menus/seo) + preview(desktop/mobile)。
- 批量 `template:validate {key}`（--strict）：**全部 ERROR=0 / WARNING=0**（manufacturing recipes=4 blocks=15；其余 recipes=1 blocks=5–7）。
- **边界（TD-139，P3/发布工程）**：8 包 preview 目前为 GD 占位截图，发布前替换为真实渲染截图。

---

## 8. 新用户首用主路径（B6）

零代码闭环（关键能力在 18I / 18L 已真实跑通，本轮补验语言扩展）：

```
登录后台 → 选择/激活行业模板（Compare → Activate → Bootstrap）
        → 改品牌（Theme / 品牌设置）
        → 改首页（Section Composer Lite：增/删/排序区块、切变体）
        → （需要时）添加语言
        → 建表单（字段/验证/通知，提交 → FormSubmission → Inquiry）
        → 发布（公开/站点门禁）
```

- 全程**不修改 PHP / Blade / JS**；未发现死路或必须命令行才能完成的首用步骤。
- 表单通知默认关闭、事务外发送、失败不丢提交，避免新用户因未配 SMTP 而出现"提交即报错"。

---

## 9. 建议

- v1.0 交付体验无阻断，可进入发布工程。
- v1.1：TD-138 全量备份（DB + media）、TD-137 admin CSP nonce 化；发布工程：TD-139 替换真实模板截图。
- 在安装/首用文档中保留两个前提提示：SQLite 空文件需先创建、英文站需在设置中添加 en。
