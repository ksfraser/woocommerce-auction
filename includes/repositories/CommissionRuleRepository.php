<?php
/**
 * Commission Rule Repository
 *
 * @package    WooCommerce Auction
 * @subpackage Repositories
 * @version    4.0.0
 * @requirement REQ-4D-001: Persist commission rules
 * @requirement REQ-4D-002: Query commission rules by tier
 */

namespace WC\Auction\Repositories;

use WC\Auction\Models\CommissionRule;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CommissionRuleRepository - Data Access Object for commission rules
 *
 * UML Class Diagram:
 * ```
 * CommissionRuleRepository (DAO)
 * ├── Methods:
 * │   ├── save(CommissionRule) : int
 * │   ├── find(int) : CommissionRule|null
 * │   ├── findByTier(string) : CommissionRule|null
 * │   ├── findByTierActive(string) : CommissionRule|null
 * │   ├── findAllActive() : CommissionRule[]
 * │   └── update(CommissionRule) : bool
 * └── Dependencies:
 *     ├── WordPress $wpdb
 *     ├── CommissionRule model
 * ```
 *
 * Design Pattern: Data Access Object (DAO)
 * - Encapsulates all database operations
 * - Returns domain objects (CommissionRule)
 * - Handles parameterized queries
 * - Caching for frequently accessed rules
 *
 * @requirement REQ-4D-001: Store and retrieve commission rules
 * @requirement PERF-4D-002: Cache active rules for fast lookup < 1ms
 */
class CommissionRuleRepository {

    const TABLE_NAME = 'wc_auction_commission_rules';

    /**
     * WordPress database instance
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Cache for active rules by tier
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
     * Save a new commission rule
     *
     * @param CommissionRule $rule Rule to save
     * @return int Rule ID
     * @throws \Exception If save fails
     * @requirement REQ-4D-001: Persist commission rule
     */
    public function save( CommissionRule $rule ): int {
        $data = $rule->toArray();
        unset( $data['id'] );

        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $result     = $this->wpdb->insert( $table_name, $data );

        if ( false === $result ) {
            throw new \Exception( 'Failed to save commission rule: ' . $this->wpdb->last_error );
        }

        // Clear cache on save
        $this->cache = [];

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Find rule by ID
     *
     * @param int $id Rule ID
     * @return CommissionRule|null
     */
    public function find( int $id ): ?CommissionRule {
        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $query      = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $id
        );
        $row        = $this->wpdb->get_row( $query, ARRAY_A );

        return $row ? CommissionRule::fromDatabase( $row ) : null;
    }

    /**
     * Find rule by seller tier (gets first active rule for tier)
     *
     * @param string $seller_tier Seller tier (STANDARD|GOLD|PLATINUM)
     * @return CommissionRule|null
     * @requirement REQ-4D-001: Query commission rule by tier
     */
    public function findByTier( string $seller_tier ): ?CommissionRule {
        // Check cache first
        if ( isset( $this->cache[ $seller_tier ] ) ) {
            return $this->cache[ $seller_tier ];
        }

        $table_name = $this->wpdb->prefix . self::TABLE_NAME;
        $now        = current_time( 'Y-m-d H:i:s', false );

        $query = $this->wpdb->prepare(
            "SELECT * FROM {$table_name}
            WHERE seller_tier = %s
            AND active = 1
            AND effective_from <= %s
            AND (effective_to IS NULL OR effective_to > %s)
            ORDER BY effective_from DESC
            LIMIT 1",
            $seller_tier,
            $now,
            $now
        );

        $row = $this->wpdb->get_row( $query, ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        $rule                          = CommissionRule::fromDatabase( $row );
        $this->cache[ $seller_tier ] = $rule;

        return $rule;
    }

    /**
     * Replace cache (for testing/reset)
     *
     * @return void
     */
    public function clearCache(): void {
        $this->cache = [];
    }
}
