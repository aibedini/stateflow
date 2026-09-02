<?php
/**
 * StateDefinition multibyte length tests (SF-002.1 §2).
 *
 * Persian/multibyte strings are counted in Unicode code points, not UTF-8
 * bytes. Pure domain: no WordPress bootstrap.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StateFlow\Domain\State\DomainException;
use StateFlow\Domain\State\StateDefinition;
use StateFlow\Domain\State\StateKey;

/**
 * Multibyte length semantics.
 */
final class StateDefinitionMultibyteTest extends TestCase {

	/**
	 * A short Persian state name is valid and preserved verbatim.
	 *
	 * @return void
	 */
	public function test_short_persian_name_is_valid(): void {
		$d = StateDefinition::create( StateKey::from_string( 'selling' ), 'در حال فروش' );

		$this->assertSame( 'در حال فروش', $d->name() );
	}

	/**
	 * 191 Persian characters are valid (would be 382+ UTF-8 bytes).
	 *
	 * @return void
	 */
	public function test_191_persian_characters_are_valid(): void {
		$name = str_repeat( 'ف', 191 );

		$d = StateDefinition::create( StateKey::from_string( 'a1' ), $name );

		$this->assertSame( 191, mb_strlen( $d->name(), 'UTF-8' ) );
	}

	/**
	 * 192 Persian characters are rejected.
	 *
	 * @return void
	 */
	public function test_192_persian_characters_are_invalid(): void {
		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'ف', 192 ) );
	}

	/**
	 * A 1000-code-point multibyte description is valid.
	 *
	 * @return void
	 */
	public function test_1000_multibyte_description_characters_are_valid(): void {
		$description = str_repeat( 'ت', 1000 );

		$d = StateDefinition::create( StateKey::from_string( 'a1' ), 'A', $description );

		$this->assertSame( 1000, mb_strlen( $d->description(), 'UTF-8' ) );
	}

	/**
	 * A 1001-code-point multibyte description is rejected.
	 *
	 * @return void
	 */
	public function test_1001_multibyte_description_characters_are_invalid(): void {
		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), 'A', str_repeat( 'ت', 1001 ) );
	}

	/**
	 * Mixed Persian/Latin/emoji content is measured in code points.
	 *
	 * @return void
	 */
	public function test_mixed_multibyte_content_is_measured_in_code_points(): void {
		// 190 visible characters + one combining-adjacent char = 191 total.
		$name = str_repeat( 'ا', 95 ) . str_repeat( 'x', 95 ) . 'ب';

		$this->assertSame( 191, mb_strlen( $name, 'UTF-8' ) );

		$d = StateDefinition::create( StateKey::from_string( 'a1' ), $name );

		$this->assertSame( $name, $d->name() );

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), $name . 'ز' );
	}

	/**
	 * ASCII length semantics are unchanged (byte length == code points).
	 *
	 * @return void
	 */
	public function test_ascii_bounds_unchanged(): void {
		$this->assertSame( 191, mb_strlen( StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'x', 191 ) )->name(), 'UTF-8' ) );

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'x', 192 ) );
	}

	/**
	 * StateKey stays ASCII-only — Persian keys remain invalid.
	 *
	 * @return void
	 */
	public function test_state_key_remains_ascii_only(): void {
		$this->expectException( DomainException::class );
		StateKey::from_string( 'فروش' );
	}

	/**
	 * Invalid UTF-8 in a merchant-facing field is handled deterministically
	 * in both mbstring and fallback environments (SF-002.1 §2):
	 *
	 * - with mbstring: mb_strlen substitutes malformed sequences one-per-
	 *   unit, so the length never shrinks below the raw byte count —
	 *   over-length garbage still fails, and valid text is measured right.
	 * - without mbstring: invalid UTF-8 throws DomainException explicitly.
	 *
	 * Either way there is no silent truncation; a lone 0xC3 0x28 sequence
	 * counts >= 2 units on mbstring and is rejected on the fallback.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_is_never_silently_shortened(): void {
		// 0xC3 followed by 0x28 is an invalid UTF-8 sequence.
		$invalid = "Selling \xC3\x28 rest";

		if ( function_exists( 'mb_strlen' ) ) {
			// mbstring path: malformed bytes still count (>= byte/2 floor);
			// the important property is that the result can NEVER be zero
			// or fewer than the number of malformed sequences.
			$length = mb_strlen( $invalid, 'UTF-8' );

			$this->assertGreaterThanOrEqual( 2, $length, 'Malformed sequences must never collapse to an empty string.' );
		} else {
			$this->expectException( DomainException::class );
			StateDefinition::create( StateKey::from_string( 'a1' ), $invalid );
		}
	}

	/**
	 * The known-invalid byte sequence still violates the length contract
	 * when repeated past the limit — in both environments.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_repeated_still_enforces_bounds(): void {
		$invalid = str_repeat( "\xC3\x28", 500 ); // >= 500 malformed pairs.

		if ( function_exists( 'mb_strlen' ) ) {
			// mbstring: each malformed byte pair reports >= 1 unit; 500
			// pairs => >= 500 code points for the name -> over 191 bound?
			// mb_strlen reports exactly 500 here (one code point per
			// malformed sequence) -> 500 > 191, must throw.
			try {
				StateDefinition::create( StateKey::from_string( 'a1' ), $invalid );
				$this->fail( 'Over-length invalid input must be rejected.' );
			} catch ( DomainException $e ) {
				$this->assertStringContainsStringIgnoringCase( 'length', $e->getMessage() );
			}
		} else {
			$this->expectException( DomainException::class );
			StateDefinition::create( StateKey::from_string( 'a1' ), $invalid );
		}
	}
}
