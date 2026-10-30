<?php
/**
 * Plugin-level version history of generated content.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Projects\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last MAX snapshots of a project's content (after each generation,
 * manual save and before each restore). Saved WordPress drafts additionally get
 * normal WordPress revisions.
 */
class VersionStore {

	public const MAX = 10;

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository $projects Projects.
	 */
	public function __construct( private readonly ProjectRepository $projects ) {}

	/**
	 * Add a snapshot.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $content    Content.
	 * @param string               $label      Label.
	 * @return string Version id.
	 */
	public function snapshot( int $project_id, array $content, string $label ): string {
		$versions = $this->all( $project_id );
		$id       = substr( md5( wp_json_encode( $content ) . microtime() ), 0, 12 );
		array_unshift(
			$versions,
			array(
				'id'      => $id,
				'at'      => time(),
				'label'   => mb_substr( $label, 0, 80 ),
				'user'    => get_current_user_id(),
				'words'   => ContentModel::wordCount( ContentModel::bodyHtml( $content ) ),
				'content' => $content,
			)
		);
		$this->projects->save( $project_id, 'versions', array( 'items' => array_slice( $versions, 0, self::MAX ) ) );
		return $id;
	}

	/**
	 * All versions, newest first.
	 *
	 * @param int $project_id Project.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $project_id ): array {
		return (array) ( $this->projects->section( $project_id, 'versions' )['items'] ?? array() );
	}

	/**
	 * One version.
	 *
	 * @param int    $project_id Project.
	 * @param string $id         Version id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $project_id, string $id ): ?array {
		foreach ( $this->all( $project_id ) as $version ) {
			if ( $version['id'] === $id ) {
				return $version;
			}
		}
		return null;
	}

	/**
	 * List without content bodies.
	 *
	 * @param int $project_id Project.
	 * @return array<int, array<string, mixed>>
	 */
	public function summaries( int $project_id ): array {
		return array_map(
			static function ( array $v ): array {
				unset( $v['content'] );
				return $v;
			},
			$this->all( $project_id )
		);
	}
}
