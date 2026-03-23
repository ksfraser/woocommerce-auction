<?php

namespace Yith\Auctions\Services\EntryFees;

use Yith\Auctions\Contracts\PaymentGatewayInterface;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Traits\LoggerTrait;
use Yith\Auctions\Traits\ValidationTrait;
use Yith\Auctions\Exceptions\PaymentException;
use Yith\Auctions\Exceptions\ValidationException;

/**
 * EntryFeePaymentService - Orchestrates entry fee payment workflow.
 *
 * Responsible for:
 * - Storing payment methods for bidders
 * - Authorizing entry fee charges (placing holds)
 * - Capturing charges when bid wins
 * - Refunding when bid is outbid
 * - Scheduling refunds with delay window
 * - Managing payment state transitions
 *
 * Entry Fee Payment Lifecycle:
 *
 * 1. PAYMENT_METHOD_SUBMISSION
 *    └─ validatePaymentMethod() → createPaymentMethod() → store token
 *
 * 2. BID PLACEMENT (charge hold)
 *    └─ authorizeEntryFee() → creates AUTHORIZED payment
 *
 * 3. AUCTION RESOLUTION
 *    ├─ For WINNER:
 *    │  └─ captureEntryFee() → captures hold, status=CAPTURED
 *    └─ For OUTBID/NON-WINNER:
 *       └─ scheduleRefund() → schedules for 24h delay, status=REFUND_PENDING
 *
 * 4. REFUND PROCESSING (24h later)
 *    └─ processScheduledRefund() → refunds held amount, status=REFUNDED
 *
 * Payment States:
 * - PENDING: Payment method submitted, not yet used
 * - AUTHORIZED: Hold placed on card (waiting for bid outcome)
 * - CAPTURED: Hold charged (bid won)
 * - REFUND_PENDING: Scheduled for refund (24h delay window)
 * - REFUNDED: Hold released or charge refunded
 * - FAILED: Authorization/capture failed
 *
 * Database Tables Used:
 * - wp_wc_auction_payment_methods: Store payment tokens
 * - wp_wc_auction_payment_authorizations: Track holds/charges
 * - wp_wc_auction_refund_schedule: Queue refunds with delays
 *
 * @package Yith\Auctions\Services\EntryFees
 * @requirement REQ-ENTRY-FEE-PAYMENT-001: Payment orchestration
 * @requirement REQ-ENTRY-FEE-COMMISSION-001: Entry fee collection
 *
 * Architecture:
 *
 * EntryFeePaymentService
 *     ├─ uses: PaymentGatewayInterface (pluggable payment processor)
 *     ├─ uses: PaymentAuthorizationRepository (stores holds/charges)
 *     ├─ uses: CommissionCalculator (fee amounts)
 *     ├─ uses: LoggerTrait, ValidationTrait
 *     ├─ throws: PaymentException, ValidationException
 *     └─ coordinates:
 *        ├─ User submits bid → validate payment → authorize hold
 *        ├─ Auction ends → capture (win) or schedule refund (outbid)
 *        └─ 24h later → process refund
 */
class EntryFeePaymentService
{
    use LoggerTrait;
    use ValidationTrait;

    /**
     * @var PaymentGatewayInterface Payment processor (Stripe, Square, PayPal)
     */
    private PaymentGatewayInterface $payment_gateway;

    /**
     * @var PaymentAuthorizationRepository Repository for payment tracking
     */
    private PaymentAuthorizationRepository $repository;

    /**
     * @var CommissionCalculator Fee calculation engine
     */
    private CommissionCalculator $fee_calculator;

    /**
     * @var int Entry fee hold duration in days
     */
    private const HOLD_DURATION_DAYS = 7;

    /**
     * @var int Refund delay (dispute window) in hours
     */
    private const REFUND_DELAY_HOURS = 24;

