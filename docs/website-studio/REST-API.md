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
