# 18S Product Capability Completion Blueprint (Read-Only Audit)

HEAD: `d798225` | date: 2026-09-26 | mode: **read-only blueprint, no product code changed**

## 0. Principle

18R closed the architecture loop (1194 tests, 0 failed). This audit asks whether the product is now **operationally maintainable by a long-term enterprise admin** vs. merely "architecturally feasible". Every recommendation below must reuse the existing architecture (Entity Graph / EntityRelation / Composition / EntityCapabilityRegistry / Template safety / unified SEO·GEO chain). **No second system.**

---

## 1. Mature CMS capability mapping

Baseline = generic mature enterprise-website CMS capabilities (YankuCMS/炎酷 and comparable). The 8 modules:

| # | Module | Mature CMS expectation |
|---|---|---|
| M1 | Content Lifecycle | Draft → Review → Published → Expired → Archive; scheduled publish/unpublish; revision rollback |
| M2 | Page Manager | List/reorder/draft/preview/duplicate/publish per landing page; composition grid |
| M3 | Media Library | Folder/alt-text/usage count/dimensions; reuse across entities/content/blocks |
| M4 | SEO/GEO Health Dashboard | Missing title/desc/OG, broken internal links, noindex leakage, orphan entities |
| M5 | Entity Coverage Check | "You have 2 published products, 0 case studies linking to them" |
| M6 | Inquiry/Lead Center | Source URL / referrer / UTM / landing page / product interest / spam filter / IP risk — **not CRM** |
| M7 | Setup Wizard | Post-install: site name → brand → contact → pick industry template → first product → first page → preview |
| M8 | Template Library | Browse industry templates, one-click apply, safe-diff against current composition |

---

## 2. Current gap matrix (real code evidence)

Five states: **IV** = Implemented Verified; **IP** = Implemented but Product Incomplete; **AR** = Architecture Ready; **DA** = Deferred Accepted; **MC** = Missing Core.

| Module | Backend | Admin | Frontend | AI·GEO | Ops experience | Overall |
|---|---|---|---|---|---|---|
| M1 Content Lifecycle | Content.status column (draft/published/archived) + ContentRevision exists (ContentController) | ContentController has publish/unpublish; **no scheduled, no expired, no reviewer queue** | Front shows published only via PublicIndex | llms/geo drop unpublished (verified) | Admin sees drafts list but no expiry/scheduling | **IP** |
| M2 Page Manager | PageController + BlockController (composition slots) | Page list / duplicate / preview exists (BlockController) | CompositionRenderer renders | — | Reorder/sort basic; no bulk status | **IP** |
| M3 Media Library | MediaController exists, BelongsToSite | Grid view, upload, alt text? | used via SafeUrl/asset | — | **No usage-count / folder / dimensions** surfaced | **IP** |
| M4 SEO/GEO Health Dashboard | SeoMetaController + GeoController emitters exist | DashboardController shows counts; **no health checks** | — | emitters run | No "missing OG / orphan entity" panel | **AR** (emitters exist, no checks UI) |
| M5 Entity Coverage Check | EntityRelation/Entity queries exist | Admin entity tabs show 关系数; **no coverage report** | — | geo graph built | Ops must manually infer "which products lack cases" | **AR** |
| M6 Inquiry/Lead Center | InquiryController + FormController; inquiries table | List exists | — | — | Source URL / UTM / landing-page / product interest / spam/IP risk **not surfaced** | **IP** (capture works, attribution weak) |
| M7 Setup Wizard | geo:install + seeders; site settings scattered | Dashboard banner hints (ICP/email/logo) | — | — | **No guided wizard** — blind user 18R-3B navigates by nav alone | **MC for v1.0** |
| M8 Template Library | TemplateController + Template ecosystem (8 pro packages, manifest validator) | Template list exists | — | — | "One-click apply to fresh site" flow / safe-diff **not productized** | **IP** |

---

## 3. v1.0 / v1.1 / long-term boundary

### P0 — v1.0 必补
1. **Setup Wizard** (M7): post-install 5-step guided flow (site→brand→contact→template→first product→preview). Reuse: existing SettingController/SiteController/EntityController; no new tables; wizard state = session + existing settings keys.
2. **GEO Health Dashboard** (M4): read-only panel scanning existing emitters (missing OG, missing meta, noindex leakage, orphan entities). Reuse: SeoMeta/GeoGraph/PublicUrl — pure read, no new writer.
3. **Entity Coverage Check** (M5): "published products with 0 related case_studies / 0 content_entity about". Reuse: EntityRelation + content_entity + PublicIndex — read-only query, no new table.

### P1
4. **Media Library Lite** (M3): usage count (count references in entities/content blocks via existing media_id FKs) + dimensions/alt display. No DAM, no folders v1.
5. **Inquiry Center attribution** (M6): persist source_url / referrer / utm_* / landing page on existing inquiries table (additive columns), simple spam honeypot. **No CRM.**
6. **Content Lifecycle completion** (M1): scheduled publish/expiry timestamps on existing contents row (no new table), existing revision rollback surfaced in UI.

### P2
7. **Template Library** (M8): industry deep one-click apply + safe-diff preview.
8. **AI Agent Operation Guide**: docs for agent-driven ops (extend existing Template SDK spec).

### Explicitly NOT in v1.0
- AI 一句话建站 / one-prompt site builder
- Marketplace / paid template store
- Canvas free-drag block editor
- CRM (pipeline/deal/contact history)
- Full DAM (asset rights/versions/folder taxonomy)
- Workflow approval chains / scheduled review queues
- Enterprise knowledge base platform

---

## 4. Suggested phase breakdown

Each item follows Discovery → Review → Implementation → Regression → Gate.

| Item | Est. effort | Reuse (no new system) |
|---|---|---|
| Setup Wizard | 3 phases | existing settings/site/entity stores; wizard state in session |
| GEO Health Dashboard | 2 phases | existing SeoMeta/GeoGraph/PublicUrl — read-only aggregator |
| Entity Coverage Check | 2 phases | existing EntityRelation/content_entity queries |
| Media Lite usage-count | 1.5 phases | existing media_id references |
| Inquiry attribution | 1.5 phases | additive columns on inquiries; existing form submission path |
| Content lifecycle scheduling | 2 phases | existing contents row columns |
| Template Library UX | 3 phases | existing Template ecosystem + validator |
| AI Agent Op Guide | 1 phase | docs only |

---

## 5. Architecture-reuse guarantees

- No new entity types beyond Registry config.
- No new relation tables beyond existing `entity_relations` / `content_entity`.
- No new renderer — all admin panels read from the same Composition/Resolver used by front.
- Health/Coverage dashboards are **pure read aggregators** over existing data, never second writers.
- Inquiry attribution adds columns, not a CRM module.
- Setup Wizard writes to existing SettingController/SiteController stores.
