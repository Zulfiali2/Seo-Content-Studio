# Changelog

All notable changes to AI SEO Content Studio. Versions follow semantic versioning.

## 1.9.0-rc.1 — 2026-10-30 — AI Website Studio, phase 8: QA and release candidate

Security
- `DocumentValidator` (`cloned` mode, used by fixes, restores and library inserts) now refuses script and event-handler markup unless the current user has `unfiltered_html`. Before, a user without that capability could carry scripts into a studio draft through copied content. Script URLs stay refused in every mode.

Fixed
- Responsive consistency check: Elementor does not inherit a container's phone width from larger screens (an empty value is 100%), so columns are reported only when an explicit narrow phone width is set.
- `RevisionRepository::pruneOrphans()` used a MySQL-only multi-table DELETE. It now uses portable statements, so it works with the WordPress SQLite integration.
- Section rewrites that return no cards for a grid section now fall back to one column instead of failing validation.

Added
- `tests/unit/SecurityTest.php`: XSS payload corpus through every sanitiser, model-output injection, SQL inputs, tampered snapshots, fix and preview abuse, and `unfiltered_html` rules.
- `tests/benchmark.php`: performance budgets for the largest accepted documents.
- `tests/integration/smoke.php`: a live WordPress + Elementor smoke test (`wp eval-file … --user=admin`).
- `docs/website-studio/USER-GUIDE.md` and `docs/website-studio/QA-REPORT.md`.

## 1.9.0-beta.4 — 2026-10-30 — AI Website Studio, phase 7: advanced features

Added
- **Revision history** (new table `aiscs_page_revisions`, DB version 8). Every change the studio makes to a draft stores a compressed snapshot of the Elementor data, the design document and the section map, with the action, a summary, the section, the user and the provider/model.
  - Edits made in Elementor between studio changes are captured as their own revision ("Edited in Elementor") before the next change. A page made before this version gets its first revision on its first change.
  - Retention: 25 revisions per page; revision 1 (the page as created) is always kept. Snapshots over 4 MB are recorded without a snapshot. History is removed when the page is deleted, and orphans are pruned daily.
  - Screens and REST: list, compare a revision with the page now (sections added, removed, moved and changed, with the texts that changed), restore the whole page, and undo one section. A restore is itself a revision.
- **Section regeneration** with proposals:
  - AI actions (background job `studio_section_regenerate`): rewrite content, improve SEO and redesign (one strict-JSON request for that section only), or regenerate its images with the image provider.
  - Style actions (immediate, no AI): background colour role, heading size and font, layout and columns, spacing preset, responsive improvements.
  - The result is a proposal. It is validated in `generated` mode and previewed on a temporary page; only after **Approve** does that one section change (the other sections are left as they are). A revision is stored and the design document and section map are updated. **Discard** deletes the proposal and its images.
  - Rewrites keep the section's structure and its existing images; images are never invented (extra image slots are dropped and reported); the page keeps its single H1.
- **Page preview**: Elementor's own rendering in desktop (1280 px), tablet (768 px) and mobile (390 px) frames, as a full page or scrolled to a section.
  - Proposals and library items are previewed on temporary draft pages. They are hidden from the Pages list and the page pickers, deleted on approve or discard, and pruned after 24 hours.
  - Previews use WordPress's nonce-protected preview links, so only users who may edit the page can open them.
- **Design Consistency Checker**: compares a page with a brand profile, with up to five reference pages (analysed by the Website Analyzer), or with the tokens the page was built with (stored with the design document from now on), else the Elementor kit.
  - Reports pass, warning or fail for brand colours, typography, button styling, card styling, section spacing, container widths, image treatment, heading hierarchy, visual patterns and responsive layout, plus a score.
  - Recommendations are computed on the server and applied one by one by id. A fix changes only the settings it names; colours that match a kit colour are linked to the global colour.
- **Accessibility Checker** (WCAG 2.2 AA as the reference; it never claims compliance):
  - Checks heading hierarchy and empty headings, missing image alt text, empty or vague links and buttons, buttons without a link, colour contrast of explicit colours against the background behind them, small text, long unbroken text, form fields without labels, custom HTML that needs a keyboard check, and video captions.
  - Each finding has a severity (error, warning or recommendation) and a suggested fix. Fixes that need text (alt text, button labels) ask for it. Alt text is saved on the Media Library attachment.
- **Design Library** (new table `aiscs_design_library`):
  - Save a section, or any element inside one as a component, from a page you may edit, with a name and category (hero, services, FAQ…; button, card, pricing…).
  - Preview, edit, duplicate, delete, and insert into a studio draft (top or end). Inserted items get new element ids that are unique on the page; a component is wrapped in a section. Items that need Flexbox Containers are refused on sites without them.
- **Responsive generator**: besides the engine's per-device values, the consistency checker flags large headings without phone sizes, columns that stay side by side on phones and large section padding on phones, with fixes. "Improve responsive design" regenerates a section's tablet and phone layout.
- **Screens**:
  - Page Tools, with Preview, Sections, Design consistency, Accessibility and Revisions tabs, linked from Generated Pages.
  - Design Consistency Checker, Design Library, and Revisions and History.
- **REST**:
  - `GET /design/pages/{id}/overview`
  - `GET|POST /design/pages/{id}/preview`
  - `POST /design/pages/{id}/consistency-check` and `/consistency/apply`
  - `POST /design/pages/{id}/accessibility-check` and `/accessibility/apply`
  - `POST /design/pages/{id}/sections/{section}/regenerate`
  - `GET /design/proposals/{id}`, and `POST /design/proposals/{id}/preview`, `/approve` and `/discard`
  - `GET /design/pages/{id}/revisions`, `GET /design/pages/{id}/revisions/compare`, `POST /design/pages/{id}/revisions/{n}/restore` and `POST /design/pages/{id}/revisions/{n}/sections/{element}/restore`
  - `GET|POST /design/library`, `GET|PUT|DELETE /design/library/{id}`, and `POST /design/library/{id}/duplicate`, `/preview` and `/insert`

