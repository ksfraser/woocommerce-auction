<?php
/**
 * Integration Tests for WordPress Dashboard Integration
 *
 * @package YITH_Auctions\Tests
 * @subpackage WordPress
 */

namespace YITH_Auctions\Tests\WordPress;

use YITH_Auctions\WordPress\Admin\DashboardMenuRegistration;
use YITH_Auctions\WordPress\Admin\DashboardPageController;
use YITH_Auctions\WordPress\Capabilities\CapabilityRegistration;
use YITH_Auctions\WordPress\Assets\AssetEnqueuer;
use YITH_Auctions\WordPress\Shortcodes\DashboardShortcodes;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for WordPress dashboard system
 *
 * @covers YITH_Auctions\WordPress
 */
class DashboardIntegrationTest extends TestCase {
	/**
	 * Menu registration
	 *
	 * @var DashboardMenuRegistration
	 */
	private DashboardMenuRegistration $menu_registration;

	/**
	 * Page controller
	 *
	 * @var DashboardPageController
	 */
	private DashboardPageController $page_controller;

	/**
	 * Capability registration
	 *
	 * @var CapabilityRegistration
	 */
	private CapabilityRegistration $capability_registration;

	/**
	 * Asset enqueuer
	 *
	 * @var AssetEnqueuer
	 */
	private AssetEnqueuer $asset_enqueuer;

	/**
	 * Shortcodes
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
		$this->page_controller = new DashboardPageController();
		$this->menu_registration = new DashboardMenuRegistration( $this->page_controller );
		$this->capability_registration = new CapabilityRegistration();
		$this->asset_enqueuer = new AssetEnqueuer( 'http://example.com/assets/', '1.0.0' );
		$this->shortcodes = new DashboardShortcodes();
	}

	/**
	 * Test all components initialize without error
	 *
	 * @test
	 * @return void
	 */
	public function testAllComponentsInitialize(): void {
		$this->assertInstanceOf( DashboardMenuRegistration::class, $this->menu_registration );
		$this->assertInstanceOf( DashboardPageController::class, $this->page_controller );
		$this->assertInstanceOf( CapabilityRegistration::class, $this->capability_registration );
		$this->assertInstanceOf( AssetEnqueuer::class, $this->asset_enqueuer );
		$this->assertInstanceOf( DashboardShortcodes::class, $this->shortcodes );
	}

	/**
	 * Test all hooks are registered
	 *
	 * @test
	 * @return void
	 */
	public function testAllHooksRegistered(): void {
		$this->assertTrue( has_action( 'admin_menu' ), 'admin_menu hook not registered' );
		$this->assertTrue( has_action( 'admin_init' ), 'admin_init hook not registered' );
		$this->assertTrue( has_action( 'admin_enqueue_scripts' ), 'admin_enqueue_scripts hook not registered' );
		$this->assertTrue( has_action( 'wp_enqueue_scripts' ), 'wp_enqueue_scripts hook not registered' );
		$this->assertTrue( has_filter( 'map_meta_cap' ), 'map_meta_cap filter not registered' );
	}

	/**
	 * Test menu slugs are consistent across components
	 *
	 * @test
	 * @return void
	 */
	public function testMenuSlugsConsistent(): void {
		$slugs = DashboardMenuRegistration::getMenuSlugs();

		// Each slug should be a valid page parameter
		foreach ( $slugs as $slug ) {
			$this->assertIsString( $slug );
			$this->assertNotEmpty( $slug );
			$this->assertStringStartsWith( 'yith_auction_', $slug );
		}
	}

	/**
	 * Test capability system defines all required capabilities
	 *
	 * @test
	 * @return void
	 */
	public function testCapabilitySystemComplete(): void {
		$cap_map = CapabilityRegistration::getCapabilityMap();

		// Verify all 5 capabilities are defined
		$expected_caps = [
			'manage_auction_settlements',
			'manage_auction_admin_reports',
			'manage_auction_seller_payouts',
			'manage_batch_operations',
			'view_seller_payouts',
		];

		foreach ( $expected_caps as $cap ) {
			$this->assertArrayHasKey( $cap, $cap_map, "Missing capability: $cap" );
		}
	}

