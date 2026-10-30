<?php
/**
 * Brief generation as a background job.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Steps: internal_links (local search of published content) → brief (AI).
 */
class BriefJob implements JobHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository     $projects Projects.
	 * @param TextGenerationService $text     AI text service.
	 * @param SettingsRepository    $settings Settings.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'brief';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $options    Options.
	 */
	public function steps( int $project_id, array $options ): array {
		return array( 'internal_links', 'brief' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $step Step.
	 */
	public function stepLabel( string $step ): string {
		return 'internal_links' === $step
			? __( 'Finding related pages on this site', 'ai-seo-content-studio' )
			: __( 'Writing the content brief with AI', 'ai-seo-content-studio' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $step       Step.
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $state      State.
	 * @param array<string, mixed> $options    Options.
	 * @throws ProviderException On provider failures.
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$inputs = $this->projects->section( $project_id, 'inputs' ) ?? array();
		$plan   = $this->projects->section( $project_id, 'keyword_plan' ) ?? KeywordPlan::empty( (string) ( $inputs['keyword'] ?? '' ) );

		if ( 'internal_links' === $step ) {
			$state['candidates'] = self::findCandidates( $plan, $project_id );
			return true;
		}
		if ( 'brief' !== $step ) {
			throw new ProviderException( 'Unknown brief step.', ProviderException::CONFIG );
		}

		$research   = $this->projects->section( $project_id, 'research' ) ?? array();
		$candidates = (array) ( $state['candidates'] ?? array() );
		$response   = $this->text->generate( BriefGenerator::request( $inputs, $plan, $research, $candidates, min( 8000, (int) $this->settings->get( 'ai.max_output_tokens' ) ), WritingProfile::fromSettings( $this->settings ) ) );
		$brief      = BriefGenerator::toBrief( (array) $response->data, $inputs, $plan, $candidates );
		// Own pages on the same keyword are listed first, so they are seen before writing.
		$brief['verification_notes'] = array_values( array_merge( ManualBrief::keywordWarnings( (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' ), (int) ( $inputs['source_post_id'] ?? 0 ) ), (array) ( $brief['verification_notes'] ?? array() ) ) );

		$brief['generated'] = array(
			'provider' => $response->provider,
			'model'    => $response->model,
			'at'       => time(),
		);
		$this->projects->save( $project_id, 'brief', $brief );
		$this->projects->setStage( $project_id, 'briefed' );
		return true;
	}

	/**
	 * Published posts/pages matching the primary or approved secondary keywords.
	 *
	 * @param array<string, mixed> $plan       Plan.
	 * @param int                  $project_id Project (excluded).
	 * @return array<int, array{post_id: int, title: string, url: string}>
	 */
	public static function findCandidates( array $plan, int $project_id ): array {
		$terms = array_slice( array_merge( array( (string) ( $plan['primary']['keyword'] ?? '' ) ), KeywordPlan::approved( $plan, 'secondary' ) ), 0, 4 );
		$found = array();
		foreach ( array_filter( $terms ) as $term ) {
			$query = new \WP_Query(
				array(
					's'                      => $term,
					'post_type'              => array( 'post', 'page' ),
					'post_status'            => 'publish',
					'posts_per_page'         => 8,
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'post__not_in'           => array( $project_id ),
				)
			);
			foreach ( $query->posts as $post ) {
				$found[ $post->ID ] = array(
					'post_id' => (int) $post->ID,
					'title'   => wp_strip_all_tags( get_the_title( $post ) ),
					'url'     => (string) get_permalink( $post ),
				);
			}
			if ( count( $found ) >= 15 ) {
				break;
			}
		}
		return array_slice( array_values( $found ), 0, 15 );
	}
}
