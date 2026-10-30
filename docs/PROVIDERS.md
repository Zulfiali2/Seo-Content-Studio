# AI provider capability and configuration guide

Status as of 1.0.0. Verified against official sources on 2026-10-12 (see the specification §8).
Provider APIs change: re-verify before relying on a capability.

## Capability matrix (what this plugin implements)

| Capability | OpenAI | Anthropic | Gemini | xAI Grok | Custom (OpenAI-compatible) |
|---|---|---|---|---|---|
| Text generation | ✅ `POST /v1/responses` | ✅ `POST /v1/messages` | ✅ `models/{m}:generateContent` | ✅ `POST https://api.x.ai/v1/responses` | ✅ `POST {base}/chat/completions` |
| Native structured JSON | ✅ `text.format` json_schema (strict) | ✅ `output_config.format` json_schema | ✅ `responseMimeType` + `responseJsonSchema` | ✅ `text.format` json_schema (strict) | Opt-in `response_format` json_schema; otherwise by instruction |
| JSON validated by plugin | ✅ | ✅ | ✅ | ✅ | ✅ |
| Model listing / connection test | ✅ `GET /v1/models` | ✅ `GET /v1/models` (paginated) | ✅ `GET /v1beta/models` (paginated, method hints) | ✅ `GET /v1/models` (404 = warning, enter model manually) | ✅ `GET {base}/models` (404 = warning) |
| Usage reporting | input/output tokens | input/output tokens | prompt + candidates + thoughts tokens | input/output tokens | prompt/completion tokens if returned |
| Image generation | ✅ `POST /v1/images/generations` | Not offered by Anthropic | ✅ `models/{m}:generateContent` (IMAGE output) | ✅ `POST /v1/images/generations` | Not supported |

Connection tests only list models, so they do not incur generation charges.

## Settings per provider

| Provider | Credential | Settings | Auth header |
|---|---|---|---|
| OpenAI | API key | Text model | `Authorization: Bearer` |
| Anthropic | API key | Text model | `x-api-key` + `anthropic-version: 2023-06-01` |
| Gemini | API key (Google AI Studio) | Text model | `x-goog-api-key` (never in the URL) |
| xAI Grok | API key (console.x.ai) | Text model | `Authorization: Bearer` |
| Custom | API key optional | Display name, base URL, "supports json_schema" | `Authorization: Bearer` if a key is stored |

No model names are shipped as defaults. Choose from the list returned by your key, or type an exact id
from the provider's documentation. Model lists can include non-text models (embeddings, audio, image);
pick a text-generation model.

## Behaviour common to all providers

* Timeouts (default 90 s), bounded retries (default 2) for 408/409/429/5xx/529 and connection
  timeouts, honouring `Retry-After` up to 20 s total wait; longer waits fail fast with a clear message.
* Not retried: invalid key (401), permission (403), not found (404), bad request (400/422), quota or
  billing exhaustion (e.g. OpenAI `insufficient_quota`, HTTP 402), refusals, invalid JSON.
* Output truncated by the token limit is an error for structured requests.
* Refusals (`stop_reason: refusal`, OpenAI refusal content, Gemini block reasons/SAFETY,
  `content_filter`) are reported as refusals, not as empty content.
* Redirects are never followed; responses over 8 MB are cut off.
* Thinking/reasoning content is never shown or stored; only answer text is used.

## xAI Grok (0.3.0)

The Grok adapter reuses the OpenAI Responses adapter with the fixed base URL `https://api.x.ai/v1`
(xAI documents the Responses API and `json_schema` structured output as OpenAI-compatible; request
and response fields were checked against the `@ai-sdk/xai` 5.0.20 schemas). Choose a Grok model from
the list your key returns, or type the exact model id from xAI's documentation. Not yet verified with
a live xAI account from this build environment.

## Image generation (0.5.0)

Verified on 2026-10-13 against the official SDK packages (no endpoints or fields are guessed):

