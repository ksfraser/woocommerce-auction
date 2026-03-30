<?php
/**
 * Seller Payout Dashboard - Displays settlement and payout information
 *
 * @package YITH_Auctions\UI\Dashboard
 * @subpackage Seller
 * @version 1.0.0
 * @requirement REQ-DASHBOARD-SELLER-PAYOUT-001-003 - Seller payout dashboard
 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-001 - Payout summary display
 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-002 - Transaction history display
 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-003 - Earnings display
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
use Ksfraser\HTML\Elements\Button;
use YITH_Auctions\Services\Dashboard\PayoutDataService;

/**
 * Seller payout dashboard for earnings and settlement management
 *
 * Displays 4 sections:
 * 1. Payout Summary - total earned, paid, pending, success rate
 * 2. Current Month Earnings - breakdown with fees and tax
 * 3. Transaction History - paginated payout transactions
 * 4. Annual Trends - monthly earnings chart data
 *
 * Accessed via shortcode [yith_auction_seller_payouts] with login check.
 * Admin can view any seller; sellers see only their data.
 *
 * Uses ksfraser/html library for WCAG 2.1 compliant output.
 *
 * @since 1.0.0
 */
class SellerPayoutDashboard {
	/**
	 * Payout data service instance
	 *
	 * @var PayoutDataService
	 */
	private PayoutDataService $data_service;

	/**
	 * Seller ID for data retrieval
	 *
	 * @var int|null
	 */
	private ?int $seller_id;

	/**
	 * Constructor
	 *
	 * @param PayoutDataService $data_service Payout data service.
	 * @param int|null          $seller_id Seller ID or null for current user.
	 * @since 1.0.0
	 */
	public function __construct( PayoutDataService $data_service, ?int $seller_id = null ) {
		$this->data_service = $data_service;
		$this->seller_id = $seller_id ?? get_current_user_id();
	}

	/**
	 * Render the complete dashboard
	 *
	 * Generates HTML using HtmlElement with all sections.
	 *
	 * @return string Dashboard HTML.
	 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-001
	 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-002
	 * @covers-requirement REQ-DASHBOARD-SELLER-PAYOUT-003
	 * @since 1.0.0
	 */
	public function render(): string {
		$container = new Div();
		$container->setAttribute( 'class', 'yith-auction-seller-payout-dashboard' );

		// Header
		$container->appendChild( $this->renderHeader() );

		// Payout Summary Section
		$container->appendChild( $this->renderPayoutSummary() );

		// Current Month Earnings Section
		$container->appendChild( $this->renderCurrentMonthEarnings() );

		// Transaction History Section
		$container->appendChild( $this->renderTransactionHistory() );

		// Annual Trends Section
		$container->appendChild( $this->renderAnnualTrends() );

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

		$title = new H1( 'Seller Payout Dashboard' );
		$header->appendChild( $title );

		$desc = new P( 'Manage your earnings, view transaction history, and track payouts' );
		$header->appendChild( $desc );

		return $header;
	}

	/**
	 * Render payout summary section
	 *
	 * @return HtmlElement
	 */
	private function renderPayoutSummary(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section payout-summary' );

		$title = new H2( 'Payout Summary' );
		$section->appendChild( $title );

		$summary = $this->data_service->getPayoutSummary( $this->seller_id );

		$grid = new Div();
		$grid->setAttribute( 'class', 'metrics-grid' );

		// Total Earned
		$grid->appendChild(
			$this->renderStatCard(
				'Total Earned',
				wc_price( $summary['total_earned'] ),
				'badge-primary'
			)
		);

		// Total Paid
		$grid->appendChild(
			$this->renderStatCard(
				'Total Paid',
				wc_price( $summary['total_paid'] ),
				'badge-success'
			)
		);

		// Pending Amount
		$grid->appendChild(
			$this->renderStatCard(
				'Pending Payouts',
				wc_price( $summary['pending_amount'] ),
				'badge-warning'
			)
		);

		// Success Rate
		$grid->appendChild(
			$this->renderStatCard(
				'Payout Success Rate',
				number_format( $summary['success_rate_percent'], 2 ) . '%',
				'badge-info'
			)
		);

		// Avg Payout Time
		$grid->appendChild(
			$this->renderStatCard(
				'Avg Processing Time',
				number_format( $summary['avg_payout_time_days'], 1 ) . ' days',
				'badge-secondary'
			)
		);

		// Next Payout Date
		$grid->appendChild(
			$this->renderStatCard(
				'Next Payout Date',
				$summary['next_payout_date'] ?? 'Not scheduled',
				'badge-dark'
			)
		);

		$section->appendChild( $grid );

		return $section;
	}

