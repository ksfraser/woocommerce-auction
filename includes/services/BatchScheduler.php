<?php
/**
 * Batch Scheduler Service - Orchestrates payout batch processing via WP-Cron
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-041: Batch Scheduler coordinates WP-Cron with payout processing
 * @requirement REQ-4D-042: Batch Scheduler manages batch locking and concurrency
 */

namespace WC\Auction\Services;

use WC\Auction\Models\SettlementBatch;
use WC\Auction\Models\BatchProcessingResult;
use WC\Auction\Repositories\BatchLockRepository;
use WC\Auction\Repositories\SettlementBatchRepository;
use WC\Auction\Events\EventPublisher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BatchScheduler - Orchestrates batch payout processing via WP-Cron
 *
 * UML Class Diagram:
 * ```
 * BatchScheduler (Orchestrator)
 * ├── Dependencies:
 * │   ├── PayoutService
 * │   ├── BatchLockRepository
 * │   ├── SettlementBatchRepository
 * │   ├── SchedulerService
 * │   ├── EventPublisher
 * │   └── BatchSchedulerConfiguration
 * ├── Core Methods:
 * │   ├── scheduleDaily(time_string) : void
 * │   ├── scheduleWeekly(day, time_string) : void
 * │   ├── processScheduledBatch(batch_id) : BatchProcessingResult
 * │   ├── processNow(batch_id) : BatchProcessingResult
 * │   └── isBatchLocked(batch_id) : bool
 * └── Algorithm (processScheduledBatch):
 *     1. Check if batch is already locked by another process
 *     2. Acquire lock with timeout
 *     3. Load settlement batch from database
 *     4. Call PayoutService->processPayoutBatch()
 *     5. Update batch status to PROCESSED
 *     6. Publish completion event
 *     7. Release lock
 *     8. Return result
 * ```
 *
 * Responsibilities:
 * - Register WP-Cron hooks for daily/weekly batch scheduling
 * - Manage batch processing lock to prevent concurrent execution
 * - Coordinate with PayoutService for actual payout processing
 * - Publish events on batch completion
 * - Handle batch chunking for large datasets
 * - Provide AJAX endpoint for manual batch triggering
 *
 * Design Pattern: Orchestrator/Service
 * - Depends on abstractions (PayoutService interface)
 * - Uses dependency injection
 * - Single responsibility: batch coordination
 * - Event-driven: publishes completion events
 *
 * @requirement REQ-4D-041: Orchestrate WP-Cron batch processing
 * @requirement REQ-4D-042: Manage concurrency and locking
 * @requirement PERF-4D-002: Batch processing < 5 seconds per chunk
 */
class BatchScheduler {

    /**
     * Payout service for batch processing
     *
     * @var PayoutService
     */
    private $payout_service;

    /**
     * Batch lock repository
     *
     * @var BatchLockRepository
     */
    private $lock_repository;

    /**
     * Settlement batch repository
     *
     * @var SettlementBatchRepository
     */
    private $batch_repository;

    /**
     * Scheduler service for retry management
     *
     * @var SchedulerService
     */
    private $scheduler_service;

    /**
     * Event publisher
     *
     * @var EventPublisher
     */
    private $event_publisher;

    /**
     * Configuration manager
     *
     * @var BatchSchedulerConfiguration
     */
    private $config;

    /**
     * Lock timeout in seconds (default 5 minutes)
     *
     * @var int
     */
    const LOCK_TIMEOUT = 300;

    /**
     * Constructor
     *
     * @param PayoutService                 $payout_service Payout service
     * @param BatchLockRepository           $lock_repository Batch lock repository
     * @param SettlementBatchRepository     $batch_repository Settlement batch repository
     * @param SchedulerService              $scheduler_service Scheduler service for retries
     * @param EventPublisher                $event_publisher Event publisher
     * @param BatchSchedulerConfiguration   $config Configuration
     */
    public function __construct(
        PayoutService $payout_service,
        BatchLockRepository $lock_repository,
        SettlementBatchRepository $batch_repository,
        SchedulerService $scheduler_service,
        EventPublisher $event_publisher,
        BatchSchedulerConfiguration $config
    ) {
        $this->payout_service      = $payout_service;
        $this->lock_repository     = $lock_repository;
        $this->batch_repository    = $batch_repository;
        $this->scheduler_service   = $scheduler_service;
        $this->event_publisher     = $event_publisher;
        $this->config              = $config;
    }

    /**
     * Schedule daily batch processing
     *
     * @param string|null $time_string Daily execution time in HH:MM format (e.g., "02:00")
     * @return void
     * @requirement REQ-4D-041: Register daily WP-Cron hook
     */
    public function scheduleDaily( ?string $time_string = null ): void {
        $time_string = $time_string ?? $this->config->getDailyScheduleTime();

        // Schedule WP-Cron event if not already scheduled
        if ( ! wp_next_scheduled( 'wc_auction_process_daily_batch' ) ) {
            // Parse time string to get hour and minute
            $parts = explode( ':', $time_string );
            $hour   = intval( $parts[0] ?? 2 );
            $minute = intval( $parts[1] ?? 0 );

            // Calculate next occurrence
            $next_run = strtotime( gmdate( 'Y-m-d' ) . " {$hour}:{$minute}:00" );
            if ( $next_run < time() ) {
                $next_run = strtotime( '+1 day', $next_run );
            }

            wp_schedule_event( $next_run, 'daily', 'wc_auction_process_daily_batch' );
        }
    }

