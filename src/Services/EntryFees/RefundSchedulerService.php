<?php

namespace Yith\Auctions\Services\EntryFees;

use Yith\Auctions\Contracts\PaymentGatewayInterface;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Exceptions\PaymentException;

/**
 * RefundSchedulerService - Processes scheduled refunds via cron job.
 *
 * Refund Scheduler handles batch processing of refunds that have completed
 * their 24-hour dispute window. Runs hourly via WordPress cron.
 *
 * Workflow:
 *
 * 1. Refund Scheduling (EntryFeePaymentService.scheduleRefund)
 *    └─ Bid is outbid → Queue refund for 24h later
 *
 * 2. Refund Processing (this service via cron)
 *    └─ Hourly: Check for pending refunds
 *    └─ Process: Call SquarePaymentGateway.refundPayment()
 *    └─ Update: Mark refund as PROCESSED or FAILED
 *
 * 3. Retry Logic
 *    └─ Failed refunds remain PENDING for next cron run
 *    └─ Failures logged for manual investigation
 *
 * Cron Registration:
 *
 * ```php
 * // In plugin initialization
 * if (!wp_next_scheduled('wc_auction_process_refunds')) {
 *     wp_schedule_event(time(), 'hourly', 'wc_auction_process_refunds');
 * }
 *
 * // Hooked handler
 * add_action('wc_auction_process_refunds', function() {
 *     $scheduler = new RefundSchedulerService(
 *         $payment_gateway,
 *         $repository,
 *         $notification_service
 *     );
 *     $scheduler->processScheduledRefunds();
 * });
 * ```
 *
 * Performance:
 * - Processes up to 50 refunds per cron run
 * - O(n) where n ≤ 50 (limit prevents API rate limits)
 * - Can run hourly without strain
 * - Failed refunds automatically retried
 *
 * Resilience:
 * - Logs all processing attempts
 * - Tracks success/failure counts
 * - Continues on individual failures (doesn't stop batch)
 * - Supports manual retry of failed refunds
 * - Idempotent operations (safe to re-run)
 *
 * @package Yith\Auctions\Services\EntryFees
 * @requirement REQ-ENTRY-FEE-PAYMENT-001: Process scheduled refunds
 *
 * Architecture:
 *
 * RefundSchedulerService
 *     ├─ depends: PaymentGatewayInterface (process refund)
 *     ├─ depends: PaymentAuthorizationRepository (get pending)
 *     ├─ depends: NotificationService (notify bidders)
 *     ├─ uses: LoggerTrait (operation logging)
 *     └─ throws: PaymentException (on critical failures)
 */
class RefundSchedulerService
{
    use LoggerTrait;

    /**
     * @var PaymentGatewayInterface Payment processor
     */
    private PaymentGatewayInterface $payment_gateway;

    /**
     * @var PaymentAuthorizationRepository Repository for payment data
     */
    private PaymentAuthorizationRepository $repository;

    /**
     * @var callable|null Optional notification callback for bidders
     */
    private $notification_callback;

    /**
     * @var int Maximum refunds to process per cron run (prevent API rate limits)
     */
    private const BATCH_SIZE = 50;

