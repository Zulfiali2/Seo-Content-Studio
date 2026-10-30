<?php
/**
 * Background jobs of the AI page generator.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Generator;

use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Media\ImageValidator;
use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Media\PlaceholderImage;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\ImageGenerationService;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;
use AiSeoContentStudio\WebsiteStudio\Vision\VisionDesign;

defined( 'ABSPATH' ) || exit;

/**
 * TYPE_WRITE: one text request → page design for review (document, SEO fields, validated
 * link suggestions, fact check, image slots). Nothing is saved to WordPress pages.
 *
 * TYPE_BUILD: placeholder images → approved links → brand tokens → new Elementor draft.
 *
 * TYPE_IMAGES: one AI image per requested slot, kept as a candidate. Candidates are only put
 * into the draft when the user approves them (GeneratedImages::apply()).
 *
 * Every step stores its result first and skips itself when the result exists, so a retry never
 * repeats a billed request, imports an image twice or creates a second draft.
 *
 * Project "studio" section: {source: "generator", brief, options, candidates, design,
 * approved_links, image_ids, result, images}.
 */
class GeneratorJob implements JobHandlerInterface {

	public const TYPE_WRITE  = 'studio_generate';
	public const TYPE_BUILD  = 'studio_generate_build';
	public const TYPE_IMAGES = 'studio_page_images';

	/** Used when neither a brand profile nor Elementor kit colours exist. */
	private const DEFAULT_COLORS = array(
		'primary'    => '#2563EB',
		'secondary'  => '#1E293B',
		'accent'     => '#F59E0B',
		'text'       => '#334155',
		'heading'    => '#0F172A',
		'background' => '#FFFFFF',
		'surface'    => '#F1F5F9',
	);

