<?php
/**
 * Batch Job Service - Manages batch operations for auctions system
 *
 * @package YITH_Auctions\Services
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-DASHBOARD-BATCH-OPS-001 - Batch operation management
 * @covers-requirement REQ-DASHBOARD-BATCH-OPS-001 - Job queue and execution
 */

namespace YITH_Auctions\Services\Dashboard;

use wpdb;

/**
 * Manages batch job execution and monitoring
 *
 * Handles:
 * - Bulk payout processing
 * - Bulk settlement operations
 * - Bulk dispute operations
 * - Custom batch jobs with scheduling
 *
 * Jobs can be queued, scheduled, monitored, and retried on failure.
 *
 * @since 1.0.0
 */
class BatchJobService {
	/**
	 * Job statuses
	 */
	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED = 'failed';

	/**
	 * Job types
	 */
	const TYPE_BULK_PAYOUT = 'bulk_payout';
	const TYPE_BULK_SETTLEMENT = 'bulk_settlement';
	const TYPE_BULK_DISPUTE = 'bulk_dispute';
	const TYPE_CUSTOM = 'custom';

	/**
	 * WordPress database instance
	 *
	 * @var wpdb
	 */
	private wpdb $db;

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
	 * Create and queue a new batch job
	 *
	 * @param string $type Job type.
	 * @param array  $parameters Job parameters.
	 * @param string $description Job description.
	 * @param string $scheduled_for Optional datetime to schedule job.
	 * @return int Job ID.
	 * @covers-requirement REQ-DASHBOARD-BATCH-OPS-001
	 * @since 1.0.0
	 */
	public function createJob(
		string $type,
		array $parameters,
		string $description = '',
		string $scheduled_for = ''
	): int {
		$data = [
			'job_type' => $type,
			'parameters' => wp_json_encode( $parameters ),
			'description' => $description,
			'status' => self::STATUS_PENDING,
			'total_items' => $parameters['total_items'] ?? 0,
			'processed_items' => 0,
			'failed_items' => 0,
			'logs' => '',
			'scheduled_for' => $scheduled_for ?: current_time( 'mysql' ),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		];

		$this->db->insert( "{$this->db->prefix}yith_auction_batch_jobs", $data );

		$job_id = $this->db->insert_id;

		// Log job creation
		$this->addLog( $job_id, "Job created: {$description}" );

		return $job_id;
	}

