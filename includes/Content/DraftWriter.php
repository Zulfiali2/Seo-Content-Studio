<?php
/**
 * Saves project content as a WordPress draft.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Media\ImagePlan;
use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Rules:
 * - Always saves with status "draft". Never publishes.
 * - Updates the project's existing draft only while it is still a draft or
 *   pending review (WordPress keeps a revision of the previous content).
 *   If that post has been published or scheduled, nothing is changed and the
 *   caller must explicitly ask for a new draft.
 * - Optimizer projects save to a new post linked to the source; the source post
 *   itself is never modified.
 */
class DraftWriter {

	/** Post meta written on drafts. */
	public const META = array(
		'project'          => '_aiscs_project_id',
		'seo_title'        => '_aiscs_seo_title',
		'meta_description' => '_aiscs_meta_description',
		'schema_type'      => '_aiscs_schema_type',
		'og_title'         => '_aiscs_og_title',
		'og_description'   => '_aiscs_og_description',
		'image_prompt'     => '_aiscs_featured_image_prompt',
		'area_served'      => '_aiscs_area_served',
		'source_post'      => '_aiscs_source_post',
		'content_hash'     => '_aiscs_content_hash',
		'faqs'             => '_aiscs_faqs',
		'seo_synced'       => '_aiscs_seo_synced',
	);

	/**
	 * SEO plugin fields filled from the draft's metadata: plugin constant => field => source.
	 * Each plugin reads its own fields; this plugin's own output is off while one is active.
	 */
	private const SEO_PLUGIN_FIELDS = array(
		'WPSEO_VERSION'     => array(
			'_yoast_wpseo_title'    => 'meta_title',
			'_yoast_wpseo_metadesc' => 'meta_description',
			'_yoast_wpseo_focuskw'  => 'keyword',
		),
		'RANK_MATH_VERSION' => array(
			'rank_math_title'         => 'meta_title',
			'rank_math_description'   => 'meta_description',
			'rank_math_focus_keyword' => 'keyword',
		),
	);

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository $projects Projects.
	 * @param VersionStore      $versions Versions.
	 */
	public function __construct( private readonly ProjectRepository $projects, private readonly VersionStore $versions ) {}

