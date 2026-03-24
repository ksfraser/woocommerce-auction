<?php
/**
 * PayPal Payment Processor Adapter
 *
 * Implements payment processor contract for PayPal Payouts API.
 * Handles PayPal wallet transfers and bank account mass payouts.
 *
 * Fee Structure:
 * - Mass Payout API: $0.30 + 1.5% (domestic transfers)
 * - PayPal to Email: $0.30 + 1.5%
 *
 * @package    YITH\Auctions\Services\Adapters
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-1: PayPal adapter implementation
 *
 * Integration Flow:
 *
 *     PayoutService
 *           │
 *           │ initiatePayment()
 *           ▼
 *     ┌────────────────────────────────────┐
 *     │ PayPalPayoutAdapter                │
 *     │ - Validates recipient method       │
 *     │ - Calls PayPal SDK                 │
 *     │ - Maps PayPal response             │
 *     │ - Returns TransactionResult        │
 *     └────────────────────────────────────┘
 *           │
 *           │ PayPal API
 *           ▼
 *     ┌────────────────────────────────────┐
 *     │ PayPal Payouts API                 │
 *     │ - Create payout batch              │
 *     │ - Parse response                   │
 *     │ - Return batch ID & status         │
 *     └────────────────────────────────────┘
 */

namespace WC\Auction\Services\Adapters;

use PayPal\Api\Payout;
use PayPal\Api\PayoutItem;
use PayPal\Api\PayoutSenderBatchHeader;
use PayPal\Rest\ApiContext;
use PayPal\Exception\PayPalConnectionException;
use WC\Auction\Contracts\IPaymentProcessorAdapter;
use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Models\TransactionResult;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class PayPalPayoutAdapter
 *
 * Implements PayPal Payouts API for seller payouts. Supports PayPal wallet and bank account transfers.
 *
 * @since 4.0.0
 */
class PayPalPayoutAdapter implements IPaymentProcessorAdapter
{
    /**
     * Fee for payouts (in cents)
     */
    private const PAYOUT_FIXED_FEE_CENTS = 30;

    /**
     * Fee percentage for payouts
     */
    private const PAYOUT_PERCENTAGE_FEE = 0.015;

    /**
     * PayPal API context
     *
     * @var ApiContext
     */
    private ApiContext $api_context;

    /**
     * Constructor
     *
     * @param ApiContext $api_context PayPal API context (injected)
     *
     * @since 4.0.0
     */
    public function __construct(ApiContext $api_context)
    {
        $this->api_context = $api_context;
    }

