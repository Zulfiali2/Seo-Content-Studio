<?php
/**
 * Research workflow as a background job.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Keywords\KeywordPlanBuilder;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Research\Contracts\Market;
use AiSeoContentStudio\Research\Contracts\ResearchProviderInterface;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Steps: serp → metrics → related → competitors → competitor_keywords → analysis → suggestion_metrics → plan.
 *
 * Without a research provider: ai_competitors → competitors → analysis → plan. The AI suggests
 * likely competitor pages (plus any pages the user listed); each is downloaded, so only real
 * pages are read, and the AI reads the keywords each page targets from its headings. None of
 * this is presented as ranking data, and the UI says so. Each step stores its results immediately, so partial research
 * survives failures and a retried step is served from the cache.
 */
class ResearchJob implements JobHandlerInterface {

	/** Seconds the competitor step may spend before continuing in a new request. */
	private const COMPETITOR_BUDGET = 25;

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository     $projects Projects.
	 * @param ResearchGateway       $gateway  Research gateway.
	 * @param CompetitorFetcher     $fetcher  Competitor fetcher.
	 * @param TextGenerationService $text     AI text service.
	 * @param SettingsRepository    $settings Settings.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly ResearchGateway $gateway,
		private readonly CompetitorFetcher $fetcher,
		private readonly TextGenerationService $text,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return 'research';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $options    Options.
	 */
	public function steps( int $project_id, array $options ): array {
		$steps = array();
		if ( $this->provider()->isConfigured() ) {
			$steps = array( 'serp', 'metrics', 'related' );
			if ( $this->fetchesCompetitors() ) {
				$steps[] = 'competitors';
			}
			if ( $this->competitorKeywords() ) {
				$steps[] = 'competitor_keywords';
			}
		} elseif ( $this->fetchesCompetitors() ) {
			if ( $this->aiCompetitors() ) {
				$steps[] = 'ai_competitors';
			}
			$steps[] = 'competitors';
		}
		$steps[] = 'analysis';
		if ( $this->provider()->isConfigured() && $this->settings->get( 'research.metrics_for_ai_suggestions', true ) ) {
			$steps[] = 'suggestion_metrics';
		}
		$steps[] = 'plan';
		return $steps;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $step Step.
	 */
	public function stepLabel( string $step ): string {
		return match ( $step ) {
			'serp' => __( 'Retrieving search results', 'ai-seo-content-studio' ),
			'metrics' => __( 'Retrieving keyword metrics', 'ai-seo-content-studio' ),
			'related' => __( 'Retrieving related keywords', 'ai-seo-content-studio' ),
			'ai_competitors' => __( 'Finding likely competitor pages with AI', 'ai-seo-content-studio' ),
			'competitors' => __( 'Reading competitor page outlines', 'ai-seo-content-studio' ),
			'competitor_keywords' => __( 'Retrieving competitor ranking keywords', 'ai-seo-content-studio' ),
			'analysis' => __( 'Analysing research with AI', 'ai-seo-content-studio' ),
			'suggestion_metrics' => __( 'Checking metrics for AI suggestions', 'ai-seo-content-studio' ),
			'plan' => __( 'Building keyword plan', 'ai-seo-content-studio' ),
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
	 * @throws ProviderException On provider failures.
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$inputs   = $this->projects->section( $project_id, 'inputs' ) ?? array();
		$keyword  = (string) ( $inputs['keyword'] ?? '' );
		$market   = Market::fromArray( (array) ( $inputs['market'] ?? array() ) );
		$refresh  = ! empty( $options['refresh'] );
		$research = $this->projects->section( $project_id, 'research' );

		if ( null === $research || ( $research['run_id'] ?? '' ) !== ( $options['run_id'] ?? '' ) ) {
			$research = $this->freshResearch( $inputs, (string) ( $options['run_id'] ?? '' ) );
		}

		$complete = true;
		switch ( $step ) {
			case 'serp':
				$result                     = $this->gateway->call(
					'serp',
					array(
						'keyword' => mb_strtolower( $keyword ),
						'market'  => $market->toArray(),
						'depth'   => $this->depth(),
					),
					fn ( ResearchProviderInterface $p ) => $p->serp( $keyword, $market, $this->depth() ),
					$refresh
				);
				$research['serp']           = $result['data'] + array( 'cached_at' => $result['cached_at'] );
				$research['status']['serp'] = 'ok';
				$research['costs'][]        = $this->costRow( 'serp', $result );
				break;

			case 'metrics':
				$result                        = $this->gateway->call(
					'keyword_overview',
					array(
						'keywords' => array( mb_strtolower( $keyword ) ),
						'market'   => $market->toArray(),
					),
					fn ( ResearchProviderInterface $p ) => $p->keywordMetrics( array( $keyword ), $market ),
					$refresh
				);
				$research['primary_metrics']   = $result['data']['keywords'][ mb_strtolower( $keyword ) ] ?? null;
				$research['status']['metrics'] = null === $research['primary_metrics'] ? 'no_data' : 'ok';
				$research['costs'][]           = $this->costRow( 'keyword_overview', $result );
				break;

			case 'related':
				$limit                         = (int) $this->settings->get( 'research.related_keywords_limit', 30 );
				$result                        = $this->gateway->call(
					'related_keywords',
					array(
						'keyword' => mb_strtolower( $keyword ),
						'market'  => $market->toArray(),
						'limit'   => $limit,
					),
					fn ( ResearchProviderInterface $p ) => $p->relatedKeywords( $keyword, $market, $limit ),
					$refresh
				);
				$research['related']           = (array) ( $result['data']['keywords'] ?? array() );
				$research['status']['related'] = array() === $research['related'] ? 'no_data' : 'ok';
				$research['costs'][]           = $this->costRow( 'related_keywords', $result );
				break;

			case 'ai_competitors':
				$own      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$response = $this->text->generate( CompetitorSuggestions::request( $inputs, $own, min( 2000, (int) $this->settings->get( 'ai.max_output_tokens' ) ) ) );
				$known    = array_column( (array) ( $research['candidates'] ?? array() ), 'url' );
				foreach ( CompetitorSuggestions::sanitize( (array) $response->data, $own ) as $row ) {
					if ( ! in_array( $row['url'], $known, true ) ) {
						$research['candidates'][] = $row;
					}
				}
				$research['status']['ai_competitors'] = 'ok';
				break;

			case 'competitors':
				$complete = $this->competitorsStep( $research, $state );
				break;

			case 'competitor_keywords':
				$complete = $this->competitorKeywordsStep( $research, $state, $market, $refresh );
				break;

			case 'analysis':
				$response                       = $this->text->generate( ResearchAnalysis::request( $inputs, $research, min( 6000, (int) $this->settings->get( 'ai.max_output_tokens' ) ) ) );
				$research['analysis']           = ResearchAnalysis::sanitize( (array) $response->data );
				$research['analysis_meta']      = array(
					'provider' => $response->provider,
					'model'    => $response->model,
				);
				$research['status']['analysis'] = 'ok';
				if ( ! $this->provider()->isConfigured() ) {
					$research = self::withAiPageKeywords( $research, (array) ( $response->data['competitor_page_keywords'] ?? array() ) );
				}
				break;

			case 'suggestion_metrics':
				$terms = array_merge(
					(array) ( $research['analysis']['secondary_keywords'] ?? array() ),
					(array) ( $research['analysis']['semantic_variations'] ?? array() ),
					(array) ( $research['serp']['related_searches'] ?? array() )
				);
				$terms = array_slice( array_values( array_unique( array_map( 'mb_strtolower', array_filter( array_map( 'strval', $terms ) ) ) ) ), 0, 50 );
				sort( $terms );
				if ( array() !== $terms ) {
					$result                         = $this->gateway->call(
						'keyword_overview',
						array(
							'keywords' => $terms,
							'market'   => $market->toArray(),
						),
						fn ( ResearchProviderInterface $p ) => $p->keywordMetrics( $terms, $market ),
						$refresh
					);
					$research['suggestion_metrics'] = (array) ( $result['data']['keywords'] ?? array() );
					$research['costs'][]            = $this->costRow( 'keyword_overview', $result );
				}
				$research['status']['suggestion_metrics'] = 'ok';
				break;

			case 'plan':
				$plan = KeywordPlanBuilder::build( $keyword, $research, $this->projects->section( $project_id, 'keyword_plan' ) );
				$this->projects->save( $project_id, 'keyword_plan', $plan );
				$research['completed_at'] = time();
				$this->projects->setStage( $project_id, 'researched' );
				break;

			default:
				throw new ProviderException( 'Unknown research step.', ProviderException::CONFIG );
		}

		$this->projects->save( $project_id, 'research', $research );
		return $complete;
	}

	/**
	 * Fetch competitor outlines within a time budget; returns false to continue.
	 *
	 * @param array<string, mixed> $research Research (by reference).
	 * @param array<string, mixed> $state    Job state (by reference).
	 */
	private function competitorsStep( array &$research, array &$state ): bool {
		$rows    = $this->competitorRows( $research );
		$index   = (int) ( $state['competitor_index'] ?? 0 );
		$total   = count( $rows );
		$started = time();
		while ( $index < $total && time() - $started < self::COMPETITOR_BUDGET ) {
			// Ranking position, result title and origin travel with the outline so the source can be shown.
			$research['competitors'][] = $this->fetcher->fetch( $rows[ $index ]['url'] ) + array(
				'rank'   => (int) ( $rows[ $index ]['rank'] ?? 0 ),
				'title'  => (string) ( $rows[ $index ]['title'] ?? '' ),
				'origin' => (string) ( $rows[ $index ]['origin'] ?? 'serp' ),
			);
			++$index;
		}
		$state['competitor_index'] = $index;

		if ( $index >= $total ) {
			$fetched                           = count( array_filter( (array) $research['competitors'], static fn ( $c ) => 'fetched' === ( $c['status'] ?? '' ) ) );
			$research['status']['competitors'] = 0 === $total ? 'no_data' : ( $fetched > 0 ? 'ok' : 'none_fetched' );
			unset( $state['competitor_index'] );
			return true;
		}
		return false;
	}

	/**
	 * Keywords each competitor page ranks for, one provider call per page. A failed page is
	 * recorded with its error and does not stop the research.
	 *
	 * @param array<string, mixed> $research Research (by reference).
	 * @param array<string, mixed> $state    Job state (by reference).
	 * @param Market               $market   Market.
	 * @param bool                 $refresh  Bypass the cache.
	 * @throws ProviderException On configuration or limit errors (the job stops).
	 */
	private function competitorKeywordsStep( array &$research, array &$state, Market $market, bool $refresh ): bool {
		$rows    = $this->competitorRows( $research );
		$limit   = (int) $this->settings->get( 'research.competitor_keywords_limit', 10 );
		$index   = (int) ( $state['competitor_keywords_index'] ?? 0 );
		$total   = count( $rows );
		$started = time();
		if ( 0 === $index ) {
			$research['competitor_keywords'] = array();
		}
		while ( $index < $total && time() - $started < self::COMPETITOR_BUDGET ) {
			$row   = $rows[ $index ];
			$entry = array(
				'url'      => (string) $row['url'],
				'rank'     => (int) $row['rank'],
				'title'    => (string) $row['title'],
				'domain'   => (string) ( $row['domain'] ?? '' ),
				'keywords' => array(),
				'status'   => 'ok',
				'error'    => '',
			);
			try {
				$result               = $this->gateway->call(
					'ranked_keywords',
					array(
						'url'    => (string) $row['url'],
						'market' => $market->toArray(),
						'limit'  => $limit,
					),
					fn ( ResearchProviderInterface $p ) => $p->rankedKeywords( (string) $row['url'], $market, $limit ),
					$refresh
				);
				$entry['keywords']    = array_slice( (array) ( $result['data']['keywords'] ?? array() ), 0, $limit );
				$entry['total_count'] = $result['data']['total_count'] ?? null;
				$entry['status']      = array() === $entry['keywords'] ? 'no_data' : 'ok';
				$research['costs'][]  = $this->costRow( 'ranked_keywords', $result );
			} catch ( ProviderException $e ) {
				if ( in_array( $e->category, array( ProviderException::CONFIG, ProviderException::AUTH, ProviderException::LIMIT, ProviderException::QUOTA, ProviderException::RATE_LIMIT ), true ) ) {
					throw $e;
				}
				$entry['status'] = 'error';
				$entry['error']  = $e->getMessage();
			}
			$research['competitor_keywords'][] = $entry;
			++$index;
		}
		$state['competitor_keywords_index'] = $index;

		if ( $index >= $total ) {
			$ok                                        = count( array_filter( (array) $research['competitor_keywords'], static fn ( $c ) => 'ok' === $c['status'] ) );
			$research['status']['competitor_keywords'] = 0 === $total ? 'no_data' : ( $ok > 0 ? 'ok' : 'no_data' );
			unset( $state['competitor_keywords_index'] );
			return true;
		}
		return false;
	}

	/**
	 * Keywords the AI read from each fetched competitor page (no provider connected). Only pages
	 * that were really downloaded are kept; there are no positions or volumes.
	 *
	 * @param array<string, mixed> $research Research.
	 * @param array<int, mixed>    $rows     AI output: [{url, keywords}].
	 * @return array<string, mixed>
	 */
	public static function withAiPageKeywords( array $research, array $rows ): array {
		$by_url = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$by_url[ untrailingslashit( strtolower( (string) ( $row['url'] ?? '' ) ) ) ] = Sanitize::strings( $row['keywords'] ?? array(), 10, 120 );
			}
		}
		$pages = array();
		foreach ( (array) ( $research['competitors'] ?? array() ) as $page ) {
			if ( 'fetched' !== ( $page['status'] ?? '' ) ) {
				continue;
			}
			$terms   = $by_url[ untrailingslashit( strtolower( (string) $page['url'] ) ) ] ?? array();
			$pages[] = array(
				'url'      => (string) $page['url'],
				'rank'     => (int) ( $page['rank'] ?? 0 ),
				'title'    => (string) ( $page['outline']['title'] ?? $page['title'] ?? '' ),
				'domain'   => (string) wp_parse_url( (string) $page['url'], PHP_URL_HOST ),
				'origin'   => (string) ( $page['origin'] ?? 'ai' ),
				'keywords' => array_map(
					static fn ( string $k ): array => array(
						'keyword'            => $k,
						'position'           => null,
						'search_volume'      => null,
						'keyword_difficulty' => null,
						'intent'             => null,
						'etv'                => null,
					),
					$terms
				),
				'status'   => array() === $terms ? 'no_data' : 'ok',
				'error'    => '',
				'source'   => 'ai',
			);
		}
		$research['competitor_keywords']           = $pages;
		$research['status']['competitor_keywords'] = array() === array_filter( $pages, static fn ( $p ) => 'ok' === $p['status'] ) ? 'no_data' : 'ai';
		return $research;
	}

