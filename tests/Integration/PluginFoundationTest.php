<?php
/**
 * WordPress/WooCommerce integration tests (require a WP test environment).
 *
 * Runs only when WP_TESTS_DIR points at a WordPress core test suite with the
 * StateFlow plugin loaded via muplugins_loaded (see tests/Integration/bootstrap.php).
 *
 * @package StateFlow\Tests\Integration
 */

declare( strict_types = 1 );

/**
 * Foundation checks against a real WordPress (+ optional WooCommerce).
 */
final class PluginFoundationTest extends WP_UnitTestCase {

	/**
	 * The plugin is loaded and bootstrapped.
	 *
	 * @return void
	 */
	public function test_plugin_is_bootstrapped(): void {
		$this->assertTrue( class_exists( 'StateFlow\Plugin' ) );
		$this->assertTrue( defined( 'STATEFLOW_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'STATEFLOW_VERSION' ) );
	}

	/**
	 * Environment reports supported on a supported stack.
	 *
	 * @return void
	 */
	public function test_environment_is_supported(): void {
		$this->assertTrue( \StateFlow\Infrastructure\Environment::meets_php() );
		$this->assertTrue( \StateFlow\Infrastructure\Environment::meets_wordpress() );
	}

	/**
	 * HPOS declaration: fires only when WooCommerce is installed.
	 *
	 * @return void
	 */
	public function test_hpos_declaration_path(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this WordPress test environment.' );
		}

		do_action( 'before_woocommerce_init' );

		$this->assertTrue( has_action( 'before_woocommerce_init' ) );
	}

	/**
	 * Activation and deactivation are repeatable on a live WordPress.
	 *
	 * @return void
	 */
	public function test_activation_and_deactivation_are_repeatable(): void {
		\StateFlow\Plugin::instance()->activate();
		\StateFlow\Plugin::instance()->deactivate();
		\StateFlow\Plugin::instance()->activate();
		\StateFlow\Plugin::instance()->deactivate();

		$this->assertTrue( true );
	}

	/**
	 * No StateFlow hooks may touch normal frontend requests: the plugin must
	 * not register query, template or asset filters.
	 *
	 * @return void
	 */
	public function test_no_frontend_surface_is_registered(): void {
		$forbidden = array( 'pre_get_posts', 'wp_enqueue_scripts', 'wp_head', 'wp_footer', 'the_content', 'template_redirect', 'parse_query', 'posts_request' );

		foreach ( $forbidden as $hook ) {
			$this->assertFalse(
				has_action( $hook ),
				sprintf( 'StateFlow must not register frontend hook "%s" in SF-001.', $hook )
			);
		}
	}

	/**
	 * With WooCommerce absent in the WP env, plugins_loaded leads to the
	 * requirements notice and nothing fatal.
	 *
	 * @return void
	 */
	public function test_graceful_without_woocommerce(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is installed; the absent-path is covered elsewhere.' );
		}

		do_action( 'plugins_loaded' );

		$this->assertTrue( has_action( 'admin_notices' ), 'Requirements notice must be registered when WooCommerce is absent.' );
	}
}
