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
| `EngineTest` | HTML sanitiser (XSS vectors), PageDocument validation, mapper output (containers, globals, responsive sizes/padding/widths, buttons, cards, icon list, accordion + FAQ schema), legacy fallback and widget fallbacks, validator attacks (bad ids, script, `javascript:` links, missing media, Pro widgets, wrong nesting), cloned-mode rules, ID generator |
| `DraftManagerTest` | New draft + ownership record, invalid documents never saved, permissions, rollback when Elementor rejects, original and published pages never overwritten, Elementor missing or too old |
| `ClonerTest` | New ids for every element and repeater item with settings kept, slot detection and protection, apply only allowed slots, AI answer validation, end-to-end clone with the source untouched |
| `CloneJobTest` | Full job run, selection rules, no repeated AI request or second draft on retry, access loss stops the job |
| `CredentialRotationTest` | Reading with the previous key, re-encryption, wrong previous key leaves data untouched |

The persistence tests use an in-memory SQLite `wpdb` double (`tests/fakes.php`), which checks the repository SQL and the permission logic. They do not replace a run on MySQL/MariaDB.

## Manual / integration (still to be done on a real site)

The 1.7.0 and 1.8.0 code has **not yet been run inside WordPress**. Before release, check on WordPress 6.6+ with Elementor Free 3.x and 4.x, and with Elementor Pro if available:

1. Activate the plugin (or upgrade from 1.6.8). The tables `wp_aiscs_brand_profiles`, `wp_aiscs_design_analyses` and `wp_aiscs_audit_log` exist, and `aiscs_db_version` = 6.
2. AI Website Studio → Dashboard shows the Elementor status.
3. Website Brand Analyzer lists only pages the user can edit. Search, the type/builder filters and paging work.
4. Analyze an Elementor page that has Site Settings colours and fonts. The tokens match Site Settings → Global Colors/Fonts, and the sections match the page.
5. Analyze the same page again: "(unchanged since last analysis)". Edit the page in Elementor and analyze: a new analysis is made.
6. Analyze a block-theme page (Twenty Twenty-Five): the colours and fonts match the theme's styles.
7. Save as a brand profile. Edit colours and fonts, then save. An invalid value (`#12`) shows the error list. Duplicate, then delete.
8. As an Editor: you cannot open an Administrator's profile (`/wp-json/aiscs/v1/website/brand-profiles/{id}` → 403). As an Author: the menu is hidden and the REST call returns 403.
9. Uninstall with "delete data" on: the four studio tables are dropped.
10. Key rotation: set `AISCS_ENCRYPTION_KEY_PREVIOUS` to the old key and change `AISCS_ENCRYPTION_KEY`. AI Providers still shows the saved keys as readable.

### Phases 2–3 (1.8.0), still to be checked on a real site

11. Generated Pages → Advanced: paste the PageDocument example from `REST-API.md`, choose a brand profile, and build. Open in Elementor and check:
    - every element is a native widget (no HTML widget) and can be edited, saved and reopened;
    - colours that match Site Settings show the global colour icon;
    - in responsive mode, the tablet and mobile font sizes and padding differ, cards are 3/2/1 per row, and hero buttons are full width on mobile.
12. Same document with Elementor containers turned off (Features → Flexbox Container: inactive): sections and columns are created.
13. Clone Existing Page: choose an Elementor page.
    - The plan lists its text. Header/footer, phone numbers, the business name (set under Brand and Writing Style), testimonials and counters are kept.
    - "Duplicate only" creates a draft identical to the source. The source's revision history and modified date are unchanged.
14. Clone and write new content: the job shows real steps. The new draft has new text in the chosen fields, the same design, and the meta title/description (and the Yoast/Rank Math fields when active). Usage and Costs shows one request.
15. Try to publish the generated page, then build/clone again: the published page is never changed (only new drafts appear).
16. As an Editor: the clone plan of a page you cannot edit returns 403. `/design/jobs/{id}` of another user's job returns 403.
17. Elementor Pro inactive on a page with Pro widgets: the plan warns, and the copy keeps them.
