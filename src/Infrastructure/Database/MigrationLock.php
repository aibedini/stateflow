<?php
/**
 * Lightweight StateFlow-owned migration lock.
 *
 * Prevents two concurrent requests from running the same migration at the
 * same time (SF-002 §15). Safety properties, stated precisely (SF-002.1 §4):
 *
 * - initial acquisition uses atomic add_option() semantics
 * - stale recovery (delete + re-add) can race and is NOT a single atomic
 *   replace; only one successful add_option() owns the current lock after
 *   a given vacancy
 * - migrations remain idempotent, so contention after a race is harmless
 * - release() deletes only the matching owned token
 * - the option is NOT autoloaded; the stale timeout is finite
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

/**
 * Migration lock via WordPress options.
 */
final class MigrationLock {

	/**
	 * Lock option name.
	 *
	 * @var string
	 */
	public const OPTION = 'stateflow_migration_lock';

	/**
	 * Default stale timeout in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_STALE_SECONDS = 60;

	/**
	 * The token owned by this process (null when no lock is held).
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Try to acquire the lock, recovering a stale lock when safe.
	 *
	 * @param int $stale_seconds Finite stale timeout.
	 * @return bool True when this process holds the lock.
	 */
	public function acquire( int $stale_seconds = self::DEFAULT_STALE_SECONDS ): bool {
		$token = bin2hex( random_bytes( 16 ) );

		// Atomic initial acquisition: add_option() fails when the option
		// already exists, which is exactly the "someone holds it" signal.
		if ( $this->try_add( $token ) ) {
			$this->token = $token;

			return true;
		}

		// Option exists: is it a live lock from someone else?
		if ( ! $this->is_stale( $stale_seconds ) ) {
			return false;
		}

		// Stale lock: recover by deleting and re-acquiring. This sequence
		// can race — it is not a single atomic replace — but only one
		// successful add_option() can own the current lock after the
		// vacancy, and dbDelta is idempotent, so a lost race is harmless.
		$this->force_delete();

		if ( $this->try_add( $token ) ) {
			$this->token = $token;

			return true;
		}

		return false;
	}

	/**
	 * Whether a stored lock is stale (or absent).
	 *
	 * @param int $stale_seconds Finite stale timeout.
	 * @return bool
	 */
	public function is_stale( int $stale_seconds ): bool {
		$acquired_at = $this->stored_acquired_at();

		if ( null === $acquired_at ) {
			return true;
		}

		return ( time() - $acquired_at ) >= $stale_seconds;
	}

	/**
	 * Release the lock. Only the process holding the matching token may
	 * release it; a recovered/replaced lock is never deleted by the
	 * previous owner.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( null === $this->token ) {
			return;
		}

		$stored = $this->stored_payload();

		if ( is_array( $stored ) && ( $stored['token'] ?? null ) === $this->token ) {
			$this->force_delete();
		}

		$this->token = null;
	}

	/**
	 * The token this process currently holds (or null).
	 *
	 * @return string|null
	 */
	public function held_token(): ?string {
		return $this->token;
	}

	/**
	 * Atomic add of a fresh lock payload.
	 *
	 * @param string $token Unique token.
	 * @return bool True when the option was created by this call.
	 */
	private function try_add( string $token ): bool {
		return (bool) add_option( self::OPTION, $this->payload( $token ), '', false );
	}

	/**
	 * Unconditional delete (stale-lock recovery path).
	 *
	 * @return void
	 */
	private function force_delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The stored lock payload.
	 *
	 * @return mixed
	 */
	private function stored_payload() {
		return get_option( self::OPTION );
	}

	/**
	 * The stored acquisition timestamp; null when absent/malformed.
	 *
	 * @return int|null
	 */
	private function stored_acquired_at(): ?int {
		$stored = $this->stored_payload();

		if ( ! is_array( $stored ) || ! isset( $stored['acquired_at'] ) || ! is_numeric( $stored['acquired_at'] ) ) {
			return null;
		}

		return (int) $stored['acquired_at'];
	}

	/**
	 * Lock payload: unique token + acquisition timestamp.
	 *
	 * @param string $token Token.
	 * @return array{token: string, acquired_at: int}
	 */
	private function payload( string $token ): array {
		return array(
			'token'       => $token,
			'acquired_at' => time(),
		);
	}
}
