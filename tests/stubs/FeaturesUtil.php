<?php
/**
 * Test stub of WooCommerce FeaturesUtil for the HPOS declaration path.
 *
 * Loaded only by the unit-test bootstrap. Records declare_compatibility()
 * calls for assertions.
 *
 * @package StateFlow\Tests
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Utilities;

/**
 * Recorded declare_compatibility() calls for assertions.
 */
class FeaturesUtil {

	/**
	 * Calls captured by the test harness.
	 *
	 * @var array<int, array<int, bool|string>>
	 */
	public static array $calls = array();

	/**
	 * Record a compatibility declaration instead of touching WooCommerce.
	 *
	 * @param string $feature     Feature name.
	 * @param string $plugin_file Plugin file.
	 * @param bool   $positive    Positive/negative declaration.
	 * @return void
	 */
	public static function declare_compatibility( $feature, $plugin_file, $positive = true ): void {
		self::$calls[] = array( $feature, $plugin_file, $positive );
	}
}
