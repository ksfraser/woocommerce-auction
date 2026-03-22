<?php
/**
 * Bid Increment Range Manager
 *
 * Manages bid increment ranges stored in a dedicated database table.
 * Supports both global (product_id = 0) and product-specific ranges.
 *
 * @package    YITH WooCommerce Auctions
 * @since      1.3.0
 * @requirement REQ-002 Bid increment by price range
 *
 * @startuml
 * class YITH_WCACT_Bid_Increment {
 *   -instance : YITH_WCACT_Bid_Increment
 *   -table_name : string
 *   +get_instance() : YITH_WCACT_Bid_Increment
 *   +get_increment_for_price(float, int) : float
 *   +get_ranges(int) : array
 *   +save_ranges(int, array) : bool
 *   +delete_ranges(int) : bool
 *   +copy_global_to_product(int) : bool
 *   +product_uses_global(int) : bool
 * }
 * @enduml
 */

if ( ! defined( 'YITH_WCACT_VERSION' ) ) {
    exit( 'Direct access forbidden.' );
}

if ( ! class_exists( 'YITH_WCACT_Bid_Increment' ) ) {

    /**
     * YITH_WCACT_Bid_Increment
     *
     * @since 1.3.0
     */
    class YITH_WCACT_Bid_Increment {

        /**
         * Single instance of the class.
         *
         * @var YITH_WCACT_Bid_Increment|null
         */
        protected static $instance;

        /**
         * Database table name (with prefix).
         *
         * @var string
         */
        private $table_name = '';

        /**
         * Returns single instance of the class.
         *
         * @return YITH_WCACT_Bid_Increment
         */
        public static function get_instance() {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor.
         */
        public function __construct() {
            global $wpdb;
            $this->table_name = $wpdb->prefix . 'yith_wcact_bid_increment';
        }

        /**
         * Get the bid increment amount for a given current price and product.
         *
         * If the product has custom ranges, use those; otherwise use global ranges.
         * Returns the increment for the matching range, or the default (1) if none match.
         *
         * @param float $current_price The current bid price.
         * @param int   $product_id    The product ID (0 for global lookup only).
         *
         * @return float The bid increment amount.
         *
         * @requirement REQ-002 Bid increment by price range
         */
        public function get_increment_for_price( $current_price, $product_id = 0 ) {
            $current_price = (float) $current_price;
            $ranges        = array();

            // Check product-specific ranges first
            if ( $product_id > 0 && ! $this->product_uses_global( $product_id ) ) {
                $ranges = $this->get_ranges( $product_id );
            }

            // Fall back to global if no product ranges
            if ( empty( $ranges ) ) {
                $ranges = $this->get_ranges( 0 );
            }

            // Sort by from_price ascending so we match the correct range
            usort( $ranges, function ( $a, $b ) {
                return ( (float) $a->from_price ) - ( (float) $b->from_price );
            });

            $increment = 1.00; // default fallback

            foreach ( $ranges as $range ) {
                if ( $current_price >= (float) $range->from_price ) {
                    $increment = (float) $range->increment;
                }
            }

            return apply_filters( 'yith_wcact_bid_increment', $increment, $current_price, $product_id );
        }

        /**
         * Get all bid increment ranges for a given product (0 = global).
         *
         * @param int $product_id Product ID (0 for global).
         *
         * @return array Array of range objects with from_price, increment.
         */
        public function get_ranges( $product_id = 0 ) {
            global $wpdb;

            $product_id = absint( $product_id );
            $query      = $wpdb->prepare(
                "SELECT id, product_id, from_price, increment FROM {$this->table_name} WHERE product_id = %d ORDER BY from_price ASC",
                $product_id
            );

            $results = $wpdb->get_results( $query );

            return is_array( $results ) ? $results : array();
        }

        /**
         * Save bid increment ranges for a product (or global with product_id=0).
         *
         * Replaces all existing ranges for this product_id.
         *
         * @param int   $product_id Product ID (0 for global).
         * @param array $ranges     Array of arrays with 'from_price' and 'increment' keys.
         *
         * @return bool True on success.
         */
        public function save_ranges( $product_id, $ranges ) {
            global $wpdb;

            $product_id = absint( $product_id );

            // Delete existing ranges
            $this->delete_ranges( $product_id );

            if ( empty( $ranges ) ) {
                return true;
            }

            foreach ( $ranges as $range ) {
                $from_price = isset( $range['from_price'] ) ? floatval( $range['from_price'] ) : 0.0;
                $increment  = isset( $range['increment'] ) ? floatval( $range['increment'] ) : 1.0;

                if ( $increment <= 0 ) {
                    $increment = 1.0;
                }

                $wpdb->insert(
                    $this->table_name,
                    array(
                        'product_id' => $product_id,
                        'from_price' => $from_price,
                        'increment'  => $increment,
                    ),
                    array( '%d', '%f', '%f' )
                );
            }

            return true;
        }

        /**
         * Delete all ranges for a product (or global with product_id=0).
         *
         * @param int $product_id Product ID (0 for global).
         *
         * @return bool True on success.
         */
        public function delete_ranges( $product_id ) {
            global $wpdb;

            $wpdb->delete(
                $this->table_name,
                array( 'product_id' => absint( $product_id ) ),
                array( '%d' )
            );

            return true;
        }

        /**
         * Copy global ranges to a product as a starting point.
         *
         * @param int $product_id Target product ID.
         *
         * @return bool True on success.
         */
        public function copy_global_to_product( $product_id ) {
            $product_id = absint( $product_id );

            if ( $product_id <= 0 ) {
                return false;
            }

            $global_ranges = $this->get_ranges( 0 );
            $ranges_array  = array();

            foreach ( $global_ranges as $range ) {
                $ranges_array[] = array(
                    'from_price' => $range->from_price,
                    'increment'  => $range->increment,
                );
            }

            return $this->save_ranges( $product_id, $ranges_array );
        }

        /**
         * Check if a product uses the global bid increment settings.
         *
         * @param int $product_id Product ID.
         *
         * @return bool True if product uses global increment ranges.
         */
        public function product_uses_global( $product_id ) {
            $use_global = get_post_meta( absint( $product_id ), '_yith_auction_bid_increment_use_global', true );
            // Default to 'yes' (use global) if not set
            return ( '' === $use_global || 'yes' === $use_global );
        }

        /**
         * Set whether a product uses global bid increment settings.
         *
         * @param int  $product_id Product ID.
         * @param bool $use_global Whether to use global settings.
         *
         * @return void
         */
        public function set_product_uses_global( $product_id, $use_global ) {
            update_post_meta(
                absint( $product_id ),
                '_yith_auction_bid_increment_use_global',
                $use_global ? 'yes' : 'no'
            );
        }
    }
}
