<?php
/**
 * Settlement Batch Monitoring Service
 *
 * @package YITH_Auctions\Services
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-4E-002
 */

namespace YITH_Auctions\Services;

use YITH_Auctions\Models\BatchStatusData;
use YITH_Auctions\Models\SystemHealthData;
use YITH_Auctions\Models\AnomalyAlert;
use YITH_Auctions\Repositories\SettlementBatchRepository;
use YITH_Auctions\Repositories\SellerPayoutRepository;

/**
 * Service for monitoring settlement batch progress and system health
 *
 * Provides real-time batch status, health metrics, and anomaly detection
 * for admin and seller dashboards. Tracks completion rates, failure patterns,
 * and system performance indicators.
 *
 * @requirement REQ-4E-002 - Batch monitoring with status/progress tracking
 * @requirement REQ-4E-008 - System health monitoring and anomaly alerts
 */
class SettlementMonitorService {
	/**
	 * Settlement batch repository
	 *
	 * @var SettlementBatchRepository
	 */
	private SettlementBatchRepository $batch_repository;

	/**
	 * Payout repository
	 *
	 * @var SellerPayoutRepository
	 */
	private SellerPayoutRepository $payout_repository;

	/**
	 * Time window for health calculations (24 hours default)
	 *
	 * @var int
	 */
	private int $health_window_hours = 24;

	/**
	 * Constructor
	 *
	 * @param SettlementBatchRepository $batch_repository Batch data access.
	 * @param SellerPayoutRepository    $payout_repository Payout data access.
	 * @since 1.0.0
	 */
	public function __construct(
		SettlementBatchRepository $batch_repository,
		SellerPayoutRepository $payout_repository
	) {
		$this->batch_repository = $batch_repository;
		$this->payout_repository = $payout_repository;
	}

	/**
	 * Get batch status with progress
	 *
	 * @param int $batch_id Batch identifier.
	 * @return BatchStatusData|null Batch status data or null if not found.
	 * @throws \Exception If fetch fails.
	 * @requirement REQ-4E-002
	 * @since 1.0.0
	 */
	public function getBatchStatus( int $batch_id ): ?BatchStatusData {
		$batch = $this->batch_repository->findById( $batch_id );

		if ( ! $batch ) {
			return null;
		}

		$payout_stats = $this->payout_repository->getStatsByBatch( $batch_id );

		return new BatchStatusData(
			$batch_id,
			(int) $batch['seller_count'],
			(int) $payout_stats['total'],
			(int) $payout_stats['completed'],
			(int) $payout_stats['failed'],
			(int) $payout_stats['pending'],
			(string) $batch['status'],
			(int) $batch['total_amount'],
			new \DateTime( $batch['created_at'] ),
			isset( $batch['completed_at'] ) ? new \DateTime( $batch['completed_at'] ) : null
		);
	}

	/**
	 * Get all active batches
	 *
	 * @return BatchStatusData[] Active batches.
	 * @throws \Exception If fetch fails.
	 * @requirement REQ-4E-002
	 * @since 1.0.0
	 */
	public function getActiveBatches(): array {
		$batches = $this->batch_repository->findActive();

		$batch_data = [];
		foreach ( $batches as $batch ) {
			$payout_stats = $this->payout_repository->getStatsByBatch( $batch['id'] );

			$batch_data[] = new BatchStatusData(
				(int) $batch['id'],
				(int) $batch['seller_count'],
				(int) $payout_stats['total'],
				(int) $payout_stats['completed'],
				(int) $payout_stats['failed'],
				(int) $payout_stats['pending'],
				(string) $batch['status'],
				(int) $batch['total_amount'],
				new \DateTime( $batch['created_at'] ),
				isset( $batch['completed_at'] ) ? new \DateTime( $batch['completed_at'] ) : null
			);
		}

		return $batch_data;
	}

