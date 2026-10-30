<?php
/**
 * Background jobs for the Existing Content Optimizer.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Optimizer;

use AiSeoContentStudio\Content\ContentModel;
use AiSeoContentStudio\Content\VersionStore;
use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\SEO\DeterministicChecks;
use AiSeoContentStudio\SEO\Evaluator;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Type "optimize_analysis" (step analyze) or "optimize_rewrite" (step rewrite).
 * One class handles both so they share dependencies; the type is fixed per instance.
 */
class OptimizeJobs implements JobHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param string                $mode      "optimize_analysis" or "optimize_rewrite".
	 * @param ProjectRepository     $projects  Projects.
	 * @param TextGenerationService $text      AI text.
	 * @param SettingsRepository    $settings  Settings.
	 * @param Evaluator             $evaluator Evaluator.
	 * @param VersionStore          $versions  Versions.
	 */
	public function __construct(
		private readonly string $mode,
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly SettingsRepository $settings,
		private readonly Evaluator $evaluator,
		private readonly VersionStore $versions,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return $this->mode;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $options    Options.
	 */
	public function steps( int $project_id, array $options ): array {
		return array( 'optimize_analysis' === $this->mode ? 'analyze' : 'rewrite' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $step Step.
	 */
	public function stepLabel( string $step ): string {
		return 'analyze' === $step ? __( 'Analysing the existing content', 'ai-seo-content-studio' ) : __( 'Writing the revised version', 'ai-seo-content-studio' );
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
		$inputs = $this->projects->section( $project_id, 'inputs' ) ?? array();
		$plan   = $this->projects->section( $project_id, 'keyword_plan' ) ?? KeywordPlan::empty( (string) ( $inputs['keyword'] ?? '' ) );
		$opt    = $this->projects->section( $project_id, 'optimization' );
		if ( null === $opt ) {
			throw new ProviderException( __( 'This project is not an optimisation project.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		$source = (array) $opt['source_content'];
		$max    = (int) $this->settings->get( 'ai.max_output_tokens' );

		if ( 'analyze' === $step ) {
			$response               = $this->text->generate( OptimizerPrompts::analysisRequest( $source, $plan, $inputs, (array) ( $opt['baseline']['deterministic'] ?? array() ), $this->projects->section( $project_id, 'brief' ), min( 6000, $max ) ) );
			$opt['summary']         = Sanitize::textarea( $response->data['summary'] ?? '', 1500 );
			$opt['recommendations'] = OptimizerPrompts::recommendations( (array) $response->data );
			$opt['analysis_meta']   = array(
				'provider' => $response->provider,
				'model'    => $response->model,
				'at'       => time(),
			);
			$this->projects->save( $project_id, 'optimization', $opt );
			$this->projects->setStage( $project_id, 'analyzed' );
			return true;
		}

		$accepted = array_values( array_filter( (array) ( $opt['recommendations'] ?? array() ), static fn ( array $r ) => 'accepted' === $r['decision'] ) );
		if ( array() === $accepted ) {
			throw new ProviderException( __( 'Accept at least one recommendation first.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		$links    = (array) ( $opt['links'] ?? array() );
		$response = $this->text->generate( OptimizerPrompts::rewriteRequest( $source, $accepted, $plan, $inputs, $links, min( 32000, max( 16000, $max ) ), WritingProfile::fromSettings( $this->settings ) ) );
		$data     = (array) $response->data;

		$content             = $source;
		$content['title']    = '' !== trim( (string) ( $data['title'] ?? '' ) ) ? (string) $data['title'] : $source['title'];
		$content['h1']       = $content['title'];
		$content['intro']    = ContentModel::part(
			'intro',
			array(
				'html'   => (string) ( $data['intro_html'] ?? '' ),
				'status' => 'draft',
			)
		);
		$content['sections'] = array();
		foreach ( (array) ( $data['sections'] ?? array() ) as $i => $section ) {
			$content['sections'][] = ContentModel::part(
				'section-' . ( $i + 1 ),
				array(
					'heading'     => (string) ( $section['heading'] ?? '' ),
					'notes'       => '',
					'subheadings' => array(),
					'html'        => (string) ( $section['html'] ?? '' ),
					'status'      => 'draft',
				)
			);
		}
		$content['faqs']                     = array_values( array_filter( (array) ( $data['faqs'] ?? array() ), static fn ( $f ) => '' !== trim( (string) ( $f['question'] ?? '' ) ) ) );
		$content['conclusion']               = ContentModel::part(
			'conclusion',
			array(
				'html'   => (string) ( $data['conclusion_html'] ?? '' ),
				'status' => 'draft',
			)
		);
		$content['meta']['meta_title']       = (string) ( $data['meta_title'] ?? $source['meta']['meta_title'] );
		$content['meta']['meta_description'] = (string) ( $data['meta_description'] ?? $source['meta']['meta_description'] );
		$content['claims_to_verify']         = (array) ( $data['claims_to_verify'] ?? array() );
		$content['generated']                = array(
			'provider' => $response->provider,
			'model'    => $response->model,
			'at'       => time(),
		);

		$this->projects->save( $project_id, 'content', ContentModel::sanitize( $content, $links ) );
		$opt['change_summary'] = Sanitize::strings( $data['change_summary'] ?? array(), 30, 400 );
		$this->projects->save( $project_id, 'optimization', $opt );

		// Make the "before" scores the source baseline for the before/after view.
		$this->projects->save( $project_id, 'evaluation', (array) $opt['baseline'] );
		$this->evaluator->evaluate( $project_id );
		$this->versions->snapshot( $project_id, $this->projects->section( $project_id, 'content' ) ?? $content, __( 'Revised from the original', 'ai-seo-content-studio' ) );
		$this->projects->setStage( $project_id, 'generated' );
		return true;
	}

	/**
	 * Baseline deterministic report of the source content.
	 *
	 * @param array<string, mixed> $content Source content.
	 * @param array<string, mixed> $inputs  Inputs.
	 * @param array<string, mixed> $plan    Plan.
	 * @return array<string, mixed>
	 */
	public static function baseline( array $content, array $inputs, array $plan ): array {
		return array(
			'deterministic'   => ( new DeterministicChecks() )->run( Evaluator::document( $content, $inputs ), $plan, array(), (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'ai'              => null,
			'previous_scores' => null,
			'at'              => time(),
			'label'           => 'original',
		);
	}
}
