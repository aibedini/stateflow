<?php
/**
 * StateDefinitionRepository — the WordPress-independent read contract for
 * State definitions (SF-003A §3).
 *
 * Strictly the SF-003A consumer contract: four batch-safe read methods.
 * No update/delete, no list_all() "for later", no write surface. Return
 * maps OMIT missing rows (missing row is a normal result, never an
 * exception).
 *
 * @package StateFlow\Domain\State
 */

declare( strict_types = 1 );

namespace StateFlow\Domain\State;

/**
 * Read-only State definition repository contract.
 */
interface StateDefinitionRepository {

	/**
	 * One definition by persistence ID.
	 *
	 * @param int $id Persistence ID (positive).
	 * @return StateDefinition|null Null when no row matches.
	 * @throws StatePersistenceException On query/hydration failure.
	 */
	public function find_by_id( int $id ): ?StateDefinition;

	/**
	 * One definition by canonical key.
	 *
	 * @param StateKey $key Canonical state key.
	 * @return StateDefinition|null Null when no row matches.
	 * @throws StatePersistenceException On query/hydration failure.
	 */
	public function find_by_key( StateKey $key ): ?StateDefinition;

	/**
	 * Definitions by persistence IDs (batch-safe, bounded IN queries).
	 *
	 * @param array<int, int> $ids Persistence IDs (positive integers;
	 *                            duplicates de-duplicated).
	 * @return array<int, StateDefinition> Map keyed by persistence ID;
	 *                                     missing IDs are omitted.
	 * @throws StatePersistenceException On query/hydration failure or
	 *                                   invalid input.
	 */
	public function find_by_ids( array $ids ): array;

	/**
	 * Definitions by keys (batch-safe, bounded IN queries).
	 *
	 * @param array<int, StateKey> $keys StateKey instances (duplicates
	 *                                   de-duplicated).
	 * @return array<string, StateDefinition> Map keyed by canonical key
	 *                                        string; missing keys omitted.
	 * @throws StatePersistenceException On query/hydration failure or
	 *                                   invalid input.
	 */
	public function find_by_keys( array $keys ): array;
}
