<?php
/**
 * Square Payment Processor Adapter
 *
 * Implements payment processor contract for Square Payouts API.
 * Handles ACH transfers and Square Cash transfers.
 *
 * Fee Structure:
 * - ACH Direct Deposit: $0.25 + 1.0% (transfers under $50,000)
 * - Square to Bank: $0.25 + 1.0%
 *
 * @package    YITH\Auctions\Services\Adapters
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-1: Square adapter implementation
 *
 * Integration Flow:
 *
 *     PayoutService
 *           │
 *           │ initiatePayment()
 *           ▼
 *     ┌────────────────────────────────────┐
 *     │ SquarePayoutAdapter                │
 *     │ - Validates recipient method       │
 *     │ - Calls Square SDK                 │
 *     │ - Maps Square response             │
 *     │ - Returns TransactionResult        │
 *     └────────────────────────────────────┘
 *           │
 *           │ Square API
 *           ▼
 *     ┌────────────────────────────────────┐
 *     │ Square Payouts API                 │
 *     │ - Create payout (idempotent)       │
 *     │ - Parse response                   │
 *     │ - Return payout ID & status        │
 *     └────────────────────────────────────┘
 */

namespace WC\Auction\Services\Adapters;

use Square\SquareClient;
use Square\Exceptions\ApiException;
use WC\Auction\Contracts\IPaymentProcessorAdapter;
use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Models\TransactionResult;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SquarePayoutAdapter
 *
 * Implements Square Payouts API for seller payouts. Supports ACH transfers to bank accounts.
 *
 * @since 4.0.0
 */
class SquarePayoutAdapter implements IPaymentProcessorAdapter
{
    /**
     * Fee for ACH transfers (in cents)
     */
    private const ACH_FIXED_FEE_CENTS = 25;

    /**
     * Fee percentage for ACH transfers
     */
    private const ACH_PERCENTAGE_FEE = 0.01;

    /**
     * Square API client
     *
     * @var SquareClient
     */
    private SquareClient $square_client;

    /**
     * Square location ID for payouts
     *
     * @var string
     */
    private string $location_id;

    /**
     * Constructor
     *
     * @param SquareClient $square_client Square SDK client (injected)
     * @param string       $location_id    Square location ID
     *
     * @since 4.0.0
     */
    public function __construct(SquareClient $square_client, string $location_id)
    {
        $this->square_client = $square_client;
        $this->location_id = $location_id;
    }

