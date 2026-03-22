<?php
/**
 * Auto Bid Audit Log Data Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    1.0.0
 * @requirement REQ-AB-005: Track all auto-bid attempts with success/failure
 * @requirement REQ-AB-006: Maintain complete audit trail
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AutoBidLog - Immutable audit log entry for every auto-bid attempt
 *
 * UML Class Diagram:
 * ```
 * AutoBidLog (Immutable Audit Entry)
 * ├── Private Properties:
 * │   ├── id: int
 * │   ├── auction_id: int
 * │   ├── user_id: int
 * │   ├── proxy_bid_id: int
 * │   ├── bid_amount: decimal
 * │   ├── previous_bid: decimal
 * │   ├── bid_increment_used: decimal
 * │   ├── outbidding_bid_id: int|null
 * │   ├── success: bool
 * │   ├── error_message: string|null
 * │   ├── processing_time_ms: int
 * │   └── triggered_at: DateTime
 * └── Public Methods:
 *     ├── create() : self
 *     ├── getId() : int
 *     ├── getAuctionId() : int
 *     ├── getUserId() : int
 *     ├── getProxyBidId() : int
 *     ├── getBidAmount() : decimal
 *     ├── wasSuccessful() : bool
 *     ├── hasError() : bool
 *     ├── getErrorMessage() : string|null
 *     ├── getProcessingTime() : int
 *     └── toArray() : array
 * ```
 *
 * Design Pattern: Immutable Value Object (Fowler)
 * - No setters; all properties set in constructor/factory only
 * - Factory method (create) for instantiation
 * - Represents single audit trail entry
 * - Immutable: cannot be modified after creation (thread-safe)
 * - Used for reporting, debugging, compliance (REQ-AB-006)
 *
 * @requirement REQ-AB-005: Maintain 99.9% bid execution tracking
 * @requirement REQ-AB-006: Track complete audit trail of all auto-bid attempts
 */
class AutoBidLog {
    
    /**
     * Log entry ID
     *
     * @var int
     */
    private $id;
    
    /**
     * Auction (product) ID
     *
     * @var int
     */
    private $auction_id;
    
    /**
     * User ID of bidder
     *
     * @var int
     */
    private $user_id;
    
    /**
     * Proxy bid that triggered this auto-bid
     *
     * @var int
     */
    private $proxy_bid_id;
    
    /**
     * Bid amount placed
     *
     * @var float
     */
    private $bid_amount;
    
    /**
     * Previous bid amount (before this bid)
     *
     * @var float
     */
    private $previous_bid;
    
    /**
     * Bid increment used to calculate this bid
     *
     * @var float
     */
    private $bid_increment_used;
    
    /**
     * ID of the bid this one outbid (if applicable)
     *
     * @var int|null
     */
    private $outbidding_bid_id;
    
    /**
     * Whether auto-bid was successful
     *
     * @var bool
     */
    private $success;
    
    /**
     * Error message if failed (null if successful)
     *
     * @var string|null
     */
    private $error_message;
    
    /**
     * Processing time in milliseconds (for performance monitoring)
     *
     * @var int
     */
    private $processing_time_ms;
    
    /**
     * When this auto-bid was triggered
     *
     * @var \DateTime
     */
    private $triggered_at;
    
    /**
     * Constructor - private; use create() factory instead
     *
     * @param int                 $id
     * @param int                 $auction_id
     * @param int                 $user_id
     * @param int                 $proxy_bid_id
     * @param float               $bid_amount
     * @param float               $previous_bid
     * @param float               $bid_increment_used
     * @param bool                $success
     * @param \DateTime           $triggered_at
     * @param int|null            $outbidding_bid_id
     * @param string|null         $error_message
     * @param int                 $processing_time_ms
     */
    private function __construct(
        int $id,
        int $auction_id,
        int $user_id,
        int $proxy_bid_id,
        float $bid_amount,
        float $previous_bid,
        float $bid_increment_used,
        bool $success,
        \DateTime $triggered_at,
        ?int $outbidding_bid_id = null,
        ?string $error_message = null,
        int $processing_time_ms = 0
    ) {
        $this->id                    = $id;
        $this->auction_id            = $auction_id;
        $this->user_id               = $user_id;
        $this->proxy_bid_id          = $proxy_bid_id;
        $this->bid_amount            = $bid_amount;
        $this->previous_bid          = $previous_bid;
        $this->bid_increment_used    = $bid_increment_used;
        $this->outbidding_bid_id     = $outbidding_bid_id;
        $this->success               = $success;
        $this->error_message         = $error_message;
        $this->processing_time_ms    = $processing_time_ms;
        $this->triggered_at          = $triggered_at;
    }
    
