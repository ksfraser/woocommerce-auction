<?php
/**
 * Seller Tier Calculator Service
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-001: Determine seller tier based on YTD volume
 * @requirement REQ-4D-002: Apply tier-based commission discounts
 */

namespace WC\Auction\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SellerTierCalculator - Determines seller tier based on YTD revenue
 *
 * UML Class Diagram:
 * ```
 * SellerTierCalculator (Service)
 * ├── Tier Logic:
 * │   ├── YTD < $10,000 → STANDARD (0% discount)
 * │   ├── YTD >= $10,000 → GOLD (-5% discount)
 * │   └── YTD >= $50,000 → PLATINUM (-10% discount)
 * ├── Core Methods:
 * │   ├── calculateTier(seller_id) : string
 * │   └── getSellerYTDRevenue(seller_id) : int (cents)
 * └── Thresholds:
 *     ├── GOLD_THRESHOLD_CENTS = 1,000,000 ($10,000)
 *     └── PLATINUM_THRESHOLD_CENTS = 5,000,000 ($50,000)
 * ```
 *
 * Algorithm: Tier Determination
 * ```
 * 1. Get seller's YTD revenue (sum of completed offers payments)
 * 2. Compare against thresholds:
 *    - If >= $50k → PLATINUM
 *    - Else if >= $10k → GOLD
 *    - Else → STANDARD
 * 3. Return tier string
 * ```
 *
 * @requirement REQ-4D-001: Determine seller tier for commission rate
 * @requirement REQ-4D-002: Support tiered commission discounts
 * @requirement PERF-4D-002: Tier calculation < 50ms (includes DB query)
 */
class SellerTierCalculator {

    /**
     * Gold tier threshold (YTD >= $10,000 = 1,000,000 cents)
     */
    const GOLD_THRESHOLD_CENTS = 1000000;

    /**
     * Platinum tier threshold (YTD >= $50,000 = 5,000,000 cents)
     */
    const PLATINUM_THRESHOLD_CENTS = 5000000;

    /**
     * Calculate seller tier based on YTD revenue
     *
     * @param int $seller_id Seller ID
     * @return string Tier name: STANDARD|GOLD|PLATINUM
     * @throws \Exception If seller not found
     * @requirement REQ-4D-001: Calculate seller tier
     */
    public function calculateTier( int $seller_id ): string {
        $ytd_revenue_cents = $this->getSellerYTDRevenue( $seller_id );

        if ( $ytd_revenue_cents >= self::PLATINUM_THRESHOLD_CENTS ) {
            return 'PLATINUM';
        }

        if ( $ytd_revenue_cents >= self::GOLD_THRESHOLD_CENTS ) {
            return 'GOLD';
        }

        return 'STANDARD';
    }

    /**
     * Get seller's year-to-date revenue in cents
     *
     * @param int $seller_id Seller ID
     * @return int YTD revenue in cents
     * @requirement REQ-4D-001: Query seller payment history
     */
    private function getSellerYTDRevenue( int $seller_id ): int {
        global $wpdb;

        // Get current year
        $year = (int) date( 'Y' );

        // Query for seller payouts in current year
        // Sum net_payout_cents from completed payouts
        $table_name = $wpdb->prefix . 'wc_auction_seller_payouts';

        $query = $wpdb->prepare(
            "SELECT COALESCE(SUM(net_payout_cents), 0) as total_cents
            FROM {$table_name}
            WHERE seller_id = %d
            AND payout_status = 'COMPLETED'
            AND YEAR(payout_date) = %d",
            $seller_id,
            $year
        );

        $result = (int) $wpdb->get_var( $query );

        return \max( 0, $result );
    }
}
