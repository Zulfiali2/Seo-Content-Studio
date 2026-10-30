<?php
/**
 * Review step for AI images of a generated page.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Generator;

use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * Puts approved image candidates into the generated draft, or discards them.
 *
 * The draft's current Elementor data is edited, not regenerated, so changes the user made in
 * Elementor are kept: only image widgets that still show the slot's current image are changed.
 */
class GeneratedImages {

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository $projects Projects.
	 * @param StudioServices    $studio   Studio services.
	 * @param SiteGateway       $site     WordPress reads and writes.
	 * @param MediaImporter     $media    Media importer.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly StudioServices $studio,
		private readonly SiteGateway $site,
		private readonly MediaImporter $media,
	) {}

	/**
	 * Put approved candidates into the draft.
	 *
	 * @param int      $project_id Project.
	 * @param string[] $slot_ids   Approved slots.
	 * @param int      $user_id    User.
	 * @return array{applied: list<string>, missing: list<string>, images: list<array<string, mixed>>}
	 * @throws DraftException When the draft cannot be changed.
	 */
	public function apply( int $project_id, array $slot_ids, int $user_id ): array {
		$studio  = (array) $this->projects->section( $project_id, 'studio' );
		$page_id = (int) ( $studio['result']['page_id'] ?? 0 );
		if ( $page_id <= 0 ) {
			throw new DraftException( 'not_built', array( __( 'Create the draft first.', 'ai-seo-content-studio' ) ) );
		}
		$elements = $this->studio->bridge()->elements( $page_id );
		$applied  = array();
		$missing  = array();
		$swapped  = array();
		foreach ( (array) ( $studio['images'] ?? array() ) as $i => $slot ) {
			if ( ! in_array( $slot['id'], $slot_ids, true ) || empty( $slot['candidate']['attachment_id'] ) ) {
				continue;
			}
			$new   = (int) $slot['candidate']['attachment_id'];
			$count = 0;
			$elements = self::swap( $elements, (int) $slot['current_id'], $new, (string) $slot['candidate']['url'], $count );
			if ( 0 === $count ) {
				$missing[] = $slot['id']; // The image was removed or replaced in Elementor.
				continue;
			}
			$swapped[ $i ] = $new;
			$applied[]     = $slot['id'];
		}
		if ( array() !== $swapped ) {
			// Cloned mode: the user may have added any widget in Elementor since the draft was made.
			$this->studio->drafts()->update( $page_id, $elements, $user_id, DocumentValidator::MODE_CLONED );
			foreach ( $swapped as $i => $new ) {
				$slot = $studio['images'][ $i ];
				$this->site->attach( $new, $page_id );
				if ( (int) $slot['current_id'] === (int) $slot['placeholder_id'] ) {
					$this->site->deleteImage( (int) $slot['placeholder_id'] ); // Placeholders have no other use.
				}
				$studio['images'][ $i ]['current_id'] = $new;
				$studio['images'][ $i ]['candidate']  = null;
				$studio['images'][ $i ]['status']     = 'applied';
			}
			$this->projects->save( $project_id, 'studio', $studio );
		}
		return array(
			'applied' => $applied,
			'missing' => $missing,
			'images'  => (array) ( $studio['images'] ?? array() ),
		);
	}

	/**
	 * Delete a candidate the user rejected.
	 *
	 * @param int    $project_id Project.
	 * @param string $slot_id    Slot.
	 * @return list<array<string, mixed>> Slots.
	 */
	public function discard( int $project_id, string $slot_id ): array {
		$studio = (array) $this->projects->section( $project_id, 'studio' );
		foreach ( (array) ( $studio['images'] ?? array() ) as $i => $slot ) {
			if ( $slot['id'] !== $slot_id || empty( $slot['candidate']['attachment_id'] ) ) {
				continue;
			}
			$this->media->deleteUnused( (int) $slot['candidate']['attachment_id'] );
			$studio['images'][ $i ]['candidate'] = null;
			$studio['images'][ $i ]['status']    = (int) $slot['current_id'] === (int) $slot['placeholder_id'] ? 'placeholder' : 'applied';
			$this->projects->save( $project_id, 'studio', $studio );
		}
		return (array) ( $studio['images'] ?? array() );
	}

	/**
	 * Replace an image in every image widget that shows it.
	 *
	 * @param array<int, mixed> $elements Elementor elements.
	 * @param int               $old      Current attachment.
	 * @param int               $new      New attachment.
	 * @param string            $url      New URL.
	 * @param int               $count    Replacements made.
	 * @return array<int, mixed>
	 */
	public static function swap( array $elements, int $old, int $new, string $url, int &$count ): array {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( 'widget' === ( $el['elType'] ?? '' ) && 'image' === ( $el['widgetType'] ?? '' ) && $old > 0 && (int) ( $el['settings']['image']['id'] ?? 0 ) === $old ) {
				$elements[ $i ]['settings']['image']['id']     = $new;
				$elements[ $i ]['settings']['image']['url']    = $url;
				$elements[ $i ]['settings']['image']['source'] = 'library';
				++$count;
			}
			if ( is_array( $el['elements'] ?? null ) ) {
				$elements[ $i ]['elements'] = self::swap( $el['elements'], $old, $new, $url, $count );
			}
		}
		return $elements;
	}
}
