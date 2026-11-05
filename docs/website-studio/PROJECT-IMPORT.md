# Website project ZIP import (1.9.0-rc.2)

AI Website Studio → **HTML to Elementor** → **Website project (ZIP)** turns a complete static website
project (HTML, CSS, JavaScript, images, SVG icons, fonts) into an Elementor draft page. It keeps the
original design system instead of rebuilding the page from a simplified schema.

The older "Paste HTML and CSS" converter is still available on the second tab. It rebuilds a page
from native widgets only and is meant for simple markup.

## 1. Why the old converter lost the design

The audit of `HtmlConverter` / `CssParser` / `SelectorMatcher` (1.9.0-rc.1) found:

| Cause | Effect on a real project |
|---|---|
| CSS variables were read only from `:root`, `html` and `body` | Projects that define their palette on a class (`.dta-home { --navy: … }`) lost every colour. |
| `@font-face`, `@keyframes` and linked stylesheets were dropped | Brand fonts, animations and most of the CSS disappeared. |
| `SelectorMatcher` ignored `>`, `+`, `~` and structural pseudo-classes | Card, grid and list styles never matched. |
| Every element was mapped to the reduced `PageDocument` schema | Spacing, borders, shadows, pseudo-elements and hover states had no place to go. |
| `header`, `nav` and `footer` were skipped or flattened | The page lost its own chrome. |
| The theme template stayed active | Theme width limits and typography overrode the design. |
| The validator refused `svg` and every `iframe` | Inline icons and video embeds were removed. |

The ZIP importer keeps the original CSS (scoped to the page) and only uses native widgets where
that cannot change the result.

## 2. Workflow

1. **Upload** one ZIP (drag and drop or browse). The upload shows its progress. Limits are checked
   before anything is read (section 5).
2. **Validate**: entries are indexed straight from the ZIP; nothing is extracted to disk. Unsafe
   entries are refused and listed with the reason.
3. **Analyse**: the entry page is chosen automatically (an `index.html`, the shallowest first; otherwise
   the shallowest and then the largest HTML file) and can be changed. The analysis lists sections, headings, text,
   images, CSS files/rules/media queries/variables/keyframes, fonts, animations, interactive
   components, scripts (with what they do and what needs care), missing files, links to pages that
   are not in the ZIP, unused files and everything that was removed for safety.
4. **Preview**: original and converted page side by side, or one at a time, at desktop (1280 px),
   tablet (768 px) and mobile (390 px). Both run in a sandboxed iframe with a strict CSP and no
   JavaScript. The converted preview imitates Elementor's default styles; the real draft can still
   be affected by the theme and other plugins, so check it in Elementor's preview before publishing.
5. **Settings**: page title; new draft or update of an earlier imported draft; conversion mode;
   page layout (Elementor Canvas, Elementor Full Width or theme default); include or drop the
   project's header/navigation/footer; reuse identical images; keep the project's root font size;
   JavaScript handling (store for approval, or drop).
6. **Create draft**: assets are imported in batches of 25 (each request stays under 20 seconds),
   then the draft is written through `DraftManager` (validation, revision snapshot, audit entry).
   A repeated or interrupted request does not create a second page.
7. **Report**: containers, native widgets and HTML widgets per section, with the reason each HTML
   widget was kept; preserved CSS; missing or failed assets (the draft is then marked *partial*);
   script review and approval.

## 3. Conversion modes

| Mode | Result |
|---|---|
| **Hybrid** (default) | Sections and layout wrappers become Elementor containers that keep their tag, id and classes. Headings, text and images become native widgets **only** where Elementor's wrappers cannot change how the CSS applies. Everything else stays in an HTML widget. |
| **High fidelity** | Each section is one HTML widget inside a container. Closest to the original; text is edited as HTML. |
| **Native** | As many native widgets as possible. Places where CSS depends on exact parent or sibling elements are reported because they may look different. |

An element stays HTML in hybrid mode when:

- a CSS selector depends on its exact position (`>`, `+`, `~`, `:nth-child`, `:first-child`, …),
  checked against its real ancestors (`SelectorIndex`);
- its parent is a flex or grid container with mixed children (an Elementor wrapper would become the
  flex/grid item instead of the element);
- the page's JavaScript refers to it (ids, classes and data attributes found in script strings);
- it is interactive or embedded (`details`, `form`, `button`, `iframe`, `video`, inline `svg`, `picture`, `pre`, …) or
  carries behaviour attributes (`aria-expanded`, `data-*` hooks).

## 4. CSS

`CssScoper` rewrites every rule so it applies only inside the page root `#aiscs-p-{key}`:

- `:root`, `html` and `body` selectors (with their classes) become the root; selectors that start
  with a body class work with and without it;
