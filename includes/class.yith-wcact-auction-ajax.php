<?php
/**
 * Notes class
 *
 * @author  Yithemes
 * @package YITH WooCommerce Auctions
 * @version 1.0.0
 */

if ( !defined( 'YITH_WCACT_VERSION' ) ) {
    exit( 'Direct access forbidden.' );
}

if ( !class_exists( 'YITH_WCACT_Auction_Ajax' ) ) {
    /**
     * YITH_WCACT_Auction_Ajax
     *
     * @since 1.0.0
     */
    class YITH_WCACT_Auction_Ajax
    {

        /**
         * Single instance of the class
         *
         * @var \YITH_WCACT_Auction_Ajax
         * @since 1.0.0
         */
        protected static $instance;


        /**
         * Returns single instance of the class
         *
         * @return \YITH_WCACT_Auction_Ajax
         * @since 1.0.0
         */
        public static function get_instance() {
            $self = __CLASS__ . ( class_exists( __CLASS__ . '_Premium' ) ? '_Premium' : '' );

            if ( is_null( $self::$instance ) ) {
                $self::$instance = new $self;
            }

            return $self::$instance;
        }

        /**
         * Constructor
         *
         * @since  1.0.0
         * @author Carlos Rodríguez <carlos.rodriguez@yourinspiration.it>
         */
        public function __construct()
        {
            add_action('wp_ajax_yith_wcact_add_bid', array($this, 'yith_wcact_add_bid'));
            add_action('wp_ajax_nopriv_yith_wcact_add_bid', array($this, 'redirect_to_my_account'));
        }

        /**
         * Add a bid to the product.
         *
         * Validates bid against starting price, minimum increment, and current price.
         *
         * @since  1.3.0
         * @author Carlos Rodríguez <carlos.rodriguez@yourinspiration.it>
         *
         * @requirement REQ-001 Starting bid (minimum bid)
         * @requirement REQ-002 Bid increment by price range
         */
        public function yith_wcact_add_bid()
        {
            $userid = get_current_user_id();

            $user_can_make_bid = apply_filters( 'yith_wcact_user_can_make_bid', true, $userid );

            if ( !$user_can_make_bid ) {
                die();
            }

            if ($userid && isset($_POST['bid']) && isset($_POST['product'])) {
                $bid = floatval( sanitize_text_field( wp_unslash( $_POST['bid'] ) ) );
                $product_id = absint( apply_filters( 'yith_wcact_auction_product_id', absint( $_POST['product'] ) ) );
                $date = date("Y-m-d H:i:s");

                $product = wc_get_product($product_id);
                if ($product && $product->is_type('auction')) {
                    $bids = YITH_Auctions()->bids;
                    $current_price = (float) $product->get_price();
                    $exist_auctions = $bids->get_max_bid($product_id);
                    $last_bid_user = $bids->get_last_bid_user($userid, $product_id);

                    // Get minimum bid (start price + increment enforcement)
                    $minimum_bid = $product->get_minimum_bid();
                    $bid_increment = $product->get_bid_increment();

                    $bid_accepted = false;

                    if ($exist_auctions) {
                        // Bid must be >= current price + increment
                        $min_next = (float) $exist_auctions->bid + $bid_increment;
                        if ($bid >= $min_next && !$last_bid_user) {
                            $bids->add_bid($userid, $product_id, $bid, $date);
                            $bid_accepted = true;
                        } elseif ($last_bid_user && $bid >= $min_next && $bid > (float) $last_bid_user) {
                            $bids->add_bid($userid, $product_id, $bid, $date);
                            $bid_accepted = true;
                        }
                    } else {
                        // First bid: must meet or exceed start price
                        if ($bid >= $minimum_bid) {
                            $bids->add_bid($userid, $product_id, $bid, $date);
                            $bid_accepted = true;
                        }
                    }

                    $user_bid = array(
                        'user_id'      => $userid,
                        'product_id'   => $product_id,
                        'bid'          => $bid,
                        'date'         => $date,
                        'url'          => get_permalink($product_id),
                        'bid_accepted' => $bid_accepted,
                        'minimum_bid'  => $minimum_bid,
                        'increment'    => $bid_increment,
                    );

                    $actual_price = $product->get_current_bid();
                    yit_save_prop($product,'_price', $actual_price);
                }
                wp_send_json($user_bid);
            }
            die();
        }

        /**
         * Redirect to user (My account)
         *
         * @since  1.0.0
         * @author Carlos Rodríguez <carlos.rodriguez@yourinspiration.it>
         */
        public function redirect_to_my_account()
        {
            if (!is_user_logged_in()) {
                $account = wc_get_page_permalink( 'myaccount');

                if (isset($_POST['bid']) && isset($_POST['product'])) {
                    $url_to_redirect = add_query_arg('redirect_after_login',get_permalink($_POST['product']),$account);
                    $array = array(
                        'product_id' => $_POST['product'],
                        'bid'       => $_POST['bid'],
                        'url'       => $url_to_redirect,
                    );

                }
                wp_send_json($array);
            }
            die();
        }
    }
}