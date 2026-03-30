<?php
/**
 * ReportGeneratorService Unit Tests
 *
 * @package YITH_Auctions\Tests\Services
 * @version 1.0.0
 * @requirement REQ-4E-005
 */

namespace YITH_Auctions\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use YITH_Auctions\Services\ReportGeneratorService;
use YITH_Auctions\Models\ReportData;
use YITH_Auctions\Repositories\SellerPayoutRepository;
use YITH_Auctions\Repositories\SettlementBatchRepository;
use YITH_Auctions\Repositories\CommissionRepository;

/**
 * Test suite for ReportGeneratorService
 *
 * @requirement REQ-4E-005
 * @covers YITH_Auctions\Services\ReportGeneratorService
 */
class ReportGeneratorServiceTest extends TestCase {
	/**
	 * Mock payout repository
	 *
	 * @var SellerPayoutRepository|MockObject
	 */
	private $payout_repository;

	/**
	 * Mock batch repository
	 *
	 * @var SettlementBatchRepository|MockObject
	 */
	private $batch_repository;

	/**
	 * Mock commission repository
	 *
	 * @var CommissionRepository|MockObject
	 */
	private $commission_repository;

	/**
	 * Service under test
	 *
	 * @var ReportGeneratorService
	 */
	private ReportGeneratorService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->payout_repository = $this->createMock( SellerPayoutRepository::class );
		$this->batch_repository = $this->createMock( SettlementBatchRepository::class );
		$this->commission_repository = $this->createMock( CommissionRepository::class );