    /**
     * Initialize entry fee payment service.
     *
     * @param PaymentGatewayInterface        $gateway    Payment processor
     * @param PaymentAuthorizationRepository $repository Payment tracking
     * @param CommissionCalculator           $calculator Fee calculator
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function __construct(
        PaymentGatewayInterface $gateway,
        PaymentAuthorizationRepository $repository,
        CommissionCalculator $calculator
    ) {
        $this->payment_gateway = $gateway;
        $this->repository = $repository;
        $this->fee_calculator = $calculator;
    }

    /**
     * Store bidder's payment method for future use.
     *
     * Validates and tokenizes card without storing sensitive data.
     * Token is used for all entry fee charges for this bidder.
     *
     * @param int   $user_id       WordPress user ID
     * @param array $card_details  Card information:
     *     [
     *         'card_number' => '4111...',
     *         'exp_month' => 12,
     *         'exp_year' => 2026,
     *         'cvc' => '123',
     *         'cardholder_name' => 'John Doe',
     *         'billing_email' => 'john@example.com'
     *     ]
     *
     * @return array Result with keys:
     *     - token: Secure token for future charges
     *     - last_four: Last 4 digits for display
     *     - brand: Card brand (Visa, Mastercard, etc)
     *
     * @throws ValidationException If card details invalid
     * @throws PaymentException If tokenization fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Store payment method
     */
    public function storePaymentMethod(int $user_id, array $card_details): array
    {
        if ($user_id <= 0) {
            throw new ValidationException('Invalid user ID');
        }

        // Validate card details
        try {
            $this->validateCardDetails($card_details);
        } catch (\Exception $e) {
            $this->logWarning('Invalid card details provided', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
            ]);

            throw new ValidationException('Invalid card details: ' . $e->getMessage());
        }

        try {
            // Tokenize with payment gateway
            $token_result = $this->payment_gateway->createPaymentMethod($card_details);

            // Store in database
            $this->repository->storePaymentMethod(
                $user_id,
                $token_result['token'],
                $token_result['brand'],
                $token_result['last_four'],
                $token_result['exp_month'],
                $token_result['exp_year']
            );

            $this->logInfo('Payment method stored for bidder', [
                'user_id' => $user_id,
                'brand' => $token_result['brand'],
                'last_four' => $token_result['last_four'],
            ]);

            return [
                'token' => $token_result['token'],
                'last_four' => $token_result['last_four'],
                'brand' => $token_result['brand'],
                'success' => true,
            ];
        } catch (\Exception $e) {
            $this->logError('Failed to store payment method', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentException('Failed to store payment method: ' . $e->getMessage());
        }
    }

    /**
     * Authorize entry fee (place hold on bidder's card).
     *
     * Called when bid is placed. Places a hold on the bidder's card for the
     * entry fee amount. The hold remains until:
     * - Bid wins → amount captured (moved to real charge)
     * - Bid lost → amount refunded after 24h dispute window
     *
     * @param int    $auction_id       Auction post ID
     * @param int    $user_id          Bidder WordPress user ID
     * @param string $bid_id           Bid identifier (UUID)
     * @param string $payment_token    Payment method token from storePaymentMethod()
     * @param Money  $bid_amount       Bid amount (for calculating entry fee)
     * @param string $customer_email   Customer email for receipt
     *
     * @return array Authorization result:
     *     [
     *         'auth_id' => string (for later capture/refund),
     *         'entry_fee' => Money,
     *         'status' => 'AUTHORIZED',
     *         'expires_at' => DateTime,
     *         'authorization_record_id' => int (DB record ID)
     *     ]
     *
     * @throws ValidationException If inputs invalid
     * @throws PaymentException If authorization fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Authorize entry fee hold
     * @requirement REQ-ENTRY-FEE-COMMISSION-001: Calculate and collect entry fees
     */
    public function authorizeEntryFee(
        int $auction_id,
        int $user_id,
        string $bid_id,
        string $payment_token,
        Money $bid_amount,
        string $customer_email
    ): array {
        // Validate inputs
        if ($auction_id <= 0 || $user_id <= 0 || empty($bid_id)) {
            throw new ValidationException('Invalid auction, user, or bid ID');
        }

        try {
            // Calculate entry fee
            $entry_fee = $this->fee_calculator->calculateEntryFee($bid_amount->getFormatted());

            $this->logDebug('Authorizing entry fee', [
                'auction_id' => $auction_id,
                'user_id' => $user_id,
                'bid_amount' => $bid_amount->getFormatted(),
                'entry_fee' => $entry_fee->getFormatted(),
            ]);

            // Call payment gateway to place hold
            $auth_result = $this->payment_gateway->authorizePayment(
                $payment_token,
                $entry_fee,
                [
                    'auction_id' => $auction_id,
                    'user_id' => $user_id,
                    'bid_id' => $bid_id,
                    'description' => "Entry fee for Auction #{$auction_id}",
                    'customer_email' => $customer_email,
                ]
            );

            // Store authorization in database
            $record_id = $this->repository->recordAuthorization(
                auction_id: $auction_id,
                user_id: $user_id,
                bid_id: $bid_id,
                authorization_id: $auth_result['auth_id'],
                payment_gateway: $this->payment_gateway->getProviderName(),
                amount: $entry_fee,
                status: 'AUTHORIZED',
                metadata: [
                    'bid_amount' => $bid_amount->getFormatted(),
                    'expires_at' => $auth_result['expires_at']->format('Y-m-d H:i:s'),
                ]
            );

            $this->logInfo('Entry fee authorized', [
                'auction_id' => $auction_id,
                'bid_id' => $bid_id,
                'entry_fee' => $entry_fee->getFormatted(),
                'auth_id' => $auth_result['auth_id'],
            ]);

            return [
                'auth_id' => $auth_result['auth_id'],
                'entry_fee' => $entry_fee,
                'status' => 'AUTHORIZED',
                'expires_at' => $auth_result['expires_at'],
                'authorization_record_id' => $record_id,
            ];
        } catch (PaymentException $e) {
            $this->logError('Entry fee authorization failed', [
                'auction_id' => $auction_id,
                'user_id' => $user_id,
                'error' => $e->getMessage(),
            ]);

            // Store failed authorization for audit trail
            $this->repository->recordAuthorization(
                auction_id: $auction_id,
                user_id: $user_id,
                bid_id: $bid_id,
                authorization_id: 'FAILED-' . time(),
                payment_gateway: $this->payment_gateway->getProviderName(),
                amount: new Money(0),
                status: 'FAILED',
                metadata: ['error' => $e->getMessage()]
            );

            throw $e;
        }
    }

