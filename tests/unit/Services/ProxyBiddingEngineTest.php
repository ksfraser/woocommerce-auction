<?php

namespace Yith\Auctions\Tests\Unit\Services\AutoBidding;

use Yith\Auctions\Tests\BaseUnitTest;
use Yith\Auctions\Services\AutoBidding\ProxyBiddingEngine;
use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Tests\Fixtures\MoneyFixture;

/**
 * ProxyBiddingEngineTest - Unit tests for proxy bidding algorithm.
 *
 * Tests core proxy bidding calculations and outcomes.
 * These tests ensure the algorithm correctly handles various bidding scenarios.
 *
 * @package Yith\Auctions\Tests\Unit\Services\AutoBidding
 * @requirement REQ-AUTO-BID-PROXY-001: Proxy bidding algorithm
 */
class ProxyBiddingEngineTest extends BaseUnitTest
{
    /**
     * @var ProxyBiddingEngine Engine instance
     */
    private ProxyBiddingEngine $engine;

    /**
     * Test setup.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ProxyBiddingEngine();
    }

    /**
     * Standard increment calculator for testing.
     *
     * @param float $bid_amount Current bid amount
     * @return float Increment
     */
    private function standardIncrement(float $bid_amount): float
    {
        if ($bid_amount < 1.00) return 0.05;
        if ($bid_amount < 5.00) return 0.25;
        if ($bid_amount < 15.00) return 0.50;
        if ($bid_amount < 60.00) return 1.00;
        if ($bid_amount < 150.00) return 2.50;
        if ($bid_amount < 300.00) return 5.00;
        return 10.00;
    }

