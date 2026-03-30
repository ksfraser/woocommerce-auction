<?php
/**
 * SettlementMonitorService Unit Tests
 *
 * @package YITH_Auctions\Tests\Services
 * @version 1.0.0
 * @requirement REQ-4E-002, REQ-4E-008
 */

namespace YITH_Auctions\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use YITH_Auctions\Services\SettlementMonitorService;
use YITH_Auctions\Models\BatchStatusData;
use YITH_Auctions\Models\SystemHealthData;
use YITH_Auctions\Repositories\SettlementBatchRepository;
use YITH_Auctions\Repositories\SellerPayoutRepository;

/**
 * Test suite for SettlementMonitorService
 *
 * @requirement REQ-4E-002, REQ-4E-008
 * @covers YITH_Auctions\Services\SettlementMonitorService
 */
class SettlementMonitorServiceTest extends TestCase {
	/**
	 * Mock batch repository
	 *
	 * @var SettlementBatchRepository|MockObject
	 */
	private $batch_repository;

	/**
	 * Mock payout repository
	 *
	 * @var SellerPayoutRepository|MockObject
	 */
	private $payout_repository;

	/**
	 * Service under test
	 *
	 * @var SettlementMonitorService
	 */
	private SettlementMonitorService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->batch_repository = $this->createMock( SettlementBatchRepository::class );
		$this->payout_repository = $this->createMock( SellerPayoutRepository::class );

