<?php
/**
 * Section regeneration proposals: build, preview, approve, discard.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Sections;

use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Generator\GeneratorJob;
use AiSeoContentStudio\WebsiteStudio\Generator\SiteGateway;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Preview\PreviewManager;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * A regeneration never writes to the page directly. It produces a proposal: the new section
 * (design document and its validated Elementor elements), kept in a studio project. The user
 * previews it in a temporary page, then approves it (only that section's elements are
 * replaced, a revision is stored) or discards it.
 *
 * Project "studio" section: {source: "regenerate", page_id, section_id, action, options,
 * instruction, proposal, preview_id, status: pending|approved|discarded}.
 */
class Proposals {

	public const KIND = 'website_studio';

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository      $projects Projects.
	 * @param StudioServices         $studio   Studio services.
	 * @param PreviewManager         $previews Preview pages.
	 * @param SiteGateway            $site     Attachments.
	 * @param MediaImporter          $media    Media clean-up.
	 * @param BrandProfileRepository $profiles Brand profiles.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly StudioServices $studio,
		private readonly PreviewManager $previews,
		private readonly SiteGateway $site,
		private readonly MediaImporter $media,
		private readonly BrandProfileRepository $profiles,
	) {}

	/**
	 * The page, its record and the located section.
	 *
	 * @param int    $page_id    Page.
	 * @param string $section_id Section.
	 * @return array{record: array<string, mixed>, elements: array<int, mixed>, located: array<string, mixed>}
	 * @throws DraftException When the section cannot be regenerated.
	 */
	public function context( int $page_id, string $section_id ): array {
		$record = $this->studio->pages()->forPage( $page_id );
		if ( null === $record || empty( $record['document']['sections'] ) ) {
			throw new DraftException( 'not_regenerable', array( __( 'Sections can be regenerated on pages built by the AI Website Studio design engine (AI Page Generator, HTML to Elementor, Image to Design). Cloned pages keep their original structure.', 'ai-seo-content-studio' ) ) );
		}
		$elements = $this->studio->bridge()->elements( $page_id );
		$located  = SectionLocator::locate( $record, $section_id, $elements, $this->studio->compat()->containers );
		if ( null === $located ) {
			throw new DraftException( 'section_missing', array( __( 'This section is no longer on the page (it was removed or replaced in Elementor).', 'ai-seo-content-studio' ) ) );
		}
		return array(
			'record'   => $record,
			'elements' => $elements,
			'located'  => $located,
		);
	}

	/**
	 * Tokens the page was built with: stored with the document, else its brand profile, else
	 * the Elementor kit colours.
	 *
	 * @param array<string, mixed> $record  Record.
	 * @param int                  $user_id User.
	 * @return array<string, mixed>
	 */
	public function tokens( array $record, int $user_id ): array {
		if ( is_array( $record['document']['_tokens'] ?? null ) ) {
			return DesignTokens::normalize( $record['document']['_tokens'] );
		}
		$profile = (int) ( $record['profile_id'] ?? 0 );
		if ( $profile > 0 && $this->profiles->canAccess( $profile, $user_id ) ) {
			return DesignTokens::normalize( (array) ( $this->profiles->get( $profile )['tokens'] ?? array() ) );
		}
		return GeneratorJob::kitTokens( (array) ( $this->studio->bridge()->activeKit()['settings'] ?? array() ) );
	}

	/**
	 * Map and validate a new version of the section.
	 *
	 * @param array<string, mixed> $context  From context().
	 * @param array<string, mixed> $section  New section (normalised or PageDocument input).
	 * @param string               $summary  Change summary.
	 * @param array<string, mixed> $meta     action, options, provider, model, report, images.
	 * @param int                  $user_id  User.
	 * @return array<string, mixed> Proposal.
	 * @throws DraftException When the section is invalid.
	 */
	public function build( array $context, array $section, string $summary, array $meta, int $user_id ): array {
		$errors  = array();
		$section['id'] = $context['located']['section_id'];
		$doc     = PageDocument::normalize(
			array(
				'schema_version' => PageDocument::VERSION,
				'page'           => array(
					'title' => 'Section',
					'slug'  => '',
				),
				'sections'       => array( $section ),
			),
			$errors
		);
		if ( array() !== $errors ) {
			throw new DraftException( 'invalid_document', array_slice( $errors, 0, 10 ) );
		}
		$section  = $doc['sections'][0];
		$elements = $this->studio->builder()->mapper( $this->tokens( $context['record'], $user_id ) )->mapSection( $section, 0 === $context['located']['index'] );
		$report   = $this->studio->drafts()->validate( $elements, DocumentValidator::MODE_GENERATED );
		if ( array() !== $report['errors'] ) {
			throw new DraftException( 'invalid_document', $report['errors'], $report['warnings'] );
		}
		return array(
			'page_id'    => (int) $context['record']['page_id'],
			'section_id' => $context['located']['section_id'],
			'index'      => (int) $context['located']['index'],
			'element_id' => $context['located']['element_id'],
			'count'      => (int) $context['located']['count'],
			'section'    => $section,
			'elements'   => $elements,
			'summary'    => $summary,
			'meta'       => $meta,
			'at'         => time(),
		);
	}

	/**
	 * A studio project for one regeneration.
	 *
	 * @param int                  $page_id    Page.
	 * @param string               $section_id Section.
	 * @param string               $action     Action.
	 * @param array<string, mixed> $options    Options.
	 * @param int                  $user_id    User.
	 * @return int Project id (0 on failure).
	 */
	public function createProject( int $page_id, string $section_id, string $action, array $options, int $user_id ): int {
		$project = $this->projects->create(
			array(
				'keyword'      => $this->studio->posts()->title( $page_id ),
				'content_type' => 'studio_regenerate',
			),
			$user_id
		);
		if ( $project <= 0 ) {
			return 0;
		}
		update_post_meta( $project, ProjectRepository::KIND_META, self::KIND );
		$this->projects->save(
			$project,
			'studio',
			array(
				'source'     => 'regenerate',
				'page_id'    => $page_id,
				'section_id' => $section_id,
				'action'     => $action,
				'options'    => $options,
				'status'     => 'pending',
			)
		);
		return $project;
	}

	/**
	 * Store a proposal on its project.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $proposal   Proposal.
	 */
	public function store( int $project_id, array $proposal ): void {
		$studio             = (array) $this->projects->section( $project_id, 'studio' );
		$studio['proposal'] = $proposal;
		$this->projects->save( $project_id, 'studio', $studio );
	}

	/**
	 * Public view of a proposal (no element tree).
	 *
	 * @param array<string, mixed> $studio Studio section.
	 * @return array<string, mixed>|null
	 */
	public static function view( array $studio ): ?array {
		$p = $studio['proposal'] ?? null;
		if ( ! is_array( $p ) ) {
			return null;
		}
		return array(
			'page_id'    => $p['page_id'],
			'section_id' => $p['section_id'],
			'element_id' => (string) ( $p['elements'][0]['id'] ?? '' ),
			'summary'    => $p['summary'],
			'action'     => (string) ( $p['meta']['action'] ?? '' ),
			'provider'   => (string) ( $p['meta']['provider'] ?? '' ),
			'model'      => (string) ( $p['meta']['model'] ?? '' ),
			'report'     => (array) ( $p['meta']['report'] ?? array() ),
			'images'     => array_values( (array) ( $p['meta']['images'] ?? array() ) ),
			'texts'      => array_slice( ElementTree::texts( (array) $p['elements'] ), 0, 40 ),
			'status'     => (string) ( $studio['status'] ?? 'pending' ),
		);
	}

	/**
	 * Preview the page with the proposal in place (temporary page).
	 *
	 * @param int $project_id Project.
	 * @param int $user_id    User.
	 * @return array{preview_url: string, element_id: string}
	 * @throws DraftException When there is nothing to preview.
	 */
	public function preview( int $project_id, int $user_id ): array {
		$studio   = (array) $this->projects->section( $project_id, 'studio' );
		$proposal = $this->pending( $studio );
		$current  = $this->studio->bridge()->elements( (int) $proposal['page_id'] );
		$elements = ElementTree::replaceTop( $current, (string) $proposal['element_id'], (int) $proposal['count'], (array) $proposal['elements'] );
		if ( null === $elements ) {
			throw new DraftException( 'section_missing', array( __( 'The section was removed from the page since this proposal was made. Regenerate it again.', 'ai-seo-content-studio' ) ) );
		}
		$this->previews->delete( (int) ( $studio['preview_id'] ?? 0 ) );
		$preview              = $this->previews->create( $elements, array(), $user_id, $this->studio->posts()->title( (int) $proposal['page_id'] ), (string) $this->studio->posts()->meta( (int) $proposal['page_id'], '_wp_page_template' ) );
		$studio['preview_id'] = $preview['preview_id'];
		$this->projects->save( $project_id, 'studio', $studio );
		return array(
			'preview_url' => $preview['preview_url'],
			'element_id'  => (string) ( $proposal['elements'][0]['id'] ?? '' ),
		);
	}

	/**
	 * Put the proposal into the page (only that section changes).
	 *
	 * @param int $project_id Project.
	 * @param int $user_id    User.
	 * @return array<string, mixed> DraftManager::update() result.
	 * @throws DraftException When the page may not be changed or the section moved.
	 */
	public function approve( int $project_id, int $user_id ): array {
		$studio   = (array) $this->projects->section( $project_id, 'studio' );
		$proposal = $this->pending( $studio );
		$page_id  = (int) $proposal['page_id'];
		$context  = $this->context( $page_id, (string) $proposal['section_id'] );
		if ( $context['located']['element_id'] !== $proposal['element_id'] ) {
			throw new DraftException( 'section_changed', array( __( 'The section was changed in Elementor since this proposal was made. Regenerate it again.', 'ai-seo-content-studio' ) ) );
		}
		$elements = ElementTree::replaceTop( $context['elements'], (string) $proposal['element_id'], (int) $proposal['count'], (array) $proposal['elements'] );
		if ( null === $elements ) {
			throw new DraftException( 'section_missing', array( __( 'This section is no longer on the page.', 'ai-seo-content-studio' ) ) );
		}
		$document                                       = $context['record']['document'];
		$document['sections'][ $proposal['index'] ]     = $proposal['section'];
		$section_map                                    = (array) $context['record']['section_map'];
		$section_map[ (string) $proposal['section_id'] ] = (string) $proposal['elements'][0]['id'];
		$result = $this->studio->drafts()->update(
			$page_id,
			$elements,
			$user_id,
			DocumentValidator::MODE_CLONED,
			array(
				'action'      => 'regenerate',
				'summary'     => (string) $proposal['summary'],
				'section_id'  => (string) $proposal['section_id'],
				'meta'        => array_intersect_key( (array) $proposal['meta'], array_flip( array( 'action', 'options', 'provider', 'model' ) ) ),
				'document'    => $document,
				'section_map' => $section_map,
			)
		);
		foreach ( (array) ( $proposal['meta']['images'] ?? array() ) as $image ) {
			$this->site->attach( (int) $image['attachment_id'], $page_id );
		}
		$this->previews->delete( (int) ( $studio['preview_id'] ?? 0 ) );
		unset( $studio['preview_id'] );
		$studio['status'] = 'approved';
		$this->projects->save( $project_id, 'studio', $studio );
		return $result;
	}

	/**
	 * Discard the proposal and the images made for it.
	 *
	 * @param int $project_id Project.
	 */
	public function discard( int $project_id ): void {
		$studio = (array) $this->projects->section( $project_id, 'studio' );
		if ( 'pending' === ( $studio['status'] ?? 'pending' ) ) {
			foreach ( (array) ( $studio['proposal']['meta']['images'] ?? array() ) as $image ) {
				$this->media->deleteUnused( (int) $image['attachment_id'] );
			}
		}
		$this->previews->delete( (int) ( $studio['preview_id'] ?? 0 ) );
		unset( $studio['preview_id'] );
		$studio['status'] = 'pending' === ( $studio['status'] ?? 'pending' ) ? 'discarded' : $studio['status'];
		$this->projects->save( $project_id, 'studio', $studio );
	}

	/**
	 * The pending proposal.
	 *
	 * @param array<string, mixed> $studio Studio section.
	 * @return array<string, mixed>
	 * @throws DraftException When there is none.
	 */
	private function pending( array $studio ): array {
		if ( 'regenerate' !== ( $studio['source'] ?? '' ) || ! is_array( $studio['proposal'] ?? null ) ) {
			throw new DraftException( 'no_proposal', array( __( 'There is no proposal yet. Wait for the regeneration to finish.', 'ai-seo-content-studio' ) ) );
		}
		if ( 'pending' !== ( $studio['status'] ?? 'pending' ) ) {
			throw new DraftException( 'proposal_closed', array( __( 'This proposal was already approved or discarded.', 'ai-seo-content-studio' ) ) );
		}
		return $studio['proposal'];
	}
}
