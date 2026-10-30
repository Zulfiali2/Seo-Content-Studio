# AI Website Studio — testing

## Automated (runs anywhere with PHP 8.2+, no dependencies)

```bash
php tests/run.php            # all tests
php tests/run.php CssParser  # filter by class or method name
```

| Suite | Covers |
|---|---|
| `DesignTokensTest` | Colour, length, box and shadow normalisation; font names that try CSS injection; normalize() errors, idempotence and version check |
| `CssParserTest` | Rules, nested @media, var() resolution, skipped at-rules, braces inside strings, unbalanced input, rule-count limit, selector roles, CSS → signals |
| `ElementorDocumentAnalyzerTest` | Kit globals, `__globals__` resolution, section classification (hero/cards/faq/contact/cta), responsive settings, unavailable widgets, depth cap, malformed trees, legacy sections, atomic elements |
| `BrandExtractorTest` | Tokens from an Elementor page, no invented values, neutral vs brand colours, theme.json presets (`var:preset|…`, `clamp()`), block and classic sections |
| `PersistenceAndPermissionsTest` | Profile CRUD and ownership, analysis cache and retention, REST permission callbacks (401/403/404), IDOR on profiles and analyses, token validation errors, audit entries |
| `CredentialRotationTest` | Reading with the previous key, re-encryption, wrong previous key leaves data untouched |

The persistence tests use an in-memory SQLite `wpdb` double (`tests/fakes.php`), which checks the repository SQL and the permission logic. They do not replace a run on MySQL/MariaDB.

## Manual / integration (still to be done on a real site)

The 1.7.0 code has **not yet been run inside WordPress**. Before release, check on WordPress 6.6+ with Elementor Free 3.x and 4.x, and with Elementor Pro if available:

1. Activate the plugin (or upgrade from 1.6.8). The tables `wp_aiscs_brand_profiles`, `wp_aiscs_design_analyses` and `wp_aiscs_audit_log` exist, and `aiscs_db_version` = 6.
2. AI Website Studio → Dashboard shows the Elementor status.
3. Website Brand Analyzer lists only pages the user can edit. Search, the type/builder filters and paging work.
4. Analyze an Elementor page that has Site Settings colours and fonts. The tokens match Site Settings → Global Colors/Fonts, and the sections match the page.
5. Analyze the same page again: "(unchanged since last analysis)". Edit the page in Elementor and analyze: a new analysis is made.
6. Analyze a block-theme page (Twenty Twenty-Five): the colours and fonts match the theme's styles.
7. Save as a brand profile. Edit colours and fonts, then save. An invalid value (`#12`) shows the error list. Duplicate, then delete.
8. As an Editor: you cannot open an Administrator's profile (`/wp-json/aiscs/v1/website/brand-profiles/{id}` → 403). As an Author: the menu is hidden and the REST call returns 403.
9. Uninstall with "delete data" on: the three tables are dropped.
10. Key rotation: set `AISCS_ENCRYPTION_KEY_PREVIOUS` to the old key and change `AISCS_ENCRYPTION_KEY`. AI Providers still shows the saved keys as readable.
