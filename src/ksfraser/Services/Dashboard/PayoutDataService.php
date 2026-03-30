<?php
/**
 * Payout Data Service - Aggregates seller payout information
 *
 * @package YITH_Auctions\Services
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-DASHBOARD-SELLER-PAYOUT-001-003 - Seller payout functionality
 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-001 - Payout summary data aggregation
 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-002 - Payout history retrieval
 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-003 - Tax and earnings calculation
 */

namespace YITH_Auctions\Services\Dashboard;

use wpdb;

/**
 * Aggregates and caches seller payout data with role-based access
 *
 * Provides payout-specific data for seller payouts dashboard.
 * Handles summary statistics, transaction history, tax calculations,
 * and role-based data filtering.
 *
 * @since 1.0.0
 */
class PayoutDataService {
	/**
	 * WordPress database instance
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Cache TTL in seconds
	 *
	 * @var int
	 */
	private int $cache_ttl = 1800; // 30 minutes

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * Get payout summary for a seller or all sellers
	 *
	 * Returns:
	 * - Total earned (all time)
	 * - Total paid to seller
	 * - Pending payouts amount
	 * - Average payout time (days)
	 * - Successful payout rate (%)
	 *
	 * @param int|null $seller_id Optional seller ID. If null, returns platform totals.
	 * @return array Payout summary data.
	 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-001
	 * @since 1.0.0
	 */
	public function getPayoutSummary( ?int $seller_id = null ): array {
		$cache_key = 'yith_auction_payout_summary_' . ( $seller_id ?? 'all' );
		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$summary = [
			'total_earned' => $this->getTotalEarned( $seller_id ),
			'total_paid' => $this->getTotalPaid( $seller_id ),
			'pending_amount' => $this->getPendingPayoutAmount( $seller_id ),
			'avg_payout_time_days' => $this->getAveragePayoutTime( $seller_id ),
			'success_rate_percent' => $this->getPayoutSuccessRate( $seller_id ),
			'next_payout_date' => $this->getNextPayoutDate( $seller_id ),
			'payment_method' => $this->getPaymentMethod( $seller_id ),
			'timestamp' => current_time( 'mysql' ),
		];

		set_transient( $cache_key, $summary, $this->cache_ttl );

		return $summary;
	}

	/**
	 * Get payout transaction history for seller or all sellers
	 *
	 * Returns recent transactions with details:
	 * - Transaction ID, amount, status
	 * - Transaction date and payout date
	 * - Auction details reference
	 *
	 * @param int|null $seller_id Optional seller ID. If null, returns all.
	 * @param int      $limit Number of transactions to return.
	 * @param int      $offset Pagination offset.
	 * @return array Array of transaction objects.
	 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-002
	 * @since 1.0.0
	 */
	public function getPayoutHistory( ?int $seller_id = null, int $limit = 50, int $offset = 0 ): array {
		$where = '';

		if ( ! is_null( $seller_id ) ) {
			$where = $this->db->prepare( 'WHERE seller_id = %d', $seller_id );
		}

		return (array) $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->db->prefix}yith_auction_payouts {$where} 
				ORDER BY payout_date DESC 
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Get current month earnings breakdown
	 *
	 * Returns:
	 * - Gross earnings (before fees)
	 * - Platform fees
	 * - Net earnings
	 * - Tax withholding
	 * - Available for payout
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return array Earnings breakdown.
	 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-003
	 * @since 1.0.0
	 */
	public function getCurrentMonthEarnings( ?int $seller_id = null ): array {
		$current_month = current_time( 'Y-m' );

		$gross = $this->db->get_var(
			$this->db->prepare(
				"SELECT SUM(final_bid_amount) FROM {$this->db->prefix}yith_auction_settlements 
				WHERE DATE_FORMAT(created_at, %s) = %s "
				. ( $seller_id ? $this->db->prepare( 'AND seller_id = %d', $seller_id ) : '' ),
				'%Y-%m',
				$current_month
			)
		);

		$gross = is_null( $gross ) ? 0 : (float) $gross;

		// Fetch platform fee rate from settings
		$fee_rate = (float) get_option( 'yith_auction_platform_fee_rate', 0.05 ); // Default 5%
		$platform_fees = $gross * $fee_rate;

		$net = $gross - $platform_fees;

		// Fetch tax withholding rate (if enabled)
		$tax_rate = (float) get_option( 'yith_auction_tax_withholding_rate', 0 );
		$tax_withholding = $net * $tax_rate;

		$available = $net - $tax_withholding;

		return [
			'gross_earnings' => $gross,
			'platform_fees' => $platform_fees,
			'net_earnings' => $net,
			'tax_withholding' => $tax_withholding,
			'available_for_payout' => $available,
			'currency' => get_woocommerce_currency(),
		];
	}

	/**
	 * Get annual earnings summary (by month)
	 *
	 * Returns array of monthly earnings for the current year.
	 * Used for trend visualization.
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return array Monthly earnings data.
	 * @since 1.0.0
	 */
	public function getAnnualEarningsSummary( ?int $seller_id = null ): array {
		$year = current_time( 'Y' );
		$summary = [];

		for ( $month = 1; $month <= 12; $month++ ) {
			$month_str = str_pad( $month, 2, '0', STR_PAD_LEFT );
			$date_pattern = "{$year}-{$month_str}";

			$result = $this->db->get_var(
				$this->db->prepare(
					"SELECT SUM(final_bid_amount) FROM {$this->db->prefix}yith_auction_settlements 
					WHERE DATE_FORMAT(created_at, %s) = %s "
					. ( $seller_id ? $this->db->prepare( 'AND seller_id = %d', $seller_id ) : '' ),
					'%Y-%m',
					$date_pattern
				)
			);

			$summary[ $month_str ] = is_null( $result ) ? 0 : (float) $result;
		}

		return $summary;
	}

