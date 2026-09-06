<?php
/**
 * Scriptable wpdb double for pure unit tests.
 *
 * The unit harness has no WordPress; repositories receive wpdb by
 * constructor type-hint. This double lets tests script the exact result
 * rows for each get_results() call (in order) and record every query —
 * enough to prove batching, keying, and failure behavior WITHOUT a
 * database. With no script, every query method throws, proving the
 * seams stay database-free (SchemaVerifier snapshot tests rely on that).
 *
 * @package StateFlow\Tests
 */

declare( strict_types = 1 );

// The lowercase class name mirrors WordPress core's own (forced by the
// production type-hints); PEAR capitalization does not apply to a
// simulated third-party core class in the test harness.
// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.NamingConventions.CamelCapsFunctionName, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
/**
 * Scriptable wpdb stand-in.
 */
class wpdb {
	// phpcs:enable

	/**
	 * Prefix (harness value; irrelevant to unit seams).
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Last database error as read by production code.
	 *
	 * @var string
	 */
	public string $last_error = '';

	/**
	 * Scripted database error (drives a get_results() failure).
	 *
	 * @var string
	 */
	public static string $static_last_error = '';

	/**
	 * Queries recorded by prepare()/get_results().
	 *
	 * @var array<int, string>
	 */
	public static array $queries = array();

	/**
	 * Scripted result sets: one entry (array|null) per get_results() call.
	 *
	 * @var array<int, array<int, array<string, mixed>>|null>|null
	 */
	public static ?array $results = array();

	/**
	 * Reserved for symmetric future use; always null.
	 *
	 * @var array<int, array<int, array<string, mixed>>>|null
	 */
	public static ?array $next_results = null;

	/**
	 * Constructor mirrors the real wpdb signature (unused here but keeps
	 * direct instantiation valid for static analysis).
	 *
	 * @param string $dbuser     Unused.
	 * @param string $dbpassword Unused.
	 * @param string $dbname     Unused.
	 * @param string $dbhost     Unused.
	 */
	public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {
		unset( $dbuser, $dbpassword, $dbname, $dbhost );
	}

	/**
	 * Record and substitute like the real prepare(): %d consumes ints,
	 * %s strings, %i quoted identifiers.
	 *
	 * @param string      $query Query with placeholders.
	 * @param array|mixed $args  First argument (array) or first value.
	 * @param mixed       ...$rest Remaining values.
	 * @return string
	 */
	public function prepare( $query, $args = array(), ...$rest ) {
		self::$queries[] = $query;

		$values = is_array( $args ) ? $args : array_merge( array( $args ), $rest );
		$out    = '';
		$pos    = 0;
		$idx    = 0;
		$len    = strlen( $query );

		while ( $pos < $len ) {
			$next = strpos( $query, '%', $pos );

			if ( false === $next ) {
				$out .= substr( $query, $pos );

				break;
			}

			$out  .= substr( $query, $pos, $next - $pos );
			$spec  = $query[ $next + 1 ] ?? '%';
			$value = $values[ $idx ] ?? null;

			if ( 'd' === $spec ) {
				$out .= (string) (int) $value;
				++$idx;
			} elseif ( 's' === $spec ) {
				$out .= "'" . addslashes( (string) $value ) . "'";
				++$idx;
			} elseif ( 'i' === $spec ) {
				$out .= '`' . (string) $value . '`';
				++$idx;
			} else {
				$out .= '%%';
			}

			$pos = $next + 2;
		}

		return $out;
	}

	/**
	 * Consume the next scripted result set.
	 *
	 * @param string|null $query  Unused.
	 * @param string|null $output Output mode (unused; ARRAY_A shape).
	 * @return array<int, array<string, mixed>>|null
	 * @throws LogicException When no result was scripted.
	 */
	public function get_results( $query = null, $output = null ) {
		unset( $query, $output );

		$this->last_error = self::$static_last_error;

		if ( null === self::$results || array() === self::$results ) {
			throw new LogicException( 'No scripted wpdb result for this test; script results via wpdb::$results.' );
		}

		$next = array_shift( self::$results );

		// Simulate the real wpdb: a failed query leaves last_error set and
		// returns null.
		if ( null === $next && '' !== $this->last_error ) {
			return null;
		}

		return is_array( $next ) ? $next : array();
	}
}
