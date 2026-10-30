<?php
/**
 * PageDocument → validated Elementor draft.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

use AiSeoContentStudio\Elementor\ElementorBridgeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The generation pipeline shared by every design workflow:
 *
 *   input → PageDocument::normalize (schema) → ElementorMapper (tokens, globals, responsive)
 *         → DocumentValidator → DraftManager::create (new draft only)
 */
class PageBuilder {

	/**
	 * Constructor.
	 *
	 * @param ElementorBridgeInterface $bridge Elementor bridge.
	 * @param ElementorCompat          $compat Installed Elementor.
	 * @param DraftManager             $drafts Draft manager.
	 */
	public function __construct(
		private readonly ElementorBridgeInterface $bridge,
		private readonly ElementorCompat $compat,
		private readonly DraftManager $drafts,
	) {}

	/**
	 * Map a document without saving (preview of the element tree, tests).
	 *
	 * @param array<string, mixed> $doc    Normalised document.
	 * @param array<string, mixed> $tokens Brand tokens.
	 * @return array{elements: list<array<string, mixed>>, section_map: array<string, string>, notes: string[], settings: array<string, mixed>}
	 */
	public function map( array $doc, array $tokens ): array {
		$mapper = new ElementorMapper( $this->compat, new IdGenerator(), $tokens, $this->kitColors() );
		$result = $mapper->map( $doc );
		// The hero carries the page's H1, so the theme's title is hidden where the theme allows it.
		$has_h1             = (bool) preg_match( '/"header_size":"h1"/', (string) wp_json_encode( $result['elements'] ) );
		$result['settings'] = $has_h1 && $this->bridge->canHideThemeTitle() ? array( 'hide_title' => 'yes' ) : array();
		return $result;
	}

	/**
	 * Build a new draft from an untrusted document.
	 *
	 * @param mixed                $input  Document.
	 * @param array<string, mixed> $tokens Brand tokens (already access-checked by the caller).
	 * @param array<string, mixed> $args   DraftManager args (user_id, profile_id, project_id, generation_type, ...).
	 * @param string               $mode   DocumentValidator::MODE_GENERATED, or MODE_CONVERTED for HTML conversion
	 *                                     (allows the "html" fallback element and widget).
	 * @return array<string, mixed> DraftManager result plus notes and section_map.
	 * @throws DraftException When the document is invalid or saving fails.
	 */
	public function build( mixed $input, array $tokens, array $args, string $mode = DocumentValidator::MODE_GENERATED ): array {
		$mode   = DocumentValidator::MODE_CONVERTED === $mode ? $mode : DocumentValidator::MODE_GENERATED;
		$errors = array();
		$doc    = PageDocument::normalize( $input, $errors, DocumentValidator::MODE_CONVERTED === $mode );
		if ( array() !== $errors ) {
			throw new DraftException( 'invalid_document', array_slice( $errors, 0, 30 ) );
		}
		$mapped = $this->map( $doc, $tokens );
		$result = $this->drafts->create(
			$mapped['elements'],
			$mapped['settings'],
			array_merge(
				$args,
				array(
					'title'       => $doc['page']['title'],
					'slug'        => $doc['page']['slug'],
					'mode'        => $mode,
					'section_map' => $mapped['section_map'],
					'document'    => $doc,
					'notes'       => $mapped['notes'],
					'template'    => (string) ( $args['template'] ?? 'elementor_header_footer' ),
				)
			)
		);
		return $result + array(
			'notes'       => $mapped['notes'],
			'section_map' => $mapped['section_map'],
		);
	}

	/**
	 * Kit colour id => hex.
	 *
	 * @return array<string, string>
	 */
	private function kitColors(): array {
		$kit = $this->bridge->activeKit()['settings'];
		$out = array();
		foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
			foreach ( (array) ( $kit[ $group ] ?? array() ) as $item ) {
				if ( is_array( $item ) && is_string( $item['_id'] ?? null ) && is_string( $item['color'] ?? null ) ) {
					$out[ $item['_id'] ] = $item['color'];
				}
			}
		}
		return $out;
	}
}
