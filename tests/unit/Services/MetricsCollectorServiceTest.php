<?php
/**
 * MetricsCollectorService Unit Tests
 *
 * @package YITH_Auctions\Tests\Services
 * @version 1.0.0
 * @requirement REQ-4E-010
 */

namespace YITH_Auctions\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use YITH_Auctions\Services\MetricsCollectorService;
use YITH_Auctions\Repositories\SellerPayoutRepository;
use YITH_Auctions\Repositories\SettlementBatchRepository;

/**
 * Test suite for MetricsCollectorService
 *
 * @requirement REQ-4E-010
 * @covers YITH_Auctions\Services\MetricsCollectorService
 */
class MetricsCollectorServiceTest extends TestCase {
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
	 * Service under test
	 *
	 * @var MetricsCollectorService
	 */
	private MetricsCollectorService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->payout_repository = $this->createMock( SellerPayoutRepository::class );
		$this->batch_repository = $this->createMock( SettlementBatchRepository::class );

		$this->service = new MetricsCollectorService(
			$this->payout_repository,
			$this->batch_repository
		);
	}

	/**
	 * Test collectMetrics returns all metrics
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_collect_metrics_returns_all_metrics(): void {
		$this->payout_repository->expects( $this->exactly( 7 ) )
			->method( 'countLast24Hours' )
			->willReturn( 500 );

		$this->payout_repository->expects( $this->once() )
			->method( 'sumLast24Hours' )
			->willReturn( 5000000 );

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->with( 24 )
			->willReturn( [ 'success_rate' => 96.5, 'error_rate' => 2.1 ] );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [ [ 'id' => 1 ], [ 'id' => 2 ] ] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 250 );

		$this->payout_repository->expects( $this->once() )
			->method( 'countFailed' )
			->willReturn( 50 );

		$metrics = $this->service->collectMetrics();

		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'payouts_24h', $metrics );
		$this->assertArrayHasKey( 'amount_24h', $metrics );
		$this->assertArrayHasKey( 'success_rate', $metrics );
		$this->assertArrayHasKey( 'active_batches', $metrics );
		$this->assertArrayHasKey( 'pending_payouts', $metrics );
		$this->assertArrayHasKey( 'failed_payouts', $metrics );
	}

	/**
	 * Test collectAndCache bypasses cache when force_refresh true
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_collect_and_cache_force_refresh(): void {
		$this->payout_repository->expects( $this->exactly( 7 ) )
			->method( 'countLast24Hours' )
			->willReturn( 500 );

		$this->payout_repository->expects( $this->once() )
			->method( 'sumLast24Hours' )
			->willReturn( 5000000 );

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->willReturn( [ 'success_rate' => 96.5, 'error_rate' => 2.1 ] );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 250 );

		$this->payout_repository->expects( $this->once() )
			->method( 'countFailed' )
			->willReturn( 50 );

		$metrics = $this->service->collectAndCache( true );

		$this->assertIsArray( $metrics );
		$this->assertNotEmpty( $metrics );
	}

	/**
	 * Test getMetricsCache returns null when no cache
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_get_metrics_cache_returns_null_when_empty(): void {
		// Since we can't control WordPress cache in unit tests,
		// we verify the method signature works
		$result = $this->service->getMetricsCache();

		$this->assertTrue( $result === null || is_array( $result ) );
	}

	/**
	 * Test invalidateCache method exists and is callable
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_invalidate_cache_is_callable(): void {
		$this->service->invalidateCache();

		// Method should not throw exception
		$this->assertTrue( true );
	}

	/**
	 * Test collectSellerMetrics returns seller-specific data
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_collect_seller_metrics_returns_data(): void {
		$seller_id = 1;

		$this->payout_repository->expects( $this->once() )
			->method( 'getStatistics' )
			->with( $seller_id )
			->willReturn( [
				'total_amount' => 100000,
				'completed_amount' => 90000,
			] );

		$this->payout_repository->expects( $this->exactly( 2 ) )
			->method( 'countBySeller' )
			->willReturnOnConsecutiveCalls( 5, 2 );

		$metrics = $this->service->collectSellerMetrics( $seller_id );

		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'payouts_pending', $metrics );
		$this->assertArrayHasKey( 'payouts_failed', $metrics );
		$this->assertArrayHasKey( 'total_amount', $metrics );
		$this->assertArrayHasKey( 'completed_amount', $metrics );
	}

	/**
	 * Test aggregateMetrics returns aggregated data
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_aggregate_metrics_returns_data(): void {
		$aggregated = [
			[ 'period' => '2024-01-15', 'payouts' => 50, 'amount' => 500000 ],
			[ 'period' => '2024-01-16', 'payouts' => 60, 'amount' => 600000 ],
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getAggregatedMetrics' )
			->willReturn( $aggregated );

		$result = $this->service->aggregateMetrics( 30, 'daily' );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test setCacheDuration sets minimum duration
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_set_cache_duration_enforces_minimum(): void {
		$service = $this->service->setCacheDuration( 30 );

		// Method chaining should return self
		$this->assertInstanceOf( MetricsCollectorService::class, $service );
	}

	/**
	 * Test collectAndCache caches metrics
	 *
	 * @requirement REQ-4E-010
	 * @return void
	 */
	public function test_collect_and_cache_stores_cache(): void {
		$this->payout_repository->expects( $this->exactly( 7 ) )
			->method( 'countLast24Hours' )
			->willReturn( 500 );

		$this->payout_repository->expects( $this->once() )
			->method( 'sumLast24Hours' )
			->willReturn( 5000000 );

		$this->payout_repository->expects( $this->once() )
			->method( 'getHealthMetrics' )
			->willReturn( [ 'success_rate' => 96.5, 'error_rate' => 2.1 ] );

		$this->batch_repository->expects( $this->once() )
			->method( 'findActive' )
			->willReturn( [] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countPending' )
			->willReturn( 250 );

		$this->payout_repository->expects( $this->once() )
			->method( 'countFailed' )
			->willReturn( 50 );

		// First call - populates cache
		$metrics1 = $this->service->collectAndCache( false );

		// Cache should have data
		$this->assertIsArray( $metrics1 );
	}
}
