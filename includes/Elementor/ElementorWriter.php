<?php
/**
 * Save plugin content as an Elementor draft.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

use AiSeoContentStudio\Content\ContentModel;
use AiSeoContentStudio\Content\DraftWriter;
use AiSeoContentStudio\Media\ImagePlan;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Creates (or updates) a draft built with Elementor from the project's content.
 *
 * Safety rules:
 * - Only drafts are written; DraftWriter refuses posts that are no longer drafts.
 * - An existing linked draft is only replaced if the plugin itself built it with Elementor
 *   and nobody has changed its layout since (hash check); otherwise a new draft is required.
 * - The previous layout is kept in `_aiscs_elementor_backup` before it is replaced.
 * - The layout is validated (structure, ids, widgets registered on this site) before saving,
 *   and read back afterwards to confirm Elementor stored every widget.
 */
class ElementorWriter {

	public const HASH_META   = '_aiscs_elementor_hash';
	public const BACKUP_META = '_aiscs_elementor_backup';

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository        $projects Projects.
	 * @param DraftWriter              $drafts   Draft writer.
	 * @param ElementorBridgeInterface $bridge   Elementor.
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly DraftWriter $drafts,
		private readonly ElementorBridgeInterface $bridge,
	) {}

	/**
	 * Save.
	 *
	 * @param int                  $project_id Project.
	 * @param string               $post_type  post | page.
	 * @param bool                 $as_new     Force a new draft.
	 * @param string               $style      content | designed.
	 * @param array<string, mixed> $cta        Call to action: text, url.
	 * @param array<string, mixed> $template   Blog template options: accent (colour), form (shortcode).
	 * @return array<string, mixed>
	 * @throws ProviderException When Elementor cannot be used or the save is refused.
	 */
	public function save( int $project_id, string $post_type, bool $as_new, string $style, array $cta = array(), array $template = array() ): array {
		if ( ! $this->bridge->isActive() ) {
			throw new ProviderException( __( 'Elementor is not active. Activate Elementor or save a standard WordPress draft instead.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		if ( ! $this->bridge->supportsPostType( $post_type ) ) {
			/* translators: %s: post type name. */
			throw new ProviderException( sprintf( __( 'Elementor is not enabled for %s on this site (Elementor → Settings → Post Types).', 'ai-seo-content-studio' ), 'page' === $post_type ? __( 'pages', 'ai-seo-content-studio' ) : __( 'posts', 'ai-seo-content-studio' ) ), ProviderException::CONFIG );
		}
		$content = $this->projects->section( $project_id, 'content' );
		if ( null === $content || '' === trim( ContentModel::bodyHtml( $content ) ) ) {
			throw new ProviderException( __( 'There is no content to save yet.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}

		$style   = in_array( $style, BlogTemplates::STYLES, true ) ? $style : LayoutBuilder::STYLE_CONTENT;
		$cta_url = trim( (string) ( $cta['url'] ?? '' ) );
		if ( '' !== $cta_url && ! self::isLinkTarget( $cta_url ) ) {
			throw new ProviderException( __( 'The button link must be a full URL (https://…) or a path on this site (/contact/).', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
		$cta = array(
			'text' => mb_substr( sanitize_text_field( (string) ( $cta['text'] ?? '' ) ), 0, 80 ),
			'url'  => $cta_url,
		);

		$existing = $as_new ? 0 : $this->linkedDraft( $project_id );
		$backup   = null;
		if ( $existing > 0 ) {
			$raw = (string) get_post_meta( $existing, '_elementor_data', true );
			if ( 'builder' !== get_post_meta( $existing, '_elementor_edit_mode', true ) ) {
				throw new ProviderException( __( 'The linked draft was saved for the WordPress editor, not Elementor. Save the Elementor layout as a new draft so that draft is not converted.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
			}
			if ( '' !== $raw && md5( $raw ) !== (string) get_post_meta( $existing, self::HASH_META, true ) ) {
				throw new ProviderException( __( 'The linked draft has been changed in Elementor since it was created, so it was not replaced. Save as a new draft instead.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
			}
			$backup = $raw;
		}

		$images = ImagePlan::forDraft( $this->projects->section( $project_id, 'images' ) );
		if ( BlogTemplates::isTemplate( $style ) ) {
			$builder = new BlogTemplateBuilder( $this->bridge->containersActive(), $style, null, $this->bridge->canHideThemeTitle(), BlogTemplates::formShortcode( $template['form'] ?? '' ) );
			$layout  = $builder->build( $content, $cta, $images['inline'] );
		} else {
			$builder = new LayoutBuilder( $this->bridge->containersActive(), $style, null, $this->bridge->canHideThemeTitle() );
			$layout  = $builder->build( $content, $cta, $images['inline'] );
		}
		$errors = LayoutValidator::validate( $layout['elements'], $this->bridge->widgetTypes() );
		if ( array() !== $errors ) {
			throw new ProviderException(
				/* translators: %s: first validation problem. */
				sprintf( __( 'The Elementor layout failed validation and was not saved (%s).', 'ai-seo-content-studio' ), $errors[0] ),
				ProviderException::INVALID_RESPONSE
			);
		}

		$draft   = $this->drafts->save( $project_id, $post_type, $as_new || 0 === $existing, 'elementor' );
		$post_id = (int) $draft['post_id'];

		if ( null !== $backup && '' !== $backup ) {
			update_post_meta(
				$post_id,
				self::BACKUP_META,
				wp_slash(
					(string) wp_json_encode(
						array(
							'saved_at' => time(),
							'data'     => $backup,
						)
					)
				)
			);
		}

		if ( ! $this->bridge->save( $post_id, $layout['elements'], $layout['settings'] ) ) {
			throw new ProviderException( __( 'Elementor did not accept the layout. The draft was saved with the content as plain HTML; you can open it in the WordPress editor.', 'ai-seo-content-studio' ), ProviderException::SERVER );
		}

		if ( BlogTemplates::isTemplate( $style ) ) {
			update_post_meta( $post_id, BlogTemplates::META_TEMPLATE, $style );
			update_post_meta( $post_id, BlogTemplates::META_ACCENT, BlogTemplates::accent( $template['accent'] ?? '' ) );
		} else {
			delete_post_meta( $post_id, BlogTemplates::META_TEMPLATE );
			delete_post_meta( $post_id, BlogTemplates::META_ACCENT );
		}

		$expected = LayoutValidator::widgetCount( $layout['elements'] );
		$stored   = LayoutValidator::widgetCount( $this->bridge->elements( $post_id ) );
		update_post_meta( $post_id, self::HASH_META, md5( (string) get_post_meta( $post_id, '_elementor_data', true ) ) );

		$this->projects->save(
			$project_id,
			'draft',
			array_merge(
				(array) $this->projects->section( $project_id, 'draft' ),
				array(
					'format'     => 'elementor',
					'style'      => $style,
					'containers' => $this->bridge->containersActive(),
					'widgets'    => $stored,
				)
			)
		);

		return $draft + array(
			'created'            => 0 === $existing,
			'elementor_edit_url' => $this->bridge->editUrl( $post_id ),
			'widgets'            => $stored,
			'complete'           => $stored === $expected,
		);
	}

	/**
	 * Linked draft id that still exists (0 when none).
	 *
	 * @param int $project_id Project.
	 */
	private function linkedDraft( int $project_id ): int {
		$id = (int) ( $this->projects->section( $project_id, 'draft' )['post_id'] ?? 0 );
		if ( $id <= 0 ) {
			return 0;
		}
		$status = get_post_status( $id );
		return false === $status || 'trash' === $status ? 0 : $id;
	}

	/**
	 * A visitor-facing link: an http(s) URL with a host, or a path on this site.
	 * (Syntax only: the server never requests this URL.)
	 *
	 * @param string $url URL.
	 */
	public static function isLinkTarget( string $url ): bool {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return ! preg_match( '/[\s<>"]/', $url );
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& ! preg_match( '/[\s<>"]/', $url );
	}
}
