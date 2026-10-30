# Elementor Free compatibility

## Status in 0.4.0

The plugin can save generated content as an **Elementor draft** built only from widgets that ship
with free Elementor. Elementor Pro is never required. Without Elementor, everything else works and
drafts are saved for the WordPress editor.

Verified against Elementor source **4.0.8** (tag `v4.0.8`; plugin header 4.0.0, requires WP 6.6 /
PHP 7.4) and smoke-tested with that code running in WordPress (see "Testing" below).

## How it works

1. In the wizard's **Output** step choose *Elementor draft* (enabled only when Elementor is active),
   a layout style, and optionally a button text and link.
2. **Save draft** creates a WordPress draft (never published), then saves the layout through
   Elementor's own API: `\Elementor\Plugin::$instance->documents->get( $id )` →
   `set_is_built_with_elementor( true )` → `Document::save( [ 'elements' => …, 'settings' => … ] )`.
   Elementor therefore runs its own permission check, element normalisation, kses (for users
   without `unfiltered_html`), CSS regeneration and plain-text copy. `_elementor_data` is never
   written directly.
3. The result links to **Edit with Elementor** (`post.php?post=ID&action=elementor`).

### Widgets and structure

| Content | Widget / element |
|---|---|
| Wrapper per part | `container` (flexbox, boxed, column) when the container feature is active; otherwise `section` → one `column` (`_column_size: 100`) |
| H2 / H3 / H4 headings | `heading` (`title`, `header_size`) |
| Paragraphs, tables, lists with links or formatting | `text-editor` (`editor`) |
| Plain bullet lists (designed style only) | `icon-list` with `fas fa-check` (Font Awesome solid ships with Elementor) |
| Call to action (when text and link are set) | `button` (`text`, `link.url`) |
| Shortcode (blog templates) | `shortcode` — plugin shortcodes for breadcrumbs, badge, meta line, featured image, author box, latest posts, categories, related posts; optional user form shortcode |
| FAQs | `heading` (H3) + `text-editor` per question — the classic `accordion`/`toggle` widgets are deprecated in Elementor 4 when nested elements are active, so they are not used |

No HTML widget is used, so every part stays editable in Elementor. Images are added in Phase 5.

### Layout styles

* **Content only** — plain widgets that inherit the theme and Elementor global (kit) styles. The
  theme prints the page title.
* **Designed** — padded sections (48/24 px) with alternating light backgrounds, a header band with
  the introduction and button, check-mark lists, an FAQ band and a closing call to action.
  *One H1 rule:* with classic themes the H1 is placed in the header band and the document's
  `hide_title` setting hides the theme title. Elementor hides it with the kit's *Page Title Selector*
  (default `h1.entry-title`), which block themes do not use, so with block themes the plugin keeps
  the theme's title and adds no H1. If your classic theme uses a different title markup, set the
  selector in Elementor → Site Settings → Layout.

### Safety rules

* Only drafts are written. A linked post that is no longer a draft is never changed.
* An existing linked draft is replaced only if the plugin created it with Elementor **and** its
  layout has not changed since (an MD5 of `_elementor_data` is stored in `_aiscs_elementor_hash`).
  If someone edited it in Elementor, or it was saved for the WordPress editor, the plugin refuses
  and offers **Save as a new draft**.
* Before replacing a layout, the previous `_elementor_data` is stored in `_aiscs_elementor_backup`
  (WordPress also keeps a post revision).
* The tree is validated before saving: allowed element types and nesting, unique 7-character hex
  ids, settings arrays, only the widgets above, and every widget registered on the site. Nothing is
  saved if a check fails. After saving, the stored layout is read back and widget counts compared.
* Button links must be `http(s)` URLs with a host or a site path such as `/contact/`; the server
  never requests them.
* Existing Elementor pages are **not edited in place**. The optimiser reads their HTML copy and saves
  the revision as a **new** Elementor draft (content style) while Elementor is active; the original
  design is not copied. Targeted in-place editing of existing layouts is not implemented.

## Testing

Unit tests (`tests/Unit/ElementorTest.php`) cover splitting, both styles, sections/columns,
validation failures, the one-H1 rule and the writer's refusal/backup rules with a fake Elementor.

Smoke test with Elementor 4.0.8 source running in WordPress (PHP 8.3, SQLite, Twenty Twenty-Four):

- [x] Containers active: content style 20 widgets, designed 26; all stored (read-back counts match).
- [x] Container feature switched off: same counts using sections/columns.
- [x] Elementor's front-end renderer output: expected widgets, one H1, working button links, no scripts.
- [x] Full wizard in Chromium (demo mode): Output → Elementor + Designed + button → draft → "Edit with Elementor" link; front end shows one H1.
- [ ] **Not tested:** the Elementor editor UI itself. The source build has no compiled editor
  JavaScript, so the drafts could not be opened in the visual editor here. Front-end CSS was
  compiled from Elementor's SCSS for the visual check. Please open a generated draft in Elementor on
  a real site and report anything odd.
- [ ] Elementor 3.x, Elementor Pro installed alongside, classic themes such as Hello Elementor.

## Blog post templates (1.2.0)

`BlogTemplateBuilder` (magazine, centered, hero) extends `LayoutBuilder` and uses the same widgets plus
`shortcode`. Layout comes from containers (`content_width`, `boxed_width` 800 px for reading columns,
`flex_direction` row → column on tablet, inner containers with `width` 68 % / 32 %) or, with
containers off, sections with columns (`_column_size`, section `content_width`). Styling comes from
CSS classes (`css_classes` on containers/sections/columns, `_css_classes` on widgets) and
`assets/frontend/blog-templates.css`, loaded only on posts with `_aiscs_template` meta (and wherever a
plugin shortcode renders); the accent colour is `_aiscs_accent` → `--aiscs-accent`.

* H2 anchors use the widget `_element_id` setting; the table of contents is a text-editor list linking to them.
* Callouts: top-level `<blockquote>` chunks become text-editor widgets classed `aiscs-callout--tip|note|example|quote`
  from their leading label.
* Classic themes: document setting `template = elementor_header_footer` (Elementor's "Full Width",
  stored in `_wp_page_template`), so the layout carries the only H1. Block themes: no template change,
  no H1 or featured image from the layout (the theme shows them); `.is-layout-constrained > .elementor`
  is widened to the theme's wide size on template posts.
* Container backgrounds of the hero and closing band are excluded from Elementor's lazy background
  loading (`e-no-lazyload`).
* Checked locally with Elementor 4.0.8 (containers) on a classic test theme and Twenty Twenty-Four,
  desktop and 390 px mobile. Opening the Elementor editor could not be checked in the test
  environment (Elementor built from source without editor bundles).
