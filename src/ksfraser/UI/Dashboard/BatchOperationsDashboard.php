<?php
/**
 * Batch Operations Dashboard - Displays and manages batch job processing
 *
 * @package YITH_Auctions\UI\Dashboard
 * @subpackage Admin
 * @version 1.0.0
 * @requirement REQ-DASHBOARD-BATCH-OPS-001 - Batch operations dashboard
 * @covers-requirement REQ-DASHBOARD-BATCH-OPS-001 - Job queue monitoring and management
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
use Ksfraser\HTML\Elements\Div as DivElement;
use YITH_Auctions\Services\Dashboard\BatchJobService;

/**
 * Batch operations dashboard for monitoring and managing batch jobs
 *
 * Displays:
 * 1. Job Queue Statistics - Pending, running, completed, failed counts
 * 2. Current/Recent Jobs - Status, progress, logs
 * 3. Job Management - Retry failed, cancel running, delete completed
 * 4. Historical Data - Job history with filtering and pagination
 *
 * Uses ksfraser/html library for WCAG 2.1 compliant output.
 *
 * @since 1.0.0
 */
class BatchOperationsDashboard {
	/**
	 * Batch job service instance
	 *
	 * @var BatchJobService
	 */
	private BatchJobService $batch_service;

	/**
	 * Constructor
	 *
	 * @param BatchJobService $batch_service Batch job management service.
	 * @since 1.0.0
	 */
	public function __construct( BatchJobService $batch_service ) {
		$this->batch_service = $batch_service;
	}

	/**
	 * Render the complete dashboard
	 *
	 * @return string Dashboard HTML.
	 * @covers-requirement REQ-DASHBOARD-BATCH-OPS-001
	 * @since 1.0.0
	 */
	public function render(): string {
		$container = new DivElement();
		$container->setAttribute( 'class', 'yith-auction-batch-operations-dashboard' );

		// Header
		$container->appendChild( $this->renderHeader() );

		// Statistics Section
		$container->appendChild( $this->renderStatistics() );

		// Job Queue Section
		$container->appendChild( $this->renderJobQueue() );

		// Running Jobs Section
		$container->appendChild( $this->renderRunningJobs() );

		// Job History Section
		$container->appendChild( $this->renderJobHistory() );

		return $container->display();
	}

	/**
	 * Render dashboard header
	 *
	 * @return HtmlElement
	 */
	private function renderHeader(): HtmlElement {
		$header = new DivElement();
		$header->setAttribute( 'class', 'dashboard-header' );

		$title = new H1( 'Batch Operations' );
		$header->appendChild( $title );

		$desc = new P( 'Monitor and manage batch job processing' );
		$header->appendChild( $desc );

		return $header;
	}

	/**
	 * Render statistics section
	 *
	 * @return HtmlElement
	 */
	private function renderStatistics(): HtmlElement {
		$section = new DivElement();
		$section->setAttribute( 'class', 'dashboard-section job-statistics' );

		$title = new H2( 'Job Queue Statistics' );
		$section->appendChild( $title );

		$stats = $this->batch_service->getStatistics();

		$grid = new DivElement();
		$grid->setAttribute( 'class', 'metrics-grid' );

		// Pending
		$grid->appendChild(
			$this->renderStatCard(
				'Pending Jobs',
				(string) $stats['pending'],
				'badge-warning'
			)
		);

		// Running
		$grid->appendChild(
			$this->renderStatCard(
				'Running Jobs',
				(string) $stats['running'],
				'badge-info'
			)
		);

		// Completed
		$grid->appendChild(
			$this->renderStatCard(
				'Completed Jobs',
				(string) $stats['completed'],
				'badge-success'
			)
		);

		// Failed
		$grid->appendChild(
			$this->renderStatCard(
				'Failed Jobs',
				(string) $stats['failed'],
				'badge-danger'
			)
		);

		// Total
		$grid->appendChild(
			$this->renderStatCard(
				'Total Jobs',
				(string) $stats['total'],
				'badge-secondary'
			)
		);

		$section->appendChild( $grid );

		return $section;
	}

