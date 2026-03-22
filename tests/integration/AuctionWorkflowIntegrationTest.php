<?php
/**
 * Integration Tests for YITH Auctions - Auction Workflow
 *
 * @requirement REQ-CORE-003, REQ-CORE-004, REQ-CORE-007
 * @covers YITH_Auctions, WC_Product_Auction, YITH_WCACT_Bids
 */

namespace YITH\Auctions\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ksfraser\TestFactories\Builders\ScenarioBuilder;

/**
 * Class AuctionWorkflowIntegrationTest
 *
 * Tests the complete auction lifecycle from creation to completion
 *
 * @package YITH\Auctions\Tests\Integration
 */
class AuctionWorkflowIntegrationTest extends TestCase
{
    /**
     * Test scenario builder
     *
     * @var \ksfraser\TestFactories\Builders\ScenarioBuilder
     */
    private $scenario_builder;

    /**
     * Setup test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario_builder = new ScenarioBuilder();
    }

    /**
     * Test complete simple auction workflow
     *
     * Scenario: User creates auction, accepts bid, completes auction
     *
     * @requirement REQ-CORE-002, REQ-CORE-003, REQ-CORE-006, REQ-CORE-007
     * @return void
     */
    public function test_simple_auction_workflow(): void
    {
        // Create simple auction scenario
        $scenario = $this->scenario_builder
            ->createSimpleAuction()
            ->getScenario();

        // Verify product created
        $product = $scenario->getProduct(1);
        $this->assertNotNull($product);
        $this->assertEquals('Simple Auction Product', $product->getName());

        // Verify bid persisted
        $bids = $scenario->getBids();
        $this->assertCount(1, $bids);

        // Verify bid record
        $bid = $scenario->getBid(1);
        $this->assertNotNull($bid);
        $this->assertEquals('winner', $bid->getStatus());
    }

    /**
     * Test competitive auction with multiple bidders
     *
     * Scenario: Three bidders submit progressively higher bids
     *
     * @requirement REQ-CORE-003, REQ-CORE-004, REQ-CORE-005, REQ-CORE-006
     * @return void
     */
    public function test_competitive_auction_workflow(): void
    {
        // Create competitive auction with 3 bidders
        $scenario = $this->scenario_builder
            ->createCompetitiveAuction(3)
            ->getScenario();

        // Verify multiple bids created
        $bids = $scenario->getBids();
        $this->assertGreaterThan(2, count($bids));

        // Verify product exists
        $product = $scenario->getProduct(1);
        $this->assertNotNull($product);
        $this->assertEquals('Competitive Auction', $product->getName());

        // Verify bids are ordered by amount
        $bid_amounts = array_map(function($bid) {
            return $bid->getBidAmount();
        }, array_values($bids));

        for ($i = 1; $i < count($bid_amounts); $i++) {
            $this->assertGreaterThan($bid_amounts[$i - 1], $bid_amounts[$i]);
        }
    }

    /**
     * Test reserve price not met scenario
     *
     * Scenario: Highest bid doesn't meet reserve price
     *
     * @requirement REQ-CORE-002, REQ-CORE-006, REQ-CORE-007
     * @return void
     */
    public function test_reserve_price_not_met_workflow(): void
    {
        // Create scenario where reserve not met
        $scenario = $this->scenario_builder
            ->createReservePriceNotMet()
            ->getScenario();

        // Verify product configuration
        $product = $scenario->getProduct(1);
        $this->assertNotNull($product);
        $this->assertEquals(100.00, $product->getReservePrice());
        $this->assertEquals(50.00, $product->getCurrentHighestBid());

        // Verify reserve not met
        $this->assertFalse($product->isReserveMet());

        // Verify auction ended
        $this->assertTrue($product->isAuctionEnded());

        // High bid but under reserve = no winner
        $bids = $scenario->getBids();
        $winner_count = count(array_filter($bids, function($bid) {
            return $bid->getStatus() === 'winner';
        }));
        $this->assertEquals(0, $winner_count);
    }

    /**
     * Test last-minute sniping scenario
     *
     * Scenario: Early bidder outbid by last-second sniper
     *
     * @requirement REQ-CORE-003, REQ-CORE-006, REQ-CORE-007
     * @return void
     */
    public function test_last_minute_sniping_workflow(): void
    {
        // Create sniping scenario
        $scenario = $this->scenario_builder
            ->createLastMinuteSniping()
            ->getScenario();

        // Verify 2 bids created
        $bids = $scenario->getBids();
        $this->assertCount(2, $bids);

        // Get bids in order
        $bid1 = $scenario->getBid(1);
        $bid2 = $scenario->getBid(2);

        // Verify first bid outbid
        $this->assertEquals('outbid', $bid1->getStatus());

        // Verify second bid is winner
        $this->assertEquals('winner', $bid2->getStatus());

        // Verify second bid later than first
        $this->assertGreaterThan(
            $bid1->getBidTime(),
            $bid2->getBidTime()
        );
    }

