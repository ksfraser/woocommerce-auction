<?php
/**
 * Payment Processor Adapter Contract Interface
 *
 * Standardizes the contract for all payment processor implementations (Square, PayPal, Stripe).
 * Each processor adapter must implement this interface to be compatible with the settlement system.
 *
 * @package    YITH\Auctions\Contracts
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-1: Payment processor abstraction layer
 *
 * UML Interface Diagram:
 *
 *     ┌─────────────────────────────────────┐
 *     │  <<interface>>                      │
 *     │  IPaymentProcessorAdapter           │
 *     ├─────────────────────────────────────┤
 *     │ + initiatePayment(                  │
 *     │     transaction_id: string,         │
 *     │     amount_cents: int,              │
 *     │     recipient: SellerPayoutMethod   │
 *     │   ): TransactionResult              │
 *     │                                     │
 *     │ + getTransactionStatus(             │
 *     │     transaction_id: string          │
 *     │   ): TransactionResult              │
 *     │                                     │
 *     │ + refundTransaction(                │
 *     │     transaction_id: string,         │
 *     │     amount_cents: ?int              │
 *     │   ): TransactionResult              │
 *     │                                     │
 *     │ + getProcessorName(): string        │
 *     │                                     │
 *     │ + supportsMethod(                   │
 *     │     method_type: string             │
 *     │   ): bool                           │
 *     └─────────────────────────────────────┘
 *            ▲
 *            │ <<implements>>
 *            │
 *    ┌───────┴──────────┬──────────────────────┐
 *    │                  │                      │
 *    │                  │                      │
 *  Square           PayPal                  Stripe
 *  Adapter          Adapter                 Adapter
 *
 */

namespace WC\Auction\Contracts;

use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Models\TransactionResult;

/**
 * Interface IPaymentProcessorAdapter
 *
 * Contract for payment processor adapters. All payment processors (Square, PayPal, Stripe)
 * must implement this interface to integrate with the settlement and payout system.
 *
 * @since 4.0.0
 */
interface IPaymentProcessorAdapter
{
    /**
     * Initiates a payout transaction to a seller
     *
     * Atomically initiates a payout transaction. The method:
     * - Validates recipient details (banker account, email, etc.)
     * - Creates transaction in processor system
     * - Returns transaction ID for tracking
     * - Must be idempotent (safe to retry with same transaction_id)
     *
     * @param string                $transaction_id Unique idempotency key (format: batches_id-seller_id)
     * @param int                   $amount_cents   Amount in cents ($1.00 = 100)
     * @param SellerPayoutMethod    $recipient      Recipient's banking/payment details
     *
     * @return TransactionResult Result object with transaction details and status
     *
     * @throws \Exception If transaction fails due to:
     *                    - Invalid banking details
     *                    - Insufficient funds (processor-side)
     *                    - Account restrictions or geographic limitations
     *                    - Network/API failures
     *
     * @since 4.0.0
     */
    public function initiatePayment(
        string $transaction_id,
        int $amount_cents,
        SellerPayoutMethod $recipient
    ): TransactionResult;

    /**
     * Retrieves status of an existing transaction
     *
     * Polling method to check transaction progress. Returns current processor status:
     * - PENDING: Transaction created, awaiting processing
     * - PROCESSING: Funds being transferred
     * - COMPLETED: Funds successfully delivered
     * - FAILED: Transaction failed
     * - CANCELLED: Transaction cancelled
     *
     * @param string $transaction_id Processor's transaction ID (returned from initiatePayment)
     *
     * @return TransactionResult Current transaction status
     *
     * @throws \Exception If transaction cannot be found or queried
     *
     * @since 4.0.0
     */
    public function getTransactionStatus(string $transaction_id): TransactionResult;

    /**
     * Refunds a previously initiated transaction
     *
     * Reverses a full or partial payout. Processor handles:
     * - Funds return to original source
     * - Reversal of processor fees (varies by processor)
     * - Transaction reconciliation
     *
     * @param string $transaction_id Processor's transaction ID
     * @param int    $amount_cents   Amount to refund in cents (null = full refund)
     *
     * @return TransactionResult Refund transaction result
     *
     * @throws \Exception If refund fails (e.g., already refunded, expired)
     *
     * @since 4.0.0
     */
    public function refundTransaction(string $transaction_id, ?int $amount_cents = null): TransactionResult;

    /**
     * Gets human-readable processor name
     *
     * @return string Processor name ('Square', 'PayPal', 'Stripe')
     *
     * @since 4.0.0
     */
    public function getProcessorName(): string;

    /**
     * Checks if adapter supports a specific payout method
     *
     * Not all processors support all payout methods:
     * - Square: ACH, Wallet
     * - PayPal: PayPal, Wallet
     * - Stripe: ACH, Card (for micro-deposits)
     *
     * @param string $method_type Method type constant (ACH, PAYPAL, STRIPE, WALLET)
     *
     * @return bool True if processor supports method, false otherwise
     *
     * @since 4.0.0
     */
    public function supportsMethod(string $method_type): bool;
}
