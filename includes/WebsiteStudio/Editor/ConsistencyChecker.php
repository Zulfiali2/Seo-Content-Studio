<?php
/**
 * Design consistency report of a page against brand tokens.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Editor;

use AiSeoContentStudio\WebsiteStudio\Design\BrandExtractor;
use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a page's Elementor data with brand tokens (a brand profile, tokens derived from
 * reference pages, or the Elementor kit) and reports per category: colours, typography,
 * buttons, cards, section spacing, container widths, image treatment, heading hierarchy,
 * visual patterns and responsive layout.
 *
 * Each finding may carry a fix: explicit setting changes on named elements. Fixes are
 * computed here, on the server, from the current page; the client only chooses which fix ids
 * to apply, so it can never send arbitrary settings. A fix touches only the elements and
 * settings it names; nothing unrelated changes.
 */
final class ConsistencyChecker {

	public const CATEGORIES = array( 'colors', 'typography', 'buttons', 'cards', 'spacing', 'widths', 'images', 'headings', 'patterns', 'responsive' );

	/** Colours closer than this to a brand colour are treated as a near miss. */
	private const NEAR = 60.0;

	/** Allowed relative difference for spacing and widths. */
	private const TOLERANCE = 0.3;

	/**
	 * Findings, built while checking.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $findings = array();

	/**
	 * Checked categories (category => true when the reference had a value to compare).
	 *
	 * @var array<string, bool>
	 */
	private array $checked = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $tokens Brand tokens (normalised here).
	 * @param array<string, string> $kit    Kit colour id => hex.
	 * @param array<string, mixed>  $kit_settings Kit settings (fonts).
	 */
	public function __construct( private array $tokens, private readonly array $kit = array(), private readonly array $kit_settings = array() ) {
		$this->tokens = DesignTokens::normalize( $tokens );
	}

	/**
	 * Run every check.
	 *
	 * @param array<int, mixed> $elements Page elements.
	 * @return array{categories: array<string, array{status: string, issues: int}>, findings: list<array<string, mixed>>, score: int}
	 */
	public function check( array $elements ): array {
		$this->findings = array();
		$this->checked  = array_fill_keys( self::CATEGORIES, true );
		$flat           = ElementTree::flatten( $elements );
		$this->colors( $flat );
		$this->typography( $flat );
		$this->buttons( $flat );
		$this->cards( $flat );
		$this->spacing( $elements );
		$this->widths( $elements );
		$this->images( $flat );
		$this->headings( $elements );
		$this->patterns( $flat );
		$this->responsive( $elements, $flat );
		$this->findings = Findings::unique( $this->findings );

		$categories = array();
		foreach ( self::CATEGORIES as $category ) {
			$mine   = array_filter( $this->findings, static fn ( array $f ): bool => $f['category'] === $category );
			$status = ! $this->checked[ $category ] ? 'not_checked' : 'pass';
			foreach ( $mine as $f ) {
				$status = 'error' === $f['severity'] ? 'fail' : ( 'fail' === $status ? 'fail' : 'warning' );
			}
			$categories[ $category ] = array(
				'status' => $status,
				'issues' => count( $mine ),
			);
		}
		$scored = array_filter( $categories, static fn ( array $c ): bool => 'not_checked' !== $c['status'] );
		$points = array_sum( array_map( static fn ( array $c ): int => 'pass' === $c['status'] ? 2 : ( 'warning' === $c['status'] ? 1 : 0 ), $scored ) );
		return array(
			'categories' => $categories,
			'findings'   => $this->findings,
			'score'      => array() === $scored ? 0 : (int) round( 100 * $points / ( 2 * count( $scored ) ) ),
		);
	}

	/* ---------------------------------------------------------------- Checks */

