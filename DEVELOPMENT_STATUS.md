# Development status — AI Website Studio

Last updated: 2026-10-30. Plugin version **1.9.0-beta.2** (DB version 7). Target for the full studio: 1.9.0.

## Summary

| Phase | Module | Status |
|---|---|---|
| 0 | Repository audit, implementation map | ✅ Done (`docs/website-studio/ARCHITECTURE-MAP.md`) |
| 1 | Studio menu, Dashboard, page selector | ✅ Implemented (1.7.0) |
| 1 | Website Analyzer, Brand Extractor, design tokens v1, brand profiles, audit log | ✅ Implemented (1.7.0) |
| — | Security: credential key rotation | ✅ Implemented (1.7.0) |
| 3 | PageDocument schema v1 (normalized intermediate representation) | ✅ Implemented (1.8.0) |
| 3 | ElementorCompat (version, Pro, containers, widget availability, fallbacks) | ✅ Implemented |
| 3 | ElementorMapper (native Free widgets, containers + legacy, globals, responsive) | ✅ Implemented |
| 3 | DocumentValidator (generated / cloned modes) | ✅ Implemented |
| 3 | DraftManager + generated page records (new drafts only, ownership, rollback) | ✅ Implemented |
| 3 | PageBuilder pipeline + `POST /design/pages/build` + Generated Pages screen | ✅ Implemented |
| 2 | ElementTreeCopier (new element and repeater IDs) | ✅ Implemented |
| 2 | ContentSlots (rewrite vs protected text) | ✅ Implemented |
| 2 | CloneContentGenerator (AI prompt and schema, answer validation) | ✅ Implemented |
| 2 | PageCloner + CloneJob (background job) + Clone Existing Page screen | ✅ Implemented |
| 4 | SelectorMatcher + StyleResolver (CSS cascade per device) | ✅ Implemented (1.9.0-beta.1) |
| 4 | HtmlConverter (structure, layout detection, native widget mapping, report, fallback) | ✅ Implemented |
| 4 | Engine additions (element styles, video, html fallback, background image, converted mode) | ✅ Implemented |
| 4 | REST `/design/html/parse`, `/design/html/convert` + HTML to Elementor screen (sandboxed preview) | ✅ Implemented |
| 5 | Provider vision support (ImageInput, VISION capability; OpenAI, xAI, Anthropic, Gemini bodies) | ✅ Implemented (1.9.0-beta.2) |
| 5 | Screenshot validation, tiling and temporary store | ✅ Implemented |
| 5 | VisionDesign (Output A: structure; Output B: slots and content) | ✅ Implemented |
| 5 | Screenshot jobs, REST `/design/image/*`, Image to Design screen with review | ✅ Implemented |
| 6 | AI page generator from a brief, section images, internal links | ⏳ Not started (it should output a PageDocument and reuse PageBuilder) |
| 7 | Consistency, accessibility, section regeneration, preview, revisions, design library | ⏳ Not started (section maps are stored for regeneration; `DraftManager::update()` and `ElementorMapper::mapSection()` exist) |
| 8 | Live WP/Elementor QA matrix, 1.9.0 packaging | ⏳ Not started |

"Implemented" means the code is written and unit-tested. It does **not** mean it has been verified in a running WordPress site (see Known limitations).

## Definition-of-done workflows

| Workflow | Status |
|---|---|
| A: Existing page cloning (select → analyze → topic → SEO content → duplicate → replace → draft → open in Elementor) | Code complete; needs a live run (TESTING.md 13–15) |
| B: HTML → Elementor (paste → parse → native widgets → validate → preview → draft → edit) | Code complete; needs a live run (TESTING.md 18–20) |
| C: Screenshot → Elementor (upload → analyse → brand and layout → content → placeholder images → layout → draft) | Code complete; needs a live run with real providers (TESTING.md 21–24) |
| D: Brand consistency | Profiles done; checker in phase 7 |
| E: Section regeneration | Phase 7 (foundations exist) |
| F: Design library | Phase 7 |

## Files changed in 1.9.0-beta.2

New:
- `includes/Providers/Contracts/ImageInput.php`
- `includes/WebsiteStudio/Vision/`: `ScreenshotPreparer`, `ScreenshotStore`, `VisionDesign`, `ScreenshotJob`
- `tests/unit/VisionTest.php`

Changed:
- `Providers/Contracts/Message.php`, `TextRequest.php`, `Capability.php`
- `Providers/OpenAI`, `Anthropic` and `Gemini` provider classes
- `Providers/TextGenerationService.php`
- `Core/Plugin.php`
- `REST/WebsiteDesignController.php`
- `Persistence/GeneratedPageRepository.php`
- `Admin/WebsiteStudioPage.php`
- `assets/website-studio/*`
- docs

## Files changed in 1.9.0-beta.1

New: `includes/WebsiteStudio/Converter/` (`SelectorMatcher`, `StyleResolver`, `HtmlConverter`), `tests/unit/ConverterTest.php`, `tests/fixtures/landing.html|css`.