	/**
	 * Render current month earnings section
	 *
	 * @return HtmlElement
	 */
	private function renderCurrentMonthEarnings(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section current-month-earnings' );

		$title = new H2( 'Current Month Earnings' );
		$section->appendChild( $title );

		$earnings = $this->data_service->getCurrentMonthEarnings( $this->seller_id );

		$grid = new Div();
		$grid->setAttribute( 'class', 'metrics-grid' );

		// Gross Earnings
		$grid->appendChild(
			$this->renderStatCard(
				'Gross Earnings',
				wc_price( $earnings['gross_earnings'] ),
				'badge-primary'
			)
		);

		// Platform Fees
		$grid->appendChild(
			$this->renderStatCard(
				'Platform Fees',
				'−' . wc_price( $earnings['platform_fees'] ),
				'badge-danger'
			)
		);

		// Net Earnings
		$grid->appendChild(
			$this->renderStatCard(
				'Net Earnings',
				wc_price( $earnings['net_earnings'] ),
				'badge-secondary'
			)
		);

		// Tax Withholding
		if ( $earnings['tax_withholding'] > 0 ) {
			$grid->appendChild(
				$this->renderStatCard(
					'Tax Withholding',
					'−' . wc_price( $earnings['tax_withholding'] ),
					'badge-warning'
				)
			);
		}

		// Available for Payout
		$grid->appendChild(
			$this->renderStatCard(
				'Available for Payout',
				wc_price( $earnings['available_for_payout'] ),
				'badge-success'
			)
		);

		$section->appendChild( $grid );

		return $section;
	}

	/**
	 * Render transaction history section
	 *
	 * @return HtmlElement
	 */
	private function renderTransactionHistory(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section transaction-history' );

		$title = new H2( 'Transaction History' );
		$section->appendChild( $title );

		$transactions = $this->data_service->getPayoutHistory( $this->seller_id, 20, 0 );

		if ( empty( $transactions ) ) {
			$empty = new P( 'No transactions yet.' );
			$empty->setAttribute( 'class', 'text-muted' );
			$section->appendChild( $empty );

			return $section;
		}

		$table = new Table();
		$table->setAttribute( 'class', 'wp-list-table widefat striped' );

		// Header
		$thead = new Thead();
		$tr = new Tr();
		$tr->appendChild( new Th( 'Date' ) );
		$tr->appendChild( new Th( 'Amount' ) );
		$tr->appendChild( new Th( 'Status' ) );
		$tr->appendChild( new Th( 'Method' ) );
		$tr->appendChild( new Th( 'Payout Date' ) );
		$thead->appendChild( $tr );
		$table->appendChild( $thead );

		// Body
		$tbody = new Tbody();
		foreach ( $transactions as $transaction ) {
			$tr = new Tr();
			$tr->appendChild( new Td( wp_date( 'M d, Y', strtotime( $transaction->created_at ) ) ) );
			$tr->appendChild( new Td( wc_price( $transaction->payout_amount ) ) );

			$status_span = new Span( ucfirst( $transaction->status ) );
			$status_class = match ( $transaction->status ) {
				'completed' => 'badge-success',
				'pending' => 'badge-warning',
				'failed' => 'badge-danger',
				default => 'badge-secondary',
			};
			$status_span->setAttribute( 'class', "badge {$status_class}" );
			$td_status = new Td();
			$td_status->appendChild( $status_span );
			$tr->appendChild( $td_status );

			$tr->appendChild( new Td( ucfirst( str_replace( '_', ' ', $transaction->payment_method ) ) ) );
			$tr->appendChild( new Td( $transaction->payout_date ? wp_date( 'M d, Y', strtotime( $transaction->payout_date ) ) : '—' ) );

			$tbody->appendChild( $tr );
		}
		$table->appendChild( $tbody );

		$section->appendChild( $table );

		return $section;
	}

	/**
	 * Render annual trends section
	 *
	 * @return HtmlElement
	 */
	private function renderAnnualTrends(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section annual-trends' );

		$title = new H2( 'Annual Earnings Trend' );
		$section->appendChild( $title );

		$earnings = $this->data_service->getAnnualEarningsSummary( $this->seller_id );

		// Chart container (data will be used by JavaScript to render chart)
		$chart_div = new Div();
		$chart_div->setAttribute( 'id', 'earnings-chart' );
		$chart_div->setAttribute( 'class', 'earnings-chart' );
		$chart_div->setAttribute( 'data-earnings', wp_json_encode( $earnings ) );
		$section->appendChild( $chart_div );

		// Fallback: Display data in table format for non-JS users
		$table = new Table();
		$table->setAttribute( 'class', 'wp-list-table widefat' );

		$thead = new Thead();
		$tr = new Tr();
		$tr->appendChild( new Th( 'Month' ) );
		$tr->appendChild( new Th( 'Earnings' ) );
		$thead->appendChild( $tr );
		$table->appendChild( $thead );

		$tbody = new Tbody();
		$months = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
		foreach ( $earnings as $month => $amount ) {
			$month_idx = (int) $month - 1;
			$tr = new Tr();
			$tr->appendChild( new Td( $months[ $month_idx ] ?? $month ) );
			$tr->appendChild( new Td( wc_price( $amount ) ) );
			$tbody->appendChild( $tr );
		}
		$table->appendChild( $tbody );

		$section->appendChild( $table );

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
}
