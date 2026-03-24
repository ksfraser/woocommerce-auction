<?php
/**
 * BatchLock Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Models
 * @version    1.0.0
 * @requirement REQ-4D-038: Batch processing locks to prevent concurrent execution
 */

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\BatchLock;

/**
 * Test BatchLock value object
 *
 * @covers \WC\Auction\Models\BatchLock
 */
class BatchLockTest extends TestCase {

	/**
	 * Test create batch lock
	 */
	public function testCreateBatchLock() {
		$lock = BatchLock::create( 'payout-batch', 300 );

		$this->assertInstanceOf( BatchLock::class, $lock );
		$this->assertNull( $lock->getId() );
		$this->assertEquals( 'payout-batch', $lock->getBatchId() );
		$this->assertInstanceOf( \DateTime::class, $lock->getLockedAt() );
		$this->assertEquals( 300, $lock->getTimeoutSeconds() );
	}

	/**
	 * Test locked at uses current UTC time
	 */
	public function testLockedAtUseCurrentUtcTime() {
		$before = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$lock   = BatchLock::create( 'test-batch', 300 );
		$after  = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		// Should be between before and after
		$locked_at = $lock->getLockedAt();
		$this->assertGreaterThanOrEqual( $before->getTimestamp(), $locked_at->getTimestamp() );
		$this->assertLessThanOrEqual( $after->getTimestamp(), $locked_at->getTimestamp() );
	}

	/**
	 * Test from database restores all properties
	 */
	public function testFromDatabaseRestoresAllProperties() {
		$locked_at_str = '2024-01-15 10:30:45';
		$row           = array(
			'id'              => 123,
			'batch_id'        => 'settlement-batch',
			'locked_at'       => $locked_at_str,
			'timeout_seconds' => 600,
		);

		$lock = BatchLock::fromDatabase( $row );

		$this->assertEquals( 123, $lock->getId() );
		$this->assertEquals( 'settlement-batch', $lock->getBatchId() );
		$this->assertEquals( 600, $lock->getTimeoutSeconds() );

		// Verify locked_at was parsed correctly
		$expected_time = new \DateTime( $locked_at_str, new \DateTimeZone( 'UTC' ) );
		$this->assertEquals( $expected_time->getTimestamp(), $lock->getLockedAt()->getTimestamp() );
	}

	/**
	 * Test is expired when past timeout
	 */
	public function testIsExpiredWhenPastTimeout() {
		// Create lock that expired 1 minute ago
		$locked_at = new \DateTime( '-10 minutes', new \DateTimeZone( 'UTC' ) );
		$lock      = BatchLock::create( 'test-batch', 300 ); // 5 minute timeout
		$lock->setLockedAt( $locked_at );

		$this->assertTrue( $lock->isExpired() );
	}

	/**
	 * Test is not expired when still valid
	 */
	public function testIsNotExpiredWhenStillValid() {
		// Create lock that was just locked with 10 minute timeout
		$locked_at = new \DateTime( '-2 minutes', new \DateTimeZone( 'UTC' ) );
		$lock      = BatchLock::create( 'test-batch', 600 ); // 10 minute timeout
		$lock->setLockedAt( $locked_at );

		$this->assertFalse( $lock->isExpired() );
	}

	/**
	 * Test is locked returns true for valid lock
	 */
	public function testIsLockedReturnsTrueForValidLock() {
		$locked_at = new \DateTime( '-1 minutes', new \DateTimeZone( 'UTC' ) );
		$lock      = BatchLock::create( 'test-batch', 300 ); // 5 minute timeout
		$lock->setLockedAt( $locked_at );

		$this->assertTrue( $lock->isLocked() );
	}

	/**
	 * Test is locked returns false when expired
	 */
	public function testIsLockedReturnsFalseWhenExpired() {
		$locked_at = new \DateTime( '-10 minutes', new \DateTimeZone( 'UTC' ) );
		$lock      = BatchLock::create( 'test-batch', 300 ); // 5 minute timeout
		$lock->setLockedAt( $locked_at );

		$this->assertFalse( $lock->isLocked() );
	}

	/**
	 * Test refresh updates locked at to current time
	 */
	public function testRefreshUpdatesLockedAtToCurrent() {
		$old_time = new \DateTime( '-5 minutes', new \DateTimeZone( 'UTC' ) );
		$lock     = BatchLock::create( 'test-batch', 300 );
		$lock->setLockedAt( $old_time );

		$before = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$lock->refresh();
		$after = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		// Locked at should be updated to now
		$locked_at = $lock->getLockedAt();
		$this->assertGreaterThanOrEqual( $before->getTimestamp(), $locked_at->getTimestamp() );
		$this->assertLessThanOrEqual( $after->getTimestamp(), $locked_at->getTimestamp() );
	}

	/**
	 * Test to array serializes for database
	 */
	public function testToArraySerializesForDatabase() {
		$locked_at_str = '2024-01-15 10:30:45';
		$locked_at     = new \DateTime( $locked_at_str, new \DateTimeZone( 'UTC' ) );

		$lock = BatchLock::create( 'settlement-batch', 600 );
		$lock->setLockedAt( $locked_at );

		$array = $lock->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'settlement-batch', $array['batch_id'] );
		$this->assertEquals( $locked_at_str, $array['locked_at'] );
		$this->assertEquals( 600, $array['timeout_seconds'] );
		$this->assertArrayNotHasKey( 'id', $array );
	}

	/**
	 * Test setters work correctly
	 */
	public function testSettersWorkCorrectly() {
		$lock = BatchLock::create( 'batch1', 300 );

		$new_time = new \DateTime( '2024-01-15 15:00:00', new \DateTimeZone( 'UTC' ) );
		$lock->setLockedAt( $new_time );
		$lock->setTimeoutSeconds( 900 );

		$this->assertEquals( $new_time->getTimestamp(), $lock->getLockedAt()->getTimestamp() );
		$this->assertEquals( 900, $lock->getTimeoutSeconds() );
	}
}
