<?php
/**
 * Unit tests bootstrap for the WooCommerce-present process.
 *
 * Loads the regular unit bootstrap, then simulates an active WooCommerce the
 * way the real plugin exposes it: the `WooCommerce` class + `WC_VERSION`.
 *
 * The simulated version honors the WC_TEST_VERSION environment variable so
 * the CI compatibility matrix can run the policy layer against multiple
 * WooCommerce generations (SF-001.1 item 7). Used only by
 * phpunit-present.xml.dist. The accompanying test
 * (EnvironmentPresentTest::test_woocommerce_is_present_in_this_process)
 * asserts this setup actually took effect.
 *
 * @package StateFlow\Tests
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

if ( ! defined( 'WC_VERSION' ) ) {
	$wc_test_version = getenv( 'WC_TEST_VERSION' );

	define( 'WC_VERSION', is_string( $wc_test_version ) && '' !== $wc_test_version ? $wc_test_version : '9.2.0' );
}

if ( ! class_exists( 'WooCommerce', false ) ) {
	/**
	 * Simulated WooCommerce plugin class for the present-process tests.
	 */
	class WooCommerce {}
}
