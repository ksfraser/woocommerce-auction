<?php
/**
 * Admin Reporting Dashboard Test
 *
 * @package YITH_Auctions\Tests\Unit\UI\Dashboard
 * @covers \YITH_Auctions\UI\Dashboard\AdminReportingDashboard
 * @requirement REQ-DASHBOARD-ADMIN-001-005
 */

namespace YITH_Auctions\Tests\Unit\UI\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\UI\Dashboard\AdminReportingDashboard;
use YITH_Auctions\Services\Dashboard\DashboardDataService;

/**
 * Test case for AdminReportingDashboard
 *
 * @since 1.0.0
 */
class AdminReportingDashboardTest extends TestCase {
	/**
	 * Dashboard instance
	 *
	 * @var AdminReportingDashboard
	 */
	private AdminReportingDashboard $dashboard;

	/**
	 * Mock data service
	 *
	 * @var DashboardDataService
	 */
	private DashboardDataService $data_service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->data_service = new DashboardDataService();
		$this->dashboard = new AdminReportingDashboard( $this->data_service );
	}

	/**
	 * Test dashboard render returns string
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\AdminReportingDashboard::render
	 * @return void
	 */
	public function testRenderReturnsString(): void {
		$html = $this->dashboard->render();

		$this->assertIsString( $html );
	}

	/**
	 * Test dashboard HTML contains expected sections
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\AdminReportingDashboard::render
	 * @return void
	 */
	public function testRenderContainsExpectedSections(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'dashboard-header', $html );
		$this->assertStringContainsString( 'settlement-metrics', $html );
		$this->assertStringContainsString( 'seller-performance', $html );
		$this->assertStringContainsString( 'revenue-analysis', $html );
		$this->assertStringContainsString( 'dispute-statistics', $html );
		$this->assertStringContainsString( 'system-health', $html );
	}

	/**
	 * Test dashboard HTML is valid markup
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\AdminReportingDashboard::render
	 * @return void
	 */
	public function testRenderProducesValidHtml(): void {
		$html = $this->dashboard->render();

		// Basic HTML structure validation
		$this->assertStringContainsString( '<div', $html );
		$this->assertStringContainsString( '</div>', $html );
	}

	/**
	 * Test dashboard contains metric labels
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\AdminReportingDashboard::render
	 * @return void
	 */
	public function testRenderContainsMetricLabels(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'Total Auctions', $html );
		$this->assertStringContainsString( 'Settlement Success Rate', $html );
		$this->assertStringContainsString( 'Total GMV', $html );
	}
}
