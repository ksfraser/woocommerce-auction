<?php
/**
 * Batch Operations Dashboard Test
 *
 * @package YITH_Auctions\Tests\Unit\UI\Dashboard
 * @covers \YITH_Auctions\UI\Dashboard\BatchOperationsDashboard
 * @requirement REQ-DASHBOARD-BATCH-OPS-001
 */

namespace YITH_Auctions\Tests\Unit\UI\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\UI\Dashboard\BatchOperationsDashboard;
use YITH_Auctions\Services\Dashboard\BatchJobService;

/**
 * Test case for BatchOperationsDashboard
 *
 * @since 1.0.0
 */
class BatchOperationsDashboardTest extends TestCase {
	/**
	 * Dashboard instance
	 *
	 * @var BatchOperationsDashboard
	 */
	private BatchOperationsDashboard $dashboard;

	/**
	 * Batch service
	 *
	 * @var BatchJobService
	 */
	private BatchJobService $batch_service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->batch_service = new BatchJobService();
		$this->dashboard = new BatchOperationsDashboard( $this->batch_service );
	}

	/**
	 * Test dashboard render returns string
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\BatchOperationsDashboard::render
	 * @return void
	 */
	public function testRenderReturnsString(): void {
		$html = $this->dashboard->render();

		$this->assertIsString( $html );
	}

	/**
	 * Test dashboard contains expected sections
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\BatchOperationsDashboard::render
	 * @return void
	 */
	public function testRenderContainsExpectedSections(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'job-statistics', $html );
		$this->assertStringContainsString( 'job-queue', $html );
		$this->assertStringContainsString( 'job-history', $html );
	}

	/**
	 * Test dashboard shows statistics
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\BatchOperationsDashboard::render
	 * @return void
	 */
	public function testRenderShowsStatistics(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'Pending Jobs', $html );
		$this->assertStringContainsString( 'Running Jobs', $html );
		$this->assertStringContainsString( 'Completed Jobs', $html );
		$this->assertStringContainsString( 'Failed Jobs', $html );
	}

	/**
	 * Test dashboard contains job queue section
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\BatchOperationsDashboard::render
	 * @return void
	 */
	public function testRenderContainsJobQueue(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'Batch Operations', $html );
	}
}
