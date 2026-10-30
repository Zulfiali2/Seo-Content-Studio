<?php
/**
 * Finding records shared by the page checkers.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * A finding: {id, category, severity, message, elements, fix: {label, needs_value, suggestion}|null}
 * plus its operations (kept on the server; see apply()).
 */
final class Findings {

	public const SEVERITIES = array( 'error', 'warning', 'recommendation' );

	/**
	 * Build a finding. The id is derived from its content, so the same problem on an unchanged
	 * page always has the same id.
	 *
	 * @param string                     $prefix      Checker prefix.
	 * @param string                     $category    Category.
	 * @param string                     $severity    Severity.
	 * @param string                     $message     Message.
	 * @param list<string>               $elements    Element ids.
	 * @param string                     $fix_label   Fix label ("" = manual).
	 * @param list<array<string, mixed>> $ops         Operations.
	 * @param bool                       $needs_value The fix needs text from the user (alt text, a label).
	 * @param string                     $suggestion  Suggested value.
	 * @return array<string, mixed>
	 */
	public static function make( string $prefix, string $category, string $severity, string $message, array $elements, string $fix_label, array $ops, bool $needs_value = false, string $suggestion = '' ): array {
		$fixable = '' !== $fix_label && array() !== $ops;
		return array(
			'id'       => $prefix . substr( md5( $category . '|' . $message . '|' . implode( ',', $elements ) . '|' . (string) wp_json_encode( $ops ) ), 0, 12 ),
			'category' => $category,
			'severity' => in_array( $severity, self::SEVERITIES, true ) ? $severity : 'warning',
			'message'  => $message,
			'elements' => array_values( $elements ),
			'fix'      => $fixable ? array(
				'label'       => $fix_label,
				'needs_value' => $needs_value,
				'suggestion'  => $suggestion,
			) : null,
			'ops'      => $fixable ? $ops : array(),
		);
	}

	/**
	 * Drop repeated findings (same id).
	 *
	 * @param list<array<string, mixed>> $findings Findings.
	 * @return list<array<string, mixed>>
	 */
	public static function unique( array $findings ): array {
		$out = array();
		foreach ( $findings as $f ) {
			$out[ $f['id'] ] ??= $f;
		}
		return array_values( $out );
	}

	/**
	 * Findings without their operations (for the REST response).
	 *
	 * @param list<array<string, mixed>> $findings Findings.
	 * @return list<array<string, mixed>>
	 */
	public static function view( array $findings ): array {
		return array_map(
			static function ( array $f ): array {
				unset( $f['ops'] );
				return $f;
			},
			$findings
		);
	}

	/**
	 * Apply chosen findings' settings operations to an element tree.
	 *
	 * Operations of type "attachment_alt" are returned for the caller, which checks the user's
	 * permission on the attachment. The placeholder value "{value}" is replaced by the text the
	 * user entered for that finding.
	 *
	 * @param array<int, mixed>          $elements Elements.
	 * @param list<array<string, mixed>> $findings Current findings (recomputed on the server).
	 * @param list<string>               $ids      Chosen ids.
	 * @param array<string, string>      $values   Finding id => user text (sanitised by the caller).
	 * @return array{elements: array<int, mixed>, applied: list<string>, skipped: list<string>, attachments: list<array{attachment_id: int, alt: string}>}
	 */
	public static function apply( array $elements, array $findings, array $ids, array $values = array() ): array {
		$applied     = array();
		$skipped     = array();
		$attachments = array();
		foreach ( $findings as $f ) {
			if ( ! in_array( $f['id'], $ids, true ) ) {
				continue;
			}
			if ( null === $f['fix'] ) {
				$skipped[] = $f['id'];
				continue;
			}
			$value = trim( (string) ( $values[ $f['id'] ] ?? '' ) );
			if ( $f['fix']['needs_value'] && '' === $value ) {
				$skipped[] = $f['id'];
				continue;
			}
			$all = true;
			foreach ( $f['ops'] as $op ) {
				if ( 'attachment_alt' === $op['type'] ) {
					$attachments[] = array(
						'attachment_id' => (int) $op['attachment_id'],
						'alt'           => $value,
					);
					continue;
				}
				$set = self::fill( (array) $op['set'], $value );
				$found = false;
				$elements = ElementTree::setSettings( $elements, (string) $op['element_id'], $set, $found );
				$all      = $all && $found;
			}
			if ( $all ) {
				$applied[] = $f['id'];
			} else {
				$skipped[] = $f['id'];
			}
		}
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $applied, true ) && ! in_array( $id, $skipped, true ) ) {
				$skipped[] = $id; // No longer found: the page changed since the check.
			}
		}
		return array(
			'elements'    => $elements,
			'applied'     => $applied,
			'skipped'     => $skipped,
			'attachments' => $attachments,
		);
	}

	/**
	 * Replace "{value}" placeholders.
	 *
	 * @param array<string, mixed> $set   Settings.
	 * @param string               $value User text (escaped for widget text settings).
	 * @return array<string, mixed>
	 */
	private static function fill( array $set, string $value ): array {
		array_walk_recursive(
			$set,
			static function ( &$v ) use ( $value ): void {
				if ( '{value}' === $v ) {
					$v = htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false );
				}
			}
		);
		return $set;
	}
}
