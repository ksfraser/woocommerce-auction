<?php
/**
 * Stripe Payment Processor Adapter
 *
 * Implements payment processor contract for Stripe Connect Payouts.
 * Handles ACH transfers and card payouts via Stripe Connect.
 *
 * Fee Structure:
 * - US ACH Transfers: $0.30 + 1.0% (transfers take 1-3 business days)
 * - Instant Payouts: $0.30 + 1.0% (faster processing, may have different rates)
 *
 * @package    YITH\Auctions\Services\Adapters
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-1: Stripe adapter implementation
 *
 * Integration Flow:
 *
 *     PayoutService
 *           │
 *           │ initiatePayment()
 *           ▼
 *     ┌────────────────────────────────────┐
 *     │ StripePayoutAdapter                │
 *     │ - Validates recipient method       │
 *     │ - Calls Stripe SDK                 │
 *     │ - Maps Stripe response             │
 *     │ - Returns TransactionResult        │
 *     └────────────────────────────────────┘
 *           │
 *           │ Stripe API
 *           ▼
 *     ┌────────────────────────────────────┐
 *     │ Stripe Connect Transfers API       │
 *     │ - Create payout                    │
 *     │ - Parse response                   │
 *     │ - Return payout ID & status        │
 *     └────────────────────────────────────┘
 */

namespace WC\Auction\Services\Adapters;

use Stripe\Payout;
use Stripe\Error\ApiError;
use Stripe\Stripe as StripeClient;
use WC\Auction\Contracts\IPaymentProcessorAdapter;
use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Models\TransactionResult;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class StripePayoutAdapter
 *
 * Implements Stripe Connect Payouts for seller payouts. Supports ACH transfers and Stripe Connect.
 *
 * @since 4.0.0
 */
class StripePayoutAdapter implements IPaymentProcessorAdapter
{
    /**
     * Fee for ACH transfers (in cents)
     */
    private const ACH_FIXED_FEE_CENTS = 30;

    /**
     * Fee percentage for ACH transfers
     */
    private const ACH_PERCENTAGE_FEE = 0.01;

    /**
     * Stripe API key
     *
     * @var string
     */
    private string $stripe_key;

    /**
     * Constructor
     *
     * @param string $stripe_key Stripe secret API key (injected)
     *
     * @since 4.0.0
     */
    public function __construct(string $stripe_key)
    {
        $this->stripe_key = $stripe_key;
        StripeClient::setApiKey($stripe_key);
    }

    /**
     * Initiates a payout via Stripe Connect
     *
     * Creates an ACH transfer to seller's bank account via Stripe Connect.
     * Request is idempotent using transaction_id as Stripe idempotency key.
     *
     * @param string                $transaction_id Idempotency key
     * @param int                   $amount_cents   Amount in cents
     * @param SellerPayoutMethod    $recipient      Seller's Stripe account or bank details
     *
     * @return TransactionResult Transaction status
     *
     * @throws \Exception On API failure
     *
     * @since 4.0.0
     */
    public function initiatePayment(
        string $transaction_id,
        int $amount_cents,
        SellerPayoutMethod $recipient
    ): TransactionResult {
        // Validate recipient method
        if ( ! $recipient->isACH() && ! $recipient->isStripe() ) {
            throw new \Exception(
                sprintf(
                    'Stripe adapter only supports ACH or STRIPE payouts. Received: %s',
                    $recipient->getMethodType()
                )
            );
        }

        try {
            // Calculate fees
            $processor_fees = $this->calculateFees($amount_cents);

            // Get Stripe connected account ID
            $stripe_account_id = $this->getStripeAccountId($recipient);

            // Create payout
            $payout = Payout::create([
                'amount' => $amount_cents,
                'currency' => 'usd',
                'destination' => $this->getPayoutDestination($recipient),
                'statement_descriptor' => 'AUCTION PAYOUT',
                'metadata' => [
                    'transaction_id' => $transaction_id,
                    'auction_payout' => 'true',
                ],
            ], [
                'stripe_account' => $stripe_account_id,
            ]);

            // Parse response
            return TransactionResult::create(
                transaction_id: $transaction_id,
                processor_name: 'Stripe',
                status: $this->mapStripeStatus($payout->status),
                amount_cents: $amount_cents,
                processor_fees_cents: $processor_fees,
                processor_reference: $payout->id,
                completed_at: $this->mapStripeTimestamp($payout->arrival_date),
                error_message: null,
                metadata: [
                    'stripe_payout_id' => $payout->id,
                    'stripe_status' => $payout->status,
                    'stripe_account_id' => $stripe_account_id,
                    'created_at' => date('Y-m-d H:i:s', $payout->created),
                    'arrival_date' => $payout->arrival_date,
                ]
            );
        } catch (ApiError $e) {
            return $this->mapStripeError($transaction_id, $amount_cents, $e);
        }
    }

    /**
     * Retrieves transaction status from Stripe
     *
     * Polls Stripe Connect API for current payout status.
     *
     * @param string $transaction_id Stripe payout ID
     *
     * @return TransactionResult Current transaction status
     *
     * @throws \Exception On API failure
     *
     * @since 4.0.0
     */
    public function getTransactionStatus(string $transaction_id): TransactionResult
    {
        try {
            $payout = Payout::retrieve($transaction_id);

            if ( $payout ) {
                return TransactionResult::create(
                    transaction_id: $transaction_id,
                    processor_name: 'Stripe',
                    status: $this->mapStripeStatus($payout->status),
                    amount_cents: $payout->amount,
                    processor_fees_cents: $this->calculateFees($payout->amount),
                    processor_reference: $payout->id,
                    completed_at: $this->mapStripeTimestamp($payout->arrival_date),
                    error_message: $payout->failure_reason ? 'Stripe Error: ' . $payout->failure_reason : null,
                    metadata: [
                        'stripe_payout_id' => $payout->id,
                        'stripe_status' => $payout->status,
                        'failure_reason' => $payout->failure_reason,
                        'updated_at' => date('Y-m-d H:i:s', $payout->created),
                    ]
                );
            } else {
                throw new \Exception('Payout not found in Stripe');
            }
        } catch (ApiError $e) {
            throw new \Exception('Failed to retrieve payout status from Stripe: ' . $e->getMessage());
        }
    }

