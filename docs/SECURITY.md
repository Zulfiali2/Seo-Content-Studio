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
