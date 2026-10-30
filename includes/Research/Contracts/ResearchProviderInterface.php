<?php
/**
 * SEO data provider contract.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research\Contracts;

use AiSeoContentStudio\Providers\Contracts\ConnectionResult;
use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by SEO data providers (DataForSEO now; others such as Semrush later).
 *
 * Every metric returned must come from the provider. Missing values are null,
 * never guessed.
 */
interface ResearchProviderInterface {

	/** Stable id. */
	public function id(): string;

	/** Display name. */
	public function label(): string;

	/** Whether credentials are present. */
	public function isConfigured(): bool;

	/** Verify credentials with a low-cost account call. */
	public function testConnection(): ConnectionResult;

	/**
	 * Supported markets (countries with their languages).
	 *
	 * @return ResearchResult data: ['markets' => array<int, array>]
	 * @throws ProviderException On failure.
	 */
	public function markets(): ResearchResult;

	/**
	 * Organic SERP for a keyword.
	 *
	 * @param string $keyword Keyword.
	 * @param Market $market  Market.
	 * @param int    $depth   Number of results (10–100).
	 * @return ResearchResult data: ['organic' => [...], 'questions' => [...], 'related_searches' => [...], 'item_types' => [...], 'se_results_count' => ?int, 'check_url' => string]
	 * @throws ProviderException On failure.
	 */
	public function serp( string $keyword, Market $market, int $depth = 10 ): ResearchResult;

	/**
	 * Metrics for known keywords.
	 *
	 * @param string[] $keywords Keywords (max 700 per call is enforced by callers).
	 * @param Market   $market   Market.
	 * @return ResearchResult data: ['keywords' => array<string, array>] keyed by lowercase keyword.
	 * @throws ProviderException On failure.
	 */
	public function keywordMetrics( array $keywords, Market $market ): ResearchResult;

	/**
	 * Related keywords with metrics.
	 *
	 * @param string $keyword Seed keyword.
	 * @param Market $market  Market.
	 * @param int    $limit   Maximum keywords.
	 * @return ResearchResult data: ['keywords' => array<int, array>]
	 * @throws ProviderException On failure.
	 */
	public function relatedKeywords( string $keyword, Market $market, int $limit = 30 ): ResearchResult;

	/**
	 * Keywords a competitor page ranks for in organic results, highest search volume first.
	 *
	 * @param string $url    Page URL (https://…).
	 * @param Market $market Market.
	 * @param int    $limit  Maximum keywords.
	 * @return ResearchResult data: ['keywords' => array<int, array>] each with keyword metrics plus position, url and etv.
	 * @throws ProviderException On failure.
	 */
	public function rankedKeywords( string $url, Market $market, int $limit = 10 ): ResearchResult;
}
