<?php
/**
 * Financial Reports Dashboard Test
 *
 * @package YITH_Auctions\Tests\Unit\UI\Dashboard
 * @covers \YITH_Auctions\UI\Dashboard\FinancialReportsDashboard
 * @requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001
 */

namespace YITH_Auctions\Tests\Unit\UI\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\UI\Dashboard\FinancialReportsDashboard;
use YITH_Auctions\Services\Dashboard\ReportGeneratorService;

/**
 * Test case for FinancialReportsDashboard
 *
 * @since 1.0.0
 */
class FinancialReportsDashboardTest extends TestCase {
	/**
	 * Dashboard instance
	 *
	 * @var FinancialReportsDashboard
	 */
	private FinancialReportsDashboard $dashboard;

	/**
	 * Report service
	 *
	 * @var ReportGeneratorService
	 */
	private ReportGeneratorService $report_service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->report_service = new ReportGeneratorService();
		$this->dashboard = new FinancialReportsDashboard( $this->report_service );
	}

	/**
	 * Test dashboard render returns string
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\FinancialReportsDashboard::render
	 * @return void
	 */
	public function testRenderReturnsString(): void {
		$html = $this->dashboard->render();

		$this->assertIsString( $html );
	}

	/**
	 * Test dashboard contains report generation form
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\FinancialReportsDashboard::render
	 * @return void
	 */
	public function testRenderContainsReportForm(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'report-generation', $html );
		$this->assertStringContainsString( 'Generate Report', $html );
	}

	/**
	 * Test dashboard shows report management section
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\FinancialReportsDashboard::render
	 * @return void
	 */
	public function testRenderShowsReportManagement(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'available-reports', $html );
		$this->assertStringContainsString( 'Financial Reports', $html );
	}

	/**
	 * Test dashboard form has required fields
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\FinancialReportsDashboard::render
	 * @return void
	 */
	public function testRenderFormHasRequiredFields(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'date_from', $html );
		$this->assertStringContainsString( 'date_to', $html );
		$this->assertStringContainsString( 'report_type', $html );
		$this->assertStringContainsString( 'format', $html );
	}
}
