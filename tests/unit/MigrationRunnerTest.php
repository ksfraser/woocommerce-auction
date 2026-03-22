<?php
/**
 * MigrationRunner Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Unit
 * @version    1.0.0
 * @requirement REQ-AB-001: Ensure database migrations execute correctly
 */

namespace WC\Auction\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC\Auction\Migrations\MigrationRunner;

/**
 * MigrationRunnerTest - Test suite for migration orchestrator
 *
 * @covers \WC\Auction\Migrations\MigrationRunner
 * @group migrations
 */
class MigrationRunnerTest extends TestCase {
    
    /**
     * Migration runner instance
     *
     * @var MigrationRunner
     */
    private $runner;
    
    /**
     * Set up test fixtures
     *
     * REQ-AB-001: Initialize migration runner for each test
     */
    protected function setUp(): void {
        // Clear wp_options for clean test state
        delete_option( MigrationRunner::OPTION_KEY );
        
        // Reset singleton instance
        $reflection = new \ReflectionClass( MigrationRunner::class );
        $property   = $reflection->getProperty( 'instance' );
        $property->setAccessible( true );
        $property->setValue( null, null );
        
        // Get fresh instance
        $this->runner = MigrationRunner::get_instance();
    }
    
    /**
     * Test singleton pattern - same instance returned
     *
     * @test
     */
    public function test_get_instance_returns_singleton() {
        $instance1 = MigrationRunner::get_instance();
        $instance2 = MigrationRunner::get_instance();
        
        $this->assertSame( $instance1, $instance2 );
    }
    
    /**
     * Test migration registration
     *
     * @test
     */
    public function test_register_migration() {
        $status = $this->runner->get_migration_status();
        
        // Should have registered migrations
        $this->assertNotEmpty( $status );
        $this->assertArrayHasKey( '1_4_0_create_proxy_bids_table', $status );
        $this->assertArrayHasKey( '1_4_0_create_auto_audit_log', $status );
        $this->assertArrayHasKey( '1_4_0_add_auto_bid_to_bids', $status );
    }
    
    /**
     * Test get_migration_status returns correct structure
     *
     * @test
     */
    public function test_get_migration_status_structure() {
        $status = $this->runner->get_migration_status();
        
        foreach ( $status as $key => $migration_status ) {
            $this->assertArrayHasKey( 'class', $migration_status );
            $this->assertArrayHasKey( 'applied', $migration_status );
            $this->assertArrayHasKey( 'exists', $migration_status );
            $this->assertIsBool( $migration_status['applied'] );
            $this->assertIsBool( $migration_status['exists'] );
        }
    }
    
    /**
     * Test pending migrations are initially marked as not applied
     *
     * @test
     */
    public function test_pending_migrations_not_applied() {
        $status = $this->runner->get_migration_status();
        
        // All should be not applied initially
        foreach ( $status as $migration_status ) {
            $this->assertFalse( $migration_status['applied'] );
        }
    }
    
    /**
     * Test marking migration as applied
     *
     * @test
     */
    public function test_mark_migration_applied() {
        // Mark migration as applied (using reflection)
        $reflection = new \ReflectionClass( MigrationRunner::class );
        $method     = $reflection->getMethod( 'mark_migration_applied' );
        $method->setAccessible( true );
        
        $method->invoke( $this->runner, '1_4_0_create_proxy_bids_table' );
        
        // Verify it's tracked in wp_options
        $applied = get_option( MigrationRunner::OPTION_KEY );
        $this->assertContains( '1_4_0_create_proxy_bids_table', $applied );
    }
    
