<?php
/**
 * Commission Result Data Model
 *
 * @package    WooCommerce Auction
 * @subpackage Models
 * @version    4.0.0
 * @requirement REQ-4D-001: Calculate and store commission results
 * @requirement REQ-4D-002: Ensure accurate financial calculations
 */

namespace WC\Auction\Models;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CommissionResult - Immutable value object for commission calculation result
 *
 * UML Class Diagram:
 * ```
 * CommissionResult (Immutable Value Object)
 * ├── Private Properties:
 * │   ├── seller_id: int
 * │   ├── auction_ids: int[]
 * │   ├── gross_amount_cents: int (total auction revenue)
 * │   ├── commission_rate: float (e.g., 5.00 for 5%)
 * │   ├── commission_cents: int (calculated commission)
 * │   ├── tier_discount_percent: float (e.g., 5.0 for 5% off)
 * │   ├── tier_discount_cents: int
 * │   ├── processor_fees_cents: int (payment processor fees)
 * │   ├── net_payout_cents: int (amount paid to seller)
 * │   └── calculated_at: DateTime
 * └── Public Methods:
 *     ├── create() : self
 *     ├── getSellerId() : int
 *     ├── getGrossAmountCents() : int
 *     ├── getCommissionCents() : int
 *     ├── getTierDiscountCents() : int
 *     ├── getProcessorFeesCents() : int
 *     ├── getNetPayoutCents() : int
 *     ├── toArray() : array
 *     └── validate() : bool
 * ```
 *
 * Design Pattern: Immutable Value Object (Fowler)
 * - No setters; all properties set in factory method constructor
 * - Created via static factory method (create)
 * - Strict type hints for type safety
 * - Facilitates easy testing and composition
 *
 * Calculation Example:
 * ```
 * Gross: $1,000.00 (100,000 cents)
 * Commission rate: 5.00%
 * Commission: $50.00 (5,000 cents)
 * 
 * Seller tier: GOLD (-5% tier discount)
 * Tier discount: $2.50 (250 cents, 5% of $50)
 * Final commission: $47.50 (4,750 cents)
 * 
 * Processor fees (Square): 1.625%
 * Processor fees: $16.25 (1,625 cents, on gross revenue)
 * 
 * Net payout: $1,000.00 - $47.50 - $16.25 = $936.25 (93,625 cents)
 * ```
 *
 * @requirement REQ-4D-001: Store commission calculation result
 * @requirement REQ-4D-002: Ensure financial accuracy (no rounding errors)
 * @requirement PERF-4D-002: Commission calculation < 100ms per seller
 */
class CommissionResult {

    /**
     * Seller ID
     *
     * @var int
     */
    private $seller_id;

    /**
     * Auction IDs included in this commission result
     *
     * @var int[]
     */
    private $auction_ids;

    /**
     * Gross settlement amount in cents
     *
     * @var int
     */
    private $gross_amount_cents;

    /**
     * Commission rate applied (e.g., 5.00 for 5%)
     *
     * @var float
     */
    private $commission_rate;

    /**
     * Calculated commission in cents
     *
     * @var int
     */
    private $commission_cents;

    /**
     * Seller tier discount percentage (e.g., 5.0 for 5% off)
     *
     * @var float
     */
    private $tier_discount_percent;

    /**
     * Tier discount amount in cents
     *
     * @var int
     */
    private $tier_discount_cents;

    /**
     * Payment processor fees in cents
     *
     * @var int
     */
    private $processor_fees_cents;

    /**
     * Final net payout to seller in cents
     *
     * @var int
     */
    private $net_payout_cents;

    /**
     * When calculation was performed
     *
     * @var \DateTime
     */
    private $calculated_at;

    /**
     * Private constructor for immutability
     *
     * @param int     $seller_id Seller ID
     * @param int[]   $auction_ids Auction IDs
     * @param int     $gross_amount_cents Gross amount in cents
     * @param float   $commission_rate Commission rate (e.g., 5.00)
     * @param int     $commission_cents Calculated commission
     * @param float   $tier_discount_percent Tier discount percentage
     * @param int     $tier_discount_cents Tier discount in cents
     * @param int     $processor_fees_cents Processor fees in cents
     * @param int     $net_payout_cents Net payout in cents
     */
    private function __construct(
        int $seller_id,
        array $auction_ids,
        int $gross_amount_cents,
        float $commission_rate,
        int $commission_cents,
        float $tier_discount_percent,
        int $tier_discount_cents,
        int $processor_fees_cents,
        int $net_payout_cents
    ) {
        $this->seller_id            = $seller_id;
        $this->auction_ids          = $auction_ids;
        $this->gross_amount_cents   = $gross_amount_cents;
        $this->commission_rate      = $commission_rate;
        $this->commission_cents     = $commission_cents;
        $this->tier_discount_percent = $tier_discount_percent;
        $this->tier_discount_cents  = $tier_discount_cents;
        $this->processor_fees_cents = $processor_fees_cents;
        $this->net_payout_cents     = $net_payout_cents;
        $this->calculated_at        = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
    }

