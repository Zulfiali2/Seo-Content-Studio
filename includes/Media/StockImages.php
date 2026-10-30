<?php
/**
 * Free stock photos and placeholders for image slots.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Media\Stock\StockSource;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Searches the stock libraries and puts a chosen image (or a generated placeholder) into a
 * slot. The browser only sends a library and an image id: the file URL is looked up again on
 * the server, SSRF-checked, downloaded, validated (type, size, dimensions) and imported into
 * the Media Library with the photographer, source and licence as the caption.
 */
class StockImages {

	/** Largest stock file accepted (bytes). */
	private const MAX_BYTES = 20971520;

	/**
	 * Sources by id.
	 *
	 * @var array<string, StockSource>
	 */
	private array $sources = array();

	/**
	 * Constructor.
	 *
	 * @param StockSource[]     $sources  Libraries.
	 * @param HttpClient        $http     HTTP client.
	 * @param MediaImporter     $media    Media importer.
	 * @param ProjectRepository $projects Projects.
	 */
	public function __construct(
		array $sources,
		private readonly HttpClient $http,
		private readonly MediaImporter $media,
		private readonly ProjectRepository $projects,
	) {
		foreach ( $sources as $source ) {
			$this->sources[ $source->id() ] = $source;
		}
	}

	/**
	 * Libraries for the UI (no secrets).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function describe(): array {
		$out = array();
		foreach ( $this->sources as $source ) {
			$out[] = array(
				'id'         => $source->id(),
				'label'      => $source->label(),
				'kind'       => $source->kind(),
				'needs_key'  => $source->needsKey(),
				'configured' => $source->isConfigured(),
				'key_url'    => $source->keyUrl(),
			);
		}
		return $out;
	}

	/**
	 * A library by id.
	 *
	 * @param string $id Id.
	 * @throws ProviderException When unknown or not set up.
	 */
	public function source( string $id ): StockSource {
		$source = $this->sources[ $id ] ?? null;
		if ( null === $source ) {
			throw new ProviderException( __( 'Unknown image library.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
		if ( ! $source->isConfigured() ) {
			/* translators: %s: library name. */
			throw new ProviderException( sprintf( __( 'Add your free %s API key under AI Providers → Free stock photos first.', 'ai-seo-content-studio' ), $source->label() ), ProviderException::CONFIG );
		}
		return $source;
	}

	/**
	 * Search a library.
	 *
	 * @param string $source_id Library.
	 * @param string $query     Words.
	 * @param string $aspect    Aspect.
	 * @return array<int, array<string, mixed>>
	 * @throws ProviderException On failure.
	 */
	public function search( string $source_id, string $query, string $aspect ): array {
		$query = trim( mb_substr( sanitize_text_field( $query ), 0, 100 ) );
		if ( '' === $query ) {
			throw new ProviderException( __( 'Enter a few words to search for.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
		return array_slice( $this->source( $source_id )->search( $query, $aspect ), 0, 20 );
	}

	/**
	 * Put a stock image into a slot.
	 *
	 * @param int    $project_id Project.
	 * @param string $slot_id    Slot.
	 * @param string $source_id  Library.
	 * @param string $image_id   Image id from search results.
	 * @return array<string, mixed> Updated slot.
	 * @throws ProviderException On failure.
	 */
	public function useStock( int $project_id, string $slot_id, string $source_id, string $image_id ): array {
		$slot   = $this->slot( $project_id, $slot_id );
		$source = $this->source( $source_id );
		$image  = $source->find( $image_id );
		if ( null === $image || '' === (string) ( $image['download'] ?? '' ) ) {
			throw new ProviderException( __( 'That image is no longer available. Search again.', 'ai-seo-content-studio' ), ProviderException::NOT_FOUND );
		}

		$file = $this->http->fetchPage( (string) $image['download'], 30, self::MAX_BYTES, 3, 'image/avif,image/webp,image/png,image/jpeg;q=0.9,*/*;q=0.5' );
		if ( 200 !== $file['status'] ) {
			throw new ProviderException( __( 'The image could not be downloaded. Try another one.', 'ai-seo-content-studio' ), ProviderException::NETWORK );
		}
		$info   = ImageValidator::validate( $file['body'] );
		$credit = self::credit( $image );
		$id     = $this->media->import(
			$file['body'],
			$info,
			array(
				'title'      => '' !== (string) $image['title'] ? (string) $image['title'] : $this->title( $project_id, $slot ),
				'alt'        => (string) $slot['alt'],
				'caption'    => '' !== (string) $slot['caption'] ? (string) $slot['caption'] : $credit,
				'prompt'     => '',
				'provider'   => $source->id(),
				'model'      => '',
				'project_id' => $project_id,
			)
		);
		update_post_meta(
			$id,
			'_aiscs_image_source',
			wp_slash(
				array(
					'library'     => $source->label(),
					'image_id'    => $image_id,
					'author'      => (string) $image['author'],
					'source_url'  => (string) $image['source_url'],
					'license'     => (string) $image['license'],
					'license_url' => (string) $image['license_url'],
				)
			)
		);

		return $this->store(
			$project_id,
			$slot,
			$id,
			array(
				'source'     => 'stock',
				'provider'   => $source->id(),
				'credit'     => $credit,
				'credit_url' => (string) ( '' !== (string) $image['source_url'] ? $image['source_url'] : $image['author_url'] ),
				'license'    => (string) $image['license'],
				'caption'    => '' !== (string) $slot['caption'] ? (string) $slot['caption'] : $credit,
			)
		);
	}

	/**
	 * Put a generated placeholder into a slot.
	 *
	 * @param int    $project_id Project.
	 * @param string $slot_id    Slot.
	 * @param string $color      Base colour.
	 * @return array<string, mixed> Updated slot.
	 * @throws ProviderException On failure.
	 */
	public function usePlaceholder( int $project_id, string $slot_id, string $color ): array {
		$slot  = $this->slot( $project_id, $slot_id );
		$bytes = PlaceholderImage::png( (string) $slot['aspect'], $color, $slot_id . microtime() );
		$id    = $this->media->import(
			$bytes,
			ImageValidator::validate( $bytes ),
			array(
				/* translators: %s: page title or section heading. */
				'title'      => sprintf( __( 'Placeholder – %s', 'ai-seo-content-studio' ), $this->title( $project_id, $slot ) ),
				'alt'        => (string) $slot['alt'],
				'caption'    => '',
				'prompt'     => '',
				'provider'   => 'placeholder',
				'model'      => '',
				'project_id' => $project_id,
			)
		);
		return $this->store(
			$project_id,
			$slot,
			$id,
			array(
				'source'     => 'placeholder',
				'provider'   => 'placeholder',
				'credit'     => '',
				'credit_url' => '',
				'license'    => '',
			)
		);
	}

	/**
	 * Attribution line: "Photo by Jane Doe on Pexels (Pexels License)".
	 *
	 * @param array<string, mixed> $image Image.
	 */
	public static function credit( array $image ): string {
		$author = trim( (string) ( $image['author'] ?? '' ) );
		$by     = '' !== $author
			/* translators: 1: author, 2: library name. */
			? sprintf( __( 'Image by %1$s via %2$s', 'ai-seo-content-studio' ), $author, (string) $image['library'] )
			/* translators: %s: library name. */
			: sprintf( __( 'Image via %s', 'ai-seo-content-studio' ), (string) $image['library'] );
		return mb_substr( $by . ' (' . (string) $image['license'] . ')', 0, 300 );
	}

	/**
	 * Current slot.
	 *
	 * @param int    $project_id Project.
	 * @param string $slot_id    Slot.
	 * @return array<string, mixed>
	 * @throws ProviderException When the slot does not exist.
	 */
	private function slot( int $project_id, string $slot_id ): array {
		foreach ( (array) ( $this->projects->section( $project_id, 'images' )['slots'] ?? array() ) as $slot ) {
			if ( $slot['id'] === $slot_id ) {
				return $slot;
			}
		}
		throw new ProviderException( __( 'That image slot no longer exists. Reload the page.', 'ai-seo-content-studio' ), ProviderException::NOT_FOUND );
	}

	/**
	 * Save the new attachment into the slot and clean up the previous plugin image.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $slot       Slot.
	 * @param int                  $id         Attachment.
	 * @param array<string, mixed> $fields     Extra fields.
	 * @return array<string, mixed>
	 */
	private function store( int $project_id, array $slot, int $id, array $fields ): array {
		$previous = (int) $slot['attachment_id'];
		$plan     = $this->projects->section( $project_id, 'images' ) ?? array( 'slots' => array() );
		$updated  = $slot;
		foreach ( $plan['slots'] as $i => $current ) {
			if ( $current['id'] === $slot['id'] ) {
				$updated             = ImagePlan::slot(
					array_merge(
						$current,
						array(
							'attachment_id'  => $id,
							'url'            => (string) wp_get_attachment_image_url( $id, 'large' ),
							'model'          => '',
							'revised_prompt' => '',
							'error'          => '',
						),
						$fields
					)
				);
				$plan['slots'][ $i ] = $updated;
			}
		}
		$this->projects->save( $project_id, 'images', $plan );
		if ( $previous > 0 && $previous !== $id ) {
			$this->media->deleteUnused( $previous );
		}
		return $updated;
	}

	/**
	 * Media title: the section heading for in-article images, else the page title.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $slot       Slot.
	 */
	private function title( int $project_id, array $slot ): string {
		$content = (array) $this->projects->section( $project_id, 'content' );
		if ( 'inline' === $slot['kind'] ) {
			foreach ( (array) ( $content['sections'] ?? array() ) as $section ) {
				if ( ( $section['id'] ?? '' ) === $slot['section'] && '' !== (string) $section['heading'] ) {
					return (string) $section['heading'];
				}
			}
		}
		return (string) ( $content['title'] ?? '' );
	}
}