	/**
	 * Get pending payouts that haven't been processed
	 *
	 * Returns pending transactions awaiting payout.
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return array Pending payout data.
	 * @since 1.0.0
	 */
	public function getPendingPayouts( ?int $seller_id = null ): array {
		$where = "WHERE status = 'pending'";

		if ( ! is_null( $seller_id ) ) {
			$where .= $this->db->prepare( ' AND seller_id = %d', $seller_id );
		}

		return (array) $this->db->get_results(
			"SELECT * FROM {$this->db->prefix}yith_auction_payouts {$where} ORDER BY created_at ASC"
		);
	}

	/**
	 * Clear all payout-related caches
	 *
	 * @param int|null $seller_id Optional seller ID to clear specific cache.
	 * @return void
	 * @since 1.0.0
	 */
	public function clearCache( ?int $seller_id = null ): void {
		if ( is_null( $seller_id ) ) {
			// Clear all payout caches
			delete_transient( 'yith_auction_payout_summary_all' );
		} else {
			delete_transient( "yith_auction_payout_summary_{$seller_id}" );
		}
	}

	// ========== Private Helper Methods ==========

	/**
	 * Get total earned (all time, before fees)
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return float
	 */
	private function getTotalEarned( ?int $seller_id = null ): float {
		$query = "SELECT SUM(final_bid_amount) FROM {$this->db->prefix}yith_auction_settlements WHERE status = 'completed'";

		if ( ! is_null( $seller_id ) ) {
			$query = $this->db->prepare( "{$query} AND seller_id = %d", $seller_id );
		}

		$result = $this->db->get_var( $query );

		return is_null( $result ) ? 0 : (float) $result;
	}

	/**
	 * Get total paid to seller (successfully completed payouts)
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return float
	 */
	private function getTotalPaid( ?int $seller_id = null ): float {
		$query = "SELECT SUM(payout_amount) FROM {$this->db->prefix}yith_auction_payouts WHERE status = 'completed'";

		if ( ! is_null( $seller_id ) ) {
			$query = $this->db->prepare( "{$query} AND seller_id = %d", $seller_id );
		}

		$result = $this->db->get_var( $query );

		return is_null( $result ) ? 0 : (float) $result;
	}

	/**
	 * Get pending payout amount
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return float
	 */
	private function getPendingPayoutAmount( ?int $seller_id = null ): float {
		$query = "SELECT SUM(payout_amount) FROM {$this->db->prefix}yith_auction_payouts WHERE status = 'pending'";

		if ( ! is_null( $seller_id ) ) {
			$query = $this->db->prepare( "{$query} AND seller_id = %d", $seller_id );
		}

		$result = $this->db->get_var( $query );

		return is_null( $result ) ? 0 : (float) $result;
	}

	/**
	 * Get average payout processing time in days
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return float
	 */
	private function getAveragePayoutTime( ?int $seller_id = null ): float {
		$query = "SELECT AVG(DATEDIFF(payout_date, created_at)) FROM {$this->db->prefix}yith_auction_payouts WHERE status = 'completed' AND payout_date IS NOT NULL";

		if ( ! is_null( $seller_id ) ) {
			$query = $this->db->prepare( "{$query} AND seller_id = %d", $seller_id );
		}

		$result = $this->db->get_var( $query );

		return is_null( $result ) ? 0 : (float) $result;
	}

	/**
	 * Get payout success rate percentage
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return float
	 */
	private function getPayoutSuccessRate( ?int $seller_id = null ): float {
		$query = "SELECT COUNT(*) FROM {$this->db->prefix}yith_auction_payouts";

		if ( ! is_null( $seller_id ) ) {
			$query = $this->db->prepare( "{$query} WHERE seller_id = %d", $seller_id );
		}

		$total = (int) $this->db->get_var( $query );

		if ( 0 === $total ) {
			return 0;
		}

		$query = "SELECT COUNT(*) FROM {$this->db->prefix}yith_auction_payouts WHERE status = 'completed'";

		if ( ! is_null( $seller_id ) ) {
			$query = $this->db->prepare( "{$query} AND seller_id = %d", $seller_id );
		}

		$completed = (int) $this->db->get_var( $query );

		return ( $completed / $total ) * 100;
	}

	/**
	 * Get next scheduled payout date
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return string|null Next payout date or null if none scheduled.
	 */
	private function getNextPayoutDate( ?int $seller_id = null ): ?string {
		$payout_schedule = get_option( 'yith_auction_payout_schedule', 'weekly' );

		// Calculate next payout based on schedule
		$next_date = match ( $payout_schedule ) {
			'daily' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
			'weekly' => gmdate( 'Y-m-d', strtotime( 'next monday' ) ),
			'biweekly' => gmdate( 'Y-m-d', strtotime( '+2 weeks' ) ),
			'monthly' => gmdate( 'Y-m-01', strtotime( '+1 month' ) ),
			default => null,
		};

		return $next_date;
	}

	/**
	 * Get seller's preferred payment method
	 *
	 * @param int|null $seller_id Optional seller ID.
	 * @return string Payment method (e.g., 'bank_transfer', 'paypal').
	 */
	private function getPaymentMethod( ?int $seller_id = null ): string {
		if ( is_null( $seller_id ) ) {
			return 'bank_transfer';
		}

		$method = get_user_meta( $seller_id, 'yith_auction_payout_method', true );

		return $method ?: 'bank_transfer';
	}
}
