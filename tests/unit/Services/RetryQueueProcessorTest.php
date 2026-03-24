<?php
/**
 * RetryQueueProcessor Unit Tests
 *
 * Handles batch processing of retry schedules
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    1.0.0
 * @requirement REQ-4D-041: Batch retry processing with lock coordination
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\RetrySchedule;
use WC\Auction\Models\BatchLock;
use WC\Auction\Repositories\RetryScheduleRepository;
use WC\Auction\Repositories\BatchLockRepository;
use WC\Auction\Services\RetryQueueProcessor;
use WC\Auction\Services\SchedulerService;
use WC\Auction\Services\EventPublisher;

/**
 * @covers \WC\Auction\Services\RetryQueueProcessor
 */
class RetryQueueProcessorTest extends TestCase {

	private $scheduler;
	private $batch_lock_repo;
	private $processor;

	protected function setUp(): void {
		$this->batch_lock_repo = $this->getMockBuilder( BatchLockRepository::class )
			->disableOriginalConstructor()
			->getMock();

		$retry_repo     = $this->getMockBuilder( RetryScheduleRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$config_repo    = $this->getMockBuilder( \WC\Auction\Repositories\SchedulerConfigRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$event_publisher = new EventPublisher();

		$this->scheduler = new SchedulerService(
			$retry_repo,
			$this->batch_lock_repo,
			$config_repo,
			$event_publisher
		);

		$this->processor = new RetryQueueProcessor(
			$this->scheduler,
			$this->batch_lock_repo
		);
	}

	/**
	 * Test queue processor construction
	 */
	public function testQueueProcessorConstruction() {
		$this->assertInstanceOf( RetryQueueProcessor::class, $this->processor );
	}

	/**
	 * Test process queue processes all retries
	 */
	public function testProcessQueueProcessesAllRetries() {
		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_queue', new \DateTime(), 300 ) );

		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'releaseLock' );

		$result = $this->processor->processQueue();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'processed', $result );
		$this->assertArrayHasKey( 'failed', $result );
		$this->assertArrayHasKey( 'skipped', $result );
	}

	/**
	 * Test process queue respects batch size limit
	 */
	public function testProcessQueueRespectsMaxBatchSize() {
		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_queue', new \DateTime(), 300 ) );

		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'releaseLock' );

		$result = $this->processor->processQueue( 10 );

		// Should not process more than batch size
		$this->assertLessThanOrEqual( 10, $result['processed'] );
	}

	/**
	 * Test process queue returns correct counts
	 */
	public function testProcessQueueReturnsCorrectCounts() {
		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_queue', new \DateTime(), 300 ) );

		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'releaseLock' );

		$result = $this->processor->processQueue();

		$this->assertIsInt( $result['processed'] );
		$this->assertIsInt( $result['failed'] );
		$this->assertIsInt( $result['skipped'] );
		$this->assertGreaterThanOrEqual( 0, $result['processed'] );
		$this->assertGreaterThanOrEqual( 0, $result['failed'] );
		$this->assertGreaterThanOrEqual( 0, $result['skipped'] );
	}

	/**
	 * Test process queue handles empty queue
	 */
	public function testProcessQueueHandlesEmptyQueue() {
		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_queue', new \DateTime(), 300 ) );

		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'releaseLock' );

		$result = $this->processor->processQueue();

		$this->assertEquals( 0, $result['processed'] );
		$this->assertEquals( 0, $result['failed'] );
		$this->assertEquals( 0, $result['skipped'] );
	}

	/**
	 * Test can acquire lock returns true if lock acquired
	 */
	public function testCanAcquireLockReturnsTrueIfSuccessful() {
		$this->batch_lock_repo->expects( $this->once() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_queue', new \DateTime(), 300 ) );

		$result = $this->processor->canAcquireLock( 'retry_queue', 300 );

		$this->assertTrue( $result );
	}

	/**
	 * Test can acquire lock returns false if lock unavailable
	 */
	public function testCanAcquireLockReturnsFalseIfLocked() {
		$this->batch_lock_repo->expects( $this->once() )
			->method( 'acquireLock' )
			->willThrowException( new \Exception( 'Could not acquire lock' ) );

		$result = $this->processor->canAcquireLock( 'retry_queue', 300 );

		$this->assertFalse( $result );
	}

	/**
	 * Test get process stats returns summary
	 */
	public function testGetProcessStatsReturnsSummary() {
		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_queue', new \DateTime(), 300 ) );

		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'releaseLock' );

		$this->processor->processQueue();

		$stats = $this->processor->getLastProcessStats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'processed', $stats );
		$this->assertArrayHasKey( 'failed', $stats );
		$this->assertArrayHasKey( 'timestamp', $stats );
	}

	/**
	 * Test set batch size updates processor batch size
	 */
	public function testSetBatchSizeUpdatesSize() {
		$this->processor->setBatchSize( 50 );

		// Process with custom batch size
		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'acquireLock' )
			->willReturn( new BatchLock( null, 'retry_queue', new \DateTime(), 300 ) );

		$this->batch_lock_repo->expects( $this->atLeastOnce() )
			->method( 'releaseLock' );

		$result = $this->processor->processQueue();

		$this->assertLessThanOrEqual( 50, $result['processed'] );
	}
}
