<?php
/**
 * Repository contract unit tests (SF-003A §17): missing row != exception,
 * malformed row => typed failure, batch keying, dedup, invalid input, and
 * the read-only mutation-surface guarantee. Uses a controllable wpdb
 * double; production objects carry no test seams.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StateFlow\Domain\State\ExplicitAssignment;
use StateFlow\Domain\State\ExplicitAssignmentRepository;
use StateFlow\Domain\State\StateDefinition;
use StateFlow\Domain\State\StateKey;
use StateFlow\Domain\State\StatePersistenceException;
use StateFlow\Infrastructure\Database\WpdbExplicitAssignmentRepository;
use StateFlow\Infrastructure\Database\WpdbStateDefinitionRepository;
use StateFlow\Infrastructure\Database\TableNames;
use StateFlow\Tests\ScriptedWpdb;

/**
 * Repository contracts on a scripted wpdb double.
 */
final class RepositoryContractTest extends TestCase {

	/**
	 * Reset the scripted wpdb global after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ScriptedWpdb::$queries           = array();
		ScriptedWpdb::$results           = array();
		ScriptedWpdb::$static_last_error = '';

		parent::tearDown();
	}

	/**
	 * A state repository wired to the scripted wpdb.
	 *
	 * @return WpdbStateDefinitionRepository
	 */
	private function state_repo(): WpdbStateDefinitionRepository {
		$wpdb = self::scripted_wpdb();

		return new WpdbStateDefinitionRepository( $wpdb, new TableNames( 'wp_' ) );
	}

	/**
	 * An assignment repository wired to the scripted wpdb.
	 *
	 * @return WpdbExplicitAssignmentRepository
	 */
	private function assignment_repo(): WpdbExplicitAssignmentRepository {
		$wpdb = self::scripted_wpdb();

		return new WpdbExplicitAssignmentRepository( $wpdb, new TableNames( 'wp_' ) );
	}

	/**
	 * The scripted wpdb double, typed as \wpdb for static analysis. The
	 * stub's behavior (scripted results, recorded queries) is driven via
	 * the StateFlow\Tests\ScriptedWpdb alias of the same class.
	 *
	 * @return \wpdb
	 */
	private static function scripted_wpdb(): \wpdb {
		// The analysis copy of wpdb requires the four real constructor
		// args; the runtime stub accepts any (see tests/stubs/wpdb.php).
		return new \wpdb( 'u', 'p', 'd', 'h' );
	}

	/**
	 * Missing row: find_by_id returns null, not an exception.
	 *
	 * @return void
	 */
	public function test_missing_state_id_returns_null(): void {
		ScriptedWpdb::$results = array( array() );

		$this->assertNull( $this->state_repo()->find_by_id( 12 ) );
	}

	/**
	 * Missing row: find_by_key returns null, not an exception.
	 *
	 * @return void
	 */
	public function test_missing_state_key_returns_null(): void {
		ScriptedWpdb::$results = array( array() );

		$this->assertNull( $this->state_repo()->find_by_key( StateKey::from_string( 'ghost' ) ) );
	}

	/**
	 * Missing row: assignment find returns null, not an exception.
	 *
	 * @return void
	 */
	public function test_missing_assignment_returns_null(): void {
		ScriptedWpdb::$results = array( array() );

		$this->assertNull( $this->assignment_repo()->find( 4242 ) );
	}

	/**
	 * Missing objects are omitted from batch results.
	 *
	 * @return void
	 */
	public function test_missing_objects_are_omitted_from_batch(): void {
		ScriptedWpdb::$results = array(
			array(
				array(
					'object_id' => '10',
					'state_id'  => '2',
					'version'   => '1',
				),
			),
		);

		$results = $this->assignment_repo()->find_many( array( 10, 11, 12 ) );

		$this->assertArrayHasKey( 10, $results );
		$this->assertArrayNotHasKey( 11, $results );
		$this->assertArrayNotHasKey( 12, $results );
	}

	/**
	 * Batch results are keyed by object ID and hydrate fields exactly.
	 *
	 * @return void
	 */
	public function test_assignment_batch_keyed_by_object_id(): void {
		ScriptedWpdb::$results = array(
			array(
				array(
					'object_id' => '10',
					'state_id'  => '2',
					'version'   => '4',
				),
				array(
					'object_id' => '20',
					'state_id'  => '3',
					'version'   => '1',
				),
			),
		);

		$results = $this->assignment_repo()->find_many( array( 10, 20 ) );

		$this->assertCount( 2, $results );
		$this->assertSame( 2, $results[10]->state_id() );
		$this->assertSame( 4, $results[10]->version() );
		$this->assertSame( 3, $results[20]->state_id() );
	}

