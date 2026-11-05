# AI Website Studio REST API

Base: `/wp-json/aiscs/v1`. Cookie-authenticated requests need the `X-WP-Nonce` header (`wp_rest` nonce); the admin UI sends it via `wp.apiFetch`. Every route requires the `aiscs_generate_content` capability. Errors are `WP_Error` JSON: `{ "code", "message", "data": { "status", ... } }`.

| Status | Meaning |
|---|---|
| 401 | Not logged in |
| 403 | Missing capability, not the owner of the object, or cannot edit the page |
| 404 | Page, analysis or profile does not exist (or is trashed, or the post is not a page or post) |
| 400 | Invalid input (`aiscs_invalid_tokens` includes `data.errors`: `["colors.primary: invalid value", ...]`) |
| 409 | Brand profile limit reached (100 per user) |

## GET /pages/available

Pages (or posts) the current user can edit.

Query: `page` (≥1), `per_page` (1–50, default 20), `search` (≤100 chars), `post_type` (`page`|`post`), `builder` (`""`|`elementor`).

```json
{ "items": [ { "id": 42, "title": "Home", "url": "https://…", "status": "publish", "post_type": "page",
  "builder": "elementor", "featured_image": "", "modified": "2026-10-30T09:00:00", "edit_url": "…" } ],
  "total": 12, "pages": 1 }
```

`builder` is `elementor`, `blocks` or `classic`.

## POST /website/analyze

Body: `{ "page_ids": [42, 57], "force": false }` (1–10 IDs). The user must be able to edit **every** page.

Analyzes each page locally (no AI and no HTTP requests). If a page, the kit, the theme or the global styles are unchanged since the last analysis, the stored analysis is returned (`cached: true`); `force` recomputes it.

```json
{ "analyses": [ { "id": 7, "source_page_id": 42, "created_at": "…", "cached": false,
    "analysis": { "page": {…}, "structure": {…}, "sections": [ { "index": 0, "type": "hero", "heading": "…", "columns": 2, "images": 1, "buttons": 1, … } ],
                  "tokens": { …DesignTokens… }, "css": { "elementor:page": { "rules": 120, "truncated": false } }, "notes": [] } } ],
  "tokens": { …combined DesignTokens for all pages… } }
```

`notes` values: `atomic_elements`, `unavailable_widgets`, `no_kit`, `classic_content`, `elementor_inactive`.

## GET /website/analysis/{id}

Same shape as one entry of `analyses`. The user must be the analysis owner (or have the manage capability) **and** still be able to edit the page.

## GET /website/brand-profiles?page=1

The user's profiles (users with the manage capability see all): `{ items: [Profile], total, pages }`.

## POST /website/brand-profiles