	/**
	 * Get batch history with pagination
	 *
	 * @param int $page Current page.
	 * @param int $per_page Items per page.
	 * @return array {
	 *   @type BatchStatusData[] $batches Batch records
	 *   @type int              $total Total count
	 *   @type int              $pages Total pages
	 * }
	 * @throws \Exception If fetch fails.
	 * @requirement REQ-4E-002
	 * @since 1.0.0
	 */
	public function getBatchHistory(
		int $page = 1,
		int $per_page = 10
	): array {
		$offset = ( $page - 1 ) * $per_page;

		$batches = $this->batch_repository->findAll( $offset, $per_page );
		$total = $this->batch_repository->countAll();

		$batch_data = [];
		foreach ( $batches as $batch ) {
			$payout_stats = $this->payout_repository->getStatsByBatch( $batch['id'] );

			$batch_data[] = new BatchStatusData(
				(int) $batch['id'],
				(int) $batch['seller_count'],
				(int) $payout_stats['total'],
				(int) $payout_stats['completed'],
				(int) $payout_stats['failed'],
				(int) $payout_stats['pending'],
				(string) $batch['status'],
				(int) $batch['total_amount'],
				new \DateTime( $batch['created_at'] ),
				isset( $batch['completed_at'] ) ? new \DateTime( $batch['completed_at'] ) : null
			);
		}

		return [
			'batches' => $batch_data,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Get system health metrics
	 *
	 * @return SystemHealthData System health status.
	 * @throws \Exception If calculation fails.
	 * @requirement REQ-4E-008
	 * @since 1.0.0
	 */
	public function getSystemHealth(): SystemHealthData {
		$health_stats = $this->payout_repository->getHealthMetrics( $this->health_window_hours );

		$active_batches = count( $this->batch_repository->findActive() );
		$pending_queue = $this->payout_repository->countPending();

		$last_completed = $this->batch_repository->getLastCompletedBatch();
		$last_batch_time = $last_completed ? new \DateTime( $last_completed['completed_at'] ) : new \DateTime();

		return new SystemHealthData(
			(float) $health_stats['success_rate'],
			(float) $health_stats['error_rate'],
			(int) $health_stats['avg_processing_time_ms'],
			(int) $health_stats['total_payouts_24h'],
			(int) $health_stats['total_amount_24h'],
			$active_batches,
			$pending_queue,
			$last_batch_time
		);
	}

	/**
	 * Detect anomalies in system behavior
	 *
	 * @return AnomalyAlert[] Detected anomalies.
	 * @throws \Exception If detection fails.
	 * @requirement REQ-4E-008
	 * @since 1.0.0
	 */
	public function detectAnomalies(): array {
		$alerts = [];
		$health = $this->getSystemHealth();

		// Check success rate
		if ( $health->success_rate < 90 ) {
			$alerts[] = new AnomalyAlert(
				'low_success_rate',
				sprintf( 'Success rate dropped to %.1f%%', $health->success_rate ),
				$health->success_rate < 80 ? 'critical' : 'warning',
				[ 'success_rate' => $health->success_rate ],
				new \DateTime()
			);
		}

		// Check high error rate
		if ( $health->error_rate > 5 ) {
			$alerts[] = new AnomalyAlert(
				'high_error_rate',
				sprintf( 'Error rate elevated to %.1f%%', $health->error_rate ),
				$health->error_rate > 10 ? 'critical' : 'warning',
				[ 'error_rate' => $health->error_rate ],
				new \DateTime()
			);
		}

		// Check processing time
		if ( $health->avg_processing_time_ms > 5000 ) {
			$alerts[] = new AnomalyAlert(
				'slow_processing',
				sprintf( 'Average processing time: %dms', $health->avg_processing_time_ms ),
				$health->avg_processing_time_ms > 10000 ? 'critical' : 'warning',
				[ 'avg_processing_time_ms' => $health->avg_processing_time_ms ],
				new \DateTime()
			);
		}

		// Check queue buildup
		if ( $health->pending_queue_size > 1000 ) {
			$alerts[] = new AnomalyAlert(
				'queue_buildup',
				sprintf( '%d payouts pending processing', $health->pending_queue_size ),
				$health->pending_queue_size > 5000 ? 'critical' : 'warning',
				[ 'pending_queue_size' => $health->pending_queue_size ],
				new \DateTime()
			);
		}

		return $alerts;
	}
}
