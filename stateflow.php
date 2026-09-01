<?php
/**
 * Plugin Name:          StateFlow
 * Description:          StateFlow adds an explainable sales-state layer to WooCommerce products and variations without mutating their canonical price or inventory data.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.0
 * WC requires at least: 8.0
 * WC tested up to:      11.0
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

// Composer autoloader when the dev dependencies are present; otherwise a
// minimal PSR-4 fallback for the StateFlow\ namespace. The released plugin
// has no runtime vendor dependencies; the fallback keeps the same file
// working (and testable) without a composer install.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} elseif ( ! defined( 'STATEFLOW_AUTOLOADER_REGISTERED' ) ) {
	define( 'STATEFLOW_AUTOLOADER_REGISTERED', true );

	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( ! str_starts_with( $class_name, 'StateFlow\\' ) ) {
				return;
			}

			$relative  = str_replace( '\\', '/', substr( $class_name, strlen( 'StateFlow\\' ) ) );
			$candidate = __DIR__ . '/src/' . $relative . '.php';

			if ( is_file( $candidate ) ) {
				require_once $candidate;
			}
		}
	);
}

if ( ! defined( 'STATEFLOW_VERSION' ) ) {
	// Cheap runtime constant. The consistency with the plugin header Version
	// is enforced by tests/Unit/VersionConsistencyTest.php; never replace
	// this with a runtime filesystem read.
	define( 'STATEFLOW_VERSION', '0.1.0' );
}

if ( ! defined( 'STATEFLOW_PLUGIN_FILE' ) ) {
	define( 'STATEFLOW_PLUGIN_FILE', __FILE__ );
}

StateFlow\Plugin::instance()->boot();
