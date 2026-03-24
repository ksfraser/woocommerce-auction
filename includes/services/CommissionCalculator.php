<?php
/**
 * Commission Calculator Service
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-001: Calculate commissions with tier discounts
 * @requirement REQ-4D-002: Support multiple payment processors
 * @requirement PERF-4D-002: Calculate commission < 100ms
 */

namespace WC\Auction\Services;

use WC\Auction\Models\CommissionResult;
use WC\Auction\Repositories\CommissionRuleRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CommissionCalculator - Core commission calculation logic
 *
 * UML Class Diagram:
 * ```
 * CommissionCalculator (Service)
 * ├── Dependencies:
 * │   ├── CommissionRuleRepository
 * │   ├── SellerTierCalculator
 * │   └── Logger
 * ├── Core Methods:
 * │   ├── calculateCommission(auction_data) : CommissionResult
 * │   ├── applyTierDiscount(base_rate, tier) : float
 * │   ├── deductProcessorFees(amount, processor) : int
 * │   └── calculateTierDiscount(rate, discount%) : float
 * └── Processors:
 *     ├── SQUARE (0.25 + 1.0%)
 *     ├── PAYPAL (0.30 + 1.5%)
 *     └── STRIPE (0.30 + 1.0%)
 * ```
 *
 * Algorithm: Commission Calculation
 * ```
 * 1. Get seller tier (via SellerTierCalculator)
 * 2. Get commission rule for tier
 * 3. Calculate base commission: (gross * rate) / 100
 * 4. Apply tier discount: commission - (commission * discount%)
 * 5. Deduct processor fees
 * 6. Calculate net payout: gross -commission - fees
 * 7. Validate all amounts (no negative)
 * 8. Return CommissionResult
 * ```
 *
 * @requirement REQ-4D-001: Calculate seller commissions
 * @requirement REQ-4D-002: Apply tier-based discounts
 * @requirement PERF-4D-002: < 100ms calculation per seller
 */
class CommissionCalculator {

    /**
     * Processor fees: Square (cents + percentage)
     */
    const SQUARE_FIXED_CENTS      = 25;
    const SQUARE_PERCENTAGE       = 1.0;

    /**
     * Processor fees: PayPal
     */
    const PAYPAL_FIXED_CENTS      = 30;
    const PAYPAL_PERCENTAGE       = 1.5;

    /**
     * Processor fees: Stripe
     */
    const STRIPE_FIXED_CENTS      = 30;
    const STRIPE_PERCENTAGE       = 1.0;

    /**
     * Commission rule repository
     *
     * @var CommissionRuleRepository
     */
    private $rule_repository;

    /**
     * Seller tier calculator
     *
     * @var SellerTierCalculator
     */
    private $tier_calculator;

    /**
     * Constructor
     *
     * @param CommissionRuleRepository $rule_repository Rule repository
     * @param SellerTierCalculator     $tier_calculator Tier calculator
     */
    public function __construct(
        CommissionRuleRepository $rule_repository,
        SellerTierCalculator $tier_calculator
    ) {
        $this->rule_repository = $rule_repository;
        $this->tier_calculator = $tier_calculator;
    }

    /**
     * Calculate commission for multiple auctions
     *
     * @param int   $seller_id Seller ID
     * @param int[] $auction_ids Auction IDs
     * @param int   $gross_amount_cents Total gross revenue (cents)
     * @param string $payment_processor Payment processor (SQUARE|PAYPAL|STRIPE)
     * @return CommissionResult Commission calculation result
     * @throws \InvalidArgumentException If inputs are invalid
     * @requirement REQ-4D-001: Calculate commission for settlement
     */
    public function calculateCommission(
        int $seller_id,
        array $auction_ids,
        int $gross_amount_cents,
        string $payment_processor = 'SQUARE'
    ): CommissionResult {
        // Validate inputs
        if ( $seller_id <= 0 ) {
            throw new \InvalidArgumentException( 'Seller ID must be positive' );
        }
        if ( empty( $auction_ids ) ) {
            throw new \InvalidArgumentException( 'At least one auction ID required' );
        }
        if ( $gross_amount_cents < 0 ) {
            throw new \InvalidArgumentException( 'Gross amount cannot be negative' );
        }

        // Calculate seller tier
        $seller_tier = $this->tier_calculator->calculateTier( $seller_id );

        // Get commission rule for tier
        $rule = $this->rule_repository->findByTier( $seller_tier );
        if ( ! $rule ) {
            throw new \Exception( "No commission rule found for tier: {$seller_tier}" );
        }

        // Get base commission rate
        $commission_rate = $rule->getCommissionRate();

        // Calculate tier discount (GOLD=-5%, PLATINUM=-10%)
        $tier_discount_percent = $this->getTierdiscount( $seller_tier );

        // Calculate processor fees
        $processor_fees_cents = $this->calculateProcessorFees( $gross_amount_cents, $payment_processor );

        // Create and return commission result
        return CommissionResult::create(
            $seller_id,
            $auction_ids,
            $gross_amount_cents,
            $commission_rate,
            $tier_discount_percent,
            $processor_fees_cents
        );
    }

    /**
     * Get tier discount percentage
     *
     * @param string $seller_tier Seller tier
     * @return float Discount percentage (0.0 for none)
     */
    private function getTierDiscount( string $seller_tier ): float {
        switch ( $seller_tier ) {
            case 'GOLD':
                return 5.0; // 5% off commission
            case 'PLATINUM':
                return 10.0; // 10% off commission
            default:
                return 0.0; // STANDARD - no discount
        }
    }

    /**
     * Calculate payment processor fees
     *
     * @param int    $gross_amount_cents Gross amount (cents)
     * @param string $processor Processor name
     * @return int Processor fees (cents)
     * @requirement REQ-4D-001: Include payment processor fees in calculation
     */
    private function calculateProcessorFees( int $gross_amount_cents, string $processor ): int {
        $processor = strtoupper( $processor );

        switch ( $processor ) {
            case 'SQUARE':
                // Square: $0.25 + 1.0%
                $variable = (int) round( ( $gross_amount_cents * self::SQUARE_PERCENTAGE ) / 100 );
                return self::SQUARE_FIXED_CENTS + $variable;

            case 'PAYPAL':
                // PayPal: $0.30 + 1.5%
                $variable = (int) round( ( $gross_amount_cents * self::PAYPAL_PERCENTAGE ) / 100 );
                return self::PAYPAL_FIXED_CENTS + $variable;

            case 'STRIPE':
                // Stripe: $0.30 + 1.0%
                $variable = (int) round( ( $gross_amount_cents * self::STRIPE_PERCENTAGE ) / 100 );
                return self::STRIPE_FIXED_CENTS + $variable;

            default:
                // Default to Square if unknown
                $variable = (int) round( ( $gross_amount_cents * self::SQUARE_PERCENTAGE ) / 100 );
                return self::SQUARE_FIXED_CENTS + $variable;
        }
    }
}
