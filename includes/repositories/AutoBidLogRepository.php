<?php
/**
 * Auto Bid Audit Log Repository
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    1.0.0
 * @requirement REQ-AB-005: Track all auto-bid attempts
 * @requirement REQ-AB-006: Maintain audit trail queries
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\AutoBidLog;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AutoBidLogRepository - Data Access Object for audit logs
 *
 * UML Class Diagram:
 * ```
 * AutoBidLogRepository (DAO)
 * ├── Methods:
 * │   ├── log(AutoBidLog) : int (returns ID)
 * │   ├── findByProxyBid(int) : AutoBidLog[]
 * │   ├── findByAuction(int) : AutoBidLog[]
 * │   ├── findByUser(int) : AutoBidLog[]
 * │   ├── getAuditTrail(int) : AutoBidLog[]
 * │   ├── getFailureCount(int) : int
 * │   ├── getSuccessRate(int) : float
 * │   └── getPendingLogs(int) : AutoBidLog[]
 * └── Dependencies:
 *     ├── WordPress global $wpdb
 *     ├── AutoBidLog model
 * ```
 *
 * Design Pattern: Data Access Object (DAO)
 * - Write-once append-only log (immutable)
 * - Optimized for efficient querying and reporting
 * - Performance tracking column (processing_time_ms) for monitoring
 * - Does NOT include update() or delete() - audit logs are immutable
 *
 * @requirement REQ-AB-005: Maintain 99.9% bid execution tracking
 * @requirement REQ-AB-006: Track complete audit trail
 */
class AutoBidLogRepository {
    
    const TABLE_NAME = 'wc_auction_auto_bid_log';
    
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
     * Log an auto-bid attempt (append-only)
     *
     * @param AutoBidLog $log Auto-bid log entry
     * @return int ID of logged entry
     * @throws \Exception If logging fails
     */
    public function log( AutoBidLog $log ): int {
        $data = [
            'auction_id'          => $log->getAuctionId(),
            'user_id'             => $log->getUserId(),
            'proxy_bid_id'        => $log->getProxyBidId(),
            'bid_amount'          => $log->getBidAmount(),
            'previous_bid'        => $log->getPreviousBid(),
            'bid_increment_used'  => $log->getBidIncrementUsed(),
            'success'             => $log->wasSuccessful() ? 1 : 0,
            'error_message'       => $log->getErrorMessage(),
            'processing_time_ms'  => $log->getProcessingTimeMs(),
            'triggered_at'        => $log->getTriggeredAt()->format( 'Y-m-d H:i:s' ),
        ];
        
        if ( $log->getOutbiddingBidId() ) {
            $data['outbidding_bid_id'] = $log->getOutbiddingBidId();
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
                'Failed to log auto-bid: ' . $this->wpdb->last_error
            );
        }
        
