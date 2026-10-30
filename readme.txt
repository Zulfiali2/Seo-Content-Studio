=== AI SEO Content Studio ===
Contributors: aqfdigital
Tags: seo, ai, content, elementor, keyword research
Requires at least: 6.6
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.9.0-beta.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO research, AI content generation, content optimisation and Elementor Free page building.

== Description ==

AI SEO Content Studio includes:

* Bring-your-own-key configuration for OpenAI, Anthropic Claude, Google Gemini, xAI Grok and OpenAI-compatible endpoints.
* Keys are encrypted at rest and never sent to the browser.
* Connection tests that list the models available to your key.
* Usage tracking, hourly limits and estimated costs from prices you enter.
* Diagnostics for Elementor, SEO plugins and background processing.
* SEO research with DataForSEO: search results, keyword metrics, related keywords and competitor page outlines (robots.txt respected).
* AI keyword suggestions clearly labelled, an editable keyword plan, and an AI-generated, fully editable content brief and outline.

* Section-by-section content generation with edit, approve, lock and regenerate; saved only as WordPress drafts, never published.
* SEO report with measured checks and an optional AI review, search-result preview, and version history with compare and restore.
* Optimise existing posts and pages: recommendations you accept or reject, saved as a new draft without changing the original.

* Elementor drafts built from free Elementor widgets (content-only or designed layout). Elementor Pro is not needed.

* AI images (OpenAI, Google Gemini, xAI Grok) saved to your Media Library with alt text and captions, placed in the draft.
* AI Website Studio: analyze your website's design and save brand profiles; clone Elementor pages with new SEO content; build native, editable Elementor pages (free widgets only).

Elementor Pro, Yoast SEO and Rank Math are not required.

= External services =

When you test a connection, run research or generate content, this plugin sends requests to the AI provider you configured, using your API key:

* OpenAI — https://api.openai.com — terms: https://openai.com/policies/
* Anthropic — https://api.anthropic.com — terms: https://www.anthropic.com/legal
* Google Gemini — https://generativelanguage.googleapis.com — terms: https://ai.google.dev/gemini-api/terms
* xAI Grok — https://api.x.ai — terms: https://x.ai/legal
* A custom endpoint URL that you enter.
* DataForSEO — https://api.dataforseo.com (or the sandbox) — terms: https://dataforseo.com/terms-of-service
* Competitor web pages listed in search results, fetched only when you run research, after checking their robots.txt.

Nothing is sent until you add a key and run an action.

== Installation ==

1. Upload the ZIP in Plugins → Add New → Upload Plugin, then activate.
2. Optional but recommended: add `define( 'AISCS_ENCRYPTION_KEY', 'a-long-random-string' );` to wp-config.php before saving keys.
3. Go to AI SEO Studio → AI Providers, save an API key, run "Test connection", choose a text model and a default provider.

== Changelog ==

= 1.9.0-beta.4 =
* AI Website Studio phase 7. Page Tools for studio drafts: device preview (desktop, tablet, mobile, one section), section regeneration (rewrite, improve SEO, redesign, new images, colours, typography, layout, spacing, responsive) with a preview before you approve, a Design Consistency Checker and an Accessibility Checker (WCAG 2.2 AA based) whose recommendations you apply one by one, full revision history with compare, restore and undo of one section, and a Design Library of reusable sections and components.

= 1.9.0-beta.3 =
* AI Website Studio phase 6: AI Page Generator. Describe a page (topic, keywords, intent, audience, location, tone, length, sections, facts) and the AI writes it as a native Elementor draft: sections, copy, SEO title and description, image prompts and internal link suggestions. You review it first, approve each internal link, and AI images are only placed in the draft after you choose them. The layout is fitted to the content (columns by card count and text length, one H1, FAQ in one column), and numbers or placeholders the AI wrote are listed for you to check.

= 1.9.0-beta.2 =
* AI Website Studio phase 5: Image to Design. Upload a screenshot or design export; an AI vision model (OpenAI, Claude, Gemini or Grok) reads its layout and colours, you review the structure, then a native Elementor draft is built with new SEO content or the screenshot's own text. The screenshot is deleted right after it is read.

= 1.9.0-beta.1 =
* AI Website Studio phase 4: HTML/CSS to Elementor. Paste or upload a design; it is rebuilt with native, editable Elementor widgets (responsive columns, sizes and padding from your media queries), with a conversion report. Scripts are never run.

