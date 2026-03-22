<?php
/**
 * Unit tests for WC_Product_Auction class reserve price and bid increment methods.
 *
 * @package WC\\Auction\\Tests\Unit
 * @requirement REQ-001 Starting bid
 * @requirement REQ-003 Reserve price
 * @requirement REQ-002 Bid increment by price range
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once __DIR__ . '/../../includes/class.yith-wcact-auction-product.php';
require_once __DIR__ . '/../../includes/class.yith-wcact-bid-increment.php';

/**
 * Mock YITH_Auctions class for testing
 */
if ( ! class_exists( 'YITH_Auctions_Mock' ) ) {
    class YITH_Auctions_Mock {
        public $bids = null;

        public function __construct() {
            $this->bids = new class {
                public function get_max_bid( $product_id ) {
                    return null; // No bids
                }
            };
        }

        public static function get_instance() {
            static $instance = null;
            if ( null === $instance ) {
                $instance = new self();
            }
            return $instance;
        }
    }
}

class AuctionProductTest extends TestCase {

    protected function set_up() {
        parent::set_up();

        // Reset post meta store
        global $_test_post_meta;
        $_test_post_meta = array();

        // Replace YITH_Auctions with our mock
        if ( ! function_exists( 'YITH_Auctions' ) ) {
            function YITH_Auctions() {
                return YITH_Auctions_Mock::get_instance();
            }
        }
    }

    protected function tear_down() {
        parent::tear_down();
    }

    /**
     * Test get_reserve_price returns 0 when not set.
     *
     * @requirement REQ-003
     */
    public function test_get_reserve_price_default() {
        $product = new WC_Product_Auction( 1 );
        $this->assertEquals( 0.0, $product->get_reserve_price() );
    }

    /**
     * Test get_reserve_price returns the set value.
     *
     * @requirement REQ-003
     */
    public function test_get_reserve_price_set() {
        update_post_meta( 1, '_yith_auction_reserve_price', 250.00 );
        $product = new WC_Product_Auction( 1 );
        $this->assertEquals( 250.0, $product->get_reserve_price() );
    }

    /**
     * Test is_reserve_met returns true when no reserve set.
     *
     * @requirement REQ-003
     */
    public function test_is_reserve_met_no_reserve() {
        $product = new WC_Product_Auction( 1 );
        $this->assertTrue( $product->is_reserve_met() );
    }

    /**
     * Test is_reserve_met returns true when reserve met.
     *
     * @requirement REQ-003
     */
    public function test_is_reserve_met_above_reserve() {
        update_post_meta( 1, '_yith_auction_reserve_price', 100.00 );
        update_post_meta( 1, '_yith_auction_start_price', 150.00 ); // Current bid

        global $wpdb;
        $original = $wpdb;

        // Create mock that returns a bid at 150.00
        $mock = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                if ( strpos( $query, 'product_id' ) !== false ) {
                    return array(
                        (object) array(
                            'id' => 1,
                            'product_id' => 1,
                            'user_id' => 123,
                            'bid' => 150.00,
                        )
                    );
                }
                return array();
            }

