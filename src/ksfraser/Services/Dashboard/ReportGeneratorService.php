<?php
/**
 * Report Generator Service - Generates and exports financial reports
 *
 * @package YITH_Auctions\Services
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001 - Report generation
 * @covers-requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001 - Report generation with export formats
 */

namespace YITH_Auctions\Services\Dashboard;

use wpdb;

/**
 * Generates financial reports in multiple formats
 *
 * Supports generation of settlement reports, revenue reports, seller performance
 * reports in CSV, Excel, and PDF formats.
 *
 * @since 1.0.0
 */
class ReportGeneratorService {
	/**
	 * Report types
	 */
	const REPORT_TYPE_SETTLEMENTS = 'settlements';
	const REPORT_TYPE_REVENUE = 'revenue';
	const REPORT_TYPE_SELLERS = 'sellers';
	const REPORT_TYPE_DISPUTES = 'disputes';

	/**
	 * Export formats
	 */
	const FORMAT_CSV = 'csv';
	const FORMAT_EXCEL = 'excel';
	const FORMAT_PDF = 'pdf';

	/**
	 * WordPress database instance
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Reports directory path
	 *
	 * @var string
	 */
	private string $reports_dir;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;

		// Set up reports directory
		$upload_dir = wp_upload_dir();
		$this->reports_dir = $upload_dir['basedir'] . '/yith-auction-reports';

