<?php
/**
 * Phase 4-E-D Dashboard Features Integration Test
 *
 * @package YITH_Auctions\Tests\Integration
 * @covers Dashboard Features with WordPress Integration
 * @requirement REQ-DASHBOARD-ADMIN-001-005, REQ-DASHBOARD-SELLER-PAYOUT-001-003, REQ-DASHBOARD-FINANCIAL-REPORTS-001, REQ-DASHBOARD-BATCH-OPS-001
 */

namespace YITH_Auctions\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\UI\Dashboard\AdminReportingDashboard;
use YITH_Auctions\UI\Dashboard\SellerPayoutDashboard;
use YITH_Auctions\UI\Dashboard\FinancialReportsDashboard;
use YITH_Auctions\UI\Dashboard\BatchOperationsDashboard;
use YITH_Auctions\Services\Dashboard\DashboardDataService;
use YITH_Auctions\Services\Dashboard\PayoutDataService;
use YITH_Auctions\Services\Dashboard\ReportGeneratorService;
use YITH_Auctions\Services\Dashboard\BatchJobService;

/**
 * Integration tests for Phase 4-E-D dashboard features
 *
 * Verifies that all dashboard components work together with
 * services and data layer in a realistic scenario.
 *
 * @since 1.0.0
 */
class DashboardFeaturesIntegrationTest extends TestCase {
	/**
	 * Admin dashboard instance
	 *
	 * @var AdminReportingDashboard
	 */
	private AdminReportingDashboard $admin_dashboard;

	/**
	 * Seller dashboard instance
	 *
	 * @var SellerPayoutDashboard
	 */
	private SellerPayoutDashboard $seller_dashboard;

	/**
	 * Reports dashboard instance
	 *
	 * @var FinancialReportsDashboard
	 */
	private FinancialReportsDashboard $reports_dashboard;

	/**
	 * Batch operations dashboard instance
	 *
	 * @var BatchOperationsDashboard
	 */
	private BatchOperationsDashboard $batch_dashboard;

	/**
	 * Set up fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$data_service = new DashboardDataService();
		$payout_service = new PayoutDataService();
		$report_service = new ReportGeneratorService();
		$batch_service = new BatchJobService();

		$this->admin_dashboard = new AdminReportingDashboard( $data_service );
		$this->seller_dashboard = new SellerPayoutDashboard( $payout_service, 1 );
		$this->reports_dashboard = new FinancialReportsDashboard( $report_service );
		$this->batch_dashboard = new BatchOperationsDashboard( $batch_service );
	}

	/**
	 * Test all dashboards can be rendered
	 *
	 * @test
	 * @return void
	 */
	public function testAllDashboardsCanBeRendered(): void {
		$admin_html = $this->admin_dashboard->render();
		$seller_html = $this->seller_dashboard->render();
		$reports_html = $this->reports_dashboard->render();
		$batch_html = $this->batch_dashboard->render();

		$this->assertIsString( $admin_html );
		$this->assertIsString( $seller_html );
		$this->assertIsString( $reports_html );
		$this->assertIsString( $batch_html );

		$this->assertNotEmpty( $admin_html );
		$this->assertNotEmpty( $seller_html );
		$this->assertNotEmpty( $reports_html );
		$this->assertNotEmpty( $batch_html );
	}

	/**
	 * Test all dashboards produce valid HTML
	 *
	 * @test
	 * @return void
	 */
	public function testAllDashboardsProduceValidHtml(): void {
		$dashboards = [
			'admin' => $this->admin_dashboard->render(),
			'seller' => $this->seller_dashboard->render(),
			'reports' => $this->reports_dashboard->render(),
			'batch' => $this->batch_dashboard->render(),
		];

		foreach ( $dashboards as $name => $html ) {
			$this->assertStringContainsString( 'dashboard', strtolower( $html ), "Dashboard {$name}" );
			$this->assertStringContainsString( '<div', $html, "Dashboard {$name} missing opening div" );
			$this->assertStringContainsString( '</div>', $html, "Dashboard {$name} missing closing div" );
		}
	}

	/**
	 * Test data services return consistent data structures
	 *
	 * @test
	 * @return void
	 */
	public function testDataServicesReturnConsistentStructures(): void {
		$data_service = new DashboardDataService();
		$payout_service = new PayoutDataService();
		$report_service = new ReportGeneratorService();
		$batch_service = new BatchJobService();

		// Test DashboardDataService methods return arrays
		$this->assertIsArray( $data_service->getSettlementMetrics() );
		$this->assertIsArray( $data_service->getSellerPerformance() );
		$this->assertIsArray( $data_service->getRevenueAnalysis() );
		$this->assertIsArray( $data_service->getDisputeStatistics() );
		$this->assertIsArray( $data_service->getSystemHealth() );

		// Test PayoutDataService methods return arrays
		$this->assertIsArray( $payout_service->getPayoutSummary() );
		$this->assertIsArray( $payout_service->getPayoutHistory() );
		$this->assertIsArray( $payout_service->getCurrentMonthEarnings() );
		$this->assertIsArray( $payout_service->getAnnualEarningsSummary() );
		$this->assertIsArray( $payout_service->getPendingPayouts() );

		// Test ReportGeneratorService methods work
		$this->assertIsArray( $report_service->getAvailableReports() );

		// Test BatchJobService methods work
		$this->assertIsArray( $batch_service->getPendingJobs() );
		$this->assertIsArray( $batch_service->getStatistics() );
	}

