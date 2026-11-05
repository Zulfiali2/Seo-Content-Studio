<?php
/**
 * Website project ZIP import endpoints.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Import\ImportedPage;
use AiSeoContentStudio\WebsiteStudio\Import\ImportException;
use AiSeoContentStudio\WebsiteStudio\Import\ImportService;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (all need aiscs_generate_content; uploads and builds also need upload_files; a token can
 * only be used by the user who uploaded it, or by a settings manager):
 *
 * - POST   /design/import/upload                  multipart "archive": validate, store, analyse
 * - POST   /design/import/{token}/analyze         { entry }: analyse another entry page
 * - POST   /design/import/{token}/preview         { target: original|converted, settings }
 * - POST   /design/import/{token}/build           { settings }: one step; repeat until done
 * - DELETE /design/import/{token}                 discard the upload
 * - GET    /design/import/targets                 imported drafts the user may update
 * - GET    /design/import/pages/{id}/scripts      scripts stored with an imported draft
 * - POST   /design/import/pages/{id}/scripts      { approve: [hash…] } (needs unfiltered_html)
 */
class ProjectImportController extends RestController {

	private const TOKEN = '(?P<token>[a-f0-9]{32})';

	/**
	 * Constructor.
	 *
	 * @param StudioServices $studio  Studio services.
	 * @param ImportService  $service Import service.
	 * @param AuditLog       $audit   Audit log.
	 */
	public function __construct(
		private readonly StudioServices $studio,
		private readonly ImportService $service,
		private readonly AuditLog $audit = new AuditLog(),
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$settings = array(
			'settings' => array(
				'type'    => 'object',
				'default' => array(),
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/import/upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'canImport' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/import/' . self::TOKEN . '/analyze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => array(
					'entry' => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 500,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/import/' . self::TOKEN . '/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => $settings + array(
					'target' => array(
						'type'    => 'string',
						'enum'    => array( 'original', 'converted' ),
						'default' => 'converted',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/import/' . self::TOKEN . '/build',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build' ),
				'permission_callback' => array( $this, 'canImport' ),
				'args'                => $settings,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/import/' . self::TOKEN,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'discard' ),
				'permission_callback' => array( $this, 'canGenerate' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/import/targets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'targets' ),
				'permission_callback' => array( $this, 'canGenerate' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/import/pages/(?P<id>\d+)/scripts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'scripts' ),
					'permission_callback' => array( $this, 'canEditImported' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approveScripts' ),
					'permission_callback' => array( $this, 'canApproveScripts' ),
					'args'                => array(
						'approve' => array(
							'type'    => 'array',
							'items'   => array(
								'type'    => 'string',
								'pattern' => '^[a-f0-9]{64}$',
							),
							'default' => array(),
						),
					),
				),
			)
		);
	}

	/* ------------------------------------------------------------ Permissions */

	/**
	 * Generate content and upload files (assets go to the Media Library).
	 */
	public function canImport(): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		return current_user_can( 'upload_files' ) ? true : $this->forbidden();
	}

	/**
	 * An imported draft the user can edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canEditImported( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$id     = (int) $request->get_param( 'id' );
		$record = $this->studio->pages()->forPage( $id );
		if ( null === $record || 'imported' !== ( $record['generation_type'] ?? '' ) ) {
			return new \WP_Error( 'aiscs_not_found', __( 'This page was not created by a project import.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_post', $id ) ? true : $this->forbidden();
	}

	/**
	 * Approving scripts is the same trust WordPress gives to publishing a <script>: unfiltered_html.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canApproveScripts( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canEditImported( $request );
		if ( true !== $can ) {
			return $can;
		}
		return current_user_can( 'unfiltered_html' ) ? true : new \WP_Error( 'aiscs_forbidden', __( 'Only users who may publish unfiltered HTML (normally administrators) can approve scripts.', 'ai-seo-content-studio' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/* ------------------------------------------------------------ Handlers */

	/**
	 * Upload a ZIP.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function upload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$file = $request->get_file_params()['archive'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'] ) ) {
			return new \WP_Error( 'aiscs_zip_missing', __( 'Choose a ZIP file to upload.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$message = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
				/* translators: %s: server upload limit. */
				? sprintf( __( 'The ZIP is larger than this server accepts (%s). Ask your host to raise upload_max_filesize and post_max_size, or make the ZIP smaller.', 'ai-seo-content-studio' ), size_format( wp_max_upload_size() ) )
				: __( 'The upload did not complete. Try again.', 'ai-seo-content-studio' );
			return new \WP_Error( 'aiscs_upload_failed', $message, array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new \WP_Error( 'aiscs_upload_failed', __( 'The upload could not be verified. Try again.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		try {
			return new \WP_REST_Response( $this->service->upload( (string) $file['tmp_name'], (string) $file['name'], get_current_user_id() ), 201 );
		} catch ( ImportException $e ) {
			return self::importError( $e );
		}
	}

	/**
	 * Analyse another entry page.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function analyze( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->service->analyze( (string) $request->get_param( 'token' ), get_current_user_id(), (string) $request->get_param( 'entry' ) ) );
		} catch ( ImportException $e ) {
			return self::importError( $e );
		}
	}

	/**
	 * Preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->service->preview( (string) $request->get_param( 'token' ), get_current_user_id(), (string) $request->get_param( 'target' ), (array) $request->get_param( 'settings' ) ) );
		} catch ( ImportException $e ) {
			return self::importError( $e );
		}
	}

	/**
	 * One build step.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function build( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
		}
		try {
			return new \WP_REST_Response( $this->service->build( (string) $request->get_param( 'token' ), get_current_user_id(), (array) $request->get_param( 'settings' ) ) );
		} catch ( ImportException $e ) {
			return self::importError( $e );
		} catch ( DraftException $e ) {
			return new \WP_Error(
				'aiscs_' . $e->reason,
				$e->getMessage(),
				array(
					'status'   => $e->status(),
					'errors'   => array_slice( $e->errors, 0, 30 ),
					'warnings' => array_slice( $e->warnings, 0, 30 ),
				)
			);
		}
	}

	/**
	 * Discard an upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function discard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$this->service->discard( (string) $request->get_param( 'token' ), get_current_user_id() );
		} catch ( ImportException $e ) {
			return self::importError( $e );
		}
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Imported drafts the user may update.
	 */
	public function targets(): \WP_REST_Response {
		$user  = get_current_user_id();
		$items = array();
		$list  = $this->studio->pages()->listFor( $user, 1, 50 );
		foreach ( $list['items'] as $record ) {
			$page = (int) $record['page_id'];
			if ( 'imported' !== ( $record['generation_type'] ?? '' ) || ! current_user_can( 'edit_post', $page ) || ! in_array( get_post_status( $page ), array( 'draft', 'pending', 'private' ), true ) ) {
				continue;
			}
			$items[] = array(
				'id'    => $page,
				'title' => html_entity_decode( get_the_title( $page ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return new \WP_REST_Response( $items );
	}

	/**
	 * Scripts of an imported draft (code included, for review).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function scripts( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		return new \WP_REST_Response(
			array(
				'scripts'     => self::publicScripts( (array) get_post_meta( $id, ImportedPage::META_SCRIPTS, true ) ),
				'can_approve' => current_user_can( 'unfiltered_html' ),
			)
		);
	}

	/**
	 * Approve (or withdraw approval of) scripts.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function approveScripts( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$hashes  = array_values( array_filter( (array) $request->get_param( 'approve' ), static fn ( $h ): bool => is_string( $h ) && (bool) preg_match( '/^[a-f0-9]{64}$/', $h ) ) );
		$scripts = ImportedPage::setApprovals( (array) get_post_meta( $id, ImportedPage::META_SCRIPTS, true ), $hashes, get_current_user_id() );
		update_post_meta( $id, ImportedPage::META_SCRIPTS, wp_slash( $scripts ) );
		$this->audit->record( 'design.import_scripts', 'page', $id, array( 'approved' => count( ImportedPage::approved( $scripts ) ) ) );
		return new \WP_REST_Response(
			array(
				'scripts'     => self::publicScripts( $scripts ),
				'can_approve' => true,
			)
		);
	}

	/**
	 * Scripts for the browser (code shown for review, at most 20 KB each).
	 *
	 * @param array<int, mixed> $scripts Stored scripts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function publicScripts( array $scripts ): array {
		$out = array();
		foreach ( $scripts as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$code  = (string) ( $s['code'] ?? '' );
			$out[] = array(
				'name'     => (string) ( $s['name'] ?? '' ),
				'hash'     => (string) ( $s['hash'] ?? '' ),
				'bytes'    => strlen( $code ),
				'code'     => mb_substr( $code, 0, 20000 ),
				'features' => array_values( (array) ( $s['features'] ?? array() ) ),
				'risks'    => array_values( (array) ( $s['risks'] ?? array() ) ),
				'level'    => (string) ( $s['level'] ?? 'none' ),
				'approved' => ! empty( $s['approved'] ) && hash_equals( (string) ( $s['hash'] ?? '' ), hash( 'sha256', $code ) ),
			);
		}
		return $out;
	}

	/**
	 * ImportException → WP_Error.
	 *
	 * @param ImportException $e Exception.
	 */
	private static function importError( ImportException $e ): \WP_Error {
		$status = match ( $e->error_code ) {
			'forbidden' => 403,
			'upload_expired' => 404,
			'busy' => 409,
			'zip_unsupported', 'store_failed' => 500,
			default => 400,
		};
		return new \WP_Error( 'aiscs_' . $e->error_code, $e->getMessage(), array( 'status' => $status, 'errors' => array_slice( $e->details, 0, 30 ) ) );
	}
}
