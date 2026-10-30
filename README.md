# AI SEO Content Studio (WordPress plugin)

Research a keyword, plan the content, write it with AI, check it for SEO, add AI images and save it
as a WordPress or Elementor draft — using your own AI keys. Nothing is ever published automatically.

**Version 1.6.8** · WordPress 6.6+ · PHP 8.2+ · Elementor (free) optional · no SEO plugin required

## What it does

* **Write new content** — a guided wizard: content type → keyword and country → research → keywords →
  brief and outline → output (WordPress or Elementor) → content → SEO report → images → preview → draft.
* **Improve existing pages** — analyse a published post or page, accept or reject each
  recommendation, and get a revised version as a new draft. The original is never changed.
* **Research** with real search data from DataForSEO (optional): the top 10 results, the headings
  (H1–H3) of each competitor page with its source link, and the keywords each competitor page ranks
  for (volume, position, difficulty, estimated traffic). Without DataForSEO the AI suggests keywords
  and up to 5 likely competitor pages (plus any you list); the real pages are read for their
  headings and the keywords they target, clearly labelled as AI suggestions with no volumes.
* **Your brand and house style** — business name, services, contact page and author; British or
  American English, reading level, sentence length, words to avoid, no em dashes, one
  "speak to a professional" line at most; a two-paragraph intro, a worked example and a call to action.
* **Facts with sources** — official facts (for example from gov.uk) with a link to the page, checked
  by the plugin and listed for you to verify. **Must-use keywords** with optional bolding and a
  coverage table, and a **final edit** that reads the whole article to remove repeats.
* **AI providers** — OpenAI, Anthropic Claude, Google Gemini, xAI Grok, or any OpenAI-compatible
  endpoint. Images with OpenAI, Gemini or Grok, or free stock photos (Openverse public-domain/CC0;
  Pexels and Pixabay royalty-free with a free key) and placeholders, chosen per image.
* **Elementor blog templates** built from free Elementor widgets: Magazine with sidebar, Centered
  article or Bold hero (plus simple styles), in your accent colour. Your theme keeps its header and footer.
* **Safe by design** — drafts only, edits are never overwritten without asking, API keys are
  encrypted, every paid request shows an estimate first, and hourly limits protect your budget.

## Install

1. Download `releases/ai-seo-content-studio-1.6.8.zip`.
2. WordPress → **Plugins → Add New → Upload Plugin** → choose the zip → **Install** → **Activate**.
3. *(Recommended)* Add this line to `wp-config.php` (any long random text) so saved API keys survive
   a change of WordPress security keys:
   `define( 'AISCS_ENCRYPTION_KEY', 'put-64-or-more-random-characters-here' );`

## Set up (5 minutes)

Open **AI SEO Studio → Dashboard**: the *Setup* list shows what is done and links to each screen.

1. **AI Providers** → open a provider (for example *Google Gemini*) → paste your API key →
   **Save key and test** → choose the **Model for writing** (and a **Model for images** if you want
   images) → at the top choose **Write content with** (and **Create images with**) → **Save settings**.
   Good starting models: `gemini-2.5-flash` for writing and `gemini-2.5-flash-image` for images.
   With Claude: `claude-sonnet-5-5` for writing and `claude-haiku-5-5` as the **Model for quick tasks**.
   Keep **Cost mode** on *Balanced* (see "Keeping costs down" below).
2. *(Recommended)* **Brand and Writing Style** → your business name, services (with page links),
   contact page and author; check the writing style and the trusted official sites.
3. *(Optional, recommended)* **SEO Research Settings** → DataForSEO API login and password →
   **Test connection**.
4. *(Optional)* **Usage and Costs** → enter your providers' current prices so estimates show amounts,
   and adjust the hourly limits.

## Use

1. **Dashboard → Write new content**, pick a content type, enter the keyword and country.
2. Follow the steps. Each billed step shows an estimate first; long steps run in the background
   (you can leave the page and come back via **Projects**).
3. On the **Keywords** step, paste your **must-use keywords** (secondary, semantic, NLP; one per line),
   switch on bolding if you want it, and optionally set how often the exact main keyword is used.
   On the **keyword** step you can set the article length and list competitor pages you know.
4. The first draft is written **in one go** (one AI request for the whole article), so it reads
   as one connected piece. Then review and edit everything: approve or lock the parts you like and regenerate the rest. When you
   click **Regenerate** on a section, a box opens where you can tell the AI what to change (for
   example "make it shorter and add a checklist"); it is used for that regeneration only.
5. **FAQs**: add or remove questions, switch the FAQ section off if you don't want one, and use
   **Write missing answers** for new questions. With **Add FAQ schema** on (Metadata card), the
   published page gets FAQPage structured data for the answered FAQs it shows.
