<?php
/**
 * Prefix-aware StateFlow table names.
 *
 * The single trusted source for StateFlow table names (SF-002 §11/§29):
 * raw SQL must take names from here, never from user input, and the site
 * prefix always comes from $wpdb->prefix — never hard-coded.
 *
 * @package StateFlow\Infrastructure\Database
 */

declare( strict_types = 1 );

namespace StateFlow\Infrastructure\Database;

use wpdb;

/**
 * Resolves StateFlow-owned table names.
 */
final class TableNames {

	/**
	 * Logical table suffixes (prefixed with the site prefix at runtime).
	 *
	 * @var string
	 */
	public const STATES = 'stateflow_states';

	/**
	 * Assignments table suffix.
	 *
	 * @var string
	 */
	public const ASSIGNMENTS = 'stateflow_assignments';

	/**
	 * The current site database prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * TableNames constructor.
	 *
	 * @param string $prefix Site prefix (from $wpdb->prefix).
	 */
	public function __construct( string $prefix ) {
		$this->prefix = $prefix;
	}

	/**
	 * Build from the current wpdb instance.
	 *
	 * @param wpdb $wpdb WordPress database abstraction.
	 * @return self
	 */
	public static function from_wpdb( wpdb $wpdb ): self {
		return new self( $wpdb->prefix );
	}

	/**
	 * Fully-qualified StateFlow states table name.
	 *
	 * @return string
	 */
	public function states(): string {
		return $this->prefix . self::STATES;
	}

	/**
	 * Fully-qualified StateFlow assignments table name.
	 *
	 * @return string
	 */
	public function assignments(): string {
		return $this->prefix . self::ASSIGNMENTS;
	}
}
