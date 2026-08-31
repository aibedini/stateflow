<?php
/**
 * Unit tests for the plugin bootstrap behaviour (process without WooCommerce).
 *
 * Covers: hook registration, repeatable activation/deactivation, no-fatal
 * degradation with WooCommerce absent, notice authorization, HPOS
 * declaration path.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use StateFlow\Infrastructure\Environment;
use StateFlow\Tests\Harness;
use StateFlow\Tests\PluginLoader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Bootstrap lifecycle tests.
 */
final class PluginTest extends TestCase {

	/**
	 * Fresh stub state + load the real plugin file once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Harness::reset();
		PluginLoader::load();
	}

	/**
	 * The main file defines its identity constants.
	 *
	 * @return void
	 */
	public function test_plugin_constants_are_defined(): void {
		$this->assertSame( '0.1.0', STATEFLOW_VERSION );
		$this->assertStringEndsWith( 'stateflow.php', STATEFLOW_PLUGIN_FILE );
	}

	/**
	 * Activation and deactivation callbacks are registered at load time.
	 *
	 * @return void
	 */
	public function test_activation_and_deactivation_hooks_are_registered(): void {
		$this->assertNotEmpty( Harness::hooks( '_stateflow_activation' ), 'register_activation_hook must have been called.' );
		$this->assertNotEmpty( Harness::hooks( '_stateflow_deactivation' ), 'register_deactivation_hook must have been called.' );
	}

	/**
	 * Activation can run repeatedly without errors or warnings.
	 *
	 * @return void
	 */
	public function test_activation_is_repeatable(): void {
		$fired = 0;
		add_action(
			'_stateflow_activation',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		// Two activation events, each followed by WP firing the hook.
		\StateFlow\Plugin::instance()->activate();
		Harness::fire( '_stateflow_activation' );
		\StateFlow\Plugin::instance()->activate();
		Harness::fire( '_stateflow_activation' );

		$this->assertSame( 2, $fired, 'Activation must remain callable repeatedly without errors.' );
	}

	/**
	 * Deactivation can run repeatedly without errors or warnings.
	 *
	 * @return void
	 */
	public function test_deactivation_is_repeatable(): void {
		$fired = 0;
		add_action(
			'_stateflow_deactivation',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		\StateFlow\Plugin::instance()->deactivate();
		Harness::fire( '_stateflow_deactivation' );
		\StateFlow\Plugin::instance()->deactivate();
		Harness::fire( '_stateflow_deactivation' );

		$this->assertSame( 2, $fired, 'Deactivation must remain callable repeatedly without errors.' );
	}

	/**
	 * Boot() must not stack duplicate listeners when called again.
	 *
	 * @return void
	 */
	public function test_boot_is_idempotent(): void {
		$plugins_before = count( Harness::hooks( 'plugins_loaded' ) );
		$hpos_before    = count( Harness::hooks( 'before_woocommerce_init' ) );

		\StateFlow\Plugin::instance()->boot();
		\StateFlow\Plugin::instance()->boot();

		$this->assertSame( $plugins_before, count( Harness::hooks( 'plugins_loaded' ) ) );
		$this->assertSame( $hpos_before, count( Harness::hooks( 'before_woocommerce_init' ) ) );
	}

	/**
	 * With WooCommerce unavailable, firing plugins_loaded must not fatal and
	 * must register the requirements notice instead.
	 *
	 * @return void
	 */
	public function test_does_not_fatal_when_woocommerce_unavailable(): void {
		$this->assertFalse( class_exists( 'WooCommerce', false ) );

		Harness::fire( 'plugins_loaded' );

		$this->assertNotEmpty( Harness::hooks( 'admin_notices' ), 'A requirements notice must be registered when WooCommerce is unavailable.' );
	}

	/**
	 * The requirements notice is hidden on the frontend.
	 *
	 * @return void
	 */
	public function test_notice_is_hidden_on_frontend(): void {
		Harness::set_is_admin( false );

		ob_start();
		\StateFlow\Plugin::instance()->render_requirements_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The requirements notice is hidden from users without capability.
	 *
	 * @return void
	 */
	public function test_notice_is_hidden_for_unauthorized_users(): void {
		Harness::set_is_admin( true );
		Harness::set_user_can( false );

		ob_start();
		\StateFlow\Plugin::instance()->render_requirements_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * An authorized administrator gets a clear requirements notice.
	 *
	 * @return void
	 */
	public function test_notice_is_shown_for_authorized_admin(): void {
		Harness::set_is_admin( true );
		Harness::set_user_can( true );

		ob_start();
		\StateFlow\Plugin::instance()->render_requirements_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'WooCommerce', $output );
		$this->assertStringContainsString( Environment::MIN_WOOCOMMERCE, $output );
	}

	/**
	 * The HPOS declaration listener is registered at boot.
	 *
	 * @return void
	 */
	public function test_hpos_declaration_hook_is_registered(): void {
		$this->assertNotEmpty( Harness::hooks( 'before_woocommerce_init' ) );
	}

	/**
	 * Firing before_woocommerce_init declares HPOS compatibility for this
	 * plugin file, positively.
	 *
	 * @return void
	 */
	public function test_hpos_declaration_declares_custom_order_tables(): void {
		Harness::fire( 'before_woocommerce_init' );

		$this->assertSame(
			array(
				array( 'custom_order_tables', STATEFLOW_PLUGIN_FILE, true ),
			),
			FeaturesUtil::$calls
		);
	}
}
