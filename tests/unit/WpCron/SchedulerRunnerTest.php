<?php
/**
 * SchedulerRunner Service Unit Tests
 *
 * Tests scheduler execution orchestration
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    1.0.0
 * @requirement REQ-4D-041: Scheduler execution orchestration
 */

namespace Tests\Unit\WpCron;

use PHPUnit\Framework\TestCase;
use WC\Auction\WpCron\SchedulerRunner;
use WC\Auction\Services\SchedulerService;
use WC\Auction\Services\RetryQueueProcessor;
use WC\Auction\Services\EventPublisher;

/**
 * @covers \WC\Auction\WpCron\SchedulerRunner
 */
class SchedulerRunnerTest extends TestCase {

	private $scheduler;
	private $queue_processor;
	private $runner;

	protected function setUp(): void {
		$retry_repo      = $this->getMockBuilder( \WC\Auction\Repositories\RetryScheduleRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$batch_lock_repo = $this->getMockBuilder( \WC\Auction\Repositories\BatchLockRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$config_repo     = $this->getMockBuilder( \WC\Auction\Repositories\SchedulerConfigRepository::class )
			->disableOriginalConstructor()
			->getMock();
		$event_publisher = new EventPublisher();

		$this->scheduler = new SchedulerService(
			$retry_repo,
			$batch_lock_repo,
			$config_repo,
			$event_publisher
		);

		$this->queue_processor = new RetryQueueProcessor(
			$this->scheduler,
			$batch_lock_repo
		);

		$this->runner = new SchedulerRunner(
			$this->scheduler,
			$this->queue_processor
		);
	}

	/**
	 * Test SchedulerRunner construction
	 */
	public function testSchedulerRunnerConstruction() {
		$this->assertInstanceOf( SchedulerRunner::class, $this->runner );
	}

	/**
	 * Test run scheduler processes queue
	 */
	public function testRunSchedulerProcessesQueue() {
		$result = $this->runner->run();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'processed', $result );
		$this->assertArrayHasKey( 'failed', $result );
		$this->assertArrayHasKey( 'skipped', $result );
	}

	/**
	 * Test run scheduler returns statistics
	 */
	public function testRunSchedulerReturnsStatistics() {
		$result = $this->runner->run();

		$this->assertIsInt( $result['processed'] );
		$this->assertIsInt( $result['failed'] );
		$this->assertIsInt( $result['skipped'] );
		$this->assertGreaterThanOrEqual( 0, $result['processed'] );
		$this->assertGreaterThanOrEqual( 0, $result['failed'] );
		$this->assertGreaterThanOrEqual( 0, $result['skipped'] );
	}

	/**
	 * Test run scheduler with custom batch size
	 */
	public function testRunSchedulerWithCustomBatchSize() {
		$result = $this->runner->run( 25 );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 25, $result['processed'] );
	}

	/**
	 * Test is running returns scheduling status
	 */
	public function testIsRunningReturnsBool() {
		$is_running = $this->runner->isRunning();

		$this->assertIsBool( $is_running );
	}

	/**
	 * Test get last run timestamp
	 */
	public function testGetLastRunTimestamp() {
		$this->runner->run();

		$timestamp = $this->runner->getLastRunTimestamp();

		$this->assertIsInt( $timestamp );
		$this->assertGreaterThan( 0, $timestamp );
	}

	/**
	 * Test get total processed count
	 */
	public function testGetTotalProcessedCount() {
		$this->runner->run();

		$count = $this->runner->getTotalProcessedCount();

		$this->assertIsInt( $count );
		$this->assertGreaterThanOrEqual( 0, $count );
	}

	/**
	 * Test get total failed count
	 */
	public function testGetTotalFailedCount() {
		$this->runner->run();

		$count = $this->runner->getTotalFailedCount();

		$this->assertIsInt( $count );
		$this->assertGreaterThanOrEqual( 0, $count );
	}

	/**
	 * Test enable scheduler
	 */
	public function testEnableScheduler() {
		$result = $this->runner->enable();

		$this->assertTrue( $result );
	}

	/**
	 * Test disable scheduler
	 */
	public function testDisableScheduler() {
		$result = $this->runner->disable();

		$this->assertTrue( $result );
	}

	/**
	 * Test reset statistics
	 */
	public function testResetStatistics() {
		$this->runner->run();
		$this->runner->resetStatistics();

		$count = $this->runner->getTotalProcessedCount();

		$this->assertEquals( 0, $count );
	}

	/**
	 * Test get health status
	 */
	public function testGetHealthStatus() {
		$status = $this->runner->getHealthStatus();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'running', $status );
		$this->assertArrayHasKey( 'last_run', $status );
		$this->assertArrayHasKey( 'next_run', $status );
	}
}
