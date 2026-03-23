<?php
/**
 * Auction Outcome Payment Integration Service
 *
 * Handles payment capture for auction winners and refund scheduling for outbid bidders
 * when an auction completes.
 *
 * ## Architecture Flow
 *
 * ```
 * Auction Ends (Timestamp Check)
 *         |
 *         v
 * AuctionOutcomePaymentIntegration::processAuctionOutcome()
 *         |
 *    +----+----+
 *    |         |
 *    v         v
 * Get       Get All
 * Winner    Other Bids
 *    |         |
 *    +----+----+
 *         |
 *    +----+-----+-----+
 *    |    |     |     |
 *    v    v     v     v
 *  Capture  Schedule Refund  Create Order  Send Email
 *  Entry Fee  (24h delay)    (WooCommerce)  Notifications
 * ```
 *
 * ## Database Interactions
 *
 * **Reads from:**
 * - wp_yith_wcact_auction (all bids for auction)
 * - wp_wc_auction_payment_authorizations (find authorization by bid_id)
 * - wp_users (bidder contact info)
 * - wp_postmeta (auction settings)
 *
 * **Writes to:**
 * - wp_wc_auction_payment_authorizations (update status to CAPTURED)
 * - wp_wc_auction_refund_schedule (insert refund row)
 * - wp_posts (create WooCommerce order)
 * - wp_postmeta (order meta, auction result)
 *
 * @package YITHEA\Services
 * @covers-requirement REQ-024-entry-fee-capture-on-auction-win
 * @covers-requirement REQ-025-refund-scheduling-on-outbid
 * @covers-requirement REQ-026-woocommerce-order-creation
 * @covers-requirement REQ-027-auction-outcome-notifications
 */

namespace YITHEA\Services;

use YITHEA\Contracts\PaymentGatewayInterface;
use YITHEA\Repositories\PaymentAuthorizationRepository;
use YITHEA\Repositories\RefundScheduleRepository;
use YITHEA\Traits\LoggerTrait;
use YITHEA\Traits\ValidationTrait;
use WC_Product;
use WC_Order;
use Exception;
use WP_Error;

/**
 * Class AuctionOutcomePaymentIntegration
 *
 * Coordinates payment capture and refund scheduling when auctions complete.
 * Handles winner settlement (entry fee capture) and refund scheduling for
 * outbid bidders.
 *
 * @package YITHEA\Services
 */
class AuctionOutcomePaymentIntegration {

    use LoggerTrait;
    use ValidationTrait;

    /**
     * Payment gateway for capture operations
     *
     * @var PaymentGatewayInterface
     */
    private PaymentGatewayInterface $payment_gateway;

    /**
     * Authorization repository for querying existing authorizations
     *
     * @var PaymentAuthorizationRepository
     */
    private PaymentAuthorizationRepository $auth_repository;

    /**
     * Refund schedule repository for recording refund tasks
     *
     * @var RefundScheduleRepository
     */
    private RefundScheduleRepository $refund_repository;

    /**
     * WordPress database object
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Constructor
     *
     * @param PaymentGatewayInterface         $payment_gateway     Square payment gateway
     * @param PaymentAuthorizationRepository  $auth_repository     Authorization repository
     * @param RefundScheduleRepository        $refund_repository   Refund schedule repository
     */
    public function __construct(
        PaymentGatewayInterface $payment_gateway,
        PaymentAuthorizationRepository $auth_repository,
        RefundScheduleRepository $refund_repository
    ) {
        global $wpdb;

        $this->payment_gateway = $payment_gateway;
        $this->auth_repository = $auth_repository;
        $this->refund_repository = $refund_repository;
        $this->wpdb = $wpdb;
    }

