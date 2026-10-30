<?php
/**
 * Background job: generate images for selected slots.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media;

use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\ImageGenerationService;

defined( 'ABSPATH' ) || exit;

/**
 * One step per slot. Each generated image is validated, imported into the Media Library
 * and linked to its slot; a replaced image is deleted only if the plugin created it and
 * nothing uses it. A refused or invalid image is recorded on its slot and the job moves on.
 */
class ImageJob implements JobHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository      $projects Projects.
	 * @param ImageGenerationService $images   Image service.
	 * @param MediaImporter          $media    Media importer.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly ImageGenerationService $images,
		private readonly MediaImporter $media,
	) {}

	/**
	 * Type.
	 */
	public function type(): string {
		return 'images';
	}

	/**
	 * Steps: one per requested slot that has a prompt.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $options    slots: ids.
	 * @return string[]
	 * @throws ProviderException When nothing can be generated.
	 */
	public function steps( int $project_id, array $options ): array {
		$wanted = array_map( 'strval', (array) ( $options['slots'] ?? array() ) );
		$steps  = array();
		foreach ( (array) ( $this->projects->section( $project_id, 'images' )['slots'] ?? array() ) as $slot ) {
			if ( in_array( (string) $slot['id'], $wanted, true ) && '' !== trim( (string) $slot['prompt'] ) ) {
				$steps[] = 'image:' . $slot['id'];
			}
		}
		if ( array() === $steps ) {
			throw new ProviderException( __( 'Choose at least one image with a prompt.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
		return $steps;
	}

	/**
	 * Label.
	 *
	 * @param string $step Step.
	 */
	public function stepLabel( string $step ): string {
		return 'image:featured' === $step
			? __( 'Generating the featured image', 'ai-seo-content-studio' )
			: __( 'Generating an in-article image', 'ai-seo-content-studio' );
	}

	/**
	 * Generate one slot.
	 *
	 * @param string               $step       Step.
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $state      Job state.
	 * @param array<string, mixed> $options    Options.
	 * @throws ProviderException Retryable provider errors (the runner retries the step).
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$slot_id = substr( $step, 6 );
		$plan    = $this->projects->section( $project_id, 'images' ) ?? array( 'slots' => array() );
		$index   = null;
		foreach ( $plan['slots'] as $i => $slot ) {
			if ( $slot['id'] === $slot_id ) {
				$index = $i;
			}
		}
		if ( null === $index ) {
			return true; // Slot removed while the job was queued.
		}
		$slot    = $plan['slots'][ $index ];
		$content = $this->projects->section( $project_id, 'content' ) ?? array();

		try {
			$result = $this->images->generate( new ImageRequest( (string) $slot['prompt'], (string) $slot['aspect'], null, 'image_' . $slot['kind'] ) );
			$id     = $this->media->import(
				$result['response']->bytes,
				$result['file'],
				array(
					'title'      => $this->title( $content, $slot ),
					'alt'        => (string) $slot['alt'],
					'caption'    => (string) $slot['caption'],
					'prompt'     => (string) $slot['prompt'],
					'provider'   => $result['response']->provider,
					'model'      => $result['response']->model,
					'project_id' => $project_id,
				)
			);
		} catch ( ProviderException $e ) {
			if ( $e->retryable ) {
				throw $e;
			}
			$this->update( $project_id, $slot_id, array( 'error' => $e->getMessage() ) );
			return true;
		}

		$previous = (int) $slot['attachment_id'];
		$this->update(
			$project_id,
			$slot_id,
			array(
				'attachment_id'  => $id,
				'url'            => (string) wp_get_attachment_image_url( $id, 'large' ),
				'provider'       => $result['response']->provider,
				'model'          => $result['response']->model,
				'revised_prompt' => $result['response']->revised_prompt,
				'error'          => '',
				'source'         => 'ai',
				'credit'         => '',
				'credit_url'     => '',
				'license'        => '',
			)
		);
		if ( $previous > 0 && $previous !== $id ) {
			$this->media->deleteUnused( $previous );
		}
		return true;
	}

	/**
	 * Media title: the page title, or the section heading for in-article images.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $slot    Slot.
	 */
	private function title( array $content, array $slot ): string {
		if ( 'inline' === $slot['kind'] ) {
			foreach ( (array) ( $content['sections'] ?? array() ) as $section ) {
				if ( ( $section['id'] ?? '' ) === $slot['section'] && '' !== (string) $section['heading'] ) {
					return (string) $section['heading'];
				}
			}
		}
		return (string) ( $content['title'] ?? '' );
	}

	/**
	 * Merge server-owned fields into one slot (re-reading the plan to keep user edits).
	 *
	 * @param int                  $project_id Project.
	 * @param string               $slot_id    Slot.
	 * @param array<string, mixed> $fields     Fields.
	 */
	private function update( int $project_id, string $slot_id, array $fields ): void {
		$plan = $this->projects->section( $project_id, 'images' ) ?? array( 'slots' => array() );
		foreach ( $plan['slots'] as $i => $slot ) {
			if ( $slot['id'] === $slot_id ) {
				$plan['slots'][ $i ] = ImagePlan::slot( array_merge( $slot, $fields ) );
			}
		}
		$this->projects->save( $project_id, 'images', $plan );
	}
}
