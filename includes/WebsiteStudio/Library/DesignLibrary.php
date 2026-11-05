<?php
/**
 * Design library: save, preview, duplicate and insert reusable designs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Library;

use AiSeoContentStudio\WebsiteStudio\Cloner\ElementTreeCopier;
use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Persistence\LibraryRepository;
use AiSeoContentStudio\WebsiteStudio\Preview\PreviewManager;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * Items are copied from a page the user may edit (the page is only read). A top-level
 * element becomes a section item; any element inside one becomes a component. Inserting
 * copies the item with new element ids into a studio draft, wrapped in a section when it is
 * a component, and stores a revision.
 */
class DesignLibrary {

	/**
	 * Constructor.
	 *
	 * @param LibraryRepository $items    Items.
	 * @param StudioServices    $studio   Studio services.
	 * @param PreviewManager    $previews Preview pages.
	 */
	public function __construct(
		private readonly LibraryRepository $items,
		private readonly StudioServices $studio,
		private readonly PreviewManager $previews,
	) {}

	/**
	 * Save an element of a page.
	 *
	 * @param int    $user_id    User.
	 * @param int    $page_id    Page (the user may edit it).
	 * @param string $element_id Element.
	 * @param string $name       Name.
	 * @param string $category   Category.
	 * @param int    $profile_id Brand profile it belongs to (already access-checked).
	 * @return array<string, mixed> Item.
	 * @throws DraftException When the element is missing, invalid or too large.
	 */
	public function save( int $user_id, int $page_id, string $element_id, string $name, string $category, int $profile_id = 0 ): array {
		$elements = $this->studio->bridge()->elements( $page_id );
		$element  = ElementTree::find( $elements, $element_id );
		if ( null === $element ) {
			throw new DraftException( 'not_found', array( __( 'That element is not on the page.', 'ai-seo-content-studio' ) ) );
		}
		$kind   = ElementTree::topIndex( $elements, $element_id ) >= 0 ? 'section' : 'component';
		$report = $this->studio->drafts()->validate( array( $this->wrap( $element, $kind ) ), DocumentValidator::MODE_CLONED );
		if ( array() !== $report['errors'] ) {
			throw new DraftException( 'invalid_document', $report['errors'], $report['warnings'] );
		}
		if ( strlen( (string) wp_json_encode( $element ) ) > LibraryRepository::MAX_BYTES ) {
			throw new DraftException( 'invalid_document', array( __( 'This element is too large for the design library (1 MB at most).', 'ai-seo-content-studio' ) ) );
		}
		$id = $this->items->insert(
			array(
				'owner_id'        => $user_id,
				'name'            => '' !== trim( $name ) ? $name : ElementTree::label( $element ),
				'kind'            => $kind,
				'category'        => $category,
				'profile_id'      => $profile_id,
				'source_page_id'  => $page_id,
				'elements'        => array( $element ),
				'summary'         => self::summary( $element ),
				'uses_containers' => self::usesContainers( $element ),
			)
		);
		if ( $id <= 0 ) {
			throw new DraftException( 'not_recorded', array( __( 'The item could not be saved. Check that the plugin database tables exist (deactivate and reactivate the plugin).', 'ai-seo-content-studio' ) ) );
		}
		return (array) $this->items->get( $id );
	}

	/**
	 * Duplicate an item.
	 *
	 * @param int $id      Item (access-checked).
	 * @param int $user_id New owner.
	 * @return array<string, mixed> New item.
	 * @throws DraftException When it cannot be copied.
	 */
	public function duplicate( int $id, int $user_id ): array {
		$item = $this->items->get( $id );
		if ( null === $item ) {
			throw new DraftException( 'not_found', array( __( 'Item not found.', 'ai-seo-content-studio' ) ) );
		}
		/* translators: %s: item name. */
		$item['name']     = sprintf( __( '%s (copy)', 'ai-seo-content-studio' ), $item['name'] );
		$item['owner_id'] = $user_id;
		$new              = $this->items->insert( $item );
		if ( $new <= 0 ) {
			throw new DraftException( 'not_recorded', array( __( 'The item could not be copied.', 'ai-seo-content-studio' ) ) );
		}
		return (array) $this->items->get( $new );
	}

	/**
	 * Preview an item on a temporary page.
	 *
	 * @param int $id      Item (access-checked).
	 * @param int $user_id User.
	 * @return array{preview_id: int, preview_url: string}
	 * @throws DraftException When it cannot be previewed.
	 */
	public function preview( int $id, int $user_id ): array {
		$item = $this->usable( $id );
		return $this->previews->create( $this->prepare( $item, array() ), array(), $user_id, (string) $item['name'] );
	}

