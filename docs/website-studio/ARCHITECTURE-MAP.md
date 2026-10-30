# AI Website Studio — Phase 0 audit and implementation map

Baseline audited: **AI SEO Content Studio 1.6.8**, the uploaded ZIP. The brief names 1.6.7, but the ZIP is 1.6.8, which already contains the 1.6.7 changes and the first security hardening items.

## 1. Existing architecture (as found)

| Layer | Where | Notes |
|---|---|---|
| Bootstrap | `ai-seo-content-studio.php`, `includes/Autoloader.php` | PSR-4-style autoloader for `AiSeoContentStudio\` → `includes/`. A requirements gate (PHP 8.2, WP 6.6, sodium) runs first. |
| Composition root | `includes/Core/Plugin.php` | Builds every service once on `plugins_loaded`. No container; constructor injection. |
| Migrations | `includes/Core/Migrations.php` | `aiscs_db_version` option, `dbDelta`. 1.6.8 = version 5. |
| Capabilities | `includes/Core/Capabilities.php` | `aiscs_manage_settings` (admin), `aiscs_generate_content` (admin, editor). |
| Settings | `includes/Settings/*` | One option, schema-validated. |
| Credentials | `includes/Security/CredentialStore.php`, `Encryption.php` | libsodium secretbox. The key comes from `AISCS_ENCRYPTION_KEY` or the WP salts. |
| HTTP | `includes/Http/HttpClient.php`, `Security/UrlValidator.php` | SSRF validation, manual redirects (each hop re-checked), DNS pinning via `CURLOPT_RESOLVE`, size limits, retries. |
| AI providers | `includes/Providers/*` | OpenAI, Anthropic, Gemini, xAI, OpenAI-compatible. `TextGenerationService` and `ImageGenerationService` are the only entry points (limits, usage, cost). |
| Projects | `includes/Projects/ProjectRepository.php` | Private CPT `aiscs_project`; each section is stored as post meta (`_aiscs_*`); ownership = post author or manage capability. |
| Jobs | `includes/Jobs/JobRunner.php` | WP-Cron steps per project, locks, retries, cancellation; handlers implement `JobHandlerInterface`. |
| REST | `includes/REST/*Controller.php` | Namespace `aiscs/v1`; base `RestController` with `canManage` / `canGenerate`. |
| Elementor | `includes/Elementor/*` | `ElementorBridge` (public `Document` API: `documents->get()`, `save()`, `get_elements_data()`, `get_edit_url()`), `LayoutBuilder` (heading, text-editor, button, icon-list, image, shortcode; containers or legacy sections), `LayoutValidator` (ids, parent/child rules, widget allow-list). |
| Admin UI | `includes/Admin/AdminPage.php`, `assets/build/index.js` | **Only the compiled bundle is in the ZIP. The React source (`src/`, `package.json`) is missing.** |
| Tests | — | **None in the ZIP.** |

### Existing REST routes (`/wp-json/aiscs/v1`)

`/settings`, `/status`, `/usage`, `/providers`, `/providers/{id}/credentials`, `/providers/{id}/test`, `/research/markets`, `/research/provider[/credentials|/test]`, `/projects`, `/projects/{id}` + `/research`, `/brief`, `/brief/generate`, `/keyword-plan`, `/job`, `/job/cancel`, `/estimate/{type}`, `/content`, `/content/generate`, `/evaluate`, `/evaluate/ai`, `/versions`, `/versions/{vid}[/restore]`, `/draft`, `/preview`, `/optimize/…`, `/images…`, `/optimizer/posts`, `/optimizer/projects`, `/stock-sources…`.

### Database (before)

`{prefix}aiscs_usage_log`, plus the options `aiscs_settings`, `aiscs_credentials` and `aiscs_db_version`. Project data lives in post meta.

## 2. Compatibility constraints found

1. **Elementor.** The bridge already uses only public Document APIs. Elementor 4.x (verified against the 4.0.8 source) still stores classic widgets in `_elementor_data`. Elementor 4 also adds *atomic* elements (`e-flexbox`, `e-heading`, …) with typed props (`{"$$type":…,"value":…}`). The studio analyzes atomic elements structurally only, and reports this to the user.
2. **Kit data.** Global colours and fonts are in the active kit's `_elementor_page_settings` (`system_colors`, `custom_colors`, `system_typography`, `custom_typography`, theme-style keys, `viewport_tablet` / `viewport_mobile`). Widgets reference them through `__globals__` → `globals/colors?id=…`.
3. **Elementor Pro widgets** (form, nav-menu, …) can appear on a site where Pro is inactive. The analyzer lists them as unavailable. The future generator must not emit them unless `widgetTypes()` contains them.
4. **Admin UI.** Without the React source, the existing dashboard's navigation cannot be changed safely. The studio therefore has its own menu and script (built on `wp.element`, no build step). Once the source is supplied, the two can be merged.
5. **Background jobs** depend on WP-Cron (`DISABLE_WP_CRON` sites need a real cron).
6. **The local test site could not be run.** WordPress and Elementor were cloned for reading, but running externally downloaded code is blocked in this environment. Integration testing in a real WordPress + Elementor install is still outstanding (see `TESTING.md`).

## 3. Reuse map

| Need | Reused | New |
|---|---|---|
| AI text and vision | `TextGenerationService` (limits, usage, cost, redaction) | Vision input in `TextRequest` (phase 5) |
| Images | `ImageGenerationService`, `MediaImporter`, `ImageValidator`, `StockImages` | Section image slots (phase 6) |
| HTTP / URL analysis | `HttpClient::fetchPage`, `UrlValidator` | — (the URL analyzer must use these, never its own client) |
| Credentials | `CredentialStore` | Key rotation (done in 1.7.0) |
| Jobs | `JobRunner` + handlers on a project | Studio job handlers (phases 2+) |
| Elementor writing | `ElementorBridge::save()`, `LayoutValidator` | `ElementorMapper` and a wider validator (phase 3) |
| Ownership | `ProjectRepository::canAccess` pattern | Same pattern for profiles and analyses |
| Locks | `Support\Lock` (token-checked) | — |

## 4. Module plan and status

| Phase | Deliverable | Status |
|---|---|---|
| 0 | Audit and implementation map | **Done** (this file) |
| 1 | Studio menu, page selector, Website Analyzer, Brand Extractor, brand profiles | **Done (1.7.0)**, verified by unit tests only (no live WordPress run) |
| 2 | Page cloner (new draft, new widget IDs, content replacement, original untouched) | **Done (1.8.0)**, unit tests only |
| 3 | Normalized design schema + Elementor mapper + document validator | **Done (1.8.0)**, unit tests only |
| 4 | HTML/CSS → Elementor (reuses `CssParser`) | **Done (1.9.0-beta.1)**, unit tests only |
| 5 | Screenshot → design (vision) | **Done (1.9.0-beta.2)**, unit tests only |
| 6 | Content-to-design generation, section images, internal links | **Done (1.9.0-beta.3)**, unit tests only |
| 7 | Consistency checker, accessibility checker, section regeneration, preview, revisions, design library | **Done (1.9.0-beta.4)**, unit tests only |
| 8 | QA, live WP/Elementor matrix, packaging | **Release candidate (1.9.0-rc.1)**: automated, security, performance and static compatibility done; live run open |

## 5. Phase 1 design (implemented)

```
WP + Elementor (local reads only)
  └─ SiteDesignSource ── elements, kit, theme.json, theme/Elementor CSS files, fingerprint
       ├─ ElementorDocumentAnalyzer ─┐
       ├─ BlockDocumentAnalyzer ─────┼─▶ StyleSignals (weighted observations per slot, per source)
       └─ CssStyleAnalyzer(CssParser)┘
                                      └─▶ BrandExtractor ─▶ DesignTokens (schema v1, validated)
WebsiteAnalyzer: orchestration + cache (AnalysisRepository, keyed by input fingerprint)
WebsiteStudioController: REST + permissions; BrandProfileRepository; AuditLog
WebsiteStudioPage + assets/website-studio/studio.js: UI
```

The analyzers and the token schema are pure PHP with no WordPress calls, so they are unit-tested directly. Every value in a profile passes a strict pattern (hex colour, CSS length or box, single shadow, plain font name). This lets later phases write tokens into Elementor settings without further escaping.

## 6. Phases 2–3 design (implemented in 1.8.0)

```
Workflow input (AI / HTML / screenshot / JSON)
  └─ PageDocument::normalize  (schema v1, sanitised text, stable section ids)
       └─ ElementorMapper(ElementorCompat, IdGenerator, DesignTokens, kit colours)
            └─ DocumentValidator (MODE_GENERATED)
                 └─ DraftManager::create → ElementorBridge::save (Document::save) → new draft
                                              + GeneratedPageRepository row (ownership, section map)

Existing Elementor page
  └─ PageCloner::plan    → ContentSlots::extract (rewrite vs protected)
  └─ CloneJob (JobRunner): plan → write (CloneContentGenerator, one AI request) → build
       └─ ContentSlots::apply (in memory) → ElementTreeCopier (new ids) → DocumentValidator (MODE_CLONED)
            └─ DraftManager::create → new draft (source only read; fingerprint checked)
```

`StudioServices` builds the Elementor-dependent services on first use, because Elementor registers its widgets after `init`.

Element settings were checked against the Elementor 4.0.8 source:
- Containers: `content_width`, `boxed_width`, `width` (with the `custom` unit), `flex_direction`, `flex_wrap`, `flex_gap` (GAPS), `flex_align_items`, `min_height`, `padding*`, `background_*`, `border_radius`, `box_shadow_box_shadow*`.
- Widgets: heading `title/header_size/title_color/typography_*`; button `text/link/background_*/button_text_color/border_*/text_padding`; accordion `tabs/tab_title/tab_content/faq_schema`; icon-list `icon_list`; image `image/image_size/caption_source/image_border_radius`; spacer `space`.
- Legacy layout: section `layout/content_width/height`, column `_column_size/_inline_size*`.

## 7. Phase 4 design (implemented in 1.9.0-beta.1)

```
HTML + CSS (+ JS, analysed only)
  └─ HtmlConverter
       ├─ DOMDocument (LIBXML_NONET) → strip scripts, templates, links, meta, base, on* attributes
       ├─ StyleResolver(CssParser rules + <style> blocks) + SelectorMatcher → own()/computed() per device
       ├─ sections → findRow (flex/grid) → columns per device → groups (cards) / split
       ├─ leaves → heading | text (p/ul/ol/table/inline) | button | image | video | accordion | icon | divider | html (fallback)
       ├─ readability fix (contrast < 3:1) + report
       └─ PageDocument::normalize(allow_html)
  └─ tokens: CssStyleAnalyzer + BrandExtractor on the same CSS (when no brand profile is chosen)
  └─ PageBuilder::build(MODE_CONVERTED) → ElementorMapper (element styles, video, html, background image)
       → DocumentValidator(MODE_CONVERTED) → DraftManager::create
