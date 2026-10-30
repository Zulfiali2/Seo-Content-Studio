<?php
/**
 * Rule-based on-page SEO checks.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\SEO;

use AiSeoContentStudio\Content\ContentModel;
use AiSeoContentStudio\Content\KeywordTools;
use AiSeoContentStudio\Keywords\KeywordPlan;

defined( 'ABSPATH' ) || exit;

/**
 * Measurable checks only. Results are guidance for people, not a search-engine
 * ranking score: no check requires a keyword density or a universal word count.
 *
 * Status: pass | warn | fail | info. Severity: high | medium | low.
 */
final class DeterministicChecks {

	/** Category weights for the summary score. */
	private const CATEGORIES = array( 'metadata', 'structure', 'keywords', 'readability', 'links_media', 'content' );

	/**
	 * Run all checks.
	 *
	 * @param array<string, mixed> $doc  Document: title, h1, meta_title, meta_description, slug, html, schema_type, content_type, claims (string[]), faq_count, faq_answered.
	 * @param array<string, mixed> $plan Keyword plan.
	 * @param array<string, mixed> $brief Brief (length range, internal links). May be empty.
	 * @param string               $site_host Host of this site (internal link detection).
	 * @return array<string, mixed>
	 */
	public function run( array $doc, array $plan, array $brief, string $site_host ): array {
		$primary = self::norm( (string) ( $plan['primary']['keyword'] ?? '' ) );
		$html    = (string) ( $doc['html'] ?? '' );
		$text    = ContentModel::text( $html );
		$norm    = self::norm( $text );
		$words   = '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) ?: array() );
		$checks  = array();

		$add = static function ( string $id, string $category, string $label, string $status, string $severity, string $message, string $recommendation = '', $value = null ) use ( &$checks ): void {
			$checks[] = array(
				'id'             => $id,
				'category'       => $category,
				'label'          => $label,
				'status'         => $status,
				'severity'       => $severity,
				'message'        => $message,
				'recommendation' => $recommendation,
				'value'          => $value,
			);
		};

		// Metadata.
		$mt  = (string) ( $doc['meta_title'] ?? '' );
		$len = mb_strlen( $mt );
		if ( '' === $mt ) {
			$add( 'meta_title', 'metadata', __( 'SEO title', 'ai-seo-content-studio' ), 'fail', 'high', __( 'No SEO title is set.', 'ai-seo-content-studio' ), __( 'Write a descriptive title of roughly 50–60 characters.', 'ai-seo-content-studio' ) );
		} else {
			$ok = $len >= 30 && $len <= 65;
			/* translators: %d: characters. */
			$add( 'meta_title_length', 'metadata', __( 'SEO title length', 'ai-seo-content-studio' ), $ok ? 'pass' : 'warn', 'low', sprintf( __( '%d characters.', 'ai-seo-content-studio' ), $len ), $ok ? '' : __( 'Titles around 50–60 characters are less likely to be truncated in results.', 'ai-seo-content-studio' ), $len );
			$has = '' !== $primary && str_contains( self::norm( $mt ), $primary );
			$add( 'meta_title_keyword', 'metadata', __( 'Primary keyword in SEO title', 'ai-seo-content-studio' ), $has ? 'pass' : 'warn', 'medium', $has ? __( 'Present.', 'ai-seo-content-studio' ) : __( 'Not found.', 'ai-seo-content-studio' ), $has ? '' : __( 'Include the primary keyword (or a close natural variant) in the title.', 'ai-seo-content-studio' ) );
		}

		$md  = (string) ( $doc['meta_description'] ?? '' );
		$len = mb_strlen( $md );
		if ( '' === $md ) {
			$add( 'meta_description', 'metadata', __( 'Meta description', 'ai-seo-content-studio' ), 'fail', 'medium', __( 'No meta description is set.', 'ai-seo-content-studio' ), __( 'Write an accurate summary of about 140–160 characters.', 'ai-seo-content-studio' ) );
		} else {
			$ok = $len >= 110 && $len <= 165;
			/* translators: %d: characters. */
			$add( 'meta_description_length', 'metadata', __( 'Meta description length', 'ai-seo-content-studio' ), $ok ? 'pass' : 'warn', 'low', sprintf( __( '%d characters.', 'ai-seo-content-studio' ), $len ), $ok ? '' : __( 'Around 140–160 characters is a common display guideline.', 'ai-seo-content-studio' ), $len );
			$has = '' !== $primary && str_contains( self::norm( $md ), $primary );
			$add( 'meta_description_keyword', 'metadata', __( 'Primary keyword in meta description', 'ai-seo-content-studio' ), $has ? 'pass' : 'warn', 'medium', $has ? __( 'Present.', 'ai-seo-content-studio' ) : __( 'Not found.', 'ai-seo-content-studio' ), $has ? '' : __( 'Use the main keyword once, naturally. Search engines often bold matching words in results.', 'ai-seo-content-studio' ) );
		}

		$slug = (string) ( $doc['slug'] ?? '' );
		if ( '' === $slug ) {
			$add( 'slug', 'metadata', __( 'URL slug', 'ai-seo-content-studio' ), 'warn', 'low', __( 'No slug suggested.', 'ai-seo-content-studio' ), __( 'Use a short, readable, hyphenated slug.', 'ai-seo-content-studio' ) );
		} else {
			$tokens  = array_filter( explode( ' ', $primary ), static fn ( $t ) => mb_strlen( $t ) > 2 );
			$matched = array() === $tokens ? 1 : count( array_filter( $tokens, static fn ( $t ) => str_contains( $slug, $t ) ) ) / count( $tokens );
			$ok      = mb_strlen( $slug ) <= 75 && $matched >= 0.5;
			$add( 'slug', 'metadata', __( 'URL slug', 'ai-seo-content-studio' ), $ok ? 'pass' : 'warn', 'low', $slug, $ok ? '' : __( 'Keep the slug short and include the main words of the primary keyword.', 'ai-seo-content-studio' ) );
		}

		// Structure.
		$h1  = (string) ( $doc['h1'] ?? $doc['title'] ?? '' );
		$has = '' !== $primary && str_contains( self::norm( $h1 ), $primary );
		$add( 'h1_keyword', 'structure', __( 'Primary keyword in H1', 'ai-seo-content-studio' ), '' === $h1 ? 'fail' : ( $has ? 'pass' : 'warn' ), 'medium', '' === $h1 ? __( 'No H1/title.', 'ai-seo-content-studio' ) : $h1, $has ? '' : __( 'Make the main heading clearly describe the topic, ideally with the primary keyword.', 'ai-seo-content-studio' ) );

		preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#is', $html, $headings, PREG_SET_ORDER );
		$problems = array();
		$prev     = 1;
		$h1_count = 0;
		foreach ( $headings as $h ) {
			$level = (int) $h[1];
			if ( 1 === $level ) {
				++$h1_count;
			}
			if ( '' === trim( wp_strip_all_tags( $h[2] ) ) ) {
				$problems[] = __( 'empty heading', 'ai-seo-content-studio' );
			}
			if ( $level > $prev + 1 ) {
				/* translators: 1: level, 2: previous level. */
				$problems[] = sprintf( __( 'H%1$d follows H%2$d', 'ai-seo-content-studio' ), $level, $prev );
			}
			$prev = $level;
		}
		if ( $h1_count > 0 ) {
			$problems[] = __( 'extra H1 in the body (the title is the H1)', 'ai-seo-content-studio' );
		}
		$h2 = count( array_filter( $headings, static fn ( $h ) => '2' === $h[1] ) );
		if ( '' !== $primary && array() !== $headings ) {
			$in_heading = count( array_filter( $headings, static fn ( $h ) => str_contains( self::norm( wp_strip_all_tags( $h[2] ) ), $primary ) ) );
			/* translators: %d: number of headings. */
			$add( 'heading_keyword', 'structure', __( 'Main keyword in a heading', 'ai-seo-content-studio' ), $in_heading > 0 ? 'pass' : 'warn', 'low', sprintf( __( 'Used in %d headings.', 'ai-seo-content-studio' ), $in_heading ), $in_heading > 0 ? '' : __( 'Use the main keyword (or a close question form of it) in at least one H2.', 'ai-seo-content-studio' ), $in_heading );
		}
		$add( 'heading_hierarchy', 'structure', __( 'Heading hierarchy', 'ai-seo-content-studio' ), array() === $problems ? 'pass' : 'warn', 'medium', array() === $problems ? __( 'Headings are in order.', 'ai-seo-content-studio' ) : implode( '; ', array_unique( $problems ) ), array() === $problems ? '' : __( 'Use H2 for main sections and H3 inside them, without skipping levels.', 'ai-seo-content-studio' ) );
		/* translators: %d: number of H2 headings. */
		$add( 'section_count', 'structure', __( 'Main sections', 'ai-seo-content-studio' ), $h2 > 0 || $words < 300 ? 'pass' : 'warn', 'low', sprintf( __( '%d H2 sections.', 'ai-seo-content-studio' ), $h2 ), $h2 > 0 || $words < 300 ? '' : __( 'Break longer content into sections with H2 headings.', 'ai-seo-content-studio' ) );

		// Keywords.
		$first = self::norm( implode( ' ', array_slice( preg_split( '/\s+/u', $text ) ?: array(), 0, 100 ) ) );
		$has   = '' !== $primary && str_contains( $first, $primary );
		$add( 'keyword_intro', 'keywords', __( 'Primary keyword early in the content', 'ai-seo-content-studio' ), $has ? 'pass' : 'warn', 'low', $has ? __( 'Used in the first 100 words.', 'ai-seo-content-studio' ) : __( 'Not in the first 100 words.', 'ai-seo-content-studio' ), $has ? '' : __( 'Mention the topic clearly in the opening paragraph.', 'ai-seo-content-studio' ) );

		$count       = '' === $primary ? 0 : substr_count( $norm, $primary );
		$token_count = max( 1, count( explode( ' ', $primary ) ) );
		$share       = $words > 0 ? $count * $token_count / $words * 100 : 0;
		$stuffed     = $words >= 200 && $share > 3.0;
		/* translators: 1: occurrences. */
		$add( 'keyword_repetition', 'keywords', __( 'Keyword repetition', 'ai-seo-content-studio' ), $stuffed ? 'warn' : 'pass', 'medium', sprintf( __( 'Primary keyword appears %1$d times.', 'ai-seo-content-studio' ), $count ), $stuffed ? __( 'The exact phrase is repeated heavily. Use natural variations instead; there is no density target.', 'ai-seo-content-studio' ) : '', $count );

		$terms   = array_merge( KeywordPlan::approved( $plan, 'secondary' ), KeywordPlan::approved( $plan, 'semantic' ), KeywordPlan::approved( $plan, 'entities' ) );
		$missing = array_values( array_filter( $terms, static fn ( $t ) => ! str_contains( $norm, self::norm( $t ) ) ) );
		if ( array() !== $terms ) {
			$covered = count( $terms ) - count( $missing );
			$ratio   = $covered / count( $terms );
			/* translators: 1: covered, 2: total. */
			$add( 'term_coverage', 'keywords', __( 'Approved term coverage', 'ai-seo-content-studio' ), $ratio >= 0.6 ? 'pass' : ( $ratio >= 0.3 ? 'warn' : 'fail' ), 'medium', sprintf( __( '%1$d of %2$d approved terms used.', 'ai-seo-content-studio' ), $covered, count( $terms ) ), array() === $missing ? '' : __( 'Consider covering where natural: ', 'ai-seo-content-studio' ) . implode( ', ', array_slice( $missing, 0, 12 ) ), round( $ratio * 100 ) );
		}

		// Must-use keywords: every one at least once, in the exact wording.
		$required = KeywordPlan::required( $plan );
		if ( array() !== $required ) {
			$rows = array();
			preg_match_all( '#<(strong|b)\b[^>]*>(.*?)</\1>#is', $html, $bold );
			$bold = ContentModel::text( implode( ' | ', $bold[2] ) );
			foreach ( $required as $term ) {
				$rows[] = array(
					'term'  => $term['text'],
					'kind'  => $term['kind'],
					'count' => KeywordTools::count( $text, $term['text'] ),
					'bold'  => KeywordTools::count( $bold, $term['text'] ) > 0,
				);
			}
			$absent = array_values( array_filter( $rows, static fn ( $r ) => 0 === $r['count'] ) );
			/* translators: 1: used, 2: total. */
			$add( 'required_terms', 'keywords', __( 'Must-use keywords', 'ai-seo-content-studio' ), array() === $absent ? 'pass' : 'fail', 'high', sprintf( __( '%1$d of %2$d must-use keywords used.', 'ai-seo-content-studio' ), count( $rows ) - count( $absent ), count( $rows ) ), array() === $absent ? '' : __( 'Missing: ', 'ai-seo-content-studio' ) . implode( ', ', array_column( $absent, 'term' ) ), $rows );
		}

		$uses = (array) ( $plan['primary_uses'] ?? array() );
		if ( (int) ( $uses['max'] ?? 0 ) > 0 && '' !== $primary ) {
			$used = KeywordTools::count( $h1 . ' . ' . $text, $primary );
			$ok   = $used >= (int) $uses['min'] && $used <= (int) $uses['max'];
			/* translators: 1: uses, 2: min, 3: max. */
			$add( 'primary_uses', 'keywords', __( 'Exact primary keyword uses', 'ai-seo-content-studio' ), $ok ? 'pass' : 'warn', 'medium', sprintf( __( '%1$d uses including the H1 (your target: %2$d–%3$d).', 'ai-seo-content-studio' ), $used, (int) $uses['min'], (int) $uses['max'] ), $ok ? '' : __( 'Regenerate with the final edit on, or adjust a few sentences by hand.', 'ai-seo-content-studio' ), $used );
		}

		// Writing style from Brand and Writing Style.
		if ( isset( $doc['banned_words'] ) ) {
			$found = array_values( array_filter( (array) $doc['banned_words'], static fn ( $w ) => KeywordTools::count( $text, (string) $w ) > 0 ) );
			/* translators: %s: words. */
			$add( 'banned_words', 'readability', __( 'Words to avoid', 'ai-seo-content-studio' ), array() === $found ? 'pass' : 'warn', 'low', array() === $found ? __( 'None found.', 'ai-seo-content-studio' ) : sprintf( __( 'Found: %s', 'ai-seo-content-studio' ), implode( ', ', $found ) ), array() === $found ? '' : __( 'Replace them with plain words.', 'ai-seo-content-studio' ), $found );
		}
		if ( ! empty( $doc['no_em_dashes'] ) ) {
			$dashes = (int) preg_match_all( '/—|\s–\s/u', $text );
			/* translators: %d: count. */
			$add( 'dashes', 'readability', __( 'Em dashes', 'ai-seo-content-studio' ), 0 === $dashes ? 'pass' : 'warn', 'low', sprintf( __( '%d found.', 'ai-seo-content-studio' ), $dashes ), 0 === $dashes ? '' : __( 'Use commas or full stops instead.', 'ai-seo-content-studio' ), $dashes );
		}

		// Readability (approximate, English).
		$sentences = array_filter( preg_split( '/(?<=[.!?])\s+/u', $text ) ?: array(), static fn ( $s ) => '' !== trim( $s ) );
		$avg       = count( $sentences ) > 0 ? $words / count( $sentences ) : 0;
		/* translators: %s: words per sentence. */
		$add( 'sentence_length', 'readability', __( 'Average sentence length', 'ai-seo-content-studio' ), $avg <= 22 ? 'pass' : ( $avg <= 28 ? 'warn' : 'fail' ), 'low', sprintf( __( '%s words per sentence.', 'ai-seo-content-studio' ), number_format_i18n( $avg, 1 ) ), $avg <= 22 ? '' : __( 'Shorter sentences are easier to read on screen.', 'ai-seo-content-studio' ), round( $avg, 1 ) );

		$flesch = self::flesch( $text, max( 1, count( $sentences ) ), max( 1, $words ) );
		/* translators: %d: score. */
		$add( 'reading_ease', 'readability', __( 'Reading ease (approximate)', 'ai-seo-content-studio' ), $flesch >= 50 ? 'pass' : ( $flesch >= 30 ? 'info' : 'warn' ), 'low', sprintf( __( 'Flesch reading ease ≈ %d (higher is easier). Approximation for English.', 'ai-seo-content-studio' ), $flesch ), $flesch >= 50 ? '' : __( 'Prefer plain words and shorter sentences unless the audience is specialist.', 'ai-seo-content-studio' ), $flesch );

		preg_match_all( '#<p[^>]*>(.*?)</p>#is', $html, $paras );
		$long = count( array_filter( $paras[1], static fn ( $p ) => ContentModel::wordCount( $p ) > 120 ) );
		/* translators: %d: count. */
		$add( 'paragraph_length', 'readability', __( 'Long paragraphs', 'ai-seo-content-studio' ), 0 === $long ? 'pass' : 'warn', 'low', sprintf( __( '%d paragraphs over 120 words.', 'ai-seo-content-studio' ), $long ), 0 === $long ? '' : __( 'Split long paragraphs.', 'ai-seo-content-studio' ), $long );

		// Links and media.
		preg_match_all( '#<a\s[^>]*href="([^"]+)"#i', $html, $links );
		$internal = count( array_filter( $links[1], static fn ( $u ) => (string) wp_parse_url( $u, PHP_URL_HOST ) === $site_host ) );
		$suggest  = count( (array) ( $brief['internal_links'] ?? array() ) );
		/* translators: %d: count. */
		$add( 'internal_links', 'links_media', __( 'Internal links', 'ai-seo-content-studio' ), $internal > 0 ? 'pass' : ( $suggest > 0 ? 'warn' : 'info' ), 'low', sprintf( __( '%d internal links.', 'ai-seo-content-studio' ), $internal ), $internal > 0 ? '' : ( $suggest > 0 ? __( 'The brief suggests related pages to link to.', 'ai-seo-content-studio' ) : __( 'Link to related pages on your site where helpful.', 'ai-seo-content-studio' ) ), $internal );

		preg_match_all( '#<img\b[^>]*>#i', $html, $imgs );
		$no_alt = count( array_filter( $imgs[0], static fn ( $i ) => ! preg_match( '#\balt="[^"]+"#i', $i ) ) );
		if ( count( $imgs[0] ) > 0 ) {
			/* translators: 1: without alt, 2: total. */
			$add( 'image_alt', 'links_media', __( 'Image alt text', 'ai-seo-content-studio' ), 0 === $no_alt ? 'pass' : 'fail', 'medium', sprintf( __( '%1$d of %2$d images lack alt text.', 'ai-seo-content-studio' ), $no_alt, count( $imgs[0] ) ), 0 === $no_alt ? '' : __( 'Describe each image for screen readers.', 'ai-seo-content-studio' ) );
		} else {
			$add( 'image_alt', 'links_media', __( 'Images', 'ai-seo-content-studio' ), 'info', 'low', __( 'No images in the content yet.', 'ai-seo-content-studio' ), __( 'Add images in the Images step or in the editor, and give each one alt text.', 'ai-seo-content-studio' ) );
		}

		// Content.
		$min = (int) ( $brief['length']['min'] ?? 0 );
		$max = (int) ( $brief['length']['max'] ?? 0 );
		$in  = 0 === $min || ( $words >= $min * 0.85 && ( 0 === $max || $words <= $max * 1.2 ) );
		/* translators: 1: words, 2: min, 3: max. */
		$add( 'length', 'content', __( 'Length vs. brief', 'ai-seo-content-studio' ), $in ? 'pass' : 'info', 'low', 0 === $min ? sprintf( __( '%1$d words.', 'ai-seo-content-studio' ), $words ) : sprintf( __( '%1$d words (brief: %2$d–%3$d).', 'ai-seo-content-studio' ), $words, $min, $max ), $in ? '' : __( 'Length is guidance from your brief, not a ranking rule. Cover the topic fully without padding.', 'ai-seo-content-studio' ), $words );

		$dupes = self::duplicateSentences( $sentences );
		/* translators: %d: count. */
		$add( 'duplicate_sentences', 'content', __( 'Repeated sentences', 'ai-seo-content-studio' ), 0 === $dupes ? 'pass' : 'warn', 'medium', sprintf( __( '%d sentences appear more than once.', 'ai-seo-content-studio' ), $dupes ), 0 === $dupes ? '' : __( 'Remove repeated sentences.', 'ai-seo-content-studio' ), $dupes );

		if ( isset( $doc['faq_count'] ) && (int) $doc['faq_count'] > 0 ) {
			$answered = (int) ( $doc['faq_answered'] ?? 0 );
			/* translators: 1: answered, 2: total. */
			$add( 'faqs', 'content', __( 'FAQs answered', 'ai-seo-content-studio' ), $answered === (int) $doc['faq_count'] ? 'pass' : 'warn', 'low', sprintf( __( '%1$d of %2$d FAQs have answers.', 'ai-seo-content-studio' ), $answered, (int) $doc['faq_count'] ) );
		}

		if ( isset( $doc['duplicate_title_ids'] ) && array() !== $doc['duplicate_title_ids'] ) {
			$add( 'duplicate_title', 'content', __( 'Duplicate title on this site', 'ai-seo-content-studio' ), 'warn', 'medium', __( 'Another published page uses the same title.', 'ai-seo-content-studio' ), __( 'Make the title distinct to avoid competing with your own page.', 'ai-seo-content-studio' ), $doc['duplicate_title_ids'] );
		}

		$same = (array) ( $doc['same_keyword_pages'] ?? array() );
		if ( array() !== $same ) {
			/* translators: %d: number of pages. */
			$add( 'keyword_cannibalization', 'content', __( 'Other pages target this keyword', 'ai-seo-content-studio' ), 'warn', 'high', sprintf( _n( '%d published page on this site already targets the main keyword.', '%d published pages on this site already target the main keyword.', count( $same ), 'ai-seo-content-studio' ), count( $same ) ), __( 'Pages aimed at the same search compete with each other. Update the existing page instead, give this one a clearly different angle and keyword, or redirect the old page to this one when you publish.', 'ai-seo-content-studio' ), $same );
		}

		$claims = (array) ( $doc['claims'] ?? array() );
		/* translators: %d: count. */
		$add( 'claims', 'content', __( 'Claims to verify', 'ai-seo-content-studio' ), array() === $claims ? 'pass' : 'info', 'medium', array() === $claims ? __( 'None flagged by the writer.', 'ai-seo-content-studio' ) : sprintf( __( '%d claims flagged for verification.', 'ai-seo-content-studio' ), count( $claims ) ), array() === $claims ? '' : __( 'Check each flagged claim against a reliable source before publishing.', 'ai-seo-content-studio' ), $claims );

		$schema   = (string) ( $doc['schema_type'] ?? '' );
		$expected = array(
			'blog_post'             => array( 'BlogPosting', 'Article' ),
			'informational_article' => array( 'Article', 'BlogPosting' ),
			'service_page'          => array( 'Service', 'WebPage' ),
			'local_seo_page'        => array( 'Service', 'WebPage' ),
			'landing_page'          => array( 'WebPage', 'Service', 'Product' ),
			'product_description'   => array( 'Product', 'WebPage' ),
		)[ (string) ( $doc['content_type'] ?? '' ) ] ?? array();
		if ( '' === $schema ) {
			$add( 'schema', 'metadata', __( 'Structured data type', 'ai-seo-content-studio' ), 'info', 'low', __( 'No schema type chosen.', 'ai-seo-content-studio' ), __( 'Choose the type that matches the page.', 'ai-seo-content-studio' ) );
		} else {
			$ok = array() === $expected || in_array( $schema, $expected, true );
			$add( 'schema', 'metadata', __( 'Structured data type', 'ai-seo-content-studio' ), $ok ? 'pass' : 'warn', 'low', $schema, $ok ? '' : __( 'This type is unusual for the content type; check it describes the page.', 'ai-seo-content-studio' ) );
		}

		return array(
			'checks'     => $checks,
			'scores'     => self::scores( $checks ),
			'word_count' => $words,
		);
	}