    /**
     * Test bid increment cascade
     *
     * Scenario: Bid increments follow price-based ranges
     *
     * @requirement REQ-CORE-005, REQ-CORE-006
     * @return void
     */
    public function test_bid_increment_cascade(): void
    {
        // Create cascade scenario
        $scenario = $this->scenario_builder
            ->createBidIncrementCascade()
            ->getScenario();

        // Verify product configured with increments
        $product = $scenario->getProduct(1);
        $this->assertNotNull($product);

        // Verify increment ranges
        $increments = $product->getBidIncrements();
        $this->assertCount(3, $increments);

        // Verify bid amounts follow cascade pattern
        $bids = $scenario->getBids();
        $this->assertGreaterThan(0, count($bids));

        // Each bid should follow increment rule
        $bid_amounts = array_values(array_map(function($bid) {
            return $bid->getBidAmount();
        }, $bids));

        for ($i = 1; $i < count($bid_amounts); $i++) {
            $increment_diff = $bid_amounts[$i] - $bid_amounts[$i - 1];
            $this->assertGreaterThan(0, $increment_diff);
        }
    }

    /**
     * Test multiple concurrent auctions
     *
     * Scenario: Multiple auction products run simultaneously
     *
     * @requirement REQ-CORE-001, REQ-CORE-002, REQ-CORE-006
     * @return void
     */
    public function test_multiple_concurrent_auctions(): void
    {
        // Create multiple concurrent auctions
        $scenario = $this->scenario_builder
            ->createMultipleAuctions(5)
            ->getScenario();

        // Verify 5 products created
        $products = $scenario->getProducts();
        $this->assertCount(5, $products);

        // Verify each product independent
        for ($i = 1; $i <= 5; $i++) {
            $product = $scenario->getProduct($i);
            $this->assertNotNull($product);

            // Each product should have its own bid
            $product_bids = $scenario->getBidsForProduct($i);
            $this->assertCount(1, $product_bids);
        }

        // Verify total bid count = product count
        $all_bids = $scenario->getBids();
        $this->assertCount(5, $all_bids);
    }

    /**
     * Test scenario state queries
     *
     * Scenario: Verify scenario API provides correct state information
     *
     * @return void
     */
    public function test_scenario_state_queries(): void
    {
        // Create multi-auction scenario
        $scenario = $this->scenario_builder
            ->createMultipleAuctions(3)
            ->getScenario();

        // Test is multi-scenario
        $this->assertTrue($scenario->isMultiScenario());

        // Test product queries
        $this->assertNotNull($scenario->getProduct(1));
        $this->assertNotNull($scenario->getProduct(2));
        $this->assertNotNull($scenario->getProduct(3));
        $this->assertNull($scenario->getProduct(999));

        // Test bid queries
        $all_bids = $scenario->getBids();
        $this->assertIsArray($all_bids);
        $this->assertGreaterThan(0, count($all_bids));

        // Test product-specific bids
        $product_1_bids = $scenario->getBidsForProduct(1);
        $this->assertIsArray($product_1_bids);
        $this->assertCount(1, $product_1_bids);
    }

    /**
     * Test all scenario types can be created
     *
     * Scenario: Verify all scenario factory methods work correctly
     *
     * @requirements REQ-QUAL-001 (TDD compliance)
     * @return void
     */
    public function test_all_scenario_types_can_be_created(): void
    {
        $scenarios = [
            $this->scenario_builder->createSimpleAuction()->getScenario(),
            $this->scenario_builder->createCompetitiveAuction(3)->getScenario(),
            $this->scenario_builder->createLastMinuteSniping()->getScenario(),
            $this->scenario_builder->createReservePriceNotMet()->getScenario(),
            $this->scenario_builder->createBidIncrementCascade()->getScenario(),
            $this->scenario_builder->createMultipleAuctions(5)->getScenario(),
        ];

        $this->assertCount(6, $scenarios);

        // Verify each scenario has products
        foreach ($scenarios as $scenario) {
            $products = $scenario->getProducts();
            $this->assertGreaterThan(0, count($products));
        }
    }
}
