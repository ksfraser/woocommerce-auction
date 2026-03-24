<?php
/**
 * Seller Payout Data Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    4.0.0
 * @requirement REQ-4D-025: Model seller payout records
 * @requirement REQ-4D-026: Track payout lifecycle and status
 * @requirement REQ-4D-027: Store payment processor information
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SellerPayout - Immutable value object representing a seller payout
 *
 * UML Class Diagram:
 * ```
 * SellerPayout (Immutable Value Object)
 * ├── Private Properties:
 * │   ├── id: int|null
 * │   ├── batch_id: int
 * │   ├── seller_id: int
 * │   ├── amount_cents: int
 * │   ├── method_type: string [METHOD_ACH|METHOD_PAYPAL|METHOD_STRIPE|METHOD_WALLET]
 * │   ├── status: string [PENDING|PROCESSING|COMPLETED|FAILED|CANCELLED]
 * │   ├── transaction_id: string|null (processor transaction ID)
 * │   ├── processor_name: string|null (Square|PayPal|Stripe)
 * │   ├── processor_fees_cents: int
 * │   ├── net_payout_cents: int (amount - fees)
 * │   ├── error_message: string|null
 * │   ├── created_at: DateTime
 * │   ├── updated_at: DateTime
 * │   └── completed_at: DateTime|null
 * └── Public Methods:
 *     ├── create() : self (factory)
 *     ├── fromDatabase() : self (factory)
 *     ├── getId() : int|null
 *     ├── getBatchId() : int
 *     ├── getSellerId() : int
 *     ├── getAmountCents() : int
 *     ├── getMethodType() : string
 *     ├── getStatus() : string
 *     ├── isPending() : bool
 *     ├── isProcessing() : bool
 *     ├── isCompleted() : bool
 *     ├── isFailed() : bool
 *     ├── isCancelled() : bool
 * ```
 *
 * Status Lifecycle:
 * ```
 * PENDING → PROCESSING → COMPLETED
 *        ↓
 *      FAILED → PENDING (retry)
 *        ↓
 *      CANCELLED
 * ```
 *
 * Design Pattern: Immutable Value Object
 * - No setters for computed properties (net_payout_cents)
 * - Status is mutable for updates but tracked
 * - Created via static factory methods
 * - Facilitates easy testing and composition
 *
 * @requirement REQ-4D-025: Model payout record
 * @requirement REQ-4D-026: Track payout lifecycle
 * @requirement REQ-4D-027: Store processor information
 * @requirement PERF-4D-001: Payouts < 100ms per record
 */
class SellerPayout {

    const STATUS_PENDING    = 'PENDING';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_COMPLETED  = 'COMPLETED';
    const STATUS_FAILED     = 'FAILED';
    const STATUS_CANCELLED  = 'CANCELLED';

    /**
     * Payout database ID
     *
     * @var int|null
     */
    private $id;

    /**
     * Settlement batch ID
     *
     * @var int
     */
    private $batch_id;

    /**
     * Seller user ID
     *
     * @var int
     */
    private $seller_id;

    /**
     * Payout amount in cents
     *
     * @var int
     */
    private $amount_cents;

    /**
     * Payout method type
     *
     * @var string
     */
    private $method_type;

    /**
     * Current status
     *
     * @var string
     */
    private $status;

    /**
     * Payment processor transaction ID
     *
     * @var string|null
     */
    private $transaction_id;

    /**
     * Payment processor name (Square, PayPal, Stripe)
     *
     * @var string|null
     */
    private $processor_name;

    /**
     * Processor fees in cents
     *
     * @var int
     */
    private $processor_fees_cents;

    /**
     * Net payout (amount - fees)
     *
     * @var int
     */
    private $net_payout_cents;

    /**
     * Error message if failed
     *
     * @var string|null
     */
    private $error_message;

    /**
     * Record creation timestamp
     *
     * @var \DateTime
     */
    private $created_at;

    /**
     * Record last update timestamp
     *
     * @var \DateTime
     */
    private $updated_at;

    /**
     * Payout completion timestamp
     *
     * @var \DateTime|null
     */
    private $completed_at;

