<?php
/**
 * WordPress/WooCommerce integration tests (require a WP test environment).
 *
 * Runs only when WP_TESTS_DIR points at a WordPress core test suite with the
 * StateFlow plugin loaded via muplugins_loaded (see tests/Integration/bootstrap.php).
 *
 * SF-001.1/SF-001.2: verifies real outcomes, not global hook existence:
 * - HPOS: WooCommerce's own FeaturesController (resolved through the
 *   container) must list StateFlow in the `compatible` set for
 *   custom_order_tables (SF-001.2 item 5).
 * - Frontend: only StateFlow-registered callbacks count (SF-001.1 item 6) —
 *   other code (WooCommerce itself) legitimately hooks wp_head & co.
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
	 * SF-001.2 item 5: WooCommerce itself must list StateFlow inside the
	 * `compatible` set for custom_order_tables (HPOS) — the real outcome,
	 * not merely "a callback exists".
	 *
	 * The controller is resolved through the supported runtime path (the
	 * WooCommerce container, exactly as FeaturesUtil does) — never
	 * FeaturesController::get_instance(), which does not exist.
	 *
	 * @return void
	 */
	public function test_hpos_compatibility_outcome(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( \Automattic\WooCommerce\Internal\Features\FeaturesController::class ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this WordPress test environment.' );
		}

		// The declaration hook fires while WooCommerce is still loading;
		// fire it now and verify the resulting declaration is registered.
		do_action( 'before_woocommerce_init' );

		// get_compatible_plugins_for_feature() requires woocommerce_init to
		// have run (WC core fires it at the end of its own bootstrap).
		if ( ! did_action( 'woocommerce_init' ) ) {
			do_action( 'woocommerce_init' );
		}

		$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );

		$result = $controller->get_compatible_plugins_for_feature( 'custom_order_tables', true );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey(
			'compatible',
			$result,
			'get_compatible_plugins_for_feature() must return categorized compatibility info.'
		);
		$this->assertIsArray( $result['compatible'] );
		$this->assertContains(
			'stateflow/stateflow.php',
			$result['compatible'],
			'WooCommerce must recognize StateFlow as HPOS-compatible.'
		);
	}

	/**
	 * SF-001.2 item 4 / SF-001.3 item 2: activation/deactivation are
	 * repeatable on a live WordPress. Activation is an intentional no-op at
	 * SF-001, so the proof is: repeated lifecycle calls complete under the
	 * strict test environment (failOnWarning/failOnRisky convert any
	 * warning, notice or deprecation into a failure) and no side effects
	 * appear. When SF-002 introduces real activation/migration effects,
	 * those effects become the observable assertions here.
	 *
	 * @return void
	 */
	public function test_activation_and_deactivation_are_repeatable(): void {
		$plugin = \StateFlow\Plugin::instance();

		// Three full lifecycle cycles; any warning/notice/exception fails
		// the run via the strict PHPUnit configuration.
		for ( $i = 0; $i < 3; $i++ ) {
			$plugin->activate();
			$plugin->deactivate();
		}

		// Re-run boot(): idempotent, must not stack duplicate listeners.
		$plugin->boot();
		$plugin->boot();

		// initialize() is an instance method; call it on the instance as
		// the plugins_loaded hook would.
		$plugin->initialize();

		// Real observable outcome: the supported stack registers no
		// requirements notice and no frontend hooks.
		$this->assertFalse(
			$this->stateflow_has_hook( 'admin_notices' ),
			'initialize() on a supported stack must not register an admin notice.'
		);
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
