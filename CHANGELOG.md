# Changelog

All notable changes to **GEO Website OS** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/).

> Pre-productization development history (from the source business project) is archived under `docs/audit/history/` and is not distributed in release artifacts.

## [Unreleased]

## [1.0.0-rc1] - 2026-09

First release candidate of the productized, business-neutral GEO Website OS.

### Added

- **Multi-site core**
  - Host-based `SiteResolver`, `SiteContext` / global `SiteScope`, and the `BelongsToSite` model contract with automatic site binding.
  - Site-aware cache keys; public requests cannot switch sites via query parameters.
  - CLI site selection via `--site` / `--site-id` (invalid/missing targets fail explicitly); queued jobs serialize `site_id`, restore context before run, and clear it in `finally`.
  - System/admin authorization boundary for cross-site access; no global switch to disable site scoping.
  - Configurable unknown-host behavior (`SITE_DEFAULT_FALLBACK`) for single-site development vs. multi-site production.
- **Entity architecture**
  - Six generic entity types: `organization`, `person`, `product`, `service`, `location`, `topic`; typed `EntityRelation` graph.
  - Database-enforced unique constraints and foreign keys; metadata/slug/lifecycle support; scope-safe `EntityRepository`.
  - Idempotent, zero-loss migrations from legacy Facts to Entities/Relations and from cases to Content.
  - Generic industrial Example dataset (no real-client data).
- **SEO / GEO**
  - `SeoMeta` three-state binding (Site / Content / Entity) via a table-level `CHECK` constraint and partial unique indexes.
  - `SeoMetaResolver` with per-resource inheritance chains; Content and Entity resolved as parallel resources.
  - `UrlResolverInterface` / generic resolver: HTTPS canonical URLs, query strings stripped, consistent trailing-slash rules, no cross-site canonicals.
  - Builders for per-page `schema.org` JSON-LD, `/geo.json`, `/llms.txt`, `/sitemap.xml`, `/feed.xml`, and `/robots.txt` from one shared data source.
- **Themes & plugins** — per-theme view overrides through a documented contract; runtime plugin enable/disable.
- **Lifecycle tooling** — `geo:install` (clean bootstrap), `geo:upgrade` (ordered, idempotent backfill + migrate + verify), `geo:version`, plus backup/restore and release build scripts.
- **Admin console** — sites, content, entities, relations, media, navigation, SEO, GEO preview, inquiries, and governance.
- **Productization & sanitization**
  - Removed all specific-client data, branding, and contact details from runtime code and default data.
  - Neutral design tokens and the GEO Website OS Design System; standards docs for code/HTML/CSS.
  - Reverse business-pollution and generic-deployment tests that run in CI.
- **CI / release** — clean-runner workflow: install, platform checks, dependency audit, full tests, HTTP smoke, and a versioned artifact with CI-generated manifest (commit == tag target == artifact source) and SHA-256.

### Security

- Multi-site isolation enforced at both the database and query layers; cross-site reads/writes/relations are rejected.
- Cross-site ("system") data access requires explicit administrator authorization; there is no environment-level scope bypass.
- Debug details, stack traces, SQL, and user data are never exposed on error pages.

### Notes

- The hosted CI workflow is defined but its first run against a cloud runner is pending a configured git remote and tag push; local and clean-environment runs are verified.