	/**
	 * Colours not in the brand palette (neutrals and kit globals are fine).
	 *
	 * @param list<array<string, mixed>> $flat Flattened elements.
	 */
	private function colors( array $flat ): void {
		// Kit colours first: a fix then links to the global colour instead of copying its value.
		$brand = array();
		foreach ( $this->kit as $id => $hex ) {
			$brand[ $hex ] = 'kit:' . $id;
		}
		foreach ( $this->tokens['colors'] as $role => $hex ) {
			if ( '' !== $hex ) {
				$brand[ substr( $hex, 0, 7 ) ] ??= $role;
			}
		}
		foreach ( $this->tokens['palette'] as $item ) {
			$hex = is_array( $item ) ? (string) ( $item['hex'] ?? $item['color'] ?? '' ) : (string) $item;
			$hex = (string) DesignTokens::color( $hex );
			if ( 7 <= strlen( $hex ) ) {
				$brand[ substr( $hex, 0, 7 ) ] ??= 'palette';
			}
		}
		if ( array() === $brand ) {
			$this->checked['colors'] = false;
			return;
		}
		$off = array();
		foreach ( $flat as $row ) {
			$s = (array) ( $row['element']['settings'] ?? array() );
			foreach ( array_keys( $s ) as $key ) {
				if ( ! is_string( $key ) || ! preg_match( '/(^|_)color(_hover)?$/', $key ) ) {
					continue;
				}
				$c = StyleValues::color( $s, $key, $this->kit );
				if ( null === $c || '' !== $c['global'] || '' === $c['hex'] || isset( $brand[ $c['hex'] ] ) || BrandExtractor::isNeutral( $c['hex'] ) ) {
					continue;
				}
				$off[ $c['hex'] ][] = array( (string) $row['element']['id'], $key );
			}
		}
		foreach ( $off as $hex => $uses ) {
			[ $nearest, $role, $distance ] = $this->nearest( (string) $hex, $brand );
			$ops = array();
			foreach ( $uses as [ $id, $key ] ) {
				$ops[] = self::op( $id, str_starts_with( $role, 'kit:' ) ? array( $key => null, '__globals__.' . $key => 'globals/colors?id=' . substr( $role, 4 ) ) : array( $key => $nearest ) );
			}
			$this->add(
				'colors',
				'warning',
				$distance <= self::NEAR
					/* translators: 1: colour, 2: brand colour. */
					? sprintf( __( '%1$s is close to the brand colour %2$s but not the same.', 'ai-seo-content-studio' ), $hex, $nearest )
					/* translators: 1: colour, 2: number of uses. */
					: sprintf( __( '%1$s is not a brand colour (used %2$d times).', 'ai-seo-content-studio' ), $hex, count( $uses ) ),
				array_values( array_unique( array_column( $uses, 0 ) ) ),
				/* translators: 1: colour, 2: brand colour. */
				sprintf( __( 'Replace %1$s with %2$s', 'ai-seo-content-studio' ), $hex, $nearest ),
				$ops
			);
		}
	}

	/**
	 * Font families outside the brand fonts.
	 *
	 * @param list<array<string, mixed>> $flat Flattened elements.
	 */
	private function typography( array $flat ): void {
		$heading = self::family( (string) $this->tokens['typography']['heading']['family'] );
		$body    = self::family( (string) $this->tokens['typography']['body']['family'] );
		$allowed = array_filter( array( $heading, $body, self::family( (string) $this->tokens['typography']['button']['family'] ) ) );
		foreach ( (array) ( $this->kit_settings['system_typography'] ?? array() ) as $item ) {
			if ( is_array( $item ) && is_string( $item['typography_font_family'] ?? null ) ) {
				$allowed[] = self::family( $item['typography_font_family'] );
			}
		}
		$allowed = array_values( array_unique( array_filter( $allowed ) ) );
		if ( array() === $allowed ) {
			$this->checked['typography'] = false;
			return;
		}
		$off = array();
		foreach ( $flat as $row ) {
			$s = (array) ( $row['element']['settings'] ?? array() );
			foreach ( $s as $key => $value ) {
				if ( is_string( $key ) && str_ends_with( $key, '_font_family' ) && is_string( $value ) && '' !== $value && empty( $s['__globals__'][ substr( $key, 0, -12 ) . '_typography' ] ) && ! in_array( self::family( $value ), $allowed, true ) ) {
					$off[ $value ][] = array( (string) $row['element']['id'], $key, 'heading' === ( $row['element']['widgetType'] ?? '' ) );
				}
			}
		}
		foreach ( $off as $font => $uses ) {
			$ops = array();
			foreach ( $uses as [ $id, $key, $is_heading ] ) {
				$target = $is_heading ? ( $heading ?: $body ) : ( $body ?: $heading );
				if ( '' !== $target ) {
					$ops[] = self::op( $id, array( $key => self::display( $is_heading ? $this->tokens['typography']['heading']['family'] : $this->tokens['typography']['body']['family'], $target ) ) );
				}
			}
			$this->add(
				'typography',
				'warning',
				/* translators: %s: font family. */
				sprintf( __( 'The font "%s" is not one of the brand fonts.', 'ai-seo-content-studio' ), $font ),
				array_values( array_unique( array_column( $uses, 0 ) ) ),
				__( 'Use the brand heading and body fonts', 'ai-seo-content-studio' ),
				$ops
			);
		}
	}

