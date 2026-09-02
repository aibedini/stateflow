<?php
/**
 * State definition domain object (pure PHP).
 *
 * Knows nothing about WordPress, WooCommerce, wpdb, options, hooks or
 * translation functions (SF-002 §3). Behavior/config (price, purchase,
 * CTA, visibility, badge, messages, replacement products) is intentionally
 * NOT part of SF-002 — no generic settings blob is pre-designed here.
 *
 * @package StateFlow\Domain\State
 */

declare( strict_types = 1 );

namespace StateFlow\Domain\State;

/**
 * A StateFlow State definition.
 */
final class StateDefinition {

	/**
	 * Maximum name length (matches the varchar(191) column).
	 *
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 191;

	/**
	 * Maximum description length: a bounded, UI-oriented sentence or two —
	 * not an arbitrary data blob.
	 *
	 * @var int
	 */
	public const MAX_DESCRIPTION_LENGTH = 1000;

	/**
	 * Unicode-aware length in code points, with ONE deterministic UTF-8
	 * validation step shared by BOTH environments (SF-002.3):
	 *
	 * - validation: preg_match('//u') is a strict UTF-8 well-formedness
	 *   check; invalid input throws DomainException — identically with or
	 *   without mbstring. No silent substitution, no silent truncation.
	 * - counting: exact lead-byte count (equals the code-point count on
	 *   valid UTF-8).
	 *
	 * @param string $text UTF-8 text.
	 * @return int Number of Unicode code points.
	 * @throws DomainException When the text is not valid UTF-8.
	 */
	private static function code_point_length( string $text ): int {
		// Strict UTF-8 well-formedness check — identical in both
		// environments, before any counting (SF-002.3 §1).
		if ( 1 !== preg_match( '//u', $text ) ) {
			throw new DomainException( 'State text must be valid UTF-8.' );
		}

		// Valid UTF-8: walk the sequences, deriving each sequence length from
		// its lead-byte pattern (1/2/3/4-byte forms). The validation above
		// guarantees well-formedness, so each step lands on a lead byte.
		$count    = 0;
		$bytes    = strlen( $text );
		$position = 0;

		while ( $position < $bytes ) {
			$byte = ord( $text[ $position ] );

			if ( 0xF0 === ( $byte & 0xF8 ) ) {
				$sequence_length = 4;
			} elseif ( 0xE0 === ( $byte & 0xF0 ) ) {
				$sequence_length = 3;
			} elseif ( 0xC0 === ( $byte & 0xE0 ) ) {
				$sequence_length = 2;
			} else {
				$sequence_length = 1;
			}

			$position += $sequence_length;
			++$count;
		}

		return $count;
	}

	/**
	 * Sort order bounds (smallint unsigned column).
	 *
	 * @var int
	 */
	public const MIN_SORT_ORDER = 0;

	/**
	 * Maximum sort order.
	 *
	 * @var int
	 */
	public const MAX_SORT_ORDER = 65535;

	/**
	 * Persistence ID; null for an unpersisted definition.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The immutable state key.
	 *
	 * @var StateKey
	 */
	private StateKey $key;

	/**
	 * Trimmed, non-empty display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Optional bounded description.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * Whether the state is enabled.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Whether the state is a built-in definition.
	 *
	 * @var bool
	 */
	private bool $builtin;

	/**
	 * Sort order (0-65535).
	 *
	 * @var int
	 */
	private int $sort_order;

	/**
	 * Private: use create().
	 *
	 * @param StateKey $key         Immutable state key.
	 * @param string   $name        Display name (trimmed, non-empty, <= 191 chars).
	 * @param string   $description Optional description (<= 1000 chars).
	 * @param bool     $enabled     Enabled flag.
	 * @param bool     $builtin     Built-in flag.
	 * @param int      $sort_order  Sort order 0-65535.
	 * @param int|null $id          Persistence ID (null = unpersisted).
	 */
	private function __construct(
		StateKey $key,
		string $name,
		string $description,
		bool $enabled,
		bool $builtin,
		int $sort_order,
		?int $id
	) {
		$this->key         = $key;
		$this->name        = $name;
		$this->description = $description;
		$this->enabled     = $enabled;
		$this->builtin     = $builtin;
		$this->sort_order  = $sort_order;
		$this->id          = $id;
	}

	/**
	 * Create a (possibly unpersisted) definition.
	 *
	 * @param StateKey $key         State key.
	 * @param string   $name        Display name.
	 * @param string   $description Optional description.
	 * @param bool     $enabled     Enabled flag (default true).
	 * @param bool     $builtin     Built-in flag (default false).
	 * @param int      $sort_order  Sort order (default 100).
	 * @param int|null $id          Persistence ID.
	 * @return self
	 * @throws DomainException On any invariant violation.
	 */
	public static function create(
		StateKey $key,
		string $name,
		string $description = '',
		bool $enabled = true,
		bool $builtin = false,
		int $sort_order = 100,
		?int $id = null
	): self {
		$name = trim( $name );

		if ( '' === $name ) {
			throw new DomainException( 'State name must not be empty.' );
		}

		if ( self::code_point_length( $name ) > self::MAX_NAME_LENGTH ) {
			throw new DomainException( 'State name exceeds the maximum allowed length.' );
		}

		$description = trim( $description );

		if ( self::code_point_length( $description ) > self::MAX_DESCRIPTION_LENGTH ) {
			throw new DomainException( 'State description exceeds the maximum allowed length.' );
		}

		if ( $sort_order < self::MIN_SORT_ORDER || $sort_order > self::MAX_SORT_ORDER ) {
			throw new DomainException( 'Sort order is outside the allowed range.' );
		}

		if ( null !== $id && $id < 1 ) {
			throw new DomainException( 'Persisted ID must be a positive integer.' );
		}

		return new self( $key, $name, $description, $enabled, $builtin, $sort_order, $id );
	}

	/**
	 * Persistence ID (null for an unpersisted definition).
	 *
	 * @return int|null
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * The immutable state key.
	 *
	 * @return StateKey
	 */
	public function key(): StateKey {
		return $this->key;
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Description (may be empty).
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Enabled flag.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Built-in flag.
	 *
	 * @return bool
	 */
	public function is_builtin(): bool {
		return $this->builtin;
	}

	/**
	 * Sort order.
	 *
	 * @return int
	 */
	public function sort_order(): int {
		return $this->sort_order;
	}

	/**
	 * Derived copy with a persistence ID (used once a row is stored).
	 * Immutable-style: never mutates the original.
	 *
	 * @param int $id Positive persistence ID.
	 * @return self
	 * @throws DomainException When the ID is not positive.
	 */
	public function with_id( int $id ): self {
		if ( $id < 1 ) {
			throw new DomainException( 'Persisted ID must be a positive integer.' );
		}

		return new self(
			$this->key,
			$this->name,
			$this->description,
			$this->enabled,
			$this->builtin,
			$this->sort_order,
			$id
		);
	}

	/**
	 * Value-object identity: same key means same State identity.
	 *
	 * @param object $other Candidate.
	 * @return bool
	 */
	public function equals( object $other ): bool {
		return $other instanceof self && $other->key()->equals( $this->key );
	}
}
