<?php
/**
 * PayoutDashboardService Unit Tests
 *
 * @package YITH_Auctions\Tests\Services
 * @version 1.0.0
 * @requirement REQ-4E-001
 */

namespace YITH_Auctions\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use YITH_Auctions\Services\PayoutDashboardService;
use YITH_Auctions\Models\PayoutDashboardData;
use YITH_Auctions\Repositories\SellerPayoutRepository;
use YITH_Auctions\Services\EncryptionService;

/**
 * Test suite for PayoutDashboardService
 *
 * @requirement REQ-4E-001
 * @covers YITH_Auctions\Services\PayoutDashboardService
 */
class PayoutDashboardServiceTest extends TestCase {
	/**
	 * Mock repository
	 *
	 * @var SellerPayoutRepository|MockObject
	 */
	private $payout_repository;

	/**
	 * Mock encryption service
	 *
	 * @var EncryptionService|MockObject
	 */
	private $encryption_service;

	/**
	 * Service under test
	 *
	 * @var PayoutDashboardService
	 */
	private PayoutDashboardService $service;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->payout_repository = $this->createMock( SellerPayoutRepository::class );
		$this->encryption_service = $this->createMock( EncryptionService::class );

		$this->service = new PayoutDashboardService(
			$this->payout_repository,
			$this->encryption_service
		);
	}

	/**
	 * Test getSellerPayouts with valid page
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_seller_payouts_returns_paginated_results(): void {
		$seller_id = 1;
		$page = 1;
		$per_page = 20;

		$mock_payouts = [
			[
				'id' => 1,
				'seller_id' => $seller_id,
				'auction_id' => 100,
				'gross_amount' => 100000,
				'commission_amount' => 10000,
				'net_amount' => 90000,
				'status' => 'completed',
				'transaction_id' => 'txn_123',
				'transaction_id_encrypted' => false,
				'processor' => 'stripe',
				'created_at' => '2024-01-15 10:00:00',
				'completed_at' => '2024-01-15 10:05:00',
			],
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'findBySeller' )
			->with( $seller_id, 0, $per_page, [] )
			->willReturn( $mock_payouts );

		$this->payout_repository->expects( $this->once() )
			->method( 'countBySeller' )
			->with( $seller_id, [] )
			->willReturn( 25 );

		$result = $this->service->getSellerPayouts( $seller_id, $page, $per_page );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['payouts'] );
		$this->assertEquals( 25, $result['total'] );
		$this->assertEquals( 2, $result['pages'] );
	}

	/**
	 * Test getSellerPayouts with filters
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_seller_payouts_applies_filters(): void {
		$seller_id = 1;
		$filters = [ 'status' => 'completed' ];

		$this->payout_repository->expects( $this->once() )
			->method( 'findBySeller' )
			->with( $seller_id, 0, 20, $filters )
			->willReturn( [] );

		$this->payout_repository->expects( $this->once() )
			->method( 'countBySeller' )
			->with( $seller_id, $filters )
			->willReturn( 0 );

		$result = $this->service->getSellerPayouts( $seller_id, 1, 20, $filters );

		$this->assertEquals( 0, $result['total'] );
	}

	/**
	 * Test getPayoutDetails returns data
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_payout_details_returns_data(): void {
		$payout_id = 1;

		$mock_payout = [
			'id' => $payout_id,
			'seller_id' => 1,
			'auction_id' => 100,
			'gross_amount' => 100000,
			'commission_amount' => 10000,
			'net_amount' => 90000,
			'status' => 'completed',
			'transaction_id' => 'enc_txn_123',
			'transaction_id_encrypted' => true,
			'processor' => 'stripe',
			'created_at' => '2024-01-15 10:00:00',
			'completed_at' => '2024-01-15 10:05:00',
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'findById' )
			->with( $payout_id )
			->willReturn( $mock_payout );

		$this->encryption_service->expects( $this->once() )
			->method( 'decrypt' )
			->with( 'enc_txn_123' )
			->willReturn( 'txn_123' );

		$result = $this->service->getPayoutDetails( $payout_id );

		$this->assertInstanceOf( PayoutDashboardData::class, $result );
		$this->assertEquals( $payout_id, $result->payout_id );
	}

	/**
	 * Test getPayoutDetails returns null for missing payout
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_payout_details_returns_null_when_not_found(): void {
		$payout_id = 999;

		$this->payout_repository->expects( $this->once() )
			->method( 'findById' )
			->with( $payout_id )
			->willReturn( null );

		$result = $this->service->getPayoutDetails( $payout_id );

		$this->assertNull( $result );
	}

	/**
	 * Test getPendingPayouts returns pending only
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_pending_payouts_filters_by_status(): void {
		$seller_id = 1;

		$mock_payouts = [
			[
				'id' => 1,
				'seller_id' => $seller_id,
				'auction_id' => 100,
				'gross_amount' => 50000,
				'commission_amount' => 5000,
				'net_amount' => 45000,
				'status' => 'pending',
				'transaction_id' => null,
				'transaction_id_encrypted' => false,
				'processor' => null,
				'created_at' => '2024-01-15 10:00:00',
				'completed_at' => null,
			],
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'findBySeller' )
			->with( $seller_id, 0, 999, [ 'status' => 'pending' ] )
			->willReturn( $mock_payouts );

		$result = $this->service->getPendingPayouts( $seller_id );

		$this->assertCount( 1, $result );
		$this->assertEquals( 'pending', $result[0]->status );
	}

	/**
	 * Test getFailedPayouts returns failed only
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_failed_payouts_filters_by_status(): void {
		$seller_id = 1;

		$this->payout_repository->expects( $this->once() )
			->method( 'findBySeller' )
			->with( $seller_id, 0, 999, [ 'status' => [ 'failed', 'permanently_failed' ] ] )
			->willReturn( [] );

		$result = $this->service->getFailedPayouts( $seller_id );

		$this->assertCount( 0, $result );
	}

	/**
	 * Test getPayoutStats calculates correctly
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_payout_stats_returns_statistics(): void {
		$seller_id = 1;

		$mock_stats = [
			'total_payouts' => 50,
			'total_amount' => 500000,
			'completed_amount' => 450000,
			'pending_amount' => 50000,
			'failed_count' => 5,
			'success_rate' => 90.0,
			'avg_amount' => 10000,
			'min_amount' => 1000,
			'max_amount' => 50000,
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getStatistics' )
			->with( $seller_id )
			->willReturn( $mock_stats );

		$result = $this->service->getPayoutStats( $seller_id );

		$this->assertEquals( 50, $result->total_payouts );
		$this->assertEquals( 500000, $result->total_amount );
		$this->assertEquals( 90.0, $result->success_rate );
	}

	/**
	 * Test stats completionRate calculation
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_payout_stats_completion_rate(): void {
		$fake_stats = [
			'total_payouts' => 100,
			'total_amount' => 1000000,
			'completed_amount' => 900000,
			'pending_amount' => 100000,
			'failed_count' => 10,
			'success_rate' => 90.0,
			'avg_amount' => 10000,
			'min_amount' => 1000,
			'max_amount' => 50000,
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'getStatistics' )
			->with( 1 )
			->willReturn( $fake_stats );

		$stats = $this->service->getPayoutStats( 1 );

		$this->assertEquals( 90, $stats->getCompletionRate() );
	}

	/**
	 * Test PayoutDashboardData formatting
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_payout_dashboard_data_formatting(): void {
		$data = new PayoutDashboardData(
			1,
			1,
			100,
			100000,
			10000,
			90000,
			'completed',
			'txn_123',
			'stripe',
			new \DateTime( '2024-01-15 10:00:00' ),
			new \DateTime( '2024-01-15 10:05:00' )
		);

		$this->assertEquals( '$1000.00', $data->getFormattedAmount( 100000 ) );
		$this->assertEquals( 'status-completed', $data->getStatusClass() );
	}

	/**
	 * Test PayoutDashboardData status labels
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_payout_dashboard_data_status_labels(): void {
		$statuses = [ 'pending', 'completed', 'failed', 'permanently_failed' ];

		foreach ( $statuses as $status ) {
			$data = new PayoutDashboardData(
				1,
				1,
				100,
				100000,
				10000,
				90000,
				$status,
				null,
				null,
				new \DateTime(),
				null
			);

			$label = $data->getStatusLabel();
			$this->assertNotEmpty( $label );
		}
	}

	/**
	 * Test encrypted transaction ID decryption
	 *
	 * @requirement REQ-4E-001
	 * @return void
	 */
	public function test_get_payout_details_decrypts_transaction_id(): void {
		$payout_id = 1;
		$encrypted_txn = 'enc_data_xyz';
		$decrypted_txn = 'stripe_ch_123';

		$mock_payout = [
			'id' => $payout_id,
			'seller_id' => 1,
			'auction_id' => 100,
			'gross_amount' => 100000,
			'commission_amount' => 10000,
			'net_amount' => 90000,
			'status' => 'completed',
			'transaction_id' => $encrypted_txn,
			'transaction_id_encrypted' => true,
			'processor' => 'stripe',
			'created_at' => '2024-01-15 10:00:00',
			'completed_at' => '2024-01-15 10:05:00',
		];

		$this->payout_repository->expects( $this->once() )
			->method( 'findById' )
			->willReturn( $mock_payout );

		$this->encryption_service->expects( $this->once() )
			->method( 'decrypt' )
			->with( $encrypted_txn )
			->willReturn( $decrypted_txn );

		$result = $this->service->getPayoutDetails( $payout_id );

		$this->assertEquals( $decrypted_txn, $result->transaction_id );
	}
}
