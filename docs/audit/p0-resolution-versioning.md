# P-STEP 07 — Versioning / Migration / Upgrade（P0-A + P0-B 正式消解）

> 版本契约：版本单一来源 `config/geo.php → 'version'`（env `GEO_OS_VERSION` 可覆盖）。
> 当前版本 **2.0.0**（产品化架构落地：installer / theme / plugin / upgrade contract）。

## 1. Versioning 契约

```
geo:version   → app.version / env / PHP / 迁移状态（升级前后对拍基线凭证）
geo:upgrade   → ① pre-migrate backfill（顺序即契约）
                ② migrate --force
                ③ post 验证（核心表 / legacy 列状态 / 数据计数 / 版本报告）
geo:install   → 全新环境引导（P-STEP 03）
```

## 2. P0-A 消解：ExampleUrlGenerator（已删除）

```
行为盘点 → 你hejuUrlGenerator 仅一个通用行为：
  「传入以 / 结尾的目录型路径时保住尾斜杠」（框架 format 会剥掉）；
  详情型 / 文件路径 / query / fragment 不动。零业务逻辑，业务只在类名。
→ Golden tests（tests/Unit/UrlGeneratorGoldenTest.php，11 用例，实测捕获）
→ 替换为 App\Support\GeoUrlGenerator（同名行为、通用命名）
→ 全局唯一消费者（AppServiceProvider 'url' 绑定）切换
→ Golden 全绿 = 旧 URL == 新 URL（逐字节）
→ 删除 ExampleUrlGenerator
```

## 3. P0-B 消解：contents legacy SEO 字段（已删除）

执行顺序（严格按既定纪律，未跳步）：

```
Consumer inventory（STEP 03 审计 + 本轮全量 grep）
→ Consumer 迁移：Admin 表单/验证/snapshot 移除 legacy 字段；
   Resolver 移除 legacy 链层；SitemapBuilder 移除列过滤；NarrativeController 移除写入
→ 数据迁移：geo:backfill-seo（幂等，legacy 字段 → Content-level SeoMeta）
→ 行为对拍：升级前后 Resolution 逐字段一致（见 §5 实测）
→ zero consumer 确认（全量 grep + 全量回归 604/2549）
→ migration 2026_09_19_000003 删除 contents.seo_title/seo_desc/canonical/noindex
```

**Resolution 链演进（ADR，P0-B 授权）**：

| 字段 | 5.6-A 冻结链 | 2.0.0 生效链 |
|---|---|---|
| Title | SeoMeta → seo_title → title → Site → System | SeoMeta → title → Site → System |
| Description | SeoMeta → seo_desc → summary → Site → System | SeoMeta → summary → Site → System |
| Noindex | SeoMeta.noindex → contents.noindex → false | SeoMeta.noindex → false |
| OG Image / Canonical | 不变 | 不变（og_image_id 属正式链，保留） |

依据：backfill 完成后 SeoMeta 完整承接 legacy 层数据，legacy 层成为
零数据零语义的死层；5.6-A 契约自身已将其标注为「legacy 过渡，迁移后删除」。

## 4. 真实旧库升级实测（隔离 sqlite）

```
构造 legacy DB：回补 4 个 legacy 列 + 业务数据（seo_title/seo_desc/canonical/noindex）
  + 从 migrations 表移除 drop 记录（等价 drop 迁移未执行的旧版本库）
→ geo:upgrade exit 0
   pre-backfill: seo_metas 1 条创建，值与 legacy 字段逐字段一致
   migrate: drop 迁移应用，[ok] legacy SEO columns removed
   post: sites=1 contents=1 seo_metas=1
→ Resolution 验证：title='Legacy SEO Title' desc='Legacy SEO Desc' noindex=true
   （全部由 backfill 后的 SeoMeta 驱动 = 数据保真）
→ 回滚演练：migrate:rollback --step=1 exit 0，seo_title 列重建成功
```

## 5. 不做与移交

- 不修改任何历史 migration 的既有语义（仅 P-STEP 02 有 ADR 的文案中性化）。
- og_image_id / cover_id 保留（OG 正式链，非 legacy）。
- Release Artifact 打包与真实升级发布 → P-STEP 08/09。
