<?php
/**
 * Bid Service Workflow Integration Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Integration
 * @version    1.0.0
 * @requirement REQ-BID-WORKFLOW: Bid placement and increment workflow
 */

namespace WC\Auction\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\Bid;
use WC\Auction\Models\Auction;
use WC\Auction\Services\BidService;
use WC\Auction\Services\BidIncrementCalculator;
use WC\Auction\Repositories\BidRepository;
use WC\Auction\Repositories\AuctionRepository;
use WC\Auction\Exceptions\InvalidBidException;

/**
 * BidServiceWorkflowIntegrationTest - Test suite for bid workflow
 *
 * @covers \WC\Auction\Services\BidService
 * @covers \WC\Auction\Services\BidIncrementCalculator
 * @group integration
 */
class BidServiceWorkflowIntegrationTest extends TestCase {
    
    /**
     * Services and repositories
     *
     * @var BidService|BidRepository|AuctionRepository
     */
    private $bid_service, $bid_repo, $auction_repo;
    
    /**
     * Test auction
     *
     * @var Auction
     */
    private $auction;
    
    /**
     * Set up integration test
     */
    protected function setUp(): void {
        $this->bid_repo = new BidRepository();
        $this->auction_repo = new AuctionRepository();
        
        $calculator = new BidIncrementCalculator(
            BidIncrementCalculator::STRATEGY_FIXED,
            [ 'increment' => 1.00 ]
        );
        
        $this->bid_service = new BidService(
            $this->bid_repo,
            $calculator
        );
        
        // Create test auction
        $this->auction = Auction::create( [
            'name' => 'Bid Workflow Test',
            'product_id' => 1,
            'current_bid' => 10.00,
            'status' => Auction::STATUS_ACTIVE,
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ] );
        
        $this->auction_repo->save( $this->auction );
    }
    
    /**
     * Test workflow: Successive bids increase correctly
     *
     * @test
     */
    public function test_workflow_successive_bids() {
        // Bid 1: User 1 bids $50
        $bid1 = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
        
        $this->assertEquals( 50.00, $bid1->bid_amount );
        $this->auction->updateBid( $bid1 );
        
        // Bid 2: User 2 bids $75
        $bid2 = $this->bid_service->place(
            $this->auction->id,
            2,
            75.00,
            Bid::TYPE_MANUAL
        );
        
        $this->assertEquals( 75.00, $bid2->bid_amount );
        $this->assertEquals( 1, $bid2->bid_number );
        $this->auction->updateBid( $bid2 );
        
        // Bid 3: User 1 bids $100
        $bid3 = $this->bid_service->place(
            $this->auction->id,
            1,
            100.00,
            Bid::TYPE_MANUAL
        );
        
        $this->assertEquals( 100.00, $bid3->bid_amount );
        $this->assertEquals( 2, $bid3->bid_number );
        $this->auction->updateBid( $bid3 );
        
        // Verify auction state
        $this->assertEquals( 100.00, $this->auction->current_bid );
        $this->assertEquals( 1, $this->auction->current_bidder_id );
    }
    
    /**
     * Test workflow: Invalid bid rejected
     *
     * @test
     */
    public function test_workflow_invalid_bid_rejected() {
        // Place first bid
        $bid1 = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
        
        $this->auction->updateBid( $bid1 );
        
        // Try to place lower bid - should fail
        $this->expectException( InvalidBidException::class );
        
        $bid2 = $this->bid_service->place(
            $this->auction->id,
            2,
            40.00,
            Bid::TYPE_MANUAL
        );
    }
    
    /**
     * Test workflow: Auto-bids and manual bids compete
     *
     * @test
     */
    public function test_workflow_auto_and_manual_bids() {
        // Place manual bid
        $manual_bid = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
        
        $this->auction->updateBid( $manual_bid );
        
        // Place auto-bid from another user
        $auto_bid = $this->bid_service->place(
            $this->auction->id,
            2,
            60.00,
            Bid::TYPE_PROXY
        );
        
        $this->auction->updateBid( $auto_bid );
        
        // Verify auto-bid won
        $this->assertEquals( 60.00, $this->auction->current_bid );
        $this->assertEquals( 2, $this->auction->current_bidder_id );
        $this->assertEquals( Bid::TYPE_PROXY, $auto_bid->bid_type );
    }
    
