<?php
/**
 * Payout Dashboard Service
 *
 * @package YITH_Auctions\Services
 * @subpackage Dashboard
 * @version 1.0.0
 * @requirement REQ-4E-001
 */

namespace YITH_Auctions\Services;

use YITH_Auctions\Models\PayoutDashboardData;
use YITH_Auctions\Models\DashboardStats;
use YITH_Auctions\Repositories\SellerPayoutRepository;
use YITH_Auctions\Services\EncryptionService;

/**
 * Service for managing payout dashboard data
 *
 * Database-driven service providing filtered, sorted, and aggregated payout data
 * for dashboard display. Utilizes repository pattern for data access and
 * encryption for sensitive fields.
 *
 * @requirement REQ-4E-001 - Seller payout display (paginated lists, filters)
 */
class PayoutDashboardService {
	/**
	 * Seller payout repository
	 *
	 * @var SellerPayoutRepository
	 */
	private SellerPayoutRepository $payout_repository;

	/**
	 * Encryption service for sensitive data
	 *
	 * @var EncryptionService
	 */
	private EncryptionService $encryption_service;

	/**
	 * Constructor
	 *
	 * @param SellerPayoutRepository $payout_repository Payout data access.
	 * @param EncryptionService      $encryption_service Encryption provider.
	 * @since 1.0.0
	 */
	public function __construct(
		SellerPayoutRepository $payout_repository,
		EncryptionService $encryption_service
	) {
		$this->payout_repository = $payout_repository;
		$this->encryption_service = $encryption_service;
	}

	/**
	 * Get seller payouts with pagination and filters
	 *
	 * @param int   $seller_id Seller identifier.
	 * @param int   $page Current page number.
	 * @param int   $per_page Items per page.
	 * @param array $filters Optional filters (status, date_range, etc.).
	 * @return array {
	 *   @type PayoutDashboardData[] $payouts Payout records
	 *   @type int                  $total Total count
	 *   @type int                  $pages Total pages
	 * }
	 * @throws \Exception If data fetch fails.
	 * @requirement REQ-4E-001
	 * @since 1.0.0
	 */
	public function getSellerPayouts(
		int $seller_id,
		int $page = 1,
		int $per_page = 20,
		array $filters = []
	): array {
		$offset = ( $page - 1 ) * $per_page;

		$payouts = $this->payout_repository->findBySeller(
			$seller_id,
			$offset,
			$per_page,
			$filters
		);

		$total = $this->payout_repository->countBySeller( $seller_id, $filters );

		$payout_data = array_map(
			fn( $payout ) => $this->hydrateDashboardData( $payout ),
			$payouts
		);

		return [
			'payouts' => $payout_data,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Get single payout with detailed information
	 *
	 * @param int $payout_id Payout identifier.
	 * @return PayoutDashboardData|null Payout data or null if not found.
	 * @throws \Exception If decryption fails.
	 * @requirement REQ-4E-001
	 * @since 1.0.0
	 */
	public function getPayoutDetails( int $payout_id ): ?PayoutDashboardData {
		$payout = $this->payout_repository->findById( $payout_id );

		if ( ! $payout ) {
			return null;
		}

		return $this->hydrateDashboardData( $payout );
	}

	/**
	 * Get pending payouts for a seller
	 *
	 * @param int $seller_id Seller identifier.
	 * @return PayoutDashboardData[] Pending payouts.
	 * @throws \Exception If fetch fails.
	 * @requirement REQ-4E-001
	 * @since 1.0.0
	 */
	public function getPendingPayouts( int $seller_id ): array {
		$payouts = $this->payout_repository->findBySeller(
			$seller_id,
			0,
			999,
			[ 'status' => 'pending' ]
		);

		return array_map(
			fn( $payout ) => $this->hydrateDashboardData( $payout ),
			$payouts
		);
	}

	/**
	 * Get failed payouts for a seller
	 *
	 * @param int $seller_id Seller identifier.
	 * @return PayoutDashboardData[] Failed payouts.
	 * @throws \Exception If fetch fails.
	 * @requirement REQ-4E-001
	 * @since 1.0.0
	 */
	public function getFailedPayouts( int $seller_id ): array {
		$payouts = $this->payout_repository->findBySeller(
			$seller_id,
			0,
			999,
			[ 'status' => [ 'failed', 'permanently_failed' ] ]
		);

		return array_map(
			fn( $payout ) => $this->hydrateDashboardData( $payout ),
			$payouts
		);
	}

	/**
	 * Get aggregated payout statistics
	 *
	 * @param int $seller_id Seller identifier.
	 * @return DashboardStats Statistics object.
	 * @throws \Exception If calculation fails.
	 * @requirement REQ-4E-001
	 * @since 1.0.0
	 */
	public function getPayoutStats( int $seller_id ): DashboardStats {
		$stats = $this->payout_repository->getStatistics( $seller_id );

		return new DashboardStats(
			(int) $stats['total_payouts'],
			(int) $stats['total_amount'],
			(int) $stats['completed_amount'],
			(int) $stats['pending_amount'],
			(int) $stats['failed_count'],
			(float) $stats['success_rate'],
			(int) $stats['avg_amount'],
			(int) $stats['min_amount'],
			(int) $stats['max_amount']
		);
	}

	/**
	 * Convert raw database record to PayoutDashboardData
	 *
	 * @param array $record Database record.
	 * @return PayoutDashboardData Hydrated data object.
	 * @throws \Exception If decryption fails.
	 * @internal
	 * @since 1.0.0
	 */
	private function hydrateDashboardData( array $record ): PayoutDashboardData {
		$transaction_id = $record['transaction_id'] ?? null;
		if ( $transaction_id && $record['transaction_id_encrypted'] ?? false ) {
			$transaction_id = $this->encryption_service->decrypt( $transaction_id );
		}

		return new PayoutDashboardData(
			(int) $record['id'],
			(int) $record['seller_id'],
			(int) $record['auction_id'],
			(int) $record['gross_amount'],
			(int) $record['commission_amount'],
			(int) $record['net_amount'],
			(string) $record['status'],
			$transaction_id,
			$record['processor'] ?? null,
			new \DateTime( $record['created_at'] ),
			isset( $record['completed_at'] ) ? new \DateTime( $record['completed_at'] ) : null
		);
	}
}
