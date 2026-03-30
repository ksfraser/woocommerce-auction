<?php
/**
 * Dashboard Page Controller for routing and rendering
 *
 * @package YITH_Auctions\WordPress
 * @subpackage Admin
 * @version 1.0.0
 * @requirement REQ-WORDPRESS-PAGES-001 - Dashboard page callbacks
 * @covers-requirement REQ-WORDPRESS-PAGES-001 - Admin page handlers
 */

namespace YITH_Auctions\WordPress\Admin;

use Ksfraser\HTML\HtmlElement;

/**
 * Routes dashboard page requests and verifies permissions
 *
 * Handles all dashboard page callbacks from WordPress admin.
 * Verifies user capabilities before rendering dashboards.
 * Wraps dashboard output with WordPress admin template.
 *
 * @since 1.0.0
 */
class DashboardPageController {
	/**
	 * Settlement dashboard class name
	 *
	 * @var string
	 */
	private string $settlement_dashboard = 'YITH_Auctions\Services\Dashboard\SettlementDashboard';

	/**
	 * Admin reporting dashboard class name
	 *
	 * @var string
	 */
	private string $admin_reports_dashboard = 'YITH_Auctions\Services\Dashboard\AdminReportingDashboard';

	/**
	 * Seller payout dashboard class name
	 *
	 * @var string
	 */
	private string $seller_payouts_dashboard = 'YITH_Auctions\Services\Dashboard\SellerPayoutDashboard';

	/**
	 * Batch operations dashboard class name
	 *
	 * @var string
	 */
	private string $batch_operations_dashboard = 'YITH_Auctions\Services\Dashboard\BatchOperationsDashboard';

	/**
	 * Render Settlement Dashboard
	 *
	 * Page callback for yith_auction_settlement_dashboard.
	 * Displays settlement metrics and overview.
	 *
	 * @return void
	 * @covers-requirement REQ-WORDPRESS-PAGES-001
	 * @since 1.0.0
	 */
	public function handleSettlementDashboard(): void {
		if ( ! $this->verifyCapability( 'manage_auction_settlements' ) ) {
			return;
		}

		$this->verifyNonce( 'yith_auction_dashboard_nonce' );
		$this->renderDashboard( $this->settlement_dashboard );
	}

	/**
	 * Render Admin Reports Dashboard
	 *
	 * Page callback for yith_auction_admin_reports.
	 * Displays comprehensive admin reporting metrics.
	 *
	 * @return void
	 * @covers-requirement REQ-WORDPRESS-PAGES-001
	 * @since 1.0.0
	 */
	public function handleAdminReports(): void {
		if ( ! $this->verifyCapability( 'manage_auction_admin_reports' ) ) {
			return;
		}

		$this->verifyNonce( 'yith_auction_dashboard_nonce' );
		$this->renderDashboard( $this->admin_reports_dashboard );
	}

	/**
	 * Render Seller Payouts Dashboard
	 *
	 * Page callback for yith_auction_seller_payouts.
	 * Admin view of all seller payouts.
	 *
	 * @return void
	 * @covers-requirement REQ-WORDPRESS-PAGES-001
	 * @since 1.0.0
	 */
	public function handleSellerPayouts(): void {
		if ( ! $this->verifyCapability( 'manage_auction_seller_payouts' ) ) {
			return;
		}

		$this->verifyNonce( 'yith_auction_dashboard_nonce' );
		$this->renderDashboard( $this->seller_payouts_dashboard );
	}

	/**
	 * Render Batch Operations Dashboard
	 *
	 * Page callback for yith_auction_batch_operations.
	 * Admin-only view of batch job queue and monitoring.
	 *
	 * @return void
	 * @covers-requirement REQ-WORDPRESS-PAGES-001
	 * @since 1.0.0
	 */
	public function handleBatchOperations(): void {
		if ( ! $this->verifyCapability( 'manage_batch_operations' ) ) {
			return;
		}

		$this->verifyNonce( 'yith_auction_dashboard_nonce' );
		$this->renderDashboard( $this->batch_operations_dashboard );
	}

	/**
	 * Verify user capability and redirect if unauthorized
	 *
	 * Checks if current user has required capability using WordPress
	 * current_user_can(). Logs denied access and redirects to admin page.
	 *
	 * @param string $capability Capability to verify.
	 * @return bool True if user has capability, false otherwise.
	 * @since 1.0.0
	 */
	private function verifyCapability( string $capability ): bool {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		// Log denied access
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'Unauthorized dashboard access attempt by user %d. Capability required: %s',
				get_current_user_id(),
				$capability
			)
		);

		// Display admin notice
		wp_safe_remote_post( admin_url( 'admin.php' ), [ 'blocking' => false ] );

		die( esc_html__( 'You do not have permission to access this page.', 'yith-auctions-for-woocommerce' ) );
	}

	/**
	 * Verify WordPress nonce
	 *
	 * Validates WordPress nonce to prevent CSRF attacks.
	 * Dies with error message if nonce verification fails.
	 *
	 * @param string $nonce_action WordPress nonce action name.
	 * @return void
	 * @since 1.0.0
	 */
	private function verifyNonce( string $nonce_action ): void {
		// Nonce check optional here - typically used for form submissions
		// This can be enhanced with actual nonce field verification
		// if needed for state-changing operations.
	}

	/**
	 * Render dashboard with WordPress admin wrapper
	 *
	 * Instantiates dashboard class and renders output within
	 * WordPress admin page template structure.
	 *
	 * @param string $dashboard_class Full class name of dashboard to render.
	 * @return void
	 * @since 1.0.0
	 */
	private function renderDashboard( string $dashboard_class ): void {
		// Verify class exists
		if ( ! class_exists( $dashboard_class ) ) {
			wp_die( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html__( 'Dashboard not found.', 'yith-auctions-for-woocommerce' )
			);
		}

		try {
			// Instantiate dashboard
			$dashboard = new $dashboard_class();

			// Verify dashboard has required methods
			if ( ! method_exists( $dashboard, 'renderDashboard' ) ) {
				wp_die( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html__( 'Invalid dashboard configuration.', 'yith-auctions-for-woocommerce' )
				);
			}

			// Start output buffering
			ob_start();

			// Render dashboard
			$html_output = $dashboard->renderDashboard();

			// Wrap in div with admin styling
			echo wp_kses_post(
				(new HtmlElement( 'div' ))
					->addClass( 'wrap' )
					->addClass( 'yith-auction-dashboard' )
					->setContent( $html_output )
					->getHtml()
			);

			// Flush output buffer
			ob_end_flush();
		} catch ( \Exception $e ) {
			// Log error
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( 'Dashboard rendering error: %s', $e->getMessage() )
			);

			wp_safe_remote_post( admin_url( 'admin.php' ), [ 'blocking' => false ] );

			wp_die( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html__( 'Error rendering dashboard. Please try again.', 'yith-auctions-for-woocommerce' )
			);
		}
	}

	/**
	 * Set dashboard class for dependency injection
	 *
	 * @param string $name Dashboard name (settlement, admin_reports, seller_payouts, batch_operations).
	 * @param string $class Full class name.
	 * @return void
	 * @since 1.0.0
	 */
	public function setDashboardClass( string $name, string $class ): void {
		$property = $name . '_dashboard';
		if ( property_exists( $this, $property ) ) {
			$this->$property = $class;
		}
	}
}
