<?php
/**
 * Migration: Add auto-bidding columns to wp_wc_auction_bids table
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
 * Migration class for adding auto-bid columns to bids table
 *
 * UML Class Diagram:
 * ```
 * Migration_1_4_0_AddAutoBidToBids
 * ├── up()           → Add columns to bids table
 * ├── down()         → Remove columns (rollback)
 * └── isApplied()    → Check if columns exist
 * ```
 *
 * @requirement REQ-AB-006: Complete audit trail of all bids
 */
class Migration_1_4_0_AddAutoBidToBids {
    
    /**
     * Forward migration - add columns
     *
     * @return bool True if successful
     */
    public static function up(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_bids';

        // Check if columns already exist (idempotent migration)
        $columns = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND TABLE_SCHEMA = %s",
                $table_name,
                DB_NAME
            ) 
        );

        $existing_columns = wp_list_pluck( $columns, 'COLUMN_NAME' );

        $queries = [];

        // Add is_auto_bid column if it doesn't exist
        if ( ! in_array( 'is_auto_bid', $existing_columns, true ) ) {
            $queries[] = "ALTER TABLE $table_name ADD COLUMN is_auto_bid BOOLEAN DEFAULT FALSE";
        }

        // Add triggered_by_bid_id column if it doesn't exist
        if ( ! in_array( 'triggered_by_bid_id', $existing_columns, true ) ) {
            $queries[] = "ALTER TABLE $table_name ADD COLUMN triggered_by_bid_id BIGINT NULL";
        }

        // Add proxy_bid_id column if it doesn't exist
        if ( ! in_array( 'proxy_bid_id', $existing_columns, true ) ) {
            $queries[] = "ALTER TABLE $table_name ADD COLUMN proxy_bid_id BIGINT NULL";
        }

        // Add index on is_auto_bid if it doesn't exist
        $indexes = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME = %s AND TABLE_SCHEMA = %s AND COLUMN_NAME = 'is_auto_bid'",
                $table_name,
                DB_NAME
            ) 
        );

        if ( empty( $indexes ) ) {
            $queries[] = "ALTER TABLE $table_name ADD KEY idx_auto_bid (is_auto_bid)";
        }

        // Add foreign key for proxy_bid_id if it doesn't exist
        $foreign_keys = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = %s AND TABLE_SCHEMA = %s AND COLUMN_NAME = 'proxy_bid_id' AND REFERENCED_TABLE_NAME IS NOT NULL",
                $table_name,
                DB_NAME
            ) 
        );

        if ( empty( $foreign_keys ) ) {
            $queries[] = "ALTER TABLE $table_name ADD FOREIGN KEY fk_proxy_bid (proxy_bid_id) REFERENCES {$wpdb->prefix}wc_auction_proxy_bids(id) ON DELETE SET NULL";
        }

        // Execute all queries
        foreach ( $queries as $query ) {
            $wpdb->query( $query );
            if ( $wpdb->last_error ) {
                error_log( 'Migration error adding auto-bid columns: ' . $wpdb->last_error );
                return false;
            }
        }

        return true;
    }

    /**
     * Backward migration - remove columns
     *
     * @return bool True if successful
     */
    public static function down(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_bids';

        // Drop foreign key first
        $wpdb->query( "ALTER TABLE $table_name DROP FOREIGN KEY IF EXISTS fk_proxy_bid" );
        $wpdb->query( "ALTER TABLE $table_name DROP INDEX IF EXISTS idx_auto_bid" );

        // Drop columns
        $wpdb->query( "ALTER TABLE $table_name DROP COLUMN IF EXISTS is_auto_bid" );
        $wpdb->query( "ALTER TABLE $table_name DROP COLUMN IF EXISTS triggered_by_bid_id" );
        $wpdb->query( "ALTER TABLE $table_name DROP COLUMN IF EXISTS proxy_bid_id" );

        return ! $wpdb->last_error;
    }

    /**
     * Check if migration has been applied
     *
     * @return bool True if columns exist
     */
    public static function isApplied(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_bids';
        
        $columns = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND TABLE_SCHEMA = %s AND COLUMN_NAME IN ('is_auto_bid', 'proxy_bid_id')",
                $table_name,
                DB_NAME
            ) 
        );

        return count( $columns ) >= 2;
    }
}
