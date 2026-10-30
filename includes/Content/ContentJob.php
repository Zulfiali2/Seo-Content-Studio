<?php
/**
 * Section-by-section content generation job.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\SEO\Evaluator;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Full run, written in one go (default): prepare → article → fill → meta → finalize. "article" writes
 * the introduction, every section, the FAQ answers and the conclusion in one request; "fill" writes
 * any part that request left empty (one request per part), so a cut-off answer never leaves gaps.
 *
 * Full run, section by section: prepare → part:intro → part:section-N… → part:conclusion → faqs → edit → meta → finalize.
 * Partial run (options.parts = ids): only those parts, then finalize. "edit" is the final edit
 * of the whole article (on when "Final edit" is switched on under Brand and Writing Style).
 *
 * Approved or locked parts are never regenerated. Each part is saved as soon as
 * it is written, so an interrupted run keeps finished parts.
 *
 * Options: parts (ids), instructions (part id or "faqs" => the user's extra
 * instructions for that regeneration), faq_mode ("missing" answers only
 * unanswered questions).
 */
class ContentJob implements JobHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository     $projects  Projects.
	 * @param TextGenerationService $text      AI text.
	 * @param SettingsRepository    $settings  Settings.
	 * @param VersionStore          $versions  Versions.
	 * @param Evaluator             $evaluator Evaluator.
	 * @param SourceVerifier|null   $sources   Checks cited official sources (null: not checked).
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly SettingsRepository $settings,
		private readonly VersionStore $versions,
		private readonly Evaluator $evaluator,
		private readonly ?SourceVerifier $sources = null,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $options    Options.
	 */
	public function steps( int $project_id, array $options ): array {
		$only = array_values( array_filter( (array) ( $options['parts'] ?? array() ), static fn ( $p ) => is_string( $p ) && preg_match( '/^(intro|conclusion|section-\d{1,3}|faqs|edit|meta)$/', $p ) ) );
		if ( array() !== $only ) {
			return array_merge( array_map( static fn ( string $p ): string => in_array( $p, array( 'faqs', 'edit', 'meta' ), true ) ? $p : 'part:' . $p, $only ), array( 'finalize' ) );
		}
		$skeleton = $this->merged( $project_id );
		$steps    = array( 'prepare' );
		$mode = (string) WritingProfile::fromSettings( $this->settings )->style( 'generation_mode' );
		if ( 'single' === $mode ) {
			// Chat-style: one plain-language brief, the article back as HTML.
			return array( 'prepare', 'chat', 'fill', 'meta', 'finalize' );
		}
		if ( 'structured' === $mode ) {
			return array( 'prepare', 'article', 'fill', 'meta', 'finalize' );
		}
		foreach ( ContentModel::parts( $skeleton ) as $part ) {
			$steps[] = 'part:' . $part['id'];
		}
		if ( $skeleton['faqs_enabled'] && array() !== $skeleton['faqs'] ) {
			$steps[] = 'faqs';
		}
		if ( WritingProfile::fromSettings( $this->settings )->editPass() ) {
			$steps[] = 'edit';
		}
		$steps[] = 'meta';
		$steps[] = 'finalize';
		return $steps;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $step Step.
	 */
	public function stepLabel( string $step ): string {
		if ( str_starts_with( $step, 'part:' ) ) {
			$id = substr( $step, 5 );
			return match ( $id ) {
				'intro' => __( 'Writing the introduction', 'ai-seo-content-studio' ),
				'conclusion' => __( 'Writing the conclusion', 'ai-seo-content-studio' ),
				/* translators: %s: section number. */
				default => sprintf( __( 'Writing section %s', 'ai-seo-content-studio' ), substr( $id, 8 ) ),
			};
		}
		return match ( $step ) {
			'prepare' => __( 'Preparing the outline', 'ai-seo-content-studio' ),
			'faqs' => __( 'Answering FAQs', 'ai-seo-content-studio' ),
			'edit' => __( 'Final edit of the whole article', 'ai-seo-content-studio' ),
			'article', 'chat' => __( 'Writing the whole article in one go', 'ai-seo-content-studio' ),
			'fill' => __( 'Checking every part was written', 'ai-seo-content-studio' ),
			'meta' => __( 'Writing metadata and image suggestions', 'ai-seo-content-studio' ),
			'finalize' => __( 'Saving a version and running SEO checks', 'ai-seo-content-studio' ),
			default => $step,
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $step       Step.
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $state      State.
	 * @param array<string, mixed> $options    Options.
	 * @throws ProviderException On failure.
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$inputs  = $this->projects->section( $project_id, 'inputs' ) ?? array();
		$plan    = $this->projects->section( $project_id, 'keyword_plan' ) ?? KeywordPlan::empty( (string) ( $inputs['keyword'] ?? '' ) );
		$brief   = $this->projects->section( $project_id, 'brief' );
		$content = $this->projects->section( $project_id, 'content' );
		$max     = (int) $this->settings->get( 'ai.max_output_tokens' );

		if ( null === $brief ) {
			throw new ProviderException( __( 'Create the content brief before generating content.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}

		$profile = WritingProfile::fromSettings( $this->settings );

		if ( 'prepare' === $step || null === $content ) {
			// Chat-style articles may have sections the brief does not list; keep the current
			// structure while any part is approved or locked, so those parts are never lost.
			$content = null !== $content && 'single' === $profile->style( 'generation_mode' ) && self::hasKeptParts( $content )
				? $content
				: $this->merged( $project_id );
			$this->save( $project_id, $content, $brief, $profile );
			if ( 'prepare' === $step ) {
				return true;
			}
		}

		$context = ContentPrompts::context( $inputs, $plan, $brief, $profile );

		if ( str_starts_with( $step, 'part:' ) ) {
			$part = ContentModel::findPart( $content, substr( $step, 5 ) );
			if ( null === $part || $part['locked'] || 'approved' === $part['status'] ) {
				return true; // Approved and locked parts are preserved.
			}
			$content = $this->writePart( $context, $content, $part, $brief, $profile, $max, self::instruction( $options, $part['id'] ) );
			$this->save( $project_id, $content, $brief, $profile );
			return true;
		}

		if ( 'chat' === $step ) {
			$content = self::hasKeptParts( $content )
				// Approved or locked parts stay as they are: write the others around them.
				? $this->article( $context, $content, $brief, $profile, $max, $state )
				: $this->chat( $inputs, $plan, $brief, $content, $profile, $max, $project_id );
			$this->save( $project_id, $content, $brief, $profile );
			return true;
		}

		if ( 'article' === $step ) {
			$content = $this->article( $context, $content, $brief, $profile, $max, $state );
			$this->save( $project_id, $content, $brief, $profile );
			return true;
		}

		if ( 'fill' === $step ) {
			// One request per call: the runner calls this step again until it returns true.
			// Each empty part is tried once per job, so a part the AI keeps returning empty cannot loop.
			$tried = (array) ( $state['fill_tried'] ?? array() );
			foreach ( ContentModel::parts( $content ) as $part ) {
				if ( ! $part['locked'] && 'approved' !== $part['status'] && '' === trim( (string) $part['html'] ) && ! in_array( $part['id'], $tried, true ) ) {
					$state['fill_tried'] = array_merge( $tried, array( $part['id'] ) );
					$content             = $this->writePart( $context, $content, $part, $brief, $profile, $max, '' );
					$this->save( $project_id, $content, $brief, $profile );
					return false;
				}
			}
			$missing = $content['faqs_enabled'] && 'approved' !== $content['faqs_status']
				? array_filter( $content['faqs'], static fn ( $f ) => '' !== trim( (string) $f['question'] ) && '' === trim( (string) $f['answer_html'] ) )
				: array();
			if ( array() !== $missing && empty( $state['faqs_filled'] ) ) {
				$state['faqs_filled'] = true;
				$this->runStep( 'faqs', $project_id, $state, array( 'faq_mode' => 'missing' ) );
				return false;
			}
			return true;
		}

		if ( 'faqs' === $step ) {
			if ( 'approved' === $content['faqs_status'] || ! $content['faqs_enabled'] ) {
				return true;
			}
			// Indexes of the questions to answer: all, or only unanswered ones.
			$targets = array();
			foreach ( $content['faqs'] as $i => $faq ) {
				if ( 'missing' !== ( $options['faq_mode'] ?? '' ) || '' === trim( (string) $faq['answer_html'] ) ) {
					$targets[] = $i;
				}
			}
			if ( array() === $targets ) {
				return true;
			}
			$questions = array_map( static fn ( int $i ): string => (string) $content['faqs'][ $i ]['question'], $targets );
			$response  = $this->text->generate( ContentPrompts::faqRequest( $context, $questions, min( 4000, $max ), self::instruction( $options, 'faqs' ), $content, $profile ) );
			$content   = $this->withSources( $content, (array) $response->data, $profile );
			$links     = $this->links( $brief, $content, $profile );
			$answers   = array();
			foreach ( (array) ( $response->data['faqs'] ?? array() ) as $faq ) {
				$answers[ mb_strtolower( trim( (string) ( $faq['question'] ?? '' ) ) ) ] = (string) ( $faq['answer_html'] ?? '' );
			}
			foreach ( $targets as $n => $i ) {
				$key                                  = mb_strtolower( trim( (string) $content['faqs'][ $i ]['question'] ) );
				$ans                                  = $answers[ $key ] ?? ( array_values( $answers )[ $n ] ?? '' );
				$content['faqs'][ $i ]['answer_html'] = $profile->polish( ContentModel::cleanHtml( $ans, $links ) );
			}
			$this->save( $project_id, $content, $brief, $profile );
			return true;
		}

		if ( 'edit' === $step ) {
			$content = $this->edit( $context, $content, $plan, $brief, $profile, $max );
			$this->save( $project_id, $content, $brief, $profile );
			return true;
		}

		if ( 'meta' === $step ) {
			$response        = $this->text->generate( ContentPrompts::metaRequest( $context, $content, min( 2000, $max ) ) );
			$meta            = (array) $response->data;
			$content['meta'] = array_merge( (array) $content['meta'], array_intersect_key( $meta, array_flip( array( 'meta_title', 'meta_description', 'title_options', 'description_options', 'slug', 'schema_type', 'schema_reason', 'og_title', 'og_description', 'featured_image_prompt', 'featured_image_alt', 'image_placements' ) ) ) );
			$this->save( $project_id, $content, $brief, $profile );
			return true;
		}

		if ( 'finalize' === $step ) {
			$content['generated'] = array(
				'provider' => (string) $this->settings->get( 'ai.default_text_provider' ),
				'model'    => '',
				'at'       => time(),
			);
			foreach ( ContentModel::parts( $content ) as $part ) {
				if ( is_array( $part['generated'] ) && $part['generated']['at'] >= (int) $content['generated']['at'] - 3600 ) {
					$content['generated']['model'] = (string) $part['generated']['model'];
				}
			}
			if ( ! empty( $plan['bold_required'] ) ) {
				$content = self::boldRequired( $content, $plan );
			}
			$this->save( $project_id, $content, $brief, $profile );
			$this->versions->snapshot( $project_id, $this->projects->section( $project_id, 'content' ) ?? $content, array() === (array) ( $options['parts'] ?? array() ) ? __( 'Generated', 'ai-seo-content-studio' ) : __( 'Regenerated parts', 'ai-seo-content-studio' ) );
			$this->evaluator->evaluate( $project_id );
			$this->projects->setStage( $project_id, 'generated' );
			return true;
		}

		throw new ProviderException( 'Unknown content step.', ProviderException::CONFIG );
	}

	/**
	 * Skeleton from the current brief, merged with existing content so parts
	 * whose heading is unchanged keep their text, status and lock.
	 *
	 * @param int $project_id Project.
	 * @return array<string, mixed>
	 */
	private function merged( int $project_id ): array {
		$brief    = $this->projects->section( $project_id, 'brief' ) ?? array();
		$fresh    = ContentModel::fromBrief( $brief );
		$existing = $this->projects->section( $project_id, 'content' );
		if ( null === $existing || ! isset( $existing['intro'], $existing['sections'] ) ) {
			return $fresh;
		}

		$by_heading = array();
		foreach ( (array) $existing['sections'] as $section ) {
			$by_heading[ mb_strtolower( trim( (string) $section['heading'] ) ) ] = $section;
		}
		foreach ( $fresh['sections'] as $i => $section ) {
			$old = $by_heading[ mb_strtolower( trim( (string) $section['heading'] ) ) ] ?? null;
			if ( $old ) {
				foreach ( array( 'html', 'status', 'locked', 'generated' ) as $key ) {
					$fresh['sections'][ $i ][ $key ] = $old[ $key ];
				}
			}
		}
		foreach ( array( 'intro', 'conclusion' ) as $id ) {
			$fresh[ $id ] = $existing[ $id ];
		}
		if ( ! empty( $existing['faqs_edited'] ) ) {
			// Questions added or removed in the content step win over the brief's list.
			$fresh['faqs'] = (array) $existing['faqs'];
		} else {
			$answers = array();
			foreach ( (array) $existing['faqs'] as $faq ) {
				$answers[ mb_strtolower( (string) $faq['question'] ) ] = $faq['answer_html'];
			}
			foreach ( $fresh['faqs'] as $i => $faq ) {
				$fresh['faqs'][ $i ]['answer_html'] = $answers[ mb_strtolower( (string) $faq['question'] ) ] ?? '';
			}
		}
		$fresh['faqs_status']        = $existing['faqs_status'] ?? 'draft';
		$fresh['faqs_enabled']       = $existing['faqs_enabled'] ?? true;
		$fresh['faqs_edited']        = $existing['faqs_edited'] ?? false;
		$fresh['meta']               = array_merge( $fresh['meta'], array_filter( (array) $existing['meta'] ) );
		$fresh['meta']['faq_schema'] = $existing['meta']['faq_schema'] ?? true;
		$fresh['claims_to_verify']   = (array) ( $existing['claims_to_verify'] ?? array() );
		$fresh['sources']            = (array) ( $existing['sources'] ?? array() );
		$fresh['edit_report']        = $existing['edit_report'] ?? null;
		$fresh['title']              = '' !== (string) ( $existing['title'] ?? '' ) ? $existing['title'] : $fresh['title'];
		$fresh['h1']                 = '' !== (string) ( $existing['h1'] ?? '' ) ? $existing['h1'] : $fresh['h1'];
		return $fresh;
	}

	/**
	 * The user's extra instructions for one part of this run, or ''.
	 *
	 * @param array<string, mixed> $options Job options.
	 * @param string               $id      Part id or "faqs".
	 */
	private static function instruction( array $options, string $id ): string {
		$all = is_array( $options['instructions'] ?? null ) ? $options['instructions'] : array();
		return is_string( $all[ $id ] ?? null ) ? trim( $all[ $id ] ) : '';
	}

	/**
	 * Words to aim for in a part, from the brief's length range.
	 *
	 * @param array<string, mixed> $brief   Brief.
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $part    Part.
	 */
	private function targetWords( array $brief, array $content, array $part ): int {
		$min   = (int) ( $brief['length']['min'] ?? 0 );
		$max   = (int) ( $brief['length']['max'] ?? 0 );
		$total = $max > 0 ? (int) round( ( $min + $max ) / 2 ) : 1200;
		$count = max( 1, count( (array) $content['sections'] ) );
		return match ( $part['id'] ) {
			'intro' => max( 60, min( 200, (int) round( $total * 0.08 ) ) ),
			'conclusion' => max( 60, min( 180, (int) round( $total * 0.07 ) ) ),
			default => max( 120, (int) round( $total * 0.8 / $count ) ),
		};
	}

	/**
	 * Write one part and record its sources.
	 *
	 * @param array<string, mixed> $context      Shared context.
	 * @param array<string, mixed> $content      Content.
	 * @param array<string, mixed> $part         Part.
	 * @param array<string, mixed> $brief        Brief.
	 * @param WritingProfile       $profile      Profile.
	 * @param int                  $max          Max output tokens setting.
	 * @param string               $instructions The user's extra instructions.
	 * @return array<string, mixed>
	 */
	private function writePart( array $context, array $content, array $part, array $brief, WritingProfile $profile, int $max, string $instructions ): array {
		$response          = $this->text->generate( ContentPrompts::partRequest( $context, $content, $part, $this->targetWords( $brief, $content, $part ), min( 4000, $max ), $instructions, $profile ) );
		$content           = $this->withSources( $content, (array) $response->data, $profile );
		$part['html']      = $profile->polish( ContentModel::cleanHtml( (string) ( $response->data['html'] ?? '' ), $this->links( $brief, $content, $profile ) ) );
		$part['status']    = '' === $part['html'] ? 'empty' : 'draft';
		$part['generated'] = array(
			'provider' => $response->provider,
			'model'    => $response->model,
			'at'       => time(),
		);
		return ContentModel::withPart( $content, $part );
	}

	/**
	 * Whether any part or the FAQ list was approved or locked by the site owner.
	 *
	 * @param array<string, mixed> $content Content.
	 */
	private static function hasKeptParts( array $content ): bool {
		foreach ( ContentModel::parts( $content ) as $part ) {
			if ( ! empty( $part['locked'] ) || 'approved' === ( $part['status'] ?? '' ) ) {
				return true;
			}
		}
		return 'approved' === ( $content['faqs_status'] ?? '' );
	}

	/**
	 * Chat-style first draft: one plain-language request, the article back as HTML, split
	 * into introduction, sections, FAQs and conclusion. The AI may add sections the outline
	 * does not list. Outbound links stay only when their page loads.
	 *
	 * @param array<string, mixed> $inputs     Inputs.
	 * @param array<string, mixed> $plan       Keyword plan.
	 * @param array<string, mixed> $brief      Brief.
	 * @param array<string, mixed> $content    Content skeleton.
	 * @param WritingProfile       $profile    Profile.
	 * @param int                  $max        Max output tokens setting.
	 * @param int                  $project_id Project.
	 * @return array<string, mixed>
	 * @throws ProviderException When the answer is cut off or has no article in it.
	 */
	private function chat( array $inputs, array $plan, array $brief, array $content, WritingProfile $profile, int $max, int $project_id ): array {
		$research = $this->projects->section( $project_id, 'research' ) ?? array();
		$response = $this->text->generate( ChatPrompt::request( $inputs, $plan, $brief, $research, $profile, min( 32000, max( 16000, $max ) ) ) );
		if ( TextResponse::FINISH_LENGTH === $response->finishReason ) {
			throw new ProviderException( __( 'The article was cut off before the end. Try again, or lower the article length.', 'ai-seo-content-studio' ), ProviderException::TRUNCATED );
		}
		$parsed = ChatPrompt::parse( $response->text );
		if ( '' === trim( wp_strip_all_tags( $parsed['intro'] ) ) && array() === $parsed['sections'] ) {
			throw new ProviderException( __( 'The AI answer did not contain an article. Try again.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}

		// Links: the brief's and the business's own pages, plus outbound pages that load. Those
		// are recorded as verified sources, so they stay linked when the content is saved and
		// are listed under "Official sources used" for the site owner to check.
		$home     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$verified = array();
		if ( null !== $this->sources && $profile->outboundLinks() ) {
			$started = time();
			foreach ( array_slice( ChatPrompt::outboundLinks( $response->text, $home ), 0, 8, true ) as $url => $anchor ) {
				if ( time() - $started > 25 ) {
					break;
				}
				if ( $this->sources->exists( $url ) ) {
					$verified[] = array(
						/* translators: %s: link text. */
						'claim'  => sprintf( __( 'Linked source: %s', 'ai-seo-content-studio' ), '' !== $anchor ? $anchor : $url ),
						'url'    => $url,
						'status' => 'verified',
					);
				}
			}
		}
		$content['sources'] = array_merge( (array) ( $content['sources'] ?? array() ), $verified );
		$links              = $this->links( $brief, $content, $profile );
		$clean     = fn ( string $html ): string => $profile->polish( ContentModel::cleanHtml( $html, $links ) );
		$generated = array(
			'provider' => $response->provider,
			'model'    => $response->model,
			'at'       => time(),
		);

		$notes = array();
		foreach ( (array) ( $brief['outline'] ?? array() ) as $item ) {
			$notes[ mb_strtolower( trim( (string) ( $item['heading'] ?? '' ) ) ) ] = (string) ( $item['notes'] ?? '' );
		}
		$sections = array();
		foreach ( $parsed['sections'] as $i => $section ) {
			$html       = $clean( $section['html'] );
			$sections[] = ContentModel::part(
				'section-' . ( $i + 1 ),
				array(
					'heading'     => $section['heading'],
					'notes'       => $notes[ mb_strtolower( $section['heading'] ) ] ?? '',
					'subheadings' => array(),
					'html'        => $html,
					'status'      => '' === $html ? 'empty' : 'draft',
					'generated'   => $generated,
				)
			);
		}
		$content['sections'] = $sections;
		foreach ( array( 'intro', 'conclusion' ) as $id ) {
			$html           = $clean( $parsed[ $id ] );
			$content[ $id ] = array_merge(
				$content[ $id ],
				array(
					'html'      => $html,
					'status'    => '' === $html ? 'empty' : 'draft',
					'generated' => $generated,
				)
			);
		}
		if ( array() !== $parsed['faqs'] && $content['faqs_enabled'] ) {
			$content['faqs'] = array_map(
				static fn ( array $f ): array => array(
					'question'    => $f['question'],
					'answer_html' => $clean( $f['answer_html'] ),
				),
				$parsed['faqs']
			);
		}
		$content['claims_to_verify'] = array_values( array_unique( array_merge( (array) ( $content['claims_to_verify'] ?? array() ), $parsed['claims'] ) ) );
		return $content;
	}

	/**
	 * The whole article in one request. Approved and locked parts are sent as fixed text and
	 * never replaced. If the answer is cut off or unusable, the parts stay as they were and the
	 * "fill" step writes the empty ones one by one.
	 *
	 * @param array<string, mixed> $context Shared context.
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $brief   Brief.
	 * @param WritingProfile       $profile Profile.
	 * @param int                  $max     Max output tokens setting.
	 * @param array<string, mixed> $state   Job state (by reference).
	 * @return array<string, mixed>
	 * @throws ProviderException On errors other than a cut-off or unusable answer.
	 */
	private function article( array $context, array $content, array $brief, WritingProfile $profile, int $max, array &$state ): array {
		$open = array_values( array_filter( ContentModel::parts( $content ), static fn ( $p ) => ! $p['locked'] && 'approved' !== $p['status'] ) );
		if ( array() === $open ) {
			return $content;
		}
		$words = array();
		foreach ( ContentModel::parts( $content ) as $part ) {
			$words[ $part['id'] ] = $this->targetWords( $brief, $content, $part );
		}
		try {
			// A whole article needs more room than one section; the cap only limits, it is not billed.
			$response = $this->text->generate( ContentPrompts::articleRequest( $context, $content, $words, min( 32000, max( 16000, $max ) ), $profile ) );
		} catch ( ProviderException $e ) {
			if ( in_array( $e->category, array( ProviderException::TRUNCATED, ProviderException::INVALID_RESPONSE ), true ) ) {
				$state['article_fallback'] = $e->getMessage();
				return $content;
			}
			throw $e;
		}
		$data    = (array) $response->data;
		$content = $this->withSources( $content, $data, $profile );
		$links   = $this->links( $brief, $content, $profile );
		$ids     = array_column( $open, 'id' );
		foreach ( (array) ( $data['parts'] ?? array() ) as $row ) {
			$id   = is_array( $row ) ? (string) ( $row['id'] ?? '' ) : '';
			$html = is_array( $row ) ? $profile->polish( ContentModel::cleanHtml( (string) ( $row['html'] ?? '' ), $links ) ) : '';
			$part = in_array( $id, $ids, true ) ? ContentModel::findPart( $content, $id ) : null;
			if ( null === $part || '' === $html ) {
				continue;
			}
			$part['html']      = $html;
			$part['status']    = 'draft';
			$part['generated'] = array(
				'provider' => $response->provider,
				'model'    => $response->model,
				'at'       => time(),
			);
			$content           = ContentModel::withPart( $content, $part );
		}
		if ( $content['faqs_enabled'] && 'approved' !== $content['faqs_status'] ) {
			$answers = array();
			foreach ( (array) ( $data['faqs'] ?? array() ) as $faq ) {
				if ( is_array( $faq ) ) {
					$answers[ mb_strtolower( trim( (string) ( $faq['question'] ?? '' ) ) ) ] = (string) ( $faq['answer_html'] ?? '' );
				}
			}
			foreach ( $content['faqs'] as $i => $faq ) {
				$new = $profile->polish( ContentModel::cleanHtml( $answers[ mb_strtolower( trim( (string) $faq['question'] ) ) ] ?? '', $links ) );
				if ( '' !== $new ) {
					$content['faqs'][ $i ]['answer_html'] = $new;
				}
			}
		}
		return $content;
	}

	/**
	 * The final edit: one request with the whole article and measured checks. The result is
	 * applied only to parts the owner has not approved or locked, and is thrown away if it
	 * lost too much of the article.
	 *
	 * @param array<string, mixed> $context Shared context.
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $plan    Keyword plan.
	 * @param array<string, mixed> $brief   Brief.
	 * @param WritingProfile       $profile Profile.
	 * @param int                  $max     Max output tokens setting.
	 * @return array<string, mixed>
	 */
	private function edit( array $context, array $content, array $plan, array $brief, WritingProfile $profile, int $max ): array {
		$editable = array_values( array_filter( ContentModel::parts( $content ), static fn ( $p ) => ! $p['locked'] && 'approved' !== $p['status'] && '' !== $p['html'] ) );
		if ( array() === $editable ) {
			return $content;
		}
		$checks   = self::measure( $content, $plan, $profile );
		// The editor returns whole parts of the article, so it needs the same room as the one-go article.
		$response = $this->text->generate( ContentPrompts::editRequest( $context, $content, $checks, min( 32000, max( 16000, $max ) ), $profile ) );
		$data     = (array) $response->data;
		$content  = $this->withSources( $content, $data, $profile );
		$links    = $this->links( $brief, $content, $profile );

		$edited = $content;
		$ids    = array_column( $editable, 'id' );
		foreach ( (array) ( $data['parts'] ?? array() ) as $row ) {
			$id   = is_array( $row ) ? (string) ( $row['id'] ?? '' ) : '';
			$html = is_array( $row ) ? $profile->polish( ContentModel::cleanHtml( (string) ( $row['html'] ?? '' ), $links ) ) : '';
			$part = in_array( $id, $ids, true ) ? ContentModel::findPart( $edited, $id ) : null;
			if ( null !== $part && '' !== $html ) {
				$part['html'] = $html;
				$edited       = ContentModel::withPart( $edited, $part );
			}
		}
		$faqs = (array) ( $data['faqs'] ?? array() );
		if ( 'approved' !== $content['faqs_status'] && array() !== $faqs ) {
			$answers = array();
			foreach ( $faqs as $faq ) {
				if ( is_array( $faq ) ) {
					$answers[ mb_strtolower( trim( (string) ( $faq['question'] ?? '' ) ) ) ] = (string) ( $faq['answer_html'] ?? '' );
				}
			}
			foreach ( $edited['faqs'] as $i => $faq ) {
				$new = $profile->polish( ContentModel::cleanHtml( $answers[ mb_strtolower( trim( (string) $faq['question'] ) ) ] ?? '', $links ) );
				if ( '' !== $new && '' !== trim( (string) $faq['answer_html'] ) ) {
					$edited['faqs'][ $i ]['answer_html'] = $new;
				}
			}
		}

		$before  = ContentModel::wordCount( ContentModel::bodyHtml( $content ) );
		$after   = ContentModel::wordCount( ContentModel::bodyHtml( $edited ) );
		$applied = $before < 300 || $after >= $before * 0.6;
		$result  = $applied ? $edited : $content;

		$result['edit_report'] = array(
			'at'      => time(),
			'applied' => $applied,
			'changes' => (array) ( $data['changes'] ?? array() ),
			/* translators: 1: words before, 2: words after. */
			'note'    => $applied ? sprintf( __( 'Words: %1$d before, %2$d after.', 'ai-seo-content-studio' ), $before, $after ) : sprintf( __( 'Not applied: the edit would have cut the article from %1$d to %2$d words.', 'ai-seo-content-studio' ), $before, $after ),
		);
		return $result;
	}

	/**
	 * Measured facts about the draft for the final edit.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $plan    Plan.
	 * @param WritingProfile       $profile Profile.
	 * @return array<string, mixed>
	 */
	public static function measure( array $content, array $plan, WritingProfile $profile ): array {
		$html    = ContentModel::bodyHtml( $content );
		$text    = ContentModel::text( $html );
		$primary = (string) ( $plan['primary']['keyword'] ?? '' );
		$checks  = array(
			'word_count'                => ContentModel::wordCount( $html ),
			'primary_keyword_uses'      => KeywordTools::count( (string) ( $content['h1'] ?? '' ) . ' . ' . $text, $primary ),
			'missing_required_terms'    => array_values( array_filter( array_column( KeywordPlan::required( $plan ), 'text' ), static fn ( $t ) => 0 === KeywordTools::count( $text, $t ) ) ),
			'banned_words_found'        => array_values( array_filter( $profile->bannedWords(), static fn ( $w ) => KeywordTools::count( $text, $w ) > 0 ) ),
			'dashes_found'              => (int) preg_match_all( '/—|\s–\s/u', $text ),
			'professional_advice_found' => self::adviceLines( $text ),
		);
		$uses    = (array) ( $plan['primary_uses'] ?? array() );
		if ( (int) ( $uses['max'] ?? 0 ) > 0 ) {
			$checks['primary_keyword_target'] = array(
				'min' => (int) $uses['min'],
				'max' => (int) $uses['max'],
			);
		}
		return $checks;
	}

	/**
	 * Sentences that send the reader to a professional.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function adviceLines( string $text ): array {
		$out = array();
		foreach ( preg_split( '/(?<=[.!?])\s+/u', $text ) ?: array() as $sentence ) {
			if ( preg_match( '/\b(speak|talk|check|consult|work)(ing)?\s+(to|with)?\s*(a|an|your)\s+(qualified\s+|professional\s+|chartered\s+)?(accountant|professional|adviser|advisor|lawyer|solicitor|doctor|expert|tax adviser)\b|\bconsult\s+(a|an|your)\b/i', $sentence ) ) {
				$out[] = mb_substr( $sentence, 0, 200 );
			}
		}
		return $out;
	}

	/**
	 * Bold the first use of each must-use keyword in the parts the owner has not approved or locked.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $plan    Plan.
	 * @return array<string, mixed>
	 */
	public static function boldRequired( array $content, array $plan ): array {
		$terms = array_column( KeywordPlan::required( $plan ), 'text' );
		if ( array() === $terms ) {
			return $content;
		}
		// Document order: intro, sections, FAQ answers, conclusion. Approved and locked parts are not changed.
		$parts  = array();
		$locked = '';
		$add    = static function ( string $id, string $html, bool $editable ) use ( &$parts, &$locked ): void {
			if ( $editable ) {
				$parts[ $id ] = $html;
			} else {
				$locked .= $html;
			}
		};
		$open   = static fn ( array $p ): bool => ! $p['locked'] && 'approved' !== $p['status'];
		$add( 'intro', (string) $content['intro']['html'], $open( $content['intro'] ) );
		foreach ( (array) $content['sections'] as $section ) {
			$add( (string) $section['id'], (string) $section['html'], $open( $section ) );
		}
		foreach ( ContentModel::publishedFaqs( $content ) === array() ? array() : (array) $content['faqs'] as $i => $faq ) {
			$add( 'faq-' . $i, (string) $faq['answer_html'], 'approved' !== $content['faqs_status'] );
		}
		$add( 'conclusion', (string) $content['conclusion']['html'], $open( $content['conclusion'] ) );

		foreach ( KeywordTools::boldFirst( $parts, $terms, $locked ) as $id => $html ) {
			if ( str_starts_with( (string) $id, 'faq-' ) ) {
				$content['faqs'][ (int) substr( (string) $id, 4 ) ]['answer_html'] = $html;
				continue;
			}
			$part         = ContentModel::findPart( $content, (string) $id );
			$part['html'] = $html;
			$content      = ContentModel::withPart( $content, $part );
		}
		return $content;
	}

	/**
	 * Record the official sources a response cited, after checking them.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $data    AI output.
	 * @param WritingProfile       $profile Profile.
	 * @return array<string, mixed>
	 */
	private function withSources( array $content, array $data, WritingProfile $profile ): array {
		$cited = (array) ( $data['sources'] ?? array() );
		$found = array();
		if ( $profile->trustedFacts() && array() !== $cited ) {
			$found = null !== $this->sources
				? $this->sources->check( $cited, $profile )
				: array_map(
					static fn ( $s ): array => array(
						'claim'  => (string) ( is_array( $s ) ? ( $s['claim'] ?? '' ) : '' ),
						'url'    => (string) ( is_array( $s ) ? ( $s['url'] ?? '' ) : '' ),
						'status' => 'not_checked',
					),
					$cited
				);
		}
		$claims = (array) ( $data['claims_to_verify'] ?? array() );
		foreach ( $found as $source ) {
			if ( '' !== $source['claim'] ) {
				$claims[] = SourceVerifier::claimLine( $source );
			}
		}
		$content['sources']          = ContentModel::sanitizeSources( array_merge( $found, (array) ( $content['sources'] ?? array() ) ) );
		$content['claims_to_verify'] = array_values( array_unique( array_merge( (array) $content['claims_to_verify'], array_map( 'strval', $claims ) ) ) );
		return $content;
	}

	/**
	 * Links the content may keep: the brief's internal links, the business's own pages and,
	 * when allowed, official sources that were verified.
	 *
	 * @param array<string, mixed> $brief   Brief.
	 * @param array<string, mixed> $content Content.
	 * @param WritingProfile       $profile Profile.
	 * @return string[]
	 */
	public static function allowedLinks( array $brief, ?array $content, WritingProfile $profile ): array {
		return array_values(
			array_unique(
				array_merge(
					array_column( (array) ( $brief['internal_links'] ?? array() ), 'url' ),
					$profile->links(),
					$profile->outboundLinks() ? ContentModel::verifiedSourceUrls( $content ) : array()
				)
			)
		);
	}

	/**
	 * Allowed links for this content.
	 *
	 * @param array<string, mixed> $brief   Brief.
	 * @param array<string, mixed> $content Content.
	 * @param WritingProfile       $profile Profile.
	 * @return string[]
	 */
	private function links( array $brief, array $content, WritingProfile $profile ): array {
		return self::allowedLinks( $brief, $content, $profile );
	}

	/**
	 * Sanitise and save.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $content    Content.
	 * @param array<string, mixed> $brief      Brief.
	 * @param WritingProfile       $profile    Profile.
	 */
	private function save( int $project_id, array $content, array $brief, WritingProfile $profile ): void {
		$this->projects->save( $project_id, 'content', ContentModel::sanitize( $content, $this->links( $brief, $content, $profile ) ) );
	}
}
