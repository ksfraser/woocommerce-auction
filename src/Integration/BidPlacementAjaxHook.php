<?php

namespace Yith\Auctions\Integration;

use Yith\Auctions\Services\BidPaymentIntegration;
use Yith\Auctions\Exceptions\PaymentException;
use Yith\Auctions\Traits\LoggerTrait;

/**
 * BidPlacementAjaxHook - Injects payment authorization into bid submission AJAX.
 *
 * This class serves as an adapter between the existing YITH AJAX bid handler
 * and the new payment authorization system. It intercepts bid submission,
 * authorizes entry fee payment, and aborts bid creation on payment failure.
 *
 * Integration Points:
 * - Hooks: wp_ajax_yith_wcact_add_bid (before bid is stored)
 * - Passes authorization_id to bid storage function
 * - Handles payment errors with JSON responses to frontend
 *
 * Responsibilities:
 * - Listen for bid submission AJAX calls
 * - Authorize entry fee payment
 * - Store authorization_id with bid record
 * - Handle payment failures gracefully (return JSON error)
 * - Provide detailed logging for debugging
 *
 * Architecture:
 *
 * ```
 * EXISTING YITH BID HANDLER
 * ├─ Validate bid amount
 * ├─ Validate user/auction
 * ├─ Check bid eligibility
 * │
 * └─ INJECT: BidPlacementAjaxHook (NEW)
 *    ├─> Call payment authorization
 *    │   ├─> Get payment method
 *    │   ├─> Calculate entry fee
 *    │   ├─> Call payment gateway
 *    │   └─> Store authorization
 *    │
 *    ├─> If success:
 *    │   └─> Continue to bid storage (with auth_id)
 *    │
 *    └─> If failure:
 *        └─> Return JSON error to frontend
 * ```
 *
 * Usage in plugin initialization:
 *
 * ```php
 * add_action('plugins_loaded', function() {
 *     $hook = new BidPlacementAjaxHook($payment_integration);
 *     $hook->register();
 * });
 * ```
 *
 * Database Interactions:
 * - Reads: wp_wc_auction_payment_methods (payment tokens)
 * - Writes: wp_wc_auction_payment_authorizations (authorization records)
 * - Reads: wp_yith_wcact_auction (existing bids, for validation)
 * - Writes: wp_yith_wcact_auction (new bid with auth_id)
 *
 * @package Yith\Auctions\Integration
 * @requirement REQ-ENTRY-FEE-PAYMENT-003: Bid placement hook integration
 */
class BidPlacementAjaxHook
{
    use LoggerTrait;

    /**
     * @var BidPaymentIntegration Payment authorization service
     */
    private BidPaymentIntegration $payment_integration;

    /**
     * Initialize AJAX hook with payment integration service.
     *
     * @param BidPaymentIntegration $payment_integration Payment authorization layer
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function __construct(BidPaymentIntegration $payment_integration)
    {
        $this->payment_integration = $payment_integration;
    }

    /**
     * Register AJAX hook to intercept bid submission.
     *
     * Hooks into: wp_ajax_yith_wcact_add_bid
     * Priority: 9 (before default priority 10, so payment happens before bid storage)
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function register(): void
    {
        // Intercept BEFORE the existing handler (priority 9 < 10)
        add_filter(
            'yith_wcact_add_bid_data',
            [$this, 'authorizePaymentForBid'],
            9,
            4
        );
    }

    /**
     * Filter hook to authorize payment before bid is created.
     *
     * Called during: wp_ajax_yith_wcact_add_bid processing
     * After: All existing validation (bid amount, user eligibility, etc)
     * Before: Bid is stored in database
     *
     * If payment authorization fails, throw exception to abort bid creation.
     * If entry fees disabled, pass through authorization_id of empty string.
     *
     * @param array $bid_data Bid submission data
     *     [
     *         'user_id' => int,
     *         'product_id' => int,
     *         'bid_amount' => float,
     *         'date' => string (mysql datetime)
     *     ]
     * @param int   $user_id       Bidder user ID
     * @param int   $product_id    Auction product ID
     * @param float $bid_amount    Bid amount in USD
     *
     * @return array Enhanced bid data with authorization_id added
     *     [
     *         'user_id' => int,
     *         'product_id' => int,
     *         'bid_amount' => float,
     *         'date' => string,
     *         'authorization_id' => string,  // NEW
     *     ]
     *
     * @throws PaymentException On payment authorization failure
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function authorizePaymentForBid(array $bid_data, int $user_id, int $product_id, float $bid_amount): array
    {
        try {
            // Generate unique bid ID (used for idempotency and linkage)
            $bid_id = $this->generateBidId($user_id, $product_id);

            // 1. AUTHORIZE ENTRY FEE
            $auth_result = $this->payment_integration->authorizePaymentForBid(
                $product_id,
                $user_id,
                $bid_amount,
                $bid_id
            );

            // 2. ADD AUTHORIZATION_ID TO BID DATA
            $bid_data['authorization_id'] = $auth_result['authorization_id'];
            $bid_data['bid_id'] = $bid_id;

            $this->logInfo('Entry fee authorized for bid', [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'bid_amount' => $bid_amount,
                'authorization_id' => $auth_result['authorization_id'],
            ]);

            return $bid_data;
        } catch (PaymentException $e) {
            // Payment failed - log and re-throw for AJAX handler to catch
            $this->logWarning('Bid placement payment authorization failed', [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'bid_amount' => $bid_amount,
                'error_code' => $e->getErrorCode(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate unique bid identifier.
     *
     * Used for:
     * - Idempotency key generation (prevents duplicate charges on retry)
     * - Linking authorization to bid record
     * - Audit trail
     *
     * @param int $user_id    Bidder user ID
     * @param int $product_id Auction product ID
     *
     * @return string Unique bid ID (UUID-like format)
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    private function generateBidId(int $user_id, int $product_id): string
    {
        // Use WordPress UUID generation if available (WP 6.2+)
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        // Fallback: Generate deterministic ID from components
        return sprintf(
            'bid_%d_%d_%s',
            $user_id,
            $product_id,
            bin2hex(random_bytes(6))
        );
    }
}
