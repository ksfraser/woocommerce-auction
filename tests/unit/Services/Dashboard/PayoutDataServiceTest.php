<?php
/**
 * Payout Data Service Test
 *
 * @package YITH_Auctions\Tests\Unit\Services\Dashboard
 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService
 * @requirement REQ-DASHBOARD-SELLER-PAYOUT-001-003
 */

namespace YITH_Auctions\Tests\Unit\Services\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\Services\Dashboard\PayoutDataService;

/**
 * Test case for PayoutDataService
 *
 * @since 1.0.0
 */
class PayoutDataServiceTest extends TestCase {
	/**
	 * Service instance
	 *
	 * @var PayoutDataService
	 */
	private PayoutDataService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new PayoutDataService();
	}

	/**
	 * Test payout summary for all sellers
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::getPayoutSummary
	 * @return void
	 */
	public function testPayoutSummaryReturnsArray(): void {
		$summary = $this->service->getPayoutSummary();

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'total_earned', $summary );
		$this->assertArrayHasKey( 'total_paid', $summary );
		$this->assertArrayHasKey( 'pending_amount', $summary );
		$this->assertArrayHasKey( 'avg_payout_time_days', $summary );
		$this->assertArrayHasKey( 'success_rate_percent', $summary );
		$this->assertArrayHasKey( 'next_payout_date', $summary );
		$this->assertArrayHasKey( 'payment_method', $summary );
	}

	/**
	 * Test payout summary for specific seller
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::getPayoutSummary
	 * @return void
	 */
	public function testPayoutSummaryWithSellerID(): void {
		$summary = $this->service->getPayoutSummary( 123 );

		$this->assertIsArray( $summary );
		$this->assertIsFloat( $summary['total_earned'] );
		$this->assertIsFloat( $summary['total_paid'] );
	}

	/**
	 * Test payout history retrieval
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::getPayoutHistory
	 * @return void
	 */
	public function testPayoutHistoryReturnsArray(): void {
		$history = $this->service->getPayoutHistory();

		$this->assertIsArray( $history );
	}

	/**
	 * Test current month earnings
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::getCurrentMonthEarnings
	 * @return void
	 */
	public function testCurrentMonthEarningsReturnsArray(): void {
		$earnings = $this->service->getCurrentMonthEarnings();

		$this->assertIsArray( $earnings );
		$this->assertArrayHasKey( 'gross_earnings', $earnings );
		$this->assertArrayHasKey( 'platform_fees', $earnings );
		$this->assertArrayHasKey( 'net_earnings', $earnings );
		$this->assertArrayHasKey( 'tax_withholding', $earnings );
		$this->assertArrayHasKey( 'available_for_payout', $earnings );
		$this->assertArrayHasKey( 'currency', $earnings );
	}

	/**
	 * Test annual earnings summary
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::getAnnualEarningsSummary
	 * @return void
	 */
	public function testAnnualEarningsSummaryReturnsArray(): void {
		$summary = $this->service->getAnnualEarningsSummary();

		$this->assertIsArray( $summary );
		$this->assertCount( 12, $summary );

		// All values should be floats
		foreach ( $summary as $value ) {
			$this->assertIsFloat( $value );
		}
	}

	/**
	 * Test pending payouts retrieval
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::getPendingPayouts
	 * @return void
	 */
	public function testPendingPayoutsReturnsArray(): void {
		$pending = $this->service->getPendingPayouts();

		$this->assertIsArray( $pending );
	}

	/**
	 * Test cache clearing
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::clearCache
	 * @return void
	 */
	public function testClearCacheMakesNoExceptions(): void {
		$this->service->clearCache();
		$this->service->clearCache( 123 );
		// If no exception, cache cleared successfully
		$this->assertTrue( true );
	}

	/**
	 * Test earnings calculations are logical
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\PayoutDataService::getCurrentMonthEarnings
	 * @return void
	 */
	public function testEarningsCalculationsAreLogical(): void {
		$earnings = $this->service->getCurrentMonthEarnings();

		// Net earnings should be gross minus fees
		$expected_net = $earnings['gross_earnings'] - $earnings['platform_fees'];
		$this->assertEquals( $expected_net, $earnings['net_earnings'] );

		// Available should be net minus tax
		$expected_available = $earnings['net_earnings'] - $earnings['tax_withholding'];
		$this->assertEquals( $expected_available, $earnings['available_for_payout'] );
	}
}
