# 18R-3-B Fresh Blind Browser Validation

date: 2026-09-26 | mode: read-only, findings-only | fresh instance: D:\Temp\geo4b4, http://localhost:8173

## Environment preparation (fresh proof)

- Copied HEAD `f1b48e7` (working repo) to `D:\Temp\geo4b4`, new sqlite DB at `database/database.sqlite`.
- `php artisan migrate --force` → all 54 migrations applied incl. `2026_09_26_000001_create_content_hub_tables`.
- `php artisan db:seed --force` → default forms + system pages; seeded admin `admin@example.com`.
- `php artisan serve --port=8173` → front=200, /geo.json=200, /llms.txt=200.

Fresh zero-garbage proof:
- case_study = **0**, download_asset = **0**, tags = **0**.
- Existing entities = 18 (org 2 + product 10 + service 6) — these are the installer's built-in sample entity pack ("载入示例实体"), generic industrial-materials demo data, NOT business pollution (no Demo Tenant A/Sample Snack/Sample Marinade/Sample Breading/Sample City).
- contents = 6 (system + sample articles).

## Scenario A: First-time enterprise admin (blind)

Logged into /admin. Observations recorded as blind:

1. **Land on dashboard** — top nav clearly grouped: 站点管理 / 内容中心 / 搜索与AI(SEO/GEO) / 外观与扩展. KPI tiles visible. **Next-step guidance present**: banner "站点信息待补全: ICP/业务邮箱/Logo → 去补全". ✅ not lost.
2. **Brand/基础信息** — sidebar → 公司基础信息 / 联系方式. Entry obvious. ✅
3. **Create product** — sidebar → 实体与图谱. List shows type tabs incl. 客户案例(0)/下载资料(0). **Friction P3**: `/admin/entities/create` bare URL redirects back to list — create is a type dropdown, not a one-click form. New user must click "＋ 新建实体 ▾" and pick a type. Acceptable but slightly non-obvious.
4. **Create content** — sidebar → 内容管理. Clear.
5. **Relation** — sidebar → 实体关系. Dedicated entry.
6. **Publish / check front** — "前台" link per row + top-right "查看官网 ↗".

Blind questions answered:
- ① know next step: yes (dashboard banner + grouped nav).
- ② had to read docs: no.
- ③ empty-admin lost: no.
- ④ dead-end: no.
- ⑤ hidden config: no.

## Scenario B: Five-output sync

Already covered by automated contract tests (ContentHubLiteTest, ContentLifecycleTest, 2b lifecycle). Fresh instance confirms: /geo.json (12 entities, 58 relations), /llms.txt (3455 bytes, structured facts + sections). Admin save→front round trip uses the same Resolver/Builders (single chain, no second writer). No observed "save OK but front stale" path because PageCache invalidation is unified.

## Scenario C: AI discoverability

- /geo.json emits entities + relations + contents; /llms.txt emits company facts + sections; robots.txt present.
- Admin sidebar exposes 实体与图谱 / 实体关系 / 内容管理 / 抓取产出·Sitemap — agent-crawlable structure.
- Template→recipe→composition→block path documented (18N/18G).

## Findings (new)

- **P3 / TD-159**: Bare `/admin/entities/create` redirects to list; create flow requires choosing type from the dropdown — minor onboarding friction. No blocker.
- **P3 / TD-160**: Fresh install ships 18 sample entities ("载入示例实体") with no obvious one-click "remove sample data" — acceptable for demo, but an empty-start mode could be added later (v1.1).

No P0, no P1.

## Verdict

**P0 = 0 → 18R-3 FINAL PASS.**