    /**
     * Factory method to create AutoBidLog from data array
     *
     * @param array $data Array with keys: id, auction_id, user_id, proxy_bid_id,
     *                     bid_amount, previous_bid, bid_increment_used, success,
     *                     triggered_at, outbidding_bid_id (optional),
     *                     error_message (optional), processing_time_ms (optional)
     * @return self
     * @throws \InvalidArgumentException If required fields missing
     */
    public static function create( array $data ): self {
        // Validate required fields
        $required = [
            'id',
            'auction_id',
            'user_id',
            'proxy_bid_id',
            'bid_amount',
            'previous_bid',
            'bid_increment_used',
            'success',
            'triggered_at',
        ];
        
        foreach ( $required as $field ) {
            if ( ! isset( $data[ $field ] ) ) {
                throw new \InvalidArgumentException( "Required field missing: {$field}" );
            }
        }
        
        // If success is false, error_message should be set
        if ( ! $data['success'] && empty( $data['error_message'] ) ) {
            throw new \InvalidArgumentException(
                'error_message is required when success is false'
            );
        }
        
        // Parse triggered_at as DateTime
        $triggered_at = $data['triggered_at'] instanceof \DateTime
            ? $data['triggered_at']
            : new \DateTime( $data['triggered_at'] );
        
        return new self(
            (int) $data['id'],
            (int) $data['auction_id'],
            (int) $data['user_id'],
            (int) $data['proxy_bid_id'],
            (float) $data['bid_amount'],
            (float) $data['previous_bid'],
            (float) $data['bid_increment_used'],
            (bool) $data['success'],
            $triggered_at,
            ! empty( $data['outbidding_bid_id'] ) ? (int) $data['outbidding_bid_id'] : null,
            $data['error_message'] ?? null,
            (int) ( $data['processing_time_ms'] ?? 0 )
        );
    }
    
    /**
     * Get log entry ID
     *
     * @return int
     */
    public function getId(): int {
        return $this->id;
    }
    
    /**
     * Get auction ID
     *
     * @return int
     */
    public function getAuctionId(): int {
        return $this->auction_id;
    }
    
    /**
     * Get user ID
     *
     * @return int
     */
    public function getUserId(): int {
        return $this->user_id;
    }
    
    /**
     * Get proxy bid ID that triggered this log entry
     *
     * @return int
     */
    public function getProxyBidId(): int {
        return $this->proxy_bid_id;
    }
    
    /**
     * Get bid amount
     *
     * @return float
     */
    public function getBidAmount(): float {
        return $this->bid_amount;
    }
    
    /**
     * Get previous bid amount
     *
     * @return float
     */
    public function getPreviousBid(): float {
        return $this->previous_bid;
    }
    
    /**
     * Get bid increment used in calculation
     *
     * @return float
     */
    public function getBidIncrementUsed(): float {
        return $this->bid_increment_used;
    }
    
    /**
     * Get ID of bid that was outbid (if applicable)
     *
     * @return int|null
     */
    public function getOutbiddingBidId(): ?int {
        return $this->outbidding_bid_id;
    }
    
    /**
     * Check if auto-bid was successful
     *
     * @return bool
     */
    public function wasSuccessful(): bool {
        return $this->success;
    }
    
    /**
     * Check if there was an error
     *
     * @return bool
     */
    public function hasError(): bool {
        return ! $this->success;
    }
    
    /**
     * Get error message (if failed)
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string {
        return $this->error_message;
    }
    
    /**
     * Get processing time in milliseconds
     *
     * Performance requirement: REQ-AB-004 < 100ms
     *
     * @return int
     */
    public function getProcessingTimeMs(): int {
        return $this->processing_time_ms;
    }
    
    /**
     * Get when this auto-bid was triggered
     *
     * @return \DateTime
     */
    public function getTriggeredAt(): \DateTime {
        return $this->triggered_at;
    }
    
    /**
     * Convert to array (for serialization, reporting)
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'id'                  => $this->id,
            'auction_id'          => $this->auction_id,
            'user_id'             => $this->user_id,
            'proxy_bid_id'        => $this->proxy_bid_id,
            'bid_amount'          => $this->bid_amount,
            'previous_bid'        => $this->previous_bid,
            'bid_increment_used'  => $this->bid_increment_used,
            'outbidding_bid_id'   => $this->outbidding_bid_id,
            'success'             => $this->success,
            'error_message'       => $this->error_message,
            'processing_time_ms'  => $this->processing_time_ms,
            'triggered_at'        => $this->triggered_at->format( 'Y-m-d H:i:s' ),
        ];
    }
}
