<?php
/**
 * Dependency-free unit test runner.
 *
 * Usage: php tests/run.php [filter]
 * Runs every public test* method of every class in tests/unit/*Test.php.
 *
 * @package AiSeoContentStudio
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/TestCase.php';

$filter = $argv[1] ?? '';
$passed = 0;
$failed = array();

foreach ( glob( __DIR__ . '/unit/*Test.php' ) as $file ) {
	require_once $file;
	$class = 'AiSeoContentStudio\\Tests\\' . basename( $file, '.php' );
	foreach ( get_class_methods( $class ) as $method ) {
		if ( ! str_starts_with( $method, 'test' ) || ( '' !== $filter && ! str_contains( $class . '::' . $method, $filter ) ) ) {
			continue;
		}
		$test = new $class();
		try {
			$test->$method();
			++$passed;
			echo '.';
		} catch ( \Throwable $e ) {
			$failed[] = sprintf( "%s::%s\n    %s: %s\n    at %s:%d", basename( $file, '.php' ), $method, get_class( $e ), $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
			echo 'F';
		}
	}
}

echo "\n\n";
foreach ( $failed as $f ) {
	echo "FAIL $f\n\n";
}
printf( "%d passed, %d failed\n", $passed, count( $failed ) );
exit( array() === $failed ? 0 : 1 );