Changed: `Engine/PageDocument.php` (element styles, background image, video, html, idempotent align), `Engine/HtmlFragment.php` (tables, fallback mode), `Engine/ElementorMapper.php` (style overrides, video, html, background image), `Engine/ElementorCompat.php`, `Engine/DocumentValidator.php` (converted mode, video/html checks), `Engine/DraftManager.php`, `Engine/PageBuilder.php` (mode), `Persistence/GeneratedPageRepository.php` (`converted` type), `REST/WebsiteDesignController.php`, `Admin/WebsiteStudioPage.php`, `assets/website-studio/*`, docs.

## Files changed in 1.8.0

New:
- `includes/WebsiteStudio/Engine/`: `IdGenerator`, `HtmlFragment`, `PageDocument`, `ElementorCompat`, `ElementorMapper`, `DocumentValidator`, `PostGateway`, `DraftManager`, `DraftException`, `PageBuilder`
- `includes/WebsiteStudio/Cloner/`: `ElementTreeCopier`, `ContentSlots`, `CloneContentGenerator`, `PageCloner`, `CloneJob`
- `includes/WebsiteStudio/Persistence/GeneratedPageRepository.php`, `includes/WebsiteStudio/StudioServices.php`
- `includes/REST/WebsiteDesignController.php`
- `tests/unit/EngineTest.php`, `DraftManagerTest.php`, `ClonerTest.php`, `CloneJobTest.php`

Changed:
- `includes/Core/Plugin.php` (studio services, clone job, design routes)
- `includes/Core/Migrations.php` (DB 7)
- `includes/WebsiteStudio/StudioTables.php` (generated pages table)
- `uninstall.php`
- `includes/Projects/ProjectRepository.php` (new `studio` section and `_aiscs_kind` marker; studio projects are left out of Projects and History)
- `includes/Content/DraftWriter.php` (`syncSeoPlugins()` is now public static, behaviour unchanged)
- `includes/Admin/WebsiteStudioPage.php`, `assets/website-studio/studio.js` and `.css` (Clone Existing Page, Generated Pages)
- version files, `CHANGELOG.md`, `docs/`

## Tests executed

- `php -l` on every PHP file: no syntax errors (PHP 8.3).
- `node --check assets/website-studio/studio.js`: OK.
- `php tests/run.php`: **70 passed, 0 failed**.
- Converter benchmark: a page with about 2,000 elements and 1,500 CSS rules converts in 0.12 s (12 MB peak).

## Known limitations

- **Screenshot to design (phase 5)** is a visual approximation.
  - Layout quality depends on the vision model, and fonts are guessed (or left to the theme).
  - Photo backgrounds and real images are not recreated: placeholders are added, and AI images come in phase 6.
  - Tablet and mobile layouts follow rules; they are not observed from the screenshot.
  - Provider request formats follow each API's documentation and are unit-tested, but they have not been sent to the real APIs from this environment.
  - Custom OpenAI-compatible endpoints cannot read screenshots yet.

0. **HTML conversion (phase 4)** approximates layouts. Converted:
   - flex/grid rows become columns; absolute positioning, transforms and animations are reported and not converted;
   - gradients become their first colour, and multi-layer shadows are dropped;
   - `:hover` states, pseudo-elements and sibling selectors are not used;
   - images stay at their external URLs (importing them into the Media Library comes with phase 6 media);
   - forms, SVG and custom scripts need Elementor features or plugins, and the report says so.

   Not claimed: pixel-perfect output.

1. **Not yet run in WordPress or Elementor.** This environment did not permit running the downloaded WordPress/Elementor code. Every Elementor setting key the mapper writes was checked against the Elementor 4.0.8 source. The live checklist (`docs/website-studio/TESTING.md`, items 1–17) is the next step and must pass before release.
2. **Alignment values.** Elementor 4 uses `start`/`end` for heading, text, image and icon alignment, and maps the older `left`/`right` values (which the mapper writes, for 3.x compatibility). Pages render correctly, but Elementor 4's editor does not highlight a "right" alignment button.
3. **Card widths** use the `custom` unit `calc((100% - gaps) / n)`, which needs Elementor 3.12 or newer. The engine requires 3.16 or newer.
4. **Image alt text** of Media Library images comes from the attachment, which Elementor renders. Cloning does not change shared attachments' alt text. New images with their own alt text arrive in phase 6.
5. **Elementor 4 atomic elements** are copied as they are, but their text is not rewritten. Templates and global widgets inside a page are not rewritten (they are shared with other pages).
6. **Large pages:** up to 150 text fields are rewritten per clone run. The rest keep their text, and the user is told.
7. React source of the original dashboard is still missing, so the studio keeps its own menu.
8. The persistence tests use SQLite, not MySQL/MariaDB.

## Security issues

None open. See `docs/SECURITY.md`, sections "AI Website Studio" and "cloning and page building".

## Compatibility status

| | Status |
|---|---|
| PHP 8.2+ | Linted on 8.3 |
| WordPress 6.6+ | Not run live |
| Elementor Free 3.16+ / 4.x | Setting keys checked against the 4.0.8 source; containers and legacy sections both supported; not run live |
| Elementor Pro | Never required; Pro widgets are never generated; they are kept, with a warning, when cloning |
| Yoast SEO / Rank Math | Clone meta synced with the existing rules (user edits win) |