    /**
     * Refunds a payout via Stripe
     *
     * Stripe allows reversal of payouts via the reverse_on_bank_account action.
     * Note: Partial reversals are supported.
     *
     * @param string $transaction_id Stripe payout ID
     * @param int    $amount_cents   Amount to refund (null = full refund)
     *
     * @return TransactionResult Refund transaction result
     *
     * @throws \Exception On API failure
     *
     * @since 4.0.0
     */
    public function refundTransaction(string $transaction_id, ?int $amount_cents = null): TransactionResult
    {
        return TransactionResult::create(
            transaction_id: $transaction_id . '_refund',
            processor_name: 'Stripe',
            status: TransactionResult::STATUS_FAILED,
            amount_cents: $amount_cents ?? 0,
            processor_fees_cents: 0,
            processor_reference: '',
            error_message: 'Stripe payout reversal must be handled through Stripe Dashboard or support. Contact support.',
            metadata: [
                'note' => 'Requires manual intervention or Stripe automation'
            ]
        );
    }

    /**
     * Gets adapter name
     *
     * @return string
     *
     * @since 4.0.0
     */
    public function getProcessorName(): string
    {
        return 'Stripe';
    }

    /**
     * Checks if adapter supports payout method
     *
     * @param string $method_type Method type constant
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function supportsMethod(string $method_type): bool
    {
        return $method_type === SellerPayoutMethod::METHOD_ACH
               || $method_type === SellerPayoutMethod::METHOD_STRIPE
               || $method_type === SellerPayoutMethod::METHOD_WALLET;
    }

    /**
     * Calculates processor fees
     *
     * @param int $amount_cents Amount in cents
     *
     * @return int Fees in cents
     *
     * @since 4.0.0
     */
    private function calculateFees(int $amount_cents): int
    {
        $percentage_fee = (int) round(($amount_cents * self::ACH_PERCENTAGE_FEE));
        return self::ACH_FIXED_FEE_CENTS + $percentage_fee;
    }

    /**
     * Maps Stripe status to TransactionResult status
     *
     * @param string $stripe_status Stripe payout status
     *
     * @return string Normalized status
     *
     * @since 4.0.0
     */
    private function mapStripeStatus(string $stripe_status): string
    {
        return match ($stripe_status) {
            'pending' => TransactionResult::STATUS_PENDING,
            'in_transit' => TransactionResult::STATUS_PROCESSING,
            'paid' => TransactionResult::STATUS_COMPLETED,
            'failed' => TransactionResult::STATUS_FAILED,
            'canceled' => TransactionResult::STATUS_CANCELLED,
            default => TransactionResult::STATUS_PENDING,
        };
    }

    /**
     * Maps Stripe timestamp to DateTime
     *
     * @param int|null $stripe_timestamp Unix timestamp
     *
     * @return \DateTime|null
     *
     * @since 4.0.0
     */
    private function mapStripeTimestamp(?int $stripe_timestamp): ?\DateTime
    {
        if ( ! $stripe_timestamp ) {
            return null;
        }

        $date = new \DateTime();
        $date->setTimestamp($stripe_timestamp);
        $date->setTimezone(new \DateTimeZone('UTC'));

        return $date;
    }

    /**
     * Gets Stripe connected account ID from recipient
     *
     * @param SellerPayoutMethod $recipient Recipient method
     *
     * @return string Stripe account ID
     *
     * @throws \Exception If account ID cannot be determined
     *
     * @since 4.0.0
     */
    private function getStripeAccountId(SellerPayoutMethod $recipient): string
    {
        // TODO: Implement PayoutMethodManager to retrieve Stripe account ID
        // For now, throw exception indicating implementation needed
        throw new \Exception('Stripe account ID retrieval not yet implemented');
    }

    /**
     * Gets payout destination (bank account or card)
     *
     * @param SellerPayoutMethod $recipient Recipient method
     *
     * @return string Destination identifier
     *
     * @throws \Exception If destination cannot be determined
     *
     * @since 4.0.0
     */
    private function getPayoutDestination(SellerPayoutMethod $recipient): string
    {
        // TODO: Implement PayoutMethodManager to decrypt and retrieve destination
        // For now, throw exception indicating implementation needed
        throw new \Exception('Payout destination decryption not yet implemented');
    }

    /**
     * Maps Stripe API errors to TransactionResult
     *
     * @param string   $transaction_id Transaction ID
     * @param int      $amount_cents   Amount
     * @param ApiError $exception      Stripe API exception
     *
     * @return TransactionResult Error result
     *
     * @since 4.0.0
     */
    private function mapStripeError(string $transaction_id, int $amount_cents, ApiError $exception): TransactionResult
    {
        $error_message = 'Stripe API error: ' . $exception->getMessage();

        return TransactionResult::create(
            transaction_id: $transaction_id,
            processor_name: 'Stripe',
            status: TransactionResult::STATUS_FAILED,
            amount_cents: $amount_cents,
            processor_fees_cents: $this->calculateFees($amount_cents),
            processor_reference: '',
            error_message: $error_message,
            metadata: [
                'exception_class' => get_class($exception),
                'error_code' => $exception->getError()?->code ?? 'unknown',
            ]
        );
    }
}
