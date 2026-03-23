<?php
/**
 * Migration: Create Bid Queue Table
 * 
 * Creates the wp_bid_queue table for storing job queue data
 * Supports priority ordering, retry tracking, and TTL expiration
 * 
 * @package WC\Auction\Database\Migrations
 */

namespace WC\Auction\Database\Migrations;

/**
 * CreateBidQueueTable migration
 * 
 * SQL migration for creating the bid_queue table
 * Version: 1.0.0
 */
class CreateBidQueueTable
{
    /**
     * Migration version
     * @var string
     */
    const VERSION = '1.0.0';

    /**
     * Table name
     * @var string
     */
    private $tableName;

    /**
     * Initialize migration
     */
    public function __construct()
    {
        global $wpdb;
        $this->tableName = $wpdb->prefix . 'bid_queue';
    }

    /**
     * Get up migration SQL
     * 
     * Returns SQL to create the bid_queue table
     * 
     * @return string SQL to execute
     */
    public function up(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                job_id VARCHAR(100) NOT NULL UNIQUE,
                status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
                priority VARCHAR(10) NOT NULL DEFAULT 'NORMAL',
                data LONGTEXT NOT NULL COMMENT 'JSON-encoded job data',
                retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_retries TINYINT UNSIGNED NOT NULL DEFAULT 3,
                error_message TEXT COMMENT 'Last error message',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at DATETIME COMMENT 'Job TTL expiration time',
                
                INDEX idx_status (status),
                INDEX idx_priority_status (priority, status),
                INDEX idx_created_at (created_at),
                INDEX idx_expires_at (expires_at),
                INDEX idx_retry_count (retry_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
    }

    /**
     * Get down migration SQL (rollback)
     * 
     * Returns SQL to drop the bid_queue table
     * 
     * @return string SQL to execute
     */
    public function down(): string
    {
        return "DROP TABLE IF EXISTS {$this->tableName}";
    }

    /**
     * Execute migration
     * 
     * @return bool True if successful
     * @throws \Exception
     */
    public function execute(): bool
    {
        global $wpdb;
        
        $sql = $this->up();
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            throw new \Exception("Migration failed: " . $wpdb->last_error);
        }
        
        // Store migration version in options
        update_option('wc_auction_bid_queue_migration_version', self::VERSION);
        
        return true;
    }

    /**
     * Rollback migration
     * 
     * @return bool True if successful
     * @throws \Exception
     */
    public function rollback(): bool
    {
        global $wpdb;
        
        $sql = $this->down();
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            throw new \Exception("Rollback failed: " . $wpdb->last_error);
        }
        
        // Clear migration version
        delete_option('wc_auction_bid_queue_migration_version');
        
        return true;
    }
}