	/**
	 * Constructor.
	 *
	 * @param string                 $type     TYPE_WRITE | TYPE_BUILD | TYPE_IMAGES.
	 * @param ProjectRepository      $projects Projects.
	 * @param TextGenerationService  $text     Text generation.
	 * @param ImageGenerationService $images   Image generation.
	 * @param SettingsRepository     $settings Settings.
	 * @param StudioServices         $studio   Studio services.
	 * @param MediaImporter          $media    Media importer.
	 * @param SiteGateway            $site     WordPress reads and writes.
	 * @param BrandProfileRepository $profiles Brand profiles.
	 */
	public function __construct(
		private readonly string $type,
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly ImageGenerationService $images,
		private readonly SettingsRepository $settings,
		private readonly StudioServices $studio,
		private readonly MediaImporter $media,
		private readonly SiteGateway $site,
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
		return match ( $this->type ) {
			self::TYPE_WRITE  => array( 'write' ),
			self::TYPE_BUILD  => array( 'images', 'build' ),
			default           => array_map( static fn ( string $id ): string => 'image:' . $id, array_values( array_filter( (array) ( $options['slots'] ?? array() ), 'is_string' ) ) ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function stepLabel( string $step ): string {
		if ( str_starts_with( $step, 'image:' ) ) {
			/* translators: %s: image slot, e.g. img-2. */
			return sprintf( __( 'Generating image %s', 'ai-seo-content-studio' ), substr( $step, 6 ) );
		}
		return match ( $step ) {
			'write'  => __( 'Writing the page and planning its layout with AI', 'ai-seo-content-studio' ),
			'images' => __( 'Adding placeholder images', 'ai-seo-content-studio' ),
			default  => __( 'Creating the Elementor draft', 'ai-seo-content-studio' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$studio = (array) $this->projects->section( $project_id, 'studio' );
		if ( str_starts_with( $step, 'image:' ) ) {
			$this->image( $project_id, $studio, substr( $step, 6 ), (string) ( $options['run'] ?? '' ) );
			return true;
		}
		switch ( $step ) {
			case 'write':
				$this->write( $project_id, $studio );
				return true;
			case 'images':
				$this->placeholders( $project_id, $studio );
				return true;
			case 'build':
				$this->build( $project_id, $studio );
				return true;
		}
		throw new ProviderException( 'Unknown generator step.', ProviderException::CONFIG );
	}

	/**
	 * The page design (one request).
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 * @throws ProviderException On failure.
	 */
	private function write( int $project_id, array $studio ): void {
		if ( isset( $studio['design'] ) ) {
			return;
		}
		$brief      = (array) ( $studio['brief'] ?? array() );
		$candidates = (array) ( $studio['candidates'] ?? array() );
		$profile    = WritingProfile::fromSettings( $this->settings );
		$answer     = $this->text->generate( PageGenerator::request( $brief, $profile->context(), $candidates, min( 32000, max( 16000, (int) $this->settings->get( 'ai.max_output_tokens' ) ) ) ) );
		$generated  = PageGenerator::toDocument( $answer->data, $brief );
		$adapted    = ContentLayoutMapper::adapt( self::polish( $generated['document'], $profile ) );
		$document   = $adapted['document'];
		if ( array() === $document['sections'] ) {
			throw new ProviderException( __( 'The AI answer contained no usable page sections. Try again, or add more detail to the brief.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		// Validate the layout now, so the user never reviews a page that cannot be built.
		$errors = array();
		$probe  = array( 'placeholders' => 0 );
		PageDocument::normalize( VisionDesign::resolveImages( $document, static fn (): int => 1, $probe ), $errors );
		if ( array() !== $errors ) {
			throw new ProviderException( __( 'The AI answer did not form a valid page layout:', 'ai-seo-content-studio' ) . ' ' . implode( ' ', array_slice( $errors, 0, 3 ) ), ProviderException::INVALID_RESPONSE );
		}
		$studio['design'] = array(
			'document' => $document,
			'seo'      => $generated['seo'],
			'links'    => InternalLinks::suggestions( $generated['links'], $candidates, $document ),
			'facts'    => PageGenerator::factCheck( $document ),
			'images'   => PageGenerator::imageSlots( $document ),
			'report'   => $generated['report'] + array( 'layout_changes' => $adapted['changes'] ),
			'provider' => $answer->provider,
			'model'    => $answer->model,
			'at'       => time(),
		);
		$this->projects->save( $project_id, 'studio', $studio );
	}

	/**
	 * Writing-profile clean-up (em dashes etc.) of the generated HTML.
	 *
	 * @param array<string, mixed> $doc     Document.
	 * @param WritingProfile       $profile Profile.
	 * @return array<string, mixed>
	 */
	private static function polish( array $doc, WritingProfile $profile ): array {
		$walk = static function ( array $elements ) use ( &$walk, $profile ): array {
			foreach ( $elements as $i => $e ) {
				if ( 'group' === $e['type'] ) {
					$elements[ $i ]['elements'] = $walk( $e['elements'] );
				} elseif ( 'text' === $e['type'] ) {
					$elements[ $i ]['html'] = $profile->polish( (string) $e['html'] );
				} elseif ( 'accordion' === $e['type'] ) {
					foreach ( $e['items'] as $j => $item ) {
						$elements[ $i ]['items'][ $j ]['html'] = $profile->polish( (string) $item['html'] );
					}
				}
			}
			return $elements;
		};
		foreach ( $doc['sections'] as $i => $section ) {
			$doc['sections'][ $i ]['elements'] = $walk( $section['elements'] );
		}
		return $doc;
	}

	/**
	 * Placeholder images for the image slots (replaced later by approved AI images).
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 */
	private function placeholders( int $project_id, array $studio ): void {
		if ( isset( $studio['image_ids'] ) ) {
			return;
		}
		$this->requireDesign( $studio );
		$ids   = array();
		$notes = array();
		$slots = PageGenerator::imageSlots( $studio['design']['document'] );
		if ( array() !== $slots ) {
			if ( ! PlaceholderImage::available() ) {
				$notes[] = 'placeholders_need_gd';
			} elseif ( ! current_user_can( 'upload_files' ) ) {
				$notes[] = 'placeholders_need_upload_permission';
			} else {
				$color = (string) ( $this->tokens( $studio )['colors']['primary'] ?? '' ) ?: self::DEFAULT_COLORS['primary'];
				foreach ( $slots as $slot ) {
					$bytes             = PlaceholderImage::png( $slot['aspect'], $color, $project_id . '-' . $slot['id'] );
					$ids[ $slot['id'] ] = $this->media->import(
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
		$design   = $studio['design'];
		$approved = (array) ( $studio['approved_links'] ?? array() );
		$links    = InternalLinks::apply(
			$design['document'],
			array_values( array_filter( (array) $design['links'], static fn ( array $l ): bool => in_array( $l['id'], $approved, true ) ) ),
			fn ( int $id ): string => $this->site->permalink( $id )
		);
		$doc      = $links['document'];
		$slots    = PageGenerator::imageSlots( $doc );
		$ids      = (array) ( $studio['image_ids'] ?? array() );
		$report   = array( 'placeholders' => 0 );
		$next     = 0;
		$doc      = VisionDesign::resolveImages(
			$doc,
			static function () use ( $slots, $ids, &$next ): int {
				$slot = $slots[ $next++ ] ?? null;
				return null === $slot ? 0 : (int) ( $ids[ $slot['id'] ] ?? 0 );
			},
			$report
		);
		$title = trim( (string) ( $studio['options']['title'] ?? '' ) );
		if ( '' !== $title ) {
			$doc['page']['title'] = $title;
		}
		$tokens = $this->tokens( $studio );
		try {
			$result = $this->studio->builder()->build(
				$doc,
				$tokens,
				array(
					'user_id'         => (int) get_current_user_id(),
					'project_id'      => $project_id,
					'profile_id'      => (int) ( $studio['options']['profile_id'] ?? 0 ),
					'generation_type' => 'generated',
				),
				DocumentValidator::MODE_GENERATED
			);
		} catch ( DraftException $e ) {
			throw new ProviderException( implode( ' ', array_slice( $e->errors, 0, 3 ) ), ProviderException::INVALID_RESPONSE );
		}
		$page = (int) $result['page_id'];
		$this->site->saveSeo( $page, (array) $design['seo'] );

		$images = array();
		foreach ( $slots as $slot ) {
			$id = (int) ( $ids[ $slot['id'] ] ?? 0 );
			if ( $id <= 0 ) {
				continue; // No placeholder: the slot is not on the page.
			}
			// Attached to the draft, so no clean-up deletes an image the page shows.
			$this->site->attach( $id, $page );
			$images[] = $slot + array(
				'placeholder_id' => $id,
				'current_id'     => $id,
				'status'         => 'placeholder',
				'candidate'      => null,
				'error'          => '',
			);
		}
		$result['report']        = $report + array(
			'image_notes'   => (array) ( $studio['image_notes'] ?? array() ),
			'links_applied' => $links['applied'],
			'links_skipped' => $links['skipped'],
			'color_source'  => $tokens['sources']['colors'] ?? '',
		);
		$result['seo']           = $design['seo'];
		$studio['result']        = $result;
		$studio['images']        = $images;
		$this->projects->save( $project_id, 'studio', $studio );
		$this->projects->setStage( $project_id, 'studio_draft' );
	}

	/**
	 * One AI image candidate (not inserted into the page).
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 * @param string               $slot_id    Slot.
	 * @param string               $run        Run token of this request.
	 * @throws ProviderException On retryable failures.
	 */
	private function image( int $project_id, array $studio, string $slot_id, string $run ): void {
		$index = null;
		foreach ( (array) ( $studio['images'] ?? array() ) as $i => $slot ) {
			if ( $slot['id'] === $slot_id ) {
				$index = $i;
			}
		}
		if ( null === $index ) {
			return; // Slot no longer exists.
		}
		$slot = $studio['images'][ $index ];
		if ( '' !== $run && $run === (string) ( $slot['candidate']['run'] ?? '' ) ) {
			return; // Already made in this run.
		}
		try {
			$result = $this->images->generate( new ImageRequest( (string) $slot['prompt'], (string) $slot['aspect'], null, 'image_inline' ) );
			$id     = $this->media->import(
				$result['response']->bytes,
				$result['file'],
				array(
					'title'      => '' !== $slot['alt'] ? $slot['alt'] : __( 'AI image', 'ai-seo-content-studio' ),
					'alt'        => (string) $slot['alt'],
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
			$studio['images'][ $index ]['error'] = $e->getMessage();
			$this->projects->save( $project_id, 'studio', $studio );
			return;
		}
		$previous                         = (int) ( $slot['candidate']['attachment_id'] ?? 0 );
		$studio['images'][ $index ]['candidate'] = array(
			'attachment_id'  => $id,
			'url'            => $this->site->imageUrl( $id ),
			'provider'       => $result['response']->provider,
			'model'          => $result['response']->model,
			'revised_prompt' => (string) $result['response']->revised_prompt,
			'run'            => $run,
		);
		$studio['images'][ $index ]['status']    = 'candidate';
		$studio['images'][ $index ]['error']     = '';
		$this->projects->save( $project_id, 'studio', $studio );
		if ( $previous > 0 && $previous !== $id ) {
			$this->media->deleteUnused( $previous ); // A candidate that was never approved.
		}
	}

	/**
	 * Brand tokens: the chosen profile, else the Elementor kit colours, else neutral defaults.
	 *
	 * @param array<string, mixed> $studio Studio section.
	 * @return array<string, mixed>
	 * @throws ProviderException When the profile can no longer be used.
	 */
	private function tokens( array $studio ): array {
		$profile = (int) ( $studio['options']['profile_id'] ?? 0 );
		if ( $profile > 0 ) {
			if ( ! $this->profiles->canAccess( $profile, (int) get_current_user_id() ) ) {
				throw new ProviderException( __( 'You can no longer use the chosen brand profile.', 'ai-seo-content-studio' ), ProviderException::PERMISSION );
			}
			return DesignTokens::normalize( (array) ( $this->profiles->get( $profile )['tokens'] ?? array() ) );
		}
		return self::kitTokens( (array) ( $this->studio->bridge()->activeKit()['settings'] ?? array() ) );
	}

	/**
	 * Tokens from the Elementor kit's system colours (so the page follows the site's global
	 * colours), with a light surface tint derived from the primary colour.
	 *
	 * @param array<string, mixed> $kit Kit settings.
	 * @return array<string, mixed>
	 */
	public static function kitTokens( array $kit ): array {
		$found = array();
		foreach ( (array) ( $kit['system_colors'] ?? array() ) as $item ) {
			$hex = is_array( $item ) ? DesignTokens::color( (string) ( $item['color'] ?? '' ) ) : null;
			if ( null !== $hex && 7 === strlen( $hex ) && in_array( $item['_id'] ?? '', array( 'primary', 'secondary', 'text', 'accent' ), true ) ) {
				$found[ $item['_id'] ] = $hex;
			}
		}
		$colors = self::DEFAULT_COLORS;
		$source = 'none';
		if ( isset( $found['primary'] ) ) {
			$source            = 'elementor_kit';
			$colors            = array_merge( $colors, $found );
			$colors['heading'] = $found['secondary'] ?? $found['text'] ?? $colors['heading'];
			$colors['surface'] = self::tint( $found['primary'], 0.93 );
		}
		return DesignTokens::normalize(
			array(
				'colors'  => $colors,
				'sources' => array( 'colors' => $source ),
			)
		);
	}

	/**
	 * Mix a colour with white.
	 *
	 * @param string $hex   #RRGGBB.
	 * @param float  $white Share of white (0-1).
	 */
	public static function tint( string $hex, float $white ): string {
		$out = '#';
		foreach ( str_split( substr( $hex, 1 ), 2 ) as $pair ) {
			$out .= sprintf( '%02X', (int) round( hexdec( $pair ) * ( 1 - $white ) + 255 * $white ) );
		}
		return $out;
	}

	/**
	 * Fail clearly when the design is missing.
	 *
	 * @param array<string, mixed> $studio Studio section.
	 * @throws ProviderException When there is no design.
	 */
	private function requireDesign( array $studio ): void {
		if ( empty( $studio['design']['document']['sections'] ) ) {
			throw new ProviderException( __( 'Generate the page design first.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
	}
}