| Provider | Request | Response | Source |
|---|---|---|---|
| OpenAI | `POST /v1/images/generations` with `model`, `prompt`, `n: 1`, `size` (`1024x1024`, `1536x1024`, `1024x1536`; DALL·E 3: `1792x1024`/`1024x1792`). GPT image models reject `response_format`; DALL·E models are sent `response_format: b64_json`. | `data[0].b64_json`, optional `revised_prompt`, `usage` tokens | `openai` 7.32.0 `resources/images.d.ts` |
| xAI Grok | `POST /v1/images/generations` with `model`, `prompt`, `n: 1`, `response_format: b64_json`, `aspect_ratio` | `data[0].b64_json`; `respect_moderation: false` = blocked | `@ai-sdk/xai` 5.0.21 |
| Gemini | `models/{model}:generateContent` with `generationConfig.responseModalities: ["IMAGE"]` and `imageConfig.aspectRatio` | first `candidates[0].content.parts[].inlineData` image; `promptFeedback.blockReason` / safety finish reasons = refusal | `@ai-sdk/google` 4.0.94 (only `gemini-` image models are supported there, so the plugin does the same) |

* Choose the **image model** per provider (no model names are shipped) and the **default image provider**
  under AI Providers.
* Image responses may be up to 32 MB, use a timeout of at least 120 s and are **not retried**
  automatically (a retried generation could be billed twice).
* Only base64 image data is accepted; image URLs are never downloaded.
* xAI reports a cost in "ticks"; its conversion is not documented in the SDK, so the plugin does not
  use it. Costs come from the **per image** column of your price table.

## Reasoning models (1.0.0)

Models that think before answering count their reasoning against the output limit. The plugin adds
16,000 tokens of headroom for Gemini 2.5 and later, OpenAI o-series and GPT-5+ (not the `-chat`
variants, following `@ai-sdk/openai`), and Grok 4+ / Grok 3 mini. The limit is a ceiling only:
unused tokens are not billed. Other models are unaffected.

## Custom endpoints

Base URL is the part before `/chat/completions`, e.g. `https://openrouter.ai/api/v1` or
`http://localhost:11434/v1` (Ollama). Private, loopback and link-local addresses are blocked unless
**Allow private-network endpoints** is on (only for self-hosted models you control).

## Hosts to allow when outbound HTTP is restricted

```php
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'api.openai.com,api.anthropic.com,generativelanguage.googleapis.com,api.x.ai,api.dataforseo.com,sandbox.dataforseo.com,api.openverse.org,api.pexels.com,images.pexels.com,pixabay.com' );
```

## Adding a provider

Implement `ProviderInterface` + `TextGenerationInterface` (extend `Providers\AbstractProvider` for
shared behaviour), declare only supported `Capability` values, and register:

```php
add_filter( 'aiscs_providers', function ( array $providers ) {
	$providers['acme'] = new Acme_Provider( /* ... */ );
	return $providers;
} );
```

## SEO data provider: DataForSEO (0.2.0)

Verified against the official `dataforseo-client` 2.1.9 package (generated from DataForSEO's
OpenAPI spec) on 2026-10-12.

| Use | Endpoint | Notes |
|---|---|---|
| Connection test | `GET /v3/appendix/user_data` | Shows the balance DataForSEO reports. |
| Market picker | `GET /v3/dataforseo_labs/locations_and_languages` | Countries only; cached 30 days. |
| Search results | `POST /v3/serp/google/organic/live/advanced` | `depth` 10–100 (billed per 10 results). Organic results, People Also Ask, related searches. |
| Keyword metrics | `POST /v3/dataforseo_labs/google/keyword_overview/live` | Search volume, CPC, competition, keyword difficulty, main intent. Missing values stay empty. |
| Related keywords | `POST /v3/dataforseo_labs/google/related_keywords/live` | `depth` 2, configurable limit. |
| Competitor ranking keywords (1.3.0) | `POST /v3/dataforseo_labs/google/ranked_keywords/live` | One task per competitor page: `target` = the page URL with `https://` (so the page, not the whole domain), `item_types` `["organic"]`, `limit`, `order_by` search volume desc. Reads `keyword_data` (keyword, volume, CPC, difficulty, intent) and `ranked_serp_element.serp_item` (`rank_group`, `url`, `etv`). |