	/**
	 * Button corner radius.
	 *
	 * @param list<array<string, mixed>> $flat Flattened elements.
	 */
	private function buttons( array $flat ): void {
		$this->radius( $flat, 'buttons', static fn ( array $el ): bool => 'button' === ( $el['widgetType'] ?? '' ), 'border_radius', (string) $this->tokens['components']['button']['radius'], __( 'Button corners differ from the brand button style.', 'ai-seo-content-studio' ) );
	}

	/**
	 * Card corner radius.
	 *
	 * @param list<array<string, mixed>> $flat Flattened elements.
	 */
	private function cards( array $flat ): void {
		$this->radius( $flat, 'cards', array( self::class, 'isCard' ), 'border_radius', (string) $this->tokens['components']['card']['radius'], __( 'Card corners differ from the brand card style.', 'ai-seo-content-studio' ) );
	}

	/**
	 * Image corner radius.
	 *
	 * @param list<array<string, mixed>> $flat Flattened elements.
	 */
	private function images( array $flat ): void {
		$this->radius( $flat, 'images', static fn ( array $el ): bool => 'image' === ( $el['widgetType'] ?? '' ), 'image_border_radius', (string) $this->tokens['components']['image']['radius'], __( 'Image corners differ from the brand image treatment.', 'ai-seo-content-studio' ) );
	}

	/**
	 * Shared radius comparison.
	 *
	 * @param list<array<string, mixed>> $flat     Flattened elements.
	 * @param string                     $category Category.
	 * @param callable                   $match    fn( element ): bool.
	 * @param string                     $key      Setting key.
	 * @param string                     $token    Token radius ("" = not in the profile).
	 * @param string                     $message  Message.
	 */
	private function radius( array $flat, string $category, callable $match, string $key, string $token, string $message ): void {
		$want = '' === $token ? null : StyleValues::boxTop( $token );
		if ( null === $want ) {
			$this->checked[ $category ] = false;
			return;
		}
		$ids = array();
		foreach ( $flat as $row ) {
			$el = $row['element'];
			if ( ! $match( $el ) ) {
				continue;
			}
			$have = StyleValues::px( $el['settings'][ $key ] ?? null ) ?? 0.0;
			if ( abs( $have - $want ) > 2 ) {
				$ids[] = (string) $el['id'];
			}
		}
		if ( array() === $ids ) {
			return;
		}
		$this->add(
			$category,
			'warning',
			$message,
			$ids,
			/* translators: %s: radius. */
			sprintf( __( 'Set the corner radius to %s', 'ai-seo-content-studio' ), $token ),
			array_map( static fn ( string $id ): array => self::op( $id, array( $key => ElementorMapper::dims( $token ) ) ), $ids )
		);
	}

