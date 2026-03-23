<?php
/**
 * Refund Scheduler WordPress Cron Integration
 *
 * Registers and manages WordPress cron hooks for automated refund processing.
 * Executes hourly to process refunds scheduled for completion.
 *
 * ## Architecture Overview
 *
 * ```
 * Plugin Activation
 *         |
 *         v
 * RefundSchedulerCronIntegration::register()
 *         |
 *         ├─ Register cron hook: wc_auction_process_refunds (hourly)
 *         ├─ Bind action handler: processScheduledRefunds()
 *         └─ Bind deactivation: unschedule()
 *
 * Every Hour (WordPress Cron)
 *         |
 *         v
 * wp_scheduled_event: wc_auction_process_refunds
 *         |
 *         v
 * RefundSchedulerCronIntegration::processScheduledRefunds()
 *         |
 *         v
 * RefundSchedulerService::processScheduledRefunds()
 *         |
 *    +----+----+
 *    |         |
 *    v         v
 * Execute   Notify
 * Refunds   Bidders
 * ```
 *
 * ## Refund Processing Timeline
 *
 * ```
 * T0: Auction Ends (Phase 4-C Step 2)
 *     └─ Refund schedules created with scheduled_for = now + 24h
 *        Status: SCHEDULED
 *
 * T1-24h: Nothing happens
 *     └─ refund_schedule records sit in database
 *
 * T2: 24 Hours Later (Hourly Cron Fires This Session)
 *     ├─ Query: WHERE status='SCHEDULED' AND scheduled_for <= NOW()
 *     ├─ For each scheduled refund:
 *     │  ├─ Create new refund in payment gateway
 *     │  ├─ Update refund_schedule status → PROCESSED
 *     │  ├─ Update authorization status → REFUNDED
 *     │  ├─ Update wp_posts refund metadata
 *     │  └─ Call notification callback
 *     └─ Log all operations
 *
 * T3: After Refund Processed
 *     └─ Bidder receives money (typically 1-3 business days via bank)
 *        Email sent: "Your refund of $X is being processed"
 * ```
 *
 * @package YITHEA\Integration
 * @covers-requirement REQ-028-cron-registration-setup
 * @covers-requirement REQ-029-hourly-refund-processing
 * @covers-requirement REQ-030-refund-completion-notifications
 */

namespace YITHEA\Integration;

use YITHEA\Services\EntryFees\RefundSchedulerService;
use YITHEA\Traits\LoggerTrait;
use Exception;

/**
 * Class RefundSchedulerCronIntegration
 *
 * Manages WordPress cron registration and execution for refund processing.
 * Handles scheduling, unscheduling, and monitoring of hourly refund jobs.
 *
 * @package YITHEA\Integration
 */
class RefundSchedulerCronIntegration {

    use LoggerTrait;

    /**
     * WordPress cron hook name
     *
     * @var string
     */
    private const CRON_HOOK = 'wc_auction_process_refunds';

    /**
     * WordPress cron interval
     *
     * @var string
     */
    private const CRON_INTERVAL = 'hourly';

    /**
     * Refund scheduler service
     *
     * @var RefundSchedulerService
     */
    private RefundSchedulerService $refund_scheduler;

    /**
     * Optional notification callback
     *
     * @var callable|null
     */
    private ?callable $notification_callback = null;

    /**
     * Constructor
     *
     * @param RefundSchedulerService $refund_scheduler Refund scheduler service
     * @param callable|null          $notification_callback Optional email callback
     */
    public function __construct(
        RefundSchedulerService $refund_scheduler,
        ?callable $notification_callback = null
    ) {
        $this->refund_scheduler = $refund_scheduler;
        $this->notification_callback = $notification_callback;
    }

