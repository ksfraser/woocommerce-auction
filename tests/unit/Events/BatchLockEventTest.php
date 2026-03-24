<?php
/**
 * Batch Lock Event Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Events
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing for batch locking
 */

namespace Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use WC\Auction\Events\BatchLockAcquiredEvent;
use WC\Auction\Events\BatchLockReleasedEvent;
use WC\Auction\Models\BatchLock;

/**
 * Test Batch Lock Events
 *
 * @covers \WC\Auction\Events\BatchLockAcquiredEvent
 * @covers \WC\Auction\Events\BatchLockReleasedEvent
 */
class BatchLockEventTest extends TestCase {

	/**
	 * Test BatchLockAcquiredEvent creation
	 */
	public function testBatchLockAcquiredEventCreation() {
		$lock  = BatchLock::create( 'payout-batch', 300 );
		$event = new BatchLockAcquiredEvent( $lock );

		$this->assertInstanceOf( BatchLockAcquiredEvent::class, $event );
		$this->assertSame( $lock, $event->getBatchLock() );
		$this->assertIsInt( $event->getTimestamp() );
	}

	/**
	 * Test BatchLockAcquiredEvent has event name
	 */
	public function testBatchLockAcquiredEventHasName() {
		$lock  = BatchLock::create( 'payout-batch', 300 );
		$event = new BatchLockAcquiredEvent( $lock );

		$this->assertEquals( 'batch_lock.acquired', $event->getName() );
	}

	/**
	 * Test BatchLockAcquiredEvent serializes to array
	 */
	public function testBatchLockAcquiredEventSerializesToArray() {
		$lock  = BatchLock::create( 'settlement-batch', 600 );
		$event = new BatchLockAcquiredEvent( $lock );

		$array = $event->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'batch_lock.acquired', $array['event_name'] );
		$this->assertEquals( 'settlement-batch', $array['data']['batch_id'] );
		$this->assertEquals( 600, $array['data']['timeout_seconds'] );
		$this->assertArrayHasKey( 'timestamp', $array );
	}

	/**
	 * Test BatchLockReleasedEvent creation
	 */
	public function testBatchLockReleasedEventCreation() {
		$lock  = BatchLock::create( 'payout-batch', 300 );
		$event = new BatchLockReleasedEvent( 'payout-batch' );

		$this->assertInstanceOf( BatchLockReleasedEvent::class, $event );
		$this->assertEquals( 'payout-batch', $event->getBatchId() );
		$this->assertIsInt( $event->getTimestamp() );
	}

	/**
	 * Test BatchLockReleasedEvent has event name
	 */
	public function testBatchLockReleasedEventHasName() {
		$event = new BatchLockReleasedEvent( 'settlement-batch' );

		$this->assertEquals( 'batch_lock.released', $event->getName() );
	}

	/**
	 * Test BatchLockReleasedEvent serializes to array
	 */
	public function testBatchLockReleasedEventSerializesToArray() {
		$event = new BatchLockReleasedEvent( 'payout-batch' );

		$array = $event->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'batch_lock.released', $array['event_name'] );
		$this->assertEquals( 'payout-batch', $array['data']['batch_id'] );
		$this->assertArrayHasKey( 'timestamp', $array );
	}

	/**
	 * Test event timestamp is close to current time
	 */
	public function testEventTimestampIsCloseToCurrentTime() {
		$before = time();
		$event  = new BatchLockAcquiredEvent( BatchLock::create( 'test-batch', 300 ) );
		$after  = time();

		$timestamp = $event->getTimestamp();

		$this->assertGreaterThanOrEqual( $before, $timestamp );
		$this->assertLessThanOrEqual( $after + 1, $timestamp );
	}
}
