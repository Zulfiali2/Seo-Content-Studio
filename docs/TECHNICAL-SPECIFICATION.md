# AI SEO Content Studio — Technical Specification

Status: **Phases 1 (Foundation) and 2 (SEO research, keyword planning, content brief) implemented.** Phases 3–6 are specified here and not yet built.
Last reviewed: 2026-10-12.

---

## 1. Repository inspection (starting point)

The repository `AQF-post-and-page-generator` was empty (no commits, no files) when work started.
The plugin is therefore created from scratch at the repository root, which *is* the plugin
directory (`ai-seo-content-studio/` inside the distributable ZIP).

## 2. Goals and non-goals

**Goals (initial release, all phases):** keyword/SERP research → editable keyword plan → editable
content brief → structured AI content → deterministic + AI SEO evaluation → optional AI images →
WordPress draft and/or Elementor Free layout built from native widgets → manual review.

**Explicit non-goals for the initial release:** bulk generation, automatic publishing, a simulated
billing system, Elementor Pro features, dependence on Yoast/Rank Math, centralised credential
hosting.

## 3. Platform requirements

| Item | Decision | Reason |
|---|---|---|
| PHP | **8.2 minimum** (tested on 8.3) | Brief targets 8.2+; PHP 8.1 is end-of-life. Readonly classes and enums are used. |
| WordPress | **6.6 minimum** | Uses current REST, `wp_register_script` asset files, `@wordpress/components` shipped in core. Elementor 4.4 itself requires WP 6.8, so Elementor users will be on 6.8+ anyway. |
| PHP extensions | `sodium` (bundled since PHP 7.2), `json`, `mbstring` (optional; fallbacks used) | Credential encryption. |
| Elementor | Optional. Free edition only. Integration targets the Elementor 3.x/4.x document API (`Elementor\Plugin::$instance->documents`, `Document::save()`); version range will be pinned and tested in Phase 4. | — |
| Database | Uses `$wpdb` + `dbDelta`; MySQL/MariaDB as supported by WP. | — |

The bootstrap checks PHP/WP versions and `sodium` before loading anything else and shows an admin
notice instead of fatally failing.

## 4. Runtime dependencies

**None.** The plugin ships its own PSR-4 autoloader and talks to provider APIs through the WordPress
HTTP API (`wp_remote_request`). No vendor directory is required at runtime.

Development-only dependencies:

| Tool | Purpose |
|---|---|
| `phpunit/phpunit` ^11 | Unit tests |
| `brain/monkey` | Mocks WordPress functions in unit tests (no DB needed, no paid API calls) |
| `wp-coding-standards/wpcs` ^3, `phpcompatibility/phpcompatibility-wp` | Coding standards and PHP-version compatibility |
| `@wordpress/scripts` | Builds the React admin bundle against WordPress's own React/components (externals; nothing extra is shipped to the browser) |

Action Scheduler (GPL-3.0, bundled with WooCommerce) will be **used when present** for background
jobs from Phase 2; otherwise a WP-Cron + polling fallback is used. It is not bundled.

## 5. Architecture

```
ai-seo-content-studio.php     Bootstrap: constants, requirement gate, autoloader, hooks
uninstall.php                 Opt-in data removal (never deletes posts)
includes/
  Autoloader.php              PSR-4 for AiSeoContentStudio\
  Core/                       Plugin (composition root), Requirements, Activator, Deactivator,
                              Migrations, Capabilities, Environment (Elementor/SEO plugin detection)
  Security/                   Encryption (libsodium), CredentialStore, UrlValidator (SSRF), Redactor
  Settings/                   SettingsSchema (defaults + sanitisation), SettingsRepository
  Http/                       HttpClient (timeouts, bounded retries, size limits, error mapping)
  Providers/
    Contracts/                ProviderInterface, TextGenerationInterface, Capability, DTOs, exceptions
    OpenAI/                   Responses API adapter
    Anthropic/                Messages API adapter
    Gemini/                   generateContent adapter
    Custom/                   OpenAI-compatible Chat Completions adapter (custom base URL)
    ProviderRegistry.php      Discovery + extension filter `aiscs_providers`
  Usage/                      UsageRepository (custom table), UsageLimiter, CostEstimator
  Logging/                    Logger (secret-redacting)
  REST/                       Settings, Providers, Usage, Status controllers (namespace aiscs/v1)
  Admin/                      Menu registration + asset loading for the React app
assets/src/admin/             React admin (uses window.wp.* packages via @wordpress/scripts)
assets/build/                 Compiled bundle (generated; included in the release ZIP)
tests/                        PHPUnit unit tests + fixtures; QA checklist in docs/
docs/                         Specification, provider guide, security & cost docs, QA checklist
```

