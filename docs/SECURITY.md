# Security

## Access control

| Capability | Granted on activation | Allows |
|---|---|---|
| `aiscs_manage_settings` | Administrator | Settings, API keys, connection tests, pricing, limits, usage report |
| `aiscs_generate_content` | Administrator, Editor | Dashboard, New Content, Projects (own projects only) |

Projects are private posts of type `aiscs_project`. Project routes check the capability **and**
ownership: users see only their own projects; users with `aiscs_manage_settings` see all. Background
jobs run as the user who started them and stop if that user loses access.

Every REST route (`/wp-json/aiscs/v1/*`) has a `permission_callback` that checks one of these
capabilities. Cookie-authenticated requests also need the `wp_rest` nonce (WordPress core enforces it;
`apiFetch` sends it). A nonce never replaces the capability check.

## API keys

* Stored in option `aiscs_credentials` (not autoloaded), each value encrypted with libsodium
  `crypto_secretbox` (XSalsa20-Poly1305, random nonce per value).
* Key: HMAC-SHA256 of `AISCS_ENCRYPTION_KEY` if defined, otherwise of the site's auth salts.
* Keys are write-only from the browser. REST responses contain `stored`, `readable` and a hint of the
  last 4 characters (only for keys of 12+ characters). Keys never appear in HTML, JS bundles,
  inline bootstrap data or logs.
* **Limit:** encryption at rest protects database dumps and backups. Anyone who can run PHP on the site
  (or read `wp-config.php` and the database) can recover keys. Use provider-side spending limits and
  restricted keys where offered.

## Outbound requests

* Fixed provider hosts use HTTPS, `reject_unsafe_urls`, no redirects, size limits and timeouts.
* The custom endpoint must use https. Plain http is accepted only when every address of its host is
  private or local (a model server on the site's own network, with "allow private endpoints" on), so
  the API key never crosses the internet unencrypted.
* User-supplied URLs (custom endpoint; competitor pages from Phase 2) go through `UrlValidator`:
  http/https only, no embedded credentials, ports 80/443/8080/8443, DNS-resolved addresses must be
  public (blocks RFC1918, loopback, link-local incl. cloud metadata `169.254.169.254`, CGNAT, ULA,
  IPv4-mapped IPv6, `localhost`, single-label and `.local`/`.internal` names).
* Competitor pages (from search results) are untrusted: every URL and every redirect hop is
  validated, redirects are followed manually (max 3), only `text/html` up to 2 MB is parsed with
  network access disabled in the parser (`LIBXML_NONET`), and only headings, word count and schema
  type names are kept. robots.txt is honoured for our token and `*`; a 5xx robots.txt means "do not
  fetch". Competitor text is never stored or sent to an AI provider.
* Known limitation: a DNS answer can change between validation and connection (rebinding). WordPress
  core's `reject_unsafe_urls` re-checks at request time as a second layer.

## Input and output

* All settings are sanitised server-side against a schema (types, bounds, enums, unknown keys dropped).
* AI structured output is decoded and validated against a JSON schema before use, then sanitised
  again field by field (keyword plan, brief). Internal links proposed by the AI are accepted only for
  posts the plugin itself found on the site.
* The admin UI renders data as React text nodes (escaped). Generated HTML is cleaned before it is
  stored: `script`/`style`/`iframe`/`noscript`/`object`/`embed`/`template` blocks are removed with
  their contents, then `wp_kses` keeps only an allow-list of text tags (attributes limited to link
  `href` and table-header `scope`);
  links are kept only if they point to a URL supplied in the brief. Previews pass through
  `wp_kses_post` again. Front-end JSON-LD is built from plugin data with `wp_json_encode` using
  `JSON_HEX_TAG`, so `<`/`>` cannot close the script element; it is printed only for published posts
  that carry the plugin's schema-type meta.
* SQL uses `$wpdb->prepare()`; the only interpolated value is the plugin's own table name.

## Logging

Errors are logged only when `WP_DEBUG_LOG` or the plugin's debug setting is on. Logs contain metadata
(provider, status, category), never request/response bodies. A redactor masks stored secrets and
common key formats (`sk-…`, `AIza…`, bearer tokens, `key=` query parameters).

## Destructive actions

* Deactivation removes only scheduled events.
* Uninstall deletes plugin options, keys, the usage table, projects, research caches, `_aiscs_*` post meta and capabilities
  **only** if "Delete plugin data" is enabled. Posts, pages, Elementor data and media are never deleted.
* The plugin never publishes. Saving creates a draft; it updates a post only if the plugin created it
  and it is still a draft (WordPress revisions apply), otherwise it creates a new draft.
* The optimiser reads the source post and never writes to it; the revised version is a new draft
  with `_aiscs_source_post` pointing to the original.
* Project versions (last 10) allow restoring earlier content inside the plugin.
* Images: provider output is accepted only as base64 (URLs are never fetched), its real type is
  read from the bytes (PNG/JPEG/WebP), cross-checked with `getimagesizefromstring`, limited to 20 MB
  and 64–8192 px, then imported with `media_handle_sideload()` (WordPress re-checks the file type).
  Generating requires `upload_files`. Generated attachments carry `_aiscs_generated_image`; the plugin
  deletes an attachment only if it carries that tag, has no parent post and is no post's featured image.
* Elementor: layouts are saved only to drafts through Elementor's `Document::save()` (its own
  permission check and kses for users without `unfiltered_html`). A linked Elementor draft is replaced
  only if unchanged since the plugin saved it; the previous layout is kept in `_aiscs_elementor_backup`.
  Button links are syntax-checked (`http(s)` with host, or a site path) and never fetched.

