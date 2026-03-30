<?php
/**
 * Enhancement Methods for Dashboard Repositories
 *
 * @package YITH_Auctions\Repositories
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-4E-001 through REQ-4E-010
 *
 * This file contains mixin methods for repositories to support dashboard queries.
 * Add these methods to existing repository classes:
 * - SellerPayoutRepository
 * - SettlementBatchRepository
 * - CommissionRepository
 */

namespace YITH_Auctions\Repositories;

/**
 * Dashboard Methods for SellerPayoutRepository
 *
 * Add these methods to SellerPayoutRepository class:
 *
 * @example
 * ```php
 * public function getStatistics( int $seller_id ): array { ... }
 * public function countLast24Hours(): int { ... }
 * public function sumLast24Hours(): int { ... }
 * public function countPending(): int { ... }
 * public function countFailed(): int { ... }
 * public function getHealthMetrics( int $hours = 24 ): array { ... }
 * public function getStatsByBatch( int $batch_id ): array { ... }
 * public function getReportData( \DateTime $start, \DateTime $end, string $type, ?int $seller_id = null ): array { ... }
 * public function getAggregatedMetrics( \DateTime $start, \DateTime $end, string $group_by = 'daily' ): array { ... }
 * ```
 *
 * @requirement REQ-4E-001 through REQ-4E-010
 */
trait SellerPayoutDashboardMethods {
	/**
	 * Get aggregated statistics for seller payouts
	 *
	 * @param int $seller_id Seller identifier.
	 * @return array {
	 *   @type int $total_payouts Total payouts
	 *   @type int $total_amount Total amount
	 *   @type int $completed_amount Completed amount
	 *   @type int $pending_amount Pending amount
	 *   @type int $failed_count Failed count
	 *   @type float $success_rate Success rate percentage
	 *   @type int $avg_amount Average amount
	 *   @type int $min_amount Minimum amount
	 *   @type int $max_amount Maximum amount
	 * }
	 * @requirement REQ-4E-001
	 */
	public function getStatistics( int $seller_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		$results = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_payouts,
					SUM(gross_amount) as total_amount,
					SUM(CASE WHEN status='completed' THEN net_amount ELSE 0 END) as completed_amount,
					SUM(CASE WHEN status='pending' THEN net_amount ELSE 0 END) as pending_amount,
					COUNT(CASE WHEN status IN ('failed', 'permanently_failed') THEN 1 END) as failed_count,
					ROUND((COUNT(CASE WHEN status='completed' THEN 1 END) / COUNT(*)) * 100, 2) as success_rate,
					ROUND(AVG(net_amount), 0) as avg_amount,
					MIN(net_amount) as min_amount,
					MAX(net_amount) as max_amount
				FROM {$table}
				WHERE seller_id = %d",
				$seller_id
			),
			ARRAY_A
		);

		return array_map( 'intval', $results ?? [] );
	}

	/**
	 * Count payouts from last 24 hours
	 *
	 * @return int Payout count.
	 * @requirement REQ-4E-010
	 */
	public function countLast24Hours(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		$result = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);

		return $result;
	}

	/**
	 * Sum payout amounts from last 24 hours
	 *
	 * @return int Total payout amount in cents.
	 * @requirement REQ-4E-010
	 */
	public function sumLast24Hours(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		$result = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(net_amount), 0) FROM {$table}
			WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
			AND status = 'completed'"
		);

		return $result;
	}

	/**
	 * Count pending payouts
	 *
	 * @return int Pending payout count.
	 * @requirement REQ-4E-001
	 */
	public function countPending(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"
		);
	}

	/**
	 * Count failed payouts
	 *
	 * @return int Failed payout count.
	 * @requirement REQ-4E-007
	 */
	public function countFailed(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			WHERE status IN ('failed', 'permanently_failed')"
		);
	}

	/**
	 * Get payment processing health metrics
	 *
	 * @param int $hours Time window in hours.
	 * @return array Health metrics {
	 *   @type float $success_rate Success rate percentage
	 *   @type float $error_rate Error rate percentage
	 *   @type int $avg_processing_time_ms Average processing time in ms
	 *   @type int $total_payouts_24h Payouts processed (24h)
	 *   @type int $total_amount_24h Total amount (24h)
	 * }
	 * @requirement REQ-4E-008
	 */
	public function getHealthMetrics( int $hours = 24 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		$metrics = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(ROUND((COUNT(CASE WHEN status='completed' THEN 1 END) / COUNT(*)) * 100, 2), 0) as success_rate,
					COALESCE(ROUND((COUNT(CASE WHEN status IN ('failed', 'permanently_failed') THEN 1 END) / COUNT(*)) * 100, 2), 0) as error_rate,
					COALESCE(ROUND(AVG(
						CASE WHEN completed_at IS NOT NULL 
						THEN TIMESTAMPDIFF(MILLISECOND, created_at, completed_at)
						ELSE NULL END
					)), 0) as avg_processing_time_ms,
					COUNT(*) as total_payouts_24h,
					COALESCE(SUM(net_amount), 0) as total_amount_24h
				FROM {$table}
				WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
				$hours
			),
			ARRAY_A
		);

		return array_map( 'floatval', $metrics ?? [] );
	}

	/**
	 * Get payout statistics by batch
	 *
	 * @param int $batch_id Batch identifier.
	 * @return array Statistics {
	 *   @type int $total Total payouts
	 *   @type int $completed Completed count
	 *   @type int $failed Failed count
	 *   @type int $pending Pending count
	 * }
	 * @requirement REQ-4E-002
	 */
	public function getStatsByBatch( int $batch_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					COUNT(CASE WHEN status='completed' THEN 1 END) as completed,
					COUNT(CASE WHEN status IN ('failed', 'permanently_failed') THEN 1 END) as failed,
					COUNT(CASE WHEN status='pending' THEN 1 END) as pending
				FROM {$table}
				WHERE batch_id = %d",
				$batch_id
			),
			ARRAY_A
		);

		return array_map( 'intval', $result ?? [] );
	}

	/**
	 * Get report data for period
	 *
	 * @param \DateTime $start_date Start date.
	 * @param \DateTime $end_date End date.
	 * @param string    $type Report type (settlement, seller, commission).
	 * @param int|null  $seller_id Optional seller filter.
	 * @return array Report data.
	 * @requirement REQ-4E-005
	 */
	public function getReportData(
		\DateTime $start_date,
		\DateTime $end_date,
		string $type = 'settlement',
		?int $seller_id = null
	): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';
		$seller_filter = $seller_id ? $wpdb->prepare( 'AND seller_id = %d', $seller_id ) : '';

		$data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_payouts,
					SUM(gross_amount) as total_amount,
					SUM(commission_amount) as commissions,
					COUNT(CASE WHEN status='completed' THEN 1 END) as successful_payouts,
					COUNT(CASE WHEN status IN ('failed', 'permanently_failed') THEN 1 END) as failed_payouts,
					ROUND((COUNT(CASE WHEN status='completed' THEN 1 END) / COUNT(*)) * 100, 2) as success_rate
				FROM {$table}
				WHERE created_at BETWEEN %s AND %s {$seller_filter}",
				$start_date->format( 'Y-m-d' ),
				$end_date->format( 'Y-m-d' )
			),
			ARRAY_A
		);

		return array_map( 'floatval', $data ?? [] );
	}

	/**
	 * Get aggregated metrics for time period
	 *
	 * @param \DateTime $start_date Start date.
	 * @param \DateTime $end_date End date.
	 * @param string    $group_by Grouping (hourly, daily, weekly, monthly).
	 * @return array Aggregated data grouped by time period.
	 * @requirement REQ-4E-010
	 */
	public function getAggregatedMetrics(
		\DateTime $start_date,
		\DateTime $end_date,
		string $group_by = 'daily'
	): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_seller_payouts';

		$group_clause = match ( $group_by ) {
			'hourly' => 'DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00")',
			'weekly' => 'DATE_FORMAT(created_at, "%Y-W%w")',
			'monthly' => 'DATE_FORMAT(created_at, "%Y-%m")',
			default => 'DATE(created_at)',
		};

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					{$group_clause} as period,
					COUNT(*) as payouts,
					SUM(net_amount) as amount,
					COUNT(CASE WHEN status='completed' THEN 1 END) as completed,
					COUNT(CASE WHEN status IN ('failed', 'permanently_failed') THEN 1 END) as failed
				FROM {$table}
				WHERE created_at BETWEEN %s AND %s
				GROUP BY period
				ORDER BY period ASC",
				$start_date->format( 'Y-m-d' ),
				$end_date->format( 'Y-m-d' )
			),
			ARRAY_A
		);

		return $results ?? [];
	}
}

