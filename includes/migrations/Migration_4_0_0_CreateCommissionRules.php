<?php
/**
 * Migration: Create wp_wc_auction_commission_rules table
 *
 * @package    WooCommerce Auction
 * @subpackage Migrations
 * @version    4.0.0
 * @requirement REQ-4D-001: Store configurable commission rules
 * @requirement REQ-4D-002: Support seller tier-based discounts
 */

namespace WC\Auction\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commission Rules Table Migration
 *
 * UML Class Diagram:
 * ```
 * Migration_4_0_0_CreateCommissionRules
 * ├── up()           → Create commission_rules table
 * ├── down()         → Drop table (rollback)
 * └── isApplied()    → Check if migration ran
 * 
 * Table: wp_wc_auction_commission_rules
 * ├── id (BIGINT, PK, AUTO_INCREMENT)
 * ├── rule_name (VARCHAR) - Human readable rule name
 * ├── seller_tier (VARCHAR) - STANDARD, GOLD, PLATINUM
 * ├── commission_type (VARCHAR) - PERCENTAGE (default), FIXED
 * ├── commission_rate (DECIMAL) - Rate percentage (e.g., 5.00)
 * ├── minimum_bid_threshold_cents (BIGINT) - Min auction value (cents)
 * ├── active (BOOLEAN) - Is rule currently active
 * ├── effective_from (DATETIME) - When rule starts applying
 * ├── effective_to (DATETIME) - When rule expires (NULL = never)
 * ├── created_at (DATETIME) - Rule creation
 * └── Indexes:
 *     ├── idx_seller_tier - Lookup by tier
 *     ├── idx_active - Find active rules only
 * 
 * Default Rules Loaded:
 * - STANDARD tier: 5.00% commission
 * - GOLD tier (YTD $10k): 4.75% commission (5% - 5% discount)
 * - PLATINUM tier (YTD $50k): 4.50% commission (5% - 10% discount)
 * ```
 *
 * Commission Calculation Formula:
 * ```
 * commission = (gross_amount * rate) / 100
 * tier_discount = tier_applied_discount / 100
 * final_commission = commission - (commission * tier_discount)
 * net_payout = gross_amount - final_commission - processor_fees
 * ```
 *
 * @requirement REQ-4D-001: Calculate commissions based on configurable rules
 * @requirement REQ-4D-002: Support tier-based discounts for high-volume sellers
 */
class Migration_4_0_0_CreateCommissionRules {
    
    /**
     * Forward migration - create commission_rules table
     *
     * @return bool True if successful
     * @requirement REQ-4D-001: Create table and seed default rules
     */
    public static function up(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_commission_rules';
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            rule_name VARCHAR(100) NOT NULL,
            seller_tier VARCHAR(50) NOT NULL,
            commission_type VARCHAR(50) NOT NULL DEFAULT 'PERCENTAGE',
            commission_rate DECIMAL(5, 2) NOT NULL,
            minimum_bid_threshold_cents BIGINT DEFAULT 0,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            effective_to DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            KEY idx_seller_tier (seller_tier),
            KEY idx_active (active),
            KEY idx_effective_from (effective_from)
        ) $charset";

        $wpdb->query( $sql );

        if ( $wpdb->last_error ) {
            error_log( 'Migration error creating commission_rules table: ' . $wpdb->last_error );
            return false;
        }

        // Seed default commission rules
        self::seed_default_rules();

        return true;
    }

    /**
     * Seed default commission rules on table creation
     *
     * @return void
     */
    private static function seed_default_rules(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_commission_rules';
        $now        = current_time( 'mysql', false );

        $rules = [
            [
                'rule_name'                  => 'Standard Commission for All Sellers',
                'seller_tier'                => 'STANDARD',
                'commission_type'            => 'PERCENTAGE',
                'commission_rate'            => 5.00,
                'minimum_bid_threshold_cents' => 0,
                'active'                     => true,
                'effective_from'             => $now,
            ],
            [
                'rule_name'                  => 'Gold Tier Discount (5% off for $10k+ YTD)',
                'seller_tier'                => 'GOLD',
                'commission_type'            => 'PERCENTAGE',
                'commission_rate'            => 4.75,
                'minimum_bid_threshold_cents' => 0,
                'active'                     => true,
                'effective_from'             => $now,
            ],
            [
                'rule_name'                  => 'Platinum Tier Discount (10% off for $50k+ YTD)',
                'seller_tier'                => 'PLATINUM',
                'commission_type'            => 'PERCENTAGE',
                'commission_rate'            => 4.50,
                'minimum_bid_threshold_cents' => 0,
                'active'                     => true,
                'effective_from'             => $now,
            ],
        ];

        foreach ( $rules as $rule ) {
            $wpdb->insert( $table_name, $rule );
        }
    }

    /**
     * Backward migration - drop commission_rules table
     *
     * @return bool True if successful
     */
    public static function down(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_commission_rules';
        $wpdb->query( "DROP TABLE IF EXISTS $table_name" );

        return ! $wpdb->last_error;
    }

    /**
     * Check if migration has been applied
     *
     * @return bool True if table exists
     */
    public static function isApplied(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_commission_rules';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