Changed
- `DraftManager::update()` takes the revision details and stores a revision. `DraftManager::validate()` validates without saving.
- `PageBuilder` stores the build tokens with the design document. `PageBuilder::mapper()` is shared with regeneration.
- `PageGenerator::sectionSchema()` and `contentRules()` are shared with section regeneration.
- `ContentLayoutMapper::adapt()` can work on single sections.

## 1.9.0-beta.3 — 2026-10-30 — AI Website Studio, phase 6: AI page generator

Added
- **AI Page Generator** screen (AI Website Studio → AI Page Generator):
  - Brief: topic, primary and secondary keywords, intent, audience, location, tone, length (short/medium/long), optional section types, number of images (0–8), button link, facts and notes, page title, styles (Elementor site colours or a brand profile).
  - One strict-JSON request (`studio_page_generate`) returns the sections, the copy, the SEO title, meta description and slug, image alt texts and prompts, internal link suggestions and the assumptions the model had to make.
  - Review before anything is saved: SEO fields with lengths, sections with their layout per device, the layout adjustments, a **fact check** (sentences with numbers, `[placeholders]` to fill in, assumptions), link suggestions to approve one by one, and the image prompts.
- **Content-to-design mapping** (`ContentLayoutMapper`): the layout follows what was written.
  - Columns by card count: 2 → 2, 3 → 3, 4 → 4, 5–6 → 3, 8 → 4.
  - Long card text (over 55 words on average) → at most 2 columns.
  - Tablet: 1 column for three cards, else 2; mobile: always 1.
  - Split layouts need exactly two parts; FAQs are single-column; at most two buttons per section.
  - Long H1s get a smaller font size; exactly one H1 per page.
  - Every change is listed in the review.
- **Brand consistency**: the model never chooses colours. Sections use background roles (page, surface, primary, secondary, dark), mapped to the chosen brand profile, or else to the Elementor kit's global colours (with a derived light surface tint), or else to neutral defaults. Cards follow the section background.
- **Internal links**:
  - Candidates are the site's published, public, password-free pages and posts. The model sees their IDs and titles only.
  - A suggestion is kept only when its target exists and its anchor (1–8 words) really appears in a text element of the named section. At most 8 suggestions, one per target page.
  - Only approved links are inserted, by wrapping the first unlinked, whole-word occurrence of the anchor. The target is checked again (still published) when the draft is built.
- **Section images with approval**:
  - The draft is created with placeholder images in each image slot, attached to the draft so no clean-up deletes them.
  - "Generate selected images" makes one AI image per chosen slot from its (editable) prompt, using the default image provider. Each image is imported into the Media Library as a *suggestion*; the draft does not change.
  - "Use this image" replaces the placeholder in the draft's current Elementor data, so edits made in Elementor are kept. The placeholder is then deleted. "Discard" deletes a suggestion; regenerating replaces an unapproved suggestion.
- **Background jobs** `studio_generate`, `studio_generate_build` and `studio_page_images`. Every step stores its result first, so a retry never repeats a billed request, imports an image twice or creates a second draft. The layout is validated against the PageDocument schema before the review.
- **REST**:
  - `POST /design/generate`
  - `POST /design/generate/{id}/build`
  - `POST /design/generate/{id}/images`
  - `POST /design/generate/{id}/images/apply`
  - `POST /design/generate/{id}/images/{slot}/discard`

  `GET /design/jobs/{id}` returns the generator review and the image slots.

Changed
- The vision layout request and the page generator request may use up to 32,000 output tokens (only a limit; unused tokens are not billed), so long pages are not cut off when the per-request setting is low.
- `VisionDesign::leaves()` is shared with the generator: it supports multi-paragraph text and keeps image prompts.

## 1.9.0-beta.2 — 2026-10-30 — AI Website Studio, phase 5: screenshot to design

Added
- **Image input for AI providers**:
  - `ImageInput` (PNG/JPEG/WebP by signature, ≤5 MB), images on user messages, and a `vision` capability.
  - Request bodies for OpenAI Responses and xAI Grok (`input_image`), Anthropic Messages (base64 `image` blocks before the text) and Gemini (`inlineData`).
  - A request with images to a provider without vision fails with a clear message before anything is sent. Custom OpenAI-compatible endpoints do not claim vision.
  - Image hashes are part of the request hash, so duplicate-request detection covers images.
- **Image to Design** screen: upload a screenshot (≤10 MB, 64–8192 px), optionally name a vision model, then analyse.
  - Review shows the detected sections, layout and columns per device, palette, fonts, button and card shapes, the model's observations and the approximations, next to the uploaded image.
  - Then keep the screenshot's text or write new content from a brief, optionally add placeholder images to the Media Library, use the screenshot's colours or a brand profile, and create the draft.
- **Screenshot preparation**: the image is scaled to 1280 px wide and cut into top-to-bottom tiles of up to 1400 px, so tall full-page screenshots stay readable. Tiles are re-encoded as JPEG (EXIF/GPS metadata removed) and sent together in one request.
- **Output A** (vision, one request): a strict JSON layout (sections, header elements, cards, colours, shapes, transcribed text and lengths) turned into a PageDocument.
  - One H1 only; split layouts that do not fit become grids; unknown icons become a check icon (reported).
  - Palette colours become token references, so a brand profile restyles them.
  - Missing FAQ answers become visible "[Answer to this question]" placeholders, never invented.
- **Output B** (text, optional, one request): new content for every text slot at its original length, using the clone prompt's rules (no invented facts, testimonials or numbers).
- **Background jobs** `studio_screenshot_analyze` and `studio_screenshot_build`. Every step stores its result first, so a retry never repeats a billed request, imports images twice or creates a second draft.
- **Temporary storage**: the uploaded screenshot is kept only until it is analysed, in `uploads/aiscs-studio-tmp/` under a random token (`.bin`, `index.php`, deny-all `.htaccess`).
  - Deleted after a successful analysis or a final failure.
  - Kept while a retry is pending.
  - Pruned daily after 24 hours.
