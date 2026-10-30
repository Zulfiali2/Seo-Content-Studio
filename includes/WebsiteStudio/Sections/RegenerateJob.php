<?php
/**
 * Background job for AI section regeneration.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Sections;

use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Jobs\JobHandlerInterface;
use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\ImageGenerationService;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;

defined( 'ABSPATH' ) || exit;

/**
 * One step: rewrite / improve SEO / redesign the section with one text request, or make new
 * images for it with the image provider. The result is stored as a proposal (Proposals); the
 * page itself is not changed. Results are stored before the step ends, so a retry never
 * repeats a billed request (images are remembered one by one).
 */
class RegenerateJob implements JobHandlerInterface {

	public const TYPE = 'studio_section_regenerate';

	public const MAX_IMAGES = 3;

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository      $projects  Projects.
	 * @param TextGenerationService  $text      Text generation.
	 * @param ImageGenerationService $images    Image generation.
	 * @param MediaImporter          $media     Media importer.
	 * @param SettingsRepository     $settings  Settings.
	 * @param Proposals              $proposals Proposals.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly TextGenerationService $text,
		private readonly ImageGenerationService $images,
		private readonly MediaImporter $media,
		private readonly SettingsRepository $settings,
		private readonly Proposals $proposals,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function steps( int $project_id, array $options ): array {
		return array( 'generate' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stepLabel( string $step ): string {
		return __( 'Regenerating the section with AI', 'ai-seo-content-studio' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool {
		$studio = (array) $this->projects->section( $project_id, 'studio' );
		if ( isset( $studio['proposal'] ) ) {
			return true;
		}
		$user = (int) get_current_user_id();
		try {
			$context = $this->proposals->context( (int) $studio['page_id'], (string) $studio['section_id'] );
			if ( 'image' === $studio['action'] ) {
				$proposal = $this->images( $project_id, $studio, $context, $user );
			} else {
				$proposal = $this->write( $studio, $context, $user );
			}
		} catch ( DraftException $e ) {
			throw new ProviderException( implode( ' ', array_slice( $e->errors, 0, 3 ) ), ProviderException::INVALID_REQUEST );
		} catch ( \UnexpectedValueException $e ) {
			throw new ProviderException( $e->getMessage(), ProviderException::INVALID_RESPONSE );
		}
		$this->proposals->store( $project_id, $proposal );
		return true;
	}

	/**
	 * Text actions.
	 *
	 * @param array<string, mixed> $studio  Studio section.
	 * @param array<string, mixed> $context Proposals::context().
	 * @param int                  $user    User.
	 * @return array<string, mixed> Proposal.
	 * @throws ProviderException On provider errors.
	 */
	private function write( array $studio, array $context, int $user ): array {
		$options = (array) ( $studio['options'] ?? array() );
		$brief   = $this->brief( $context['record'], $options );
		$doc     = $context['record']['document'];
		$page    = array(
			'title' => (string) ( $doc['page']['title'] ?? '' ),
			'types' => array_map( static fn ( array $s ): string => (string) $s['type'], (array) $doc['sections'] ),
			'index' => $context['located']['index'],
		);
		$profile = WritingProfile::fromSettings( $this->settings );
		$answer  = $this->text->generate( SectionWriter::request( (string) $studio['action'], $context['located']['section'], $page, $brief, (string) ( $options['instruction'] ?? '' ), $profile->context() ) );
		$parsed  = SectionWriter::parse( $answer->data, (string) $studio['action'], $context['located']['section'], $brief );
		$summary = '' !== $parsed['summary'] ? $parsed['summary'] : __( 'Section regenerated', 'ai-seo-content-studio' );
		return $this->proposals->build(
			$context,
			self::polish( $parsed['section'], $profile ),
			$summary,
			array(
				'action'   => (string) $studio['action'],
				'options'  => $options,
				'provider' => $answer->provider,
				'model'    => $answer->model,
				'report'   => $parsed['report'],
			),
			$user
		);
	}

	/**
	 * New images for the section.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $studio     Studio section.
	 * @param array<string, mixed> $context    Proposals::context().
	 * @param int                  $user       User.
	 * @return array<string, mixed> Proposal.
	 * @throws ProviderException On provider errors.
	 */
	private function images( int $project_id, array $studio, array $context, int $user ): array {
		$section  = $context['located']['section'];
		$pictures = SectionWriter::images( (array) $section['elements'] );
		if ( array() === $pictures ) {
			throw new ProviderException( __( 'This section has no images to regenerate.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
		$options = (array) ( $studio['options'] ?? array() );
		$which   = (int) ( $options['image_index'] ?? -1 );
		$targets = $which >= 0 && isset( $pictures[ $which ] ) ? array( $which ) : array_slice( array_keys( $pictures ), 0, self::MAX_IMAGES );
		$made    = (array) ( $studio['images_made'] ?? array() );
		foreach ( $targets as $i ) {
			if ( isset( $made[ $i ] ) ) {
				continue; // Made before a retry.
			}
			$alt    = (string) $pictures[ $i ]['alt'];
			$prompt = trim( (string) ( $options['prompt'] ?? '' ) );
			$prompt = '' !== $prompt ? $prompt : ( '' !== $alt ? 'A professional, natural-looking photograph: ' . $alt . '. No text, logos or watermarks.' : 'A professional photograph for a "' . $section['type'] . '" section of a business website. No text, logos or watermarks.' );
			$result = $this->images->generate( new ImageRequest( $prompt, '3:2', null, 'image_inline' ) );
			$id     = $this->media->import(
				$result['response']->bytes,
				$result['file'],
				array(
					'title'      => '' !== $alt ? $alt : __( 'AI image', 'ai-seo-content-studio' ),
					'alt'        => $alt,
					'prompt'     => $prompt,
					'provider'   => $result['response']->provider,
					'model'      => $result['response']->model,
					'project_id' => $project_id,
				)
			);
			$made[ $i ] = array(
				'attachment_id' => $id,
				'url'           => (string) wp_get_attachment_image_url( $id, 'large' ),
				'provider'      => $result['response']->provider,
				'model'         => $result['response']->model,
			);
			$fresh                = (array) $this->projects->section( $project_id, 'studio' );
			$fresh['images_made'] = $made;
			$this->projects->save( $project_id, 'studio', $fresh );
		}
		$n                   = 0;
		$swap                = static function ( array $elements ) use ( &$swap, &$n, $made ): array {
			foreach ( $elements as $k => $e ) {
				if ( 'group' === $e['type'] ) {
					$elements[ $k ]['elements'] = $swap( (array) $e['elements'] );
				} elseif ( 'image' === $e['type'] ) {
					if ( isset( $made[ $n ] ) ) {
						$elements[ $k ]['attachment_id'] = (int) $made[ $n ]['attachment_id'];
						$elements[ $k ]['url']           = '';
					}
					++$n;
				}
			}
			return $elements;
		};
		$section['elements'] = $swap( (array) $section['elements'] );
		$first               = reset( $made );
		return $this->proposals->build(
			$context,
			$section,
			/* translators: %d: number of images. */
			sprintf( _n( '%d new AI image', '%d new AI images', count( $made ), 'ai-seo-content-studio' ), count( $made ) ),
			array(
				'action'   => 'image',
				'options'  => $options,
				'provider' => (string) ( $first['provider'] ?? '' ),
				'model'    => (string) ( $first['model'] ?? '' ),
				'images'   => array_values( $made ),
			),
			$user
		);
	}

	/**
	 * The brief: the page's original project brief, with the user's keyword override.
	 *
	 * @param array<string, mixed> $record  Record.
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed>
	 */
	private function brief( array $record, array $options ): array {
		$brief   = array();
		$project = (int) ( $record['project_id'] ?? 0 );
		if ( $project > 0 && $this->projects->canAccess( $project, (int) get_current_user_id() ) ) {
			$brief = (array) ( $this->projects->section( $project, 'studio' )['brief'] ?? array() );
		}
		foreach ( array( 'topic', 'primary_keyword' ) as $key ) {
			if ( '' !== (string) ( $options[ $key ] ?? '' ) ) {
				$brief[ $key ] = (string) $options[ $key ];
			}
		}
		return $brief;
	}

	/**
	 * Writing-profile clean-up of the new text.
	 *
	 * @param array<string, mixed> $section Section.
	 * @param WritingProfile       $profile Profile.
	 * @return array<string, mixed>
	 */
	private static function polish( array $section, WritingProfile $profile ): array {
		$walk = static function ( array $elements ) use ( &$walk, $profile ): array {
			foreach ( $elements as $i => $e ) {
				if ( 'group' === $e['type'] ) {
					$elements[ $i ]['elements'] = $walk( (array) $e['elements'] );
				} elseif ( 'text' === $e['type'] ) {
					$elements[ $i ]['html'] = $profile->polish( (string) $e['html'] );
				}
			}
			return $elements;
		};
		$section['elements'] = $walk( (array) $section['elements'] );
		return $section;
	}
}
