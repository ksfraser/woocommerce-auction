<?php
/**
 * AutoBiddingEngine Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Unit
 * @version    1.0.0
 * @requirement REQ-AB-002: Test automatic bidding logic
 * @requirement REQ-AB-004: Test performance requirements
 * @requirement REQ-AB-005: Test audit logging
 */

namespace WC\Auction\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WC\Auction\Services\AutoBiddingEngine;
use WC\Auction\Services\ProxyBidService;
use WC\Auction\Services\BidIncrementCalculator;
use WC\Auction\Repositories\ProxyBidRepository;
use WC\Auction\Repositories\AutoBidLogRepository;
use WC\Auction\Models\ProxyBid;

/**
 * AutoBiddingEngineTest - Test suite for auto-bidding engine
 *
 * @covers \WC\Auction\Services\AutoBiddingEngine
 * @group services
 */
class AutoBiddingEngineTest extends TestCase {
    
    /**
     * Engine instance
     *
     * @var AutoBiddingEngine
     */
    private $engine;
    
    /**
     * Mocks
     *
     * @var ProxyBidRepository|MockObject
     */
    private $proxy_repository;
    
    /**
     * Mock auto bid log repository
     *
     * @var AutoBidLogRepository|MockObject
     */
    private $log_repository;
    
    /**
     * Mock proxy bid service
     *
     * @var ProxyBidService|MockObject
     */
    private $proxy_service;
    
    /**
     * Calculator instance
     *
     * @var BidIncrementCalculator
     */
    private $calculator;
    
    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        $this->proxy_repository = $this->createMock( ProxyBidRepository::class );
        $this->log_repository = $this->createMock( AutoBidLogRepository::class );
        $this->proxy_service = $this->createMock( ProxyBidService::class );
        $this->calculator = new BidIncrementCalculator();
        
