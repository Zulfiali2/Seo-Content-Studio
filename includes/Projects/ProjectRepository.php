<?php
/**
 * Content projects storage.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Projects;

use AiSeoContentStudio\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A project holds one piece of planned content through the workflow: inputs,
 * research, keyword plan, brief and (later phases) outline and drafts.
 *
 * Stored as a private custom post type so WordPress handles authorship, trash
 * and export. Each workflow section is a separate JSON post meta value, so saving
 * one step never rewrites another.
 */
class ProjectRepository {

	public const POST_TYPE = 'aiscs_project';

	/** Meta keys per section. */
	public const SECTIONS = array(
		'inputs'       => '_aiscs_inputs',
		'research'     => '_aiscs_research',
		'keyword_plan' => '_aiscs_keyword_plan',
		'brief'        => '_aiscs_brief',
		'content'      => '_aiscs_content',
		'evaluation'   => '_aiscs_evaluation',
		'versions'     => '_aiscs_versions',
		'draft'        => '_aiscs_draft',
		'optimization' => '_aiscs_optimization',
		'images'       => '_aiscs_images',
		'job'          => '_aiscs_job',
	);

	/** Workflow stage meta key. */
	public const STAGE_META = '_aiscs_stage';

	/**
	 * Register the post type (hooked to init).
	 */
	public static function registerPostType(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'SEO content projects', 'ai-seo-content-studio' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'author' ),
				'can_export'          => true,
			)
		);
	}

	/**
	 * Create a project.
	 *
	 * @param array<string, mixed> $inputs  Sanitised inputs.
	 * @param int                  $user_id Owner.
	 * @return int Post ID (0 on failure).
	 */
	public function create( array $inputs, int $user_id ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => (string) ( $inputs['keyword'] ?? '' ),
				'post_author' => $user_id,
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		$this->save( (int) $id, 'inputs', $inputs );
		$this->setStage( (int) $id, 'created' );
		return (int) $id;
	}

	/**
	 * Whether a post is a live project.
	 *
	 * @param int $id Post ID.
	 */
	public function exists( int $id ): bool {
		$post = get_post( $id );
		return $post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status;
	}

	/**
	 * Owners may access their projects; settings managers may access all.
	 *
	 * @param int $id      Project ID.
	 * @param int $user_id User ID.
	 */
	public function canAccess( int $id, int $user_id ): bool {
		if ( ! $this->exists( $id ) || $user_id <= 0 ) {
			return false;
		}
		if ( ! user_can( $user_id, Capabilities::GENERATE ) ) {
			return false;
		}
		return (int) get_post_field( 'post_author', $id ) === $user_id || user_can( $user_id, Capabilities::MANAGE );
	}

	/**
	 * Meta key of a section; unknown names are a programming error, never a silent no-op.
	 *
	 * @param string $section Section name.
	 * @throws \InvalidArgumentException On an unknown section.
	 */
	private static function key( string $section ): string {
		if ( ! isset( self::SECTIONS[ $section ] ) ) {
			throw new \InvalidArgumentException( 'Unknown project section: ' . esc_html( $section ) );
		}
		return self::SECTIONS[ $section ];
	}

	/**
	 * Read one section.
	 *
	 * @param int    $id      Project ID.
	 * @param string $section Section name.
	 * @return array<string, mixed>|null
	 */
	public function section( int $id, string $section ): ?array {
		$raw = get_post_meta( $id, self::key( $section ), true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Save one section.
	 *
	 * @param int                  $id      Project ID.
	 * @param string               $section Section name.
	 * @param array<string, mixed> $data    Data (already sanitised).
	 */
	public function save( int $id, string $section, array $data ): void {
		update_post_meta( $id, self::key( $section ), wp_slash( (string) wp_json_encode( $data ) ) );
		if ( 'job' !== $section ) {
			// Touch the modified date for the projects list. A direct update: wp_update_post()
			// would run every save_post hook on the site for each workflow save.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cache cleared below.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => current_time( 'mysql' ),
					'post_modified_gmt' => current_time( 'mysql', true ),
				),
				array( 'ID' => $id )
			);
			clean_post_cache( $id );
		}
	}

	/**
	 * Set the workflow stage.
	 *
	 * @param int    $id    Project ID.
	 * @param string $stage Stage.
	 */
	public function setStage( int $id, string $stage ): void {
		update_post_meta( $id, self::STAGE_META, $stage );
	}

	/**
	 * Full project for the REST API.
	 *
	 * @param int $id Project ID.
	 * @return array<string, mixed>
	 */
	public function get( int $id ): array {
		$out = array(
			'id'       => $id,
			'title'    => (string) get_post_field( 'post_title', $id ),
			'author'   => (int) get_post_field( 'post_author', $id ),
			'modified' => (string) get_post_field( 'post_modified_gmt', $id ),
			'stage'    => (string) get_post_meta( $id, self::STAGE_META, true ),
		);
		foreach ( array_keys( self::SECTIONS ) as $section ) {
			$out[ $section ] = $this->section( $id, $section );
		}
		return $out;
	}

	/**
	 * List projects visible to a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page (1-based).
	 * @param int $per     Per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function listFor( int $user_id, int $page = 1, int $per = 20 ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => $per,
			'paged'          => max( 1, $page ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);
		if ( ! user_can( $user_id, Capabilities::MANAGE ) ) {
			$args['author'] = $user_id;
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$inputs  = $this->section( $post->ID, 'inputs' ) ?? array();
			$job     = $this->section( $post->ID, 'job' );
			$items[] = array(
				'id'           => $post->ID,
				'title'        => $post->post_title,
				'content_type' => (string) ( $inputs['content_type'] ?? '' ),
				'market'       => (string) ( $inputs['market']['location_name'] ?? '' ),
				'stage'        => (string) get_post_meta( $post->ID, self::STAGE_META, true ),
				'job_status'   => (string) ( $job['status'] ?? '' ),
				'modified'     => $post->post_modified_gmt,
				'author'       => (int) $post->post_author,
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Move a project to the trash (recoverable).
	 *
	 * @param int $id Project ID.
	 */
	public function trash( int $id ): bool {
		return (bool) wp_trash_post( $id );
	}
}
