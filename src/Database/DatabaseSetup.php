<?php
/**
 * Database Setup and Initialization
 * 
 * Orchestrates database initialization, migration, and provides
 * access to database components for the bid queue system
 * 
 * @package WC\Auction\Database
 * @requirement REQ-QUEUE-DB-001 Database table structure initialization
 */

namespace WC\Auction\Database;

use WC\Auction\Exceptions\Queue\ConnectionException;

/**
 * Database Setup class
 * 
 * Entry point for database initialization and management
 * Handles migrations and component initialization
 * 
 * UML Class Diagram:
 * ```
 * ┌──────────────────────────┐
 * │    DatabaseSetup         │
 * ├──────────────────────────┤
 * │ - wpdb                   │
 * │ - migration              │
 * │ - tableName              │
 * ├──────────────────────────┤
 * │ + initialize()           │
 * │ + migrate()              │
 * │ + getTableName()         │
 * │ + getMigration()         │
 * │ + isReady()              │
 * └──────────────────────────┘
 * ```
 * 
 * Usage:
 * ```php
 * $setup = new DatabaseSetup($wpdb);
 * $setup->initialize(); // Create tables and run migrations
 * 
 * // Get table name for queries
 * $tableName = $setup->getTableName();
 * ```
 * 
 * @requirement REQ-QUEUE-DB-001 Database table structure initialization
 */
class DatabaseSetup
{
    /**
     * WordPress database object
     * 
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Migration handler
     * 
     * @var Migration|null
     */
    private $migration;

    /**
     * Queue table name
     * 
     * @var string
     */
    private $tableName;

    /**
     * Table name without prefix (for customization)
     * 
     * @var string
     */
    private $baseTableName;

    /**
     * Initialize database setup
     * 
     * @param \wpdb $wpdb WordPress database object
     * @param string $baseTableName Table name without prefix
     * @throws ConnectionException
     */
    public function __construct(\wpdb $wpdb, string $baseTableName = 'wc_auction_bid_queue')
    {
        if (!$wpdb instanceof \wpdb) {
            throw new ConnectionException('Invalid WPDB instance provided');
        }

        $this->wpdb = $wpdb;
        $this->baseTableName = $baseTableName;
        $this->tableName = $wpdb->base_prefix . $baseTableName;
    }

    /**
     * Initialize database (create tables if needed)
     * 
     * Safe to call multiple times - idempotent operation
     * 
     * @return bool True if successful, false on error
     * @throws ConnectionException
     * @requirement REQ-QUEUE-DB-001 Database table structure initialization
     */
    public function initialize(): bool
    {
        try {
            $migration = $this->getMigration();
            return $migration->createTable();
        } catch (\Exception $e) {
            throw new ConnectionException('Database initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Run database migrations
     * 
     * Checks schema version and applies necessary updates
     * 
     * @return bool True if successful, false on error
     * @throws ConnectionException
     * @requirement REQ-QUEUE-DB-001 Database table structure initialization
     */
    public function migrate(): bool
    {
        try {
            $migration = $this->getMigration();
            return $migration->migrate();
        } catch (\Exception $e) {
            throw new ConnectionException('Database migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Get fully qualified table name
     * 
     * Includes WordPress table prefix
     * 
     * @return string Table name with prefix
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get base table name (without prefix)
     * 
     * @return string Base table name
     */
    public function getBaseTableName(): string
    {
        return $this->baseTableName;
    }

    /**
     * Get WPDB instance
     * 
     * @return \wpdb
     */
    public function getWpdb(): \wpdb
    {
        return $this->wpdb;
    }

    /**
     * Get migration handler
     * 
     * Lazy-loads the migration instance
     * 
     * @return Migration
     */
    public function getMigration(): Migration
    {
        if ($this->migration === null) {
            $this->migration = new Migration($this->wpdb, $this->baseTableName);
        }

        return $this->migration;
    }

    /**
     * Check if database is ready for use
     * 
     * Verifies that the queue table exists and is accessible
     * 
     * @return bool True if ready, false otherwise
     */
    public function isReady(): bool
    {
        try {
            $schema = $this->getMigration()->getSchema();
            return $schema !== null && count($schema) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Uninstall database (DROP table)
     * 
     * DANGER: Permanently removes all queue data
     * Use only for plugin uninstallation
     * 
     * @return bool True if dropped or didn't exist, false on error
     * @throws ConnectionException
     */
    public function uninstall(): bool
    {
        try {
            return $this->getMigration()->dropTable();
        } catch (\Exception $e) {
            throw new ConnectionException('Database uninstallation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get database diagnostics
     * 
     * Returns information useful for debugging
     * 
     * @return array Diagnostic information
     */
    public function getDiagnostics(): array
    {
        try {
            $migration = $this->getMigration();
            
            return [
                'table_name' => $this->tableName,
                'table_exists' => $migration->getSchema() !== null,
                'current_version' => $migration->getCurrentVersion(),
                'schema' => $migration->getSchema(),
                'wpdb_prefix' => $this->wpdb->base_prefix,
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
