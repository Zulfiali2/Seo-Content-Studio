<?php
/**
 * Elementor bridge backed by Elementor's public document API.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Calls Elementor only through `Plugin::$instance` documents, experiments and widgets
 * managers. Saving goes through `Document::save()`, so Elementor runs its own element
 * normalisation, permission check, CSS regeneration and plain-text copy.
 */
class ElementorBridge implements ElementorBridgeInterface {

	/**
	 * Elementor instance, or null.
	 *
	 * @return object|null
	 */
	private function elementor(): ?object {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}
		$instance = \Elementor\Plugin::$instance;
		return is_object( $instance ) ? $instance : null;
	}

	/**
	 * Loaded.
	 */
	public function isActive(): bool {
		return null !== $this->elementor();
	}

	/**
	 * Container feature.
	 */
	public function containersActive(): bool {
		$e = $this->elementor();
		return $e && isset( $e->experiments ) && $e->experiments->is_feature_active( 'container' );
	}

	/**
	 * Post type support (Elementor adds 'elementor' support to enabled post types).
	 *
	 * @param string $post_type Post type.
	 */
	public function supportsPostType( string $post_type ): bool {
		return $this->isActive() && post_type_supports( $post_type, 'elementor' );
	}

	/**
	 * Hide Title only matches classic themes' "h1.entry-title" by default.
	 */
	public function canHideThemeTitle(): bool {
		return ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );
	}

	/**
	 * Registered widgets.
	 *
	 * @return string[]
	 */
	public function widgetTypes(): array {
		$e = $this->elementor();
		if ( ! $e || ! isset( $e->widgets_manager ) ) {
			return array();
		}
		return array_map( 'strval', array_keys( (array) $e->widgets_manager->get_widget_types() ) );
	}

	/**
	 * Save through the document API.
	 *
	 * @param int                              $post_id  Post.
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param array<string, mixed>             $settings Document settings.
	 */
	public function save( int $post_id, array $elements, array $settings ): bool {
		$e = $this->elementor();
		if ( ! $e ) {
			return false;
		}
		$document = $e->documents->get( $post_id, false );
		if ( ! $document ) {
			return false;
		}
		$document->set_is_built_with_elementor( true );
		$data = array( 'elements' => $elements );
		if ( array() !== $settings ) {
			$data['settings'] = $settings;
		}
		return (bool) $document->save( $data );
	}

	/**
	 * Saved elements.
	 *
	 * @param int $post_id Post.
	 * @return array<int, array<string, mixed>>
	 */
	public function elements( int $post_id ): array {
		$e = $this->elementor();
		if ( ! $e ) {
			return array();
		}
		$document = $e->documents->get( $post_id, false );
		return $document ? (array) $document->get_elements_data() : array();
	}

	/**
	 * Editor URL.
	 *
	 * @param int $post_id Post.
	 */
	public function editUrl( int $post_id ): string {
		$e = $this->elementor();
		if ( ! $e ) {
			return '';
		}
		$document = $e->documents->get( $post_id, false );
		return $document ? (string) $document->get_edit_url() : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isBuiltWithElementor( int $post_id ): bool {
		$e = $this->elementor();
		if ( ! $e ) {
			return false;
		}
		$document = $e->documents->get( $post_id, false );
		return $document && $document->is_built_with_elementor();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reads the kit's saved settings rather than Kit::get_settings(), which fills in control
	 * defaults: the analyzers should only see choices the site actually made.
	 */
	public function activeKit(): array {
		$e = $this->elementor();
		if ( ! $e || ! isset( $e->kits_manager ) ) {
			return array(
				'id'       => 0,
				'modified' => '',
				'settings' => array(),
			);
		}
		$id       = (int) $e->kits_manager->get_active_id();
		$settings = $id > 0 ? get_post_meta( $id, '_elementor_page_settings', true ) : array();
		return array(
			'id'       => $id,
			'modified' => $id > 0 ? (string) get_post_field( 'post_modified_gmt', $id ) : '',
			'settings' => is_array( $settings ) ? $settings : array(),
		);
	}
}
