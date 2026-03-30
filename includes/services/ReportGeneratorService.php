<?php
/**
 * Report Generation Service
 *
 * @package YITH_Auctions\Services
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-4E-005
 */

namespace YITH_Auctions\Services;

use YITH_Auctions\Models\ReportData;
use YITH_Auctions\Repositories\SellerPayoutRepository;
use YITH_Auctions\Repositories\SettlementBatchRepository;
use YITH_Auctions\Repositories\CommissionRepository;

/**
 * Service for generating comprehensive settlement and payout reports
 *
 * Aggregates payout and settlement data for reporting periods,
 * computes statistics, and exports data in multiple formats.
 * Supports seller, admin, and reconciliation reports.
 *
 * @requirement REQ-4E-005 - Report generation for settlement/payout data
 */
class ReportGeneratorService {
	/**
	 * Payout repository
	 *
	 * @var SellerPayoutRepository
	 */
	private SellerPayoutRepository $payout_repository;

	/**
	 * Batch repository
	 *
	 * @var SettlementBatchRepository
	 */
	private SettlementBatchRepository $batch_repository;

	/**
	 * Commission repository
	 *
	 * @var CommissionRepository
	 */
	private CommissionRepository $commission_repository;

	/**
	 * Constructor
	 *
	 * @param SellerPayoutRepository    $payout_repository Payout data access.
	 * @param SettlementBatchRepository $batch_repository Batch data access.
	 * @param CommissionRepository      $commission_repository Commission data access.
	 * @since 1.0.0
	 */
	public function __construct(
		SellerPayoutRepository $payout_repository,
		SettlementBatchRepository $batch_repository,
		CommissionRepository $commission_repository
	) {
		$this->payout_repository = $payout_repository;
		$this->batch_repository = $batch_repository;
		$this->commission_repository = $commission_repository;
	}

	/**
	 * Generate settlement report
	 *
	 * @param \DateTime $start_date Report period start.
	 * @param \DateTime $end_date Report period end.
	 * @return ReportData Settlement report data.
	 * @throws \Exception If generation fails.
	 * @requirement REQ-4E-005
	 * @since 1.0.0
	 */
	public function generateSettlementReport(
		\DateTime $start_date,
		\DateTime $end_date
	): ReportData {
		$report_data = $this->payout_repository->getReportData(
			$start_date,
			$end_date,
			'settlement'
		);

		return new ReportData(
			'settlement',
			$start_date,
			$end_date,
			(int) $report_data['total_payouts'],
			(int) $report_data['total_amount'],
			(int) $report_data['commissions'],
			(int) $report_data['successful_payouts'],
			(int) $report_data['failed_payouts'],
			(float) $report_data['success_rate'],
			$report_data['processor_breakdown'] ?? [],
			$report_data['seller_breakdown'] ?? [],
			new \DateTime()
		);
	}

	/**
	 * Generate seller-specific report
	 *
	 * @param int     $seller_id Seller identifier.
	 * @param \DateTime $start_date Report period start.
	 * @param \DateTime $end_date Report period end.
	 * @return ReportData Seller report data.
	 * @throws \Exception If generation fails.
	 * @requirement REQ-4E-005
	 * @since 1.0.0
	 */
	public function generateSellerReport(
		int $seller_id,
		\DateTime $start_date,
		\DateTime $end_date
	): ReportData {
		$report_data = $this->payout_repository->getReportData(
			$start_date,
			$end_date,
			'seller',
			$seller_id
		);

		return new ReportData(
			'seller',
			$start_date,
			$end_date,
			(int) $report_data['total_payouts'],
			(int) $report_data['total_amount'],
			(int) $report_data['commissions'],
			(int) $report_data['successful_payouts'],
			(int) $report_data['failed_payouts'],
			(float) $report_data['success_rate'],
			$report_data['processor_breakdown'] ?? [],
			[],
			new \DateTime()
		);
	}

	/**
	 * Generate commission report
	 *
	 * @param \DateTime $start_date Report period start.
	 * @param \DateTime $end_date Report period end.
	 * @return ReportData Commission report data.
	 * @throws \Exception If generation fails.
	 * @requirement REQ-4E-005
	 * @since 1.0.0
	 */
	public function generateCommissionReport(
		\DateTime $start_date,
		\DateTime $end_date
	): ReportData {
		$commission_data = $this->commission_repository->getReportData(
			$start_date,
			$end_date
		);

		$payout_data = $this->payout_repository->getReportData(
			$start_date,
			$end_date,
			'settlement'
		);

		return new ReportData(
			'commission',
			$start_date,
			$end_date,
			(int) $commission_data['total_commissions'],
			(int) $commission_data['total_commission_amount'],
			(int) $commission_data['total_commission_amount'],
			(int) $commission_data['processed_count'],
			(int) $commission_data['failed_count'],
			(float) $commission_data['success_rate'],
			$commission_data['breakdown_by_processor'] ?? [],
			$commission_data['breakdown_by_seller'] ?? [],
			new \DateTime()
		);
	}

