<?php
/**
 * DB class
 *
 * @author  Yithemes
 * @package YITH WooCommerce Booking
 * @version 1.0.0
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly

if ( !class_exists( 'YITH_WCACT_DB' ) ) {
    /**
     * YITH WooCommerce Booking Database
     *
     * @since 1.0.0
     */
    class YITH_WCACT_DB {

        /**
         * DB version
         *
         * @var string
         */
        public static $version = '1.1.0';

        public static $auction_table = 'yith_wcact_auction';

        public static $bid_increment_table = 'yith_wcact_bid_increment';


        /**
         * Constructor
         *
         * @return YITH_WCBK_DB
         */
        private function __construct() {
        }

        public static function install() {
            self::create_db_table();
        }

        /**
         * Create database tables for auctions and bid increments.
         *
         * @param bool $force Force table recreation.
         *
         * @requirement REQ-002 Bid increment by price range
         */
        public static function create_db_table( $force = false ) {
            global $wpdb;

            $current_version = get_option( 'yith_wcact_db_version' );

            if ( $force || $current_version != self::$version ) {
                $wpdb->hide_errors();

                $charset_collate = $wpdb->get_charset_collate();

                if ( !function_exists( 'dbDelta' ) ) {
                    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
                }

                // Auction bids table
                $table_name = $wpdb->prefix . self::$auction_table;
                $sql = "CREATE TABLE $table_name (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `user_id` bigint(20) NOT NULL,
                    `auction_id` bigint(20) NOT NULL,
                    `bid` varchar(255) NOT NULL,
                    `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                    ) $charset_collate;";
                dbDelta( $sql );

                // Bid increment ranges table
                $increment_table = $wpdb->prefix . self::$bid_increment_table;
                $sql_increment = "CREATE TABLE $increment_table (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `product_id` bigint(20) NOT NULL DEFAULT 0,
                    `from_price` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `increment` decimal(10,2) NOT NULL DEFAULT 1.00,
                    PRIMARY KEY (id),
                    KEY `product_id` (`product_id`),
                    KEY `from_price` (`from_price`)
                    ) $charset_collate;";
                dbDelta( $sql_increment );

                update_option( 'yith_wcact_db_version', self::$version );
            }
        }

    }
}