<?php
/**
 * Settlement Batch Data Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    4.0.0
 * @requirement REQ-4D-001: Model settlement batch records
 * @requirement REQ-4D-002: Track batch lifecycle and status
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SettlementBatch - Immutable value object representing a settlement batch
 *
 * UML Class Diagram:
 * ```
 * SettlementBatch (Immutable Value Object)
 * ├── Private Properties:
 * │   ├── id: int|null
 * │   ├── batch_number: string (unique identifier)
 * │   ├── settlement_date: DateTime
 * │   ├── period_start: DateTime
 * │   ├── period_end: DateTime
 * │   ├── status: string [DRAFT|VALIDATED|PROCESSING|COMPLETED|CANCELLED]
 * │   ├── total_amount_cents: int
 * │   ├── commission_amount_cents: int
 * │   ├── processor_fees_cents: int
 * │   ├── payout_count: int
 * │   ├── created_at: DateTime
 * │   ├── processed_at: DateTime|null
 * │   ├── notes: string|null
 * │   └── seller_payouts: array[]
 * └── Public Methods:
 *     ├── create() : self
 *     ├── getId() : int|null
 * │   ├── getBatchNumber() : string
 * │   ├── getStatus() : string
 * │   ├── isDraft() : bool
 * │   ├── isValidated() : bool
 * │   ├── isProcessing() : bool
 * │   ├── isCompleted() : bool
 * │   ├── isCancelled() : bool
 * │   ├── getTotalAmountCents() : int
 * │   ├── getNetPayoutCents() : int
 * │   ├── toArray() : array
 * ```
 *
 * Status Lifecycle:
 * ```
 * DRAFT → VALIDATED → PROCESSING → COMPLETED
 *        →             ↓
 *        CANCELLED    FAILED (with retry)
 * ```
 *
 * Design Pattern: Immutable Value Object
 * - No setters; all properties set in factory method
 * - Created via static factory method (create)
 * - Status is immutable; state changes create new instance
 * - Facilitates easy testing and composition
 *
 * @requirement REQ-4D-001: Store settlement batch data
 * @requirement REQ-4D-002: Track settlement batch lifecycle
 * @requirement PERF-4D-001: Settlement batch processing < 5 seconds for 100 sellers
 */
class SettlementBatch {

    const STATUS_DRAFT      = 'DRAFT';
    const STATUS_VALIDATED  = 'VALIDATED';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_COMPLETED  = 'COMPLETED';
    const STATUS_CANCELLED  = 'CANCELLED';

    /**
     * Batch database ID
     *
     * @var int|null
     */
    private $id;

    /**
     * Unique batch number (e.g., "2026-03-23-001")
     *
     * @var string
     */
    private $batch_number;

    /**
     * Settlement date (when batch was created)
     *
     * @var \DateTime
     */
    private $settlement_date;

    /**
     * Auction period start
     *
     * @var \DateTime
     */
    private $period_start;

    /**
     * Auction period end
     *
     * @var \DateTime
     */
    private $period_end;

    /**
     * Current batch status
     *
     * @var string
     */
    private $status;

    /**
     * Total gross amount in cents
     *
     * @var int
     */
    private $total_amount_cents;

    /**
     * Total commission collected in cents
     *
     * @var int
     */
    private $commission_amount_cents;

    /**
     * Total processor fees in cents
     *
     * @var int
     */
    private $processor_fees_cents;

    /**
     * Number of sellers in batch
     *
     * @var int
     */
    private $payout_count;

    /**
     * When batch was created
     *
     * @var \DateTime
     */
    private $created_at;

    /**
     * When batch processing completed
     *
     * @var \DateTime|null
     */
    private $processed_at;

    /**
     * Batch notes (errors, warnings, etc)
     *
     * @var string|null
     */
    private $notes;

    /**
     * Seller payouts in this batch
     *
     * @var array[]
     */
    private $seller_payouts;

    /**
     * Private constructor for immutability
     */
    private function __construct(
        ?int $id,
        string $batch_number,
        \DateTime $settlement_date,
        \DateTime $period_start,
        \DateTime $period_end,
        string $status,
        int $total_amount_cents,
        int $commission_amount_cents,
        int $processor_fees_cents,
        int $payout_count,
        \DateTime $created_at,
        ?\DateTime $processed_at = null,
        ?string $notes = null,
        array $seller_payouts = []
    ) {
        $this->id                      = $id;
        $this->batch_number            = $batch_number;
        $this->settlement_date         = $settlement_date;
        $this->period_start            = $period_start;
        $this->period_end              = $period_end;
        $this->status                  = $status;
        $this->total_amount_cents      = $total_amount_cents;
        $this->commission_amount_cents = $commission_amount_cents;
        $this->processor_fees_cents    = $processor_fees_cents;
        $this->payout_count            = $payout_count;
        $this->created_at              = $created_at;
        $this->processed_at            = $processed_at;
        $this->notes                   = $notes;
        $this->seller_payouts          = $seller_payouts;
    }