	/**
	 * Pages to read: the top organic results from other sites (not this one), or without a
	 * research provider the pages the user listed and the AI suggested; up to the competitor
	 * page setting.
	 *
	 * @param array<string, mixed> $research Research.
	 * @return array<int, array<string, mixed>>
	 */
	private function competitorRows( array $research ): array {
		$limit  = (int) $this->settings->get( 'research.competitor_pages', 10 );
		$rows   = array();
		$seen   = array();
		$own    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$source = is_array( $research['serp'] ?? null ) ? (array) ( $research['serp']['organic'] ?? array() ) : (array) ( $research['candidates'] ?? array() );
		foreach ( $source as $row ) {
			$url  = (string) ( $row['url'] ?? '' );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' !== $host && $host !== $own && ! isset( $seen[ $url ] ) ) {
				$seen[ $url ] = true;
				$rows[]       = $row;
			}
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * New research record for this run.
	 *
	 * @param array<string, mixed> $inputs Inputs.
	 * @param string               $run_id Run id.
	 * @return array<string, mixed>
	 */
	private function freshResearch( array $inputs, string $run_id ): array {
		$configured = $this->provider()->isConfigured();
		$na         = $configured ? 'pending' : 'unavailable';
		$candidates = array();
		foreach ( (array) ( $inputs['competitor_urls'] ?? array() ) as $url ) {
			$candidates[] = array(
				'url'    => (string) $url,
				'title'  => '',
				'reason' => '',
				'origin' => 'user',
				'rank'   => 0,
			);
		}
		return array(
			'run_id'              => $run_id,
			'provider'            => $configured ? $this->provider()->id() : null,
			'sandbox'             => $configured && (bool) $this->settings->get( 'research.use_sandbox', false ),
			'keyword'             => (string) ( $inputs['keyword'] ?? '' ),
			'market'              => (array) ( $inputs['market'] ?? array() ),
			'started_at'          => time(),
			'completed_at'        => null,
			'serp'                => null,
			'primary_metrics'     => null,
			'related'             => array(),
			'candidates'          => $configured ? array() : $candidates,
			'competitors'         => array(),
			'competitor_keywords' => array(),
			'analysis'            => null,
			'analysis_meta'       => null,
			'suggestion_metrics'  => array(),
			'costs'               => array(),
			'status'              => array(
				'serp'                => $na,
				'metrics'             => $na,
				'related'             => $na,
				'ai_competitors'      => ! $configured && $this->fetchesCompetitors() && $this->aiCompetitors() ? 'pending' : 'unavailable',
				'competitors'         => $this->fetchesCompetitors() ? 'pending' : 'unavailable',
				'competitor_keywords' => ( $configured ? $this->competitorKeywords() : $this->fetchesCompetitors() ) ? 'pending' : 'unavailable',
				'analysis'            => 'pending',
				'suggestion_metrics'  => $configured && $this->settings->get( 'research.metrics_for_ai_suggestions', true ) ? 'pending' : 'unavailable',
			),
		);
	}

	/**
	 * Cost row for the research record.
	 *
	 * @param string               $purpose Purpose.
	 * @param array<string, mixed> $result  Gateway result.
	 * @return array<string, mixed>
	 */
	private function costRow( string $purpose, array $result ): array {
		return array(
			'purpose'  => $purpose,
			'cached'   => (bool) $result['cached'],
			'amount'   => $result['cost'],
			'currency' => $result['currency'],
		);
	}

	/**
	 * SERP depth setting.
	 */
	private function depth(): int {
		return (int) $this->settings->get( 'research.serp_depth', 10 );
	}

	/**
	 * Research provider.
	 */
	private function provider(): ResearchProviderInterface {
		return $this->gateway->provider();
	}

	/**
	 * Whether competitor ranking keywords are retrieved.
	 */
	private function competitorKeywords(): bool {
		return (bool) $this->settings->get( 'research.competitor_keywords', true ) && (int) $this->settings->get( 'research.competitor_pages', 10 ) > 0;
	}

	/**
	 * Whether the AI suggests competitor pages when no research provider is connected.
	 */
	private function aiCompetitors(): bool {
		return (bool) $this->settings->get( 'research.ai_competitors', true );
	}

	/**
	 * Whether competitor pages are read.
	 */
	private function fetchesCompetitors(): bool {
		return (bool) $this->settings->get( 'research.fetch_competitors', true );
	}
}