	/**
	 * Render job queue section (pending jobs)
	 *
	 * @return HtmlElement
	 */
	private function renderJobQueue(): HtmlElement {
		$section = new DivElement();
		$section->setAttribute( 'class', 'dashboard-section job-queue' );

		$title = new H2( 'Pending Job Queue' );
		$section->appendChild( $title );

		$jobs = $this->batch_service->getPendingJobs( 20 );

		if ( empty( $jobs ) ) {
			$empty = new P( 'No pending jobs.' );
			$empty->setAttribute( 'class', 'text-muted' );
			$section->appendChild( $empty );

			return $section;
		}

		$table = new Table();
		$table->setAttribute( 'class', 'wp-list-table widefat striped' );

		// Header
		$thead = new Thead();
		$tr = new Tr();
		$tr->appendChild( new Th( 'ID' ) );
		$tr->appendChild( new Th( 'Type' ) );
		$tr->appendChild( new Th( 'Description' ) );
		$tr->appendChild( new Th( 'Scheduled For' ) );
		$tr->appendChild( new Th( 'Items' ) );
		$thead->appendChild( $tr );
		$table->appendChild( $thead );

		// Body
		$tbody = new Tbody();
		foreach ( $jobs as $job ) {
			$tr = new Tr();
			$tr->appendChild( new Td( "#" . (string) $job->id ) );
			$tr->appendChild( new Td( ucfirst( str_replace( '_', ' ', $job->job_type ) ) ) );
			$tr->appendChild( new Td( esc_html( (string) $job->description ) ) );
			$tr->appendChild( new Td( wp_date( 'M d, Y H:i', strtotime( $job->scheduled_for ) ) ) );
			$tr->appendChild( new Td( (string) $job->total_items ) );
			$tbody->appendChild( $tr );
		}
		$table->appendChild( $tbody );

		$section->appendChild( $table );

		return $section;
	}

	/**
	 * Render running jobs section
	 *
	 * @return HtmlElement
	 */
	private function renderRunningJobs(): HtmlElement {
		$section = new DivElement();
		$section->setAttribute( 'class', 'dashboard-section running-jobs' );

		$title = new H2( 'Running Jobs' );
		$section->appendChild( $title );

		$jobs = $this->batch_service->getJobs( 'running', 10, 0 );

		if ( empty( $jobs ) ) {
			$empty = new P( 'No running jobs.' );
			$empty->setAttribute( 'class', 'text-muted' );
			$section->appendChild( $empty );

			return $section;
		}

		foreach ( $jobs as $job ) {
			$job_div = new DivElement();
			$job_div->setAttribute( 'class', 'job-card' );

			// Job header
			$header = new DivElement();
			$header->setAttribute( 'class', 'job-card-header' );

			$job_title = new H3( "#" . $job->id . " - " . $job->description );
			$header->appendChild( $job_title );

			$job_div->appendChild( $header );

			// Progress bar
			$progress = $this->batch_service->getProgress( $job->id );
			$progress_div = new DivElement();
			$progress_div->setAttribute( 'class', 'progress' );

			$progress_bar = new DivElement();
			$progress_bar->setAttribute( 'class', 'progress-bar' );
			$progress_bar->setAttribute( 'style', "width: {$progress}%;" );
			$progress_bar->appendChild( new Span( "{$progress}%" ) );
			$progress_div->appendChild( $progress_bar );

			$job_div->appendChild( $progress_div );

			// Job stats
			$stats = new DivElement();
			$stats->setAttribute( 'class', 'job-stats' );

			$stats_p = new P(
				"Processed: " . $job->processed_items . " / " . $job->total_items .
				" | Failed: " . $job->failed_items
			);
			$stats->appendChild( $stats_p );

			$job_div->appendChild( $stats );

			// Logs
			if ( ! empty( $job->logs ) ) {
				$logs_div = new DivElement();
				$logs_div->setAttribute( 'class', 'job-logs' );

				$logs_title = new H3( 'Recent Logs' );
				$logs_div->appendChild( $logs_title );

				// Show last 5 log lines
				$log_lines = array_slice( explode( "\n", $job->logs ), -5 );
				$logs_pre = new \Ksfraser\HTML\Elements\Pre( implode( "\n", array_filter( $log_lines ) ) );
				$logs_pre->setAttribute( 'class', 'logs' );
				$logs_div->appendChild( $logs_pre );

				$job_div->appendChild( $logs_div );
			}

			$section->appendChild( $job_div );
		}

		return $section;
	}