## Reporting

Report vulnerabilities privately to the plugin maintainer, not in public issues.

## Stock photos and placeholders (1.3.0)

* The browser sends only a library id and an image id; the server looks the image up again with the
  library's API and downloads the file URL from that answer. Every URL and redirect hop is checked by
  `UrlValidator` (public addresses only), the size is capped at 20 MB, and `ImageValidator` checks the
  real file type and dimensions before `media_handle_sideload`.
* Pexels/Pixabay keys are stored encrypted in the credential store and never returned to the browser;
  only users who manage settings can save or remove them. Searching and importing require access to the
  project, and importing requires `upload_files`.
* Imported images are tagged as plugin images, so "Remove image" deletes them only when the plugin
  created them and nothing uses them. The library, author, source URL and licence are stored in
  `_aiscs_image_source` post meta.
* Placeholders are drawn locally with GD; no external request.

## Trusted facts and AI-suggested competitors (1.5.0)

- Sources cited by the writer are checked on the server. A source counts only if it uses https, is on the site owner's trusted domain list (a domain covers its subdomains), and the page loads with HTTP 200 as HTML or PDF. The fetch goes through the SSRF-safe page fetcher and each result is cached for a day.
- Only verified source URLs, the brief's internal links and the business's own service and contact URLs survive link sanitising. Any other `<a>` keeps its text and loses the link.
- `sources` and `edit_report` are server-owned. The content save endpoint replaces them with the stored values before sanitising, so a client cannot whitelist a link by marking it verified.
- AI-suggested competitor URLs and user-listed URLs go through the same robots.txt check and SSRF-safe fetcher as SERP results. Plain words are never turned into addresses, and this site's own host is never suggested.
- The business profile is stored in the settings option. It holds no secrets; credentials stay in the encrypted credential store.

## Media limits

* Images are checked from their header before WordPress decodes them: PNG, JPEG or WebP only,
  20 MB at most, 64-8192 px per side and at most 25 million pixels in total (about 100 MB of memory
  while sizes are made). Change the pixel budget with the `aiscs_max_image_pixels` filter.

## Locks

* Billable operations use expiring locks in the options table. Each lock stores its expiry and a
  random token; a request releases only a lock that still holds its own token, so a request whose
  lock expired cannot remove the lock of the request that took over.

## AI Website Studio (1.7.0)

