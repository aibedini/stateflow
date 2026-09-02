<?php
/**
 * Typed accessors for the unit-harness option-map global.
 *
 * Helper fixture shared by option-stub unit tests (e.g. MigrationLockTest).
 * Lives in its own file: one object structure per file (WPCS).
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use StateFlow\Infrastructure\Database\MigrationLock;

/**
 * Static-analysis-friendly accessor for $GLOBALS['sf_options'].
 */
final class LockFixture {

	/**
	 * The full option map, re-keyed as string=>mixed from the harness
	 * global (built by the string-keyed add_option()/update_option()
	 * stubs in tests/bootstrap.php, so the shape is guaranteed).
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$options = $GLOBALS['sf_options'] ?? null;

		if ( ! is_array( $options ) ) {
			return array();
		}

		// Re-key explicitly: proves/keeps the string-keyed contract for
		// static analysis instead of trusting the mixed global shape.
		$typed = array();

		foreach ( $options as $name => $value ) {
			$typed[ (string) $name ] = $value;
		}

		return $typed;
	}

	/**
	 * One stored option value (or null).
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public static function get( string $name ) {
		$all = self::all();

		return $all[ $name ] ?? null;
	}

	/**
	 * Overwrite one option directly.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function set( string $name, $value ): void {
		$all          = self::all();
		$all[ $name ] = $value;

		$GLOBALS['sf_options'] = $all;
	}

	/**
	 * Backdate the lock's acquisition timestamp by N seconds.
	 *
	 * @param int $seconds Seconds to backdate.
	 * @return void
	 */
	public static function backdate_lock( int $seconds ): void {
		$stored = self::lock_payload();

		if ( null === $stored ) {
			return;
		}

		$acquired_at = $stored['acquired_at'] ?? 0;

		if ( ! is_numeric( $acquired_at ) ) {
			return;
		}

		$stored['acquired_at'] = (int) $acquired_at - $seconds;

		self::set( MigrationLock::OPTION, $stored );
	}

	/**
	 * The stored lock payload (typed array), or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function lock_payload(): ?array {
		$stored = self::get( MigrationLock::OPTION );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		// Same explicit re-key contract as all().
		$typed = array();

		foreach ( $stored as $key => $value ) {
			$typed[ (string) $key ] = $value;
		}

		return $typed;
	}
}
