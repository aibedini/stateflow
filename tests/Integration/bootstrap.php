<?php
/**
 * Integration-test bootstrap for the WordPress core test suite.
 *
 * Requires a WordPress test environment via WP_TESTS_DIR (see docs/ARCHITECTURE.md
 * for setup). When the environment is absent the suite reports zero executed
 * tests instead of failing, so `composer qa` still works on machines without
 * WordPress; the unit suite remains the primary local gate.
 *
 * @package StateFlow\Tests\Integration
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"WordPress test suite not found (WP_TESTS_DIR not set).\n"
		. "Integration tests need a WordPress test environment; skipping them.\n"
		. "Setup: https://make.wordpress.org/core/handbook/testing/automated-testing/wordpress-test-suite/\n"
	);

	exit( 0 );
}

// Give access to tests_add_filter().
require_once $_tests_dir . '/includes/functions.php';

/**
 * Load StateFlow as if it were an active plugin.
 *
 * @return void
 */
function _stateflow_manually_load_plugin(): void {
	require dirname( __DIR__, 2 ) . '/stateflow.php';
}

tests_add_filter( 'muplugins_loaded', '_stateflow_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
