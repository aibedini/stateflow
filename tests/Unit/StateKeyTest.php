<?php
/**
 * StateKey unit tests (pure domain, no WordPress bootstrap needed).
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StateFlow\Domain\State\DomainException;
use StateFlow\Domain\State\StateKey;

/**
 * StateKey invariants.
 */
final class StateKeyTest extends TestCase {

	/**
	 * Valid keys are accepted and preserved verbatim.
	 *
	 * @return void
	 */
	public function test_valid_keys_are_accepted(): void {
		foreach ( array( 'selling', 'coming-soon', 'supplier_stock', 'a1', 'waiting-for-supplier' ) as $raw ) {
			$this->assertSame( $raw, StateKey::from_string( $raw )->value(), "Key '{$raw}' must be accepted." );
		}
	}

	/**
	 * Minimum length is 1 character.
	 *
	 * @return void
	 */
	public function test_minimum_one_character(): void {
		$this->assertSame( 'a', StateKey::from_string( 'a' )->value() );
	}

	/**
	 * Empty keys are rejected.
	 *
	 * @return void
	 */
	public function test_empty_key_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( '' );
	}

	/**
	 * Uppercase is rejected — no silent normalization ("Coming Soon" is
	 * never turned into "coming-soon").
	 *
	 * @return void
	 */
	public function test_uppercase_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( 'Selling' );
	}

	/**
	 * Spaces (inner or around) are rejected — including "Coming Soon".
	 *
	 * @return void
	 */
	public function test_spaces_are_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( 'Coming Soon' );
	}

	/**
	 * A leading digit is rejected (first char must be a-z).
	 *
	 * @return void
	 */
	public function test_leading_number_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( '1abc' );
	}

	/**
	 * Unicode/Persian keys are rejected — no Unicode key names.
	 *
	 * @return void
	 */
	public function test_unicode_key_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( 'موجود' );
	}

	/**
	 * Keys longer than 64 characters are rejected.
	 *
	 * @return void
	 */
	public function test_over_64_chars_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( str_repeat( 'a', 65 ) );
	}

	/**
	 * Exactly 64 characters is allowed.
	 *
	 * @return void
	 */
	public function test_exactly_64_chars_is_allowed(): void {
		$key = StateKey::from_string( str_repeat( 'a', 64 ) );
		$this->assertSame( 64, strlen( $key->value() ) );
	}

	/**
	 * Reserved virtual-resolver keys cannot become persisted states.
	 *
	 * @return void
	 */
	public function test_reserved_normal_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( 'normal' );
	}

	/**
	 * Reserved "inherit" cannot become a persisted state either.
	 *
	 * @return void
	 */
	public function test_reserved_inherit_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( 'inherit' );
	}

	/**
	 * Other special characters are rejected.
	 *
	 * @return void
	 */
	public function test_special_characters_are_rejected(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( 'sell!ng' );
	}

	/**
	 * Value objects are immutable and equal by value.
	 *
	 * @return void
	 */
	public function test_equality_and_immutability(): void {
		$a = StateKey::from_string( 'selling' );
		$b = StateKey::from_string( 'selling' );

		$this->assertTrue( $a->equals( $b ) );
		$this->assertFalse( $a->equals( StateKey::from_string( 'inquiry' ) ) );
		$this->assertSame( 'selling', (string) $a );
		$this->assertFalse( $a->is_reserved() );
	}
}
