<?php
/**
 * Runs the consistency and accessibility checks on a page and applies chosen fixes.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Editor;

use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress side of the checkers: reads the page and the Elementor kit, resolves attachment
 * alt texts, and writes fixes. Fixes are recomputed from the page as it is now and matched
 * by id, then written through DraftManager (studio drafts only, validated, with a revision).
 * Alt text fixes update the Media Library attachment, when the user may edit it.
 */
class PageChecks {

	/**
	 * Constructor.
	 *
	 * @param StudioServices $studio Studio services.
	 */
	public function __construct( private readonly StudioServices $studio ) {}

	/**
	 * Consistency report.
	 *
	 * @param int                  $page_id Page.
	 * @param array<string, mixed> $tokens  Reference tokens.
	 * @return array<string, mixed>
	 */
	public function consistency( int $page_id, array $tokens ): array {
		$kit    = $this->kit();
		$report = ( new ConsistencyChecker( $tokens, StyleValues::kitColors( $kit ), $kit ) )->check( $this->studio->bridge()->elements( $page_id ) );
		return $report;
	}

	/**
	 * Accessibility report.
	 *
	 * @param int $page_id Page.
	 * @return array<string, mixed>
	 */
	public function accessibility( int $page_id ): array {
		$kit = $this->kit();
		$bg  = StyleValues::color( $kit, 'body_background_color', StyleValues::kitColors( $kit ) );
		return ( new AccessibilityChecker( StyleValues::kitColors( $kit ), array( $this, 'altText' ), null !== $bg && '' !== $bg['hex'] ? $bg['hex'] : '#FFFFFF' ) )->check( $this->studio->bridge()->elements( $page_id ) );
	}

	/**
	 * Apply chosen fixes.
	 *
	 * @param int                        $page_id  Page.
	 * @param list<array<string, mixed>> $findings Findings recomputed now.
	 * @param list<string>               $ids      Chosen ids.
	 * @param array<string, string>      $values   Id => user text.
	 * @param int                        $user_id  User.
	 * @param string                     $label    Revision summary.
	 * @return array{applied: list<string>, skipped: list<string>, revision: int}
	 * @throws DraftException When the page may not be changed.
	 */
	public function apply( int $page_id, array $findings, array $ids, array $values, int $user_id, string $label ): array {
		$this->studio->drafts()->assertWritable( $page_id, $user_id );
		$current = $this->studio->bridge()->elements( $page_id );
		$result  = Findings::apply( $current, $findings, $ids, $values );
		foreach ( $result['attachments'] as $alt ) {
			$id = (int) $alt['attachment_id'];
			if ( 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt['alt'] ) );
			}
		}
		$revision = 0;
		if ( $result['elements'] !== $current ) {
			$update   = $this->studio->drafts()->update(
				$page_id,
				$result['elements'],
				$user_id,
				DocumentValidator::MODE_CLONED,
				array(
					'action'  => 'fix',
					/* translators: 1: checker, 2: number of fixes. */
					'summary' => sprintf( __( '%1$s: %2$d fixes applied', 'ai-seo-content-studio' ), $label, count( $result['applied'] ) ),
					'meta'    => array( 'fixes' => $result['applied'] ),
				)
			);
			$revision = (int) $update['revision'];
		}
		return array(
			'applied'  => $result['applied'],
			'skipped'  => $result['skipped'],
			'revision' => $revision,
		);
	}

	/**
	 * Alt text of an attachment.
	 *
	 * @param int $id Attachment.
	 */
	public function altText( int $id ): string {
		return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	}

	/**
	 * Active kit settings.
	 *
	 * @return array<string, mixed>
	 */
	private function kit(): array {
		return (array) ( $this->studio->bridge()->activeKit()['settings'] ?? array() );
	}
}
