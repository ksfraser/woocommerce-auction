<?php
/**
 * YITH Auction Outcome Hook Adapter
 *
 * Adapter that integrates AuctionOutcomePaymentIntegration with YITH auction completion flow.
 * Registers WordPress hooks to trigger payment capture when auctions end.
 *
 * ## Integration Architecture
 *
 * ```
 * YITH Auction Ends (@timestamp)
 *         |
 *         v
 * WordPress Hook: yith_wcact_auction_completed
 *         |
 *         v
 * register() → Registers filter/action handlers
 *         |
 *         v
 * processOutcome() → Calls AuctionOutcomePaymentIntegration
 *         |
 *         +--------+--------+--------+
 *         |        |        |        |
 *         v        v        v        v
 *      Capture   Schedule  Create  Send
 *      Entry Fee  Refunds   Order   Emails
 * ```
 *
 * ## WordPress Hooks Used
 *
 * **Action Hook**: wp_loaded (priority 91)
 * - Checks if auction timestamp has passed (is_closed())
 * - Gets all completed auctionsneeds to exist for processAuctionOutcome integration
 *
 * **Filter Hook**: yith_wcact_auction_completed (priority 10)
 * - Called when auction is detected as finished
 * - For future use: allows plugins to inject custom logic
 *
 * @package YITHEA\Integration
 * @covers-requirement REQ-024-entry-fee-capture-on-auction-win
 * @covers-requirement REQ-025-refund-scheduling-on-outbid
 */

namespace YITHEA\Integration;

use YITHEA\Services\AuctionOutcomePaymentIntegration;
use YITHEA\Traits\LoggerTrait;
use Exception;

/**
 * Class AuctionOutcomeHook
 *
 * Adapter that integrates with YITH Auctions completion flow.
 * Represents the Bridge between YITH's auction completion system and payment processing.
 *
 * @package YITHEA\Integration
 */
class AuctionOutcomeHook {

    use LoggerTrait;

    /**
     * Payment integration service
     *
     * @var AuctionOutcomePaymentIntegration
     */
    private AuctionOutcomePaymentIntegration $payment_integration;

    /**
     * WordPress database object
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Constructor
     *
     * @param AuctionOutcomePaymentIntegration $payment_integration Auction outcome service
     */
    public function __construct(AuctionOutcomePaymentIntegration $payment_integration) {
        global $wpdb;

        $this->payment_integration = $payment_integration;
        $this->wpdb = $wpdb;
    }

    /**
     * Register WordPress hooks for auction completion
     *
     * Called during plugin initialization to hook into:
     * - wp_loaded: Check for completed auctions
     * - yith_wcact_auction_completed: Custom hook for 3rd party extensions
     *
     * **Hook Registration:**
     *
     * ```php
     * add_action('wp_loaded', [outcome_hook, 'checkCompletedAuctions'], 91);
     * ```
     *
     * Priority 91 ensures this runs after standard WordPress initialization (priority 10)
     * but before most plugin logic.
     *
     * @return void
     *
     * REQ-024: Captures entry fees automatically when auction ends
     * REQ-025: Schedules refunds for outbid bidders
     *
     * @internal Called during plugin initialization
     */
    public function register(): void {
        add_action('wp_loaded', [$this, 'checkCompletedAuctions'], 91);

        $this->log('info', 'Auction outcome hooks registered');
    }

