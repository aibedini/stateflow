<?php
/**
 * Explicit migration result (SF-002 §14).
 *
 * DbDelta() returning is not success: callers must branch on this
 * result — the installed schema version is only bumped after
 * structural verification passes.
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

/**
 * Immutable migration outcome.
 */
final class MigrationResult {

	/**
	 * Whether the schema is now current (either it already was, or the
	 * migration ran and verified successfully).
	 *
	 * @var bool
	 */
	private bool $success = false;

	/**
	 * True when the fast path determined the schema was already current
	 * (no migration work was attempted).
	 *
	 * @var bool
	 */
	private bool $already_current = false;

	/**
	 * True when the migration could not start because another request
	 * holds a live migration lock.
	 *
	 * @var bool
	 */
	private bool $locked = false;

	/**
	 * True when no usable database layer was available (stub-based test
	 * harnesses). Never occurs in real WordPress.
	 *
	 * @var bool
	 */
	private bool $db_unavailable = false;

	/**
	 * Human-readable verification/setup errors (bounded set).
	 *
	 * @var array<int, string>
	 */
	private array $errors = array();

	/**
	 * Migration outcome constructor.
	 *
	 * @param bool               $success         Schema current after this call.
	 * @param bool               $already_current Fast path (no work needed).
	 * @param bool               $locked          Blocked by a concurrent migration.
	 * @param bool               $db_unavailable  No usable database layer.
	 * @param array<int, string> $errors          Error details (empty on success).
	 */
	private function __construct( bool $success, bool $already_current, bool $locked, bool $db_unavailable, array $errors ) {
		$this->success         = $success;
		$this->already_current = $already_current;
		$this->locked          = $locked;
		$this->db_unavailable  = $db_unavailable;
		$this->errors          = $errors;
	}

	/**
	 * The schema was already at the target version; nothing ran.
	 *
	 * @return self
	 */
	public static function already_current(): self {
		return new self( true, true, false, false, array() );
	}

	/**
	 * Migration ran and verified successfully.
	 *
	 * @return self
	 */
	public static function migrated(): self {
		return new self( true, false, false, false, array() );
	}

	/**
	 * Blocked by a live lock held by another request.
	 *
	 * @return self
	 */
	public static function locked(): self {
		return new self( false, false, true, false, array() );
	}

	/**
	 * No usable database layer (stub-based harnesses only).
	 *
	 * @return self
	 */
	public static function db_unavailable(): self {
		return new self( false, false, false, true, array() );
	}

	/**
	 * Migration ran but failed verification; the installed schema version
	 * was NOT updated.
	 *
	 * @param array<int, string> $errors Structural problems found.
	 * @return self
	 */
	public static function failed( array $errors ): self {
		return new self( false, false, false, false, $errors );
	}

	/**
	 * Whether the schema is current.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Fast-path flag (no work was performed).
	 *
	 * @return bool
	 */
	public function was_already_current(): bool {
		return $this->already_current;
	}

	/**
	 * Lock-contention flag.
	 *
	 * @return bool
	 */
	public function was_locked(): bool {
		return $this->locked;
	}

	/**
	 * Whether no usable database layer was available.
	 *
	 * @return bool
	 */
	public function was_db_unavailable(): bool {
		return $this->db_unavailable;
	}

	/**
	 * Verification/setup errors (empty on success).
	 *
	 * @return array<int, string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
