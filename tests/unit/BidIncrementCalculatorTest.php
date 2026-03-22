<?php
/**
 * BidIncrementCalculator Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests\Unit
 * @version    1.0.0
 * @requirement REQ-AB-002: Test bid increment calculation
 * @requirement REQ-AB-003: Test multiple increment strategies
 */

namespace WC\Auction\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\BidIncrementCalculator;

/**
 * BidIncrementCalculatorTest - Test suite for bid increment strategies
 *
 * @covers \WC\Auction\Services\BidIncrementCalculator
 * @group services
 */
class BidIncrementCalculatorTest extends TestCase {
    
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
        $this->calculator = new BidIncrementCalculator();
    }
    
    /**
     * Test default strategy is dynamic
     *
     * @test
     */
    public function test_default_strategy_is_dynamic() {
        $this->assertEquals( BidIncrementCalculator::STRATEGY_DYNAMIC, $this->calculator->getStrategy() );
    }
    
    /**
     * Test constructor with fixed strategy
     *
     * @test
     */
    public function test_constructor_with_fixed_strategy() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_FIXED, [ 'increment' => 10.00 ] );
        $this->assertEquals( BidIncrementCalculator::STRATEGY_FIXED, $calc->getStrategy() );
    }
    
    /**
     * Test calculate next bid with positive values
     *
     * @test
     */
    public function test_calculate_next_bid_basic() {
        $next_bid = $this->calculator->calculateNextBid( 100.00, 200.00 );
        
        $this->assertGreaterThan( 100.00, $next_bid );
        $this->assertLessThanOrEqual( 200.00, $next_bid );
    }
    
    /**
     * Test next bid never exceeds user max
     *
     * @test
     */
    public function test_next_bid_never_exceeds_user_max() {
        $next_bid = $this->calculator->calculateNextBid( 150.00, 160.00 );
        
        $this->assertLessThanOrEqual( 160.00, $next_bid );
    }
    
    /**
     * Test next bid beats current bid (REQ-AB-002)
     *
     * @test
     */
    public function test_next_bid_beats_current_bid() {
        $current = 50.00;
        $user_max = 100.00;
        $next_bid = $this->calculator->calculateNextBid( $current, $user_max );
        
        $this->assertGreaterThan( $current, $next_bid );
    }
    
    /**
     * Test throws on negative current bid
     *
     * @test
     */
    public function test_throws_on_negative_current_bid() {
        $this->expectException( \InvalidArgumentException::class );
        
        $this->calculator->calculateNextBid( -10.00, 100.00 );
    }
    
    /**
     * Test throws on negative user max bid
     *
     * @test
     */
    public function test_throws_on_negative_user_max_bid() {
        $this->expectException( \InvalidArgumentException::class );
        
        $this->calculator->calculateNextBid( 50.00, -100.00 );
    }
    
    /**
     * Test throws when user max <= current bid
     *
     * @test
     */
    public function test_throws_when_user_max_equals_current() {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'must exceed' );
        
        $this->calculator->calculateNextBid( 100.00, 100.00 );
    }
    
    /**
     * Test throws when user max < current bid
     *
     * @test
     */
    public function test_throws_when_user_max_less_than_current() {
        $this->expectException( \InvalidArgumentException::class );
        
        $this->calculator->calculateNextBid( 150.00, 100.00 );
    }
    
    /**
     * Test fixed increment strategy
     *
     * REQ-AB-003: Support fixed increment strategy
     *
     * @test
     */
    public function test_fixed_increment_strategy() {
        $calc = new BidIncrementCalculator( 
            BidIncrementCalculator::STRATEGY_FIXED, 
            [ 'increment' => 5.00 ]
        );
        
        $next_bid = $calc->calculateNextBid( 100.00, 200.00 );
        
        // Should be 100.00 + 5.00 = 105.00
        $this->assertEquals( 105.00, $next_bid );
    }
    
    /**
     * Test percentage increment strategy
     *
     * REQ-AB-003: Support percentage increment strategy
     *
     * @test
     */
    public function test_percentage_increment_strategy() {
        $calc = new BidIncrementCalculator(
            BidIncrementCalculator::STRATEGY_PERCENTAGE,
            [ 'percentage' => 0.10 ] // 10%
        );
        
        $next_bid = $calc->calculateNextBid( 100.00, 200.00 );
        
        // Should be 100.00 + (100.00 * 0.10) = 110.00
        $this->assertEquals( 110.00, $next_bid );
    }
    
    /**
     * Test default percentage is 5%
     *
     * @test
     */
    public function test_percentage_default_is_5_percent() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_PERCENTAGE );
        
        $next_bid = $calc->calculateNextBid( 100.00, 200.00 );
        
        // Should be 100.00 + (100.00 * 0.05) = 105.00
        $this->assertEquals( 105.00, $next_bid );
    }
    
    /**
     * Test dynamic increment strategy - low tier
     *
     * REQ-AB-003: Support dynamic/tiered increment strategy
     *
     * @test
     */
    public function test_dynamic_strategy_low_tier() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_DYNAMIC );
        
        $next_bid = $calc->calculateNextBid( 50.00, 200.00 );
        
        // For bids under $100, should increment by $1
        $this->assertEquals( 51.00, $next_bid );
    }
    
    /**
     * Test dynamic increment strategy - medium tier
     *
     * @test
     */
    public function test_dynamic_strategy_medium_tier() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_DYNAMIC );
        
        $next_bid = $calc->calculateNextBid( 200.00, 500.00 );
        
        // For bids $100-$500, should increment by $5
        $this->assertEquals( 205.00, $next_bid );
    }
    
    /**
     * Test dynamic increment strategy - high tier
     *
     * @test
     */
    public function test_dynamic_strategy_high_tier() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_DYNAMIC );
        
        $next_bid = $calc->calculateNextBid( 600.00, 1000.00 );
        
        // For bids $500-$1000, should increment by $10
        $this->assertEquals( 610.00, $next_bid );
    }
    
    /**
     * Test dynamic increment strategy - very high tier
     *
     * @test
     */
    public function test_dynamic_strategy_very_high_tier() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_DYNAMIC );
        
        $next_bid = $calc->calculateNextBid( 5000.00, 10000.00 );
        
        // For bids $1000-$5000, should increment by $25
        // Actually this is 5000, so it should be 2% = 100
        $this->assertGreaterThan( 5000.00, $next_bid );
    }
    
    /**
     * Test dynamic strategy with custom config
     *
     * @test
     */
    public function test_dynamic_strategy_with_custom_config() {
        $calc = new BidIncrementCalculator( BidIncrementCalculator::STRATEGY_DYNAMIC );
        
        $custom_config = [
            'bid_tiers' => [
                50 => 0.50,
                100 => 1.00,
                500 => 5.00,
            ]
        ];
        
        $next_bid = $calc->calculateNextBid( 25.00, 500.00, $custom_config );
        
        // Under $50, should increment by $0.50
        $this->assertEquals( 25.50, $next_bid );
    }
    
    /**
     * Test tiered increment strategy
     *
     * @test
     */
    public function test_tiered_increment_strategy() {
        $calc = new BidIncrementCalculator(
            BidIncrementCalculator::STRATEGY_TIERED,
            [
                'tiers' => [
                    100 => 1.00,
                    500 => 5.00,
                    1000 => 10.00,
                ]
            ]
        );
        
        $next_bid = $calc->calculateNextBid( 75.00, 1000.00 );
        
        // Under $100, should increment by $1
        $this->assertEquals( 76.00, $next_bid );
    }
    
    /**
     * Test set strategy method
     *
     * @test
     */
    public function test_set_strategy_method() {
        $this->calculator->setStrategy( 
            BidIncrementCalculator::STRATEGY_FIXED,
            [ 'increment' => 2.50 ]
        );
        
        $this->assertEquals( BidIncrementCalculator::STRATEGY_FIXED, $this->calculator->getStrategy() );
        
        $next_bid = $this->calculator->calculateNextBid( 100.00, 200.00 );
        $this->assertEquals( 102.50, $next_bid );
    }
    
    /**
     * Test validate bid with valid bid
     *
     * @test
     */
    public function test_validate_bid_valid() {
        $valid = $this->calculator->validateBid( 110.00, 200.00, 100.00 );
        $this->assertTrue( $valid );
    }
    
    /**
     * Test validate bid rejects zero bid
     *
     * @test
     */
    public function test_validate_bid_rejects_zero() {
        $valid = $this->calculator->validateBid( 0, 200.00, 100.00 );
        $this->assertFalse( $valid );
    }
    
    /**
     * Test validate bid rejects negative bid
     *
     * @test
     */
    public function test_validate_bid_rejects_negative() {
        $valid = $this->calculator->validateBid( -10.00, 200.00, 100.00 );
        $this->assertFalse( $valid );
    }
    
    /**
     * Test validate bid rejects bid exceeding user max
     *
     * @test
     */
    public function test_validate_bid_rejects_over_user_max() {
        $valid = $this->calculator->validateBid( 250.00, 200.00, 100.00 );
        $this->assertFalse( $valid );
    }
    
    /**
     * Test validate bid rejects bid not beating current
     *
     * @test
     */
    public function test_validate_bid_rejects_not_beating_current() {
        $valid = $this->calculator->validateBid( 100.00, 200.00, 100.00 );
        $this->assertFalse( $valid );
    }
    
    /**
     * Test validate bid rejects bid below current
     *
     * @test
     */
    public function test_validate_bid_rejects_below_current() {
        $valid = $this->calculator->validateBid( 95.00, 200.00, 100.00 );
        $this->assertFalse( $valid );
    }
    
    /**
     * Test decimal precision in next bid calculation
     *
     * @test
     */
    public function test_decimal_precision() {
        $next_bid = $this->calculator->calculateNextBid( 99.99, 200.00 );
        
        // Should be rounded to 2 decimal places
        $this->assertEquals( 2, strlen( substr( strrchr( (string) $next_bid, '.' ), 1 ) ) );
    }
    
    /**
     * Test large bid amounts
     *
     * @test
     */
    public function test_large_bid_amounts() {
        $next_bid = $this->calculator->calculateNextBid( 10000.00, 50000.00 );
        
        $this->assertGreaterThan( 10000.00, $next_bid );
        $this->assertLessThanOrEqual( 50000.00, $next_bid );
    }
    
    /**
     * Test very close user max and current bid
     *
     * @test
     */
    public function test_very_close_bids() {
        $next_bid = $this->calculator->calculateNextBid( 100.00, 100.01 );
        
        // Bid should be slightly above current
        $this->assertGreaterThan( 100.00, $next_bid );
        $this->assertLessThanOrEqual( 100.01, $next_bid );
    }
}
