# P-STEP 18L-4b-3 — Template Migration Lite：Final Gate

- 阶段：P-STEP 18L-4b-3 — Template Migration Lite（TD-131，Lite）
- 父 Epic：#116 Page Composition / Template System；18L Design System & Template Ecosystem Productization
- 前置：18L-4a（Safety Foundation）PASS、18L-4b-1（GEO Semantic Foundation）PASS、18L-4b-2（Section Composer Lite）PASS
- 日期：2026-09-26
- Gate 结论：**✅ ACCEPTED / PASS / CLOSED**

---

## 1. 目标与边界

为模板生态建立**升级兼容机制**：旧 Template Package / Recipe / Composition 在系统升级后可安全迁移。

硬边界（用户裁定，全程遵守）：

- 迁移规则**必须 JSON + Registry 驱动**；禁止 PHP / Blade / JS migration code。
- 不是自动升级平台、不是 Marketplace、不是在线安装。
- 规则只改结构键 / block type / variant / token 引用；**禁止自动改 SEO 内容、GEO semantic、URL、Schema**。
- block type 替换导致的 GEO 语义变化**必须在 plan 中显式标注**。
- 必须有 dry-run（plan）与失败 rollback；槽位级幂等。

---

## 2. 交付物清单

| 类型 | 路径 |
|---|---|
| 新增 | `app/Support/Templates/TemplateMigrationRegistry.php`（final，声明式规则入口 + 校验） |
| 新增 | `app/Support/Templates/TemplateMigrator.php`（final，plan/apply 引擎） |
| 新增 | `app/Console/Commands/TemplateMigrate.php`（命令：dry-run/apply/rollback/备份） |
| 新增 | `resources/templates/manufacturing-pro/migration.json`（示例规则 1.0.0 → 1.1.0） |
| 新增 | `tests/Feature/TemplateMigration18L4b3Test.php`（13 测试 / 55 assertions） |
| 修改 | `resources/templates/manufacturing-pro/manifest.json`（新增 `migration` 段） |
| 修改 | `app/Support/Templates/TemplateManifestValidator.php`（migration 段校验） |
| 修改 | `app/Support/Templates/TemplateRecipeValidator.php`（migration compatibility check） |
| 修改 | `docs/audit/technical-debt-registry.md`（TD-131 CLOSED） |
| 修改 | `docs/audit/template-sdk-specification-18l4.md`（§8 重写为实际实现） |

全部 PHP 文件 `php -l` 通过。

---

## 3. Migration Contract（声明式）

包在包根提供 `migration.json`：

```json
{
  "id": "manufacturing-pro",
  "paths": [
    {
      "from": "1.0.0",
      "to": "1.1.0",
      "rules": {
        "rename_fields":   [ { "block": "hero", "from": "lead", "to": "eyebrow" } ],
        "replace_blocks":  [ { "from": "feature_list", "to": "feature_grid" } ],
        "rename_tokens":   [ { "from": "primary_color", "to": "brand_primary" } ],
        "rename_variants": [ { "block": "hero", "from": "full", "to": "image" } ]
      }
    }
  ]
}
```

`manifest.json` 同时声明：

```json
"migration": { "from": "1.0.0", "to": "1.1.0", "supported": true }
```

### 四类规则（Lite）

| 规则键 | 作用 | 约束（Registry validate，fail-closed） |
|---|---|---|
| `rename_fields` | 重命名指定 block 的 content 键，值保留 | 不得命中 `url`/`href`/`canonical`，或前缀 `seo_`/`og_`/`schema_` |
| `replace_blocks` | 整体替换 block type，content 可复用部分保留 | 目标 block 必须当前已在 BlockRegistry 注册 |
| `rename_tokens` | 替换 content 中**精确等于**旧 token 名的字符串值 | 子串不误伤（仅精确匹配） |
| `rename_variants` | 重命名指定 block 的 `variant` 值 | 需提供 block |

---

## 4. 命令与安全流程

```
php artisan template:migrate {pack} [--from=] [--to=] [--site=] [--dry-run] [--force] [--rollback]
```

执行流程：

1. 确认 pack 存在、`migration.json` 存在且通过 `TemplateMigrationRegistry::validate()`。
2. 解析 Site（`--site` > 当前 SiteContext > 默认 Site）。
3. 取该 Site 的 PageBlock，`TemplateMigrator::plan()` 生成变更计划（不写库）。
   - block type 替换时，用 SectionSemantic 对旧/新 BlockType 重算语义并在计划中标注。
4. 无变更 → `Already up to date`；`--dry-run` → 只报告。
5. `--force` → 写备份 → `DB::transaction(TemplateMigrator::apply)`。
6. 迁移后 recheck：plan 必须 **0 changes（幂等）**，否则自动 restoreBackup。
7. `PageCache::flush()`。
8. `--rollback` → 取最新备份恢复。

备份：

- 目录：`storage/app/template-migration-backups`
- 文件名：`{pack}-{from}-{to}-{Ymd-His}.json`
- 内容：`{pack,from,to,site_id,created_at,blocks:[{id,type,content}]}`

---

## 5. 测试与验收矩阵

新增 `TemplateMigration18L4b3Test`，**13 passed / 55 assertions**：

1. registry loads migration paths
2. dry run plan does not write
3. field rename keeps value
4. deprecated block is replaced
5. token identifier is renamed（子串不误伤）
6. variant is renamed
7. protected field rule is rejected（保护字段）
8. unregistered target block is rejected（目标未注册）
9. migration preserves text and seo values
10. geo semantic change is annotated for replaced block
11. command apply and rollback end to end（含临时 pack 清理）
12. all eight business os packs validate
13. migration is scoped per site（Multi-Site 隔离）

