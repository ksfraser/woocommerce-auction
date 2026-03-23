<?php

namespace Tests\Unit\Services;

use Tests\BaseUnitTest;
use Yith\Auctions\Services\EntryFees\CommissionCalculator;
use Yith\Auctions\ValueObjects\Money;

/**
 * CommissionCalculatorTest - Unit tests for commission calculations.
 *
 * @package Tests\Unit\Services
 * @requirement REQ-ENTRY-FEE-CALCULATOR-001: Entry fee calculations
 * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001: FVF calculations
 */
class CommissionCalculatorTest extends BaseUnitTest
{
    /**
     * @var CommissionCalculator Calculator under test
     */
    private CommissionCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CommissionCalculator();
    }

    /**
     * Test: Calculate entry fee for small auction.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-CALCULATOR-001
     */
    public function testCalculateEntryFee_SmallAuction(): void
    {
        $result = $this->calculator->calculateEntryFee('50.00');

        $this->assertEquals(10.00, $result->asFloat());
    }

    /**
     * Test: Calculate entry fee for medium auction.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-CALCULATOR-001
     */
    public function testCalculateEntryFee_MediumAuction(): void
    {
        $result = $this->calculator->calculateEntryFee('250.00');

        $this->assertEquals(25.00, $result->asFloat());
    }

    /**
     * Test: Calculate entry fee for large auction.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-CALCULATOR-001
     */
    public function testCalculateEntryFee_LargeAuction(): void
    {
        $result = $this->calculator->calculateEntryFee('1000.00');

        $this->assertEquals(50.00, $result->asFloat());
    }

    /**
     * Test: Calculate FVF for small winning bid.
     *
     * @test
     * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001
     */
    public function testCalculateFVF_SmallBid(): void
    {
        $result = $this->calculator->calculateFinalValueFee('100.00');

        // 12% FVF for $0-500
        $this->assertEquals(12.00, $result->asFloat());
    }

    /**
     * Test: Calculate FVF for medium winning bid.
     *
     * @test
     * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001
     */
    public function testCalculateFVF_MediumBid(): void
    {
        $result = $this->calculator->calculateFinalValueFee('750.00');

        // 10% FVF for $500-1000
        $this->assertEquals(75.00, $result->asFloat());
    }

    /**
     * Test: Calculate FVF for large winning bid.
     *
     * @test
     * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001
     */
    public function testCalculateFVF_LargeBid(): void
    {
        $result = $this->calculator->calculateFinalValueFee('3000.00');

        // 8% FVF for $1000-5000
        $this->assertEquals(240.00, $result->asFloat());
    }

    /**
     * Test: Calculate FVF for very large winning bid.
     *
     * @test
     * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001
     */
    public function testCalculateFVF_VeryLargeBid(): void
    {
        $result = $this->calculator->calculateFinalValueFee('10000.00');

        // 5% FVF for $5000+
        $this->assertEquals(500.00, $result->asFloat());
    }

    /**
     * Test: Calculate Stripe processor fee.
     *
     * @test
     * @requirement REQ-PAYMENT-PROCESSOR-FEE-001
     */
    public function testCalculateProcessorFee_Stripe(): void
    {
        $result = $this->calculator->calculateProcessorFee('100.00', 'stripe');

        // 2.9% + $0.30 = $2.90 + $0.30 = $3.20
        $this->assertEquals(3.20, $result->asFloat());
    }

    /**
     * Test: Calculate PayPal processor fee.
     *
     * @test
     * @requirement REQ-PAYMENT-PROCESSOR-FEE-001
     */
    public function testCalculateProcessorFee_PayPal(): void
    {
        $result = $this->calculator->calculateProcessorFee('100.00', 'paypal');

        // 2.2% + $0.30 = $2.20 + $0.30 = $2.50
        $this->assertEquals(2.50, $result->asFloat());
    }

    /**
     * Test: Calculate processor fee with invalid method.
     *
     * @test
     * @requirement REQ-PAYMENT-PROCESSOR-FEE-001
     */
    public function testCalculateProcessorFee_InvalidMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculateProcessorFee('100.00', 'invalid_method');
    }

    /**
     * Test: Generate complete commission breakdown.
     *
     * @test
     * @requirement REQ-COMMISSION-BREAKDOWN-001
     */
    public function testGenerateCommissionBreakdown_Success(): void
    {
        $breakdown = $this->calculator->generateCommissionBreakdown('100.00', 'stripe');

        // Verify structure
        $this->assertArrayHasKey('summary', $breakdown);
        $this->assertArrayHasKey('itemized', $breakdown);
        $this->assertArrayHasKey('customer_breakdown', $breakdown);

        // Verify calculations
        $summary = $breakdown['summary'];
        $this->assertEquals(100.00, $summary['hammer_price']);
        $this->assertEquals(12.00, $summary['final_value_fee']);
        $this->assertEquals(3.20, $summary['processor_fee']);
        $this->assertEquals(115.20, $summary['winner_total']);
        $this->assertEquals(88.00, $summary['seller_proceeds']);
    }

    /**
     * Test: Commission breakdown with different tiers.
     *
     * @test
     * @requirement REQ-COMMISSION-BREAKDOWN-001
     */
    public function testGenerateCommissionBreakdown_DifferentTiers(): void
    {
        // Test $750 bid (10% FVF tier)
        $breakdown = $this->calculator->generateCommissionBreakdown('750.00', 'paypal');

        $summary = $breakdown['summary'];
        $this->assertEquals(750.00, $summary['hammer_price']);
        $this->assertEquals(75.00, $summary['final_value_fee']);  // 10%
        $this->assertEquals(2.50 + (750 * 0.022), $summary['processor_fee']);
        $this->assertEquals(750.00 - 75.00, $summary['seller_proceeds']);
    }

    /**
     * Test: Get entry fee tiers.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-CALCULATOR-001
     */
    public function testGetEntryFeeTiers_Success(): void
    {
        $tiers = $this->calculator->getEntryFeeTiers();

        $this->assertIsArray($tiers);
        $this->assertNotEmpty($tiers);
    }

    /**
     * Test: Get FVF tiers.
     *
     * @test
     * @requirement REQ-FINAL-VALUE-FEE-CALCULATOR-001
     */
    public function testGetFVFTiers_Success(): void
    {
        $tiers = $this->calculator->getFVFTiers();

        $this->assertIsArray($tiers);
        $this->assertNotEmpty($tiers);
    }

    /**
     * Test: Get processor fee configurations.
     *
     * @test
     * @requirement REQ-PAYMENT-PROCESSOR-FEE-001
     */
    public function testGetProcessorFees_Success(): void
    {
        $fees = $this->calculator->getProcessorFees();

        $this->assertArrayHasKey('stripe', $fees);
        $this->assertArrayHasKey('paypal', $fees);
        $this->assertArrayHasKey('credit_card', $fees);
    }

    /**
     * Test: Set passthrough processor fees.
     *
     * @test
     */
    public function testSetPassthroughProcessorFees(): void
    {
        $this->assertFalse($this->calculator->getPassthroughProcessorFees());

        $this->calculator->setPassthroughProcessorFees(true);

        $this->assertTrue($this->calculator->getPassthroughProcessorFees());
    }

    /**
     * Test: Entry fee calculation with invalid amount.
     *
     * @test
     */
    public function testCalculateEntryFee_InvalidDecimal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculateEntryFee('100.999');
    }

    /**
     * Test: Entry fee calculation with negative amount.
     *
     * @test
     */
    public function testCalculateEntryFee_NegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculateEntryFee('-50.00');
    }

    /**
     * Test: Edge case - exact tier boundary.
     *
     * @test
     */
    public function testCalculateEntryFee_ExactBoundary(): void
    {
        // At $100 boundary - should use $100 tier
        $result = $this->calculator->calculateEntryFee('100.00');

        $this->assertEquals(10.00, $result->asFloat());
    }

    /**
     * Test: Edge case - just above tier boundary.
     *
     * @test
     */
    public function testCalculateEntryFee_JustAboveBoundary(): void
    {
        // Just above $100 - should use $500 tier
        $result = $this->calculator->calculateEntryFee('100.01');

        $this->assertEquals(25.00, $result->asFloat());
    }
}
