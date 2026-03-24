<?php
/**
 * EventPublisher Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Services
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing and dispatch
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\EventPublisher;
use WC\Auction\Events\RetryScheduleCreatedEvent;
use WC\Auction\Events\BatchLockAcquiredEvent;
use WC\Auction\Models\RetrySchedule;
use WC\Auction\Models\BatchLock;

/**
 * Mock listener for testing event dispatch
 */
class MockEventListener {
	public $events_received = array();

	public function onEvent( $event ) {
		$this->events_received[] = $event;
	}
}

/**
 * Test EventPublisher service
 *
 * @covers \WC\Auction\Services\EventPublisher
 */
class EventPublisherTest extends TestCase {

	/**
	 * Publisher instance
	 *
	 * @var EventPublisher
	 */
	private $publisher;

	/**
	 * Set up test fixtures
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->publisher = new EventPublisher();
	}

	/**
	 * Test subscribe adds listener
	 */
	public function testSubscribeAddsListener() {
		$listener = new MockEventListener();

		$this->publisher->subscribe( 'retry_schedule.created', array( $listener, 'onEvent' ) );

		// Verify listener is stored
		$this->assertTrue( $this->publisher->hasListeners( 'retry_schedule.created' ) );
	}

	/**
	 * Test multiple listeners can subscribe to same event
	 */
	public function testMultipleListenersCanSubscribe() {
		$listener1 = new MockEventListener();
		$listener2 = new MockEventListener();

		$this->publisher->subscribe( 'retry_schedule.created', array( $listener1, 'onEvent' ) );
		$this->publisher->subscribe( 'retry_schedule.created', array( $listener2, 'onEvent' ) );

		$this->assertTrue( $this->publisher->hasListeners( 'retry_schedule.created' ) );
	}

	/**
	 * Test publish dispatches event to listeners
	 */
	public function testPublishDispatchesEventToListeners() {
		$listener1 = new MockEventListener();
		$listener2 = new MockEventListener();

		$this->publisher->subscribe( 'retry_schedule.created', array( $listener1, 'onEvent' ) );
		$this->publisher->subscribe( 'retry_schedule.created', array( $listener2, 'onEvent' ) );

		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );

		$this->publisher->publish( $event );

		$this->assertCount( 1, $listener1->events_received );
		$this->assertCount( 1, $listener2->events_received );
		$this->assertSame( $event, $listener1->events_received[0] );
		$this->assertSame( $event, $listener2->events_received[0] );
	}

	/**
	 * Test publish only dispatches to matching event names
	 */
	public function testPublishOnlyDispatchesToMatchingEventNames() {
		$listener1 = new MockEventListener();
		$listener2 = new MockEventListener();

		$this->publisher->subscribe( 'retry_schedule.created', array( $listener1, 'onEvent' ) );
		$this->publisher->subscribe( 'batch_lock.acquired', array( $listener2, 'onEvent' ) );

		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );

		$this->publisher->publish( $event );

		$this->assertCount( 1, $listener1->events_received );
		$this->assertEmpty( $listener2->events_received );
	}

	/**
	 * Test publish without listeners doesn't error
	 */
	public function testPublishWithoutListenersDoesNotError() {
		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );

		// Should not throw
		$this->publisher->publish( $event );

		$this->assertTrue( true );
	}

	/**
	 * Test unsubscribe removes listener
	 */
	public function testUnsubscribeRemovesListener() {
		$listener = new MockEventListener();

		$this->publisher->subscribe( 'retry_schedule.created', array( $listener, 'onEvent' ) );
		$this->assertTrue( $this->publisher->hasListeners( 'retry_schedule.created' ) );

		$this->publisher->unsubscribe( 'retry_schedule.created', array( $listener, 'onEvent' ) );
		$this->assertFalse( $this->publisher->hasListeners( 'retry_schedule.created' ) );
	}

	/**
	 * Test multiple events can be dispatched
	 */
	public function testMultipleEventsCanBeDispatched() {
		$listener = new MockEventListener();

		$this->publisher->subscribe( 'retry_schedule.created', array( $listener, 'onEvent' ) );
		$this->publisher->subscribe( 'batch_lock.acquired', array( $listener, 'onEvent' ) );

		$schedule = RetrySchedule::create( 42 );
		$schedule_event = new RetryScheduleCreatedEvent( $schedule );
		$lock_event     = new BatchLockAcquiredEvent( BatchLock::create( 'batch', 300 ) );

		$this->publisher->publish( $schedule_event );
		$this->publisher->publish( $lock_event );

		$this->assertCount( 2, $listener->events_received );
	}

	/**
	 * Test listener receives correct event data
	 */
	public function testListenerReceivesCorrectEventData() {
		$listener = new MockEventListener();

		$this->publisher->subscribe( 'retry_schedule.created', array( $listener, 'onEvent' ) );

		$schedule = RetrySchedule::create( 42 );
		$event    = new RetryScheduleCreatedEvent( $schedule );

		$this->publisher->publish( $event );

		$received_event = $listener->events_received[0];
		$this->assertEquals( 42, $received_event->getRetrySchedule()->getPayoutId() );
		$this->assertEquals( 'retry_schedule.created', $received_event->getName() );
	}

	/**
	 * Test has listeners returns false when no listeners
	 */
	public function testHasListenersReturnsFalseWhenNoListeners() {
		$has_listeners = $this->publisher->hasListeners( 'non.existent.event' );

		$this->assertFalse( $has_listeners );
	}
}