Phase 2 added `Research/` (contracts, DataForSEO adapter, gateway, cache, competitor fetcher, robots,
extractor, analysis prompt, job), `Keywords/` (plan model + builder), `Content/` (brief model,
generator, job), `Projects/`, `Jobs/` and `Support/`. Later phases add `SEO/`, `Elementor/`,
`Images/`, `Media/` only when they contain real code.

### 5.1 Composition

`Core\Plugin` is the composition root. It builds services once (simple constructor injection, no
container library) and registers hooks. Each REST controller receives only the services it needs, so
modules are testable in isolation.

### 5.2 Provider abstraction

```
ProviderInterface            id(), label(), capabilities(), isConfigured(), testConnection(), listModels()
TextGenerationInterface      generateText(TextRequest): TextResponse
                             generateStructured(TextRequest with jsonSchema): TextResponse (->data)
(Phase 5) ImageGenerationInterface   generateImage(ImageRequest): ImageResponse
```

* `Capability` enum: `TEXT`, `STRUCTURED_OUTPUT`, `IMAGE`, `MODEL_LISTING`, `USAGE_REPORTING`.
  An adapter declares only what its API supports; callers check before use.
* All adapters return a normalised `TextResponse` (`text`, `data`, `model`, `finishReason`,
  `Usage{inputTokens, outputTokens}`) or throw `ProviderException` with a `category`
  (`auth`, `rate_limit`, `timeout`, `network`, `invalid_request`, `server`, `invalid_response`,
  `refusal`, `truncated`, `config`, `limit`) and `retryable` flag.
* Model names are **never hard-coded as facts**. Connection tests call each provider's documented
  list-models endpoint; users pick a model from that list (or type one). Which model is "text" vs
  "image" is a per-model user setting because list endpoints do not reliably say so (Gemini's
  `supportedGenerationMethods` is used where available).
* Structured output uses each API's native mechanism (verified against official sources, see the
  provider guide) and the JSON is always decoded and validated server-side; malformed output is an
  `invalid_response` error, never silently accepted.

### 5.3 Data storage

| Data | Storage | Notes |
|---|---|---|
| Settings | option `aiscs_settings` (autoload) | Sanitised against `SettingsSchema`. |
| Credentials | option `aiscs_credentials` (**not** autoloaded) | Each value encrypted with libsodium `crypto_secretbox`; key from `AISCS_ENCRYPTION_KEY` constant if defined, otherwise derived from WP salts. Never returned to the browser — only `configured: true` and the last 4 characters. |
| Usage / cost log | custom table `{prefix}aiscs_usage_log` | Justified: append-only, queried by time window for rate limits and reports; would bloat `postmeta`/options. |
| DB schema version | option `aiscs_db_version` | Drives migrations. |
| Content, revisions | posts/pages + WP revisions (Phase 3) | No duplicate content store. |
| Research cache | transients `aiscs_rc_*` (and `aiscs_robots_*`) | Keyed by provider, call, params, sandbox flag. |
| Projects | private CPT `aiscs_project`; meta `_aiscs_inputs`, `_aiscs_research`, `_aiscs_keyword_plan`, `_aiscs_brief`, `_aiscs_job` (JSON) | One section per meta key so saving one step never rewrites another. |
| Generated images | Media Library attachments (Phase 5) | — |
| Plugin post meta | keys prefixed `_aiscs_` (Phase 3+) | Underscore = hidden from custom-fields UI. |

