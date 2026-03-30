<?php
/**
 * Tests for Capability Registration
 *
 * @package YITH_Auctions\Tests
 * @subpackage WordPress
 */

namespace YITH_Auctions\Tests\WordPress\Capabilities;

use YITH_Auctions\WordPress\Capabilities\CapabilityRegistration;
use PHPUnit\Framework\TestCase;

/**
 * Test CapabilityRegistration class
 *
 * @covers YITH_Auctions\WordPress\Capabilities\CapabilityRegistration
 */
class CapabilityRegistrationTest extends TestCase {
	/**
	 * Capability registration instance
	 *
	 * @var CapabilityRegistration
	 */
	private CapabilityRegistration $registration;

	/**
	 * Set up test
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registration = new CapabilityRegistration();
	}

	/**
	 * Test capability map is returned
	 *
	 * @test
	 * @return void
	 */
	public function testGetCapabilityMapReturnsArray(): void {
		$map = CapabilityRegistration::getCapabilityMap();

		$this->assertIsArray( $map );
		$this->assertNotEmpty( $map );
	}

	/**
	 * Test capability map contains expected keys
	 *
	 * @test
	 * @return void
	 */
	public function testCapabilityMapContainsCustomCapabilities(): void {
		$map = CapabilityRegistration::getCapabilityMap();

		$this->assertArrayHasKey( 'manage_auction_settlements', $map );
		$this->assertArrayHasKey( 'manage_auction_admin_reports', $map );
		$this->assertArrayHasKey( 'manage_auction_seller_payouts', $map );
		$this->assertArrayHasKey( 'manage_batch_operations', $map );
		$this->assertArrayHasKey( 'view_seller_payouts', $map );
	}

	/**
	 * Test capabilities are mapped to roles
	 *
	 * @test
	 * @return void
	 */
	public function testCapabilitiesAreMappedToRoles(): void {
		$map = CapabilityRegistration::getCapabilityMap();

		// Check manage_auction_settlements is assigned to administrator and shop_manager
		$this->assertContains( 'administrator', $map['manage_auction_settlements'] );
		$this->assertContains( 'shop_manager', $map['manage_auction_settlements'] );

		// Check manage_batch_operations is admin-only
		$this->assertContains( 'administrator', $map['manage_batch_operations'] );
		$this->assertCount( 1, $map['manage_batch_operations'] );

		// Check view_seller_payouts includes all roles
		$this->assertContains( 'administrator', $map['view_seller_payouts'] );
		$this->assertContains( 'shop_manager', $map['view_seller_payouts'] );
		$this->assertContains( 'seller', $map['view_seller_payouts'] );
	}

	/**
	 * Test getCapabilitiesForRole returns correct capabilities
	 *
	 * @test
	 * @return void
	 */
	public function testGetCapabilitiesForAdministratorRole(): void {
		$caps = CapabilityRegistration::getCapabilitiesForRole( 'administrator' );

		$this->assertIsArray( $caps );
		$this->assertNotEmpty( $caps );
		$this->assertContains( 'manage_auction_settlements', $caps );
		$this->assertContains( 'manage_batch_operations', $caps );
	}

	/**
	 * Test getCapabilitiesForRole returns subset for shop_manager
	 *
	 * @test
	 * @return void
	 */
	public function testGetCapabilitiesForShopManagerRole(): void {
		$caps = CapabilityRegistration::getCapabilitiesForRole( 'shop_manager' );

		$this->assertIsArray( $caps );
		$this->assertContains( 'manage_auction_settlements', $caps );
		$this->assertNotContains( 'manage_batch_operations', $caps );
	}

	/**
	 * Test getCapabilitiesForRole returns limited capabilities for seller
	 *
	 * @test
	 * @return void
	 */
	public function testGetCapabilitiesForSellerRole(): void {
		$caps = CapabilityRegistration::getCapabilitiesForRole( 'seller' );

		$this->assertIsArray( $caps );
		$this->assertContains( 'view_seller_payouts', $caps );
		$this->assertNotContains( 'manage_auction_settlements', $caps );
		$this->assertNotContains( 'manage_batch_operations', $caps );
	}

	/**
	 * Test isCustomCapability returns true for known capability
	 *
	 * @test
	 * @return void
	 */
	public function testIsCustomCapabilityReturnsTrueForKnownCap(): void {
		$this->assertTrue( CapabilityRegistration::isCustomCapability( 'manage_auction_settlements' ) );
		$this->assertTrue( CapabilityRegistration::isCustomCapability( 'view_seller_payouts' ) );
	}

	/**
	 * Test isCustomCapability returns false for unknown capability
	 *
	 * @test
	 * @return void
	 */
	public function testIsCustomCapabilityReturnsFalseForUnknownCap(): void {
		$this->assertFalse( CapabilityRegistration::isCustomCapability( 'unknown_capability' ) );
		$this->assertFalse( CapabilityRegistration::isCustomCapability( 'manage_options' ) );
	}

	/**
	 * Test constructor registers hooks
	 *
	 * @test
	 * @return void
	 */
	public function testConstructorRegistersHooks(): void {
		// Verify admin_init hook is registered
		$this->assertTrue( has_action( 'admin_init' ) );
		// Verify map_meta_cap filter is registered
		$this->assertTrue( has_filter( 'map_meta_cap' ) );
	}
}
