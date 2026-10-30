<?php
/**
 * Validation of Elementor element trees before they are saved.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a complete Elementor document. Two modes:
 *
 * - MODE_GENERATED (mapper output): only the widgets ElementorCompat::FREE_WIDGETS, strict
 *   settings checks, no executable content anywhere.
 * - MODE_CLONED (a copy of one of the site's own pages): any widget registered on the site,
 *   atomic (Elementor 4) elements allowed, settings kept as the author made them; still checks
 *   structure, IDs, links and media and flags widgets the site cannot render.
 *
 * - MODE_CONVERTED (HTML conversion): as generated, plus the HTML widget for the fallback
 *   elements the user chose to keep; its content must still carry no executable markup.
 *
 * Errors block saving; warnings are reported to the user.
 */
final class DocumentValidator {

	public const MODE_GENERATED = 'generated';
	public const MODE_CLONED    = 'cloned';
	public const MODE_CONVERTED = 'converted';

	public const MAX_ELEMENTS = 3000;
	public const MAX_DEPTH    = 30;
	public const MAX_BYTES    = 4194304;

	private const UNITS = array( 'px', 'em', 'rem', '%', 'vw', 'vh', 'custom', 'deg', 's', 'ms', 'fr', '' );

	/** Setting keys whose value is a slider ({unit, size}). Responsive suffixes are stripped first. */
	private const SLIDER_KEYS = array( 'width', 'boxed_width', 'min_height', 'custom_height', 'space', 'typography_font_size', 'typography_line_height', 'title_typography_font_size', 'content_typography_font_size' );

	/** Setting keys whose value is a dimensions control ({unit, top, right, bottom, left}). */
	private const DIMENSION_KEYS = array( 'padding', 'margin', 'border_radius', 'border_width', 'text_padding', 'image_border_radius' );

	/**
	 * Attachment check: int ID → bool.
	 *
	 * @var callable(int): bool
	 */
	private $attachmentExists;

