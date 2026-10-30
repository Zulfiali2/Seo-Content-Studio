# Cost controls

All billable AI work goes through `TextGenerationService` and all paid research through
`ResearchGateway`. Together they apply, in order:

1. **Provider/model resolution** — a default provider and model must be chosen explicitly.
2. **Request size limit** — `Max input characters per request` (default 120,000).
3. **Output cap** — `Max output tokens per request` (default 8,000); larger requests are reduced.
4. **Hourly limits** — per operation type (text 60, research 60, images 20 per rolling hour, all users).
   Failed requests count, because they may still be billed. `0` disables the operation.
5. **Duplicate prevention** — an identical request already in flight is rejected (atomic lock).
6. **Bounded retries** — at most the configured retries, only for temporary errors, ≤20 s total wait.
7. **Usage logging** — provider, model, tokens, status, error category and estimated cost per call.

Connection tests list models and do not generate content.

## Research costs (0.2.0)

* DataForSEO reports the cost of each request; it is logged as **provider-reported** (`cost_source =
  provider`), separate from AI costs calculated from your price table (`estimate`).
* Research results are cached (default 72 hours, adjustable). Cached answers cost nothing and do not
  count towards hourly limits. "Refresh cached search data" bypasses the cache and is billed.
* A research run makes up to 4 DataForSEO requests (SERP, primary metrics, related keywords, metrics
  for AI suggestions) and 1 AI request; competitor page reading is free apart from bandwidth. You can
  turn off competitor reading and the AI-suggestion metrics lookup in SEO Research Settings.
* Before research or brief generation, the wizard lists each billable request with an estimate based
  on your average recorded cost for that request over the last 30 days, otherwise your price table,
  otherwise "unknown". Confirmation is required when the total exceeds *Ask for confirmation above*
  or when any cost is unknown. Estimates assume no cache hits, so they are an upper bound.

## Content costs (0.3.0)

* Generating content makes one AI request per part (introduction, each H2 section, conclusion) plus
  one for FAQs and one for metadata. Regenerating skips approved and locked parts, so only the
  regenerated parts are billed. The estimate lists each request before you confirm.
* The measured SEO checks are free (no API call). The AI review is a separate, optional request.
* The optimiser makes one analysis request and one rewrite request.
* Each request is capped by *Max output tokens* and *Max input characters*, counts towards the hourly
  text limit, and duplicate in-flight requests are rejected.

## Image costs (0.5.0)

* One request per image. The estimate uses your recent average per image provider, otherwise the
  **per image** price for the provider and image model in your price table, otherwise "unknown".
* Image requests count towards *Image requests per hour* and are never retried automatically.
* Timed-out generation requests (text or image) are not retried automatically either, because the
  provider may already have billed them; a step that the server kills is run at most twice.
* Regenerating an image is a new billed request; the previous plugin-made image is deleted if unused.

## Estimated costs

* No prices are shipped. Enter current prices per provider and model (or `*` for a provider default)
  in **Usage and Costs**, with a source/date note.
* Estimate = per-request + input tokens × input price + output tokens × output price.
* Estimates are **not invoices**: providers may apply caching discounts, batch pricing, tiers, taxes,
  reasoning-token billing rules or currency conversion. Your provider's billing dashboard is
  authoritative. Rows without a matching price show "unpriced".
* `Ask for confirmation above` makes the wizard ask for confirmation when an operation's estimate
  exceeds it or any cost is unknown.

## Retention

Usage rows older than the retention period (default 365 days) are pruned daily via WP-Cron.

## Future centralised service

The provider and cost interfaces allow a later hosted service (accounts, central keys, metering,
quotas, licensing). It is intentionally **not** implemented: central provider keys must live only on
that service, which must enforce authentication, authorisation, spending and rate limits server-side.
No billing is simulated in the plugin.

## Cost mode, quick-tasks model and caching (1.4.0)

`Providers\CostPolicy` decides per request purpose, for every provider:

| Mode | Writing (parts, FAQs, brief, research analysis) | Quick tasks (metadata, AI review, optimiser analysis) |
|---|---|---|
| Economy | effort low | effort low, quick model if set |
| Balanced (default) | effort medium | effort low, quick model if set |
| Best quality | provider default | provider default, main model |

Effort mapping per adapter: Claude `output_config.effort` (Opus 4.5, 4.6+ and 5-generation models;
Sonnet 4.5 / Haiku 4.5 get none), OpenAI and xAI `reasoning.effort` (reasoning models only; not the
fixed Grok 4.20 reasoning/non-reasoning variants), Gemini 3+ `thinkingConfig.thinkingLevel`, Gemini 2.5
`thinkingConfig.thinkingBudget` (1,024 low / 4,096 medium; high = default). Verified against the Claude
API docs and @ai-sdk/openai, @ai-sdk/google 4.0.94 and @ai-sdk/xai 5.0.21.

Caching: `TextRequest::$sharedContext` carries the article context (brief, keywords, facts, links,
outline) that is identical for every part of one article. Claude marks the system prompt and the shared
context with `cache_control: {type: ephemeral}` (5-minute cache; current Claude models cache prefixes of
512+ tokens); OpenAI (with `prompt_cache_key`) and Gemini 2.5+ cache identical prompt starts automatically.

If a request changed by the policy is rejected as invalid (e.g. a retired quick model), it is retried
once unchanged. Batch APIs (50% cheaper, results within hours) are not used, because the wizard
needs answers within the same session.

## Final edit and AI competitor suggestions (1.5.0)

- The final edit is one extra writing request per article. It reuses the same system rules and article context as the part requests, so with prompt caching most of its input is billed at the cached rate. Its output is the changed parts only. Switch it off under Brand and Writing Style, or run it on its own from the content step. It appears in the cost estimate as "AI final edit of the whole article".
- Without DataForSEO, research adds one AI request ("AI suggestions for competitor pages"). Reading the suggested pages costs nothing, and their keywords are read in the existing analysis request.
- Checking cited sources costs nothing (plain page downloads, cached for a day).

## Writing in one go (1.6.0)

- The default first draft is one writing request ("AI writing the whole article in one go") plus the metadata request, instead of one request per section, the FAQ request and the final edit. That usually costs less: the rules and article context are sent once instead of once per part.
- The output cap for this request is at least 16,000 tokens so long articles are not cut off. Only the tokens actually written are billed.
- If the answer is cut off, the missing parts are written one by one, so in that case you pay for both.
