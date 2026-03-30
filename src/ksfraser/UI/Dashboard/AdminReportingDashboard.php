<?php
/**
 * Admin Reporting Dashboard - Displays settlement and performance metrics
 *
 * @package YITH_Auctions\UI\Dashboard
 * @subpackage Admin
 * @version 1.0.0
 * @requirement REQ-DASHBOARD-ADMIN-001-005 - Admin reporting dashboard
 * @covers-requirement REQ-DASHBOARD-ADMIN-001 - Settlement metrics display
 * @covers-requirement REQ-DASHBOARD-ADMIN-002 - Seller performance display
 * @covers-requirement REQ-DASHBOARD-ADMIN-003 - Revenue analysis display
 * @covers-requirement REQ-DASHBOARD-ADMIN-004 - Dispute statistics display
 * @covers-requirement REQ-DASHBOARD-ADMIN-005 - System health display
 */

namespace YITH_Auctions\UI\Dashboard;

use Ksfraser\HTML\HtmlElement;
use Ksfraser\HTML\Elements\Div;
use Ksfraser\HTML\Elements\H1;
use Ksfraser\HTML\Elements\H2;
use Ksfraser\HTML\Elements\H3;
use Ksfraser\HTML\Elements\P;
use Ksfraser\HTML\Elements\Table;
use Ksfraser\HTML\Elements\Thead;
use Ksfraser\HTML\Elements\Tbody;
use Ksfraser\HTML\Elements\Tr;
use Ksfraser\HTML\Elements\Th;
use Ksfraser\HTML\Elements\Td;
use Ksfraser\HTML\Elements\Span;
use YITH_Auctions\Services\Dashboard\DashboardDataService;

/**
 * Admin dashboard for auction system reporting and analytics
 *
 * Displays comprehensive metrics across 6 stat sections:
 * 1. Settlement Metrics - auction counts, success rates, GMV
 * 2. Seller Performance - top sellers, seller counts, trends
 * 3. Revenue Analysis - revenue breakdown, commission, refunds
 * 4. Dispute Statistics - open disputes, resolution rates
 * 5. System Health - API times, database performance, uptime
 *
 * Uses ksfraser/html library for WCAG 2.1 compliant output.
 * All metrics cached with 1-hour TTL for performance.
 *
 * @since 1.0.0
 */
class AdminReportingDashboard {
	/**
	 * Dashboard data service instance
	 *
	 * @var DashboardDataService
	 */
	private DashboardDataService $data_service;

	/**
	 * Constructor
	 *
	 * @param DashboardDataService $data_service Data aggregation service.
	 * @since 1.0.0
	 */
	public function __construct( DashboardDataService $data_service ) {
		$this->data_service = $data_service;
	}

	/**
	 * Render the complete dashboard
	 *
	 * Generates HTML using HtmlElement with all stat sections.
	 * Returns complete dashboard page.
	 *
	 * @return string Dashboard HTML.
	 * @covers-requirement REQ-DASHBOARD-ADMIN-001
	 * @covers-requirement REQ-DASHBOARD-ADMIN-002
	 * @covers-requirement REQ-DASHBOARD-ADMIN-003
	 * @covers-requirement REQ-DASHBOARD-ADMIN-004
	 * @covers-requirement REQ-DASHBOARD-ADMIN-005
	 * @since 1.0.0
	 */
	public function render(): string {
		$container = new Div();
		$container->setAttribute( 'class', 'yith-auction-admin-dashboard' );

		// Header
		$container->appendChild( $this->renderHeader() );

		// Settlement Metrics Section
		$container->appendChild( $this->renderSettlementMetrics() );

		// Seller Performance Section
		$container->appendChild( $this->renderSellerPerformance() );

		// Revenue Analysis Section
		$container->appendChild( $this->renderRevenueAnalysis() );

		// Dispute Statistics Section
		$container->appendChild( $this->renderDisputeStatistics() );

		// System Health Section
		$container->appendChild( $this->renderSystemHealth() );

		return $container->display();
	}

	/**
	 * Render dashboard header
	 *
	 * @return HtmlElement
	 */
	private function renderHeader(): HtmlElement {
		$header = new Div();
		$header->setAttribute( 'class', 'dashboard-header' );

		$title = new H1( 'Admin Reporting Dashboard' );
		$header->appendChild( $title );

		$desc = new P( 'Real-time metrics and analytics for auction system management' );
		$header->appendChild( $desc );

		return $header;
	}