	/**
	 * Constructor.
	 *
	 * @param ElementorCompat $compat            Installed Elementor.
	 * @param callable|null   $attachment_exists Returns whether an attachment ID is an image in the Media Library.
	 */
	public function __construct( private readonly ElementorCompat $compat, ?callable $attachment_exists = null ) {
		$this->attachmentExists = $attachment_exists ?? static fn ( int $id ): bool => function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $id );
	}

	/**
	 * Validate.
	 *
	 * @param array<int, mixed> $elements Top-level elements.
	 * @param string            $mode     MODE_GENERATED | MODE_CLONED.
	 * @return array{errors: string[], warnings: string[], stats: array{elements: int, widgets: int, depth: int}}
	 */
	public function validate( array $elements, string $mode = self::MODE_GENERATED ): array {
		$state = array(
			'errors'   => array(),
			'warnings' => array(),
			'ids'      => array(),
			'count'    => 0,
			'widgets'  => 0,
			'depth'    => 0,
			'mode'     => $mode,
		);
		if ( array() === $elements ) {
			$state['errors'][] = 'The page has no content.';
		}
		if ( ! array_is_list( $elements ) ) {
			$state['errors'][] = 'Top-level elements must be a list.';
		}
		$json = wp_json_encode( $elements );
		if ( false === $json ) {
			$state['errors'][] = 'The page data cannot be encoded.';
		} elseif ( strlen( $json ) > self::MAX_BYTES ) {
			$state['errors'][] = 'The page data is too large.';
		}
		foreach ( array_values( $elements ) as $i => $element ) {
			$this->node( $element, 'root', "[$i]", 1, $state );
			if ( count( $state['errors'] ) > 50 ) {
				$state['errors'][] = 'Too many errors; validation stopped.';
				break;
			}
		}
		if ( $state['count'] > self::MAX_ELEMENTS ) {
			$state['errors'][] = 'The page has too many elements.';
		}
		return array(
			'errors'   => array_values( array_unique( $state['errors'] ) ),
			'warnings' => array_values( array_unique( $state['warnings'] ) ),
			'stats'    => array(
				'elements' => $state['count'],
				'widgets'  => $state['widgets'],
				'depth'    => $state['depth'],
			),
		);
	}

	/**
	 * Validate one element.
	 *
	 * @param mixed                $node   Element.
	 * @param string               $parent Parent type ("root", "container", "section", "column", "atomic").
	 * @param string               $path   Path.
	 * @param int                  $depth  Depth.
	 * @param array<string, mixed> $state  State.
	 */
	private function node( mixed $node, string $parent, string $path, int $depth, array &$state ): void {
		++$state['count'];
		$state['depth'] = max( $state['depth'], $depth );
		if ( $depth > self::MAX_DEPTH ) {
			$state['errors'][] = "$path is nested too deeply.";
			return;
		}
		if ( ! is_array( $node ) ) {
			$state['errors'][] = "$path is not an element.";
			return;
		}
		$id = $node['id'] ?? null;
		if ( ! is_string( $id ) || ! IdGenerator::isValid( $id ) ) {
			$state['errors'][] = "$path has an invalid id.";
		} elseif ( isset( $state['ids'][ $id ] ) ) {
			$state['errors'][] = "$path reuses the id $id.";
		} else {
			$state['ids'][ $id ] = true;
		}
		$settings = $node['settings'] ?? null;
		if ( ! is_array( $settings ) ) {
			$state['errors'][] = "$path has no settings.";
			$settings = array();
		}
		$children = $node['elements'] ?? null;
		if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
			$state['errors'][] = "$path has no element list.";
			return;
		}

		$type   = is_string( $node['elType'] ?? null ) ? $node['elType'] : '';
		$atomic = str_starts_with( $type, 'e-' );
		$cloned    = self::MODE_CLONED === $state['mode'];
		$converted = self::MODE_CONVERTED === $state['mode'];

		if ( $atomic ) {
			if ( ! $cloned ) {
				$state['errors'][] = "$path: atomic elements are not generated.";
				return;
			}
			$this->settings( $settings, $path, $state, true );
			foreach ( $children as $i => $child ) {
				$this->node( $child, 'atomic', "$path.elements[$i]", $depth + 1, $state );
			}
			return;
		}

		$allowed_parents = array(
			'container' => array( 'root', 'container', 'atomic' ),
			'section'   => array( 'root', 'column' ), // A section inside a column is an inner section.
			'column'    => array( 'section' ),
			'widget'    => array( 'container', 'column', 'atomic' ),
		);
		if ( ! isset( $allowed_parents[ $type ] ) ) {
			$state['errors'][] = "$path has an unsupported element type.";
			return;
		}
		if ( ! in_array( $parent, $allowed_parents[ $type ], true ) ) {
			$state['errors'][] = "$path: a $type cannot be placed inside a $parent.";
		}
		if ( 'section' === $type && 'column' === $parent && empty( $node['isInner'] ) ) {
			$state['errors'][] = "$path: a section inside a column must be an inner section.";
		}
		if ( 'container' === $type && ! $this->compat->containers ) {
			$state[ $cloned ? 'warnings' : 'errors' ][] = "$path: flexbox containers are turned off on this site.";
		}

		if ( 'widget' === $type ) {
			++$state['widgets'];
			$widget = $node['widgetType'] ?? null;
			if ( ! is_string( $widget ) || ! preg_match( '/^[a-z0-9][a-z0-9_.-]{0,63}$/', $widget ) ) {
				$state['errors'][] = "$path has an invalid widget type.";
			} elseif ( ! $cloned && ( ! $this->compat->has( $widget ) || ( ! $converted && in_array( $widget, ElementorCompat::FALLBACK_WIDGETS, true ) ) ) ) {
				$state['errors'][] = "$path uses the \"$widget\" widget, which cannot be generated on this site.";
			} elseif ( $cloned && array() !== $this->compat->widgets && ! in_array( $widget, $this->compat->widgets, true ) ) {
				$state['warnings'][] = "The \"$widget\" widget is not available on this site (for example an Elementor Pro widget while Pro is inactive); it will not display until it is.";
			}
			if ( array() !== $children ) {
				$state['errors'][] = "$path: widgets cannot contain elements.";
			}
			$this->settings( $settings, $path, $state, $cloned );
			if ( ! $cloned && is_string( $widget ) ) {
				$this->widgetContent( $widget, $settings, $path, $state );
			}
			return;
		}

		$this->settings( $settings, $path, $state, $cloned );
		if ( 'column' === $type && isset( $settings['_column_size'] ) && ( ! is_numeric( $settings['_column_size'] ) || $settings['_column_size'] < 1 || $settings['_column_size'] > 100 ) ) {
			$state['errors'][] = "$path has an invalid column size.";
		}
		if ( 'section' === $type && array() === $children ) {
			$state['warnings'][] = "$path is an empty section.";
		}
		foreach ( $children as $i => $child ) {
			$this->node( $child, $type, "$path.elements[$i]", $depth + 1, $state );
		}
	}

	/**
	 * Check settings: control value shapes, links, media and executable content.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $path     Path.
	 * @param array<string, mixed> $state    State.
	 * @param bool                 $lenient  Cloned content (author's own settings).
	 */
	private function settings( array $settings, string $path, array &$state, bool $lenient ): void {
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_$-]{1,100}$/', $key ) ) {
				$state['errors'][] = "$path has an invalid setting name.";
				continue;
			}
			$base = (string) preg_replace( '/_(tablet|mobile|tablet_extra|mobile_extra|laptop|widescreen)$/', '', $key );
			if ( ! $lenient ) {
				if ( in_array( $base, self::SLIDER_KEYS, true ) && ! self::isSlider( $value ) ) {
					$state['errors'][] = "$path.$key is not a valid size.";
				}
				if ( in_array( $base, self::DIMENSION_KEYS, true ) && ! self::isDimensions( $value ) ) {
					$state['errors'][] = "$path.$key is not a valid spacing value.";
				}
			}
		}
		$this->walkValues( $settings, $path, $state, $lenient );
	}

	/**
	 * Recursively check links, media and strings.
	 *
	 * @param mixed                $value   Value.
	 * @param string               $path    Path.
	 * @param array<string, mixed> $state   State.
	 * @param bool                 $lenient Cloned content.
	 */
	private function walkValues( mixed $value, string $path, array &$state, bool $lenient ): void {
		if ( ! is_array( $value ) ) {
			if ( is_string( $value ) && ! $lenient && self::executable( $value ) ) {
				$state['errors'][] = "$path contains script or event-handler content.";
			}
			return;
		}
		// Link and media controls: {url, ...}.
		if ( array_key_exists( 'url', $value ) && is_string( $value['url'] ) && '' !== $value['url'] ) {
			$is_media = array_key_exists( 'id', $value ) || array_key_exists( 'source', $value );
			$url      = $value['url'];
			$safe     = $is_media ? (bool) preg_match( '#^https?://#i', $url ) || ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) : '' !== HtmlFragment::url( $url );
			if ( ! $safe ) {
				$state['errors'][] = "$path has a link or image address that is not allowed.";
			}
			if ( $is_media && is_numeric( $value['id'] ?? null ) && (int) $value['id'] > 0 && ! ( $this->attachmentExists )( (int) $value['id'] ) ) {
				$state[ $lenient ? 'warnings' : 'errors' ][] = "$path refers to image #{$value['id']}, which is not in the Media Library.";
			}
		}
		foreach ( $value as $k => $v ) {
			$this->walkValues( $v, $path, $state, $lenient );
		}
	}

	/**
	 * Generated widgets must carry the content their type needs.
	 *
	 * @param string               $widget   Widget type.
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $path     Path.
	 * @param array<string, mixed> $state    State.
	 */
	private function widgetContent( string $widget, array $settings, string $path, array &$state ): void {
		$required = array(
			'heading'     => 'title',
			'text-editor' => 'editor',
			'button'      => 'text',
			'image'       => 'image',
			'icon-list'   => 'icon_list',
			'accordion'   => 'tabs',
			'html'        => 'html',
		);
		$key = $required[ $widget ] ?? null;
		if ( null !== $key && ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] || array() === $settings[ $key ] ) ) {
			$state['errors'][] = "$path ($widget) is missing its $key.";
		}
		if ( 'heading' === $widget && isset( $settings['header_size'] ) && ! in_array( $settings['header_size'], array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), true ) ) {
			$state['errors'][] = "$path has an invalid heading level.";
		}
		if ( 'video' === $widget && '' === PageDocument::videoProvider( (string) ( $settings['youtube_url'] ?? $settings['vimeo_url'] ?? '' ) ) ) {
			$state['errors'][] = "$path (video) needs a YouTube or Vimeo address.";
		}
		foreach ( array( 'icon_list', 'tabs' ) as $repeater ) {
			foreach ( (array) ( $settings[ $repeater ] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || ! is_string( $item['_id'] ?? null ) || ! IdGenerator::isValid( $item['_id'] ) ) {
					$state['errors'][] = "$path has a list item without a valid id.";
				}
			}
		}
	}

	/**
	 * Slider value check.
	 *
	 * @param mixed $v Value.
	 */
	private static function isSlider( mixed $v ): bool {
		if ( ! is_array( $v ) || ! in_array( $v['unit'] ?? '', self::UNITS, true ) ) {
			return false;
		}
		$size = $v['size'] ?? '';
		if ( 'custom' === $v['unit'] ) {
			// Only the calc() forms the mapper writes.
			return is_string( $size ) && (bool) preg_match( '/^calc\(\(100% - \d{1,4}px\) \/ [1-6]\)$/', $size );
		}
		return '' === $size || is_int( $size ) || is_float( $size ) || ( is_string( $size ) && is_numeric( $size ) );
	}

	/**
	 * Dimensions value check.
	 *
	 * @param mixed $v Value.
	 */
	private static function isDimensions( mixed $v ): bool {
		if ( ! is_array( $v ) || ! in_array( $v['unit'] ?? 'px', self::UNITS, true ) ) {
			return false;
		}
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$s = $v[ $side ] ?? '';
			if ( '' !== $s && ! is_numeric( $s ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a string carries executable markup or a script URL.
	 *
	 * @param string $s String.
	 */
	public static function executable( string $s ): bool {
		$decoded = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return (bool) preg_match( '/<\s*(script|iframe|object|embed|svg|math|style|link|meta)\b|\son[a-z]+\s*=|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html/i', $decoded );
	}
}
