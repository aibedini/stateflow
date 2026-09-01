<?php
/**
 * Environment guard: supported-stack detection and version policy.
 *
 * Single source of truth for the minimum supported versions at runtime.
 * The plugin header (stateflow.php) mirrors these values for wp.org
 * metadata; keep the two in sync when bumping.
 *
 * @package StateFlow\Infrastructure
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure;

/**
 * Version policy for the runtime environment.
 */
final class Environment {

	/**
	 * Minimum supported PHP version.
	 */
	public const MIN_PHP = '8.0';

	/**
	 * Minimum supported WordPress version.
	 */
	public const MIN_WORDPRESS = '6.4';

	/**
	 * Minimum supported WooCommerce version.
	 */
	public const MIN_WOOCOMMERCE = '8.0';

	/**
	 * Whether the current environment meets all requirements.
	 *
	 * Every unknown version fails closed: if a requirement cannot be
	 * verified, the plugin degrades to the notice path instead of running.
	 *
	 * @param array<string, string|null>|null $env Optional explicit environment
	 *                                              (php/wp/wc version strings)
	 *                                              for testing; null detects
	 *                                              the real environment.
	 * @return bool
	 */
	public static function is_supported( ?array $env = null ): bool {
		if ( null === $env ) {
			return self::meets_php()
				&& self::meets_wordpress()
				&& self::meets_woocommerce();
		}

		return self::meets( (string) ( $env['php'] ?? '' ), self::MIN_PHP )
			&& self::meets( (string) ( $env['wp'] ?? '' ), self::MIN_WORDPRESS )
			&& self::meets( (string) ( $env['wc'] ?? '' ), self::MIN_WOOCOMMERCE );
	}

	/**
	 * Whether WooCommerce is available: the plugin class must genuinely be
	 * loaded. A bare WC_VERSION constant (or a class defined by unrelated
	 * code) alone never satisfies presence. The `false` flag keeps this
	 * deterministic by never triggering an autoloader.
	 *
	 * @return bool
	 */
	public static function has_woocommerce(): bool {
		return class_exists( 'WooCommerce', false );
	}

	/**
	 * Whether the current PHP runtime meets the minimum.
	 *
	 * @return bool
	 */
	public static function meets_php(): bool {
		return self::meets( PHP_VERSION, self::MIN_PHP );
	}

	/**
	 * Whether the current WordPress meets the minimum.
	 *
	 * @return bool
	 */
	public static function meets_wordpress(): bool {
		return self::meets( self::global_string( 'wp_version' ), self::MIN_WORDPRESS );
	}

	/**
	 * Whether the current WooCommerce meets the minimum.
	 *
	 * Requires a genuinely loaded WooCommerce (class) plus a readable
	 * version constant; either signal alone is not enough (SF-001.1 item 8).
	 *
	 * @return bool
	 */
	public static function meets_woocommerce(): bool {
		if ( ! self::has_woocommerce() || ! defined( 'WC_VERSION' ) ) {
			return false;
		}

		return self::meets( (string) constant( 'WC_VERSION' ), self::MIN_WOOCOMMERCE );
	}

	/**
	 * Compare one version against one minimum. Unknown or empty versions
	 * fail closed.
	 *
	 * @param string $version Reported version (empty means unknown).
	 * @param string $minimum Minimum supported version.
	 * @return bool
	 */
	private static function meets( string $version, string $minimum ): bool {
		if ( '' === $version ) {
			return false;
		}

		return version_compare( $version, $minimum, '>=' );
	}

	/**
	 * Read a global as a string; non-string or missing values read as empty.
	 *
	 * @param string $key Global name.
	 * @return string
	 */
	private static function global_string( string $key ): string {
		$value = $GLOBALS[ $key ] ?? null;

		return is_string( $value ) ? $value : '';
	}
}