    /**
     * Initiates a payout via Square Payouts API
     *
     * Creates ACH transfer to seller's bank account. Request is idempotent
     * using transaction_id as idempotency key.
     *
     * @param string                $transaction_id Idempotency key
     * @param int                   $amount_cents   Amount in cents
     * @param SellerPayoutMethod    $recipient      Seller's bank account details
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
        // Validate recipient method is ACH
        if ( ! $recipient->isACH() ) {
            throw new \Exception(
                sprintf(
                    'Square adapter only supports ACH payouts. Received: %s',
                    $recipient->getMethodType()
                )
            );
        }

        try {
            // Calculate fees
            $processor_fees = $this->calculateFees($amount_cents);

            // Call Square Payouts API
            $response = $this->square_client->getPayoutsApi()->createPayout(
                new \Square\Models\CreatePayoutRequest(
                    idempotencyKey: $transaction_id,
                    payout: new \Square\Models\Payout(
                        amount: $amount_cents,
                        frequency: 'IMMEDIATE',
                        payoutMethod: new \Square\Models\PayoutMethod(
                            bankAccount: new \Square\Models\BankAccount(
                                accountNumber: $this->decryptBankingDetails($recipient, 'account_number'),
                                routingNumber: $this->decryptBankingDetails($recipient, 'routing_number'),
                                accountType: 'CHECKING',
                                accountHolderName: $recipient->getAccountHolderName()
                            )
                        )
                    )
                )
            );

            // Parse response and return TransactionResult
            if ( $response->getResult() ) {
                $payout = $response->getResult()->getPayout();
                return TransactionResult::create(
                    transaction_id: $transaction_id,
                    processor_name: 'Square',
                    status: $this->mapSquareStatus($payout->getStatus()),
                    amount_cents: $amount_cents,
                    processor_fees_cents: $processor_fees,
                    processor_reference: $payout->getId(),
                    completed_at: $this->mapSquareTimestamp($payout->getCompletedAt()),
                    error_message: null,
                    metadata: [
                        'square_payout_id' => $payout->getId(),
                        'square_status' => $payout->getStatus(),
                        'created_at' => $payout->getCreatedAt()?->format('Y-m-d H:i:s'),
                        'updated_at' => $payout->getUpdatedAt()?->format('Y-m-d H:i:s'),
                    ]
                );
            } else {
                throw new \Exception('No payout data in Square response');
            }
        } catch (ApiException $e) {
            return $this->mapSquareError($transaction_id, $amount_cents, $e);
        }
    }

    /**
     * Retrieves transaction status from Square
     *
     * Polls Square Payouts API for current status.
     *
     * @param string $transaction_id Square payout ID
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
            $response = $this->square_client->getPayoutsApi()->getPayout($transaction_id);

            if ( $response->getResult() ) {
                $payout = $response->getResult()->getPayout();
                return TransactionResult::create(
                    transaction_id: $transaction_id,
                    processor_name: 'Square',
                    status: $this->mapSquareStatus($payout->getStatus()),
                    amount_cents: (int) ($payout->getAmount() ?? 0),
                    processor_fees_cents: $this->calculateFees((int) ($payout->getAmount() ?? 0)),
                    processor_reference: $payout->getId(),
                    completed_at: $this->mapSquareTimestamp($payout->getCompletedAt()),
                    error_message: $payout->getFailureCode() ? 'Square error: ' . $payout->getFailureCode() : null,
                    metadata: [
                        'square_payout_id' => $payout->getId(),
                        'square_status' => $payout->getStatus(),
                        'failure_code' => $payout->getFailureCode(),
                        'updated_at' => $payout->getUpdatedAt()?->format('Y-m-d H:i:s'),
                    ]
                );
            } else {
                throw new \Exception('Payout not found in Square response');
            }
        } catch (ApiException $e) {
            throw new \Exception('Failed to retrieve payout status from Square: ' . $e->getMessage());
        }
    }

    /**
     * Refunds a payout via Square
     *
     * Reverses a payout (full or partial). Note: Square doesn't support partial refunds
     * at the payout level - refund must be full or create a new deposit.
     *
     * @param string $transaction_id Square payout ID
     * @param int    $amount_cents   Amount to refund (ignored - Square does full refund only)
     *
     * @return TransactionResult Refund transaction result
     *
     * @throws \Exception On API failure
     *
     * @since 4.0.0
     */
    public function refundTransaction(string $transaction_id, ?int $amount_cents = null): TransactionResult
    {
        // Note: Square Payouts API has limited refund support
        // For now, return error indicating manual intervention needed
        return TransactionResult::create(
            transaction_id: $transaction_id,
            processor_name: 'Square',
            status: TransactionResult::STATUS_FAILED,
            amount_cents: 0,
            processor_fees_cents: 0,
            processor_reference: $transaction_id,
            error_message: 'Square payout refunds must be processed manually through Square Dashboard',
            metadata: [
                'note' => 'Contact support for payout reversal'
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
        return 'Square';
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
     * Maps Square status to TransactionResult status
     *
     * @param string $square_status Square payout status
     *
     * @return string Normalized status
     *
     * @since 4.0.0
     */
    private function mapSquareStatus(string $square_status): string
    {
        return match ($square_status) {
            'PENDING' => TransactionResult::STATUS_PENDING,
            'IN_TRANSIT' => TransactionResult::STATUS_PROCESSING,
            'COMPLETED' => TransactionResult::STATUS_COMPLETED,
            'FAILED' => TransactionResult::STATUS_FAILED,
            'CANCELLED' => TransactionResult::STATUS_CANCELLED,
            default => TransactionResult::STATUS_PENDING,
        };
    }

    /**
     * Maps Square timestamp to DateTime
     *
     * @param \DateTimeInterface|null $square_date Square timestamp
     *
     * @return \DateTime|null
     *
     * @since 4.0.0
     */
    private function mapSquareTimestamp(?\DateTimeInterface $square_date): ?\DateTime
    {
        if ( ! $square_date ) {
            return null;
        }

        return new \DateTime($square_date->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    /**
     * Decrypts banking details from recipient method
     *
     * @param SellerPayoutMethod $recipient Recipient method
     * @param string            $field    Field to decrypt (account_number, routing_number)
     *
     * @return string Decrypted field value
     *
     * @throws \Exception If decryption fails
     *
     * @since 4.0.0
     */
    private function decryptBankingDetails(SellerPayoutMethod $recipient, string $field): string
    {
        // TODO: Implement PayoutMethodManager decryption (Phase 2 Task 2-4)
        // For now, placeholder that will be implemented in PayoutMethodManager
        throw new \Exception('Payout method decryption not yet implemented');
    }

    /**
     * Maps Square API errors to TransactionResult
     *
     * @param string      $transaction_id Transaction ID
     * @param int         $amount_cents   Amount
     * @param ApiException $exception      Square API exception
     *
     * @return TransactionResult Error result
     *
     * @since 4.0.0
     */
    private function mapSquareError(string $transaction_id, int $amount_cents, ApiException $exception): TransactionResult
    {
        $error_message = 'Square API error: ' . $exception->getMessage();

        return TransactionResult::create(
            transaction_id: $transaction_id,
            processor_name: 'Square',
            status: TransactionResult::STATUS_FAILED,
            amount_cents: $amount_cents,
            processor_fees_cents: $this->calculateFees($amount_cents),
            processor_reference: '',
            error_message: $error_message,
            metadata: [
                'exception_class' => get_class($exception),
                'http_status' => $exception->getHttpStatus(),
                'errors' => $exception->getErrors() ?? [],
            ]
        );
    }
}
