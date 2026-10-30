<?php
/**
 * Pre-run cost estimates for billable operations.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Usage\CostEstimator;
use AiSeoContentStudio\Usage\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the billable calls an operation will make and estimates each from, in
 * order: your average recorded cost for that call over the last 30 days, then
 * your price table, otherwise "unknown". Cached results are free but cannot be
 * predicted reliably, so estimates assume no cache hits (an upper bound).
 */
class CostEstimates {

	/**
	 * Constructor.
	 *
	 * @param UsageRepository    $usage    Usage log.
	 * @param CostEstimator      $costs    Price table.
	 * @param SettingsRepository $settings Settings.
	 */
	public function __construct(
		private readonly UsageRepository $usage,
		private readonly CostEstimator $costs,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * Estimate for a job.
	 *
	 * @param string[] $steps Planned steps.
	 * @return array{calls: array<int, array<string, mixed>>, total: float|null, currency: string, unknown: int, threshold: float, needs_confirmation: bool}
	 */
	public function forSteps( array $steps ): array {
		$map = array(
			'serp'                => array( 'research', 'serp', 'dataforseo', 'serp/google/organic/live/advanced', __( 'Search results (DataForSEO)', 'ai-seo-content-studio' ) ),
			'metrics'             => array( 'research', 'keyword_overview', 'dataforseo', 'dataforseo_labs/google/keyword_overview/live', __( 'Keyword metrics (DataForSEO)', 'ai-seo-content-studio' ) ),
			'related'             => array( 'research', 'related_keywords', 'dataforseo', 'dataforseo_labs/google/related_keywords/live', __( 'Related keywords (DataForSEO)', 'ai-seo-content-studio' ) ),
			/* translators: %d: number of competitor pages. */
			'competitor_keywords' => array( 'research', 'ranked_keywords', 'dataforseo', 'dataforseo_labs/google/ranked_keywords/live', sprintf( __( 'Competitor ranking keywords (DataForSEO, up to %d pages)', 'ai-seo-content-studio' ), $this->competitorPages() ), $this->competitorPages() ),
			'suggestion_metrics'  => array( 'research', 'keyword_overview', 'dataforseo', 'dataforseo_labs/google/keyword_overview/live', __( 'Metrics for AI suggestions (DataForSEO)', 'ai-seo-content-studio' ) ),
			'analysis'            => array( 'text', 'research_analysis', (string) $this->settings->get( 'ai.default_text_provider' ), '', __( 'AI research analysis', 'ai-seo-content-studio' ) ),
			'brief'               => array( 'text', 'content_brief', (string) $this->settings->get( 'ai.default_text_provider' ), '', __( 'AI content brief', 'ai-seo-content-studio' ) ),
			'ai_competitors'      => array( 'text', 'competitor_suggestions', (string) $this->settings->get( 'ai.default_text_provider' ), '', __( 'AI suggestions for competitor pages', 'ai-seo-content-studio' ) ),
		);

		$calls         = array();
		$total         = 0.0;
		$unknown       = 0;
		$currency      = 'USD';
		$text_provider = (string) $this->settings->get( 'ai.default_text_provider' );
		$map          += array(
			'part'     => array( 'text', 'content_section', $text_provider, '', __( 'AI writing a content part', 'ai-seo-content-studio' ) ),
			'faqs'     => array( 'text', 'content_faqs', $text_provider, '', __( 'AI FAQ answers', 'ai-seo-content-studio' ) ),
			'edit'     => array( 'text', 'content_edit', $text_provider, '', __( 'AI final edit of the whole article', 'ai-seo-content-studio' ) ),
			'article'  => array( 'text', 'content_article', $text_provider, '', __( 'AI writing the whole article in one go', 'ai-seo-content-studio' ) ),
			'meta'     => array( 'text', 'content_meta', $text_provider, '', __( 'AI metadata and image suggestions', 'ai-seo-content-studio' ) ),
			'evaluate' => array( 'text', 'seo_evaluation', $text_provider, '', __( 'AI SEO review', 'ai-seo-content-studio' ) ),
			'analyze'  => array( 'text', 'optimize_analysis', $text_provider, '', __( 'AI analysis of existing content', 'ai-seo-content-studio' ) ),
			'rewrite'  => array( 'text', 'optimize_rewrite', $text_provider, '', __( 'AI revised version', 'ai-seo-content-studio' ) ),
		);
		foreach ( $steps as $step ) {
			if ( str_starts_with( $step, 'image:' ) ) {
				$calls[] = $this->imageCall( $step );
				$last    = end( $calls );
				if ( null === $last['estimate'] ) {
					++$unknown;
				} else {
					$total   += (float) $last['estimate'];
					$currency = (string) ( $last['currency'] ?? $currency );
				}
				continue;
			}
			$key = str_starts_with( $step, 'part:' ) ? 'part' : $step;
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}
			[ $operation, $purpose, $provider, $endpoint, $label ] = $map[ $key ];
			$calls_per_step                                        = (int) ( $map[ $key ][5] ?? 1 );

			$estimate = null;
			$basis    = 'unknown';
			$average  = $this->usage->averageCost( $operation, $purpose, 30, $provider );
			if ( null !== $average ) {
				$estimate = $average['amount'];
				$currency = $average['currency'];
				$basis    = 'recent_average';
			} elseif ( '' !== $endpoint ) {
				$price = $this->costs->estimateUnits( $provider, $endpoint, 1, 'per_request' );
				if ( null !== $price ) {
					$estimate = $price['amount'];
					$currency = $price['currency'];
					$basis    = 'price_table';
				}
			}
			if ( null !== $estimate ) {
				$estimate *= $calls_per_step;
			}
			if ( null === $estimate ) {
				++$unknown;
			} else {
				$total += $estimate;
			}
			$calls[] = array(
				'step'     => $step,
				'label'    => $label,
				'estimate' => $estimate,
				'basis'    => $basis,
			);
		}

		$threshold = (float) $this->settings->get( 'limits.confirm_cost_above', 0.5 );
		return array(
			'calls'              => $calls,
			'total'              => array() === $calls ? 0.0 : round( $total, 4 ),
			'currency'           => $currency,
			'unknown'            => $unknown,
			'threshold'          => $threshold,
			'needs_confirmation' => $total > $threshold || $unknown > 0,
		);
	}

