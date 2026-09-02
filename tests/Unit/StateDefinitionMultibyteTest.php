<?php
/**
 * StateDefinition multibyte length tests (SF-002.1 §2, SF-002.3 §1).
 *
 * Persian/multibyte strings are counted in Unicode code points, not UTF-8
 * bytes. One deterministic implementation: strict preg_match('//u')
 * validation (malformed UTF-8 rejected) + exact lead-byte counting on
 * valid UTF-8. Pure domain: no WordPress bootstrap, no test seams, no
 * mutation of production static state.
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
	 * 3-byte code points (CJK) count exactly: 191 CJK characters are
	 * valid, 192 are rejected.
	 *
	 * @return void
	 */
	public function test_three_byte_code_points_are_counted_exactly(): void {
		$d = StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( '售', 191 ) );

		$this->assertSame( 191, mb_strlen( $d->name(), 'UTF-8' ) );

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( '售', 192 ) );
	}

	/**
	 * 4-byte code points (outside the BMP, e.g. emoji) count exactly: 191
	 * are valid, 192 are rejected.
	 *
	 * @return void
	 */
	public function test_four_byte_code_points_are_counted_exactly(): void {
		$d = StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( '𝕏', 191 ) );

		$this->assertSame( 191, mb_strlen( $d->name(), 'UTF-8' ) );

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( '𝕏', 192 ) );
	}

	/**
	 * Mixed Persian/Latin content is measured in code points.
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
	 * Invalid UTF-8 in a merchant-facing field is rejected explicitly —
	 * never silently truncated, substituted or re-encoded (SF-002.2/§2.3).
	 * The single shared validation step runs before counting, so this
	 * test is environment-independent by construction.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_is_rejected(): void {
		$this->expectException( DomainException::class );

		StateDefinition::create(
			StateKey::from_string( 'a1' ),
			"Selling \xC3\x28 rest" // 0xC3 followed by 0x28: invalid UTF-8.
		);
	}

	/**
	 * The same rejection for descriptions.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_description_is_rejected(): void {
		$this->expectException( DomainException::class );

		StateDefinition::create(
			StateKey::from_string( 'a1' ),
			'A',
			"توضیح \xB1\x21 invalid" // Lone continuation byte 0xB1: invalid UTF-8.
		);
	}

	/**
	 * Over-length invalid UTF-8 (repeated malformed pairs, far past the
	 * 191-character bound) is rejected by the validation step — regardless
	 * of what any counter would have reported.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_repeated_is_rejected(): void {
		$this->expectException( DomainException::class );

		StateDefinition::create(
			StateKey::from_string( 'a1' ),
			str_repeat( "\xC3\x28", 500 )
		);
	}

	/**
	 * No test-only mutable production state exists on the domain object.
	 *
	 * @return void
	 */
	public function test_no_mutable_test_seam_exists(): void {
		$reflection = new \ReflectionClass( StateDefinition::class );

		$static_props = $reflection->getProperties(
			\ReflectionProperty::IS_STATIC | \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED
		);

		$names = array();

		foreach ( $static_props as $property ) {
			$names[] = $property->getName();
		}

		$this->assertSame(
			array(),
			$names,
			'StateDefinition must expose no mutable static test state.'
		);
	}
}