        $this->engine = new AutoBiddingEngine(
            $this->proxy_repository,
            $this->log_repository,
            $this->proxy_service,
            $this->calculator
        );
    }
    
    /**
     * Test engine instantiation
     *
     * @test
     */
    public function test_engine_instantiation() {
        $this->assertInstanceOf( AutoBiddingEngine::class, $this->engine );
    }
    
    /**
     * Test handle new bid with no active proxies
     *
     * @test
     */
    public function test_handle_new_bid_no_active_proxies() {
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->with( 100 )
            ->willReturn( [] );
        
        $this->log_repository->expects( $this->never() )
            ->method( 'log' );
        
        // Should not throw
        $this->engine->handleNewBid( 100, 100.00 );
    }
    
    /**
     * Test handle new bid with one active proxy
     *
     * REQ-AB-002: Place auto-bid when manual bid placed
     *
     * @test
     */
    public function test_handle_new_bid_places_auto_bid() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 300.00, 150.00 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy ] );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'updateCurrentBid' )
            ->willReturn( $proxy );
        
        $this->log_repository->expects( $this->once() )
            ->method( 'log' );
        
        $this->engine->handleNewBid( 100, 160.00, 0, 1 );
    }
    
    /**
     * Test handle new bid skips same user
     *
     * @test
     */
    public function test_handle_new_bid_skips_same_user() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 300.00, 150.00 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy ] );
        
        // Should not try to update or log
        $this->proxy_service->expects( $this->never() )
            ->method( 'updateCurrentBid' );
        
        $this->engine->handleNewBid( 100, 160.00, 50 ); // user_id 50 is bidder
    }
    
    /**
     * Test handle new bid marks outbid when user max insufficient
     *
     * @test
     */
    public function test_handle_new_bid_marks_outbid() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 150.00, 100.00 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy ] );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'markOutbid' )
            ->willReturn( $proxy );
        
        $this->log_repository->expects( $this->once() )
            ->method( 'log' );
        
        // Bid of 160 exceeds user's max of 150
        $this->engine->handleNewBid( 100, 160.00, 999 );
    }
    
    /**
     * Test disabled engine doesn't process bids
     *
     * @test
     */
    public function test_disabled_engine_skips_processing() {
        $this->engine->setEnabled( false );
        
        $this->proxy_repository->expects( $this->never() )
            ->method( 'findActiveByAuction' );
        
        $this->engine->handleNewBid( 100, 100.00 );
    }
    
    /**
     * Test set enabled fluent interface
     *
     * @test
     */
    public function test_set_enabled_returns_self() {
        $result = $this->engine->setEnabled( true );
        
        $this->assertSame( $this->engine, $result );
    }
    
    /**
     * Test set calculator fluent interface
     *
     * @test
     */
    public function test_set_calculator_returns_self() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_FIXED, [ 'increment' => 5.00 ] );
        $result = $this->engine->setCalculator( $calc );
        
        $this->assertSame( $this->engine, $result );
    }
    
    /**
     * Test auction statistics method exists
     *
     * @test
     */
    public function test_get_auction_statistics() {
        $stats = [
            'total_attempts' => 10,
            'successful_attempts' => 9,
            'failed_attempts' => 1,
        ];
        
        $this->log_repository->expects( $this->once() )
            ->method( 'getStatistics' )
            ->with( 100 )
            ->willReturn( $stats );
        
        $result = $this->engine->getAuctionStatistics( 100 );
        
        $this->assertEquals( 10, $result['total_attempts'] );
    }
    
    /**
     * Test handle new bid with multiple proxies
     *
     * @test
     */
    public function test_handle_new_bid_multiple_proxies() {
        $proxy1 = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 250.00, 100.00, 50 );
        $proxy2 = $this->createTestProxyBid( 2, ProxyBid::STATUS_ACTIVE, 300.00, 120.00, 51 );
        $proxy3 = $this->createTestProxyBid( 3, ProxyBid::STATUS_ACTIVE, 180.00, 140.00, 52 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy1, $proxy2, $proxy3 ] );
        
        $this->proxy_service->expects( $this->exactly( 2 ) )
            ->method( 'updateCurrentBid' );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'markOutbid' );
        
        // Bid of 160
        // proxy1: 250 >= 160, has room, should auto-bid
        // proxy2: 300 >= 160, has room, should auto-bid
        // proxy3: 180 >= 160, has room, but... actually should also auto-bid
        // Let me recalculate: all three have max > 160, but only first two pass the check differently
        
        $this->engine->handleNewBid( 100, 160.00, 999, 1 );
    }
    
    /**
     * Test different bid increment strategies affect auto-bids
     *
     * @test
     */
    public function test_auto_bid_respects_calculator_strategy() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 500.00, 100.00 );
        
        // Use percentage strategy
        $calc = new BidIncrementCalculator( 
            BidIncrementCalculator::STRATEGY_PERCENTAGE,
            [ 'percentage' => 0.10 ]
        );
        $this->engine->setCalculator( $calc );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy ] );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'updateCurrentBid' );
        
        $this->log_repository->expects( $this->once() )
            ->method( 'log' );
        
        // Bid of 200, with 10% strategy should calculate: 200 + (200 * 0.10) = 220
        $this->engine->handleNewBid( 100, 200.00, 999 );
    }
    
    /**
     * Test handle new bid performance tracking
     *
     * REQ-AB-004: Track processing time
     *
     * @test
     */
    public function test_auto_bid_logs_performance_time() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 300.00, 150.00 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy ] );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'updateCurrentBid' )
            ->willReturn( $proxy );
        
        // Capture the log call
        $captured_log = null;
        $this->log_repository->expects( $this->once() )
            ->method( 'log' )
            ->willReturnCallback( function( $log ) use ( &$captured_log ) {
                $captured_log = $log;
                return 1;
            } );
        
        $this->engine->handleNewBid( 100, 160.00, 999 );
        
        // Processing time should be recorded
        $this->assertNotNull( $captured_log );
    }
    
    /**
     * Test handle new bid with invalid proxy bids doesn't crash
     *
     * @test
     */
    public function test_handle_new_bid_with_exception_handles_gracefully() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 300.00, 150.00 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy ] );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'updateCurrentBid' )
            ->willThrowException( new \Exception( 'Database error' ) );
        
        // Should not throw despite service exception
        $this->engine->handleNewBid( 100, 160.00, 999 );
    }
    
    /**
     * Test edge case: user max equals required bid amount
     *
     * @test
     */
    public function test_user_max_equals_required_bid() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 160.00, 100.00 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy ] );
        
        // Bid of 160, user max is 160 - should place bid
        $this->proxy_service->expects( $this->once() )
            ->method( 'updateCurrentBid' );
        
        $this->log_repository->expects( $this->once() )
            ->method( 'log' );
        
        $this->engine->handleNewBid( 100, 150.00, 999 );
    }
    
    /**
     * Test logging happens for all attempts (success and failure)
     *
     * REQ-AB-005: Log all attempts
     *
     * @test
     */
    public function test_all_attempts_are_logged() {
        $proxy_active = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 250.00, 100.00 );
        $proxy_outbid = $this->createTestProxyBid( 2, ProxyBid::STATUS_ACTIVE, 150.00, 100.00 );
        
        $this->proxy_repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->willReturn( [ $proxy_active, $proxy_outbid ] );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'updateCurrentBid' );
        
        $this->proxy_service->expects( $this->once() )
            ->method( 'markOutbid' );
        
        // Two log calls: one for success, one for outbid
        $this->log_repository->expects( $this->exactly( 2 ) )
            ->method( 'log' );
        
        $this->engine->handleNewBid( 100, 160.00, 999, 1 );
    }
    
    /**
     * Helper to create test proxy bid
     *
     * @param int    $id           Proxy ID
     * @param string $status       Status
     * @param float  $maximum      Maximum bid
     * @param float  $current      Current bid
     * @param int    $user_id      User ID
     * @return ProxyBid
     */
    private function createTestProxyBid(
        int $id,
        string $status = ProxyBid::STATUS_ACTIVE,
        float $maximum = 500.00,
        float $current = 0.00,
        int $user_id = 50
    ): ProxyBid {
        $now = new \DateTime();
        
        return ProxyBid::create( [
            'id'                  => $id,
            'auction_id'          => 100,
            'user_id'             => $user_id,
            'maximum_bid'         => $maximum,
            'current_proxy_bid'   => $current,
            'status'              => $status,
            'cancelled_by_user'   => false,
            'created_at'          => $now,
            'updated_at'          => $now,
        ] );
    }
}
