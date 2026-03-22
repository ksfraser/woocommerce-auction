<?php
/**
 * Proxy Bid Data Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    1.0.0
 * @requirement REQ-AB-001: Define proxy bid structure
 * @requirement REQ-AB-008: Enforce maximum bid constraints
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ProxyBid - Immutable value object representing a user's proxy bid
 *
 * UML Class Diagram:
 * ```
 * ProxyBid (Immutable Value Object)
 * ├── Private Properties:
 * │   ├── id: int
 * │   ├── auction_id: int
 * │   ├── user_id: int
 * │   ├── maximum_bid: decimal
 * │   ├── current_proxy_bid: decimal
 * │   ├── status: string [active|ended|cancelled|outbid]
 * │   ├── cancelled_by_user: bool
 * │   ├── notes: string
 * │   ├── created_at: DateTime
 * │   ├── updated_at: DateTime
 * │   ├── ended_at: DateTime|null
 * │   └── cancelled_at: DateTime|null
 * └── Public Methods:
 *     ├── create() : self
 *     ├── getId() : int
 *     ├── getAuctionId() : int
 *     ├── getUserId() : int
 *     ├── getMaximumBid() : decimal
 *     ├── getCurrentProxyBid() : decimal
 *     ├── getStatus() : string
 *     ├── isActive() : bool
 *     ├── isEnded() : bool
 *     ├── isCancelled() : bool
 *     ├── isOutbid() : bool
 *     ├── toArray() : array
 * ```
 *
 * Design Pattern: Immutable Value Object (Fowler)
 * - No setters; all properties set in constructor only
 * - Factory method (create) for instantiation
 * - Strict type hints for type safety
 * - Status values: 'active', 'ended', 'cancelled', 'outbid'
 *
 * @requirement REQ-AB-001: Store user proxy bids
 * @requirement REQ-AB-008: Enforce maximum bid never exceeded
 */
class ProxyBid {
    
    const STATUS_ACTIVE   = 'active';
    const STATUS_ENDED    = 'ended';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_OUTBID   = 'outbid';
    
