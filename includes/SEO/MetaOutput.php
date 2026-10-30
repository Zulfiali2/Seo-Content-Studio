<?php
/**
 * Front-end output of plugin metadata.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\SEO;

use AiSeoContentStudio\Core\Environment;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the SEO title, meta description and JSON-LD for published posts the
 * plugin created — only when no known SEO plugin is active (to avoid duplicate
 * tags) and only when the setting allows it. FAQPage markup is the exception:
 * it follows its own setting only. Canonical URLs are left to
 * WordPress core, which already prints them.
 */
class MetaOutput {

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings    Settings.
	 * @param Environment        $environment Environment.
	 */
	public function __construct( private readonly SettingsRepository $settings, private readonly Environment $environment ) {}

	/**
	 * Register front-end hooks.
	 */
	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
		add_action( 'wp_head', array( $this, 'head' ), 2 );
	}

	/**
	 * Whether this plugin should output a given field for the current request.
	 *
	 * @param string $field "meta", "schema" or "faq_schema".
	 */
	public function enabled( string $field ): bool {
		if ( 'faq_schema' === $field ) {
			// SEO plugins only output FAQPage for their own FAQ blocks, which these drafts
			// do not use, so FAQ markup is not duplicated when one is active.
			return (bool) $this->settings->get( 'seo.output_faq_schema', true );
		}
		if ( array() !== $this->environment->seoPlugins() ) {
			return false;
		}
		return (bool) $this->settings->get( 'seo.output_' . $field, true );
	}

	/**
	 * Document title filter.
	 *
	 * @param string $title Current title.
	 */
	public function title( $title ) {
		if ( ! is_singular() || ! $this->enabled( 'meta' ) ) {
			return $title;
		}
		$custom = (string) get_post_meta( (int) get_queried_object_id(), '_aiscs_seo_title', true );
		return '' !== $custom ? $custom : $title;
	}

	/**
	 * Head output.
	 */
	public function head(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( $this->enabled( 'meta' ) ) {
			$description = (string) get_post_meta( $post->ID, '_aiscs_meta_description', true );
			if ( '' !== $description ) {
				printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
			}
		}
		if ( $this->enabled( 'schema' ) ) {
			$data = SchemaBuilder::build( $post, (string) get_post_meta( $post->ID, '_aiscs_schema_type', true ) );
			if ( null !== $data ) {
				echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
			}
		}
		if ( $this->enabled( 'faq_schema' ) ) {
			$faq = SchemaBuilder::faqPage( $post );
			if ( null !== $faq ) {
				echo '<script type="application/ld+json">' . wp_json_encode( $faq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
			}
		}
	}
}