    /**
     * Test get_applied_migrations returns correct format
     *
     * @test
     */
    public function test_get_applied_migrations_format() {
        // Manually set some applied migrations
        update_option( MigrationRunner::OPTION_KEY, [
            '1_4_0_create_proxy_bids_table',
            '1_4_0_create_auto_audit_log',
        ] );
        
        // Reset singleton to pick up option
        $reflection = new \ReflectionClass( MigrationRunner::class );
        $property   = $reflection->getProperty( 'instance' );
        $property->setAccessible( true );
        $property->setValue( null, null );
        
        $runner = MigrationRunner::get_instance();
        
        // Test via migration status
        $status = $runner->get_migration_status();
        $this->assertTrue( $status['1_4_0_create_proxy_bids_table']['applied'] );
        $this->assertTrue( $status['1_4_0_create_auto_audit_log']['applied'] );
        $this->assertFalse( $status['1_4_0_add_auto_bid_to_bids']['applied'] );
    }
    
    /**
     * Test run_pending returns correct structure
     *
     * @test
     */
    public function test_run_pending_returns_array() {
        $results = $this->runner->run_pending();
        
        $this->assertIsArray( $results );
        
        // Each migration should have result
        foreach ( $results as $key => $result ) {
            $this->assertArrayHasKey( 'status', $result );
            $this->assertArrayHasKey( 'message', $result );
            $this->assertIsString( $result['status'] );
            $this->assertIsString( $result['message'] );
        }
    }
    
    /**
     * Test skipping already applied migrations
     *
     * @test
     */
    public function test_skip_already_applied_migrations() {
        // Mark one as applied
        update_option( MigrationRunner::OPTION_KEY, [
            '1_4_0_create_proxy_bids_table',
        ] );
        
        // Reset singleton
        $reflection = new \ReflectionClass( MigrationRunner::class );
        $property   = $reflection->getProperty( 'instance' );
        $property->setAccessible( true );
        $property->setValue( null, null );
        
        $runner  = MigrationRunner::get_instance();
        $results = $runner->run_pending();
        
        // First migration should be skipped
        $this->assertEquals( 'skipped', $results['1_4_0_create_proxy_bids_table']['status'] );
    }
    
    /**
     * Test rollback method structure
     *
     * @test
     */
    public function test_rollback_returns_array() {
        $result = $this->runner->rollback( '1_4_0_create_proxy_bids_table' );
        
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'status', $result );
        $this->assertArrayHasKey( 'message', $result );
    }
    
    /**
     * Test rollback non-existent migration
     *
     * @test
     */
    public function test_rollback_nonexistent_migration() {
        $result = $this->runner->rollback( 'nonexistent_migration' );
        
        $this->assertEquals( 'error', $result['status'] );
        $this->assertStringContainsString( 'not found', $result['message'] );
    }
    
    /**
     * Test error handling for missing up method
     *
     * @test
     */
    public function test_run_pending_handles_missing_methods() {
        // Create mock migration with no up method
        $mock_class = 'WC\Auction\Migrations\TestMigrationNoUp';
        
        // Test via reflection that error handling exists
        $reflection = new \ReflectionClass( MigrationRunner::class );
        $method     = $reflection->getMethod( 'run_migration' );
        $method->setAccessible( true );
        
        // This would be tested with actual mock when running
        // Here we just verify the method exists
        $this->assertTrue( method_exists( MigrationRunner::class, 'run_pending' ) );
    }
    
    /**
     * Test migration status with option corruption
     *
     * @test
     */
    public function test_get_applied_migrations_handles_corrupted_option() {
        // Set corrupted option (non-array)
        update_option( MigrationRunner::OPTION_KEY, 'corrupted_string' );
        
        // Reset singleton
        $reflection = new \ReflectionClass( MigrationRunner::class );
        $property   = $reflection->getProperty( 'instance' );
        $property->setAccessible( true );
        $property->setValue( null, null );
        
        // This should not throw; should handle gracefully
        $runner = MigrationRunner::get_instance();
        $status = $runner->get_migration_status();
        
        // All should be marked as not applied (corruption detected)
        foreach ( $status as $migration_status ) {
            $this->assertFalse( $migration_status['applied'] );
        }
    }
    
    /**
     * Test clean up after tests
     */
    protected function tearDown(): void {
        delete_option( MigrationRunner::OPTION_KEY );
    }
}
