# GEO Website OS

**GEO Website OS** is a multi-site, GEO/SEO-first content and entity operating system built on Laravel. It turns a single codebase into independently themed, isolated websites — each with its own content, entities, knowledge graph, and machine-readable GEO/SEO outputs — while shipping fully generic, business-neutral example data.

It is designed for the generative-search era: alongside traditional SEO (canonical, Open Graph, sitemap, RSS), every site emits structured, AI-readable artifacts (`schema.org` JSON-LD, `geo.json`, `llms.txt`) from one shared source of truth.

---

## Highlights

- **Multi-site by default** — host resolution (`Host` header / domain), per-site rows, automatic site scoping on Eloquent, and site-aware caching. Data never leaks across sites, even with explicit cross-site query parameters.
- **Entity & knowledge-graph core** — eight generic entity types (`organization`, `person`, `product`, `service`, `location`, `topic`, `case_study`, `download_asset`) plus typed `EntityRelation` edges, with database-enforced uniqueness and foreign keys.
- **Unified SEO + GEO layer** — a `SeoMetaResolver` with per-resource inheritance chains, a `UrlResolverInterface` for canonical URLs, and builders for JSON-LD, `geo.json`, `llms.txt`, `sitemap.xml`, `feed.xml`, and `robots.txt`. Content and Entity are parallel resources with explicit, predictable rules.
- **Themes & plugins** — override views per theme without touching engine internals; enable/disable extensions at runtime.
- **Installer / upgrade / rollback** — `php artisan geo:install` bootstraps a clean site; `geo:upgrade` runs ordered, idempotent migrations; backup/restore is scripted and tested.
- **Admin console** — sites, content, entities, relations, media, navigation, SEO, GEO preview, inquiries, and governance.
- **Business-neutral by construction** — the default dataset is a generic industrial Example organization. No specific client, industry, brand, phone number, or address is wired into the core runtime; reverse pollution assertions run in CI.

## Requirements

- PHP **8.4+** (with `pdo_sqlite`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, GD recommended)
- [Composer](https://getcomposer.org/) 2.x
- Node.js 20+ / npm (only for building front-end assets)
- SQLite (default, zero-config) or any Laravel-supported database

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite

# Bootstrap a clean site, default content, and an administrator (non-interactive flags available)
php artisan geo:install

npm install && npm run build
php artisan serve
```

For production-style provisioning on a fresh machine, see [`DEPLOY.md`](DEPLOY.md) and `scripts/install-release.sh`.

After installation the front page, admin login, `/geo.json`, `/sitemap.xml`, `/llms.txt`, `/feed.xml`, and `/robots.txt` are reachable. The installer creates a **blank product site** (no demo content); run the demo seeder if you want the Example dataset:

```bash
php artisan db:seed          # seeds the generic Example dataset (development/demo only)
```

## Multi-site model

- Each request resolves exactly one site from the `Host` header; query parameters cannot switch the runtime site for public requests.
- Unknown hosts: in single-site/development mode a configurable fallback may serve the default site; in **multi-site production** set `SITE_DEFAULT_FALLBACK=false` so unknown hosts return "site not found" instead of silently serving a real site.
- CLI work is explicit: `--site` / `--site-id` selects the site; invalid or missing targets fail rather than implicitly crossing sites.
- Queue jobs serialize their `site_id`, restore context before running, and always clear context in a `finally` block.
- Cross-site ("system") access is an explicit, administrator-authorized capability — there is no global environment switch to disable site scoping.

## Machine-readable outputs

| Endpoint | Purpose |
| --- | --- |
| `/geo.json` | Entity/knowledge graph as structured data |
| `/sitemap.xml` | Canonical, indexable URLs |
| `/llms.txt` | LLM-oriented site summary and references |
| `/feed.xml` | RSS feed of recent content |
| `/robots.txt` | Crawl rules incl. known generative/AI agents |
| JSON-LD (per page) | `schema.org` structured data matching visible content |

## Documentation

- [Design system](docs/design-system.md)
- [Code standard](docs/code-standard.md)
- [HTML standard](docs/html-standard.md)
- [CSS standard](docs/css-standard.md)
- [Deployment & operations](DEPLOY.md)

## Project layout (domain code)

```
app/
  Console/Commands/   geo:install / geo:upgrade / geo:version
  Http/Controllers/   Site (front), Admin, Geo (machine outputs), API
  Models/             Site, Content, Entity, EntityRelation, SeoMeta, Media, ...
  Repositories/       EntityRepository (scope-safe queries)
  Services/           Seo/* resolvers, Geo/* builders, Gate/* governance, Installer/Upgrade
  Support/            SiteContext, SiteScope, BelongsToSite, SiteResolver, ...
database/
  migrations/         schema + idempotent data migrations (up/down tested)
  seeders/            generic Example demo dataset
resources/themes/     default + example theme contracts
```

## Testing

```bash
php vendor/bin/phpunit
```

The suite covers multi-site isolation, entity/relation constraints, SEO resolution, GEO outputs, the installer/upgrade/rollback paths, themes/plugins, and **reverse business-pollution assertions**. Tests must stay green with `0 failed / 0 skipped`; assertions and coverage are expected to grow, not shrink.

## Releases & CI

A GitHub Actions workflow performs a clean-runner install, platform checks, dependency audit, full tests, HTTP smoke tests, and builds a versioned release artifact with a CI-generated manifest (commit == tag target == artifact source) and SHA-256 checksum. Triggering it requires configuring a git remote and pushing a tag; until then the pipeline is defined but not yet executed against a hosted runner.

## Contributing

Keep the core generic: no client names, brands, contact details, or industry-specific data in runtime code, default data, or shipped themes. Follow the standards in `docs/` and add tests for new behavior.

## Security

Do not open a public issue for vulnerabilities. Please report security issues privately to the maintainers. Debug details, stack traces, SQL, and user data are never exposed on error pages.

## License

GEO Website OS is open-sourced software licensed under the [MIT license](LICENSE).