* Auth: HTTP Basic with the **API login and API password** from DataForSEO's API Access page.
* Every response is checked at envelope and task level (`status_code` 20000 = OK); DataForSEO
  often returns HTTP 200 for failed tasks. Its message is shown to you.
* Costs: the `cost` field DataForSEO returns is recorded as a provider-reported cost (not an estimate).
* Sandbox: `https://sandbox.dataforseo.com` returns free dummy data for testing the workflow.
* Results are cached (default 72 hours) so re-runs and interrupted jobs do not pay twice.

Without DataForSEO the workflow still runs: search results, metrics and competitor outlines are
marked "not available" and keyword ideas are AI-only. Other providers (e.g. Semrush) can be added by
implementing `Research\Contracts\ResearchProviderInterface`.

Hosts to allow when outbound HTTP is restricted: add `api.dataforseo.com` (and
`sandbox.dataforseo.com` if used). Competitor pages are arbitrary hosts, so competitor reading
will not work under a strict `WP_HTTP_BLOCK_EXTERNAL` allow-list; turn it off in SEO Research Settings.

## Free stock photos (1.3.0)

Verified against official clients/source on 2026-10-16.

| Library | Licence offered | Endpoint | Auth |
|---|---|---|---|
| Openverse | Public domain mark and CC0 only (`license=cc0,pdm`) | `GET https://api.openverse.org/v1/images/?q=&license=&mature=false&aspect_ratio=&page_size=20`, `GET /v1/images/{id}/` (Openverse API serializers; `@openverse/api-client` types) | None (anonymous, max 20 per page) |
| Pexels | Pexels License (royalty-free) | `GET https://api.pexels.com/v1/search?query=&per_page=`, `GET /v1/photos/{id}` (official `pexels` 1.4.0 client) | `Authorization: <key>` |
| Pixabay | Pixabay Content License (royalty-free) | `GET https://pixabay.com/api/?key=&q=&image_type=photo&orientation=&safesearch=true&per_page=`, `?id=` (typed `pixabay-api` client) | `key` query parameter |

* Unsplash is not offered: its API guidelines require hotlinking images from Unsplash, while this plugin
  copies images into your Media Library.
* Pixabay's file URLs expire, so images are always downloaded. `largeImageURL` is used when present,
  otherwise `webformatURL` (640 px).
* Openverse images are hosted by their original sites (Flickr, Wikimedia, …), so downloads come from
  those hosts: they are SSRF-checked on every redirect, limited to 20 MB, and validated as PNG/JPEG/WebP
  with sensible dimensions before import. Allow-listing hosts for Openverse downloads is not possible.

## Cost settings per provider (1.4.0)

| Provider | Effort field | Prompt caching | Cached tokens read from |
|---|---|---|---|
| Anthropic | `output_config.effort` | explicit `cache_control` on system prompt and shared context | `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens` (not included in `input_tokens`) |
| OpenAI | `reasoning.effort` (reasoning models) | automatic; `prompt_cache_key` sent | `usage.input_tokens_details.cached_tokens` (included in `input_tokens`) |
| Gemini | `generationConfig.thinkingConfig.thinkingLevel` (3+) / `thinkingBudget` (2.5) | implicit (2.5+) | `usageMetadata.cachedContentTokenCount` (included in `promptTokenCount`) |
| xAI Grok | `reasoning.effort` (not Grok 4.20 fixed variants) | identical prefix only | as OpenAI |
| Custom | none | identical prefix only | — |

Each provider card has an optional **Model for quick tasks**. Claude 5-generation models get 16,000
tokens of extra output room because thinking counts towards `max_tokens`.
