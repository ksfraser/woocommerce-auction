<?php
/**
 * Bid Increment Calculator
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    1.0.0
 * @requirement REQ-AB-002: Calculate optimal bid increments
 * @requirement REQ-AB-003: Support multiple increment strategies
 */

namespace WC\Auction\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BidIncrementCalculator - Strategy pattern for bid increment calculation
 *
 * UML Class Diagram:
 * ```
 * BidIncrementCalculator (Strategy Pattern)
 * ├── Strategies:
 * │   ├── FixedIncrementStrategy
 * │   ├── PercentageIncrementStrategy
 * │   ├── DynamicIncrementStrategy
 * │   └── TieredIncrementStrategy
 * ├── Methods:
 * │   ├── calculateIncrement($current_bid, $auction_config) : float
 * │   ├── setStrategy($strategy) : void
 * │   ├── getNextBidAmount($current_bid, $auction_config) : float
 * │   └── validateBid($bid_amount, $user_max, $auction_config) : bool
 * └── Dependencies:
 *     ├── Strategy interface
 *     ├── Auction configuration
 * ```
 *
 * Design Pattern: Strategy Pattern (Gang of Four)
 * - Multiple increment strategies for different auction types
 * - Calculate optimal next bid that maximizes chance of winning
 * - Consider: current bid, user's max bid, bid history
 * - Performance: < 50ms calculation time (part of REQ-AB-004: < 100ms total)
 *
 * @requirement REQ-AB-002: Calculate bid increments to outbid current bidder
 * @requirement REQ-AB-003: Support percentage and fixed increment strategies
 * @requirement REQ-AB-004: Performance < 100ms (calculation portion < 50ms)
 */
class BidIncrementCalculator {
    
    /**
     * Increment strategy interface constant
     */
    const STRATEGY_FIXED       = 'fixed';
    const STRATEGY_PERCENTAGE  = 'percentage';
    const STRATEGY_DYNAMIC     = 'dynamic';
    const STRATEGY_TIERED      = 'tiered';
    
    /**
     * Current strategy
     *
     * @var string
     */
    private $strategy = self::STRATEGY_DYNAMIC;
    
    /**
     * Strategy configuration
     *
     * @var array
     */
    private $strategy_config = [];
    
    /**
     * Bid history cache (for dynamic strategy)
     *
     * @var array
     */
    private $bid_history = [];
    
    /**
     * Constructor
     *
     * @param string $strategy       Strategy to use (fixed, percentage, dynamic, tiered)
     * @param array  $config         Strategy-specific configuration
     */
    public function __construct( string $strategy = self::STRATEGY_DYNAMIC, array $config = [] ) {
        $this->strategy = $strategy;
        $this->strategy_config = $config;
    }
    
    /**
     * Calculate next bid amount to outbid current bid
     *
     * REQ-AB-002: Return bid that beats current bid but doesn't exceed user's maximum
     *
     * @param float $current_bid      Current highest bid
     * @param float $user_max_bid     User's maximum willing to pay
     * @param array $auction_config   Auction-specific configuration
     * @return float Next bid amount
     * @throws \InvalidArgumentException If bids are invalid
     */
    public function calculateNextBid( float $current_bid, float $user_max_bid, array $auction_config = [] ): float {
        // Validate inputs
        if ( $current_bid < 0 || $user_max_bid < 0 ) {
            throw new \InvalidArgumentException( 'Bid amounts cannot be negative' );
        }
        
        if ( $user_max_bid <= $current_bid ) {
            // User cannot outbid - return null to indicate user is outbid
            throw new \InvalidArgumentException( 'User maximum bid must exceed current bid' );
        }
        
        // Calculate increment based on strategy
        $increment = $this->getIncrement( $current_bid, $auction_config );
        
        // Calculate next bid
        $next_bid = round( $current_bid + $increment, 2 );
        
        // Ensure next bid doesn't exceed user's maximum
        if ( $next_bid > $user_max_bid ) {
            $next_bid = $user_max_bid;
        }
        
        return max( $current_bid + 0.01, $next_bid );
    }
    
