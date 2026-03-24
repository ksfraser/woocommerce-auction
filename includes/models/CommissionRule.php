<?php
/**
 * Commission Rule Data Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    4.0.0
 * @requirement REQ-4D-001: Define commission rules
 * @requirement REQ-4D-002: Support tier-based commission rules
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CommissionRule - Immutable value object representing a commission calculation rule
 *
 * UML Class Diagram:
 * ```
 * CommissionRule (Immutable Value Object)
 * ├── Private Properties:
 * │   ├── id: int|null
 * │   ├── rule_name: string
 * │   ├── seller_tier: string [STANDARD|GOLD|PLATINUM]
 * │   ├── commission_type: string [PERCENTAGE|FIXED]
 * │   ├── commission_rate: float (e.g., 5.00 for 5%)
 * │   ├── minimum_bid_threshold_cents: int
 * │   ├── active: bool
 * │   ├── effective_from: DateTime
 * │   ├── effective_to: DateTime|null
 * │   └── created_at: DateTime
 * └── Public Methods:
 *     ├── create() : self
 *     ├── fromDatabase() : self
 * │   ├── getId() : int|null
 * │   ├── getRuleName() : string
 * │   ├── getSellerTier() : string
 * │   ├── getCommissionType() : string
 * │   ├── getCommissionRate() : float
 * │   ├── isActive() : bool
 * │   ├── isEffectiveAt() : bool
 * │   ├── toArray() : array
 * ```
 *
 * Tier Hierarchy:
 * ```
 * STANDARD: Base 5.00% commission
 *   └─ Applies to all sellers by default
 *
 * GOLD: 4.75% commission (-5% tier discount from base)
 *   └─ YTD revenue >= $10,000
 *
 * PLATINUM: 4.50% commission (-10% tier discount from base)
 *   └─ YTD revenue >= $50,000
 *
 * Formula: final_rate = base_rate - (base_rate * tier_discount%)
 * Example: 5.00 - (5.00 * 0.05) = 4.75
 * ```
 *
 * Design Pattern: Immutable Value Object
 * - No setters; all properties set in factory method
 * - Created via static factory method (create)
 * - Effective period defines when rule applies
 * - Support for historical rules (non-active but kept for audit)
 *
 * @requirement REQ-4D-001: Define configurable commission rules
 * @requirement REQ-4D-002: Support multiple seller tiers with different rates
 * @requirement REQ-4D-005: Audit trail of rule changes (historical rules retained)
 */
class CommissionRule {

    const TIER_STANDARD  = 'STANDARD';
    const TIER_GOLD      = 'GOLD';
    const TIER_PLATINUM  = 'PLATINUM';

    const TYPE_PERCENTAGE = 'PERCENTAGE';
    const TYPE_FIXED      = 'FIXED';

    const DEFAULT_COMMISSION_RATE = 5.00;

    /**
     * Rule database ID
     *
     * @var int|null
     */
    private $id;

    /**
     * Human-readable rule name
     *
     * @var string
     */
    private $rule_name;

    /**
     * Seller tier this rule applies to
     *
     * @var string
     */
    private $seller_tier;

    /**
     * Commission type (PERCENTAGE or FIXED)
     *
     * @var string
     */
    private $commission_type;

    /**
     * Commission rate (e.g., 5.00 for 5% or fixed cents amount)
     *
     * @var float
     */
    private $commission_rate;

    /**
     * Minimum auction value threshold in cents (0 = no minimum)
     *
     * @var int
     */
    private $minimum_bid_threshold_cents;

    /**
     * Is rule currently active
     *
     * @var bool
     */
    private $active;

    /**
     * When rule becomes effective
     *
     * @var \DateTime
     */
    private $effective_from;

    /**
     * When rule expires (null = never expires)
     *
     * @var \DateTime|null
     */
    private $effective_to;

    /**
     * When rule was created
     *
     * @var \DateTime
     */
    private $created_at;

    /**
     * Private constructor for immutability
     */
    private function __construct(
        ?int $id,
        string $rule_name,
        string $seller_tier,
        string $commission_type,
        float $commission_rate,
        int $minimum_bid_threshold_cents,
        bool $active,
        \DateTime $effective_from,
        ?\DateTime $effective_to,
        \DateTime $created_at
    ) {
        $this->id                          = $id;
        $this->rule_name                   = $rule_name;
        $this->seller_tier                 = $seller_tier;
        $this->commission_type             = $commission_type;
        $this->commission_rate             = $commission_rate;
        $this->minimum_bid_threshold_cents = $minimum_bid_threshold_cents;
        $this->active                      = $active;
        $this->effective_from              = $effective_from;
        $this->effective_to                = $effective_to;
        $this->created_at                  = $created_at;
    }