	/**
	 * Render settlement metrics section
	 *
	 * @return HtmlElement
	 */
	private function renderSettlementMetrics(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section settlement-metrics' );

		$title = new H2( 'Settlement Metrics' );
		$section->appendChild( $title );

		$metrics = $this->data_service->getSettlementMetrics();

		$grid = new Div();
		$grid->setAttribute( 'class', 'metrics-grid' );

		// Stat card: Total Auctions
		$grid->appendChild(
			$this->renderStatCard(
				'Total Auctions (All Time)',
				number_format( $metrics['total_auctions_all_time'] ),
				'badge-primary'
			)
		);

		// Stat card: This Month
		$grid->appendChild(
			$this->renderStatCard(
				'Auctions This Month',
				number_format( $metrics['total_auctions_this_month'] ),
				'badge-success'
			)
		);

		// Stat card: Success Rate
		$grid->appendChild(
			$this->renderStatCard(
				'Settlement Success Rate',
				number_format( $metrics['success_rate_percent'], 2 ) . '%',
				'badge-info'
			)
		);

		// Stat card: Avg Settlement Time
		$grid->appendChild(
			$this->renderStatCard(
				'Average Settlement Time',
				number_format( $metrics['avg_settlement_time_days'], 1 ) . ' days',
				'badge-warning'
			)
		);

		// Stat card: Total GMV
		$grid->appendChild(
			$this->renderStatCard(
				'Total GMV',
				wc_price( $metrics['total_gmv'] ),
				'badge-secondary'
			)
		);

		// Stat card: Settlements Completed
		$grid->appendChild(
			$this->renderStatCard(
				'Settlements Completed',
				number_format( $metrics['total_settlements'] ),
				'badge-dark'
			)
		);

		$section->appendChild( $grid );

		return $section;
	}

	/**
	 * Render seller performance section
	 *
	 * @return HtmlElement
	 */
	private function renderSellerPerformance(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section seller-performance' );

		$title = new H2( 'Seller Performance' );
		$section->appendChild( $title );

		$performance = $this->data_service->getSellerPerformance();

		// Top Sellers Table
		$section->appendChild( $this->renderTopSellersTable( $performance['top_sellers'] ) );

		// Seller counts
		$grid = new Div();
		$grid->setAttribute( 'class', 'metrics-grid' );

		$grid->appendChild(
			$this->renderStatCard(
				'Avg Sales Per Seller',
				wc_price( $performance['avg_sales_per_seller'] ),
				'badge-info'
			)
		);

		$section->appendChild( $grid );

		return $section;
	}

	/**
	 * Render revenue analysis section
	 *
	 * @return HtmlElement
	 */
	private function renderRevenueAnalysis(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section revenue-analysis' );

		$title = new H2( 'Revenue Analysis' );
		$section->appendChild( $title );

		$analysis = $this->data_service->getRevenueAnalysis();

		$grid = new Div();
		$grid->setAttribute( 'class', 'metrics-grid' );

		$grid->appendChild(
			$this->renderStatCard(
				'Total Revenue',
				wc_price( $analysis['total_revenue'] ),
				'badge-primary'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Commission Revenue',
				wc_price( $analysis['commission_revenue'] ),
				'badge-success'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Refund Rate',
				number_format( $analysis['refund_rate_percent'], 2 ) . '%',
				'badge-warning'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Payment Volume',
				number_format( $analysis['payment_volume'] ),
				'badge-info'
			)
		);

		$section->appendChild( $grid );

		// Revenue by status table
		$section->appendChild( $this->renderRevenueByStatusTable( $analysis['breakdown_by_status'] ) );

		return $section;
	}

	/**
	 * Render dispute statistics section
	 *
	 * @return HtmlElement
	 */
	private function renderDisputeStatistics(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section dispute-statistics' );

		$title = new H2( 'Dispute Statistics' );
		$section->appendChild( $title );

		$stats = $this->data_service->getDisputeStatistics();

		$grid = new Div();
		$grid->setAttribute( 'class', 'metrics-grid' );

		$grid->appendChild(
			$this->renderStatCard(
				'Open Disputes',
				number_format( $stats['open_disputes'] ),
				'badge-danger'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Resolved This Month',
				number_format( $stats['resolved_this_month'] ),
				'badge-success'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Avg Resolution Time',
				number_format( $stats['avg_resolution_time_days'], 1 ) . ' days',
				'badge-info'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Success Rate',
				number_format( $stats['resolution_success_rate_percent'], 2 ) . '%',
				'badge-warning'
			)
		);

		$section->appendChild( $grid );

		return $section;
	}

