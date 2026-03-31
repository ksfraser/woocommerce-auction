<?php
/**
 * Tests for monitoring event listeners.
 *
 * @package ksfraser\Tests\Monitoring
 * @covers \ksfraser\Monitoring\Listeners
 */

namespace ksfraser\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use ksfraser\Monitoring\Listeners\PerformanceListener;
use ksfraser\Events\EventDispatcher;

/**
 * MonitoringListenersTest class.
 *
 * @covers \ksfraser\Monitoring\Listeners\PerformanceListener
 */
class MonitoringListenersTest extends TestCase {

	/**
	 * Test performance listener instantiation.
	 *
	 * @test
	 */
	public function test_listener_instantiation() {
		$listener = new PerformanceListener();

		$this->assertInstanceOf( PerformanceListener::class, $listener );
	}

	/**
	 * Test listener on API call event.
	 *
	 * @test
	 */
	public function test_listener_on_api_call() {
		$listener = new PerformanceListener();

		// Verify listener can be called
		$this->assertTrue( method_exists( $listener, 'on_api_call' ) );
	}

	/**
	 * Test listener on database query event.
	 *
	 * @test
	 */
	public function test_listener_on_database_query() {
		$listener = new PerformanceListener();

		// Verify listener can be called
		$this->assertTrue( method_exists( $listener, 'on_database_query' ) );
	}

	/**
	 * Test listener on batch job event.
	 *
	 * @test
	 */
	public function test_listener_on_batch_job() {
		$listener = new PerformanceListener();

		// Verify listener can be called
		$this->assertTrue( method_exists( $listener, 'on_batch_job' ) );
	}

	/**
	 * Test listener on error event.
	 *
	 * @test
	 */
	public function test_listener_on_error() {
		$listener = new PerformanceListener();

		// Verify listener can be called
		$this->assertTrue( method_exists( $listener, 'on_error' ) );
	}

	/**
	 * Test listener is callable.
	 *
	 * @test
	 */
	public function test_listener_methods_are_callable() {
		$listener = new PerformanceListener();

		$this->assertTrue( is_callable( [ $listener, 'on_api_call' ] ) );
		$this->assertTrue( is_callable( [ $listener, 'on_database_query' ] ) );
		$this->assertTrue( is_callable( [ $listener, 'on_batch_job' ] ) );
		$this->assertTrue( is_callable( [ $listener, 'on_error' ] ) );
	}

	/**
	 * Test listener methods return void.
	 *
	 * @test
	 */
	public function test_listener_methods_return_void() {
		$listener = new PerformanceListener();

		$result = $listener->on_api_call( [], 100, true );
		$this->assertNull( $result );

		$result = $listener->on_database_query( 'SELECT 1', 50 );
		$this->assertNull( $result );

		$result = $listener->on_batch_job( 1, 100, 5 );
		$this->assertNull( $result );

		$result = $listener->on_error( 'Error', 'test' );
		$this->assertNull( $result );
	}
}