    /**
     * Get increment amount based on strategy
     *
     * REQ-AB-002: Calculate increment strategically
     * REQ-AB-003: Support multiple strategies
     *
     * @param float $current_bid    Current highest bid
     * @param array $auction_config Auction configuration
     * @return float Increment amount
     */
    private function getIncrement( float $current_bid, array $auction_config = [] ): float {
        switch ( $this->strategy ) {
            case self::STRATEGY_FIXED:
                return $this->getFixedIncrement();
            
            case self::STRATEGY_PERCENTAGE:
                return $this->getPercentageIncrement( $current_bid );
            
            case self::STRATEGY_DYNAMIC:
                return $this->getDynamicIncrement( $current_bid, $auction_config );
            
            case self::STRATEGY_TIERED:
                return $this->getTieredIncrement( $current_bid );
            
            default:
                return $this->getDynamicIncrement( $current_bid, $auction_config );
        }
    }
    
    /**
     * Fixed increment strategy
     *
     * Always increment by fixed amount (e.g., $5.00)
     *
     * @return float
     */
    private function getFixedIncrement(): float {
        return $this->strategy_config['increment'] ?? 5.00;
    }
    
    /**
     * Percentage increment strategy
     *
     * Increment by percentage of current bid (e.g., 5% of bid)
     *
     * @param float $current_bid Current bid amount
     * @return float
     */
    private function getPercentageIncrement( float $current_bid ): float {
        $percentage = $this->strategy_config['percentage'] ?? 0.05; // 5% default
        return $current_bid * $percentage;
    }
    
    /**
     * Dynamic increment strategy (RECOMMENDED)
     *
     * Increment varies based on bid tier:
     * - Low bids ($0-$100): smaller increments
     * - Medium bids ($100-$500): moderate increments
     * - High bids ($500+): larger increments
     *
     * Maximizes winning chances while minimizing bid size
     *
     * @param float $current_bid    Current bid
     * @param array $auction_config Auction config with possible tiers
     * @return float
     */
    private function getDynamicIncrement( float $current_bid, array $auction_config = [] ): float {
        // Default tiers (can be overridden via config)
        $tiers = $auction_config['bid_tiers'] ?? [
            100  => 1.00,    // $0-$100: increment $1
            500  => 5.00,    // $100-$500: increment $5
            1000 => 10.00,   // $500-$1000: increment $10
            5000 => 25.00,   // $1000-$5000: increment $25
        ];
        
        foreach ( $tiers as $threshold => $increment ) {
            if ( $current_bid <= $threshold ) {
                return $increment;
            }
        }
        
        // For very high bids, use percentage
        return $current_bid * 0.02; // 2% for very high bids
    }
    
    /**
     * Tiered increment strategy
     *
     * Use predefined tiers from configuration
     *
     * @param float $current_bid Current bid
     * @return float
     */
    private function getTieredIncrement( float $current_bid ): float {
        $tiers = $this->strategy_config['tiers'] ?? [];
        
        if ( empty( $tiers ) ) {
            return $this->getDynamicIncrement( $current_bid );
        }
        
        ksort( $tiers );
        
        foreach ( $tiers as $threshold => $increment ) {
            if ( $current_bid <= $threshold ) {
                return $increment;
            }
        }
        
        return end( $tiers );
    }
    
    /**
     * Set strategy
     *
     * @param string $strategy Strategy name
     * @param array  $config   Strategy configuration
     * @return self
     */
    public function setStrategy( string $strategy, array $config = [] ): self {
        $this->strategy = $strategy;
        $this->strategy_config = $config;
        return $this;
    }
    
    /**
     * Validate bid is valid
     *
     * @param float $bid           Bid amount
     * @param float $user_max_bid  User's maximum
     * @param float $current_bid   Current highest bid
     * @return bool
     */
    public function validateBid( float $bid, float $user_max_bid, float $current_bid ): bool {
        // Bid must be positive
        if ( $bid <= 0 ) {
            return false;
        }
        
        // Bid must not exceed user's max
        if ( $bid > $user_max_bid ) {
            return false;
        }
        
        // Bid must beat current bid
        if ( $bid <= $current_bid ) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get current strategy name
     *
     * @return string
     */
    public function getStrategy(): string {
        return $this->strategy;
    }
}
