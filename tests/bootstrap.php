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
        private $data_store = array(); // In-memory data store for tests
        private $last_query = '';

        public function prepare( $query, ...$args ) {
            // Store for debugging
            $this->last_query = $query;
            
            // Handle array or variadic args
            if ( count( $args ) === 1 && is_array( $args[0] ) ) {
                $args = $args[0];
            }
            
            // Replace placeholders
            $count = 0;
            $result = preg_replace_callback(
                '/(%d|%s|%f)/',
                function( $matches ) use ( $args, &$count ) {
                    if ( $count >= count( $args ) ) {
                        return $matches[0];
                    }
                    $value = $args[ $count++ ];
                    if ( $matches[0] === '%d' ) {
                        return (int) $value;
                    } elseif ( $matches[0] === '%s' ) {
                        return "'" . addslashes( $value ) . "'";
                    } elseif ( $matches[0] === '%f' ) {
                        return (float) $value;
                    }
                    return $value;
                },
                $query
            );
            
            return $result;
        }

        public function query( $query ) {
            // Handle TRUNCATE queries
            if ( preg_match( '/TRUNCATE\s+TABLE\s+(\w+)$/is', $query, $matches ) ) {
                $table = $matches[1];
                $this->data_store[ $table ] = array();
                return true;
            }

            // Handle UPDATE queries
            if ( preg_match( '/UPDATE\s+(\w+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $query, $matches ) ) {
                $table = $matches[1];
                $set_clause = $matches[2];
                $where_clause = $matches[3];
                
                if ( ! isset( $this->data_store[ $table ] ) ) {
                    return 0;
                }
                
                $updated = 0;
                
                // Parse SET clause (simple: column = value, column = value)
                $set_parts = preg_split( '/\s*,\s*/', $set_clause );
                $updates = array();
                foreach ( $set_parts as $part ) {
                    if ( preg_match( '/(\w+)\s*=\s*\'([^\']*)\'/', $part, $match ) ) {
                        $updates[ $match[1] ] = $match[2];
                    } elseif ( preg_match( '/(\w+)\s*=\s*(\d+)/', $part, $match ) ) {
                        $updates[ $match[1] ] = (int) $match[2];
                    }
                }
                
                foreach ( $this->data_store[ $table ] as &$row ) {
                    if ( $this->matches_where( $row, $where_clause ) ) {
                        foreach ( $updates as $field => $value ) {
                            $row[ $field ] = $value;
                        }
                        $updated++;
                    }
                }
                
                return $updated;
            }
            
            return true;
        }

        public function get_results( $query, $output = OBJECT ) {
            // Handle COUNT(*) queries
            if ( preg_match( '/SELECT\s+COUNT\(\*\)\s+FROM\s+(\w+)(.*)$/is', $query, $matches ) ) {
                $table = $matches[1];
                $rest = trim( $matches[2] ?? '' );
                
                if ( isset( $this->data_store[ $table ] ) ) {
                    $results = $this->data_store[ $table ];
                    
                    // Parse WHERE conditions
                    if ( ! empty( $rest ) && preg_match( '/WHERE\s+(.+?)(?:\s+ORDER|$)/is', $rest, $where_match ) ) {
                        $where_clause = trim( $where_match[1] );
                        $results = $this->filter_results( $results, $where_clause );
                    }
                    
                    $count = count( $results );
                    $result_row = array( 'COUNT(*)' => $count );
                    
                    if ( $output === 'ARRAY_A' || $output === ARRAY_A ) {
                        return array( $result_row );
                    } elseif ( $output === OBJECT ) {
                        return array( (object) $result_row );
                    }
                    return array( $result_row );
                }
                return array();
            }

            // Parse simple SELECT queries for testing
            if ( preg_match( '/SELECT \* FROM (\w+)(.*)$/is', $query, $matches ) ) {
                $table = $matches[1];
                $rest = trim( $matches[2] ?? '' );
                
                // Return mock data for testing
                if ( isset( $this->data_store[ $table ] ) ) {
                    $results = $this->data_store[ $table ];
                    
                    // Parse WHERE conditions
                    if ( ! empty( $rest ) && preg_match( '/WHERE\s+(.+?)(?:\s+ORDER|$)/is', $rest, $where_match ) ) {
                        $where_clause = trim( $where_match[1] );
                        $results = $this->filter_results( $results, $where_clause );
                    }
                    
                    // Convert output format if needed
                    if ( $output === 'ARRAY_A' || $output === ARRAY_A ) {
                        return $results;
                    } elseif ( $output === OBJECT ) {
                        return array_map( function( $row ) {
                            return (object) $row;
                        }, $results );
                    }
                    return $results;
                }
            }
            return array();
        }

        public function get_row( $query, $output = OBJECT, $y = 0 ) {
            // Get results and return first row
            $results = $this->get_results( $query, $output );
            return ! empty( $results ) ? $results[0] : null;
        }

        public function get_var( $query, $x = 0, $y = 0 ) {
            $row = $this->get_row( $query, ARRAY_A );
            if ( $row ) {
                $keys = array_keys( $row );
                return $row[ $keys[ $x ] ] ?? null;
            }
            return null;
        }

        public function insert( $table, $data, $format = null ) {
            if ( ! isset( $this->data_store[ $table ] ) ) {
                $this->data_store[ $table ] = array();
            }
            
            $data['id'] = count( $this->data_store[ $table ] ) + 1;
            $this->insert_id = $data['id'];
            $this->data_store[ $table ][] = $data;
            
            return 1;
        }

        public function update( $table, $data, $where, $data_format = null, $where_format = null ) {
            if ( ! isset( $this->data_store[ $table ] ) ) {
                return 0;
            }

            $updated = 0;
            foreach ( $this->data_store[ $table ] as &$row ) {
                $matches = true;
                foreach ( $where as $key => $value ) {
                    if ( ! isset( $row[ $key ] ) || (string) $row[ $key ] !== (string) $value ) {
                        $matches = false;
                        break;
                    }
                }
                
                if ( $matches ) {
                    foreach ( $data as $key => $value ) {
                        $row[ $key ] = $value;
                    }
                    $updated++;
                }
            }
            
            return $updated;
        }

        public function delete( $table, $where, $where_format = null ) {
            if ( ! isset( $this->data_store[ $table ] ) ) {
                return 0;
            }

            $deleted = 0;
            $this->data_store[ $table ] = array_filter(
                $this->data_store[ $table ],
                function( $row ) use ( $where, &$deleted ) {
                    $matches = true;
                    foreach ( $where as $key => $value ) {
                        if ( ! isset( $row[ $key ] ) || (string) $row[ $key ] !== (string) $value ) {
                            $matches = false;
                            break;
                        }
                    }
                    if ( $matches ) {
                        $deleted++;
                        return false; // Remove from array
                    }
                    return true; // Keep in array
                }
            );

            return $deleted;
        }

        /**
         * Filter results based on WHERE clause
         *
         * @param array  $results Results to filter
         * @param string $where_clause WHERE clause from query
         * @return array Filtered results
         */
        private function filter_results( $results, $where_clause ) {
            $filtered = array();
            
            foreach ( $results as $row ) {
                if ( $this->matches_where( $row, $where_clause ) ) {
                    $filtered[] = $row;
                }
            }
            
            return $filtered;
        }

        /**
         * Check if row matches WHERE clause
         *
         * @param array  $row Row from results
         * @param string $where_clause WHERE clause to match
         * @return bool True if row matches
         */
        private function matches_where( $row, $where_clause ) {
            // Parse multiple conditions with AND/OR
            // For now, simple AND support
            $parts = preg_split( '/\s+AND\s+/i', $where_clause );
            
            foreach ( $parts as $part ) {
                $part = trim( $part );
                
                if ( preg_match( '/(\w+)\s*=\s*\'([^\']*)\'/i', $part, $match ) ) {
                    $field = $match[1];
                    $value = $match[2];
                    
                    if ( ! isset( $row[ $field ] ) || (string) $row[ $field ] !== $value ) {
                        return false;
                    }
                } elseif ( preg_match( '/(\w+)\s*=\s*(\d+)/i', $part, $match ) ) {
                    $field = $match[1];
                    $value = (int) $match[2];
                    
                    if ( ! isset( $row[ $field ] ) || (int) $row[ $field ] !== $value ) {
                        return false;
                    }
                } elseif ( preg_match( '/(\w+)\s*>=\s*\'([^\']*)\'/i', $part, $match ) ) {
                    $field = $match[1];
                    $value = $match[2];
                    
                    if ( ! isset( $row[ $field ] ) || (string) $row[ $field ] < $value ) {
                        return false;
                    }
                } elseif ( preg_match( '/(\w+)\s*<=\s*\'([^\']*)\'/i', $part, $match ) ) {
                    $field = $match[1];
                    $value = $match[2];
                    
                    if ( ! isset( $row[ $field ] ) || (string) $row[ $field ] > $value ) {
                        return false;
                    }
                }
            }
            
            return true;
        }
    }
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
    define( 'ARRAY_N', 'ARRAY_N' );
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

        return $_test_post_meta[ $post_id ][ $key ];
    }
}

