<?php
/**
 * Clones an Elementor page into a new draft.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Cloner;

use AiSeoContentStudio\Content\DraftWriter;
use AiSeoContentStudio\Elementor\ElementorBridgeInterface;
use AiSeoContentStudio\WebsiteStudio\Analysis\ElementorDocumentAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftManager;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * The source page is only ever read: its element tree is copied in memory, optionally given
 * new text, given fresh IDs and saved by the DraftManager as a NEW draft page. Page settings
 * (hide title, layout, custom CSS), the page template and the featured image are copied.
 * A fingerprint of the source's Elementor data is taken before and after, and a change is
 * reported as an error (it would mean something else wrote to the source meanwhile).
 */
class PageCloner {

	/** Most slots sent to the AI in one request (larger pages: the rest keep their text). */
	public const MAX_AI_SLOTS = 150;

	/**
	 * Constructor.
	 *
	 * @param ElementorBridgeInterface $bridge Elementor bridge.
	 * @param DraftManager             $drafts Draft manager.
	 * @param ContentSlots             $slots  Slot finder.
	 */
	public function __construct(
		private readonly ElementorBridgeInterface $bridge,
		private readonly DraftManager $drafts,
		private readonly ContentSlots $slots = new ContentSlots(),
	) {}

	/**
	 * What cloning a page involves: sections, slots and compatibility warnings.
	 *
	 * @param \WP_Post $source Source page (caller checked edit permission).
	 * @param string   $brand  Business name.
	 * @return array<string, mixed>
	 * @throws DraftException When the page is not an Elementor page.
	 */
	public function plan( \WP_Post $source, string $brand = '' ): array {
		$elements = $this->sourceElements( $source );
		$analysis = ( new ElementorDocumentAnalyzer() )->analyze( $elements, array(), $this->bridge->widgetTypes() );
		$slots    = $this->slots->extract( $elements, $brand );
		$warnings = array();
		foreach ( $analysis['structure']['unavailable_widgets'] as $widget ) {
			/* translators: %s: widget type. */
			$warnings[] = sprintf( __( 'The "%s" widget is not available on this site; it is copied but will not display until it is.', 'ai-seo-content-studio' ), $widget );
		}
		if ( in_array( 'atomic_elements', $analysis['notes'], true ) ) {
			$warnings[] = __( 'This page uses Elementor 4 atomic elements. They are copied as they are; their text is not rewritten yet.', 'ai-seo-content-studio' );
		}
		if ( isset( $analysis['structure']['widgets']['template'] ) || isset( $analysis['structure']['widgets']['global'] ) ) {
			$warnings[] = __( 'The page embeds saved templates or global widgets. Their content is shared with other pages and is not rewritten.', 'ai-seo-content-studio' );
		}
		$replace = array_values( array_filter( $slots, static fn ( array $s ): bool => $s['replace'] ) );
		if ( count( $replace ) > self::MAX_AI_SLOTS ) {
			/* translators: %d: number of text fields. */
			$warnings[] = sprintf( __( 'Only the first %d text fields are rewritten in one run; the rest keep their current text.', 'ai-seo-content-studio' ), self::MAX_AI_SLOTS );
		}
		return array(
			'source'   => array(
				'id'    => $source->ID,
				'title' => html_entity_decode( get_the_title( $source ), ENT_QUOTES, 'UTF-8' ),
			),
			'sections' => array_map(
				static fn ( array $s ): array => array(
					'index'   => $s['index'],
					'type'    => $s['type'],
					'heading' => $s['heading'],
				),
				$analysis['sections']
			),
			'slots'    => $slots,
			'stats'    => array(
				'elements' => $analysis['structure']['element_count'],
				'widgets'  => $analysis['structure']['widget_count'],
				'slots'    => count( $slots ),
				'replace'  => count( $replace ),
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * Clone a page, optionally with new text.
	 *
	 * @param \WP_Post             $source Source page (caller checked edit permission).
	 * @param int                  $user   User.
	 * @param array<string, mixed> $args   title, texts (key => text), slots (plan slots with the user's choices),
	 *                                     meta_title, meta_description, keyword, project_id, profile_id.
	 * @return array<string, mixed> DraftManager result plus applied keys and source check.
	 * @throws DraftException When the page cannot be cloned.
	 */
	public function clone( \WP_Post $source, int $user, array $args = array() ): array {
		$before   = $this->fingerprint( $source->ID );
		$elements = $this->sourceElements( $source );
		$applied  = array();
		$texts    = (array) ( $args['texts'] ?? array() );
		if ( array() !== $texts ) {
			$replaced = $this->slots->apply( $elements, $texts, (array) ( $args['slots'] ?? array() ) );
			$elements = $replaced['elements'];
			$applied  = $replaced['applied'];
		}
		$copy = ( new ElementTreeCopier( new IdGenerator() ) )->copy( $elements );

		$page_settings = get_post_meta( $source->ID, '_elementor_page_settings', true );
		$title         = trim( (string) ( $args['title'] ?? '' ) );
		if ( '' === $title ) {
			/* translators: %s: source page title. */
			$title = sprintf( __( '%s (copy)', 'ai-seo-content-studio' ), get_the_title( $source ) );
		}
		$result = $this->drafts->create(
			$copy['elements'],
			is_array( $page_settings ) ? $page_settings : array(),
			array(
				'title'           => $title,
				'user_id'         => $user,
				'post_type'       => $source->post_type,
				'mode'            => DocumentValidator::MODE_CLONED,
				'generation_type' => array() === $applied ? 'clone' : 'clone_generated',
				'source_page_id'  => $source->ID,
				'project_id'      => (int) ( $args['project_id'] ?? 0 ),
				'profile_id'      => (int) ( $args['profile_id'] ?? 0 ),
				'template'        => (string) get_post_meta( $source->ID, '_wp_page_template', true ),
				'thumbnail_id'    => (int) get_post_thumbnail_id( $source ),
				'section_map'     => self::sectionMap( $copy['elements'] ),
			)
		);

		$meta = array(
			'meta_title'       => (string) ( $args['meta_title'] ?? '' ),
			'meta_description' => (string) ( $args['meta_description'] ?? '' ),
			'keyword'          => (string) ( $args['keyword'] ?? '' ),
		);
		if ( '' !== $meta['meta_title'] ) {
			update_post_meta( $result['page_id'], DraftWriter::META['seo_title'], wp_slash( $meta['meta_title'] ) );
		}
		if ( '' !== $meta['meta_description'] ) {
			update_post_meta( $result['page_id'], DraftWriter::META['meta_description'], wp_slash( $meta['meta_description'] ) );
		}
		DraftWriter::syncSeoPlugins( $result['page_id'], $meta );

		$result['applied']          = $applied;
		$result['source_unchanged'] = $before === $this->fingerprint( $source->ID );
		if ( ! $result['source_unchanged'] ) {
			$result['warnings'][] = __( 'The source page changed while it was being cloned (it was edited at the same time). The copy reflects the version that was read.', 'ai-seo-content-studio' );
		}
		return $result;
	}

	/**
	 * The source's element tree.
	 *
	 * @param \WP_Post $source Source.
	 * @return array<int, mixed>
	 * @throws DraftException When it is not an Elementor page.
	 */
	private function sourceElements( \WP_Post $source ): array {
		if ( ! $this->bridge->isActive() ) {
			throw new DraftException( 'elementor_inactive', array( DraftManager::blockerMessage( 'elementor_inactive' ) ) );
		}
		if ( ! $this->bridge->isBuiltWithElementor( $source->ID ) ) {
			throw new DraftException( 'not_elementor', array( __( 'This page is not built with Elementor, so it cannot be cloned as an Elementor page.', 'ai-seo-content-studio' ) ) );
		}
		$elements = $this->bridge->elements( $source->ID );
		if ( array() === $elements ) {
			throw new DraftException( 'empty', array( __( 'The Elementor page has no content to clone.', 'ai-seo-content-studio' ) ) );
		}
		return $elements;
	}

	/**
	 * Fingerprint of a page's stored Elementor data.
	 *
	 * @param int $id Page.
	 */
	private function fingerprint( int $id ): string {
		return md5( (string) get_post_meta( $id, '_elementor_data', true ) . '|' . (string) get_post_field( 'post_modified_gmt', $id ) );
	}

	/**
	 * Section map for a clone: "section-<n>" => new top-level element ID.
	 *
	 * @param array<int, mixed> $copy Copied tree.
	 * @return array<string, string>
	 */
	private static function sectionMap( array $copy ): array {
		$map = array();
		foreach ( array_values( $copy ) as $i => $element ) {
			$map[ 'section-' . ( $i + 1 ) ] = (string) ( $element['id'] ?? '' );
		}
		return $map;
	}
}
