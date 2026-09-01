<?php
/**
 * WordPress/WooCommerce integration tests (require a WP test environment).
 *
 * Runs only when WP_TESTS_DIR points at a WordPress core test suite with the
 * StateFlow plugin loaded via muplugins_loaded (see tests/Integration/bootstrap.php).
 *
 * SF-001.1: verifies real outcomes, not global hook existence:
 * - HPOS: WooCommerce's own FeaturesController must list StateFlow as
 *   compatible with custom_order_tables (item 5).
 * - Frontend: only StateFlow-registered callbacks count (item 6) — other
 *   code (WooCommerce itself) legitimately hooks wp_head & co.
 *
 * @package StateFlow\Tests\Integration
 */

declare( strict_types = 1 );

/**
 * Foundation checks against a real WordPress (+ optional WooCommerce).
 */
final class PluginFoundationTest extends WP_UnitTestCase {

	/**
	 * Class names (prefix match) that identify StateFlow callbacks.
	 *
	 * @return array<int, string>
	 */
	private function stateflow_class_prefixes(): array {
		return array( 'StateFlow' );
	}

	/**
	 * Whether a hook carries a callback from StateFlow classes.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	private function stateflow_has_hook( string $hook ): bool {
		$filter = $GLOBALS['wp_filter'][ $hook ] ?? null;

		if ( ! $filter instanceof WP_Hook ) {
			return false;
		}

		foreach ( $filter->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$func = $callback['function'];

				if ( ! is_array( $func ) || ! isset( $func[0] ) || ! is_object( $func[0] ) ) {
					continue;
				}

				$class = get_class( $func[0] );

				foreach ( $this->stateflow_class_prefixes() as $prefix ) {
					if ( str_starts_with( $class, $prefix ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * The plugin is loaded and bootstrapped.
	 *
	 * @return void
	 */
	public function test_plugin_is_bootstrapped(): void {
		$this->assertTrue( class_exists( 'StateFlow\Plugin' ) );
		$this->assertSame( '0.1.0', STATEFLOW_VERSION );
	}

	/**
	 * Environment reports supported on a supported stack.
	 *
	 * @return void
	 */
	public function test_environment_is_supported(): void {
		$this->assertTrue( \StateFlow\Infrastructure\Environment::meets_php() );
		$this->assertTrue( \StateFlow\Infrastructure\Environment::meets_wordpress() );
		$this->assertTrue( \StateFlow\Infrastructure\Environment::meets_woocommerce() );
	}

	/**
	 * SF-001.1 item 5: WooCommerce itself must recognize StateFlow as
	 * compatible with custom_order_tables (HPOS) — the real outcome, not
	 * merely "a callback exists".
	 *
	 * @return void
	 */
	public function test_hpos_compatibility_outcome(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this WordPress test environment.' );
		}

		do_action( 'before_woocommerce_init' );

		$controller = \Automattic\WooCommerce\Internal\Features\FeaturesController::get_instance();

		$this->assertNotNull( $controller, 'WooCommerce FeaturesController must be available.' );

		$compatible = $controller->get_compatible_plugins_for_feature( 'custom_order_tables', true );

		$this->assertIsArray( $compatible );
		$this->assertContains(
			'stateflow/stateflow.php',
			$compatible,
			'WooCommerce must recognize StateFlow as HPOS-compatible.'
		);
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
		\StateFlow\Plugin::initialize();

		$this->assertTrue( true );
	}

	/**
	 * SF-001.1 item 6: no StateFlow callback on frontend-specific hooks —
	 * regardless of what WooCommerce or other plugins register.
	 *
	 * @return void
	 */
	public function test_no_stateflow_callbacks_on_frontend_hooks(): void {
		$forbidden = array(
			'wp_enqueue_scripts',
			'pre_get_posts',
			'wp_head',
			'wp_footer',
			'wp_body_open',
			'the_content',
			'template_redirect',
			'parse_query',
			'posts_request',
			'wp_loaded',
		);

		// Fire the real load sequence first so degraded/service initialization
		// has actually run.
		do_action( 'plugins_loaded' );

		foreach ( $forbidden as $hook ) {
			$this->assertFalse(
				$this->stateflow_has_hook( $hook ),
				sprintf( 'StateFlow must not register the frontend hook "%s".', $hook )
			);
		}
	}

	/**
	 * Degraded mode: without WooCommerce the requirements notice registers.
	 *
	 * @return void
	 */
	public function test_graceful_without_woocommerce(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is installed; the absent path is covered by the unit suites.' );
		}

		do_action( 'plugins_loaded' );

		$this->assertTrue( has_action( 'admin_notices' ), 'Requirements notice must be registered when WooCommerce is absent.' );
	}
}
