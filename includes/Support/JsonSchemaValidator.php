<?php
/**
 * Minimal JSON Schema validator for AI structured output.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the subset of JSON Schema used by this plugin's prompts:
 * type (incl. type arrays), properties, required, additionalProperties (false),
 * items, enum, minItems, maxItems, minLength, maxLength.
 *
 * Provider-side structured output is helpful but never trusted on its own.
 */
final class JsonSchemaValidator {

	/**
	 * Validate data; returns a list of error strings (empty when valid).
	 *
	 * @param mixed                $data   Decoded JSON.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   JSON pointer-ish path for messages.
	 * @return string[]
	 */
	public function validate( mixed $data, array $schema, string $path = '$' ): array {
		$errors = array();

		if ( isset( $schema['type'] ) ) {
			$types = (array) $schema['type'];
			$ok    = false;
			foreach ( $types as $type ) {
				if ( $this->matchesType( $data, (string) $type ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return array( sprintf( '%s: expected %s', $path, implode( '|', $types ) ) );
			}
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
			$errors[] = sprintf( '%s: value not in enum', $path );
		}

		if ( is_string( $data ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $data ) : strlen( $data );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
				$errors[] = sprintf( '%s: shorter than %d', $path, (int) $schema['minLength'] );
			}
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
				$errors[] = sprintf( '%s: longer than %d', $path, (int) $schema['maxLength'] );
			}
		}

		if ( is_array( $data ) && array_is_list( $data ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			if ( isset( $schema['minItems'] ) && count( $data ) < (int) $schema['minItems'] ) {
				$errors[] = sprintf( '%s: fewer than %d items', $path, (int) $schema['minItems'] );
			}
			if ( isset( $schema['maxItems'] ) && count( $data ) > (int) $schema['maxItems'] ) {
				$errors[] = sprintf( '%s: more than %d items', $path, (int) $schema['maxItems'] );
			}
			foreach ( $data as $index => $item ) {
				$errors = array_merge( $errors, $this->validate( $item, $schema['items'], $path . '[' . $index . ']' ) );
			}
		}

		if ( is_array( $data ) && ( ! array_is_list( $data ) || array() === $data ) && isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
				if ( ! array_key_exists( $required, $data ) ) {
					$errors[] = sprintf( '%s: missing required property "%s"', $path, $required );
				}
			}
			foreach ( $data as $key => $value ) {
				if ( isset( $schema['properties'][ $key ] ) && is_array( $schema['properties'][ $key ] ) ) {
					$errors = array_merge( $errors, $this->validate( $value, $schema['properties'][ $key ], $path . '.' . $key ) );
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					$errors[] = sprintf( '%s: unexpected property "%s"', $path, $key );
				}
			}
		}

		return $errors;
	}

	/**
	 * Type check.
	 *
	 * @param mixed  $data Value.
	 * @param string $type JSON type.
	 */
	private function matchesType( mixed $data, string $type ): bool {
		return match ( $type ) {
			'object'  => is_array( $data ) && ( array() === $data || ! array_is_list( $data ) ),
			'array'   => is_array( $data ) && array_is_list( $data ),
			'string'  => is_string( $data ),
			'integer' => is_int( $data ),
			'number'  => is_int( $data ) || is_float( $data ),
			'boolean' => is_bool( $data ),
			'null'    => null === $data,
			default   => true,
		};
	}
}