	/**
	 * Score per category (0–100) from check statuses.
	 *
	 * @param array<int, array<string, mixed>> $checks Checks.
	 * @return array<string, int|null>
	 */
	public static function scores( array $checks ): array {
		$points = array(
			'pass' => 1.0,
			'info' => 1.0,
			'warn' => 0.5,
			'fail' => 0.0,
		);
		$weight = array(
			'high'   => 3,
			'medium' => 2,
			'low'    => 1,
		);
		$out    = array();
		foreach ( self::CATEGORIES as $category ) {
			$total = 0;
			$got   = 0.0;
			foreach ( $checks as $check ) {
				if ( $check['category'] === $category ) {
					$w      = $weight[ $check['severity'] ] ?? 1;
					$total += $w;
					$got   += $w * ( $points[ $check['status'] ] ?? 0 );
				}
			}
			$out[ $category ] = $total > 0 ? (int) round( $got / $total * 100 ) : null;
		}
		$valid          = array_filter( $out, static fn ( $v ) => null !== $v );
		$out['overall'] = array() === $valid ? null : (int) round( array_sum( $valid ) / count( $valid ) );
		return $out;
	}

	/**
	 * Lowercase, collapse whitespace and punctuation for phrase matching.
	 *
	 * @param string $text Text.
	 */
	public static function norm( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower( $text ) ) ) );
	}

	/**
	 * Approximate Flesch reading ease.
	 *
	 * @param string $text      Text.
	 * @param int    $sentences Sentence count.
	 * @param int    $words     Word count.
	 */
	private static function flesch( string $text, int $sentences, int $words ): int {
		$syllables = 0;
		foreach ( preg_split( '/\s+/u', mb_strtolower( $text ) ) ?: array() as $word ) {
			$word = (string) preg_replace( '/[^a-z]/', '', $word );
			if ( '' === $word ) {
				continue;
			}
			$word       = (string) preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
			$count      = preg_match_all( '/[aeiouy]{1,2}/', $word );
			$syllables += max( 1, (int) $count );
		}
		$score = 206.835 - 1.015 * ( $words / $sentences ) - 84.6 * ( $syllables / $words );
		return (int) max( 0, min( 100, round( $score ) ) );
	}

	/**
	 * Count sentences (8+ words) that occur more than once.
	 *
	 * @param string[] $sentences Sentences.
	 */
	private static function duplicateSentences( array $sentences ): int {
		$seen  = array();
		$dupes = 0;
		foreach ( $sentences as $sentence ) {
			$key = self::norm( $sentence );
			if ( str_word_count( $key ) < 8 ) {
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				++$dupes;
			}
			$seen[ $key ] = true;
		}
		return $dupes;
	}
}
