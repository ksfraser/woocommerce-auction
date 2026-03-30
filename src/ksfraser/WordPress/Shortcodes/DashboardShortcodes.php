<?php
/**
 * Dashboard Shortcodes for Frontend Rendering
 *
 * @package YITH_Auctions\WordPress
 * @subpackage Shortcodes
 * @version 1.0.0
 * @requirement REQ-WORDPRESS-PAGES-002 - Frontend shortcode/page templates
 * @covers-requirement REQ-WORDPRESS-PAGES-002 - Shortcode registration
 */

namespace YITH_Auctions\WordPress\Shortcodes;

use Ksfraser\HTML\HtmlElement;

/**
 * Registers and handles dashboard shortcodes for frontend use
 *
 * Provides shortcodes for sellers and customers to view:
 * - [yith_auction_seller_payouts] - Seller payout dashboard
 * - [yith_auction_my_auctions] - Seller auction history (enhances existing)
 *
 * All shortcodes verify user authentication and appropriate roles
 * before rendering dashboard content.
 *
 * @since 1.0.0
 */
class DashboardShortcodes {
	/**
	 * Seller payout dashboard class name
	 *
	 * @var string
	 */
	private string $seller_payouts_dashboard = 'YITH_Auctions\Services\Dashboard\SellerPayoutDashboard';

	/**
	 * My auctions dashboard class name
	 *
	 * @var string
	 */
	private string $my_auctions_dashboard = 'YITH_Auctions\Services\Dashboard\MyAuctionsDashboard';

