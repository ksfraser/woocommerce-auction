<?php
/**
 * Database Migration
 * 
 * Handles database schema creation and updates
 * Tracks migration versions and manages schema evolution
 * 
 * @package WC\Auction\Database
 * @requirement REQ-QUEUE-DB-001 Database table structure initialization
 */

namespace WC\Auction\Database;

/**
 * Database Migration class
 * 
 * Manages database schema for bid queue
 * Provides version tracking and safe migrations
 * 
 * UML Class Diagram:
 * ```
 * ┌──────────────────────┐
 * │    Migration         │
 * ├──────────────────────┤
 * │ - wpdb               │
 * │ - dbVersion          │
 * │ - tableName          │
 * ├──────────────────────┤
 * │ + createTable()      │
 * │ + migrate()          │
 * │ + getCurrentVersion()│
 * │ + setCurrentVersion()│
 * │ - hasTable()         │
 * │ - getSchema()        │
 * └──────────────────────┘
 * ```
 */
class Migration
{
    /**
     * WordPress database object
     * 
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Current database version
     * 
     * @var string
     */
    private $dbVersion = '1.0.0';

    /**
     * Queue table name
     * 
     * @var string
     */
    private $tableName;

    /**
     * Options table for tracking version
     * 
     * @var string
     */
    private $optionKey = 'wc_auction_queue_db_version';

    /**
     * Initialize migration handler
     * 
     * @param \wpdb $wpdb WordPress database object
     * @param string $tableName Table name without prefix
     */
    public function __construct(\wpdb $wpdb, string $tableName = 'wc_auction_bid_queue')
    {
        $this->wpdb = $wpdb;
        $this->tableName = $wpdb->base_prefix . $tableName;
    }

    /**
     * Create bid queue table if it doesn't exist
     * 
     * Creates the main queue table with proper indexes and constraints
     * Safe to call multiple times - idempotent operation
     * 
     * @return bool True if created or already exists, false on error
     * @requirement REQ-QUEUE-DB-001 Database table structure initialization
     */
    public function createTable(): bool
    {
        if ($this->hasTable()) {
            return true;
        }

        // Build CREATE TABLE statement
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(36) NOT NULL UNIQUE,
            data LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            priority VARCHAR(10) NOT NULL DEFAULT 'NORMAL',
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            max_retries INT UNSIGNED NOT NULL DEFAULT 3,
            error_message TEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            executed_at DATETIME,
            expires_at DATETIME,
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_created_at (created_at),
            KEY idx_expires_at (expires_at),
            KEY idx_status_priority (status, priority)
        ) $charset_collate;";

        // Execute the CREATE TABLE statement
        $result = $this->wpdb->query($sql);

        if ($result === false) {
            return false;
        }

        // Mark as migrated
        $this->setCurrentVersion($this->dbVersion);

        return true;
    }

    /**
     * Run pending migrations
     * 
     * Checks current schema version and applies necessary migrations
     * 
     * @return bool True if successful, false on error
     * @requirement REQ-QUEUE-DB-001 Database table structure initialization
     */
    public function migrate(): bool
    {
        $currentVersion = $this->getCurrentVersion();

        // If table doesn't exist, create it
        if (!$this->hasTable()) {
            return $this->createTable();
        }

        // Version 1.0.0 - Initial schema
        if (version_compare($currentVersion, '1.0.0', '<')) {
            // Already created by createTable()
            $this->setCurrentVersion('1.0.0');
        }

        // Add future migrations here as schema evolves
        // Example:
        // if (version_compare($currentVersion, '1.1.0', '<')) {
        //     $this->migrateTo_1_1_0();
        // }

        return true;
    }

    /**
     * Get current database schema version
     * 
     * Retrieves the stored version from WordPress options
     * 
     * @return string Current version string
     */
    public function getCurrentVersion(): string
    {
        $version = get_option($this->optionKey, '0.0.0');
        return is_string($version) ? $version : '0.0.0';
    }

    /**
     * Set current database schema version
     * 
     * Updates the version tracking in WordPress options
     * 
     * @param string $version Version string
     * @return void
     */
    public function setCurrentVersion(string $version): void
    {
        update_option($this->optionKey, $version);
    }

    /**
     * Check if queue table exists
     * 
     * @return bool True if table exists, false otherwise
     */
    private function hasTable(): bool
    {
        $result = $this->wpdb->get_var(
            "SELECT 1 FROM information_schema.TABLES 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$this->tableName}'"
        );

        return $result !== null;
    }

    /**
     * Get database schema information
     * 
     * Useful for debugging and validation
     * 
     * @return array|null Column information or null if table doesn't exist
     */
    public function getSchema(): ?array
    {
        if (!$this->hasTable()) {
            return null;
        }

        $columns = $this->wpdb->get_results(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY 
             FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$this->tableName}'"
        );

        return $columns ?: null;
    }

    /**
     * Drop queue table
     * 
     * DANGER: Permanently removes all queue data
     * Use only for testing or complete uninstallation
     * 
     * @return bool True if dropped or didn't exist, false on error
     */
    public function dropTable(): bool
    {
        $result = $this->wpdb->query("DROP TABLE IF EXISTS {$this->tableName}");
        
        if ($result === false) {
            return false;
        }

        delete_option($this->optionKey);
        return true;
    }
}