    /**
     * Schedule weekly batch processing
     *
     * @param string|null $day_of_week Day of week (0=Sunday, 6=Saturday)
     * @param string|null $time_string Execution time in HH:MM format
     * @return void
     * @requirement REQ-4D-041: Register weekly WP-Cron hook
     */
    public function scheduleWeekly( ?string $day_of_week = null, ?string $time_string = null ): void {
        $day_of_week = $day_of_week ?? $this->config->getWeeklyScheduleDay();
        $time_string = $time_string ?? $this->config->getDailyScheduleTime();

        if ( ! wp_next_scheduled( 'wc_auction_process_weekly_batch' ) ) {
            $parts       = explode( ':', $time_string );
            $hour        = intval( $parts[0] ?? 2 );
            $minute      = intval( $parts[1] ?? 0 );
            $current_dow = intval( gmdate( 'w' ) );

            // Calculate next occurrence
            $days_until = ( $day_of_week - $current_dow + 7 ) % 7;
            if ( 0 === $days_until ) {
                $run_time = strtotime( gmdate( 'Y-m-d' ) . " {$hour}:{$minute}:00" );
                if ( $run_time < time() ) {
                    $days_until = 7;
                }
            }

            $next_run = strtotime( "+{$days_until} days", strtotime( gmdate( 'Y-m-d' ) . " {$hour}:{$minute}:00" ) );

            wp_schedule_event( $next_run, 'weekly', 'wc_auction_process_weekly_batch' );
        }
    }

    /**
     * Process scheduled batch by ID with concurrency lock
     *
     * @param int $batch_id Settlement batch ID
     * @return BatchProcessingResult Processing result
     * @throws \Exception If batch not found
     * @requirement REQ-4D-041: Execute batch with locking
     * @requirement REQ-4D-042: Prevent concurrent execution
     */
    public function processScheduledBatch( int $batch_id ): BatchProcessingResult {
        // Check if batch is already locked
        if ( $this->isBatchLocked( $batch_id ) ) {
            return BatchProcessingResult::createSkipped(
                $batch_id,
                0,
                0,
                0,
                'Batch already processing'
            );
        }

        // Acquire lock
        $lock_acquired = $this->lock_repository->acquireLock( $batch_id, self::LOCK_TIMEOUT );
        if ( ! $lock_acquired ) {
            return BatchProcessingResult::createSkipped(
                $batch_id,
                0,
                0,
                0,
                'Could not acquire batch lock'
            );
        }

        try {
            // Load batch
            $batch = $this->batch_repository->find( $batch_id );
            if ( null === $batch ) {
                throw new \Exception( "Batch not found: {$batch_id}" );
            }

            // Record processing start time
            $start_time = microtime( true );

            // Process payouts in batch
            $processed = $this->payout_service->processPayoutBatch( $batch );

            // Update batch status
            $batch->setStatus( SettlementBatch::STATUS_PROCESSED );
            $batch->setProcessedAt( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
            $this->batch_repository->update( $batch );

            // Calculate duration
            $duration = microtime( true ) - $start_time;

            // Publish event
            $this->event_publisher->publish(
                'batch.processing.completed',
                [
                    'batch_id' => $batch_id,
                    'processed' => $processed,
                    'duration' => $duration,
                ]
            );

            return BatchProcessingResult::createSuccess(
                $batch_id,
                $processed,
                0,
                $processed,
                $duration
            );

        } catch ( \Exception $e ) {
            error_log( "Error processing batch {$batch_id}: {$e->getMessage()}" );

            return BatchProcessingResult::createFailed(
                $batch_id,
                0,
                1,
                0,
                $e->getMessage()
            );

        } finally {
            // Always release lock
            $this->lock_repository->releaseLock( $batch_id );
        }
    }

    /**
     * Process batch immediately (manual triggering)
     *
     * @param int $batch_id Settlement batch ID
     * @return BatchProcessingResult Processing result
     * @requirement REQ-4D-041: Support manual batch processing
     */
    public function processNow( int $batch_id ): BatchProcessingResult {
        return $this->processScheduledBatch( $batch_id );
    }

    /**
     * Check if batch is currently locked
     *
     * @param int $batch_id Batch ID
     * @return bool True if batch is locked
     * @requirement REQ-4D-042: Check lock status
     */
    public function isBatchLocked( int $batch_id ): bool {
        return $this->lock_repository->isLocked( $batch_id );
    }

    /**
     * Get batch processing configuration
     *
     * @return BatchSchedulerConfiguration
     */
    public function getConfiguration(): BatchSchedulerConfiguration {
        return $this->config;
    }
}