	/**
	 * Export report to CSV format
	 *
	 * @param ReportData $report Report data to export.
	 * @return string CSV formatted report content.
	 * @requirement REQ-4E-005
	 * @since 1.0.0
	 */
	public function exportToCSV( ReportData $report ): string {
		$csv = "Report Type,{$report->report_type}\n";
		$csv .= "Date Range,\"{$report->getDateRange()}\"\n";
		$csv .= "Generated,{$report->generated_at->format( 'Y-m-d H:i:s' )}\n\n";

		$csv .= "Summary\n";
		$csv .= "Total Payouts,{$report->total_payouts}\n";
		$csv .= "Total Amount,\"" . number_format( $report->total_amount / 100, 2 ) . "\"\n";
		$csv .= "Total Commissions,\"" . number_format( $report->total_commissions / 100, 2 ) . "\"\n";
		$csv .= "Successful,{$report->successful_payouts}\n";
		$csv .= "Failed,{$report->failed_payouts}\n";
		$csv .= "Success Rate,{$report->success_rate}%\n\n";

		// Processor breakdown
		if ( ! empty( $report->processor_breakdown ) ) {
			$csv .= "Processor Breakdown\n";
			$csv .= "Processor,Count,Amount,Success Rate\n";

			foreach ( $report->processor_breakdown as $processor => $data ) {
				$amount = number_format( $data['amount'] / 100, 2 );
				$rate = $data['success_rate'] ?? 0;
				$csv .= "\"{$processor}\",{$data['count']},\"{$amount}\",{$rate}%\n";
			}
			$csv .= "\n";
		}

		// Seller breakdown
		if ( ! empty( $report->seller_breakdown ) ) {
			$csv .= "Seller Breakdown\n";
			$csv .= "Seller ID,Payouts,Amount,Successful,Failed\n";

			foreach ( $report->seller_breakdown as $seller_id => $data ) {
				$amount = number_format( $data['amount'] / 100, 2 );
				$csv .= "{$seller_id},{$data['total']},\"{$amount}\",{$data['successful']},{$data['failed']}\n";
			}
		}

		return $csv;
	}

	/**
	 * Export report to JSON format
	 *
	 * @param ReportData $report Report data to export.
	 * @return string JSON formatted report content.
	 * @requirement REQ-4E-005
	 * @since 1.0.0
	 */
	public function exportToJSON( ReportData $report ): string {
		$data = [
			'report_type' => $report->report_type,
			'date_range' => $report->getDateRange(),
			'generated_at' => $report->generated_at->format( 'c' ),
			'summary' => [
				'total_payouts' => $report->total_payouts,
				'total_amount' => $report->total_amount / 100,
				'total_commissions' => $report->total_commissions / 100,
				'successful_payouts' => $report->successful_payouts,
				'failed_payouts' => $report->failed_payouts,
				'success_rate' => $report->success_rate,
			],
			'processor_breakdown' => $report->processor_breakdown,
			'seller_breakdown' => $report->seller_breakdown,
		];

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Export report to array for template rendering
	 *
	 * @param ReportData $report Report data to export.
	 * @return array Array representation of report.
	 * @requirement REQ-4E-005
	 * @since 1.0.0
	 */
	public function exportToArray( ReportData $report ): array {
		return [
			'report_type' => $report->report_type,
			'date_range' => $report->getDateRange(),
			'generated_at' => $report->generated_at->format( 'Y-m-d H:i:s' ),
			'summary' => [
				'total_payouts' => $report->total_payouts,
				'total_amount' => $report->total_amount,
				'total_commissions' => $report->total_commissions,
				'successful_payouts' => $report->successful_payouts,
				'failed_payouts' => $report->failed_payouts,
				'success_rate' => round( $report->success_rate, 2 ),
			],
			'processor_breakdown' => $report->processor_breakdown,
			'seller_breakdown' => $report->seller_breakdown,
		];
	}
}