	/**
	 * Competitor pages read per research run.
	 */
	private function competitorPages(): int {
		return max( 1, (int) $this->settings->get( 'research.competitor_pages', 10 ) );
	}

	/**
	 * Estimate for one image: the recent average for the image provider, else the "per image" price for its model, else unknown.
	 *
	 * @param string $step Step (image:slot).
	 * @return array<string, mixed>
	 */
	private function imageCall( string $step ): array {
		$provider = (string) $this->settings->get( 'ai.default_image_provider', '' );
		$model    = (string) $this->settings->get( 'ai.providers.' . $provider . '.image_model', '' );
		$label    = 'image:featured' === $step ? __( 'AI featured image', 'ai-seo-content-studio' ) : __( 'AI in-article image', 'ai-seo-content-studio' );
		$call     = array(
			'step'     => $step,
			'label'    => $label,
			'estimate' => null,
			'basis'    => 'unknown',
			'currency' => 'USD',
		);
		$average  = $this->usage->averageCost( 'image', 'image:featured' === $step ? 'image_featured' : 'image_inline', 30, $provider );
		if ( null !== $average ) {
			return array_merge(
				$call,
				array(
					'estimate' => $average['amount'],
					'basis'    => 'recent_average',
					'currency' => $average['currency'],
				)
			);
		}
		$price = '' === $model ? null : $this->costs->estimateUnits( $provider, $model, 1, 'per_image' );
		if ( null !== $price ) {
			return array_merge(
				$call,
				array(
					'estimate' => $price['amount'],
					'basis'    => 'price_table',
					'currency' => $price['currency'],
				)
			);
		}
		return $call;
	}
}