Either `{ "name": "Main brand", "analysis_ids": [7, 8] }` (built from the user's own analyses; analysis IDs the user does not own → 403), or `{ "name": "…", "tokens": { … } }` (validated; any invalid value → 400). Returns 201 with the Profile.

## GET | PUT | DELETE /website/brand-profiles/{id}

Owner or manage capability. `PUT` body: `{ "name"?: string, "tokens"?: DesignTokens }`. Token groups that changed get the source `user`.

```json
Profile: { "id": 3, "owner_id": 2, "project_id": 0, "name": "Main brand", "tokens": { … },
           "source_pages": [42, 57], "created_at": "…", "updated_at": "…" }
```

## DesignTokens (schema version 1)

```json
{
  "schema_version": 1,
  "colors": { "primary": "#1F365C", "secondary": "", "accent": "#8A8A2B", "text": "#3F3F3F",
              "heading": "#1F365C", "background": "#FFFFFF", "surface": "#F4EFE6", "border": "" },
  "palette": [ { "hex": "#1F365C", "count": 14, "roles": ["primary", "heading"] } ],
  "typography": {
    "heading": { "family": "Poppins", "weight": "600" },
    "body":    { "family": "Inter", "weight": "400" },
    "button":  { "family": "Inter", "weight": "500" },
    "scale": { "h1": { "desktop": "52px", "tablet": "", "mobile": "32px" }, "h2": {…}, …, "body": {…} },
    "line_height": { "heading": "1.2", "body": "1.6" }
  },
  "components": {
    "button": { "radius": "8px", "padding": "14px 28px", "background": "#8A8A2B", "color": "#FFFFFF" },
    "card":   { "radius": "12px", "shadow": "0 4px 16px 0 #0000001F", "background": "#FFFFFF", "padding": "32px 24px" },
    "image":  { "radius": "" }
  },
  "spacing": { "section_padding": { "desktop": "100px 0", "tablet": "", "mobile": "50px 0" }, "element_gap": "20px" },
  "layout":  { "container_width": "1200px", "breakpoints": { "tablet": 1024, "mobile": 767 } },
  "sources": { "colors": "elementor_kit", "typography": "elementor_kit", "components": "elementor_widgets", "layout": "elementor_kit" }
}
```

Field rules: colours are `#RRGGBB` or `#RRGGBBAA`. Lengths are a number with `px|em|rem|%|vw|vh` (or `0`). Boxes are 1–4 lengths. A shadow is one shadow ending in a colour. Font names use letters, digits, spaces, `-` and `_` (up to 4 families). Weights are `100`–`900`, `normal` or `bold`. Breakpoints are 240–2560 px, or 0 for unknown. An empty string means "not found". Values are never invented.

---

# Phase 2–3: cloning and page building (1.8.0)

Extra status codes: **409** a page that the studio may not change (not created by it, or already published), or a job already running; **422** an invalid design document (`data.errors` lists the problems); **424** Elementor inactive, too old, or missing its basic widgets.

## GET /design/capabilities

`{ "active": true, "version": "3.25.0", "pro": false, "containers": true, "blocker": "", "icons": ["check", ...] }`. When `blocker` is not empty (`elementor_inactive`, `elementor_too_old`, `widgets_missing`), no page can be created.

## GET /pages/{id}/clone/plan

Needs edit permission on the page, and the page must be built with Elementor. Makes no AI request and changes nothing.

```json
{ "source": { "id": 42, "title": "Bookkeeping" },
  "sections": [ { "index": 0, "type": "hero", "heading": "…" } ],
  "slots": [ { "key": "1a2b3c4|title", "element_id": "1a2b3c4", "widget": "heading", "role": "heading", "level": "h1",
               "section_index": 1, "section_type": "hero", "text": "…", "words": 4, "html": false,
               "replace": true, "reason": "", "locked": false } ],
  "stats": { "elements": 40, "widgets": 22, "slots": 18, "replace": 12 },
  "warnings": [] }
```

`reason` is set when a slot is kept by default: `site_chrome`, `brand`, `legal`, `contact`, `testimonial`, `statistic` or `dynamic`. `locked` slots (testimonials, statistics, dynamic tags) can never be rewritten.

## POST /pages/{id}/clone

Body `{ "title"?: string }`. Makes an exact copy as a new draft (no AI) and returns 201:

`{ "page_id", "edit_url", "elementor_url", "preview_url", "warnings": [], "stats": {...}, "applied": [], "source_unchanged": true }`.

## POST /pages/{id}/clone-and-generate

Body: `topic`* (≤300), `primary_keyword`* (≤120), `secondary_keywords` (≤15), `audience`, `intent` (`informational|commercial|transactional|local`), `location`, `tone`, `notes` (≤3000; facts the AI may use), `title`, `profile_id` (must be the user's own), and `selected` (slot keys to rewrite; when omitted, the default choices from the plan are used).

Starts a background job and returns 202 `{ "project_id": 123, "job": {…} }`.

## GET /design/jobs/{project_id}

The project must be one of the user's studio projects. If WP-Cron is late, this call moves the job forward.

`{ "job": { "status": "queued|running|completed|failed|cancelled", "steps": [...], "step_index", "message" }, "result": { …clone result…, "ai_warnings": [ { "key", "issue": "longer_than_design|not_written", "role", "text" } ], "plan_warnings": [] } | null, "written": { "page_title", "meta_title", "meta_description", "provider", "model" } | null }`

## POST /design/jobs/{project_id}/cancel

Cancels a queued or running job.

## POST /design/pages/build

Body `{ "document": PageDocument, "profile_id"?: int }`. The document is validated, mapped to native widgets with the profile's tokens, validated again, and saved as a new draft (201). Errors return 422 with `data.errors`.

## GET /design/pages?page=1

Pages the studio created that the user can still edit: `{ items: [ { page_id, title, status, generation_type: "generated|clone|clone_generated", source_page_id, created_at, elementor_url, preview_url, notes } ], total, pages }`.

## PageDocument (schema version 1)

```json
{
  "schema_version": 1,
  "page": { "title": "Accounting Services", "slug": "accounting-services" },
  "sections": [
    { "id": "hero-001", "type": "hero",
      "layout": { "kind": "stack", "align": "center", "content_width": "boxed", "min_height": "520px" },
      "style": { "background": "token:surface", "text_color": "", "padding": { "desktop": "100px 0", "tablet": "", "mobile": "" } },
      "elements": [
        { "type": "heading", "text": "Professional Accounting Services", "level": "h1" },
        { "type": "text", "html": "<p>Expert financial solutions for your business.</p>" },
        { "type": "button", "text": "Get started", "url": "/contact/", "variant": "primary" }
      ] },
    { "id": "services", "type": "cards",
      "layout": { "kind": "grid", "columns": { "desktop": 3, "tablet": 2, "mobile": 1 }, "gap": "24px" },
      "elements": [
        { "type": "heading", "text": "What we do", "level": "h2" },
        { "type": "group", "elements": [ { "type": "icon", "icon": "calculator" }, { "type": "heading", "text": "Bookkeeping", "level": "h3" }, { "type": "text", "html": "<p>…</p>" } ] },
        { "type": "group", "elements": [ … ] },
        { "type": "group", "elements": [ … ] }
      ] },
    { "id": "faq", "type": "faq", "elements": [ { "type": "accordion", "items": [ { "title": "How much does it cost?", "html": "<p>…</p>" } ] } ] }
  ]
}
```

| Field | Rules |
|---|---|
| `sections` | 1–40 |
| `id` | `^[a-z0-9][a-z0-9-]{0,40}$`, unique (the stable section identifier, stored in the page record's section map) |
| `type` | `hero` `features` `cards` `about` `cta` `faq` `testimonials` `contact` `image_grid` `statistics` `content` |
| `layout.kind` | `stack`; `grid` (non-group elements first, then ≥1 group); `split` (exactly two groups) |
| `layout.columns` | 1–6 per device |
| `elements` | 1–60 per section; `group` holds 1–20 elements and cannot contain another group |
| Element types | `heading` (`text` ≤300, `level` h1–h6), `text` (`html`, sanitised to p/br/strong/b/em/i/u/ul/ol/li/blockquote/code/h3/h4/a), `button` (`text` ≤80, `url`, `variant` primary\|secondary), `image` (`attachment_id` and/or http(s) `url`, `alt`, `caption`), `icon` (one of `/design/capabilities` → `icons`), `list` (`items` ≤30), `accordion` (`items` ≤30 `{title, html}`), `spacer` (`height`), `divider`, `group` (`style.background/radius/shadow/padding`) |
| Colours | `#RRGGBB`, or `token:<role>` (primary, secondary, accent, text, heading, background, surface, border) resolved from the brand profile |
| Links | http(s), mailto, tel, `/relative`, `#fragment` only |


---

# Phase 4: HTML/CSS to Elementor (1.9.0-beta.1)

## POST /design/html/parse

Body:

| Field | Rules |
|---|---|
| `html`* | ≤1 MB, fragment or full document |
| `css` | ≤1 MB |
| `js` | ≤512 KB; analysed only, never run |
| `base_url` | http(s); used to resolve relative links and images |
| `fallback` | `skip` (default) or `html` |
| `include_chrome` | boolean; convert header/nav/footer as content |
| `title` | page title |

Analyses only; nothing is saved. Returns `{ document: PageDocument, errors: [], report, tokens }`.

`report`:

```json
{ "coverage": 81, "converted_elements": 21,
  "stats": { "sections": 6, "elements": { "heading": 8, "group": 5, "button": 3, ... } },
  "unsupported": [ { "tag": "form", "category": "form|svg|canvas|media|embed|iframe|interactive|image_url|icon|no_native_widget", "text": "…", "fallback": false } ],
  "skipped": [ { "tag": "header", "reason": "site_chrome", "text": "…" } ],
  "fallbacks": 1, "scripts_removed": 1,
  "javascript": { "bytes": 77, "executed": false, "features": ["event_listeners", "sliders"], "inline_handlers_removed": 1 },
  "external_stylesheets": ["https://…/theme.css"], "relative_urls": ["images/x.png"],
  "approximations": ["gradient_as_colour", "complex_shadow", "contrast_fixed"],
  "css": { "unsupported_selectors": 2, "unsupported_features": ["position"] }, "truncated": false }
```

`tokens` holds the brand tokens extracted from the HTML's own CSS.

## POST /design/html/convert

Same body as `parse`, plus `profile_id` (optional, must be the user's own). Converts the HTML and saves it as a new draft (validator mode `converted`), styled with the brand profile or, without one, with the HTML's own CSS.

Returns 201 with the draft result and the report. Returns 422 when the HTML cannot be converted.

## PageDocument additions (still schema version 1, all optional)

- Element `style` (heading, text, button, image, icon, list): `{ color, background, font_family, font_weight, font_size: {desktop, tablet, mobile}, line_height, padding, radius, border_color, border_width, align: {tablet, mobile} }`.
- Section `style.background_image`: an http(s) image URL.
- `{ "type": "video", "url": "https://www.youtube.com/watch?v=…" | "https://vimeo.com/…" }`.
- `{ "type": "html", "html": "…" }`: accepted only by the converter's fallback (validator mode `converted`), and sanitised to allow-listed tags and safe inline styles.


---

# Phase 5: Image to Design (1.9.0-beta.2)

## POST /design/image/analyze

Multipart form:
- `screenshot`: PNG/JPEG/WebP, ≤10 MB, 64–8192 px per side, within the pixel limit.
- `model` (optional): vision model ID, `^[A-Za-z0-9._:/-]{0,100}$`.

Needs `aiscs_generate_content` and active Elementor. Stores the file temporarily, creates a studio project and starts the analysis job. Returns 202 `{ project_id, job }`.

Errors: 400 no or invalid file, 413 too large, 424 Elementor missing, 409 a job is already running.

## GET /design/jobs/{project_id}

As for cloning, plus:

```json
{ "source": "screenshot",
  "design": { "document": "PageDocument (image slots as {type: image, aspect, alt})", "tokens": "DesignTokens",
              "report": { "approximations": ["visual_approximation", "fonts_guessed|fonts_unknown", "photo_backgrounds"],
                          "observations": [], "icons_replaced": 0, "dropped": 0, "photo_backgrounds": 0, "tiles": 2, "truncated": false },
              "provider": "openai", "model": "..." } }
```

## POST /design/image/{project_id}/generate

Owner only. Body:
- `keep_text` (boolean). When false, `topic` and `primary_keyword` are required.
- Brief: `topic`, `primary_keyword`, `secondary_keywords`, `audience`, `intent`, `location`, `tone`, `notes`.
- `title`.
- `profile_id` (must be the user's own).
- `placeholders` (boolean, default true; needs `upload_files` and GD).

Starts the build job (write → images → build) and returns 202. The draft's `generation_type` is `screenshot`. Returns 409 when the project has not been analysed or a draft was already made from it.


---

# Phase 6: AI Page Generator (1.9.0-beta.3)

## POST /design/generate

Needs `aiscs_generate_content` and active Elementor.

Body:
- **Brief.**
  - Required: `topic` (≤300) and `primary_keyword` (≤120).
  - Optional: `secondary_keywords` (≤15), `audience`, `intent` (`informational|commercial|transactional|local`), `location`, `tone`, `notes` (≤3000).
- **Options.**
  - `length`: `short|medium|long`.
  - `sections`: section types, ≤12.
  - `images`: 0–8, default 3.
  - `cta_url`: http(s), `/path` or `#anchor`; otherwise 400.
  - `links`: boolean, default true.
  - `title`.
  - `profile_id`: must be the user's own.

Creates a studio project, reads the link candidates as the current user, and starts `studio_generate`. Returns 202 `{ project_id, job }`. Nothing is written to pages.

## GET /design/jobs/{project_id}

When `source` is `generator`, the response also has:

```json
{ "design": { "document": "PageDocument (image slots as {type: image, aspect, alt, prompt})", "report": {}, "provider": "", "model": "" },
  "generator": {
    "seo":   { "meta_title": "", "meta_description": "", "keyword": "" },
    "links": [ { "id": "link-1", "target_id": 12, "title": "", "url": "", "anchor": "", "section": 1, "reason": "" } ],
    "facts": { "numbers": [ { "section": 1, "text": "" } ], "placeholders": [ { "section": 2, "text": "[Add your phone number]" } ] },
    "images": [ { "id": "img-1", "section": 0, "section_type": "hero", "aspect": "3:2", "alt": "", "prompt": "" } ],
    "report": { "assumptions": [], "layout_changes": [ { "section": 1, "change": "columns_4" } ], "images_removed": 0, "icons_replaced": 0, "dropped": 0 } },
  "images": { "slots": [ { "id": "img-1", "...": "as above", "placeholder_id": 501, "current_id": 501,
                           "status": "placeholder|candidate|applied", "candidate": null, "error": "" } ],
              "can_generate": true } }
```

## POST /design/generate/{project_id}/build

Owner only. Body: `approved_links` (IDs from `generator.links`; unknown IDs are ignored) and `title`.

Starts `studio_generate_build` (images → build) and returns 202. The draft's `generation_type` is `generated`. The result has `report.links_applied`, `report.links_skipped` and `seo`.

Returns 409 before the design exists or after a draft was made.

## POST /design/generate/{project_id}/images

Owner only, plus `upload_files` and a default image provider (424 otherwise).

Body:
- `slots`: `img-N`, 1–8.
- `prompts`: `{ "img-1": "..." }`, ≤1500 characters each.

Starts `studio_page_images`: one image request per slot. Results are kept as `candidate` and are not put into the page.

## POST /design/generate/{project_id}/images/apply

Owner only. Body: `slots`.

Replaces each slot's current image with its candidate in the draft's current Elementor data. The update is validated in `cloned` mode, because the user may have added any widget since. Returns `{ applied, missing, images }`; `missing` means the image was removed or replaced in Elementor.

## POST /design/generate/{project_id}/images/{slot}/discard

Deletes the slot's unapproved candidate.


---

# Phase 7: page tools, revisions and design library (1.9.0-beta.4)

Every `/design/pages/{id}/…` route requires `aiscs_generate_content` and `edit_post` on the page (a page or post, not trashed). Reading works on any such page. Writing goes through `DraftManager`, which changes only studio-made drafts (409 `aiscs_not_studio_page` or `aiscs_published` otherwise).

## GET /design/pages/{id}/overview

```json
{ "page": { "id": 12, "title": "", "status": "draft", "studio": true, "generation_type": "generated", "writable": true,
            "not_writable": "", "elementor": true, "elementor_url": "", "preview_url": "" },
  "regenerable": true,
  "sections": [ { "section_id": "services", "index": 1, "type": "cards", "element_id": "a1b2c3d", "count": 1, "present": true, "label": "What we do" } ],
  "outline":  [ { "id": "a1b2c3d", "type": "container", "label": "What we do", "widgets": 9 } ],
  "image_ready": true }
```

## GET|POST /design/pages/{id}/preview

Returns `{ preview_url, elementor_url, devices: { desktop: 1280, tablet: 768, mobile: 390 } }`. This has no side effects. The preview link is WordPress's own, which needs a logged-in user who may edit the page.

## POST /design/pages/{id}/consistency-check

Body: `profile_id` (the user's own) or `reference_page_ids` (1–5 pages the user may edit; analysed and merged), else the page's build tokens or the Elementor kit.

```json
{ "reference": "profile|reference_pages|page|site", "score": 85,
  "categories": { "colors": { "status": "pass|warning|fail|not_checked", "issues": 0 }, "...": {} },
  "findings": [ { "id": "c1a2b3c4d5e6f", "category": "buttons", "severity": "warning", "message": "", "elements": ["a1b2c3d"],
                  "fix": { "label": "Set the corner radius to 8px", "needs_value": false, "suggestion": "" } } ] }
```

Categories: `colors`, `typography`, `buttons`, `cards`, `spacing`, `widths`, `images`, `headings`, `patterns`, `responsive`.

## POST /design/pages/{id}/consistency/apply

Body: the same reference fields, plus `fix_ids` (≤100).

The findings are recomputed from the page as it is now, and only matching ids are applied. Returns `{ applied, skipped, revision }`; `skipped` holds ids that are no longer found.

## POST /design/pages/{id}/accessibility-check

Returns `{ target: "WCAG 2.2 AA", notice, categories: { headings|images|links|contrast|readability|forms|keyboard|media: { errors, warnings, recommendations } }, findings }`. The findings have the same shape as above, with ids that start with `a`.

## POST /design/pages/{id}/accessibility/apply

Body:
- `fix_ids`.
- `values`: `{ "a…": "text" }`, ≤250 characters each, for fixes with `needs_value`.

Alt text is written to the attachment when the user may edit it.

## POST /design/pages/{id}/sections/{section_id}/regenerate

Body: `action` (required) and its options.

| `action` | Kind | Options |
|---|---|---|
| `rewrite`, `seo` | AI | `instruction` ≤1000, `primary_keyword` |
| `redesign` | AI | `instruction` |
| `image` | AI | `prompt` ≤1500, `image_index` (−1 = up to 3 images); needs `upload_files` and an image provider |
| `colors` | Style | `background`: `""`, `surface`, `primary`, `secondary` or `dark` |
| `typography` | Style | `scale`: `smaller`, `larger` or `default`; `font` |
| `layout` | Style | `kind`: `stack`, `split` or `grid`; `columns` 0–6 |
| `spacing` | Style | `spacing`: `compact`, `normal` or `spacious` |
| `responsive` | Style | — |

- Style actions return 201 `{ project_id, proposal }`.
- AI actions return 202 `{ project_id, job }`.

Errors:
- 409 `aiscs_not_regenerable`: a cloned page without a design document.
- 409 `aiscs_section_missing`: the section was removed in Elementor.
- 400 `aiscs_invalid_option`: for example "two halves" for a section that is not two parts.

## Proposals

`GET /design/proposals/{id}` returns `{ project_id, page_id, section_id, action, status, job, proposal }`.

The `proposal` object is `{ page_id, section_id, element_id, summary, action, provider, model, report, images, texts, status }`.

The project owner can then call:
- `POST …/preview`: returns `{ preview_url, element_id }`. The temporary page shows the whole page with the proposal in place.
- `POST …/approve`: returns the DraftManager result with `revision`. 409 `aiscs_section_changed` means the section was changed in Elementor since the proposal.
- `POST …/discard`.

## Revisions

- `GET /design/pages/{id}/revisions`: returns `{ items: [ { revision, action, summary, section_id, meta, bytes, restorable, created_by, created_at } ], edited_since }`.
- `GET /design/pages/{id}/revisions/compare?from=N&to=M`: `to=0` means the page now. Returns `{ from, to, diff: { added, removed, changed: [ { id, label, text_added, text_removed, style_only } ], moved, unchanged }, outline_from, outline_to }`.
- `POST /design/pages/{id}/revisions/{n}/restore`
- `POST /design/pages/{id}/revisions/{n}/sections/{element_id}/restore`: puts one top-level element back, or re-inserts it at its old position.

## Design library

Every route requires `aiscs_generate_content`. Item routes require the owner (or `aiscs_manage_settings`).

- `GET /design/library?kind=section|component&category=`
- `POST /design/library`: body `{ page_id, element_id, name, category, profile_id }`, and the user must be able to edit the page. Returns 201 with the item.
- `GET`, `PUT` (`name`, `category`) and `DELETE /design/library/{id}`. Items carry `summary` and `outline`, never the raw tree.
- `POST /design/library/{id}/duplicate`
- `POST /design/library/{id}/preview`: returns `{ preview_id, preview_url }`.
- `POST /design/library/{id}/insert`: body `{ page_id, position }`, where −1 means the end. 409 `aiscs_needs_containers` when the item needs Flexbox Containers and the site does not have them.

## Website project ZIP import (1.9.0-rc.2)

Upload, analysis and build need `aiscs_generate_content` and `upload_files`; an upload token (32 hex characters) belongs to the user who uploaded it. Errors use `aiscs_{code}` (for example `aiscs_zip_invalid`, `aiscs_zip_bomb`, `aiscs_zip_too_large`, `aiscs_upload_expired`, `aiscs_busy`) with `data.errors` listing files and reasons. Details: `PROJECT-IMPORT.md`.

| Route | Purpose |
|---|---|
| `POST /design/import/upload` | Multipart field `archive` (the ZIP). Returns the analysis (201) with `token`. |
| `POST /design/import/{token}/analyze` | `entry`: analyse another HTML page of the ZIP. Cached per entry. |
| `POST /design/import/{token}/preview` | `target`: `original` or `converted`; `settings`. Returns a self-contained HTML document for a sandboxed iframe. |
| `POST /design/import/{token}/build` | `settings`. One step: imports a batch of assets, or writes the draft. Repeat until `done` is true. Returns the draft URLs, `report`, `partial`, `notes` and the stored scripts (without code). |
| `DELETE /design/import/{token}` | Discard the upload. |
| `GET /design/import/targets` | Imported drafts the user may update. |
| `GET /design/import/pages/{id}/scripts` | Scripts of an imported page with their code, for review (`edit_post`). |
| `POST /design/import/pages/{id}/scripts` | `approve`: SHA-256 hashes to approve; all others are switched off. Needs `unfiltered_html`. |

`settings`: `title`, `entry`, `mode` (`hybrid` \| `fidelity` \| `native`), `template` (`canvas` \| `full_width` \| `default`), `header_footer` (`include` \| `skip`), `target` (0 = new draft), `reuse_assets`, `root_font`, `scripts` (`request` \| `off`).
