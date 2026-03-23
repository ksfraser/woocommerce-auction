<?php

namespace Yith\Auctions\Services;

use Yith\Auctions\Contracts\PaymentGatewayInterface;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;

/**
 * BidPaymentIntegration - Integrates entry fee payment with bid placement.
 *
 * Responsibilities:
 * - Authorize entry fee when bid is submitted
 * - Store authorization_id with bid record
 * - Handle payment failures with clear error messages
 * - Integrate with existing bid submission flow
 *
 * Integration Points:
 * - Before bid creation in YITH_WCACT_Auction_Ajax::yith_wcact_add_bid()
 * - Payment must succeed before bid is stored
 * - Authorization holds funds until auction outcome (capture or release)
 *
 * Architecture:
 *
 * ```
 * BID SUBMISSION
 *     │
 *     ├─> Existing Validation (bid amount, auction status)
 *     │
 *     ├─> NEW: BidPaymentIntegration::authorizePaymentForBid()
 *     │   ├─> Get bidder's payment method
 *     │   ├─> Calculate entry fee
 *     │   ├─> Call payment gateway
 *     │   └─> Return authorization_id or PaymentException
 *     │
 *     └─> If authorized:
 *         ├─> Store bid WITH authorization_id
 *         └─> Return success to frontend
 *         
 *         If payment fails:
 *         ├─> Don't store bid
 *         └─> Return error to frontend
 * ```
 *
 * Database Connections:
 * - Reads: wp_yith_wcact_auction (bids)
 * - Reads: wp_wc_auction_payment_methods (bidder's token)
 * - Writes: wp_wc_auction_payment_authorizations (authorization record)
 *
 * @package Yith\Auctions\Services
 * @requirement REQ-ENTRY-FEE-PAYMENT-002: Bid-linked payment authorization
 */
