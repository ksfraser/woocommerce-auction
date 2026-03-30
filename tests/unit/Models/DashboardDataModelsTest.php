<?php
/**
 * Dashboard Data Models Unit Tests
 *
 * @package YITH_Auctions\Tests\Models
 * @version 1.0.0
 * @requirement REQ-4E-001 through REQ-4E-010
 */

namespace YITH_Auctions\Tests\Models;

use PHPUnit\Framework\TestCase;
use YITH_Auctions\Models\PayoutDashboardData;
use YITH_Auctions\Models\BatchStatusData;
use YITH_Auctions\Models\DashboardStats;
use YITH_Auctions\Models\SystemHealthData;
use YITH_Auctions\Models\FailedPayoutData;
use YITH_Auctions\Models\ReportData;

/**
 * Test suite for dashboard data models
 *
 * @requirement REQ-4E-001 through REQ-4E-010
 * @covers YITH_Auctions\Models
 */
class DashboardDataModelsTest extends TestCase {
	/**
	 * Test PayoutDashboardData immutability and formatting
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_payout_dashboard_data_properties(): void {
		$data = new PayoutDashboardData(
			1,
			10,
			100,
			100000,
			10000,
			90000,
			'completed',
			'txn_stripe_123',
			'stripe',
			new \DateTime( '2024-01-15 10:00:00' ),
			new \DateTime( '2024-01-15 10:05:00' )
		);

		$this->assertEquals( 1, $data->payout_id );
		$this->assertEquals( 10, $data->seller_id );
		$this->assertEquals( 100, $data->auction_id );
		$this->assertEquals( 'completed', $data->status );
		$this->assertEquals( 'stripe', $data->processor );
	}

	/**
	 * Test PayoutDashboardData amount formatting
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_payout_dashboard_data_format_amount(): void {
		$data = new PayoutDashboardData(
			1, 10, 100, 100000, 10000, 90000,
			'completed', 'txn_123', 'stripe',
			new \DateTime(), new \DateTime()
		);

		$this->assertEquals( '$1000.00', $data->getFormattedAmount( 100000 ) );
		$this->assertEquals( '$0.01', $data->getFormattedAmount( 1 ) );
	}

	/**
	 * Test PayoutDashboardData status labels
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_payout_dashboard_data_status_label(): void {
		$statuses = [
			'pending' => 'Pending',
			'processing' => 'Processing',
			'completed' => 'Completed',
			'failed' => 'Failed',
			'skipped' => 'Skipped',
			'permanently_failed' => 'Permanently Failed',
		];

		foreach ( $statuses as $status => $expected_label ) {
			$data = new PayoutDashboardData(
				1, 10, 100, 100000, 10000, 90000,
				$status, null, null,
				new \DateTime(), null
			);

			$label = $data->getStatusLabel();
			$this->assertNotEmpty( $label );
		}
	}

	/**
	 * Test PayoutDashboardData CSS class mapping
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_payout_dashboard_data_status_class(): void {
		$data = new PayoutDashboardData(
			1, 10, 100, 100000, 10000, 90000,
			'completed', null, null,
			new \DateTime(), null
		);

		$this->assertEquals( 'status-completed', $data->getStatusClass() );
	}

	/**
	 * Test BatchStatusData progress calculation
	 *
	 * @requirement REQ-4E-002
	 * @return void
	 */
	public function test_batch_status_data_progress_percentage(): void {
		$batch = new BatchStatusData(
			1, 50, 100, 80, 15, 5,
			'processing', 500000,
			new \DateTime(), null
		);

		$this->assertEquals( 80, $batch->getProgressPercentage() );
	}

	/**
	 * Test BatchStatusData time estimation
	 *
	 * @requirement REQ-4E-002
	 * @return void
	 */
	public function test_batch_status_data_estimated_time(): void {
		$batch = new BatchStatusData(
			1, 50, 100, 50, 0, 50,
			'processing', 500000,
			new \DateTime( '-1 hour' ), null
		);

		$estimate = $batch->getEstimatedTimeRemaining();

		// Should return something
		$this->assertIsString( $estimate );
	}

