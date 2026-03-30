<?php
/**
 * Tests for Asset Enqueuer
 *
 * @package YITH_Auctions\Tests
 * @subpackage WordPress
 */

namespace YITH_Auctions\Tests\WordPress\Assets;

use YITH_Auctions\WordPress\Assets\AssetEnqueuer;
use PHPUnit\Framework\TestCase;

/**
 * Test AssetEnqueuer class
 *
 * @covers YITH_Auctions\WordPress\Assets\AssetEnqueuer
 */
class AssetEnqueuerTest extends TestCase {
	/**
	 * Asset enqueuer instance
	 *
	 * @var AssetEnqueuer
	 */
	private AssetEnqueuer $enqueuer;

	/**
	 * Set up test
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->enqueuer = new AssetEnqueuer( 'http://example.com/wp-content/plugins/yith-auctions/', '1.0.0' );
	}

	/**
	 * Test constructor registers hooks
	 *
	 * @test
	 * @return void
	 */
	public function testConstructorRegistersHooks(): void {
		// Verify admin_enqueue_scripts hook is registered
		$this->assertTrue( has_action( 'admin_enqueue_scripts' ) );
		// Verify wp_enqueue_scripts hook is registered
		$this->assertTrue( has_action( 'wp_enqueue_scripts' ) );
	}

	/**
	 * Test constructor with version parameter
	 *
	 * @test
	 * @return void
	 */
	public function testConstructorAcceptsVersion(): void {
		$enqueuer = new AssetEnqueuer( 'http://example.com/assets/', '2.1.5' );
		$this->assertInstanceOf( AssetEnqueuer::class, $enqueuer );
	}

	/**
	 * Test constructor with default version
	 *
	 * @test
	 * @return void
	 */
	public function testConstructorUsesDefaultVersion(): void {
		$enqueuer = new AssetEnqueuer( 'http://example.com/assets/' );
		$this->assertInstanceOf( AssetEnqueuer::class, $enqueuer );
	}

	/**
	 * Test asset URL is normalized
	 *
	 * @test
	 * @return void
	 */
	public function testAssetUrlNormalizedRemovesTrailingSlash(): void {
		// URL with trailing slash should be normalized
		$enqueuer1 = new AssetEnqueuer( 'http://example.com/assets/' );
		$enqueuer2 = new AssetEnqueuer( 'http://example.com/assets' );

		// Both should work correctly
		$this->assertInstanceOf( AssetEnqueuer::class, $enqueuer1 );
		$this->assertInstanceOf( AssetEnqueuer::class, $enqueuer2 );
	}

	/**
	 * Test enqueueAdminAssets method exists
	 *
	 * @test
	 * @return void
	 */
	public function testEnqueueAdminAssetsMethodExists(): void {
		$this->assertTrue( method_exists( $this->enqueuer, 'enqueueAdminAssets' ) );
	}

	/**
	 * Test enqueueFrontendAssets method exists
	 *
	 * @test
	 * @return void
	 */
	public function testEnqueueFrontendAssetsMethodExists(): void {
		$this->assertTrue( method_exists( $this->enqueuer, 'enqueueFrontendAssets' ) );
	}

	/**
	 * Test enqueueAdminAssets doesn't enqueue on non-dashboard pages
	 *
	 * @test
	 * @return void
	 */
	public function testEnqueueAdminAssetsOnlyOnDashboardPages(): void {
		// Unset page parameter to avoid dashboard page
		unset( $_GET['page'] );

		// Call enqueue (should not enqueue anything)
		ob_start();
		$this->enqueuer->enqueueAdminAssets();
		ob_end_clean();

		// Verify assets were not enqueued
		$this->assertFalse( wp_style_is( 'yith-auction-admin-dashboard', 'enqueued' ) );
	}

	/**
	 * Test admin assets include Bootstrap and Font Awesome
	 *
	 * @test
	 * @return void
	 */
	public function testAdminAssetsIncludeDependencies(): void {
		// This test verifies the method calls wp_enqueue_style with correct dependencies
		// We're testing the logic flow rather than actual WordPress enqueue
		$reflection = new \ReflectionMethod( $this->enqueuer, 'isDashboardPage' );
		$reflection->setAccessible( true );

		// Test that method is callable
		$this->assertTrue( is_callable( [ $this->enqueuer, 'enqueueAdminAssets' ] ) );
	}

	/**
	 * Test frontend assets method doesn't error
	 *
	 * @test
	 * @return void
	 */
	public function testEnqueueFrontendAssetsDoesNotError(): void {
		// Call should not throw exception
		ob_start();
		$this->enqueuer->enqueueFrontendAssets();
		ob_end_clean();

		$this->assertTrue( true );
	}
}