    /**
     * Factory method to create commission result
     *
     * @param int     $seller_id Seller ID
     * @param int[]   $auction_ids Auction IDs
     * @param int     $gross_amount_cents Gross amount (cents)
     * @param float   $commission_rate Commission rate (e.g., 5.00 for 5%)
     * @param float   $tier_discount_percent Tier discount (e.g., 5.0 for 5% off)
     * @param int     $processor_fees_cents Processor fees (cents)
     * @return self Immutable commission result
     * @throws \InvalidArgumentException If amounts are negative
     * @requirement REQ-4D-001: Create validated commission result
     */
    public static function create(
        int $seller_id,
        array $auction_ids,
        int $gross_amount_cents,
        float $commission_rate,
        float $tier_discount_percent,
        int $processor_fees_cents
    ): self {
        // Validate inputs
        if ( $seller_id <= 0 ) {
            throw new \InvalidArgumentException( 'Seller ID must be positive' );
        }
        if ( $gross_amount_cents < 0 ) {
            throw new \InvalidArgumentException( 'Gross amount cannot be negative' );
        }
        if ( $commission_rate < 0 || $commission_rate > 100 ) {
            throw new \InvalidArgumentException( 'Commission rate must be between 0-100' );
        }
        if ( $tier_discount_percent < 0 || $tier_discount_percent > 100 ) {
            throw new \InvalidArgumentException( 'Tier discount must be between 0-100' );
        }
        if ( $processor_fees_cents < 0 ) {
            throw new \InvalidArgumentException( 'Processor fees cannot be negative' );
        }

        // Calculate commission (cents)
        $commission_cents = (int) round( ( $gross_amount_cents * $commission_rate ) / 100 );

        // Calculate tier discount (cents)
        $tier_discount_cents = (int) round( ( $commission_cents * $tier_discount_percent ) / 100 );

        // Calculate final commission (after tier discount)
        $final_commission_cents = $commission_cents - $tier_discount_cents;

        // Calculate net payout
        $net_payout_cents = $gross_amount_cents - $final_commission_cents - $processor_fees_cents;

        return new self(
            $seller_id,
            $auction_ids,
            $gross_amount_cents,
            $commission_rate,
            $final_commission_cents,
            $tier_discount_percent,
            $tier_discount_cents,
            $processor_fees_cents,
            \max( 0, $net_payout_cents ) // Net payout cannot be negative
        );
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
     * Get auction IDs
     *
     * @return int[]
     */
    public function getAuctionIds(): array {
        return $this->auction_ids;
    }

    /**
     * Get gross amount in cents
     *
     * @return int
     */
    public function getGrossAmountCents(): int {
        return $this->gross_amount_cents;
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
     * Get final commission amount in cents
     *
     * @return int
     */
    public function getCommissionCents(): int {
        return $this->commission_cents;
    }

    /**
     * Get tier discount percentage
     *
     * @return float
     */
    public function getTierDiscountPercent(): float {
        return $this->tier_discount_percent;
    }

    /**
     * Get tier discount amount in cents
     *
     * @return int
     */
    public function getTierDiscountCents(): int {
        return $this->tier_discount_cents;
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
     * Get net payout to seller in cents
     *
     * @return int
     */
    public function getNetPayoutCents(): int {
        return $this->net_payout_cents;
    }

    /**
     * Get calculation timestamp
     *
     * @return \DateTime
     */
    public function getCalculatedAt(): \DateTime {
        return $this->calculated_at;
    }

    /**
     * Convert to associative array
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'seller_id'                => $this->seller_id,
            'auction_ids'              => $this->auction_ids,
            'gross_amount_cents'       => $this->gross_amount_cents,
            'commission_rate'          => $this->commission_rate,
            'commission_cents'         => $this->commission_cents,
            'tier_discount_percent'    => $this->tier_discount_percent,
            'tier_discount_cents'      => $this->tier_discount_cents,
            'processor_fees_cents'     => $this->processor_fees_cents,
            'net_payout_cents'         => $this->net_payout_cents,
            'calculated_at'            => $this->calculated_at->format( 'Y-m-d H:i:s' ),
        ];
    }
}
