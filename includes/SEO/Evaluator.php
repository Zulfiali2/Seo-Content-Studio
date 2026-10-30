<?php
/**
 * Runs and stores SEO evaluations for a project.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\SEO;

use AiSeoContentStudio\Content\ContentModel;
use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Combines deterministic checks (always, free) with the latest AI review
 * (on request, billable). Each run keeps the previous scores so the UI can show
 * before/after.
 */
class Evaluator {

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository       $projects Projects.
	 * @param DeterministicChecks     $checks   Checks.
	 * @param SettingsRepository|null $settings Settings (writing style checks).
	 */
	public function __construct( private readonly ProjectRepository $projects, private readonly DeterministicChecks $checks = new DeterministicChecks(), private readonly ?SettingsRepository $settings = null ) {}

	/**
	 * Document view of project content.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $inputs  Inputs.
	 * @return array<string, mixed>
	 */
	public static function document( array $content, array $inputs ): array {
		$faqs = false === ( $content['faqs_enabled'] ?? true ) ? array() : (array) ( $content['faqs'] ?? array() );
		return array(
			'title'            => (string) ( $content['title'] ?? '' ),
			'h1'               => (string) ( $content['h1'] ?? $content['title'] ?? '' ),
			'meta_title'       => (string) ( $content['meta']['meta_title'] ?? '' ),
			'meta_description' => (string) ( $content['meta']['meta_description'] ?? '' ),
			'slug'             => (string) ( $content['meta']['slug'] ?? '' ),
			'schema_type'      => (string) ( $content['meta']['schema_type'] ?? '' ),
			'content_type'     => (string) ( $inputs['content_type'] ?? '' ),
			'html'             => ContentModel::bodyHtml( $content ),
			'claims'           => (array) ( $content['claims_to_verify'] ?? array() ),
			'faq_count'        => count( $faqs ),
			'faq_answered'     => count( array_filter( $faqs, static fn ( $f ) => '' !== trim( (string) $f['answer_html'] ) ) ),
		);
	}

	/**
	 * Run deterministic checks and store them (keeps the latest AI review if
	 * it was made for the same content).
	 *
	 * @param int  $project_id Project.
	 * @param bool $keep_ai    Keep the existing AI review.
	 * @return array<string, mixed>|null Report, or null when there is no content.
	 */
	public function evaluate( int $project_id, bool $keep_ai = false ): ?array {
		$content = $this->projects->section( $project_id, 'content' );
		if ( null === $content ) {
			return null;
		}
		$inputs   = $this->projects->section( $project_id, 'inputs' ) ?? array();
		$plan     = $this->projects->section( $project_id, 'keyword_plan' ) ?? array();
		$brief    = $this->projects->section( $project_id, 'brief' ) ?? array();
		$previous = $this->projects->section( $project_id, 'evaluation' );
		$doc      = self::document( $content, $inputs );

		$doc['duplicate_title_ids'] = $this->duplicateTitles( $doc['title'], $project_id );
		$draft                      = $this->projects->section( $project_id, 'draft' ) ?? array();
		$doc['same_keyword_pages']  = Cannibalization::lines(
			Cannibalization::find(
				(string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' ),
				array( (int) ( $draft['post_id'] ?? 0 ), (int) ( $inputs['source_post_id'] ?? 0 ) )
			)
		);
		if ( null !== $this->settings ) {
			$profile             = WritingProfile::fromSettings( $this->settings );
			$doc['banned_words'] = $profile->bannedWords();
			$doc['no_em_dashes'] = (bool) $profile->style( 'no_em_dashes' );
		}

		$report = array(
			'deterministic'   => $this->checks->run( $doc, $plan, $brief, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'ai'              => $keep_ai ? ( $previous['ai'] ?? null ) : null,
			'previous_scores' => $previous ? array(
				'deterministic' => $previous['deterministic']['scores']['overall'] ?? null,
				'ai'            => $previous['ai']['overall'] ?? null,
				'at'            => $previous['at'] ?? null,
			) : null,
			'content_hash'    => md5( (string) wp_json_encode( $content ) ),
			'at'              => time(),
		);
		$this->projects->save( $project_id, 'evaluation', $report );
		return $report;
	}

	/**
	 * Store an AI review on the current report.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $ai         Sanitised AI review.
	 */
	public function storeAi( int $project_id, array $ai ): void {
		$report = $this->evaluate( $project_id );
		if ( null === $report ) {
			return;
		}
		$report['ai'] = $ai;
		$this->projects->save( $project_id, 'evaluation', $report );
	}

	/**
	 * Published posts/pages with the same title (measurable duplication risk).
	 *
	 * @param string $title      Title.
	 * @param int    $project_id Project.
	 * @return int[]
	 */
	private function duplicateTitles( string $title, int $project_id ): array {
		if ( '' === trim( $title ) || ! class_exists( '\WP_Query' ) ) {
			return array();
		}
		$draft = $this->projects->section( $project_id, 'draft' );
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'title'          => $title,
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'post__not_in'   => array_filter( array( (int) ( $draft['post_id'] ?? 0 ) ) ),
			)
		);
		return array_map( 'intval', $query->posts );
	}
}
