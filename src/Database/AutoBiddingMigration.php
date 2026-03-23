<?php

namespace Yith\Auctions\Database;

/**
 * AutoBiddingMigration - Database migration for auto-bidding feature.
 *
 * Creates tables for storing auto-bid configurations and history.
 * Uses proper indexing for performance and foreign key constraints.
 *
 * @package Yith\Auctions\Database
 * @requirement REQ-AUTO-BID-DB-001: Auto-bidding database schema
 */
class AutoBiddingMigration
{
    /**
     * @var \wpdb WordPress database object
     */
    private \wpdb $wpdb;

    /**
     * @var string Current schema version
     */
    private const SCHEMA_VERSION = '1.0.0';

    /**
     * Initialize migration.
     *
     * @param \wpdb $wpdb WordPress database object
     * @requirement REQ-AUTO-BID-DB-001
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Run migration - create or update tables.
     *
     * @return bool Success
     * @throws \RuntimeException If migration fails
     * @requirement REQ-AUTO-BID-DB-001
     */
    public function migrate(): bool
    {
        $current_version = get_option('yith_auction_auto_bid_schema_version', '0.0.0');

        if (version_compare($current_version, self::SCHEMA_VERSION, '<')) {
            $this->createAutoBidsTable();
            $this->createAutoBidHistoryTable();
            update_option('yith_auction_auto_bid_schema_version', self::SCHEMA_VERSION);
        }

        return true;
    }

    /**
     * Create wp_wc_auction_auto_bids table.
     *
     * Stores auto-bid configurations per user per auction.
     * Each row represents an active auto-bid setup.
     *
     * @return bool Success
     * @requirement REQ-AUTO-BID-DB-001
     */
    private function createAutoBidsTable(): bool
    {
        $table = $this->wpdb->prefix . 'wc_auction_auto_bids';
        $charset = $this->wpdb->get_charset_collate();

        $sql = "
CREATE TABLE IF NOT EXISTS {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  auto_bid_id VARCHAR(36) NOT NULL UNIQUE,
  auction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  maximum_bid DECIMAL(10,2) NOT NULL,
  current_bid DECIMAL(10,2),
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  proxy_bid_amount DECIMAL(10,2),
  proxy_bid_placed BOOLEAN DEFAULT 0,
  bid_count INT UNSIGNED DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  expires_at DATETIME,
  
  KEY idx_auction_user (auction_id, user_id),
  KEY idx_status (status),
  KEY idx_user_status (user_id, status),
  KEY idx_created_at (created_at),
  KEY idx_expires_at (expires_at),
  
  CONSTRAINT fk_auto_bid_auction FOREIGN KEY (auction_id) REFERENCES {$this->wpdb->posts}(ID) ON DELETE CASCADE,
  CONSTRAINT fk_auto_bid_user FOREIGN KEY (user_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE CASCADE
) {$charset};
        ";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create auto_bids table: ' . $this->wpdb->last_error
            );
        }

        return true;
    }

    /**
     * Create wp_wc_auction_auto_bid_history table.
     *
     * Stores detailed history of proxy bids placed by the auto-bidding engine.
     * Provides audit trail and analysis data.
     *
     * @return bool Success
     * @requirement REQ-AUTO-BID-DB-001
     */
    private function createAutoBidHistoryTable(): bool
    {
        $table = $this->wpdb->prefix . 'wc_auction_auto_bid_history';
        $charset = $this->wpdb->get_charset_collate();

        $sql = "
CREATE TABLE IF NOT EXISTS {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  history_id VARCHAR(36) NOT NULL UNIQUE,
  auto_bid_id VARCHAR(36) NOT NULL,
  auction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(30) NOT NULL,
  event_data JSON,
  bid_amount DECIMAL(10,2),
  outbidden_by_user BIGINT UNSIGNED,
  outbidden_by_bid DECIMAL(10,2),
  proxy_action VARCHAR(50),
  created_at DATETIME NOT NULL,
  
  KEY idx_auto_bid_id (auto_bid_id),
  KEY idx_auction_id (auction_id),
  KEY idx_user_id (user_id),
  KEY idx_event_type (event_type),
  KEY idx_created_at (created_at),
  KEY idx_user_event (user_id, event_type),
  
  CONSTRAINT fk_history_auto_bid FOREIGN KEY (auto_bid_id) REFERENCES {$this->wpdb->prefix}wc_auction_auto_bids(auto_bid_id) ON DELETE CASCADE,
  CONSTRAINT fk_history_auction FOREIGN KEY (auction_id) REFERENCES {$this->wpdb->posts}(ID) ON DELETE CASCADE,
  CONSTRAINT fk_history_user FOREIGN KEY (user_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE CASCADE,
  CONSTRAINT fk_history_outbidder FOREIGN KEY (outbidden_by_user) REFERENCES {$this->wpdb->users}(ID) ON DELETE SET NULL
) {$charset};
        ";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create auto_bid_history table: ' . $this->wpdb->last_error
            );
        }

        return true;
    }

    /**
     * Rollback migration - drop tables.
     *
     * @return bool Success
     * @requirement REQ-AUTO-BID-DB-001
     */
    public function rollback(): bool
    {
        $this->wpdb->query('DROP TABLE IF EXISTS ' . $this->wpdb->prefix . 'wc_auction_auto_bid_history');
        $this->wpdb->query('DROP TABLE IF EXISTS ' . $this->wpdb->prefix . 'wc_auction_auto_bids');
        delete_option('yith_auction_auto_bid_schema_version');

        return true;
    }
}