### 5.4 Security model

* Custom capabilities: `aiscs_manage_settings` (administrators) and `aiscs_generate_content`
  (administrators + editors). Every REST route has a `permission_callback` checking a capability;
  cookie-authenticated requests additionally require the `wp_rest` nonce (enforced by core).
* Secrets stay server-side; REST responses expose masked status only; logs pass through `Redactor`.
* Outbound URLs that are user-supplied (custom AI endpoints now, competitor pages in Phase 2) go
  through `UrlValidator`: http(s) only, no credentials in URL, DNS-resolved IPs must be public
  (private/loopback/link-local/reserved ranges rejected), ports restricted, redirects disabled and
  re-validated. Private endpoints (e.g. a local LLM) require the explicit “allow private network”
  setting.
* All billable calls pass `UsageLimiter` (per-hour limits per operation type) and are recorded.

### 5.5 Request flow (Phase 1: connection test)

```
React (apiFetch + X-WP-Nonce) ──POST /aiscs/v1/providers/{id}/test──▶ ProvidersController
   permission_callback: current_user_can('aiscs_manage_settings')
   ─▶ ProviderRegistry::get(id) ─▶ Adapter::testConnection()
         ─▶ CredentialStore::get(id,'api_key')  (decrypt)
         ─▶ HttpClient::request()  (timeout, bounded retry, size cap, error mapping)
   ◀─ ConnectionResult {ok, message, models[]}   (no secrets, redacted errors)
```

### 5.6 Full workflow data flow (target, Phases 2–5)

```
Keyword + market + content type
  └▶ Research job (DataForSEO SERP + keyword metrics, cached) ──▶ competitor fetch (UrlValidator,
     robots.txt respected) ──▶ extraction (titles, headings, questions)
  └▶ AI keyword expansion (labelled "AI-generated", separate from provider metrics)
  └▶ Keyword plan (user edits/approves) ──▶ Brief (user edits) ──▶ Outline (user edits)
  └▶ Section-by-section structured generation (approved sections locked)
  └▶ SEO evaluator: deterministic checks + AI judgement (kept separate)
  └▶ Optional images (preview → approve → Media Library)
  └▶ Output: WP draft (blocks/classic) or Elementor document (native widgets, validated)
  └▶ Save only on explicit confirmation; open in Elementor / editor
```

Long operations run as background jobs (Action Scheduler when present, else WP-Cron) with a job
record, idempotency key (hash of inputs) to prevent duplicate billable execution, and polling from
the UI.

## 6. Phased implementation checklist

### Phase 1 — Foundation  ✅ (0.1.0)
- [x] Plugin bootstrap, requirement gate, PSR-4 autoloader
- [x] Activation (caps, default settings, DB table), deactivation (cron cleanup only), opt-in uninstall
- [x] Settings schema with sanitisation; REST settings endpoint
- [x] Encrypted credential storage; masked status output
- [x] HTTP client with timeouts, bounded retries, `Retry-After`, response-size cap, error mapping
- [x] Provider contracts + adapters: OpenAI, Anthropic, Gemini, OpenAI-compatible custom endpoint
- [x] Connection testing + model listing per provider
- [x] Text + structured generation methods in each adapter (used from Phase 2 onward)
- [x] Usage log table, per-hour limits, configurable pricing → estimated cost (marked as estimate)
- [x] SSRF-safe URL validator, secret redactor, logger
- [x] Environment diagnostics (PHP/WP/Elementor/SEO plugins/Action Scheduler)
- [x] React admin shell: Dashboard, AI Providers, Usage & Costs, General Settings
- [x] PHPUnit unit tests with fixtures/mocks; PHPCS config; manual QA checklist