	/**
	 * Insert an item into a studio draft.
	 *
	 * @param int $id       Item (access-checked).
	 * @param int $page_id  Studio draft.
	 * @param int $position Top-level position (-1 = end).
	 * @param int $user_id  User.
	 * @return array<string, mixed> DraftManager::update() result plus the inserted element id.
	 * @throws DraftException When the page may not be changed or the item does not fit the site.
	 */
	public function insert( int $id, int $page_id, int $position, int $user_id ): array {
		$item    = $this->usable( $id );
		$this->studio->drafts()->assertWritable( $page_id, $user_id );
		$current = $this->studio->bridge()->elements( $page_id );
		$copy    = $this->prepare( $item, $current );
		$result  = $this->studio->drafts()->update(
			$page_id,
			ElementTree::insertTop( $current, $position, $copy ),
			$user_id,
			DocumentValidator::MODE_CLONED,
			array(
				'action'  => 'insert',
				/* translators: %s: item name. */
				'summary' => sprintf( __( 'Inserted "%s" from the design library', 'ai-seo-content-studio' ), $item['name'] ),
				'meta'    => array( 'library_id' => $id ),
			)
		);
		return $result + array( 'element_id' => (string) ( $copy[0]['id'] ?? '' ) );
	}

	/**
	 * An item the current site can render.
	 *
	 * @param int $id Item.
	 * @return array<string, mixed>
	 * @throws DraftException When missing or incompatible.
	 */
	private function usable( int $id ): array {
		$item = $this->items->get( $id );
		if ( null === $item || array() === $item['elements'] ) {
			throw new DraftException( 'not_found', array( __( 'Item not found.', 'ai-seo-content-studio' ) ) );
		}
		if ( $item['uses_containers'] && ! $this->studio->compat()->containers ) {
			throw new DraftException( 'needs_containers', array( __( 'This item uses Flexbox Containers, which are not active on this site (Elementor → Settings → Features).', 'ai-seo-content-studio' ) ) );
		}
		return $item;
	}

	/**
	 * Copy with new ids (unique on the target page), wrapped in a section when needed.
	 *
	 * @param array<string, mixed> $item   Item.
	 * @param array<int, mixed>    $target Target page elements.
	 * @return list<array<string, mixed>>
	 */
	private function prepare( array $item, array $target ): array {
		$ids = new IdGenerator();
		$ids->reserveTree( $target );
		$copy = ( new ElementTreeCopier( $ids ) )->copy( array( $this->wrap( (array) $item['elements'][0], (string) $item['kind'] ) ) );
		return $copy['elements'];
	}

	/**
	 * A component inside a top-level wrapper (container, or section > column).
	 *
	 * @param array<string, mixed> $element Element.
	 * @param string               $kind    section|component.
	 * @return array<string, mixed>
	 */
	private function wrap( array $element, string $kind ): array {
		if ( 'section' === $kind ) {
			return $element;
		}
		if ( $this->studio->compat()->containers ) {
			$element['isInner'] = 'container' === ( $element['elType'] ?? '' );
			return array(
				'id'       => 'a0a0a0a',
				'elType'   => 'container',
				'isInner'  => false,
				'settings' => array(
					'content_width'  => 'boxed',
					'flex_direction' => 'column',
				),
				'elements' => array( $element ),
			);
		}
		if ( 'section' === ( $element['elType'] ?? '' ) ) {
			$element['isInner'] = true; // A section inside a column is an inner section.
		}
		$inner = 'column' === ( $element['elType'] ?? '' ) ? (array) ( $element['elements'] ?? array() ) : array( $element );
		return array(
			'id'       => 'a0a0a0a',
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'a0a0a0b',
					'elType'   => 'column',
					'isInner'  => false,
					'settings' => array( '_column_size' => 100 ),
					'elements' => array_values( $inner ),
				),
			),
		);
	}

	/**
	 * Summary shown in the library list.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return array<string, mixed>
	 */
	public static function summary( array $element ): array {
		return array(
			'label'   => ElementTree::label( $element ),
			'type'    => (string) ( $element['widgetType'] ?? $element['elType'] ?? '' ),
			'widgets' => ElementTree::widgets( array( $element ) ),
			'texts'   => array_slice( ElementTree::texts( array( $element ) ), 0, 4 ),
		);
	}

	/**
	 * Whether a tree contains Flexbox Containers.
	 *
	 * @param array<string, mixed> $element Element.
	 */
	private static function usesContainers( array $element ): bool {
		foreach ( ElementTree::flatten( array( $element ) ) as $row ) {
			if ( 'container' === ( $row['element']['elType'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
