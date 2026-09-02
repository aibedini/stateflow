<?php
/**
 * Structural schema verification against the live database.
 *
 * Answers exactly one question (SF-002 §31): is schema VERSION 1 actually
 * usable? Checks structure — tables, columns, indexes — never business
 * data. NOT part of the normal-request fast path; used by migrations,
 * explicit diagnostics and tests.
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

use wpdb;

/**
 * Verifies the live schema against the target definition.
 */
final class SchemaVerifier {

	/**
	 * WordPress database abstraction.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Prefix-aware table names.
	 *
	 * @var TableNames
	 */
	private TableNames $names;

	/**
	 * Verifier constructor.
	 *
	 * @param wpdb       $wpdb  Database abstraction.
	 * @param TableNames $names Table names.
	 */
	public function __construct( wpdb $wpdb, TableNames $names ) {
		$this->wpdb  = $wpdb;
		$this->names = $names;
	}

	/**
	 * Verify tables, columns and indexes against the target schema.
	 *
	 * @return array<int, string> Empty when the schema is usable; otherwise
	 *                            human-readable structural problems.
	 */
	public function verify(): array {
		$errors = array();

		foreach ( $this->tables() as $logical => $actual ) {
			$columns = $this->columns( $actual );

			if ( null === $columns ) {
				$errors[] = sprintf( 'Missing table: %s', $actual );

				continue;
			}

			$errors = array_merge(
				$errors,
				$this->check_table(
					$logical,
					$actual,
					$columns,
					$this->indexes( $actual ),
					Schema::required_indexes()[ $logical ] ?? array(),
					Schema::required_columns()[ $logical ] ?? array()
				)
			);
		}

		return $errors;
	}

	/**
	 * Unit-testable structural seam: run the verification logic against a
	 * caller-provided columns/indexes snapshot (as captured from the live
	 * database by columns()/indexes()). No database is touched.
	 *
	 * @param array<string, array<string, string>> $columns Column rows keyed by name (null-ish table absence is expressed by an empty outer array()).
	 * @param array<string, array<string, string>> $indexes Index rows keyed by name.
	 * @param string                               $logical Logical table name (TableNames constant).
	 * @return array<int, string>
	 */
	public function verify_snapshot( array $columns, array $indexes, string $logical = TableNames::STATES ): array {
		return $this->check_table(
			$logical,
			TableNames::STATES === $logical ? TableNames::STATES : $logical,
			$columns,
			$indexes,
			Schema::required_indexes()[ $logical ] ?? array(),
			Schema::required_columns()[ $logical ] ?? array()
		);
	}

	/**
	 * Logical => actual table name mapping.
	 *
	 * @return array<string, string>
	 */
	private function tables(): array {
		return array(
			TableNames::STATES      => $this->names->states(),
			TableNames::ASSIGNMENTS => $this->names->assignments(),
		);
	}

	/**
	 * Verify one table's existence, columns and indexes against the
	 * required specification (full ordered column sequences).
	 *
	 * @param string                                                          $logical Logical table name (TableNames constant).
	 * @param string                                                          $actual  Fully-qualified table name.
	 * @param array<string, array<string, string>>                            $columns Column rows keyed by name.
	 * @param array<string, array<string, string>>                            $indexes Index rows keyed by name (with per-index 'columns' sequence).
	 * @param array<string, array{unique: bool, columns: array<int, string>}> $required Required index spec.
	 * @param array<int, string>                                              $required_columns Required column names.
	 * @return array<int, string>
	 */
	private function check_table(
		string $logical,
		string $actual,
		array $columns,
		array $indexes,
		array $required,
		array $required_columns
	): array {
		$errors = array();

		foreach ( $required_columns as $column ) {
			if ( ! array_key_exists( $column, $columns ) ) {
				$errors[] = sprintf( 'Table %s is missing column %s', $actual, $column );
			}
		}

		foreach ( $required as $index_name => $spec ) {
			$unique = $spec['unique'];
			$wanted = $spec['columns'];

			if ( ! array_key_exists( $index_name, $indexes ) ) {
				$errors[] = sprintf( 'Table %s is missing index %s', $actual, $index_name );

				continue;
			}

			if ( $unique && 0 !== (int) ( $indexes[ $index_name ]['Non_unique'] ?? '1' ) ) {
				$errors[] = sprintf( 'Table %s index %s must be UNIQUE', $actual, $index_name );
			}

			$actual_columns = $this->index_columns( $indexes[ $index_name ] );

			if ( $wanted !== $actual_columns ) {
				$errors[] = sprintf(
					'Table %s index %s must cover columns [%s] in order (found [%s])',
					$actual,
					$index_name,
					implode( ', ', $wanted ),
					implode( ', ', $actual_columns )
				);
			}
		}

		return $errors;
	}

	/**
	 * The ordered column sequence of one index, from its SHOW INDEX rows
	 * (Seq_in_index 1..n preserved in the row map).
	 *
	 * @param array<string, string> $index_row Aggregated index row.
	 * @return array<int, string>
	 */
	private function index_columns( array $index_row ): array {
		$columns = array();

		foreach ( $index_row as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'column_' ) ) {
				continue;
			}

			$seq = (int) substr( $key, strlen( 'column_' ) );

			if ( $seq < 1 ) {
				continue;
			}

			$columns[ $seq ] = $value;
		}

		ksort( $columns );

		return array_values( $columns );
	}

	/**
	 * Columns of a table, keyed by name. Null when the table does not exist.
	 * The table name is a trusted TableNames value passed through the %i
	 * identifier placeholder (SF-002 §29).
	 *
	 * @param string $table Fully-qualified table name.
	 * @return array<string, array<string, string>>|null
	 */
	private function columns( string $table ): ?array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'DESCRIBE %i', $table ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$columns = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['Field'] ) || ! is_string( $row['Field'] ) ) {
				continue;
			}

			$field = $this->stringify_row( $row );

			if ( null !== $field ) {
				$columns[ $field['Field'] ] = $field;
			}
		}

		return $columns;
	}

	/**
	 * Indexes of a table, keyed by index name, with the first column of
	 * each composite index resolved. Empty when the table does not exist.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return array<string, array<string, string>>
	 */
	private function indexes( string $table ): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SHOW INDEX FROM %i', $table ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$indexes = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$normalized = $this->stringify_row( $row );

			if ( null === $normalized || '' === $normalized['Key_name'] ) {
				continue;
			}

			$name = $normalized['Key_name'];

			if ( ! array_key_exists( $name, $indexes ) ) {
				$indexes[ $name ] = $normalized;
			}

			$seq = (int) ( $normalized['Seq_in_index'] ?? '0' );

			if ( $seq >= 1 ) {
				$indexes[ $name ][ 'column_' . $seq ] = $normalized['Column_name'] ?? '';
			}
		}

		return $indexes;
	}

	/**
	 * Normalize one DB result row to string values. Returns null when the
	 * row carries no usable keys.
	 *
	 * @param array<mixed> $row Raw row.
	 * @return array<string, string>|null
	 */
	private function stringify_row( array $row ): ?array {
		$normalized = array();
		$has_key    = false;
		$count      = 0;

		foreach ( $row as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$normalized[ $key ] = is_scalar( $value ) ? (string) $value : '';
			$has_key            = true;
			++$count;
		}

		return $has_key && $count > 0 ? $normalized : null;
	}
}
