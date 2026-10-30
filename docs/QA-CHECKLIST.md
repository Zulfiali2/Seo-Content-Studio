# Testing and manual QA checklist

## Automated (run on every change)

| Command | Covers |
|---|---|
| `composer test` | 156 PHPUnit tests: encryption, credential store, settings sanitisation, SSRF validator, redaction/logging, HTTP retries and error mapping, all four provider adapters (request shape, structured output, refusals, truncation, pagination), cost estimation, limits, duplicate-call locking, REST permissions and secret non-disclosure; DataForSEO request/response handling (envelope and task errors, nulls kept null), robots.txt rules, HTML outline extraction (no body text), competitor fetch safety (robots, redirects to private IPs, non-HTML), keyword plan building/merging/sanitising, brief sanitising and real-only internal links, strict-mode schema checks, research gateway caching/limits/cost logging, job runner (steps, continuation, retry, cancel, stale tick, locking, access loss), end-to-end research job with and without DataForSEO, cost estimates, project ownership |
| `composer lint` | WordPress coding standards, docs, PHP 8.2+ compatibility |
| `npm run lint:js` | ESLint for the admin app |
| `npm run build` | Admin bundle compiles |

External APIs are replaced by JSON fixtures shaped like the official API references; tests make no
network calls and incur no cost.

## Smoke test performed for 0.1.0 (WordPress 6.7-alpha, PHP 8.3, SQLite drop-in)

- [x] Activation creates the usage table, default settings, capabilities, daily prune event; no PHP notices.
- [x] REST: anonymous → 401, editor → 403 on settings/keys and 200 on status, administrator → 200.
- [x] Saved key never appears in any REST response or page HTML; stored ciphertext has no plaintext; option not autoloaded.
- [x] Invalid settings clamped/dropped server-side.
- [x] Custom endpoint pointing to 169.254.169.254, 127.0.0.1, 10.0.0.1, localhost, file:// → rejected with zero outbound requests.
- [x] Unreachable/blocked provider → clear error, no wasted retries.
- [x] Mocked generation → usage row with tokens and estimated cost; lock released.
- [x] Admin UI (Chromium, desktop and 390 px): all four pages load; save key → masked hint; save settings; test connection error shown; remove key via confirmation dialog; editor cannot see/open admin-only pages.
- [x] Deactivation keeps data; uninstall keeps data unless opted in; opt-in uninstall removes plugin data but keeps posts and non-plugin meta.

## Smoke test performed for 0.2.0 (same environment; DataForSEO, OpenAI and competitor sites mocked at the WordPress HTTP layer)

- [x] Schema v1 → v2 migration added `cost_source` on an existing install.
- [x] SEO Research Settings: credentials saved (never echoed), connection test shows balance.
- [x] Wizard in Chromium: content type → keyword/market (markets from provider) → project created → estimate + confirmation → research completed via WP-Cron in ~25 s.
- [x] Competitor allowed by robots.txt read; competitor disallowed by robots.txt not fetched.
- [x] Keyword step: approve all, exclude, add own term, save; state persisted after reload.
- [x] Brief generated; AI-proposed link to a non-existent post dropped; edits and added outline section persisted after reload.
- [x] Projects list shows stage; usage log shows provider-reported research costs.
- [x] AI-only mode (no DataForSEO): fallback market list, only AI steps, data marked unavailable, job advanced by status polling with cron callbacks removed.
- [x] Duplicate research start rejected (409); editor cannot read another user's project (403).
- [x] Bug found and fixed: usage rows were missing `cost_source`.

## Smoke test performed for 0.3.0 (same environment; xAI, DataForSEO and competitor sites mocked at the WordPress HTTP layer)

- [x] Grok provider: key saved (masked), connection test OK, set as default text provider.
- [x] Content generation via the Grok adapter completed in the background (~28 s, 6 parts + FAQs + metadata).
- [x] Link to a supplied internal URL kept; link to an invented URL removed (text kept); no script tags in stored content.
- [x] Approved introduction and locked section unchanged after "Regenerate all"; single-section regeneration works.
- [x] Measured SEO score shown, AI review findings shown separately; search-result preview rendered.
- [x] Draft created (status `draft`) and opened in the block editor with 0 invalid blocks (paragraph, heading, list, list-item, freeform for tables).
- [x] Version history: Generated → Edited → Regenerated parts → Saved as WordPress draft; compare shows added/removed lines (9/1 for "Edited"); restoring "Edited" snapshots "Before restore" first and the compare then shows no differences.
- [x] Optimiser: published source post analysed, 3 recommendations (2 accepted, 1 rejected), revised version with before/after scores, saved as a new draft linked via `_aiscs_source_post`; source post's modified date unchanged.
- [x] Bugs found and fixed: script text left behind after sanitising; disabled draft-format radios still selectable.

