<?php
/**
 * Migration: Create wp_wc_auction_settlement_batches table
 *
 * @package    WooCommerce Auction
 * @subpackage Migrations
 * @version    4.0.0
 * @requirement REQ-4D-001: Settlement batches storage and lifecycle
 * @requirement REQ-4D-002: Batch status tracking (DRAFT, VALIDATED, PROCESSING, COMPLETED)
 */

namespace WC\Auction\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settlement Batches Table Migration
 *
 * UML Class Diagram:
 * ```
 * Migration_4_0_0_CreateSettlementBatches
 * ├── up()           → Create settlement_batches table
 * ├── down()         → Drop table (rollback)
 * └── isApplied()    → Check if migration ran
 * 
 * Table: wp_wc_auction_settlement_batches
 * ├── id (BIGINT, PK, AUTO_INCREMENT)
 * ├── batch_number (VARCHAR, UNIQUE) - Daily batch identifier
 * ├── settlement_date (DATE) - When settlement calculated
 * ├── batch_period_start (DATE) - Auction period start
 * ├── batch_period_end (DATE) - Auction period end
 * ├── status (ENUM) - DRAFT|VALIDATED|PROCESSING|COMPLETED|CANCELLED
 * ├── total_amount_cents (BIGINT) - Total gross amount (cents)
 * ├── commission_amount_cents (BIGINT) - Total commission collected
 * ├── processor_fees_cents (BIGINT) - Total payment processor fees
 * ├── payout_count (INT) - Number of sellers paid in batch
 * ├── created_at (DATETIME) - Batch creation timestamp
 * ├── processed_at (DATETIME) - Completion timestamp
 * ├── notes (LONGTEXT) - Batch notes/errors
 * └── Indexes:
 *     ├── idx_settlement_date - Query by date
 *     ├── idx_status - Filter by status
 *     ├── idx_batch_number - Lookup by number
 *     └── idx_created_at - Sort by creation
 * ```
 *
 * @requirement REQ-4D-001: Calculate daily settlement batches
 * @requirement REQ-4D-002: Track settlement batch status and progress
 */
class Migration_4_0_0_CreateSettlementBatches {
    
    /**
     * Forward migration - create settlement_batches table
     *
     * @return bool True if successful
     * @requirement REQ-4D-001: Create table with proper schema
     */
    public static function up(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_settlement_batches';
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            batch_number VARCHAR(50) NOT NULL,
            settlement_date DATE NOT NULL,
            batch_period_start DATE NOT NULL,
            batch_period_end DATE NOT NULL,
            status ENUM('DRAFT', 'VALIDATED', 'PROCESSING', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
            total_amount_cents BIGINT NOT NULL DEFAULT 0,
            commission_amount_cents BIGINT NOT NULL DEFAULT 0,
            processor_fees_cents BIGINT NOT NULL DEFAULT 0,
            payout_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            
            UNIQUE KEY uk_batch_number (batch_number),
            KEY idx_settlement_date (settlement_date),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) $charset";

        $wpdb->query( $sql );

        if ( $wpdb->last_error ) {
            error_log( 'Migration error creating settlement_batches table: ' . $wpdb->last_error );
            return false;
        }

        return true;
    }

    /**
     * Backward migration - drop settlement_batches table
     *
     * @return bool True if successful
     */
    public static function down(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_auction_settlement_batches';
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

        $table_name = $wpdb->prefix . 'wc_auction_settlement_batches';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
