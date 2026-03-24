<?php
/**
 * Scheduler Runner
 *
 * Orchestrates the execution of the retry scheduler
 *
 * @package    WooCommerce Auction
 * @subpackage WpCron
 * @version    1.0.0
 * @requirement REQ-4D-041: Scheduler execution orchestration
 */

namespace WC\Auction\WpCron;

use WC\Auction\Services\SchedulerService;
use WC\Auction\Services\RetryQueueProcessor;


/**
 * SchedulerRunner - Orchestrates scheduler execution
 *
 * Responsibilities:
 * - Execute scheduler and process retries
 * - Track execution statistics
 * - Manage scheduler state (enable/disable)
 * - Report health status
 * - Handle batch processing configuration
 *
 * UML Class Diagram:
 * ```
 * SchedulerRunner (Execution Orchestrator)
 * ├── Properties:
 * │   ├── scheduler: SchedulerService
 * │   ├── queue_processor: RetryQueueProcessor
 * │   ├── is_running: bool
 * │   ├── enabled: bool
 * │   ├── stats: array
 * │   ├── last_run_timestamp: int|null
 * │   └── batch_size: int
 * ├── Public Methods:
 * │   ├── run(): array
 * │   ├── run(batch_size): array
 * │   ├── isRunning(): bool
 * │   ├── getLastRunTimestamp(): int|null
 * │   ├── getTotalProcessedCount(): int
 * │   ├── getTotalFailedCount(): int
 * │   ├── enable(): bool
 * │   ├── disable(): bool
 * │   ├── resetStatistics(): void
 * │   ├── getHealthStatus(): array
 * │   └── getStats(): array
 * └── Dependencies:
 *     ├── SchedulerService (process retries)
 *     └── RetryQueueProcessor (batch processing)
 * ```
 *
 * Design Patterns:
 * - Service Locator: Coordinates SchedulerService and RetryQueueProcessor
 * - State Machine: Manages enable/disable/running states
 * - Statistics Collector: Tracks execution metrics
 *
 * @requirement REQ-4D-041: Scheduler execution orchestration
 */
class SchedulerRunner {

	/**
	 * Default batch size
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Scheduler service
	 *
	 * @var SchedulerService
	 */
	private $scheduler;

	/**
	 * Queue processor service
	 *
	 * @var RetryQueueProcessor
	 */
	private $queue_processor;

	/**
	 * Is running flag
	 *
	 * @var bool
	 */
	private $is_running = false;

	/**
	 * Is enabled flag
	 *
	 * @var bool
	 */
	private $enabled = true;

	/**
	 * Execution statistics
	 *
	 * @var array
	 */
	private $stats = [
		'total_processed' => 0,
		'total_failed'    => 0,
		'total_skipped'   => 0,
	];

	/**
	 * Last run timestamp
	 *
	 * @var int|null
	 */
	private $last_run_timestamp = null;

	/**
	 * Batch size for processing
	 *
	 * @var int
	 */
	private $batch_size = self::DEFAULT_BATCH_SIZE;

	/**
	 * Constructor
	 *
	 * @param SchedulerService     $scheduler       Scheduler service
	 * @param RetryQueueProcessor  $queue_processor Queue processor service
	 * @requirement REQ-4D-041: Initialize scheduler runner
	 */
	public function __construct(
		SchedulerService $scheduler,
		RetryQueueProcessor $queue_processor
	) {
		$this->scheduler       = $scheduler;
		$this->queue_processor = $queue_processor;
	}

	/**
	 * Run scheduler with default batch size
	 *
	 * @return array Processing result [processed, failed, skipped]
	 * @requirement REQ-4D-041: Execute scheduler processing
	 */
	public function run(): array {
		return $this->runWithBatchSize( $this->batch_size );
	}

	/**
	 * Run scheduler with custom batch size
	 *
	 * @param int $batch_size Batch size for processing
	 * @return array Processing result [processed, failed, skipped]
	 * @requirement REQ-4D-041: Execute scheduler with custom batch size
	 */
	public function runWithBatchSize( int $batch_size ): array {
		// Check if enabled
		if ( ! $this->enabled ) {
			return [
				'processed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'reason'    => 'scheduler_disabled',
			];
		}

		// Set running flag
		$this->is_running = true;
		$this->last_run_timestamp = time();

		try {
			// Process queue with specified batch size
			$this->queue_processor->setBatchSize( $batch_size );
			$stats = $this->queue_processor->processQueue( $batch_size );

			// Update statistics
			$this->stats['total_processed'] += $stats['processed'] ?? 0;
			$this->stats['total_failed']    += $stats['failed'] ?? 0;
			$this->stats['total_skipped']   += $stats['skipped'] ?? 0;

			return [
				'processed' => $stats['processed'] ?? 0,
				'failed'    => $stats['failed'] ?? 0,
				'skipped'   => $stats['skipped'] ?? 0,
				'success'   => true,
			];

		} catch ( \Exception $e ) {
			$this->stats['total_failed']++;

			return [
				'processed' => 0,
				'failed'    => 1,
				'skipped'   => 0,
				'success'   => false,
				'error'     => $e->getMessage(),
			];

		} finally {
			// Always clear running flag
			$this->is_running = false;
		}
	}

	/**
	 * Check if scheduler is running
	 *
	 * @return bool True if running, false otherwise
	 */
	public function isRunning(): bool {
		return $this->is_running;
	}

	/**
	 * Get last run timestamp
	 *
	 * @return int|null Timestamp of last run, or null if never run
	 */
	public function getLastRunTimestamp(): ?int {
		return $this->last_run_timestamp;
	}

	/**
	 * Get total processed count
	 *
	 * @return int Total number of retries processed
	 */
	public function getTotalProcessedCount(): int {
		return $this->stats['total_processed'];
	}

	/**
	 * Get total failed count
	 *
	 * @return int Total number of failures
	 */
	public function getTotalFailedCount(): int {
		return $this->stats['total_failed'];
	}

	/**
	 * Enable scheduler
	 *
	 * @return bool True if enabled successfully
	 */
	public function enable(): bool {
		$this->enabled = true;
		return true;
	}

	/**
	 * Disable scheduler
	 *
	 * @return bool True if disabled successfully
	 */
	public function disable(): bool {
		$this->enabled = false;
		return true;
	}

	/**
	 * Reset statistics
	 *
	 * @return void
	 */
	public function resetStatistics(): void {
		$this->stats = [
			'total_processed' => 0,
			'total_failed'    => 0,
			'total_skipped'   => 0,
		];
		$this->last_run_timestamp = null;
	}

	/**
	 * Get health status
	 *
	 * @return array Health status with running state, last run, and next run
	 */
	public function getHealthStatus(): array {
		$next_run = null;
		if ( $this->last_run_timestamp ) {
			$next_run = $this->last_run_timestamp + 43200; // 12 hours
		}

		return [
			'running'        => $this->is_running,
			'enabled'        => $this->enabled,
			'last_run'       => $this->last_run_timestamp,
			'next_run'       => $next_run,
			'total_processed' => $this->stats['total_processed'],
			'total_failed'   => $this->stats['total_failed'],
		];
	}

	/**
	 * Get statistics
	 *
	 * @return array Current statistics
	 */
	public function getStats(): array {
		return $this->stats;
	}

	/**
	 * Set batch size
	 *
	 * @param int $batch_size Batch size to set
	 * @return void
	 */
	public function setBatchSize( int $batch_size ): void {
		$this->batch_size = $batch_size;
	}

	/**
	 * Get batch size
	 *
	 * @return int Current batch size
	 */
	public function getBatchSize(): int {
		return $this->batch_size;
	}
}
