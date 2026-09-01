<?php
/**
 * Integration-test bootstrap for the WordPress core test suite.
 *
 * Two execution modes (SF-001.1 item 4):
 *
 * - Local (optional): without WP_TESTS_DIR the suite exits 0 after a notice,
 *   so `composer qa` still works on machines without WordPress.
 * - CI (mandatory): set STATEFLOW_STRICT_INTEGRATION=1. A missing
 *   environment then exits 1 and FAILS the pipeline — zero executed tests
 *   is never an acceptable CI result.
 *
 * WooCommerce: when STATEFLOW_WC_PLUGIN_FILE points at a WooCommerce
 * checkout (woocommerce.php), it is required before StateFlow on
 * muplugins_loaded, so the integration suite runs against real
 * WooCommerce (HPOS outcome verification, SF-001.1 item 5).
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
		. "Setup: https://make.wordpress.org/core/handbook/testing/automated-testing/wordpress-test-suite/\n"
	);

	// CI mode must fail on a missing environment (SF-001.1 item 4).
	if ( '1' === getenv( 'STATEFLOW_STRICT_INTEGRATION' ) ) {
		fwrite( STDERR, "STATEFLOW_STRICT_INTEGRATION=1: integration environment is mandatory, failing.\n" );

		exit( 1 );
	}

	fwrite( STDERR, "Local mode: skipping the integration suite (explicitly optional outside CI).\n" );

	exit( 0 );
}

// Give access to tests_add_filter().
require_once $_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce (optional) and StateFlow as if both were active plugins.
 *
 * When STATEFLOW_BOOTSTRAP_FILE points at the StateFlow copy installed in
 * the test WordPress's plugins directory, that copy is loaded instead of
 * the workspace copy: WooCommerce resolves declaration plugin IDs via
 * plugin_basename(), which only matches paths inside wp-content/plugins.
 *
 * @return void
 */
function _stateflow_manually_load_plugin(): void {
	$wc_bootstrap = getenv( 'STATEFLOW_WC_PLUGIN_FILE' );

	if ( is_string( $wc_bootstrap ) && '' !== $wc_bootstrap && file_exists( $wc_bootstrap ) ) {
		require $wc_bootstrap;
	}

	$stateflow_bootstrap = getenv( 'STATEFLOW_BOOTSTRAP_FILE' );

	if ( is_string( $stateflow_bootstrap ) && '' !== $stateflow_bootstrap && file_exists( $stateflow_bootstrap ) ) {
		require $stateflow_bootstrap;
	} else {
		require dirname( __DIR__, 2 ) . '/stateflow.php';
	}
}

tests_add_filter( 'muplugins_loaded', '_stateflow_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

// Simulate plugin activation for WooCommerce's plugin-awareness queries.
// is_plugin_active() (used by WooCommerce's PluginUtil to build the
// active-plugin lists) reads the active_plugins option, and get_plugins()
// scans the plugins directory. The CI setup installs both plugins into the
// test WordPress's plugins directory (see .github/workflows/qa.yml) but the
// test suite never activates them; without this, active_only compatibility
// queries return an empty set.
$stateflow_active = array( 'stateflow/stateflow.php' );

$stateflow_wc_bootstrap = getenv( 'STATEFLOW_WC_PLUGIN_FILE' );

if ( is_string( $stateflow_wc_bootstrap ) && '' !== $stateflow_wc_bootstrap ) {
	$stateflow_active[] = 'woocommerce/woocommerce.php';
}

update_option( 'active_plugins', $stateflow_active );
