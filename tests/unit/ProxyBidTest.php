<?php
/**
 * ProxyBid Model Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Unit
 * @version    1.0.0
 * @requirement REQ-AB-001: Validate proxy bid model
 * @requirement REQ-AB-008: Enforce maximum bid constraints
 */

namespace WC\Auction\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\ProxyBid;

/**
 * ProxyBidTest - Test suite for proxy bid immutable value object
 *
 * @covers \WC\Auction\Models\ProxyBid
 * @group models
 */
class ProxyBidTest extends TestCase {
    
    /**
     * Valid test data
     *
     * @var array
     */
    private $valid_data;
    
    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        $now = new \DateTime();
        
        $this->valid_data = [
            'id'                  => 1,
            'auction_id'          => 100,
            'user_id'             => 50,
            'maximum_bid'         => 500.00,
            'current_proxy_bid'   => 250.00,
            'status'              => ProxyBid::STATUS_ACTIVE,
            'cancelled_by_user'   => false,
            'created_at'          => $now,
            'updated_at'          => $now,
            'notes'               => 'Test proxy bid',
        ];
    }
    
    /**
     * Test create factory succeeds with valid data
     *
     * @test
     */
    public function test_create_with_valid_data() {
        $proxy_bid = ProxyBid::create( $this->valid_data );
        
        $this->assertInstanceOf( ProxyBid::class, $proxy_bid );
        $this->assertEquals( 1, $proxy_bid->getId() );
        $this->assertEquals( 100, $proxy_bid->getAuctionId() );
        $this->assertEquals( 50, $proxy_bid->getUserId() );
    }
    
    /**
     * Test missing required field throws exception
     *
     * @test
     */
    public function test_create_throws_on_missing_required_field() {
        $this->expectException( \InvalidArgumentException::class );
        
        $data = $this->valid_data;
        unset( $data['auction_id'] );
        
        ProxyBid::create( $data );
    }
    
    /**
     * Test missing id throws exception
     *
     * @test
     */
    public function test_create_throws_on_missing_id() {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Required field missing: id' );
        
        $data = $this->valid_data;
        unset( $data['id'] );
        
        ProxyBid::create( $data );
    }
    
    /**
     * Test invalid status throws exception
     *
     * @test
     */
    public function test_create_throws_on_invalid_status() {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Invalid status' );
        
        $data           = $this->valid_data;
        $data['status'] = 'invalid_status';
        
        ProxyBid::create( $data );
    }
    
    /**
     * Test current proxy bid exceeding maximum throws exception (REQ-AB-008)
     *
     * @test
     */
    public function test_create_throws_on_proxy_bid_exceeds_maximum() {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Current proxy bid cannot exceed maximum bid' );
        
        $data                       = $this->valid_data;
        $data['maximum_bid']        = 100.00;
        $data['current_proxy_bid']  = 150.00;
        
        ProxyBid::create( $data );
    }
    
    /**
     * Test proxy bid equal to maximum is allowed
     *
     * @test
     */
    public function test_proxy_bid_equal_to_maximum_allowed() {
        $data                       = $this->valid_data;
        $data['maximum_bid']        = 100.00;
        $data['current_proxy_bid']  = 100.00;
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertEquals( 100.00, $proxy_bid->getMaximumBid() );
        $this->assertEquals( 100.00, $proxy_bid->getCurrentProxyBid() );
    }
    
    /**
     * Test all valid status values
     *
     * @test
     * @dataProvider validStatusProvider
     */
    public function test_create_with_all_valid_statuses( string $status ) {
        $data           = $this->valid_data;
        $data['status'] = $status;
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertEquals( $status, $proxy_bid->getStatus() );
    }
    
    /**
     * Data provider for valid status values
     */
    public static function validStatusProvider(): array {
        return [
            [ ProxyBid::STATUS_ACTIVE ],
            [ ProxyBid::STATUS_ENDED ],
            [ ProxyBid::STATUS_CANCELLED ],
            [ ProxyBid::STATUS_OUTBID ],
        ];
    }
    
    /**
     * Test status checker methods
     *
     * @test
     */
    public function test_status_checkers() {
        $data           = $this->valid_data;
        $data['status'] = ProxyBid::STATUS_ACTIVE;
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertTrue( $proxy_bid->isActive() );
        $this->assertFalse( $proxy_bid->isEnded() );
        $this->assertFalse( $proxy_bid->isCancelled() );
        $this->assertFalse( $proxy_bid->isOutbid() );
    }
    
    /**
     * Test status checker for ended
     *
     * @test
     */
    public function test_status_checker_ended() {
        $data           = $this->valid_data;
        $data['status'] = ProxyBid::STATUS_ENDED;
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertFalse( $proxy_bid->isActive() );
        $this->assertTrue( $proxy_bid->isEnded() );
    }
    
    /**
     * Test datetime parsing from string
     *
     * @test
     */
    public function test_datetime_parsing_from_string() {
        $data = $this->valid_data;
        $data['created_at'] = '2026-03-22 10:30:00';
        $data['updated_at'] = '2026-03-22 11:30:00';
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertInstanceOf( \DateTime::class, $proxy_bid->getCreatedAt() );
        $this->assertEquals( '2026-03-22 10:30:00', $proxy_bid->getCreatedAt()->format( 'Y-m-d H:i:s' ) );
    }
    
    /**
     * Test optional ended_at field
     *
     * @test
     */
    public function test_optional_ended_at_field() {
        $data = $this->valid_data;
        $data['ended_at'] = '2026-03-23 15:00:00';
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertNotNull( $proxy_bid->getEndedAt() );
        $this->assertInstanceOf( \DateTime::class, $proxy_bid->getEndedAt() );
    }
    
    /**
     * Test optional ended_at defaults to null
     *
     * @test
     */
    public function test_optional_ended_at_null_by_default() {
        $proxy_bid = ProxyBid::create( $this->valid_data );
        
        $this->assertNull( $proxy_bid->getEndedAt() );
    }
    
    /**
     * Test optional cancelled_at field
     *
     * @test
     */
    public function test_optional_cancelled_at_field() {
        $data = $this->valid_data;
        $data['cancelled_at'] = '2026-03-23 15:00:00';
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertNotNull( $proxy_bid->getCancelledAt() );
    }
    
    /**
     * Test toArray conversion
     *
     * @test
     */
    public function test_to_array_conversion() {
        $proxy_bid = ProxyBid::create( $this->valid_data );
        $array     = $proxy_bid->toArray();
        
        $this->assertIsArray( $array );
        $this->assertEquals( 1, $array['id'] );
        $this->assertEquals( 100, $array['auction_id'] );
        $this->assertEquals( 500.00, $array['maximum_bid'] );
        $this->assertArrayHasKey( 'created_at', $array );
        $this->assertIsString( $array['created_at'] );
    }
    
    /**
     * Test immutability - no setters available
     *
     * @test
     */
    public function test_immutability() {
        $proxy_bid = ProxyBid::create( $this->valid_data );
        
        // Cannot access private properties directly
        $this->assertFalse( method_exists( $proxy_bid, 'setId' ) );
        $this->assertFalse( method_exists( $proxy_bid, 'setStatus' ) );
        $this->assertFalse( method_exists( $proxy_bid, 'setMaximumBid' ) );
    }
    
    /**
     * Test cancelled_by_user flag
     *
     * @test
     */
    public function test_cancelled_by_user_flag() {
        $data = $this->valid_data;
        $data['cancelled_by_user'] = true;
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertTrue( $proxy_bid->isCancelledByUser() );
    }
    
    /**
     * Test notes field
     *
     * @test
     */
    public function test_notes_field() {
        $data = $this->valid_data;
        $data['notes'] = 'Important notes about this bid';
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertEquals( 'Important notes about this bid', $proxy_bid->getNotes() );
    }
    
    /**
     * Test notes field optional
     *
     * @test
     */
    public function test_notes_field_optional() {
        $data = $this->valid_data;
        unset( $data['notes'] );
        
        $proxy_bid = ProxyBid::create( $data );
        
        $this->assertNull( $proxy_bid->getNotes() );
    }
    
    /**
     * Test create with integer string values
     *
     * @test
     */
    public function test_create_with_string_numeric_values() {
        $data = $this->valid_data;
        $data['id'] = '1';
        $data['auction_id'] = '100';
        $data['maximum_bid'] = '500.00';
        
        $proxy_bid = ProxyBid::create( $data );
        
        // Should be cast to correct types
        $this->assertIsInt( $proxy_bid->getId() );
        $this->assertEquals( 1, $proxy_bid->getId() );
    }
}
