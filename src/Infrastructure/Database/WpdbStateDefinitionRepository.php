<?php
/**
 * WpdbStateDefinitionRepository — wpdb-backed read implementation of the
 * StateDefinitionRepository contract (SF-003A §5/§6/§8-§13).
 *
 * Discipline:
 * - table identifiers ONLY from the trusted TableNames resolver
 * - every value parameter through $wpdb->prepare() (local-variable form
 *   satisfies the WPCS prepared-SQL sniff)
 * - exact field SELECT (no SELECT *, no timestamps the model lacks)
 * - hydration strictly through StateKey/StateDefinition invariants; a
 *   violating row raises StatePersistenceException (never normalized)
 * - bounded batching (internal BATCH_SIZE, no unbounded IN lists, no N+1)
 * - empty input => zero SQL
 * - duplicate identity inside one result set => explicit failure
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

use StateFlow\Domain\State\DomainException;
use StateFlow\Domain\State\StateDefinition;
use StateFlow\Domain\State\StateDefinitionRepository;
use StateFlow\Domain\State\StateKey;
use StateFlow\Domain\State\StatePersistenceException;
use wpdb;

/**
 * Read-only State definition repository on wpdb.
 */
final class WpdbStateDefinitionRepository implements StateDefinitionRepository {

	/**
	 * Internal batch size for IN (...) lists (SF-003A §9).
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 500;

	/**
	 * WordPress database abstraction.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Trusted table-name resolver.
	 *
	 * @var TableNames
	 */
	private TableNames $names;

	/**
	 * Constructor.
	 *
	 * @param wpdb       $wpdb  Database abstraction.
	 * @param TableNames $names Trusted table names.
	 */
	public function __construct( wpdb $wpdb, TableNames $names ) {
		$this->wpdb  = $wpdb;
		$this->names = $names;
	}

	/**
	 * One definition by persistence ID.
	 *
	 * @param int $id Persistence ID.
	 * @return StateDefinition|null
	 * @throws StatePersistenceException On invalid input or failure.
	 */
	public function find_by_id( int $id ): ?StateDefinition {
		$results = $this->find_by_ids( array( $id ) );

		return $results[ $id ] ?? null;
	}

	/**
	 * One definition by canonical key.
	 *
	 * @param StateKey $key Canonical state key.
	 * @return StateDefinition|null
	 * @throws StatePersistenceException On failure.
	 */
	public function find_by_key( StateKey $key ): ?StateDefinition {
		$results = $this->find_by_keys( array( $key ) );

		return $results[ $key->value() ] ?? null;
	}

	/**
	 * Definitions by persistence IDs (bounded batches, no N+1).
	 *
	 * @param array<int, int> $ids IDs.
	 * @return array<int, StateDefinition> Keyed by persistence ID.
	 * @throws StatePersistenceException On invalid input or failure.
	 */
	public function find_by_ids( array $ids ): array {
		$unique = self::positive_unique_ints( $ids );

		if ( array() === $unique ) {
			return array();
		}

		$results = array();

		foreach ( array_chunk( $unique, self::BATCH_SIZE ) as $chunk ) {
			$results += $this->fetch_by_ids( $chunk );
		}

		return $results;
	}

	/**
	 * Definitions by keys (bounded batches, no N+1).
	 *
	 * @param array<int, StateKey> $keys StateKey instances.
	 * @return array<string, StateDefinition> Keyed by canonical key string.
	 * @throws StatePersistenceException On invalid input or failure.
	 */
	public function find_by_keys( array $keys ): array {
		$unique_keys = array();
		$unique_map  = array();

		foreach ( $keys as $key ) {
			if ( ! $key instanceof StateKey ) {
				throw new StatePersistenceException( 'Batch key input must contain StateKey instances only.' );
			}

			$value = $key->value();

			if ( ! isset( $unique_map[ $value ] ) ) {
				$unique_map[ $value ]  = $key;
				$unique_keys[ $value ] = $value;
			}
		}

		if ( array() === $unique_keys ) {
			return array();
		}

		$results = array();

		foreach ( array_chunk( array_values( $unique_keys ), self::BATCH_SIZE ) as $chunk ) {
			$results += $this->fetch_by_keys( $chunk );
		}

		return $results;
	}