		$this->service = new ReportGeneratorService(
			$this->payout_repository,
			$this->batch_repository,
			$this->commission_repository
		);
	}

	/**
	 * Test generateSettlementReport returns report data
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_generate_settlement_report_returns_data(): void {
		$start = new \DateTime( '2024-01-01' );
		$end = new \DateTime( '2024-01-31' );

		$mock_data = [
			'total_payouts' => 500,
			'total_amount' => 5000000,
			'commissions' => 500000,
			'successful_payouts' => 485,
			'failed_payouts' => 15,
			'success_rate' => 97.0,
			'processor_breakdown' => [
				'stripe' => [ 'count' => 300, 'amount' => 3000000 ],
				'paypal' => [ 'count' => 200, 'amount' => 2000000 ],
			],
			'seller_breakdown' => [
				1 => [ 'total' => 100, 'amount' => 1000000, 'successful' => 98, 'failed' => 2 ],
			],
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getReportData' )
			->with( $start, $end, 'settlement' )
			->willReturn( $mock_data );

		$report = $this->service->generateSettlementReport( $start, $end );

		$this->assertInstanceOf( ReportData::class, $report );
		$this->assertEquals( 'settlement', $report->report_type );
		$this->assertEquals( 500, $report->total_payouts );
	}

	/**
	 * Test generateSellerReport with seller filter
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_generate_seller_report_returns_seller_data(): void {
		$seller_id = 1;
		$start = new \DateTime( '2024-01-01' );
		$end = new \DateTime( '2024-01-31' );

		$mock_data = [
			'total_payouts' => 50,
			'total_amount' => 500000,
			'commissions' => 50000,
			'successful_payouts' => 48,
			'failed_payouts' => 2,
			'success_rate' => 96.0,
			'processor_breakdown' => [
				'stripe' => [ 'count' => 50, 'amount' => 500000 ],
			],
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getReportData' )
			->with( $start, $end, 'seller', $seller_id )
			->willReturn( $mock_data );

		$report = $this->service->generateSellerReport( $seller_id, $start, $end );

		$this->assertInstanceOf( ReportData::class, $report );
		$this->assertEquals( 'seller', $report->report_type );
		$this->assertEquals( 50, $report->total_payouts );
	}

	/**
	 * Test generateCommissionReport returns commission data
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_generate_commission_report_returns_data(): void {
		$start = new \DateTime( '2024-01-01' );
		$end = new \DateTime( '2024-01-31' );

		$commission_data = [
			'total_commissions' => 500,
			'total_commission_amount' => 500000,
			'processed_count' => 490,
			'failed_count' => 10,
			'success_rate' => 98.0,
			'breakdown_by_processor' => [],
			'breakdown_by_seller' => [],
		];

		$payout_data = [
			'total_payouts' => 500,
			'total_amount' => 5000000,
			'commissions' => 500000,
			'successful_payouts' => 490,
			'failed_payouts' => 10,
			'success_rate' => 98.0,
		];

		$this->commission_repository->expects( $this->once() )
			->method( 'getReportData' )
			->with( $start, $end )
			->willReturn( $commission_data );

		$this->payout_repository->expects( $this->once() )
			->method( 'getReportData' )
			->with( $start, $end, 'settlement' )
			->willReturn( $payout_data );

		$report = $this->service->generateCommissionReport( $start, $end );

		$this->assertInstanceOf( ReportData::class, $report );
		$this->assertEquals( 'commission', $report->report_type );
	}

	/**
	 * Test exportToCSV generates valid CSV
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_csv_generates_valid_output(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[ 'stripe' => [ 'count' => 100, 'amount' => 1000000, 'success_rate' => 95 ] ],
			[ 1 => [ 'total' => 100, 'amount' => 1000000, 'successful' => 95, 'failed' => 5 ] ],
			new \DateTime()
		);

		$csv = $this->service->exportToCSV( $report );

		$this->assertStringContainsString( 'Report Type,settlement', $csv );
		$this->assertStringContainsString( 'Total Payouts,100', $csv );
		$this->assertStringContainsString( 'stripe', $csv );
	}

	/**
	 * Test exportToCSV includes breakdown
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_csv_includes_processor_breakdown(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			200,
			2000000,
			200000,
			190,
			10,
			95.0,
			[
				'stripe' => [ 'count' => 120, 'amount' => 1200000, 'success_rate' => 96 ],
				'paypal' => [ 'count' => 80, 'amount' => 800000, 'success_rate' => 93 ],
			],
			[],
			new \DateTime()
		);

		$csv = $this->service->exportToCSV( $report );

		$this->assertStringContainsString( 'Processor Breakdown', $csv );
		$this->assertStringContainsString( 'stripe', $csv );
		$this->assertStringContainsString( 'paypal', $csv );
	}

	/**
	 * Test exportToJSON generates valid JSON
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_json_generates_valid_output(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[],
			[],
			new \DateTime()
		);

		$json = $this->service->exportToJSON( $report );

		$decoded = json_decode( $json, true );

		$this->assertEquals( 'settlement', $decoded['report_type'] );
		$this->assertEquals( 100, $decoded['summary']['total_payouts'] );
		$this->assertEquals( 10000.00, $decoded['summary']['total_amount'] );
	}

	/**
	 * Test exportToArray returns properly formatted array
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_array_returns_array(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[],
			[],
			new \DateTime()
		);

		$array = $this->service->exportToArray( $report );

		$this->assertIsArray( $array );
		$this->assertEquals( 'settlement', $array['report_type'] );
		$this->assertArrayHasKey( 'summary', $array );
		$this->assertEquals( 100, $array['summary']['total_payouts'] );
	}

	/**
	 * Test ReportData date range formatting
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_report_data_date_range_formatting(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[],
			[],
			new \DateTime()
		);

		$date_range = $report->getDateRange();

		$this->assertEquals( '2024-01-01 to 2024-01-31', $date_range );
	}

	/**
	 * Test exportToCSV with seller breakdown
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_csv_includes_seller_breakdown(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[],
			[
				1 => [ 'total' => 50, 'amount' => 500000, 'successful' => 48, 'failed' => 2 ],
				2 => [ 'total' => 50, 'amount' => 500000, 'successful' => 47, 'failed' => 3 ],
			],
			new \DateTime()
		);

		$csv = $this->service->exportToCSV( $report );

		$this->assertStringContainsString( 'Seller Breakdown', $csv );
		$this->assertStringContainsString( 'Seller ID', $csv );
	}

	/**
	 * Test exportToJSON includes processor breakdown
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_json_includes_processor_breakdown(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[
				'stripe' => [ 'count' => 100, 'amount' => 1000000, 'success_rate' => 95 ],
			],
			[],
			new \DateTime()
		);

		$json = $this->service->exportToJSON( $report );
		$decoded = json_decode( $json, true );

		$this->assertArrayHasKey( 'processor_breakdown', $decoded );
		$this->assertArrayHasKey( 'stripe', $decoded['processor_breakdown'] );
	}

	/**
	 * Test exportToArray formats amounts as integers
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_array_formats_amounts(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[],
			[],
			new \DateTime()
		);

		$array = $this->service->exportToArray( $report );

		$this->assertEquals( 1000000, $array['summary']['total_amount'] );
		$this->assertEquals( 100000, $array['summary']['total_commissions'] );
	}

	/**
	 * Test exportToCSV amount formatting
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_export_to_csv_formats_amounts_as_currency(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100,
			1000000,
			100000,
			95,
			5,
			95.0,
			[],
			[],
			new \DateTime()
		);

		$csv = $this->service->exportToCSV( $report );

		$this->assertStringContainsString( '10000.00', $csv );
		$this->assertStringContainsString( '1000.00', $csv );
	}
}
