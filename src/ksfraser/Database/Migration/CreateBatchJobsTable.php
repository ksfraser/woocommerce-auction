<?php
/**
 * Migration to create batch_jobs table.
 *
 * Creates the batch_jobs table for queue-based batch operation management.
 * Stores job metadata, progress tracking, and execution logs.
 *
 * @requirement REQ-DB-MIGRATIONS-001, REQ-DASHBOARD-BATCH-OPS-001
 * @package ksfraser\Database\Migration
 * @version 1.0.0
 */

namespace ksfraser\Database\Migration;

/**
 * CreateBatchJobsTable migration.
 *
 * Schema for batch_jobs table:
 * - id: Auto-incrementing primary key
 * - job_type: Type of job (bulk_payout, bulk_settlement, bulk_dispute, custom)
 * - parameters: JSON-encoded job parameters
 * - description: Human-readable job description
 * - status: Current job status (pending, running, completed, failed)
 * - total_items: Total items to process
 * - processed_items: Items successfully processed
 * - failed_items: Items that failed processing
 * - logs: Newline-delimited JSON log entries
 * - scheduled_for: Scheduled execution time (nullable)
 * - started_at: Actual execution start time (nullable)
 * - completed_at: Job completion time (nullable)
 * - created_at: Job creation timestamp
 * - updated_at: Last update timestamp
 *
 * Indexes optimized for common queries:
 * - status: Filter by job status
 * - job_type: Filter by job type
 * - scheduled_for: Find jobs to run
 * - created_at: Sort and filter by creation date
 *
 * @see \ksfraser\Services\Dashboard\BatchJobService
 */
class CreateBatchJobsTable extends DatabaseMigration {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'2026_03_30_140000_create_batch_jobs_table',
			'Create batch_jobs table for queue-based batch operations'
		);
	}

	/**
	 * Execute migration - create batch_jobs table.
	 *
	 * @throws \Exception If table creation fails.
	 * @return bool True if successful.
	 */
	public function up() {
		if ( $this->table_exists( 'batch_jobs' ) ) {
			return true; // Idempotent - table already exists
		}

		$charset_collate = $this->wpdb->get_charset_collate();
		$table_name      = $this->table_prefix . 'batch_jobs';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			job_type VARCHAR(50) NOT NULL,
			parameters JSON,
			description TEXT,
			status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
			total_items INT UNSIGNED DEFAULT 0,
			processed_items INT UNSIGNED DEFAULT 0,
			failed_items INT UNSIGNED DEFAULT 0,
			logs LONGTEXT,
			scheduled_for DATETIME,
			started_at DATETIME,
			completed_at DATETIME,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			KEY idx_status (status),
			KEY idx_job_type (job_type),
			KEY idx_scheduled_for (scheduled_for),
			KEY idx_created_at (created_at),
			KEY idx_status_created (status, created_at),
			KEY idx_job_type_status (job_type, status)
		) {$charset_collate}";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Verify table was created
		if ( ! $this->table_exists( 'batch_jobs' ) ) {
			throw new \Exception( 'Failed to create batch_jobs table' );
		}

		return true;
	}

	/**
	 * Rollback migration - drop batch_jobs table.
	 *
	 * WARNING: This will delete all batch job records and logs.
	 * Should only be used during development or in emergency situations.
	 *
	 * @throws \Exception If rollback fails.
	 * @return bool True if successful.
	 */
	public function down() {
		$table_name = $this->table_prefix . 'batch_jobs';

		$this->wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		if ( $this->table_exists( 'batch_jobs' ) ) {
			throw new \Exception( 'Failed to drop batch_jobs table' );
		}

		return true;
	}
}
