<?php
/**
 * Dashboard Data Service Test
 *
 * @package YITH_Auctions\Tests\Unit\Services\Dashboard
 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService
 * @requirement REQ-DASHBOARD-ADMIN-001-005
 */

namespace YITH_Auctions\Tests\Unit\Services\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\Services\Dashboard\DashboardDataService;

/**
 * Test case for DashboardDataService
 *
 * @since 1.0.0
 */
class DashboardDataServiceTest extends TestCase {
	/**
	 * Service instance
	 *
	 * @var DashboardDataService
	 */
	private DashboardDataService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new DashboardDataService();
	}

	/**
	 * Test settlement metrics retrieval
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::getSettlementMetrics
	 * @return void
	 */
	public function testSettlementMetricsReturnsArray(): void {
		$metrics = $this->service->getSettlementMetrics();

		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'total_auctions_all_time', $metrics );
		$this->assertArrayHasKey( 'total_auctions_this_month', $metrics );
		$this->assertArrayHasKey( 'total_settlements', $metrics );
		$this->assertArrayHasKey( 'avg_settlement_time_days', $metrics );
		$this->assertArrayHasKey( 'success_rate_percent', $metrics );
		$this->assertArrayHasKey( 'total_gmv', $metrics );
		$this->assertArrayHasKey( 'timestamp', $metrics );
	}

	/**
	 * Test settlement metrics values are numeric
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::getSettlementMetrics
	 * @return void
	 */
	public function testSettlementMetricsValuesAreNumeric(): void {
		$metrics = $this->service->getSettlementMetrics();

		$this->assertIsInt( $metrics['total_auctions_all_time'] );
		$this->assertIsInt( $metrics['total_auctions_this_month'] );
		$this->assertIsInt( $metrics['total_settlements'] );
		$this->assertIsFloat( $metrics['avg_settlement_time_days'] );
		$this->assertIsFloat( $metrics['success_rate_percent'] );
		$this->assertIsFloat( $metrics['total_gmv'] );
	}

	/**
	 * Test seller performance data
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::getSellerPerformance
	 * @return void
	 */
	public function testSellerPerformanceReturnsArray(): void {
		$performance = $this->service->getSellerPerformance();

		$this->assertIsArray( $performance );
		$this->assertArrayHasKey( 'top_sellers', $performance );
		$this->assertArrayHasKey( 'seller_counts', $performance );
		$this->assertArrayHasKey( 'avg_sales_per_seller', $performance );
		$this->assertArrayHasKey( 'trends', $performance );
	}

	/**
	 * Test revenue analysis data
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::getRevenueAnalysis
	 * @return void
	 */
	public function testRevenueAnalysisReturnsArray(): void {
		$analysis = $this->service->getRevenueAnalysis();

		$this->assertIsArray( $analysis );
		$this->assertArrayHasKey( 'total_revenue', $analysis );
		$this->assertArrayHasKey( 'commission_revenue', $analysis );
		$this->assertArrayHasKey( 'breakdown_by_status', $analysis );
		$this->assertArrayHasKey( 'refund_rate_percent', $analysis );
		$this->assertArrayHasKey( 'payment_volume', $analysis );
	}

	/**
	 * Test dispute statistics data
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::getDisputeStatistics
	 * @return void
	 */
	public function testDisputeStatisticsReturnsArray(): void {
		$stats = $this->service->getDisputeStatistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'open_disputes', $stats );
		$this->assertArrayHasKey( 'resolved_this_month', $stats );
		$this->assertArrayHasKey( 'avg_resolution_time_days', $stats );
		$this->assertArrayHasKey( 'resolution_success_rate_percent', $stats );
		$this->assertArrayHasKey( 'by_type', $stats );
	}

	/**
	 * Test system health data
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::getSystemHealth
	 * @return void
	 */
	public function testSystemHealthReturnsArray(): void {
		$health = $this->service->getSystemHealth();

		$this->assertIsArray( $health );
		$this->assertArrayHasKey( 'api_response_times', $health );
		$this->assertArrayHasKey( 'database', $health );
		$this->assertArrayHasKey( 'payment_processor_status', $health );
		$this->assertArrayHasKey( 'uptime_percent', $health );
	}

	/**
	 * Test cache clearing
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::clearCache
	 * @return void
	 */
	public function testClearCacheMakesNoExceptions(): void {
		$this->service->clearCache();
		// If no exception, cache cleared successfully
		$this->assertTrue( true );
	}

	/**
	 * Test metrics are within expected ranges
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\DashboardDataService::getSettlementMetrics
	 * @return void
	 */
	public function testSettlementMetricsWithinValidRanges(): void {
		$metrics = $this->service->getSettlementMetrics();

		// All numeric fields should be >= 0
		$this->assertGreaterThanOrEqual( 0, $metrics['total_auctions_all_time'] );
		$this->assertGreaterThanOrEqual( 0, $metrics['total_auctions_this_month'] );
		$this->assertGreaterThanOrEqual( 0, $metrics['total_settlements'] );

		// Percentages should be 0-100
		$this->assertGreaterThanOrEqual( 0, $metrics['success_rate_percent'] );
		$this->assertLessThanOrEqual( 100, $metrics['success_rate_percent'] );
	}
}
