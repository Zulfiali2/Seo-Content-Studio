<?php
/**
 * Temporary preview pages.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Preview;

use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * A proposal (a regenerated section, a library item) is previewed by rendering it in a
 * separate, temporary draft page, so Elementor renders it exactly as it would on the real
 * page, while the real page and any original stay untouched.
 *
 * Preview pages are drafts: WordPress shows them only to logged-in users who may edit them,
 * through its nonce-protected preview link. They carry META with their expiry, are hidden
 * from the Pages list, are deleted when the proposal is approved or discarded, and are pruned
 * daily after TTL.
 */
class PreviewManager {

	public const META = '_aiscs_preview';

	public const TTL = DAY_IN_SECONDS;

	/**
	 * Constructor. Services are resolved when used: Elementor registers its widgets late.
	 *
	 * @param StudioServices $studio Studio services.
	 */
	public function __construct( private readonly StudioServices $studio ) {}

	/**
	 * Create a preview page.
	 *
	 * @param array<int, mixed>    $elements Elements to show.
	 * @param array<string, mixed> $settings Elementor page settings.
	 * @param int                  $user_id  User.
	 * @param string               $title    Title.
	 * @param string               $template Page template ("" = Elementor full width with header/footer).
	 * @return array{preview_id: int, preview_url: string}
	 * @throws DraftException When the elements are invalid or the page cannot be made.
	 */
	public function create( array $elements, array $settings, int $user_id, string $title, string $template = '' ): array {
		if ( ! $this->studio->posts()->can( $user_id, 'edit_pages' ) ) {
			throw new DraftException( 'forbidden', array( __( 'You are not allowed to create pages.', 'ai-seo-content-studio' ) ) );
		}
		$report = $this->studio->drafts()->validate( $elements, DocumentValidator::MODE_CLONED );
		if ( array() !== $report['errors'] ) {
			throw new DraftException( 'invalid_document', $report['errors'], $report['warnings'] );
		}
		/* translators: %s: page title. */
		$id = $this->studio->posts()->insertDraft( sprintf( __( 'AI Studio preview (temporary): %s', 'ai-seo-content-studio' ), $title ), '', $user_id, 'page' );
		if ( $id <= 0 ) {
			throw new DraftException( 'not_created', array( __( 'The preview could not be created.', 'ai-seo-content-studio' ) ) );
		}
		$this->studio->posts()->setMeta( $id, self::META, (string) ( time() + self::TTL ) );
		$this->studio->posts()->setMeta( $id, '_wp_page_template', '' !== $template ? $template : 'elementor_header_footer' );
		if ( ! $this->studio->bridge()->save( $id, array_values( $elements ), $settings ) ) {
			$this->studio->posts()->delete( $id );
			throw new DraftException( 'elementor_rejected', array( __( 'Elementor did not accept the preview.', 'ai-seo-content-studio' ) ) );
		}
		return array(
			'preview_id'  => $id,
			'preview_url' => $this->studio->posts()->previewUrl( $id ),
		);
	}

	/**
	 * Delete a preview page (only pages made here).
	 *
	 * @param int $id Preview page.
	 */
	public function delete( int $id ): void {
		if ( $id > 0 && '' !== (string) $this->studio->posts()->meta( $id, self::META ) ) {
			$this->studio->posts()->delete( $id );
		}
	}

	/**
	 * Delete expired preview pages.
	 *
	 * @return int Deleted.
	 */
	public function prune(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily maintenance, small set.
					array(
						'key'     => self::META,
						'value'   => (string) time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return count( $ids );
	}

	/**
	 * Hide preview pages from the admin Pages list.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function hideFromLists( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'page' !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => self::META,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}
}
