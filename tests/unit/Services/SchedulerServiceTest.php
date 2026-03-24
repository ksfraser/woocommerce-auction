<?php
/**
 * SchedulerService Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    1.0.0
 * @requirement REQ-4D-041: Scheduler service orchestration
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\RetrySchedule;
use WC\Auction\Models\BatchLock;
use WC\Auction\Models\SchedulerConfig;
use WC\Auction\Repositories\RetryScheduleRepository;
use WC\Auction\Repositories\BatchLockRepository;
use WC\Auction\Repositories\SchedulerConfigRepository;
use WC\Auction\Services\EventPublisher;
use WC\Auction\Services\SchedulerService;
use WC\Auction\Events\RetryScheduleCreatedEvent;
use WC\Auction\Events\RetryScheduleFailedEvent;
use WC\Auction\Events\RetryScheduleSucceededEvent;
use WC\Auction\Events\BatchLockAcquiredEvent;
use WC\Auction\Events\BatchLockReleasedEvent;

/**
 * @covers \WC\Auction\Services\SchedulerService
 */
class SchedulerServiceTest extends TestCase {

	private $retry_repo;
	private $batch_lock_repo;
	private $config_repo;
	private $event_publisher;
	private $scheduler;

	protected function setUp(): void {
		$this->retry_repo      = $this->getMockBuilder( RetryScheduleRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$this->batch_lock_repo = $this->getMockBuilder( BatchLockRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$this->config_repo     = $this->getMockBuilder( SchedulerConfigRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$this->event_publisher = new EventPublisher();

		$this->scheduler = new SchedulerService(
			$this->retry_repo,
			$this->batch_lock_repo,
			$this->config_repo,
			$this->event_publisher
		);
	}

	/**
	 * Test SchedulerService construction
	 */
	public function testSchedulerServiceConstruction() {
		$this->assertInstanceOf( SchedulerService::class, $this->scheduler );
	}

	/**
	 * Test schedule retry creates new retry schedule with exponential backoff
	 */
	public function testScheduleRetryCreatesNewSchedule() {
		$payout_id = 42;

		$this->retry_repo->expects( $this->once() )
			->method( 'save' )
			->with( $this->isInstanceOf( RetrySchedule::class ) )
			->willReturn( 1 );

		$result = $this->scheduler->scheduleRetry( $payout_id );

		$this->assertInstanceOf( RetrySchedule::class, $result );
		$this->assertEquals( $payout_id, $result->getPayoutId() );
		$this->assertEquals( 0, $result->getFailureCount() );
	}

	/**
	 * Test schedule retry publishes creation event
	 */
	public function testScheduleRetryPublishesCreatedEvent() {
		$publishers_called = 0;
		$this->event_publisher->subscribe( 'retry_schedule.created', function() use ( &$publishers_called ) {
			$publishers_called++;
		} );

		$this->retry_repo->expects( $this->once() )
			->method( 'save' )
			->willReturn( 1 );

		$this->scheduler->scheduleRetry( 42 );

		$this->assertEquals( 1, $publishers_called );
	}

	/**
	 * Test process due retries finds and processes ready retries
	 */
	public function testProcessDueRetriesFindsDueRetries() {
		$retry1 = RetrySchedule::create( 100 );
		$retry2 = RetrySchedule::create( 101 );

		$this->batch_lock_repo->expects( $this->once() )
			->method( 'acquireLock' )
			->with( 'retry_processing_batch', $this->isType( 'int' ) )
			->willReturn( new BatchLock( null, 'retry_processing_batch', new \DateTime(), 300 ) );

		$this->retry_repo->expects( $this->once() )
			->method( 'findDueRetries' )
			->willReturn( [ $retry1, $retry2 ] );

		$this->batch_lock_repo->expects( $this->once() )
			->method( 'releaseLock' )
			->with( 'retry_processing_batch' )
			->willReturn( true );

		$processed = $this->scheduler->processDueRetries();

		$this->assertIsArray( $processed );
		$this->assertCount( 2, $processed );
	}

	/**
	 * Test process due retries acquires batch lock
	 */
	public function testProcessDueRetriesAcquiresBatchLock() {
		$lock_acquired = false;

		$this->batch_lock_repo->expects( $this->once() )
			->method( 'acquireLock' )
			->willReturnCallback( function() use ( &$lock_acquired ) {
				$lock_acquired = true;
				return new BatchLock( null, 'retry_processing_batch', new \DateTime(), 300 );
			} );

		$this->retry_repo->expects( $this->once() )
			->method( 'findDueRetries' )
			->willReturn( [] );

		$this->batch_lock_repo->expects( $this->once() )
			->method( 'releaseLock' )
			->willReturn( true );

		$this->scheduler->processDueRetries();

		$this->assertTrue( $lock_acquired );
	}

	/**
	 * Test process due retries releases batch lock even on error
	 */
	public function testProcessDueRetriesReleasesLockOnError() {
		$lock_released = false;

		$this->batch_lock_repo->expects( $this->once() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_processing_batch', new \DateTime(), 300 ) );

		$this->retry_repo->expects( $this->once() )
			->method( 'findDueRetries' )
			->willThrowException( new \Exception( 'Database error' ) );

		$this->batch_lock_repo->expects( $this->once() )
			->method( 'releaseLock' )
			->willReturnCallback( function() use ( &$lock_released ) {
				$lock_released = true;
				return true;
			} );

		try {
			$this->scheduler->processDueRetries();
		} catch ( \Exception $e ) {
			// Expected
		}

		$this->assertTrue( $lock_released );
	}

	/**
	 * Test mark retry failed increments failure count
	 */
	public function testMarkRetryFailedIncrementsFailureCount() {
		$schedule = RetrySchedule::create( 42 );

		$this->retry_repo->expects( $this->once() )
			->method( 'update' )
			->with( $this->isInstanceOf( RetrySchedule::class ) )
			->willReturnCallback( function( RetrySchedule $sched ) {
				$sched->setId( 1 );
				return true;
			} );

		$result = $this->scheduler->markRetryFailed( $schedule, 'Payment gateway timeout' );

		$this->assertInstanceOf( RetrySchedule::class, $result );
		$this->assertEquals( 1, $result->getFailureCount() );
		$this->assertEquals( 'Payment gateway timeout', $result->getLastErrorMessage() );
	}

	/**
	 * Test mark retry failed publishes failed event
	 */
	public function testMarkRetryFailedPublishesFailedEvent() {
		$publishers_called = 0;
		$this->event_publisher->subscribe( 'retry_schedule.failed', function() use ( &$publishers_called ) {
			$publishers_called++;
		} );

		$schedule = RetrySchedule::create( 42 );

		$this->retry_repo->expects( $this->once() )
			->method( 'update' )
			->willReturn( true );

		$this->scheduler->markRetryFailed( $schedule, 'Test error' );

		$this->assertEquals( 1, $publishers_called );
	}

	/**
	 * Test mark retry succeeded deletes retry schedule
	 */
	public function testMarkRetrySucceededDeletesSchedule() {
		$schedule = RetrySchedule::fromDatabase( [
			'id'                   => 1,
			'payout_id'            => 42,
			'failure_count'        => 2,
			'next_retry_time'      => null,
			'last_error_message'   => null,
			'created_at'           => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
		] );

		$this->retry_repo->expects( $this->once() )
			->method( 'delete' )
			->with( 1 )
			->willReturn( true );

		$result = $this->scheduler->markRetrySucceeded( $schedule );

		$this->assertTrue( $result );
	}

	/**
	 * Test mark retry succeeded publishes succeeded event
	 */
	public function testMarkRetrySucceededPublishesSucceededEvent() {
		$publishers_called = 0;
		$this->event_publisher->subscribe( 'retry_schedule.succeeded', function() use ( &$publishers_called ) {
			$publishers_called++;
		} );

		$schedule = RetrySchedule::fromDatabase( [
			'id'                   => 1,
			'payout_id'            => 42,
			'failure_count'        => 1,
			'next_retry_time'      => null,
			'last_error_message'   => null,
			'created_at'           => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
		] );

		$this->retry_repo->expects( $this->once() )
			->method( 'delete' )
			->willReturn( true );

		$this->scheduler->markRetrySucceeded( $schedule );

		$this->assertEquals( 1, $publishers_called );
	}

	/**
	 * Test get retry schedule by payout ID
	 */
	public function testGetRetryScheduleByPayoutId() {
		$schedule = RetrySchedule::create( 42 );

		$this->retry_repo->expects( $this->once() )
			->method( 'findByPayoutId' )
			->with( 42 )
			->willReturn( $schedule );

		$result = $this->scheduler->getRetrySchedule( 42 );

		$this->assertSame( $schedule, $result );
	}

	/**
	 * Test get retry schedule returns null if not found
	 */
	public function testGetRetryScheduleReturnsNullIfNotFound() {
		$this->retry_repo->expects( $this->once() )
			->method( 'findByPayoutId' )
			->with( 999 )
			->willReturn( null );

		$result = $this->scheduler->getRetrySchedule( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test has pending retries returns true if retries exist
	 */
	public function testHasPendingRetriesReturnsTrueIfExist() {
		$this->retry_repo->expects( $this->once() )
			->method( 'findDueRetries' )
			->willReturn( [ RetrySchedule::create( 100 ) ] );

		$result = $this->scheduler->hasPendingRetries();

		$this->assertTrue( $result );
	}

	/**
	 * Test has pending retries returns false if none exist
	 */
	public function testHasPendingRetriesReturnsFalseIfNone() {
		$this->retry_repo->expects( $this->once() )
			->method( 'findDueRetries' )
			->willReturn( [] );

		$result = $this->scheduler->hasPendingRetries();

		$this->assertFalse( $result );
	}

	/**
	 * Test update config value
	 */
	public function testUpdateConfigValue() {
		$config = new SchedulerConfig( 1, 'retry_interval', '60', new \DateTime(), new \DateTime() );

		$this->config_repo->expects( $this->once() )
			->method( 'set' )
			->with( 'retry_interval', '120' )
			->willReturn( $config );

		$result = $this->scheduler->updateConfig( 'retry_interval', '120' );

		$this->assertInstanceOf( SchedulerConfig::class, $result );
	}

	/**
	 * Test get config value
	 */
	public function testGetConfigValue() {
		$expected_value = '60';

		$this->config_repo->expects( $this->once() )
			->method( 'get' )
			->with( 'retry_interval' )
			->willReturn( $expected_value );

		$result = $this->scheduler->getConfig( 'retry_interval' );

		$this->assertEquals( $expected_value, $result );
	}
}
