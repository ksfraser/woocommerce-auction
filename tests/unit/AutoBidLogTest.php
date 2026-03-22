<?php
/**
 * AutoBidLog Model Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Unit
 * @version    1.0.0
 * @requirement REQ-AB-005: Track all auto-bid attempts
 * @requirement REQ-AB-006: Maintain complete audit trail
 */

namespace WC\Auction\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC\Auction\Models\AutoBidLog;

/**
 * AutoBidLogTest - Test suite for auto-bid audit log
 *
 * @covers \WC\Auction\Models\AutoBidLog
 * @group models
 */
class AutoBidLogTest extends TestCase {
    
    /**
     * Valid test data for successful bid
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
            'id'                   => 1,
            'auction_id'           => 100,
            'user_id'              => 50,
            'proxy_bid_id'         => 1,
            'bid_amount'           => 320.50,
            'previous_bid'         => 300.00,
            'bid_increment_used'   => 20.50,
            'success'              => true,
            'triggered_at'         => $now,
        ];
    }
    
    /**
     * Test create factory succeeds with valid data
     *
     * @test
     */
    public function test_create_with_valid_successful_bid() {
        $log = AutoBidLog::create( $this->valid_data );
        
        $this->assertInstanceOf( AutoBidLog::class, $log );
        $this->assertEquals( 1, $log->getId() );
        $this->assertEquals( 320.50, $log->getBidAmount() );
        $this->assertTrue( $log->wasSuccessful() );
    }
    
    /**
     * Test missing required field throws exception
     *
     * @test
     */
    public function test_create_throws_on_missing_required_field() {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Required field missing' );
        
        $data = $this->valid_data;
        unset( $data['auction_id'] );
        
        AutoBidLog::create( $data );
    }
    
    /**
     * Test failed bid without error_message throws exception
     *
     * @test
     */
    public function test_create_throws_on_failed_bid_without_error_message() {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'error_message is required when success is false' );
        
        $data = $this->valid_data;
        $data['success'] = false;
        unset( $data['error_message'] );
        
