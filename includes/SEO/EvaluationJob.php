<?php
/**
 * AI SEO review as a background job.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\SEO;

use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One step: send the assembled page to the AI reviewer and store the result.
 */
class EvaluationJob implements JobHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository     $projects  Projects.
	 * @param TextGenerationService $text      AI text.
	 * @param Evaluator             $evaluator Evaluator.
	 * @param SettingsRepository    $settings  Settings.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly Evaluator $evaluator,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'evaluate';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $options    Options.
	 */
	public function steps( int $project_id, array $options ): array {
		return array( 'evaluate' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $step Step.
	 */
	public function stepLabel( string $step ): string {
		return __( 'AI editorial and SEO review', 'ai-seo-content-studio' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $step       Step.
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $state      State.
	 * @param array<string, mixed> $options    Options.
	 * @throws ProviderException On failure or missing content.
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$content = $this->projects->section( $project_id, 'content' );
		if ( null === $content ) {
			throw new ProviderException( __( 'Generate content before running the AI review.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		$inputs   = $this->projects->section( $project_id, 'inputs' ) ?? array();
		$plan     = $this->projects->section( $project_id, 'keyword_plan' ) ?? array();
		$response = $this->text->generate( AiEvaluation::request( Evaluator::document( $content, $inputs ), $plan, $inputs, min( 6000, (int) $this->settings->get( 'ai.max_output_tokens' ) ) ) );
		$review   = AiEvaluation::sanitize( (array) $response->data );

		$review['provider'] = $response->provider;
		$review['model']    = $response->model;
		$this->evaluator->storeAi( $project_id, $review );
		return true;
	}
}
