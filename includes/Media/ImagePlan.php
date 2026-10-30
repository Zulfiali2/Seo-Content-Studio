<?php
/**
 * Image slots of a project: the featured image and images placed after sections.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media;

use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for the `images` project section.
 *
 * Slot shape: id, kind (featured|inline), section (section id for inline images), prompt,
 * alt, caption, aspect, use (include in the draft), attachment_id, url, provider, model,
 * revised_prompt, error, source (ai | stock | placeholder), credit, credit_url, license.
 */
final class ImagePlan {

	public const MAX_INLINE = 5;

	/**
	 * Suggested slots from the content metadata (AI suggestions, all editable).
	 *
	 * @param array<string, mixed> $content Content model.
	 * @return array{slots: array<int, array<string, mixed>>}
	 */
	public static function fromContent( array $content ): array {
		$meta  = (array) ( $content['meta'] ?? array() );
		$slots = array(
			self::slot(
				array(
					'id'     => 'featured',
					'kind'   => 'featured',
					'prompt' => (string) ( $meta['featured_image_prompt'] ?? '' ),
					'alt'    => (string) ( $meta['featured_image_alt'] ?? '' ),
					'aspect' => '3:2',
				)
			),
		);
		$n     = 0;
		foreach ( (array) ( $meta['image_placements'] ?? array() ) as $placement ) {
			$section = self::sectionFor( $content, (string) ( $placement['after_section'] ?? '' ) );
			if ( '' === $section || $n >= self::MAX_INLINE ) {
				continue;
			}
			$slots[] = self::slot(
				array(
					'id'      => 'inline-' . ( ++$n ),
					'kind'    => 'inline',
					'section' => $section,
					'prompt'  => (string) ( $placement['prompt'] ?? '' ),
					'alt'     => (string) ( $placement['alt'] ?? '' ),
					'aspect'  => '3:2',
				)
			);
		}
		return array( 'slots' => $slots );
	}

	/**
	 * Section id whose heading matches (case-insensitive), or ''.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param string               $heading Heading text or section id.
	 */
	public static function sectionFor( array $content, string $heading ): string {
		$needle = mb_strtolower( trim( $heading ) );
		if ( '' === $needle ) {
			return '';
		}
		foreach ( (array) ( $content['sections'] ?? array() ) as $section ) {
			$id      = mb_strtolower( (string) ( $section['id'] ?? '' ) );
			$heading = mb_strtolower( trim( (string) ( $section['heading'] ?? '' ) ) );
			if ( in_array( $needle, array( $id, $heading ), true ) ) {
				return (string) $section['id'];
			}
		}
		return '';
	}