    /**
     * Test workflow: Same user cannot bid twice with incremental bid
     *
     * @test
     */
    public function test_workflow_same_user_can_rebid() {
        // User 1 places bid
        $bid1 = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
        
        $this->auction->updateBid( $bid1 );
        
        // User 1 places higher bid (allowed)
        $bid2 = $this->bid_service->place(
            $this->auction->id,
            1,
            75.00,
            Bid::TYPE_MANUAL
        );
        
        $this->auction->updateBid( $bid2 );
        
        // Verify current state
        $this->assertEquals( 75.00, $this->auction->current_bid );
        $this->assertEquals( 1, $this->auction->current_bidder_id );
    }
    
    /**
     * Test workflow: Bid history maintained
     *
     * @test
     */
    public function test_workflow_bid_history_maintained() {
        // Place multiple bids
        for ( $i = 0; $i < 5; $i += 1 ) {
            $bid = $this->bid_service->place(
                $this->auction->id,
                ( $i % 2 ) + 1,
                (20 + ( $i * 10 )),
                Bid::TYPE_MANUAL
            );
            
            $this->auction->updateBid( $bid );
        }
        
        // Retrieve bid history
        $bids = $this->bid_repo->findByAuction( $this->auction->id );
        
        $this->assertCount( 5, $bids );
        
        // Verify bid numbers increment
        for ( $i = 0; $i < count( $bids ); $i += 1 ) {
            $this->assertEquals( $i, $bids[ $i ]->bid_number );
        }
    }
    
    /**
     * Test workflow: Bid retraction (if supported)
     *
     * @test
     */
    public function test_workflow_bid_retraction() {
        // Place bid
        $bid = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
        
        // Retract bid
        $result = $this->bid_service->retract( $bid->id );
        
        $this->assertTrue( $result );
        
        // Verify bid is marked as retracted
        $retracted_bid = $this->bid_repo->findById( $bid->id );
        
        $this->assertTrue( $retracted_bid->is_retracted );
    }
    
    /**
     * Test workflow: Bid expiry handling
     *
     * @test
     */
    public function test_workflow_bid_expiry() {
        // Create bid with 1 second expiry for testing
        $bid = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL,
            new \DateTime( 'now -1 second' )
        );
        
        // Check if expired
        $is_expired = $this->bid_service->isExpired( $bid );
        
        $this->assertTrue( $is_expired );
    }
    
    /**
     * Test workflow: Calculate next required bid
     *
     * @test
     */
    public function test_workflow_calculate_next_bid() {
        // Place initial bid
        $bid1 = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
        
        $this->auction->updateBid( $bid1 );
        
        // Get next required bid
        $next_bid = $this->bid_service->getNextRequiredBid( $this->auction->current_bid );
        
        // With fixed increment of $1, should be $51
        $this->assertEquals( 51.00, $next_bid );
    }
    
    /**
     * Test workflow: Get user's bids in auction
     *
     * @test
     */
    public function test_workflow_get_user_auction_bids() {
        // User 1 places multiple bids
        $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
        
        $this->bid_service->place(
            $this->auction->id,
            1,
            75.00,
            Bid::TYPE_MANUAL
        );
        
        // User 2 places bids
        $this->bid_service->place(
            $this->auction->id,
            2,
            100.00,
            Bid::TYPE_MANUAL
        );
        
        // Get user 1's bids
        $user_bids = $this->bid_repo->findByUserAndAuction(
            1,
            $this->auction->id
        );
        
        $this->assertCount( 2, $user_bids );
    }
    
    /**
     * Test workflow: Minimum bid enforcement
     *
     * @test
     */
    public function test_workflow_minimum_bid_enforcement() {
        // Set minimum bid
        $this->auction->minimum_bid = 100.00;
        $this->auction_repo->save( $this->auction );
        
        // Try to place bid below minimum
        $this->expectException( InvalidBidException::class );
        
        $bid = $this->bid_service->place(
            $this->auction->id,
            1,
            50.00,
            Bid::TYPE_MANUAL
        );
    }
    
    /**
     * Test workflow: Reserve price logic
     *
     * @test
     */
    public function test_workflow_reserve_price_logic() {
        // Set reserve price
        $this->auction->reserve_price = 200.00;
        $this->auction_repo->save( $this->auction );
        
        // Place bid below reserve (should be accepted but marked)
        $bid = $this->bid_service->place(
            $this->auction->id,
            1,
            150.00,
            Bid::TYPE_MANUAL
        );
        
        // Bid should be recorded
        $this->assertEquals( 150.00, $bid->bid_amount );
        
        // But auction might mark as reserve not met
        $this->assertFalse( $this->auction->reserve_met );
    }
    
    /**
     * Clean up test data
     */
    protected function tearDown(): void {
        if ( isset( $this->auction ) && $this->auction->id ) {
            $this->bid_repo->deleteByAuction( $this->auction->id );
            $this->auction_repo->delete( $this->auction->id );
        }
    }
}
