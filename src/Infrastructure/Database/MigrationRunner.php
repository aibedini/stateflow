<?php
/**
 * Safe schema installation/upgrade runner.
 *
 * Owns the whole migration lifecycle (SF-002 §11/§13/§14/§15/§16):
 *
 *   installed version < target?
 *     → acquire migration lock (atomic, stale-recoverable)
 *     → load wp-admin/includes/upgrade.php (migration path only)
 *     → run dbDelta with the target CREATE TABLE statements
 *     → verify structure (SchemaVerifier)
 *     → only on verified success: update stateflow_schema_version
 *     → release the lock token this process owns (finally)
 *
 * The fast path (installed == target) is read cached option → int compare →
 * return. No upgrade.php, no dbDelta, no SHOW TABLES/DESCRIBE/SHOW INDEX,
 * no lock write, no StateFlow-table queries (SF-002 §10/§28).
 *
 * Failure never fatals the storefront: the caller decides degraded-mode
 * behavior (SF-002 §16); the installed version stays untouched on failure.
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

use wpdb;

/**
 * Runs schema migrations.
 */
final class MigrationRunner {

	/**
	 * WordPress database abstraction.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Prefix-aware table names.
	 *
	 * @var TableNames
	 */
	private TableNames $names;

	/**
	 * Structural verifier.
	 *
	 * @var SchemaVerifier
	 */
	private SchemaVerifier $verifier;

	/**
	 * Lock owned by the current run.
	 *
	 * @var MigrationLock
	 */
	private MigrationLock $lock;

	/**
	 * Test seam: forces the verifier to fail after dbDelta, WITHOUT
	 * touching a real database. Production code never sets this.
	 *
	 * @var bool
	 */
	private bool $simulate_verification_failure = false;

	/**
	 * Runner constructor.
	 *
	 * @param wpdb $wpdb Database abstraction.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb     = $wpdb;
		$this->names    = TableNames::from_wpdb( $wpdb );
		$this->verifier = new SchemaVerifier( $wpdb, $this->names );
		$this->lock     = new MigrationLock();
	}

	/**
	 * The installed schema version; 0 when unset/unknown/malformed.
	 *
	 * @return int
	 */
	public function installed_version(): int {
		$installed = $this->read_installed_version();

		if ( null === $installed ) {
			return 0;
		}

		return max( 0, $installed );
	}

	/**
	 * Whether the installed schema version equals the target version.
	 * The per-request fast path: cached option read + integer comparison.
	 *
	 * @return bool
	 */
	public function is_current(): bool {
		return $this->installed_version() === Schema::VERSION;
	}

	/**
	 * Ensure the schema is current. Safe to call on every request: the
	 * current path is a cached option read plus an integer comparison.
	 *
	 * @return MigrationResult
	 */
	public function ensure_current(): MigrationResult {
		if ( $this->is_current() ) {
			return MigrationResult::already_current();
		}

		if ( ! $this->lock->acquire() ) {
			return MigrationResult::locked();
		}

		try {
			// Another request may have completed the migration while we
			// waited for the lock; re-check before doing any work. The
			// separate helper also keeps this call site free of the
			// stateful-stub false-positive "always false" inference.
			if ( $this->migration_still_needed() ) {
				$this->run_dbdelta();

				$errors = $this->simulate_verification_failure
					? array( 'simulated verification failure (test seam)' )
					: $this->verifier->verify();

				if ( array() !== $errors ) {
					// Verification failed: do NOT bump the installed version.
					return MigrationResult::failed( $errors );
				}

				$this->write_installed_version();

				return MigrationResult::migrated();
			}

			return MigrationResult::already_current();
		} finally {
			// Releases only the token this process owns.
			$this->lock->release();
		}
	}

	/**
	 * Whether the schema still needs migrating after the lock was won.
	 *
	 * @return bool
	 */
	private function migration_still_needed(): bool {
		return ! $this->is_current();
	}

	/**
	 * Test seam: force verification failure after dbDelta.
	 *
	 * @return void
	 */
	public function simulate_verification_failure(): void {
		$this->simulate_verification_failure = true;
	}

	/**
	 * Load upgrade.php and run dbDelta. Called only on an actual migration
	 * path — never during normal requests (SF-002 §12).
	 *
	 * @return void
	 */
	private function run_dbdelta(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( Schema::create_table_sql( $this->names, (string) $this->wpdb->get_charset_collate() ) );
	}

	/**
	 * Read the installed schema version option.
	 *
	 * @return int|null Null when unset/malformed.
	 */
	private function read_installed_version(): ?int {
		$installed = get_option( Schema::VERSION_OPTION );

		if ( ! is_string( $installed ) && ! is_int( $installed ) ) {
			return null;
		}

		return (int) $installed;
	}

	/**
	 * Persist the installed schema version (only after verified success).
	 *
	 * @return void
	 */
	private function write_installed_version(): void {
		$existing = get_option( Schema::VERSION_OPTION );

		if ( false === $existing || null === $existing ) {
			add_option( Schema::VERSION_OPTION, (string) Schema::VERSION, '', true );
		} else {
			update_option( Schema::VERSION_OPTION, (string) Schema::VERSION, true );
		}
	}
}