### Phase 2 — SEO research, keyword planning, brief  ✅ (0.2.0)
- [x] `ResearchProviderInterface` + DataForSEO adapter (verified against the official
      `dataforseo-client` 2.1.9 models): account check, markets, Google organic SERP (live/advanced),
      Labs keyword overview, Labs related keywords; envelope/task status validation; provider-reported cost
- [x] Research gateway: cache (transients, configurable hours, refresh option), hourly limits, usage log
      with `cost_source = provider`; schema v2 migration adds `cost_source`
- [x] Competitor outlines: robots.txt (RFC 9309 matching, per-host cache, 5xx = disallow), SSRF-checked
      fetch with manual re-validated redirects, HTML-only, 2 MB cap; only headings/word count/schema types kept
- [x] AI research analysis (structured, strict-mode schema) from SERP + outlines; never sends competitor text
- [x] Optional provider metrics lookup for AI-suggested keywords
- [x] Keyword plan (Module D): groups, per-term source + approval, exclusions, user terms, intent with source,
      placement guidance; re-runs keep user decisions
- [x] Content brief (Module E): AI-generated, fully editable; internal links only from real published posts
- [x] Projects (private custom post type, JSON meta per section, trash/restore, ownership checks)
- [x] Background jobs: step runner with per-project lock, Action Scheduler or WP-Cron, poll-driven
      fallback for stalled jobs, bounded retry, cancel
- [x] Pre-run cost estimates (recent average → price table → unknown) with confirmation
- [x] UI: New Content wizard steps 1–5, Projects, SEO Research Settings
- [x] Works without DataForSEO (data explicitly marked unavailable)

### Phase 3 — Content generation  ✅ (0.3.0)
- [x] xAI Grok provider (OpenAI Responses-compatible adapter, base URL `https://api.x.ai/v1`)
- [x] Content model: intro / section-N / conclusion parts with status (empty, draft, approved) and lock;
      FAQs, metadata, claims to verify; HTML allow-list, links only to supplied URLs
- [x] Section-by-section structured generation as a background job; regenerate all or one part;
      approved/locked parts preserved
- [x] SEO evaluator: deterministic measured checks (category scores, no density targets) separate from an
      optional AI review; previous scores kept for before/after
- [x] Version store (10 snapshots), compare and restore
- [x] Draft writer: core block markup or classic HTML, drafts only, never overwrites non-draft posts,
      `_aiscs_*` post meta (meta title/description, slug, schema type, project)
- [x] Front-end title/meta description/JSON-LD only for plugin posts and only when no SEO plugin is active
- [x] FAQPage JSON-LD (1.1.0) from the FAQs visible on the page (`_aiscs_faqs` post meta, plain-text answers); own setting `seo.output_faq_schema`, per-page `meta.faq_schema`
- [x] Existing-content optimiser: read-only source (blocks/classic/Elementor text), recommendations with
      accept/reject, rewrite, new draft linked with `_aiscs_source_post`
- [x] UI: wizard steps 6–11, Optimise Existing Content, Projects and History

### Phase 4 — Elementor Free  ✅ (0.4.0)
- [x] Layout builder: native free widgets (`heading`, `text-editor`, `icon-list`, `button`; FAQ as
      heading + text — classic accordion/toggle are deprecated in Elementor 4) in containers, or
      sections/columns when the container feature is off; settings verified against Elementor 4.0.8 source
- [x] Content-only and designed styles; one-H1 rule (theme title hidden only where Elementor can)
- [x] Validator (types, nesting, unique 7-hex ids, registered widgets) before save; read-back after save
- [x] Save through `Document::save()` on a draft; hash-checked replacement with `_aiscs_elementor_backup`;
      "save as new draft" fallback; optimiser revisions of Elementor pages saved as new Elementor drafts
- [x] UI: Output step (format, style, button), Save step "Edit with Elementor"
- [ ] Not done: targeted in-place editing of existing Elementor layouts (duplicate-draft approach instead);
      `image` widget mapping moves to Phase 5; `divider`/`spacer` not needed by the layouts