	/**
	 * Test batch job lifecycle
	 *
	 * @test
	 * @return void
	 */
	public function testBatchJobLifecycle(): void {
		$batch_service = new BatchJobService();

		// Create job
		$job_id = $batch_service->createJob(
			BatchJobService::TYPE_BULK_PAYOUT,
			[ 'total_items' => 100 ],
			'Test job'
		);

		$this->assertIsInt( $job_id );
		$this->assertGreater( 0, $job_id );

		// Mark as running
		$batch_service->markAsRunning( $job_id );

		// Update progress
		$batch_service->updateProgress( $job_id, 50 );

		// Check progress
		$progress = $batch_service->getProgress( $job_id );
		$this->assertIsInt( $progress );

		// Add log
		$batch_service->addLog( $job_id, 'Test log' );

		// Mark as completed
		$batch_service->markAsCompleted( $job_id, 'All items processed' );

		// Get statistics
		$stats = $batch_service->getStatistics();
		$this->assertIsArray( $stats );
	}

	/**
	 * Test dashboard component integration with services
	 *
	 * @test
	 * @return void
	 */
	public function testDashboardsIntegrateWithServices(): void {
		// Admin dashboard uses DashboardDataService
		$admin_html = $this->admin_dashboard->render();
		$this->assertStringContainsString( 'Admin Reporting Dashboard', $admin_html );

		// Seller dashboard uses PayoutDataService
		$seller_html = $this->seller_dashboard->render();
		$this->assertStringContainsString( 'Seller Payout Dashboard', $seller_html );

		// Reports dashboard uses ReportGeneratorService
		$reports_html = $this->reports_dashboard->render();
		$this->assertStringContainsString( 'Financial Reports', $reports_html );

		// Batch dashboard uses BatchJobService
		$batch_html = $this->batch_dashboard->render();
		$this->assertStringContainsString( 'Batch Operations', $batch_html );
	}

	/**
	 * Test dashboard error handling
	 *
	 * @test
	 * @return void
	 */
	public function testDashboardsHandleErrorsGracefully(): void {
		// Create dashboards and render - should not throw exceptions
		try {
			$this->admin_dashboard->render();
			$this->seller_dashboard->render();
			$this->reports_dashboard->render();
			$this->batch_dashboard->render();
			$this->assertTrue( true );
		} catch ( \Exception $e ) {
			$this->fail( "Dashboard rendering threw exception: " . $e->getMessage() );
		}
	}

	/**
	 * Test cache management
	 *
	 * @test
	 * @return void
	 */
	public function testCacheManagement(): void {
		$data_service = new DashboardDataService();
		$payout_service = new PayoutDataService();

		// Get data (should cache)
		$metrics1 = $data_service->getSettlementMetrics();

		// Clear cache
		$data_service->clearCache();

		// Get data again (should not be cached)
		$metrics2 = $data_service->getSettlementMetrics();

		// Both should be arrays with same structure
		$this->assertIsArray( $metrics1 );
		$this->assertIsArray( $metrics2 );

		// Payout cache
		$summary1 = $payout_service->getPayoutSummary();
		$payout_service->clearCache();
		$summary2 = $payout_service->getPayoutSummary();

		$this->assertIsArray( $summary1 );
		$this->assertIsArray( $summary2 );
	}

	/**
	 * Test data consistency across dashboards
	 *
	 * @test
	 * @return void
	 */
	public function testDataConsistencyAcrossDashboards(): void {
		$data_service = new DashboardDataService();
		$batch_service = new BatchJobService();

		// Get metrics from both
		$metrics = $data_service->getSettlementMetrics();
		$stats = $batch_service->getStatistics();

		// Both should return arrays with numeric values
		$this->assertIsArray( $metrics );
		$this->assertIsArray( $stats );

		foreach ( $metrics as $key => $value ) {
			if ( 'timestamp' !== $key ) {
				$this->assertTrue( is_numeric( $value ) || is_array( $value ), "Metric {$key} is not numeric or array" );
			}
		}

		foreach ( $stats as $key => $value ) {
			$this->assertIsInt( $value, "Stat {$key} is not integer" );
		}
	}
}