		$this->service = new SettlementMonitorService(
			$this->batch_repository,
			$this->payout_repository
		);
	}

	/**
	 * Test getBatchStatus returns correct data
	 *
	 * @requirement REQ-4E-002
	 * @return void
	 */
	public function test_get_batch_status_returns_data(): void {
		$batch_id = 1;

		$mock_batch = [
			'id' => $batch_id,
			'seller_count' => 50,
			'status' => 'processing',
			'total_amount' => 500000,
			'created_at' => '2024-01-15 10:00:00',
			'completed_at' => null,
		];

		$mock_stats = [
			'total' => 100,
			'completed' => 80,
			'failed' => 10,
			'pending' => 10,
		];

		$this->batch_repository->expects( $this->once() )
			->method( 'findById' )
			->with( $batch_id )
			->willReturn( $mock_batch );

		$this->payout_repository->expects( $this->once() )
			->method( 'getStatsByBatch' )
			->with( $batch_id )
			->willReturn( $mock_stats );

		$result = $this->service->getBatchStatus( $batch_id );

		$this->assertInstanceOf( BatchStatusData::class, $result );
		$this->assertEquals( $batch_id, $result->batch_id );
		$this->assertEquals( 100, $result->completed_count );
	}

	/**
	 * Test getBatchStatus returns null when not found
	 *
	 * @requirement REQ-4E-002
	 * @return void
	 */
	public function test_get_batch_status_returns_null_when_not_found(): void {
		$batch_id = 999;

		$this->batch_repository->expects( $this->once() )
			->method( 'findById' )
			->with( $batch_id )
			->willReturn( null );

		$result = $this->service->getBatchStatus( $batch_id );

		$this->assertNull( $result );
	}

	/**
	 * Test getActiveBatches returns active batches only
	 *
	 * @requirement REQ-4E-002
	 * @return void
	 */
	public function test_get_active_batches_returns_active(): void {
		$mock_active_batches = [
			[
				'id' => 1,
				'seller_count' => 50,
				'status' => 'processing',
				'total_amount' => 500000,
				'created_at' => '2024-01-15 10:00:00',
				'completed_at' => null,
			],
			[
				'id' => 2,
				'seller_count' => 30,
				'status' => 'pending',
				'total_amount' => 300000,
				'created_at' => '2024-01-15 11:00:00',
				'completed_at' => null,
			],
		];

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( $mock_active_batches );

		$this->payout_repository->expects( $this->exactly( 2 ) )
			->method( 'getStatsByBatch' )
			->willReturn( [ 'total' => 50, 'completed' => 0, 'failed' => 0, 'pending' => 50 ] );

		$result = $this->service->getActiveBatches();

		$this->assertCount( 2, $result );
		foreach ( $result as $batch ) {
			$this->assertInstanceOf( BatchStatusData::class, $batch );
		}
	}

	/**
	 * Test getBatchHistory pagination
	 *
	 * @requirement REQ-4E-002
	 * @return void
	 */
	public function test_get_batch_history_pagination(): void {
		$mock_batches = [
			[
				'id' => 10,
				'seller_count' => 50,
				'status' => 'completed',
				'total_amount' => 500000,
				'created_at' => '2024-01-15 10:00:00',
				'completed_at' => '2024-01-15 12:00:00',
			],
		];

		$this->batch_repository->expects( $this->once() )
			->method( 'findAll' )
			->with( 0, 10 )
			->willReturn( $mock_batches );

		$this->batch_repository->expects( $this->once() )
			->method( 'countAll' )
			->willReturn( 25 );

		$this->payout_repository->expects( $this->once() )
			->method( 'getStatsByBatch' )
			->willReturn( [ 'total' => 50, 'completed' => 50, 'failed' => 0, 'pending' => 0 ] );

		$result = $this->service->getBatchHistory( 1, 10 );

		$this->assertCount( 1, $result['batches'] );
		$this->assertEquals( 25, $result['total'] );
		$this->assertEquals( 3, $result['pages'] );
	}

	/**
	 * Test getSystemHealth returns health data
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_get_system_health_returns_data(): void {
		$mock_health = [
			'success_rate' => 96.5,
			'error_rate' => 2.1,
			'avg_processing_time_ms' => 1200,
			'total_payouts_24h' => 500,
			'total_amount_24h' => 5000000,
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->with( 24 )
			->willReturn( $mock_health );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [ [ 'id' => 1 ], [ 'id' => 2 ] ] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 250 );

		$this->batch_repository->expects( $this->once() )
			->method( 'getLastCompletedBatch' )
			->willReturn( [ 'completed_at' => '2024-01-15 12:00:00' ] );

		$result = $this->service->getSystemHealth();

		$this->assertInstanceOf( SystemHealthData::class, $result );
		$this->assertEquals( 96.5, $result->success_rate );
		$this->assertEquals( 2, $result->active_batches );
	}

	/**
	 * Test detectAnomalies with low success rate
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_detect_anomalies_low_success_rate(): void {
		$mock_health = [
			'success_rate' => 85.0,
			'error_rate' => 2.0,
			'avg_processing_time_ms' => 1000,
			'total_payouts_24h' => 100,
			'total_amount_24h' => 1000000,
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->with( 24 )
			->willReturn( $mock_health );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 10 );

		$this->batch_repository->expects( $this->once() )
			->method( 'getLastCompletedBatch' )
			->willReturn( [ 'completed_at' => '2024-01-15 12:00:00' ] );

		$alerts = $this->service->detectAnomalies();

		$this->assertNotEmpty( $alerts );
		$alert = $alerts[0];
		$this->assertEquals( 'low_success_rate', $alert->alert_type );
		$this->assertEquals( 'warning', $alert->severity );
	}

	/**
	 * Test detectAnomalies with high error rate
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_detect_anomalies_high_error_rate(): void {
		$mock_health = [
			'success_rate' => 92.0,
			'error_rate' => 8.0,
			'avg_processing_time_ms' => 1000,
			'total_payouts_24h' => 100,
			'total_amount_24h' => 1000000,
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->with( 24 )
			->willReturn( $mock_health );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 10 );

		$this->batch_repository->expects( $this->once() )
			->method( 'getLastCompletedBatch' )
			->willReturn( [ 'completed_at' => '2024-01-15 12:00:00' ] );

		$alerts = $this->service->detectAnomalies();

		$critical_alert = array_filter( $alerts, fn( $a ) => $a->alert_type === 'high_error_rate' );
		$this->assertNotEmpty( $critical_alert );
	}

	/**
	 * Test detectAnomalies with slow processing
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_detect_anomalies_slow_processing(): void {
		$mock_health = [
			'success_rate' => 98.0,
			'error_rate' => 1.0,
			'avg_processing_time_ms' => 8000,
			'total_payouts_24h' => 100,
			'total_amount_24h' => 1000000,
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->with( 24 )
			->willReturn( $mock_health );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 10 );

		$this->batch_repository->expects( $this->once() )
			->method( 'getLastCompletedBatch' )
			->willReturn( [ 'completed_at' => '2024-01-15 12:00:00' ] );

		$alerts = $this->service->detectAnomalies();

		$slow_alert = array_filter( $alerts, fn( $a ) => $a->alert_type === 'slow_processing' );
		$this->assertNotEmpty( $slow_alert );
	}

	/**
	 * Test detectAnomalies with queue buildup
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_detect_anomalies_queue_buildup(): void {
		$mock_health = [
			'success_rate' => 98.0,
			'error_rate' => 1.0,
			'avg_processing_time_ms' => 1000,
			'total_payouts_24h' => 100,
			'total_amount_24h' => 1000000,
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->with( 24 )
			->willReturn( $mock_health );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 2000 );

		$this->batch_repository->expects( $this->once() )
			->method( 'getLastCompletedBatch' )
			->willReturn( [ 'completed_at' => '2024-01-15 12:00:00' ] );

		$alerts = $this->service->detectAnomalies();

		$queue_alert = array_filter( $alerts, fn( $a ) => $a->alert_type === 'queue_buildup' );
		$this->assertNotEmpty( $queue_alert );
	}

	/**
	 * Test BatchStatusData progress percentage
	 *
	 * @requirement REQ-4E-002
	 * @return void
	 */
	public function test_batch_status_data_progress_percentage(): void {
		$batch = new BatchStatusData(
			1,
			50,
			100,
			80,
			10,
			10,
			'processing',
			500000,
			new \DateTime(),
			null
		);

		$this->assertEquals( 80, $batch->getProgressPercentage() );
	}

	/**
	 * Test SystemHealthData isHealthy check
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_is_healthy(): void {
		$health = new SystemHealthData(
			96.5,
			2.1,
			1200,
			500,
			5000000,
			2,
			250,
			new \DateTime()
		);

		$this->assertTrue( $health->isHealthy() );
	}

	/**
	 * Test SystemHealthData health status
	 *
	 * @requirement REQ-4E-008
	 * @return void
	 */
	public function test_system_health_data_status_classification(): void {
		$excellent = new SystemHealthData( 99.0, 0.5, 1000, 100, 1000000, 1, 10, new \DateTime() );
		$this->assertEquals( 'excellent', $excellent->getHealthStatus() );

		$good = new SystemHealthData( 96.0, 2.0, 2000, 100, 1000000, 1, 10, new \DateTime() );
		$this->assertEquals( 'good', $good->getHealthStatus() );

		$poor = new SystemHealthData( 88.0, 8.0, 3000, 100, 1000000, 1, 10, new \DateTime() );
		$this->assertEquals( 'critical', $poor->getHealthStatus() );
	}
}
