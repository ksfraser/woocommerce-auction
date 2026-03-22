<?php
/**
 * Unit tests for WcAuction_Bid_Increment class.
 *
 * @package WC\\Auction\\Tests\Unit
 * @requirement REQ-002 Bid increment by price range
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once __DIR__ . '/../../includes/class.yith-wcact-bid-increment.php';

class BidIncrementTest extends TestCase {

    /**
     * @var WcAuction_Bid_Increment
     */
    private $bid_increment;

    /**
     * @var wpdb mock reference
     */
    private $wpdb_mock;

    protected function set_up() {
        parent::set_up();

        // Reset the singleton so each test gets a fresh instance
        $reflection = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $instance_prop = $reflection->getProperty( 'instance' );
        $instance_prop->setAccessible( true );
        $instance_prop->setValue( null, null );

        // Reset post meta store
        global $_test_post_meta;
        $_test_post_meta = array();

        $this->bid_increment = WcAuction_Bid_Increment::get_instance();

        global $wpdb;
        $this->wpdb_mock = $wpdb;
    }

    protected function tear_down() {
        parent::tear_down();
        // Reset singleton
        $reflection = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $instance_prop = $reflection->getProperty( 'instance' );
        $instance_prop->setAccessible( true );
        $instance_prop->setValue( null, null );
    }

    /**
     * @requirement REQ-002
     */
    public function test_get_instance_returns_singleton() {
        $instance_a = WcAuction_Bid_Increment::get_instance();
        $instance_b = WcAuction_Bid_Increment::get_instance();
        $this->assertSame( $instance_a, $instance_b );
    }

    /**
     * @requirement REQ-002
     */
    public function test_default_increment_when_no_ranges() {
        global $wpdb;
        $original_wpdb = $wpdb;

        // Mock wpdb to return empty results empty for any query
        $mock_wpdb = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                return array(); // No ranges
            }
        };
        $mock_wpdb->prefix = 'wp_';
        $wpdb = $mock_wpdb;

        // Reset singleton
        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $bi = WcAuction_Bid_Increment::get_instance();
        $increment = $bi->get_increment_for_price( 50.00, 0 );
        $this->assertEquals( 1.00, $increment, 'Default increment should be 1.00 when no ranges configured' );

        $wpdb = $original_wpdb;
    }

    /**
     * @requirement REQ-002
     */
    public function test_get_increment_matches_correct_range() {
        // Override wpdb->get_results to return sample global ranges
        $ranges = array(
            (object) array( 'id' => 1, 'product_id' => 0, 'from_price' => 0.00,   'increment' => 1.00 ),
            (object) array( 'id' => 2, 'product_id' => 0, 'from_price' => 100.00,  'increment' => 5.00 ),
            (object) array( 'id' => 3, 'product_id' => 0, 'from_price' => 500.00,  'increment' => 10.00 ),
            (object) array( 'id' => 4, 'product_id' => 0, 'from_price' => 1000.00, 'increment' => 25.00 ),
        );

        global $wpdb;
        $original_wpdb = $wpdb;

        // Create a partial mock of wpdb
        $mock_wpdb = new class extends wpdb {
            public $mock_results = array();

            public function get_results( $query, $output = OBJECT ) {
                return $this->mock_results;
            }
        };
        $mock_wpdb->prefix = 'wp_';
        $mock_wpdb->mock_results = $ranges;
        $wpdb = $mock_wpdb;

        // Reset singleton to use new wpdb
        $reflection = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $instance_prop = $reflection->getProperty( 'instance' );
        $instance_prop->setAccessible( true );
        $instance_prop->setValue( null, null );

        $bi = WcAuction_Bid_Increment::get_instance();

        // Test each price range
        $this->assertEquals( 1.00, $bi->get_increment_for_price( 0.00, 0 ),    'Price $0 should use $1 increment' );
        $this->assertEquals( 1.00, $bi->get_increment_for_price( 50.00, 0 ),   'Price $50 should use $1 increment' );
        $this->assertEquals( 1.00, $bi->get_increment_for_price( 99.99, 0 ),   'Price $99.99 should use $1 increment' );
        $this->assertEquals( 5.00, $bi->get_increment_for_price( 100.00, 0 ),  'Price $100 should use $5 increment' );
        $this->assertEquals( 5.00, $bi->get_increment_for_price( 250.00, 0 ),  'Price $250 should use $5 increment' );
        $this->assertEquals( 10.00, $bi->get_increment_for_price( 500.00, 0 ), 'Price $500 should use $10 increment' );
        $this->assertEquals( 10.00, $bi->get_increment_for_price( 750.00, 0 ), 'Price $750 should use $10 increment' );
        $this->assertEquals( 25.00, $bi->get_increment_for_price( 1000.00, 0 ),'Price $1000 should use $25 increment' );
        $this->assertEquals( 25.00, $bi->get_increment_for_price( 5000.00, 0 ),'Price $5000 should use $25 increment' );

        // Restore
        $wpdb = $original_wpdb;
    }

    /**
     * @requirement REQ-002
     */
    public function test_product_uses_global_default() {
        // No meta set — should default to true (use global)
        $this->assertTrue( $this->bid_increment->product_uses_global( 42 ) );
    }

    /**
     * @requirement REQ-002
     */
    public function test_product_uses_global_when_set_to_yes() {
        update_post_meta( 42, '_yith_auction_bid_increment_use_global', 'yes' );
        $this->assertTrue( $this->bid_increment->product_uses_global( 42 ) );
    }

    /**
     * @requirement REQ-002
     */
    public function test_product_uses_custom_when_set_to_no() {
        update_post_meta( 42, '_yith_auction_bid_increment_use_global', 'no' );
        $this->assertFalse( $this->bid_increment->product_uses_global( 42 ) );
    }

    /**
     * @requirement REQ-002
     */
    public function test_set_product_uses_global() {
        $this->bid_increment->set_product_uses_global( 42, true );
        $this->assertTrue( $this->bid_increment->product_uses_global( 42 ) );

        $this->bid_increment->set_product_uses_global( 42, false );
        $this->assertFalse( $this->bid_increment->product_uses_global( 42 ) );
    }

    /**
     * @requirement REQ-002
     */
    public function test_save_ranges_calls_insert_for_each_range() {
        global $wpdb;
        $original_wpdb = $wpdb;

        // Track inserts
        $mock_wpdb = new class extends wpdb {
            public $inserts = array();

            public function insert( $table, $data, $format = null ) {
                $this->inserts[] = array( 'table' => $table, 'data' => $data );
                $this->insert_id = count( $this->inserts );
                return 1;
            }

            public function delete( $table, $where, $where_format = null ) {
                return 1;
            }

            public function get_results( $query, $output = OBJECT ) {
                return array();
            }
        };
        $mock_wpdb->prefix = 'wp_';
        $wpdb = $mock_wpdb;

        // Reset singleton
        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $bi = WcAuction_Bid_Increment::get_instance();

        $ranges = array(
            array( 'from_price' => 0.00, 'increment' => 1.00 ),
            array( 'from_price' => 100.00, 'increment' => 5.00 ),
        );

        $result = $bi->save_ranges( 0, $ranges );
        $this->assertTrue( $result );
        $this->assertCount( 2, $mock_wpdb->inserts );

        // Verify correct data
        $this->assertEquals( 0, $mock_wpdb->inserts[0]['data']['product_id'] );
        $this->assertEquals( 0.00, $mock_wpdb->inserts[0]['data']['from_price'] );
        $this->assertEquals( 1.00, $mock_wpdb->inserts[0]['data']['increment'] );

        $this->assertEquals( 0, $mock_wpdb->inserts[1]['data']['product_id'] );
        $this->assertEquals( 100.00, $mock_wpdb->inserts[1]['data']['from_price'] );
        $this->assertEquals( 5.00, $mock_wpdb->inserts[1]['data']['increment'] );

        // Restore
        $wpdb = $original_wpdb;
    }

    /**
     * Test that save_ranges enforces minimum increment of 1.0 for invalid values.
     *
     * @requirement REQ-002
     */
    public function test_save_ranges_enforces_minimum_increment() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $mock_wpdb = new class extends wpdb {
            public $inserts = array();

            public function insert( $table, $data, $format = null ) {
                $this->inserts[] = $data;
                $this->insert_id = count( $this->inserts );
                return 1;
            }

            public function delete( $table, $where, $where_format = null ) {
                return 1;
            }

            public function get_results( $query, $output = OBJECT ) {
                return array();
            }
        };
        $mock_wpdb->prefix = 'wp_';
        $wpdb = $mock_wpdb;

        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $bi = WcAuction_Bid_Increment::get_instance();

        $ranges = array(
            array( 'from_price' => 0.00, 'increment' => 0 ),
            array( 'from_price' => 50.00, 'increment' => -5.00 ),
        );

        $bi->save_ranges( 0, $ranges );

        // Both should be clamped to 1.0
        $this->assertEquals( 1.00, $mock_wpdb->inserts[0]['increment'] );
        $this->assertEquals( 1.00, $mock_wpdb->inserts[1]['increment'] );

        $wpdb = $original_wpdb;
    }

    /**
     * @requirement REQ-002
     */
    public function test_copy_global_to_product_rejects_zero_product_id() {
        $result = $this->bid_increment->copy_global_to_product( 0 );
        $this->assertFalse( $result );
    }

    /**
     * @requirement REQ-002
     */
    public function test_delete_ranges_returns_true() {
        $result = $this->bid_increment->delete_ranges( 99 );
        $this->assertTrue( $result );
    }

    /**
     * @requirement REQ-002
     */
    public function test_save_empty_ranges_returns_true() {
        $result = $this->bid_increment->save_ranges( 0, array() );
        $this->assertTrue( $result );
    }

    /**
     * Test get_increment uses product ranges when product doesn't use global.
     *
     * @requirement REQ-002
     */
    public function test_get_increment_uses_product_ranges_when_not_global() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $product_id = 42;

        // Set product to NOT use global
        update_post_meta( $product_id, '_yith_auction_bid_increment_use_global', 'no' );

        // Mock wpdb to return product-specific ranges for product_id 42
        $mock_wpdb = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                // Check if the query is for product_id 42
                if ( strpos( $query, '42' ) !== false ) {
                    return array(
                        (object) array( 'id' => 10, 'product_id' => 42, 'from_price' => 0.00, 'increment' => 2.00 ),
                        (object) array( 'id' => 11, 'product_id' => 42, 'from_price' => 100.00, 'increment' => 10.00 ),
                    );
                }
                return array();
            }
        };
        $mock_wpdb->prefix = 'wp_';
        $wpdb = $mock_wpdb;

        // Reset singleton
        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $bi = WcAuction_Bid_Increment::get_instance();

        // Should use product ranges
        $this->assertEquals( 2.00, $bi->get_increment_for_price( 50.00, $product_id ) );
        $this->assertEquals( 10.00, $bi->get_increment_for_price( 200.00, $product_id ) );

        $wpdb = $original_wpdb;
    }

    /**
     * Test get_increment falls through to global when product uses global.
     *
     * @requirement REQ-002
     */
    public function test_get_increment_falls_back_to_global() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $product_id = 42;

        // Product uses global (default)
        update_post_meta( $product_id, '_yith_auction_bid_increment_use_global', 'yes' );

        $mock_wpdb = new class extends wpdb {
            public function get_results( $query, $output = OBJECT ) {
                // Only return results for global (product_id=0)
                if ( strpos( $query, "'0'" ) !== false || strpos( $query, "= 0" ) !== false ) {
                    return array(
                        (object) array( 'id' => 1, 'product_id' => 0, 'from_price' => 0.00, 'increment' => 3.00 ),
                    );
                }
                return array();
            }
        };
        $mock_wpdb->prefix = 'wp_';
        $wpdb = $mock_wpdb;

        $ref = new ReflectionClass( 'WcAuction_Bid_Increment' );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $bi = WcAuction_Bid_Increment::get_instance();

        // Should use global ranges since product_uses_global = yes
        $this->assertEquals( 3.00, $bi->get_increment_for_price( 50.00, $product_id ) );

        $wpdb = $original_wpdb;
    }
}
