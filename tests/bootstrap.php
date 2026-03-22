<?php
/**
 * PHPUnit bootstrap file.
 *
 * Provides stubs for WordPress functions and WooCommerce classes
 * so unit tests can run without a full WordPress environment.
 *
 * @package WC\\Auction\\Tests
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define plugin constants
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'WcAuction_VERSION' ) ) {
    define( 'WcAuction_VERSION', '1.2.4' );
}

if ( ! defined( 'WcAuction_PATH' ) ) {
    define( 'WcAuction_PATH', dirname( __DIR__ ) . '/' );
}

// Stub WordPress global $wpdb
if ( ! class_exists( 'wpdb' ) ) {
    class wpdb {
        public $prefix = 'wp_';
        public $base_prefix = 'wp_';
        public $last_error = '';
        public $insert_id = 0;

        public function prepare( $query, ...$args ) {
            return vsprintf( str_replace( '%s', "'%s'", str_replace( '%d', '%s', $query ) ), $args );
        }

        public function query( $query ) {
            return true;
        }

        public function get_results( $query, $output = OBJECT ) {
            return array();
        }

        public function get_row( $query, $output = OBJECT, $y = 0 ) {
            return null;
        }

        public function get_var( $query, $x = 0, $y = 0 ) {
            return null;
        }

        public function insert( $table, $data, $format = null ) {
            $this->insert_id = 1;
            return 1;
        }

        public function delete( $table, $where, $where_format = null ) {
            return 1;
        }
    }
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

global $wpdb;
$wpdb = new wpdb();

// Stub WordPress functions used by the plugin
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$args ) {
        return $value;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $tag, ...$args ) {}
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        return true;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return htmlspecialchars( strip_tags( trim( $str ) ), ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return false;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability, ...$args ) {
        return true;
    }
}

if ( ! function_exists( 'wc_price' ) ) {
    function wc_price( $price ) {
        return '$' . number_format( (float) $price, 2 );
    }
}

if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( $queries = '', $execute = true ) {
        return array();
    }
}

// Stub WooCommerce WC_Product base class
if ( ! class_exists( 'WC_Data' ) ) {
    class WC_Data {
        protected $data = array();

        public function get_prop( $prop, $context = 'view' ) {
            return isset( $this->data[ $prop ] ) ? $this->data[ $prop ] : null;
        }

        public function set_prop( $prop, $value ) {
            $this->data[ $prop ] = $value;
        }
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product extends WC_Data {
        protected $id = 0;
        public $stock = 1;

        public function __construct( $product = 0 ) {
            if ( is_numeric( $product ) ) {
                $this->id = (int) $product;
            }
        }

        public function get_id() {
            return $this->id;
        }

        public function get_type() {
            return 'simple';
        }

        public function is_in_stock() {
            return true;
        }

        public function get_availability() {
            return array( 'availability' => 'In Stock', 'class' => 'in-stock' );
        }

        public function set_stock_quantity( $qty ) {
            $this->stock = (int) $qty;
        }
    }
}

if ( ! class_exists( 'WC_Product_Auction' ) && ! function_exists( 'class_exists' ) ) {
    // Will be loaded later
}

// In-memory store for post meta stubs
global $_test_post_meta;
$_test_post_meta = array();

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key = '', $single = false ) {
        global $_test_post_meta;
        $post_id = (int) $post_id;

        if ( empty( $key ) ) {
            return isset( $_test_post_meta[ $post_id ] ) ? $_test_post_meta[ $post_id ] : array();
        }

        if ( ! isset( $_test_post_meta[ $post_id ][ $key ] ) ) {
            return $single ? '' : array();
        }

        $values = $_test_post_meta[ $post_id ][ $key ];

        return $single ? $values[0] : $values;
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
        global $_test_post_meta;
        $post_id = (int) $post_id;
        $_test_post_meta[ $post_id ][ $meta_key ] = array( $meta_value );
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
        global $_test_post_meta;
        $post_id = (int) $post_id;
        unset( $_test_post_meta[ $post_id ][ $meta_key ] );
        return true;
    }
}

// YIT Framework stubs
if ( ! function_exists( 'yit_get_prop' ) ) {
    function yit_get_prop( $object, $prop, $single = false ) {
        // If it's a WC_Product, try to get product meta
        if ( is_object( $object ) && method_exists( $object, 'get_id' ) ) {
            $product_id = $object->get_id();
            // Convert prop name from WC convention to meta key
            if ( strpos( $prop, '_' ) === 0 ) {
                $value = get_post_meta( $product_id, $prop, true );
                // Return null if empty/not set
                return ( '' === $value || null === $value ) ? null : $value;
            }
        }
        return null;
    }
}

if ( ! function_exists( 'yit_set_prop' ) ) {
    function yit_set_prop( $object, $prop, $value ) {
        if ( is_object( $object ) && method_exists( $object, 'get_id' ) ) {
            $product_id = $object->get_id();
            if ( strpos( $prop, '_' ) === 0 ) {
                update_post_meta( $product_id, $prop, $value );
            }
        }
    }
}
