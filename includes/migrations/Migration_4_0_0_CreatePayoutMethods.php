<?php
/**
 * Migration: Create wp_wc_auction_seller_payout_methods table
 *
 * @package    WooCommerce Auction
 * @subpackage Migrations
 * @version    4.0.0
 * @requirement REQ-4D-001: Store seller payout method information
 * @requirement SEC-4D-001: PCI-DSS compliance - encrypt banking details
 */

namespace WC\Auction\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Seller Payout Methods Table Migration
 *
 * UML Class Diagram:
 * ```
 * Migration_4_0_0_CreatePayoutMethods
 * ├── up()           → Create payout_methods table
 * ├── down()         → Drop table (rollback)
 * └── isApplied()    → Check if migration ran
 * 
 * Table: wp_wc_auction_seller_payout_methods
 * ├── id (BIGINT, PK, AUTO_INCREMENT)
 * ├── seller_id (BIGINT) - Seller/vendor ID
 * ├── method_type (ENUM) - ACH, PAYPAL, STRIPE, WALLET
 * ├── is_primary (BOOLEAN) - Primary payment method for seller
 * ├── account_holder_name (VARCHAR) - Account owner name
 * ├── account_last_four (VARCHAR) - Last 4 digits (unencrypted for UI)
 * ├── banking_details_encrypted (LONGTEXT) - AES-256 encrypted JSON
 * ├── verified (BOOLEAN) - Micro-deposit or processor verification status
 * ├── verification_date (DATETIME) - When method verified
 * ├── created_at (DATETIME) - Method creation
 * ├── updated_at (DATETIME) - Last update
 * └── Indexes:
 *     ├── idx_seller_id - Query by seller
 *     ├── idx_method_type - Filter by type
 *     └── idx_is_primary - Find primary method
 * ```
 *
 * Security Notes:
 * - banking_details_encrypted stores AES-256 encrypted data
 * - Only account_last_four is stored unencrypted for UI display
 * - Full details never transmitted in cleartext
 * - Encryption key stored separately in environment config
 * - PCI-DSS Level 1 compliance required
 *
 * @requirement REQ-4D-004: Store seller banking details securely
 * @requirement SEC-4D-001: Encrypt all banking information (AES-256)
 * @requirement SEC-4D-002: Full banking details never in logs
 */
class Migration_4_0_0_CreatePayoutMethods {
    
    /**
     * Forward migration - create payout_methods table
     *
     * @return bool True if successful
     * @requirement REQ-4D-001: Create table with encryption support
     */
    public static function up(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_seller_payout_methods';
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            seller_id BIGINT NOT NULL,
            method_type ENUM('ACH', 'PAYPAL', 'STRIPE', 'WALLET') NOT NULL,
            is_primary BOOLEAN NOT NULL DEFAULT FALSE,
            account_holder_name VARCHAR(255) DEFAULT NULL,
            account_last_four VARCHAR(4) DEFAULT NULL,
            banking_details_encrypted LONGTEXT NOT NULL,
            verified BOOLEAN NOT NULL DEFAULT FALSE,
            verification_date DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            KEY idx_seller_id (seller_id),
            KEY idx_method_type (method_type),
            KEY idx_is_primary (is_primary),
            KEY idx_verified (verified)
        ) $charset";

        $wpdb->query( $sql );

        if ( $wpdb->last_error ) {
            error_log( 'Migration error creating seller_payout_methods table: ' . $wpdb->last_error );
            return false;
        }

        return true;
    }

    /**
     * Backward migration - drop payout_methods table
     *
     * @return bool True if successful
     */
    public static function down(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_seller_payout_methods';
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

        $table_name = $wpdb->prefix . 'wc_auction_seller_payout_methods';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