	/**
	 * Render system health section
	 *
	 * @return HtmlElement
	 */
	private function renderSystemHealth(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section system-health' );

		$title = new H2( 'System Health' );
		$section->appendChild( $title );

		$health = $this->data_service->getSystemHealth();

		$grid = new Div();
		$grid->setAttribute( 'class', 'metrics-grid' );

		$grid->appendChild(
			$this->renderStatCard(
				'Uptime',
				number_format( $health['uptime_percent'], 2 ) . '%',
				'badge-success'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Avg API Response',
				number_format( $health['api_response_times']['avg_ms'], 1 ) . 'ms',
				'badge-info'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Avg Query Time',
				number_format( $health['database']['avg_query_time_ms'], 2 ) . 'ms',
				'badge-info'
			)
		);

		$grid->appendChild(
			$this->renderStatCard(
				'Payment Processor',
				ucfirst( $health['payment_processor_status'] ),
				'badge-success'
			)
		);

		$section->appendChild( $grid );

		return $section;
	}

	/**
	 * Render a metric stat card
	 *
	 * @param string $label Stat label.
	 * @param string $value Stat value.
	 * @param string $badge_class CSS class for badge styling.
	 * @return HtmlElement
	 */
	private function renderStatCard( string $label, string $value, string $badge_class = '' ): HtmlElement {
		$card = new Div();
		$card->setAttribute( 'class', 'stat-card' );

		$label_el = new P( $label );
		$label_el->setAttribute( 'class', 'stat-label' );
		$card->appendChild( $label_el );

		$value_el = new Span( $value );
		$value_el->setAttribute( 'class', "stat-value {$badge_class}" );
		$card->appendChild( $value_el );

		return $card;
	}

	/**
	 * Render top sellers table
	 *
	 * @param array $sellers Seller data.
	 * @return HtmlElement
	 */
	private function renderTopSellersTable( array $sellers ): HtmlElement {
		$container = new Div();
		$container->setAttribute( 'class', 'top-sellers-table-container' );

		$title = new H3( 'Top 10 Sellers by Revenue' );
		$container->appendChild( $title );

		$table = new Table();
		$table->setAttribute( 'class', 'wp-list-table widefat striped' );

		// Header
		$thead = new Thead();
		$tr = new Tr();
		$tr->appendChild( new Th( 'Seller ID' ) );
		$tr->appendChild( new Th( 'Sales Count' ) );
		$tr->appendChild( new Th( 'Revenue' ) );
		$thead->appendChild( $tr );
		$table->appendChild( $thead );

		// Body
		$tbody = new Tbody();
		foreach ( $sellers as $seller ) {
			$tr = new Tr();
			$tr->appendChild( new Td( (string) $seller->seller_id ) );
			$tr->appendChild( new Td( number_format( $seller->sales_count ) ) );
			$tr->appendChild( new Td( wc_price( $seller->revenue ) ) );
			$tbody->appendChild( $tr );
		}
		$table->appendChild( $tbody );

		$container->appendChild( $table );

		return $container;
	}

	/**
	 * Render revenue by status table
	 *
	 * @param array $breakdown Revenue breakdown.
	 * @return HtmlElement
	 */
	private function renderRevenueByStatusTable( array $breakdown ): HtmlElement {
		$container = new Div();
		$container->setAttribute( 'class', 'revenue-by-status-table-container' );

		$title = new H3( 'Revenue by Settlement Status' );
		$container->appendChild( $title );

		$table = new Table();
		$table->setAttribute( 'class', 'wp-list-table widefat striped' );

		// Header
		$thead = new Thead();
		$tr = new Tr();
		$tr->appendChild( new Th( 'Status' ) );
		$tr->appendChild( new Th( 'Revenue' ) );
		$thead->appendChild( $tr );
		$table->appendChild( $thead );

		// Body
		$tbody = new Tbody();
		foreach ( $breakdown as $item ) {
			$tr = new Tr();
			$tr->appendChild( new Td( ucfirst( (string) $item->status ) ) );
			$tr->appendChild( new Td( wc_price( $item->revenue ) ) );
			$tbody->appendChild( $tr );
		}
		$table->appendChild( $tbody );

		$container->appendChild( $table );

		return $container;
	}
}
