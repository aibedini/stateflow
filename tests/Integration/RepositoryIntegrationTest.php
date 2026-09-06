<?php
/**
 * SF-003A repository read-layer integration tests (real WordPress + real
 * MySQL/MariaDB). Uses the frozen StateFlow tables, seed fixtures through
 * the migration runner, and proves the read contracts, batch/query-count
 * mechanics and query targets end to end.
 *
 * Coverage (§18-§21): state find-by-id/key + batches, Persian round-trip,
 * disabled + built-in hydration, assignment find/find_many + version
 * hydration, product vs variation-like IDs treated identically, empty
 * batch => 0 queries, bounded batching (500) => 1/3 SELECTs for 200/1200
 * IDs, and query-target acceptance (only StateFlow-owned tables touched
 * inside the repository invocation window).
 *
 * @package StateFlow\Tests\Integration
 */

declare( strict_types = 1 );

use StateFlow\Domain\State\StateKey;
use StateFlow\Infrastructure\Database\MigrationRunner;
use StateFlow\Infrastructure\Database\TableNames;
use StateFlow\Infrastructure\Database\WpdbExplicitAssignmentRepository;
use StateFlow\Infrastructure\Database\WpdbStateDefinitionRepository;

/**
 * SF-003A repository integration checks.
 */
final class RepositoryIntegrationTest extends WP_UnitTestCase {

	/**
	 * Fresh runner + repository objects per test; schema ensured, tables
	 * reset to a clean state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->runner()->ensure_current();

		$this->drop_stateflow_tables();
		$this->runner()->ensure_current();
	}

	/**
	 * Reset tables after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->drop_stateflow_tables();

		parent::tearDown();
	}

	/**
	 * §18: find a state definition by persistence ID.
	 *
	 * @return void
	 */
	public function test_find_state_by_id(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );
		$inquiry_id = $this->insert_state( 'inquiry', 'Inquiry' );

		$repo = $this->state_repo();

		$found = $repo->find_by_id( $selling_id );

