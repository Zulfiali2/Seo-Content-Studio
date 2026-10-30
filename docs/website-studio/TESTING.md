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
| `ConverterTest` | Selector matcher (supported and unsupported selectors, specificity), cascade (inline, specificity, media per device, inheritance), a full landing page (sections, grid 3/2/1, split, buttons and variants, cards, icons, images, tables, YouTube, accordion, gradient and contrast fixes, fallback), the report, tokens from the page's CSS, untrusted input (scripts, handlers, `javascript:`, `data:`, unknown iframes, objects, unsafe inline styles), limits, and the converted save path |
| `StudioToolsTest` | Revision store (retention, snapshots), DraftManager revisions and captured Elementor edits, consistency checker (clean page, profile differences, colour fixes incl. kit globals, untouched sections), responsive and heading checks with fixes, accessibility checker (alt text, contrast, links, buttons, forms, small text) and fixes with user text, section transforms, section writer (request, structure kept, images reused, H1 rule), proposal preview/approve/discard and the regeneration job, revision compare/restore/undo section, design library (save, components, ownership, insert with new ids, duplicate, preview), REST permissions |
| `GeneratorTest` | Generator answer to a valid PageDocument (paragraphs, prompts, CTA URL, image limit, one H1, background roles), request payload and strict schema, CTA URL safety, content-to-design rules (columns by card count and length, split/FAQ/buttons, long H1, missing H1), link suggestion validation and insertion (unpublished targets, existing links, whole words, escaping), fact check, kit tokens, all three jobs end to end with retries, image approval and discard |
| `VisionTest` | ImageInput and messages with images, request hashes, OpenAI/Grok/Anthropic/Gemini vision bodies and capabilities, screenshot validation and tiling (tall images, metadata removal, corrupt files), temporary store (traversal, prune), strict vision schema, vision answer to a valid PageDocument (one H1, layout fixes, icons, tokens, placeholders), slots and new content (unknown keys never create elements), both screenshot jobs end to end with retries, kept text without AI |
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

### Phase 4 (1.9.0-beta.1), still to be checked on a real site

18. HTML to Elementor: upload `tests/fixtures/landing.html` and `landing.css` from the source repository.
    - Analyze: the report lists the form, SVG, relative image and skipped header/footer. The preview switches desktop, tablet and mobile widths.
    - Create the draft and open it in Elementor: hero, 3-column cards (2 on tablet, 1 on mobile), the about split with the YouTube video, and the FAQ accordion. Everything can be edited.
19. Repeat with "Keep content … in an HTML widget": the `<dl>` appears as an HTML widget and nothing else does.
20. As an Editor without `unfiltered_html`, convert HTML containing `<script>` and `onerror=`: neither appears anywhere in the saved page (check the Elementor JSON with "Export Template").

### Phase 5 (1.9.0-beta.2), still to be checked on a real site

21. With OpenAI, Anthropic and Gemini as the default text provider in turn, upload a full-page landing-page screenshot.
    - Analyze: the review lists its sections with plausible layouts and colours, and the file is gone from `uploads/aiscs-studio-tmp/`.
    - Create with a brief: a draft with new text, placeholder images and the screenshot's colours, editable in Elementor. Usage and Costs shows two requests.
22. Same with "Keep the text from the screenshot" and a brand profile: one request only, and the brand colours are used.
23. With a custom endpoint as the default provider, the analysis fails with "cannot read images" and nothing is billed.
24. A renamed text file (`fake.png`), a 9000 px wide image, or a 12 MB file: each is refused before any AI request.

### Phase 6 (1.9.0-beta.3), still to be checked on a real site

25. AI Page Generator with OpenAI, Anthropic and Gemini in turn: brief "payroll services", medium, 3 images, notes with two real facts. The review shows sections, SEO fields and a fact check with no invented numbers. Create the draft: it opens in Elementor with native widgets, the site's global colours and three placeholders. Yoast/Rank Math fields are filled.
26. Approve one of two link suggestions: only that link is in the draft. Unpublish the other target, generate again and approve both: the unpublished one is reported as skipped.
27. With an image provider configured, generate two images, edit one prompt and regenerate it, use one, discard the other. The draft shows the approved image; the placeholder is gone from the Media Library; the discarded image is deleted; Usage and Costs lists the image requests.
28. Edit the draft in Elementor (move the image, add a widget), then use another image: the edit is kept. Delete the image widget, then use an image: it is reported as not placed.
29. Without an image provider, the image panel says so and the generate button is disabled.

### Phase 7 (1.9.0-beta.4), still to be checked on a real site

30. Page Tools on a generated draft: the preview loads in desktop, tablet and mobile frames and jumps to a chosen section; a logged-out browser cannot open the preview link.
31. Sections tab: change a section's colours, approve; regenerate the hero with "Improve SEO", preview the proposal (temporary page), approve. Only that section changed; Revisions shows both changes; the temporary page is gone from Pages.
32. Edit the draft in Elementor, then apply a consistency fix: Revisions shows an "Edited in Elementor" revision before the fix. Compare revision 1 with now, undo one section, then restore the whole page.
33. Design Consistency Checker with a brand profile and with two reference pages: plausible statuses; apply one recommendation and only that setting changes.
34. Accessibility: an image without alt text gets alt text saved in the Media Library; a low-contrast heading is fixed; the WCAG notice is shown.
35. Design Library: save a section and a card, preview them, insert both into another draft (ids unique, layout intact in Elementor), duplicate, rename and delete. Another non-admin user cannot see them.
36. On a site with Flexbox Containers off, inserting a container item is refused with a clear message.