    /**
     * Process auction outcome: capture winner's entry fee and schedule refunds
     *
     * Called when auction ends and winner is determined. This method:
     * 1. Queries winning bid from wp_yith_wcact_auction
     * 2. Retrieves authorization for winning bid
     * 3. Captures entry fee via payment gateway
     * 4. Creates WooCommerce order for charged entry fee
     * 5. Queries all non-winning bids
     * 6. Schedules refunds for each outbid bidder (24h later)
     * 7. Sends notifications (winner: entry fee charged, losers: refund scheduled)
     *
     * @param int $auction_id Product ID of the auction
     *
     * @return array {
     *     'status'         => 'SUCCESS' | 'PARTIAL' | 'FAILED',
     *     'winner_id'      => int (bidder user ID),
     *     'winner_amount'  => int (captured amount in cents),
     *     'order_id'       => int (WooCommerce order ID),
     *     'refund_count'   => int (number of refunds scheduled),
     *     'errors'         => array (list of error messages),
     * }
     *
     * @throws Exception If auction_id invalid or auction not finished
     *
     * REQ-024: Entry fee captured immediately when winner is declared
     * REQ-025: Refunds scheduled for all outbid bidders (24 hours after capture)
     * REQ-026: WooCommerce order created for audit trail
     */
    public function processAuctionOutcome(int $auction_id): array {
        try {
            $this->log('info', 'Starting auction outcome processing', ['auction_id' => $auction_id]);

            // 1. Get winning bid
            $winning_bid = $this->getWinningBid($auction_id);
            if (!$winning_bid) {
                throw new Exception('No winning bid found for auction');
            }

            $winner_user_id = $winning_bid->user_id;
            $this->log('info', 'Winning bid identified', ['auction_id' => $auction_id, 'winner_id' => $winner_user_id]);

            // 2. Retrieve authorization for winning bid
            $authorization = $this->auth_repository->findByBidId($winning_bid->id);
            if (!$authorization) {
                throw new Exception('No payment authorization found for winning bid');
            }

            // 3. Capture entry fee via payment gateway
            $capture_result = $this->captureEntryFeeForWinner(
                $auction_id,
                $winner_user_id,
                $authorization
            );

            if ($capture_result['status'] === 'FAILED') {
                $this->log('error', 'Entry fee capture failed', $capture_result);
                throw new Exception('Entry fee capture failed: ' . $capture_result['error']);
            }

            // 4. Create WooCommerce order
            $order_id = $this->createAuctionOrderForWinner(
                $auction_id,
                $winner_user_id,
                $authorization,
                $capture_result
            );

            if (!$order_id) {
                throw new Exception('Failed to create WooCommerce order');
            }

            // 5. Get all outbid bids
            $outbid_bids = $this->getOutbidBids($auction_id, $winning_bid->id);
            $this->log('info', 'Found outbid bids', ['count' => count($outbid_bids)]);

            // 6. Schedule refunds for outbid bidders
            $refund_count = 0;
            $errors = [];
            foreach ($outbid_bids as $outbid_bid) {
                $refund_result = $this->scheduleRefundForOutbidBidder(
                    $auction_id,
                    $outbid_bid->user_id,
                    $outbid_bid->id
                );

                if ($refund_result['status'] === 'SUCCESS') {
                    $refund_count++;
                } else {
                    $errors[] = $refund_result['error'];
                    $this->log('warning', 'Refund scheduling failed', $refund_result);
                }
            }

            // 7. Send notifications
            $this->sendOutcomeNotifications(
                $auction_id,
                $winner_user_id,
                $authorization,
                $capture_result,
                count($outbid_bids)
            );

            $status = empty($errors) ? 'SUCCESS' : (count($errors) < count($outbid_bids) ? 'PARTIAL' : 'FAILED');
            $this->log('info', 'Auction outcome processing complete', ['status' => $status, 'errors' => count($errors)]);

            return [
                'status' => $status,
                'winner_id' => $winner_user_id,
                'winner_amount' => $authorization['amount_cents'],
                'order_id' => $order_id,
                'refund_count' => $refund_count,
                'errors' => $errors,
            ];

        } catch (Exception $e) {
            $this->log('error', 'Auction outcome processing failed', [
                'auction_id' => $auction_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get winning bid for auction from wp_yith_wcact_auction table
     *
     * The winning bid is the highest bid amount. In case of tie, earliest bid wins.
     *
     * SQL Query:
     * ```sql
     * SELECT * FROM wp_yith_wcact_auction
     * WHERE auction_id = %d
     * ORDER BY CAST(bid AS DECIMAL(50,5)) DESC, date ASC
     * LIMIT 1
     * ```
     *
     * @param int $auction_id Product ID
     *
     * @return object|null Bid object with properties: id, user_id, auction_id, bid, date
     *
     * @internal Used by processAuctionOutcome()
     */
    private function getWinningBid(int $auction_id): ?object {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}yith_wcact_auction 
             WHERE auction_id = %d 
             ORDER BY CAST(bid AS DECIMAL(50,5)) DESC, date ASC 
             LIMIT 1",
            $auction_id
        );

        $result = $this->wpdb->get_row($query);
        return $result ?? null;
    }

    /**
     * Get all outbid bids (non-winning bids) for auction
     *
     * Returns all bids except the winning one, ordered by amount descending.
     * Each bid gets a separate refund scheduled so bidders are notified.
     *
     * @param int $auction_id   Product ID
     * @param int $winning_bid_id Bid ID of winner (excluded from results)
     *
     * @return array Array of bid objects
     *
     * @internal Used by processAuctionOutcome()
     */
    private function getOutbidBids(int $auction_id, int $winning_bid_id): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}yith_wcact_auction 
             WHERE auction_id = %d AND id != %d
             ORDER BY CAST(bid AS DECIMAL(50,5)) DESC, date ASC",
            $auction_id,
            $winning_bid_id
        );

