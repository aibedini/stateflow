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
			$errors = array_merge( $errors, $this->verify_table( $logical, $actual ) );
		}

		return $errors;
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
	 * Verify one table's existence, columns and indexes.
	 *
	 * @param string $logical Logical table name (TableNames constant).
	 * @param string $actual  Fully-qualified table name.
	 * @return array<int, string>
	 */
	private function verify_table( string $logical, string $actual ): array {
		$errors = array();

		$required_columns = Schema::required_columns()[ $logical ] ?? array();
		$required_indexes = Schema::required_indexes()[ $logical ] ?? array();

		$columns = $this->columns( $actual );

		if ( null === $columns ) {
			return array( sprintf( 'Missing table: %s', $actual ) );
		}

		$indexes = $this->indexes( $actual );

		foreach ( $required_columns as $column ) {
			if ( ! array_key_exists( $column, $columns ) ) {
				$errors[] = sprintf( 'Table %s is missing column %s', $actual, $column );
			}
		}

		foreach ( $required_indexes as $index_name => $spec ) {
			$unique = $spec['unique'];
			$first  = $spec['first'];

			if ( ! array_key_exists( $index_name, $indexes ) ) {
				$errors[] = sprintf( 'Table %s is missing index %s', $actual, $index_name );

				continue;
			}

			if ( $unique && 0 !== (int) ( $indexes[ $index_name ]['Non_unique'] ?? '1' ) ) {
				$errors[] = sprintf( 'Table %s index %s must be UNIQUE', $actual, $index_name );
			}

			$first_column = (string) ( $indexes[ $index_name ]['first_column'] ?? '' );

			if ( $first !== $first_column ) {
				$errors[] = sprintf(
					'Table %s index %s must lead with column %s (found %s)',
					$actual,
					$index_name,
					$first,
					'' === $first_column ? '(none)' : $first_column
				);
			}
		}

		return $errors;
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
				$indexes[ $name ] = $normalized + array( 'first_column' => '' );
			}

			if ( '1' === ( $normalized['Seq_in_index'] ?? '' ) ) {
				$indexes[ $name ]['first_column'] = $normalized['Column_name'] ?? '';
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