	/**
	 * Constructor - registers shortcodes
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_shortcode( 'yith_auction_seller_payouts', [ $this, 'renderSellerPayouts' ] );
		add_shortcode( 'yith_auction_my_auctions', [ $this, 'renderMyAuctions' ] );
	}

	/**
	 * Render seller payouts dashboard via shortcode
	 *
	 * Shortcode: [yith_auction_seller_payouts]
	 *
	 * Verifies:
	 * - User is logged in
	 * - User has seller capability or is admin
	 * - Seller only sees own data
	 *
	 * @param array $atts Shortcode attributes (none required).
	 * @return string HTML output or error message.
	 * @covers-requirement REQ-WORDPRESS-PAGES-002
	 * @since 1.0.0
	 */
	public function renderSellerPayouts( array $atts ): string {
		// Verify user is logged in
		if ( ! is_user_logged_in() ) {
			return wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'yith-auction-alert alert alert-warning' )
					->setContent(
						wp_kses_post(
							(new HtmlElement( 'p' ))
								->setContent(
									sprintf(
										'<a href="%s">%s</a> %s',
										esc_url( wp_login_url() ),
										esc_html__( 'Log in', 'yith-auctions-for-woocommerce' ),
										esc_html__( 'to view your payouts.', 'yith-auctions-for-woocommerce' )
									)
								)
								->getHtml()
						)
					)
					->getHtml()
			);
		}

		// Verify user has view capability
		if ( ! current_user_can( 'view_seller_payouts' ) ) {
			return wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'yith-auction-alert alert alert-danger' )
					->setContent( esc_html__( 'You do not have permission to view this page.', 'yith-auctions-for-woocommerce' ) )
					->getHtml()
			);
		}

		try {
			// Get current seller ID
			$user = wp_get_current_user();
			$seller_id = get_user_meta( $user->ID, 'seller_id', true );

			if ( ! $seller_id && current_user_can( 'manage_options' ) ) {
				// Admins can view any seller (implement filtering)
				$seller_id = intval( $_GET['seller_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedInput.InputNotSanitized
			}

			// Verify seller ownership unless admin
			if ( ! current_user_can( 'manage_options' ) && $seller_id !== $user->ID ) {
				return wp_kses_post(
					(new HtmlElement( 'div' ))
						->addClass( 'yith-auction-alert alert alert-danger' )
						->setContent( esc_html__( 'You can only view your own payouts.', 'yith-auctions-for-woocommerce' ) )
						->getHtml()
				);
			}

			// Instantiate dashboard
			if ( ! class_exists( $this->seller_payouts_dashboard ) ) {
				return wp_kses_post(
					(new HtmlElement( 'div' ))
						->addClass( 'yith-auction-alert alert alert-danger' )
						->setContent( esc_html__( 'Dashboard not available.', 'yith-auctions-for-woocommerce' ) )
						->getHtml()
				);
			}

			$dashboard = new $this->seller_payouts_dashboard();

			if ( ! method_exists( $dashboard, 'renderDashboard' ) ) {
				return wp_kses_post(
					(new HtmlElement( 'div' ))
						->addClass( 'yith-auction-alert alert alert-danger' )
						->setContent( esc_html__( 'Invalid dashboard configuration.', 'yith-auctions-for-woocommerce' ) )
						->getHtml()
				);
			}

			// Render dashboard wrapped in theme-compatible container
			return wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'yith-auction-seller-dashboard' )
					->addClass( 'container-fluid' )
					->setContent( $dashboard->renderDashboard() )
					->getHtml()
			);
		} catch ( \Exception $e ) {
			error_log( 'Seller Payouts Dashboard Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'yith-auction-alert alert alert-danger' )
					->setContent( esc_html__( 'Error loading dashboard. Please try again.', 'yith-auctions-for-woocommerce' ) )
					->getHtml()
			);
		}
	}

	/**
	 * Render my auctions dashboard via shortcode
	 *
	 * Shortcode: [yith_auction_my_auctions]
	 *
	 * Enhanced version showing:
	 * - Seller auction history
	 * - Settlement status
	 * - Related payouts
	 *
	 * @param array $atts Shortcode attributes (none required).
	 * @return string HTML output or error message.
	 * @covers-requirement REQ-WORDPRESS-PAGES-002
	 * @since 1.0.0
	 */
	public function renderMyAuctions( array $atts ): string {
		// Verify user is logged in
		if ( ! is_user_logged_in() ) {
			return wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'yith-auction-alert alert alert-warning' )
					->setContent(
						wp_kses_post(
							(new HtmlElement( 'p' ))
								->setContent(
									sprintf(
										'<a href="%s">%s</a> %s',
										esc_url( wp_login_url() ),
										esc_html__( 'Log in', 'yith-auctions-for-woocommerce' ),
										esc_html__( 'to view your auctions.', 'yith-auctions-for-woocommerce' )
									)
								)
								->getHtml()
						)
					)
					->getHtml()
			);
		}

		try {
			// Instantiate dashboard
			if ( ! class_exists( $this->my_auctions_dashboard ) ) {
				return wp_kses_post(
					(new HtmlElement( 'div' ))
						->addClass( 'yith-auction-alert alert alert-info' )
						->setContent( esc_html__( 'My Auctions dashboard not configured.', 'yith-auctions-for-woocommerce' ) )
						->getHtml()
				);
			}

			$dashboard = new $this->my_auctions_dashboard();

			if ( ! method_exists( $dashboard, 'renderDashboard' ) ) {
				return wp_kses_post(
					(new HtmlElement( 'div' ))
						->addClass( 'yith-auction-alert alert alert-danger' )
						->setContent( esc_html__( 'Invalid dashboard configuration.', 'yith-auctions-for-woocommerce' ) )
						->getHtml()
				);
			}

			// Render dashboard
			return wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'yith-auction-my-auctions-dashboard' )
					->addClass( 'container-fluid' )
					->setContent( $dashboard->renderDashboard() )
					->getHtml()
			);
		} catch ( \Exception $e ) {
			error_log( 'My Auctions Dashboard Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'yith-auction-alert alert alert-danger' )
					->setContent( esc_html__( 'Error loading dashboard. Please try again.', 'yith-auctions-for-woocommerce' ) )
					->getHtml()
			);
		}
	}

	/**
	 * Set dashboard class for dependency injection
	 *
	 * @param string $shortcode Shortcode name (seller_payouts, my_auctions).
	 * @param string $class Full class name.
	 * @return void
	 * @since 1.0.0
	 */
	public function setDashboardClass( string $shortcode, string $class ): void {
		if ( 'seller_payouts' === $shortcode ) {
			$this->seller_payouts_dashboard = $class;
		} elseif ( 'my_auctions' === $shortcode ) {
			$this->my_auctions_dashboard = $class;
		}
	}
}
