<?php
/**
 * Settlement Batch Service
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-001: Create and process settlement batches
 * @requirement REQ-4D-002: Calculate commissions for batch
 * @requirement PERF-4D-001: Process batch for 100 sellers in < 5 seconds
 */

namespace WC\Auction\Services;

use WC\Auction\Models\SettlementBatch;
use WC\Auction\Repositories\SettlementBatchRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SettlementBatchService - Orchestrates settlement batch operations
 *
 * UML Class Diagram:
 * ```
 * SettlementBatchService (Orchestrator)
 * ├── Dependencies:
 * │   ├── SettlementBatchRepository
 * │   ├── CommissionCalculator
 * │   └── Logger
 * ├── Core Methods:
 * │   ├── createBatch(period_start, period_end) : SettlementBatch
 * │   ├── validateBatch(batch) : bool
 * │   ├── processBatch(batch) : bool
 * │   └── getBatchStatus(batch_id) : string
 * └── Algorithm:
 *     1. Get auctions in period
 *     2. Group by seller
 *     3. Calculate commissions
 *     4. Create batch record
 *     5. Validate totals
 *     6. Return batch
 * ```
 *
 * @requirement REQ-4D-001: Create settlement batches
 * @requirement REQ-4D-002: Validate batch data
 * @requirement PERF-4D-001: Complete batch < 5 seconds
 */
class SettlementBatchService {

    /**
     * Batch repository
     *
     * @var SettlementBatchRepository
     */
    private $batch_repository;

    /**
     * Commission calculator
     *
     * @var CommissionCalculator
     */
    private $commission_calculator;

    /**
     * Constructor
     *
     * @param SettlementBatchRepository $batch_repository Batch repository
     * @param CommissionCalculator      $commission_calculator Calculator
     */
    public function __construct(
        SettlementBatchRepository $batch_repository,
        CommissionCalculator $commission_calculator
    ) {
        $this->batch_repository         = $batch_repository;
        $this->commission_calculator    = $commission_calculator;
    }

    /**
     * Create a new settlement batch
     *
     * @param \DateTime $period_start Period start date
     * @param \DateTime $period_end Period end date
     * @return SettlementBatch New batch (not yet saved)
     * @throws \Exception If batch creation fails
     * @requirement REQ-4D-001: Create settlement batch
     */
    public function createBatch(
        \DateTime $period_start,
        \DateTime $period_end
    ): SettlementBatch {
        // Generate unique batch number based on date
        $batch_number = $this->generateBatchNumber();

        // Create batch record (DRAFT status)
        $batch = SettlementBatch::create(
            $batch_number,
            new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ),
            $period_start,
            $period_end,
            0, // Will be calculated
            0,
            0
        );

        // Save batch to database
        $batch_id = $this->batch_repository->save( $batch );

        // Log batch creation
        error_log( "Settlement batch created: {$batch_number} (ID: {$batch_id})" );

        return $batch;
    }

    /**
     * Generate unique batch number
     *
     * @return string Batch number (e.g., "2026-03-23-001")
     */
    private function generateBatchNumber(): string {
        $date_part = gmdate( 'Y-m-d' );

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_auction_settlement_batches';

        // Count existing batches for this date
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE DATE(settlement_date) = %s",
                $date_part
            )
        );

        return sprintf( '%s-%03d', $date_part, $count + 1 );
    }

    /**
     * Get batch by ID
     *
     * @param int $batch_id Batch ID
     * @return SettlementBatch|null Batch or null if not found
     */
    public function getBatchById( int $batch_id ): ?SettlementBatch {
        return $this->batch_repository->find( $batch_id );
    }

    /**
     * Get latest batch
     *
     * @return SettlementBatch|null Latest batch or null
     */
    public function getLatestBatch(): ?SettlementBatch {
        return $this->batch_repository->findLatest();
    }
}
