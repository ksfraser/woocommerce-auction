<?php
/**
 * Migration: Create wp_wc_auction_proxy_bids table for auto-bidding
 *
 * @package    WooCommerce Auction
 * @subpackage Migrations
 * @version    1.0.0
 * @requirement REQ-AB-001: Proxy bid storage and lifecycle management
 * @requirement REQ-AB-008: Never exceed user's maximum bid
 */

namespace WC\Auction\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Migration class for creating proxy bids table
 *
 * UML Class Diagram:
 * ```
 * Migration_1_4_0_CreateProxyBidsTable
 * ├── up()           → Create table with schema
 * ├── down()         → Drop table (rollback)
 * └── isApplied()    → Check if migration ran
 * ```
 *
 * @requirement REQ-AB-001: Users can set proxy bid max on active auctions
 * @requirement REQ-AB-003: Auto-bids respect bid increment rules
 * @requirement REQ-AB-009: Thread-safe bid processing
 */
class Migration_1_4_0_CreateProxyBidsTable {
    
    /**
     * Forward migration - create table
     *
     * @return bool True if successful
     */
    public static function up(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_proxy_bids';
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            auction_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            maximum_bid DECIMAL(10,2) NOT NULL,
            current_proxy_bid DECIMAL(10,2) NULL,
            status ENUM('active', 'ended', 'cancelled', 'outbid') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ended_at TIMESTAMP NULL,
            cancelled_at TIMESTAMP NULL,
            cancelled_by_user BOOLEAN DEFAULT FALSE,
            notes VARCHAR(255),
            
            UNIQUE KEY uk_auction_user (auction_id, user_id),
            KEY idx_user_status (user_id, status),
            KEY idx_auction_status (auction_id, status),
            KEY idx_created_at (created_at),
            FOREIGN KEY fk_auction (auction_id) REFERENCES {$wpdb->prefix}posts(ID),
            FOREIGN KEY fk_user (user_id) REFERENCES {$wpdb->prefix}users(ID)
        ) $charset";

        $wpdb->query( $sql );

        if ( $wpdb->last_error ) {
            error_log( 'Migration error creating proxy_bids table: ' . $wpdb->last_error );
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

        $table_name = $wpdb->prefix . 'wc_auction_proxy_bids';
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

        $table_name = $wpdb->prefix . 'wc_auction_proxy_bids';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
