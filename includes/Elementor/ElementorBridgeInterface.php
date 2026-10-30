<?php
/**
 * The few Elementor operations the plugin needs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow boundary to Elementor so the rest of the plugin never calls Elementor classes directly.
 */
interface ElementorBridgeInterface {

	/**
	 * Elementor is loaded.
	 */
	public function isActive(): bool;

	/**
	 * The flexbox container feature is active (otherwise sections/columns are used).
	 */
	public function containersActive(): bool;

	/**
	 * The post type can be edited with Elementor on this site.
	 *
	 * @param string $post_type Post type.
	 */
	public function supportsPostType( string $post_type ): bool;

	/**
	 * Elementor's "Hide Title" can hide the theme's title. It works through a CSS selector
	 * (default "h1.entry-title"), which block themes do not use.
	 */
	public function canHideThemeTitle(): bool;

	/**
	 * Registered widget type names.
	 *
	 * @return string[]
	 */
	public function widgetTypes(): array;

	/**
	 * Save elements and document settings, marking the post as built with Elementor.
	 *
	 * @param int                              $post_id  Post.
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param array<string, mixed>             $settings Document settings.
	 */
	public function save( int $post_id, array $elements, array $settings ): bool;

	/**
	 * Saved elements of a post (empty when none).
	 *
	 * @param int $post_id Post.
	 * @return array<int, array<string, mixed>>
	 */
	public function elements( int $post_id ): array;

	/**
	 * URL that opens the post in the Elementor editor.
	 *
	 * @param int $post_id Post.
	 */
	public function editUrl( int $post_id ): string;
}