- **REST**: `POST /design/image/analyze` (multipart `screenshot`, optional `model`) and `POST /design/image/{id}/generate`. `GET /design/jobs/{id}` now returns the design for review.

## 1.9.0-beta.1 — 2026-10-30 — AI Website Studio, phase 4: HTML/CSS to Elementor

Added
- **HTML to Elementor** screen: paste or upload HTML, CSS and (optionally) JavaScript. Files are read in the browser.
  - Analyze: shows a conversion report and a sandboxed preview of the source (no scripts) at desktop, tablet and mobile widths.
  - Create a draft built from native Elementor widgets.
- **Converter**:
  - Parses the HTML with DOMDocument (no network) and resolves the CSS cascade: specificity, source order, inline styles, inheritance and `@media` rules per device.
  - Top-level blocks become sections. Site header, navigation and footer are skipped unless asked for.
  - CSS grid and flex rows become card grids or two-column splits, with the column count per device read from `grid-template-columns`, `flex-direction` and child widths.
  - Headings, paragraphs, lists and tables map to native widgets, and so do button-styled links, images and figures, YouTube/Vimeo embeds (Video widget), `<details>` (Accordion), `<hr>` and Font Awesome icons. Colours, fonts, per-device font sizes, alignment, padding, radius, borders and section backgrounds/images are kept.
- **Conversion report**: what converted (coverage %), what did not and why (forms, SVG, canvas, other iframes, images without an absolute URL, unknown icons), skipped header/footer, removed scripts and inline handlers, JavaScript features found (never run), linked stylesheets (not downloaded), unresolved relative URLs, CSS selectors and features not used, and approximations (gradient → colour, multi-layer shadows, readability fixes).
- **Controlled HTML widget fallback** (opt-in) for content without a native widget (`<dl>`, `<pre>`, `<address>`, …). It is sanitised: allow-listed tags, safe inline styles only, no scripts or event handlers. It is never the whole page.
- Without a brand profile, the page is styled from its own CSS (fonts, colours, buttons, container width).
- Unreadable text colours on a background (contrast below 3:1) are replaced with a readable colour, and this is reported.
- Engine:
  - Optional per-element style overrides in PageDocument (colour, background, font, per-device size, line height, padding, radius, border, per-device alignment), and section background images.
  - New `video` element, and an `html` element allowed only in conversion mode.
  - Validator mode `converted`; tables in rich text.
- REST: `POST /design/html/parse` (analysis only) and `POST /design/html/convert` (new draft).

Fixed
- `PageDocument::normalize()` is now idempotent (an empty `align` written by normalisation is accepted again).

## 1.8.0 — 2026-10-30 — AI Website Studio, phases 2 and 3: page cloner and native Elementor engine

Added
- **Clone Existing Page**: choose an Elementor page, see every text field and decide which to rewrite, enter the topic, keywords, audience, intent, location and tone, and get a new draft with the same design and new content. Kept by default: header and footer, the business name, legal text, contact details, testimonials, counters/prices and dynamic tags. The AI is told never to invent facts. One AI request per clone runs as a background job (real step status, cancel), and a retried step never repeats the request or creates a second draft. "Duplicate only" makes an exact copy without AI.
- The copy gets new element and repeater IDs, and keeps the design settings, responsive values, animations, global colours, page settings, page template and featured image. The source page is only read, and a fingerprint check confirms it did not change. The meta title and description go to the plugin's SEO fields and to Yoast SEO or Rank Math.
- **Native Elementor engine**: a versioned page design schema (PageDocument v1) mapped to native Free widgets (Heading, Text Editor, Button, Image, Icon, Icon List, Accordion with FAQ schema, Spacer, Divider) in flexbox containers. Legacy sections/columns are used when containers are off.
  - Brand-profile colours that match Elementor Site Settings are written as global references.
  - Tablet and mobile font sizes, padding and column widths are set per device. Hero and CTA buttons are full width on phones.
- **Document validator** run before every save: IDs, parent/child rules, widget availability, control value shapes, links (no `javascript:` or `data:`), media references and executable content.
- **Draft manager**: always creates a new draft through Elementor's `Document::save()`.
  - It writes to an existing page only when the studio created it and it is still unpublished.
  - If Elementor rejects a page, the new draft is removed.
- Generated Pages screen (Open in Elementor, Preview), and an advanced "build from design document" panel.
- REST:
  - `GET /design/capabilities`
  - `GET /pages/{id}/clone/plan`, `POST /pages/{id}/clone`, `POST /pages/{id}/clone-and-generate`
  - `GET /design/jobs/{id}`, `POST /design/jobs/{id}/cancel`
  - `POST /design/pages/build`, `GET /design/pages`
- Database version 7: table `aiscs_generated_pages`.

Changed
- Website Studio projects are not listed under Projects and History. `DraftWriter::syncSeoPlugins()` is now public static, so the cloner can reuse it.

Tests
- 54 unit tests in total, now including the mapper, validator, draft manager ownership and rollback, the cloner, and the clone job.

## 1.7.0 — 2026-10-30 — AI Website Studio, phase 1: brand analysis

