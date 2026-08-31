<?php
/**
 * Test harness helpers: manipulate the WordPress stub globals.
 *
 * @package StateFlow\Tests
 */

declare( strict_types = 1 );

namespace StateFlow\Tests;

/**
 * Static helpers around the WP stub globals defined in tests/bootstrap.php.
 */
final class Harness {

	/**
	 * Reset every stub global to its default state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$GLOBALS['is_admin']     = true;
		$GLOBALS['sf_user_can']  = true;
		$GLOBALS['wp_version']   = '6.5.0';
		$GLOBALS['table_prefix'] = 'wptests_';

		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::$calls = array();
		}
	}

	/**
	 * Callbacks registered on a hook.
	 *
	 * @param string $hook Hook name.
	 * @return array<int, mixed>
	 */
	public static function hooks( string $hook ): array {
		$hooks = is_array( $GLOBALS['sf_hooks'] ?? null ) ? $GLOBALS['sf_hooks'] : array();
		$found = $hooks[ $hook ] ?? array();

		return is_array( $found ) ? array_values( $found ) : array();
	}

	/**
	 * Fire a hook through the stub do_action().
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	public static function fire( string $hook, ...$args ): void {
		do_action( $hook, ...$args );
	}

	/**
	 * Set the global $is_admin flag consumed by the stub.
	 *
	 * @param bool $value Result.
	 * @return void
	 */
	public static function set_is_admin( bool $value ): void {
		$GLOBALS['is_admin'] = $value;
	}

	/**
	 * Set the current_user_can() stub result.
	 *
	 * @param bool $value Result.
	 * @return void
	 */
	public static function set_user_can( bool $value ): void {
		$GLOBALS['sf_user_can'] = $value;
	}

	/**
	 * Set the reported WordPress version (the real core global).
	 *
	 * @param string $version Version string.
	 * @return void
	 */
	public static function set_wp_version( string $version ): void {
		$GLOBALS['wp_version'] = $version;
	}
}