    const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ENDED,
        self::STATUS_CANCELLED,
        self::STATUS_OUTBID,
    ];
    
    /**
     * Proxy bid ID
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
     * User ID
     *
     * @var int
     */
    private $user_id;
    
    /**
     * Maximum bid user is willing to pay
     *
     * @var float
     */
    private $maximum_bid;
    
    /**
     * Current proxy bid value (last bid placed on behalf of user)
     *
     * @var float
     */
    private $current_proxy_bid;
    
    /**
     * Proxy bid status
     *
     * @var string
     */
    private $status;
    
    /**
     * Whether user cancelled this proxy bid
     *
     * @var bool
     */
    private $cancelled_by_user;
    
    /**
     * Optional notes field
     *
     * @var string|null
     */
    private $notes;
    
    /**
     * When proxy bid was created
     *
     * @var \DateTime
     */
    private $created_at;
    
    /**
     * When proxy bid was last updated
     *
     * @var \DateTime
     */
    private $updated_at;
    
    /**
     * When auction ended (if applicable)
     *
     * @var \DateTime|null
     */
    private $ended_at;
    
    /**
     * When user cancelled (if applicable)
     *
     * @var \DateTime|null
     */
    private $cancelled_at;
    
    /**
     * Constructor - private; use create() factory instead
     *
     * @param int                 $id
     * @param int                 $auction_id
     * @param int                 $user_id
     * @param float               $maximum_bid
     * @param float               $current_proxy_bid
     * @param string              $status
     * @param bool                $cancelled_by_user
     * @param \DateTime           $created_at
     * @param \DateTime           $updated_at
     * @param string|null         $notes
     * @param \DateTime|null      $ended_at
     * @param \DateTime|null      $cancelled_at
     */
    private function __construct(
        int $id,
        int $auction_id,
        int $user_id,
        float $maximum_bid,
        float $current_proxy_bid,
        string $status,
        bool $cancelled_by_user,
        \DateTime $created_at,
        \DateTime $updated_at,
        ?string $notes = null,
        ?\DateTime $ended_at = null,
        ?\DateTime $cancelled_at = null
    ) {
        $this->id                   = $id;
        $this->auction_id           = $auction_id;
        $this->user_id              = $user_id;
        $this->maximum_bid          = $maximum_bid;
        $this->current_proxy_bid    = $current_proxy_bid;
        $this->status               = $status;
        $this->cancelled_by_user    = $cancelled_by_user;
        $this->created_at           = $created_at;
        $this->updated_at           = $updated_at;
        $this->notes                = $notes;
        $this->ended_at             = $ended_at;
        $this->cancelled_at         = $cancelled_at;
    }
    
    /**
     * Factory method to create ProxyBid from data array
     *
     * @param array $data Array with keys: id, auction_id, user_id, maximum_bid,
     *                     current_proxy_bid, status, cancelled_by_user, created_at,
     *                     updated_at, notes (optional), ended_at (optional), cancelled_at (optional)
     * @return self
     * @throws \InvalidArgumentException If required fields missing or invalid
     */
    public static function create( array $data ): self {
        // Validate required fields
        $required = [
            'id',
            'auction_id',
            'user_id',
            'maximum_bid',
            'current_proxy_bid',
            'status',
            'cancelled_by_user',
            'created_at',
            'updated_at',
        ];
        
        foreach ( $required as $field ) {
            if ( ! isset( $data[ $field ] ) ) {
                throw new \InvalidArgumentException( "Required field missing: {$field}" );
            }
        }
        
        // Validate status
        if ( ! in_array( $data['status'], self::VALID_STATUSES, true ) ) {
            throw new \InvalidArgumentException(
                "Invalid status: {$data['status']}. Valid statuses: " . implode( ', ', self::VALID_STATUSES )
            );
        }
        
        // Validate maximum bid >= current proxy bid (REQ-AB-008)
        if ( $data['current_proxy_bid'] > $data['maximum_bid'] ) {
            throw new \InvalidArgumentException(
                'Current proxy bid cannot exceed maximum bid'
            );
        }
        
        // Parse dates
        $created_at = $data['created_at'] instanceof \DateTime
            ? $data['created_at']
            : new \DateTime( $data['created_at'] );
            
        $updated_at = $data['updated_at'] instanceof \DateTime
            ? $data['updated_at']
            : new \DateTime( $data['updated_at'] );
            
        $ended_at = ! empty( $data['ended_at'] )
            ? ( $data['ended_at'] instanceof \DateTime
                ? $data['ended_at']
                : new \DateTime( $data['ended_at'] ) )
            : null;
            
        $cancelled_at = ! empty( $data['cancelled_at'] )
            ? ( $data['cancelled_at'] instanceof \DateTime
                ? $data['cancelled_at']
                : new \DateTime( $data['cancelled_at'] ) )
            : null;
        
        return new self(
            (int) $data['id'],
            (int) $data['auction_id'],
            (int) $data['user_id'],
            (float) $data['maximum_bid'],
            (float) $data['current_proxy_bid'],
            $data['status'],
            (bool) $data['cancelled_by_user'],
            $created_at,
            $updated_at,
            $data['notes'] ?? null,
            $ended_at,
            $cancelled_at
        );
    }
    
    /**
     * Get proxy bid ID
     *
     * @return int
     */
    public function getId(): int {
        return $this->id;
    }
    
    /**
     * Get auction (product) ID
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
     * Get maximum bid
     *
     * @return float
     */
    public function getMaximumBid(): float {
        return $this->maximum_bid;
    }
    
    /**
     * Get current proxy bid
     *
     * @return float
     */
    public function getCurrentProxyBid(): float {
        return $this->current_proxy_bid;
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
     * Get cancelled by user flag
     *
     * @return bool
     */
    public function isCancelledByUser(): bool {
        return $this->cancelled_by_user;
    }
    
    /**
     * Get notes
     *
     * @return string|null
     */
    public function getNotes(): ?string {
        return $this->notes;
    }
    
    /**
     * Get created at timestamp
     *
     * @return \DateTime
     */
    public function getCreatedAt(): \DateTime {
        return $this->created_at;
    }
    
    /**
     * Get updated at timestamp
     *
     * @return \DateTime
     */
    public function getUpdatedAt(): \DateTime {
        return $this->updated_at;
    }
    
    /**
     * Get ended at timestamp (if applicable)
     *
     * @return \DateTime|null
     */
    public function getEndedAt(): ?\DateTime {
        return $this->ended_at;
    }
    
    /**
     * Get cancelled at timestamp (if applicable)
     *
     * @return \DateTime|null
     */
    public function getCancelledAt(): ?\DateTime {
        return $this->cancelled_at;
    }
    
    /**
     * Check if proxy bid is active
     *
     * @return bool
     */
    public function isActive(): bool {
        return self::STATUS_ACTIVE === $this->status;
    }
    
    /**
     * Check if auction ended
     *
     * @return bool
     */
    public function isEnded(): bool {
        return self::STATUS_ENDED === $this->status;
    }
    
    /**
     * Check if proxy bid cancelled
     *
     * @return bool
     */
    public function isCancelled(): bool {
        return self::STATUS_CANCELLED === $this->status;
    }
    
    /**
     * Check if user was outbid
     *
     * @return bool
     */
    public function isOutbid(): bool {
        return self::STATUS_OUTBID === $this->status;
    }
    
    /**
     * Convert to array (for serialization)
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'id'                  => $this->id,
            'auction_id'          => $this->auction_id,
            'user_id'             => $this->user_id,
            'maximum_bid'         => $this->maximum_bid,
            'current_proxy_bid'   => $this->current_proxy_bid,
            'status'              => $this->status,
            'cancelled_by_user'   => $this->cancelled_by_user,
            'notes'               => $this->notes,
            'created_at'          => $this->created_at->format( 'Y-m-d H:i:s' ),
            'updated_at'          => $this->updated_at->format( 'Y-m-d H:i:s' ),
            'ended_at'            => $this->ended_at ? $this->ended_at->format( 'Y-m-d H:i:s' ) : null,
            'cancelled_at'        => $this->cancelled_at ? $this->cancelled_at->format( 'Y-m-d H:i:s' ) : null,
        ];
    }
}
