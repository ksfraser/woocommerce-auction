<?php
/**
 * Transaction Result Value Object
 *
 * Standardized immutable value object for transaction responses from all payment processors.
 * Normalizes Square, PayPal, and Stripe response formats into a unified contract.
 *
 * @package    YITH\Auctions\Models
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-1: Unified transaction response modeling
 *
 * UML Class Diagram:
 *
 *     ┌──────────────────────────────────────────────┐
 *     │         TransactionResult                    │
 *     │        (Immutable Value Object)              │
 *     ├──────────────────────────────────────────────┤
 *     │ - transaction_id: string                     │
 *     │ - processor_name: string                     │
 *     │ - status: string                             │
 *     │ - amount_cents: int                          │
 *     │ - processor_fees_cents: int                  │
 *     │ - net_payout_cents: int                      │
 *     │ - initiated_at: DateTime                     │
 *     │ - completed_at: ?DateTime                    │
 *     │ - error_message: ?string                     │
 *     │ - processor_reference: string                │
 *     │ - metadata: array                            │
 *     ├──────────────────────────────────────────────┤
 *     │ + create(...): TransactionResult             │
 *     │ + fromProcessor(...): TransactionResult      │
 *     │ + isPending(): bool                          │
 *     │ + isProcessing(): bool                       │
 *     │ + isCompleted(): bool                        │
 *     │ + isFailed(): bool                           │
 *     │ + isCancelled(): bool                        │
 *     │ + getters: all properties                    │
 *     │ + toArray(): array                           │
 *     └──────────────────────────────────────────────┘
 */

namespace WC\Auction\Models;

use DateTime;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class TransactionResult
 *
 * Immutable value object standardizing payment processor responses.
 * Converts Square, PayPal, STipe formats into unified interface.
 *
 * @since 4.0.0
 */
class TransactionResult
{
    // Status constants normalized across all processors
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * Unique transaction identifier from processor
     *
     * @var string
     */
    private string $transaction_id;

    /**
     * Processor name (Square, PayPal, Stripe)
     *
     * @var string
     */
    private string $processor_name;

    /**
     * Normalized transaction status
     *
     * @var string
     */
    private string $status;

    /**
     * Gross payout amount in cents
     *
     * @var int
     */
    private int $amount_cents;

    /**
     * Processor fees deducted in cents
     *
     * @var int
     */
    private int $processor_fees_cents;

    /**
     * Net amount actually received by seller
     *
     * @var int
     */
    private int $net_payout_cents;

    /**
     * When transaction was initiated
     *
     * @var DateTime
     */
    private DateTime $initiated_at;

    /**
     * When transaction was completed (null if not yet complete)
     *
     * @var DateTime|null
     */
    private ?DateTime $completed_at;

    /**
     * Error message if transaction failed
     *
     * @var string|null
     */
    private ?string $error_message;

    /**
     * Processor-specific reference (invoice ID, authorization code, etc.)
     *
     * @var string
     */
    private string $processor_reference;

    /**
     * Processor-specific metadata (for advanced use)
     *
     * @var array
     */
    private array $metadata;

    /**
     * Constructor (private - use factory methods)
     *
     * @param string      $transaction_id      Transaction ID
     * @param string      $processor_name      Processor name
     * @param string      $status              Status constant
     * @param int         $amount_cents        Gross amount in cents
     * @param int         $processor_fees_cents Processor fees in cents
     * @param int         $net_payout_cents    Net amount in cents
     * @param DateTime    $initiated_at        When transaction was created
     * @param DateTime|null $completed_at      When transaction was completed
     * @param string|null $error_message       Error message if failed
     * @param string      $processor_reference Processor-specific reference
     * @param array       $metadata            Additional processor data
     *
     * @since 4.0.0
     */
    private function __construct(
        string $transaction_id,
        string $processor_name,
        string $status,
        int $amount_cents,
        int $processor_fees_cents,
        int $net_payout_cents,
        DateTime $initiated_at,
        ?DateTime $completed_at,
        ?string $error_message,
        string $processor_reference,
        array $metadata
    ) {
        $this->transaction_id = $transaction_id;
        $this->processor_name = $processor_name;
        $this->status = $status;
        $this->amount_cents = $amount_cents;
        $this->processor_fees_cents = $processor_fees_cents;
        $this->net_payout_cents = $net_payout_cents;
        $this->initiated_at = $initiated_at;
        $this->completed_at = $completed_at;
        $this->error_message = $error_message;
        $this->processor_reference = $processor_reference;
        $this->metadata = $metadata;
    }

