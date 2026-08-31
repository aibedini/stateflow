<?php
/**
 * Plugin Name:          StateFlow
 * Description:          Order state automation for WooCommerce.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.0
 * WC requires at least: 8.0
 * WC tested up to:      9.6
 * Author:               StateFlow
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          stateflow
 *
 * Bootstrap only: constants, autoloader, environment version source and the
 * single boot() call. Business logic lives in the StateFlow\ namespace; the
 * minimum versions above mirror src/Infrastructure/Environment.php (runtime
 * source of truth).
 *
 * @package StateFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'STATEFLOW_VERSION' ) ) {
	// SSOT for the runtime version is the plugin header.
	$stateflow_headers = get_file_data(
		__FILE__,
		array( 'Version' => 'Version' )
	);

	define( 'STATEFLOW_VERSION', (string) ( $stateflow_headers['Version'] ?? '0.0.0' ) );

	unset( $stateflow_headers );
}

if ( ! defined( 'STATEFLOW_PLUGIN_FILE' ) ) {
	define( 'STATEFLOW_PLUGIN_FILE', __FILE__ );
}

StateFlow\Plugin::instance()->boot();
