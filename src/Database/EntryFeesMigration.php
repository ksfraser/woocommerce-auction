<?php

namespace Yith\Auctions\Database;

use Yith\Auctions\Traits\LoggerTrait;

/**
 * EntryFeesMigration - Database schema for entry fees and commissions.
 *
 * Creates tables:
 * 1. wp_wc_auction_entry_fees - Entry fee records
 * 2. wp_wc_auction_fvf_commissions - Final Value Fee commissions
 * 3. wp_wc_auction_fee_transactions - Accounting ledger
 *
 * @package Yith\Auctions\Database
 * @requirement REQ-ENTRY-FEE-DATABASE-001: Entry fee schema
 * @requirement REQ-COMMISSION-DATABASE-001: Commission schema
 *
 * Table Structures:
 *
 * wp_wc_auction_entry_fees
 * ├─ id (PK)
 * ├─ entry_fee_id (UUID, UNIQUE)
 * ├─ auction_id (FK)
 * ├─ bidder_id (FK to wp_users)
 * ├─ amount (DECIMAL 10,2)
 * ├─ status (ENUM: PENDING, COMPLETED, FAILED, REFUNDED)
 * ├─ recorded_at (DATETIME)
 * ├─ completed_at (DATETIME, nullable)
 * ├─ refunded_at (DATETIME, nullable)
 * ├─ created_at (DATETIME)
 * └─ updated_at (DATETIME)
 *
 * wp_wc_auction_fvf_commissions
 * ├─ id (PK)
 * ├─ commission_id (UUID, UNIQUE)
 * ├─ auction_id (FK)
 * ├─ winner_id (FK to wp_users)
 * ├─ seller_id (FK to wp_users)
 * ├─ hammer_price (DECIMAL 10,2)
 * ├─ commission_amount (DECIMAL 10,2)
 * ├─ status (ENUM: PENDING, PAID, DISPUTED)
 * ├─ recorded_at (DATETIME)
 * ├─ paid_at (DATETIME, nullable)
 * └─ created_at (DATETIME)
 *
 * wp_wc_auction_fee_transactions
 * ├─ id (PK)
 * ├─ transaction_id (UUID, UNIQUE)
 * ├─ type (VARCHAR: ENTRY_FEE_RECEIVED, COMMISSION_RECEIVED, REFUND_PAID, etc.)
 * ├─ amount (DECIMAL 10,2)
 * ├─ auction_id (FK)
 * ├─ user_id (FK to wp_users)
 * ├─ description (TEXT)
 * └─ created_at (DATETIME, immutable)
 */
class EntryFeesMigration
{
    use LoggerTrait;

    /**
     * @var \wpdb WordPress database
     */
    private \wpdb $wpdb;

    /**
     * @var string Migration version
     */
    private const MIGRATION_VERSION = '1.0.0';

    /**
     * @var string Option key for tracking
     */
    private const OPTION_KEY = 'yith_auction_entry_fees_migration';

    /**
     * Initialize migration.
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
     * @requirement REQ-ENTRY-FEE-DATABASE-001
     */
    public function migrate(): bool
    {
        $this->logInfo('Starting entry fees migration', ['version' => self::MIGRATION_VERSION]);

        $version = get_option(self::OPTION_KEY);
        if ($version === self::MIGRATION_VERSION) {
            $this->logDebug('Migration already applied');
            return true;
        }

        try {
            $this->createEntryFeesTable();
            $this->createCommissionsTable();
            $this->createTransactionsTable();

            update_option(self::OPTION_KEY, self::MIGRATION_VERSION);

            $this->logInfo('Entry fees migration completed successfully');

            return true;

        } catch (\Exception $e) {
            $this->logError('Migration failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create entry fees table.
     *
     * @throws \RuntimeException If creation fails
     */
    private function createEntryFeesTable(): void
    {
        $table = $this->wpdb->prefix . 'wc_auction_entry_fees';

        if ($this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()',
            $table
        ))) {
            $this->logDebug('Entry fees table already exists');
            return;
        }

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_fee_id VARCHAR(36) NOT NULL UNIQUE,
            auction_id BIGINT UNSIGNED NOT NULL,
            bidder_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('PENDING', 'COMPLETED', 'FAILED', 'REFUNDED') NOT NULL DEFAULT 'PENDING',
            recorded_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            refunded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_entry_fee_id (entry_fee_id),
            KEY idx_auction_id_bidder (auction_id, bidder_id),
            KEY idx_bidder_id (bidder_id),
            KEY idx_status (status),
            CONSTRAINT fk_entry_fees_auction FOREIGN KEY (auction_id) REFERENCES {$this->wpdb->posts}(ID) ON DELETE CASCADE,
            CONSTRAINT fk_entry_fees_bidder FOREIGN KEY (bidder_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE CASCADE
        ) {$charset_collate}";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create entry fees table: ' . $this->wpdb->last_error
            );
        }