    /**
     * Factory: Create a new transaction result
     *
     * @param string      $transaction_id      Transaction ID
     * @param string      $processor_name      Processor name
     * @param string      $status              Status constant (PENDING, PROCESSING, etc.)
     * @param int         $amount_cents        Gross payout amount in cents
     * @param int         $processor_fees_cents Processor fees in cents
     * @param string      $processor_reference Processor reference ID or invoice number
     * @param DateTime|null $completed_at      Completion timestamp (null if not complete)
     * @param string|null $error_message       Error message if failed
     * @param array       $metadata            Processor-specific metadata
     *
     * @return TransactionResult
     *
     * @since 4.0.0
     */
    public static function create(
        string $transaction_id,
        string $processor_name,
        string $status,
        int $amount_cents,
        int $processor_fees_cents,
        string $processor_reference,
        ?DateTime $completed_at = null,
        ?string $error_message = null,
        array $metadata = []
    ): self {
        $net_payout_cents = max(0, $amount_cents - $processor_fees_cents);

        return new self(
            $transaction_id,
            $processor_name,
            $status,
            $amount_cents,
            $processor_fees_cents,
            $net_payout_cents,
            new DateTime('now', new \DateTimeZone('UTC')),
            $completed_at,
            $error_message,
            $processor_reference,
            $metadata
        );
    }

    /**
     * Factory: Hydrate from database row
     *
     * @param array $data Database row
     *
     * @return TransactionResult
     *
     * @since 4.0.0
     */
    public static function fromDatabase(array $data): self {
        return new self(
            $data['transaction_id'] ?? '',
            $data['processor_name'] ?? '',
            $data['status'] ?? self::STATUS_PENDING,
            (int) ($data['amount_cents'] ?? 0),
            (int) ($data['processor_fees_cents'] ?? 0),
            (int) ($data['net_payout_cents'] ?? 0),
            isset($data['initiated_at']) ? new DateTime($data['initiated_at'], new \DateTimeZone('UTC')) : new DateTime('now', new \DateTimeZone('UTC')),
            isset($data['completed_at']) && $data['completed_at'] ? new DateTime($data['completed_at'], new \DateTimeZone('UTC')) : null,
            $data['error_message'] ?? null,
            $data['processor_reference'] ?? '',
            isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : []
        );
    }

    /**
     * Check if transaction is pending
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isPending(): bool {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if transaction is processing
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isProcessing(): bool {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if transaction is completed
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isCompleted(): bool {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if transaction failed
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isFailed(): bool {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if transaction was cancelled
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isCancelled(): bool {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if transaction is in terminal state (completed, failed, or cancelled)
     *
     * @return bool
     *
     * @since 4.0.0
     */
    public function isTerminal(): bool {
        return $this->isCompleted() || $this->isFailed() || $this->isCancelled();
    }

    // ===== Getters =====

    public function getTransactionId(): string {
        return $this->transaction_id;
    }

    public function getProcessorName(): string {
        return $this->processor_name;
    }

    public function getStatus(): string {
        return $this->status;
    }

    public function getAmountCents(): int {
        return $this->amount_cents;
    }

    public function getProcessorFeesCents(): int {
        return $this->processor_fees_cents;
    }

    public function getNetPayoutCents(): int {
        return $this->net_payout_cents;
    }

    public function getInitiatedAt(): DateTime {
        return $this->initiated_at;
    }

    public function getCompletedAt(): ?DateTime {
        return $this->completed_at;
    }

    public function getErrorMessage(): ?string {
        return $this->error_message;
    }

    public function getProcessorReference(): string {
        return $this->processor_reference;
    }

    public function getMetadata(): array {
        return $this->metadata;
    }

    /**
     * Convert to array representation
     *
     * @return array
     *
     * @since 4.0.0
     */
    public function toArray(): array {
        return [
            'transaction_id' => $this->transaction_id,
            'processor_name' => $this->processor_name,
            'status' => $this->status,
            'amount_cents' => $this->amount_cents,
            'processor_fees_cents' => $this->processor_fees_cents,
            'net_payout_cents' => $this->net_payout_cents,
            'initiated_at' => $this->initiated_at->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at ? $this->completed_at->format('Y-m-d H:i:s') : null,
            'error_message' => $this->error_message,
            'processor_reference' => $this->processor_reference,
            'metadata' => $this->metadata,
        ];
    }
}