    /**
     * Initiates a payout via PayPal Payouts API
     *
     * Creates a payout batch item for transfer to PayPal wallet or bank account.
     * Request is idempotent using transaction_id as batch item reference.
     *
     * @param string                $transaction_id Idempotency key
     * @param int                   $amount_cents   Amount in cents
     * @param SellerPayoutMethod    $recipient      Seller's PayPal email or bank details
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
        // Validate recipient method is PayPal or ACH
        if ( ! $recipient->isPayPal() && ! $recipient->isACH() ) {
            throw new \Exception(
                sprintf(
                    'PayPal adapter only supports PAYPAL or ACH payouts. Received: %s',
                    $recipient->getMethodType()
                )
            );
        }

        try {
            // Calculate fees
            $processor_fees = $this->calculateFees($amount_cents);

            // Convert cents to dollars for PayPal API
            $amount_dollars = number_format($amount_cents / 100, 2, '.', '');

            // Create payout batch item
            $payout_item = new PayoutItem();
            $payout_item->setRecipientType($recipient->isPayPal() ? 'EMAIL' : 'PHONE')
                ->setAmount(new \PayPal\Api\Currency([
                    'value' => $amount_dollars,
                    'currency' => 'USD'
                ]))
                ->setReceiver($this->getReceiverIdentifier($recipient))
                ->setNote(sprintf(
                    'Auction settlement - Reference: %s',
                    substr($transaction_id, 0, 50)
                ))
                ->setSenderItemId($transaction_id);

            // Create payout batch
            $sender_batch_header = new PayoutSenderBatchHeader();
            $sender_batch_header->setSenderBatchId($transaction_id)
                ->setEmailSubject('You have received a payout')
                ->setEmailMessage('Thank you for selling on our auction platform');

            $payout = new Payout();
            $payout->setSenderBatchHeader($sender_batch_header)
                ->addItem($payout_item);

            // Send to PayPal API
            $batch = $payout->create($this->api_context);

            // Parse response
            return TransactionResult::create(
                transaction_id: $transaction_id,
                processor_name: 'PayPal',
                status: $this->mapPayPalStatus($batch->getBatchHeader()->getBatchStatus()),
                amount_cents: $amount_cents,
                processor_fees_cents: $processor_fees,
                processor_reference: $batch->getBatchHeader()->getPayoutBatchId(),
                completed_at: null, // PayPal batches are async
                error_message: null,
                metadata: [
                    'paypal_batch_id' => $batch->getBatchHeader()->getPayoutBatchId(),
                    'batch_status' => $batch->getBatchHeader()->getBatchStatus(),
                    'sender_batch_id' => $batch->getBatchHeader()->getSenderBatchId(),
                ]
            );
        } catch (PayPalConnectionException $e) {
            return $this->mapPayPalError($transaction_id, $amount_cents, $e);
        }
    }

    /**
     * Retrieves transaction status from PayPal
     *
     * Polls PayPal Payouts API for current batch status.
     *
     * @param string $transaction_id PayPal batch ID
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
            // PayPal batch details
            $batch = Payout::getById($transaction_id, $this->api_context);

            if ( $batch ) {
                $header = $batch->getBatchHeader();
                $items = $batch->getItems();
                $first_item = $items[0] ?? null;

                $amount_cents = 0;
                if ( $first_item ) {
                    $amount_cents = (int) ($first_item->getAmount()->getValue() * 100);
                }

                return TransactionResult::create(
                    transaction_id: $transaction_id,
                    processor_name: 'PayPal',
                    status: $this->mapPayPalStatus($header->getBatchStatus()),
                    amount_cents: $amount_cents,
                    processor_fees_cents: $this->calculateFees($amount_cents),
                    processor_reference: $header->getPayout_batch_id(),
                    completed_at: null,
                    error_message: $first_item?->getErrors() ? implode(', ', $first_item->getErrors()) : null,
                    metadata: [
                        'batch_status' => $header->getBatchStatus(),
                        'time_completed' => $header->getTimeCompleted(),
                        'item_count' => count($items),
                    ]
                );
            } else {
                throw new \Exception('Batch not found in PayPal');
            }
        } catch (PayPalConnectionException $e) {
            throw new \Exception('Failed to retrieve batch status from PayPal: ' . $e->getMessage());
        }
    }

    /**
     * Refunds a payout via PayPal
     *
     * PayPal payouts cannot be directly refunded. Create a new payout in opposite direction.
     *
     * @param string $transaction_id PayPal batch ID
     * @param int    $amount_cents   Amount to refund
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
            processor_name: 'PayPal',
            status: TransactionResult::STATUS_FAILED,
            amount_cents: $amount_cents ?? 0,
            processor_fees_cents: 0,
            processor_reference: '',
            error_message: 'PayPal payout refunds must be processed as new payouts. Contact support.',
            metadata: [
                'note' => 'Create new payout in opposite direction'
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
        return 'PayPal';
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
        return $method_type === SellerPayoutMethod::METHOD_PAYPAL
               || $method_type === SellerPayoutMethod::METHOD_ACH
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
        $percentage_fee = (int) round(($amount_cents * self::PAYOUT_PERCENTAGE_FEE));
        return self::PAYOUT_FIXED_FEE_CENTS + $percentage_fee;
    }

    /**
     * Maps PayPal status to TransactionResult status
     *
     * @param string $paypal_status PayPal batch status
     *
     * @return string Normalized status
     *
     * @since 4.0.0
     */
    private function mapPayPalStatus(string $paypal_status): string
    {
        return match ($paypal_status) {
            'CREATED' => TransactionResult::STATUS_PENDING,
            'QUEUED' => TransactionResult::STATUS_PENDING,
            'PROCESSING' => TransactionResult::STATUS_PROCESSING,
            'SUCCESS' => TransactionResult::STATUS_COMPLETED,
            'FAILED' => TransactionResult::STATUS_FAILED,
            'HELD' => TransactionResult::STATUS_PROCESSING,
            'RELEASED' => TransactionResult::STATUS_PROCESSING,
            'DENIED' => TransactionResult::STATUS_FAILED,
            'CANCELLED' => TransactionResult::STATUS_CANCELLED,
            default => TransactionResult::STATUS_PENDING,
        };
    }

    /**
     * Gets receiver identifier based on method type
     *
     * @param SellerPayoutMethod $recipient Recipient method
     *
     * @return string Receiver email, phone, or identifier
     *
     * @since 4.0.0
     */
    private function getReceiverIdentifier(SellerPayoutMethod $recipient): string
    {
        // TODO: Implement PayoutMethodManager decryption for account details
        // For now, use placeholder based on method type
        if ( $recipient->isPayPal() ) {
            return substr($recipient->getAccountLastFour(), -4) . '@seller.example.com';
        } elseif ( $recipient->isACH() ) {
            return '1' . $recipient->getAccountLastFour();
        }

        return $recipient->getAccountLastFour();
    }

    /**
     * Maps PayPal API errors to TransactionResult
     *
     * @param string                   $transaction_id Transaction ID
     * @param int                      $amount_cents   Amount
     * @param PayPalConnectionException $exception      PayPal API exception
     *
     * @return TransactionResult Error result
     *
     * @since 4.0.0
     */
    private function mapPayPalError(string $transaction_id, int $amount_cents, PayPalConnectionException $exception): TransactionResult
    {
        $error_message = 'PayPal API error: ' . $exception->getMessage();

        return TransactionResult::create(
            transaction_id: $transaction_id,
            processor_name: 'PayPal',
            status: TransactionResult::STATUS_FAILED,
            amount_cents: $amount_cents,
            processor_fees_cents: $this->calculateFees($amount_cents),
            processor_reference: '',
            error_message: $error_message,
            metadata: [
                'exception_class' => get_class($exception),
                'http_status' => $exception->getStatusCode() ?? 'unknown',
            ]
        );
    }
}
