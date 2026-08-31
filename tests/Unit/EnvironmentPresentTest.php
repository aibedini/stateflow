<?php
/**
 * Unit tests for the Environment guard (process with WooCommerce present).
 *
 * WooCommerce presence is simulated the way the real plugin sees it: a
 * `WooCommerce` class plus the `WC_VERSION` constant (tests/bootstrap-present.php).
 * Because class_exists() is cached per process, every WooCommerce-present test
 * MUST live in this file; it runs as its own PHPUnit process (see
 * phpunit-present.xml.dist).
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use StateFlow\Infrastructure\Environment;
use StateFlow\Tests\Harness;
use StateFlow\Tests\PluginLoader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Environment guard with WooCommerce present.
 */
final class EnvironmentPresentTest extends TestCase {

	/**
	 * Fresh stub state per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Harness::reset();
	}

	/**
	 * Suite-wiring guard: this process must really have WooCommerce.
	 *
	 * @return void
	 */
	public function test_woocommerce_is_present_in_this_process(): void {
		// Value check proves bootstrap-present.php actually ran; PHPStan
		// would flag class_exists()/defined() assertions as always-true.
		$this->assertSame( '9.2.0', WC_VERSION );
	}

	/**
	 * WooCommerce present -> has_woocommerce() true.
	 *
	 * @return void
	 */
	public function test_has_woocommerce_is_true_when_present(): void {
		$this->assertTrue( Environment::has_woocommerce() );
	}

	/**
	 * Real detection path: present + current versions -> supported.
	 *
	 * @return void
	 */
	public function test_is_supported_with_real_detection(): void {
		$this->assertTrue( Environment::is_supported() );
	}

	/**
	 * The version policy still applies when WooCommerce is present.
	 *
	 * @return void
	 */
	public function test_is_unsupported_when_woocommerce_version_below_minimum(): void {
		$this->assertFalse(
			Environment::is_supported(
				array(
					'php' => '8.1.2',
					'wp'  => '6.5.0',
					'wc'  => '7.0.0',
				)
			)
		);
	}

	/**
	 * WooCommerce meets the minimum when the constant reports it.
	 *
	 * @return void
	 */
	public function test_meets_woocommerce_true_when_version_sufficient(): void {
		$this->assertTrue( Environment::meets_woocommerce() );
	}

	/**
	 * The plugin bootstraps when WooCommerce is active: plugins_loaded leads
	 * to full initialization and NO requirements notice.
	 *
	 * @return void
	 */
	public function test_bootstrap_completes_without_notice_when_woocommerce_active(): void {
		PluginLoader::load();

		Harness::fire( 'plugins_loaded' );

		$this->assertSame( array(), Harness::hooks( 'admin_notices' ), 'No requirements notice may be registered when WooCommerce is supported.' );
	}
}
