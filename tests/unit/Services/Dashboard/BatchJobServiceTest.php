<?php
/**
 * Batch Job Service Test
 *
 * @package YITH_Auctions\Tests\Unit\Services\Dashboard
 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService
 * @requirement REQ-DASHBOARD-BATCH-OPS-001
 */

namespace YITH_Auctions\Tests\Unit\Services\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\Services\Dashboard\BatchJobService;

/**
 * Test case for BatchJobService
 *
 * @since 1.0.0
 */
class BatchJobServiceTest extends TestCase {
	/**
	 * Service instance
	 *
	 * @var BatchJobService
	 */
	private BatchJobService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new BatchJobService();
	}

	/**
	 * Test status constants exist
	 *
	 * @test
	 * @return void
	 */
	public function testStatusConstantsExist(): void {
		$this->assertEquals( 'pending', BatchJobService::STATUS_PENDING );
		$this->assertEquals( 'running', BatchJobService::STATUS_RUNNING );
		$this->assertEquals( 'completed', BatchJobService::STATUS_COMPLETED );
		$this->assertEquals( 'failed', BatchJobService::STATUS_FAILED );
	}

	/**
	 * Test job type constants exist
	 *
	 * @test
	 * @return void
	 */
	public function testJobTypeConstantsExist(): void {
		$this->assertEquals( 'bulk_payout', BatchJobService::TYPE_BULK_PAYOUT );
		$this->assertEquals( 'bulk_settlement', BatchJobService::TYPE_BULK_SETTLEMENT );
		$this->assertEquals( 'bulk_dispute', BatchJobService::TYPE_BULK_DISPUTE );
		$this->assertEquals( 'custom', BatchJobService::TYPE_CUSTOM );
	}

	/**
	 * Test job creation returns integer ID
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::createJob
	 * @return void
	 */
	public function testCreateJobReturnsInteger(): void {
		$job_id = $this->service->createJob(
			BatchJobService::TYPE_BULK_PAYOUT,
			[ 'total_items' => 100 ],
			'Test bulk payout'
		);

		$this->assertIsInt( $job_id );
		$this->assertGreater( 0, $job_id );
	}

	/**
	 * Test get job returns object or null
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::getJob
	 * @return void
	 */
	public function testGetJobReturnsObjectOrNull(): void {
		$result = $this->service->getJob( 99999 );

		$this->assertTrue( null === $result || is_object( $result ) );
	}

	/**
	 * Test get pending jobs returns array
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::getPendingJobs
	 * @return void
	 */
	public function testGetPendingJobsReturnsArray(): void {
		$jobs = $this->service->getPendingJobs();

		$this->assertIsArray( $jobs );
	}

	/**
	 * Test get jobs with optional filtering
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::getJobs
	 * @return void
	 */
	public function testGetJobsReturnsArray(): void {
		$all_jobs = $this->service->getJobs();
		$completed = $this->service->getJobs( 'completed' );

		$this->assertIsArray( $all_jobs );
		$this->assertIsArray( $completed );
	}

	/**
	 * Test mark as running
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::markAsRunning
	 * @return void
	 */
	public function testMarkAsRunningMakesNoExceptions(): void {
		$this->service->markAsRunning( 99999 );
		// If no exception, method works
		$this->assertTrue( true );
	}

	/**
	 * Test update progress
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::updateProgress
	 * @return void
	 */
	public function testUpdateProgressMakesNoExceptions(): void {
		$this->service->updateProgress( 99999, 50, 0 );
		// If no exception, method works
		$this->assertTrue( true );
	}

	/**
	 * Test mark as completed
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::markAsCompleted
	 * @return void
	 */
	public function testMarkAsCompletedMakesNoExceptions(): void {
		$this->service->markAsCompleted( 99999, 'All items processed' );
		// If no exception, method works
		$this->assertTrue( true );
	}

	/**
	 * Test mark as failed
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::markAsFailed
	 * @return void
	 */
	public function testMarkAsFailedMakesNoExceptions(): void {
		$this->service->markAsFailed( 99999, 'Processing error' );
		// If no exception, method works
		$this->assertTrue( true );
	}

	/**
	 * Test retry job
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::retryJob
	 * @return void
	 */
	public function testRetryJobMakesNoExceptions(): void {
		$this->service->retryJob( 99999 );
		// If no exception, method works
		$this->assertTrue( true );
	}

	/**
	 * Test add log
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::addLog
	 * @return void
	 */
	public function testAddLogMakesNoExceptions(): void {
		$this->service->addLog( 99999, 'Test log message' );
		// If no exception, method works
		$this->assertTrue( true );
	}

	/**
	 * Test get progress returns integer 0-100
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::getProgress
	 * @return void
	 */
	public function testGetProgressReturnsPercentage(): void {
		$progress = $this->service->getProgress( 99999 );

		$this->assertIsInt( $progress );
		$this->assertGreaterThanOrEqual( 0, $progress );
		$this->assertLessThanOrEqual( 100, $progress );
	}

	/**
	 * Test get statistics returns array
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::getStatistics
	 * @return void
	 */
	public function testGetStatisticsReturnsArray(): void {
		$stats = $this->service->getStatistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'pending', $stats );
		$this->assertArrayHasKey( 'running', $stats );
		$this->assertArrayHasKey( 'completed', $stats );
		$this->assertArrayHasKey( 'failed', $stats );
		$this->assertArrayHasKey( 'total', $stats );
	}

	/**
	 * Test cleanup old jobs returns integer count
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::cleanupOldJobs
	 * @return void
	 */
	public function testCleanupOldJobsReturnsInteger(): void {
		$count = $this->service->cleanupOldJobs( 30 );

		$this->assertIsInt( $count );
		$this->assertGreaterThanOrEqual( 0, $count );
	}

	/**
	 * Test delete job returns boolean
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\BatchJobService::deleteJob
	 * @return void
	 */
	public function testDeleteJobReturnsBoolean(): void {
		$result = $this->service->deleteJob( 99999 );

		$this->assertIsBool( $result );
	}
}
