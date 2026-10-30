<?php
/**
 * What the installed Elementor can render.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

use AiSeoContentStudio\Elementor\ElementorBridgeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot of the site's Elementor: version, Pro, flexbox containers and the registered
 * widget types. The mapper asks it which widget to emit and falls back when a widget is
 * missing, so a page is never generated with widgets the site cannot render.
 *
 * Only Elementor Free widgets are ever emitted (heading, text-editor, button, image, icon,
 * icon-list, accordion, spacer, divider, video, and the HTML widget as an opt-in conversion
 * fallback). Pro widgets are never generated.
 */
final class ElementorCompat {

	/** Widgets the mapper can emit, all part of Elementor Free. */
	public const FREE_WIDGETS = array( 'heading', 'text-editor', 'button', 'image', 'icon', 'icon-list', 'accordion', 'spacer', 'divider', 'video', 'html' );

	/** Emitted only for HTML conversion with the fallback switched on. */
	public const FALLBACK_WIDGETS = array( 'html' );

	/** Oldest Elementor this engine targets (flexbox containers and the current control names). */
	public const MIN_VERSION = '3.16.0';

	/**
	 * Constructor.
	 *
	 * @param bool     $active     Elementor loaded.
	 * @param string   $version    Elementor version.
	 * @param bool     $pro        Elementor Pro active.
	 * @param bool     $containers Flexbox containers active.
	 * @param string[] $widgets    Registered widget types ([] = unknown, assume Free set).
	 */
	public function __construct(
		public readonly bool $active,
		public readonly string $version,
		public readonly bool $pro,
		public readonly bool $containers,
		public readonly array $widgets = array(),
	) {}

	/**
	 * From the live site.
	 *
	 * @param ElementorBridgeInterface $bridge Bridge.
	 */
	public static function fromBridge( ElementorBridgeInterface $bridge ): self {
		return new self(
			$bridge->isActive(),
			defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '',
			defined( 'ELEMENTOR_PRO_VERSION' ),
			$bridge->containersActive(),
			$bridge->widgetTypes()
		);
	}

	/**
	 * Whether a widget can be emitted.
	 *
	 * @param string $widget Widget type.
	 */
	public function has( string $widget ): bool {
		if ( ! in_array( $widget, self::FREE_WIDGETS, true ) ) {
			return false;
		}
		return array() === $this->widgets || in_array( $widget, $this->widgets, true );
	}

	/**
	 * Why generation cannot run, or "" when it can.
	 */
	public function blocker(): string {
		if ( ! $this->active ) {
			return 'elementor_inactive';
		}
		if ( '' !== $this->version && version_compare( $this->version, self::MIN_VERSION, '<' ) ) {
			return 'elementor_too_old';
		}
		foreach ( array( 'heading', 'text-editor', 'button', 'image' ) as $required ) {
			if ( ! $this->has( $required ) ) {
				return 'widgets_missing';
			}
		}
		return '';
	}

	/**
	 * Public summary.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'active'     => $this->active,
			'version'    => $this->version,
			'pro'        => $this->pro,
			'containers' => $this->containers,
			'blocker'    => $this->blocker(),
		);
	}
}