	/**
	 * One bounded SELECT for a chunk of IDs.
	 *
	 * @param array<int, int> $chunk Positive IDs (<= BATCH_SIZE).
	 * @return array<int, StateDefinition>
	 * @throws StatePersistenceException On query/hydration failure.
	 */
	private function fetch_by_ids( array $chunk ): array {
		$wpdb  = $this->wpdb;
		$table = $this->names->states();

		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

		// The %i identifier placeholder carries the trusted TableNames
		// value; every value is a %d placeholder built from count() only.
		// The IN-list is concatenated placeholder syntax (never values);
		// WPCS cannot statically prove that, hence the documented ignore.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, state_key, name, description, is_enabled, is_builtin, sort_order FROM %i WHERE id IN ( ' . $placeholders . ' )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders-only concatenation, see above.
				$table,
				...$chunk
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) && '' !== $wpdb->last_error ) {
			throw new StatePersistenceException( 'State definition lookup failed at the database level.' );
		}

		$results = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$definition = $this->hydrate( self::stringy_row( (array) $row ) );

			$definition_id = $definition->id();

			if ( null === $definition_id || isset( $results[ $definition_id ] ) ) {
				throw new StatePersistenceException( 'Duplicate state definition identity in one result set.' );
			}

			$results[ $definition_id ] = $definition;
		}

		return $results;
	}

	/**
	 * One bounded SELECT for a chunk of canonical keys.
	 *
	 * @param array<int, string> $chunk Canonical key strings (<= BATCH_SIZE).
	 * @return array<string, StateDefinition>
	 * @throws StatePersistenceException On query/hydration failure.
	 */
	private function fetch_by_keys( array $chunk ): array {
		$wpdb  = $this->wpdb;
		$table = $this->names->states();

		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

		// Same placeholders-only concatenation pattern as fetch_by_ids.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, state_key, name, description, is_enabled, is_builtin, sort_order FROM %i WHERE state_key IN ( ' . $placeholders . ' )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders-only concatenation, see above.
				$table,
				...$chunk
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) && '' !== $wpdb->last_error ) {
			throw new StatePersistenceException( 'State definition lookup failed at the database level.' );
		}

		$results = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$definition = $this->hydrate( self::stringy_row( (array) $row ) );

			$key_value = $definition->key()->value();

			if ( isset( $results[ $key_value ] ) ) {
				throw new StatePersistenceException( 'Duplicate state definition identity in one result set.' );
			}

			$results[ $key_value ] = $definition;
		}

		return $results;
	}

	/**
	 * Hydrate one string-keyed row through the domain invariants. Every
	 * invariant violation throws — a violating row is never silently
	 * normalized or manufactured into a valid object.
	 *
	 * @param array<string, string> $row String-keyed raw row.
	 * @return StateDefinition
	 * @throws StatePersistenceException When the row violates an invariant.
	 */
	private function hydrate( array $row ): StateDefinition {
		$id      = (int) ( $row['id'] ?? '0' );
		$key_raw = $row['state_key'] ?? '';
		$name    = $row['name'] ?? '';
		$desc    = $row['description'] ?? '';
		$enabled = '1' === ( $row['is_enabled'] ?? '0' );
		$builtin = '1' === ( $row['is_builtin'] ?? '0' );
		$sort    = (int) ( $row['sort_order'] ?? '0' );

		try {
			$key = StateKey::from_string( $key_raw );

			return StateDefinition::create( $key, $name, $desc, $enabled, $builtin, $sort, $id );
		} catch ( DomainException $e ) {
			// Technical context travels in the exception chain; the
			// message itself contains no SQL or raw DB error text.
			throw new StatePersistenceException(
				'Persisted state definition row violates domain invariants.',
				0,
				$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- technical context, never echoed.
			);
		}
	}

	/**
	 * Normalize one wpdb row to string keys/values (wpdb ARRAY_A rows are
	 * string-keyed; values may arrive as scalars).
	 *
	 * @param array<mixed> $row Raw row.
	 * @return array<string, string>
	 */
	private static function stringy_row( array $row ): array {
		$normalized = array();

		foreach ( $row as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$normalized[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $normalized;
	}

	/**
	 * Validate + de-duplicate ID input: positive integers only, no
	 * coercion of strings/zero/negatives (SF-003A §8).
	 *
	 * @param array<int, mixed> $ids Raw IDs.
	 * @return array<int, int> Unique positive integer IDs (list).
	 * @throws StatePersistenceException On any invalid entry.
	 */
	private static function positive_unique_ints( array $ids ): array {
		$unique = array();

		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) ) {
				throw new StatePersistenceException( 'Batch ID input must contain integers only.' );
			}

			if ( $id < 1 ) {
				throw new StatePersistenceException( 'Batch ID input must contain positive integers only.' );
			}

			if ( ! isset( $unique[ $id ] ) ) {
				$unique[ $id ] = $id;
			}
		}

		return array_values( $unique );
	}
}