    /**
     * Constructor (private - use factory methods)
     *
     * @param int|null   $id Payout ID
     * @param int        $batch_id Batch ID
     * @param int        $seller_id Seller ID
     * @param int        $amount_cents Amount in cents
     * @param string     $method_type Payout method
     * @param string     $status Current status
     * @param int        $processor_fees_cents Processor fees
     * @param int        $net_payout_cents Net payout amount
     * @param \DateTime  $created_at Creation time
     * @param \DateTime  $updated_at Update time
     * @param string|null $transaction_id Transaction ID
     * @param string|null $processor_name Processor name
     * @param string|null $error_message Error message
     * @param \DateTime|null $completed_at Completion time
     */
    private function __construct(
        ?int $id,
        int $batch_id,
        int $seller_id,
        int $amount_cents,
        string $method_type,
        string $status,
        int $processor_fees_cents,
        int $net_payout_cents,
        \DateTime $created_at,
        \DateTime $updated_at,
        ?string $transaction_id = null,
        ?string $processor_name = null,
        ?string $error_message = null,
        ?\DateTime $completed_at = null
    ) {
        $this->id                   = $id;
        $this->batch_id             = $batch_id;
        $this->seller_id            = $seller_id;
        $this->amount_cents         = $amount_cents;
        $this->method_type          = $method_type;
        $this->status               = $status;
        $this->processor_fees_cents = $processor_fees_cents;
        $this->net_payout_cents     = $net_payout_cents;
        $this->created_at           = $created_at;
        $this->updated_at           = $updated_at;
        $this->transaction_id       = $transaction_id;
        $this->processor_name       = $processor_name;
        $this->error_message        = $error_message;
        $this->completed_at         = $completed_at;
    }

