# 18S Product Capability Completion — Implementation Plan (Read-Only)

HEAD: `addb051` | date: 2026-09-26 | mode: **plan only, no product code**

Single principle: one chain Entity→Relation→Composition→Schema→GEO→Search. No second system.

---

## Capability 1 — Setup Wizard (P0)

1. **Value**: turns a fresh install from "navigate by sidebar intuition" into a 6-step guided first-run. Belongs to GEO OS because it bootstraps the **Entity Graph** (org/product), not a visual skin.
2. **User scenario**: brand-new admin → log in → wizard starts → fills org info → uploads brand → adds first product → picks industry template → preview → publish. No docs.
3. **Code mapping**:
   - Routes: `routes/web.php` admin group; new `/admin/wizard` (single controller).
   - Stores reused: `SettingController`, `SiteController`, `EntityController` (TYPE_ORGANIZATION/TYPE_PRODUCT), `ThemeController`/TemplateController apply.
   - State: session only (no table).
4. **Architecture**: writes to existing stores; no new builder. Front preview reuses existing PageResolver.
5. **Data**: zero new tables/columns.
6. **Plan**: (a) route+controller skeleton; (b) step1 brand/org; (c) step2 product; (d) step3 template apply; (e) preview+finish.
7. **Acceptance**: Fresh install → wizard visible → completes → org+product published → front+geo.json+JSON-LD render. Full regression 0/0.

**Estimated phases: 3. Gate: 18S-Wizard.**

---

## Capability 2 — GEO Health Dashboard (P0)

1. **Value**: tells admin "your site has GEO gaps" — not fake scores.
2. **Scenario**: admin opens dashboard after setup → sees red/yellow/green checks.
3. **Code mapping**: read-only aggregator over `SeoMeta` resolver, `PublicUrl::entity()`, `SchemaBuilder`, `GeoGraphBuilder`, `SearchIndexBuilder`. New `Admin/HealthController` (read-only).
4. **Architecture**: pure read; no writer. No new entity.
5. **Data**: zero new tables.
6. **Plan**: checks = (a) published entities missing OG image; (b) products with no public URL; (c) JSON-LD emit success/fail; (d) noindex leakage on public pages; (e) geo.json edge count sanity.
7. **Acceptance**: every check has real data source; no fabricated scores; regression 0/0.

**Estimated phases: 2. Gate: 18S-Health.**

---

## Capability 3 — Entity Coverage Check (P0)

1. **Value**: "which products lack a case study / a content mention" — closes the orphan-entity gap.
2. **Scenario**: admin sees "8/10 products covered; these 2 have zero cases".
3. **Code mapping**: queries over `EntityRelation`, `content_entity`, `PublicIndex::entityQuery()`. New `Admin/CoverageController`.
4. **Architecture**: read-only; reuses existing graph.
5. **Data**: zero new tables.
6. **Plan**: per-product coverage % = (has case_study?) + (has content_entity about?) + (has download_asset?) + (has industry/scenario relation).
7. **Acceptance**: numbers match DB truth; linked from Health Dashboard.

**Estimated phases: 2. Gate: 18S-Coverage.**

---

## P1 (planned, deferred)

### Content Lifecycle completion
- Reuse: `contents` row already has status; add `published_at`/`expired_at` scheduling (additive columns), existing revision rollback surfaced in UI.
- Acceptance: scheduled publish/unpublish works; expired drops from PublicIndex/llms/geo.

### Page Manager
- Reuse existing PageController/BlockController; add bulk status / duplicate preview. No drag Canvas.

### Media Library Lite
- Reuse MediaController; add usage-count (grep media_id references) + unused detection. No DAM.

### Inquiry Center Lite
- **Note**: columns already exist (source_page/landing_url/referer/utm_*/ip/user_agent/device_type per migrations). Work = **admin UI surfacing + spam flag**, no new columns. Not CRM.

---

## P2

- Template Library: industry deep one-click apply over existing template ecosystem + manifest validator; safe-diff preview.

## Excluded from v1.0
AI builder / Marketplace / Canvas / Plugin ecosystem / advanced RBAC / CRM / DAM / knowledge platform / approval.

## Final regression gate
Every P0 gate ends with `php artisan test` = 0 failed / 0 skipped + browser real-flow evidence + geo.json/llms.txt/Schema/Sitemap diff.