// Redis stub interface and class for mocking
if ( ! interface_exists( 'RedisInterface' ) ) {
    interface RedisInterface
    {
        public function ping();
        public function zAdd( $key, $score, $member );
        public function zRange( $key, $start, $end );
        public function zRem( $key, $member );
        public function zCard( $key );
        public function zScore( $key, $member );
        public function hSet( $key, $field, $value );
        public function hGet( $key, $field );
        public function hDel( $key, $field );
        public function lPush( $key, $value );
        public function lRange( $key, $start, $end );
        public function del( ...$keys );
        public function expire( $key, $ttl );
    }
}

if ( ! class_exists( 'Redis' ) ) {
    class Redis implements RedisInterface
    {
        public function ping() { return true; }
        public function zAdd( $key, $score, $member ) { return 1; }
        public function zRange( $key, $start, $end ) { return array(); }
        public function zRem( $key, $member ) { return 1; }
        public function zCard( $key ) { return 0; }
        public function zScore( $key, $member ) { return false; }
        public function hSet( $key, $field, $value ) { return 1; }
        public function hGet( $key, $field ) { return null; }
        public function hDel( $key, $field ) { return 1; }
        public function lPush( $key, $value ) { return 1; }
        public function lRange( $key, $start, $end ) { return array(); }
        public function del( ...$keys ) { return 1; }
        public function expire( $key, $ttl ) { return 1; }
    }
}

if ( ! class_exists( 'RedisException' ) ) {
    class RedisException extends \Exception {}
}

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
