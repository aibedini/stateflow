<?php
/**
 * Unit tests for the Environment guard (process without WooCommerce).
 *
 * This file runs in a PHPUnit process where the `WooCommerce` class and the
 * `WC_VERSION` constant are never defined (see phpunit.xml.dist). The version
 * policy matrix is tested through the pure is_supported( $env ) seam, so the
 * tests do not depend on real WooCommerce presence.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use StateFlow\Infrastructure\Environment;
use StateFlow\Tests\Harness;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Environment guard: absence + pure version policy.
 */
final class EnvironmentTest extends TestCase {

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
	 * Suite-wiring guard: this process must really be WooCommerce-free.
	 *
	 * @return void
	 */
	public function test_woocommerce_is_absent_in_this_process(): void {
		$this->assertFalse( class_exists( 'WooCommerce', false ), 'EnvironmentTest must run in a process without the WooCommerce class.' );
		$this->assertFalse( defined( 'WC_VERSION' ), 'EnvironmentTest must run in a process without WC_VERSION.' );
	}

	/**
	 * WooCommerce absent -> has_woocommerce() false.
	 *
	 * @return void
	 */
	public function test_has_woocommerce_is_false_when_absent(): void {
		$this->assertFalse( Environment::has_woocommerce() );
	}

	/**
	 * WooCommerce absent -> unsupported, and importantly: not fatal.
	 *
	 * @return void
	 */
	public function test_is_unsupported_when_woocommerce_is_absent(): void {
		$this->assertFalse( Environment::is_supported() );
	}

	/**
	 * Everything above the minimums -> supported.
	 *
	 * @return void
	 */
	public function test_is_supported_with_versions_above_minimums(): void {
		$this->assertTrue(
			Environment::is_supported(
				array(
					'php' => '8.1.2',
					'wp'  => '6.5.0',
					'wc'  => '8.0.0',
				)
			)
		);
	}

	/**
	 * PHP below the minimum -> unsupported.
	 *
	 * @return void
	 */
	public function test_is_unsupported_below_php_minimum(): void {
		$this->assertFalse(
			Environment::is_supported(
				array(
					'php' => '7.4.33',
					'wp'  => '6.5.0',
					'wc'  => '8.0.0',
				)
			)
		);
	}

	/**
	 * WordPress below the minimum -> unsupported.
	 *
	 * @return void
	 */
	public function test_is_unsupported_below_wordpress_minimum(): void {
		$this->assertFalse(
			Environment::is_supported(
				array(
					'php' => '8.1.2',
					'wp'  => '6.3.2',
					'wc'  => '8.0.0',
				)
			)
		);
	}

	/**
	 * WooCommerce below the minimum -> unsupported.
	 *
	 * @return void
	 */
	public function test_is_unsupported_below_woocommerce_minimum(): void {
		$this->assertFalse(
			Environment::is_supported(
				array(
					'php' => '8.1.2',
					'wp'  => '6.5.0',
					'wc'  => '7.3.0',
				)
			)
		);
	}

	/**
	 * WooCommerce present but version unknown -> unsupported (cannot verify).
	 *
	 * @return void
	 */
	public function test_is_unsupported_when_woocommerce_version_unknown(): void {
		$this->assertFalse(
			Environment::is_supported(
				array(
					'php' => '8.1.2',
					'wp'  => '6.5.0',
					'wc'  => null,
				)
			)
		);
	}

	/**
	 * WordPress version unknown -> unsupported.
	 *
	 * @return void
	 */
	public function test_is_unsupported_when_wordpress_version_unknown(): void {
		$this->assertFalse(
			Environment::is_supported(
				array(
					'php' => '8.1.2',
					'wp'  => null,
					'wc'  => '8.0.0',
				)
			)
		);
	}

	/**
	 * Empty version strings -> unsupported, not a false positive.
	 *
	 * @return void
	 */
	public function test_is_unsupported_with_empty_version_string(): void {
		$this->assertFalse(
			Environment::is_supported(
				array(
					'php' => '8.1.2',
					'wp'  => '',
					'wc'  => '8.0.0',
				)
			)
		);
	}

	/**
	 * Exact minimums -> supported (>= comparison).
	 *
	 * @return void
	 */
	public function test_exact_minimums_are_supported(): void {
		$this->assertTrue(
			Environment::is_supported(
				array(
					'php' => Environment::MIN_PHP,
					'wp'  => Environment::MIN_WORDPRESS,
					'wc'  => Environment::MIN_WOOCOMMERCE,
				)
			)
		);
	}

	/**
	 * PHP requirement met on the test runtime.
	 *
	 * @return void
	 */
	public function test_meets_php_on_current_runtime(): void {
		$this->assertTrue( Environment::meets_php() );
	}

	/**
	 * WordPress check follows the wp_version global.
	 *
	 * @return void
	 */
	public function test_meets_wordpress_follows_global_version(): void {
		Harness::set_wp_version( '6.3.0' );
		$this->assertFalse( Environment::meets_wordpress() );

		Harness::set_wp_version( '6.5.0' );
		$this->assertTrue( Environment::meets_wordpress() );
	}
}
