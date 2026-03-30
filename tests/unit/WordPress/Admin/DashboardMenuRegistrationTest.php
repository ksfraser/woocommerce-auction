<?php
/**
 * Tests for Dashboard Menu Registration
 *
 * @package YITH_Auctions\Tests
 * @subpackage WordPress
 */

namespace YITH_Auctions\Tests\WordPress\Admin;

use YITH_Auctions\WordPress\Admin\DashboardMenuRegistration;
use YITH_Auctions\WordPress\Admin\DashboardPageController;
use PHPUnit\Framework\TestCase;

/**
 * Test DashboardMenuRegistration class
 *
 * @covers YITH_Auctions\WordPress\Admin\DashboardMenuRegistration
 */
class DashboardMenuRegistrationTest extends TestCase {
	/**
	 * Menu registration instance
	 *
	 * @var DashboardMenuRegistration
	 */
	private DashboardMenuRegistration $registration;

	/**
	 * Page controller mock
	 *
	 * @var DashboardPageController
	 */
	private DashboardPageController $controller;

	/**
	 * Set up test
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->controller = $this->createMock( DashboardPageController::class );
		$this->registration = new DashboardMenuRegistration( $this->controller );
	}

	/**
	 * Test menu slug constants are defined
	 *
	 * @test
	 * @return void
	 */
	public function testMenuSlugsAreDefined(): void {
		$this->assertSame( 'yith_auction_settlement_dashboard', DashboardMenuRegistration::MENU_SLUG_SETTLEMENT );
		$this->assertSame( 'yith_auction_admin_reports', DashboardMenuRegistration::MENU_SLUG_REPORTS );
		$this->assertSame( 'yith_auction_seller_payouts', DashboardMenuRegistration::MENU_SLUG_PAYOUTS );
		$this->assertSame( 'yith_auction_batch_operations', DashboardMenuRegistration::MENU_SLUG_BATCH_OPS );
	}

	/**
	 * Test getMenuSlugs returns all slugs
	 *
	 * @test
	 * @return void
	 */
	public function testGetMenuSlugsReturnsAllSlugs(): void {
		$slugs = DashboardMenuRegistration::getMenuSlugs();

		$this->assertCount( 4, $slugs );
		$this->assertContains( 'yith_auction_settlement_dashboard', $slugs );
		$this->assertContains( 'yith_auction_admin_reports', $slugs );
		$this->assertContains( 'yith_auction_seller_payouts', $slugs );
		$this->assertContains( 'yith_auction_batch_operations', $slugs );
	}

	/**
	 * Test constructor registers hooks
	 *
	 * @test
	 * @return void
	 */
	public function testConstructorRegistersHooks(): void {
		// Verify admin_menu hook is registered
		$this->assertTrue( has_action( 'admin_menu' ) );
	}

	/**
	 * Test isDashboardPage returns false for invalid page
	 *
	 * @test
	 * @return void
	 */
	public function testIsDashboardPageReturnsFalseForInvalidPage(): void {
		// Mock $_GET
		$_GET['page'] = 'some_other_page';

		$this->assertFalse( DashboardMenuRegistration::isDashboardPage() );
	}

	/**
	 * Test isDashboardPage returns true for valid dashboard page
	 *
	 * @test
	 * @return void
	 */
	public function testIsDashboardPageReturnsTrueForValidPage(): void {
		// Mock $_GET
		$_GET['page'] = 'yith_auction_settlement_dashboard';

		$this->assertTrue( DashboardMenuRegistration::isDashboardPage() );
	}

	/**
	 * Test isDashboardPage returns false when page not set
	 *
	 * @test
	 * @return void
	 */
	public function testIsDashboardPageReturnsFalseWhenPageNotSet(): void {
		unset( $_GET['page'] );

		$this->assertFalse( DashboardMenuRegistration::isDashboardPage() );
	}
}