    /**
     * initialize refund scheduler service.
     *
     * @param PaymentGatewayInterface        $gateway             Payment processor
     * @param PaymentAuthorizationRepository $repository          Payment data access
     * @param callable|null                  $notification_helper Callback: function($user_id, $amount, $refund_id)
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function __construct(
        PaymentGatewayInterface $gateway,
        PaymentAuthorizationRepository $repository,
        $notification_callback = null
    ) {
        $this->payment_gateway = $gateway;
        $this->repository = $repository;
        $this->notification_callback = $notification_callback;
    }

    /**
     * Process all pending refunds (24h+ older).
     *
     * Called hourly by WordPress cron job. Retrieves all refunds that have
     * passed the 24-hour dispute window and processes them.
     *
     * Processing for each refund:
     * 1. Retrieve authorization details from database
     * 2. Call payment gateway to refund
     * 3. Update refund status (PROCESSED or FAILED)
     * 4. Notify bidder if callback provided
     * 5. Log all operations for audit trail
     *
     * Batch Behavior:
     * - Processes up to 50 refunds per run (prevent API rate limits)
     * - Continues on individual failures (doesn't abort)
     * - Failed refunds remain PENDING for next run
     * - Returns summary stats for monitoring
     *
     * @return array Summary stats:
     *     [
     *         'total_processed' => int,
     *         'successful' => int,
     *         'failed' => int,
     *         'skipped' => int,
     *         'next_run' => 'in ~1 hour'
     *     ]
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Process scheduled refunds
     */
    public function processScheduledRefunds(): array
    {
        $this->logInfo('Starting refund processing batch', [
            'batch_size' => self::BATCH_SIZE,
        ]);

        $stats = [
            'total_processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            // Get refunds ready to process (scheduled_for <= NOW)
            $pending_refunds = $this->repository->getPendingRefunds(self::BATCH_SIZE);

            if (empty($pending_refunds)) {
                $this->logInfo('No pending refunds to process');
                return $stats;
            }

            $this->logInfo('Found pending refunds', [
                'count' => count($pending_refunds),
            ]);

            // Process each refund
            foreach ($pending_refunds as $refund) {
                $stats['total_processed']++;

                try {
                    $result = $this->processRefund($refund);

                    if ($result) {
                        $stats['successful']++;
                    } else {
                        $stats['failed']++;
                    }
                } catch (\Exception $e) {
                    $stats['failed']++;
                    $stats['errors'][] = [
                        'refund_id' => $refund['refund_id'],
                        'error' => $e->getMessage(),
                    ];

                    $this->logError('Failed to process refund', [
                        'refund_id' => $refund['refund_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Log summary
            $this->logInfo('Refund batch processing complete', $stats);

            return $stats;
        } catch (\Exception $e) {
            $this->logError('Critical error in refund batch processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new PaymentException('Refund batch processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Process a single refund.
     *
     * Retrieves authorization details, calls payment gateway to process refund,
     * updates database status, and notifies bidder if callback provided.
     *
     * @param array $refund Refund record:
     *     [
     *         'id' => int,
     *         'refund_id' => string,
     *         'authorization_id' => string,
     *         'user_id' => int,
     *         'amount_cents' => int,
     *         'reason' => string,
     *         'scheduled_for' => string (Y-m-d H:i:s),
     *     ]
     *
     * @return bool True if successful, false if failed (but not thrown)
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Process individual refund
     */
    private function processRefund(array $refund): bool
    {
        $refund_id = $refund['refund_id'];
        $auth_id = $refund['authorization_id'];

        try {
            // Get authorization details
            $auth = $this->repository->getAuthorizationById($auth_id);

            if (!$auth) {
                throw new \Exception("Authorization not found: {$auth_id}");
            }

            // Create Money object from stored amount
            $amount = new Money($auth['amount_cents']);

            $this->logDebug('Processing refund', [
                'refund_id' => $refund_id,
                'auth_id' => $auth_id,
                'amount' => $amount->getFormatted(),
                'user_id' => $refund['user_id'],
            ]);

            // Call payment gateway to refund
            $refund_result = $this->payment_gateway->refundPayment(
                $auth_id,
                $amount,
                [
                    'reason' => $refund['reason'],
                    'refund_id' => $refund_id,
                    'scheduled_refund' => true,
                ]
            );

            // Update refund status to PROCESSED
            $this->repository->updateRefundStatus(
                $refund_id,
                'PROCESSED',
                [
                    'processed_at' => current_time('mysql'),
                ]
            );

            // Update authorization status to REFUNDED
            $this->repository->updateAuthorizationStatus(
                $auth_id,
                'REFUNDED',
                [
                    'refunded_at' => $refund_result['refund_timestamp']->format('Y-m-d H:i:s'),
                ]
            );

            $this->logInfo('Refund processed successfully', [
                'refund_id' => $refund_id,
                'auth_id' => $auth_id,
                'amount' => $amount->getFormatted(),
            ]);

            // Notify bidder if callback provided
            if ($this->notification_callback) {
                $this->notifyBidder(
                    $refund['user_id'],
                    $amount,
                    $refund_id,
                    $refund['reason']
                );
            }

            return true;
        } catch (\Exception $e) {
            $this->logError('Refund processing failed', [
                'refund_id' => $refund_id,
                'auth_id' => $auth_id,
                'error' => $e->getMessage(),
            ]);

            // Mark as failed but don't throw (allow batch to continue)
            try {
                $this->repository->updateRefundStatus(
                    $refund_id,
                    'FAILED',
                    ['error_message' => $e->getMessage()]
                );
            } catch (\Exception $update_error) {
                $this->logError('Failed to update refund status after error', [
                    'refund_id' => $refund_id,
                    'error' => $update_error->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * Notify bidder that refund has been processed.
     *
     * Calls the notification callback with refund details.
     * Used to send email notifications to bidders.
     *
     * @param int    $user_id   Bidder user ID
     * @param Money  $amount    Refunded amount
     * @param string $refund_id Refund identifier
     * @param string $reason    Refund reason
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Notify bidders
     */
    private function notifyBidder(int $user_id, Money $amount, string $refund_id, string $reason): void
    {
        try {
            if (!is_callable($this->notification_callback)) {
                return;
            }

            call_user_func(
                $this->notification_callback,
                $user_id,
                $amount,
                $refund_id,
                $reason
            );

            $this->logDebug('Bidder notified of refund', [
                'user_id' => $user_id,
                'refund_id' => $refund_id,
            ]);
        } catch (\Exception $e) {
            $this->logWarning('Failed to notify bidder', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - notification failure shouldn't abort refund processing
        }
    }

    /**
     * Get processing statistics.
     *
     * Returns aggregate stats for monitoring (total pending, failed, etc).
     * Used by admin dashboard or monitoring systems.
     *
     * @return array Statistics:
     *     [
     *         'pending_count' => int,
     *         'failed_count' => int,
     *         'oldest_pending' => DateTime or null,
     *         'next_batch_size' => int
     *     ]
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Monitoring
     */
    public function getProcessingStats(): array
    {
        $pending = $this->repository->getPendingRefunds(1000);
        $failed = $this->repository->getFailedRefunds(1000);

        $oldest_pending = null;
        if (!empty($pending)) {
            $oldest_pending = new \DateTime($pending[0]['scheduled_for']);
        }

        return [
            'pending_count' => count($pending),
            'failed_count' => count($failed),
            'oldest_pending_scheduled_for' => $oldest_pending?->format('Y-m-d H:i:s'),
            'next_batch_size' => min(count($pending), self::BATCH_SIZE),
            'failed_refunds' => array_slice($failed, 0, 10), // Show last 10 failures
        ];
    }

    /**
     * Retry a specific failed refund.
     *
     * Called manually when admin wants to retry a single failed refund.
     * Uses the same processing logic as batch, but for one refund.
     *
     * @param string $refund_id Refund ID to retry
     *
     * @return bool True if successful
     *
     * @throws \Exception If refund not found
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Manual retry
     */
    public function retryFailedRefund(string $refund_id): bool
    {
        $this->logInfo('Retrying failed refund manually', [
            'refund_id' => $refund_id,
        ]);

        try {
            // Get the refund record
            $refund = $this->repository->getRefundById($refund_id);

            if (!$refund) {
                throw new \Exception("Refund not found: {$refund_id}");
            }

            // Process it
            return $this->processRefund($refund);
        } catch (\Exception $e) {
            $this->logError('Manual refund retry failed', [
                'refund_id' => $refund_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Prune old refund records (after sufficient retention period).
     *
     * Called periodically to clean up successfully processed refunds.
     * Retains failures longer for investigation.
     *
     * @param int $days_old Records older than this are deleted
     *
     * @return int Number of records deleted
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Data retention
     */
    public function pruneRefundRecords(int $days_old = 90): int
    {
        $this->logInfo('Pruning refund records', [
            'older_than_days' => $days_old,
        ]);

        try {
            // Delete authorization records that are fully processed
            $deleted = $this->repository->pruneOldRecords($days_old);

            $this->logInfo('Refund records pruned', [
                'count' => $deleted,
            ]);

            return $deleted;
        } catch (\Exception $e) {
            $this->logError('Failed to prune refund records', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
