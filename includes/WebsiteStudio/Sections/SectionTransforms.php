<?php
/**
 * Deterministic section changes (no AI).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Sections;

use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Generator\ContentLayoutMapper;

defined( 'ABSPATH' ) || exit;

/**
 * Change one aspect of a normalised PageDocument section: colours, typography, layout,
 * spacing or responsive behaviour. Content is never touched. Every result is normalised
 * again by the caller before it is mapped.
 */
final class SectionTransforms {

	public const ACTIONS = array( 'colors', 'typography', 'layout', 'spacing', 'responsive' );

	public const SPACING = array(
		'compact'  => array( '40px 0', '32px 0', '24px 0' ),
		'normal'   => array( '80px 0', '60px 0', '40px 0' ),
		'spacious' => array( '120px 0', '90px 0', '60px 0' ),
	);

	/**
	 * Apply an action.
	 *
	 * @param string               $action  One of ACTIONS.
	 * @param array<string, mixed> $section Normalised section.
	 * @param array<string, mixed> $options Action options (background, scale, font, kind, columns, spacing).
	 * @param array<string, mixed> $tokens  Page tokens.
	 * @return array{section: array<string, mixed>, summary: string}
	 * @throws \InvalidArgumentException When the options do not fit the section.
	 */
	public static function apply( string $action, array $section, array $options, array $tokens ): array {
		return match ( $action ) {
			'colors'     => self::colors( $section, (string) ( $options['background'] ?? '' ) ),
			'typography' => self::typography( $section, (string) ( $options['scale'] ?? 'default' ), (string) ( $options['font'] ?? '' ), $tokens ),
			'layout'     => self::layout( $section, (string) ( $options['kind'] ?? '' ), (int) ( $options['columns'] ?? 0 ) ),
			'spacing'    => self::spacing( $section, (string) ( $options['spacing'] ?? 'normal' ) ),
			'responsive' => self::responsive( $section ),
			default      => throw new \InvalidArgumentException( 'Unknown section action.' ),
		};
	}

	/**
	 * Background role (the brand decides the colour) and matching text colour.
	 *
	 * @param array<string, mixed> $section Section.
	 * @param string               $role    ""|surface|primary|secondary|dark.
	 * @return array{section: array<string, mixed>, summary: string}
	 */
	private static function colors( array $section, string $role ): array {
		$map = array(
			''          => array( '', '' ),
			'surface'   => array( 'token:surface', '' ),
			'primary'   => array( 'token:primary', 'token:background' ),
			'secondary' => array( 'token:secondary', 'token:background' ),
			'dark'      => array( 'token:heading', 'token:background' ),
		);
		if ( ! isset( $map[ $role ] ) ) {
			throw new \InvalidArgumentException( __( 'Choose a background: page, light, primary, secondary or dark.', 'ai-seo-content-studio' ) );
		}
		$section['style']['background'] = $map[ $role ][0];
		$section['style']['text_color'] = $map[ $role ][1];
		return array(
			'section' => $section,
			/* translators: %s: background role. */
			'summary' => sprintf( __( 'Background changed to "%s"', 'ai-seo-content-studio' ), '' === $role ? __( 'page', 'ai-seo-content-studio' ) : $role ),
		);
	}

	/**
	 * Heading sizes (smaller, larger, or back to the brand scale) and optionally the heading font.
	 *
	 * @param array<string, mixed> $section Section.
	 * @param string               $scale   smaller|larger|default.
	 * @param string               $font    Font family ("" = keep).
	 * @param array<string, mixed> $tokens  Tokens.
	 * @return array{section: array<string, mixed>, summary: string}
	 */
	private static function typography( array $section, string $scale, string $font, array $tokens ): array {
		$factor = array(
			'smaller' => 0.85,
			'larger'  => 1.2,
			'default' => 1.0,
		)[ $scale ] ?? null;
		if ( null === $factor ) {
			throw new \InvalidArgumentException( __( 'Choose smaller, larger or the brand size.', 'ai-seo-content-studio' ) );
		}
		$family = '';
		if ( '' !== trim( $font ) ) {
			$family = (string) DesignTokens::fontFamily( $font );
			if ( '' === $family ) {
				throw new \InvalidArgumentException( __( 'That font name is not valid.', 'ai-seo-content-studio' ) );
			}
		}
		$tokens = DesignTokens::normalize( $tokens );
		$fix    = static function ( array $e ) use ( $factor, $family, $tokens ): array {
			if ( 'heading' !== $e['type'] ) {
				return $e;
			}
			$style = (array) ( $e['style'] ?? array() );
			if ( 1.0 === $factor ) {
				$style['font_size'] = array();
			} else {
				$base               = (string) ( $tokens['typography']['scale'][ $e['level'] ]['desktop'] ?? '' );
				$base               = '' !== $base ? $base : ( ElementorMapper::DEFAULT_SCALE[ $e['level'] ] ?? 26 ) . 'px';
				$desktop            = ElementorMapper::scaleLength( $base, $factor, 16 );
				$style['font_size'] = array(
					'desktop' => $desktop,
					'tablet'  => ElementorMapper::scaleLength( $desktop, 0.85, 16 ),
					'mobile'  => ElementorMapper::scaleLength( $desktop, 0.72, 16 ),
				);
			}
			if ( '' !== $family ) {
				$style['font_family'] = $family;
			}
			$e['style'] = $style;
			return $e;
		};
		$section['elements'] = self::each( (array) $section['elements'], $fix );
		return array(
			'section' => $section,
			'summary' => 1.0 === $factor && '' === $family ? __( 'Headings back to the brand sizes', 'ai-seo-content-studio' ) : __( 'Heading typography changed', 'ai-seo-content-studio' ),
		);
	}

