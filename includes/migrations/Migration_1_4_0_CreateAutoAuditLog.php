<?php
/**
 * Migration: Create wp_wc_auction_auto_bid_log table for audit trail
 *
 * @package    WooCommerce Auction
 * @subpackage Migrations
 * @version    1.0.0
 * @requirement REQ-AB-006: Complete audit trail of all bids
 */

namespace WC\Auction\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Migration class for creating auto bid log table
 *
 * UML Class Diagram:
 * ```
 * Migration_1_4_0_CreateAutoAuditLog
 * ├── up()           → Create table with schema
 * ├── down()         → Drop table (rollback)
 * └── isApplied()    → Check if migration ran
 * ```
 *
 * @requirement REQ-AB-006: Complete audit trail of all bids
 * @requirement REQ-AB-005: 99.9% auto-bid success rate (track failures)
 */
class Migration_1_4_0_CreateAutoAuditLog {
    
    /**
     * Forward migration - create table
     *
     * @return bool True if successful
     */
    public static function up(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_auto_bid_log';
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            auction_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            proxy_bid_id BIGINT NOT NULL,
            bid_amount DECIMAL(10,2) NOT NULL,
            previous_bid DECIMAL(10,2) NULL,
            bid_increment_used DECIMAL(10,2) NULL,
            outbidding_bid_id BIGINT NULL,
            success BOOLEAN DEFAULT TRUE,
            error_message VARCHAR(500) NULL,
            processing_time_ms INT NULL,
            triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            KEY idx_auction (auction_id),
            KEY idx_user (user_id),
            KEY idx_proxy (proxy_bid_id),
            KEY idx_triggered (triggered_at),
            KEY idx_success (success),
            FOREIGN KEY fk_auction (auction_id) REFERENCES {$wpdb->prefix}posts(ID),
            FOREIGN KEY fk_user (user_id) REFERENCES {$wpdb->prefix}users(ID),
            FOREIGN KEY fk_proxy (proxy_bid_id) REFERENCES {$wpdb->prefix}wc_auction_proxy_bids(id),
            FOREIGN KEY fk_outbid (outbidding_bid_id) REFERENCES {$wpdb->prefix}posts(ID)
        ) $charset";

        $wpdb->query( $sql );

        if ( $wpdb->last_error ) {
            error_log( 'Migration error creating auto_bid_log table: ' . $wpdb->last_error );
            return false;
        }

        return true;
    }

    /**
     * Backward migration - drop table
     *
     * @return bool True if successful
     */
    public static function down(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_auto_bid_log';
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

        $table_name = $wpdb->prefix . 'wc_auction_auto_bid_log';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
