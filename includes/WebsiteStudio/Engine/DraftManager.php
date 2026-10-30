<?php
/**
 * Creates and updates the Website Studio's draft pages.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

use AiSeoContentStudio\Elementor\ElementorBridgeInterface;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The only class in the studio that writes Elementor data to a page.
 *
 * Rules:
 * - New content always goes into a NEW draft page created here; nothing is published.
 * - An existing page is written only when the studio created it (a GeneratedPageRepository
 *   row exists), it is still a draft/pending/private page, and the user can edit it.
 *   Original pages, and studio pages that were since published, are never overwritten.
 * - Every document passes DocumentValidator before saving; nothing invalid is written.
 * - Saving goes through Elementor's Document::save() (via ElementorBridge), never by writing
 *   _elementor_data directly. If Elementor rejects the document, the new draft is deleted.
 */
class DraftManager {

	public const META_MARKER = '_aiscs_studio_page';

	private const WRITABLE_STATUSES = array( 'draft', 'pending', 'private', 'auto-draft' );

	/**
	 * Constructor.
	 *
	 * @param ElementorBridgeInterface $bridge    Elementor bridge.
	 * @param ElementorCompat          $compat    Installed Elementor.
	 * @param DocumentValidator        $validator Validator.
	 * @param GeneratedPageRepository  $records   Ownership records.
	 * @param AuditLog                 $audit     Audit log.
	 * @param PostGateway              $posts     WordPress posts.
	 */
	public function __construct(
		private readonly ElementorBridgeInterface $bridge,
		private readonly ElementorCompat $compat,
		private readonly DocumentValidator $validator,
		private readonly GeneratedPageRepository $records,
		private readonly AuditLog $audit,
		private readonly PostGateway $posts = new PostGateway(),
	) {}

	/**
	 * Create a new draft page from an Elementor element tree.
	 *
	 * @param array<int, mixed>    $elements      Elements.
	 * @param array<string, mixed> $page_settings Elementor page settings (hide_title, ...).
	 * @param array<string, mixed> $args          title, slug, user_id, post_type, mode, generation_type,
	 *                                            source_page_id, project_id, profile_id, section_map,
	 *                                            document, notes, template, thumbnail_id.
	 * @return array{page_id: int, edit_url: string, elementor_url: string, preview_url: string, warnings: string[], stats: array<string, int>}
	 * @throws DraftException When validation, permissions or saving fail.
	 */
	public function create( array $elements, array $page_settings, array $args ): array {
		$user = (int) ( $args['user_id'] ?? 0 );
		$type = 'post' === ( $args['post_type'] ?? 'page' ) ? 'post' : 'page';
		$mode = self::modeFor( (string) ( $args['mode'] ?? DocumentValidator::MODE_GENERATED ) );

		$blocker = $this->compat->blocker();
		if ( '' !== $blocker ) {
			throw new DraftException( $blocker, array( self::blockerMessage( $blocker ) ) );
		}
		if ( ! $this->posts->can( $user, 'page' === $type ? 'edit_pages' : 'edit_posts' ) ) {
			throw new DraftException( 'forbidden', array( __( 'You are not allowed to create pages.', 'ai-seo-content-studio' ) ) );
		}
		$report = $this->validator->validate( $elements, $mode );
		if ( array() !== $report['errors'] ) {
			throw new DraftException( 'invalid_document', $report['errors'], $report['warnings'] );
		}

		$title   = trim( (string) ( $args['title'] ?? '' ) );
		$page_id = $this->posts->insertDraft( '' === $title ? __( 'Untitled page', 'ai-seo-content-studio' ) : $title, (string) ( $args['slug'] ?? '' ), $user, $type );
		if ( $page_id <= 0 ) {
			throw new DraftException( 'not_created', array( __( 'The draft page could not be created.', 'ai-seo-content-studio' ) ) );
		}

		// Ownership marker first, so a later update can prove the studio created this page.
		$record = $this->records->insert(
			array(
				'project_id'      => (int) ( $args['project_id'] ?? 0 ),
				'page_id'         => $page_id,
				'source_page_id'  => (int) ( $args['source_page_id'] ?? 0 ),
				'generation_type' => (string) ( $args['generation_type'] ?? 'generated' ),
				'profile_id'      => (int) ( $args['profile_id'] ?? 0 ),
				'created_by'      => $user,
				'section_map'     => (array) ( $args['section_map'] ?? array() ),
				'document'        => (array) ( $args['document'] ?? array() ),
				'notes'           => array_merge( (array) ( $args['notes'] ?? array() ), $report['warnings'] ),
			)
		);
		if ( $record <= 0 ) {
			$this->posts->delete( $page_id );
			throw new DraftException( 'not_recorded', array( __( 'The draft could not be recorded. Check that the plugin database tables exist (deactivate and reactivate the plugin).', 'ai-seo-content-studio' ) ) );
		}
		$this->posts->setMeta( $page_id, self::META_MARKER, (string) $record );
		if ( '' !== (string) ( $args['template'] ?? '' ) ) {
			$this->posts->setMeta( $page_id, '_wp_page_template', (string) $args['template'] );
		}
		if ( (int) ( $args['thumbnail_id'] ?? 0 ) > 0 ) {
			$this->posts->setMeta( $page_id, '_thumbnail_id', (int) $args['thumbnail_id'] );
		}

		if ( ! $this->bridge->save( $page_id, array_values( $elements ), $page_settings ) ) {
			// Elementor refused the document: remove the empty draft this request just made.
			$this->records->deleteForPage( $page_id );
			$this->posts->delete( $page_id );
			throw new DraftException( 'elementor_rejected', array( __( 'Elementor did not accept the page. Nothing was saved.', 'ai-seo-content-studio' ) ) );
		}

		$warnings = $report['warnings'];
		if ( count( $this->bridge->elements( $page_id ) ) !== count( $elements ) ) {
			$warnings[] = __( 'Elementor stored a different number of sections than were generated. Open the page in Elementor and check it.', 'ai-seo-content-studio' );
		}
		$this->audit->record(
			'design.draft_create',
			'page',
			$page_id,
			array(
				'type'   => (string) ( $args['generation_type'] ?? 'generated' ),
				'source' => (int) ( $args['source_page_id'] ?? 0 ),
			),
			$user
		);
		return $this->result( $page_id, $warnings, $report['stats'] );
	}