	/**
	 * Layout kind and desktop columns.
	 *
	 * @param array<string, mixed> $section Section.
	 * @param string               $kind    stack|split|grid.
	 * @param int                  $columns Desktop columns (grid).
	 * @return array{section: array<string, mixed>, summary: string}
	 */
	private static function layout( array $section, string $kind, int $columns ): array {
		$groups = count( array_filter( (array) $section['elements'], static fn ( array $e ): bool => 'group' === $e['type'] ) );
		$header = count( $section['elements'] ) - $groups;
		if ( ! in_array( $kind, array( 'stack', 'split', 'grid' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Choose one column, two halves or a grid.', 'ai-seo-content-studio' ) );
		}
		if ( 'split' === $kind && ( 2 !== $groups || $header > 0 ) ) {
			throw new \InvalidArgumentException( __( 'Two halves need a section made of exactly two parts. Use a grid for this section.', 'ai-seo-content-studio' ) );
		}
		if ( 'grid' === $kind && 0 === $groups ) {
			throw new \InvalidArgumentException( __( 'This section has no cards to arrange in a grid.', 'ai-seo-content-studio' ) );
		}
		$desktop = 'split' === $kind ? 2 : ( 'stack' === $kind ? 1 : max( 1, min( 6, $columns > 0 ? $columns : ContentLayoutMapper::gridColumns( $groups, false ) ) ) );
		$section['layout']['kind']    = $kind;
		$section['layout']['columns'] = array(
			'desktop' => $desktop,
			'tablet'  => min( 2, $desktop ),
			'mobile'  => 1,
		);
		return array(
			'section' => $section,
			/* translators: 1: layout, 2: columns. */
			'summary' => sprintf( __( 'Layout changed to %1$s (%2$d columns on desktop)', 'ai-seo-content-studio' ), $kind, $desktop ),
		);
	}

	/**
	 * Section padding preset per device.
	 *
	 * @param array<string, mixed> $section Section.
	 * @param string               $preset  compact|normal|spacious.
	 * @return array{section: array<string, mixed>, summary: string}
	 */
	private static function spacing( array $section, string $preset ): array {
		if ( ! isset( self::SPACING[ $preset ] ) ) {
			throw new \InvalidArgumentException( __( 'Choose compact, normal or spacious.', 'ai-seo-content-studio' ) );
		}
		[ $d, $t, $m ]               = self::SPACING[ $preset ];
		$section['style']['padding'] = array(
			'desktop' => $d,
			'tablet'  => $t,
			'mobile'  => $m,
		);
		return array(
			'section' => $section,
			/* translators: %s: spacing preset. */
			'summary' => sprintf( __( 'Spacing set to %s', 'ai-seo-content-studio' ), $preset ),
		);
	}

	/**
	 * Responsive rules: columns per device from the content, smaller long headings, one column
	 * on phones, and phone padding at half the desktop value.
	 *
	 * @param array<string, mixed> $section Section.
	 * @return array{section: array<string, mixed>, summary: string}
	 */
	private static function responsive( array $section ): array {
		$adapted = ContentLayoutMapper::adapt( array( 'sections' => array( $section ) ), false );
		$section = $adapted['document']['sections'][0];
		$section['layout']['columns']['mobile'] = 1;
		$section['layout']['columns']['tablet'] = min( 2, (int) $section['layout']['columns']['desktop'] );
		$desktop = (string) ( $section['style']['padding']['desktop'] ?? '' );
		if ( '' !== $desktop ) {
			$section['style']['padding']['tablet'] = ElementorMapper::scaleBox( $desktop, 0.75 );
			$section['style']['padding']['mobile'] = ElementorMapper::scaleBox( $desktop, 0.5 );
		}
		return array(
			'section' => $section,
			'summary' => __( 'Tablet and phone layout improved', 'ai-seo-content-studio' ),
		);
	}

	/**
	 * Apply a function to every element, groups included.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @param callable                   $fn       fn( element ): element.
	 * @return list<array<string, mixed>>
	 */
	private static function each( array $elements, callable $fn ): array {
		foreach ( $elements as $i => $e ) {
			if ( 'group' === $e['type'] ) {
				$elements[ $i ]['elements'] = self::each( (array) $e['elements'], $fn );
			} else {
				$elements[ $i ] = $fn( $e );
			}
		}
		return $elements;
	}
}