        AutoBidLog::create( $data );
    }
    
    /**
     * Test create with failed bid and error message
     *
     * @test
     */
    public function test_create_with_failed_bid() {
        $data = $this->valid_data;
        $data['success'] = false;
        $data['error_message'] = 'Bid exceeded maximum allowed amount';
        
        $log = AutoBidLog::create( $data );
        
        $this->assertFalse( $log->wasSuccessful() );
        $this->assertEquals( 'Bid exceeded maximum allowed amount', $log->getErrorMessage() );
        $this->assertTrue( $log->hasError() );
    }
    
    /**
     * Test successful bid has no error message
     *
     * @test
     */
    public function test_successful_bid_has_no_error() {
        $log = AutoBidLog::create( $this->valid_data );
        
        $this->assertTrue( $log->wasSuccessful() );
        $this->assertFalse( $log->hasError() );
        $this->assertNull( $log->getErrorMessage() );
    }
    
    /**
     * Test optional outbidding_bid_id field
     *
     * @test
     */
    public function test_optional_outbidding_bid_id() {
        $data = $this->valid_data;
        $data['outbidding_bid_id'] = 5;
        
        $log = AutoBidLog::create( $data );
        
        $this->assertEquals( 5, $log->getOutbiddingBidId() );
    }
    
    /**
     * Test outbidding_bid_id defaults to null
     *
     * @test
     */
    public function test_outbidding_bid_id_null_by_default() {
        $log = AutoBidLog::create( $this->valid_data );
        
        $this->assertNull( $log->getOutbiddingBidId() );
    }
    
    /**
     * Test processing_time_ms tracking (REQ-AB-004)
     *
     * @test
     */
    public function test_processing_time_tracking() {
        $data = $this->valid_data;
        $data['processing_time_ms'] = 45;  // 45ms - well under 100ms limit
        
        $log = AutoBidLog::create( $data );
        
        $this->assertEquals( 45, $log->getProcessingTimeMs() );
    }
    
    /**
     * Test processing_time_ms defaults to zero
     *
     * @test
     */
    public function test_processing_time_ms_default() {
        $log = AutoBidLog::create( $this->valid_data );
        
        $this->assertEquals( 0, $log->getProcessingTimeMs() );
    }
    
    /**
     * Test all getters work correctly
     *
     * @test
     */
    public function test_all_getters() {
        $log = AutoBidLog::create( $this->valid_data );
        
        $this->assertEquals( 1, $log->getId() );
        $this->assertEquals( 100, $log->getAuctionId() );
        $this->assertEquals( 50, $log->getUserId() );
        $this->assertEquals( 1, $log->getProxyBidId() );
        $this->assertEquals( 320.50, $log->getBidAmount() );
        $this->assertEquals( 300.00, $log->getPreviousBid() );
        $this->assertEquals( 20.50, $log->getBidIncrementUsed() );
        $this->assertInstanceOf( \DateTime::class, $log->getTriggeredAt() );
    }
    
    /**
     * Test datetime parsing from string
     *
     * @test
     */
    public function test_datetime_parsing_from_string() {
        $data = $this->valid_data;
        $data['triggered_at'] = '2026-03-22 10:30:00';
        
        $log = AutoBidLog::create( $data );
        
        $this->assertInstanceOf( \DateTime::class, $log->getTriggeredAt() );
        $this->assertEquals( '2026-03-22 10:30:00', $log->getTriggeredAt()->format( 'Y-m-d H:i:s' ) );
    }
    
    /**
     * Test toArray conversion
     *
     * @test
     */
    public function test_to_array_conversion() {
        $data = $this->valid_data;
        $data['error_message'] = null;
        
        $log = AutoBidLog::create( $data );
        $array = $log->toArray();
        
        $this->assertIsArray( $array );
        $this->assertEquals( 1, $array['id'] );
        $this->assertEquals( 320.50, $array['bid_amount'] );
        $this->assertTrue( $array['success'] );
        $this->assertArrayHasKey( 'triggered_at', $array );
    }
    
    /**
     * Test immutability - no setters
     *
     * @test
     */
    public function test_immutability() {
        $log = AutoBidLog::create( $this->valid_data );
        
        // Cannot access setters
        $this->assertFalse( method_exists( $log, 'setId' ) );
        $this->assertFalse( method_exists( $log, 'setSuccess' ) );
        $this->assertFalse( method_exists( $log, 'setBidAmount' ) );
    }
    
    /**
     * Test error scenarios with different error messages
     *
     * @test
     * @dataProvider failureScenarios
     */
    public function test_failure_scenarios( string $error, string $expected_message ) {
        $data = $this->valid_data;
        $data['success'] = false;
        $data['error_message'] = $error;
        
        $log = AutoBidLog::create( $data );
        
        $this->assertFalse( $log->wasSuccessful() );
        $this->assertStringContainsString( $expected_message, $log->getErrorMessage() );
    }
    
    /**
     * Data provider for failure scenarios
     */
    public static function failureScenarios(): array {
        return [
            [ 'User maximum bid exceeded', 'maximum bid' ],
            [ 'Database connection timeout', 'timeout' ],
            [ 'Bid increment calculation failed', 'increment' ],
            [ 'Auction no longer active', 'active' ],
        ];
    }
    
    /**
     * Test bid values with decimal precision
     *
     * @test
     */
    public function test_decimal_precision_handling() {
        $data = $this->valid_data;
        $data['bid_amount'] = 123.45;
        $data['previous_bid'] = 100.00;
        $data['bid_increment_used'] = 23.45;
        
        $log = AutoBidLog::create( $data );
        
        $this->assertEquals( 123.45, $log->getBidAmount() );
        $this->assertEquals( 100.00, $log->getPreviousBid() );
        $this->assertEquals( 23.45, $log->getBidIncrementUsed() );
    }
    
    /**
     * Test with string numeric values
     *
     * @test
     */
    public function test_create_with_string_numeric_values() {
        $data = $this->valid_data;
        $data['id'] = '1';
        $data['auction_id'] = '100';
        $data['bid_amount'] = '320.50';
        
        $log = AutoBidLog::create( $data );
        
        $this->assertIsInt( $log->getId() );
        $this->assertIsFloat( $log->getBidAmount() );
        $this->assertEquals( 320.50, $log->getBidAmount() );
    }
    
    /**
     * Test complete audit trail entry (REQ-AB-006)
     *
     * @test
     */
    public function test_complete_audit_trail_structure() {
        $data = $this->valid_data;
        $data['processing_time_ms'] = 67;
        $data['outbidding_bid_id'] = 3;
        $data['error_message'] = null;
        
        $log = AutoBidLog::create( $data );
        $array = $log->toArray();
        
        // Verify complete audit trail has all necessary fields
        $required_fields = [
            'id', 'auction_id', 'user_id', 'proxy_bid_id',
            'bid_amount', 'previous_bid', 'bid_increment_used',
            'success', 'error_message', 'processing_time_ms',
            'outbidding_bid_id', 'triggered_at'
        ];
        
        foreach ( $required_fields as $field ) {
            $this->assertArrayHasKey( $field, $array );
        }
    }
}
