<?php
/**
 * Migration: Create wp_wc_auction_seller_payouts table
 *
 * @package    WooCommerce Auction
 * @subpackage Migrations
 * @version    4.0.0
 * @requirement REQ-4D-001: Seller payouts storage and tracking
 * @requirement REQ-4D-003: Payout status lifecycle management
 */

namespace WC\Auction\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Seller Payouts Table Migration
 *
 * UML Class Diagram:
 * ```
 * Migration_4_0_0_CreateSellerPayouts
 * ├── up()           → Create seller_payouts table
 * ├── down()         → Drop table (rollback)
 * └── isApplied()    → Check if migration ran
 * 
 * Table: wp_wc_auction_seller_payouts
 * ├── id (BIGINT, PK, AUTO_INCREMENT)
 * ├── batch_id (BIGINT, FK) - References settlement_batches
 * ├── seller_id (BIGINT, FK) - Seller/vendor ID
 * ├── auction_ids (JSON) - Auctions included in payout
 * ├── gross_amount_cents (BIGINT) - Total auction revenue (cents)
 * ├── commission_amount_cents (BIGINT) - Commission deducted
 * ├── processor_fee_cents (BIGINT) - Payment processor fees
 * ├── net_payout_cents (BIGINT) - Amount paid to seller
 * ├── payout_method (VARCHAR) - ACH, PayPal, Stripe, Wallet
 * ├── payout_status (ENUM) - PENDING|INITIATED|PROCESSING|COMPLETED|FAILED
 * ├── payout_id (VARCHAR) - Processor transaction ID
 * ├── payout_date (DATETIME) - When payout executed
 * ├── settlement_statement_id (BIGINT) - PDF statement reference
 * ├── created_at (DATETIME) - Record creation
 * ├── updated_at (DATETIME) - Last update
 * ├── error_message (TEXT) - Failure reason
 * └── Indexes:
 *     ├── idx_batch_id - Query by batch
 *     ├── idx_seller_id - Query by seller
 *     ├── idx_payout_status - Filter by status
 *     └── idx_payout_date - Sort by date
 * ```
 *
 * @requirement REQ-4D-003: Execute seller payments via multiple processors
 * @requirement REQ-4D-002: Track payout status for each seller
 */
class Migration_4_0_0_CreateSellerPayouts {
    
    /**
     * Forward migration - create seller_payouts table
     *
     * @return bool True if successful
     * @requirement REQ-4D-001: Create table with proper schema
     */
    public static function up(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_seller_payouts';
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            batch_id BIGINT NOT NULL,
            seller_id BIGINT NOT NULL,
            auction_ids JSON DEFAULT NULL,
            gross_amount_cents BIGINT NOT NULL,
            commission_amount_cents BIGINT NOT NULL,
            processor_fee_cents BIGINT NOT NULL,
            net_payout_cents BIGINT NOT NULL,
            payout_method VARCHAR(50) DEFAULT NULL,
            payout_status ENUM('PENDING', 'INITIATED', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
            payout_id VARCHAR(255) DEFAULT NULL,
            payout_date DATETIME DEFAULT NULL,
            settlement_statement_id BIGINT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            error_message TEXT DEFAULT NULL,
            
            FOREIGN KEY fk_batch_id (batch_id) REFERENCES {$wpdb->prefix}wc_auction_settlement_batches(id) ON DELETE CASCADE,
            KEY idx_batch_id (batch_id),
            KEY idx_seller_id (seller_id),
            KEY idx_payout_status (payout_status),
            KEY idx_payout_date (payout_date),
            KEY idx_created_at (created_at)
        ) $charset";

        $wpdb->query( $sql );

        if ( $wpdb->last_error ) {
            error_log( 'Migration error creating seller_payouts table: ' . $wpdb->last_error );
            return false;
        }

        return true;
    }

    /**
     * Backward migration - drop seller_payouts table
     *
     * @return bool True if successful
     */
    public static function down(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_seller_payouts';
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

        $table_name = $wpdb->prefix . 'wc_auction_seller_payouts';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
