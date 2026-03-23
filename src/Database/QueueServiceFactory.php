<?php
/**
 * Database Queue Factory
 * 
 * Factory for creating and configuring queue services
 * Handles dependency injection and service initialization
 * 
 * @package WC\Auction\Database
 * @requirement REQ-QUEUE-FACTORY-001 Queue service instantiation
 */

namespace WC\Auction\Database;

use WC\Auction\Services\BidQueue;
use WC\Auction\Exceptions\Queue\ConnectionException;

/**
 * Queue Service Factory
 * 
 * Creates properly configured queue instances with all dependencies
 * Follows Factory pattern and Dependency Injection principles
 * 
 * UML Class Diagram:
 * ```
 * ┌──────────────────────────┐
 * │  QueueServiceFactory     │
 * ├──────────────────────────┤
 * │ - wpdb (static)          │
 * │ - setup (static)         │
 * ├──────────────────────────┤
 * │ + createBidQueue()       │
 * │ + setup()                │
 * │ + getSetup()             │
 * └──────────────────────────┘
 * ```
 * 
 * Usage:
 * ```php
 * // One-time setup
 * QueueServiceFactory::setup($wpdb);
 * 
 * // Create queue instances
 * $bidQueue = QueueServiceFactory::createBidQueue();
 * ```
 * 
 * @requirement REQ-QUEUE-FACTORY-001 Queue service instantiation
 */
class QueueServiceFactory
{
    /**
     * Cached WPDB instance
     * 
     * @var \wpdb|null
     */
    private static $wpdb;

    /**
     * Cached DatabaseSetup instance
     * 
     * @var DatabaseSetup|null
     */
    private static $setup;

    /**
     * Setup factory with database connection
     * 
     * Call this once during plugin initialization
     * 
     * @param \wpdb $wpdb WordPress database object
     * @return DatabaseSetup Configured database setup instance
     * @throws ConnectionException
     * @requirement REQ-QUEUE-FACTORY-001 Queue service instantiation
     */
    public static function setup(\wpdb $wpdb): DatabaseSetup
    {
        try {
            self::$wpdb = $wpdb;
            self::$setup = new DatabaseSetup($wpdb);
            
            // Run initialization
            if (!self::$setup->initialize()) {
                throw new ConnectionException('Failed to initialize queue database');
            }

            return self::$setup;
        } catch (\Exception $e) {
            throw new ConnectionException('Factory setup failed: ' . $e->getMessage());
        }
    }

    /**
     * Create bid queue service instance
     * 
     * Returns a configured BidQueue service ready for use
     * 
     * @return BidQueue Configured bid queue service
     * @throws ConnectionException If factory not setup or initialization fails
     * @requirement REQ-QUEUE-FACTORY-001 Queue service instantiation
     */
    public static function createBidQueue(): BidQueue
    {
        if (self::$wpdb === null) {
            throw new ConnectionException('Factory not setup. Call QueueServiceFactory::setup() first');
        }

        $setup = self::getSetup();
        return new BidQueue(self::$wpdb, $setup->getTableName());
    }

    /**
     * Get database setup instance
     * 
     * Lazy-initializes if not already setup
     * 
     * @return DatabaseSetup
     * @throws ConnectionException
     */
    public static function getSetup(): DatabaseSetup
    {
        if (self::$setup === null) {
            if (self::$wpdb === null) {
                throw new ConnectionException('Factory not initialized');
            }

            self::$setup = new DatabaseSetup(self::$wpdb);
        }

        return self::$setup;
    }

    /**
     * Reset factory state (useful for testing)
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$wpdb = null;
        self::$setup = null;
    }

    /**
     * Get current WPDB instance
     * 
     * @return \wpdb|null
     */
    public static function getWpdb(): ?\wpdb
    {
        return self::$wpdb;
    }

    /**
     * Check if factory is initialized
     * 
     * @return bool True if setup and ready
     */
    public static function isInitialized(): bool
    {
        return self::$wpdb !== null && self::$setup !== null;
    }
}
