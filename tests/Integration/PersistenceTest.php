<?php
/**
 * SF-002 schema/migration integration tests (real WordPress + real MySQL).
 *
 * Covers SF-002 §20-§24/§32/§36: activation-driven installation, exact
 * columns/indexes, DB-level uniqueness, one-assignment-per-object,
 * idempotence with data preservation, the upgrade (non-activation) path,
 * deactivation persistence, version-written-last safety, and the
 * operations-counted fast-path benchmark.
 *
 * Fixtures use narrow internal persistence helpers (SF-002 §19) — this is
 * NOT a repository API and must not be promoted into one.
 *
 * @package StateFlow\Tests\Integration
 */

declare( strict_types = 1 );

/**
 * SF-002 persistence foundation checks.
 */
final class PersistenceTest extends WP_UnitTestCase {

	/**
	 * Set up a fresh runner; drop any StateFlow tables left by a previous
	 * test so scenarios start from a controlled state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->drop_stateflow_tables();
		delete_option( StateFlow\Infrastructure\Database\Schema::VERSION_OPTION );
		delete_option( StateFlow\Infrastructure\Database\MigrationLock::OPTION );
	}

	/**
	 * Clean up StateFlow tables and options after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->drop_stateflow_tables();
		delete_option( StateFlow\Infrastructure\Database\Schema::VERSION_OPTION );
		delete_option( StateFlow\Infrastructure\Database\MigrationLock::OPTION );

		parent::tearDown();
	}

	/**
	 * §20: activation creates both tables with the current $wpdb->prefix
	 * and bumps the schema version to 1.
	 *
	 * @return void
	 */
	public function test_activation_creates_schema(): void {
		$wpdb = $this->wpdb();

		\StateFlow\Plugin::instance()->activate();

		$this->assertNotFalse(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->names()->states() ) ),
			'States table must exist after activation.'
		);
		$this->assertNotFalse(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->names()->assignments() ) ),
			'Assignments table must exist after activation.'
		);

		$this->assertSame( 1, $this->runner()->installed_version(), 'Schema version must be 1 after activation.' );
	}

	/**
	 * §20: the exact required columns exist on both tables. Expected names
	 * derive from the site prefix via TableNames — never hard-coded.
	 *
	 * @return void
	 */
	public function test_required_columns(): void {
		$wpdb = $this->wpdb();

		\StateFlow\Plugin::instance()->activate();

		foreach ( StateFlow\Infrastructure\Database\Schema::required_columns() as $logical => $required ) {
			$table = 'stateflow_states' === $logical ? $this->names()->states() : $this->names()->assignments();

			$rows = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ), ARRAY_A );
			$this->assertIsArray( $rows, "DESCRIBE must work on {$table}." );

			$actual = array();

			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['Field'] ) ) {
					$actual[] = (string) $row['Field'];
				}
			}

			$this->assertSame(
				$required,
				$actual,
				"Column set of {$table} must match the target schema exactly."
			);
		}
	}

	/**
	 * §20/§31: required indexes exist with the right uniqueness and
	 * composite ordering (verified structurally by the SchemaVerifier).
	 *
	 * @return void
	 */
	public function test_required_indexes(): void {
		\StateFlow\Plugin::instance()->activate();

		$verifier = new StateFlow\Infrastructure\Database\SchemaVerifier( $this->wpdb(), $this->names() );

		$this->assertSame(
			array(),
			$verifier->verify(),
			'SchemaVerifier must find no structural problems after installation.'
		);
	}

	/**
	 * §20: state_key uniqueness is enforced at the database level.
	 *
	 * @return void
	 */
	public function test_state_key_uniqueness_at_database_level(): void {
		$wpdb = $this->wpdb();

		\StateFlow\Plugin::instance()->activate();

		$this->insert_state_fixture( 'selling', 'Selling' );

		$second = $wpdb->insert(
			$this->names()->states(),
			array(
				'state_key'   => 'selling',
				'name'        => 'Selling duplicate',
				'description' => '',
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->assertFalse( $second, 'A duplicate state_key must be rejected by the database.' );
	}

	/**
	 * §20: one explicit assignment per object_id (PRIMARY KEY).
	 *
	 * @return void
	 */
	public function test_one_assignment_per_object(): void {
		$wpdb = $this->wpdb();

		\StateFlow\Plugin::instance()->activate();

		$state_id = $this->insert_state_fixture( 'selling', 'Selling' );

		$first = $this->insert_assignment_fixture( 4242, $state_id );
		$this->assertNotFalse( $first, 'The first assignment for an object must succeed.' );

		$second = $this->insert_assignment_fixture( 4242, $state_id );
		$this->assertFalse( $second, 'A second assignment row for the same object_id must be rejected.' );
	}

	/**
	 * §20: the schema-version option equals VERSION 1 after a successful
	 * migration — and is separate from the plugin release version.
	 *
	 * @return void
	 */
	public function test_schema_version_option(): void {
		\StateFlow\Plugin::instance()->activate();

		$stored = get_option( StateFlow\Infrastructure\Database\Schema::VERSION_OPTION );

		$this->assertSame( '1', (string) $stored, 'Installed schema version must be 1.' );
		$this->assertNotSame( STATEFLOW_VERSION, $stored, 'Schema version is a different concept from the plugin version.' );
	}

	/**
	 * §21: repeated migrations are idempotent — no duplicate tables or
	 * indexes, no data loss, version stays current.
	 *
	 * @return void
	 */
	public function test_migration_is_idempotent_and_preserves_data(): void {
		$wpdb = $this->wpdb();

		\StateFlow\Plugin::instance()->activate();

		$state_id = $this->insert_state_fixture( 'inquiry', 'Inquiry' );
		$this->insert_assignment_fixture( 4243, $state_id );

		// Force the actual migration path twice more (not the fast path).
		delete_option( StateFlow\Infrastructure\Database\Schema::VERSION_OPTION );

		for ( $i = 0; $i < 2; $i++ ) {
			$result = $this->runner()->ensure_current();

			$this->assertTrue( $result->is_success(), 'Repeated migration must succeed.' );
		}

		$this->assertSame( 1, $this->runner()->installed_version() );

		$states = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->names()->states() ) );
		$assign = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->names()->assignments() ) );

		$this->assertSame( 1, $states, 'Repeated migration must not duplicate or delete state rows.' );
		$this->assertSame( 1, $assign, 'Repeated migration must not duplicate or delete assignment rows.' );
	}

	/**
	 * §21: activation repeated multiple times preserves data.
	 *
	 * @return void
	 */
	public function test_repeated_activation_preserves_data(): void {
		$wpdb   = $this->wpdb();
		$plugin = \StateFlow\Plugin::instance();

		$plugin->activate();
		$state_id = $this->insert_state_fixture( 'preorder', 'Pre-order' );
		$this->insert_assignment_fixture( 4244, $state_id );

		$plugin->activate();
		$plugin->activate();

		$states = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->names()->states() ) );
		$assign = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->names()->assignments() ) );

		$this->assertSame( 1, $states, 'Repeated activation must preserve state rows.' );
		$this->assertSame( 1, $assign, 'Repeated activation must preserve assignment rows.' );
		$this->assertSame( 1, $this->runner()->installed_version() );
	}

	/**
	 * §22: upgrade path — schema missing while the plugin is active; the
	 * normal initialization path (not activation) brings it current.
	 *
	 * @return void
	 */
	public function test_upgrade_path_via_initialization(): void {
		$wpdb   = $this->wpdb();
		$plugin = \StateFlow\Plugin::instance();

		$plugin->activate();

		$state_id = $this->insert_state_fixture( 'selling', 'Selling' );
		$this->insert_assignment_fixture( 4245, $state_id );

		// Simulate "plugin update on an already-active site": the schema
		// version option is gone (pre-versioned install), tables remain.
		delete_option( StateFlow\Infrastructure\Database\Schema::VERSION_OPTION );
		$this->assertFalse( $this->runner()->is_current() );

		$plugin->initialize();

		$this->assertSame( 1, $this->runner()->installed_version(), 'initialize() must bring the schema current.' );

		$states = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->names()->states() ) );
		$this->assertSame( 1, $states, 'The upgrade path must preserve existing rows.' );
	}

	/**
	 * §23: deactivation preserves tables, rows and the schema version.
	 *
	 * @return void
	 */
	public function test_deactivation_preserves_data(): void {
		$wpdb   = $this->wpdb();
		$plugin = \StateFlow\Plugin::instance();

		$plugin->activate();
		$state_id = $this->insert_state_fixture( 'discontinued', 'Discontinued' );
		$this->insert_assignment_fixture( 4246, $state_id );

		$plugin->deactivate();

		$this->assertNotFalse(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->names()->states() ) ),
			'Deactivation must not drop the states table.'
		);
		$this->assertNotFalse(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->names()->assignments() ) ),
			'Deactivation must not drop the assignments table.'
		);

		$states = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->names()->states() ) );
		$assign = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->names()->assignments() ) );

		$this->assertSame( 1, $states, 'Deactivation must not delete state rows.' );
		$this->assertSame( 1, $assign, 'Deactivation must not delete assignment rows.' );
		$this->assertSame( 1, $this->runner()->installed_version(), 'Deactivation must not reset the schema version.' );
	}

	/**
	 * §32: verification failure must NOT write the schema version — and
	 * recovery (a real migration afterwards) must work. The seam proves
	 * the version is written LAST without damaging a real database.
	 *
	 * @return void
	 */
	public function test_verification_failure_does_not_bump_version(): void {
		// Start from a state where migration will run.
		delete_option( StateFlow\Infrastructure\Database\Schema::VERSION_OPTION );
		$this->drop_stateflow_tables();

		// ONE runner instance carries the failure seam into ensure_current().
		$failing = $this->runner();
		$failing->simulate_verification_failure();

		$result = $failing->ensure_current();

		$this->assertFalse( $result->is_success(), 'A failed verification must report failure.' );
		$this->assertNotEmpty( $result->errors() );
		$this->assertFalse(
			get_option( StateFlow\Infrastructure\Database\Schema::VERSION_OPTION ),
			'The schema version must NOT be written when verification fails.'
		);

		// Recovery: a real runner (fresh seam state) migrates and verifies.
		$recovery = $this->runner()->ensure_current();

		$this->assertTrue( $recovery->is_success(), 'Recovery migration must succeed.' );
		$this->assertSame( 1, $this->runner()->installed_version() );
	}

	/**
	 * §28/§36: with the schema already current, an initialized request adds
	 * ZERO database queries (operations, not wall-clock time). wpdb counts
	 * queries since SAVEQUERIES is enabled in the test bootstrap.
	 *
	 * @return void
	 */
	public function test_fast_path_costs_no_queries(): void {
		$wpdb = $this->wpdb();

		\StateFlow\Plugin::instance()->activate();

		$this->runner()->ensure_current(); // Warm any caches.

		$before = (int) $wpdb->num_queries;

		$result = $this->runner()->ensure_current();

		$after = (int) $wpdb->num_queries;

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->was_already_current(), 'The fast path must classify the schema as current.' );
		$this->assertSame( 0, $after - $before, 'The schema-current fast path must issue zero DB queries.' );
	}

	/**
	 * §15: the migration lock is released after the run and leaves no row.
	 *
	 * @return void
	 */
	public function test_migration_lock_released_after_run(): void {
		$wpdb = $this->wpdb();

		$this->runner()->ensure_current();

		$this->assertFalse(
			get_option( StateFlow\Infrastructure\Database\MigrationLock::OPTION ),
			'The migration lock must be released after the run.'
		);

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT autoload FROM %i WHERE option_name = %s',
				$wpdb->options,
				StateFlow\Infrastructure\Database\MigrationLock::OPTION
			)
		);

		$this->assertNull( $autoload, 'No lock row may remain in the options table.' );
	}

	/**
	 * The current wpdb instance.
	 *
	 * @return wpdb
	 */
	private function wpdb(): wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Prefix-aware table names for the current site.
	 *
	 * @return StateFlow\Infrastructure\Database\TableNames
	 */
	private function names(): StateFlow\Infrastructure\Database\TableNames {
		return StateFlow\Infrastructure\Database\TableNames::from_wpdb( $this->wpdb() );
	}

	/**
	 * A fresh migration runner for the current site.
	 *
	 * @return StateFlow\Infrastructure\Database\MigrationRunner
	 */
	private function runner(): StateFlow\Infrastructure\Database\MigrationRunner {
		return new StateFlow\Infrastructure\Database\MigrationRunner( $this->wpdb() );
	}

	/**
	 * Insert a state definition fixture; returns the new row ID.
	 *
	 * @param string $key  State key.
	 * @param string $name Display name.
	 * @return int
	 */
	private function insert_state_fixture( string $key, string $name ): int {
		$wpdb = $this->wpdb();
		$now  = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			$this->names()->states(),
			array(
				'state_key'   => $key,
				'name'        => $name,
				'description' => 'SF-002 integration fixture',
				'is_enabled'  => 1,
				'is_builtin'  => 0,
				'sort_order'  => 100,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		$this->assertNotFalse( $inserted, 'State fixture insert must succeed.' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert an assignment fixture (with the concurrency primitive).
	 *
	 * @param int $object_id WordPress object ID.
	 * @param int $state_id  StateFlow state ID.
	 * @return bool|int False on a DB-level rejection.
	 */
	private function insert_assignment_fixture( int $object_id, int $state_id ) {
		$wpdb = $this->wpdb();
		$now  = gmdate( 'Y-m-d H:i:s' );

		return $wpdb->insert(
			$this->names()->assignments(),
			array(
				'object_id'  => $object_id,
				'state_id'   => $state_id,
				'version'    => 1,
				'entered_at' => $now,
				'updated_at' => $now,
			)
		);
	}

	/**
	 * Drop StateFlow tables (test lifecycle only, via TableNames).
	 *
	 * @return void
	 */
	private function drop_stateflow_tables(): void {
		$wpdb = $this->wpdb();

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->names()->assignments() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->names()->states() ) );
	}
}