        return (int) $this->wpdb->insert_id;
    }
    
    /**
     * Find all log entries for a proxy bid
     *
     * @param int $proxy_bid_id Proxy bid ID
     * @return AutoBidLog[]
     */
    public function findByProxyBid( int $proxy_bid_id ): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE proxy_bid_id = %d 
             ORDER BY triggered_at DESC",
            $proxy_bid_id
        );
        
        $rows = $this->wpdb->get_results( $query );
        
        $logs = [];
        foreach ( $rows as $row ) {
            $logs[] = AutoBidLog::create( (array) $row );
        }
        
        return $logs;
    }
    
    /**
     * Find all log entries for an auction
     *
     * @param int $auction_id Auction ID
     * @return AutoBidLog[]
     */
    public function findByAuction( int $auction_id ): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE auction_id = %d 
             ORDER BY triggered_at DESC",
            $auction_id
        );
        
        $rows = $this->wpdb->get_results( $query );
        
        $logs = [];
        foreach ( $rows as $row ) {
            $logs[] = AutoBidLog::create( (array) $row );
        }
        
        return $logs;
    }
    
    /**
     * Find all log entries for a user
     *
     * @param int $user_id User ID
     * @return AutoBidLog[]
     */
    public function findByUser( int $user_id ): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE user_id = %d 
             ORDER BY triggered_at DESC",
            $user_id
        );
        
        $rows = $this->wpdb->get_results( $query );
        
        $logs = [];
        foreach ( $rows as $row ) {
            $logs[] = AutoBidLog::create( (array) $row );
        }
        
        return $logs;
    }
    
    /**
     * Get audit trail for a user (paginated, latest first)
     *
     * @param int $user_id User ID
     * @param int $limit   Maximum number of entries (default 100)
     * @param int $offset  Pagination offset (default 0)
     * @return AutoBidLog[]
     */
    public function getAuditTrail( int $user_id, int $limit = 100, int $offset = 0 ): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE user_id = %d 
             ORDER BY triggered_at DESC 
             LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        );
        
        $rows = $this->wpdb->get_results( $query );
        
        $logs = [];
        foreach ( $rows as $row ) {
            $logs[] = AutoBidLog::create( (array) $row );
        }
        
        return $logs;
    }
    
    /**
     * Count failed auto-bids for an auction
     *
     * Used for monitoring REQ-AB-005: Track failures for analysis
     *
     * @param int $auction_id Auction ID
     * @return int Failure count
     */
    public function getFailureCount( int $auction_id ): int {
        $query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->get_table_name()} 
             WHERE auction_id = %d AND success = 0",
            $auction_id
        );
        
        return (int) $this->wpdb->get_var( $query );
    }
    
    /**
     * Calculate success rate for an auction
     *
     * REQ-AB-005: Target 99.9% success rate
     * Formula: (successful_bids / total_attempts) * 100
     *
     * @param int $auction_id Auction ID
     * @return float Success percentage (0-100)
     */
    public function getSuccessRate( int $auction_id ): float {
        $query = $this->wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful
             FROM {$this->get_table_name()} 
             WHERE auction_id = %d",
            $auction_id
        );
        
        $result = $this->wpdb->get_row( $query );
        
        if ( ! $result || 0 === $result->total ) {
            return 0.0;
        }
        
        return ( $result->successful / $result->total ) * 100;
    }
    
    /**
     * Get average processing time for an auction
     *
     * REQ-AB-004: Monitor < 100ms requirement
     *
     * @param int $auction_id Auction ID
     * @return int Average processing time in milliseconds
     */
    public function getAverageProcessingTime( int $auction_id ): int {
        $query = $this->wpdb->prepare(
            "SELECT AVG(processing_time_ms) as avg_time 
             FROM {$this->get_table_name()} 
             WHERE auction_id = %d",
            $auction_id
        );
        
        $result = $this->wpdb->get_row( $query );
        
        return $result ? (int) $result->avg_time : 0;
    }
    
    /**
     * Get entries with errors (for debugging)
     *
     * @param int $limit Maximum number of entries
     * @return AutoBidLog[]
     */
    public function getErrorEntries( int $limit = 100 ): array {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} 
             WHERE success = 0 
             ORDER BY triggered_at DESC 
             LIMIT %d",
            $limit
        );
        
        $rows = $this->wpdb->get_results( $query );
        
        $logs = [];
        foreach ( $rows as $row ) {
            $logs[] = AutoBidLog::create( (array) $row );
        }
        
        return $logs;
    }
    
    /**
     * Get statistics for dashboard/reporting
     *
     * @param int $auction_id Auction ID
     * @return array Statistics array
     */
    public function getStatistics( int $auction_id ): array {
        $query = $this->wpdb->prepare(
            "SELECT 
                COUNT(*) as total_attempts,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_attempts,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_attempts,
                AVG(processing_time_ms) as avg_processing_time,
                MAX(processing_time_ms) as max_processing_time,
                MIN(processing_time_ms) as min_processing_time,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT proxy_bid_id) as unique_proxy_bids,
                MAX(triggered_at) as last_bid_at
             FROM {$this->get_table_name()} 
             WHERE auction_id = %d",
            $auction_id
        );
        
        $result = $this->wpdb->get_row( $query );
        
        if ( ! $result ) {
            return [];
        }
        
        return (array) $result;
    }
    
    /**
     * Get full table name with WordPress prefix
     *
     * @return string
     */
    private function get_table_name(): string {
        return $this->wpdb->prefix . self::TABLE_NAME;
    }
}
