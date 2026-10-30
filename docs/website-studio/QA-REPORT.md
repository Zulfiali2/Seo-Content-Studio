# Phase 8 QA report — AI Website Studio 1.9.0-rc.1

Date: 2026-10-30. Scope: everything added in phases 1–7 (AI Website Studio). The pre-existing content studio keeps its own tests.

**Summary.** Automated tests, the security review, the performance benchmark and the static compatibility checks are complete, and the problems they found are fixed. The live run on WordPress + Elementor is **still open**: this build environment did not permit running the downloaded WordPress/Elementor code, so the release is a **release candidate**. 1.9.0 final follows once `tests/integration/smoke.php` and the manual checklist (TESTING.md) pass on a real site.

## 1. Automated tests

`php tests/run.php` runs 94 tests, all passing (PHP 8.3, SQLite-backed `$wpdb` double).

| Suite | Tests | Area |
|---|---|---|
| DesignTokensTest, CssParserTest, ElementorDocumentAnalyzerTest, BrandExtractorTest | 22 | Brand analysis (phase 1) |
| PersistenceAndPermissionsTest, CredentialRotationTest | 8 | Repositories, REST permissions, key rotation |
| EngineTest, DraftManagerTest | 16 | Native Elementor engine (phase 3) |
| ClonerTest, CloneJobTest | 8 | Page cloner (phase 2) |
| ConverterTest | 8 | HTML/CSS → Elementor (phase 4) |
| VisionTest | 8 | Screenshot → design (phase 5) |
| GeneratorTest | 7 | AI page generator, links, images (phase 6) |
| StudioToolsTest | 11 | Revisions, checkers, regeneration, library, REST permissions (phase 7) |
| SecurityTest | 6 | Hostile input at every boundary (phase 8) |

There is also a live integration smoke test, `tests/integration/smoke.php`, run with `wp eval-file … --user=admin`. It makes no AI requests and needs no network. It covers:
- building a draft through the real engine and Elementor, and checking the rendered HTML;
- a consistency fix;
- a section proposal through preview and approval;
- capturing an outside edit and restoring a revision;
- inserting a library item;
- cleaning up everything it created.

Status: **written, not yet run** (see the summary).

## 2. Security review

What was reviewed:
- every REST route (each has a capability check plus an ownership or edit-permission check; none uses `__return_true`);
- superglobal use (none in the studio code; uploads go through the REST file API);
- SQL (custom tables only, values bound with `prepare()` or `$wpdb` helpers; three unprepared queries take no input);
- output escaping in the admin screens;
- the trust boundaries listed below.

Boundaries covered by `SecurityTest`:
- **Model output** (generator, section writer, vision): tags arrive as escaped text. Links come only from the user's validated button URL or the server's internal-page list. Icons come from an allow-list, and colours cannot be set by the model.
- **XSS payload corpus** (16 variants, including entity, tab, case and `data:` bypasses) through `HtmlFragment`, `PageDocument`, `ElementorMapper` and `DocumentValidator`: nothing executable reaches a document.
- **SQL**: hostile values in library kind, category and name, and in revision fields, are replaced or bound; the tables stay intact.
- **Stored data**: tampered revision snapshots are ignored, never unserialised.
- **Fixes**: only server-generated finding ids are applied, and user text is escaped.
- **Previews**: only pages created as previews can be deleted through the preview manager, and previews need page-creation rights and pass validation.

Findings fixed in this phase:

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | High | `DocumentValidator` in `cloned` mode accepted script and event-handler markup. Phase 7 writes (fixes, restores, library inserts) use that mode, so a user without `unfiltered_html` (an Author, or an Editor on multisite) could carry a script into a studio draft through a library item or a restore. | In `cloned` mode, executable content is refused unless the current user has `unfiltered_html`; for those users it is kept with a warning. Script URLs (`javascript:` and similar) are refused in every mode. Tests: `SecurityTest::testCopiedScriptsNeedUnfilteredHtml`, `EngineTest`. |
| 2 | Low | A section rewrite that returned no cards for a grid section failed validation instead of falling back. | The kept layout is fitted to the answer: no cards gives one column, and two halves need two parts. |

