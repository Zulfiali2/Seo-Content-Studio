<?php
/**
 * Revision history: list, compare, restore, undo one section.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\History;

use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Persistence\RevisionRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * Restoring never deletes history: it writes the old state as a new revision (and the state
 * before it is captured first if it was edited in Elementor), so a restore can be undone too.
 */
class RevisionService {

	/**
	 * Constructor.
	 *
	 * @param StudioServices $studio Studio services.
	 */
	public function __construct( private readonly StudioServices $studio ) {}

	/**
	 * Revisions of a page, newest first, with whether the page changed since the newest one.
	 *
	 * @param int $page_id Page.
	 * @return array{items: list<array<string, mixed>>, edited_since: bool}
	 */
	public function history( int $page_id ): array {
		$items  = $this->studio->revisions()->listFor( $page_id );
		$latest = $items[0]['hash'] ?? '';
		foreach ( $items as $i => $item ) {
			unset( $items[ $i ]['hash'] );
		}
		return array(
			'items'        => $items,
			'edited_since' => '' !== $latest && RevisionRepository::hash( $this->studio->bridge()->elements( $page_id ) ) !== $latest,
		);
	}

	/**
	 * Compare two revisions (0 = the page as it is now).
	 *
	 * @param int $page_id Page.
	 * @param int $from    Older revision.
	 * @param int $to      Newer revision (0 = current).
	 * @return array<string, mixed>
	 * @throws DraftException When a revision has no snapshot.
	 */
	public function compare( int $page_id, int $from, int $to ): array {
		$a = $this->elements( $page_id, $from );
		$b = 0 === $to ? $this->studio->bridge()->elements( $page_id ) : $this->elements( $page_id, $to );
		return array(
			'from' => $from,
			'to'   => $to,
			'diff' => RevisionDiff::compare( $a, $b ),
			'outline_from' => ElementTree::outline( $a ),
			'outline_to'   => ElementTree::outline( $b ),
		);
	}

	/**
	 * Restore the whole page to a revision.
	 *
	 * @param int $page_id  Page.
	 * @param int $revision Revision.
	 * @param int $user_id  User.
	 * @return array<string, mixed> DraftManager::update() result.
	 * @throws DraftException When it cannot be restored.
	 */
	public function restore( int $page_id, int $revision, int $user_id ): array {
		$row = $this->snapshot( $page_id, $revision );
		$change = array(
			'action'  => 'restore',
			/* translators: %d: revision number. */
			'summary' => sprintf( __( 'Restored revision %d', 'ai-seo-content-studio' ), $revision ),
			'meta'    => array( 'restored' => $revision ),
		);
		if ( array() !== $row['snapshot']['document'] ) {
			$change['document']    = $row['snapshot']['document'];
			$change['section_map'] = $row['snapshot']['section_map'];
		}
		return $this->studio->drafts()->update( $page_id, $row['snapshot']['elements'], $user_id, DocumentValidator::MODE_CLONED, $change );
	}

	/**
	 * Put one section (top-level element) back as it was in a revision.
	 *
	 * @param int    $page_id    Page.
	 * @param int    $revision   Revision.
	 * @param string $element_id Top-level element id.
	 * @param int    $user_id    User.
	 * @return array<string, mixed> DraftManager::update() result.
	 * @throws DraftException When it cannot be restored.
	 */
	public function restoreSection( int $page_id, int $revision, string $element_id, int $user_id ): array {
		$row      = $this->snapshot( $page_id, $revision );
		$old      = array_values( $row['snapshot']['elements'] );
		$position = ElementTree::topIndex( $old, $element_id );
		if ( $position < 0 ) {
			throw new DraftException( 'section_missing', array( __( 'That section is not in this revision.', 'ai-seo-content-studio' ) ) );
		}
		$current  = $this->studio->bridge()->elements( $page_id );
		$replaced = ElementTree::replaceTop( $current, $element_id, 1, array( $old[ $position ] ) );
		$elements = $replaced ?? ElementTree::insertTop( $current, $position, array( $old[ $position ] ) );

		// Keep the design document in step when the section came from it.
		$change = array(
			'action'     => 'restore',
			/* translators: 1: section label, 2: revision number. */
			'summary'    => sprintf( __( 'Restored "%1$s" from revision %2$d', 'ai-seo-content-studio' ), ElementTree::label( $old[ $position ] ), $revision ),
			'section_id' => $element_id,
			'meta'       => array( 'restored' => $revision ),
		);
		$record     = $this->studio->pages()->forPage( $page_id );
		$section_id = (string) array_search( $element_id, (array) $row['snapshot']['section_map'], true );
		if ( null !== $record && '' !== $section_id ) {
			foreach ( (array) ( $row['snapshot']['document']['sections'] ?? array() ) as $section ) {
				if ( ( $section['id'] ?? '' ) !== $section_id ) {
					continue;
				}
				$document = (array) $record['document'];
				foreach ( (array) ( $document['sections'] ?? array() ) as $i => $mine ) {
					if ( ( $mine['id'] ?? '' ) === $section_id ) {
						$document['sections'][ $i ] = $section;
						$map                        = (array) $record['section_map'];
						$map[ $section_id ]         = $element_id;
						$change['document']         = $document;
						$change['section_map']      = $map;
					}
				}
			}
		}
		return $this->studio->drafts()->update( $page_id, $elements, $user_id, DocumentValidator::MODE_CLONED, $change );
	}

	/**
	 * Elements of a revision.
	 *
	 * @param int $page_id  Page.
	 * @param int $revision Revision.
	 * @return array<int, mixed>
	 * @throws DraftException When it has no snapshot.
	 */
	private function elements( int $page_id, int $revision ): array {
		return $this->snapshot( $page_id, $revision )['snapshot']['elements'];
	}

	/**
	 * A revision with its snapshot.
	 *
	 * @param int $page_id  Page.
	 * @param int $revision Revision.
	 * @return array<string, mixed>
	 * @throws DraftException When it is missing or was too large to store.
	 */
	private function snapshot( int $page_id, int $revision ): array {
		$row = $this->studio->revisions()->get( $page_id, $revision );
		if ( null === $row ) {
			throw new DraftException( 'not_found', array( __( 'That revision does not exist.', 'ai-seo-content-studio' ) ) );
		}
		if ( null === $row['snapshot'] ) {
			throw new DraftException( 'no_snapshot', array( __( 'This revision was too large to store a snapshot, so it cannot be restored.', 'ai-seo-content-studio' ) ) );
		}
		return $row;
	}
}