		$this->assertNotNull( $found );
		$this->assertSame( 'selling', $found->key()->value() );
		$this->assertSame( $selling_id, $found->id() );
		$this->assertNotSame( $inquiry_id, $found->id() );
	}

	/**
	 * §18: missing ID returns null, not an exception.
	 *
	 * @return void
	 */
	public function test_find_state_by_missing_id_returns_null(): void {
		$this->assertNull( $this->state_repo()->find_by_id( 987654321 ) );
	}

	/**
	 * §18: find a state definition by canonical key.
	 *
	 * @return void
	 */
	public function test_find_state_by_key(): void {
		$this->insert_state( 'selling', 'Selling' );

		$found = $this->state_repo()->find_by_key( StateKey::from_string( 'selling' ) );

		$this->assertNotNull( $found );
		$this->assertSame( 'Selling', $found->name() );
	}

	/**
	 * §18: missing key returns null.
	 *
	 * @return void
	 */
	public function test_find_state_by_missing_key_returns_null(): void {
		$this->assertNull( $this->state_repo()->find_by_key( StateKey::from_string( 'ghost' ) ) );
	}

	/**
	 * §18: find_many by IDs keys the map by persistence ID.
	 *
	 * @return void
	 */
	public function test_find_many_state_ids(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );
		$inquiry_id = $this->insert_state( 'inquiry', 'Inquiry' );
		$hold_id    = $this->insert_state( 'hold', 'On hold' );

		$results = $this->state_repo()->find_by_ids( array( $inquiry_id, $selling_id, $hold_id ) );

		$this->assertSame( array( $inquiry_id, $selling_id, $hold_id ), array_keys( $results ) );
	}

	/**
	 * §18: find_many by keys keys the map by canonical key string.
	 *
	 * @return void
	 */
	public function test_find_many_state_keys(): void {
		$this->insert_state( 'selling', 'Selling' );
		$this->insert_state( 'inquiry', 'Inquiry' );

		$results = $this->state_repo()->find_by_keys(
			array(
				StateKey::from_string( 'selling' ),
				StateKey::from_string( 'inquiry' ),
			)
		);

		$this->assertSame( array( 'selling', 'inquiry' ), array_keys( $results ) );
	}

	/**
	 * §18: Persian state name + description survive a full round-trip
	 * through the database and hydrate unchanged.
	 *
	 * @return void
	 */
	public function test_persian_name_description_round_trip(): void {
		$name = 'در حال فروش';
		$desc = 'محصول در حال فروش فعال است';

		$this->insert_state_raw( 'selling', $name, $desc );

		$found = $this->state_repo()->find_by_key( StateKey::from_string( 'selling' ) );

		$this->assertNotNull( $found );
		$this->assertSame( $name, $found->name() );
		$this->assertSame( $desc, $found->description() );
	}

	/**
	 * §18: a disabled state hydrates as disabled.
	 *
	 * @return void
	 */
	public function test_disabled_state_hydrates_as_disabled(): void {
		$this->insert_state_raw( 'inactive', 'Inactive', '', false );

		$found = $this->state_repo()->find_by_key( StateKey::from_string( 'inactive' ) );

		$this->assertNotNull( $found );
		$this->assertFalse( $found->enabled() );
	}

	/**
	 * §18: the built-in flag hydrates correctly.
	 *
	 * @return void
	 */
	public function test_builtin_flag_hydrates(): void {
		$this->insert_state_raw( 'preorder', 'Pre-order', '', true, true );

		$found = $this->state_repo()->find_by_key( StateKey::from_string( 'preorder' ) );

		$this->assertNotNull( $found );
		$this->assertTrue( $found->builtin() );
	}

	/**
	 * §18: assignment find returns the explicit assignment.
	 *
	 * @return void
	 */
	public function test_assignment_find_existing(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );
		$this->insert_assignment( 4242, $selling_id );

		$found = $this->assignment_repo()->find( 4242 );

		$this->assertNotNull( $found );
		$this->assertSame( $selling_id, $found->state_id() );
	}

	/**
	 * §18: assignment find on a missing object returns null.
	 *
	 * @return void
	 */
	public function test_assignment_find_missing(): void {
		$this->assertNull( $this->assignment_repo()->find( 4242 ) );
	}

	/**
	 * §18: assignment find_many keys by object ID.
	 *
	 * @return void
	 */
	public function test_assignment_find_many(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );
		$this->insert_assignment( 4242, $selling_id );
		$this->insert_assignment( 4243, $selling_id );
		$this->insert_assignment( 99999, $selling_id );

		$results = $this->assignment_repo()->find_many( array( 4242, 4243, 4244 ) );

		$this->assertArrayHasKey( 4242, $results );
		$this->assertArrayHasKey( 4243, $results );
		$this->assertArrayNotHasKey( 4244, $results );
		$this->assertArrayNotHasKey( 99999, $results );
	}

	/**
	 * §18: version hydrates correctly.
	 *
	 * @return void
	 */
	public function test_assignment_version_hydrates(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );

		global $wpdb;

		$wpdb->insert(
			$this->names()->assignments(),
			array(
				'object_id'  => 4242,
				'state_id'   => $selling_id,
				'version'    => 7,
				'entered_at' => gmdate( 'Y-m-d H:i:s' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$found = $this->assignment_repo()->find( 4242 );

		$this->assertNotNull( $found );
		$this->assertSame( 7, $found->version() );
	}

	/**
	 * §18: product-like and variation-like IDs are treated identically as
	 * plain object IDs (no post-type knowledge in the repository).
	 *
	 * @return void
	 */
	public function test_product_and_variation_ids_treated_identically(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );

		// Product 100 and its "variation" 101 both live in the same ID
		// space; the repository must not care.
		$this->insert_assignment( 100, $selling_id );
		$this->insert_assignment( 101, $selling_id );

		$results = $this->assignment_repo()->find_many( array( 100, 101 ) );

		$this->assertCount( 2, $results );
		$this->assertArrayHasKey( 100, $results );
		$this->assertArrayHasKey( 101, $results );
	}

	/**
	 * §19/§20: empty batch => zero repository SQL queries.
	 *
	 * @return void
	 */
	public function test_empty_batch_zero_queries(): void {
		$wpdb   = $this->wpdb();
		$before = (int) $wpdb->num_queries;

		$this->assertSame( array(), $this->assignment_repo()->find_many( array() ) );
		$this->assertSame( array(), $this->state_repo()->find_by_ids( array() ) );
		$this->assertSame( array(), $this->state_repo()->find_by_keys( array() ) );

		$this->assertSame( 0, (int) $wpdb->num_queries - $before );
	}

	/**
	 * §19: 200 assignment IDs => exactly 1 assignment SELECT.
	 *
	 * @return void
	 */
	public function test_200_assignment_ids_one_select(): void {
		$this->seed_assignments( 200 );

		$wpdb   = $this->wpdb();
		$before = (int) $wpdb->num_queries;

		$this->assignment_repo()->find_many( range( 1, 200 ) );

		$this->assertSame( 1, (int) $wpdb->num_queries - $before );
	}

	/**
	 * §19: 200 state IDs => exactly 1 state SELECT.
	 *
	 * @return void
	 */
	public function test_200_state_ids_one_select(): void {
		$this->seed_states( 200 );

		$wpdb   = $this->wpdb();
		$before = (int) $wpdb->num_queries;

		$this->state_repo()->find_by_ids( range( 1, 200 ) );

		$this->assertSame( 1, (int) $wpdb->num_queries - $before );
	}

	/**
	 * §19/§21: 1200 unique assignment IDs => at most 3 assignment SELECTs
	 * (batch size 500). No per-object query.
	 *
	 * @return void
	 */
	public function test_1200_assignment_ids_at_most_three_selects(): void {
		$this->seed_assignments( 1200 );

		$wpdb   = $this->wpdb();
		$before = (int) $wpdb->num_queries;

		$this->assignment_repo()->find_many( range( 1, 1200 ) );

		$this->assertLessThanOrEqual( 3, (int) $wpdb->num_queries - $before );
		$this->assertGreaterThanOrEqual( 3, (int) $wpdb->num_queries - $before );
	}

	/**
	 * §19/§21: 1200 unique state IDs => at most 3 state SELECTs.
	 *
	 * @return void
	 */
	public function test_1200_state_ids_at_most_three_selects(): void {
		$this->seed_states( 1200 );

		$wpdb   = $this->wpdb();
		$before = (int) $wpdb->num_queries;

		$this->state_repo()->find_by_ids( range( 1, 1200 ) );

		$this->assertLessThanOrEqual( 3, (int) $wpdb->num_queries - $before );
		$this->assertGreaterThanOrEqual( 3, (int) $wpdb->num_queries - $before );
	}

	/**
	 * §20: the queries issued by a state repository invocation reference
	 * ONLY the StateFlow states table (no posts/postmeta/terms/WC).
	 *
	 * @return void
	 */
	public function test_state_repository_queries_only_stateflow_table(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );
		$inquiry_id = $this->insert_state( 'inquiry', 'Inquiry' );

		$wpdb = $this->wpdb();

		$start = count( $wpdb->queries );
		$this->state_repo()->find_by_ids( array( $selling_id, $inquiry_id ) );

		$window = array_slice( $wpdb->queries, $start );

		$this->assertNotEmpty( $window );

		foreach ( $window as $entry ) {
			$query = (string) $entry[0];

			$this->assertStringContainsString( 'stateflow_states', $query, 'Only the StateFlow states table may be read.' );
			$this->assertStringNotContainsString( 'stateflow_assignments', $query );
			$this->assertStringNotContainsString( ' FROM wp_posts', $query );
			$this->assertStringNotContainsString( ' postmeta', $query );
			$this->assertStringNotContainsString( ' terms', $query );
		}
	}

	/**
	 * §20: assignment repository queries reference ONLY the assignments
	 * table.
	 *
	 * @return void
	 */
	public function test_assignment_repository_queries_only_stateflow_table(): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );
		$this->insert_assignment( 4242, $selling_id );

		$wpdb = $this->wpdb();

		$start = count( $wpdb->queries );
		$this->assignment_repo()->find_many( array( 4242, 4243 ) );

		$window = array_slice( $wpdb->queries, $start );

		$this->assertNotEmpty( $window );

		foreach ( $window as $entry ) {
			$query = (string) $entry[0];

			$this->assertStringContainsString( 'stateflow_assignments', $query );
			$this->assertStringNotContainsString( 'stateflow_states', $query );
			$this->assertStringNotContainsString( ' FROM wp_posts', $query );
			$this->assertStringNotContainsString( ' postmeta', $query );
		}
	}

	/**
	 * State repository.
	 *
	 * @return WpdbStateDefinitionRepository
	 */
	private function state_repo(): WpdbStateDefinitionRepository {
		return new WpdbStateDefinitionRepository( $this->wpdb(), $this->names() );
	}

	/**
	 * Assignment repository.
	 *
	 * @return WpdbExplicitAssignmentRepository
	 */
	private function assignment_repo(): WpdbExplicitAssignmentRepository {
		return new WpdbExplicitAssignmentRepository( $this->wpdb(), $this->names() );
	}

	/**
	 * Current wpdb.
	 *
	 * @return wpdb
	 */
	private function wpdb(): wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * TableNames for this site.
	 *
	 * @return StateFlow\Infrastructure\Database\TableNames
	 */
	private function names(): StateFlow\Infrastructure\Database\TableNames {
		return StateFlow\Infrastructure\Database\TableNames::from_wpdb( $this->wpdb() );
	}

	/**
	 * A fresh migration runner.
	 *
	 * @return StateFlow\Infrastructure\Database\MigrationRunner
	 */
	private function runner(): StateFlow\Infrastructure\Database\MigrationRunner {
		return new StateFlow\Infrastructure\Database\MigrationRunner( $this->wpdb() );
	}

	/**
	 * Insert a state definition; returns its new row ID.
	 *
	 * @param string $key  State key.
	 * @param string $name Display name.
	 * @return int
	 */
	private function insert_state( string $key, string $name ): int {
		return $this->insert_state_raw( $key, $name, '' );
	}

	/**
	 * Insert a state definition with full control; returns the new ID.
	 *
	 * @param string $key      State key.
	 * @param string $name     Display name.
	 * @param string $desc     Description.
	 * @param bool   $enabled  Enabled flag (default true).
	 * @param bool   $builtin  Built-in flag (default false).
	 * @return int
	 */
	private function insert_state_raw( string $key, string $name, string $desc = '', bool $enabled = true, bool $builtin = false ): int {
		$wpdb = $this->wpdb();
		$now  = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			$this->names()->states(),
			array(
				'state_key'   => $key,
				'name'        => $name,
				'description' => $desc,
				'is_enabled'  => $enabled ? 1 : 0,
				'is_builtin'  => $builtin ? 1 : 0,
				'sort_order'  => 100,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		$this->assertNotFalse( $inserted, 'State fixture insert must succeed.' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert an explicit assignment fixture.
	 *
	 * @param int $object_id Object ID.
	 * @param int $state_id  State ID.
	 * @return void
	 */
	private function insert_assignment( int $object_id, int $state_id ): void {
		$wpdb = $this->wpdb();
		$now  = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			$this->names()->assignments(),
			array(
				'object_id'  => $object_id,
				'state_id'   => $state_id,
				'version'    => 1,
				'entered_at' => $now,
				'updated_at' => $now,
			)
		);

		$this->assertNotFalse( $inserted, 'Assignment fixture insert must succeed.' );
	}

	/**
	 * Seed N state rows (deterministic synthetic dataset, §21).
	 *
	 * @param int $count Row count.
	 * @return void
	 */
	private function seed_states( int $count ): void {
		$wpdb = $this->wpdb();
		$now  = gmdate( 'Y-m-d H:i:s' );

		for ( $i = 1; $i <= $count; $i++ ) {
			$wpdb->insert(
				$this->names()->states(),
				array(
					'state_key'   => 'seed_' . $i,
					'name'        => 'Seed ' . $i,
					'description' => '',
					'is_enabled'  => 1,
					'is_builtin'  => 0,
					'sort_order'  => 100,
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
		}
	}

	/**
	 * Seed N assignment rows against a shared state (deterministic
	 * synthetic dataset, §21).
	 *
	 * @param int $count Row count.
	 * @return void
	 */
	private function seed_assignments( int $count ): void {
		$selling_id = $this->insert_state( 'selling', 'Selling' );
		$wpdb       = $this->wpdb();
		$now        = gmdate( 'Y-m-d H:i:s' );

		for ( $i = 1; $i <= $count; $i++ ) {
			$wpdb->insert(
				$this->names()->assignments(),
				array(
					'object_id'  => $i,
					'state_id'   => $selling_id,
					'version'    => 1,
					'entered_at' => $now,
					'updated_at' => $now,
				)
			);
		}
	}

	/**
	 * Drop StateFlow tables (test lifecycle only).
	 *
	 * @return void
	 */
	private function drop_stateflow_tables(): void {
		$wpdb = $this->wpdb();

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->names()->assignments() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->names()->states() ) );
	}
}
