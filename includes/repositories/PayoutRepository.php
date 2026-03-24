<?php
/**
 * Seller Payout Repository
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    4.0.0
 * @requirement REQ-4D-034: Persist payout data
 * @requirement REQ-4D-035: Query payout data
 * @requirement REQ-4D-036: Atomic batch updates
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\SellerPayout;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PayoutRepository - Data Access Object for seller payouts
 *
 * UML Class Diagram:
 * ```
 * PayoutRepository (DAO)
 * ├── Methods:
 * │   ├── save(SellerPayout) : int
 * │   ├── find(int) : SellerPayout|null
 * │   ├── findByBatch(int) : SellerPayout[]
 * │   ├── findByStatus(string) : SellerPayout[]
 * │   ├── findBySeller(int) : SellerPayout[]
 * │   ├── findByTransactionId(string) : SellerPayout|null
 * │   ├── findPending() : SellerPayout[]
 * │   ├── findByDateRange(DateTime, DateTime) : SellerPayout[]
 * │   ├── findByBatchAndStatus(int, string) : SellerPayout[]
 * │   ├── update(SellerPayout) : bool
 * │   └── batchUpdateStatus(int[], string) : bool
 * └── Dependencies:
 *     ├── WordPress $wpdb
 *     └── SellerPayout model
 * ```
 *
 * Design Pattern: Data Access Object (DAO)
 * - Encapsulates all CRUD operations
 * - Returns domain objects (SellerPayout)
 * - Parameterized queries for security
 * - Support for status filtering and complex queries
 *
 * @requirement REQ-4D-034: Persist seller payouts
 * @requirement REQ-4D-035: Query payouts by various filters
 * @requirement REQ-4D-036: Atomic batch operations
 */
class PayoutRepository {

    const TABLE_NAME = 'wc_auction_seller_payouts';

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
     * Save a new seller payout
     *
     * @param SellerPayout $payout Payout to save
     * @return int Payout ID
     * @throws \Exception If save fails
     * @requirement REQ-4D-034: Save new payout
     */
    public function save( SellerPayout $payout ): int {
        $data = $payout->toArray();
        unset( $data['id'] );

        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $result     = $this->wpdb->insert( $table_name, $data );

        if ( false === $result ) {
            throw new \Exception( 'Failed to save seller payout: ' . $this->wpdb->last_error );
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Find payout by ID
     *
     * @param int $id Payout ID
     * @return SellerPayout|null
     * @requirement REQ-4D-035: Find by ID
     */
    public function find( int $id ): ?SellerPayout {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $id
        );
        $row        = $this->wpdb->get_row( $query, \ARRAY_A );

        return $row ? SellerPayout::fromDatabase( $row ) : null;
    }

    /**
     * Find all payouts in batch
     *
     * @param int $batch_id Batch ID
     * @return SellerPayout[]
     * @requirement REQ-4D-035: Query by batch
     */
    public function findByBatch( int $batch_id ): array {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE batch_id = %d ORDER BY created_at DESC",
            $batch_id
        );
        $rows       = $this->wpdb->get_results( $query, \ARRAY_A );

        return array_map(
            static function( $row ) {
                return SellerPayout::fromDatabase( $row );
            },
            $rows ?? []
        );
    }

    /**
     * Find payouts by status
     *
     * @param string $status Status to filter
     * @return SellerPayout[]
     * @requirement REQ-4D-035: Query by status
     */
    public function findByStatus( string $status ): array {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = %s ORDER BY created_at DESC",
            $status
        );
        $rows       = $this->wpdb->get_results( $query, \ARRAY_A );