    /**
     * Check for completed auctions and process outcomes
     *
     * Called on wp_loaded to identify auctions that have ended and need payment
     * processing. Queries wp_postmeta for auctions with timestamps in the past.
     *
     * **Query Logic:**
     * - Gets all products with post_type = 'product'
     * - Filters by has _yith_auction_to meta (is auction)
     * - Filters by _yith_auction_to <= current_time (auction ended)
     * - Filters by NOT _yith_auction_paid_order (not yet processed)
     * - Excludes auctions with 0 bids (no_payment_needed flag)
     *
     * **Processing:**
     * For each completed auction:
     * 1. Call processAuctionOutcome()
     * 2. On success: Mark auction as _yith_auction_paid_order = 1
     * 3. On failure: Log error, retry on next wp_loaded
     *
     * @return void
     *
     * @internal Called on wp_loaded action
     */
    public function checkCompletedAuctions(): void {
        try {
            $this->log('info', 'Checking for completed auctions');

            // Get all auctions that have ended but not been processed
            $completed_auctions = $this->getCompletedAuctionsNeedingProcessing();

            if (empty($completed_auctions)) {
                $this->log('debug', 'No completed auctions to process');
                return;
            }

            $this->log('info', 'Found completed auctions to process', [
                'count' => count($completed_auctions),
            ]);

            // Process each auction
            $success_count = 0;
            $error_count = 0;

            foreach ($completed_auctions as $auction_id) {
                try {
                    $this->processAuctionOutcome($auction_id);
                    $success_count++;
                } catch (Exception $e) {
                    $this->log('error', 'Failed to process auction outcome', [
                        'auction_id' => $auction_id,
                        'error' => $e->getMessage(),
                    ]);
                    $error_count++;
                }
            }

            $this->log('info', 'Auction outcome processing batch complete', [
                'success' => $success_count,
                'errors' => $error_count,
            ]);

        } catch (Exception $e) {
            $this->log('error', 'Auction completion check failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process single auction outcome
     *
     * Calls AuctionOutcomePaymentIntegration::processAuctionOutcome() and marks
     * auction as processed in database.
     *
     * **Flow:**
     * 1. Call payment service processAuctionOutcome()
     * 2. On success:
     *    - Set _yith_auction_paid_order = 1 (prevents reprocessing)
     *    - Trigger yith_wcact_auction_outcome_processed action
     * 3. On failure:
     *    - Log error but don't mark as processed (retry on next wp_loaded)
     *    - Trigger yith_wcact_auction_outcome_failed action
     *
     * @param int $auction_id Product ID of auction
     *
     * @return void
     *
     * @throws Exception If processAuctionOutcome fails
     *
     * @internal Called by checkCompletedAuctions()
     */
    private function processAuctionOutcome(int $auction_id): void {
        $this->log('info', 'Processing auction outcome', ['auction_id' => $auction_id]);

        $result = $this->payment_integration->processAuctionOutcome($auction_id);

        if ($result['status'] === 'SUCCESS') {
            // Mark auction as processed
            update_post_meta($auction_id, '_yith_auction_paid_order', '1');

            /**
             * Action: Auction outcome successfully processed
             *
             * @hook yith_wcact_auction_outcome_processed
             * @param {int}   $auction_id    Product ID
             * @param {array} $result        Processing result with status, winner_id, order_id
             * @returns {void}
             */
            do_action('yith_wcact_auction_outcome_processed', $auction_id, $result);

            $this->log('info', 'Auction outcome processed successfully', [
                'auction_id' => $auction_id,
                'winner_id' => $result['winner_id'],
                'order_id' => $result['order_id'],
            ]);

        } else {
            /**
             * Action: Auction outcome processing failed
             *
             * @hook yith_wcact_auction_outcome_failed
             * @param {int}   $auction_id    Product ID
             * @param {array} $result        Processing result with status, errors
             * @returns {void}
             */
            do_action('yith_wcact_auction_outcome_failed', $auction_id, $result);

            $error_msg = !empty($result['errors']) ? implode('; ', $result['errors']) : 'Unknown error';
            throw new Exception('Auction outcome processing failed: ' . $error_msg);
        }
    }

    /**
     * Get all auctions that need outcome processing
     *
     * Queries for auctions that:
     * 1. Are products (post_type = 'product')
     * 2. Have auction end time in the past (_yith_auction_to <= now)
     * 3. Are not yet marked as paid (_yith_auction_paid_order is empty)
     * 4. Have at least one bid (prevent processing no-bid auctions)
     *
     * **SQL Query:**
     * ```sql
     * SELECT p.ID FROM wp_posts p
     * INNER JOIN wp_postmeta pm_end ON p.ID = pm_end.post_id
     *   AND pm_end.meta_key = '_yith_auction_to'
     * LEFT JOIN wp_postmeta pm_paid ON p.ID = pm_paid.post_id
     *   AND pm_paid.meta_key = '_yith_auction_paid_order'
     * INNER JOIN wp_yith_wcact_auction bids ON p.ID = bids.auction_id
     * WHERE p.post_type = 'product'
     *   AND CAST(pm_end.meta_value AS UNSIGNED) <= %d (current_time)
     *   AND (pm_paid.meta_value IS NULL OR pm_paid.meta_value = '')
     * GROUP BY p.ID
     * ```
     *
     * @return array Array of product IDs (auction IDs)
     *
     * @internal Used by checkCompletedAuctions()
     */
    private function getCompletedAuctionsNeedingProcessing(): array {
        $current_time = time();

        $query = $this->wpdb->prepare(
            "SELECT p.ID FROM {$this->wpdb->posts} p
             INNER JOIN {$this->wpdb->postmeta} pm_end 
                ON p.ID = pm_end.post_id 
                AND pm_end.meta_key = '_yith_auction_to'
             LEFT JOIN {$this->wpdb->postmeta} pm_paid 
                ON p.ID = pm_paid.post_id 
                AND pm_paid.meta_key = '_yith_auction_paid_order'
             INNER JOIN {$this->wpdb->prefix}yith_wcact_auction bids 
                ON p.ID = bids.auction_id
             WHERE p.post_type = 'product'
               AND CAST(pm_end.meta_value AS UNSIGNED) <= %d
               AND (pm_paid.meta_value IS NULL OR pm_paid.meta_value = '')
             GROUP BY p.ID",
            $current_time
        );

        $results = $this->wpdb->get_col($query);
        return $results ?? [];
    }

    /**
     * Get audit trail of processed auctions
     *
     * Returns list of recently processed auctions for admin dashboard inspection.
     * Queries wp_postmeta for auctions with _yith_auction_paid_order = 1, limited
     * to most recent 100.
     *
     * @param int $limit Maximum results to return (default: 100)
     *
     * @return array Array of audit records [
     *     [
     *         'auction_id' => int,
     *         'audit_timestamp' => string (date format Y-m-d H:i:s),
     *     ]
     * ]
     *
     * @internal Used by admin dashboard widgets
     */
    public function getAuditTrail(int $limit = 100): array {
        $query = $this->wpdb->prepare(
            "SELECT pm.post_id as auction_id, pm.meta_value as audit_timestamp 
             FROM {$this->wpdb->postmeta} pm
             WHERE pm.meta_key = '_yith_auction_paid_order' 
               AND pm.meta_value != ''
             ORDER BY pm.meta_id DESC
             LIMIT %d",
            $limit
        );

        $results = $this->wpdb->get_results($query);
        return $results ?? [];
    }

    /**
     * Manually trigger auction outcome processing
     *
     * Admin function to manually process auction outcome if automatic processing fails.
     * Called from admin UI when admin clicks "Process Outcome" button.
     *
     * @param int $auction_id Product ID to process
     *
     * @return array Processing result [
     *     'status' => 'SUCCESS' | 'FAILED',
     *     'message' => string,
     *     'result' => array (if successful),
     * ]
     *
     * @internal Called from admin action handler
     */
    public function manuallyProcessOutcome(int $auction_id): array {
        try {
            $this->processAuctionOutcome($auction_id);

            return [
                'status' => 'SUCCESS',
                'message' => sprintf(
                    __('Auction %d processed successfully', 'yith-auctions-for-woocommerce'),
                    $auction_id
                ),
            ];

        } catch (Exception $e) {
            $this->log('error', 'Manual outcome processing failed', [
                'auction_id' => $auction_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'FAILED',
                'message' => $this->payment_integration->getErrorMessage($e),
                'error' => $e->getMessage(),
            ];
        }
    }
}