	/**
	 * Test DashboardStats completion rate
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_dashboard_stats_completion_rate(): void {
		$stats = new DashboardStats(
			100, 1000000, 900000, 100000, 10, 90.0,
			10000, 1000, 50000
		);

		$this->assertEquals( 90, $stats->getCompletionRate() );
	}

	/**
	 * Test DashboardStats with zero payouts
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_dashboard_stats_zero_payouts(): void {
		$stats = new DashboardStats(
			0, 0, 0, 0, 0, 0.0,
			0, 0, 0
		);

		$this->assertEquals( 0, $stats->getCompletionRate() );
	}

	/**
	 * Test SystemHealthData isHealthy check
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_is_healthy(): void {
		$healthy = new SystemHealthData(
			96.5, 2.1, 1200, 500, 5000000, 2, 250,
			new \DateTime()
		);

		$this->assertTrue( $healthy->isHealthy() );
	}

	/**
	 * Test SystemHealthData isHealthy returns false when unhealthy
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_is_unhealthy(): void {
		$unhealthy = new SystemHealthData(
			85.0, 10.0, 8000, 500, 5000000, 2, 250,
			new \DateTime()
		);

		$this->assertFalse( $unhealthy->isHealthy() );
	}

	/**
	 * Test SystemHealthData status classification
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_status_excellent(): void {
		$excellent = new SystemHealthData(
			99.0, 0.5, 1000, 500, 5000000, 1, 50,
			new \DateTime()
		);

		$this->assertEquals( 'excellent', $excellent->getHealthStatus() );
	}

	/**
	 * Test SystemHealthData status good
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_status_good(): void {
		$good = new SystemHealthData(
			96.0, 2.0, 2000, 500, 5000000, 2, 250,
			new \DateTime()
		);

		$this->assertEquals( 'good', $good->getHealthStatus() );
	}

	/**
	 * Test SystemHealthData status warning
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_status_warning(): void {
		$warning = new SystemHealthData(
			92.0, 4.0, 3000, 500, 5000000, 5, 1500,
			new \DateTime()
		);

		$this->assertEquals( 'warning', $warning->getHealthStatus() );
	}

	/**
	 * Test SystemHealthData status critical
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_status_critical(): void {
		$critical = new SystemHealthData(
			85.0, 10.0, 5000, 500, 5000000, 10, 5000,
			new \DateTime()
		);

		$this->assertEquals( 'critical', $critical->getHealthStatus() );
	}

	/**
	 * Test FailedPayoutData retry eligibility
	 *
	 * @requirement REQ-4E-007
	 * @return void
	 */
	public function test_failed_payout_data_can_retry(): void {
		$failed = new FailedPayoutData(
			1, 10, 100000,
			'Processor timeout',
			2, 5,
			new \DateTime( '+5 minutes' ),
			new \DateTime()
		);

		$this->assertTrue( $failed->canRetry() );
		$this->assertFalse( $failed->isPermanentlyFailed() );
	}

	/**
	 * Test FailedPayoutData permanent failure
	 *
	 * @requirement REQ-4E-007
	 * @return void
	 */
	public function test_failed_payout_data_permanently_failed(): void {
		$failed = new FailedPayoutData(
			1, 10, 100000,
			'Invalid account details',
			5, 5,
			null,
			new \DateTime()
		);

		$this->assertFalse( $failed->canRetry() );
		$this->assertTrue( $failed->isPermanentlyFailed() );
	}

	/**
	 * Test FailedPayoutData eligible for retry
	 *
	 * @requirement REQ-4E-007
	 * @return void
	 */
	public function test_failed_payout_data_eligible_for_retry(): void {
		$failed = new FailedPayoutData(
			1, 10, 100000,
			'Processor timeout',
			2, 5,
			new \DateTime( '-1 minutes' ),
			new \DateTime()
		);

		$this->assertTrue( $failed->isEligibleForRetry() );
	}

	/**
	 * Test FailedPayoutData not eligible for retry (future date)
	 *
	 * @requirement REQ-4E-007
	 * @return void
	 */
	public function test_failed_payout_data_not_eligible_for_retry(): void {
		$failed = new FailedPayoutData(
			1, 10, 100000,
			'Processor timeout',
			2, 5,
			new \DateTime( '+1 hour' ),
			new \DateTime()
		);

		$this->assertFalse( $failed->isEligibleForRetry() );
	}

	/**
	 * Test ReportData date range formatting
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_report_data_date_range(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100, 1000000, 100000, 95, 5, 95.0,
			[], [], new \DateTime()
		);

		$this->assertEquals( '2024-01-01 to 2024-01-31', $report->getDateRange() );
	}

	/**
	 * Test ReportData immutability
	 *
	 * @requirement REQ-4E-005
	 * @return void
	 */
	public function test_report_data_immutability(): void {
		$report = new ReportData(
			'settlement',
			new \DateTime( '2024-01-01' ),
			new \DateTime( '2024-01-31' ),
			100, 1000000, 100000, 95, 5, 95.0,
			[], [], new \DateTime()
		);

		$this->assertEquals( 'settlement', $report->report_type );
		$this->assertEquals( 100, $report->total_payouts );

		// Properties should be readonly
		$this->assertTrue( property_exists( $report, 'report_type' ) );
	}
}
