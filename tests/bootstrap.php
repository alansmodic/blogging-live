<?php
/**
 * WordPress test-suite bootstrap.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

$tests_dir_value = getenv( 'WP_TESTS_DIR' );
$tests_dir       = $tests_dir_value ? $tests_dir_value : '/tmp/wordpress-tests-lib';

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/blogging-live.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