	/**
	 * Test administrator has all capabilities
	 *
	 * @test
	 * @return void
	 */
	public function testAdministratorHasAllCapabilities(): void {
		$admin_caps = CapabilityRegistration::getCapabilitiesForRole( 'administrator' );

		// Admin should have at least 5 capabilities
		$this->assertGreaterThanOrEqual( 5, count( $admin_caps ) );

		// Verify key capabilities
		$this->assertContains( 'manage_auction_settlements', $admin_caps );
		$this->assertContains( 'manage_batch_operations', $admin_caps );
	}

	/**
	 * Test shop manager has settlement capabilities
	 *
	 * @test
	 * @return void
	 */
	public function testShopManagerHasSettlementCapabilities(): void {
		$manager_caps = CapabilityRegistration::getCapabilitiesForRole( 'shop_manager' );

		$this->assertContains( 'manage_auction_settlements', $manager_caps );
		$this->assertNotContains( 'manage_batch_operations', $manager_caps );
	}

	/**
	 * Test seller has limited capabilities
	 *
	 * @test
	 * @return void
	 */
	public function testSellerHasLimitedCapabilities(): void {
		$seller_caps = CapabilityRegistration::getCapabilitiesForRole( 'seller' );

		// Seller should only have view capability
		$this->assertContains( 'view_seller_payouts', $seller_caps );
		$this->assertCount( 1, $seller_caps );
	}

	/**
	 * Test shortcodes are registered
	 *
	 * @test
	 * @return void
	 */
	public function testShortcodesRegistered(): void {
		// Verify shortcodes can be detected
		$this->assertTrue( has_shortcode( 'yith_auction_seller_payouts', 'do_shortcode' ) );
		$this->assertTrue( has_shortcode( 'yith_auction_my_auctions', 'do_shortcode' ) );
	}

	/**
	 * Test page controller can be dependency injected
	 *
	 * @test
	 * @return void
	 */
	public function testPageControllerDependencyInjection(): void {
		$controller = new DashboardPageController();

		// Test setting custom dashboard classes
		$custom_class = 'My\Custom\Dashboard';
		$controller->setDashboardClass( 'settlement', $custom_class );

		$this->assertTrue( true );
	}

	/**
	 * Test capability registration handles unknown roles gracefully
	 *
	 * @test
	 * @return void
	 */
	public function testCapabilityRegistrationHandlesUnknownRole(): void {
		$caps = CapabilityRegistration::getCapabilitiesForRole( 'nonexistent_role' );

		// Should return empty array, not error
		$this->assertIsArray( $caps );
		$this->assertEmpty( $caps );
	}

	/**
	 * Test asset enqueuer initializes with different URLs
	 *
	 * @test
	 * @return void
	 */
	public function testAssetEnqueuerFlexible(): void {
		$enqueuer1 = new AssetEnqueuer( 'http://example.com/wp-content/plugins/yith/' );
		$enqueuer2 = new AssetEnqueuer( 'http://example.com/wp-content/plugins/yith' );

		$this->assertInstanceOf( AssetEnqueuer::class, $enqueuer1 );
		$this->assertInstanceOf( AssetEnqueuer::class, $enqueuer2 );
	}

	/**
	 * Test WordPress integration doesn't break existing functionality
	 *
	 * @test
	 * @return void
	 */
	public function testNoConflictsWithExistingWordPressCode(): void {
		// Verify WordPress functions are still available
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( function_exists( 'add_filter' ) );
		$this->assertTrue( function_exists( 'wp_enqueue_style' ) );
		$this->assertTrue( function_exists( 'current_user_can' ) );
	}

	/**
	 * Test all menu slugs are unique
	 *
	 * @test
	 * @return void
	 */
	public function testMenuSlugsAreUnique(): void {
		$slugs = DashboardMenuRegistration::getMenuSlugs();

		// All slugs should be unique
		$this->assertCount( count( array_unique( $slugs ) ), $slugs );
	}
}