Added
- AI Website Studio admin menu (Dashboard, Website Brand Analyzer, Brand Profiles). Its interface uses WordPress's bundled React packages and needs no build step.
- Website Brand Analyzer: choose up to 10 pages you can edit. Elementor pages are read from their Elementor document and the active kit (global colours and typography, theme style, breakpoints), with `__globals__` references resolved. Block and classic pages are read from their blocks or headings. theme.json styles, the theme stylesheet and Elementor's generated CSS files are also read. Everything is read locally: no AI request and no HTTP request. Results are cached until the page, kit, theme or global styles change.
- Section detection: hero, cards/services, CTA, FAQ, testimonials, contact, image grid, statistics, header, footer and content. Structure report: containers or legacy sections, nesting depth, widget counts, responsive settings, global references, custom CSS, and widgets not available on the site.
- Brand profiles: a versioned design-token schema (colours, palette, fonts, type scale per device, buttons, cards, images, section padding, container width, breakpoints). Each group records its source. Profiles can be edited, duplicated and deleted; every value is validated.
- REST: `GET /pages/available`, `POST /website/analyze`, `GET /website/analysis/{id}`, and `GET|POST /website/brand-profiles` and `GET|PUT|DELETE /website/brand-profiles/{id}`. Ownership is checked on every object, and edit permission on every page.
- Audit log of studio actions (analyses, profile create/update/delete), pruned with the usage-log retention setting.
- Database version 6: tables `aiscs_brand_profiles`, `aiscs_design_analyses` and `aiscs_audit_log`, removed on uninstall when data deletion is on.

Security
- Credential key rotation: with `AISCS_ENCRYPTION_KEY_PREVIOUS` (the old key, or `wp-salts`), stored API keys that the current key cannot open are read with the previous key and re-encrypted with the current one.

Tests
- Dependency-free unit tests (`php tests/run.php`): token validation, CSS parser, Elementor and block analyzers, brand extraction, repositories, REST permission callbacks (401/403/404, IDOR), and key rotation.

## 1.6.8 — 2026-10-30 — Security hardening, SEO plugin sync, same-keyword warning

Security
- SEC-001: the custom AI endpoint must use https. Plain http is accepted only for a server on the site's own network (every address private or local, with "allow private endpoints" on), so the API key never travels unencrypted across the internet.
- SEC-002: images are rejected before decoding when they have more than 25 million pixels (filter `aiscs_max_image_pixels`), on top of the 20 MB and 8192 px per side limits.
- SEC-003: locks store a random token and a request releases only a lock that still holds its token, in one compare-and-delete statement. An expired lock taken over by another request is no longer removed by the first one.

Added
- Yoast SEO and Rank Math: saving a draft also fills the SEO plugin's SEO title, meta description and focus keyword. A field is written only while it is empty or still holds what this plugin wrote last time, so edits made in the SEO plugin are kept.
- Same-keyword warning (keyword cannibalization): published or scheduled posts and pages whose title or URL contains the main keyword, or whose Yoast or Rank Math focus keyword is the main keyword, are listed in the Brief step under "Claims to verify before publishing" (before anything is written) and as a high-severity check in the SEO report.

## 1.6.7 — 2026-10-29 — Chat-style prompt fixes from the first test

Changed
- No invented first-person experience ("in my years…"): personal or business experience is written only when it is in the site owner's notes, and "we" is used only for the business.
- No H2 that repeats the title, and each H2 covers a different question.
- FAQ questions skip anything an H2 already answers.
- The introduction and conclusion do not open by repeating the title or keyword as a question.
- Articles are written from the business's point of view: a fitting option from the brand is mentioned, and readers are not steered to competitors or away from what the business sells.

## 1.6.6 — 2026-10-29 — Chat-style article writing

Changed
- "Whole article in one go" now writes like a Claude chat: one short, plain-language brief (about 700 words instead of about 1,500 words and 29 rules) and the article back as plain HTML instead of JSON.
  - The brief carries the title, language, length, market, the main keyword and its use range, every must-use secondary, semantic and NLP keyword, related terms, the outline (or "create your own outline" when none is written), competitor page headings from research, the brand, its services, internal links and the site owner's notes as instructions.
  - The AI may use its own knowledge (typical prices, figures, examples). Every specific figure, price, date or rule it states comes back in "Claims to verify". It still may not invent studies, quotes, reviews, customer stories or credentials.
  - The AI may add H2 or H3 sections the outline does not list. The answer is split into introduction, sections, FAQs and conclusion, so editing, approving, locking and regenerating single parts work as before.
  - Outbound links are kept only when their page loads, and are listed under "Official sources used".
  - Steps: prepare, write, fill (only if a part is missing), metadata, finalize. There is no separate final edit.
- In this mode the Brief step's outline is optional: with no headings the AI plans the structure.
- If any part is approved or locked, the full-rule one-go writer fills the rest around it, so kept parts are never lost.
- The previous one-go writer (full rule set, JSON answer) remains as the "structured" mode.
- Admin screen: the content-language note now says content is written in the target market's language.

Fixed
- Empty outline rows no longer create sections without a heading.

## 1.6.5 — 2026-10-29 — Hand-written outline

Changed
- The Brief step opens the outline editor straight away with a starting brief: title, H1 and slug from the primary keyword, the project's word range, three empty H2 rows and up to 5 related pages on this site as internal links (found by a WordPress search, no AI). The SEO writes the headings, notes and FAQs and saves. No AI request is made for the brief, so it costs nothing.
- "Regenerate brief" still asks the AI for a full brief when wanted.
- Until the SEO saves it, the starting brief follows changes to the keyword and keyword plan. A saved or AI brief is never replaced.
- Generating content now asks for a saved outline with at least one H2 section, with a clear message instead of an empty article.
- Developers can restore the old behaviour with `add_filter( 'aiscs_manual_brief', '__return_false' );`.

## 1.6.4 — 2026-10-29 — Better writing quality

