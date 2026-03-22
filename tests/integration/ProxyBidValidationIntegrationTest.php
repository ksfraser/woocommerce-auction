<?php
/**
 * Proxy Bid Validation Integration Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Integration
 * @version    1.0.0
 * @requirement REQ-PROXY-VALIDATION: Integration test for proxy bid validation
 */

namespace WC\Auction\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\ProxyBid;
use WC\Auction\Models\Auction;
use WC\Auction\Services\ProxyBidService;
use WC\Auction\Services\ProxyBidValidator;
use WC\Auction\Repositories\ProxyBidRepository;
use WC\Auction\Repositories\AuctionRepository;
use WC\Auction\Exceptions\ProxyBidValidationException;

/**
 * ProxyBidValidationIntegrationTest - Integration test suite for proxy bid validation
 *
 * @covers \WC\Auction\Services\ProxyBidValidator
 * @covers \WC\Auction\Services\ProxyBidService
 * @group integration
 */
class ProxyBidValidationIntegrationTest extends TestCase {
    
    /**
     * Service instances
     *
     * @var ProxyBidValidator|ProxyBidService
     */
    private $validator, $service;
    
    /**
     * Repository instances
     *
     * @var ProxyBidRepository|AuctionRepository
     */
    private $proxy_repo, $auction_repo;
    
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
        $this->proxy_repo = new ProxyBidRepository();
        $this->auction_repo = new AuctionRepository();
        
        $this->validator = new ProxyBidValidator(
            $this->proxy_repo,
            $this->auction_repo
        );
        
        $this->service = new ProxyBidService(
            $this->proxy_repo,
            $this->validator
        );
        
        // Create test auction
        $this->auction = Auction::create( [
            'name' => 'Test Auction',
            'product_id' => 1,
            'current_bid' => 10.00,
            'status' => Auction::STATUS_ACTIVE,
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ] );
        
        $this->auction_repo->save( $this->auction );
    }
    
    /**
     * Test valid proxy bid creation
     *
     * @test
     */
    public function test_valid_proxy_bid_creation() {
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 500.00,
        ];
        
        $result = $this->service->create( $proxy_data );
        
        $this->assertInstanceOf( ProxyBid::class, $result );
        $this->assertEquals( 500.00, $result->maximum_bid );
    }
    
    /**
     * Test invalid proxy bid: max bid below current
     *
     * @test
     */
    public function test_invalid_proxy_bid_below_current() {
        $this->expectException( ProxyBidValidationException::class );
        
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 5.00, // Below $10 current
        ];
        
        $this->service->create( $proxy_data );
    }
    
    /**
     * Test invalid proxy bid: user already has active
     *
     * @test
     */
    public function test_invalid_proxy_bid_user_has_active() {
        // Create first proxy bid
        $proxy1 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $this->proxy_repo->save( $proxy1 );
        
        // Try to create second for same user
        $this->expectException( ProxyBidValidationException::class );
        $this->expectExceptionMessage( 'already has an active' );
        
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 200.00,
        ];
        
        $this->service->create( $proxy_data );
    }
    
    /**
     * Test valid proxy bid: user can create after cancelling
     *
     * @test
     */
    public function test_user_can_recreate_after_cancel() {
        // Create and cancel first proxy
        $proxy1 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
            'status' => ProxyBid::STATUS_ACTIVE,
        ] );
        
        $this->proxy_repo->save( $proxy1 );
        $this->service->cancel( $proxy1->id );
        
        // Should be able to create new one
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 200.00,
        ];
        
        $result = $this->service->create( $proxy_data );
        
        $this->assertInstanceOf( ProxyBid::class, $result );
    }
    
    /**
     * Test invalid proxy bid: negative maximum
     *
     * @test
     */
    public function test_invalid_proxy_bid_negative_bid() {
        $this->expectException( ProxyBidValidationException::class );
        
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => -100.00,
        ];
        
        $this->service->create( $proxy_data );
    }
    
    /**
     * Test invalid proxy bid: zero maximum
     *
     * @test
     */
    public function test_invalid_proxy_bid_zero_bid() {
        $this->expectException( ProxyBidValidationException::class );
        
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 0.00,
        ];
        
        $this->service->create( $proxy_data );
    }
    
    /**
     * Test invalid proxy bid: auction not found
     *
     * @test
     */
    public function test_invalid_proxy_bid_auction_not_found() {
        $this->expectException( ProxyBidValidationException::class );
        
        $proxy_data = [
            'auction_id' => 99999,
            'user_id' => 1,
            'maximum_bid' => 100.00,
        ];
        
        $this->service->create( $proxy_data );
    }
    
    /**
     * Test invalid proxy bid: auction not active
     *
     * @test
     */
    public function test_invalid_proxy_bid_auction_ended() {
        // Create ended auction
        $ended_auction = Auction::create( [
            'name' => 'Ended Auction',
            'product_id' => 2,
            'current_bid' => 100.00,
            'status' => Auction::STATUS_ENDED,
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ] );
        
        $this->auction_repo->save( $ended_auction );
        
        $this->expectException( ProxyBidValidationException::class );
        
        $proxy_data = [
            'auction_id' => $ended_auction->id,
            'user_id' => 1,
            'maximum_bid' => 150.00,
        ];
        
        $this->service->create( $proxy_data );
    }
    
    /**
     * Test valid proxy bid: very high maximum allowed
     *
     * @test
     */
    public function test_valid_proxy_bid_very_high_maximum() {
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 999999.99,
        ];
        
        $result = $this->service->create( $proxy_data );
        
        $this->assertEquals( 999999.99, $result->maximum_bid );
    }
    
    /**
     * Test valid proxy bid: fractional penny amounts
     *
     * @test
     */
    public function test_valid_proxy_bid_fractional_amounts() {
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.55,
        ];
        
        $result = $this->service->create( $proxy_data );
        
        $this->assertEquals( 100.55, $result->maximum_bid );
    }
    
    /**
     * Test validation works with existing proxies
     *
     * @test
     */
    public function test_validation_considers_existing_proxies() {
        // Create first proxy
        $proxy1 = ProxyBid::create( [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 100.00,
            'status' => ProxyBid::STATUS_OUTBID,
        ] );
        
        $this->proxy_repo->save( $proxy1 );
        
        // Can still create new proxy because first is outbid
        $proxy_data = [
            'auction_id' => $this->auction->id,
            'user_id' => 1,
            'maximum_bid' => 200.00,
        ];
        
        $result = $this->service->create( $proxy_data );
        
        $this->assertInstanceOf( ProxyBid::class, $result );
    }
    
    /**
     * Clean up test data
     */
    protected function tearDown(): void {
        if ( isset( $this->auction ) && $this->auction->id ) {
            $this->proxy_repo->deleteByAuction( $this->auction->id );
            $this->auction_repo->delete( $this->auction->id );
        }
    }
}