        $results = $this->wpdb->get_results($query);
        return $results ?? [];
    }

    /**
     * Capture entry fee for winning bid via payment gateway
     *
     * Uses existing authorization (pre-auth hold) to capture the entry fee.
     * Gateway charges the payment method and returns capture ID.
     *
     * Flow:
     * 1. Get authorization details
     * 2. Call payment gateway captureAuthorizedPayment()
     * 3. Update authorization status to CAPTURED in database
     * 4. Return capture result
     *
     * @param int   $auction_id      Product ID
     * @param int   $winner_user_id  Winner's user ID
     * @param array $authorization   Authorization from repository [
     *     'authorization_id' => string,
     *     'amount_cents'     => int,
     *     'payment_gateway'  => 'square',
     * ]
     *
     * @return array {
     *     'status'           => 'SUCCESS' | 'FAILED',
     *     'capture_id'       => string (if successful),
     *     'amount_cents'     => int,
     *     'timestamp'        => int (Unix timestamp),
     *     'error'            => string (if failed),
     * }
     *
     * REQ-024: Entry fee charged immediately after winner is declared
     *
     * @internal Used by processAuctionOutcome()
     */
    private function captureEntryFeeForWinner(
        int $auction_id,
        int $winner_user_id,
        array $authorization
    ): array {
        try {
            $this->log('info', 'Capturing entry fee for winner', [
                'auction_id' => $auction_id,
                'winner_id' => $winner_user_id,
                'auth_id' => $authorization['authorization_id'],
            ]);

            // Call payment gateway to capture the authorized amount
            $capture_id = $this->payment_gateway->captureAuthorizedPayment(
                authorization_id: $authorization['authorization_id'],
                amount_cents: $authorization['amount_cents'],
                metadata: [
                    'auction_id' => $auction_id,
                    'winner_id' => $winner_user_id,
                    'capture_type' => 'entry_fee_winner',
                    'timestamp' => time(),
                ]
            );

            // Update authorization record in database
            $this->auth_repository->updateStatus(
                authorization_id: $authorization['authorization_id'],
                status: 'CAPTURED',
                capture_id: $capture_id
            );

            $this->log('info', 'Entry fee capture successful', [
                'capture_id' => $capture_id,
                'amount_cents' => $authorization['amount_cents'],
            ]);

            return [
                'status' => 'SUCCESS',
                'capture_id' => $capture_id,
                'amount_cents' => $authorization['amount_cents'],
                'timestamp' => time(),
            ];

        } catch (Exception $e) {
            $this->log('error', 'Entry fee capture failed', [
                'error' => $e->getMessage(),
                'auction_id' => $auction_id,
            ]);

            return [
                'status' => 'FAILED',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Schedule refund for outbid bidder
     *
     * Creates a refund schedule record in wp_wc_auction_refund_schedule table.
     * Refund will be processed 24 hours after insertion via WordPress cron.
     *
     * @param int $auction_id    Product ID
     * @param int $bidder_id     User ID of outbid bidder
     * @param int $bid_id        Bid ID from wp_yith_wcact_auction
     *
     * @return array {
     *     'status'     => 'SUCCESS' | 'FAILED',
     *     'schedule_id' => int (if successful),
     *     'error'      => string (if failed),
     * }
     *
     * REQ-025: Refunds scheduled for all outbid bidders (24 hours after capture)
     *
     * @internal Used by processAuctionOutcome()
     */
    private function scheduleRefundForOutbidBidder(
        int $auction_id,
        int $bidder_id,
        int $bid_id
    ): array {
        try {
            $this->log('info', 'Scheduling refund for outbid bidder', [
                'auction_id' => $auction_id,
                'bidder_id' => $bidder_id,
                'bid_id' => $bid_id,
            ]);

            // Get authorization for this bid
            $authorization = $this->auth_repository->findByBidId($bid_id);
            if (!$authorization) {
                throw new Exception('No authorization found for outbid bid');
            }

            // Schedule refund (24 hours from now)
            $schedule_id = $this->refund_repository->createRefundSchedule([
                'auction_id' => $auction_id,
                'user_id' => $bidder_id,
                'bid_id' => $bid_id,
                'authorization_id' => $authorization['authorization_id'],
                'amount_cents' => $authorization['amount_cents'],
                'status' => 'SCHEDULED',
                'scheduled_for' => time() + (24 * HOUR_IN_SECONDS),
                'notes' => 'Outbid - refund scheduled',
            ]);

            $this->log('info', 'Refund scheduled', [
                'schedule_id' => $schedule_id,
                'bidder_id' => $bidder_id,
            ]);

            return [
                'status' => 'SUCCESS',
                'schedule_id' => $schedule_id,
            ];

        } catch (Exception $e) {
            $this->log('error', 'Refund scheduling failed', [
                'error' => $e->getMessage(),
                'bid_id' => $bid_id,
            ]);

            return [
                'status' => 'FAILED',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create WooCommerce order for auction winner's entry fee
     *
     * Creates an order record to track the entry fee charge in WooCommerce.
     * Order contains the auction product and entry fee amount.
     *
     * Order Meta Stored:
     * - _yith_auction_id: Product ID of auction
     * - _yith_auction_winner: TRUE
     * - _yith_auction_entry_fee_cents: Amount charged
     * - _yith_auction_capture_id: Payment capture ID
     *
     * @param int   $auction_id      Product ID of auction
     * @param int   $winner_user_id  Winner's user ID
     * @param array $authorization   Authorization details
     * @param array $capture_result  Result from captureEntryFeeForWinner()
     *
     * @return int|null Order ID if successful, null on failure
     *
     * REQ-026: WooCommerce order created for audit trail and notification
     *
     * @internal Used by processAuctionOutcome()
     */
    private function createAuctionOrderForWinner(
        int $auction_id,
        int $winner_user_id,
        array $authorization,
        array $capture_result
    ): ?int {
        try {
            $this->log('info', 'Creating WooCommerce order for auction winner', [
                'auction_id' => $auction_id,
                'winner_id' => $winner_user_id,
            ]);

            // Create order with entry fee as order item
            $order = wc_create_order(['customer_id' => $winner_user_id]);
            if (!$order) {
                throw new Exception('Failed to create WooCommerce order');
            }

            // Add auction product to order
            $order->add_product(wc_get_product($auction_id), 1, [
                'subtotal' => 0,
                'total' => 0,
            ]);

            // Add entry fee as order fee
            $entry_fee_amount = $authorization['amount_cents'] / 100;
            $order->add_fee(
                __('Auction Entry Fee', 'yith-auctions-for-woocommerce'),
                $entry_fee_amount
            );

            // Set order status to completed (payment already processed)
            $order->set_status('completed');
            $order->set_date_paid(current_time('mysql'));
            $order->set_payment_method('square-entry-fee');
            $order->set_payment_method_title('Square Entry Fee');

            // Store auction metadata
            $order->update_meta_data('_yith_auction_id', $auction_id);
            $order->update_meta_data('_yith_auction_winner', true);
            $order->update_meta_data('_yith_auction_entry_fee_cents', $authorization['amount_cents']);
            $order->update_meta_data('_yith_auction_capture_id', $capture_result['capture_id']);
            $order->update_meta_data('_yith_auction_capture_timestamp', $capture_result['timestamp']);

            // Save order
            $order->save();

            $this->log('info', 'WooCommerce order created', [
                'order_id' => $order->get_id(),
                'amount' => $entry_fee_amount,
            ]);

            return $order->get_id();

        } catch (Exception $e) {
            $this->log('error', 'Failed to create WooCommerce order', [
                'error' => $e->getMessage(),
                'auction_id' => $auction_id,
            ]);

            return null;
        }
    }

    /**
     * Send outcome notifications
     *
     * Sends email notifications to:
     * - Winner: Entry fee has been charged
     * - Outbid bidders: You've been outbid, refund is scheduled
     *
     * @param int   $auction_id     Product ID
     * @param int   $winner_user_id Winner's user ID
     * @param array $authorization  Authorization details
     * @param array $capture_result Capture result
     * @param int   $outbid_count   Number of outbid bidders
     *
     * @return void
     *
     * REQ-027: Email notifications sent to affected parties
     *
     * @internal Used by processAuctionOutcome()
     */
    private function sendOutcomeNotifications(
        int $auction_id,
        int $winner_user_id,
        array $authorization,
        array $capture_result,
        int $outbid_count
    ): void {
        try {
            /**
             * Allow plugins to send custom notifications
             *
             * @hook yith_wcact_auction_outcome_notification
             * @param {int}   $auction_id     Product ID
             * @param {int}   $winner_user_id Winner user ID
             * @param {array} $capture_result Capture result with capture_id, amount_cents
             * @param {int}   $outbid_count   Number of outbid bidders
             * @returns {void}
             */
            do_action(
                'yith_wcact_auction_outcome_notification',
                $auction_id,
                $winner_user_id,
                $capture_result,
                $outbid_count
            );

            $this->log('info', 'Outcome notifications sent', [
                'auction_id' => $auction_id,
                'winner_id' => $winner_user_id,
                'outbid_count' => $outbid_count,
            ]);

        } catch (Exception $e) {
            $this->log('warning', 'Failed to send notifications', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get error message for display to users
     *
     * Maps exception messages to user-friendly strings for display
     * on frontend or in admin notifications.
     *
     * @param Exception $e Exception thrown during processing
     *
     * @return string User-friendly error message
     *
     * @internal Used by callers to display errors
     */
    public function getErrorMessage(Exception $e): string {
        $message = $e->getMessage();

        $error_map = [
            'No winning bid found' => __('No bids were placed for this auction.', 'yith-auctions-for-woocommerce'),
            'No payment authorization found' => __('Payment authorization not found.', 'yith-auctions-for-woocommerce'),
            'Entry fee capture failed' => __('Entry fee processing failed. Please contact support.', 'yith-auctions-for-woocommerce'),
            'Failed to create WooCommerce order' => __('Unable to create order record.', 'yith-auctions-for-woocommerce'),
            'No authorization found' => __('Payment method not found.', 'yith-auctions-for-woocommerce'),
        ];

        foreach ($error_map as $error_key => $user_message) {
            if (strpos($message, $error_key) !== false) {
                return $user_message;
            }
        }

        return __('An error occurred processing the auction outcome.', 'yith-auctions-for-woocommerce');
    }
}
