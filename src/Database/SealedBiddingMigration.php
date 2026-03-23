<?php

namespace Yith\Auctions\Database;

use Yith\Auctions\Traits\LoggerTrait;

/**
 * SealedBiddingMigration - Database schema for sealed bidding system.
 *
 * Creates tables:
 * 1. wp_wc_auction_sealed_bids - Main sealed bid records
 * 2. wp_wc_auction_sealed_bid_history - Immutable audit trail
 * 3. wp_wc_auction_encryption_keys - Encryption key management
 * 4. wp_wc_auction_states - Auction state machine tracking
 *
 * Security & Performance:
 * - All bids stored encrypted, never plaintext
 * - Hash stored for verification without decryption
 * - Encryption key version tracked per bid for rotation
 * - Comprehensive indexes for fast queries
 * - Audit trail for compliance and troubleshooting
 *
 * @package Yith\Auctions\Database
 * @requirement REQ-SEALED-BID-DATABASE-001: Schema for sealed bids
 * @requirement REQ-SEALED-BID-ENCRYPTION-001: Key management tables
 *
 * Table Structure:
 *
 * wp_wc_auction_sealed_bids
 * ├─ id (PK) - Auto-increment
 * ├─ sealed_bid_id (UUID, UNIQUE) - Business key for external reference
 * ├─ auction_id (FK) - Reference to auction
 * ├─ user_id (FK) - WordPress user
 * ├─ encrypted_bid (LONGBLOB) - AES-256-GCM encrypted amount (IV+CT+Tag)
 * ├─ bid_hash (CHAR 64) - SHA-256 of plaintext (for comparison)
 * ├─ plaintext_hash (CHAR 64, nullable) - Hash after revelation (for verification)
 * ├─ key_id (VARCHAR 36) - Encryption key UUID used
 * ├─ status (ENUM) - SUBMITTED, REVEALED, ACCEPTED_FOR_COUNT, REJECTED
 * ├─ submitted_at (DATETIME)
 * ├─ revealed_at (DATETIME, nullable)
 * ├─ created_at (DATETIME)
 * └─ updated_at (DATETIME)
 *
 * Indexes:
 * ├─ idx_sealed_bid_id (sealed_bid_id) - UUID lookup
 * ├─ idx_auction_id_status (auction_id, status) - Filtered queries
 * ├─ idx_user_id_auction (user_id, auction_id) - User's bids for auction
 * ├─ idx_status (status) - All bids in state
 * ├─ idx_key_id (key_id) - Bids encrypted with key
 * └─ idx_submitted_at (submitted_at) - Chronological queries
 *
 * wp_wc_auction_sealed_bid_history
 * ├─ id (PK) - Auto-increment
 * ├─ history_id (UUID, UNIQUE) - Business key
 * ├─ sealed_bid_id (FK) - Reference to bid
 * ├─ auction_id (FK) - Reference to auction
 * ├─ user_id (FK) - User involved
 * ├─ event_type (VARCHAR) - SUBMITTED, REVEALED, REJECTED, etc.
 * ├─ description (TEXT) - Human-readable description
 * ├─ metadata (JSON) - Additional context
 * └─ created_at (DATETIME) - Event timestamp (immutable)
 *
 * Indexes:
 * ├─ idx_sealed_bid_id (sealed_bid_id) - Retrieve bid history
 * ├─ idx_auction_id (auction_id) - All events for auction
 * ├─ idx_event_type (event_type) - Query by event
 * └─ idx_created_at (created_at) - Chronological queries
 *
 * wp_wc_auction_encryption_keys
 * ├─ id (PK) - Auto-increment
 * ├─ key_id (UUID, UNIQUE) - Business key for encryption
 * ├─ algorithm (VARCHAR) - "AES-256-GCM" currently
 * ├─ rotation_status (ENUM) - ACTIVE, ROTATED, ARCHIVED
 * ├─ created_at (DATETIME) - When key initialized
 * ├─ expires_at (DATETIME) - When to rotate
 * ├─ rotated_at (DATETIME, nullable) - When actually rotated
 * └─ retention_until (DATETIME, nullable) - When can be deleted
 *
 * Indexes:
 * ├─ idx_key_id (key_id) - UUID lookup
 * ├─ idx_rotation_status (rotation_status) - Find active key
 * ├─ idx_expires_at (expires_at) - Check rotation due
 * └─ idx_created_at (created_at) - Key history queries
 *
 * wp_wc_auction_states
 * ├─ id (PK) - Auto-increment
 * ├─ state_id (UUID, UNIQUE)
 * ├─ auction_id (FK) - Auction for state
 * ├─ auction_state (VARCHAR) - UPCOMING, ACTIVE_OPEN_BID, ACTIVE_SEALED, ENDED_REVEAL, COMPLETED
 * ├─ transition_from (VARCHAR, nullable) - Previous state
 * ├─ transition_at (DATETIME) - When state changed
 * ├─ initiated_by (INT, nullable) - User who initiated
 * ├─ metadata (JSON, nullable) - Context for transition
 * └─ created_at (DATETIME)
 *
 * Indexes:
 * ├─ idx_auction_id (auction_id) - State history for auction
 * ├─ idx_auction_state (auction_state) - Auctions in state
 * └─ idx_transition_at (transition_at) - Recent transitions
 */
