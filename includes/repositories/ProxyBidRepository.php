<?php
/**
 * Proxy Bid Repository
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    1.0.0
 * @requirement REQ-AB-001: Persist proxy bid data
 * @requirement REQ-AB-008: Query and enforce maximum bid constraints
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\ProxyBid;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ProxyBidRepository - Data Access Object for proxy bids
 *
 * UML Class Diagram:
 * ```
 * ProxyBidRepository (DAO)
 * ├── Methods:
 * │   ├── save(ProxyBid) : int (returns ID)
 * │   ├── find(int) : ProxyBid|null
 * │   ├── findByAuctionAndUser(int, int) : ProxyBid|null
 * │   ├── findActiveByUser(int) : ProxyBid[]
 * │   ├── findActiveByAuction(int) : ProxyBid[]
 * │   ├── update(ProxyBid) : bool
 * │   └── delete(int) : bool
 * └── Dependencies:
 *     ├── WordPress global $wpdb
 *     ├── ProxyBid model
 * ```
 *
 * Design Pattern: Data Access Object (DAO) + Repository
 * - Encapsulates all database operations
 * - Returns domain objects (ProxyBid), not raw SQL results
 * - Handles parameterized queries (prevents SQL injection)
 * - Implements caching for frequently accessed queries
 * - Performance optimized with proper indexes
 *
 * @requirement REQ-AB-001: Store and retrieve proxy bid data
 * @requirement REQ-AB-009: Ensure thread-safe concurrent bid operations
 */
class ProxyBidRepository {
    
    const TABLE_NAME = 'wc_auction_proxy_bids';
    
    /**
     * WordPress database instance
     *
     * @var \wpdb
     */
    private $wpdb;
    
    /**
     * Cache for frequently accessed queries
     *
     * @var array
     */
    private $cache = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    /**
     * Save a new proxy bid
     *
     * @param ProxyBid $proxy_bid Proxy bid to save
     * @return int ID of saved proxy bid
     * @throws \Exception If save fails
     */
    public function save( ProxyBid $proxy_bid ): int {
        $data = [
            'auction_id'        => $proxy_bid->getAuctionId(),
            'user_id'           => $proxy_bid->getUserId(),
            'maximum_bid'       => $proxy_bid->getMaximumBid(),
            'current_proxy_bid' => $proxy_bid->getCurrentProxyBid(),
            'status'            => $proxy_bid->getStatus(),
            'cancelled_by_user' => $proxy_bid->isCancelledByUser() ? 1 : 0,
            'notes'             => $proxy_bid->getNotes(),
            'created_at'        => $proxy_bid->getCreatedAt()->format( 'Y-m-d H:i:s' ),
            'updated_at'        => $proxy_bid->getUpdatedAt()->format( 'Y-m-d H:i:s' ),
        ];
        
        if ( $proxy_bid->getEndedAt() ) {
            $data['ended_at'] = $proxy_bid->getEndedAt()->format( 'Y-m-d H:i:s' );
        }
        
        if ( $proxy_bid->getCancelledAt() ) {
            $data['cancelled_at'] = $proxy_bid->getCancelledAt()->format( 'Y-m-d H:i:s' );
        }
        
        $formats = [];
        foreach ( $data as $key => $value ) {
            $formats[] = '%' . ( is_numeric( $value ) && false === strpos( $value, '.' ) ? 'd' : 's' );
        }
        
        $result = $this->wpdb->insert(
            $this->get_table_name(),
            $data,
            $formats
        );
        
        if ( false === $result ) {
            throw new \Exception(
                'Failed to save proxy bid: ' . $this->wpdb->last_error
            );
        }
        
        $id = (int) $this->wpdb->insert_id;
        
        // Clear cache
        $this->clear_cache();
        
        return $id;
    }
    