        return array_map(
            static function( $row ) {
                return SellerPayout::fromDatabase( $row );
            },
            $rows ?? []
        );
    }

    /**
     * Find payouts for seller
     *
     * @param int $seller_id Seller ID
     * @return SellerPayout[]
     * @requirement REQ-4D-035: Query by seller
     */
    public function findBySeller( int $seller_id ): array {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE seller_id = %d ORDER BY created_at DESC",
            $seller_id
        );
        $rows       = $this->wpdb->get_results( $query, \ARRAY_A );

        return array_map(
            static function( $row ) {
                return SellerPayout::fromDatabase( $row );
            },
            $rows ?? []
        );
    }

    /**
     * Find only pending payouts
     *
     * @return SellerPayout[]
     * @requirement REQ-4D-035: Query pending payouts
     */
    public function findPending(): array {
        return $this->findByStatus( SellerPayout::STATUS_PENDING );
    }

    /**
     * Find payout by transaction ID
     *
     * @param string $transaction_id Transaction ID from processor
     * @return SellerPayout|null
     * @requirement REQ-4D-035: Query by transaction ID
     */
    public function findByTransactionId( string $transaction_id ): ?SellerPayout {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE transaction_id = %s",
            $transaction_id
        );
        $row        = $this->wpdb->get_row( $query, \ARRAY_A );

        return $row ? SellerPayout::fromDatabase( $row ) : null;
    }

    /**
     * Find payouts by date range
     *
     * @param \DateTime $start_date Start date (inclusive)
     * @param \DateTime $end_date End date (inclusive)
     * @return SellerPayout[]
     * @requirement REQ-4D-035: Query by date range
     */
    public function findByDateRange( \DateTime $start_date, \DateTime $end_date ): array {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE created_at >= %s AND created_at <= %s ORDER BY created_at DESC",
            $start_date->format( 'Y-m-d H:i:s' ),
            $end_date->format( 'Y-m-d H:i:s' )
        );
        $rows       = $this->wpdb->get_results( $query, \ARRAY_A );

        return array_map(
            static function( $row ) {
                return SellerPayout::fromDatabase( $row );
            },
            $rows ?? []
        );
    }

    /**
     * Find payouts by batch and status (combined filter)
     *
     * @param int    $batch_id Batch ID
     * @param string $status Status to filter
     * @return SellerPayout[]
     * @requirement REQ-4D-035: Query with combined filters
     */
    public function findByBatchAndStatus( int $batch_id, string $status ): array {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE batch_id = %d AND status = %s ORDER BY created_at DESC",
            $batch_id,
            $status
        );
        $rows       = $this->wpdb->get_results( $query, \ARRAY_A );

        return array_map(
            static function( $row ) {
                return SellerPayout::fromDatabase( $row );
            },
            $rows ?? []
        );
    }

    /**
     * Update existing payout
     *
     * @param SellerPayout $payout Payout to update
     * @return bool Success status
     * @throws \Exception If payout has no ID
     * @requirement REQ-4D-034: Update persisted payout
     */
    public function update( SellerPayout $payout ): bool {
        if ( null === $payout->getId() ) {
            throw new \Exception( 'Cannot update payout without ID' );
        }

        $data = $payout->toArray();
        unset( $data['id'] );
        unset( $data['created_at'] );

        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $result     = $this->wpdb->update(
            $table_name,
            $data,
            [ 'id' => $payout->getId() ]
        );

        return false !== $result;
    }

    /**
     * Batch update status for multiple payouts
     *
     * @param int[]  $payout_ids Payout IDs to update
     * @param string $status New status
     * @return bool Success status
     * @requirement REQ-4D-036: Atomic batch updates
     */
    public function batchUpdateStatus( array $payout_ids, string $status ): bool {
        if ( empty( $payout_ids ) ) {
            return true;
        }

        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $now = gmdate( 'Y-m-d H:i:s' );

        // Create IN clause for IDs
        $id_placeholders = implode( ',', array_fill( 0, count( $payout_ids ), '%d' ) );
        $query = $this->wpdb->prepare(
            "UPDATE {$table_name} SET status = %s, updated_at = %s WHERE id IN ({$id_placeholders})",
            array_merge( [ $status, $now ], $payout_ids )
        );

        $result = $this->wpdb->query( $query );

        return false !== $result;
    }
}
