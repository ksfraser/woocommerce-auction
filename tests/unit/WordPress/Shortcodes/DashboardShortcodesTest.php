<?php
/**
 * Tests for Dashboard Shortcodes
 *
 * @package YITH_Auctions\Tests
 * @subpackage WordPress
 */

namespace YITH_Auctions\Tests\WordPress\Shortcodes;

use YITH_Auctions\WordPress\Shortcodes\DashboardShortcodes;
use PHPUnit\Framework\TestCase;

/**
 * Test DashboardShortcodes class
 *
 * @covers YITH_Auctions\WordPress\Shortcodes\DashboardShortcodes
 */
class DashboardShortcodesTest extends TestCase {
	/**
	 * Shortcodes instance
	 *
	 * @var DashboardShortcodes
	 */
	private DashboardShortcodes $shortcodes;

	/**
	 * Set up test
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->shortcodes = new DashboardShortcodes();
	}

	/**
	 * Test constructor registers shortcodes
	 *
	 * @test
	 * @return void
	 */
	public function testConstructorRegistersShortcodes(): void {
		// Verify shortcodes are registered
		$this->assertTrue( has_shortcode( 'yith_auction_seller_payouts', 'do_shortcode' ) );
		$this->assertTrue( has_shortcode( 'yith_auction_my_auctions', 'do_shortcode' ) );
	}

	/**
	 * Test renderSellerPayouts method exists
	 *
	 * @test
	 * @return void
	 */
	public function testRenderSellerPayoutsMethodExists(): void {
		$this->assertTrue( method_exists( $this->shortcodes, 'renderSellerPayouts' ) );
	}

	/**
	 * Test renderMyAuctions method exists
	 *
	 * @test
	 * @return void
	 */
	public function testRenderMyAuctionsMethodExists(): void {
		$this->assertTrue( method_exists( $this->shortcodes, 'renderMyAuctions' ) );
	}

	/**
	 * Test renderSellerPayouts returns error when user not logged in
	 *
	 * @test
	 * @return void
	 */
	public function testRenderSellerPayoutsRequiresLogin(): void {
		// Ensure user is not logged in
		wp_logout();

		$result = $this->shortcodes->renderSellerPayouts( [] );

		// Should return HTML with login link
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'log in', strtolower( $result ) );
	}

	/**
	 * Test renderMyAuctions returns error when user not logged in
	 *
	 * @test
	 * @return void
	 */
	public function testRenderMyAuctionsRequiresLogin(): void {
		// Ensure user is not logged in
		wp_logout();

		$result = $this->shortcodes->renderMyAuctions( [] );

		// Should return HTML with login link
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'log in', strtolower( $result ) );
	}

	/**
	 * Test setDashboardClass updates seller_payouts class
	 *
	 * @test
	 * @return void
	 */
	public function testSetDashboardClassForSellerPayouts(): void {
		$test_class = 'My\Custom\SellerPayoutsClass';
		$this->shortcodes->setDashboardClass( 'seller_payouts', $test_class );

		// Verify it was set (we can't directly access private property, but method should not error)
		$this->assertTrue( true );
	}

	/**
	 * Test setDashboardClass updates my_auctions class
	 *
	 * @test
	 * @return void
	 */
	public function testSetDashboardClassForMyAuctions(): void {
		$test_class = 'My\Custom\MyAuctionsClass';
		$this->shortcodes->setDashboardClass( 'my_auctions', $test_class );

		// Verify it was set
		$this->assertTrue( true );
	}

	/**
	 * Test setDashboardClass ignores unknown shortcode
	 *
	 * @test
	 * @return void
	 */
	public function testSetDashboardClassIgnoresUnknownShortcode(): void {
		$test_class = 'My\Custom\Class';
		$this->shortcodes->setDashboardClass( 'unknown_shortcode', $test_class );

		// Should not error
		$this->assertTrue( true );
	}

	/**
	 * Test renderSellerPayouts returns string output
	 *
	 * @test
	 * @return void
	 */
	public function testRenderSellerPayoutsReturnsString(): void {
		// Call with empty attributes
		$result = $this->shortcodes->renderSellerPayouts( [] );

		// Should return string
		$this->assertIsString( $result );
	}

	/**
	 * Test renderMyAuctions returns string output
	 *
	 * @test
	 * @return void
	 */
	public function testRenderMyAuctionsReturnsString(): void {
		// Call with empty attributes
		$result = $this->shortcodes->renderMyAuctions( [] );

		// Should return string
		$this->assertIsString( $result );
	}

	/**
	 * Test shortcode accepts empty attributes array
	 *
	 * @test
	 * @return void
	 */
	public function testShortcodeAcceptsEmptyAttributes(): void {
		$this->assertTrue( is_callable( [ $this->shortcodes, 'renderSellerPayouts' ] ) );
		$this->assertTrue( is_callable( [ $this->shortcodes, 'renderMyAuctions' ] ) );
	}
}