            public function get_row( $query, $output = OBJECT, $y = 0 ) {
                if ( strpos( $query, 'product_id' ) !== false ) {
                    return (object) array(
                        'id' => 1,
                        'product_id' => 1,
                        'user_id' => 123,
                        'bid' => 150.00,
                    );
                }
                return null;
            }
        };
        $mock->prefix = 'wp_';
        $wpdb = $mock;

        $product = new WC_Product_Auction( 1 );
        $this->assertTrue( $product->is_reserve_met() );

        $wpdb = $original;
    }

    /**
     * Test is_reserve_met returns false when reserve not met.
     *
     * @requirement REQ-003
     */
    public function test_is_reserve_met_below_reserve() {
        update_post_meta( 1, '_yith_auction_reserve_price', 500.00 );
        update_post_meta( 1, '_yith_auction_start_price', 200.00 ); // Current bid

        global $wpdb;
        $original = $wpdb;

        // Return bid at 200.00, below reserve of 500
        $mock = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                if ( strpos( $query, 'product_id' ) !== false ) {
                    return array(
                        (object) array(
                            'id' => 1,
                            'product_id' => 1,
                            'user_id' => 123,
                            'bid' => 200.00,
                        )
                    );
                }
                return array();
            }

            public function get_row( $query, $output = OBJECT, $y = 0 ) {
                if ( strpos( $query, 'product_id' ) !== false ) {
                    return (object) array(
                        'id' => 1,
                        'product_id' => 1,
                        'user_id' => 123,
                        'bid' => 200.00,
                    );
                }
                return null;
            }
        };
        $mock->prefix = 'wp_';
        $wpdb = $mock;

        $product = new WC_Product_Auction( 1 );
        $this->assertFalse( $product->is_reserve_met() );

        $wpdb = $original;
    }

    /**
     * Test get_start_price returns set start price.
     *
     * @requirement REQ-001
     */
    public function test_get_start_price() {
        update_post_meta( 1, '_yith_auction_start_price', 50.00 );
        $product = new WC_Product_Auction( 1 );
        $this->assertEquals( 50.0, $product->get_start_price() );
    }

    /**
     * Test get_start_price returns false when not set.
     *
     * @requirement REQ-001
     */
    public function test_get_start_price_not_set() {
        $product = new WC_Product_Auction( 1 );
        $this->assertFalse( $product->get_start_price() );
    }

    /**
     * Test get_bid_increment delegates to WcAuction_Bid_Increment.
     *
     * @requirement REQ-002
     */
    public function test_get_bid_increment_calls_increment_class() {
        update_post_meta( 1, '_yith_auction_start_price', 100.00 );

        global $wpdb;
        $original = $wpdb;

        // Mock wpdb to return global increment range
        $mock = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                return array(
                    (object) array( 'id' => 1, 'product_id' => 0, 'from_price' => 0.0, 'increment' => 5.00 )
                );
            }
        };
        $mock->prefix = 'wp_';
        $wpdb = $mock;

        // Reset bid increment singleton
        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $product = new WC_Product_Auction( 1 );
        $increment = $product->get_bid_increment();

        // Should get 5.00 from our mocked range
        $this->assertEquals( 5.0, $increment );

        $wpdb = $original;
    }

    /**
     * Test get_minimum_bid when no bids yet returns start price.
     *
     * @requirement REQ-001
     * @requirement REQ-002
     */
    public function test_get_minimum_bid_no_bids_returns_start_price() {
        update_post_meta( 1, '_yith_auction_start_price', 50.00 );

        global $wpdb;
        $original = $wpdb;

        $mock = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                return array(
                    (object) array( 'id' => 1, 'product_id' => 0, 'from_price' => 0.0, 'increment' => 1.00 )
                );
            }

            public function get_row( $query, $output = OBJECT, $y = 0 ) {
                return null; // No max bid
            }
        };
        $mock->prefix = 'wp_';
        $wpdb = $mock;

        // Reset bid increment singleton
        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $product = new WC_Product_Auction( 1 );
        $min_bid = $product->get_minimum_bid();

        // Should equal start price when no bids
        $this->assertEquals( 50.0, $min_bid );

        $wpdb = $original;
    }

    /**
     * Test get_minimum_bid returns a numeric value.
     *
     * @requirement REQ-001
     * @requirement REQ-002
     */
    public function test_get_minimum_bid_returns_numeric() {
        update_post_meta( 1, '_yith_auction_start_price', 50.00 );

        global $wpdb;
        $original = $wpdb;

        $mock = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                // Return global increment range for all queries
                return array(
                    (object) array( 'id' => 1, 'product_id' => 0, 'from_price' => 0.0, 'increment' => 5.00 )
                );
            }

            public function get_row( $query, $output = OBJECT, $y = 0 ) {
                return null; // No max bid
            }
        };
        $mock->prefix = 'wp_';
        $wpdb = $mock;

        // Reset bid increment singleton
        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $product = new WC_Product_Auction( 1 );
        $min_bid = $product->get_minimum_bid();

        // Should be numeric and at least the starting bid
        $this->assertIsNumeric( $min_bid );
        $this->assertGreaterThanOrEqual( 50.0, $min_bid );

        $wpdb = $original;
    }
}