6. Pick one of the three **SEO titles** and **meta descriptions**. Check the **SEO report** and the **claims to verify** and **official sources** lists, then **Save draft** and open it in
   WordPress or Elementor. Publish it yourself when you are happy.

## Keeping costs down

The same cost settings work with every provider, so you can switch providers later without
changing anything else:

* **Cost mode** (AI Providers, top card): *Economy* (short thinking everywhere), *Balanced* — the
  default (medium thinking for writing, short for quick tasks), or *Best quality* (the provider's
  full default). The plugin translates it into each provider's own setting (Claude `effort`,
  OpenAI/Grok `reasoning.effort`, Gemini `thinkingLevel`/`thinkingBudget`).
* **Model for quick tasks** (each provider card, optional): a cheaper model for metadata, the AI SEO
  review and the optimiser analysis. Articles, FAQs and the research analysis (which the brief is
  built on) always use the main model.
* **Prompt caching**: the writing rules and the article context are sent first and identically for
  every section, so they are cached — Claude with explicit cache markers, OpenAI and Gemini
  automatically. Cached input costs a fraction of normal input.
* **Usage and Costs**: "Add Claude list prices (Oct 2026)" fills Anthropic's prices (incl. the cached
  input price) for you to confirm, so estimates and the usage report show real amounts.
* If a provider rejects a cost setting (for example a retired quick model), the request is retried
  once exactly as originally asked, so a step never fails because of a saving.

## Elementor blog templates

On the **Output mode** step choose **Elementor draft**, then pick a layout (each card shows a preview):

| Layout | What you get |
|---|---|
| Magazine with sidebar | Breadcrumbs, category badge, title, author/date/reading time, summary, featured image, table of contents, numbered sections, tip/note/example boxes, check-mark lists, FAQs. Sidebar: author, latest posts, categories, your button and an optional form. Related posts at the end. |
| Centered article | The same article in one reading column, author box, button and related posts below. |
| Bold hero | Full-width coloured header, reading column, closing call-to-action band, related posts. |

Pick an accent colour, and optionally a button and a newsletter/form shortcode (from your form plugin).
Breadcrumbs, category, dates, reading time, author box, latest/related posts and categories are
filled in from your site automatically and stay current. For the best result give the post a
category and featured image, and add an author bio under **Users → Profile**.

With a classic theme (Hello Elementor, Astra, GeneratePress, Kadence…) the post uses Elementor's
"Full Width" layout: your theme header and footer stay, the design replaces the theme's title area.
Block themes keep their own title and featured image above the design.

## Troubleshooting

| Message | What to do |
|---|---|
| "No AI is ready to write content yet" | AI Providers: save a key, choose a model, select it under *Write content with*, Save settings. |
| "The AI ran out of room before finishing its answer" | Try again, use a faster model (Flash / mini), or raise *Max output tokens* under AI Providers → Advanced. |
| "per-minute limit was reached. Waiting…" | Normal on free plans: the job continues by itself after about a minute. |
| "no remaining quota for this model" | The model has no allowance on your plan (free plans have none for image models). Enable billing or pick another model. |
| "… did not respond in time" | Try again later or raise *Request timeout* under AI Providers → Advanced. |
| "This step did not finish" | Your server stopped the step (time or memory limit). Try again; ask your host to raise the PHP time limit if it repeats. |
| Steps never start | WP-Cron may be disabled on your host: keep the project page open (it moves the work along) or set up a server cron job. |
| "The saved key can no longer be read" | WordPress security keys changed. Enter the key again (and set `AISCS_ENCRYPTION_KEY`). |
| Outbound requests blocked | If `WP_HTTP_BLOCK_EXTERNAL` is set, add the provider hosts to `WP_ACCESSIBLE_HOSTS` (see `docs/PROVIDERS.md`). |

## For developers

```bash
composer install && npm ci && npm run build
composer test        # PHPUnit (mocked HTTP, no paid API calls)
composer lint        # PHPCS (WordPress standards)
npm run lint:js      # ESLint
bin/build-zip.sh     # dist/ai-seo-content-studio-<version>.zip
```

Documentation: [technical specification](docs/TECHNICAL-SPECIFICATION.md) ·
[providers](docs/PROVIDERS.md) · [security](docs/SECURITY.md) · [costs](docs/COST-CONTROLS.md) ·
[Elementor](docs/ELEMENTOR.md) · [QA checklist](docs/QA-CHECKLIST.md) · [changelog](CHANGELOG.md)

## License

GPL-2.0-or-later.