- `@media`, `@supports`, `@container` and `@layer` are kept and their rules scoped;
- `@keyframes` are renamed per project and `animation` / `animation-name` rewritten;
- `@font-face` is kept with its `url()`s pointing at the imported font files;
- `@import` of project files is inlined; `link media="…"` becomes an `@media` wrapper;
- custom properties, pseudo-elements, `:hover` / `:focus` states and gradients are kept as written;
- a percentage or `em` root font size becomes pixels, so `rem` values keep their size.

A baseline before the project CSS reverts theme styles inside the root, and Elementor's wrapper
elements get `all: revert`, so the theme and Elementor defaults do not leak in. SVG elements and
Elementor's editor overlays are not touched.

The CSS is stored in post meta and printed only on that page (front end and Elementor preview) as an
inline style whose `</style` sequences are escaped. Web-font and CDN stylesheets are kept only from
an allowlist (Google Fonts, Bunny Fonts, Adobe Fonts, cdnjs, jsDelivr, unpkg, Font Awesome).

## 5. Security

| Area | Rule |
|---|---|
| Archive | Read with `ZipArchive` (checked with `CHECKCONS`); never extracted. Refused: `..`, absolute paths, drive letters, control characters, deep paths, symbolic links, encrypted entries, any `.php*`/`.phtml`/`.phar`/server or executable extension, unknown types. Zip-bomb check by compression ratio and total size. |
| Limits (filter `aiscs_project_import_limits`) | ZIP 50 MB; extracted 200 MB; one file 20 MB; 3,000 entries; depth 12; ratio 200; HTML 3 MB; CSS 4 MB; JS 2 MB; 400 assets / 150 MB; 25 MP per image; SVG 256 KB; 20,000 DOM nodes; preview 25 MB. |
| Permissions | Upload, analysis, build: `aiscs_generate_content` + `upload_files`; editing an imported page: `edit_post`; approving scripts: `unfiltered_html`. Uploads belong to their user (settings managers may open them). REST nonce on every request. |
| HTML | Parsed with `LIBXML_NONET`. `script`, `noscript`, `template`, `base`, `meta`, `link`, `object`, `embed`, `applet`, `frame`, `portal`, `on*` attributes, `srcdoc`, `formaction`, `ping` and `javascript:` / `vbscript:` / unsafe `data:` URLs are removed. Form `action` URLs follow the same rules as links. Iframes only for YouTube, Vimeo and Google Maps over https. |
| SVG | Element and attribute allowlist; `script`, `foreignObject`, event handlers, external references and unsafe styles are removed. Inline SVG and SVG files (as data URIs) only after that. |
| Images and fonts | Checked by content (magic bytes, `getimagesizefromstring`, pixel limit), not by extension. Images go through `media_handle_sideload()` (WordPress file checks, unique names, nothing overwritten) and are de-duplicated by SHA-256. Fonts are written to `uploads/aiscs-imports/{key}/{sha256}.{ext}` with the font extension only. |
| JavaScript | Never run in the admin, the previews, the Elementor editor or its preview, the Customizer, REST or AJAX requests. It is analysed (features and risks) and stored with the draft, **off**. A user with `unfiltered_html` approves each script; the approval is bound to the SHA-256 of the code and is lost if the code changes. Approved scripts run only on that page's front end. External script URLs are never loaded. |
| Validation | The draft goes through `DocumentValidator` in the new `imported` mode: allowlisted iframes and sanitised SVG are accepted; scripts, event handlers, styles/links/meta in widgets and executable URLs are refused. |
| Temporary files | `uploads/aiscs-studio-tmp/imports/{token}/` (random 32-hex token) with deny rules; deleted on discard and by the daily clean-up after one day. |

## 6. Files

New: `includes/WebsiteStudio/Import/` — `ProjectArchive`, `ImportLimits`, `ImportException`,
`AssetRefs`, `ProjectParser`, `SvgSanitizer`, `CssScoper`, `SelectorIndex`, `ScriptAnalyzer`,
`HybridBuilder`, `AssetResolver`, `PreviewRenderer`, `ImportStore`, `AssetSink`, `MediaAssetSink`,
`ImportService`, `ImportedPage`; `includes/REST/ProjectImportController.php`;
`tests/unit/ProjectImportTest.php`; `tests/fixtures/project-site/`.

Changed: `DocumentValidator` (`imported` mode), `DraftManager`, `GeneratedPageRepository`
(`imported` type), `Core/Plugin` (routes, front-end hook, clean-up), `Admin/WebsiteStudioPage`
(boot data), `assets/website-studio/studio.js` and `studio.css` (ZIP tab).

## 7. Known limitations

- Single-page import: other HTML pages in the ZIP can be chosen as the entry page, one at a time.
  Links to them are removed from the draft.
- HTML widgets keep the original design but are edited as HTML, not with Elementor's controls.
- ES modules and external scripts are not supported; scripts needing a build step will not work.
- Server-side features (PHP, forms posting to the original backend) are not imported.
- The converted preview imitates Elementor; the real page may differ where the theme or other
  plugins add CSS. Use Elementor Canvas for projects that bring their own header and footer.
