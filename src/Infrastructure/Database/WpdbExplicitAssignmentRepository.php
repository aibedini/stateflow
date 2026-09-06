<?php
/**
 * WpdbExplicitAssignmentRepository — wpdb-backed read implementation of
 * the ExplicitAssignmentRepository contract (SF-003A §7/§8-§13).
 *
 * Answers exactly one question: "Does StateFlow hold an explicit
 * assignment row for this object ID?" It does NOT verify object
 * existence, does not join posts/postmeta/WooCommerce tables, and never
 * writes. Selects only object_id/state_id/version — no timestamps.
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

use StateFlow\Domain\State\DomainException;
use StateFlow\Domain\State\ExplicitAssignment;
use StateFlow\Domain\State\ExplicitAssignmentRepository;
use StateFlow\Domain\State\StatePersistenceException;
use wpdb;

/**
 * Read-only explicit assignment repository on wpdb.
 */
final class WpdbExplicitAssignmentRepository implements ExplicitAssignmentRepository {

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
	 * The explicit assignment for one object ID.
	 *
	 * @param int $object_id Object ID.
	 * @return ExplicitAssignment|null
	 * @throws StatePersistenceException On invalid input or failure.
	 */
	public function find( int $object_id ): ?ExplicitAssignment {
		$results = $this->find_many( array( $object_id ) );

		return $results[ $object_id ] ?? null;
	}

	/**
	 * Explicit assignments for many object IDs (bounded batches, no N+1).
	 *
	 * @param array<int, int> $object_ids Object IDs.
	 * @return array<int, ExplicitAssignment> Keyed by object ID.
	 * @throws StatePersistenceException On invalid input or failure.
	 */
	public function find_many( array $object_ids ): array {
		$unique = self::positive_unique_ints( $object_ids );

		if ( array() === $unique ) {
			return array();
		}

		$results = array();

		foreach ( array_chunk( $unique, self::BATCH_SIZE ) as $chunk ) {
			$results += $this->fetch_chunk( $chunk );
		}

		return $results;
	}

	/**
	 * One bounded SELECT for a chunk of object IDs.
	 *
	 * @param array<int, int> $chunk Positive object IDs (<= BATCH_SIZE).
	 * @return array<int, ExplicitAssignment>
	 * @throws StatePersistenceException On query/hydration failure.
	 */
	private function fetch_chunk( array $chunk ): array {
		$wpdb  = $this->wpdb;
		$table = $this->names->assignments();

		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

		// The %i identifier placeholder carries the trusted TableNames
		// value; every value is a %d placeholder built from count() only.
		// The IN-list is concatenated placeholder syntax (never values), so
		// the resulting template is safe; wpdb::prepare() receives it as
		// one string. WPCS cannot statically prove the concatenation is
		// placeholder-only, hence the documented ignore on this line.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, state_id, version FROM %i WHERE object_id IN ( ' . $placeholders . ' )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders-only concatenation, see above.
				$table,
				...$chunk
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) && '' !== $wpdb->last_error ) {
			throw new StatePersistenceException( 'Assignment lookup failed at the database level.' );
		}

		$results = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row_values = self::stringy_row( (array) $row );

			$object_id = (int) ( $row_values['object_id'] ?? '0' );

			if ( isset( $results[ $object_id ] ) ) {
				// Structurally impossible under the frozen PRIMARY KEY;
				// never let last-row-wins hide corruption (SF-003A §13).
				throw new StatePersistenceException( 'Duplicate assignment identity in one result set.' );
			}

			try {
				$results[ $object_id ] = ExplicitAssignment::create(
					$object_id,
					(int) ( $row_values['state_id'] ?? '0' ),
					(int) ( $row_values['version'] ?? '0' )
				);
			} catch ( DomainException $e ) {
				// Technical context travels in the exception chain; the
				// message itself contains no SQL or raw DB error text.
				throw new StatePersistenceException(
					'Persisted assignment row violates domain invariants.',
					0,
					$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- technical context, never echoed.
				);
			}
		}

		return $results;
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
