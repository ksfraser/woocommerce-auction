<?php

namespace WC\Auction\UI\Dashboard;

use Ksfraser\HTML\HtmlElement;

/**
 * Settlement Dashboard Component
 * 
 * Displays settlement and payout information for sellers and admins.
 * Uses ksfraser/html library for HTML generation.
 * 
 * @requirement REQ-DASHBOARD-SETTLEMENT-001
 * @requirement REQ-DASHBOARD-SETTLEMENT-002
 * @requirement REQ-DASHBOARD-SETTLEMENT-003
 *
 * @package WC\Auction\UI\Dashboard
 * @since 1.4.0
 * @version 1.0.0
 * @author Kevin Fraser
 */
class SettlementDashboard
{
    /**
     * Render settlement dashboard with auction summary and payout status
     *
     * @param array $auctions List of auction data
     * @param array $settlements Settlement summary data
     * @param array $payouts Recent payout records
     * @return string HTML content for dashboard
     */
    public static function renderDashboard(array $auctions = [], array $settlements = [], array $payouts = []): string
    {
        /*
         * Requirement: REQ-DASHBOARD-SETTLEMENT-001 - Display Overview Cards
         * Requirement: REQ-DASHBOARD-SETTLEMENT-002 - Display Auction Table
         * Requirement: REQ-DASHBOARD-SETTLEMENT-003 - Display Payout History
         */

        $dashboard = (new HtmlElement('div'))
            ->addCSSClass('settlement-dashboard', 'mt-4')
            ->setAttribute('role', 'main')
            ->addNested(self::renderHeader())
            ->addNested(self::renderOverviewCards($settlements))
            ->addNested(self::renderAuctionTable($auctions))
            ->addNested(self::renderPayoutHistory($payouts));

        return $dashboard->getHtml();
    }

    /**
     * Render dashboard header
     *
     * @return HtmlElement Header section
     */
    private static function renderHeader(): HtmlElement
    {
        return (new HtmlElement('div'))
            ->addCSSClass('dashboard-header', 'mb-4')
            ->addNested(
                HtmlElement::heading('Settlement & Payout Dashboard', 1)
                    ->addCSSClass('mb-2')
            )
            ->addNested(
                HtmlElement::paragraph('Track your auctions, settlements, and payment history')
                    ->addCSSClass('text-muted')
            );
    }

    /**
     * Render overview stat cards
     *
     * @param array $settlements Settlement data (total_settled, pending, next_payout_date, etc.)
     * @return HtmlElement Container with stat cards
     */
    private static function renderOverviewCards(array $settlements): HtmlElement
    {
        $container = (new HtmlElement('div'))
            ->addCSSClass('row', 'mb-4')
            ->setAttribute('role', 'region')
            ->setAriaLabel('Settlement Overview');

        // Card 1: Total Settled
        $container->addNested(
            (new HtmlElement('div'))
                ->addCSSClass('col-md-3', 'mb-3')
                ->addNested(
                    self::statCard(
                        'Total Settled',
                        '$' . number_format($settlements['total_settled'] ?? 0, 2),
                        'success',
                        'chart-line'
                    )
                )
        );

        // Card 2: Pending Settlement
        $container->addNested(
            (new HtmlElement('div'))
                ->addCSSClass('col-md-3', 'mb-3')
                ->addNested(
                    self::statCard(
                        'Pending Settlement',
                        '$' . number_format($settlements['pending_settlement'] ?? 0, 2),
                        'warning',
                        'hourglass'
                    )
                )
        );

        // Card 3: Next Payout
        $next_payout = $settlements['next_payout_date'] ?? 'N/A';
        $container->addNested(
            (new HtmlElement('div'))
                ->addCSSClass('col-md-3', 'mb-3')
                ->addNested(
                    self::statCard(
                        'Next Payout',
                        $next_payout,
                        'info',
                        'calendar'
                    )
                )
        );

        // Card 4: Commission Earned
        $container->addNested(
            (new HtmlElement('div'))
                ->addCSSClass('col-md-3', 'mb-3')
                ->addNested(
                    self::statCard(
                        'Commission Earned',
                        '$' . number_format($settlements['commission_total'] ?? 0, 2),
                        'primary',
                        'credit-card'
                    )
                )
        );

        return $container;
    }

