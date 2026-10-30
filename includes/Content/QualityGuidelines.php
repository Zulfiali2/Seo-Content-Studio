<?php
/**
 * Content quality rules shared by the brief, writing and review prompts.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Based on Google Search Central's current guidance (checked October 2026): "Creating helpful,
 * reliable, people-first content" (incl. main content quality, how raters assess it, and the
 * warning about fabricated authors), "Using generative AI content" (synced with the Search
 * Quality Rater Guidelines on 1 October 2026: add value, fact-check, scaled content abuse),
 * the spam policies, and the guide to generative AI features in Search (original,
 * non-commodity content; structured data must match visible content).
 * These are quality principles, not ranking guarantees.
 */
final class QualityGuidelines {

	/**
	 * Rules for writing.
	 *
	 * @param bool $trusted_facts Facts from listed official sources are allowed (see WritingProfile).
	 * @return string[]
	 */
	public static function writing( bool $trusted_facts = false ): array {
		return array(
			'- People-first: write to fully satisfy the stated audience and search intent, so a reader leaves having what they came for. Never write for search engines first (no padding to reach a length, no keyword stuffing, no summarising other pages without adding anything).',
			'- Lead with the answer: the first 1-2 sentences after every H2 (roughly 40-60 words) directly answer the question or promise of that heading, so they can be quoted on their own as a featured snippet or AI answer; then add depth, steps, examples and decision criteria. Under an <h3> phrased as a question, answer it in the first sentence; other <h3> sub-sections may open naturally. Vary how sections open so they do not all start the same way.',
			'- Original, non-commodity value: go beyond what the top results already say. Add specific, practical detail, clear comparisons, worked examples (clearly framed as illustrative) and the reasoning behind recommendations. Use facts_from_user for the business\'s own experience; never invent experience.',
			'- Trust: no fabricated authors, credentials, reviews, case studies, client names, statistics or quotes. Be accurate and do not overstate. For health, finance, legal or safety topics stay consistent with established expert consensus and be precise about what applies to whom.',
			'- Descriptive, honest headings and wording: no clickbait or exaggeration, no shocking claims.',
			$trusted_facts
				? '- Dates, years and time-sensitive facts only from facts_from_user or an official source listed in "sources". Never add "updated" or freshness claims.'
				: '- Do not add dates, years, "updated" claims or time-sensitive facts unless they are in facts_from_user.',
		);
	}

	/**
	 * Rules for briefs and outlines.
	 *
	 * @return string[]
	 */
	public static function planning(): array {
		return array(
			'- Plan people-first content: the outline must fully cover what the searcher needs for this intent, in the order they need it, answering the main question early.',
			'- Information gain: use competitor outlines and their ranking keywords only to find what to cover and what they miss; plan sections that add something the top results lack (specific steps, comparisons, criteria, examples, the business\'s own experience from the notes). Never mirror a competitor\'s structure or wording.',
			'- Experience and trust: where the notes allow, plan where first-hand experience, evidence or a clear author perspective belongs; never plan invented credentials, reviews or statistics.',
			'- Each section must earn its place; no filler sections written only for keywords or length.',
			'- Answer engines and AI overviews quote short, self-contained answers: plan the main question to be answered in the first two or three sentences, and headings that match how people ask.',
			'- Headings: each H2 and H3 is specific and descriptive, phrased the way people search (questions where it fits). Use the primary keyword in at least one H2 and secondary or must-use keywords in other headings where they read naturally; never force a keyword into every heading.',
		);
	}

	/**
	 * Rules for the AI review.
	 *
	 * @return string[]
	 */
	public static function review(): array {
		return array(
			'- Judge the page as a quality rater would judge main content: does it satisfy the intent, show effort, originality and accuracy, and offer something beyond what is commonly available? Flag commodity or generic passages, unsupported claims, missing first-hand experience where it would be expected, and anything that looks written for search engines first.',
			'- For health, finance, legal or safety topics, flag anything that may conflict with expert consensus or could cause harm.',
			'- Flag invented-looking details (names, numbers, quotes, credentials) and dates or "updated" claims that are not supported.',
		);
	}
}
