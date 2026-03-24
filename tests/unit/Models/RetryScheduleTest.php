<?php
/**
 * RetrySchedule Model Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests/Unit/Models
 * @version    1.0.0
 * @requirement REQ-4D-039: Test retry scheduling with exponential backoff
 */

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\RetrySchedule;

/**
 * Test RetrySchedule value object
 *
 * @covers \WC\Auction\Models\RetrySchedule
 */
class RetryScheduleTest extends TestCase {

	/**
	 * Test create new retry schedule
	 */
	public function testCreateNewRetrySchedule() {
		$payout_id = 42;
		$schedule = RetrySchedule::create( $payout_id );

		$this->assertNull( $schedule->getId() );
		$this->assertEquals( $payout_id, $schedule->getPayoutId() );
		$this->assertEquals( 0, $schedule->getFailureCount() );
		$this->assertNull( $schedule->getNextRetryTime() );
		$this->assertNull( $schedule->getLastErrorMessage() );
	}

	/**
	 * Test from database restores all properties
	 */
	public function testFromDatabaseRestoresAllProperties() {
		$row = [
			'id'                   => 10,
			'payout_id'            => 42,
			'failure_count'        => 2,
			'next_retry_time'      => '2026-03-24 15:30:00',
			'last_error_message'   => 'Connection timeout',
			'created_at'           => '2026-03-24 10:00:00',
		];

		$schedule = RetrySchedule::fromDatabase( $row );

		$this->assertEquals( 10, $schedule->getId() );
		$this->assertEquals( 42, $schedule->getPayoutId() );
		$this->assertEquals( 2, $schedule->getFailureCount() );
		$this->assertInstanceOf( \DateTime::class, $schedule->getNextRetryTime() );
		$this->assertEquals( 'Connection timeout', $schedule->getLastErrorMessage() );
	}

	/**
	 * Test increment failure count
	 */
	public function testIncrementFailureCount() {
		$schedule = RetrySchedule::create( 42 );
		$this->assertEquals( 0, $schedule->getFailureCount() );

		$schedule->incrementFailureCount();
		$this->assertEquals( 1, $schedule->getFailureCount() );

		$schedule->incrementFailureCount();
		$this->assertEquals( 2, $schedule->getFailureCount() );
	}

	/**
	 * Test set next retry time
	 */
	public function testSetNextRetryTime() {
		$schedule = RetrySchedule::create( 42 );
		$future = new \DateTime( '+5 minutes' );

		$schedule->setNextRetryTime( $future );

		$this->assertEquals( $future, $schedule->getNextRetryTime() );
	}

	/**
	 * Test set error message
	 */
	public function testSetErrorMessage() {
		$schedule = RetrySchedule::create( 42 );
		$message = 'Payment processor returned 503 Service Unavailable';

		$schedule->setErrorMessage( $message );

		$this->assertEquals( $message, $schedule->getLastErrorMessage() );
	}

	/**
	 * Test is retry due calculation
	 */
	public function testIsRetryDueWhenPastRetryTime() {
		$schedule = RetrySchedule::create( 42 );
		$past = new \DateTime( '-5 minutes' );
		$schedule->setNextRetryTime( $past );

		$this->assertTrue( $schedule->isRetryDue() );
	}

	/**
	 * Test is retry due returns false for future time
	 */
	public function testIsRetryDueReturnsFalseForFutureTime() {
		$schedule = RetrySchedule::create( 42 );
		$future = new \DateTime( '+5 minutes' );
		$schedule->setNextRetryTime( $future );

		$this->assertFalse( $schedule->isRetryDue() );
	}

	/**
	 * Test is retry due returns false when no retry time set
	 */
	public function testIsRetryDueReturnsFalseWhenNoRetryTimeSet() {
		$schedule = RetrySchedule::create( 42 );

		$this->assertFalse( $schedule->isRetryDue() );
	}

	/**
	 * Test get remaining seconds until retry
	 */
	public function testGetRemainingSecondsUntilRetry() {
		$schedule = RetrySchedule::create( 42 );
		$future = new \DateTime( '+300 seconds' );
		$schedule->setNextRetryTime( $future );

		$remaining = $schedule->getRemainingSeconds();

		// Should be approximately 300 seconds (allow 1 second variance)
		$this->assertGreaterThanOrEqual( 299, $remaining );
		$this->assertLessThanOrEqual( 301, $remaining );
	}

	/**
	 * Test get remaining seconds returns 0 when past due
	 */
	public function testGetRemainingSecondsReturnsZeroWhenPastDue() {
		$schedule = RetrySchedule::create( 42 );
		$past = new \DateTime( '-5 minutes' );
		$schedule->setNextRetryTime( $past );

		$remaining = $schedule->getRemainingSeconds();

		$this->assertLessThanOrEqual( 0, $remaining );
	}

	/**
	 * Test to array serialization
	 */
	public function testToArraySerializesAllProperties() {
		$schedule = RetrySchedule::create( 42 );
		$schedule->incrementFailureCount();
		$schedule->incrementFailureCount();
		$future = new \DateTime( '2026-03-24 15:30:00' );
		$schedule->setNextRetryTime( $future );
		$schedule->setErrorMessage( 'Test error' );

		$arr = $schedule->toArray();

		$this->assertNull( $arr['id'] );
		$this->assertEquals( 42, $arr['payout_id'] );
		$this->assertEquals( 2, $arr['failure_count'] );
		$this->assertIsString( $arr['next_retry_time'] );
		$this->assertEquals( 'Test error', $arr['last_error_message'] );
	}

	/**
	 * Test to array includes ID when set
	 */
	public function testToArrayIncludesIdWhenSet() {
		$row = [
			'id'                   => 99,
			'payout_id'            => 42,
			'failure_count'        => 0,
			'next_retry_time'      => null,
			'last_error_message'   => null,
			'created_at'           => '2026-03-24 10:00:00',
		];

		$schedule = RetrySchedule::fromDatabase( $row );
		$arr = $schedule->toArray();

		$this->assertEquals( 99, $arr['id'] );
	}

	/**
	 * Test exponential backoff calculation
	 */
	public function testCalculateBackoffSeconds() {
		$backoff = RetrySchedule::getBackoffSeconds();

		// Should return array: [0, 300, 1800, 7200, 28800, 86400]
		$this->assertIsArray( $backoff );
		$this->assertCount( 6, $backoff );
		$this->assertEquals( 0, $backoff[0] );         // immediate
		$this->assertEquals( 300, $backoff[1] );       // 5 minutes
		$this->assertEquals( 1800, $backoff[2] );      // 30 minutes
		$this->assertEquals( 7200, $backoff[3] );      // 2 hours
		$this->assertEquals( 28800, $backoff[4] );     // 8 hours
		$this->assertEquals( 86400, $backoff[5] );     // 24 hours
	}

	/**
	 * Test max retry attempts constant
	 */
	public function testMaxRetryAttemptsConstant() {
		$this->assertEquals( 6, RetrySchedule::MAX_RETRIES );
	}

	/**
	 * Test has exceeded max retries
	 */
	public function testHasExceededMaxRetries() {
		$schedule = RetrySchedule::create( 42 );

		// 1-5 retries should not exceed (failure_count < 6)
		for ( $i = 0; $i < 5; $i++ ) {
			$schedule->incrementFailureCount();
			$this->assertFalse( $schedule->hasExceededMaxRetries() );
		}

		// 6th increment should exceed (failure_count >= 6)
		$schedule->incrementFailureCount();
		$this->assertTrue( $schedule->hasExceededMaxRetries() );
	}
}
