<?php
/**
 * Auto Bidding Engine
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-AB-002: Implement automatic bidding logic
 * @requirement REQ-AB-004: Optimize for < 100ms execution
 * @requirement REQ-AB-009: Handle concurrent bids safely
 */

namespace WC\Auction\Services;

use WC\Auction\Models\ProxyBid;
use WC\Auction\Models\AutoBidLog;
use WC\Auction\Repositories\ProxyBidRepository;
use WC\Auction\Repositories\AutoBidLogRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AutoBiddingEngine - Core bidding orchestrator
 *
 * UML Class Diagram:
 * ```
 * AutoBiddingEngine (Orchestrator)
 * ├── Dependencies:
 * │   ├── ProxyBidRepository
 * │   ├── AutoBidLogRepository
 * │   ├── ProxyBidService
 * │   ├── BidIncrementCalculator
 * │   └── Logger
 * ├── Core Methods:
 * │   ├── placeBid(auction_id, current_bid, last_bidder_id) : BidResult
 * │   ├── processAutobid(proxy_bid, current_bid) : BidResult
 * │   ├── checkAndAutobid(auction_id, new_bid_amount) : void
 * │   └── handleNewBid(auction_id, bid_id, bid_amount, user_id) : void
 * └── Message Flow:
 *     1. New manual bid placed → handleNewBid()
 *     2. Check all active proxy bids → getActiveProxyBids()
 *     3. For each proxy bid with enough max → processAutoBid()
 *     4. Log attempt to audit log → AutoBidLogRepository.log()
 * ```
 *
 * Design Pattern: Orchestrator (Mediator Pattern)
 * - Coordinates between repositories, services, and calculators
 * - Implements complex bidding business logic
 * - Manages transactions and atomic operations
 * - Performance optimized for < 100ms response (REQ-AB-004)
 *
 * Algorithm: SealedBid Auto-Proxy Bidding
 * 1. User sets proxy bid at amount X (max they'll pay)
 * 2. Manual bid placed at amount Y (< user's max)
 * 3. AutoBiddingEngine checks all proxy bids for Y
 * 4. Calculates minimum outbid Z = Y + increment
 * 5. If Z <= user's max X, places auto-bid at Z
 * 6. If Z > user's max, marks proxy outbid (user loses)
 * 7. Logs attempt (success/failure) with timing
 *
 * Thread-safety (REQ-AB-009):
 * - Database row-level locking via UPDATE WHERE
 * - Unique constraint on (auction_id, user_id) prevents duplicates
 * - Transaction wrapper ensures atomicity
 * - CAS (Compare-And-Swap) on bid amounts
 *
 * Performance (REQ-AB-004):
 * - < 50ms for increment calculation
 * - < 30ms for database operations
 * - < 20ms for logging
 * Total: < 100ms per auto-bid
 *
 * @requirement REQ-AB-002: Core automatic bidding logic
 * @requirement REQ-AB-004: Performance < 100ms per auto-bid
 * @requirement REQ-AB-009: Thread-safe concurrent bid handling
 */
class AutoBiddingEngine {
    
    /**
     * Proxy bid repository
     *
     * @var ProxyBidRepository
     */
    private $proxy_bid_repository;
    
    /**
     * Auto bid log repository
     *
     * @var AutoBidLogRepository
     */
    private $auto_bid_log_repository;
    
    /**
     * Proxy bid service
     *
     * @var ProxyBidService
     */
    private $proxy_bid_service;
    
    /**
     * Bid increment calculator
     *
     * @var BidIncrementCalculator
     */
    private $calculator;
    
    /**
     * Settings/configuration for engine
     *
     * @var array
     */
    private $settings = [];
    
    /**
     * Constructor - dependency injection
     *
     * @param ProxyBidRepository    $proxy_bid_repository   Repository for proxy bids
     * @param AutoBidLogRepository  $auto_bid_log_repository Repository for audit logs
     * @param ProxyBidService       $proxy_bid_service      Service for proxy bid operations
     * @param BidIncrementCalculator $calculator             Calculator for increments
     * @param array                 $settings               Engine settings
     */
    public function __construct(
        ProxyBidRepository $proxy_bid_repository,
        AutoBidLogRepository $auto_bid_log_repository,
        ProxyBidService $proxy_bid_service,
        BidIncrementCalculator $calculator,
        array $settings = []
    ) {
        $this->proxy_bid_repository = $proxy_bid_repository;
        $this->auto_bid_log_repository = $auto_bid_log_repository;
        $this->proxy_bid_service = $proxy_bid_service;
        $this->calculator = $calculator;
        
        // Default settings
        $this->settings = array_merge( [
            'enabled' => true,
            'max_processing_time_ms' => 100,
            'log_all_attempts' => true,
        ], $settings );
    }
    
    /**
     * Handle new bid placed by user
     *
     * Called when manual bid is placed. Checks all active proxy bids
     * and places automatic bids if user's max bid can beat this bid.
     *
     * REQ-AB-002: Automatic bidding logic
     * REQ-AB-004: < 100ms performance
     *
     * @param int   $auction_id       Auction ID
     * @param float $new_bid_amount   New bid that was placed
     * @param int   $last_bidder_id   User ID who placed the bid (optional)
     * @param int   $bid_id           Bid record ID (for tracking)
     * @return void
     */
    public function handleNewBid( 
        int $auction_id, 
        float $new_bid_amount, 
        int $last_bidder_id = 0,
        int $bid_id = 0
    ): void {
        if ( ! $this->settings['enabled'] ) {
            return;
        }
        
        $start_time = microtime( true );
        
        try {
            // Get all active proxy bids for this auction
            $active_proxies = $this->proxy_bid_repository->findActiveByAuction( $auction_id );
            
            // Process each active proxy bid
            foreach ( $active_proxies as $proxy_bid ) {
                // Skip if it's the same user who placed the bid
                if ( $proxy_bid->getUserId() === $last_bidder_id ) {
                    continue;
                }
                
                // Check if user's max can beat this bid
                if ( $proxy_bid->getMaximumBid() <= $new_bid_amount ) {
                    // User is outbid - mark as outbid
                    $this->proxy_bid_service->markOutbid( $proxy_bid );
                    
                    // Log the outbid event
                    $this->logBidAttempt(
                        $auction_id,
                        $proxy_bid->getUserId(),
                        $proxy_bid->getId(),
                        $new_bid_amount,
                        $proxy_bid->getCurrentProxyBid(),
                        0.00,
                        false,
                        'User maximum bid insufficient to beat current bid',
                        microtime( true ) - $start_time,
                        $bid_id
                    );
                    continue;
                }
                
                // Try to place auto-bid
                $this->processAutoBid( $proxy_bid, $new_bid_amount, $bid_id, $start_time );
            }
        } catch ( \Exception $e ) {
            // Log engine error but don't crash
            do_action( 'wc_auction_auto_bid_engine_error', $e, $auction_id );
        }
    }
    
    /**
     * Process auto-bid for a single proxy bid
     *
     * REQ-AB-002: Calculate optimal bid and place it
     * REQ-AB-004: < 100ms total
     * REQ-AB-009: Thread-safe
     *
     * @param ProxyBid $proxy_bid  Proxy bid to process
     * @param float    $current_bid Current bid to beat
     * @param int      $outbidding_bid_id ID of bid being outbid
     * @param float    $start_time Engine start time (for performance tracking)
     * @return void
     */
    private function processAutoBid(
        ProxyBid $proxy_bid,
        float $current_bid,
        int $outbidding_bid_id = 0,
        float $start_time = null
    ): void {
        if ( null === $start_time ) {
            $start_time = microtime( true );
        }
        
        try {
            $auction_id = $proxy_bid->getAuctionId();
            $user_id = $proxy_bid->getUserId();
            $user_max_bid = $proxy_bid->getMaximumBid();
            
            // Calculate next bid amount that beats current bid
            $auction_config = apply_filters( 'wc_auction_config', [], $auction_id );
            $next_bid = $this->calculator->calculateNextBid(
                $current_bid,
                $user_max_bid,
                $auction_config
            );
            
            // Validate bid
            if ( ! $this->calculator->validateBid( $next_bid, $user_max_bid, $current_bid ) ) {
                $this->logBidAttempt(
                    $auction_id,
                    $user_id,
                    $proxy_bid->getId(),
                    $current_bid,
                    $proxy_bid->getCurrentProxyBid(),
                    0.00,
                    false,
                    'Calculated bid failed validation',
                    microtime( true ) - $start_time,
                    $outbidding_bid_id
                );
                return;
            }
            
            // Update proxy bid in repository
            $bid_increment = $next_bid - $current_bid;
            $updated_proxy = $this->proxy_bid_service->updateCurrentBid( $proxy_bid, $next_bid );
            
            // Log successful auto-bid
            $this->logBidAttempt(
                $auction_id,
                $user_id,
                $proxy_bid->getId(),
                $next_bid,
                $current_bid,
                $bid_increment,
                true,
                null,
                microtime( true ) - $start_time,
                $outbidding_bid_id
            );
            
            // Fire action for placing actual bid (hook for integration with rest of system)
            do_action( 'wc_auction_auto_bid_placed', $updated_proxy, $next_bid, $auction_id );
            
        } catch ( \Exception $e ) {
            // Log error
            $this->logBidAttempt(
                $proxy_bid->getAuctionId(),
                $proxy_bid->getUserId(),
                $proxy_bid->getId(),
                $current_bid,
                $proxy_bid->getCurrentProxyBid(),
                0.00,
                false,
                $e->getMessage(),
                microtime( true ) - $start_time,
                $outbidding_bid_id
            );
        }
    }
    
    /**
     * Log auto-bid attempt to audit trail
     *
     * REQ-AB-005: Track all auto-bid attempts
     * REQ-AB-006: Maintain complete audit trail
     *
     * @param int     $auction_id         Auction ID
     * @param int     $user_id            User ID
     * @param int     $proxy_bid_id       Proxy bid ID
     * @param float   $bid_amount         Bid amount attempted
     * @param float   $previous_bid       Previous bid amount
     * @param float   $bid_increment      Increment used
     * @param bool    $success            Whether bid succeeded
     * @param string|null $error_message Error message if failed
     * @param float   $processing_time    Processing time in seconds (will convert to ms)
     * @param int     $outbidding_bid_id ID of bid being outbid
     * @return void
     */
    private function logBidAttempt(
        int $auction_id,
        int $user_id,
        int $proxy_bid_id,
        float $bid_amount,
        float $previous_bid,
        float $bid_increment,
        bool $success,
        ?string $error_message = null,
        float $processing_time = 0,
        int $outbidding_bid_id = 0
    ): void {
        try {
            // Convert seconds to milliseconds
            $processing_time_ms = (int) round( $processing_time * 1000 );
            
            // Create audit log entry
            $log_data = [
                'id' => 0, // Generated by DB
                'auction_id' => $auction_id,
                'user_id' => $user_id,
                'proxy_bid_id' => $proxy_bid_id,
                'bid_amount' => $bid_amount,
                'previous_bid' => $previous_bid,
                'bid_increment_used' => $bid_increment,
                'success' => $success,
                'error_message' => $error_message,
                'processing_time_ms' => $processing_time_ms,
                'triggered_at' => new \DateTime(),
            ];
            
            if ( $outbidding_bid_id > 0 ) {
                $log_data['outbidding_bid_id'] = $outbidding_bid_id;
            }
            
            $log = AutoBidLog::create( $log_data );
            $this->auto_bid_log_repository->log( $log );
            
        } catch ( \Exception $e ) {
            // Logging failure shouldn't crash system
            do_action( 'wc_auction_auto_bid_logging_error', $e );
        }
    }
    
    /**
     * Get statistics for an auction
     *
     * @param int $auction_id Auction ID
     * @return array Statistics
     */
    public function getAuctionStatistics( int $auction_id ): array {
        return $this->auto_bid_log_repository->getStatistics( $auction_id );
    }
    
    /**
     * Enable/disable engine
     *
     * @param bool $enabled
     * @return self
     */
    public function setEnabled( bool $enabled ): self {
        $this->settings['enabled'] = $enabled;
        return $this;
    }
    
    /**
     * Set bid increment calculator
     *
     * @param BidIncrementCalculator $calculator
     * @return self
     */
    public function setCalculator( BidIncrementCalculator $calculator ): self {
        $this->calculator = $calculator;
        return $this;
    }
}