```

## 8. Phase 5 design (implemented in 1.9.0-beta.2)

```
Upload (multipart) -> ScreenshotPreparer::validate -> ScreenshotStore::put (temp, token)
studio_screenshot_analyze: tiles (1280 px wide, <=1400 px slices, JPEG) -> TextRequest with ImageInput
     -> provider body (OpenAI/xAI input_image | Anthropic image blocks | Gemini inlineData)
     -> VisionDesign::toDocument (Output A: PageDocument + tokens + report) -> delete screenshot
review in the UI
studio_screenshot_build: write (Output B: CloneContentGenerator on VisionDesign::slots, optional)
     -> images (PlaceholderImage + MediaImporter, optional) -> resolveImages
     -> PageBuilder::build (MODE_GENERATED) -> DraftManager
```

## 9. Phase 6 design (implemented in 1.9.0-beta.3)

```
POST /design/generate -> SiteGateway::linkCandidates (published pages, as the user)
studio_generate:  PageGenerator::request (brief, business facts, candidate ids/titles)
     -> TextGenerationService (strict JSON) -> PageGenerator::toDocument (VisionDesign::leaves, role backgrounds, CTA URL, image cap)
     -> WritingProfile::polish -> ContentLayoutMapper::adapt -> PageDocument::normalize (probe)
     -> InternalLinks::suggestions, PageGenerator::factCheck, imageSlots -> review