        $this->logInfo('Entry fees table created');
    }

    /**
     * Create commissions table.
     *
     * @throws \RuntimeException If creation fails
     */
    private function createCommissionsTable(): void
    {
        $table = $this->wpdb->prefix . 'wc_auction_fvf_commissions';

        if ($this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()',
            $table
        ))) {
            $this->logDebug('Commissions table already exists');
            return;
        }

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            commission_id VARCHAR(36) NOT NULL UNIQUE,
            auction_id BIGINT UNSIGNED NOT NULL,
            winner_id BIGINT UNSIGNED NOT NULL,
            seller_id BIGINT UNSIGNED NOT NULL,
            hammer_price DECIMAL(10,2) NOT NULL,
            commission_amount DECIMAL(10,2) NOT NULL,
            status ENUM('PENDING', 'PAID', 'DISPUTED') NOT NULL DEFAULT 'PENDING',
            recorded_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_commission_id (commission_id),
            KEY idx_auction_id (auction_id),
            KEY idx_winner_id (winner_id),
            KEY idx_seller_id (seller_id),
            KEY idx_status (status),
            CONSTRAINT fk_commissions_auction FOREIGN KEY (auction_id) REFERENCES {$this->wpdb->posts}(ID) ON DELETE CASCADE,
            CONSTRAINT fk_commissions_winner FOREIGN KEY (winner_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE CASCADE,
            CONSTRAINT fk_commissions_seller FOREIGN KEY (seller_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE CASCADE
        ) {$charset_collate}";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create commissions table: ' . $this->wpdb->last_error
            );
        }

        $this->logInfo('Commissions table created');
    }

    /**
     * Create transaction ledger table.
     *
     * @throws \RuntimeException If creation fails
     */
    private function createTransactionsTable(): void
    {
        $table = $this->wpdb->prefix . 'wc_auction_fee_transactions';

        if ($this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE()',
            $table
        ))) {
            $this->logDebug('Transaction ledger table already exists');
            return;
        }

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id VARCHAR(36) NOT NULL UNIQUE,
            type VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            auction_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            description TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_transaction_id (transaction_id),
            KEY idx_type (type),
            KEY idx_auction_id (auction_id),
            KEY idx_user_id (user_id),
            KEY idx_created_at (created_at),
            CONSTRAINT fk_transactions_auction FOREIGN KEY (auction_id) REFERENCES {$this->wpdb->posts}(ID) ON DELETE CASCADE,
            CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE CASCADE
        ) {$charset_collate}";

        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException(
                'Failed to create transaction ledger table: ' . $this->wpdb->last_error
            );
        }

        $this->logInfo('Transaction ledger table created');
    }

    /**
     * Rollback migration.
     *
     * @return bool Success
     */
    public function rollback(): bool
    {
        $this->logInfo('Rolling back entry fees migration');

        $tables = [
            $this->wpdb->prefix . 'wc_auction_entry_fees',
            $this->wpdb->prefix . 'wc_auction_fvf_commissions',
            $this->wpdb->prefix . 'wc_auction_fee_transactions',
        ];

        foreach ($tables as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::OPTION_KEY);

        $this->logInfo('Entry fees migration rolled back');

        return true;
    }
}