### Phase 5 — Images  ✅ (0.5.0)
- [x] `ImageGenerationInterface`; OpenAI, xAI Grok and Gemini adapters (verified against the official SDKs) and a demo adapter
- [x] `ImageGenerationService`: provider resolution, hourly image limit, duplicate lock, usage log (1 unit, per-image price)
- [x] `ImageValidator`: type from magic bytes (PNG/JPEG/WebP only), cross-checked with `getimagesizefromstring`, 20 MB and 64–8192 px limits
- [x] `MediaImporter`: temp file → `media_handle_sideload()`, alt text, caption, `_aiscs_generated_image` tag; deletes only its own unused images
- [x] Image slots from the content metadata (featured + up to 5 after sections), editable prompt/alt/caption/shape/placement, background job, cost confirmation
- [x] Drafts: featured image set, in-article images as core/image blocks (block editor markup) or figures (classic), Elementor `image` widgets; images attached to the draft

### Phase 6 — Hardening and release
Security review, compatibility matrix, cost-control polish, error recovery, docs, packaging tests.

## 7. Assumptions requiring confirmation

1. **Minimum versions:** PHP 8.2, WordPress 6.6. If your host is on PHP 8.1, tell me and I will
   lower the floor (the code avoids 8.2-only syntax except readonly classes, which can be changed).
2. **Plugin slug / text domain:** `ai-seo-content-studio`; PHP namespace `AiSeoContentStudio`;
   option/table prefix `aiscs_`; REST namespace `aiscs/v1`.
3. **Editors** may generate content (`aiscs_generate_content`) but only administrators may change
   settings and API keys. Adjustable via standard role/capability plugins.
4. **Encryption key:** if `AISCS_ENCRYPTION_KEY` is not defined in `wp-config.php`, the key is derived
   from WordPress salts. Rotating salts makes stored API keys unreadable (the UI then asks for them
   again). Defining the constant is recommended and documented.
5. **Pricing** is not shipped as fact. Users (or a future central service) enter per-model prices;
   until then costs show as “unknown”, while token usage is still recorded.
6. **Custom endpoint** adapter targets the OpenAI-compatible Chat Completions shape, which is the
   most widely implemented compatibility surface (vLLM, Ollama, LM Studio, OpenRouter, etc.).
7. **Language:** English only initially; strings are translatable and a `content_language` setting
   exists for future languages.
8. **Elementor Free version range** will be fixed in Phase 4 after testing (current upstream is 4.4.0).

## 8. Documentation sources consulted

Direct access to vendor documentation sites was blocked by the build environment's network policy,
so integrations were verified against official machine-readable sources instead:

* OpenAI — official OpenAPI spec (`github.com/openai/openai-openapi`): `POST /v1/responses`
  (`instructions`, `input`, `max_output_tokens`, `text.format` `{type: json_schema, name, schema,
  strict}`, `output[]`, `usage.input_tokens/output_tokens`, `status`), `GET /v1/models`,
  `POST /v1/chat/completions`.
* Anthropic — Claude API reference: `POST /v1/messages` with `x-api-key` and
  `anthropic-version: 2023-06-01`, `output_config.format {type: json_schema, schema}`, `GET /v1/models`
  (paginated with `has_more`/`last_id`/`after_id`), `stop_reason` incl. `refusal`.
* Google Gemini — official `google-genai` SDK source: base
  `https://generativelanguage.googleapis.com/v1beta/`, header `x-goog-api-key`,
  `models/{model}:generateContent`, `systemInstruction`, `generationConfig.maxOutputTokens /
  responseMimeType / responseJsonSchema`, `usageMetadata`, `GET models` with
  `supportedGenerationMethods`.
* Elementor — upstream source (`elementor.php`, `core/base/document.php`): `ELEMENTOR_VERSION`,
  `_elementor_data`, `_elementor_edit_mode`, `Document::save()`, `get_elements_data()`.

These must be re-checked against live docs before each later phase (rule 7/8 of the brief).