	/**
	 * Get job by ID
	 *
	 * @param int $job_id Job ID.
	 * @return object|null Job object or null if not found.
	 * @since 1.0.0
	 */
	public function getJob( int $job_id ): ?object {
		return $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->db->prefix}yith_auction_batch_jobs WHERE id = %d", $job_id )
		);
	}

	/**
	 * Get pending jobs for processing
	 *
	 * @param int $limit Number of jobs to retrieve.
	 * @return array Array of job objects.
	 * @since 1.0.0
	 */
	public function getPendingJobs( int $limit = 10 ): array {
		return (array) $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->db->prefix}yith_auction_batch_jobs 
				WHERE status = %s AND scheduled_for <= %s 
				ORDER BY created_at ASC 
				LIMIT %d",
				self::STATUS_PENDING,
				current_time( 'mysql' ),
				$limit
			)
		);
	}

	/**
	 * Get all jobs with optional filtering
	 *
	 * @param string|null $status Optional status filter.
	 * @param int         $limit Number of jobs to retrieve.
	 * @param int         $offset Pagination offset.
	 * @return array Array of job objects.
	 * @since 1.0.0
	 */
	public function getJobs( ?string $status = null, int $limit = 50, int $offset = 0 ): array {
		$where = '';

		if ( ! is_null( $status ) ) {
			$where = $this->db->prepare( 'WHERE status = %s', $status );
		}

		return (array) $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->db->prefix}yith_auction_batch_jobs {$where} 
				ORDER BY created_at DESC 
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Mark job as running
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 * @since 1.0.0
	 */
	public function markAsRunning( int $job_id ): void {
		$this->updateJob( $job_id, [ 'status' => self::STATUS_RUNNING ] );
		$this->addLog( $job_id, 'Job started processing' );
	}

	/**
	 * Update job progress
	 *
	 * @param int $job_id Job ID.
	 * @param int $processed_items Number of items processed.
	 * @param int $failed_items Number of items failed.
	 * @return void
	 * @since 1.0.0
	 */
	public function updateProgress( int $job_id, int $processed_items, int $failed_items = 0 ): void {
		$this->updateJob(
			$job_id,
			[
				'processed_items' => $processed_items,
				'failed_items' => $failed_items,
				'updated_at' => current_time( 'mysql' ),
			]
		);
	}

	/**
	 * Mark job as completed
	 *
	 * @param int    $job_id Job ID.
	 * @param string $summary Completion summary.
	 * @return void
	 * @since 1.0.0
	 */
	public function markAsCompleted( int $job_id, string $summary = '' ): void {
		$this->updateJob(
			$job_id,
			[
				'status' => self::STATUS_COMPLETED,
				'completed_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);

		$this->addLog( $job_id, "Job completed. {$summary}" );
	}

	/**
	 * Mark job as failed
	 *
	 * @param int    $job_id Job ID.
	 * @param string $error_message Error message.
	 * @return void
	 * @since 1.0.0
	 */
	public function markAsFailed( int $job_id, string $error_message = '' ): void {
		$this->updateJob(
			$job_id,
			[
				'status' => self::STATUS_FAILED,
				'updated_at' => current_time( 'mysql' ),
			]
		);

		$this->addLog( $job_id, "Job failed: {$error_message}" );
	}

	/**
	 * Retry a failed job
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 * @since 1.0.0
	 */
	public function retryJob( int $job_id ): void {
		$this->updateJob(
			$job_id,
			[
				'status' => self::STATUS_PENDING,
				'processed_items' => 0,
				'failed_items' => 0,
				'updated_at' => current_time( 'mysql' ),
			]
		);

		$this->addLog( $job_id, 'Job retried after failure' );
	}

	/**
	 * Add log entry to job
	 *
	 * @param int    $job_id Job ID.
	 * @param string $message Log message.
	 * @return void
	 * @since 1.0.0
	 */
	public function addLog( int $job_id, string $message ): void {
		$timestamp = wp_date( 'Y-m-d H:i:s' );
		$log_entry = "[{$timestamp}] {$message}\n";

		$job = $this->getJob( $job_id );

		if ( ! $job ) {
			return;
		}

		$updated_logs = ( $job->logs ?? '' ) . $log_entry;

		$this->updateJob( $job_id, [ 'logs' => $updated_logs ] );
	}

	/**
	 * Get job progress percentage
	 *
	 * @param int $job_id Job ID.
	 * @return int Progress percentage (0-100).
	 * @since 1.0.0
	 */
	public function getProgress( int $job_id ): int {
		$job = $this->getJob( $job_id );

		if ( ! $job || 0 === (int) $job->total_items ) {
			return 0;
		}

		return (int) round( ( (int) $job->processed_items / (int) $job->total_items ) * 100 );
	}

	/**
	 * Get job statistics
	 *
	 * @return array Job statistics.
	 * @since 1.0.0
	 */
	public function getStatistics(): array {
		return [
			'pending' => (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$this->db->prefix}yith_auction_batch_jobs WHERE status = 'pending'"
			),
			'running' => (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$this->db->prefix}yith_auction_batch_jobs WHERE status = 'running'"
			),
			'completed' => (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$this->db->prefix}yith_auction_batch_jobs WHERE status = 'completed'"
			),
			'failed' => (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$this->db->prefix}yith_auction_batch_jobs WHERE status = 'failed'"
			),
			'total' => (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$this->db->prefix}yith_auction_batch_jobs"
			),
		];
	}

	/**
	 * Clean up old completed jobs (older than specified days)
	 *
	 * @param int $days_old Number of days before cleanup.
	 * @return int Number of jobs deleted.
	 * @since 1.0.0
	 */
	public function cleanupOldJobs( int $days_old = 30 ): int {
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_old} days" ) );

		return (int) $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->db->prefix}yith_auction_batch_jobs 
				WHERE status = 'completed' AND updated_at < %s",
				$cutoff_date
			)
		);
	}

	/**
	 * Delete job
	 *
	 * @param int $job_id Job ID.
	 * @return bool Success status.
	 * @since 1.0.0
	 */
	public function deleteJob( int $job_id ): bool {
		return false !== $this->db->delete(
			"{$this->db->prefix}yith_auction_batch_jobs",
			[ 'id' => $job_id ]
		);
	}

	// ========== Private Helper Methods ==========

	/**
	 * Update job data
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data Data to update.
	 * @return void
	 */
	private function updateJob( int $job_id, array $data ): void {
		$this->db->update(
			"{$this->db->prefix}yith_auction_batch_jobs",
			$data,
			[ 'id' => $job_id ]
		);
	}
}
