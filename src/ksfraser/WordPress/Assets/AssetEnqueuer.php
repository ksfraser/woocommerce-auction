<?php
/**
 * Asset Enqueuer for Dashboard CSS/JS
 *
 * @package YITH_Auctions\WordPress
 * @subpackage Assets
 * @version 1.0.0
 * @requirement REQ-WORDPRESS-THEME-001-002 - Theming and styling
 * @covers-requirement REQ-WORDPRESS-THEME-001 - Admin dashboard styling
 * @covers-requirement REQ-WORDPRESS-THEME-002 - Frontend seller dashboard styling
 */

namespace YITH_Auctions\WordPress\Assets;

/**
 * Manages enqueuing of CSS and JavaScript assets for dashboards
 *
 * Enqueues admin and frontend assets appropriately based on context.
 * Supports:
 * - Admin dashboard styling with WordPress color schemes
 * - Frontend seller dashboard styling with theme compatibility
 * - Bootstrap CSS (if not already enqueued)
 * - Font Awesome icons (if not already enqueued)
 *
 * @since 1.0.0
 */
class AssetEnqueuer {
	/**
	 * Base URL for plugin assets
	 *
	 * @var string
	 */
	private string $asset_url;

	/**
	 * Plugin version for cache busting
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor
	 *
	 * @param string $asset_url Base URL for assets (e.g., plugin_dir_url()).
	 * @param string $version Plugin version.
	 * @since 1.0.0
	 */
	public function __construct( string $asset_url, string $version = '1.0.0' ) {
		$this->asset_url = rtrim( $asset_url, '/' );
		$this->version = $version;

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAdminAssets' ], 15 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueFrontendAssets' ], 15 );
	}

	/**
	 * Enqueue admin dashboard assets
	 *
	 * Called on admin_enqueue_scripts action. Only enqueues on
	 * dashboard pages to reduce admin page load.
	 *
	 * Enqueues:
	 * - admin.css - Dashboard specific styling
	 * - admin.js - Dashboard interactions (if needed)
	 *
	 * @return void
	 * @covers-requirement REQ-WORDPRESS-THEME-001
	 * @since 1.0.0
	 */
	public function enqueueAdminAssets(): void {
		// Only enqueue on dashboard pages
		if ( ! $this->isDashboardPage() ) {
			return;
		}

		// Enqueue Bootstrap CSS (low priority so theme can override)
		wp_enqueue_style(
			'bootstrap-css',
			$this->asset_url . '/assets/lib/bootstrap/css/bootstrap.min.css',
			[],
			$this->version,
			'all'
		);

		// Enqueue Font Awesome
		wp_enqueue_style(
			'font-awesome',
			$this->asset_url . '/assets/fonts/font-awesome/css/font-awesome.min.css',
			[],
			$this->version,
			'all'
		);

		// Enqueue admin dashboard CSS
		wp_enqueue_style(
			'yith-auction-admin-dashboard',
			$this->asset_url . '/assets/css/admin-dashboard.css',
			[ 'bootstrap-css', 'font-awesome' ],
			$this->version,
			'all'
		);

		// Enqueue admin dashboard JS
		wp_enqueue_script(
			'yith-auction-admin-dashboard',
			$this->asset_url . '/assets/js/admin-dashboard.js',
			[ 'jquery', 'wp-util' ],
			$this->version,
			true
		);

		// Localize script with data
		wp_localize_script(
			'yith-auction-admin-dashboard',
			'yithAuctionDashboard',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'yith_auction_dashboard_nonce' ),
				'isDashboard' => true,
			]
		);
	}

	/**
	 * Enqueue frontend seller dashboard assets
	 *
	 * Called on wp_enqueue_scripts. Enqueues on pages with
	 * dashboard shortcodes or seller account pages.
	 *
	 * Enqueues:
	 * - frontend.css - Seller dashboard styling
	 * - frontend.js - Seller interactions (if needed)
	 *
	 * @return void
	 * @covers-requirement REQ-WORDPRESS-THEME-002
	 * @since 1.0.0
	 */
	public function enqueueFrontendAssets(): void {
		// Check if on seller dashboard page
		if ( ! $this->isSellerDashboardPage() ) {
			return;
		}

		// Enqueue Bootstrap CSS if not already enqueued
		if ( ! wp_style_is( 'bootstrap', 'enqueued' ) ) {
			wp_enqueue_style(
				'bootstrap-css',
				$this->asset_url . '/assets/lib/bootstrap/css/bootstrap.min.css',
				[],
				$this->version,
				'all'
			);
		}

		// Enqueue Font Awesome if not already enqueued
		if ( ! wp_style_is( 'font-awesome', 'enqueued' ) ) {
			wp_enqueue_style(
				'font-awesome',
				$this->asset_url . '/assets/fonts/font-awesome/css/font-awesome.min.css',
				[],
				$this->version,
				'all'
			);
		}

		// Enqueue frontend seller dashboard CSS
		wp_enqueue_style(
			'yith-auction-frontend-dashboard',
			$this->asset_url . '/assets/css/frontend-dashboard.css',
			[ 'bootstrap-css', 'font-awesome' ],
			$this->version,
			'all'
		);

		// Enqueue frontend dashboard JS
		wp_enqueue_script(
			'yith-auction-frontend-dashboard',
			$this->asset_url . '/assets/js/frontend-dashboard.js',
			[ 'jquery' ],
			$this->version,
			true
		);
	}

	/**
	 * Check if current page is a dashboard page
	 *
	 * @return bool True if on admin dashboard page.
	 * @since 1.0.0
	 */
	private function isDashboardPage(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		$page = $_GET['page'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array(
			$page,
			[
				'yith_auction_settlement_dashboard',
				'yith_auction_admin_reports',
				'yith_auction_seller_payouts',
				'yith_auction_batch_operations',
			],
			true
		);
	}

	/**
	 * Check if current page is seller dashboard
	 *
	 * Detects:
	 * - WooCommerce my-account page with [yith_auction_seller_payouts] shortcode
	 * - Custom auction dashboard pages
	 *
	 * @return bool True if on seller dashboard page.
	 * @since 1.0.0
	 */
	private function isSellerDashboardPage(): bool {
		// Check for shortcodes on current page
		if ( is_singular() || is_page() ) {
			$page = get_queried_object();
			if ( $page && isset( $page->post_content ) ) {
				return (
					has_shortcode( $page->post_content, 'yith_auction_seller_payouts' ) ||
					has_shortcode( $page->post_content, 'yith_auction_my_auctions' )
				);
			}
		}

		// Check if on WooCommerce account page
		if ( is_page( 'myaccount' ) || apply_filters( 'woocommerce_is_account_page', false ) ) {
			return true;
		}

		return false;
	}
}
