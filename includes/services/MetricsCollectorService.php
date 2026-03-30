<?php
/**
 * Real-time Metrics Collection Service
 *
 * @package YITH_Auctions\Services
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-4E-010
 */

namespace YITH_Auctions\Services;

use YITH_Auctions\Models\MetricsData;
use YITH_Auctions\Repositories\SellerPayoutRepository;
use YITH_Auctions\Repositories\SettlementBatchRepository;

/**
 * Service for collecting and caching real-time metrics
 *
 * Aggregates system metrics for dashboard display with intelligent caching
 * to minimize database queries. Invalidates cache on significant events
 * and provides fallback retrieval mechanisms.
 *
 * @requirement REQ-4E-010 - Real-time metrics with caching
 */
class MetricsCollectorService {
	/**
	 * Payout repository
	 *
	 * @var SellerPayoutRepository
	 */
	private SellerPayoutRepository $payout_repository;

	/**
	 * Batch repository
	 *
	 * @var SettlementBatchRepository
	 */
	private SettlementBatchRepository $batch_repository;

	/**
	 * Cache duration in seconds (5 minutes default)
	 *
	 * @var int
	 */
	private int $cache_duration = 300;

	/**
	 * cache key prefix
	 *
	 * @var string
	 */
	private string $cache_prefix = 'yith_auction_metrics_';

	/**
	 * Constructor
	 *
	 * @param SellerPayoutRepository    $payout_repository Payout data access.
	 * @param SettlementBatchRepository $batch_repository Batch data access.
	 * @since 1.0.0
	 */
	public function __construct(
		SellerPayoutRepository $payout_repository,
		SettlementBatchRepository $batch_repository
	) {
		$this->payout_repository = $payout_repository;
		$this->batch_repository = $batch_repository;
	}

	/**
	 * Collect all dashboard metrics
	 *
	 * @return array Collected metrics {
	 *   @type MetricsData $payouts_24h Payout count (24h)
	 *   @type MetricsData $amount_24h Total amount (24h)
	 *   @type MetricsData $success_rate Success rate percentage
	 *   @type MetricsData $active_batches Active batch count
	 *   @type MetricsData $pending_payouts Pending payout count
	 *   @type MetricsData $failed_payouts Failed payout count
	 * }
	 * @throws \Exception If collection fails.
	 * @requirement REQ-4E-010
	 * @since 1.0.0
	 */
	public function collectMetrics(): array {
		$metrics = [];

		// Payouts in last 24 hours
		$metrics['payouts_24h'] = $this->payout_repository->countLast24Hours();

		// Total amount in last 24 hours
		$metrics['amount_24h'] = $this->payout_repository->sumLast24Hours();

		// Success rate percentage
		$health = $this->payout_repository->getHealthMetrics( 24 );
		$metrics['success_rate'] = round( $health['success_rate'], 2 );

		// Active batches
		$metrics['active_batches'] = count( $this->batch_repository->findActive() );

		// Pending payouts
		$metrics['pending_payouts'] = $this->payout_repository->countPending();

		// Failed payouts (all time)
		$metrics['failed_payouts'] = $this->payout_repository->countFailed();

		return $metrics;
	}

	/**
	 * Collect and cache metrics
	 *
	 * @param bool $force_refresh Bypass cache and collect fresh data.
	 * @return array Metrics data.
	 * @throws \Exception If collection fails.
	 * @requirement REQ-4E-010
	 * @since 1.0.0
	 */
	public function collectAndCache( bool $force_refresh = false ): array {
		$cache_key = $this->cache_prefix . 'all';

		if ( ! $force_refresh ) {
			$cached = wp_cache_get( $cache_key );
			if ( $cached ) {
				return $cached;
			}
		}

		$metrics = $this->collectMetrics();
		wp_cache_set( $cache_key, $metrics, '', $this->cache_duration );

		return $metrics;
	}

	/**
	 * Get cached metrics
	 *
	 * @return array|null Cached metrics or null if not cached.
	 * @requirement REQ-4E-010
	 * @since 1.0.0
	 */
	public function getMetricsCache(): ?array {
		$cache_key = $this->cache_prefix . 'all';
		return wp_cache_get( $cache_key ) ?: null;
	}

	/**
	 * Invalidate metric cache
	 *
	 * Called after significant events (payout creation, batch completion, etc)
	 * to ensure fresh data on next retrieval.
	 *
	 * @requirement REQ-4E-010
	 * @since 1.0.0
	 */
	public function invalidateCache(): void {
		$cache_key = $this->cache_prefix . 'all';
		wp_cache_delete( $cache_key );
	}

	/**
	 * Collect seller-specific metrics
	 *
	 * @param int $seller_id Seller identifier.
	 * @return array Seller metrics {
	 *   @type int $payouts_pending Pending payout count
	 *   @type int $payouts_failed Failed payout count
	 *   @type int $total_amount Total payout amount
	 *   @type int $completed_amount Completed payout amount
	 * }
	 * @throws \Exception If collection fails.
	 * @requirement REQ-4E-010
	 * @since 1.0.0
	 */
	public function collectSellerMetrics( int $seller_id ): array {
		$cache_key = $this->cache_prefix . 'seller_' . $seller_id;
		$cached = wp_cache_get( $cache_key );

		if ( $cached ) {
			return $cached;
		}

		$stats = $this->payout_repository->getStatistics( $seller_id );

		$metrics = [
			'payouts_pending' => (int) $this->payout_repository->countBySeller(
				$seller_id,
				0,
				999,
				[ 'status' => 'pending' ]
			),
			'payouts_failed' => (int) $this->payout_repository->countBySeller(
				$seller_id,
				0,
				999,
				[ 'status' => [ 'failed', 'permanently_failed' ] ]
			),
			'total_amount' => (int) $stats['total_amount'],
			'completed_amount' => (int) $stats['completed_amount'],
		];

		wp_cache_set( $cache_key, $metrics, '', $this->cache_duration );

		return $metrics;
	}

	/**
	 * Aggregate metrics for time period
	 *
	 * @param int    $days Number of days to aggregate.
	 * @param string $group_by Grouping (hourly, daily).
	 * @return array Aggregated metrics by time period.
	 * @throws \Exception If aggregation fails.
	 * @requirement REQ-4E-010
	 * @since 1.0.0
	 */
	public function aggregateMetrics( int $days = 30, string $group_by = 'daily' ): array {
		$cache_key = $this->cache_prefix . 'aggregate_' . $days . '_' . $group_by;
		$cached = wp_cache_get( $cache_key );

		if ( $cached ) {
			return $cached;
		}

		$start_date = new \DateTime( "-{$days} days" );
		$end_date = new \DateTime();

		$aggregated_data = $this->payout_repository->getAggregatedMetrics(
			$start_date,
			$end_date,
			$group_by
		);

		wp_cache_set( $cache_key, $aggregated_data, '', $this->cache_duration * 2 );

		return $aggregated_data;
	}

	/**
	 * Set cache duration
	 *
	 * @param int $seconds Cache duration in seconds.
	 * @return self For method chaining.
	 * @since 1.0.0
	 */
	public function setCacheDuration( int $seconds ): self {
		$this->cache_duration = max( 60, $seconds );
		return $this;
	}
}