	/**
	 * State batch results are keyed by persistence ID / canonical key.
	 *
	 * @return void
	 */
	public function test_state_batches_are_keyed_correctly(): void {
		ScriptedWpdb::$results = array(
			array(
				array(
					'id'         => '12',
					'state_key'  => 'selling',
					'name'       => 'Selling',
					'is_enabled' => '1',
					'is_builtin' => '0',
					'sort_order' => '10',
				),
				array(
					'id'         => '17',
					'state_key'  => 'inquiry',
					'name'       => 'Inquiry',
					'is_enabled' => '1',
					'is_builtin' => '0',
					'sort_order' => '20',
				),
			),
		);

		$by_ids = $this->state_repo()->find_by_ids( array( 12, 17 ) );

		$this->assertSame( array( 12, 17 ), array_keys( $by_ids ) );
		$this->assertSame( 'selling', $by_ids[12]->key()->value() );

		ScriptedWpdb::$results = array(
			array(
				array(
					'id'         => '12',
					'state_key'  => 'selling',
					'name'       => 'Selling',
					'is_enabled' => '1',
					'is_builtin' => '0',
					'sort_order' => '10',
				),
			),
		);

		$by_keys = $this->state_repo()->find_by_keys( array( StateKey::from_string( 'selling' ) ) );

		$this->assertSame( array( 'selling' ), array_keys( $by_keys ) );
		$this->assertSame( 12, $by_keys['selling']->id() );
	}

	/**
	 * Duplicate requested IDs are de-duplicated (one ID, one query slot).
	 *
	 * @return void
	 */
	public function test_duplicate_ids_are_de_duplicated(): void {
		ScriptedWpdb::$results = array(
			array(
				array(
					'object_id' => '10',
					'state_id'  => '2',
					'version'   => '1',
				),
			),
		);

		$results = $this->assignment_repo()->find_many( array( 10, 10, 10 ) );

		$this->assertCount( 1, $results );

		$recorded_queries = ScriptedWpdb::$queries;
		$first_query      = is_array( $recorded_queries ) && isset( $recorded_queries[0] ) && is_string( $recorded_queries[0] ) ? $recorded_queries[0] : '';

		// The prepared statement must contain exactly one %d placeholder —
		// the triple-duplicate collapsed into a single query slot.
		$this->assertSame( 1, substr_count( $first_query, '%d' ) );
	}

	/**
	 * Invalid batch input (string ID) is rejected explicitly — "42" is
	 * never coerced.
	 *
	 * @return void
	 */
	public function test_string_ids_are_rejected(): void {
		// Invalid input must raise the typed failure in every case.
		$this->expectException( StatePersistenceException::class );

		$invalid = array( '42' );

		// Deliberately invalid: the repository must reject a string ID.
		// @phpstan-ignore-next-line argument.type.
		$this->assignment_repo()->find_many( $invalid );
	}

	/**
	 * Zero and negative batch IDs are rejected.
	 *
	 * @return void
	 */
	public function test_non_positive_ids_are_rejected(): void {
		$repo = $this->assignment_repo();

		$this->expectException( StatePersistenceException::class );
		try {
			$repo->find_many( array( 0 ) );
		} catch ( StatePersistenceException $e ) {
			$repo->find_many( array( -1 ) );
			$this->fail( 'A negative ID must also be rejected.' );
		}
	}

	/**
	 * Non-StateKey batch input is rejected for key batches.
	 *
	 * @return void
	 */
	public function test_key_batches_require_statekey_instances(): void {
		// Invalid input must raise the typed failure in every case.
		$this->expectException( StatePersistenceException::class );

		$invalid = array( 'selling' );

		// Deliberately invalid: keys must be StateKey instances.
		// @phpstan-ignore-next-line argument.type.
		$this->state_repo()->find_by_keys( $invalid );
	}

	/**
	 * A malformed state row (reserved key) raises the typed persistence
	 * failure — never a silently manufactured object.
	 *
	 * @return void
	 */
	public function test_malformed_state_row_raises_typed_failure(): void {
		ScriptedWpdb::$results = array(
			array(
				array(
					'id'         => '12',
					'state_key'  => 'normal', // Reserved key: invariant violation.
					'name'       => 'Normal',
					'is_enabled' => '1',
					'is_builtin' => '0',
					'sort_order' => '10',
				),
			),
		);

		$this->expectException( StatePersistenceException::class );
		$this->state_repo()->find_by_id( 12 );
	}

