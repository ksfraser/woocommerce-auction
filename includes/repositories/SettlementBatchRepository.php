<?php
/**
 * Settlement Batch Repository
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    4.0.0
 * @requirement REQ-4D-001: Persist settlement batch data
 * @requirement REQ-4D-002: Query batches by status and date
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\SettlementBatch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SettlementBatchRepository - Data Access Object for settlement batches
 *
 * UML Class Diagram:
 * ```
 * SettlementBatchRepository (DAO)
 * ├── Methods:
 * │   ├── save(SettlementBatch) : int
 * │   ├── find(int) : SettlementBatch|null
 * │   ├── findByBatchNumber(string) : SettlementBatch|null
 * │   ├── findByStatus(string) : SettlementBatch[]
 * │   ├── findLatest() : SettlementBatch|null
 * │   ├── findByDateRange(DateTime, DateTime) : SettlementBatch[]
 * │   ├── update(SettlementBatch) : bool
 * │   └── delete(int) : bool
 * └── Dependencies:
 *     ├── WordPress $wpdb
 *     └── SettlementBatch model
 * ```
 *
 * Design Pattern: Data Access Object (DAO)
 * - Encapsulates all CRUD operations
 * - Returns domain objects (SettlementBatch)
 * - Parameterized queries for security
 * - Support for status filtering
 *
 * @requirement REQ-4D-001: Store and retrieve settlement batches
 * @requirement REQ-4D-002: Query batches by status
 */
class SettlementBatchRepository {

    const TABLE_NAME = 'wc_auction_settlement_batches';

    /**
     * WordPress database instance
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Save a new settlement batch
     *
     * @param SettlementBatch $batch Batch to save
     * @return int Batch ID
     * @throws \Exception If save fails
     * @requirement REQ-4D-001: Persist settlement batch
     */
    public function save( SettlementBatch $batch ): int {
        $data = $batch->toArray();
        unset( $data['id'] );
        unset( $data['net_payout_cents'] );

        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $result     = $this->wpdb->insert( $table_name, $data );

        if ( false === $result ) {
            throw new \Exception( 'Failed to save settlement batch: ' . $this->wpdb->last_error );
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Find batch by ID
     *
     * @param int $id Batch ID
     * @return SettlementBatch|null
     */
    public function find( int $id ): ?SettlementBatch {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $id
        );
        $row        = $this->wpdb->get_row( $query, ARRAY_A );

        return $row ? SettlementBatch::fromDatabase( $row ) : null;
    }

    /**
     * Find batch by batch number
     *
     * @param string $batch_number Batch number
     * @return SettlementBatch|null
     */
    public function findByBatchNumber( string $batch_number ): ?SettlementBatch {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE batch_number = %s",
            $batch_number
        );
        $row        = $this->wpdb->get_row( $query, ARRAY_A );

        return $row ? SettlementBatch::fromDatabase( $row ) : null;
    }

    /**
     * Find batches by status
     *
     * @param string $status Status to filter
     * @return SettlementBatch[]
     */
    public function findByStatus( string $status ): array {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = %s ORDER BY created_at DESC",
            $status
        );
        $rows       = $this->wpdb->get_results( $query, ARRAY_A );

        return array_map(
            static function( $row ) {
                return SettlementBatch::fromDatabase( $row );
            },
            $rows ?? []
        );
    }

    /**
     * Find latest batch
     *
     * @return SettlementBatch|null
     */
    public function findLatest(): ?SettlementBatch {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 1";
        $row        = $this->wpdb->get_row( $query, ARRAY_A );

        return $row ? SettlementBatch::fromDatabase( $row ) : null;
    }

    /**
     * Update existing batch
     *
     * @param SettlementBatch $batch Batch to update
     * @return bool Success status
     * @throws \Exception If batch has no ID
     */
    public function update( SettlementBatch $batch ): bool {
        if ( null === $batch->getId() ) {
            throw new \Exception( 'Cannot update batch without ID' );
        }

        $data = $batch->toArray();
        unset( $data['id'] );
        unset( $data['created_at'] );
        unset( $data['net_payout_cents'] );

        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $result     = $this->wpdb->update(
            $table_name,
            $data,
            [ 'id' => $batch->getId() ]
        );

        return false !== $result;
    }
}