Unchanged from the earlier phases:
- SSRF-protected HTTP client;
- encrypted credentials with key rotation;
- upload validation by file signature;
- temporary files that cannot be served;
- the no-publish and no-overwrite rules enforced in `DraftManager`.

## 3. Performance

Run with `php tests/benchmark.php` on the largest documents the studio accepts (the same machine as the tests):

| Step | Time | Peak memory |
|---|---|---|
| PageDocument::normalize (40 sections, 1,040 elements) | 12 ms | 2.4 MB |
| ElementorMapper::map (1,360 Elementor elements, 634 KB JSON) | 12 ms | 3.6 MB |
| DocumentValidator::validate | 13 ms | 0.8 MB |
| ConsistencyChecker::check | 11 ms | 1.6 MB |
| AccessibilityChecker::check | 8 ms | 1.3 MB |
| RevisionDiff::compare | 4 ms | 1.2 MB |
| 30 revision snapshots (compress, store, trim to 25) | 341 ms | 2.4 MB |
| Read one snapshot (1,035 KB raw, 30 KB stored) | 11 ms | 8.7 MB |
| HtmlConverter (300 sections, 7,500 nodes, 600 CSS rules) | 131 ms | 5.3 MB |

AI and image requests run in background jobs: one step per request, a lock per job, and results stored before the step ends. The REST polling endpoints only read state and advance a stalled job.

## 4. Compatibility

| Target | Result | How it was checked |
|---|---|---|
| PHP 8.2 / 8.3 | OK | Linted on 8.3. No 8.3+ or 8.4 syntax or functions (typed class constants, `json_validate`, `#[Override]`, property hooks, `new X()->` without brackets). |
| WordPress 6.6 – 6.8 | Expected OK; not run live | All WordPress functions used exist in 6.6. The admin UI uses `wp.element`'s `createRoot` when present and falls back to `render`. `Tested up to` stays at 6.7 until the live run. |
| Elementor Free 3.16+ / 4.0 | Setting keys verified; not run live | Every key the mapper, checkers and fixes write or read was checked against the Elementor 4.0.0 source: container `width` (with its responsive behaviour), `boxed_width`, `flex_*`, `padding_*`, `header_size`, `title_color`, `text_color`, `button_text_color`, `image_border_radius`, typography groups, kit `system_colors` / `system_typography` / `body_background_color`, and legacy `_column_size`. |
| Elementor Pro | Never required | Pro widgets are never generated. When cloning they are kept, with a warning if Pro is inactive. The accessibility checker reads Pro form fields (`form_fields[].field_label`), but these could not be verified against the Pro source here. |
| SQLite (WP SQLite integration) | Fixed | `RevisionRepository::pruneOrphans()` used a MySQL-only multi-table `DELETE`; it now uses portable statements. |

Elementor behaviour found while verifying, and fixed: a container's **phone width is not inherited** from larger screens; an empty value means 100% (`container.php`, `min_affected_device`). The responsive check had assumed inheritance and reported columns as "side by side on phones" when they were not. It now flags only an explicit narrow phone width.

## 5. Packaging

`bin/build-zip.sh` builds `dist/ai-seo-content-studio-1.9.0-rc.1.zip`. It excludes `tests/`, `bin/`, `dist/`, `.git`, `.gitignore`, `DEVELOPMENT_STATUS.md`, logs and the missing `src/`. The archive was listed and checked: it holds no test files and no credentials, and a secret scan of the repository found none.

## 6. Open before 1.9.0 final

1. Run `wp eval-file tests/integration/smoke.php --user=admin` on WordPress 6.8 with Elementor Free 4.x, then with Pro, then with Flexbox Containers off.
2. Work through the manual checklist (TESTING.md 1–36) with real AI providers: OpenAI, Anthropic and Gemini for text and vision, and one image provider.
3. Raise `Tested up to` and set the version to 1.9.0.