	/**
	 * Render job history section
	 *
	 * @return HtmlElement
	 */
	private function renderJobHistory(): HtmlElement {
		$section = new DivElement();
		$section->setAttribute( 'class', 'dashboard-section job-history' );

		$title = new H2( 'Recent Job History' );
		$section->appendChild( $title );

		$jobs = $this->batch_service->getJobs( null, 50, 0 );

		if ( empty( $jobs ) ) {
			$empty = new P( 'No job history.' );
			$empty->setAttribute( 'class', 'text-muted' );
			$section->appendChild( $empty );

			return $section;
		}

		$table = new Table();
		$table->setAttribute( 'class', 'wp-list-table widefat striped' );

		// Header
		$thead = new Thead();
		$tr = new Tr();
		$tr->appendChild( new Th( 'ID' ) );
		$tr->appendChild( new Th( 'Type' ) );
		$tr->appendChild( new Th( 'Status' ) );
		$tr->appendChild( new Th( 'Progress' ) );
		$tr->appendChild( new Th( 'Created' ) );
		$tr->appendChild( new Th( 'Completed' ) );
		$tr->appendChild( new Th( 'Actions' ) );
		$thead->appendChild( $tr );
		$table->appendChild( $thead );

		// Body
		$tbody = new Tbody();
		foreach ( $jobs as $job ) {
			$tr = new Tr();
			$tr->appendChild( new Td( "#" . (string) $job->id ) );
			$tr->appendChild( new Td( ucfirst( str_replace( '_', ' ', $job->job_type ) ) ) );

			$status_span = new Span( ucfirst( $job->status ) );
			$status_class = match ( $job->status ) {
				'completed' => 'badge-success',
				'running' => 'badge-info',
				'pending' => 'badge-warning',
				'failed' => 'badge-danger',
				default => 'badge-secondary',
			};
			$status_span->setAttribute( 'class', "badge {$status_class}" );
			$td_status = new Td();
			$td_status->appendChild( $status_span );
			$tr->appendChild( $td_status );

			$progress = $this->batch_service->getProgress( $job->id );
			$tr->appendChild( new Td( "{$progress}%" ) );

			$tr->appendChild( new Td( wp_date( 'M d, Y H:i', strtotime( $job->created_at ) ) ) );
			$tr->appendChild( new Td( $job->completed_at ? wp_date( 'M d, Y H:i', strtotime( $job->completed_at ) ) : '—' ) );

			// Actions
			$actions = new DivElement();
			if ( 'failed' === $job->status ) {
				$retry_form = new \Ksfraser\HTML\Elements\Form();
				$retry_form->setAttribute( 'method', 'post' );
				$retry_form->setAttribute( 'style', 'display: inline;' );

				$input = new \Ksfraser\HTML\Elements\Input();
				$input->setAttribute( 'type', 'hidden' );
				$input->setAttribute( 'name', 'action' );
				$input->setAttribute( 'value', 'yith_auction_retry_job' );
				$retry_form->appendChild( $input );

				$input_id = new \Ksfraser\HTML\Elements\Input();
				$input_id->setAttribute( 'type', 'hidden' );
				$input_id->setAttribute( 'name', 'job_id' );
				$input_id->setAttribute( 'value', (string) $job->id );
				$retry_form->appendChild( $input_id );

				$button = new Button( 'Retry' );
				$button->setAttribute( 'type', 'submit' );
				$button->setAttribute( 'class', 'button button-small' );
				$retry_form->appendChild( $button );

				$actions->appendChild( $retry_form );
			}

			$td_actions = new Td();
			$td_actions->appendChild( $actions );
			$tr->appendChild( $td_actions );

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
		$card = new DivElement();
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
