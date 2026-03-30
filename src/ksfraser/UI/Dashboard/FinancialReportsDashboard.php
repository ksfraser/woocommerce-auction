<?php
/**
 * Financial Reports Dashboard - Report generation and export interface
 *
 * @package YITH_Auctions\UI\Dashboard
 * @subpackage Admin
 * @version 1.0.0
 * @requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001 - Financial reports dashboard
 * @covers-requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001 - Report generation and export
 */

namespace YITH_Auctions\UI\Dashboard;

use Ksfraser\HTML\HtmlElement;
use Ksfraser\HTML\Elements\Div;
use Ksfraser\HTML\Elements\H1;
use Ksfraser\HTML\Elements\H2;
use Ksfraser\HTML\Elements\H3;
use Ksfraser\HTML\Elements\P;
use Ksfraser\HTML\Elements\Form;
use Ksfraser\HTML\Elements\Label;
use Ksfraser\HTML\Elements\Input;
use Ksfraser\HTML\Elements\Select;
use Ksfraser\HTML\Elements\Option;
use Ksfraser\HTML\Elements\Button;
use Ksfraser\HTML\Elements\Table;
use Ksfraser\HTML\Elements\Thead;
use Ksfraser\HTML\Elements\Tbody;
use Ksfraser\HTML\Elements\Tr;
use Ksfraser\HTML\Elements\Th;
use Ksfraser\HTML\Elements\Td;
use Ksfraser\HTML\Elements\Span;
use YITH_Auctions\Services\Dashboard\ReportGeneratorService;

/**
 * Financial reports dashboard for generating and downloading reports
 *
 * Provides admin interface to:
 * 1. Generate Reports - Settlement, Revenue, Seller Performance, Disputes
 * 2. Select Date Range - Custom date filtering
 * 3. Choose Format - CSV, Excel (fallback CSV), PDF (fallback CSV)
 * 4. Download Reports - Access previously generated reports
 * 5. Manage Reports - Delete old reports
 *
 * Uses ksfraser/html library for WCAG 2.1 compliant output.
 *
 * @since 1.0.0
 */
class FinancialReportsDashboard {
	/**
	 * Report generator service instance
	 *
	 * @var ReportGeneratorService
	 */
	private ReportGeneratorService $report_service;

	/**
	 * Constructor
	 *
	 * @param ReportGeneratorService $report_service Report generation service.
	 * @since 1.0.0
	 */
	public function __construct( ReportGeneratorService $report_service ) {
		$this->report_service = $report_service;
	}

	/**
	 * Render the complete dashboard
	 *
	 * @return string Dashboard HTML.
	 * @covers-requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001
	 * @since 1.0.0
	 */
	public function render(): string {
		$container = new Div();
		$container->setAttribute( 'class', 'yith-auction-financial-reports-dashboard' );

		// Header
		$container->appendChild( $this->renderHeader() );

		// Report Generation Section
		$container->appendChild( $this->renderReportGenerationForm() );

		// Available Reports Section
		$container->appendChild( $this->renderAvailableReports() );

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

		$title = new H1( 'Financial Reports' );
		$header->appendChild( $title );

		$desc = new P( 'Generate and download comprehensive financial reports for your auction system' );
		$header->appendChild( $desc );

		return $header;
	}

	/**
	 * Render report generation form section
	 *
	 * @return HtmlElement
	 */
	private function renderReportGenerationForm(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section report-generation' );

		$title = new H2( 'Generate Report' );
		$section->appendChild( $title );

		// Form
		$form = new Form();
		$form->setAttribute( 'method', 'post' );
		$form->setAttribute( 'action', admin_url( 'admin.php' ) );

		// Hidden fields for form submission
		$form->appendChild( $this->createHiddenInput( 'action', 'yith_auction_generate_report' ) );
		$form->appendChild( $this->createHiddenInput( 'nonce', wp_create_nonce( 'yith_auction_report_nonce' ) ) );

		// Form container for better layout
		$form_grid = new Div();
		$form_grid->setAttribute( 'class', 'report-form-grid' );

		// Report Type Field
		$form_grid->appendChild(
			$this->createFormGroup(
				'report_type',
				'Report Type',
				$this->createReportTypeSelect()
			)
		);

		// Date Range Fields
		$form_grid->appendChild(
			$this->createFormGroup(
				'date_from',
				'From Date',
				$this->createDateInput( 'date_from', gmdate( 'Y-m-d', strtotime( '-30 days' ) ) )
			)
		);

		$form_grid->appendChild(
			$this->createFormGroup(
				'date_to',
				'To Date',
				$this->createDateInput( 'date_to', gmdate( 'Y-m-d' ) )
			)
		);

		// Export Format Field
		$form_grid->appendChild(
			$this->createFormGroup(
				'format',
				'Export Format',
				$this->createFormatSelect()
			)
		);

		$form->appendChild( $form_grid );

		// Submit Button
		$button = new Button( 'Generate Report' );
		$button->setAttribute( 'type', 'submit' );
		$button->setAttribute( 'class', 'button button-primary' );
		$form->appendChild( $button );

		$section->appendChild( $form );

		return $section;
	}

