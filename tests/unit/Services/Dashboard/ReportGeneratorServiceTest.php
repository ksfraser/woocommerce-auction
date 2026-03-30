<?php
/**
 * Report Generator Service Test
 *
 * @package YITH_Auctions\Tests\Unit\Services\Dashboard
 * @covers \YITH_Auctions\Services\Dashboard\ReportGeneratorService
 * @requirement REQ-DASHBOARD-FINANCIAL-REPORTS-001
 */

namespace YITH_Auctions\Tests\Unit\Services\Dashboard;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\Services\Dashboard\ReportGeneratorService;

/**
 * Test case for ReportGeneratorService
 *
 * @since 1.0.0
 */
class ReportGeneratorServiceTest extends TestCase {
	/**
	 * Service instance
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
		parent::setUp();
		$this->service = new ReportGeneratorService();
	}

	/**
	 * Test report type constants exist
	 *
	 * @test
	 * @return void
	 */
	public function testReportTypeConstantsExist(): void {
		$this->assertEquals( 'settlements', ReportGeneratorService::REPORT_TYPE_SETTLEMENTS );
		$this->assertEquals( 'revenue', ReportGeneratorService::REPORT_TYPE_REVENUE );
		$this->assertEquals( 'sellers', ReportGeneratorService::REPORT_TYPE_SELLERS );
		$this->assertEquals( 'disputes', ReportGeneratorService::REPORT_TYPE_DISPUTES );
	}

	/**
	 * Test format constants exist
	 *
	 * @test
	 * @return void
	 */
	public function testFormatConstantsExist(): void {
		$this->assertEquals( 'csv', ReportGeneratorService::FORMAT_CSV );
		$this->assertEquals( 'excel', ReportGeneratorService::FORMAT_EXCEL );
		$this->assertEquals( 'pdf', ReportGeneratorService::FORMAT_PDF );
	}

	/**
	 * Test available reports retrieval
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\ReportGeneratorService::getAvailableReports
	 * @return void
	 */
	public function testGetAvailableReportsReturnsArray(): void {
		$reports = $this->service->getAvailableReports();

		$this->assertIsArray( $reports );
	}

	/**
	 * Test delete report method exists
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\ReportGeneratorService::deleteReport
	 * @return void
	 */
	public function testDeleteReportReturnsBoolean(): void {
		$result = $this->service->deleteReport( 'nonexistent.csv' );

		$this->assertIsBool( $result );
		$this->assertFalse( $result ); // Should return false for nonexistent file
	}

	/**
	 * Test download URL generation
	 *
	 * @test
	 * @covers \YITH_Auctions\Services\Dashboard\ReportGeneratorService::getDownloadUrl
	 * @return void
	 */
	public function testGetDownloadUrlReturnsString(): void {
		$url = $this->service->getDownloadUrl( 'report.csv' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'yith-auction-reports', $url );
	}

	/**
	 * Test invalid export format throws exception
	 *
	 * @test
	 * @return void
	 */
	public function testInvalidFormatThrowsException(): void {
		$this->expectException( \Exception::class );

		// Try to generate a report with invalid format by using reflection
		try {
			$method = new \ReflectionMethod( $this->service, 'exportReport' );
			$method->setAccessible( true );
			$method->invoke(
				$this->service,
				'Test Report',
				[ 'Column 1' ],
				[],
				'invalid_format',
				'test_report'
			);
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'Unsupported export format', $e->getMessage() );
		}
	}
}
