<?php
/**
 * Dashboard Menu Registration for WordPress Admin
 *
 * @package YITH_Auctions\WordPress
 * @subpackage Admin
 * @version 1.0.0
 * @requirement REQ-WORDPRESS-ADMIN-001-004 - Admin menu integration
 * @covers-requirement REQ-WORDPRESS-ADMIN-001 - Main menu registration
 * @covers-requirement REQ-WORDPRESS-ADMIN-002 - Admin Reports submenu
 * @covers-requirement REQ-WORDPRESS-ADMIN-003 - Seller Payouts submenu
 * @covers-requirement REQ-WORDPRESS-ADMIN-004 - Batch Operations submenu
 */

namespace YITH_Auctions\WordPress\Admin;

/**
 * Handles registration of dashboard menus in WordPress admin
 *
 * Registers main "YITH Auctions" menu and submenu items for:
 * - Settlement Dashboard
 * - Admin Reports
 * - Seller Payouts
 * - Batch Operations
 *
 * Each menu item verifies user capabilities before display.
 *
 * @since 1.0.0
 */
class DashboardMenuRegistration {
	/**
	 * Menu slug constants
	 */
	const MENU_SLUG_SETTLEMENT = 'yith_auction_settlement_dashboard';
	const MENU_SLUG_REPORTS = 'yith_auction_admin_reports';
	const MENU_SLUG_PAYOUTS = 'yith_auction_seller_payouts';
	const MENU_SLUG_BATCH_OPS = 'yith_auction_batch_operations';

	/**
	 * Dashboard page controller
	 *
	 * @var DashboardPageController
	 */
	private DashboardPageController $page_controller;

	/**
	 * Constructor - registers hooks
	 *
	 * @param DashboardPageController $controller Page routing controller.
	 * @since 1.0.0
	 */
	public function __construct( DashboardPageController $controller ) {
		$this->page_controller = $controller;
		add_action( 'admin_menu', [ $this, 'registerMenus' ], 10 );
	}

	/**
	 * Register main and submenu items
	 *
	 * Hooked to admin_menu action. Registers menus only if user
	 * has appropriate capabilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerMenus(): void {
		$this->registerMainMenu();
		$this->registerSettlementDashboard();
		$this->registerAdminReports();
		$this->registerSellerPayouts();
		$this->registerBatchOperations();
	}

	/**
	 * Register main "YITH Auctions" menu
	 *
	 * Creates top-level menu item positioned after WooCommerce menu.
	 * Only displays if user has manage_auction_settlements capability.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function registerMainMenu(): void {
		add_menu_page(
			esc_html__( 'YITH Auctions', 'yith-auctions-for-woocommerce' ),
			esc_html__( 'YITH Auctions', 'yith-auctions-for-woocommerce' ),
			'manage_auction_settlements',
			self::MENU_SLUG_SETTLEMENT,
			[ $this->page_controller, 'handleSettlementDashboard' ],
			'dashicons-gavel',
			58 // Position after WooCommerce (usually at 55)
		);
	}

	/**
	 * Register Settlement Dashboard submenu (also main page)
	 *
	 * This is the default page when user clicks main menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function registerSettlementDashboard(): void {
		add_submenu_page(
			self::MENU_SLUG_SETTLEMENT,
			esc_html__( 'Settlement Dashboard', 'yith-auctions-for-woocommerce' ),
			esc_html__( 'Settlement Dashboard', 'yith-auctions-for-woocommerce' ),
			'manage_auction_settlements',
			self::MENU_SLUG_SETTLEMENT,
			[ $this->page_controller, 'handleSettlementDashboard' ]
		);
	}

	/**
	 * Register Admin Reports submenu
	 *
	 * Restricted to managers/admins who can view admin reports.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function registerAdminReports(): void {
		add_submenu_page(
			self::MENU_SLUG_SETTLEMENT,
			esc_html__( 'Admin Reports', 'yith-auctions-for-woocommerce' ),
			esc_html__( 'Admin Reports', 'yith-auctions-for-woocommerce' ),
			'manage_auction_admin_reports',
			self::MENU_SLUG_REPORTS,
			[ $this->page_controller, 'handleAdminReports' ]
		);
	}

	/**
	 * Register Seller Payouts submenu
	 *
	 * Allows managers to view/manage seller payouts.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function registerSellerPayouts(): void {
		add_submenu_page(
			self::MENU_SLUG_SETTLEMENT,
			esc_html__( 'Seller Payouts', 'yith-auctions-for-woocommerce' ),
			esc_html__( 'Seller Payouts', 'yith-auctions-for-woocommerce' ),
			'manage_auction_seller_payouts',
			self::MENU_SLUG_PAYOUTS,
			[ $this->page_controller, 'handleSellerPayouts' ]
		);
	}

	/**
	 * Register Batch Operations submenu
	 *
	 * Admin-only access to batch job monitoring and controls.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function registerBatchOperations(): void {
		add_submenu_page(
			self::MENU_SLUG_SETTLEMENT,
			esc_html__( 'Batch Operations', 'yith-auctions-for-woocommerce' ),
			esc_html__( 'Batch Operations', 'yith-auctions-for-woocommerce' ),
			'manage_batch_operations',
			self::MENU_SLUG_BATCH_OPS,
			[ $this->page_controller, 'handleBatchOperations' ]
		);
	}

	/**
	 * Get list of all registered menu slugs
	 *
	 * Useful for enqueuing assets or filtering content.
	 *
	 * @return string[] Array of menu slugs.
	 * @since 1.0.0
	 */
	public static function getMenuSlugs(): array {
		return [
			self::MENU_SLUG_SETTLEMENT,
			self::MENU_SLUG_REPORTS,
			self::MENU_SLUG_PAYOUTS,
			self::MENU_SLUG_BATCH_OPS,
		];
	}

	/**
	 * Check if current page is a dashboard page
	 *
	 * @return bool True if on any dashboard page.
	 * @since 1.0.0
	 */
	public static function isDashboardPage(): bool {
		$page = $_GET['page'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $page, self::getMenuSlugs(), true );
	}
}