    /**
     * Create a stat card component
     *
     * @param string $title Card title
     * @param string $value Main value to display
     * @param string $color Bootstrap color (primary, success, danger, warning, info)
     * @param string $icon Icon name
     * @return HtmlElement Card element
     */
    private static function statCard(string $title, string $value, string $color = 'primary', string $icon = ''): HtmlElement
    {
        return HtmlElement::card()
            ->addCSSClass("border-$color")
            ->addNested(
                HtmlElement::cardBody()
                    ->displayFlex()
                    ->justifyContentBetween()
                    ->alignItemsCenter()
                    ->addNested(
                        (new HtmlElement('div'))
                            ->addNested(
                                (new HtmlElement('small'))
                                    ->addCSSClass('text-muted')
                                    ->addNested(new HtmlElement('span', $title))
                            )
                            ->addNested(
                                HtmlElement::heading($value, 3)
                                    ->addCSSClass("text-$color", 'mt-2', 'mb-0')
                            )
                    )
                    ->addNested(
                        (new HtmlElement('div'))
                            ->addCSSClass("text-$color", 'fs-1')
                            ->setAttribute('aria-hidden', 'true')
                            ->addNested(new HtmlElement('i', $icon))
                    )
            );
    }

    /**
     * Render auctions table
     *
     * @param array $auctions List of auction records
     * @return HtmlElement Table container
     */
    private static function renderAuctionTable(array $auctions): HtmlElement
    {
        return (new HtmlElement('div'))
            ->addCSSClass('card', 'mb-4')
            ->addNested(
                HtmlElement::cardHeader('Recent Auctions')
                    ->addCSSClass('bg-light')
            )
            ->addNested(
                HtmlElement::cardBody()
                    ->setPadding(0)
                    ->addNested(self::buildAuctionDataTable($auctions))
            );
    }

    /**
     * Build the HTML table for auctions
     *
     * @param array $auctions Auction data
     * @return HtmlElement Table element
     */
    private static function buildAuctionDataTable(array $auctions): HtmlElement
    {
        $table = HtmlElement::table()
            ->addCSSClass('table', 'table-hover', 'mb-0')
            ->setAriaLabel('Recent Auctions');

        // Table head
        $thead = new HtmlElement('thead');
        $thead_row = new HtmlElement('tr');
        $thead_row->addNested(new HtmlElement('th', 'Auction'));
        $thead_row->addNested(new HtmlElement('th', 'Item'));
        $thead_row->addNested(new HtmlElement('th', 'Final Price'));
        $thead_row->addNested(new HtmlElement('th', 'Status'));
        $thead_row->addNested(new HtmlElement('th', 'Action'));
        $thead->addNested($thead_row);
        $table->addNested($thead);

        // Table body
        $tbody = new HtmlElement('tbody');
        if (empty($auctions)) {
            $tbody->addNested(
                (new HtmlElement('tr'))
                    ->addNested(
                        (new HtmlElement('td'))
                            ->setAttribute('colspan', '5')
                            ->addCSSClass('text-center', 'text-muted')
                            ->addNested(new HtmlElement('span', 'No auctions found'))
                    )
            );
        } else {
            foreach ($auctions as $auction) {
                $tbody->addNested(self::buildAuctionRow($auction));
            }
        }
        $table->addNested($tbody);

        return $table;
    }