Changed
- Content, briefs, research analysis, AI reviews and optimiser rewrites are written in the project's market language instead of always in English. The British/American spelling setting applies when writing in English.
- The worked-example section is planned only for process, calculation or step-by-step decision topics, not for every blog post. The bookkeeping-specific example was removed from the instructions.
- "Lead with the answer" applies after every H2 and under question-style H3s, not under every H3, and sections are asked to open in varied ways.
- The introduction and conclusion use word ranges (about 40-70 + 25-50 words; about 3-6 sentences) instead of exact counts.
- The system prompt no longer says "one part of a web page" when the whole article is written in one request.
- The writer now also receives the brief's content gaps (what the top results miss).
- The final edit is told to keep specific detail, examples and voice, and not to shorten good passages just to save words.
- Em dashes are replaced by sentence: a pair around an aside becomes commas, a single dash becomes a colon when an explanation follows, otherwise a comma. Before, every dash became a comma.
- Research analysis (the basis of the brief) uses the main model instead of the quick-tasks model.
- New installs default to the "General adult reader" level with sentences up to 25 words (was age 11-12 and 20 words). Existing settings are not changed.
- Shorter default banned-words list with real AI clichés only. Sites still using the old default list unchanged get the new one; edited lists are kept.

Fixed
- The final edit and the optimiser rewrite were capped at the "max output tokens" setting (8,000 by default), so long articles could be cut off. They now get 16,000–32,000 tokens of room, like the one-go article.

## 1.6.3 — 2026-10-29 — Reliability and security fixes

Fixed
- Background steps that work in several runs (filling empty parts after the one-go article, reading competitor pages, competitor ranking keywords) were stopped after two runs with "This step did not finish". A run that ends normally no longer counts as an interrupted attempt; a step that never completes is stopped after 60 runs.
- "Fill" tries each empty part once per job, so a part the AI keeps returning empty cannot repeat.
- The one-go article request was capped at the "max output tokens" setting (8,000 by default), so long articles were cut off. It now gets the documented 16,000–32,000 tokens of room.
- Multisite: after network activation, or on sites created later, the plugin's capabilities were only added on the main site. Each site now adds them the first time it runs the plugin.
- Two quick clicks could start two jobs on the same project.

Security
- Address checks for outbound page requests now normalise IPv6 and reject private IPv4 addresses written in any IPv6 form (::ffff:7f00:1, 6to4), plus multicast, Teredo and NAT64 ranges.
- Page and custom-endpoint requests connect to the address that was checked (cURL), so a DNS answer that changes in between (DNS rebinding) cannot reach a private address.
- A POST that failed with an ambiguous server error (408, 409, 500, 502, 504) is no longer repeated silently by the HTTP client, because the provider may already have billed it.
- Discarding a slot's image, searching stock photos and using placeholders now require the `upload_files` capability, like generating images.

Changed
- Deactivation also removes job steps queued in Action Scheduler.
- Saving a project step no longer runs every `save_post` hook on the site.

## 1.6.2 — 2026-10-20 — Answer first under every heading

Changed
- Writing rules: the first 1-2 sentences after every H2 and H3 (about 40-60 words) directly answer that heading, so they can be quoted as a featured snippet or AI answer.
- Brief rules: headings are phrased the way people search, with the main keyword in at least one H2 and secondary or must-use keywords in other headings where natural.
- Metadata: the meta title starts with (or very near the start) the exact main keyword, and the meta description contains it once.
- SEO report: "Main keyword in meta description" is now a warning when missing, and there is a new check, "Main keyword in a heading".

## 1.6.1 — 2026-10-20 — UK reading level

Added
- Reading level **UK plain English (GOV.UK style, reading age 9)**: short everyday words, active voice, "you", one idea per sentence, technical terms explained. The other levels now show their UK reading ages (Simple: reading age 11–12, UK Year 7; General adult reader: UK reading age 13–14).

## 1.6.0 — 2026-10-20 — Whole article in one go

Changed
- **The first draft is written in one request** (new default, "How the first draft is written" under Brand and Writing Style). The AI gets the full outline, notes, subheadings, target lengths, FAQ questions and all style, keyword and fact rules at once, and returns every part. It plans the article before writing, so each idea and example appears once and sections build on each other. Steps: `prepare → article → fill → meta → finalize`.
- Afterwards, regenerating a section still sends one request for that section only, with your optional instructions.
- Parts you approved or locked are sent as fixed text and are never replaced, including by "Regenerate everything not approved".
- "Section by section" (the previous behaviour, with the optional final edit) is still available.

Reliability
- If the one-go answer is cut off or unusable, nothing is lost: the "fill" step writes each empty part, and any unanswered FAQs, with separate requests. A part the AI left out is filled the same way.
- The one-go request gets at least 16,000 output tokens of room (up to 32,000). This is only a cap: you pay for the text actually written.

## 1.5.0 — 2026-10-16 — Brand, house style, trusted facts and a final edit

Added
- **Brand and Writing Style** screen (new menu item).
  - Business profile: name, about, country, contact page, services with their page links, and the author's name, credentials and bio. Articles name the business (at most 3 times), mention only the listed services and may link only to those pages. The conclusion ends with a call to action for the business.
  - Writing style: British or American English, reading level (default about age 11–12), longest sentence (default 20 words), tone, a list of words to avoid (prefilled), no em dashes (stray ones are replaced with commas), and the "speak to a professional" line at most once, in the conclusion (or never).
  - Article structure: a two-paragraph introduction (about 4 lines with the main keyword, then about 3 lines), one worked example section that runs the whole process with figures that add up, and a 4–5 sentence conclusion with a call to action.
- **Facts from trusted official sources** (default on; "Only the facts I give" restores the old behaviour).
  - The writer may state rules, thresholds and deadlines from sites on your trusted list (prefilled with gov.uk, legislation.gov.uk, HMRC via gov.uk, NHS, ONS, ICAEW, ACCA, IRS and others). It must name the source page for each one.
  - The plugin downloads each cited page. Only pages on a trusted domain that load stay linked; any other link is removed.
  - Every fact and its source appear in "Claims to verify" and in a new "Official sources used" table.
- **Must-use keywords** (Keywords step).
  - Secondary, semantic and NLP terms, plus an optional range for the exact main keyword (counted with the H1). Each part's request lists the must-use keywords not yet used, and the uses are spread across the parts.
  - Optional bolding of each term's first use. This is done by the plugin, not the AI, and skips headings, links and approved parts.
  - The SEO report has a coverage table (uses and bold for each term) and new checks for "Exact primary keyword uses", "Words to avoid" and "Em dashes".