	/**
	 * Replace the Elementor document of a studio-created draft.
	 *
	 * @param int               $page_id  Page.
	 * @param array<int, mixed> $elements Elements.
	 * @param int               $user_id  User.
	 * @param string            $mode     Validation mode.
	 * @return array{page_id: int, edit_url: string, elementor_url: string, preview_url: string, warnings: string[], stats: array<string, int>}
	 * @throws DraftException When the page may not be written or the document is invalid.
	 */
	public function update( int $page_id, array $elements, int $user_id, string $mode = DocumentValidator::MODE_GENERATED ): array {
		$this->assertWritable( $page_id, $user_id );
		$report = $this->validator->validate( $elements, self::modeFor( $mode ) );
		if ( array() !== $report['errors'] ) {
			throw new DraftException( 'invalid_document', $report['errors'], $report['warnings'] );
		}
		if ( ! $this->bridge->save( $page_id, array_values( $elements ), array() ) ) {
			throw new DraftException( 'elementor_rejected', array( __( 'Elementor did not accept the change. The page was not changed.', 'ai-seo-content-studio' ) ) );
		}
		$this->audit->record( 'design.draft_update', 'page', $page_id, array(), $user_id );
		return $this->result( $page_id, $report['warnings'], $report['stats'] );
	}

	/**
	 * Throw unless the studio created this page, it is unpublished and the user can edit it.
	 *
	 * @param int $page_id Page.
	 * @param int $user_id User.
	 * @throws DraftException When the page must not be written.
	 */
	public function assertWritable( int $page_id, int $user_id ): void {
		$record = $this->records->forPage( $page_id );
		if ( null === $record ) {
			throw new DraftException( 'not_studio_page', array( __( 'This page was not created by AI Website Studio, so it is never changed. Clone it instead.', 'ai-seo-content-studio' ) ) );
		}
		$status = $this->posts->status( $page_id );
		if ( '' === $status ) {
			throw new DraftException( 'not_found', array( __( 'The page no longer exists.', 'ai-seo-content-studio' ) ) );
		}
		if ( ! in_array( $status, self::WRITABLE_STATUSES, true ) ) {
			throw new DraftException( 'published', array( __( 'This page has been published, so AI Website Studio no longer changes it. Clone it to work on a new draft.', 'ai-seo-content-studio' ) ) );
		}
		if ( ! $this->posts->can( $user_id, 'edit_post', $page_id ) ) {
			throw new DraftException( 'forbidden', array( __( 'You are not allowed to edit this page.', 'ai-seo-content-studio' ) ) );
		}
	}

	/**
	 * Result links.
	 *
	 * @param int                $page_id  Page.
	 * @param string[]           $warnings Warnings.
	 * @param array<string, int> $stats    Stats.
	 * @return array{page_id: int, edit_url: string, elementor_url: string, preview_url: string, warnings: string[], stats: array<string, int>}
	 */
	private function result( int $page_id, array $warnings, array $stats ): array {
		return array(
			'page_id'       => $page_id,
			'edit_url'      => $this->posts->editUrl( $page_id ),
			'elementor_url' => $this->bridge->editUrl( $page_id ),
			'preview_url'   => $this->posts->previewUrl( $page_id ),
			'warnings'      => array_values( array_unique( $warnings ) ),
			'stats'         => $stats,
		);
	}

	/**
	 * Valid validation mode.
	 *
	 * @param string $mode Mode.
	 */
	private static function modeFor( string $mode ): string {
		return DocumentValidator::MODE_CLONED === $mode ? DocumentValidator::MODE_CLONED : DocumentValidator::MODE_GENERATED;
	}

	/**
	 * Message for a compatibility blocker.
	 *
	 * @param string $blocker Blocker.
	 */
	public static function blockerMessage( string $blocker ): string {
		return match ( $blocker ) {
			'elementor_inactive' => __( 'Elementor is not active. Install and activate Elementor (the free version is enough) to create Elementor pages.', 'ai-seo-content-studio' ),
			/* translators: %s: version. */
			'elementor_too_old'  => sprintf( __( 'Elementor %s or newer is needed. Update Elementor.', 'ai-seo-content-studio' ), ElementorCompat::MIN_VERSION ),
			default              => __( 'Elementor’s basic widgets (Heading, Text Editor, Button, Image) are not available on this site.', 'ai-seo-content-studio' ),
		};
	}
}
