<?php
/**
 * Database Migration Runner
 *
 * @package    WooCommerce Auction
 * @subpackage Migrations
 * @version    1.0.0
 * @requirement REQ-AB-001: Ensure database schema is up-to-date
 */

namespace WC\Auction\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Migration runner - orchestrates database schema migrations
 *
 * UML Class Diagram:
 * ```
 * MigrationRunner (Singleton)
 * ├── private $instance
 * ├── private $migrations = []
 * ├── get_instance()
 * ├── register($migration_class)
 * ├── run_pending()
 * ├── run_migration($migration)
 * └── get_migration_status()
 * ```
 *
 * Design Pattern: Singleton + Command Pattern
 * - Ensures single migration runner per request
 * - Each migration implements up/down/isApplied interface
 * - Tracks applied migrations in wp_options
 *
 * @requirement REQ-AB-001: Ensure database schema is created and updated
 */
class MigrationRunner {
    
    const OPTION_KEY = 'wc_auction_migrations_applied';
    
    /**
     * Singleton instance
     *
     * @var MigrationRunner
     */
    private static $instance = null;
    
    /**
     * Registered migrations to run
     *
     * @var array
     */
    private $migrations = [];
    
    /**
     * Get singleton instance
     *
     * @return MigrationRunner
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - private for singleton
     */
    private function __construct() {
        $this->register_migrations();
    }
    
    /**
     * Register all available migrations
     *
     * @return void
     */
    private function register_migrations(): void {
        // Auto-bidding migrations (Phase 4-A)
        $this->register( '1_4_0_create_proxy_bids_table', Migration_1_4_0_CreateProxyBidsTable::class );
        $this->register( '1_4_0_create_auto_audit_log', Migration_1_4_0_CreateAutoAuditLog::class );
        $this->register( '1_4_0_add_auto_bid_to_bids', Migration_1_4_0_AddAutoBidToBids::class );
        
        // Settlement & Payouts migrations (Phase 4-D)
        $this->register( '4_0_0_create_settlement_batches', Migration_4_0_0_CreateSettlementBatches::class );
        $this->register( '4_0_0_create_seller_payouts', Migration_4_0_0_CreateSellerPayouts::class );
        $this->register( '4_0_0_create_payout_methods', Migration_4_0_0_CreatePayoutMethods::class );
        $this->register( '4_0_0_create_commission_rules', Migration_4_0_0_CreateCommissionRules::class );
    }
    
    /**
     * Register a migration
     *
     * @param string $key              Migration key (unique identifier)
     * @param string $migration_class  Fully qualified migration class name
     * @return void
     */
    public function register( string $key, string $migration_class ): void {
        $this->migrations[ $key ] = $migration_class;
    }
    
    /**
     * Run all pending migrations
     *
     * @return array Migration results
     */
    public function run_pending(): array {
        $results = [];
        $applied = $this->get_applied_migrations();
        
        foreach ( $this->migrations as $key => $migration_class ) {
            // Skip if already applied
            if ( in_array( $key, $applied, true ) ) {
                $results[ $key ] = [
                    'status' => 'skipped',
                    'message' => 'Already applied',
                ];
                continue;
            }
            
            // Check if migration class has isApplied method (for external verification)
            if ( method_exists( $migration_class, 'isApplied' ) && call_user_func( [ $migration_class, 'isApplied' ] ) ) {
                // Migrate was applied but not tracked - mark it
                $this->mark_migration_applied( $key );
                $results[ $key ] = [
                    'status' => 'tracked',
                    'message' => 'Migration was already applied (tracked retroactively)',
                ];
                continue;
            }
            
            // Run the migration
            $results[ $key ] = $this->run_migration( $key, $migration_class );
        }
        
        return $results;
    }
    
    /**
     * Run a single migration
     *
     * @param string $key              Migration key
     * @param string $migration_class  Migration class
     * @return array Result of migration
     */
    private function run_migration( string $key, string $migration_class ): array {
        try {
            if ( ! method_exists( $migration_class, 'up' ) ) {
                return [
                    'status'  => 'error',
                    'message' => "Migration class {$migration_class} has no 'up' method",
                ];
            }
            
            // Start transaction
            global $wpdb;
            $wpdb->query( 'START TRANSACTION' );
            
            // Run migration
            $success = call_user_func( [ $migration_class, 'up' ] );
            
            if ( ! $success ) {
                $wpdb->query( 'ROLLBACK' );
                return [
                    'status'  => 'error',
                    'message' => 'Migration failed or returned false',
                ];
            }
            
            // Commit transaction
            $wpdb->query( 'COMMIT' );
            
            // Mark as applied
            $this->mark_migration_applied( $key );
            
            return [
                'status'  => 'applied',
                'message' => 'Migration applied successfully',
            ];
        } catch ( \Exception $e ) {
            global $wpdb;
            $wpdb->query( 'ROLLBACK' );
            
            return [
                'status'  => 'error',
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get list of applied migrations
     *
     * @return array Applied migration keys
     */
    private function get_applied_migrations(): array {
        $applied = get_option( self::OPTION_KEY, [] );
        return is_array( $applied ) ? $applied : [];
    }
    
    /**
     * Mark migration as applied
     *
     * @param string $key Migration key
     * @return void
     */
    private function mark_migration_applied( string $key ): void {
        $applied = $this->get_applied_migrations();
        
        if ( ! in_array( $key, $applied, true ) ) {
            $applied[] = $key;
            update_option( self::OPTION_KEY, $applied );
        }
    }
    
    /**
     * Get migration status
     *
     * @return array Status of all migrations
     */
    public function get_migration_status(): array {
        $status  = [];
        $applied = $this->get_applied_migrations();
        
        foreach ( $this->migrations as $key => $migration_class ) {
            $status[ $key ] = [
                'class'    => $migration_class,
                'applied'  => in_array( $key, $applied, true ),
                'exists'   => method_exists( $migration_class, 'isApplied' ) && call_user_func( [ $migration_class, 'isApplied' ] ),
            ];
        }
        
        return $status;
    }
    
    /**
     * Rollback last migration (for development/debugging)
     *
     * @param string $key Migration key to rollback
     * @return array Result of rollback
     */
    public function rollback( string $key ): array {
        if ( ! isset( $this->migrations[ $key ] ) ) {
            return [
                'status'  => 'error',
                'message' => "Migration '{$key}' not found",
            ];
        }
        
        $migration_class = $this->migrations[ $key ];
        
        try {
            if ( ! method_exists( $migration_class, 'down' ) ) {
                return [
                    'status'  => 'error',
                    'message' => "Migration class {$migration_class} has no 'down' method",
                ];
            }
            
            global $wpdb;
            $wpdb->query( 'START TRANSACTION' );
            
            $success = call_user_func( [ $migration_class, 'down' ] );
            
            if ( ! $success ) {
                $wpdb->query( 'ROLLBACK' );
                return [
                    'status'  => 'error',
                    'message' => 'Rollback failed or returned false',
                ];
            }
            
            $wpdb->query( 'COMMIT' );
            
            // Remove from applied list
            $applied = $this->get_applied_migrations();
            $applied = array_filter( $applied, fn( $k ) => $k !== $key );
            update_option( self::OPTION_KEY, array_values( $applied ) );
            
            return [
                'status'  => 'rolled_back',
                'message' => 'Migration rolled back successfully',
            ];
        } catch ( \Exception $e ) {
            global $wpdb;
            $wpdb->query( 'ROLLBACK' );
            
            return [
                'status'  => 'error',
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }
}