    /**
     * Test: Proxy bid calculation - basic scenario.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testCalculateProxyBid_BasicScenario()
    {
        $auto_bid_max = Money::fromFloat(100.00);
        $incoming_bid = Money::fromFloat(50.00);

        $proxy = $this->engine->calculateProxyBid(
            $auto_bid_max,
            $incoming_bid,
            [$this, 'standardIncrement']
        );

        // Should be incoming (50) + increment (1.00) = 51.00
        $this->assertNotNull($proxy);
        $this->assertMoneyEquals(51.00, $proxy->asFloat(), 0.01);
    }

    /**
     * Test: Proxy bid caps at maximum.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testCalculateProxyBid_CapsAtMaximum()
    {
        $auto_bid_max = Money::fromFloat(100.00);
        $incoming_bid = Money::fromFloat(99.00);

        $proxy = $this->engine->calculateProxyBid(
            $auto_bid_max,
            $incoming_bid,
            [$this, 'standardIncrement']
        );

        // Should be capped at 100.00 (maximum)
        $this->assertNotNull($proxy);
        $this->assertMoneyEquals(100.00, $proxy->asFloat(), 0.01);
    }

    /**
     * Test: Auto-bidder loses at maximum.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testCalculateProxyBid_AutoBidderLoses()
    {
        $auto_bid_max = Money::fromFloat(100.00);
        $incoming_bid = Money::fromFloat(100.00);

        $proxy = $this->engine->calculateProxyBid(
            $auto_bid_max,
            $incoming_bid,
            [$this, 'standardIncrement']
        );

        // Should be null (auto-bidder lost)
        $this->assertNull($proxy);
    }

    /**
     * Test: Auto-bidder loses above maximum.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testCalculateProxyBid_BidAboveMaximum()
    {
        $auto_bid_max = Money::fromFloat(100.00);
        $incoming_bid = Money::fromFloat(150.00);

        $proxy = $this->engine->calculateProxyBid(
            $auto_bid_max,
            $incoming_bid,
            [$this, 'standardIncrement']
        );

        $this->assertNull($proxy);
    }

    /**
     * Test: Proxy bid determination - auto-bidder wins.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testDoesProxyBidWin_AutoBidderWins()
    {
        $proxy_bid = Money::fromFloat(51.00);
        $competing_bid = Money::fromFloat(50.00);

        $wins = $this->engine->doesProxyBidWin($proxy_bid, $competing_bid);

        $this->assertTrue($wins);
    }

    /**
     * Test: Proxy bid determination - competitor wins.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testDoesProxyBidWin_CompetitorWins()
    {
        $proxy_bid = Money::fromFloat(50.00);
        $competing_bid = Money::fromFloat(51.00);

        $wins = $this->engine->doesProxyBidWin($proxy_bid, $competing_bid);

        $this->assertFalse($wins);
    }

    /**
     * Test: Determine winning bid amount.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testDetermineWinningBid_AutoBidderWins()
    {
        $auto_bid_max = Money::fromFloat(100.00);
        $competing_bid = Money::fromFloat(50.00);
        $proxy_bid = Money::fromFloat(51.00);

        $winning = $this->engine->determineWinningBid(
            $auto_bid_max,
            $competing_bid,
            $proxy_bid,
            [$this, 'standardIncrement']
        );

        // Winning bid should be competing_bid + increment = 50 + 1 = 51
        $this->assertMoneyEquals(51.00, $winning->asFloat(), 0.01);
    }

    /**
     * Test: Determine winning bid amount - competitor wins.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testDetermineWinningBid_CompetitorWins()
    {
        $auto_bid_max = Money::fromFloat(100.00);
        $competing_bid = Money::fromFloat(51.00);
        $proxy_bid = Money::fromFloat(50.00);

        $winning = $this->engine->determineWinningBid(
            $auto_bid_max,
            $competing_bid,
            $proxy_bid,
            [$this, 'standardIncrement']
        );

        // Winning bid is the competing bid
        $this->assertMoneyEquals(51.00, $winning->asFloat(), 0.01);
    }

    /**
     * Test: Should place counter bid - active auto-bid.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testShouldPlaceCounterBid_Active()
    {
        $auto_bid = [
            'status'       => 'ACTIVE',
            'user_id'      => 1,
            'maximum_bid'  => '100.00',
        ];
        $new_bid = [
            'user_id' => 2,
            'amount'  => '50.00',
        ];

        $should_place = $this->engine->shouldPlaceCounterBid(
            $auto_bid,
            $new_bid,
            'ACTIVE'
        );

        $this->assertTrue($should_place);
    }

    /**
     * Test: Should not place counter bid - same user.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testShouldPlaceCounterBid_SameUser()
    {
        $auto_bid = [
            'status'       => 'ACTIVE',
            'user_id'      => 1,
            'maximum_bid'  => '100.00',
        ];
        $new_bid = [
            'user_id' => 1, // Same user
            'amount'  => '50.00',
        ];

        $should_place = $this->engine->shouldPlaceCounterBid(
            $auto_bid,
            $new_bid,
            'ACTIVE'
        );

        $this->assertFalse($should_place);
    }

    /**
     * Test: Should not place counter bid - bid at maximum.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testShouldPlaceCounterBid_BidAtMaximum()
    {
        $auto_bid = [
            'status'       => 'ACTIVE',
            'user_id'      => 1,
            'maximum_bid'  => '50.00',
        ];
        $new_bid = [
            'user_id' => 2,
            'amount'  => '50.00', // >= maximum
        ];

        $should_place = $this->engine->shouldPlaceCounterBid(
            $auto_bid,
            $new_bid,
            'ACTIVE'
        );

        $this->assertFalse($should_place);
    }

    /**
     * Test: Should not place counter bid - auction inactive.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testShouldPlaceCounterBid_AuctionClosed()
    {
        $auto_bid = [
            'status'       => 'ACTIVE',
            'user_id'      => 1,
            'maximum_bid'  => '100.00',
        ];
        $new_bid = [
            'user_id' => 2,
            'amount'  => '50.00',
        ];

        $should_place = $this->engine->shouldPlaceCounterBid(
            $auto_bid,
            $new_bid,
            'COMPLETED' // Auction closed
        );

        $this->assertFalse($should_place);
    }

    /**
     * Test: Simulate bidding scenario.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testSimulateBidding_AutoBidderWins()
    {
        $auto_bid_max = Money::fromFloat(100.00);
        $bids = [
            Money::fromFloat(10.00),
            Money::fromFloat(20.00),
            Money::fromFloat(30.00),
            Money::fromFloat(50.00),
        ];

        $result = $this->engine->simulateBidding(
            $auto_bid_max,
            $bids,
            [$this, 'standardIncrement']
        );

        $this->assertEquals('auto_bidder', $result['winner']);
        $this->assertTrue($result['auto_bid_wins']);
    }

    /**
     * Test: Simulate bidding - auto-bidder gets outbid.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testSimulateBidding_AutoBidderLoses()
    {
        $auto_bid_max = Money::fromFloat(50.00);
        $bids = [
            Money::fromFloat(10.00),
            Money::fromFloat(20.00),
            Money::fromFloat(60.00), // Above maximum
        ];

        $result = $this->engine->simulateBidding(
            $auto_bid_max,
            $bids,
            [$this, 'standardIncrement']
        );

        $this->assertEquals('manual_bidder', $result['winner']);
        $this->assertFalse($result['auto_bid_wins']);
    }

    /**
     * Test: Large bid values precision.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testCalculateProxyBid_LargeBids()
    {
        $auto_bid_max = Money::fromFloat(5000.00);
        $incoming_bid = Money::fromFloat(2500.00);

        $proxy = $this->engine->calculateProxyBid(
            $auto_bid_max,
            $incoming_bid,
            [$this, 'standardIncrement']
        );

        // Should be 2500 + 10 (increment for this range) = 2510
        $this->assertNotNull($proxy);
        $this->assertMoneyEquals(2510.00, $proxy->asFloat(), 0.01);
    }

    /**
     * Test: Edge case - very small bids.
     *
     * @test
     * @requirement REQ-AUTO-BID-PROXY-001
     */
    public function testCalculateProxyBid_VerySmallBids()
    {
        $auto_bid_max = Money::fromFloat(2.00);
        $incoming_bid = Money::fromFloat(0.50);

        $proxy = $this->engine->calculateProxyBid(
            $auto_bid_max,
            $incoming_bid,
            [$this, 'standardIncrement']
        );

        // Should be 0.50 + 0.05 = 0.55
        $this->assertNotNull($proxy);
        $this->assertTrue($proxy->greaterThan(Money::fromFloat(0.50)));
        $this->assertTrue($proxy->lessThan($auto_bid_max));
    }
}