- **Final edit of the whole article**.
  - One extra request after all parts are written. It removes repeated examples, warnings and duplicate question blocks, keeps to the word budget, fixes the introduction and conclusion format, works in missing must-use keywords, fixes style slips and keeps the professional-advice line only once.
  - The plugin measures keyword counts, banned words, dashes and advice lines and sends them to the editor.
  - Parts that are approved or locked are not changed. An edit that would cut more than 40% of the article is not applied.
  - The edit reuses the cached rules and article context. It can also be run on its own ("Run final edit"), and it is included in the cost estimate.
- **Three SEO title and three meta description options**, with character counts and a "Use" button.
  - Titles start with the exact main keyword and stay under 60 characters.
  - Descriptions contain the keyword and the business name and stay under 150 characters.
- **Competitor research without DataForSEO**.
  - The AI suggests up to 5 pages likely to rank, and you can list your own competitor pages on the keyword step.
  - Every page is downloaded (robots.txt respected), so only real pages are read. Pages that do not load are shown as skipped.
  - Their headings feed the analysis, and the AI reads the keywords each page targets from its title and headings. These are labelled "AI-suggested" and "AI reading", with no volumes or positions.
  - New setting under SEO Research Settings.
- Keyword step: optional article length (words). It overrides the brief's length and is used as the word budget for writing and the final edit.

Fixed
- Two FAQ sections: the brief can no longer plan an FAQ-style H2 when the page has an FAQ list. Such sections are removed and their questions move to the FAQ list.
- The same example reintroduced in several sections: the brief plans each idea for one section, the writer refers back instead of repeating, and the final edit removes what remains.
- "Speak to a qualified accountant" repeated in every section: only the conclusion may include it.
- Internal links in parts kept from an earlier run could be dropped when the outline was prepared again.

Security
- The checked sources and the edit report are written by the server only: a saved edit cannot mark a source as verified or add a link that was not allowed.

## 1.4.0 — 2026-10-16 — Lower AI costs, provider-neutral

Added
- **Cost mode** (Economy / Balanced (default) / Best quality) applied centrally by `CostPolicy` to every text request. Each adapter maps the effort to its own API: Claude `output_config.effort` (models that accept it), OpenAI and Grok `reasoning.effort` (reasoning models; not the fixed Grok 4.20 variants), Gemini 3+ `thinkingConfig.thinkingLevel`, Gemini 2.5 `thinkingConfig.thinkingBudget`. The custom endpoint gets no effort field.
- **Model for quick tasks** per provider: metadata, AI SEO review, research analysis and optimiser analysis use it (not in Best quality mode). Article parts and FAQs always use the main model.
- **Prompt caching**: article parts, FAQs and metadata now send the writing rules plus a shared, byte-identical "Article context" first and the per-part task after it. Claude requests mark the system prompt and the shared context with `cache_control`; OpenAI gets a `prompt_cache_key`; OpenAI and Gemini cache identical prompt starts automatically.
- Cached tokens are read from each provider's usage (Claude cache reads/writes, OpenAI `cached_tokens`, Gemini `cachedContentTokenCount`) and priced: new "Cached input / 1M" price column; without it Claude is estimated at 0.1× (reads) / 1.25× (writes) and other providers at full input price. Usage logs record total input tokens.
- "Add Claude list prices (Oct 2026)" on Usage and Costs (Opus 5.5, Sonnet 5.5, Haiku 5.5, with cached input prices) for you to confirm.

Fixed
- Claude 5-generation models think by default and thinking counts towards `max_tokens`; they now get the same extra output room as other reasoning models, so answers are not cut off.

Reliability
- If a provider rejects a cost setting (invalid model or parameter), the request is retried once exactly as originally asked. Duplicate detection uses the original request.

## 1.3.0 — 2026-10-16 — Competitor insights, stock photos, quality standard

Added
- Keywords step: **Competitor ranking keywords** — for each top-ranking competitor page, the keywords it ranks for (DataForSEO Labs `ranked_keywords`, highest volume first) with position, volume, difficulty, intent, estimated traffic and the source link. Add single keywords or all new ones to your secondary keywords. One request per competitor page, cached; on/off and keywords-per-page under SEO Research Settings. The AI research analysis also uses them.
- Research and Brief steps: **Top competitor headings** — H1/H2/H3 of the top 10 competitor pages (was 5), each with ranking position, page title and source link. In the Brief, "Add to outline" copies a heading into your outline with a note to make that section better than the competitor's. Existing installs still on the old default of 5 pages are raised to 10.
- Images step: an **Image source** per image — AI image, **free stock photo** or **placeholder**.
  - Openverse: copyright-free (public domain and CC0 only), no key needed.
  - Pexels and Pixabay: royalty-free, free API key (AI Providers → Free stock photos).
  - The server looks up the chosen image again, SSRF-checks and validates the file, and imports it into the Media Library with "Image by … via … (licence)" as caption and the source link stored.
  - Placeholders are drawn on your server (PHP GD) in the project's accent colour and marked "replace before publishing".
- Writing quality standard based on Google Search Central's current guidance (people-first content, main content quality, information gain over rehashing competitors, no fabricated authors/credentials, accuracy and expert consensus for health/finance/legal topics, fact-checking, no scaled low-value content). Used by the research analysis, brief, writing, optimiser and AI review prompts.

## 1.2.1 — 2026-10-14 — Full-width blog templates

Fixed
- Blog templates no longer get squeezed into a narrow column by themes (or a small Elementor content width): each template row now spans the screen and uses its own content width (1200px; 800px for reading columns). Sideways scrolling is prevented on template posts.
- Body text, lists, buttons, meta line and sidebar cards keep normal sentence case when the theme capitalises every word. Headings keep the theme's style.
- Takes effect on existing template posts after updating the plugin; no need to save them again.