	/**
	 * Render available reports section
	 *
	 * @return HtmlElement
	 */
	private function renderAvailableReports(): HtmlElement {
		$section = new Div();
		$section->setAttribute( 'class', 'dashboard-section available-reports' );

		$title = new H2( 'Available Reports' );
		$section->appendChild( $title );

		$reports = $this->report_service->getAvailableReports();

		if ( empty( $reports ) ) {
			$empty = new P( 'No reports generated yet.' );
			$empty->setAttribute( 'class', 'text-muted' );
			$section->appendChild( $empty );

			return $section;
		}

		$table = new Table();
		$table->setAttribute( 'class', 'wp-list-table widefat striped' );

		// Header
		$thead = new Thead();
		$tr = new Tr();
		$tr->appendChild( new Th( 'Filename' ) );
		$tr->appendChild( new Th( 'Size' ) );
		$tr->appendChild( new Th( 'Generated' ) );
		$tr->appendChild( new Th( 'Actions' ) );
		$thead->appendChild( $tr );
		$table->appendChild( $thead );

		// Body
		$tbody = new Tbody();
		foreach ( $reports as $report ) {
			$report_path = $this->report_service->getDownloadUrl( $report );
			$filepath = wp_upload_dir()['basedir'] . '/yith-auction-reports/' . $report;
			$file_size = filesize( $filepath );

			$tr = new Tr();
			$tr->appendChild( new Td( esc_html( $report ) ) );
			$tr->appendChild( new Td( size_format( $file_size ) ) );
			$tr->appendChild( new Td( wp_date( 'M d, Y H:i', filemtime( $filepath ) ) ) );

			// Actions
			$actions = new Div();
			$actions->setAttribute( 'class', 'report-actions' );

			// Download link
			$download_link = new \Ksfraser\HTML\Elements\A( 'Download' );
			$download_link->setAttribute( 'href', esc_url( $report_path ) );
			$download_link->setAttribute( 'class', 'button button-small' );
			$download_link->setAttribute( 'download', $report );
			$actions->appendChild( $download_link );

			// Delete button
			$delete_form = new Form();
			$delete_form->setAttribute( 'method', 'post' );
			$delete_form->setAttribute( 'action', admin_url( 'admin.php' ) );
			$delete_form->setAttribute( 'style', 'display: inline; margin-left: 5px;' );

			$delete_form->appendChild( $this->createHiddenInput( 'action', 'yith_auction_delete_report' ) );
			$delete_form->appendChild( $this->createHiddenInput( 'nonce', wp_create_nonce( 'yith_auction_report_nonce' ) ) );
			$delete_form->appendChild( $this->createHiddenInput( 'report_file', sanitize_file_name( $report ) ) );

			$delete_button = new Button( 'Delete' );
			$delete_button->setAttribute( 'type', 'submit' );
			$delete_button->setAttribute( 'class', 'button button-small button-link-delete' );
			$delete_button->setAttribute( 'onclick', 'return confirm("Are you sure?");' );
			$delete_form->appendChild( $delete_button );

			$actions->appendChild( $delete_form );

			$td = new Td();
			$td->appendChild( $actions );
			$tr->appendChild( $td );

			$tbody->appendChild( $tr );
		}
		$table->appendChild( $tbody );

		$section->appendChild( $table );

		return $section;
	}

	/**
	 * Create report type select element
	 *
	 * @return Select
	 */
	private function createReportTypeSelect(): Select {
		$select = new Select();
		$select->setAttribute( 'name', 'report_type' );
		$select->setAttribute( 'required', 'required' );

		$select->appendChild( new Option( 'Select Report Type', '', true, false ) );
		$select->appendChild( new Option( 'Settlements', 'settlements' ) );
		$select->appendChild( new Option( 'Revenue Analysis', 'revenue' ) );
		$select->appendChild( new Option( 'Seller Performance', 'sellers' ) );
		$select->appendChild( new Option( 'Disputes', 'disputes' ) );

		return $select;
	}

	/**
	 * Create export format select element
	 *
	 * @return Select
	 */
	private function createFormatSelect(): Select {
		$select = new Select();
		$select->setAttribute( 'name', 'format' );
		$select->setAttribute( 'required', 'required' );

		$select->appendChild( new Option( 'CSV', 'csv', true ) );
		$select->appendChild( new Option( 'Excel (XLSX)', 'excel' ) );
		$select->appendChild( new Option( 'PDF', 'pdf' ) );

		return $select;
	}

	/**
	 * Create date input element
	 *
	 * @param string $name Input name.
	 * @param string $value Default value.
	 * @return Input
	 */
	private function createDateInput( string $name, string $value ): Input {
		$input = new Input();
		$input->setAttribute( 'type', 'date' );
		$input->setAttribute( 'name', $name );
		$input->setAttribute( 'value', $value );
		$input->setAttribute( 'required', 'required' );

		return $input;
	}

	/**
	 * Create hidden input element
	 *
	 * @param string $name Input name.
	 * @param string $value Input value.
	 * @return Input
	 */
	private function createHiddenInput( string $name, string $value ): Input {
		$input = new Input();
		$input->setAttribute( 'type', 'hidden' );
		$input->setAttribute( 'name', $name );
		$input->setAttribute( 'value', $value );

		return $input;
	}

	/**
	 * Create form group (label + input wrapper)
	 *
	 * @param string      $id Element ID.
	 * @param string      $label_text Label text.
	 * @param HtmlElement $input Input element.
	 * @return Div
	 */
	private function createFormGroup( string $id, string $label_text, HtmlElement $input ): Div {
		$group = new Div();
		$group->setAttribute( 'class', 'form-group' );

		$label = new Label( $label_text );
		$label->setAttribute( 'for', $id );
		$group->appendChild( $label );

		$input->setAttribute( 'id', $id );
		$group->appendChild( $input );

		return $group;
	}
}