- **Permissions.** Every route needs `aiscs_generate_content`. Analysing a page needs `edit_post` on every requested page. An analysis can be read only by its owner (or a manager) who can still edit the page. Brand profiles can be read and changed only by their owner (or a manager). Missing objects return 404 before ownership is checked, so the 404/403 difference only tells a user whether an ID exists; it reveals no content.
- **No outbound requests.** Analysing the site's own pages reads only local data: post content, the Elementor document and kit, theme.json, and stylesheets on disk. A stylesheet is read only when its real path is inside the theme root or the uploads directory, ends in `.css` and is at most 512 KB. The CSS parser never follows `@import` or `url()`. The later Website URL Analyzer must use `HttpClient::fetchPage()` (SSRF validation, redirect re-checks, DNS pinning) and nothing else.
- **Untrusted values.** Page settings, CSS and REST input pass `DesignTokens` patterns before storage: hex colours, CSS lengths and boxes, a single shadow, plain font names. A value such as `Arial; } body {…}` or `javascript:` is rejected, so tokens can be written into Elementor settings or CSS later without injection.
- **Bounded work.** At most 10 pages per analysis. CSS input is capped at 1 MB and 5,000 rules, element trees at depth 40, distinct values per signal at 64. Five analyses are kept per page and 100 profiles per user, and analyses and audit entries are pruned with the usage-log retention setting.
- **Output.** The studio UI renders through React, which escapes text. The admin bootstrap data contains no secrets.
- **Audit log.** `wp_aiscs_audit_log` records analyses and profile create/update/delete with user, time and object. Content and credentials are never logged.
- **Credential key rotation.** Define `AISCS_ENCRYPTION_KEY_PREVIOUS` (the old key, or `wp-salts` for the salt-derived default) next to a new `AISCS_ENCRYPTION_KEY`. A key the current key cannot open is decrypted with the previous key and immediately re-encrypted with the new one. Remove the constant after every provider shows its key as readable.

## AI Website Studio — cloning and page building (1.8.0)

- **Original pages are never written.** `DraftManager` is the only studio code that saves Elementor data, and it always does so through Elementor's `Document::save()`. It creates new drafts. It updates an existing page only when an `aiscs_generated_pages` row proves the studio created it, the page is still draft, pending or private, and the user can `edit_post` it. Cloning reads the source tree into memory; the copy is saved under a new post ID with new element and repeater IDs. A fingerprint of the source's `_elementor_data` is compared before and after.
- **Validation before every save.** `DocumentValidator` checks:
  - element IDs (format, uniqueness) and parent/child rules (widgets only in containers or columns, columns only in sections, inner sections only in columns), depth ≤30, ≤3,000 elements, ≤4 MB;
  - widget availability on the site;
  - control value shapes (sliders, dimensions, and only the mapper's own `calc()` widths);
  - link and media URLs (http(s), mailto, tel, relative, fragment; never `javascript:`, `data:`, `vbscript:` or protocol-relative), and that media IDs are attachments.

  For generated pages, any string with script, iframe, svg or style tags, `on*=` handlers or script URLs is rejected. Cloned pages may keep the author's own HTML widgets and custom CSS (they are the site's existing content), but unsafe links are still rejected.
- **Generated HTML.** Text Editor content goes through an allow-list DOM sanitiser (`HtmlFragment`), then `wp_kses_post()`. Elementor also runs `wp_kses_post()` on save for users without `unfiltered_html`. Plain text fields are entity-escaped.
- **Free widgets only.** Generated pages use only Elementor Free widgets that are registered on the site, never Pro-only widgets. Missing widgets fall back to native equivalents (icon list → bulleted text, accordion → question headings).
- **AI content.** The clone prompt forbids invented prices, statistics, years, client counts, awards, qualifications, testimonials, client names, guarantees and contact details. Testimonial, counter, price and dynamic-tag fields are locked and never sent to the AI. Contact details, legal text, the business name and header/footer text are kept by default. Answers are matched to the requested slot keys only. Unknown keys are ignored, and an empty answer never blanks a field.
- **Billing safety.** The clone job stores the AI answer before building, so a retried step never repeats the request, and it never creates a second draft. The existing duplicate-request lock and usage limits apply.
- **IDOR.** Source pages need `edit_post`. Brand profiles and studio projects need ownership (or the manage capability). Studio jobs are reachable only through `/design/jobs/{id}` for projects marked `_aiscs_kind = website_studio`.

