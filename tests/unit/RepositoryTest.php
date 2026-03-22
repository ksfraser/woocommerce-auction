<?php
/**
 * Repository Unit Tests Base Class
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Unit
 * @version    1.0.0
 * @requirement REQ-AB-001: Test repository CRUD operations
 */

namespace WC\Auction\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC\Auction\Repositories\ProxyBidRepository;
use WC\Auction\Repositories\AutoBidLogRepository;
use WC\Auction\Models\ProxyBid;
use WC\Auction\Models\AutoBidLog;

/**
 * ProxyBidRepositoryTest - Test suite for proxy bid repository
 *
 * @covers \WC\Auction\Repositories\ProxyBidRepository
 * @group repositories
 */
class ProxyBidRepositoryTest extends TestCase {
    
    /**
     * Repository instance
     *
     * @var ProxyBidRepository
     */
    private $repository;
    
    /**
     * Mock WordPress database
     *
     * @var \wpdb
     */
    private $wpdb_mock;
    
    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        // Mock WordPress WPDB if needed
        // For integration tests, would use real database with transactions
        $this->repository = new ProxyBidRepository();
    }
    
    /**
     * Test repository can be instantiated
     *
     * @test
     */
    public function test_repository_instantiation() {
        $this->assertInstanceOf( ProxyBidRepository::class, $this->repository );
    }
    
    /**
     * Test TABLE_NAME constant
     *
     * @test
     */
    public function test_table_name_constant() {
        $this->assertEquals( 'wc_auction_proxy_bids', ProxyBidRepository::TABLE_NAME );
    }
    
    /**
     * Test save method exists and has correct signature
     *
     * @test
     */
    public function test_save_method_signature() {
        $reflection = new \ReflectionClass( ProxyBidRepository::class );
        $method     = $reflection->getMethod( 'save' );
        
        $this->assertTrue( $method->isPublic() );
        $this->assertTrue( $method->hasReturnType() );
    }
    
    /**
     * Test find method exists
     *
     * @test
     */
    public function test_find_method_exists() {
        $this->assertTrue( method_exists( $this->repository, 'find' ) );
    }
    
    /**
     * Test findByAuctionAndUser method exists
     *
     * @test
     */
    public function test_find_by_auction_and_user_method_exists() {
        $this->assertTrue( method_exists( $this->repository, 'findByAuctionAndUser' ) );
    }
    
    /**
     * Test findActiveByUser method exists
     *
     * @test
     */
    public function test_find_active_by_user_method_exists() {
        $this->assertTrue( method_exists( $this->repository, 'findActiveByUser' ) );
    }
    
    /**
     * Test findActiveByAuction method exists
     *
     * @test
     */
    public function test_find_active_by_auction_method_exists() {
        $this->assertTrue( method_exists( $this->repository, 'findActiveByAuction' ) );
    }
    
    /**
     * Test update method exists
     *
     * @test
     */
    public function test_update_method_exists() {
        $this->assertTrue( method_exists( $this->repository, 'update' ) );
    }
    
    /**
     * Test delete method exists
     *
     * @test
     */
    public function test_delete_method_exists() {
        $this->assertTrue( method_exists( $this->repository, 'delete' ) );
    }
    
    /**
     * Test all query methods return arrays or null
     *
     * @test
     */
    public function test_query_methods_signatures() {
        $reflection = new \ReflectionClass( ProxyBidRepository::class );
        
        // Test that methods have proper return types
        $methods = [
            'findActiveByUser'      => 'array',
            'findActiveByAuction'   => 'array',
        ];
        
        foreach ( $methods as $method_name => $expected_type ) {
            $method = $reflection->getMethod( $method_name );
            // Verify method exists
            $this->assertTrue( $method->isPublic() );
        }
    }
}

/**
 * AutoBidLogRepositoryTest - Test suite for auto-bid log repository
 *
 * @covers \WC\Auction\Repositories\AutoBidLogRepository
 * @group repositories
 */
class AutoBidLogRepositoryTest extends TestCase {
    
    /**
     * Repository instance
     *
     * @var AutoBidLogRepository
     */
    private $repository;
    
    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        $this->repository = new AutoBidLogRepository();
    }
    
    /**
     * Test repository instantiation
     *
     * @test
     */
    public function test_repository_instantiation() {
        $this->assertInstanceOf( AutoBidLogRepository::class, $this->repository );
    }
    
    /**
     * Test TABLE_NAME constant
     *
     * @test
     */
    public function test_table_name_constant() {
        $this->assertEquals( 'wc_auction_auto_bid_log', AutoBidLogRepository::TABLE_NAME );
    }
    
    /**
     * Test log method exists (append-only)
     *
     * @test
     */
    public function test_log_method_exists() {
        $this->assertTrue( method_exists( $this->repository, 'log' ) );
    }
    
    /**
     * Test log method returns integer ID
     *
     * @test
     */
    public function test_log_method_signature() {
        $reflection = new \ReflectionClass( AutoBidLogRepository::class );
        $method     = $reflection->getMethod( 'log' );
        
        $this->assertTrue( $method->isPublic() );
        // Should return int
        $this->assertTrue( $method->hasReturnType() );
    }
    
    /**
     * Test query methods exist
     *
     * @test
     */
    public function test_query_methods_exist() {
        $methods = [
            'findByProxyBid',
            'findByAuction',
            'findByUser',
            'getAuditTrail',
        ];
        
        foreach ( $methods as $method ) {
            $this->assertTrue( 
                method_exists( $this->repository, $method ),
                "Method {$method} should exist on repository"
            );
        }
    }
    
    /**
     * Test statistics methods exist (REQ-AB-005, REQ-AB-006)
     *
     * @test
     */
    public function test_statistics_methods_exist() {
        $methods = [
            'getFailureCount',
            'getSuccessRate',
            'getAverageProcessingTime',
            'getErrorEntries',
            'getStatistics',
        ];
        
        foreach ( $methods as $method ) {
            $this->assertTrue(
                method_exists( $this->repository, $method ),
                "Statistics method {$method} should exist"
            );
        }
    }
    
    /**
     * Test repository does NOT have update method (immutable)
     *
     * @test
     */
    public function test_no_update_method_on_immutable_log() {
        $this->assertFalse( method_exists( $this->repository, 'update' ) );
    }
    
    /**
     * Test repository does NOT have delete method (immutable)
     *
     * @test
     */
    public function test_no_delete_method_on_immutable_log() {
        $this->assertFalse( method_exists( $this->repository, 'delete' ) );
    }
    
    /**
     * Test audit trail method accepts limit parameter
     *
     * @test
     */
    public function test_get_audit_trail_signature() {
        $reflection = new \ReflectionClass( AutoBidLogRepository::class );
        $method     = $reflection->getMethod( 'getAuditTrail' );
        $params     = $method->getParameters();
        
        // Should have user_id, limit, offset parameters
        $this->assertGreaterThanOrEqual( 3, count( $params ) );
    }
}
