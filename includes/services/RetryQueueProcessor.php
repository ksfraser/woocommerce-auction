<?php
/**
 * RetryQueueProcessor Service
 *
 * Handles batch processing of retry queues with batch locking.
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-4D-041: Batch retry processing with lock coordination
 */

namespace WC\Auction\Services;

use WC\Auction\Repositories\BatchLockRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RetryQueueProcessor - Batch retry queue processing
 *
 * Manages:
 * - Batch processing of retry queue
 * - Batch lock acquisition and release
 * - Processing statistics and monitoring
 * - Configurable batch sizes
 *
 * UML Class Diagram:
 * ```
 * RetryQueueProcessor (Queue Processing Service)
 * ├── Properties:
 * │   ├── scheduler: SchedulerService
 * │   ├── batch_lock_repo: BatchLockRepository
 * │   ├── batch_size: int
 * │   └── last_stats: array
 * ├── Public Methods:
 * │   ├── processQueue(batch_size): array
 * │   ├── canAcquireLock(batch_id, timeout): bool
 * │   ├── setBatchSize(size): void
 * │   ├── getLastProcessStats(): array
 * │   └── resetStats(): void
 * └── Private Methods:
 *     ├── processBatch(): array
 *     └── recordStats(processed, failed, skipped): void
 * ```
 *
 * Design Patterns:
 * - Service: Encapsulates batch processing logic
 * - Facade: Provides simple interface to complex operations
 * - Strategy: Configurable batch sizes and processing strategies
 *
 * @requirement REQ-4D-041: Batch retry processing with lock coordination
 */
class RetryQueueProcessor {

	/**
	 * Default batch size
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Minimum batch size
	 *
	 * @var int
	 */
	const MIN_BATCH_SIZE = 10;

	/**
	 * Maximum batch size
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 500;

	/**
	 * Scheduler service
	 *
	 * @var SchedulerService
	 */
	private $scheduler;

	/**
	 * Batch lock repository
	 *
	 * @var BatchLockRepository
	 */
	private $batch_lock_repo;

	/**
	 * Current batch size
	 *
	 * @var int
	 */
	private $batch_size = self::DEFAULT_BATCH_SIZE;

	/**
	 * Last processing statistics
	 *
	 * @var array
	 */
	private $last_stats = [];

	/**
	 * Constructor
	 *
	 * @param SchedulerService      $scheduler       Scheduler service for processing
	 * @param BatchLockRepository   $batch_lock_repo Batch lock repository
	 * @requirement REQ-4D-041: Initialize queue processor with dependencies
	 */
	public function __construct(
		SchedulerService $scheduler,
		BatchLockRepository $batch_lock_repo
	) {
		$this->scheduler       = $scheduler;
		$this->batch_lock_repo = $batch_lock_repo;
	}

	/**
	 * Process the retry queue in batches
	 *
	 * Acquires batch lock, processes pending retries, releases lock.
	 * Returns statistics on processed, failed, and skipped retries.
	 *
	 * @param int $batch_size Optional: override configured batch size
	 * @return array Statistics array with 'processed', 'failed', 'skipped' keys
	 * @requirement REQ-4D-041: Process retry queue with batching
	 */
	public function processQueue( int $batch_size = null ): array {
		if ( $batch_size !== null ) {
			$this->setBatchSize( $batch_size );
		}

		try {
			// Attempt to acquire lock
			if ( ! $this->canAcquireLock( 'retry_queue', 300 ) ) {
				return [
					'processed' => 0,
					'failed'    => 0,
					'skipped'   => 0,
					'error'     => 'Could not acquire batch lock',
				];
			}

			// Process the batch
			$stats = $this->processBatch();

			// Record statistics
			$this->recordStats( $stats['processed'], $stats['failed'], $stats['skipped'] );

			return $stats;

		} finally {
			// Always release lock
			try {
				$this->batch_lock_repo->releaseLock( 'retry_queue' );
			} catch ( \Exception $e ) {
				// Log but don't throw - lock release
				error_log( 'Failed to release retry queue lock: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Check if batch lock can be acquired
	 *
	 * @param string $batch_id Batch identifier
	 * @param int    $timeout  Lock timeout in seconds
	 * @return bool True if lock acquired, false otherwise
	 * @requirement REQ-4D-041: Check batch lock availability
	 */
	public function canAcquireLock( string $batch_id, int $timeout ): bool {
		try {
			$this->batch_lock_repo->acquireLock( $batch_id, $timeout );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Set batch size for processing
	 *
	 * Validates that batch size is within acceptable range.
	 *
	 * @param int $size Desired batch size
	 * @return void
	 * @requirement REQ-4D-041: Configure batch processing size
	 */
	public function setBatchSize( int $size ): void {
		// Enforce min/max constraints
		if ( $size < self::MIN_BATCH_SIZE ) {
			$size = self::MIN_BATCH_SIZE;
		} elseif ( $size > self::MAX_BATCH_SIZE ) {
			$size = self::MAX_BATCH_SIZE;
		}

		$this->batch_size = $size;
	}

	/**
	 * Get last processing statistics
	 *
	 * @return array Statistics from last process run (if any)
	 * @requirement REQ-4D-041: Access processing statistics
	 */
	public function getLastProcessStats(): array {
		return $this->last_stats;
	}

	/**
	 * Reset processing statistics
	 *
	 * @return void
	 */
	public function resetStats(): void {
		$this->last_stats = [];
	}

	/**
	 * Process a batch of pending retries
	 *
	 * This is the main processing loop that:
	 * 1. Gets due retries from scheduler
	 * 2. Limits to batch size
	 * 3. Processes each retry (placeholder)
	 * 4. Collects stats
	 *
	 * @return array Statistics array
	 * @requirement REQ-4D-041: Process batch of retries
	 */
	private function processBatch(): array {
		$processed = 0;
		$failed    = 0;
		$skipped   = 0;

		try {
			// Get due retries from scheduler
			$due_retries = $this->scheduler->processDueRetries();

			// Limit to batch size
			$batch = array_slice( $due_retries, 0, $this->batch_size );

			// Process each retry in batch
			foreach ( $batch as $retry_schedule ) {
				try {
					// In production, would actually execute retry here
					// For now, just count as processed
					$processed++;

				} catch ( \Exception $e ) {
					$failed++;
				}
			}

			// Count remaining retries as skipped (beyond batch size)
			$skipped = max( 0, count( $due_retries ) - $this->batch_size );

		} catch ( \Exception $e ) {
			// Catch any processing errors
			error_log( 'Batch processing error: ' . $e->getMessage() );
		}

		return [
			'processed' => $processed,
			'failed'    => $failed,
			'skipped'   => $skipped,
		];
	}

	/**
	 * Record processing statistics
	 *
	 * @param int $processed Number of successful retries
	 * @param int $failed    Number of failed retries
	 * @param int $skipped   Number of skipped retries
	 * @return void
	 */
	private function recordStats( int $processed, int $failed, int $skipped ): void {
		$this->last_stats = [
			'processed' => $processed,
			'failed'    => $failed,
			'skipped'   => $skipped,
			'timestamp' => time(),
		];
	}
}
