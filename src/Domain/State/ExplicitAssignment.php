<?php
/**
 * ExplicitAssignment — the pure read model of one persisted assignment row.
 *
 * Represents exactly ONE explicit StateFlow assignment (object_id → state)
 * as stored in {$wpdb->prefix}stateflow_assignments (SF-003A §1). It is the
 * read-side complement of "absence is meaningful": this object exists only
 * when an explicit row exists.
 *
 * This model does NOT determine whether the object is a product, a
 * variation, deleted, or a valid WooCommerce product — those concerns
 * belong to higher layers (the later EffectiveStateResolver receives that
 * context separately). Deliberately minimal: the hot-path resolver needs
 * object_id, state_id and version — nothing else.
 *
 * @package StateFlow\Domain\State
 */

declare( strict_types = 1 );

namespace StateFlow\Domain\State;

/**
 * Immutable explicit assignment read model.
 */
final class ExplicitAssignment {

	/**
	 * The WordPress object ID (products and variations share the ID space).
	 *
	 * @var int
	 */
	private int $object_id;

	/**
	 * The assigned StateFlow state persistence ID.
	 *
	 * @var int
	 */
	private int $state_id;

	/**
	 * Optimistic-concurrency version of the assignment row (>= 1).
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Private: use create().
	 *
	 * @param int $object_id Positive object ID.
	 * @param int $state_id  Positive state ID.
	 * @param int $version   Row version >= 1.
	 */
	private function __construct( int $object_id, int $state_id, int $version ) {
		$this->object_id = $object_id;
		$this->state_id  = $state_id;
		$this->version   = $version;
	}

	/**
	 * Create a validated explicit assignment.
	 *
	 * @param int $object_id Positive object ID.
	 * @param int $state_id  Positive state ID.
	 * @param int $version   Row version (>= 1).
	 * @return self
	 * @throws DomainException On any invariant violation.
	 */
	public static function create( int $object_id, int $state_id, int $version ): self {
		if ( $object_id < 1 ) {
			throw new DomainException( 'Assignment object ID must be a positive integer.' );
		}

		if ( $state_id < 1 ) {
			throw new DomainException( 'Assignment state ID must be a positive integer.' );
		}

		if ( $version < 1 ) {
			throw new DomainException( 'Assignment version must be an integer >= 1.' );
		}

		return new self( $object_id, $state_id, $version );
	}

	/**
	 * The object ID.
	 *
	 * @return int
	 */
	public function object_id(): int {
		return $this->object_id;
	}

	/**
	 * The assigned state persistence ID.
	 *
	 * @return int
	 */
	public function state_id(): int {
		return $this->state_id;
	}

	/**
	 * The row version.
	 *
	 * @return int
	 */
	public function version(): int {
		return $this->version;
	}
}
