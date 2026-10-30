# AI Website Studio — user guide

AI Website Studio builds and improves Elementor pages with AI. It always works on **new drafts**: it never publishes anything and never changes your existing pages. Every page it makes uses native, editable Elementor widgets.

## Before you start

1. **Elementor** 3.16 or newer must be active. Elementor Pro is optional; the studio uses free widgets only.
2. Under **AI SEO Content Studio → AI Providers**, add an API key and choose:
   - a **default text provider** (OpenAI, Anthropic Claude, Google Gemini or xAI Grok) for writing and page analysis;
   - a **default image provider** for AI images (optional).
3. Under **Settings → Business**, enter your business facts. The AI uses only facts you give it.
4. Optional: build a brand profile with **Website Brand Analyzer**, so new pages match your site.

Users need the **Generate content** capability (administrators and editors have it) and must be allowed to edit pages.

## Screens

| Screen | What it does |
|---|---|
| Dashboard | Elementor status, brand profiles, links to every module. |
| AI Page Generator | Writes a new page from a brief. |
| Website Brand Analyzer | Reads colours, fonts, buttons, cards and spacing from your pages and saves them as a brand profile. |
| Brand Profiles | View and edit profiles. |
| Clone Existing Page | Duplicates a page's design with new content. |
| HTML to Elementor | Converts HTML/CSS into native widgets. |
| Image to Design | Rebuilds a page from a screenshot. |
| Generated Pages | Every page the studio made, with links to Elementor, the preview and Page Tools. |
| Page Tools | Preview, regenerate sections, check consistency and accessibility, and manage revisions. |
| Design Consistency Checker | Page Tools, opened on the consistency tab. |
| Design Library | Reusable sections and components. |
| Revisions and History | Page Tools, opened on the revisions tab. |

## AI Page Generator

1. Fill in the **topic** and **primary keyword**. Add any of the other fields that help: secondary keywords, intent, audience, location, tone, length, the sections you want, the number of images, and the button link (`/contact/`, `#form` or a full URL).
2. Put real facts in **Facts and notes**: services, prices, guarantees, testimonials. The AI never invents facts. Missing ones appear as `[placeholders]`.
3. **Generate page** sends one request to your text provider. Then review:
   - the SEO title, description and slug;
   - the sections and their layout on each device;
   - the **fact check**: every sentence with a number, every placeholder and every assumption the AI made. Check these before publishing.
   - the internal link suggestions. Tick the ones you want; nothing is linked otherwise.
4. **Create Elementor draft** builds the page with placeholder images.
5. **Section images**: tick images, edit their prompts if you like, and **Generate**. Each image arrives as a suggestion. **Use this image** puts it into the draft; **Discard** deletes it.

## Clone, HTML and screenshot

- **Clone Existing Page**: choose a page, choose which texts to rewrite, and enter the new topic. The original page is never changed.
- **HTML to Elementor**: paste HTML and CSS (scripts are never run), parse it, review the report, and convert it to a draft.
- **Image to Design**: upload a PNG, JPEG or WebP (up to 10 MB). Review the structure the vision model found, then create the draft with new content or with the screenshot's own text. The screenshot is deleted right after it is read.

## Page Tools

Open it from **Generated Pages → Page tools**, or choose a page at the top.

**Preview.** Shows the draft as Elementor renders it, at desktop (1280 px), tablet (768 px) and mobile (390 px) widths. You can also jump to a single section. Only people who may edit the page can open the preview.

**Sections.** Select a section and choose an action.

| Action | Kind | What changes |
|---|---|---|
| Rewrite content | AI | The section's text. Its structure and images stay the same. |
| Improve SEO | AI | Headings and text, for the keyword and the search intent. |
| Redesign section | AI | Layout, cards and wording. Existing images are reused. |
| Regenerate images | AI | New pictures from your prompt or the alt text. Needs an image provider. |
| Change colours | Style | The section background (light, primary, secondary or dark). Colours come from the brand. |
| Change typography | Style | Heading size and font. |
| Change layout | Style | One column, two halves or a grid, and the number of columns. |
| Adjust spacing | Style | Compact, normal or spacious, for each device. |
| Improve responsive design | Style | Tablet and phone columns, heading sizes and phone spacing. |

The result is a **proposal**. **Preview** shows the whole page with the new section on a temporary page. **Approve** changes only that section. **Discard** leaves the page as it was. Every approval is saved as a revision.

Section regeneration is available for pages built by the AI Page Generator, HTML to Elementor and Image to Design. For cloned pages, you can use all the other tools.

**Design consistency.** Compare the page with a brand profile, with up to five reference pages, or with the styles it was built with. The report rates colours, typography, buttons, cards, spacing, widths, images, headings, patterns and responsive layout as pass, warning or fail, and gives a score. Tick the recommendations you want and apply them. Each one changes only what it names.

**Accessibility.** Finds common problems, using WCAG 2.2 AA as the reference: heading order, missing alt text, empty or vague links and buttons, low colour contrast, small text, form labels, keyboard and video captions. Fixes that need text, such as alt text or a button label, ask for it; alt text is saved in the Media Library. Automated checks find only part of the issues, so test with a keyboard and a screen reader as well.

**Revisions.** The studio saves a snapshot each time it changes a page. Edits you make in Elementor are saved as well, before the studio's next change.
- **Compare with now** shows the sections that were added, removed, moved or changed, and their text.
- **Undo this section** puts back a single section.
- **Restore** brings back the whole page. A restore is itself a revision, so you can undo it.
- 25 revisions are kept per page, and the first one is always kept.

## Design Library

Save a section from **Page Tools → Sections → Save this section to the design library**. In the library you can:
- preview items on a temporary page;
- rename them or change their category;
- duplicate or delete them;
- **insert** them into a studio draft, at the top or the end. Inserted items get new element IDs.

Items are private to the person who saved them; administrators can see all of them.

## Costs and privacy

- Every AI request goes to the provider you chose, with your own key, and is listed under **Usage and Costs**.
- Retried background steps never repeat a billed request.
- Screenshots are deleted after analysis.
- Temporary preview pages are deleted after approve or discard, or after 24 hours.

## Troubleshooting

| Message | What to do |
|---|---|
| "This page was not created by AI Website Studio" | The studio only changes its own drafts. Clone the page instead. |
| "This page has been published" | Published pages are not changed. Clone the page to work on a new draft. |
| "This section is no longer on the page" | The section was removed in Elementor. Restore it from Revisions, or regenerate another section. |
| "Choose a default image provider" | Configure one under AI Providers. |
| "Flexbox Containers … not active" | Turn on Flexbox Containers in Elementor → Settings → Features, or use library items that were saved from legacy-section pages. |
| A step keeps failing | See the job message. Rate limits are retried automatically; other errors show the provider's reason. |
