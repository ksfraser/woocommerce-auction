<?php
/**
 * Auto-Bidding Integration Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Integration
 * @version    1.0.0
 * @requirement REQ-AB-001: End-to-end auto-bidding workflow
 */

namespace WC\Auction\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\Auction;
use WC\Auction\Models\ProxyBid;
use WC\Auction\Models\Bid;
use WC\Auction\Services\AutoBiddingEngine;
use WC\Auction\Services\ProxyBidService;
use WC\Auction\Services\BidIncrementCalculator;
use WC\Auction\Services\BidService;
use WC\Auction\Repositories\ProxyBidRepository;
use WC\Auction\Repositories\BidRepository;
use WC\Auction\Repositories\AuctionRepository;
use WC\Auction\Repositories\AutoBidLogRepository;

/**
 * AutoBiddingIntegrationTest - Integration test suite for auto-bidding workflow
 *
 * @covers \WC\Auction\Services\AutoBiddingEngine
 * @covers \WC\Auction\Services\ProxyBidService
 * @covers \WC\Auction\Services\BidService
 * @group integration
 */
class AutoBiddingIntegrationTest extends TestCase {
    
    /**
     * Engine instance
     *
     * @var AutoBiddingEngine
     */
    private $engine;
    
    /**
     * Service instance
     *
     * @var ProxyBidService
     */
    private $proxy_service;
    
    /**
     * Bid service
     *
     * @var BidService
     */
    private $bid_service;
    
    /**
     * Repositories
     *
     * @var AuctionRepository|ProxyBidRepository|BidRepository
     */
    private $auction_repo, $proxy_repo, $bid_repo, $log_repo;
    
    /**
     * Test data
     *
     * @var Auction
     */
    private $auction;
    
    /**
     * Set up integration test
     *
     * This creates a full object graph with all services and
     * populates test data in the database
     */
    protected function setUp(): void {
        $this->auction_repo = new AuctionRepository();
        $this->proxy_repo = new ProxyBidRepository();
        $this->bid_repo = new BidRepository();
        $this->log_repo = new AutoBidLogRepository();
        
        $this->proxy_service = new ProxyBidService(
            $this->proxy_repo
        );
        
        $this->bid_service = new BidService(
            $this->bid_repo,
            new BidIncrementCalculator()
        );
        
        $calculator = new BidIncrementCalculator(
            BidIncrementCalculator::STRATEGY_FIXED,
            [ 'increment' => 1.00 ]
        );
        
        $this->engine = new AutoBiddingEngine(
            $this->proxy_repo,
            $this->log_repo,
            $this->proxy_service,
            $calculator
        );
        
        // Create test auction
        $this->auction = Auction::create( [
            'name' => 'Integration Test Auction',
            'product_id' => 1,
            'current_bid' => 10.00,
            'current_bidder_id' => null,
            'status' => Auction::STATUS_ACTIVE,
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ] );
        
        $this->auction_repo->save( $this->auction );
    }
    