    /**
     * Factory method to create new commission rule
     *
     * @param string        $rule_name Human-readable name
     * @param string        $seller_tier Seller tier (STANDARD|GOLD|PLATINUM)
     * @param float         $commission_rate Rate or fixed amount
     * @param string        $commission_type PERCENTAGE or FIXED
     * @param int           $minimum_bid_threshold_cents Minimum auction value (cents)
     * @return self New commission rule
     * @throws \InvalidArgumentException If parameters are invalid
     * @requirement REQ-4D-001: Create commission rule
     */
    public static function create(
        string $rule_name,
        string $seller_tier,
        float $commission_rate,
        string $commission_type = self::TYPE_PERCENTAGE,
        int $minimum_bid_threshold_cents = 0
    ): self {
        // Validate inputs
        if ( empty( $rule_name ) ) {
            throw new \InvalidArgumentException( 'Rule name cannot be empty' );
        }

        $valid_tiers = [ self::TIER_STANDARD, self::TIER_GOLD, self::TIER_PLATINUM ];
        if ( ! in_array( $seller_tier, $valid_tiers, true ) ) {
            throw new \InvalidArgumentException( "Invalid seller tier: {$seller_tier}" );
        }

        $valid_types = [ self::TYPE_PERCENTAGE, self::TYPE_FIXED ];
        if ( ! in_array( $commission_type, $valid_types, true ) ) {
            throw new \InvalidArgumentException( "Invalid commission type: {$commission_type}" );
        }

        if ( $commission_rate < 0 ) {
            throw new \InvalidArgumentException( 'Commission rate cannot be negative' );
        }

        if ( $commission_type === self::TYPE_PERCENTAGE && $commission_rate > 100 ) {
            throw new \InvalidArgumentException( 'Percentage commission rate cannot exceed 100' );
        }

        if ( $minimum_bid_threshold_cents < 0 ) {
            throw new \InvalidArgumentException( 'Minimum bid threshold cannot be negative' );
        }

        return new self(
            null,
            $rule_name,
            $seller_tier,
            $commission_type,
            $commission_rate,
            $minimum_bid_threshold_cents,
            true,
            new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ),
            null,
            new \DateTime( 'now', new \DateTimeZone( 'UTC' ) )
        );
    }

    /**
     * Factory method to create rule from database record
     *
     * @param array $data Database row data
     * @return self
     * @throws \InvalidArgumentException If data is incomplete
     */
    public static function fromDatabase( array $data ): self {
        $required_keys = [ 'id', 'rule_name', 'seller_tier', 'commission_type', 'commission_rate' ];
        foreach ( $required_keys as $key ) {
            if ( ! isset( $data[ $key ] ) ) {
                throw new \InvalidArgumentException( "Missing required key: {$key}" );
            }
        }

        return new self(
            (int) $data['id'],
            (string) $data['rule_name'],
            (string) $data['seller_tier'],
            (string) $data['commission_type'],
            (float) $data['commission_rate'],
            (int) ( $data['minimum_bid_threshold_cents'] ?? 0 ),
            (bool) ( $data['active'] ?? true ),
            new \DateTime( $data['effective_from'], new \DateTimeZone( 'UTC' ) ),
            isset( $data['effective_to'] ) && ! empty( $data['effective_to'] ) ? new \DateTime( $data['effective_to'], new \DateTimeZone( 'UTC' ) ) : null,
            new \DateTime( $data['created_at'], new \DateTimeZone( 'UTC' ) )
        );
    }

    /**
     * Get rule ID
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->id;
    }

    /**
     * Get rule name
     *
     * @return string
     */
    public function getRuleName(): string {
        return $this->rule_name;
    }

    /**
     * Get seller tier
     *
     * @return string
     */
    public function getSellerTier(): string {
        return $this->seller_tier;
    }

    /**
     * Get commission type
     *
     * @return string
     */
    public function getCommissionType(): string {
        return $this->commission_type;
    }

    /**
     * Get commission rate
     *
     * @return float
     */
    public function getCommissionRate(): float {
        return $this->commission_rate;
    }

    /**
     * Get minimum bid threshold in cents
     *
     * @return int
     */
    public function getMinimumBidThresholdCents(): int {
        return $this->minimum_bid_threshold_cents;
    }

    /**
     * Check if rule is active
     *
     * @return bool
     */
    public function isActive(): bool {
        return $this->active;
    }

    /**
     * Check if rule is effective at given time
     *
     * @param \DateTime|null $datetime DateTime to check (null = now)
     * @return bool
     */
    public function isEffectiveAt( ?\DateTime $datetime = null ): bool {
        if ( ! $this->active ) {
            return false;
        }

        if ( null === $datetime ) {
            $datetime = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        }

        if ( $datetime < $this->effective_from ) {
            return false;
        }

        if ( null !== $this->effective_to && $datetime > $this->effective_to ) {
            return false;
        }

        return true;
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'id'                               => $this->id,
            'rule_name'                        => $this->rule_name,
            'seller_tier'                      => $this->seller_tier,
            'commission_type'                  => $this->commission_type,
            'commission_rate'                  => $this->commission_rate,
            'minimum_bid_threshold_cents'      => $this->minimum_bid_threshold_cents,
            'active'                           => $this->active,
            'effective_from'                   => $this->effective_from->format( 'Y-m-d H:i:s' ),
            'effective_to'                     => $this->effective_to ? $this->effective_to->format( 'Y-m-d H:i:s' ) : null,
            'created_at'                       => $this->created_at->format( 'Y-m-d H:i:s' ),
        ];
    }
}
