<?php
/**
 * Loads the real plugin main file exactly once per process and snapshots the
 * pristine post-boot state (SF-001.1 item 3).
 *
 * The snapshot gives Harness::reset() a deterministic baseline: every test —
 * in any execution order — starts from the exact hook/globals state the
 * plugin had right after boot(), no matter what previous tests fired.
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
	 * Load the plugin main file (no-op on subsequent calls) and snapshot the
	 * pristine post-boot state for Harness::reset().
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( self::$loaded ) {
			return;
		}

		require_once dirname( __DIR__ ) . '/stateflow.php';

		self::$loaded = true;

		// Pristine baseline: hooks registered by the bootstrap itself, before
		// any test fired anything. Test-side state, never a production API.
		$GLOBALS['sf_hooks_snapshot'] = is_array( $GLOBALS['sf_hooks'] ?? null ) ? $GLOBALS['sf_hooks'] : array();
	}

	/**
	 * Whether the plugin file was already loaded in this process.
	 *
	 * @return bool
	 */
	public static function is_loaded(): bool {
		return self::$loaded;
	}
}