studio_generate_build: placeholders (PlaceholderImage + MediaImporter)
     -> InternalLinks::apply (approved, permalinks re-read) -> VisionDesign::resolveImages
     -> tokens (brand profile | Elementor kit colours | defaults) -> PageBuilder::build (MODE_GENERATED)
     -> SiteGateway::saveSeo, attach placeholders -> image slots
studio_page_images: ImageGenerationService -> MediaImporter (candidate only)
POST .../images/apply: GeneratedImages::swap on the live Elementor data -> DraftManager::update (MODE_CLONED)
```

## 10. Phase 7 design (implemented in 1.9.0-beta.4)

```
DraftManager::create/update ──> RevisionRepository (snapshot: elements + document + section_map; outside edits captured first)
Page Tools
  overview ── SectionLocator (section_map → top-level element span)
  checks ──── PageChecks → ConsistencyChecker | AccessibilityChecker → Findings (server-side ops) → apply by id → DraftManager::update (fix)
  regenerate ─ style: SectionTransforms ─┐
               AI: RegenerateJob → SectionWriter (one section, strict JSON) / ImageGenerationService
                                        └→ Proposals::build (PageBuilder::mapper()->mapSection, validate generated)
               preview: PreviewManager (temporary draft) · approve: replaceTop → DraftManager::update (regenerate) · discard
  revisions ── RevisionService (RevisionDiff compare, restore, restoreSection)
Design library ── DesignLibrary (save from page, wrap components, ElementTreeCopier with reserved ids, insert → DraftManager::update)
```
