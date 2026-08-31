<?php
/**
 * Loads the real plugin main file exactly once per process.
 *
 * @package StateFlow\Tests
 */

declare( strict_types = 1 );

namespace StateFlow\Tests;

/**
 * Require stateflow.php once, from the repo root.
 */
final class PluginLoader {

	/**
	 * Whether the plugin file was already loaded in this process.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Load the plugin main file (no-op on subsequent calls).
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}

		require_once dirname( __DIR__ ) . '/stateflow.php';

		self::$loaded = true;
	}
}