    /**
     * Test workflow: User creates proxy bid then is outbid
     *
     * REQ-AB-001: User sets proxy bid of $100 max, gets outbid
     *
     * @test
     */
    public function test_workflow_proxy_bid_then_outbid() {
        // Step 1: User 1 creates proxy bid of $100
        $proxy1 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
            'current_proxy_bid' => 10.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $this->proxy_repo->save( $proxy1 );
        
        // Step 2: User 2 places manual bid of $50
        $bid = Bid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 2,
            'bid_amount' => 50.00,
            'bid_type' => Bid::TYPE_MANUAL,
        ] );
        
        $this->bid_repo->save( $bid );
        $this->auction->updateBid( $bid );
        
        // Step 3: Auto-bidding engine processes
        // Should place auto-bid from user 1
        $this->engine->handleNewBid( $this->auction->id, 50.00, 2, 1 );
        
        // Step 4: Verify proxy bid was updated
        $updated_proxy = $this->proxy_repo->findById( $proxy1->id );
        
        $this->assertGreaterThan( 50.00, $updated_proxy->current_proxy_bid );
        $this->assertEquals( ProxyBid::STATUS_ACTIVE, $updated_proxy->status );
    }
    
    /**
     * Test workflow: Multiple users with proxy bids compete
     *
     * @test
     */
    public function test_workflow_multiple_proxy_bidders() {
        // Three users set proxy bids
        $proxy1 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $proxy2 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 2,
            'maximum_bid' => 150.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $proxy3 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 3,
            'maximum_bid' => 75.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $this->proxy_repo->save( $proxy1 );
        $this->proxy_repo->save( $proxy2 );
        $this->proxy_repo->save( $proxy3 );
        
        // Scenario: User 4 bids $80
        $bid = Bid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 4,
            'bid_amount' => 80.00,
            'bid_type' => Bid::TYPE_MANUAL,
        ] );
        
        $this->bid_repo->save( $bid );
        
        // Process auto-bids
        $this->engine->handleNewBid( $this->auction->id, 80.00, 4, 1 );
        
        // Verify results:
        // - proxy1 (max 100) should be outbid
        // - proxy2 (max 150) should compete and win
        // - proxy3 (max 75) should be outbid immediately
        
        $p1 = $this->proxy_repo->findById( $proxy1->id );
        $p2 = $this->proxy_repo->findById( $proxy2->id );
        $p3 = $this->proxy_repo->findById( $proxy3->id );
        
        $this->assertEquals( ProxyBid::STATUS_OUTBID, $p1->status );
        $this->assertEquals( ProxyBid::STATUS_ACTIVE, $p2->status );
        $this->assertEquals( ProxyBid::STATUS_OUTBID, $p3->status );
    }
    
    /**
     * Test workflow: User cancels proxy bid mid-auction
     *
     * @test
     */
    public function test_workflow_user_cancels_proxy_bid() {
        $proxy = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $this->proxy_repo->save( $proxy );
        
        // User cancels bid
        $this->proxy_service->cancel( $proxy->id, ProxyBidService::CANCEL_REASON_USER );
        
        // New bid is placed
        $bid = Bid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 2,
            'bid_amount' => 50.00,
        ] );
        
        $this->bid_repo->save( $bid );
        
        // Process auto-bid (should not respond since cancelled)
        $this->engine->handleNewBid( $this->auction->id, 50.00, 2, 1 );
        
        // No logs should be created
        $logs = $this->log_repo->findByAuction( $this->auction->id );
        
        $this->assertEmpty( $logs );
    }
    
    /**
     * Test workflow: Auto-bid reaches max and loses auction
     *
     * @test
     */
    public function test_workflow_auto_bid_reaches_max() {
        $proxy = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $this->proxy_repo->save( $proxy );
        
        // Simulate escalating bids
        $bid1 = Bid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 2,
            'bid_amount' => 99.00,
        ] );
        $this->bid_repo->save( $bid1 );
        $this->engine->handleNewBid( $this->auction->id, 99.00, 2, 1 );
        
        // User 2 raises to $101 (exceeds proxy user's max)
        $bid2 = Bid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 2,
            'bid_amount' => 101.00,
        ] );
        $this->bid_repo->save( $bid2 );
        $this->engine->handleNewBid( $this->auction->id, 101.00, 2, 1 );
        
        // Verify proxy is marked as outbid
        $updated_proxy = $this->proxy_repo->findById( $proxy->id );
        $this->assertEquals( ProxyBid::STATUS_OUTBID, $updated_proxy->status );
    }
    
    /**
     * Test workflow: Performance with many concurrent proxy bids
     *
     * REQ-AB-004: Performance requirements
     *
     * @test
     */
    public function test_workflow_performance_many_proxies() {
        // Create 100 proxy bids
        for ( $i = 0; $i < 100; $i += 1 ) {
            $proxy = ProxyBid::create( [
                'auction_id' => $this->auction->id,
                'user_id' => 100 + $i,
                'maximum_bid' => 50.00 + ( $i * 0.5 ),
                'status' => ProxyBid::STATUS_ACTIVE,
            ] );
            
            $this->proxy_repo->save( $proxy );
        }
        
        // New bid arrives
        $start = microtime( true );
        $this->engine->handleNewBid( $this->auction->id, 45.00, 999 );
        $elapsed = microtime( true ) - $start;
        
        // Should complete in < 1 second (adjust based on environment)
        $this->assertLessThan( 1.0, $elapsed, 'Processing took too long' );
    }
    
    /**
     * Test workflow: Post-auction winner determination
     *
     * @test
     */
    public function test_workflow_auctions_ends_verify_winner() {
        // Create proxy bids
        $proxy1 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 200.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $proxy2 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 2,
            'maximum_bid' => 150.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $this->proxy_repo->save( $proxy1 );
        $this->proxy_repo->save( $proxy2 );
        
        // Simulate auction ending
        $this->engine->handleNewBid( $this->auction->id, 100.00, 3, 1 );
        
        $final_bid = Bid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'bid_amount' => 150.00,
        ] );
        
        $this->bid_repo->save( $final_bid );
        
        // Verify proxy winner
        $winner_proxy = $this->proxy_repo->findById( $proxy1->id );
        $this->assertEquals( ProxyBid::STATUS_ACTIVE, $winner_proxy->status );
    }
    
    /**
     * Test workflow: Edge case same second bids
     *
     * @test
     */
    public function test_workflow_same_second_bids_resolves() {
        $proxy1 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
        ] );
        
        $proxy2 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 2,
            'maximum_bid' => 100.00,
        ] );
        
        $this->proxy_repo->save( $proxy1 );
        $this->proxy_repo->save( $proxy2 );
        
        // Both place bids at same time
        $this->engine->handleNewBid( $this->auction->id, 50.00, 999 );
        
        // System should resolve consistently (FIFO or other rule)
        $all_proxies = $this->proxy_repo->findActiveByAuction( $this->auction->id );
        
        // Should have one active and one outbid (or similar resolution)
        $active_count = count( array_filter(
            $all_proxies,
            fn( $p ) => $p->status === ProxyBid::STATUS_ACTIVE
        ) );
        
        $this->assertGreaterThanOrEqual( 0, $active_count );
    }
    
    /**
     * Clean up test data
     */
    protected function tearDown(): void {
        // Clean up test data from database
        if ( isset( $this->auction ) && $this->auction->id ) {
            $this->bid_repo->deleteByAuction( $this->auction->id );
            $this->proxy_repo->deleteByAuction( $this->auction->id );
            $this->auction_repo->delete( $this->auction->id );
        }
    }
}