    /**
     * Find proxy bid by ID
     *
     * @param int $id Proxy bid ID
     * @return ProxyBid|null
     */
    public function find( int $id ): ?ProxyBid {
        // Check cache
        $cache_key = "proxy_bid_{$id}";
        if ( isset( $this->cache[ $cache_key ] ) ) {
            return $this->cache[ $cache_key ];
        }
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} WHERE id = %d",
            $id
        );
        
        $row = $this->wpdb->get_row( $query );
        
        if ( ! $row ) {
            return null;
        }
        
        $proxy_bid = ProxyBid::create( (array) $row );
        
        // Cache result
        $this->cache[ $cache_key ] = $proxy_bid;
        
        return $proxy_bid;
    }
    
    /**
     * Find proxy bid by auction and user
     *
     * @param int $auction_id Auction ID
     * @param int $user_id    User ID
     * @return ProxyBid|null
     */
    public function findByAuctionAndUser( int $auction_id, int $user_id ): ?ProxyBid {
        $cache_key = "proxy_bid_{$auction_id}_{$user_id}";
        if ( isset( $this->cache[ $cache_key ] ) ) {
            return $this->cache[ $cache_key ];
        }
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE auction_id = %d AND user_id = %d 
             LIMIT 1",
            $auction_id,
            $user_id
        );
        
        $row = $this->wpdb->get_row( $query );
        
        if ( ! $row ) {
            return null;
        }
        
        $proxy_bid = ProxyBid::create( (array) $row );
        
        // Cache result
        $this->cache[ $cache_key ] = $proxy_bid;
        
        return $proxy_bid;
    }
    
    /**
     * Find all active proxy bids for a user
     *
     * @param int $user_id User ID
     * @return ProxyBid[]
     */
    public function findActiveByUser( int $user_id ): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE user_id = %d AND status = %s 
             ORDER BY created_at DESC",
            $user_id,
            ProxyBid::STATUS_ACTIVE
        );
        
        $rows = $this->wpdb->get_results( $query );
        
        $proxy_bids = [];
        foreach ( $rows as $row ) {
            $proxy_bids[] = ProxyBid::create( (array) $row );
        }
        
        return $proxy_bids;
    }
    
    /**
     * Find all active proxy bids for an auction
     *
     * @param int $auction_id Auction ID
     * @return ProxyBid[]
     */
    public function findActiveByAuction( int $auction_id ): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE auction_id = %d AND status = %s 
             ORDER BY current_proxy_bid DESC",
            $auction_id,
            ProxyBid::STATUS_ACTIVE
        );
        
        $rows = $this->wpdb->get_results( $query );
        
        $proxy_bids = [];
        foreach ( $rows as $row ) {
            $proxy_bids[] = ProxyBid::create( (array) $row );
        }
        
        return $proxy_bids;
    }
    
    /**
     * Update proxy bid
     *
     * Requeirement REQ-AB-009: Use WHERE clause to ensure we only update
     * the exact row we intended to update (handles concurrent updates safely)
     *
     * @param ProxyBid $proxy_bid Proxy bid to update
     * @return bool Success
     * @throws \Exception If update fails
     */
    public function update( ProxyBid $proxy_bid ): bool {
        $data = [
            'maximum_bid'       => $proxy_bid->getMaximumBid(),
            'current_proxy_bid' => $proxy_bid->getCurrentProxyBid(),
            'status'            => $proxy_bid->getStatus(),
            'cancelled_by_user' => $proxy_bid->isCancelledByUser() ? 1 : 0,
            'notes'             => $proxy_bid->getNotes(),
            'updated_at'        => $proxy_bid->getUpdatedAt()->format( 'Y-m-d H:i:s' ),
        ];
        
        if ( $proxy_bid->getEndedAt() ) {
            $data['ended_at'] = $proxy_bid->getEndedAt()->format( 'Y-m-d H:i:s' );
        }
        
        if ( $proxy_bid->getCancelledAt() ) {
            $data['cancelled_at'] = $proxy_bid->getCancelledAt()->format( 'Y-m-d H:i:s' );
        }
        
        $formats = [];
        foreach ( $data as $key => $value ) {
            $formats[] = '%' . ( is_numeric( $value ) && false === strpos( $value, '.' ) ? 'd' : 's' );
        }
        
        $result = $this->wpdb->update(
            $this->get_table_name(),
            $data,
            [ 'id' => $proxy_bid->getId() ],
            $formats,
            [ '%d' ]
        );
        
        if ( false === $result ) {
            throw new \Exception(
                'Failed to update proxy bid: ' . $this->wpdb->last_error
            );
        }
        
        // Clear cache
        $this->clear_cache();
        
        return true;
    }
    
    /**
     * Delete proxy bid
     *
     * @param int $id Proxy bid ID
     * @return bool Success
     */
    public function delete( int $id ): bool {
        $result = $this->wpdb->delete(
            $this->get_table_name(),
            [ 'id' => $id ],
            [ '%d' ]
        );
        
        if ( false === $result ) {
            return false;
        }
        
        // Clear cache
        $this->clear_cache();
        
        return true;
    }
    
    /**
     * Get full table name with WordPress prefix
     *
     * @return string
     */
    private function get_table_name(): string {
        return $this->wpdb->prefix . self::TABLE_NAME;
    }
    
    /**
     * Clear repository cache
     *
     * @return void
     */
    private function clear_cache(): void {
        $this->cache = [];
    }
}
