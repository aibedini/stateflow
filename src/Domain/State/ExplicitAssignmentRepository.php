<?php
/**
 * ExplicitAssignmentRepository — the WordPress-independent read contract
 * for explicit assignments (SF-003A §4).
 *
 * Strictly READ-ONLY: no insert/upsert/save/update/delete/assign/
 * transition surface exists here or ever will — all assignment mutation
 * belongs to a future TransitionService. Answers exactly one question:
 * "Does StateFlow hold an explicit assignment row for this object ID?"
 *
 * Nothing about products, variations, parents, post types or object
 * existence is known or checked here (SF-003A §15).
 *
 * @package StateFlow\Domain\State
 */

declare( strict_types = 1 );

namespace StateFlow\Domain\State;

/**
 * Read-only explicit assignment repository contract.
 */
interface ExplicitAssignmentRepository {

	/**
	 * The explicit assignment for one object ID.
	 *
	 * @param int $object_id Positive object ID.
	 * @return ExplicitAssignment|null Null when no row matches.
	 * @throws StatePersistenceException On query/hydration failure.
	 */
	public function find( int $object_id ): ?ExplicitAssignment;

	/**
	 * Explicit assignments for many object IDs (batch-safe, bounded IN
	 * queries, no N+1).
	 *
	 * @param array<int, int> $object_ids Positive object IDs (duplicates
	 *                                    de-duplicated).
	 * @return array<int, ExplicitAssignment> Map keyed by object ID;
	 *                                        missing objects omitted.
	 * @throws StatePersistenceException On query/hydration failure or
	 *                                   invalid input.
	 */
	public function find_many( array $object_ids ): array;
}
