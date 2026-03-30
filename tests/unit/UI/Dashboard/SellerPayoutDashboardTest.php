<?php
/**
 * Seller Payout Dashboard Test
 *
 * @package YITH_Auctions\Tests\Unit\UI\Dashboard
 * @covers \YITH_Auctions\UI\Dashboard\SellerPayoutDashboard
 * @requirement REQ-DASHBOARD-SELLER-PAYOUT-001-003
 */

namespace YITH_Auctions\Tests\Unit\UI\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\UI\Dashboard\SellerPayoutDashboard;
use YITH_Auctions\Services\Dashboard\PayoutDataService;

/**
 * Test case for SellerPayoutDashboard
 *
 * @since 1.0.0
 */
class SellerPayoutDashboardTest extends TestCase {
	/**
	 * Dashboard instance
	 *
	 * @var SellerPayoutDashboard
	 */
	private SellerPayoutDashboard $dashboard;

	/**
	 * Data service
	 *
	 * @var PayoutDataService
	 */
	private PayoutDataService $data_service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->data_service = new PayoutDataService();
		$this->dashboard = new SellerPayoutDashboard( $this->data_service, 1 );
	}

	/**
	 * Test dashboard render returns string
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\SellerPayoutDashboard::render
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
	 * @covers \YITH_Auctions\UI\Dashboard\SellerPayoutDashboard::render
	 * @return void
	 */
	public function testRenderContainsExpectedSections(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'payout-summary', $html );
		$this->assertStringContainsString( 'current-month-earnings', $html );
		$this->assertStringContainsString( 'transaction-history', $html );
		$this->assertStringContainsString( 'annual-trends', $html );
	}

	/**
	 * Test dashboard shows payout information
	 *
	 * @test
	 * @covers \YITH_Auctions\UI\Dashboard\SellerPayoutDashboard::render
	 * @return void
	 */
	public function testRenderShowsPayoutInfo(): void {
		$html = $this->dashboard->render();

		$this->assertStringContainsString( 'Total Earned', $html );
		$this->assertStringContainsString( 'Total Paid', $html );
		$this->assertStringContainsString( 'Pending Payouts', $html );
	}
}