    /**
     * Capture entry fee (finalize charge for winning bid).
     *
     * Called when auction ends and bidder has won. Completes the hold,
     * moving it from pre-authorized to captured (actual charge).
     *
     * @param string $auth_id  Authorization ID from authorizeEntryFee()
     * @param Money  $amount   Amount to capture (should match authorized)
     *
     * @return array Capture result:
     *     [
     *         'capture_id' => string,
     *         'amount' => Money,
     *         'status' => 'CAPTURED',
     *         'charge_timestamp' => DateTime
     *     ]
     *
     * @throws PaymentException If capture fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Capture held amount
     * @requirement REQ-ENTRY-FEE-COMMISSION-001: Collect winning bid fees
     */
    public function captureEntryFee(string $auth_id, Money $amount): array
    {
        if (empty($auth_id) || $amount->getAmount() <= 0) {
            throw new ValidationException('Invalid auth ID or amount');
        }

        try {
            $capture_result = $this->payment_gateway->capturePayment($auth_id, $amount);

            // Update database
            $this->repository->updateAuthorizationStatus(
                $auth_id,
                'CAPTURED',
                ['charged_at' => $capture_result['charge_timestamp']->format('Y-m-d H:i:s')]
            );

            $this->logInfo('Entry fee captured (charged)', [
                'auth_id' => $auth_id,
                'amount' => $amount->getFormatted(),
            ]);

            return $capture_result;
        } catch (PaymentException $e) {
            $this->logError('Entry fee capture failed', [
                'auth_id' => $auth_id,
                'error' => $e->getMessage(),
            ]);

            $this->repository->updateAuthorizationStatus($auth_id, 'CAPTURE_FAILED');

            throw $e;
        }
    }

    /**
     * Schedule refund for outbid bidder.
     *
     * Called when auction ends and bidder is outbid. Schedules the held entry fee
     * for refund after a 24-hour dispute window (allows chargebacks to be assessed).
     *
     * @param string $auth_id  Authorization ID from authorizeEntryFee()
     * @param int    $user_id  Bidder user ID
     * @param string $reason   Refund reason (e.g., "Outbid in auction #123")
     *
     * @return array Schedule result:
     *     [
     *         'refund_id' => string,
     *         'scheduled_for' => DateTime (when refund will process),
     *         'status' => 'REFUND_PENDING'
     *     ]
     *
     * @throws PaymentException If scheduling fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Schedule refunds
     */
    public function scheduleRefund(string $auth_id, int $user_id, string $reason): array
    {
        if (empty($auth_id) || $user_id <= 0) {
            throw new ValidationException('Invalid auth ID or user ID');
        }

        try {
            // Calculate when refund should process (24h from now)
            $scheduled_for = new \DateTime();
            $scheduled_for->modify('+' . self::REFUND_DELAY_HOURS . ' hours');

            // Store in refund schedule
            $refund_id = $this->repository->scheduleRefund(
                auth_id: $auth_id,
                user_id: $user_id,
                scheduled_for: $scheduled_for,
                reason: $reason
            );

            // Update authorization status
            $this->repository->updateAuthorizationStatus(
                $auth_id,
                'REFUND_PENDING',
                ['refund_id' => $refund_id]
            );

            $this->logInfo('Refund scheduled', [
                'auth_id' => $auth_id,
                'user_id' => $user_id,
                'scheduled_for' => $scheduled_for->format('Y-m-d H:i:s'),
                'reason' => $reason,
            ]);

            return [
                'refund_id' => $refund_id,
                'scheduled_for' => $scheduled_for,
                'status' => 'REFUND_PENDING',
            ];
        } catch (\Exception $e) {
            $this->logError('Failed to schedule refund', [
                'auth_id' => $auth_id,
                'error' => $e->getMessage(),
            ]);

            throw new PaymentException('Failed to schedule refund: ' . $e->getMessage());
        }
    }