	/**
	 * Vertical section padding.
	 *
	 * @param array<int, mixed> $elements Page elements.
	 */
	private function spacing( array $elements ): void {
		$boxes = (array) $this->tokens['spacing']['section_padding'];
		$want  = StyleValues::boxTop( (string) $boxes['desktop'] );
		$have  = array();
		foreach ( $elements as $el ) {
			$v = is_array( $el ) ? StyleValues::vertical( $el['settings']['padding'] ?? null ) : null;
			if ( null !== $v ) {
				$have[ (string) $el['id'] ] = max( $v );
			}
		}
		if ( null === $want ) {
			// No reference: sections should at least agree with each other.
			if ( count( $have ) >= 2 && min( $have ) > 0 && max( $have ) / min( $have ) > 2.5 ) {
				$this->add( 'spacing', 'warning', __( 'Section spacing varies a lot between sections.', 'ai-seo-content-studio' ), array_keys( $have ), '', array() );
			}
			return;
		}
		$ids = array_keys( array_filter( $have, static fn ( float $px ): bool => $want > 0 && abs( $px - $want ) / $want > self::TOLERANCE ) );
		if ( array() === $ids ) {
			return;
		}
		$tablet = '' !== $boxes['tablet'] ? $boxes['tablet'] : ElementorMapper::scaleBox( $boxes['desktop'], 0.75 );
		$mobile = '' !== $boxes['mobile'] ? $boxes['mobile'] : ElementorMapper::scaleBox( $boxes['desktop'], 0.5 );
		$set    = array( 'padding' => ElementorMapper::dims( $boxes['desktop'] ) );
		if ( '' !== $tablet ) {
			$set['padding_tablet'] = ElementorMapper::dims( $tablet );
		}
		if ( '' !== $mobile ) {
			$set['padding_mobile'] = ElementorMapper::dims( $mobile );
		}
		$this->add(
			'spacing',
			'warning',
			__( 'Section spacing differs from the brand spacing.', 'ai-seo-content-studio' ),
			$ids,
			/* translators: %s: padding. */
			sprintf( __( 'Use the brand section padding (%s)', 'ai-seo-content-studio' ), $boxes['desktop'] ),
			array_map( static fn ( string $id ): array => self::op( $id, $set ), $ids )
		);
	}