class BidPaymentIntegration
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var PaymentGatewayInterface Payment processor (Square, Stripe, etc)
     */
    private PaymentGatewayInterface $payment_gateway;

    /**
     * @var PaymentAuthorizationRepository Authorization persistence layer
     */
    private PaymentAuthorizationRepository $auth_repository;

    /**
     * @var CommissionCalculator Entry fee calculator
     */
    private CommissionCalculator $fee_calculator;

    /**
     * Initialize payment integration with gateway and repository.
     *
     * @param PaymentGatewayInterface        $gateway    Payment processor
     * @param PaymentAuthorizationRepository $repository Authorization storage
     * @param CommissionCalculator           $calculator Entry fee calculator
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function __construct(
        PaymentGatewayInterface $gateway,
        PaymentAuthorizationRepository $repository,
        CommissionCalculator $calculator = null
    ) {
        $this->payment_gateway = $gateway;
        $this->auth_repository = $repository;
        $this->fee_calculator = $calculator ?? new CommissionCalculator();
    }

    /**
     * Authorize entry fee payment for bid placement.
     *
     * Call this BEFORE creating the bid record. If authorization succeeds,
     * return the authorization_id to be stored with the bid. If payment fails,
     * throw PaymentException to abort bid creation.
     *
     * Flow:
     * 1. Verify entry fees are enabled for auction product
     * 2. Get bidder's payment method (or error if none)
     * 3. Calculate entry fee from bid amount
     * 4. Call payment gateway to place pre-auth hold
     * 5. Store authorization in database
     * 6. Return authorization_id for bid linkage
     *
     * @param int    $auction_id      Product/auction post ID
     * @param int    $bidder_id       User ID of bidder
     * @param float  $bid_amount      Bid amount in USD (used to calculate fee)
     * @param string $bid_id          Unique bid identifier (uuid or slug)
     *
     * @return array Authorization data
     *     [
     *         'authorization_id' => string,   // Gateway auth ID
     *         'amount_cents' => int,          // Fee in cents
     *         'expires_at' => string,         // Datetime when hold expires
     *         'status' => 'AUTHORIZED',
     *         'bid_id' => string,             // Passed bid identifier
     *     ]
     *
     * @throws PaymentException                 On payment failure (card declined, etc)
     * @throws \InvalidArgumentException        On invalid input
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function authorizePaymentForBid(
        int $auction_id,
        int $bidder_id,
        float $bid_amount,
        string $bid_id
    ): array {
        // 1. VERIFY ENTRY FEES ENABLED
        $is_enabled = $this->isEntryFeeEnabled($auction_id);
        if (!$is_enabled) {
            $this->logInfo('Entry fees not enabled for auction', [
                'auction_id' => $auction_id,
            ]);

            // Return empty auth (no payment required)
            return [
                'authorization_id' => '',
                'amount_cents' => 0,
                'expires_at' => null,
                'status' => 'SKIPPED',
                'bid_id' => $bid_id,
            ];
        }

        try {
            // 2. RETRIEVE BIDDER'S PAYMENT METHOD
            $payment_method = $this->getBidderPaymentMethod($bidder_id);
            if (empty($payment_method)) {
                throw new PaymentException(
                    'No payment method found. Please add a payment method before bidding.',
                    'NO_PAYMENT_METHOD'
                );
            }

            // 3. CALCULATE ENTRY FEE
            $entry_fee_cents = $this->fee_calculator->calculateEntryFee(
                (int) ($bid_amount * 100) // Convert to cents
            );

            if ($entry_fee_cents <= 0) {
                throw new \InvalidArgumentException('Invalid entry fee amount');
            }

            // 4. AUTHORIZE PAYMENT (place pre-auth hold)
            $auth_id = $this->payment_gateway->authorizePayment(
                payment_method_id: $payment_method['token'],
                amount: new Money($entry_fee_cents),
                metadata: [
                    'bid_id' => $bid_id,
                    'auction_id' => $auction_id,
                    'bidder_id' => $bidder_id,
                    'bid_amount' => (float) $bid_amount,
                    'idempotency_key' => $this->generateIdempotencyKey($bidder_id, $auction_id, $bid_id),
                ]
            );

            // 5. STORE AUTHORIZATION IN DATABASE
            $auth_record = $this->auth_repository->recordAuthorization([
                'auction_id' => $auction_id,
                'user_id' => $bidder_id,
                'bid_id' => $bid_id,
                'authorization_id' => $auth_id,
                'payment_gateway' => $this->payment_gateway->getProviderName(),
                'amount_cents' => $entry_fee_cents,
                'status' => 'AUTHORIZED',
            ]);

            // 6. LOG SUCCESS
            $this->logInfo('Entry fee authorized for bid', [
                'auction_id' => $auction_id,
                'bidder_id' => $bidder_id,
                'bid_id' => $bid_id,
                'authorization_id' => $auth_id,
                'amount_cents' => $entry_fee_cents,
            ]);

            // 7. RETURN AUTHORIZATION DATA FOR BID LINKAGE
            return [
                'authorization_id' => $auth_id,
                'amount_cents' => $entry_fee_cents,
                'expires_at' => $auth_record['expires_at'] ?? null,
                'status' => 'AUTHORIZED',
                'bid_id' => $bid_id,
            ];
        } catch (PaymentException $e) {
            // Payment failed - log and re-throw
            $this->logWarning('Entry fee authorization failed', [
                'auction_id' => $auction_id,
                'bidder_id' => $bidder_id,
                'error_code' => $e->getErrorCode(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Exception $e) {
            // Unexpected error - log and convert to payment exception
            $this->logError('Unexpected error during entry fee authorization', [
                'auction_id' => $auction_id,
                'bidder_id' => $bidder_id,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentException(
                'An error occurred while processing your payment. Please try again.',
                'PAYMENT_PROCESSING_ERROR'
            );
        }
    }

    /**
     * Get user-facing error message for payment exception.
     *
     * Converts payment error codes to customer-friendly messages.
     *
     * @param PaymentException $exception Payment error
     *
     * @return string User-facing error message
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function getErrorMessage(PaymentException $exception): string
    {
        $error_code = $exception->getErrorCode();
        $error_map = [
            'CARD_DECLINED' => 'Your card was declined. Please check your card details and try again.',
            'CARD_EXPIRED' => 'Your card has expired. Please use a different card.',
            'INVALID_CVC' => 'Invalid security code (CVC). Please check and try again.',
            'INVALID_EXPIRATION' => 'Your card expiration date is invalid.',
            'INVALID_CARD_NUMBER' => 'Your card number is invalid. Please check and try again.',
            'INSUFFICIENT_FUNDS' => 'Insufficient funds on your card. Please use a different card.',
            'NETWORK_ERROR' => 'Payment service temporarily unavailable. Please try again in a moment.',
            'RATE_LIMIT' => 'Too many attempts. Please wait a moment and try again.',
            'NO_PAYMENT_METHOD' => 'You haven\'t saved a payment method yet. Please add one before bidding.',
            'PAYMENT_PROCESSING_ERROR' => 'An error occurred while processing your payment. Please try again.',
        ];

        return $error_map[$error_code] ?? 'Payment failed. Please try again or contact support.';
    }

    /**
     * Check if entry fees are enabled for auction.
     *
     * @param int $auction_id Product/auction post ID
     *
     * @return bool True if entry fees enabled
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    private function isEntryFeeEnabled(int $auction_id): bool
    {
        // Get product
        $product = wc_get_product($auction_id);
        if (!$product) {
            return false;
        }

        // Check if entry fees enabled
        $enabled = $product->get_meta('_auction_entry_fee_enable', true);
        return (bool) $enabled;
    }

    /**
     * Get bidder's payment method (token from previous storage).
     *
     * Retrieves the most recent payment method stored for the user.
     *
     * @param int $bidder_id User ID
     *
     * @return array|null Payment method data
     *     [
     *         'token' => string,     // Gateway token (not raw card)
     *         'brand' => string,     // Visa, Mastercard, etc
     *         'last_four' => string, // Last 4 digits
     *     ]
     *     or null if no payment method
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    private function getBidderPaymentMethod(int $bidder_id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wc_auction_payment_methods';

        $method = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payment_token, card_brand, card_last_four
                 FROM {$table}
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT 1",
                $bidder_id
            )
        );

        if (!$method) {
            return null;
        }

        return [
            'token' => $method->payment_token,
            'brand' => $method->card_brand,
            'last_four' => $method->card_last_four,
        ];
    }

    /**
     * Generate idempotency key for payment authorization.
     *
     * Prevents duplicate charges on retry (payment gateway requirement).
     * Key is deterministic based on inputs (same inputs = same key).
     *
     * @param int    $bidder_id  User ID
     * @param int    $auction_id Auction ID
     * @param string $bid_id     Bid identifier
     *
     * @return string Idempotency key (deterministic hash)
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    private function generateIdempotencyKey(int $bidder_id, int $auction_id, string $bid_id): string
    {
        return hash('sha256', sprintf(
            'bid_auth_%d_%d_%s',
            $bidder_id,
            $auction_id,
            $bid_id
        ));
    }
}