    /**
     * Factory method to create new settlement batch
     *
     * @param string        $batch_number Unique batch identifier
     * @param \DateTime     $settlement_date Settlement date
     * @param \DateTime     $period_start Auction period start
     * @param \DateTime     $period_end Auction period end
     * @param int           $total_amount_cents Total gross amount (cents)
     * @param int           $commission_amount_cents Total commission (cents)
     * @param int           $processor_fees_cents Total processor fees (cents)
     * @return self New settlement batch
     * @throws \InvalidArgumentException If parameters are invalid
     * @requirement REQ-4D-001: Create new settlement batch
     */
    public static function create(
        string $batch_number,
        \DateTime $settlement_date,
        \DateTime $period_start,
        \DateTime $period_end,
        int $total_amount_cents = 0,
        int $commission_amount_cents = 0,
        int $processor_fees_cents = 0
    ): self {
        // Validate batch number format
        if ( empty( $batch_number ) ) {
            throw new \InvalidArgumentException( 'Batch number cannot be empty' );
        }

        // Validate period dates
        if ( $period_end < $period_start ) {
            throw new \InvalidArgumentException( 'Period end cannot be before period start' );
        }

        // Validate amounts
        if ( $total_amount_cents < 0 || $commission_amount_cents < 0 || $processor_fees_cents < 0 ) {
            throw new \InvalidArgumentException( 'Amounts cannot be negative' );
        }

        return new self(
            null,
            $batch_number,
            $settlement_date,
            $period_start,
            $period_end,
            self::STATUS_DRAFT,
            $total_amount_cents,
            $commission_amount_cents,
            $processor_fees_cents,
            0,
            new \DateTime( 'now', new \DateTimeZone( 'UTC' ) )
        );
    }

    /**
     * Factory method to create batch from database record
     *
     * @param array $data Database row data
     * @return self
     * @throws \InvalidArgumentException If data is incomplete
     */
    public static function fromDatabase( array $data ): self {
        $required_keys = [ 'id', 'batch_number', 'settlement_date', 'batch_period_start', 'batch_period_end', 'status' ];
        foreach ( $required_keys as $key ) {
            if ( ! isset( $data[ $key ] ) ) {
                throw new \InvalidArgumentException( "Missing required key: {$key}" );
            }
        }

        return new self(
            (int) $data['id'],
            (string) $data['batch_number'],
            new \DateTime( $data['settlement_date'], new \DateTimeZone( 'UTC' ) ),
            new \DateTime( $data['batch_period_start'], new \DateTimeZone( 'UTC' ) ),
            new \DateTime( $data['batch_period_end'], new \DateTimeZone( 'UTC' ) ),
            (string) $data['status'],
            (int) ( $data['total_amount_cents'] ?? 0 ),
            (int) ( $data['commission_amount_cents'] ?? 0 ),
            (int) ( $data['processor_fees_cents'] ?? 0 ),
            (int) ( $data['payout_count'] ?? 0 ),
            new \DateTime( $data['created_at'], new \DateTimeZone( 'UTC' ) ),
            isset( $data['processed_at'] ) && ! empty( $data['processed_at'] ) ? new \DateTime( $data['processed_at'], new \DateTimeZone( 'UTC' ) ) : null,
            (string) ( $data['notes'] ?? '' ) ?: null
        );
    }

    /**
     * Get batch ID
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->id;
    }

    /**
     * Get batch number
     *
     * @return string
     */
    public function getBatchNumber(): string {
        return $this->batch_number;
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Check if DRAFT status
     *
     * @return bool
     */
    public function isDraft(): bool {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if VALIDATED status
     *
     * @return bool
     */
    public function isValidated(): bool {
        return $this->status === self::STATUS_VALIDATED;
    }

    /**
     * Check if PROCESSING status
     *
     * @return bool
     */
    public function isProcessing(): bool {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if COMPLETED status
     *
     * @return bool
     */
    public function isCompleted(): bool {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if CANCELLED status
     *
     * @return bool
     */
    public function isCancelled(): bool {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Get total gross amount in cents
     *
     * @return int
     */
    public function getTotalAmountCents(): int {
        return $this->total_amount_cents;
    }

    /**
     * Get total commission in cents
     *
     * @return int
     */
    public function getCommissionAmountCents(): int {
        return $this->commission_amount_cents;
    }

    /**
     * Get total processor fees in cents
     *
     * @return int
     */
    public function getProcessorFeesCents(): int {
        return $this->processor_fees_cents;
    }

    /**
     * Calculate net payout (gross - commission - fees)
     *
     * @return int
     */
    public function getNetPayoutCents(): int {
        return $this->total_amount_cents - $this->commission_amount_cents - $this->processor_fees_cents;
    }

    /**
     * Get payout count
     *
     * @return int
     */
    public function getPayoutCount(): int {
        return $this->payout_count;
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'id'                       => $this->id,
            'batch_number'             => $this->batch_number,
            'settlement_date'          => $this->settlement_date->format( 'Y-m-d' ),
            'batch_period_start'       => $this->period_start->format( 'Y-m-d' ),
            'batch_period_end'         => $this->period_end->format( 'Y-m-d' ),
            'status'                   => $this->status,
            'total_amount_cents'       => $this->total_amount_cents,
            'commission_amount_cents'  => $this->commission_amount_cents,
            'processor_fees_cents'     => $this->processor_fees_cents,
            'net_payout_cents'         => $this->getNetPayoutCents(),
            'payout_count'             => $this->payout_count,
            'created_at'               => $this->created_at->format( 'Y-m-d H:i:s' ),
            'processed_at'             => $this->processed_at ? $this->processed_at->format( 'Y-m-d H:i:s' ) : null,
            'notes'                    => $this->notes,
        ];
    }
}
