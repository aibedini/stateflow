<?php
/**
 * SchemaVerifier composite-index ordering tests (SF-002.1 §3).
 *
 * Uses the verify_snapshot() structural seam: full ordered column
 * sequences are checked against synthetic SHOW INDEX-shaped snapshots —
 * no database is touched. The real MySQL/MariaDB integration suite keeps
 * proving the production schema itself verifies cleanly.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StateFlow\Infrastructure\Database\Schema;
use StateFlow\Infrastructure\Database\SchemaVerifier;
use StateFlow\Infrastructure\Database\TableNames;
use wpdb;

/**
 * Composite-index ordering verification.
 */
final class SchemaIndexOrderTest extends TestCase {

	/**
	 * A verifier instance (wpdb is never touched via verify_snapshot()).
	 *
	 * @var SchemaVerifier
	 */
	private SchemaVerifier $verifier;

	/**
	 * Build the verifier with a never-called wpdb double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->verifier = new SchemaVerifier( $this->never_wpdb(), new TableNames( 'wp_' ) );
	}

	/**
	 * The full, correctly ordered states index set verifies cleanly.
	 *
	 * @return void
	 */
	public function test_correct_ordered_indexes_pass(): void {
		$indexes = $this->states_snapshot(
			array( 'id' ),
			array( 'state_key' ),
			array( 'is_enabled', 'sort_order', 'id' )
		);

		$this->assertSame(
			array(),
			$this->verifier->verify_snapshot( $this->states_columns(), $indexes ),
			'The exact VERSION 1 index set must verify without errors.'
		);
	}