## Smoke test performed for 0.3.1 (demo mode; every outbound HTTP request blocked and logged)

- [x] "Use demo mode for AI and research" switched both settings; banner shown on plugin pages.
- [x] Full wizard (service page, "boiler repair london" and "emergency plumber leeds"): research ~16–31 s, keywords (sources: Demo data / Search results / AI suggestion), brief, 6 content parts, measured score 87, AI review, draft created.
- [x] Estimates listed every call as "demo mode, free"; no confirmation required.
- [x] The block log contained only WordPress core update checks — no request from the plugin.

## Smoke test performed for 0.4.0 (Elementor 4.0.8 source running in WordPress; demo mode)

- [x] Elementor detected; Output step enables *Elementor draft*; style and button fields shown.
- [x] Containers on and off: content 20 widgets / designed 26, read-back counts equal; Elementor marks the post `builder`, version 4.0.0, status draft.
- [x] Elementor front-end rendering: expected widget types, button links, no scripts; block theme (Twenty Twenty-Four) shows exactly one H1 after the one-H1 fix.
- [x] Bugs found and fixed: block themes showed two H1s (Elementor's Hide Title selector does not match them); button-link check used a network-resolving validator.
- [ ] Elementor editor UI not exercised (source build lacks compiled editor JS).

## Smoke test performed for 0.5.0 (demo images; Elementor 4.0.8 source; outbound requests blocked)

- [x] AI Providers: default image provider "Demo" saved; Images step shows 2 suggested slots (featured + one after a section).
- [x] Cost check listed both images as "demo mode, free"; generation ran in the background (~5 s); 2 previews, 0 errors.
- [x] Block draft opened in the block editor: 12 paragraphs, 9 headings, 4 lists, 1 core/image — 0 invalid blocks; featured image set.
- [x] Featured attachment: PNG, alt text and caption from the wizard, attached to the draft.
- [x] Elementor designed draft from the same project: image widget rendered by Elementor's front end.
- [x] Bug found and fixed: the `images` project section was not registered, so image settings were silently not saved; unknown section names now throw (and the test fake checks them too).
- [ ] Real OpenAI / Gemini / xAI image generation with your keys (only the demo adapter ran here; request/response shapes are unit-tested against the SDK definitions).

## Checks performed for 1.0.0 (local WordPress; providers mocked at the HTTP layer)

- [x] Upgrade from 0.5.0 with demo mode selected: migration 3 cleared the demo choices; Dashboard showed "Connect an AI" as to do and New Content showed the setup notice.
- [x] AI Providers: choosing xAI Grok, "Test connection" and "Save settings" → Dashboard step shows "Done · xAI Grok · grok-mock".
- [x] Full wizard (research → brief → content → SEO report → Images step "off" notice → draft) completed.
- [x] Atomic lock against the real database layer: second acquire refused, re-acquire after release, expired lock taken over.
- [x] Unit tests: interrupted step stops after two runs; cancel during a failing step stays cancelled; timed-out POST not retried; edited WordPress draft not overwritten; reasoning headroom per model family.
- [ ] Real runs with your own keys (Gemini, OpenAI, Anthropic, xAI, DataForSEO) on your live site.

## Checks performed for 1.5.0 (local WordPress; AI and competitor sites mocked at the HTTP layer)

- Settings: business profile, services list, house style and fact policy are saved and sanitised. A service without a name and a non-http contact address are dropped. The dashboard shows the new setup step.
- Research without DataForSEO: steps `ai_competitors → competitors → analysis → plan`.
  - User-listed pages come first, then AI suggestions; duplicates and this site are dropped, and plain words are not turned into addresses.
  - A suggested page that returns 404 is shown as "Page did not load (error 404)".
  - Page keywords are kept only for downloaded pages, with no metrics.
- Brief: an FAQ-style H2 from the AI was removed and its question moved to the FAQ list; the length set on the keyword step overrode the AI's range.
- Content: steps `… faqs → edit → meta → finalize`. Each part request carries the British English, banned-word and advice rules, the business in the shared context, `advice_line` (allowed only in the conclusion), the missing must-use keywords, and the main-keyword uses for that part (at most 2 per part).
- Sources:
  - A gov.uk source that could not be reached was marked "Page not found", and its link was removed with the text kept.
  - A blog source was marked "Not a trusted site".
  - Both appear in "Claims to verify".
- Final edit and output:
  - The final edit was applied to editable parts only, and measured checks were sent to it.
  - An em dash from the writer was replaced.
  - Must-use keywords were bolded once each.
  - Three title options were stored.
- SEO report: must-use coverage table, primary-use range, words-to-avoid and em-dash checks.
- Security: saving content with a forged "verified" source and an unknown link kept neither; the business's own service link was kept.
- Browser: Brand and Writing Style screen (add a service, save), must-use keywords card, AI competitor labels, title options with character counts and "Use", official sources table, coverage table.

## Not covered automatically (manual, needs real accounts — may incur small costs)

- [ ] Each provider with a real key: Test connection lists models; invalid key shows "rejected".
- [ ] Real DataForSEO account (live and sandbox): research results, costs and error messages.
- [ ] Real AI structured output from each provider (incl. xAI Grok) for the research, brief, content, evaluation and optimiser schemas.
- [ ] Draft output with the classic editor plugin active; posts with very long content.
- [ ] Front-end meta/JSON-LD output alongside themes that already print a title tag or schema.
- [x] 1.1.0 local smoke: section regenerated with instructions (sent as `user_instructions` with the priority rule), FAQ added and only its answer written, all answers regenerated with instructions, FAQPage JSON-LD on the published post, nothing FAQ-related output with the FAQ section off.
- [ ] FAQPage output checked with Google's Rich Results Test on a live site.
- [x] 1.2.0 local smoke: all three blog templates saved through Elementor 4.0.8 (every widget stored), one H1, TOC anchors resolve, tip/note/example callouts, related posts and FAQ schema present; classic theme uses Elementor Full Width; Twenty Twenty-Four keeps its title; no horizontal scroll from the templates at 390 px.
- [ ] Blog templates opened and edited in the Elementor editor on a real site (Hello Elementor / Astra).
- [x] 1.3.0 local smoke (mocked DataForSEO and Openverse responses): research job ran all 8 steps incl. competitor_keywords (one ranked_keywords call per competitor page); Keywords step lists them with source links and adds them to the plan; Research step shows H1–H3 per competitor with rank and source; Brief "Add to outline" works; Openverse search drops non-CC0 results, "Use" imports with credit caption; placeholder created; stock key settings shown; migration raised competitor pages 5 → 10.
- [ ] Real DataForSEO ranked_keywords response, and real Openverse/Pexels/Pixabay searches and downloads, on a live site.
- [x] 1.4.0 local smoke (mocked xAI responses): Balanced mode sent `reasoning.effort` medium on the main model for parts and FAQs and low on the quick model for metadata; every request started with the identical "Article context" prefix; cost mode and quick-model fields shown; Claude list prices added.
- [ ] Real Claude run: `cache_read_input_tokens` > 0 from the second article part onwards; costs shown on Usage and Costs.
- [ ] Competitor reading against real websites (only mocked pages were fetched).
- [ ] Action Scheduler path (WooCommerce installed); `DISABLE_WP_CRON` with polling fallback in a browser.
- [ ] Real rate-limit (429) and quota-exhausted responses.
- [ ] MySQL/MariaDB activation (smoke test used SQLite).
- [ ] Multisite network uninstall.
- [ ] Current WordPress releases (7.x) — the sandbox could only obtain a 6.7 pre-release build.
- [ ] PHP 8.2 runtime (static compatibility checked; tests ran on 8.3).
- [ ] Opening a generated draft in the Elementor editor on a real site (Elementor from WordPress.org), with a classic theme such as Hello Elementor and with Elementor Pro installed.
- [ ] Screen reader pass on the admin pages; keyboard-only navigation of the confirm dialog.
- [ ] `DISABLE_WP_CRON` site: prune event runs from system cron.

## Per-release manual checklist

1.5.0, with a real AI account:
- [ ] Fill in Brand and Writing Style, then generate one article. Check the introduction has two paragraphs, there is one worked example, one FAQ list, at most one "speak to a professional" line, and a call to action with your service link.
- [ ] Open every row in "Official sources used" and check the page says what the article says.
- [ ] Check the must-use keyword table in the SEO report and the final edit's list of changes.
- [ ] Without DataForSEO: check the AI-suggested competitor pages are real and relevant.


1. Fresh install from ZIP on a clean site; activate; no notices in `debug.log`.
2. Configure one provider; Test connection; pick a model; set default provider.
3. Change `AISCS_ENCRYPTION_KEY`; dashboard warns keys are unreadable; re-enter key fixes it.
4. Log in as editor: only Dashboard is available.
5. Deactivate/reactivate: settings and keys retained.
6. Delete plugin with and without "Delete plugin data"; verify retention behaviour.
