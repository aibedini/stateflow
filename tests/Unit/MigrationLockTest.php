<?php
/**
 * MigrationLock unit tests (SF-002.1 §4): ownership semantics on the
 * WordPress-function stub harness — no database involved.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StateFlow\Infrastructure\Database\MigrationLock;

/**
 * Migration lock ownership behavior.
 */
final class MigrationLockTest extends TestCase {

	/**
	 * Fresh option map for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sf_options'] = array();
	}

	/**
	 * Clean the option map after every test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['sf_options'] = array();

		parent::tearDown();
	}

	/**
	 * A fresh lock is acquired and can be released by its owner.
	 *
	 * @return void
	 */
	public function test_acquire_and_release_by_owner(): void {
		$lock = new MigrationLock();

		$this->assertTrue( $lock->acquire() );
		$this->assertNotNull( $lock->held_token() );

		$lock->release();

		$this->assertNull( $lock->held_token() );
		$this->assertNull( LockFixture::lock_payload() );
	}

	/**
	 * A live (non-stale) foreign lock cannot be acquired.
	 *
	 * @return void
	 */
	public function test_live_foreign_lock_cannot_be_acquired(): void {
		$first = new MigrationLock();

		$this->assertTrue( $first->acquire() );

		$second = new MigrationLock();

		$this->assertFalse( $second->acquire() );
		$this->assertNull( $second->held_token() );

		// The original lock payload is untouched.
		$this->assertSame( $first->held_token(), LockFixture::lock_payload()['token'] ?? null );
	}

	/**
	 * A stale lock can be recovered by a new request.
	 *
	 * @return void
	 */
	public function test_stale_lock_can_be_recovered(): void {
		$first = new MigrationLock();

		$this->assertTrue( $first->acquire() );

		LockFixture::backdate_lock( MigrationLock::DEFAULT_STALE_SECONDS + 1 );

		$second = new MigrationLock();

		$this->assertTrue( $second->acquire(), 'A stale lock must be recoverable.' );

		// The recovered lock carries a NEW token.
		$this->assertNotSame( $first->held_token(), LockFixture::lock_payload()['token'] ?? null );
		$this->assertSame( $second->held_token(), LockFixture::lock_payload()['token'] ?? null );
	}

	/**
	 * The previous owner cannot release a lock that was recovered by
	 * someone else (token mismatch protects the replacement); the current
	 * owner can.
	 *
	 * @return void
	 */
	public function test_previous_owner_cannot_release_replacement_lock(): void {
		$previous = new MigrationLock();

		$this->assertTrue( $previous->acquire() );

		// Stale recovery by a second process.
		LockFixture::backdate_lock( MigrationLock::DEFAULT_STALE_SECONDS + 1 );

		$replacement = new MigrationLock();

		$this->assertTrue( $replacement->acquire() );

		// The PREVIOUS owner tries to release: must not delete the
		// replacement's lock (token mismatch).
		$previous->release();

		$this->assertNotNull( LockFixture::lock_payload(), 'A previous owner must not delete a replacement lock.' );
		$this->assertSame( $replacement->held_token(), LockFixture::lock_payload()['token'] ?? null );

		// The current owner CAN release its own lock.
		$replacement->release();

		$this->assertNull( LockFixture::lock_payload() );
		$this->assertNull( $replacement->held_token() );
	}

	/**
	 * Absent or malformed payloads always count as stale.
	 *
	 * @return void
	 */
	public function test_absent_or_malformed_lock_is_stale(): void {
		$lock = new MigrationLock();

		$this->assertTrue( $lock->is_stale( 60 ) );

		LockFixture::set( MigrationLock::OPTION, 'not-an-array' );

		$this->assertTrue( $lock->is_stale( 60 ) );

		LockFixture::set( MigrationLock::OPTION, array( 'token' => 'x' ) );

		$this->assertTrue( $lock->is_stale( 60 ) );
	}

	/**
	 * A live acquisition window never counts as stale.
	 *
	 * @return void
	 */
	public function test_live_lock_is_not_stale(): void {
		$lock = new MigrationLock();

		$this->assertTrue( $lock->acquire() );

		$fresh = new MigrationLock();

		$this->assertFalse( $fresh->is_stale( MigrationLock::DEFAULT_STALE_SECONDS ) );
	}

	/**
	 * The previous owner's own release still works when nobody replaced
	 * the lock (held-token guard resets).
	 *
	 * @return void
	 */
	public function test_release_is_idempotent(): void {
		$lock = new MigrationLock();

		$this->assertTrue( $lock->acquire() );

		$lock->release();
		$lock->release(); // Second call: no-op, held_token() is already null.

		$this->assertNull( LockFixture::lock_payload() );
	}
}
