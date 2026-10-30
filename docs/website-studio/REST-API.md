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