	/**
	 * A malformed assignment row (version 0) raises the typed failure.
	 *
	 * @return void
	 */
	public function test_malformed_assignment_row_raises_typed_failure(): void {
		ScriptedWpdb::$results = array(
			array(
				array(
					'object_id' => '10',
					'state_id'  => '2',
					'version'   => '0',
				),
			),
		);

		$this->expectException( StatePersistenceException::class );
		$this->assignment_repo()->find( 10 );
	}

	/**
	 * A structurally impossible duplicate identity in one result set
	 * fails explicitly (SF-003A §13).
	 *
	 * @return void
	 */
	public function test_duplicate_result_identity_fails_explicitly(): void {
		ScriptedWpdb::$results = array(
			array(
				array(
					'object_id' => '10',
					'state_id'  => '2',
					'version'   => '1',
				),
				array(
					'object_id' => '10',
					'state_id'  => '3',
					'version'   => '1',
				),
			),
		);

		$this->expectException( StatePersistenceException::class );
		$this->assignment_repo()->find_many( array( 10 ) );
	}

	/**
	 * A database-level failure (query error) raises the typed failure —
	 * it must never be mistaken for "no state".
	 *
	 * @return void
	 */
	public function test_database_failure_raises_typed_failure(): void {
		ScriptedWpdb::$static_last_error = 'Table is gone';
		ScriptedWpdb::$results           = array( null ); // One failed query.

		$this->expectException( StatePersistenceException::class );
		$this->assignment_repo()->find( 10 );
	}

	/**
	 * Empty batch input executes zero queries and returns an empty map.
	 *
	 * @return void
	 */
	public function test_empty_batch_runs_zero_queries(): void {
		$this->assertSame( array(), $this->assignment_repo()->find_many( array() ) );
		$this->assertSame( array(), $this->state_repo()->find_by_ids( array() ) );
		$this->assertSame( array(), $this->state_repo()->find_by_keys( array() ) );
		$this->assertSame( array(), ScriptedWpdb::$queries );
	}

	/**
	 * Bounded batching: 1200 unique IDs => 3 SELECTs with batch size 500.
	 *
	 * @return void
	 */
	public function test_batching_bounded_at_500(): void {
		ScriptedWpdb::$results = array( array(), array(), array() );

		$ids = range( 1, 1200 );

		$this->assignment_repo()->find_many( $ids );
		$this->assertCount( 3, (array) ScriptedWpdb::$queries );

		ScriptedWpdb::$queries = array();
		ScriptedWpdb::$results = array( array(), array(), array() );

		$this->state_repo()->find_by_ids( $ids );
		$this->assertCount( 3, (array) ScriptedWpdb::$queries );
	}

	/**
	 * REGRESSION GUARD (SF-003A §4): the assignment repository contract
	 * exposes NO mutation API. The implementation class must declare
	 * exactly find/find_many (plus the two constructors) and implement
	 * only the read interface.
	 *
	 * @return void
	 */
	public function test_assignment_repository_has_no_mutation_api(): void {
		$reflection = new \ReflectionClass( WpdbExplicitAssignmentRepository::class );

		$methods = array_map(
			static fn( \ReflectionMethod $method ): string => $method->getName(),
			$reflection->getMethods( \ReflectionMethod::IS_PUBLIC )
		);

		sort( $methods );

		$this->assertSame(
			array( 'find', 'find_many' ),
			array_values( array_diff( $methods, array( '__construct' ) ) ),
			'The assignment repository must expose no mutation API.'
		);

		$interfaces = class_implements( $reflection->getName() );

		$this->assertContains( ExplicitAssignmentRepository::class, $interfaces );

		// Guard against future mutation APIs (checked via the reflection
		// method list, which is complete by construction).
		$forbidden = array( 'save', 'assign', 'delete', 'update', 'insert', 'upsert', 'transition' );

		$this->assertSame(
			array(),
			array_intersect( $forbidden, $methods ),
			'No assignment mutation method may exist.'
		);
	}

	/**
	 * The repository interface itself carries only the two read methods.
	 *
	 * @return void
	 */
	public function test_assignment_interface_is_read_only(): void {
		$methods = get_class_methods( ExplicitAssignmentRepository::class );

		sort( $methods );

		$this->assertSame( array( 'find', 'find_many' ), $methods );
	}
}