	/**
	 * Questions and plain-text answers for FAQPage structured data: only FAQs shown
	 * on the page, and only when FAQ schema is switched on for this content.
	 *
	 * @param array<string, mixed> $content Content.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function schemaFaqs( array $content ): array {
		if ( false === ( $content['meta']['faq_schema'] ?? true ) ) {
			return array();
		}
		$out = array();
		foreach ( ContentModel::publishedFaqs( $content ) as $faq ) {
			$answer = ContentModel::text( (string) $faq['answer_html'] );
			if ( '' !== $answer ) {
				$out[] = array(
					'question' => (string) $faq['question'],
					'answer'   => $answer,
				);
			}
		}
		return $out;
	}

	/**
	 * Create or update the draft.
	 *
	 * @param int    $project_id Project.
	 * @param string $post_type  "post" or "page".
	 * @param bool   $as_new     Force a new draft.
	 * @param string $format     "blocks", "classic" or "elementor" (plain HTML fallback; Elementor stores its own copy).
	 * @return array{post_id: int, created: bool, edit_url: string, preview_url: string}
	 * @throws ProviderException When there is nothing to save, the draft is published, or saving fails.
	 */
	public function save( int $project_id, string $post_type, bool $as_new = false, string $format = 'blocks' ): array {
		$content = $this->projects->section( $project_id, 'content' );
		if ( null === $content || '' === trim( ContentModel::bodyHtml( $content ) ) ) {
			throw new ProviderException( __( 'There is no content to save yet.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		$inputs   = $this->projects->section( $project_id, 'inputs' ) ?? array();
		$optimize = 'optimize' === ( $inputs['mode'] ?? '' );
		$existing = $this->projects->section( $project_id, 'draft' );
		// The draft section only ever holds posts this plugin created, never an
		// optimizer's source post, so updating it cannot touch the original.
		$post_id = $as_new ? 0 : (int) ( $existing['post_id'] ?? 0 );

		if ( $post_id > 0 ) {
			$status = get_post_status( $post_id );
			if ( false === $status || 'trash' === $status ) {
				$post_id = 0;
			} elseif ( ! in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
				throw new ProviderException( __( 'The linked post is no longer a draft (it has been published or scheduled), so it was not changed. Save as a new draft instead.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
			} elseif ( 'elementor' !== $format && self::editedSince( $post_id ) ) {
				// Elementor drafts have their own layout check in ElementorWriter.
				throw new ProviderException( __( 'The linked draft was edited in WordPress after the plugin saved it, so it was not replaced. Save as a new draft instead.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
			}
		}
		$keep_status = $post_id > 0 && 'pending' === get_post_status( $post_id ) ? 'pending' : 'draft';

		$images  = ImagePlan::forDraft( $this->projects->section( $project_id, 'images' ) );
		$figures = array_map( array( ImagePlan::class, 'figureHtml' ), $images['inline'] );
		$body    = ContentModel::bodyHtml( $content, true, $figures );
		$post    = array(
			'post_title'   => (string) ( '' !== $content['title'] ? $content['title'] : $content['h1'] ),
			'post_content' => 'blocks' === $format ? BlockSerializer::serialize( $body ) : $body,
			'post_status'  => $keep_status,
			'post_type'    => in_array( $post_type, array( 'post', 'page' ), true ) ? $post_type : 'post',
		);
		if ( '' !== (string) $content['meta']['slug'] ) {
			$post['post_name'] = (string) $content['meta']['slug'];
		}

		if ( $post_id > 0 ) {
			$post['ID'] = $post_id;
			unset( $post['post_type'] );
			$result = wp_update_post( wp_slash( $post ), true );
		} else {
			$post['post_author'] = get_current_user_id();
			$result              = wp_insert_post( wp_slash( $post ), true );
		}
		if ( is_wp_error( $result ) || ! $result ) {
			throw new ProviderException( __( 'WordPress could not save the draft.', 'ai-seo-content-studio' ), ProviderException::SERVER );
		}
		$saved_id = (int) $result;

		$meta = array(
			self::META['project']          => $project_id,
			self::META['seo_title']        => (string) $content['meta']['meta_title'],
			self::META['meta_description'] => (string) $content['meta']['meta_description'],
			self::META['schema_type']      => (string) $content['meta']['schema_type'],
			self::META['og_title']         => (string) $content['meta']['og_title'],
			self::META['og_description']   => (string) $content['meta']['og_description'],
			self::META['image_prompt']     => (string) $content['meta']['featured_image_prompt'],
			self::META['area_served']      => 'local_seo_page' === ( $inputs['content_type'] ?? '' ) ? (string) ( $inputs['market']['location_name'] ?? '' ) : '',
		);
		if ( $optimize ) {
			$meta[ self::META['source_post'] ] = (int) ( $inputs['source_post_id'] ?? 0 );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $saved_id, $key, wp_slash( $value ) );
		}
		$faqs = self::schemaFaqs( $content );
		if ( array() === $faqs ) {
			delete_post_meta( $saved_id, self::META['faqs'] );
		} else {
			update_post_meta( $saved_id, self::META['faqs'], wp_slash( $faqs ) );
		}

		$this->syncSeoPlugins(
			$saved_id,
			array(
				'meta_title'       => (string) $content['meta']['meta_title'],
				'meta_description' => (string) $content['meta']['meta_description'],
				'keyword'          => (string) ( $inputs['keyword'] ?? '' ),
			)
		);

		update_post_meta( $saved_id, self::META['content_hash'], md5( (string) get_post_field( 'post_content', $saved_id ) ) );
		$this->attachImages( $saved_id, $images );
		if ( $optimize && 0 === $post_id ) {
			$this->copyTerms( (int) ( $inputs['source_post_id'] ?? 0 ), $saved_id );
		}

		$this->projects->save(
			$project_id,
			'draft',
			array(
				'post_id'   => $saved_id,
				'post_type' => get_post_type( $saved_id ),
				'saved_at'  => time(),
				'format'    => $format,
			)
		);
		$this->versions->snapshot( $project_id, $content, __( 'Saved as WordPress draft', 'ai-seo-content-studio' ) );
		$this->projects->setStage( $project_id, 'drafted' );

		return array(
			'post_id'     => $saved_id,
			'images'      => count( $images['inline'] ) + ( $images['featured'] > 0 ? 1 : 0 ),
			'created'     => 0 === $post_id,
			'edit_url'    => (string) get_edit_post_link( $saved_id, 'raw' ),
			'preview_url' => (string) get_preview_post_link( $saved_id ),
		);
	}

	/**
	 * Copy the meta title, meta description and focus keyword into Yoast SEO and Rank Math, so
	 * the page has them when one of those plugins is active. A field is written only while it
	 * is empty or still holds what this plugin wrote last time: edits made in the SEO plugin win.
	 *
	 * @param int                   $post_id Draft.
	 * @param array<string, string> $values  meta_title, meta_description, keyword.
	 */
	private function syncSeoPlugins( int $post_id, array $values ): void {
		$synced = get_post_meta( $post_id, self::META['seo_synced'], true );
		$synced = is_array( $synced ) ? $synced : array();
		$wrote  = false;
		foreach ( self::SEO_PLUGIN_FIELDS as $constant => $fields ) {
			if ( ! defined( $constant ) ) {
				continue;
			}
			foreach ( $fields as $key => $source ) {
				$value   = trim( (string) ( $values[ $source ] ?? '' ) );
				$current = (string) get_post_meta( $post_id, $key, true );
				if ( '' === $value || ( '' !== $current && $current !== (string) ( $synced[ $key ] ?? '' ) ) ) {
					continue;
				}
				update_post_meta( $post_id, $key, wp_slash( $value ) );
				$synced[ $key ] = $value;
				$wrote          = true;
			}
		}
		if ( $wrote ) {
			update_post_meta( $post_id, self::META['seo_synced'], wp_slash( $synced ) );
		}
	}

	/**
	 * The draft's content changed since the plugin last saved it (no record = unknown, allowed).
	 *
	 * @param int $post_id Draft.
	 */
	public static function editedSince( int $post_id ): bool {
		$saved = (string) get_post_meta( $post_id, self::META['content_hash'], true );
		return '' !== $saved && md5( (string) get_post_field( 'post_content', $post_id ) ) !== $saved;
	}

	/**
	 * Set the featured image and attach unattached generated images to the draft.
	 *
	 * @param int                  $post_id Draft.
	 * @param array<string, mixed> $images  ImagePlan::forDraft() result.
	 */
	private function attachImages( int $post_id, array $images ): void {
		$ids = array_column( (array) $images['inline'], 'id' );
		if ( (int) $images['featured'] > 0 ) {
			set_post_thumbnail( $post_id, (int) $images['featured'] );
			$ids[] = (int) $images['featured'];
		}
		foreach ( $ids as $id ) {
			if ( MediaImporter::isOwn( (int) $id ) && 0 === (int) get_post_field( 'post_parent', (int) $id ) ) {
				wp_update_post(
					array(
						'ID'          => (int) $id,
						'post_parent' => $post_id,
					)
				);
			}
		}
	}

	/**
	 * Copy categories and tags from the source post (optimizer).
	 *
	 * @param int $from Source post.
	 * @param int $to   New draft.
	 */
	private function copyTerms( int $from, int $to ): void {
		if ( $from <= 0 || 'post' !== get_post_type( $from ) ) {
			return;
		}
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) && array() !== $terms ) {
				wp_set_object_terms( $to, array_map( 'intval', $terms ), $taxonomy );
			}
		}
	}
}