## AI Website Studio — HTML/CSS conversion (1.9.0-beta.1)

- **Nothing submitted is executed.** The HTML is parsed with DOMDocument using `LIBXML_NONET`, so no network access and no external entities. Before anything else, `<script>`, `<noscript>`, `<template>`, `<link>`, `<meta>` and `<base>` are removed, along with every `on*` attribute. The JavaScript field is only scanned with regular expressions for the report, and is never stored or output. Linked stylesheets are not fetched.
- **Admin preview.** The source preview is an `<iframe sandbox="">` with `srcdoc`: no scripts, no forms, no same-origin access to the dashboard, no top navigation. Script tags are also stripped before rendering.
- **Output passes the engine's sanitisers.** Text is entity-escaped or passes `HtmlFragment` (allow-list; links only http(s), mailto, tel, relative or fragment). Image URLs must be absolute http(s); `data:` and relative URLs without a base are rejected and reported. Videos are limited to YouTube/Vimeo watch URLs. Every style value passes the DesignTokens patterns.
- **HTML widget fallback** is opt-in and limited to content without a native widget. It keeps only allow-listed tags, and only inline style properties from an allow-list whose values contain no `url()`, `expression`, `javascript` or escapes. Scripts, iframes, SVG, forms, objects and event handlers are always removed. The validator accepts the HTML widget only in `converted` mode, and still rejects executable content there. Elementor runs `wp_kses_post()` on save for users without `unfiltered_html`.
- **Bounded work.** HTML and CSS are capped at 1 MB each, the document at 8,000 elements, and the result at 40 sections and 60 elements per section. CSS limits are the parser's: 5,000 rules, nesting depth. Selector matching is indexed by the rightmost compound. A 2,000-element page with 1,500 rules converts in about 0.1 s.
- **Permissions.** Both routes need `aiscs_generate_content`, and saving a page needs `edit_pages`. A brand profile used for styling must be the user's own (or the user has the manage capability).

## AI Website Studio — screenshots and vision requests (1.9.0-beta.2)

- **Upload checks.** The file must pass `is_uploaded_file()` and be ≤10 MB. The type comes from the file signature (PNG/JPEG/WebP), cross-checked with the decoded header, and must be 64–8192 px per side and within the plugin's pixel limit (`aiscs_max_image_pixels`). The client's file name and MIME type are ignored.
- **Storage.** The screenshot is kept only until it is analysed, in `uploads/aiscs-studio-tmp/{32 hex}.bin`.
  - The `.bin` extension means it is never served as an image. An `index.php` and a deny-all `.htaccess` are added; on nginx, the random name and the extension are the protection.
  - Tokens are validated before any path is built, so they cannot traverse.
  - The file is deleted after a successful analysis or a final failure, and pruned daily after 24 hours. Only the analysis (text) is kept.
- **Privacy.** Tiles are re-encoded as JPEG, so EXIF/GPS metadata is not sent. The screenshot goes only to the user's chosen text provider, with the user's own key, and the UI says so. Screenshots may contain personal data.
- **Untrusted model output.** The vision answer is strict JSON, and every value passes VisionDesign and then the PageDocument, DesignTokens and HtmlFragment sanitisers:
  - colours are hex only;
  - text is plain or allow-listed HTML;
  - buttons link to `#`;
  - icons come from an allow-list;
  - at most 40 sections, and 60 elements per section.

  Missing content becomes visible placeholders, never invented facts. Pages pass the DocumentValidator in `generated` mode, so no HTML widget is possible.
- **Costs.** One vision request per screenshot, plus one optional text request for the content. Both go through TextGenerationService (limits, usage log, duplicate-request lock). Retried steps never repeat a request.

## AI Website Studio — page generator, links and images (1.9.0-beta.3)

