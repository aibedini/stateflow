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
	 * Invalid UTF-8 is REJECTED deterministically in BOTH counting
	 * environments — via StateDefinition::create(), with no branching on
	 * environment (SF-002.2 §1/§2/§3). Validation happens before any
	 * counting: preg_match('//u') is a strict well-formedness check on
	 * every stock PHP build, shared by both paths.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_is_rejected_on_mbstring_path(): void {
		StateDefinition::$mbstring_override = true;

		$this->expectException( DomainException::class );

		try {
			StateDefinition::create(
				StateKey::from_string( 'a1' ),
				"Selling \xC3\x28 rest" // 0xC3 followed by 0x28: invalid UTF-8.
			);
		} finally {
			StateDefinition::$mbstring_override = null;
		}
	}

	/**
	 * The same rejection on the no-mbstring fallback path — the seam
	 * proves the fallback branch is genuinely exercised (SF-002.2 §4).
	 *
	 * @return void
	 */
	public function test_invalid_utf8_is_rejected_on_fallback_path(): void {
		StateDefinition::$mbstring_override = false;

		$this->expectException( DomainException::class );

		try {
			StateDefinition::create(
				StateKey::from_string( 'a1' ),
				"Selling \xC3\x28 rest"
			);
		} finally {
			StateDefinition::$mbstring_override = null;
		}
	}

	/**
	 * Over-length invalid UTF-8 is still rejected (repeated garbage well
	 * past the 191-character bound) — on the mbstring path.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_repeated_is_rejected_on_mbstring_path(): void {
		StateDefinition::$mbstring_override = true;

		$this->expectException( DomainException::class );

		try {
			StateDefinition::create(
				StateKey::from_string( 'a1' ),
				str_repeat( "\xC3\x28", 500 )
			);
		} finally {
			StateDefinition::$mbstring_override = null;
		}
	}

	/**
	 * The fallback counting path produces exact code-point counts on
	 * valid Persian text — proving the seam-exercised fallback is correct,
	 * not just present (SF-002.2 §4).
	 *
	 * @return void
	 */
	public function test_fallback_path_counts_persian_code_points_exactly(): void {
		StateDefinition::$mbstring_override = false;

		try {
			$d = StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'ف', 191 ) );

			$this->assertSame( 191, mb_strlen( $d->name(), 'UTF-8' ) );

			// One more code point must fail on the same path.
			$this->expectException( DomainException::class );
			StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'ف', 192 ) );
		} finally {
			StateDefinition::$mbstring_override = null;
		}
	}

	/**
	 * The mbstring counting path produces exact code-point counts on
	 * valid Persian text as well (both counting paths agree).
	 *
	 * @return void
	 */
	public function test_mbstring_path_counts_persian_code_points_exactly(): void {
		StateDefinition::$mbstring_override = true;

		try {
			$d = StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'ف', 191 ) );

			$this->assertSame( 191, mb_strlen( $d->name(), 'UTF-8' ) );
		} finally {
			StateDefinition::$mbstring_override = null;
		}
	}
}
