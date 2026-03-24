<?php
/**
 * Retry Schedule Event Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Events
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing for retry scheduling
 */

namespace Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use WC\Auction\Events\RetryScheduleCreatedEvent;
use WC\Auction\Events\RetryScheduleFailedEvent;
use WC\Auction\Events\RetryScheduleSucceededEvent;
use WC\Auction\Models\RetrySchedule;

/**
 * Test Retry Schedule Events
 *
 * @covers \WC\Auction\Events\RetryScheduleCreatedEvent
 * @covers \WC\Auction\Events\RetryScheduleFailedEvent
 * @covers \WC\Auction\Events\RetryScheduleSucceededEvent
 */
class RetryScheduleEventTest extends TestCase {

	/**
	 * Test RetryScheduleCreatedEvent creation
	 */
	public function testRetryScheduleCreatedEventCreation() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );

		$this->assertInstanceOf( RetryScheduleCreatedEvent::class, $event );
		$this->assertSame( $schedule, $event->getRetrySchedule() );
		$this->assertIsInt( $event->getTimestamp() );
	}

	/**
	 * Test RetryScheduleCreatedEvent has event name
	 */
	public function testRetryScheduleCreatedEventHasName() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );

		$this->assertEquals( 'retry_schedule.created', $event->getName() );
	}

	/**
	 * Test RetryScheduleCreatedEvent serializes to array
	 */
	public function testRetryScheduleCreatedEventSerializesToArray() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );

		$array = $event->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'retry_schedule.created', $array['event_name'] );
		$this->assertArrayHasKey( 'timestamp', $array );
		$this->assertArrayHasKey( 'data', $array );
		$this->assertEquals( 42, $array['data']['payout_id'] );
	}

	/**
	 * Test RetryScheduleFailedEvent creation
	 */
	public function testRetryScheduleFailedEventCreation() {
		$schedule = RetrySchedule::create( 42 );
		$schedule->incrementFailureCount();
		$schedule->setErrorMessage( 'Payment gateway timeout' );

		$event = new RetryScheduleFailedEvent( $schedule, 'Payment gateway timeout' );

		$this->assertInstanceOf( RetryScheduleFailedEvent::class, $event );
		$this->assertSame( $schedule, $event->getRetrySchedule() );
		$this->assertEquals( 'Payment gateway timeout', $event->getErrorMessage() );
	}

	/**
	 * Test RetryScheduleFailedEvent has event name
	 */
	public function testRetryScheduleFailedEventHasName() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleFailedEvent( $schedule, 'Test error' );

		$this->assertEquals( 'retry_schedule.failed', $event->getName() );
	}

	/**
	 * Test RetryScheduleFailedEvent serializes to array
	 */
	public function testRetryScheduleFailedEventSerializesToArray() {
		$schedule = RetrySchedule::create( 42 );
		$schedule->incrementFailureCount();
		$event = new RetryScheduleFailedEvent( $schedule, 'Connection timeout' );

		$array = $event->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'retry_schedule.failed', $array['event_name'] );
		$this->assertEquals( 'Connection timeout', $array['data']['error_message'] );
		$this->assertEquals( 1, $array['data']['failure_count'] );
	}

	/**
	 * Test RetryScheduleSucceededEvent creation
	 */
	public function testRetryScheduleSucceededEventCreation() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleSucceededEvent( $schedule );

		$this->assertInstanceOf( RetryScheduleSucceededEvent::class, $event );
		$this->assertSame( $schedule, $event->getRetrySchedule() );
	}

	/**
	 * Test RetryScheduleSucceededEvent has event name
	 */
	public function testRetryScheduleSucceededEventHasName() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleSucceededEvent( $schedule );

		$this->assertEquals( 'retry_schedule.succeeded', $event->getName() );
	}

	/**
	 * Test RetryScheduleSucceededEvent serializes to array
	 */
	public function testRetryScheduleSucceededEventSerializesToArray() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleSucceededEvent( $schedule );

		$array = $event->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'retry_schedule.succeeded', $array['event_name'] );
		$this->assertEquals( 42, $array['data']['payout_id'] );
		$this->assertArrayHasKey( 'timestamp', $array );
	}

	/**
	 * Test event timestamp is close to current time
	 */
	public function testEventTimestampIsCloseToCurrentTime() {
		$before   = time();
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );
		$after    = time();

		$timestamp = $event->getTimestamp();

		$this->assertGreaterThanOrEqual( $before, $timestamp );
		$this->assertLessThanOrEqual( $after + 1, $timestamp );
	}
}
