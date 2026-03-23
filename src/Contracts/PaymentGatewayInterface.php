<?php

namespace Yith\Auctions\Contracts;

use Yith\Auctions\ValueObjects\Money;

/**
 * PaymentGatewayInterface - Contract for payment processors.
 *
 * Defines the interface for payment gateway implementations (Stripe, PayPal, Square, etc).
 * Supports pre-authorization holds for auction entry fees without immediate charging.
 *
 * Design Pattern: Strategy Pattern
 * Each provider (Stripe, Square) implements this interface independently.
 * The service layer depends on this interface, not concrete implementations.
 *
 * Pre-Authorization Flow:
 * 1. createPaymentMethod() - Store card token (PCI compliant, no card data stored)
 * 2. authorizePayment() - Place hold (verify card, reserve funds)
 * 3. capturePayment() - Charge the held amount (for winner)
 * 4. refundPayment() - Return held/charged funds (for outbid)
 *
 * @package Yith\Auctions\Contracts
 * @requirement REQ-ENTRY-FEE-PAYMENT-001: Payment gateway abstraction
 * @requirement REQ-ENTRY-FEE-VALIDATION-001: Card validation
 *
 * Architecture Diagram:
 *
 * PaymentGatewayInterface (contract)
 *     ├─ SquarePaymentGateway (implementation)
 *     ├─ StripePaymentGateway (future)
 *     └─ PayPalPaymentGateway (future)
 *
 * PaymentGatewayService
 *     ├─ uses: PaymentGatewayInterface
 *     ├─ creates: PaymentAuthorization records
 *     ├─ throws: PaymentException, ValidationException
 *     └─ returns: PaymentResult (with auth_id, hold_token, etc)
 */
interface PaymentGatewayInterface
{
    /**
     * Store payment method for future use.
     *
     * Tokenizes card or payment method without storing sensitive data.
     * Token is used for future authorizations and charges.
     *
     * @param array $payment_method Payment details:
     *     [
     *         'card_number' => '4111111111111111',
     *         'exp_month' => 12,
     *         'exp_year' => 2026,
     *         'cvc' => '123',
     *         'cardholder_name' => 'John Doe',
     *         'billing_email' => 'john@example.com'
     *     ]
     *
     * @return array Result with keys:
     *     - token: string (secure token for future use)
     *     - last_four: string (last 4 digits for display)
     *     - brand: string (Visa, Mastercard, Amex)
     *     - exp_month: int
     *     - exp_year: int
     *
     * @throws PaymentException If tokenization fails
     * @throws ValidationException If card details invalid
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Create payment method
     */
    public function createPaymentMethod(array $payment_method): array;

    /**
     * Authorize payment (place hold on funds).
     *
     * Places a pre-authorization hold on the customer's card without charging.
     * This verifies the card and reserves funds for later capture (if bid wins)
     * or refund (if bid is outbid).
     *
     * Pre-authorizations typically expire in 7-30 days depending on processor.
     * Must call capturePayment() or refundPayment() before expiration.
     *
     * @param string $payment_token Token from createPaymentMethod()
     * @param Money  $amount        Entry fee amount to hold
     * @param array  $context       Additional context:
     *     [
     *         'auction_id' => 123,
     *         'user_id' => 456,
     *         'bid_id' => 'bid-uuid',
     *         'description' => 'Entry fee for Auction #123',
     *         'customer_email' => 'user@example.com'
     *     ]
     *
     * @return array Authorization result:
     *     [
     *         'auth_id' => string (authorization ID for capture/refund),
     *         'hold_amount' => Money (amount held),
     *         'hold_token' => string (token for capture/refund),
     *         'status' => 'AUTHORIZED' (pending capture),
     *         'expires_at' => DateTime,
     *         'raw_response' => array (provider-specific data)
     *     ]
     *
     * @throws PaymentException If authorization fails
     * @throws InsufficientFundsException If card declined
     * @throws CardExpiredException If card expired
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Authorize payment hold
     */
    public function authorizePayment(string $payment_token, Money $amount, array $context = []): array;

    /**
     * Capture (charge) a previously authorized payment.
     *
     * Completes a payment that was previously authorized. The held amount
     * is now charged to the customer's card. Used when bid wins and entry
     * fee must be collected.
     *
     * Amount may be less than or equal to authorized amount (partial capture).
     *
     * @param string $auth_id     Authorization ID from authorizePayment()
     * @param Money  $amount      Amount to charge (must be <= authorized amount)
     * @param array  $context     Additional context (optional)
     *
     * @return array Capture result:
     *     [
     *         'capture_id' => string,
     *         'charged_amount' => Money,
     *         'status' => 'CAPTURED',
     *         'charge_timestamp' => DateTime,
     *         'raw_response' => array
     *     ]
     *
     * @throws PaymentException If capture fails
     * @throws AuthorizationExpiredException If hold expired
     * @throws AmountMismatchException If amount > authorized
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Charge held amount
     */
    public function capturePayment(string $auth_id, Money $amount, array $context = []): array;

    /**
     * Refund a payment (full or partial).
     *
     * Releases a pre-authorization hold OR refunds a captured charge.
     * Used when bid is outbid (release hold) or to refund charge later.
     *
     * @param string $auth_id     Authorization ID or Capture ID to refund
     * @param Money  $amount      Amount to refund (null = full amount)
     * @param array  $context     Additional context:
     *     [
     *         'reason' => 'Bid outbid',
     *         'refund_note' => 'Customer requested',
     *         'auction_id' => 123
     *     ]
     *
     * @return array Refund result:
     *     [
     *         'refund_id' => string,
     *         'refunded_amount' => Money,
     *         'status' => 'REFUNDED',
     *         'refund_timestamp' => DateTime,
     *         'raw_response' => array
     *     ]
     *
     * @throws PaymentException If refund fails
     * @throws AuthorizationExpiredException If hold expired before refund
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Release holds and refunds
     */
    public function refundPayment(string $auth_id, ?Money $amount = null, array $context = []): array;

    /**
     * Verify payment method (validate card without charging).
     *
     * Verifies that a payment method is valid and can be used for transactions.
     * Often a small ($0.01-$1) charge is used to verify, then immediately refunded.
     *
     * @param string $payment_token Token from createPaymentMethod()
     * @param array  $context       Additional context
     *
     * @return array Verification result:
     *     [
     *         'valid' => bool,
     *         'last_four' => string,
     *         'brand' => string,
     *         'message' => string (error message if invalid)
     *     ]
     *
     * @throws PaymentException If verification fails
     * @throws ValidationException If token invalid
     *
     * @requirement REQ-ENTRY-FEE-VALIDATION-001: Card verification
     */
    public function verifyPaymentMethod(string $payment_token, array $context = []): array;

    /**
     * Get payment method details.
     *
     * Retrieves stored details about a payment method (for display/confirmation).
     * Does NOT return sensitive data (card number, CVC, etc).
     *
     * @param string $payment_token Token from createPaymentMethod()
     *
     * @return array Payment method details:
     *     [
     *         'last_four' => '1111',
     *         'brand' => 'Visa',
     *         'exp_month' => 12,
     *         'exp_year' => 2026,
     *         'cardholder_name' => 'John Doe'
     *     ]
     *
     * @throws PaymentException If retrieval fails
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Payment method retrieval
     */
    public function getPaymentMethodDetails(string $payment_token): array;

    /**
     * Get provider name (for logging, identification).
     *
     * @return string Provider name (e.g. 'square', 'stripe')
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001: Provider identification
     */
    public function getProviderName(): string;
}
