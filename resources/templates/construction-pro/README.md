# Construction & Real Estate OS Pro

- **Pack id**: `construction-pro`
- **Industry**: construction-realestate
- **Version**: 1.0.0
- **Locales**: zh-CN, en
- **License**: MIT

A declarative Business OS template pack for **GEO Website OS**. It ships metadata, a
homepage recipe (business narrative) and suggested defaults — **no PHP / Blade / JS / CSS**.
All rendering goes through the existing Composition pipeline and registered blocks.

## Lifecycle

```bash
# 1. validate the pack (manifest / recipe / runtime)
php artisan template:validate construction-pro

# 2. activate and build the page skeleton (recipes)
php artisan template:activate construction-pro

# 3. (optional) bootstrap suggested default settings / menus / SEO
php artisan template:bootstrap construction-pro

# rollback to core templates
php artisan template:deactivate
```

The pack never copies business facts into pages; Content / Entity remain the source of truth.