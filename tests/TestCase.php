<?php
/**
 * Minimal assertions.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Tests;

/**
 * Base class for unit tests.
 */
abstract class TestCase {

	protected function assertSame( mixed $expected, mixed $actual, string $message = '' ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( ( '' !== $message ? $message . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	protected function assertTrue( mixed $value, string $message = '' ): void {
		$this->assertSame( true, $value, $message );
	}

	protected function assertFalse( mixed $value, string $message = '' ): void {
		$this->assertSame( false, $value, $message );
	}

	protected function assertNull( mixed $value, string $message = '' ): void {
		$this->assertSame( null, $value, $message );
	}

	protected function assertContains( mixed $needle, array $haystack, string $message = '' ): void {
		if ( ! in_array( $needle, $haystack, true ) ) {
			throw new \RuntimeException( ( '' !== $message ? $message . ': ' : '' ) . var_export( $needle, true ) . ' not in ' . var_export( $haystack, true ) );
		}
	}

	protected function assertCount( int $count, array $items, string $message = '' ): void {
		$this->assertSame( $count, count( $items ), $message );
	}
}
