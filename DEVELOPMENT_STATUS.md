# Development status — AI Website Studio

Last updated: 2026-10-30. Plugin version **1.7.0** (DB version 6). Target for the full studio: 1.9.0.

## Summary

| Phase | Module | Status |
|---|---|---|
| 0 | Repository audit, implementation map | ✅ Done — `docs/website-studio/ARCHITECTURE-MAP.md` |
| 1 | Studio menu + Dashboard | ✅ Implemented |
| 1 | Page selector (`GET /pages/available`) | ✅ Implemented |
| 1 | Website Analyzer (Elementor document + kit, blocks/classic, theme.json, stylesheets) | ✅ Implemented |
| 1 | Brand Extractor + design-token schema v1 | ✅ Implemented |
| 1 | Brand profiles (create from analysis, edit, duplicate, delete) | ✅ Implemented |
| 1 | Audit log | ✅ Implemented (analyses, profiles) |
| — | Security: credential key rotation | ✅ Implemented |
| 2 | Existing Page Cloner | ⏳ Not started |
| 3 | Normalized design schema, ElementorMapper, PageValidator | ⏳ Not started |
| 4 | HTML/CSS → Elementor | ⏳ Not started (`CssParser` is ready for reuse) |
| 5 | Screenshot → design (vision) | ⏳ Not started |
| 6 | AI content-to-design, section images, internal links | ⏳ Not started |
| 7 | Consistency, accessibility, section regeneration, preview, revisions, design library | ⏳ Not started |
| 8 | QA matrix, packaging for 1.9.0 | ⏳ Not started |

"Implemented" means the code is written and covered by unit tests. It does **not** mean it has been verified in a running WordPress site (see Known limitations).

## Files changed in 1.7.0

New:
- `includes/WebsiteStudio/StudioTables.php`
- `includes/WebsiteStudio/Design/DesignTokens.php`, `BrandExtractor.php`
- `includes/WebsiteStudio/Css/CssParser.php`
- `includes/WebsiteStudio/Analysis/StyleSignals.php`, `CssStyleAnalyzer.php`, `ElementorDocumentAnalyzer.php`, `BlockDocumentAnalyzer.php`, `SiteDesignSource.php`, `PageCatalog.php`, `WebsiteAnalyzer.php`
- `includes/WebsiteStudio/Persistence/BrandProfileRepository.php`, `AnalysisRepository.php`, `AuditLog.php`
- `includes/REST/WebsiteStudioController.php`
- `includes/Admin/WebsiteStudioPage.php`
- `assets/website-studio/studio.js`, `studio.css`
- `tests/` (runner, fakes, 6 suites)
- `docs/website-studio/ARCHITECTURE-MAP.md`, `REST-API.md`, `TESTING.md`
- `bin/build-zip.sh`

Changed:
- `ai-seo-content-studio.php` (version), `readme.txt`, `CHANGELOG.md`
- `includes/Core/Migrations.php` (DB 6: three studio tables)
- `includes/Core/Plugin.php` (wiring, retention, previous encryption key)
- `includes/Elementor/ElementorBridgeInterface.php`, `ElementorBridge.php` (`isBuiltWithElementor`, `activeKit`)
- `includes/Security/Encryption.php`, `CredentialStore.php` (key rotation)
- `uninstall.php` (drops studio tables)
- `docs/SECURITY.md`

Existing behaviour is unchanged. The new code only adds a menu, routes and tables. The one change to existing behaviour is in `CredentialStore::get()`, and it applies only when `AISCS_ENCRYPTION_KEY_PREVIOUS` is defined.

## Tests executed

- `php -l` on every PHP file: no syntax errors (PHP 8.3).
- `node --check assets/website-studio/studio.js`: OK.
- `php tests/run.php`: **30 passed, 0 failed**.

## Known limitations

1. **Not yet run in WordPress.** WordPress 6.8.3, Elementor 4.0.8 and the SQLite integration were cloned into the dev environment, but running externally downloaded code was not permitted there. The Elementor source was used only to confirm the data structures and the public methods relied on (`kits_manager->get_active_id()`, `Document::is_built_with_elementor()`, `get_elements_data()`). The manual checklist in `docs/website-studio/TESTING.md` must be run before release.
2. **Existing React source missing.** The ZIP has only `assets/build/index.js`. The studio is therefore a separate menu with its own dependency-free script, not a tab inside the existing dashboard. Once the source is provided, the two can be merged.
3. **Elementor 4 atomic elements** (`e-flexbox`, `e-heading`, …) are counted and structurally classified, but their styles are not read yet.
4. **Elementor Pro theme-builder header/footer templates** are not part of a page's document, so header and footer patterns are detected only when they are inside the page.
5. **Rendered appearance.** Phase 1 reads stored settings and stylesheets, not the rendered page. Styles that come only from inline JS or third-party plugins' CSS are not seen. A rendered-page analysis (via `HttpClient::fetchPage`) belongs to the Website URL Analyzer.
6. The persistence tests use SQLite, not MySQL/MariaDB. The `dbDelta` schema follows the existing migration's format but has not been executed on MySQL.
7. `readme.txt` still says "Tested up to: 6.7". Update it only after testing on current WordPress.

## Security issues

None open from this phase. The 1.6.8 hardening (https custom endpoints, pixel limit, token-checked locks, SSRF and redirect validation) is in place. Key rotation was added. See `docs/SECURITY.md` → "AI Website Studio".

## Compatibility status

| | Status |
|---|---|
| PHP 8.2+ | Code uses 8.2 features already required by the plugin; linted on 8.3 |
| WordPress 6.6+ | Uses `wp_get_global_settings/styles`, `WP_Query perm=editable`, `wp.element.createRoot` with `render` fallback; not run live |
| Elementor Free 3.x / 4.x | Public Document and Kit APIs only; data format checked against the 4.0.8 source; not run live |
| Elementor Pro | Pro widgets detected and reported as unavailable when Pro is inactive |
| Multisite | Tables are per site (`$wpdb->prefix`); uninstall loops over sites |