- **Untrusted model output.** The answer is strict JSON. Every value passes VisionDesign/PageGenerator and then the PageDocument and HtmlFragment sanitisers before the review:
  - text is plain (paragraphs are escaped and wrapped in `<p>`);
  - icons come from the allow-list;
  - colours cannot be given at all (background roles only);
  - at most 20 sections.

  The document is validated before the user sees it, and again (DocumentValidator, `generated` mode) before the draft is saved.
- **Links.**
  - Button URLs come only from the user's `cta_url` (http(s), a site path or `#anchor`; `javascript:`, protocol-relative and other schemes are refused). Otherwise buttons link to `#`.
  - Internal link targets come only from the server's candidate list (published, public, password-free pages and posts). The model sees IDs and titles, and URLs are filled in by the server.
  - The anchor is HTML-escaped and wrapped outside existing links. The target's permalink is read again when the draft is built.
- **No invented facts.** The prompt forbids prices, statistics, awards, testimonials, contact details and the like unless the user's notes or business settings provide them. Missing facts become visible `[placeholders]`. The review lists every sentence with a number, every placeholder and the model's assumptions.
- **Images.**
  - Image requests need `upload_files` and a configured image provider, and go through ImageGenerationService (limits, usage log, duplicate lock) and ImageValidator.
  - Generated images are suggestions until the user approves them.
  - Placeholders and approved images are attached to the draft, so `MediaImporter::deleteUnused()` never removes an image a page shows.
  - Only plugin-made images are ever deleted (rejected suggestions, replaced placeholders), and only when the user may delete them.
- **Ownership.** Every generator route requires `aiscs_generate_content` and ownership of the studio project. Applying images also passes `DraftManager::assertWritable()` (a studio-made draft the user can edit). Nothing is published.

## AI Website Studio — page tools, revisions and library (1.9.0-beta.4)

- **No writes to original pages.** Every write (fixes, regeneration, restore, library insert) goes through `DraftManager::update()`. That requires a studio-made page that is still a draft, pending or private and that the user can edit, validates the whole tree, and stores a revision. Reading (checks, preview links, history, saving to the library) needs `edit_post` on the page.
- **Fixes cannot carry arbitrary settings.** The client sends only finding ids. The server recomputes the findings from the current page and applies the operations it generated itself. User text (alt text, button labels) is sanitised and HTML-escaped. Alt text is written only to attachments the user may edit.
- **Proposals are isolated.**
  - A regeneration result lives in a studio project owned by the user, and is validated in `generated` mode, so only free native widgets are allowed and there is no HTML widget.
  - It is previewed on a separate temporary draft that is never published, is hidden from page lists, carries an expiry, and is deleted on approve, on discard, or after 24 hours.
  - Approving re-checks that the section is still where the proposal expects it.
- **Previews** use WordPress's nonce-protected draft preview links: only logged-in users who may edit the page can open them, and nothing is exposed publicly.
- **AI section requests** send only that section's text, the page's section types, the brief and the business facts, under the same rules as the generator. Facts are never invented, and images are never invented (only existing images are reused). The answer is strict JSON and passes the PageDocument sanitisers.
- **Revisions** are stored in the plugin's own table: compressed, limited per page and in size, deleted with the page, and pruned for deleted pages. Restores are themselves revisions.
- **Design library** items are owned by their creator (managers see all). Saving validates the element in `cloned` mode and limits it to 1 MB. Inserting copies it with new ids, never shares ids with the target page, and is validated again.

## AI Website Studio — phase 8 review (1.9.0-rc.1)

- **Copied content and `unfiltered_html`.** `cloned` validation keeps an author's own page scripts only for users WordPress already trusts with unfiltered HTML. For everyone else, script and event-handler markup is refused, so the library, restores and fixes cannot be used for privilege escalation. Script URLs are refused in every mode.
- A regression suite (`tests/unit/SecurityTest.php`) covers XSS payloads through every sanitiser, model-output injection, SQL inputs, tampered revision snapshots, fix ids that the server did not issue, and preview-page abuse. See `docs/website-studio/QA-REPORT.md` for the full review.