class SealedBiddingMigration
{
    use LoggerTrait;

    /**
     * @var \wpdb WordPress database object
     */
    private \wpdb $wpdb;

    /**
     * @var string Current version of this migration
     */
    private const MIGRATION_VERSION = '1.0.0';

    /**
     * @var string Option key for tracking migration
     */
    private const OPTION_KEY = 'yith_auction_sealed_bidding_migration';

    /**
     * Initialize migration handler.
     *
     * @param \wpdb $wpdb WordPress database
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Run migration (idempotent).
     *
     * @return bool Success
     * @requirement REQ-SEALED-BID-DATABASE-001
     */
    public function migrate(): bool
    {
        $this->logInfo('Starting sealed bidding migration', ['version' => self::MIGRATION_VERSION]);

        // Check if already migrated
        $version = get_option(self::OPTION_KEY);
        if ($version === self::MIGRATION_VERSION) {
            $this->logDebug('Migration already applied');
            return true;
        }

        try {
            $this->createSealedBidsTable();
            $this->createSealedBidHistoryTable();
            $this->createEncryptionKeysTable();
            $this->createAuctionStatesTable();

            // Mark as migrated
            update_option(self::OPTION_KEY, self::MIGRATION_VERSION);

            $this->logInfo('Sealed bidding migration completed successfully');

            return true;

        } catch (\Exception $e) {
            $this->logError('Migration failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create sealed bids table.
     *
     * @throws \RuntimeException If table creation fails
     * @requirement REQ-SEALED-BID-DATABASE-001
     */
    private function createSealedBidsTable(): void
    {
        $table = $this->wpdb->prefix . 'wc_auction_sealed_bids';

        // Check if table exists
        if ($this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()',
            $table
        ))) {
            $this->logDebug('Sealed bids table already exists');
            return;
        }

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sealed_bid_id VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID for business reference',
            auction_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL COMMENT 'WordPress user ID',
            encrypted_bid LONGBLOB NOT NULL COMMENT 'AES-256-GCM encrypted amount (IV+CT+Tag)',
            bid_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of plaintext bid',
            plaintext_hash CHAR(64) NULL COMMENT 'SHA-256 after revelation (for verification)',
            key_id VARCHAR(36) NOT NULL COMMENT 'Encryption key UUID used',
            status ENUM('SUBMITTED', 'REVEALED', 'ACCEPTED_FOR_COUNT', 'REJECTED') NOT NULL DEFAULT 'SUBMITTED',
            submitted_at DATETIME NOT NULL,
            revealed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_sealed_bid_id (sealed_bid_id),
            KEY idx_auction_id_status (auction_id, status),
            KEY idx_user_id_auction (user_id, auction_id),
            KEY idx_status (status),
            KEY idx_key_id (key_id),
            KEY idx_submitted_at (submitted_at),
            CONSTRAINT fk_sealed_bids_auction FOREIGN KEY (auction_id) REFERENCES {$this->wpdb->posts}(ID) ON DELETE CASCADE,
            CONSTRAINT fk_sealed_bids_user FOREIGN KEY (user_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE CASCADE
        ) {$charset_collate}";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create sealed bids table: ' . $this->wpdb->last_error
            );
        }