    /**
     * Process scheduled refund (called by cron job after 24h delay).
     *
     * Processes a scheduled refund that has passed the dispute window.
     * Actually refunds the held amount back to the bidder's card.
     *
     * @param string $refund_id Refund ID from scheduleRefund()
     * @param string $auth_id   Authorization ID to refund
     * @param Money  $amount    Amount to refund
     *
     * @return array Refund result:
     *     [
     *         'refund_id' => string,
     *         'amount_refunded' => Money,
     *         'status' => 'REFUNDED',
     *         'refund_timestamp' => DateTime
     *     ]
     *
     * @throws PaymentException If refund fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Process refunds
     */
    public function processScheduledRefund(string $refund_id, string $auth_id, Money $amount): array
    {
        if (empty($refund_id) || empty($auth_id)) {
            throw new ValidationException('Invalid refund or auth ID');
        }

        try {
            // Call payment gateway to refund
            $refund_result = $this->payment_gateway->refundPayment(
                $auth_id,
                $amount,
                ['reason' => 'Auction entry fee refund']
            );

            // Update database
            $this->repository->updateAuthorizationStatus(
                $auth_id,
                'REFUNDED',
                ['refunded_at' => $refund_result['refund_timestamp']->format('Y-m-d H:i:s')]
            );

            $this->repository->updateRefundStatus(
                $refund_id,
                'REFUNDED',
                ['refund_timestamp' => $refund_result['refund_timestamp']->format('Y-m-d H:i:s')]
            );

            $this->logInfo('Scheduled refund processed', [
                'refund_id' => $refund_id,
                'auth_id' => $auth_id,
                'amount' => $amount->getFormatted(),
            ]);

            return [
                'refund_id' => $refund_id,
                'amount_refunded' => $amount,
                'status' => 'REFUNDED',
                'refund_timestamp' => $refund_result['refund_timestamp'],
            ];
        } catch (PaymentException $e) {
            $this->logError('Scheduled refund processing failed', [
                'refund_id' => $refund_id,
                'error' => $e->getMessage(),
            ]);

            $this->repository->updateRefundStatus($refund_id, 'REFUND_FAILED');

            throw $e;
        }
    }

    /**
     * Get payment authorizations for auction.
     *
     * Retrieve all payment holds/charges for an auction (for admin review).
     *
     * @param int $auction_id Auction ID
     *
     * @return array[] Array of authorization records
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Retrieve authorizations
     */
    public function getAuctionPayments(int $auction_id): array
    {
        return $this->repository->getAuthorizationsByAuction($auction_id);
    }

    /**
     * Get refund status for bidder.
     *
     * Check if refund is pending or completed for a bidder's bid.
     *
     * @param string $bid_id Bid ID
     *
     * @return array Refund status info
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Check refund status
     */
    public function getRefundStatus(string $bid_id): array
    {
        return $this->repository->getRefundByBid($bid_id) ?: [];
    }

    /**
     * Validate card details before tokenization.
     *
     * @param array $card_details Card data
     *
     * @throws ValidationException If validation fails
     *
     * @requirement REQ-ENTRY-FEE-VALIDATION-001: Card validation
     */
    private function validateCardDetails(array $card_details): void
    {
        $required = ['card_number', 'exp_month', 'exp_year', 'cvc', 'cardholder_name'];

        foreach ($required as $field) {
            if (empty($card_details[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        // Additional validation could go here (card number format, etc)
        if (strlen((string) $card_details['card_number']) < 13) {
            throw new ValidationException('Card number too short');
        }
    }
}