测试夹具修正记录（真实发现，非放宽断言）：

- dry-run 断言改为只针对自建 block（避免命中 seed 的其它 block）。
- Site B 使用 sites 真实列（sites 表无 `default_locale`/`supported_locales` 列，locale 在 Setting）。
- 跨站查询 Site B 的 block 需 `PageBlock::withoutSiteScope()`（BelongsToSite 全局 SiteScope 否则过滤为 null）。

---

## 6. 全量回归

```
Tests:    1131 passed (6032 assertions)
Duration: 1093.76s
Failed:   0
Skipped:  0
```

Delta 对账：18L-4b-2 `1118 / 5971` → 18L-4b-3 `1131 / 6032`，净增 **13 tests / 61 assertions**（与新增迁移测试一致），**0 failed / 0 skipped，无测试面缩小**。

输出：`D:\Temp\reg-4b3-final.txt`。

---

## 7. Fresh Install

独立库 `D:\Temp\geo4b3`（`geo:install`，默认 blank）：

- install exit=0
- `PageBlock::count()=6`、`Form::count()=1`（默认中性 Contact Form）
- `template:validate manufacturing-pro` → `ERROR=0 WARNING=0`
- `template:migrate manufacturing-pro --dry-run` → `blocks=0 ... Already up to date`

说明：geo:install 无 blank/demo 参数（默认即 blank），Demo 走单独 `db:seed --class=DemoSeeder`。

---

## 8. Browser / HTTP 四态

- 浏览器（真实 Chrome，zh/en × Light/Dark）四态 **Console 全 0 error**。
- curl 中英首页（light）对拍：

| 项 | ZH | EN |
|---|---|---|
| root 语义 | `hero\|brand\|Organization` | `hero\|brand\|Organization` |
| root class | `hero-split` | `hero-split` |
| H1 | 工业涂料 · 胶粘剂 · 功能助剂 一体化定制 | Coatings · Adhesives · Additives, Customized as One |
| canonical | `http://127.0.0.1:8171/` | `http://127.0.0.1:8171/en` |

4b-3 未改动任何 block blade / CSS / dark 机制，仅新增迁移能力；公开契约（语义 / H1 / canonical）保持不变。

---

## 9. Runtime 污染与日志

- PDO-only 扫描 demo + blank 两库：**CLEAN**（强身份词 0）。
- 日志审计：现存 `local.ERROR`/`testing.ERROR` 均为——
  - testing 环境中**预期触发并已修复**的历史错误（productCards source、SectionSemantic::forSection、hero variant key）；
  - CLI 探索误操作（`--store`、tinker 语法、`geo:install blank` 参数），均已当场纠正。
- 最终全量回归全绿，回归窗口内无未处理 ERROR；**无产品运行态未闭合 ERROR**。

---

## 10. 已知观察（不阻塞，带入后续）

**demo 库（geo4b1）菜单数据持久化了 localhost 绝对 URL**（`http://localhost/products/`、`/contact/`、`/solutions/`，中英）。

- 成因：该 demo 库在 localhost（端口 80）环境 seed 菜单时，`url()` 生成绝对 URL 并写入菜单数据。
- **非 4b-3 引入**；Fresh Install（geo4b3）无此问题（localhost URL = 0）。
- 生产固定域名场景 Host 恒定、不受影响；但 backup/restore 到不同域名时，历史菜单可能残留旧 Host。
- 建议 v1.1 评估「菜单 URL 相对化 / 渲染时再按当前 Host 生成绝对 URL」。本阶段不扩大范围修复。

---

## 11. v1.0 / v1.1 边界

v1.0 已完成（18L 全链路）：

- Theme Token Foundation、Component Design System（Variant Registry）
- Template Package（Manifest / Validator / Lifecycle / 8 Business OS）
- Template Safety（SafeUrl / Compatibility / SDK）
- GEO Section Semantic、Section Composer Lite、**Template Migration Lite**

v1.1（沿用 DEFERRED）：

- TD-14 RBAC + Site Membership
- TD-76 Logo srcset、TD-79 Page/Landing Search
- Marketplace / 在线 zip 上传 / 第三方审核
- 自由拖拽 Canvas、hero default/image/product 三 variant 的 block 结构
- **完整自动 Migration 平台**（批量 / 向导 / Marketplace 联动）
- 菜单 URL 相对化（本阶段观察项）
- 8 包 GD 占位截图换真实截图

---

## 12. Gate 结论

| 验收项 | 结果 |
|---|---|
| Migration Contract（JSON + Registry，声明式） | ✅ |
| 四类规则 + 保护字段 / 注册校验 | ✅ |
| dry-run / 备份 / 事务 / 幂等 / rollback | ✅ |
| GEO 语义变化显式标注 | ✅ |
| 新增测试 13 / 55 | ✅ |
| 全量回归 1131 / 6032 / 0 / 0 | ✅ |
| Fresh Install | ✅ |
| Browser zh/en × Light/Dark，Console 0 | ✅ |
| 公开契约（语义 / H1 / canonical）不变 | ✅ |
| Runtime 污染 0 | ✅ |
| 产品运行态未闭合 ERROR | 0 |
| Worktree clean（tag 对齐后） | ✅ |

**P-STEP 18L-4b-3 = PASS / ACCEPTED / CLOSED。**

18L（Design System & Template Ecosystem Productization）全部 Gate PASS。后续 Release Engineering（19A Private GitHub + Cloud CI / 19B Final RC / 19C Public Release）保持 **HOLD，等待用户明确授权**；rc1（`965d63c`）不移动、不配置 remote、不 push。