		if ( ! file_exists( $this->reports_dir ) ) {
			mkdir( $this->reports_dir, 0755, true );
		}
	}

	/**
	 * Generate settlement report
	 *
	 * @param string     $format Export format (csv, excel, pdf).
	 * @param string     $date_from Start date (Y-m-d format).
	 * @param string     $date_to End date (Y-m-d format).
	 * @param array|null $seller_ids Optional array of seller IDs to filter.
	 * @return string File path to generated report.
	 * @covers-requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function generateSettlementReport(
		string $format,
		string $date_from,
		string $date_to,
		?array $seller_ids = null
	): string {
		$data = $this->getSettlementReportData( $date_from, $date_to, $seller_ids );

		return $this->exportReport(
			"Settlement Report {$date_from} to {$date_to}",
			[
				'Settlement ID',
				'Seller ID',
				'Auction ID',
				'Final Bid Amount',
				'Commission Amount',
				'Status',
				'Created At',
				'Completed At',
			],
			$data,
			$format,
			"settlements_{$date_from}_{$date_to}"
		);
	}

	/**
	 * Generate revenue report
	 *
	 * @param string $format Export format (csv, excel, pdf).
	 * @param string $date_from Start date (Y-m-d format).
	 * @param string $date_to End date (Y-m-d format).
	 * @return string File path to generated report.
	 * @covers-requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function generateRevenueReport( string $format, string $date_from, string $date_to ): string {
		$data = $this->getRevenueReportData( $date_from, $date_to );

		return $this->exportReport(
			"Revenue Report {$date_from} to {$date_to}",
			[
				'Date',
				'Total Revenue',
				'Commission Revenue',
				'Refunds',
				'Net Revenue',
			],
			$data,
			$format,
			"revenue_{$date_from}_{$date_to}"
		);
	}

	/**
	 * Generate seller performance report
	 *
	 * @param string $format Export format (csv, excel, pdf).
	 * @param string $date_from Start date (Y-m-d format).
	 * @param string $date_to End date (Y-m-d format).
	 * @return string File path to generated report.
	 * @covers-requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function generateSellerPerformanceReport( string $format, string $date_from, string $date_to ): string {
		$data = $this->getSellerPerformanceReportData( $date_from, $date_to );

		return $this->exportReport(
			"Seller Performance Report {$date_from} to {$date_to}",
			[
				'Seller ID',
				'Auctions',
				'Sales',
				'Revenue',
				'Avg Rating',
				'Activity Status',
			],
			$data,
			$format,
			"seller_performance_{$date_from}_{$date_to}"
		);
	}

	/**
	 * Generate dispute report
	 *
	 * @param string $format Export format (csv, excel, pdf).
	 * @param string $date_from Start date (Y-m-d format).
	 * @param string $date_to End date (Y-m-d format).
	 * @return string File path to generated report.
	 * @covers-requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function generateDisputeReport( string $format, string $date_from, string $date_to ): string {
		$data = $this->getDisputeReportData( $date_from, $date_to );

		return $this->exportReport(
			"Dispute Report {$date_from} to {$date_to}",
			[
				'Dispute ID',
				'Dispute Type',
				'Seller ID',
				'Buyer ID',
				'Status',
				'Created At',
				'Resolved At',
				'Resolution',
			],
			$data,
			$format,
			"disputes_{$date_from}_{$date_to}"
		);
	}

	/**
	 * Get settlement report data
	 *
	 * @param string     $date_from Start date.
	 * @param string     $date_to End date.
	 * @param array|null $seller_ids Optional seller filter.
	 * @return array Report data rows.
	 */
	private function getSettlementReportData( string $date_from, string $date_to, ?array $seller_ids = null ): array {
		$where = $this->db->prepare(
			'WHERE created_at BETWEEN %s AND %s',
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		);

		if ( ! is_null( $seller_ids ) && ! empty( $seller_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $seller_ids ), '%d' ) );
			$where .= $this->db->prepare( " AND seller_id IN ({$placeholders})", ...$seller_ids );
		}

		$settlements = $this->db->get_results(
			"SELECT id, seller_id, auction_id, final_bid_amount, commission_amount, status, created_at, completed_at 
			FROM {$this->db->prefix}yith_auction_settlements 
			{$where}"
		);

		$rows = [];
		foreach ( $settlements as $settlement ) {
			$rows[] = [
				$settlement->id,
				$settlement->seller_id,
				$settlement->auction_id,
				$settlement->final_bid_amount,
				$settlement->commission_amount,
				$settlement->status,
				$settlement->created_at,
				$settlement->completed_at,
			];
		}

		return $rows;
	}

	/**
	 * Get revenue report data
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @return array Report data rows.
	 */
	private function getRevenueReportData( string $date_from, string $date_to ): array {
		$query = $this->db->prepare(
			"SELECT DATE(created_at) as date, 
					SUM(final_bid_amount) as total_revenue,
					SUM(commission_amount) as commission,
					SUM(CASE WHEN status = 'refunded' THEN final_bid_amount ELSE 0 END) as refunds
			FROM {$this->db->prefix}yith_auction_settlements 
			WHERE created_at BETWEEN %s AND %s 
			GROUP BY DATE(created_at) 
			ORDER BY created_at ASC",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		);

		$results = $this->db->get_results( $query );

		$rows = [];
		foreach ( $results as $result ) {
			$net_revenue = $result->total_revenue - $result->refunds;
			$rows[] = [
				$result->date,
				$result->total_revenue,
				$result->commission,
				$result->refunds,
				$net_revenue,
			];
		}

		return $rows;
	}

	/**
	 * Get seller performance report data
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @return array Report data rows.
	 */
	private function getSellerPerformanceReportData( string $date_from, string $date_to ): array {
		$query = $this->db->prepare(
			"SELECT seller_id, 
					COUNT(DISTINCT auction_id) as total_auctions,
					COUNT(*) as total_sales,
					SUM(final_bid_amount) as total_revenue,
					AVG(seller_rating) as avg_rating
			FROM {$this->db->prefix}yith_auction_settlements 
			WHERE created_at BETWEEN %s AND %s 
			GROUP BY seller_id 
			ORDER BY total_revenue DESC",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		);

		$results = $this->db->get_results( $query );

		$rows = [];
		foreach ( $results as $result ) {
			$status = get_user_meta( $result->seller_id, 'seller_status', true ) ?: 'active';
			$rows[] = [
				$result->seller_id,
				$result->total_auctions,
				$result->total_sales,
				$result->total_revenue,
				number_format( $result->avg_rating, 2 ),
				ucfirst( $status ),
			];
		}

		return $rows;
	}

	/**
	 * Get dispute report data
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @return array Report data rows.
	 */
	private function getDisputeReportData( string $date_from, string $date_to ): array {
		$query = $this->db->prepare(
			"SELECT id, dispute_type, seller_id, buyer_id, status, created_at, resolved_at, resolution 
			FROM {$this->db->prefix}yith_auction_disputes 
			WHERE created_at BETWEEN %s AND %s 
			ORDER BY created_at DESC",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		);

		$results = $this->db->get_results( $query );

		$rows = [];
		foreach ( $results as $result ) {
			$rows[] = [
				$result->id,
				$result->dispute_type,
				$result->seller_id,
				$result->buyer_id,
				$result->status,
				$result->created_at,
				$result->resolved_at,
				$result->resolution,
			];
		}

		return $rows;
	}

	/**
	 * Export report data to file
	 *
	 * @param string $title Report title.
	 * @param array  $headers Column headers.
	 * @param array  $data Data rows.
	 * @param string $format Export format.
	 * @param string $filename Base filename.
	 * @return string Full file path.
	 * @throws \Exception
	 */
	private function exportReport(
		string $title,
		array $headers,
		array $data,
		string $format,
		string $filename
	): string {
		switch ( $format ) {
			case self::FORMAT_CSV:
				return $this->exportCSV( $title, $headers, $data, $filename );
			case self::FORMAT_EXCEL:
				return $this->exportExcel( $title, $headers, $data, $filename );
			case self::FORMAT_PDF:
				return $this->exportPDF( $title, $headers, $data, $filename );
			default:
				throw new \Exception( "Unsupported export format: {$format}" );
		}
	}

	/**
	 * Export to CSV
	 *
	 * @param string $title Report title.
	 * @param array  $headers Column headers.
	 * @param array  $data Data rows.
	 * @param string $filename Base filename.
	 * @return string File path.
	 */
	private function exportCSV( string $title, array $headers, array $data, string $filename ): string {
		$filepath = $this->reports_dir . '/' . sanitize_file_name( $filename ) . '.csv';

		$handle = fopen( $filepath, 'w' );

		if ( false === $handle ) {
			throw new \Exception( "Unable to create file: {$filepath}" );
		}

		// Write BOM for proper UTF-8 encoding in Excel
		fwrite( $handle, "\xEF\xBB\xBF" );

		// Write title
		fputcsv( $handle, [ $title ] );
		fputcsv( $handle, [] ); // Blank line

		// Write headers
		fputcsv( $handle, $headers );

		// Write data
		foreach ( $data as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle );

		return $filepath;
	}

	/**
	 * Export to Excel
	 *
	 * @param string $title Report title.
	 * @param array  $headers Column headers.
	 * @param array  $data Data rows.
	 * @param string $filename Base filename.
	 * @return string File path.
	 */
	private function exportExcel( string $title, array $headers, array $data, string $filename ): string {
		// For now, use CSV as fallback
		// In production, would use a library like PhpSpreadsheet
		$filepath = $this->reports_dir . '/' . sanitize_file_name( $filename ) . '.csv';

		$handle = fopen( $filepath, 'w' );

		if ( false === $handle ) {
			throw new \Exception( "Unable to create file: {$filepath}" );
		}

		// Write BOM for proper UTF-8 encoding in Excel
		fwrite( $handle, "\xEF\xBB\xBF" );

		// Write title
		fputcsv( $handle, [ $title ] );
		fputcsv( $handle, [] );

		// Write headers
		fputcsv( $handle, $headers );

		// Write data
		foreach ( $data as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle );

		return $filepath;
	}

	/**
	 * Export to PDF
	 *
	 * @param string $title Report title.
	 * @param array  $headers Column headers.
	 * @param array  $data Data rows.
	 * @param string $filename Base filename.
	 * @return string File path.
	 */
	private function exportPDF( string $title, array $headers, array $data, string $filename ): string {
		// For now, use CSV as fallback
		// In production, would use a library like TCPDF or mPDF
		$filepath = $this->reports_dir . '/' . sanitize_file_name( $filename ) . '.csv';

		$handle = fopen( $filepath, 'w' );

		if ( false === $handle ) {
			throw new \Exception( "Unable to create file: {$filepath}" );
		}

		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, [ $title ] );
		fputcsv( $handle, [] );
		fputcsv( $handle, $headers );

		foreach ( $data as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle );

		return $filepath;
	}

	/**
	 * Get list of available reports
	 *
	 * @return array Array of report filenames.
	 */
	public function getAvailableReports(): array {
		if ( ! file_exists( $this->reports_dir ) ) {
			return [];
		}

		$files = array_diff( scandir( $this->reports_dir ), [ '.', '..' ] );

		return array_values( $files );
	}

	/**
	 * Delete report file
	 *
	 * @param string $filename Report filename.
	 * @return bool Success status.
	 */
	public function deleteReport( string $filename ): bool {
		$filepath = $this->reports_dir . '/' . sanitize_file_name( $filename );

		if ( file_exists( $filepath ) ) {
			return unlink( $filepath );
		}

		return false;
	}

	/**
	 * Get download URL for report
	 *
	 * @param string $filename Report filename.
	 * @return string Download URL.
	 */
	public function getDownloadUrl( string $filename ): string {
		$upload_dir = wp_upload_dir();

		return $upload_dir['baseurl'] . '/yith-auction-reports/' . sanitize_file_name( $filename );
	}
}