/**
 * Dashboard Methods for SettlementBatchRepository
 *
 * Add these methods to SettlementBatchRepository class:
 *
 * @requirement REQ-4E-002
 */
trait SettlementBatchDashboardMethods {
	/**
	 * Get last completed batch
	 *
	 * @return array|null Last completed batch or null.
	 * @requirement REQ-4E-002
	 */
	public function getLastCompletedBatch(): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_settlement_batches';

		return $wpdb->get_row(
			"SELECT * FROM {$table}
			WHERE status = 'completed'
			ORDER BY completed_at DESC
			LIMIT 1",
			ARRAY_A
		);
	}

	/**
	 * Find all active batches
	 *
	 * @return array Active batch records.
	 * @requirement REQ-4E-002
	 */
	public function findActive(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_settlement_batches';

		return $wpdb->get_results(
			"SELECT * FROM {$table}
			WHERE status IN ('pending', 'processing')
			ORDER BY created_at DESC",
			ARRAY_A
		) ?? [];
	}

	/**
	 * Find all batches with pagination
	 *
	 * @param int $offset Offset.
	 * @param int $limit Limit.
	 * @return array Batch records.
	 * @requirement REQ-4E-002
	 */
	public function findAll( int $offset = 0, int $limit = 10 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_settlement_batches';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		) ?? [];
	}

	/**
	 * Count all batches
	 *
	 * @return int Total batch count.
	 * @requirement REQ-4E-002
	 */
	public function countAll(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_settlement_batches';

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}

/**
 * Dashboard Methods for CommissionRepository
 *
 * Add these methods to CommissionRepository class:
 *
 * @requirement REQ-4E-005
 */
trait CommissionDashboardMethods {
	/**
	 * Get commission report data
	 *
	 * @param \DateTime $start_date Start date.
	 * @param \DateTime $end_date End date.
	 * @return array Commission report data.
	 * @requirement REQ-4E-005
	 */
	public function getReportData( \DateTime $start_date, \DateTime $end_date ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'yith_auction_commissions';

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_commissions,
					SUM(commission_amount) as total_commission_amount,
					COUNT(CASE WHEN status='processed' THEN 1 END) as processed_count,
					COUNT(CASE WHEN status='failed' THEN 1 END) as failed_count,
					ROUND((COUNT(CASE WHEN status='processed' THEN 1 END) / COUNT(*)) * 100, 2) as success_rate
				FROM {$table}
				WHERE created_at BETWEEN %s AND %s",
				$start_date->format( 'Y-m-d' ),
				$end_date->format( 'Y-m-d' )
			),
			ARRAY_A
		);

		return array_map( 'intval', $result ?? [] );
	}
}