    /**
     * Register WordPress cron hooks
     *
     * Called during plugin initialization to set up hourly refund processing.
     * This method:
     *
     * 1. Checks if cron is already scheduled (prevents duplicates)
     * 2. Registers WordPress action hook
     * 3. Schedules cron event if not already scheduled
     * 4. Registers deactivation hook for cleanup
     *
     * **WordPress Cron Behavior:**
     * - Only runs if WP-Cron is enabled (wp_disable_cron !== true)
     * - Triggered on page load if due (not guaranteed real-time)
     * - Supports custom intervals via wp_get_schedules()
     *
     * **Usage:**
     * ```php
     * add_action('plugins_loaded', function() {
     *     $cron = new RefundSchedulerCronIntegration($service, $callback);
     *     $cron->register();
     * }, 22); // Priority 22 (after services initialized at 21)
     * ```
     *
     * @return void
     *
     * REQ-028: Cron hooks registered on plugin initialization
     *
     * @internal Called during plugin setup
     */
    public function register(): void {
        try {
            // 1. Add action handler
            add_action(self::CRON_HOOK, [$this, 'processScheduledRefunds']);

            // 2. Schedule cron event if not already scheduled
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(
                    time(),
                    self::CRON_INTERVAL,
                    self::CRON_HOOK
                );

                $this->log('info', 'Cron job scheduled', [
                    'hook' => self::CRON_HOOK,
                    'interval' => self::CRON_INTERVAL,
                    'next_run' => wp_next_scheduled(self::CRON_HOOK),
                ]);
            } else {
                $this->log('info', 'Cron job already scheduled', [
                    'hook' => self::CRON_HOOK,
                    'next_run' => wp_next_scheduled(self::CRON_HOOK),
                ]);
            }

            // 3. Register deactivation cleanup
            register_deactivation_hook(
                plugin_basename(__FILE__),
                [$this, 'unschedule']
            );

            $this->log('info', 'Refund scheduler cron integration registered');

        } catch (Exception $e) {
            $this->log('error', 'Failed to register refund cron', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Process scheduled refunds (WordPress cron action handler)
     *
     * Called hourly by WordPress cron. This is the main entry point that
     * processes all refunds that are scheduled and due (scheduled_for <= NOW()).
     *
     * **Execution Flow:**
     *
     * 1. Check if processing already running (prevent concurrent execution)
     * 2. Call RefundSchedulerService::processScheduledRefunds()
     * 3. Get processing statistics
     * 4. Log results
     * 5. Trigger monitoring actions for dashboard
     * 6. Send optional notifications
     *
     * **Processing Details:**
     * - Processes up to 50 refunds per hourly run
     * - Continues hourly until all are processed
     * - Handles failures gracefully (logs, retryable)
     * - Updates authorization and refund_schedule records
     *
     * **Error Handling:**
     * - Individual refund failures don't stop others
     * - Failures logged with details for admin
     * - Retryable on next hourly run
     *
     * @return void
     *
     * REQ-029: Refunds processed automatically every hour
     * REQ-030: Notifications sent after refund completion
     *
     * @internal Action handler for wc_auction_process_refunds hook
     */
    public function processScheduledRefunds(): void {
        try {
            // 1. Check for concurrent execution
            if ($this->isProcessingInProgress()) {
                $this->log('warning', 'Refund processing already in progress');
                return;
            }

            // Mark as processing
            $this->setProcessingInProgress(true);

            $this->log('info', 'Starting scheduled refund processing');

            // 2. Process refunds via scheduler service
            $result = $this->refund_scheduler->processScheduledRefunds(
                $this->notification_callback
            );

            $this->log('info', 'Scheduled refund processing complete', [
                'processed' => $result['processed_count'],
                'failed' => $result['failed_count'],
                'skipped' => $result['skipped_count'],
                'total_refunded' => $result['total_refunded_cents'],
            ]);

            // 3. Trigger monitoring action for dashboard
            /**
             * Action: Refund processing batch completed
             *
             * @hook wc_auction_refund_processing_complete
             * @param {array} $result  Processing statistics [
             *     'processed_count' => int,
             *     'failed_count'    => int,
             *     'skipped_count'   => int,
             *     'total_refunded'  => int (cents),
             *     'duration_ms'     => int,
             * ]
             * @returns {void}
             */
            do_action('wc_auction_refund_processing_complete', $result);

            // 4. Alert on failures
            if ($result['failed_count'] > 0) {
                $this->log('warning', 'Some refunds failed during processing', [
                    'failed_count' => $result['failed_count'],
                ]);

                /**
                 * Action: Refund processing had failures
                 *
                 * @hook wc_auction_refund_processing_failed
                 * @param {int} $failed_count Number of refunds that failed
                 * @returns {void}
                 */
                do_action('wc_auction_refund_processing_failed', $result['failed_count']);
            }

        } catch (Exception $e) {
            $this->log('error', 'Refund processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            /**
             * Action: Critical error during refund processing
             *
             * @hook wc_auction_refund_processing_error
             * @param {Exception} $e The exception thrown
             * @returns {void}
             */
            do_action('wc_auction_refund_processing_error', $e);

        } finally {
            // Always clear processing flag
            $this->setProcessingInProgress(false);
        }
    }

    /**
     * Unschedule cron events
     *
     * Called on plugin deactivation to clean up scheduled cron events.
     * Prevents orphaned cron tasks from running after plugin is disabled.
     *
     * **Cleanup Actions:**
     * 1. Get next scheduled time for our hook
     * 2. Remove the scheduled event
     * 3. Log cleanup
     *
     * @return void
     *
     * @internal Called via register_deactivation_hook()
     */
    public function unschedule(): void {
        try {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);

            if ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);

                $this->log('info', 'Cron job unscheduled', [
                    'hook' => self::CRON_HOOK,
                    'was_scheduled_for' => date('Y-m-d H:i:s', $timestamp),
                ]);
            }

        } catch (Exception $e) {
            $this->log('error', 'Failed to unschedule cron', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cron job status and scheduling information
     *
     * Returns detailed information about the cron job:
     * - Currently scheduled or not
     * - Next scheduled run time
     * - Interval
     * - Hook name
     *
     * Used for:
     * - Admin dashboard status display
     * - Troubleshooting (check if cron is registered)
     * - Monitoring (next run time)
     *
     * @return array {
     *     'is_scheduled'    => bool,
     *     'hook'            => string,
     *     'interval'        => string,
     *     'next_run'        => int (Unix timestamp),
     *     'next_run_human'  => string (formatted date),
     *     'status'          => 'active' | 'inactive' | 'overdue',
     * }
     *
     * @internal Used by admin dashboard
     */
    public function getStatus(): array {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        $status = 'inactive';
        if ($timestamp) {
            $status = $timestamp <= time() ? 'overdue' : 'active';
        }

        return [
            'is_scheduled' => (bool) $timestamp,
            'hook' => self::CRON_HOOK,
            'interval' => self::CRON_INTERVAL,
            'next_run' => $timestamp ?: null,
            'next_run_human' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : 'Not scheduled',
            'status' => $status,
        ];
    }

    /**
     * Get processing statistics
     *
     * Returns statistics about refund processing from RefundSchedulerService.
     * Used for monitoring and dashboard display.
     *
     * @return array Processing statistics [
     *     'total_pending'    => int (refunds waiting to process),
     *     'total_processed'  => int (refunds completed),
     *     'total_failed'     => int (refunds that failed),
     *     'success_rate'     => float (0-100),
     *     'avg_processing_time_ms' => int,
     * ]
     *
     * @internal Used by admin dashboard widgets
     */
    public function getStatistics(): array {
        return $this->refund_scheduler->getProcessingStats();
    }

    /**
     * Manually trigger refund processing (for admin UI)
     *
     * Allows admin to manually run refund processing instead of waiting
     * for hourly cron. Useful for:
     * - Testing
     * - Emergency processing
     * - Forcing missed runs
     *
     * **Safety Checks:**
     * - Prevents concurrent execution
     * - Returns status array
     * - Allows error recovery
     *
     * @return array {
     *     'status'          => 'SUCCESS' | 'FAILED',
     *     'message'         => string,
     *     'processing_stats' => array (if successful),
     *     'error'           => string (if failed),
     * }
     *
     * @internal Called from admin action handler
     */
    public function manuallyTriggerProcessing(): array {
        try {
            if ($this->isProcessingInProgress()) {
                return [
                    'status' => 'FAILED',
                    'message' => __('Refund processing already in progress', 'yith-auctions-for-woocommerce'),
                ];
            }

            $this->log('info', 'Manual refund processing triggered');

            $this->processScheduledRefunds();

            return [
                'status' => 'SUCCESS',
                'message' => __('Refund processing completed successfully', 'yith-auctions-for-woocommerce'),
                'processing_stats' => $this->getStatistics(),
            ];

        } catch (Exception $e) {
            $this->log('error', 'Manual refund processing failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'FAILED',
                'message' => __('Refund processing failed', 'yith-auctions-for-woocommerce'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if refund processing is currently in progress
     *
     * Returns true if another process is currently executing refunds.
     * Prevents concurrent execution via transient lock.
     *
     * @return bool True if processing in progress
     *
     * @internal Used to prevent race conditions
     */
    private function isProcessingInProgress(): bool {
        return (bool) get_transient('wc_auction_refund_processing_lock');
    }

    /**
     * Set processing in progress flag
     *
     * Sets/clears transient lock to prevent concurrent refund processing.
     * Lock expires automatically after 15 minutes for safety.
     *
     * @param bool $in_progress True to lock, false to unlock
     *
     * @return void
     *
     * @internal Used to synchronize processing
     */
    private function setProcessingInProgress(bool $in_progress): void {
        if ($in_progress) {
            // Lock for 15 minutes (HOUR_IN_SECONDS / 4)
            set_transient('wc_auction_refund_processing_lock', 1, 15 * 60);
        } else {
            delete_transient('wc_auction_refund_processing_lock');
        }
    }

    /**
     * Get refund processing logs
     *
     * Retrieves recent log entries related to refund processing for
     * admin dashboard inspection.
     *
     * @param int $limit Maximum results to return (default: 100)
     *
     * @return array Array of log entries
     *
     * @internal Used by admin dashboard
     */
    public function getProcessingLogs(int $limit = 100): array {
        // This would query from logger implementation
        // Placeholder for integration with logging system
        return [];
    }

    /**
     * Get refund processing queue status
     *
     * Returns current state of refund_schedule table:
     * - How many refunds are SCHEDULED
     * - How many are PROCESSING
     * - How many are COMPLETED
     * - How many are FAILED
     *
     * @return array {
     *     'scheduled'   => int,
     *     'processing'  => int,
     *     'completed'   => int,
     *     'failed'      => int,
     *     'oldest_refund_age_hours' => int,
     * }
     *
     * @internal Used by admin dashboard
     */
    public function getQueueStatus(): array {
        return $this->refund_scheduler->getQueueStatus();
    }

    /**
     * Retry failed refund processing
     *
     * Manually retry a specific refund that failed during processing.
     * Used by admin to manually recover from failures.
     *
     * @param int $refund_schedule_id Refund schedule record ID
     *
     * @return array {
     *     'status'  => 'SUCCESS' | 'FAILED',
     *     'message' => string,
     *     'result'  => array (if successful),
     * }
     *
     * @internal Called from admin action handler
     */
    public function retryFailedRefund(int $refund_schedule_id): array {
        try {
            $result = $this->refund_scheduler->retryFailedRefund($refund_schedule_id);

            return [
                'status' => 'SUCCESS',
                'message' => __('Refund retry queued', 'yith-auctions-for-woocommerce'),
                'result' => $result,
            ];

        } catch (Exception $e) {
            $this->log('error', 'Failed to retry refund', [
                'schedule_id' => $refund_schedule_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'FAILED',
                'message' => __('Failed to retry refund', 'yith-auctions-for-woocommerce'),
            ];
        }
    }
}