= 1.8.0 =
* AI Website Studio phases 2 and 3: clone any Elementor page into a new draft with new SEO content (the original is never changed), and a native Elementor engine that builds editable pages from free widgets with your brand profile, global colours and responsive settings.

= 1.7.0 =
* New AI Website Studio menu (phase 1): Website Brand Analyzer reads your Elementor Site Settings, page designs, theme.json and stylesheets and saves editable brand profiles (colours, fonts, type scale, buttons, cards, spacing). No AI request is made. Credential key rotation with AISCS_ENCRYPTION_KEY_PREVIOUS.

= 1.6.8 =
* Security: https required for remote custom endpoints, image pixel limit, token-checked locks. Yoast SEO and Rank Math fields filled from the draft. Warning when another page already targets the same keyword.

= 1.6.7 =
* Chat-style writing: no invented personal experience, no H2 repeating the title, FAQs that do not repeat sections, and the business's point of view.

= 1.6.6 =
* Chat-style writing: one short, plain brief with your keywords, brand, outline and notes, the AI's own knowledge allowed (figures listed to verify), the article back as HTML. Fewer, cheaper requests and a more natural article.

= 1.6.5 =
* The SEO writes the outline: the Brief step opens a ready-to-fill outline editor with no AI request. "Regenerate brief" still uses the AI when wanted.

= 1.6.4 =
* Better writing quality: content in the market's language, worked examples only where they fit, less rigid structure, smarter dash clean-up, room for the final edit of long articles, a stronger model for research analysis and a shorter banned-words list.

= 1.6.3 =
* Fixes background steps stopping after two runs, long one-go articles being cut off, and missing permissions on multisite sites. Stronger checks for outbound page requests, no silent repeat of possibly billed requests, and image library actions now need upload permission.

= 1.6.2 =
* A direct answer under every heading, keyword-aware headings, the main keyword in both meta title and description, and a new "Main keyword in a heading" check.

= 1.6.1 =
* New reading level for UK readers: UK plain English (GOV.UK style, reading age 9). The other levels now show their UK reading ages.

= 1.6.0 =
* The first draft is written in one go: one AI request plans and writes the introduction, every section, the FAQ answers and the conclusion as one connected piece. Any single section can still be regenerated with your own instructions.

= 1.5.0 =
* Brand and Writing Style: business profile, services and author, house style, facts from trusted official sources with checked links, must-use keywords with bolding and a coverage counter, a final edit of the whole article, three title and description options, and AI competitor research without DataForSEO.

= 1.4.0 =
* Lower AI costs for every provider: cost mode (Economy / Balanced / Best quality), optional cheaper model for quick tasks, cache-friendly prompts, cached-token cost tracking.

= 1.3.0 =
* Competitor ranking keywords (DataForSEO) and top-10 competitor headings with source links; free stock photos (Openverse, Pexels, Pixabay) and placeholders per image; writing rules updated to Google's current people-first guidance.

= 1.2.1 =
* Blog templates use the full page width even when the theme narrows post content, and keep normal sentence case.

= 1.2.0 =
* Blog post templates for Elementor drafts: Magazine with sidebar, Centered article and Bold hero, with an accent colour picker.

= 1.1.0 =
* FAQ schema (FAQPage), add/remove FAQs and switch the FAQ section on or off, and optional instructions when regenerating a section.

= 1.0.1 =
* Works better on free plans: waits out per-minute limits, hides unusable models, clearer quota messages.

= 1.0.0 =
* Production release: simpler setup, demo mode removed, reliability and safety fixes. See CHANGELOG.md.

= 0.5.0 =
* Phase 5: AI images. See CHANGELOG.md.

= 0.4.0 =
* Phase 4: Elementor Free layouts. See CHANGELOG.md.

= 0.3.1 =
* Demo mode: try the full workflow free, with no API key and no external requests.

= 0.3.0 =
* xAI Grok provider. Phase 3: content generation, SEO evaluator, optimiser, drafts and history. See CHANGELOG.md.

= 0.2.0 =
* Phase 2: SEO research, keyword planning and content brief. See CHANGELOG.md.

= 0.1.0 =
* Phase 1 foundation.
