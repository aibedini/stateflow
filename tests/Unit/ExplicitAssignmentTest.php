<?php
/**
 * ExplicitAssignment unit tests (SF-003A §16).
 *
 * Pure PHP: invariants and immutable getter behavior only.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StateFlow\Domain\State\DomainException;
use StateFlow\Domain\State\ExplicitAssignment;

/**
 * ExplicitAssignment invariants.
 */
final class ExplicitAssignmentTest extends TestCase {

	/**
	 * A valid assignment stores its fields.
	 *
	 * @return void
	 */
	public function test_valid_assignment(): void {
		$assignment = ExplicitAssignment::create( 4242, 17, 3 );

		$this->assertSame( 4242, $assignment->object_id() );
		$this->assertSame( 17, $assignment->state_id() );
		$this->assertSame( 3, $assignment->version() );
	}

	/**
	 * Version 1 is the minimum accepted version.
	 *
	 * @return void
	 */
	public function test_version_one_is_accepted(): void {
		$assignment = ExplicitAssignment::create( 1, 1, 1 );

		$this->assertSame( 1, $assignment->version() );
	}

	/**
	 * Object ID 0 is rejected.
	 *
	 * @return void
	 */
	public function test_object_id_zero_is_rejected(): void {
		$this->expectException( DomainException::class );
		ExplicitAssignment::create( 0, 17, 1 );
	}

	/**
	 * A negative object ID is rejected.
	 *
	 * @return void
	 */
	public function test_negative_object_id_is_rejected(): void {
		$this->expectException( DomainException::class );
		ExplicitAssignment::create( -5, 17, 1 );
	}

	/**
	 * State ID 0 is rejected.
	 *
	 * @return void
	 */
	public function test_state_id_zero_is_rejected(): void {
		$this->expectException( DomainException::class );
		ExplicitAssignment::create( 4242, 0, 1 );
	}

	/**
	 * A negative state ID is rejected.
	 *
	 * @return void
	 */
	public function test_negative_state_id_is_rejected(): void {
		$this->expectException( DomainException::class );
		ExplicitAssignment::create( 4242, -9, 1 );
	}

	/**
	 * Version 0 is rejected.
	 *
	 * @return void
	 */
	public function test_version_zero_is_rejected(): void {
		$this->expectException( DomainException::class );
		ExplicitAssignment::create( 4242, 17, 0 );
	}

	/**
	 * A negative version is rejected.
	 *
	 * @return void
	 */
	public function test_negative_version_is_rejected(): void {
		$this->expectException( DomainException::class );
		ExplicitAssignment::create( 4242, 17, -2 );
	}

	/**
	 * Getters are immutable: repeated reads return identical values and
	 * no mutators exist on the class.
	 *
	 * @return void
	 */
	public function test_getters_are_immutable(): void {
		$assignment = ExplicitAssignment::create( 4242, 17, 3 );

		$this->assertSame( $assignment->object_id(), $assignment->object_id() );
		$this->assertSame( $assignment->state_id(), $assignment->state_id() );
		$this->assertSame( $assignment->version(), $assignment->version() );

		$mutators = array_filter(
			get_class_methods( $assignment ),
			static fn( string $method ): bool => 0 === strpos( $method, 'set' ) || 0 === strpos( $method, 'with' )
		);

		$this->assertSame( array(), $mutators, 'ExplicitAssignment must expose no mutator methods.' );
	}
}