## 1.2.0 — 2026-10-14 — Elementor blog templates

Added
- Output mode: a layout gallery with previews. Three new blog post templates built only from free Elementor widgets: **Magazine with sidebar**, **Centered article** and **Bold hero**, plus the existing simple styles. Accent colour picker (presets or custom), optional newsletter/form shortcode.
- Templates include breadcrumbs, category badge, author/date/reading-time line, summary, featured image, a table of contents linked to numbered sections, tip/note/example callout boxes, check-mark lists, FAQs, author box, latest posts, categories and related posts with thumbnails.
- The parts that depend on the live post are plugin shortcodes (`[aiscs_breadcrumbs]`, `[aiscs_post_badge]`, `[aiscs_post_meta]`, `[aiscs_featured_image]`, `[aiscs_author_box]`, `[aiscs_recent_posts]`, `[aiscs_categories]`, `[aiscs_related_posts]`), so dates, categories and related posts stay correct after publishing. They can also be used anywhere on the site.
- When a template is chosen, the AI may add at most one "Pro tip:", "Note:" or "Example:" box per part (as a blockquote), styled as a callout.
- Classic themes: template posts use Elementor's "Full Width" page layout, keeping the theme header and footer. Block themes keep their title and get the wide content width.

## 1.1.0 — 2026-10-14 — FAQs and guided regeneration

Added
- FAQ schema: published posts created by the plugin get FAQPage JSON-LD for the answered FAQs shown on the page. Per page with "Add FAQ schema (FAQPage)" in the Metadata card, and site-wide under General Settings ("Output FAQ schema"). It is also output when an SEO plugin is active, because those plugins only add FAQ markup for their own FAQ blocks.
- Generate content: "Regenerate" on the introduction, any section or the conclusion opens a box for optional instructions (tone, focus, length, points to add) that the AI follows for that regeneration only; the writing rules (no invented facts, links or claims) still take priority. FAQ answers have the same option ("Regenerate all answers").
- Generate content: add and remove FAQ questions, "Write missing answers" (answers only new, unanswered questions), and "Include FAQ section" to leave FAQs out of the page, draft and schema completely. Questions changed here are kept when content is regenerated.

## 1.0.1 — 2026-10-13 — Free-tier friendly

Fixed
- Model lists hide models that cannot be used for normal generation (Gemini "Live" streaming models, audio, embeddings, robotics, etc.); Gemini lists are filtered by the methods each model supports.
- Background jobs wait automatically (at least a minute, up to 6 times) when a provider's per-minute rate limit is reached, then continue; a lasting rate limit fails with a hint about daily limits.
- A rate-limit answer that reports "limit: 0" (no allowance for that model on the plan, e.g. image models on free plans) is reported straight away as a quota problem instead of being retried.

## 1.0.0 — 2026-10-13 — Production release

Removed
- Demo mode (offline sample AI text, sample search data and placeholder images). Existing installs that had demo mode selected are reset to "no provider selected" automatically; choose a real provider under AI Providers.

Changed
- Simpler setup: the Dashboard shows a setup checklist and quick actions; AI Providers asks just "Write content with" / "Create images with", each provider card has "Save key and test", model lists from your key (writing and image models filtered), a "Get an API key" link, and an Advanced section for limits and custom endpoints.
- A setup notice on New Content and the Optimiser when no writing AI is ready.
- Plain-language messages (cost estimates, truncated answers, interrupted steps, background tasks).

Fixed (production audit)
- Reasoning models (Gemini 2.5+, OpenAI o-series/GPT-5, Grok 4 / 3 mini) get extra output room so structured answers are not cut off by their thinking tokens.
- Background jobs: each step run is counted before it starts, so a step killed by a server limit stops after two runs instead of repeating billed requests; the job lock now outlives the slowest request; cancel is respected in every path; status cannot flip back from failed/cancelled.
- Locks are now atomic (database INSERT IGNORE) so two workers cannot run the same paid step.
- Timed-out generation requests are no longer retried automatically (the provider may already have billed them); reads still are.
- WordPress drafts edited after the plugin saved them are no longer overwritten ("Save as a new draft" instead); "pending" drafts keep their status.
- Regenerating content or the brief saves unsaved edits first.

## 0.5.0 — 2026-10-13 — Phase 5: AI images

Added
- Image generation with OpenAI, xAI Grok and Google Gemini (adapters verified against the official SDKs), plus free demo images in demo mode. Choose a default image provider and an image model per provider under AI Providers.
- Wizard **Images** step: a featured image and up to five in-article images (suggested from the content metadata), each with an editable prompt, alt text, caption, shape and placement; generate selected images in the background after a cost check; preview, regenerate, remove.
- Images are checked before saving (real file type from the bytes — PNG, JPEG or WebP only — size and dimension limits) and imported with `media_handle_sideload()` into the Media Library with alt text and caption. Image URLs are never downloaded.
- Drafts: featured image set; in-article images placed after their section as core Image blocks (block editor), figures (classic) or Elementor Image widgets; images are attached to the draft.
- Usage log and estimates for images (one unit per image, priced from the "per image" column); hourly image limit applies.
- Safety: the plugin deletes only images it generated and only when no post uses them.

## 0.4.0 — 2026-10-13 — Phase 4: Elementor Free layouts

Added
- Output step: *Elementor draft* (when Elementor is active) with two styles — *Content only* (theme and kit styles) and *Designed* (header band, padded sections with alternating backgrounds, check-mark lists, FAQ band, closing call to action) — plus an optional button text and link.
- Layouts use only free Elementor widgets (heading, text editor, icon list, button) in containers, or sections/columns when the container feature is off. No HTML widget; FAQs as headings and text.
- Saving goes through Elementor's document API; the layout is validated before saving (types, nesting, unique ids, widgets registered on the site) and read back afterwards.
- One-H1 rule: the designed style moves the title into the layout only where Elementor can hide the theme title (classic themes); block themes keep their own title.
- Safety: drafts only; a linked Elementor draft is replaced only if unchanged since the plugin saved it (hash check), with the previous layout backed up; otherwise "Save as a new draft".
- Optimiser: revisions of Elementor pages are saved as new Elementor drafts while Elementor is active (the original is never changed).

