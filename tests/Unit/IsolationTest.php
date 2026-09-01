<?php
/**
 * Test-isolation proofs (SF-001.1 item 3).
 *
 * These tests deliberately mutate harness state (fire lifecycle hooks,
 * register extra callbacks) and then verify the harness restores the
 * pristine baseline. Under randomized execution they prove that no test
 * depends on order: whatever a previous test did, setUp() sees the same
 * snapshot state.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use StateFlow\Tests\Harness;
use StateFlow\Tests\PluginLoader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Harness isolation guarantees.
 */
final class IsolationTest extends TestCase {

	/**
	 * Fresh baseline per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Harness::reset();
		PluginLoader::load();
		Harness::reset();
	}

	/**
	 * After firing plugins_loaded (which registers an admin_notices
	 * listener in degraded mode), the baseline restore removes it.
	 *
	 * @return void
	 */
	public function test_fired_lifecycle_state_does_not_leak(): void {
		Harness::fire( 'plugins_loaded' );

		$this->assertNotEmpty( Harness::hooks( 'admin_notices' ), 'Precondition: degraded mode registered the notice.' );

		Harness::reset();

		$this->assertEmpty( Harness::hooks( 'admin_notices' ), 'Reset must drop hooks registered by a previous test.' );
	}

	/**
	 * Extra callbacks registered directly by a test do not leak.
	 *
	 * @return void
	 */
	public function test_manually_registered_callbacks_do_not_leak(): void {
		add_action(
			'isolation_probe_hook',
			static function (): void {}
		);

		$this->assertNotEmpty( Harness::hooks( 'isolation_probe_hook' ) );

		Harness::reset();

		$this->assertEmpty( Harness::hooks( 'isolation_probe_hook' ), 'Reset must drop manually registered callbacks.' );
	}

	/**
	 * The pristine baseline is exactly the plugin's own post-boot state:
	 * the four bootstrap hooks and nothing else.
	 *
	 * @return void
	 */
	public function test_baseline_contains_only_bootstrap_state(): void {
		$hooks = is_array( $GLOBALS['sf_hooks'] ?? null ) ? array_keys( $GLOBALS['sf_hooks'] ) : array();

		sort( $hooks );

		$this->assertSame(
			array(
				'_stateflow_activation',
				'_stateflow_deactivation',
				'before_woocommerce_init',
				'plugins_loaded',
			),
			$hooks
		);
	}

	/**
	 * Stub globals reset even when a previous test changed them.
	 *
	 * @return void
	 */
	public function test_stub_globals_reset(): void {
		Harness::set_is_admin( false );
		Harness::set_user_can( false );
		Harness::set_wp_version( '1.0.0' );

		$this->assertFalse( is_admin() );
		$this->assertFalse( current_user_can( 'activate_plugins' ) );
		$this->assertSame( '1.0.0', $GLOBALS['wp_version'] );

		Harness::reset();

		$this->assertTrue( is_admin() );
		$this->assertTrue( current_user_can( 'activate_plugins' ) );
		$this->assertSame( '6.5.0', $GLOBALS['wp_version'] );
	}
}