        $this->logInfo('Sealed bids table created');
    }

    /**
     * Create sealed bid history table.
     *
     * @throws \RuntimeException If table creation fails
     * @requirement REQ-SEALED-BID-AUDIT-TRAIL-001
     */
    private function createSealedBidHistoryTable(): void
    {
        $table = $this->wpdb->prefix . 'wc_auction_sealed_bid_history';
        $bids_table = $this->wpdb->prefix . 'wc_auction_sealed_bids';

        if ($this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()',
            $table
        ))) {
            $this->logDebug('Sealed bid history table already exists');
            return;
        }

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            history_id VARCHAR(36) NOT NULL UNIQUE,
            sealed_bid_id VARCHAR(36) NOT NULL,
            auction_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL COMMENT 'SUBMITTED, REVEALED, REJECTED, etc.',
            description TEXT NOT NULL,
            metadata JSON NULL COMMENT 'Additional event context',
            created_at DATETIME NOT NULL COMMENT 'Immutable event timestamp',
            PRIMARY KEY (id),
            UNIQUE KEY idx_history_id (history_id),
            KEY idx_sealed_bid_id (sealed_bid_id),
            KEY idx_auction_id (auction_id),
            KEY idx_event_type (event_type),
            KEY idx_created_at (created_at),
            CONSTRAINT fk_sealed_bid_history_bid FOREIGN KEY (sealed_bid_id) REFERENCES {$bids_table}(sealed_bid_id) ON DELETE CASCADE
        ) {$charset_collate}";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create sealed bid history table: ' . $this->wpdb->last_error
            );
        }

        $this->logInfo('Sealed bid history table created');
    }

    /**
     * Create encryption keys management table.
     *
     * @throws \RuntimeException If table creation fails
     * @requirement REQ-SEALED-BID-ENCRYPTION-001
     */
    private function createEncryptionKeysTable(): void
    {
        $table = $this->wpdb->prefix . 'wc_auction_encryption_keys';

        if ($this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()',
            $table
        ))) {
            $this->logDebug('Encryption keys table already exists');
            return;
        }

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            key_id VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID for encryption key reference',
            algorithm VARCHAR(50) NOT NULL DEFAULT 'AES-256-GCM',
            rotation_status ENUM('ACTIVE', 'ROTATED', 'ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
            created_at DATETIME NOT NULL COMMENT 'When key was created',
            expires_at DATETIME NOT NULL COMMENT 'When to rotate key',
            rotated_at DATETIME NULL COMMENT 'When actually rotated',
            retention_until DATETIME NULL COMMENT 'When can be deleted',
            PRIMARY KEY (id),
            UNIQUE KEY idx_key_id (key_id),
            KEY idx_rotation_status (rotation_status),
            KEY idx_expires_at (expires_at),
            KEY idx_created_at (created_at)
        ) {$charset_collate}";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create encryption keys table: ' . $this->wpdb->last_error
            );
        }

        $this->logInfo('Encryption keys table created');
    }

    /**
     * Create auction states tracking table.
     *
     * @throws \RuntimeException If table creation fails
     * @requirement REQ-SEALED-BID-STATE-MACHINE-001
     */
    private function createAuctionStatesTable(): void
    {
        $table = $this->wpdb->prefix . 'wc_auction_states';

        if ($this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()',
            $table
        ))) {
            $this->logDebug('Auction states table already exists');
            return;
        }

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            state_id VARCHAR(36) NOT NULL UNIQUE,
            auction_id BIGINT UNSIGNED NOT NULL,
            auction_state VARCHAR(50) NOT NULL COMMENT 'UPCOMING, ACTIVE_OPEN_BID, ACTIVE_SEALED, ENDED_REVEAL, COMPLETED',
            transition_from VARCHAR(50) NULL COMMENT 'Previous state',
            transition_at DATETIME NOT NULL,
            initiated_by BIGINT UNSIGNED NULL COMMENT 'WordPress user who initiated',
            metadata JSON NULL COMMENT 'Transition context',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_state_id (state_id),
            KEY idx_auction_id (auction_id),
            KEY idx_auction_state (auction_state),
            KEY idx_transition_at (transition_at),
            CONSTRAINT fk_auction_states_auction FOREIGN KEY (auction_id) REFERENCES {$this->wpdb->posts}(ID) ON DELETE CASCADE
        ) {$charset_collate}";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create auction states table: ' . $this->wpdb->last_error
            );
        }

        $this->logInfo('Auction states table created');
    }

    /**
     * Rollback migration (remove tables).
     *
     * @return bool Success
     * @requirement REQ-SEALED-BID-DATABASE-001
     */
    public function rollback(): bool
    {
        $this->logInfo('Rolling back sealed bidding migration');

        $tables = [
            $this->wpdb->prefix . 'wc_auction_sealed_bid_history',
            $this->wpdb->prefix . 'wc_auction_sealed_bids',
            $this->wpdb->prefix . 'wc_auction_encryption_keys',
            $this->wpdb->prefix . 'wc_auction_states',
        ];

        foreach ($tables as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::OPTION_KEY);

        $this->logInfo('Sealed bidding migration rolled back');

        return true;
    }
}