Not yet implemented: in-place targeted editing of existing Elementor layouts; images (Phase 5).

## 0.3.1 — 2026-10-13 — Demo mode

Added
- Demo mode (free, no API key): an offline sample AI provider and offline sample research data, so the whole workflow (research, keywords, brief, content, SEO report, drafts, optimiser) can be tried without any account. One button on **AI Providers** turns it on for both. Demo mode makes no external requests, costs nothing, and is clearly labelled everywhere (banner, "Demo data" source badges, estimates marked "demo mode, free"). Demo text is generic filler: do not publish it.
- Research data source setting (DataForSEO or demo data) in SEO Research Settings.

Changed
- Pre-run estimates now average past costs per provider, so switching providers does not mix their costs.

## 0.3.0 — 2026-10-12 — xAI Grok provider; Phase 3: content generation, evaluator, optimiser, drafts

Added
- xAI Grok text provider (`https://api.x.ai/v1`, Responses API with strict JSON schema output; same adapter behaviour as OpenAI). Model listing falls back to manual model entry if xAI does not offer it.
- Structured content generation from the approved brief: introduction, one part per H2 section and a conclusion, then FAQs and metadata. Each part can be edited, approved, locked or regenerated on its own; approved and locked parts are never overwritten by a regeneration.
- Generated HTML is limited to an allow-list (no scripts, styles, iframes, images or attributes other than links); links are kept only when they point to URLs supplied in the brief.
- SEO report: measured checks (title, meta description, headings, keyword placement, readability, links, FAQ, length against the brief) kept separate from an optional AI review, with before/after scores. No keyword-density targets.
- Search-result preview, claims to verify, and version history (last 10 versions) with compare and restore.
- Save as WordPress draft (block editor markup with core blocks, or classic HTML). Never publishes; an existing post the plugin created is updated only while it is still a draft, otherwise a new draft is created. Meta, slug and schema recommendation stored as `_aiscs_*` post meta.
- Title tag, meta description and JSON-LD (Article/BlogPosting/WebPage; Product and FAQ markup are never emitted) output on the front end only for plugin-created posts and only when no SEO plugin is active; both can be switched off.
- Existing-content optimiser: pick a post or page (block, classic or Elementor content is read, never modified), get a baseline score and recommendations, accept/reject each, generate a revised version and save it as a new draft linked to the source.
- Admin: wizard steps 6–11 (output, content, SEO report, preview, save draft), Optimise Existing Content view, Projects and History.

Fixed
- Script, style, iframe and similar blocks are removed together with their text content before HTML sanitising (found during the smoke test).
- Draft-format radio options in the wizard now honour disabled states (native radios).

Not yet implemented: Elementor layouts (Phase 4), AI images (Phase 5), hardening and release (Phase 6).

## 0.2.0 — 2026-10-12 — Phase 2: SEO research, keyword planning, content brief

Added
- DataForSEO research provider (SERP, keyword metrics, related keywords, markets, account check) with provider-reported costs; optional free sandbox mode.
- Research cache, hourly research limits and usage logging (`cost_source` column; schema version 2 migrates automatically).
- Competitor outline reading that respects robots.txt, re-validates every redirect against SSRF rules and never stores page text.
- AI research analysis: intent, secondary keywords, semantic variations, entities, questions, gaps, differentiation, length guidance — always labelled as AI suggestions.
- Editable keyword plan with per-term source labels (DataForSEO / search results / competitor / AI / you), approvals, exclusions and added terms that survive re-runs.
- AI content brief with editable outline, metadata, FAQs, CTAs, internal links (only real published pages) and source-type suggestions flagged for verification.
- Projects (private post type) and a background job runner (Action Scheduler or WP-Cron, with a polling fallback, per-project lock, retry and cancel).
- Pre-run cost estimates with confirmation.
- Admin: New Content wizard (steps 1–5), Projects list, SEO Research Settings.
- Research also works without DataForSEO; unavailable data is shown as unavailable.

Fixed
- Usage records now store `cost_source` (found during the real-WordPress smoke test).

## 0.1.0 — 2026-10-12 — Phase 1: Foundation

Added
- Plugin bootstrap with PHP 8.2 / WordPress 6.6 / libsodium requirement gate (admin notice instead of a fatal error).
- Custom capabilities `aiscs_manage_settings` (administrators) and `aiscs_generate_content` (administrators, editors).
- Settings schema with server-side sanitisation, bounds and partial updates.
- Encrypted API key storage (libsodium secretbox; optional `AISCS_ENCRYPTION_KEY`), masked status only in the browser.
- HTTP client: timeouts, bounded retries with `Retry-After`, response size limit, no redirects, normalised errors, secret redaction.
- AI provider adapters: OpenAI (Responses API), Anthropic (Messages API), Google Gemini (generateContent), custom OpenAI-compatible endpoint (Chat Completions) — text, structured JSON output, model listing and connection tests.
- SSRF protection for user-supplied endpoints.
- Controlled text generation service: request/output caps, hourly limits, duplicate in-flight protection, usage logging.
- Usage log table, configurable pricing table and estimated costs (clearly labelled estimates).
- REST API (`aiscs/v1`): settings, providers, credentials, connection tests, usage, status.
- React admin: Dashboard, AI Providers, Usage and Costs, General Settings.
- Opt-in uninstall cleanup that never deletes posts, pages or media.
- 100 PHPUnit tests with fixtures (no network, no paid calls); PHPCS (WordPress standards) and ESLint clean.

Not yet implemented (planned): SEO research (Phase 2), content generation and evaluation (Phase 3), Elementor layouts (Phase 4), image generation (Phase 5).
