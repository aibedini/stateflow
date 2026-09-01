<?php
/**
 * Target schema definitions for StateFlow-owned tables.
 *
 * Single source of truth: CREATE TABLE SQL (dbDelta-compatible), required
 * columns and required indexes. The MigrationRunner applies it, the
 * SchemaVerifier checks the live database against it (SF-002 §5/§6/§31).
 *
 * Design decisions recorded here (SF-002 §4/§8):
 * - No FOREIGN KEY constraints: WordPress/dbDelta portability, table-prefix
 *   environments and plugin lifecycle safety win over DB-level enforcement.
 *   Referential integrity is enforced by repositories/domain services later.
 * - No JSON or generated columns; no indexes beyond the required ones.
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

/**
 * Target schema (version 1).
 */
final class Schema {

	/**
	 * Current StateFlow database schema version.
	 *
	 * NOTE: independent of the plugin release version (STATEFLOW_VERSION).
	 * Plugin version and schema version are different concepts and must
	 * never be inferred from each other (SF-002 §9).
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * Option storing the installed schema version. Autoloaded (tiny) so
	 * the per-request fast path is a cached read + integer compare.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'stateflow_schema_version';

	/**
	 * Required columns of the states table (exact set, SF-002 §5).
	 *
	 * @var array<int, string>
	 */
	public const STATES_COLUMNS = array(
		'id',
		'state_key',
		'name',
		'description',
		'is_enabled',
		'is_builtin',
		'sort_order',
		'created_at',
		'updated_at',
	);

	/**
	 * Required columns of the assignments table (exact set, SF-002 §6).
	 * Deliberately NO parent_id / product_type / variation flag / stock /
	 * price / source / history columns — later layers add their own.
	 *
	 * @var array<int, string>
	 */
	public const ASSIGNMENTS_COLUMNS = array(
		'object_id',
		'state_id',
		'version',
		'entered_at',
		'updated_at',
	);

	/**
	 * Build the dbDelta-compatible CREATE TABLE statements.
	 *
	 * @param TableNames $names           Prefix-aware table names.
	 * @param string     $charset_collate From $wpdb->get_charset_collate().
	 * @return array<string, string> table name => CREATE TABLE statement.
	 */
	public static function create_table_sql( TableNames $names, string $charset_collate ): array {
		$states = "
CREATE TABLE {$names->states()} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	state_key varchar(64) NOT NULL,
	name varchar(191) NOT NULL,
	description text NOT NULL,
	is_enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
	is_builtin tinyint(1) unsigned NOT NULL DEFAULT 0,
	sort_order smallint(5) unsigned NOT NULL DEFAULT 100,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY state_key (state_key),
	KEY enabled_sort (is_enabled, sort_order, id)
) {$charset_collate};";

		$assignments = "
CREATE TABLE {$names->assignments()} (
	object_id bigint(20) unsigned NOT NULL,
	state_id bigint(20) unsigned NOT NULL,
	version bigint(20) unsigned NOT NULL DEFAULT 1,
	entered_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (object_id),
	KEY state_object (state_id, object_id)
) {$charset_collate};";

		return array(
			$names->states()      => $states,
			$names->assignments() => $assignments,
		);
	}

	/**
	 * Required columns per table name.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function required_columns(): array {
		return array(
			TableNames::STATES      => self::STATES_COLUMNS,
			TableNames::ASSIGNMENTS => self::ASSIGNMENTS_COLUMNS,
		);
	}

	/**
	 * Required indexes per table: index name => required attributes.
	 * 'unique' => whether the index must be UNIQUE; 'first' => the column
	 * that must lead a composite index (proves composite ordering).
	 *
	 * @return array<string, array<string, array{unique: bool, first: string}>>
	 */
	public static function required_indexes(): array {
		return array(
			TableNames::STATES      => array(
				'PRIMARY'      => array(
					'unique' => true,
					'first'  => 'id',
				),
				'state_key'    => array(
					'unique' => true,
					'first'  => 'state_key',
				),
				'enabled_sort' => array(
					'unique' => false,
					'first'  => 'is_enabled',
				),
			),
			TableNames::ASSIGNMENTS => array(
				'PRIMARY'      => array(
					'unique' => true,
					'first'  => 'object_id',
				),
				'state_object' => array(
					'unique' => false,
					'first'  => 'state_id',
				),
			),
		);
	}
}
