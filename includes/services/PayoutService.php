<?php
/**
 * Payout Service - Orchestrates payout execution
 *
 * @package    WooCommerce Auction
 * @subpackage Services
 * @version    4.0.0
 * @requirement REQ-4D-031: Orchestrate payout execution
 * @requirement REQ-4D-032: Coordinate with payment adapters
 * @requirement REQ-4D-033: Manage payout state transitions
 */

namespace WC\Auction\Services;

use WC\Auction\Models\SellerPayout;
use WC\Auction\Models\SettlementBatch;
use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Repositories\PayoutRepository;
use WC\Auction\Contracts\IPaymentProcessorAdapter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PayoutService - Orchestrates seller payout execution
 *
 * UML Class Diagram:
 * ```
 * PayoutService (Orchestrator)
 * ├── Dependencies:
 * │   ├── PaymentProcessorFactory
 * │   └── PayoutRepository
 * ├── Core Methods:
 * │   ├── initiateSellerPayout(batch, seller_id, amount, method) : SellerPayout
 * │   ├── getPayoutStatus(payout_id) : string
 * │   ├── processPayoutBatch(batch) : void
 * │   ├── retryFailedPayout(payout_id) : SellerPayout
 * │   ├── getBatchPayouts(batch_id) : SellerPayout[]
 * │   ├── calculateBatchTotalAmount(batch_id) : int
 * │   └── validatePayout(batch, payout) : void
 * └── Algorithm (initiate payout):
 *     1. Validate inputs
 *     2. Create pending record
 *     3. Get payment adapter
 *     4. Call adapter->initiatePayment()
 *     5. Update record with transaction data
 *     6. Return updated payout
 * ```
 *
 * Responsibilities:
 * - Validate payout requests (amount, method, seller)
 * - Coordinate with payment processing adapters
 * - Manage payout state transitions (PENDING → PROCESSING → COMPLETED/FAILED)
 * - Query payout history and status
 * - Handle failures and retries
 * - Calculate batch totals
 *
 * Design Pattern: Service/Orchestrator
 * - Depends on abstractions (factory interface)
 * - Uses dependency injection
 * - Single responsibility: orchestration
 * - Error handling: catches adapter exceptions, marks failed
 *
 * @requirement REQ-4D-031: Orchestrate payout execution
 * @requirement REQ-4D-032: Coordinate with adapters
 * @requirement REQ-4D-033: Manage state transitions
 * @requirement PERF-4D-001: Payout < 100ms per record
 */
class PayoutService {

    /**
     * Payment processor factory
     *
     * @var PaymentProcessorFactory
     */
    private $factory;

    /**
     * Payout repository
     *
     * @var PayoutRepository
     */
    private $repository;

    /**
     * Constructor
     *
     * @param PaymentProcessorFactory $factory Payment processor factory
     * @param PayoutRepository        $repository Payout repository
     */
    public function __construct(
        PaymentProcessorFactory $factory,
        PayoutRepository $repository
    ) {
        $this->factory     = $factory;
        $this->repository  = $repository;
    }

    /**
     * Initiate seller payout
     *
     * @param SettlementBatch $batch Settlement batch
     * @param int             $seller_id Seller user ID
     * @param int             $amount_cents Amount in cents
     * @param string|null     $method_type Payout method (null = use primary)
     * @return SellerPayout Updated payout with transaction data
     * @throws \Exception If payout fails
     * @requirement REQ-4D-031: Initiate payout execution
     * @requirement REQ-4D-032: Coordinate with processor
     */
    public function initiateSellerPayout(
        SettlementBatch $batch,
        int $seller_id,
        int $amount_cents,
        ?string $method_type = null
    ): SellerPayout {
        // Step 1: Validate inputs
        if ( $amount_cents <= 0 ) {
            throw new \InvalidArgumentException( 'Payout amount must be positive' );
        }

        // Step 2: Create pending payout record
        $payout = SellerPayout::create(
            null,
            $batch->getId(),
            $seller_id,
            $amount_cents,
            $method_type ?? SellerPayoutMethod::METHOD_PAYPAL,
            SellerPayout::STATUS_PENDING
        );

        // Validate payout
        $this->validatePayout( $batch, $payout );

        // Save initial record
        $payout_id = $this->repository->save( $payout );
        $payout->setId( $payout_id );

        try {
            // Step 3: Get payment adapter
            $adapter = $this->factory->getAdapter( $payout->getMethodType() );

            if ( null === $adapter ) {
                throw new \Exception( "No adapter available for method: {$payout->getMethodType()}" );
            }

            // Step 4: Get seller payout method
            // TODO: Implement PayoutMethodManager to fetch and decrypt
            $seller_method = $this->getSellerPayoutMethod( $seller_id, $payout->getMethodType() );

            // Step 5: Call adapter to initiate payment
            $transaction_result = $adapter->initiatePayment(
                "{$payout_id}-" . time(),
                $amount_cents,
                $seller_method
            );

            // Step 6: Update payout with transaction data
            $payout->setTransactionId( $transaction_result->getTransactionId() );
            $payout->setProcessorName( $transaction_result->getProcessorName() );
            $payout->setProcessorFeesCents( $transaction_result->getProcessorFeesCents() );
            $payout->setNetPayoutCents( $transaction_result->getNetPayoutCents() );
            $payout->setStatus( $transaction_result->getStatus() );

            // Save updated record
            $this->repository->update( $payout );

            return $payout;

        } catch ( \Exception $e ) {
            // Mark payout as failed
            $payout->setStatus( SellerPayout::STATUS_FAILED );
            $payout->setErrorMessage( $e->getMessage() );
            $this->repository->update( $payout );

            throw $e;
        }
    }

