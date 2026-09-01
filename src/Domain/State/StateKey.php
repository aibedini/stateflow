<?php
/**
 * Immutable state key value object.
 *
 * A StateKey is the locale-independent identity of a StateFlow State.
 * Keys are lowercase ASCII: first character a-z, then a-z/0-9/-/_ , max 64
 * chars. Absence of an assignment is meaningful on its own — the virtual
 * concepts "normal" and "inherit" are resolver semantics and can never be
 * persisted as real State keys (SF-002 §1/§2).
 *
 * @package StateFlow\Domain\State
 */

declare( strict_types = 1 );

namespace StateFlow\Domain\State;

/**
 * Immutable, locale-independent state key.
 */
final class StateKey {

	/**
	 * Maximum key length.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 64;

	/**
	 * Reserved keys: virtual resolver concepts, never persistable states.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED = array( 'normal', 'inherit' );

	/**
	 * The validated key value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Private: use from_string().
	 *
	 * @param string $value Validated key.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Create a StateKey from raw input. Explicitly rejects invalid input —
	 * never silently normalizes (e.g. "Coming Soon" is not "coming-soon").
	 *
	 * @param string $raw Raw key candidate.
	 * @return self
	 * @throws DomainException When the key violates the format rules or is reserved.
	 */
	public static function from_string( string $raw ): self {
		if ( '' === $raw || strlen( $raw ) > self::MAX_LENGTH ) {
			throw new DomainException( 'State key length must be between 1 and 64 characters.' );
		}

		if ( preg_match( '/^[a-z][a-z0-9_-]*$/', $raw ) !== 1 ) {
			throw new DomainException(
				'State key must be lowercase ASCII: first character a-z, then a-z, 0-9, hyphen or underscore.'
			);
		}

		if ( in_array( $raw, self::RESERVED, true ) ) {
			throw new DomainException( 'This key is reserved for virtual resolver semantics.' );
		}

		return new self( $raw );
	}

	/**
	 * The key value.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Whether this is a reserved virtual key.
	 *
	 * @return bool
	 */
	public function is_reserved(): bool {
		return in_array( $this->value, self::RESERVED, true );
	}

	/**
	 * Value-object identity: two StateKeys with the same value are equal.
	 *
	 * @param object $other Candidate.
	 * @return bool
	 */
	public function equals( object $other ): bool {
		return $other instanceof self && $other->value === $this->value;
	}

	/**
	 * Canonical string form (safe for array keys and SQL values).
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}
}