	/**
	 * Normalise one slot.
	 *
	 * @param array<string, mixed> $s Input.
	 * @return array<string, mixed>
	 */
	public static function slot( array $s ): array {
		$kind = 'featured' === ( $s['kind'] ?? '' ) ? 'featured' : 'inline';
		return array(
			'id'             => 'featured' === $kind ? 'featured' : substr( (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) ( $s['id'] ?? 'inline' ) ) ), 0, 20 ),
			'kind'           => $kind,
			'section'        => 'featured' === $kind ? '' : substr( (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) ( $s['section'] ?? '' ) ) ), 0, 40 ),
			'prompt'         => Sanitize::textarea( $s['prompt'] ?? '', 4000 ),
			'alt'            => Sanitize::text( $s['alt'] ?? '', 250 ),
			'caption'        => Sanitize::text( $s['caption'] ?? '', 300 ),
			'aspect'         => Sanitize::enum( $s['aspect'] ?? '', ImageRequest::ASPECTS, '3:2' ),
			'use'            => ! array_key_exists( 'use', $s ) || (bool) $s['use'],
			'attachment_id'  => max( 0, (int) ( $s['attachment_id'] ?? 0 ) ),
			'url'            => esc_url_raw( (string) ( $s['url'] ?? '' ) ),
			'provider'       => sanitize_key( (string) ( $s['provider'] ?? '' ) ),
			'model'          => Sanitize::text( $s['model'] ?? '', 191 ),
			'revised_prompt' => Sanitize::textarea( $s['revised_prompt'] ?? '', 4000 ),
			'error'          => Sanitize::text( $s['error'] ?? '', 500 ),
			'source'         => Sanitize::enum( $s['source'] ?? '', array( 'ai', 'stock', 'placeholder' ), 'ai' ),
			'credit'         => Sanitize::text( $s['credit'] ?? '', 300 ),
			'credit_url'     => esc_url_raw( (string) ( $s['credit_url'] ?? '' ) ),
			'license'        => Sanitize::text( $s['license'] ?? '', 100 ),
		);
	}

	/**
	 * Sanitise a full plan from untrusted input, keeping server-owned fields from $current.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param array<string, mixed> $current Current plan.
	 * @param array<string, mixed> $content Content (inline slots must point at a real section).
	 * @return array{slots: array<int, array<string, mixed>>}
	 */
	public static function sanitize( array $input, array $current, array $content ): array {
		$existing = array();
		foreach ( (array) ( $current['slots'] ?? array() ) as $slot ) {
			$existing[ (string) $slot['id'] ] = $slot;
		}
		$out      = array();
		$seen     = array();
		$inline   = 0;
		$featured = false;
		foreach ( array_slice( (array) ( $input['slots'] ?? array() ), 0, self::MAX_INLINE + 1 ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$slot = self::slot( $raw );
			if ( 'featured' === $slot['kind'] ) {
				if ( $featured ) {
					continue;
				}
				$featured = true;
			} else {
				if ( $inline >= self::MAX_INLINE || '' === self::sectionFor( $content, $slot['section'] ) ) {
					continue;
				}
				++$inline;
				if ( '' === $slot['id'] || 'featured' === $slot['id'] || isset( $seen[ $slot['id'] ] ) ) {
					$slot['id'] = 'inline-' . substr( wp_generate_uuid4(), 0, 8 );
				}
			}
			// Generated image details are set by the server only.
			$prev = $existing[ $slot['id'] ] ?? array();
			foreach ( array( 'attachment_id', 'url', 'provider', 'model', 'revised_prompt', 'error', 'source', 'credit', 'credit_url', 'license' ) as $key ) {
				$slot[ $key ] = $prev[ $key ] ?? ( 'attachment_id' === $key ? 0 : '' );
			}
			$seen[ $slot['id'] ] = true;
			$out[]               = self::slot( $slot );
		}
		return array( 'slots' => $out );
	}

	/**
	 * Slots that will be placed in a draft: chosen, generated and still in the Media Library.
	 *
	 * @param array<string, mixed>|null $plan Plan.
	 * @return array{featured: int, inline: array<string, array{id: int, url: string, alt: string, caption: string}>}
	 */
	public static function forDraft( ?array $plan ): array {
		$featured = 0;
		$inline   = array();
		foreach ( (array) ( $plan['slots'] ?? array() ) as $slot ) {
			$id = (int) ( $slot['attachment_id'] ?? 0 );
			if ( empty( $slot['use'] ) || $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			if ( 'featured' === $slot['kind'] ) {
				$featured = $id;
				continue;
			}
			$data = self::imageData( $id );
			if ( null === $data || isset( $inline[ $slot['section'] ] ) ) {
				continue;
			}
			$inline[ (string) $slot['section'] ] = $data;
		}
		return array(
			'featured' => $featured,
			'inline'   => $inline,
		);
	}

	/**
	 * Large image URL, alt text and caption of an attachment, or null without a URL.
	 *
	 * @param int $id Attachment.
	 * @return array{id: int, url: string, alt: string, caption: string}|null
	 */
	public static function imageData( int $id ): ?array {
		$url = (string) wp_get_attachment_image_url( $id, 'large' );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'id'      => $id,
			'url'     => $url,
			'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption' => (string) wp_get_attachment_caption( $id ),
		);
	}

	/**
	 * Image markup as the block editor saves it (core/image, figure with optional caption).
	 *
	 * @param array{id: int, url: string, alt: string, caption: string} $img Image.
	 */
	public static function figureHtml( array $img ): string {
		$caption = '' !== $img['caption'] ? '<figcaption class="wp-element-caption">' . esc_html( $img['caption'] ) . '</figcaption>' : '';
		return '<figure class="wp-block-image size-large"><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ) . '" class="wp-image-' . (int) $img['id'] . '"/>' . $caption . '</figure>';
	}
}