    /**
     * Get current payout status from processor
     *
     * @param int $payout_id Payout ID
     * @return string Current status
     * @throws \Exception If payout not found
     * @requirement REQ-4D-033: Sync status from processor
     */
    public function getPayoutStatus( int $payout_id ): string {
        // Retrieve payout
        $payout = $this->repository->find( $payout_id );
        if ( null === $payout ) {
            throw new \Exception( "Payout not found: {$payout_id}" );
        }

        // If already terminal, return as-is
        if ( $payout->isCompleted() || $payout->isFailed() || $payout->isCancelled() ) {
            return $payout->getStatus();
        }

        // Get adapter
        $adapter = $this->factory->getAdapter( $payout->getMethodType() );
        if ( null === $adapter || null === $payout->getTransactionId() ) {
            return $payout->getStatus();
        }

        try {
            // Poll processor for status
            $transaction_result = $adapter->getTransactionStatus( $payout->getTransactionId() );

            // Update payout
            $payout->setStatus( $transaction_result->getStatus() );
            if ( $transaction_result->isCompleted() ) {
                $payout->setCompletedAt( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
            }

            $this->repository->update( $payout );

            return $transaction_result->getStatus();

        } catch ( \Exception $e ) {
            error_log( "Error polling payout status: {$e->getMessage()}" );
            return $payout->getStatus();
        }
    }

    /**
     * Process all pending payouts in batch
     *
     * @param SettlementBatch $batch Settlement batch
     * @return int Number of payouts processed
     * @requirement REQ-4D-031: Process batch payouts
     */
    public function processPayoutBatch( SettlementBatch $batch ): int {
        $payouts = $this->repository->findByBatchAndStatus(
            $batch->getId(),
            SellerPayout::STATUS_PENDING
        );

        $processed = 0;
        foreach ( $payouts as $payout ) {
            try {
                // Re-initiate using stored data
                $seller_method = $this->getSellerPayoutMethod( $payout->getSellerId(), $payout->getMethodType() );
                $adapter = $this->factory->getAdapter( $payout->getMethodType() );

                if ( null === $adapter ) {
                    $payout->setStatus( SellerPayout::STATUS_FAILED );
                    $payout->setErrorMessage( "No adapter for method: {$payout->getMethodType()}" );
                    $this->repository->update( $payout );
                    continue;
                }

                // Initiate payment
                $transaction_result = $adapter->initiatePayment(
                    "{$payout->getId()}-" . time(),
                    $payout->getAmountCents(),
                    $seller_method
                );

                // Update payout
                $payout->setTransactionId( $transaction_result->getTransactionId() );
                $payout->setProcessorName( $transaction_result->getProcessorName() );
                $payout->setProcessorFeesCents( $transaction_result->getProcessorFeesCents() );
                $payout->setNetPayoutCents( $transaction_result->getNetPayoutCents() );
                $payout->setStatus( $transaction_result->getStatus() );
                $this->repository->update( $payout );
                $processed++;

            } catch ( \Exception $e ) {
                // Mark failed and continue
                error_log( "Error processing payout {$payout->getId()}: {$e->getMessage()}" );
                $payout->setStatus( SellerPayout::STATUS_FAILED );
                $payout->setErrorMessage( $e->getMessage() );
                $this->repository->update( $payout );
            }
        }

        return $processed;
    }

    /**
     * Retry a failed payout
     *
     * @param int $payout_id Payout ID
     * @return SellerPayout Updated payout
     * @throws \Exception If payout not found
     * @requirement REQ-4D-031: Retry failed payouts
     */
    public function retryFailedPayout( int $payout_id ): SellerPayout {
        $payout = $this->repository->find( $payout_id );
        if ( null === $payout ) {
            throw new \Exception( "Payout not found: {$payout_id}" );
        }

        if ( ! $payout->isFailed() ) {
            throw new \Exception( "Payout is not in failed state: {$payout_id}" );
        }

        // Reset to pending
        $payout->setStatus( SellerPayout::STATUS_PENDING );
        $payout->setErrorMessage( null );
        $this->repository->update( $payout );

        // Re-initiate with minimal batch info (batch_id is stored in payout)
        // NOTE: For retry, we create a simplified batch object since we don't have the full settlement batch
        // In production, this would be injected via SettlementBatchRepository
        $minimal_batch = SettlementBatch::fromDatabase( [
            'id' => $payout->getBatchId(),
            'batch_number' => 'retry-' . $payout_id,
            'settlement_date' => gmdate( 'Y-m-d H:i:s' ),
            'batch_period_start' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
            'batch_period_end' => gmdate( 'Y-m-d H:i:s' ),
            'status' => SettlementBatch::STATUS_PROCESSING,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );

        return $this->initiateSellerPayout(
            $minimal_batch,
            $payout->getSellerId(),
            $payout->getAmountCents(),
            $payout->getMethodType()
        );
    }

    /**
     * Get all payouts for batch
     *
     * @param int $batch_id Batch ID
     * @return SellerPayout[]
     * @requirement REQ-4D-031: Query batch payouts
     */
    public function getBatchPayouts( int $batch_id ): array {
        return $this->repository->findByBatch( $batch_id );
    }

    /**
     * Calculate total payout amount for batch (excluding failed)
     *
     * @param int $batch_id Batch ID
     * @return int Total amount in cents
     * @requirement REQ-4D-031: Calculate batch amounts
     */
    public function calculateBatchTotalAmount( int $batch_id ): int {
        $payouts = $this->repository->findByBatch( $batch_id );

        $total = 0;
        foreach ( $payouts as $payout ) {
            if ( ! $payout->isFailed() && ! $payout->isCancelled() ) {
                $total += $payout->getAmountCents();
            }
        }

        return $total;
    }

    /**
     * Validate payout before processing
     *
     * @param SettlementBatch $batch Settlement batch
     * @param SellerPayout    $payout Payout to validate
     * @throws \InvalidArgumentException If validation fails
     * @requirement REQ-4D-031: Validate payout input
     */
    public function validatePayout( SettlementBatch $batch, SellerPayout $payout ): void {
        // Check amount
        if ( $payout->getAmountCents() <= 0 ) {
            throw new \InvalidArgumentException( 'Payout amount must be positive' );
        }

        // Check batch exists and is valid
        if ( null === $batch->getId() ) {
            throw new \InvalidArgumentException( 'Batch ID required' );
        }

        // Check seller has payout method
        $method = $this->getSellerPayoutMethod( $payout->getSellerId(), $payout->getMethodType() );
        if ( null === $method ) {
            throw new \LogicException( "Seller {$payout->getSellerId()} has no payout method: {$payout->getMethodType()}" );
        }
    }

    // ===== Helper Methods =====

    /**
     * Get seller payout method
     *
     * @param int    $seller_id Seller ID
     * @param string $method_type Method type
     * @return SellerPayoutMethod|null
     */
    private function getSellerPayoutMethod( int $seller_id, string $method_type ): ?SellerPayoutMethod {
        // TODO: Implement via PayoutMethodManager (Phase 2-4)
        // For now, return mock method for testing
        return SellerPayoutMethod::create(
            $seller_id,
            $method_type,
            true,
            'Seller Name',
            '1234',
            'encrypted_data',
            true,
            new \DateTime( 'now' )
        );
    }

    /**
     * Get settlement batch
     *
     * @param int $batch_id Batch ID
     * @return SettlementBatch|null
     */
    private function getSettlementBatch( int $batch_id ): ?SettlementBatch {
        // TODO: Inject SettlementBatchRepository
        return null;
    }
}
