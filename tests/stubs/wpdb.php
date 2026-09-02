<?php
/**
 * Test stub of the wpdb class for pure unit tests.
 *
 * The unit harness has no WordPress; SchemaVerifier's structural seam
 * (verify_snapshot) never calls wpdb, but its constructor type-hints it.
 * The class name MUST stay lowercase `wpdb` to satisfy the production
 * type-hints (WP core names this class lowercase). Every query method
 * throws if actually invoked, proving the seam keeps unit tests
 * database-free.
 *
 * @package StateFlow\Tests
 */

declare( strict_types = 1 );

// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.NamingConventions.CamelCapsFunctionName -- mirrors the real (lowercase) WordPress core class name, required by the production type-hints; applies to the stub declaration below.
/**
 * Minimal wpdb stand-in: instantiable, never queried in unit tests.
 */
class wpdb {
	// phpcs:enable

	/**
	 * Prefix (harness value; irrelevant to the snapshot seam).
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Query methods must never be reached in unit tests.
	 *
	 * @param string|null $query  Query.
	 * @param string|null $output Output mode.
	 * @return never
	 * @throws LogicException Always: unit tests must not touch wpdb.
	 */
	public function get_results( $query = null, $output = null ) {
		unset( $query, $output );

		throw new LogicException( 'Unit tests must not touch wpdb; use the verify_snapshot() seam.' );
	}
}