    /**
     * Build a single auction table row
     *
     * @param array $auction Auction data (id, name, final_price, status, etc.)
     * @return HtmlElement Table row
     */
    private static function buildAuctionRow(array $auction): HtmlElement
    {
        $row = new HtmlElement('tr');
        $row->addNested(new HtmlElement('td', '#' . ($auction['id'] ?? 'N/A')));
        $row->addNested(new HtmlElement('td', $auction['name'] ?? 'N/A'));
        $row->addNested(
            new HtmlElement('td', '$' . number_format($auction['final_price'] ?? 0, 2))
        );

        // Status badge
        $status = $auction['status'] ?? 'unknown';
        $status_badge = self::statusBadge($status);
        $row->addNested((new HtmlElement('td'))->addNested($status_badge));

        // Action button
        $row->addNested(
            (new HtmlElement('td'))
                ->addNested(
                    HtmlElement::buttonSmall('View')
                        ->addCSSClass('btn-outline-primary')
                        ->setAttribute('href', '#') // Would be actual URL in real code
                )
        );

        return $row;
    }

    /**
     * Create status badge component
     *
     * @param string $status Status value
     * @return HtmlElement Badge element
     */
    private static function statusBadge(string $status): HtmlElement
    {
        $badge_class = match($status) {
            'active' => 'bg-success',
            'pending' => 'bg-warning',
            'settled' => 'bg-info',
            'cancelled' => 'bg-danger',
            default => 'bg-secondary'
        };

        return HtmlElement::badge($status)
            ->addCSSClass($badge_class);
    }

    /**
     * Render payout history section
     *
     * @param array $payouts List of payout records
     * @return HtmlElement Payout history container
     */
    private static function renderPayoutHistory(array $payouts): HtmlElement
    {
        return (new HtmlElement('div'))
            ->addCSSClass('card')
            ->addNested(
                HtmlElement::cardHeader('Payout History')
                    ->addCSSClass('bg-light')
            )
            ->addNested(
                HtmlElement::cardBody()
                    ->addNested(self::buildPayoutTimeline($payouts))
            );
    }

    /**
     * Build payout history timeline
     *
     * @param array $payouts List of payout records
     * @return HtmlElement Timeline element
     */
    private static function buildPayoutTimeline(array $payouts): HtmlElement
    {
        $timeline = (new HtmlElement('div'))
            ->addCSSClass('timeline');

        if (empty($payouts)) {
            $timeline->addNested(
                HtmlElement::alert('No payouts yet', 'info')
            );
        } else {
            foreach ($payouts as $payout) {
                $timeline->addNested(self::timelineItem($payout));
            }
        }

        return $timeline;
    }

    /**
     * Create a single timeline item for payout record
     *
     * @param array $payout Payout data (date, amount, status, method)
     * @return HtmlElement Timeline item
     */
    private static function timelineItem(array $payout): HtmlElement
    {
        $date = $payout['date'] ?? 'N/A';
        $amount = '$' . number_format($payout['amount'] ?? 0, 2);
        $status = $payout['status'] ?? 'pending';
        $method = $payout['method'] ?? 'Bank Transfer';

        $status_class = match($status) {
            'completed' => 'success',
            'pending' => 'warning',
            'failed' => 'danger',
            default => 'secondary'
        };

        return (new HtmlElement('div'))
            ->addCSSClass('timeline-item', 'mb-3', 'pb-3', 'border-bottom')
            ->addNested(
                (new HtmlElement('div'))
                    ->displayFlex()
                    ->justifyContentBetween()
                    ->alignItemsStart()
                    ->addNested(
                        (new HtmlElement('div'))
                            ->addNested(
                                HtmlElement::heading($amount, 5)
                                    ->addCSSClass('mb-1')
                            )
                            ->addNested(
                                HtmlElement::paragraph($method)
                                    ->addCSSClass('text-muted', 'small', 'mb-0')
                            )
                    )
                    ->addNested(
                        (new HtmlElement('div'))
                            ->addNested(
                                HtmlElement::badge($status)
                                    ->addCSSClass("bg-$status_class")
                            )
                            ->addNested(
                                HtmlElement::paragraph($date)
                                    ->addCSSClass('text-muted', 'small', 'mb-0', 'mt-2')
                            )
                    )
            );
    }

    /**
     * Helper method to set padding on element
     * Used for card bodies
     *
     * @param int $padding Padding value (0-5 for Bootstrap)
     * @return $this
     */
    private function setPadding(int $padding): self
    {
        return $this->addCSSClass("p-$padding");
    }
}
