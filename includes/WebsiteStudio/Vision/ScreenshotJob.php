<?php
/**
 * Background jobs of the screenshot-to-design workflow.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Vision;

use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Media\ImageValidator;
use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Media\PlaceholderImage;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\WebsiteStudio\Cloner\CloneContentGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * TYPE_ANALYZE: one vision request → design structure (Output A). The uploaded screenshot is
 * deleted as soon as the step ends, whether it succeeded or not.
 *
 * TYPE_BUILD: optional content request for the layout's text slots (Output B) → optional
 * placeholder images → new Elementor draft. Each step stores its result first and skips itself
 * when the result exists, so retries never repeat a billed request, import images twice or
 * create a second draft.
 *
 * Project "studio" section: {source: "screenshot", token, upload, model, design, brief, options,
 * written, image_ids, result}.
 */
class ScreenshotJob implements JobHandlerInterface {

	public const TYPE_ANALYZE = 'studio_screenshot_analyze';
	public const TYPE_BUILD   = 'studio_screenshot_build';

	/**
	 * Constructor.
	 *
	 * @param string                 $type     TYPE_ANALYZE | TYPE_BUILD.
	 * @param ProjectRepository      $projects Projects.
	 * @param TextGenerationService  $text     Text generation.
	 * @param SettingsRepository     $settings Settings.
	 * @param StudioServices         $studio   Studio services.
	 * @param MediaImporter          $media    Media importer.
	 * @param ScreenshotStore        $store    Screenshot store.
	 * @param BrandProfileRepository $profiles Brand profiles.
	 */
	public function __construct(
		private readonly string $type,
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly SettingsRepository $settings,
		private readonly StudioServices $studio,
		private readonly MediaImporter $media,
		private readonly ScreenshotStore $store,
		private readonly BrandProfileRepository $profiles,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function steps( int $project_id, array $options ): array {
		return self::TYPE_ANALYZE === $this->type ? array( 'analyze' ) : array( 'write', 'images', 'build' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stepLabel( string $step ): string {
		return match ( $step ) {
			'analyze' => __( 'Reading the screenshot with an AI vision model', 'ai-seo-content-studio' ),
			'write'   => __( 'Writing content for the layout', 'ai-seo-content-studio' ),
			'images'  => __( 'Adding placeholder images', 'ai-seo-content-studio' ),
			default   => __( 'Creating the Elementor draft', 'ai-seo-content-studio' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$studio = (array) $this->projects->section( $project_id, 'studio' );
		switch ( $step ) {
			case 'analyze':
				$this->analyze( $project_id, $studio );
				return true;
			case 'write':
				$this->write( $project_id, $studio );
				return true;
			case 'images':
				$this->images( $project_id, $studio );
				return true;
			case 'build':
				$this->build( $project_id, $studio );
				return true;
		}
		throw new ProviderException( 'Unknown screenshot step.', ProviderException::CONFIG );
	}

	/**
	 * Vision analysis.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 * @throws ProviderException On failure.
	 */
	private function analyze( int $project_id, array $studio ): void {
		if ( isset( $studio['design'] ) ) {
			return;
		}
		$token = (string) ( $studio['token'] ?? '' );
		$bytes = $this->store->get( $token );
		if ( '' === $bytes ) {
			throw new ProviderException( __( 'The uploaded screenshot is no longer available. Upload it again.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
		try {
			try {
				$prepared = ScreenshotPreparer::tiles( $bytes );
			} catch ( \InvalidArgumentException $e ) {
				throw new ProviderException( $e->getMessage(), ProviderException::INVALID_REQUEST );
			}
			$response = $this->text->generate( VisionDesign::request( $prepared['tiles'], (string) ( $studio['model'] ?? '' ), min( 16000, max( 8000, (int) $this->settings->get( 'ai.max_output_tokens' ) ) ) ) );
			$design   = VisionDesign::toDocument( $response->data );
			if ( array() === $design['document']['sections'] ) {
				throw new ProviderException( __( 'The AI could not recognise any page sections in this screenshot. Try a clearer or larger screenshot of a web page.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
			}
		} catch ( ProviderException $e ) {
			// Keep the file while the job runner may retry (rate limits, timeouts); otherwise remove it.
			if ( ! $e->retryable && ProviderException::RATE_LIMIT !== $e->category ) {
				$this->forget( $project_id, $token );
			}
			throw $e;
		}
		$design['report']['tiles']     = count( $prepared['tiles'] );
		$design['report']['truncated'] = $prepared['truncated'];
		$studio['design']              = $design + array(
			'provider' => $response->provider,
			'model'    => $response->model,
			'at'       => time(),
		);
		$this->projects->save( $project_id, 'studio', $studio );
		// The screenshot was only needed for this one request.
		$this->forget( $project_id, $token );
	}

	/**
	 * Delete the uploaded screenshot and its token.
	 *
	 * @param int    $project_id Project.
	 * @param string $token      Token.
	 */
	private function forget( int $project_id, string $token ): void {
		$this->store->delete( $token );
		$fresh = (array) $this->projects->section( $project_id, 'studio' );
		unset( $fresh['token'] );
		$this->projects->save( $project_id, 'studio', $fresh );
	}

	/**
	 * Content for the layout (Output B), when a brief was given and the text is not kept.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 * @throws ProviderException On failure.
	 */
	private function write( int $project_id, array $studio ): void {
		if ( isset( $studio['written'] ) ) {
			return;
		}
		$brief = (array) ( $studio['brief'] ?? array() );
		if ( ! empty( $studio['options']['keep_text'] ) || '' === (string) ( $brief['topic'] ?? '' ) ) {
			$studio['written'] = array(
				'texts'    => array(),
				'warnings' => array(),
				'skipped'  => true,
			);
			$this->projects->save( $project_id, 'studio', $studio );
			return;
		}
		$this->requireDesign( $studio );
		$slots   = array_slice( VisionDesign::slots( $studio['design']['document'] ), 0, 150 );
		$profile = WritingProfile::fromSettings( $this->settings );
		$request = CloneContentGenerator::request( $brief, $slots, $profile->context(), min( 16000, max( 4000, (int) $this->settings->get( 'ai.max_output_tokens' ) ) ) );
		$answer  = $this->text->generate( $request );
		$parsed  = CloneContentGenerator::parse( $answer->data, $slots );
		$parsed['texts']   = array_map( array( $profile, 'polish' ), $parsed['texts'] );
		$studio['written'] = $parsed + array(
			'provider' => $answer->provider,
			'model'    => $answer->model,
		);
		$this->projects->save( $project_id, 'studio', $studio );
	}

	/**
	 * Placeholder images for the image slots.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 */
	private function images( int $project_id, array $studio ): void {
		if ( isset( $studio['image_ids'] ) ) {
			return;
		}
		$this->requireDesign( $studio );
		$ids     = array();
		$aspects = self::imageSlots( $studio['design']['document'] );
		$notes   = array();
		if ( array() !== $aspects && ! empty( $studio['options']['placeholders'] ) ) {
			if ( ! PlaceholderImage::available() ) {
				$notes[] = 'placeholders_need_gd';
			} elseif ( ! current_user_can( 'upload_files' ) ) {
				$notes[] = 'placeholders_need_upload_permission';
			} else {
				$color = (string) ( $studio['design']['tokens']['colors']['primary'] ?? '' ) ?: '#4f46e5';
				foreach ( array_slice( $aspects, 0, 12 ) as $i => $slot ) {
					$bytes = PlaceholderImage::png( $slot['aspect'], $color, $project_id . '-' . $i );
					$ids[] = $this->media->import(
						$bytes,
						ImageValidator::validate( $bytes ),
						array(
							'title'      => __( 'Placeholder image', 'ai-seo-content-studio' ),
							'alt'        => $slot['alt'],
							'project_id' => $project_id,
							'provider'   => 'placeholder',
						)
					);
				}
			}
		}
		$studio['image_ids']   = $ids;
		$studio['image_notes'] = $notes;
		$this->projects->save( $project_id, 'studio', $studio );
	}

	/**
	 * The draft.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 * @throws ProviderException On failure.
	 */
	private function build( int $project_id, array $studio ): void {
		if ( isset( $studio['result']['page_id'] ) ) {
			return;
		}
		$this->requireDesign( $studio );
		$design  = $studio['design'];
		$written = (array) ( $studio['written'] ?? array() );
		$doc     = VisionDesign::applyTexts( $design['document'], (array) ( $written['texts'] ?? array() ) );
		$report  = (array) $design['report'];
		$ids     = array_values( (array) ( $studio['image_ids'] ?? array() ) );
		$next    = 0;
		$doc     = VisionDesign::resolveImages(
			$doc,
			static function () use ( $ids, &$next ): int {
				return (int) ( $ids[ $next++ ] ?? 0 );
			},
			$report
		);
		$title = trim( (string) ( $studio['options']['title'] ?? '' ) ) ?: (string) ( $written['page_title'] ?? '' );
		if ( '' !== $title ) {
			$doc['page']['title'] = $title;
		}
		$profile = (int) ( $studio['options']['profile_id'] ?? 0 );
		$tokens  = $design['tokens'];
		if ( $profile > 0 ) {
			if ( ! $this->profiles->canAccess( $profile, (int) get_current_user_id() ) ) {
				throw new ProviderException( __( 'You can no longer use the chosen brand profile.', 'ai-seo-content-studio' ), ProviderException::PERMISSION );
			}
			$tokens = (array) ( $this->profiles->get( $profile )['tokens'] ?? array() );
		}
		try {
			$result = $this->studio->builder()->build(
				$doc,
				$tokens,
				array(
					'user_id'         => (int) get_current_user_id(),
					'project_id'      => $project_id,
					'profile_id'      => $profile,
					'generation_type' => 'screenshot',
				),
				DocumentValidator::MODE_GENERATED
			);
		} catch ( DraftException $e ) {
			throw new ProviderException( implode( ' ', array_slice( $e->errors, 0, 3 ) ), ProviderException::INVALID_RESPONSE );
		}
		$result['report']      = $report + array( 'image_notes' => (array) ( $studio['image_notes'] ?? array() ) );
		$result['ai_warnings'] = (array) ( $written['warnings'] ?? array() );
		$studio['result']      = $result;
		$this->projects->save( $project_id, 'studio', $studio );
		$this->projects->setStage( $project_id, 'studio_draft' );
	}

	/**
	 * Image slots of a design document, in document order.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return list<array{aspect: string, alt: string}>
	 */
	public static function imageSlots( array $doc ): array {
		$out  = array();
		$walk = static function ( array $elements ) use ( &$walk, &$out ): void {
			foreach ( $elements as $e ) {
				if ( 'group' === ( $e['type'] ?? '' ) ) {
					$walk( (array) $e['elements'] );
				} elseif ( '_image' === ( $e['type'] ?? '' ) ) {
					$out[] = array(
						'aspect' => (string) $e['aspect'],
						'alt'    => (string) $e['alt'],
					);
				}
			}
		};
		foreach ( (array) ( $doc['sections'] ?? array() ) as $section ) {
			$walk( (array) $section['elements'] );
		}
		return $out;
	}

	/**
	 * Fail clearly when the analysis is missing.
	 *
	 * @param array<string, mixed> $studio Studio section.
	 * @throws ProviderException When there is no design.
	 */
	private function requireDesign( array $studio ): void {
		if ( empty( $studio['design']['document']['sections'] ) ) {
			throw new ProviderException( __( 'Analyse the screenshot first.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
	}
}