	/**
	 * A wrongly ordered composite index (sort_order/id swapped) is
	 * rejected.
	 *
	 * @return void
	 */
	public function test_wrong_composite_order_is_rejected(): void {
		$indexes = $this->states_snapshot(
			array( 'id' ),
			array( 'state_key' ),
			array( 'is_enabled', 'id', 'sort_order' ) // Wrong ordering.
		);

		$errors = $this->verifier->verify_snapshot( $this->states_columns(), $indexes );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'enabled_sort', implode( "\n", $errors ) );
	}

	/**
	 * An incomplete composite index (missing the third column) is
	 * rejected.
	 *
	 * @return void
	 */
	public function test_incomplete_composite_index_is_rejected(): void {
		$indexes = $this->states_snapshot(
			array( 'id' ),
			array( 'state_key' ),
			array( 'is_enabled', 'sort_order' ) // Missing trailing id.
		);

		$errors = $this->verifier->verify_snapshot( $this->states_columns(), $indexes );

		$this->assertNotEmpty( $errors );
	}

	/**
	 * A wrong first column is rejected.
	 *
	 * @return void
	 */
	public function test_wrong_first_column_is_rejected(): void {
		$indexes = $this->states_snapshot(
			array( 'id' ),
			array( 'state_key' ),
			array( 'sort_order', 'is_enabled', 'id' )
		);

		$errors = $this->verifier->verify_snapshot( $this->states_columns(), $indexes );

		$this->assertNotEmpty( $errors );
	}

	/**
	 * A missing index is rejected.
	 *
	 * @return void
	 */
	public function test_missing_index_is_rejected(): void {
		$indexes = $this->states_snapshot(
			array( 'id' ),
			array( 'state_key' ),
			null // The enabled_sort index is entirely absent.
		);

		$errors = $this->verifier->verify_snapshot( $this->states_columns(), $indexes );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'missing index enabled_sort', implode( "\n", $errors ) );
	}

	/**
	 * Wrong uniqueness is rejected.
	 *
	 * @return void
	 */
	public function test_wrong_uniqueness_is_rejected(): void {
		$indexes = $this->states_snapshot(
			array( 'id' ),
			array( 'state_key' ),
			array( 'is_enabled', 'sort_order', 'id' )
		);

		// state_key must be UNIQUE: mark it non-unique.
		$indexes['state_key']['Non_unique'] = '1';

		$errors = $this->verifier->verify_snapshot( $this->states_columns(), $indexes );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'must be UNIQUE', implode( "\n", $errors ) );
	}

	/**
	 * The same rejection logic applies to the assignments table
	 * (state_id/object_id order).
	 *
	 * @return void
	 */
	public function test_assignments_composite_order_is_enforced(): void {
		$correct = array(
			'PRIMARY'      => $this->index_row( 'PRIMARY', true, array( 'object_id' ) ),
			'state_object' => $this->index_row( 'state_object', false, array( 'state_id', 'object_id' ) ),
		);

		$this->assertSame(
			array(),
			$this->verifier->verify_snapshot(
				$this->assignments_columns(),
				$correct,
				TableNames::ASSIGNMENTS
			)
		);

		$swapped = array(
			'PRIMARY'      => $this->index_row( 'PRIMARY', true, array( 'object_id' ) ),
			'state_object' => $this->index_row( 'state_object', false, array( 'object_id', 'state_id' ) ),
		);

		$errors = $this->verifier->verify_snapshot(
			$this->assignments_columns(),
			$swapped,
			TableNames::ASSIGNMENTS
		);

		$this->assertNotEmpty( $errors, 'A swapped state_object order must be rejected.' );
	}

	/**
	 * The required-index specification describes the full sequences.
	 *
	 * @return void
	 */
	public function test_required_index_spec_carry_full_sequences(): void {
		$spec = Schema::required_indexes();

		$this->assertSame(
			array( 'is_enabled', 'sort_order', 'id' ),
			$spec[ TableNames::STATES ]['enabled_sort']['columns']
		);
		$this->assertSame(
			array( 'state_id', 'object_id' ),
			$spec[ TableNames::ASSIGNMENTS ]['state_object']['columns']
		);
	}

	/**
	 * Synthetic states index snapshot in SHOW INDEX row shape.
	 *
	 * @param array<int, string>      $primary     PRIMARY columns.
	 * @param array<int, string>      $state_key   state_key columns.
	 * @param array<int, string>|null $enabled_sort enabled_sort columns (null = missing).
	 * @return array<string, array<string, string>>
	 */
	private function states_snapshot( array $primary, array $state_key, ?array $enabled_sort ): array {
		$indexes = array(
			'PRIMARY'   => $this->index_row( 'PRIMARY', true, $primary ),
			'state_key' => $this->index_row( 'state_key', true, $state_key ),
		);

		if ( null !== $enabled_sort ) {
			$indexes['enabled_sort'] = $this->index_row( 'enabled_sort', false, $enabled_sort );
		}

		return $indexes;
	}

	/**
	 * One aggregated index row (the SchemaVerifier::indexes() output shape:
	 * row fields + column_<seq> entries).
	 *
	 * @param string             $name    Index name.
	 * @param bool               $unique  Unique flag.
	 * @param array<int, string> $columns Ordered column sequence.
	 * @return array<string, string>
	 */
	private function index_row( string $name, bool $unique, array $columns ): array {
		$row = array(
			'Key_name'   => $name,
			'Non_unique' => $unique ? '0' : '1',
		);

		foreach ( array_values( $columns ) as $seq => $column ) {
			$row[ 'column_' . ( $seq + 1 ) ] = $column;
		}

		return $row;
	}

	/**
	 * Synthetic states column map.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function states_columns(): array {
		$columns = array();

		foreach ( Schema::STATES_COLUMNS as $name ) {
			$columns[ $name ] = array( 'Field' => $name );
		}

		return $columns;
	}

	/**
	 * Synthetic assignments column map.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function assignments_columns(): array {
		$columns = array();

		foreach ( Schema::ASSIGNMENTS_COLUMNS as $name ) {
			$columns[ $name ] = array( 'Field' => $name );
		}

		return $columns;
	}

	/**
	 * A wpdb instance whose query methods must never be reached by
	 * verify_snapshot(). Uses the unit-harness stub (tests/stubs/wpdb.php),
	 * whose get_results() throws.
	 *
	 * @return wpdb
	 */
	private function never_wpdb(): wpdb {
		return new wpdb( 'user', 'pass', 'db', 'host' );
	}
}
