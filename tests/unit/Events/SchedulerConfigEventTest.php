<?php
/**
 * Scheduler Config Event Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Events
 * @version    1.0.0
 * @requirement REQ-4D-041: Event publishing for configuration changes
 */

namespace Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use WC\Auction\Events\SchedulerConfigChangedEvent;
use WC\Auction\Models\SchedulerConfig;

/**
 * Test Scheduler Config Events
 *
 * @covers \WC\Auction\Events\SchedulerConfigChangedEvent
 */
class SchedulerConfigEventTest extends TestCase {

	/**
	 * Test SchedulerConfigChangedEvent creation with config model
	 */
	public function testSchedulerConfigChangedEventCreationWithModel() {
		$config = SchedulerConfig::create( 'polling_interval', 300 );
		$event  = new SchedulerConfigChangedEvent( $config );

		$this->assertInstanceOf( SchedulerConfigChangedEvent::class, $event );
		$this->assertSame( $config, $event->getConfig() );
		$this->assertIsInt( $event->getTimestamp() );
	}

	/**
	 * Test SchedulerConfigChangedEvent creation with details
	 */
	public function testSchedulerConfigChangedEventCreationWithDetails() {
		$event = new SchedulerConfigChangedEvent( null, 'max_retries', '6', '5' );

		$this->assertInstanceOf( SchedulerConfigChangedEvent::class, $event );
		$this->assertNull( $event->getConfig() );
		$this->assertEquals( 'max_retries', $event->getOptionName() );
		$this->assertEquals( '6', $event->getNewValue() );
		$this->assertEquals( '5', $event->getOldValue() );
	}

	/**
	 * Test SchedulerConfigChangedEvent has event name
	 */
	public function testSchedulerConfigChangedEventHasName() {
		$config = SchedulerConfig::create( 'batch_size', '50' );
		$event  = new SchedulerConfigChangedEvent( $config );

		$this->assertEquals( 'scheduler_config.changed', $event->getName() );
	}

	/**
	 * Test SchedulerConfigChangedEvent serializes to array
	 */
	public function testSchedulerConfigChangedEventSerializesToArray() {
		$config = SchedulerConfig::create( 'polling_interval', 600 );
		$event  = new SchedulerConfigChangedEvent( $config );

		$array = $event->toArray();

		$this->assertIsArray( $array );
		$this->assertEquals( 'scheduler_config.changed', $array['event_name'] );
		$this->assertEquals( 'polling_interval', $array['data']['option_name'] );
		$this->assertEquals( '600', $array['data']['option_value'] );
		$this->assertArrayHasKey( 'timestamp', $array );
	}

	/**
	 * Test SchedulerConfigChangedEvent with old and new values
	 */
	public function testSchedulerConfigChangedEventWithOldAndNewValues() {
		$event = new SchedulerConfigChangedEvent( null, 'max_attempts', '10', '8' );

		$array = $event->toArray();

		$this->assertEquals( 'max_attempts', $array['data']['option_name'] );
		$this->assertEquals( '10', $array['data']['new_value'] );
		$this->assertEquals( '8', $array['data']['old_value'] );
	}

	/**
	 * Test event timestamp is close to current time
	 */
	public function testEventTimestampIsCloseToCurrentTime() {
		$before = time();
		$config = SchedulerConfig::create( 'test_option', 'test_value' );
		$event  = new SchedulerConfigChangedEvent( $config );
		$after  = time();

		$timestamp = $event->getTimestamp();

		$this->assertGreaterThanOrEqual( $before, $timestamp );
		$this->assertLessThanOrEqual( $after + 1, $timestamp );
	}
}