	/**
	 * Boxed container width.
	 *
	 * @param array<int, mixed> $elements Page elements.
	 */
	private function widths( array $elements ): void {
		$token = (string) $this->tokens['layout']['container_width'];
		$want  = StyleValues::px( $token );
		if ( null === $want ) {
			$this->checked['widths'] = false;
			return;
		}
		$ids = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$key = 'container' === ( $el['elType'] ?? '' ) ? 'boxed_width' : 'content_width';
			if ( 'container' === ( $el['elType'] ?? '' ) && 'full' === ( $el['settings']['content_width'] ?? 'boxed' ) ) {
				continue;
			}
			$have = StyleValues::px( $el['settings'][ $key ] ?? null );
			if ( null !== $have && abs( $have - $want ) / $want > 0.05 ) {
				$ids[ (string) $el['id'] ] = $key;
			}
		}
		if ( array() === $ids ) {
			return;
		}
		$ops = array();
		foreach ( $ids as $id => $key ) {
			$ops[] = self::op( (string) $id, array( $key => ElementorMapper::slider( $token ) ) );
		}
		$this->add(
			'widths',
			'warning',
			__( 'Some sections use a different content width than the brand layout.', 'ai-seo-content-studio' ),
			array_map( 'strval', array_keys( $ids ) ),
			/* translators: %s: width. */
			sprintf( __( 'Set the content width to %s', 'ai-seo-content-studio' ), $token ),
			$ops
		);
	}

	/**
	 * Heading order.
	 *
	 * @param array<int, mixed> $elements Page elements.
	 */
	private function headings( array $elements ): void {
		foreach ( Headings::problems( $elements ) as $p ) {
			$this->add( 'headings', $p['severity'], $p['message'], $p['elements'], $p['fix_label'], $p['ops'] );
		}
	}

	/**
	 * Cards in one row should look alike.
	 *
	 * @param list<array<string, mixed>> $flat Flattened elements.
	 */
	private function patterns( array $flat ): void {
		$rows = array();
		foreach ( $flat as $row ) {
			if ( self::isCard( $row['element'] ) && array() !== $row['parents'] ) {
				$rows[ end( $row['parents'] ) ][] = $row['element'];
			}
		}
		foreach ( $rows as $cards ) {
			if ( count( $cards ) < 2 ) {
				continue;
			}
			$looks = array_unique(
				array_map(
					fn ( array $c ): string => (string) ( StyleValues::color( (array) $c['settings'], 'background_color', $this->kit )['hex'] ?? '' ) . '|' . (string) ( StyleValues::px( $c['settings']['border_radius'] ?? null ) ?? 0 ),
					$cards
				)
			);
			if ( count( $looks ) > 1 ) {
				$this->add( 'patterns', 'warning', __( 'Cards in the same row have different backgrounds or corners.', 'ai-seo-content-studio' ), array_map( static fn ( array $c ): string => (string) $c['id'], $cards ), '', array() );
			}
		}
	}

	/**
	 * Responsive settings: large headings need smaller phone sizes, columns must stack on
	 * phones, and large section padding needs a phone value.
	 *
	 * @param array<int, mixed>          $elements Page elements.
	 * @param list<array<string, mixed>> $flat     Flattened elements.
	 */
	private function responsive( array $elements, array $flat ): void {
		$by_id = array();
		foreach ( $flat as $row ) {
			$by_id[ (string) $row['element']['id'] ] = $row['element'];
		}
		foreach ( $flat as $row ) {
			$el = $row['element'];
			$s  = (array) ( $el['settings'] ?? array() );
			if ( 'heading' === ( $el['widgetType'] ?? '' ) ) {
				$desktop = StyleValues::px( $s['typography_font_size'] ?? null );
				if ( null !== $desktop && $desktop >= 40 && null === StyleValues::px( $s['typography_font_size_mobile'] ?? null ) ) {
					$set = array( 'typography_font_size_mobile' => ElementorMapper::slider( max( 26, (int) round( $desktop * 0.62 ) ) . 'px' ) );
					if ( null === StyleValues::px( $s['typography_font_size_tablet'] ?? null ) ) {
						$set['typography_font_size_tablet'] = ElementorMapper::slider( (int) round( $desktop * 0.8 ) . 'px' );
					}
					$this->add(
						'responsive',
						'warning',
						/* translators: %d: font size in px. */
						sprintf( __( 'A %dpx heading has no smaller size for phones.', 'ai-seo-content-studio' ), (int) $desktop ),
						array( (string) $el['id'] ),
						__( 'Add tablet and phone sizes', 'ai-seo-content-studio' ),
						array( self::op( (string) $el['id'], $set ) )
					);
				}
			}
			$parent = array() === $row['parents'] ? null : ( $by_id[ end( $row['parents'] ) ] ?? null );
			if ( 'container' === ( $el['elType'] ?? '' ) && null !== $parent && 'row' === ( $parent['settings']['flex_direction'] ?? '' ) ) {
				// Elementor does not inherit a container's phone width from larger screens: an empty
				// phone width is 100% (container.php, "min_affected_device"). Only an explicit
				// narrow phone width keeps columns side by side.
				$phone = $s['width_mobile'] ?? null;
				if ( null !== $phone && '' !== (string) ( $phone['size'] ?? '' ) && ! self::fullWidth( $phone ) ) {
					$this->add(
						'responsive',
						'warning',
						__( 'Columns stay side by side on phones.', 'ai-seo-content-studio' ),
						array( (string) $el['id'] ),
						__( 'Stack the columns on phones', 'ai-seo-content-studio' ),
						array(
							self::op(
								(string) $el['id'],
								array(
									'width_mobile' => array(
										'unit' => '%',
										'size' => 100,
									),
								)
							),
						)
					);
				}
			}
		}
		foreach ( $elements as $el ) {
			$v = is_array( $el ) ? StyleValues::vertical( $el['settings']['padding'] ?? null ) : null;
			if ( null !== $v && max( $v ) > 64 && null === StyleValues::vertical( $el['settings']['padding_mobile'] ?? null ) ) {
				$box = (array) $el['settings']['padding'];
				$this->add(
					'responsive',
					'recommendation',
					__( 'Large section spacing is not reduced on phones.', 'ai-seo-content-studio' ),
					array( (string) $el['id'] ),
					__( 'Halve the spacing on phones', 'ai-seo-content-studio' ),
					array(
						self::op(
							(string) $el['id'],
							array(
								'padding_mobile' => array(
									'unit'     => (string) ( $box['unit'] ?? 'px' ),
									'top'      => (string) round( (float) $box['top'] / 2 ),
									'right'    => (string) ( $box['right'] ?? '0' ),
									'bottom'   => (string) round( (float) $box['bottom'] / 2 ),
									'left'     => (string) ( $box['left'] ?? '0' ),
									'isLinked' => false,
								),
							)
						),
					)
				);
			}
		}
	}

	/* --------------------------------------------------------------- Helpers */

	/**
	 * Whether a width setting spans the whole row.
	 *
	 * @param mixed $width Slider value.
	 */
	private static function fullWidth( mixed $width ): bool {
		if ( ! is_array( $width ) ) {
			return false;
		}
		$size = (string) ( $width['size'] ?? '' );
		return match ( (string) ( $width['unit'] ?? '' ) ) {
			'%'      => (float) $size >= 100,
			'custom' => (bool) preg_match( '#^calc\(\(100% - 0px\) / 1\)$|^100%$#', $size ),
			default  => false,
		};
	}

	/**
	 * Whether an element is a card: an inner container with a background, corners or shadow.
	 *
	 * @param array<string, mixed> $el Element.
	 */
	public static function isCard( array $el ): bool {
		$s = (array) ( $el['settings'] ?? array() );
		return 'container' === ( $el['elType'] ?? '' ) && ! empty( $el['isInner'] ) && ( ! empty( $s['background_color'] ) || ! empty( $s['__globals__']['background_color'] ) || null !== StyleValues::px( $s['border_radius'] ?? null ) && StyleValues::px( $s['border_radius'] ) > 0 || 'yes' === ( $s['box_shadow_box_shadow_type'] ?? '' ) );
	}

	/**
	 * Nearest brand colour.
	 *
	 * @param string                $hex   Colour.
	 * @param array<string, string> $brand Hex => role.
	 * @return array{0: string, 1: string, 2: float} Hex, role, distance.
	 */
	private function nearest( string $hex, array $brand ): array {
		$best = array( '', '', INF );
		foreach ( $brand as $candidate => $role ) {
			$d = StyleValues::distance( $hex, (string) $candidate );
			if ( $d < $best[2] ) {
				$best = array( (string) $candidate, (string) $role, $d );
			}
		}
		return $best;
	}

	/**
	 * Normalised first family name.
	 *
	 * @param string $family Font stack.
	 */
	private static function family( string $family ): string {
		return strtolower( trim( explode( ',', $family )[0], " \t'\"" ) );
	}

	/**
	 * Display name for a family (as Elementor stores it).
	 *
	 * @param string $stack Token font stack.
	 * @param string $fallback Lower-case name.
	 */
	private static function display( string $stack, string $fallback ): string {
		$first = trim( explode( ',', $stack )[0], " \t'\"" );
		return '' !== $first ? $first : ucwords( $fallback );
	}

	/**
	 * A settings operation.
	 *
	 * @param string               $id  Element.
	 * @param array<string, mixed> $set Changes.
	 * @return array{type: string, element_id: string, set: array<string, mixed>}
	 */
	public static function op( string $id, array $set ): array {
		return array(
			'type'       => 'settings',
			'element_id' => $id,
			'set'        => $set,
		);
	}

	/**
	 * Record a finding.
	 *
	 * @param string                     $category  Category.
	 * @param string                     $severity  error|warning|recommendation.
	 * @param string                     $message   Message.
	 * @param list<string>               $elements  Element ids.
	 * @param string                     $fix_label Fix label ("" = no automatic fix).
	 * @param list<array<string, mixed>> $ops       Fix operations.
	 */
	private function add( string $category, string $severity, string $message, array $elements, string $fix_label, array $ops ): void {
		$this->findings[] = Findings::make( 'c', $category, $severity, $message, $elements, $fix_label, $ops );
	}
}
