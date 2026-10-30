<?php
/**
 * Background job: clone a page and rewrite its content with AI.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Cloner;

use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * Steps: plan (find slots, apply the user's choices) → write (one AI request) → build (new
 * draft). Each step stores its result first and skips itself when the result exists, so a
 * retried step never repeats a billed AI request or creates a second draft.
 *
 * Project "studio" section: {source_page_id, brief, result: {page_id, urls, applied, warnings, ...}}.
 */
class CloneJob implements JobHandlerInterface {

	public const TYPE = 'studio_clone';

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository     $projects Projects.
	 * @param TextGenerationService $text     Text generation.
	 * @param SettingsRepository    $settings Settings.
	 * @param StudioServices        $studio   Studio services (the cloner is built when a step runs).
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly SettingsRepository $settings,
		private readonly StudioServices $studio,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function steps( int $project_id, array $options ): array {
		return array( 'plan', 'write', 'build' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stepLabel( string $step ): string {
		return match ( $step ) {
			'plan'  => __( 'Reading the page layout and its text', 'ai-seo-content-studio' ),
			'write' => __( 'Writing the new content with AI', 'ai-seo-content-studio' ),
			default => __( 'Creating the draft page in Elementor', 'ai-seo-content-studio' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$studio = (array) $this->projects->section( $project_id, 'studio' );
		$source = get_post( (int) ( $studio['source_page_id'] ?? 0 ) );
		if ( ! $source instanceof \WP_Post || ! current_user_can( 'edit_post', $source->ID ) ) {
			throw new ProviderException( __( 'The source page no longer exists or you can no longer edit it.', 'ai-seo-content-studio' ), ProviderException::PERMISSION );
		}
		$profile = WritingProfile::fromSettings( $this->settings );

		try {
			switch ( $step ) {
				case 'plan':
					$plan     = $this->studio->cloner()->plan( $source, $profile->brand() );
					$selected = isset( $studio['selected'] ) && is_array( $studio['selected'] ) ? array_flip( array_map( 'strval', $studio['selected'] ) ) : null;
					$slots    = array();
					foreach ( $plan['slots'] as $slot ) {
						if ( null !== $selected && empty( $slot['locked'] ) ) {
							$slot['replace'] = isset( $selected[ $slot['key'] ] );
						}
						$slots[] = $slot;
					}
					$state['slots'] = $slots;
					$state['plan_warnings'] = $plan['warnings'];
					return true;

				case 'write':
					if ( isset( $studio['written'] ) ) {
						return true; // Already paid for: never ask again.
					}
					$write = array_slice( array_values( array_filter( (array) ( $state['slots'] ?? array() ), static fn ( array $s ): bool => ! empty( $s['replace'] ) && empty( $s['locked'] ) ) ), 0, PageCloner::MAX_AI_SLOTS );
					if ( array() === $write ) {
						$studio['written'] = array(
							'texts'    => array(),
							'warnings' => array(),
						);
					} else {
						$response          = $this->text->generate( CloneContentGenerator::request( (array) ( $studio['brief'] ?? array() ), $write, $profile->context(), min( 16000, max( 4000, (int) $this->settings->get( 'ai.max_output_tokens' ) ) ) ) );
						$parsed            = CloneContentGenerator::parse( $response->data, $write );
						$parsed['texts']   = array_map( array( $profile, 'polish' ), $parsed['texts'] );
						$studio['written'] = $parsed + array(
							'provider' => $response->provider,
							'model'    => $response->model,
							'at'       => time(),
						);
					}
					$this->projects->save( $project_id, 'studio', $studio );
					return true;

				case 'build':
					if ( isset( $studio['result']['page_id'] ) ) {
						return true; // The draft exists already.
					}
					$written = (array) ( $studio['written'] ?? array() );
					$brief   = (array) ( $studio['brief'] ?? array() );
					$result  = $this->studio->cloner()->clone(
						$source,
						(int) get_current_user_id(),
						array(
							'title'            => (string) ( $studio['title'] ?? '' ) ?: (string) ( $written['page_title'] ?? '' ),
							'texts'            => (array) ( $written['texts'] ?? array() ),
							'slots'            => (array) ( $state['slots'] ?? array() ),
							'meta_title'       => (string) ( $written['meta_title'] ?? '' ),
							'meta_description' => (string) ( $written['meta_description'] ?? '' ),
							'keyword'          => (string) ( $brief['primary_keyword'] ?? '' ),
							'project_id'       => $project_id,
							'profile_id'       => (int) ( $studio['profile_id'] ?? 0 ),
						)
					);
					$result['plan_warnings'] = (array) ( $state['plan_warnings'] ?? array() );
					$result['ai_warnings']   = (array) ( $written['warnings'] ?? array() );
					$studio['result']        = $result;
					$this->projects->save( $project_id, 'studio', $studio );
					$this->projects->setStage( $project_id, 'studio_draft' );
					return true;
			}
		} catch ( DraftException $e ) {
			throw new ProviderException( implode( ' ', array_slice( $e->errors, 0, 3 ) ), ProviderException::INVALID_RESPONSE );
		}
		throw new ProviderException( 'Unknown clone step.', ProviderException::CONFIG );
	}
}
