<?php
/**
 * ProxyBidService Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Unit
 * @version    1.0.0
 * @requirement REQ-AB-001: Test proxy bid lifecycle
 * @requirement REQ-AB-008: Test maximum bid enforcement
 */

namespace WC\Auction\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WC\Auction\Services\ProxyBidService;
use WC\Auction\Repositories\ProxyBidRepository;
use WC\Auction\Models\ProxyBid;

/**
 * ProxyBidServiceTest - Test suite for proxy bid service
 *
 * @covers \WC\Auction\Services\ProxyBidService
 * @group services
 */
class ProxyBidServiceTest extends TestCase {
    
    /**
     * Service instance
     *
     * @var ProxyBidService
     */
    private $service;
    
    /**
     * Mock repository
     *
     * @var ProxyBidRepository|MockObject
     */
    private $repository;
    
    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        $this->repository = $this->createMock( ProxyBidRepository::class );
        $this->service = new ProxyBidService( $this->repository );
    }
    
    /**
     * Test service instantiation
     *
     * @test
     */
    public function test_service_instantiation() {
        $this->assertInstanceOf( ProxyBidService::class, $this->service );
    }
    
    /**
     * Test create proxy bid success
     *
     * REQ-AB-001: Create new proxy bid
     *
     * @test
     */
    public function test_create_proxy_bid_success() {
        $this->repository->expects( $this->once() )
            ->method( 'findByAuctionAndUser' )
            ->with( 100, 50 )
            ->willReturn( null );
        
        $this->repository->expects( $this->once() )
            ->method( 'save' )
            ->willReturn( 1 );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->with( 1 )
            ->willReturn( $this->createTestProxyBid( 1 ) );
        
        $result = $this->service->createProxyBid( 100, 50, 500.00 );
        
        $this->assertInstanceOf( ProxyBid::class, $result );
        $this->assertEquals( 1, $result->getId() );
        $this->assertTrue( $result->isActive() );
    }
    
    /**
     * Test create proxy bid with zero maximum throws
     *
     * @test
     */
    public function test_create_proxy_bid_zero_maximum_throws() {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'greater than zero' );
        
        $this->service->createProxyBid( 100, 50, 0 );
    }
    
    /**
     * Test create proxy bid with negative maximum throws
     *
     * @test
     */
    public function test_create_proxy_bid_negative_maximum_throws() {
        $this->expectException( \InvalidArgumentException::class );
        
        $this->service->createProxyBid( 100, 50, -100.00 );
    }
    
    /**
     * Test create proxy bid when active proxy exists throws
     *
     * @test
     */
    public function test_create_proxy_bid_existing_active_throws() {
        $existing_proxy = $this->createTestProxyBid( 5 );
        
        $this->repository->expects( $this->once() )
            ->method( 'findByAuctionAndUser' )
            ->willReturn( $existing_proxy );
        
        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'already has active' );
        
        $this->service->createProxyBid( 100, 50, 500.00 );
    }
    
    /**
     * Test create proxy bid allows replacement of ended proxy
     *
     * @test
     */
    public function test_create_proxy_bid_replaces_ended_proxy() {
        $ended_proxy = $this->createTestProxyBid( 5, ProxyBid::STATUS_ENDED );
        
        $this->repository->expects( $this->once() )
            ->method( 'findByAuctionAndUser' )
            ->willReturn( $ended_proxy );
        
        $this->repository->expects( $this->once() )
            ->method( 'save' )
            ->willReturn( 6 );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $this->createTestProxyBid( 6 ) );
        
        $result = $this->service->createProxyBid( 100, 50, 500.00 );
        
        $this->assertInstanceOf( ProxyBid::class, $result );
    }
    
    /**
     * Test update maximum bid success
     *
     * @test
     */
    public function test_update_maximum_bid_success() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 250.00 );
        
        $this->repository->expects( $this->once() )
            ->method( 'update' );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->with( 1 )
            ->willReturn( $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 400.00 ) );
        
        $result = $this->service->updateMaximumBid( $proxy, 400.00 );
        
        $this->assertEquals( 400.00, $result->getMaximumBid() );
    }
    
    /**
     * Test update maximum bid zero throws
     *
     * @test
     */
    public function test_update_maximum_bid_zero_throws() {
        $proxy = $this->createTestProxyBid( 1 );
        
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'greater than zero' );
        
        $this->service->updateMaximumBid( $proxy, 0 );
    }
    
    /**
     * Test update maximum bid below current proxy bid throws (REQ-AB-008)
     *
     * @test
     */
    public function test_update_maximum_bid_below_current_throws() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 100.00, 250.00 );
        
        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'cannot be less than' );
        
        $this->service->updateMaximumBid( $proxy, 200.00 );
    }
    
    /**
     * Test update current bid success
     *
     * @test
     */
    public function test_update_current_bid_success() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 400.00, 200.00 );
        
        $this->repository->expects( $this->once() )
            ->method( 'update' );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 400.00, 320.00 ) );
        
        $result = $this->service->updateCurrentBid( $proxy, 320.00 );
        
        $this->assertEquals( 320.00, $result->getCurrentProxyBid() );
    }
    
    /**
     * Test update current bid exceeding maximum throws
     *
     * @test
     */
    public function test_update_current_bid_exceeds_maximum_throws() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 300.00 );
        
        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'exceeds maximum' );
        
        $this->service->updateCurrentBid( $proxy, 400.00 );
    }
    
    /**
     * Test update current bid not increasing throws
     *
     * @test
     */
    public function test_update_current_bid_not_increasing_throws() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE, 400.00, 200.00 );
        
        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'must be greater' );
        
        $this->service->updateCurrentBid( $proxy, 200.00 );
    }
    
    /**
     * Test mark outbid
     *
     * @test
     */
    public function test_mark_outbid() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE );
        
        $this->repository->expects( $this->once() )
            ->method( 'update' );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $this->createTestProxyBid( 1, ProxyBid::STATUS_OUTBID ) );
        
        $result = $this->service->markOutbid( $proxy );
        
        $this->assertTrue( $result->isOutbid() );
    }
    
    /**
     * Test cancel proxy bid
     *
     * @test
     */
    public function test_cancel_proxy_bid() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE );
        
        $this->repository->expects( $this->once() )
            ->method( 'update' );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $this->createTestProxyBid( 1, ProxyBid::STATUS_CANCELLED ) );
        
        $result = $this->service->cancelProxyBid( $proxy );
        
        $this->assertTrue( $result->isCancelled() );
        $this->assertTrue( $result->isCancelledByUser() );
    }
    
    /**
     * Test end proxy bid
     *
     * @test
     */
    public function test_end_proxy_bid() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE );
        
        $this->repository->expects( $this->once() )
            ->method( 'update' );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $this->createTestProxyBid( 1, ProxyBid::STATUS_ENDED ) );
        
        $result = $this->service->endProxyBid( $proxy );
        
        $this->assertTrue( $result->isEnded() );
    }
    
    /**
     * Test is proxy bid active
     *
     * @test
     */
    public function test_is_proxy_bid_active() {
        $active_proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE );
        $ended_proxy = $this->createTestProxyBid( 2, ProxyBid::STATUS_ENDED );
        
        $this->assertTrue( $this->service->isProxyBidActive( $active_proxy ) );
        $this->assertFalse( $this->service->isProxyBidActive( $ended_proxy ) );
    }
    
    /**
     * Test get active proxy bids
     *
     * @test
     */
    public function test_get_active_proxy_bids() {
        $proxies = [
            $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE ),
            $this->createTestProxyBid( 2, ProxyBid::STATUS_ACTIVE ),
        ];
        
        $this->repository->expects( $this->once() )
            ->method( 'findActiveByUser' )
            ->with( 50 )
            ->willReturn( $proxies );
        
        $result = $this->service->getActiveProxyBids( 50 );
        
        $this->assertCount( 2, $result );
    }
    
    /**
     * Test get active proxy bids for auction
     *
     * @test
     */
    public function test_get_active_proxy_bids_for_auction() {
        $proxies = [
            $this->createTestProxyBid( 1 ),
            $this->createTestProxyBid( 2 ),
        ];
        
        $this->repository->expects( $this->once() )
            ->method( 'findActiveByAuction' )
            ->with( 100 )
            ->willReturn( $proxies );
        
        $result = $this->service->getActiveProxyBidsForAuction( 100 );
        
        $this->assertCount( 2, $result );
    }
    
    /**
     * Test get proxy bid
     *
     * @test
     */
    public function test_get_proxy_bid() {
        $proxy = $this->createTestProxyBid( 1 );
        
        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->with( 1 )
            ->willReturn( $proxy );
        
        $result = $this->service->getProxyBid( 1 );
        
        $this->assertInstanceOf( ProxyBid::class, $result );
        $this->assertEquals( 1, $result->getId() );
    }
    
    /**
     * Test get proxy bid for auction
     *
     * @test
     */
    public function test_get_proxy_bid_for_auction() {
        $proxy = $this->createTestProxyBid( 1 );
        
        $this->repository->expects( $this->once() )
            ->method( 'findByAuctionAndUser' )
            ->with( 100, 50 )
            ->willReturn( $proxy );
        
        $result = $this->service->getProxyBidForAuction( 100, 50 );
        
        $this->assertInstanceOf( ProxyBid::class, $result );
    }
    
    /**
     * Test state transitions
     *
     * @test
     */
    public function test_state_transitions() {
        $proxy = $this->createTestProxyBid( 1, ProxyBid::STATUS_ACTIVE );
        $this->assertTrue( $proxy->isActive() );
        
        $this->repository->expects( $this->any() )
            ->method( 'update' );
        
        $this->repository->expects( $this->any() )
            ->method( 'find' )
            ->willReturnOnConsecutiveCalls(
                $this->createTestProxyBid( 1, ProxyBid::STATUS_OUTBID ),
                $this->createTestProxyBid( 1, ProxyBid::STATUS_ENDED )
            );
        
        $outbid = $this->service->markOutbid( $proxy );
        $this->assertTrue( $outbid->isOutbid() );
        
        $ended = $this->service->endProxyBid( $outbid );
        $this->assertTrue( $ended->isEnded() );
    }
    
    /**
     * Helper method to create test proxy bid
     *
     * @param int    $id           Proxy bid ID
     * @param string $status       Status
     * @param float  $maximum_bid  Maximum bid
     * @param float  $current_bid  Current bid
     * @return ProxyBid
     */
    private function createTestProxyBid(
        int $id,
        string $status = ProxyBid::STATUS_ACTIVE,
        float $maximum_bid = 500.00,
        float $current_bid = 0.00
    ): ProxyBid {
        $now = new \DateTime();
        
        return ProxyBid::create( [
            'id'                  => $id,
            'auction_id'          => 100,
            'user_id'             => 50,
            'maximum_bid'         => $maximum_bid,
            'current_proxy_bid'   => $current_bid,
            'status'              => $status,
            'cancelled_by_user'   => false,
            'created_at'          => $now,
            'updated_at'          => $now,
        ] );
    }
}
