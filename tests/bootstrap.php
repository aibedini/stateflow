<?php
/**
 * Unit-test bootstrap: minimal WordPress function stubs + Composer autoloader.
 *
 * These stubs implement only the WordPress surface StateFlow actually calls,
 * so the unit suite runs without a WordPress installation. Integration tests
 * (tests/Integration) use the real WordPress test suite instead and never
 * load this file.
 *
 * The WooCommerce-present process (tests/bootstrap-present.php) loads this
 * file first and then defines the WooCommerce class + WC_VERSION constant.
 *
 * @package StateFlow\Tests
 */

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/FeaturesUtil.php';
require_once __DIR__ . '/stubs/wpdb.php';
require_once __DIR__ . '/Harness.php';

$GLOBALS['sf_hooks']   = array();
$GLOBALS['sf_options'] = array();

/**
 * Stub of get_option(): reads the sf_options global (autoload simulation
 * is the caller's concern; all reads here come from the live map).
 *
 * @param string $option         Option name.
 * @param mixed  $fallback_value Value when absent.
 * @return mixed
 */
function get_option( string $option, $fallback_value = false ) {
	$options = is_array( $GLOBALS['sf_options'] ?? null ) ? $GLOBALS['sf_options'] : array();

	return array_key_exists( $option, $options ) ? $options[ $option ] : $fallback_value;
}

/**
 * Stub of add_option(): atomic-create semantics — fails when the option
 * already exists (this is the property the migration lock relies on).
 *
 * @param string $option   Option name.
 * @param mixed  $value    Value.
 * @param string $deprecated Unused.
 * @param mixed  $autoload Autoload flag (recorded; unused by the harness).
 * @return bool
 */
function add_option( string $option, $value, string $deprecated = '', $autoload = null ): bool {
	unset( $deprecated, $autoload );

	$options = is_array( $GLOBALS['sf_options'] ?? null ) ? $GLOBALS['sf_options'] : array();

	if ( array_key_exists( $option, $options ) ) {
		return false;
	}

	$options[ $option ] = $value;

	$GLOBALS['sf_options'] = $options;

	return true;
}

/**
 * Stub of update_option(): overwrites any existing option.
 *
 * @param string $option Option name.
 * @param mixed  $value  Value.
 * @param mixed  $unused Unused.
 * @return bool
 */
function update_option( string $option, $value, $unused = null ): bool {
	unset( $unused );

	$options            = is_array( $GLOBALS['sf_options'] ?? null ) ? $GLOBALS['sf_options'] : array();
	$options[ $option ] = $value;

	$GLOBALS['sf_options'] = $options;

	return true;
}

/**
 * Stub of delete_option().
 *
 * @param string $option Option name.
 * @return bool
 */
function delete_option( string $option ): bool {
	$options = is_array( $GLOBALS['sf_options'] ?? null ) ? $GLOBALS['sf_options'] : array();

	if ( ! array_key_exists( $option, $options ) ) {
		return false;
	}

	unset( $options[ $option ] );

	$GLOBALS['sf_options'] = $options;

	return true;
}

/**
 * Record a callback on a hook.
 *
 * @param string   $hook          Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Accepted argument count.
 * @return bool
 */
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$hooks          = is_array( $GLOBALS['sf_hooks'] ?? null ) ? $GLOBALS['sf_hooks'] : array();
	$hooks[ $hook ] = is_array( $hooks[ $hook ] ?? null ) ? $hooks[ $hook ] : array();

	$hooks[ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);

	$GLOBALS['sf_hooks'] = $hooks;

	return true;
}

/**
 * Record a callback on a filter (same registry as actions in this harness).
 *
 * @param string   $hook          Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Accepted argument count.
 * @return bool
 */
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_action( $hook, $callback, $priority, $accepted_args );
}

/**
 * Fire every callback registered on a hook.
 *
 * @param string $hook Hook name.
 * @param mixed  ...$args Arguments.
 * @return void
 */
function do_action( string $hook, ...$args ): void {
	$hooks   = is_array( $GLOBALS['sf_hooks'] ?? null ) ? $GLOBALS['sf_hooks'] : array();
	$entries = $hooks[ $hook ] ?? array();

	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$callback      = $entry['callback'] ?? null;
		$accepted      = $entry['accepted_args'] ?? 1;
		$accepted_args = is_int( $accepted ) ? $accepted : 1;

		if ( ! is_callable( $callback ) ) {
			continue;
		}

		call_user_func_array( $callback, array_slice( $args, 0, $accepted_args ) );
	}
}

/**
 * Record an activation callback under a synthetic hook.
 *
 * @param string   $file     Plugin file (ignored by the harness).
 * @param callable $callback Callback.
 * @return void
 */
function register_activation_hook( $file, callable $callback ): void {
	add_action( '_stateflow_activation', $callback );
}

/**
 * Record a deactivation callback under a synthetic hook.
 *
 * @param string   $file     Plugin file (ignored by the harness).
 * @param callable $callback Callback.
 * @return void
 */
function register_deactivation_hook( $file, callable $callback ): void {
	add_action( '_stateflow_deactivation', $callback );
}

/**
 * Stub of is_admin(): configurable via the global $is_admin.
 *
 * @return bool
 */
function is_admin(): bool {
	return (bool) ( $GLOBALS['is_admin'] ?? true );
}

/**
 * Stub of current_user_can(): configurable via the sf_user_can global.
 *
 * @param string $capability Capability name (ignored by the harness).
 * @return bool
 */
function current_user_can( string $capability ): bool {
	unset( $capability );

	return (bool) ( $GLOBALS['sf_user_can'] ?? true );
}

/**
 * Stub of esc_html().
 *
 * @param string $text Text to escape.
 * @return string
 */
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

/**
 * Stub of __().
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 * @return string
 */
function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

/**
 * Stub of esc_html__().
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 * @return string
 */
function esc_html__( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

/**
 * Minimal stub of get_file_data(): parses " * Header: value" lines.
 * Mirrors real WordPress semantics: default_headers is field => header name,
 * and the returned array is keyed by field.
 *
 * @param string                $file            File path.
 * @param array<string, string> $default_headers Field => header name.
 * @param string                $context         Context (unused).
 * @return array<string, string>
 */
function get_file_data( string $file, array $default_headers, string $context = '' ): array {
	unset( $context );

	$content = file_get_contents( $file );
	$content = is_string( $content ) ? $content : '';
	$data    = array();

	foreach ( $default_headers as $field => $header ) {
		if ( preg_match( '/^[ \t]*\*[ \t]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+)$/mi', $content, $matches ) ) {
			$data[ $field ] = trim( $matches[1] );
		} else {
			$data[ $field ] = '';
		}
	}

	return $data;
}
