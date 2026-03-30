<?php

namespace WC\Auction\Tests\UI\Dashboard;

use PHPUnit\Framework\TestCase;
use WC\Auction\UI\Dashboard\SettlementDashboard;

/**
 * Settlement Dashboard Tests
 *
 * Tests the HTML output and component rendering of the Settlement Dashboard.
 * Uses ksfraser/html library for element generation.
 *
 * @requirement REQ-DASHBOARD-SETTLEMENT-001: Display overview cards with settlement stats
 * @requirement REQ-DASHBOARD-SETTLEMENT-002: Display table with recent auctions
 * @requirement REQ-DASHBOARD-SETTLEMENT-003: Display payout history timeline
 *
 * @package WC\Auction\Tests\UI\Dashboard
 * @since 1.4.0
 */
class SettlementDashboardTest extends TestCase
{
    /**
     * Test that dashboard renders HTML string output
     *
     * @requirement REQ-DASHBOARD-SETTLEMENT-001
     * @test
     */
    public function testRenderDashboardReturnsHtmlString(): void
    {
        $html = SettlementDashboard::renderDashboard();

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('<div', $html);
    }

    /**
     * Test dashboard includes settlement overview cards
     *
     * @requirement REQ-DASHBOARD-SETTLEMENT-001
     * @test
     */
    public function testDashboardIncludesOverviewCards(): void
    {
        $settlements = [
            'total_settled' => 5000.00,
            'pending_settlement' => 1500.00,
            'next_payout_date' => '2026-04-15',
            'commission_total' => 250.00,
        ];

        $html = SettlementDashboard::renderDashboard([], $settlements);

        // Check for settlement values
        $this->assertStringContainsString('5000', $html);
        $this->assertStringContainsString('1500', $html);
        $this->assertStringContainsString('250', $html);

        // Check for card titles
        $this->assertStringContainsString('Total Settled', $html);
        $this->assertStringContainsString('Pending Settlement', $html);
        $this->assertStringContainsString('Commission Earned', $html);
    }

    /**
     * Test dashboard includes auction table when auctions provided
     *
     * @requirement REQ-DASHBOARD-SETTLEMENT-002
     * @test
     */
    public function testDashboardIncludesAuctionTable(): void
    {
        $auctions = [
            [
                'id' => '123',
                'name' => 'Vintage Watch',
                'final_price' => 450.00,
                'status' => 'settled',
            ],
            [
                'id' => '124',
                'name' => 'Antique Clock',
                'final_price' => 320.00,
                'status' => 'settled',
            ],
        ];

        $html = SettlementDashboard::renderDashboard($auctions);

        // Check for table structure
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead', $html);
        $this->assertStringContainsString('<tbody', $html);

        // Check for auction data
        $this->assertStringContainsString('Vintage Watch', $html);
        $this->assertStringContainsString('Antique Clock', $html);
        $this->assertStringContainsString('450', $html);
        $this->assertStringContainsString('320', $html);
    }

    /**
     * Test auction table shows "no auctions" message when empty
     *
     * @requirement REQ-DASHBOARD-SETTLEMENT-002
     * @test
     */
    public function testDashboardShowsNoAuctionsMessage(): void
    {
        $html = SettlementDashboard::renderDashboard([]);

        $this->assertStringContainsString('No auctions found', $html);
    }

    /**
     * Test dashboard includes payout history section
     *
     * @requirement REQ-DASHBOARD-SETTLEMENT-003
     * @test
     */
    public function testDashboardIncludesPayoutHistory(): void
    {
        $payouts = [
            [
                'date' => '2026-03-15',
                'amount' => 1200.00,
                'status' => 'completed',
                'method' => 'Bank Transfer',
            ],
            [
                'date' => '2026-02-15',
                'amount' => 950.00,
                'status' => 'completed',
                'method' => 'Bank Transfer',
            ],
        ];

        $html = SettlementDashboard::renderDashboard([], [], $payouts);

        // Check for payout history section
        $this->assertStringContainsString('Payout History', $html);

        // Check for payout data
        $this->assertStringContainsString('1200', $html);
        $this->assertStringContainsString('950', $html);
        $this->assertStringContainsString('2026-03-15', $html);
        $this->assertStringContainsString('completed', $html);
    }

    /**
     * Test dashboard header includes semantic HTML5 elements
     *
     * @test
     */
    public function testDashboardHeaderIncludesSemanticElements(): void
    {
        $html = SettlementDashboard::renderDashboard();

        // Check for semantic structure
        $this->assertStringContainsString('<main', $html);
        $this->assertStringContainsString('role="main"', $html);

        // Check for heading hierarchy
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Settlement & Payout Dashboard', $html);
    }

    /**
     * Test dashboard includes accessibility attributes
     *
     * @test
     */
    public function testDashboardIncludesAccessibilityAttributes(): void
    {
        $html = SettlementDashboard::renderDashboard();

        // Check for ARIA labels
        $this->assertStringContainsString('role="main"', $html);
        $this->assertStringContainsString('aria-', $html);
    }

    /**
     * Test dashboard includes Bootstrap CSS classes for styling
     *
     * @test
     */
    public function testDashboardIncludesBootstrapClasses(): void
    {
        $html = SettlementDashboard::renderDashboard();

        // Check for Bootstrap classes
        $this->assertStringContainsString('class="', $html);
        $this->assertStringContainsString('card', $html);
        $this->assertStringContainsString('table', $html);
        $this->assertStringContainsString('col-', $html);
    }

    /**
     * Test dashboard renders with empty data without errors
     *
     * @test
     */
    public function testDashboardRendersWithEmptyData(): void
    {
        $html = SettlementDashboard::renderDashboard([], [], []);

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Settlement & Payout Dashboard', $html);
        $this->assertStringContainsString('No auctions found', $html);
        $this->assertStringContainsString('No payouts yet', $html);
    }

    /**
     * Test dashboard renders with realistic data
     *
     * @test
     */
    public function testDashboardRendersWithRealisticData(): void
    {
        $settlements = [
            'total_settled' => 12500.85,
            'pending_settlement' => 3200.50,
            'next_payout_date' => '2026-04-15',
            'commission_total' => 625.04,
        ];

        $auctions = [
            [
                'id' => '1001',
                'name' => 'Vintage Rolex Watch',
                'final_price' => 2500.00,
                'status' => 'settled',
            ],
            [
                'id' => '1002',
                'name' => 'Antique Desk',
                'final_price' => 1850.00,
                'status' => 'active',
            ],
            [
                'id' => '1003',
                'name' => 'Signed Lithograph',
                'final_price' => 450.00,
                'status' => 'settled',
            ],
        ];

        $payouts = [
            [
                'date' => '2026-03-15',
                'amount' => 2500.00,
                'status' => 'completed',
                'method' => 'Bank Transfer',
            ],
            [
                'date' => '2026-02-15',
                'amount' => 1850.00,
                'status' => 'completed',
                'method' => 'Bank Transfer',
            ],
        ];

        $html = SettlementDashboard::renderDashboard($auctions, $settlements, $payouts);

        // Verify all data is present
        $this->assertStringContainsString('Settlement & Payout Dashboard', $html);
        $this->assertStringContainsString('12500', $html);
        $this->assertStringContainsString('Vintage Rolex Watch', $html);
        $this->assertStringContainsString('2500', $html);
        $this->assertStringContainsString('Antique Desk', $html);

        // Verify structure
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Payout History', $html);

        // Should not have any "No data" messages
        $this->assertStringNotContainsString('No auctions found', $html);
    }
}
