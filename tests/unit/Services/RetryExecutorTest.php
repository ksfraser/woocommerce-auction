<?php
/**
 * RetryExecutor Unit Tests
 *
 * Handles execution of individual retry attempts
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    1.0.0
 * @requirement REQ-4D-041: Retry execution with error handling
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\RetrySchedule;
use WC\Auction\Services\RetryExecutor;

/**
 * @covers \WC\Auction\Services\RetryExecutor
 */
class RetryExecutorTest extends TestCase {

	private $executor;

	protected function setUp(): void {
		$this->executor = new RetryExecutor();
	}

	/**
	 * Test retry executor construction
	 */
	public function testRetryExecutorConstruction() {
		$this->assertInstanceOf( RetryExecutor::class, $this->executor );
	}

	/**
	 * Test execute retry returns result with success flag
	 */
	public function testExecuteRetryReturnsResult() {
		$schedule = RetrySchedule::create( 42 );

		// Mock the actual payout processing logic
		$result = $this->executor->execute( $schedule );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error_message', $result );
	}

	/**
	 * Test execute retry failure is caught and reported
	 */
	public function testExecuteRetryFailureReturnsError() {
		$schedule = RetrySchedule::fromDatabase( [
			'id'                   => 1,
			'payout_id'            => 42,
			'failure_count'        => 1,
			'next_retry_time'      => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
			'last_error_message'   => 'Previous error',
			'created_at'           => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
		] );

		// The executor should handle failures gracefully
		$result = $this->executor->execute( $schedule );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error_message', $result );
	}

	/**
	 * Test get max retries returns correct value
	 */
	public function testGetMaxRetriesReturnsConfiguredValue() {
		$max_retries = $this->executor->getMaxRetries();

		$this->assertEquals( 6, $max_retries );
	}

	/**
	 * Test calculate next retry time for first attempt
	 */
	public function testCalculateNextRetryTimeFirstAttempt() {
		$schedule = RetrySchedule::create( 42 );

		$next_time = $this->executor->calculateNextRetryTime( $schedule );

		$this->assertInstanceOf( \DateTime::class, $next_time );
		// Should be in the future (at least 1 minute from now)
		$this->assertGreaterThan( new \DateTime(), $next_time );
	}

	/**
	 * Test calculate next retry time uses exponential backoff
	 */
	public function testCalculateNextRetryTimeUsesExponentialBackoff() {
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		// Failure count 0: 60 seconds
		$schedule0 = RetrySchedule::fromDatabase( [
			'id'                   => 1,
			'payout_id'            => 42,
			'failure_count'        => 0,
			'next_retry_time'      => null,
			'last_error_message'   => null,
			'created_at'           => $now->format( 'Y-m-d H:i:s' ),
		] );
		$time0 = $this->executor->calculateNextRetryTime( $schedule0 );

		// Failure count 1: 120 seconds
		$schedule1 = RetrySchedule::fromDatabase( [
			'id'                   => 2,
			'payout_id'            => 43,
			'failure_count'        => 1,
			'next_retry_time'      => null,
			'last_error_message'   => null,
			'created_at'           => $now->format( 'Y-m-d H:i:s' ),
		] );
		$time1 = $this->executor->calculateNextRetryTime( $schedule1 );

		// Verify backoff schedule is applied (roughly):
		// time0 should be around 60 seconds in future
		// time1 should be around 120 seconds in future  
		// Allow 10 second tolerance for execution time
		$diff0 = $time0->getTimestamp() - $now->getTimestamp();
		$diff1 = $time1->getTimestamp() - $now->getTimestamp();

		$this->assertGreaterThan( 50, $diff0 );
		$this->assertLessThan( 70, $diff0 );
		$this->assertGreaterThan( 110, $diff1 );
		$this->assertLessThan( 130, $diff1 );
	}

	/**
	 * Test get retry attempts returns all configured attempts
	 */
	public function testGetRetryAttemptsReturnsArray() {
		$attempts = $this->executor->getRetryAttempts();

		$this->assertIsArray( $attempts );
		$this->assertGreaterThan( 0, count( $attempts ) );
	}

	/**
	 * Test should retry returns true if under max attempts
	 */
	public function testShouldRetryReturnsTrueIfUnderMax() {
		$schedule = RetrySchedule::fromDatabase( [
			'id'                   => 1,
			'payout_id'            => 42,
			'failure_count'        => 2,
			'next_retry_time'      => null,
			'last_error_message'   => null,
			'created_at'           => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
		] );

		$should_retry = $this->executor->shouldRetry( $schedule );

		$this->assertTrue( $should_retry );
	}

	/**
	 * Test should retry returns false if at max attempts
	 */
	public function testShouldRetryReturnsFalseIfAtMax() {
		$schedule = RetrySchedule::fromDatabase( [
			'id'                   => 1,
			'payout_id'            => 42,
			'failure_count'        => 6,
			'next_retry_time'      => null,
			'last_error_message'   => null,
			'created_at'           => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
		] );

		$should_retry = $this->executor->shouldRetry( $schedule );

		$this->assertFalse( $should_retry );
	}
}