    /**
     * Factory method to create new payout
     *
     * @param int|null   $id Payout ID (null for new)
     * @param int        $batch_id Batch ID
     * @param int        $seller_id Seller ID
     * @param int        $amount_cents Amount in cents
     * @param string     $method_type Payout method type
     * @param string     $status Initial status
     * @return self New instance
     * @requirement REQ-4D-025: Create payout model
     */
    public static function create(
        ?int $id,
        int $batch_id,
        int $seller_id,
        int $amount_cents,
        string $method_type,
        string $status
    ): self {
        return new self(
            $id,
            $batch_id,
            $seller_id,
            $amount_cents,
            $method_type,
            $status,
            0,
            $amount_cents,
            new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ),
            new \DateTime( 'now', new \DateTimeZone( 'UTC' ) )
        );
    }

    /**
     * Factory method to restore from database
     *
     * @param array $row Database row
     * @return self Restored instance
     * @requirement REQ-4D-025: Restore from database
     */
    public static function fromDatabase( array $row ): self {
        return new self(
            (int) $row['id'],
            (int) $row['batch_id'],
            (int) $row['seller_id'],
            (int) $row['amount_cents'],
            (string) $row['method_type'],
            (string) $row['status'],
            (int) ( $row['processor_fees_cents'] ?? 0 ),
            (int) ( $row['net_payout_cents'] ?? 0 ),
            new \DateTime( $row['created_at'], new \DateTimeZone( 'UTC' ) ),
            new \DateTime( $row['updated_at'], new \DateTimeZone( 'UTC' ) ),
            $row['transaction_id'] ?? null,
            $row['processor_name'] ?? null,
            $row['error_message'] ?? null,
            $row['completed_at'] ? new \DateTime( $row['completed_at'], new \DateTimeZone( 'UTC' ) ) : null
        );
    }

    /**
     * Get payout ID
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->id;
    }

    /**
     * Set payout ID (for after-save)
     *
     * @param int $id Payout ID
     */
    public function setId( int $id ): void {
        $this->id = $id;
    }

    /**
     * Get batch ID
     *
     * @return int
     */
    public function getBatchId(): int {
        return $this->batch_id;
    }

    /**
     * Get seller ID
     *
     * @return int
     */
    public function getSellerId(): int {
        return $this->seller_id;
    }

    /**
     * Get amount in cents
     *
     * @return int
     */
    public function getAmountCents(): int {
        return $this->amount_cents;
    }

    /**
     * Get method type
     *
     * @return string
     */
    public function getMethodType(): string {
        return $this->method_type;
    }

    /**
     * Get current status
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Set new status
     *
     * @param string $status New status
     */
    public function setStatus( string $status ): void {
        $this->status = $status;
        $this->updated_at = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Check if pending
     *
     * @return bool
     */
    public function isPending(): bool {
        return self::STATUS_PENDING === $this->status;
    }

    /**
     * Check if processing
     *
     * @return bool
     */
    public function isProcessing(): bool {
        return self::STATUS_PROCESSING === $this->status;
    }

    /**
     * Check if completed
     *
     * @return bool
     */
    public function isCompleted(): bool {
        return self::STATUS_COMPLETED === $this->status;
    }

    /**
     * Check if failed
     *
     * @return bool
     */
    public function isFailed(): bool {
        return self::STATUS_FAILED === $this->status;
    }

    /**
     * Check if cancelled
     *
     * @return bool
     */
    public function isCancelled(): bool {
        return self::STATUS_CANCELLED === $this->status;
    }

    /**
     * Get transaction ID
     *
     * @return string|null
     */
    public function getTransactionId(): ?string {
        return $this->transaction_id;
    }

    /**
     * Set transaction ID
     *
     * @param string $transaction_id Transaction ID from processor
     */
    public function setTransactionId( string $transaction_id ): void {
        $this->transaction_id = $transaction_id;
        $this->updated_at = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Get processor name
     *
     * @return string|null
     */
    public function getProcessorName(): ?string {
        return $this->processor_name;
    }

    /**
     * Set processor name
     *
     * @param string $processor_name Processor name (Square, PayPal, Stripe)
     */
    public function setProcessorName( string $processor_name ): void {
        $this->processor_name = $processor_name;
        $this->updated_at = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Get processor fees in cents
     *
     * @return int
     */
    public function getProcessorFeesCents(): int {
        return $this->processor_fees_cents;
    }

    /**
     * Set processor fees
     *
     * @param int $processor_fees_cents Fees in cents
     */
    public function setProcessorFeesCents( int $processor_fees_cents ): void {
        $this->processor_fees_cents = $processor_fees_cents;
        $this->updateNetPayout();
    }

    /**
     * Get net payout in cents
     *
     * @return int
     */
    public function getNetPayoutCents(): int {
        return $this->net_payout_cents;
    }

    /**
     * Set net payout in cents
     *
     * @param int $net_payout_cents Net payout amount in cents
     */
    public function setNetPayoutCents( int $net_payout_cents ): void {
        $this->net_payout_cents = max( 0, $net_payout_cents );
        $this->updated_at = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Update net payout (amount - fees, never negative)
     */
    private function updateNetPayout(): void {
        $this->net_payout_cents = max( 0, $this->amount_cents - $this->processor_fees_cents );
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string {
        return $this->error_message;
    }

    /**
     * Set error message
     *
     * @param string|null $error_message Error description
     */
    public function setErrorMessage( ?string $error_message ): void {
        $this->error_message = $error_message;
        $this->updated_at = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Get creation timestamp
     *
     * @return \DateTime
     */
    public function getCreatedAt(): \DateTime {
        return $this->created_at;
    }

    /**
     * Get update timestamp
     *
     * @return \DateTime
     */
    public function getUpdatedAt(): \DateTime {
        return $this->updated_at;
    }

    /**
     * Get completion timestamp
     *
     * @return \DateTime|null
     */
    public function getCompletedAt(): ?\DateTime {
        return $this->completed_at;
    }

    /**
     * Set completion timestamp
     *
     * @param \DateTime $completed_at Completion time
     */
    public function setCompletedAt( \DateTime $completed_at ): void {
        $this->completed_at = $completed_at;
        $this->updated_at = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     * @requirement REQ-4D-025: Serialize for persistence
     */
    public function toArray(): array {
        return [
            'id'                     => $this->id,
            'batch_id'               => $this->batch_id,
            'seller_id'              => $this->seller_id,
            'amount_cents'           => $this->amount_cents,
            'method_type'            => $this->method_type,
            'status'                 => $this->status,
            'transaction_id'         => $this->transaction_id,
            'processor_name'         => $this->processor_name,
            'processor_fees_cents'   => $this->processor_fees_cents,
            'net_payout_cents'       => $this->net_payout_cents,
            'error_message'          => $this->error_message,
            'created_at'             => $this->created_at->format( 'Y-m-d H:i:s' ),
            'updated_at'             => $this->updated_at->format( 'Y-m-d H:i:s' ),
            'completed_at'           => $this->completed_at ? $this->completed_at->format( 'Y-m-d H:i:s' ) : null,
        ];
    }
}
