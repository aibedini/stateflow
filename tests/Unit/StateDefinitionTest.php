<?php
/**
 * StateDefinition unit tests (pure domain, no WordPress bootstrap).
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
 * StateDefinition invariants.
 */
final class StateDefinitionTest extends TestCase {

	/**
	 * A minimal, valid definition.
	 *
	 * @return StateDefinition
	 */
	private function valid(): StateDefinition {
		return StateDefinition::create( StateKey::from_string( 'selling' ), 'Selling' );
	}

	/**
	 * Defaults are applied: enabled, not builtin, sort 100, no ID, empty description.
	 *
	 * @return void
	 */
	public function test_defaults(): void {
		$d = $this->valid();

		$this->assertTrue( $d->is_enabled() );
		$this->assertFalse( $d->is_builtin() );
		$this->assertSame( 100, $d->sort_order() );
		$this->assertNull( $d->id() );
		$this->assertSame( '', $d->description() );
	}

	/**
	 * Names are trimmed.
	 *
	 * @return void
	 */
	public function test_name_is_trimmed(): void {
		$d = StateDefinition::create( StateKey::from_string( 'selling' ), "  Selling \t" );

		$this->assertSame( 'Selling', $d->name() );
	}

	/**
	 * Whitespace-only names are rejected after trimming.
	 *
	 * @return void
	 */
	public function test_whitespace_only_name_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'selling' ), '   ' );
	}

	/**
	 * Empty names are rejected.
	 *
	 * @return void
	 */
	public function test_empty_name_is_rejected(): void {
		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'selling' ), '' );
	}

	/**
	 * Names over 191 characters are rejected; exactly 191 is allowed.
	 *
	 * @return void
	 */
	public function test_name_length_bounds(): void {
		$exactly = StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'x', 191 ) );
		$this->assertSame( 191, strlen( $exactly->name() ) );

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), str_repeat( 'x', 192 ) );
	}

	/**
	 * Descriptions may be empty and are bounded.
	 *
	 * @return void
	 */
	public function test_description_bounds(): void {
		$ok = StateDefinition::create( StateKey::from_string( 'a1' ), 'A', str_repeat( 'd', 1000 ) );
		$this->assertSame( 1000, strlen( $ok->description() ) );

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), 'A', str_repeat( 'd', 1001 ) );
	}

	/**
	 * Sort-order lower bound is enforced (-1 rejected).
	 *
	 * @return void
	 */
	public function test_sort_order_bounds(): void {
		$this->assertSame( 0, StateDefinition::create( StateKey::from_string( 'a1' ), 'A', '', true, false, 0 )->sort_order() );
		$this->assertSame( 65535, StateDefinition::create( StateKey::from_string( 'a1' ), 'A', '', true, false, 65535 )->sort_order() );

		try {
			StateDefinition::create( StateKey::from_string( 'a1' ), 'A', '', true, false, -1 );
			$this->fail( 'Negative sort order must be rejected.' );
		} catch ( DomainException $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'a1' ), 'A', '', true, false, 65536 );
	}

	/**
	 * Unpersisted definitions carry a null ID.
	 *
	 * @return void
	 */
	public function test_new_definition_has_null_id(): void {
		$this->assertNull( $this->valid()->id() );
	}

	/**
	 * Persisted definitions carry a positive ID.
	 *
	 * @return void
	 */
	public function test_positive_persisted_id(): void {
		$d = StateDefinition::create( StateKey::from_string( 'selling' ), 'Selling', '', true, false, 100, 42 );

		$this->assertSame( 42, $d->id() );
	}

	/**
	 * Non-positive persisted IDs are rejected.
	 *
	 * @return void
	 */
	public function test_zero_and_negative_ids_are_rejected(): void {
		try {
			StateDefinition::create( StateKey::from_string( 'selling' ), 'Selling', '', true, false, 100, 0 );
			$this->fail( 'ID 0 must be rejected.' );
		} catch ( DomainException $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->expectException( DomainException::class );
		StateDefinition::create( StateKey::from_string( 'selling' ), 'Selling', '', true, false, 100, -3 );
	}

	/**
	 * The with_id() derivation is immutable.
	 *
	 * @return void
	 */
	public function test_with_id_is_immutable_derivation(): void {
		$original = $this->valid();
		$derived  = $original->with_id( 7 );

		$this->assertNull( $original->id(), 'Original must stay unpersisted.' );
		$this->assertSame( 7, $derived->id() );
		$this->assertTrue( $original->equals( $derived ), 'Identity is the state key.' );
	}

	/**
	 * The with_id() derivation rejects non-positive IDs.
	 *
	 * @return void
	 */
	public function test_with_id_rejects_zero(): void {
		$this->expectException( DomainException::class );
		$this->valid()->with_id( 0 );
	}

	/**
	 * Enabled/builtin flags round-trip.
	 *
	 * @return void
	 */
	public function test_flags(): void {
		$d = StateDefinition::create( StateKey::from_string( 'a1' ), 'A', '', false, true, 5 );

		$this->assertFalse( $d->is_enabled() );
		$this->assertTrue( $d->is_builtin() );
	}

	/**
	 * Identity is the immutable StateKey, not name/flags.
	 *
	 * @return void
	 */
	public function test_key_identity_is_immutable(): void {
		$d = $this->valid();

		$this->assertSame( 'selling', $d->key()->value() );
		$this->assertTrue( $d->equals( StateDefinition::create( StateKey::from_string( 'selling' ), 'Renamed' ) ) );
		$this->assertFalse( $d->equals( StateDefinition::create( StateKey::from_string( 'inquiry' ), 'Selling' ) ) );
	}
}
