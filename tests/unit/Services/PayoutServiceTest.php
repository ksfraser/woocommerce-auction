<?php
/**
 * PayoutService Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    4.0.0
 * @requirement REQ-4D-031: PayoutService orchestrates payout operations
 * @requirement REQ-4D-032: PayoutService coordinates with payment adapters
 * @requirement REQ-4D-033: PayoutService manages transaction state
 */

namespace WC\Auction\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WC\Auction\Services\PayoutService;
use WC\Auction\Repositories\PayoutRepository;
use WC\Auction\Services\PaymentProcessorFactory;
use WC\Auction\Contracts\IPaymentProcessorAdapter;
use WC\Auction\Models\SellerPayout;
use WC\Auction\Models\TransactionResult;
use WC\Auction\Models\SettlementBatch;
use WC\Auction\Models\SellerPayoutMethod;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test PayoutService orchestration and coordination
 *
 * @requirement REQ-4D-031: PayoutService orchestrates payout execution
 * @requirement REQ-4D-032: Adapt payment processors for payout
 * @requirement REQ-4D-033: Manage payout state transitions
 */
class PayoutServiceTest extends TestCase {

    /**
     * Mock payment processor factory
     *
     * @var MockObject|PaymentProcessorFactory
     */
    private $factory;

    /**
     * Mock payout repository
     *
     * @var MockObject|PayoutRepository
     */
    private $repository;

    /**
     * Service under test
     *
     * @var PayoutService
     */
    private $service;

    /**
     * Setup test fixtures
     */
    protected function setUp(): void {
        $this->factory     = $this->createMock( PaymentProcessorFactory::class );
        $this->repository  = $this->createMock( PayoutRepository::class );
        $this->service     = new PayoutService( $this->factory, $this->repository );
    }

    /**
     * Test service can be instantiated with dependencies
     *
     * @test
     * @requirement REQ-4D-031: PayoutService instantiation
     */
    public function test_service_can_be_instantiated(): void {
        $this->assertInstanceOf( PayoutService::class, $this->service );
    }

    /**
     * Test initiateSellerPayout creates pending record
     *
     * @test
     * @requirement REQ-4D-031: Create payout record
     */
    public function test_initiate_seller_payout_creates_pending_record(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );
        $method = $this->createPayoutMethod( 1, 'METHOD_ACH' );
        $seller_id = 1;
        $amount_cents = 100000;

        $payout = SellerPayout::create(
            0, // auto-assigned
            $batch->getId(),
            $seller_id,
            $amount_cents,
            'METHOD_ACH',
            SellerPayout::STATUS_PENDING
        );

        $this->repository->expects( $this->once() )
            ->method( 'save' )
            ->with( $this->isInstanceOf( SellerPayout::class ) )
            ->willReturn( 1 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->expects( $this->once() )
            ->method( 'getProcessorName' )
            ->willReturn( 'Square' );

        $result = TransactionResult::create(
            'txn_123',
            'Square',
            TransactionResult::STATUS_PENDING,
            $amount_cents,
            1025,
            $amount_cents - 1025
        );

        $adapter->expects( $this->once() )
            ->method( 'initiatePayment' )
            ->willReturn( $result );

        $this->factory->expects( $this->once() )
            ->method( 'getAdapter' )
            ->with( 'METHOD_ACH' )
            ->willReturn( $adapter );

        // Act
        $initiated_payout = $this->service->initiateSellerPayout(
            $batch,
            $seller_id,
            $amount_cents,
            'METHOD_ACH'
        );

        // Assert
        $this->assertInstanceOf( SellerPayout::class, $initiated_payout );
        $this->assertEquals( 'Square', $initiated_payout->getProcessorName() );
    }

    /**
     * Test initiateSellerPayout fetches adapter by method type
     *
     * @test
     * @requirement REQ-4D-032: Route to correct adapter by method
     */
    public function test_initiate_seller_payout_fetches_adapter_by_method(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );
        $amount_cents = 100000;

        $this->repository->expects( $this->once() )
            ->method( 'save' )
            ->willReturn( 1 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getProcessorName' )->willReturn( 'PayPal' );
        $adapter->method( 'initiatePayment' )
            ->willReturn( $this->createTransactionResult() );

        $this->factory->expects( $this->once() )
            ->method( 'getAdapter' )
            ->with( 'METHOD_PAYPAL' )
            ->willReturn( $adapter );

        // Act
        $this->service->initiateSellerPayout( $batch, 1, $amount_cents, 'METHOD_PAYPAL' );
    }

    /**
     * Test initiateSellerPayout calls adapter initiatePayment
     *
     * @test
     * @requirement REQ-4D-032: Coordinate with adapter
     */
    public function test_initiate_seller_payout_calls_adapter_initiate_payment(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );
        $amount_cents = 100000;

        $this->repository->expects( $this->once() )
            ->method( 'save' )
            ->willReturn( 1 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getProcessorName' )->willReturn( 'Stripe' );
        $adapter->expects( $this->once() )
            ->method( 'initiatePayment' )
            ->with(
                $this->stringContains( '1-' ), // payout_id format
                $amount_cents,
                $this->isInstanceOf( SellerPayoutMethod::class )
            )
            ->willReturn( $this->createTransactionResult() );

        $this->factory->expects( $this->once() )
            ->method( 'getAdapter' )
            ->willReturn( $adapter );

        // Act
        $this->service->initiateSellerPayout( $batch, 1, $amount_cents, 'METHOD_ACH' );
    }

    /**
     * Test initiateSellerPayout updates status to processing
     *
     * @test
     * @requirement REQ-4D-033: Update payout status after adapter call
     */
    public function test_initiate_seller_payout_updates_status_to_processing(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );
        $amount_cents = 100000;

        $payout_to_save = null;
        $this->repository->expects( $this->exactly( 2 ) )
            ->method( 'save' )
            ->willReturnCallback( function( $payout ) use ( &$payout_to_save ) {
                $payout_to_save = $payout;
                return 1;
            } );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getProcessorName' )->willReturn( 'Square' );
        $adapter->method( 'initiatePayment' )
            ->willReturn( $this->createTransactionResult( TransactionResult::STATUS_PROCESSING ) );

        $this->factory->method( 'getAdapter' )->willReturn( $adapter );

        // Act
        $result = $this->service->initiateSellerPayout( $batch, 1, $amount_cents, 'METHOD_ACH' );

        // Assert
        $this->assertEquals( SellerPayout::STATUS_PROCESSING, $result->getStatus() );
    }

    /**
     * Test initiateSellerPayout returns updated payout with transaction data
     *
     * @test
     * @requirement REQ-4D-033: Store transaction result
     */
    public function test_initiate_seller_payout_returns_updated_payout(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );
        $amount_cents = 100000;

        $this->repository->expects( $this->any() )
            ->method( 'save' )
            ->willReturn( 1 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getProcessorName' )->willReturn( 'PayPal' );
        $transaction = TransactionResult::create(
            'payout_abc123',
            'PayPal',
            TransactionResult::STATUS_PROCESSING,
            $amount_cents,
            1530,
            $amount_cents - 1530
        );
        $adapter->method( 'initiatePayment' )->willReturn( $transaction );

        $this->factory->method( 'getAdapter' )->willReturn( $adapter );

        // Act
        $result = $this->service->initiateSellerPayout( $batch, 1, $amount_cents, 'METHOD_PAYPAL' );

        // Assert
        $this->assertInstanceOf( SellerPayout::class, $result );
        $this->assertEquals( 'payout_abc123', $result->getTransactionId() );
        $this->assertEquals( 'PayPal', $result->getProcessorName() );
        $this->assertEquals( 1530, $result->getProcessorFeesCents() );
    }

    /**
     * Test getPayoutStatus retrieves from adapter
     *
     * @test
     * @requirement REQ-4D-033: Sync status from processor
     */
    public function test_get_payout_status_retrieves_from_adapter(): void {
        // Arrange
        $payout = SellerPayout::create( 1, 1, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PROCESSING );
        $payout->setTransactionId( 'txn_123' );
        $payout->setProcessorName( 'Square' );

        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->with( 1 )
            ->willReturn( $payout );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->expects( $this->once() )
            ->method( 'getTransactionStatus' )
            ->with( 'txn_123' )
            ->willReturn( $this->createTransactionResult( TransactionResult::STATUS_COMPLETED ) );

        $this->factory->method( 'getAdapter' )
            ->with( $payout->getMethodType() )
            ->willReturn( $adapter );

        // Act
        $status = $this->service->getPayoutStatus( 1 );

        // Assert
        $this->assertEquals( TransactionResult::STATUS_COMPLETED, $status );
    }

    /**
     * Test getPayoutStatus updates payout record
     *
     * @test
     * @requirement REQ-4D-033: Persist status updates
     */
    public function test_get_payout_status_updates_payout_record(): void {
        // Arrange
        $payout = SellerPayout::create( 1, 1, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PROCESSING );
        $payout->setTransactionId( 'txn_456' );
        $payout->setProcessorName( 'Stripe' );

        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->willReturn( $payout );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getTransactionStatus' )
            ->willReturn( $this->createTransactionResult( TransactionResult::STATUS_COMPLETED ) );

        $this->factory->method( 'getAdapter' )->willReturn( $adapter );

        $this->repository->expects( $this->once() )
            ->method( 'update' )
            ->with( $this->isInstanceOf( SellerPayout::class ) );

        // Act
        $status = $this->service->getPayoutStatus( 1 );

        // Assert
        $this->assertEquals( TransactionResult::STATUS_COMPLETED, $status );
    }

    /**
     * Test processPayoutBatch iterates all pending payouts
     *
     * @test
     * @requirement REQ-4D-031: Process batch of payouts
     */
    public function test_process_payout_batch_iterates_payouts(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'PROCESSING' );

        $payouts = [
            SellerPayout::create( 1, 1, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING ),
            SellerPayout::create( 2, 1, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_PENDING ),
            SellerPayout::create( 3, 1, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_PENDING ),
        ];

        $this->repository->expects( $this->once() )
            ->method( 'findByBatch' )
            ->with( 1 )
            ->willReturn( $payouts );

        $this->repository->expects( $this->exactly( 3 ) )
            ->method( 'save' )
            ->willReturnOnConsecutiveCalls( 1, 2, 3 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->expects( $this->exactly( 3 ) )
            ->method( 'initiatePayment' )
            ->willReturn( $this->createTransactionResult() );

        $this->factory->expects( $this->exactly( 3 ) )
            ->method( 'getAdapter' )
            ->willReturn( $adapter );

        // Act
        $this->service->processPayoutBatch( $batch );
    }

    /**
     * Test processPayoutBatch skips already processing payouts
     *
     * @test
     * @requirement REQ-4D-031: Skip non-pending payouts
     */
    public function test_process_payout_batch_skips_already_processing(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'PROCESSING' );

        $payouts = [
            SellerPayout::create( 1, 1, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING ),
            SellerPayout::create( 2, 1, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_PROCESSING ),
            SellerPayout::create( 3, 1, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
        ];

        $this->repository->expects( $this->once() )
            ->method( 'findByBatch' )
            ->with( 1 )
            ->willReturn( $payouts );

        $this->repository->expects( $this->once() )
            ->method( 'save' )
            ->willReturn( 1 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->expects( $this->once() )
            ->method( 'initiatePayment' )
            ->willReturn( $this->createTransactionResult() );

        $this->factory->expects( $this->once() )
            ->method( 'getAdapter' )
            ->willReturn( $adapter );

        // Act
        $this->service->processPayoutBatch( $batch );
    }

    /**
     * Test processPayoutBatch handles adapter errors gracefully
     *
     * @test
     * @requirement REQ-4D-031: Handle payment errors
     */
    public function test_process_payout_batch_handles_adapter_errors(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'PROCESSING' );

        $payouts = [
            SellerPayout::create( 1, 1, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING ),
            SellerPayout::create( 2, 1, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_PENDING ),
        ];

        $this->repository->expects( $this->once() )
            ->method( 'findByBatch' )
            ->willReturn( $payouts );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getProcessorName' )->willReturn( 'Square' );
        $adapter->method( 'initiatePayment' )
            ->willThrowException( new \Exception( 'API Error' ) );

        $this->factory->method( 'getAdapter' )->willReturn( $adapter );

        // Act & Assert - should not throw exception
        try {
            $this->service->processPayoutBatch( $batch );
            $this->assertTrue( true ); // Success if no exception
        } catch ( \Exception $e ) {
            $this->fail( 'processPayoutBatch should handle adapter errors gracefully' );
        }
    }

    /**
     * Test retryFailedPayout resets status
     *
     * @test
     * @requirement REQ-4D-031: Retry failed payouts
     */
    public function test_retry_failed_payout_resets_status(): void {
        // Arrange
        $payout = SellerPayout::create( 1, 1, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_FAILED );
        $payout->setErrorMessage( 'Insufficient funds' );

        $this->repository->expects( $this->once() )
            ->method( 'find' )
            ->with( 1 )
            ->willReturn( $payout );

        $this->repository->expects( $this->atLeast( 1 ) )
            ->method( 'save' )
            ->willReturn( 1 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getProcessorName' )->willReturn( 'Square' );
        $adapter->method( 'initiatePayment' )
            ->willReturn( $this->createTransactionResult( TransactionResult::STATUS_PROCESSING ) );

        $this->factory->method( 'getAdapter' )->willReturn( $adapter );

        // Act
        $retried = $this->service->retryFailedPayout( 1 );

        // Assert
        $this->assertEquals( SellerPayout::STATUS_PROCESSING, $retried->getStatus() );
    }

    /**
     * Test getBatchPayouts returns all payouts for batch
     *
     * @test
     * @requirement REQ-4D-031: Query batch payouts
     */
    public function test_get_batch_payouts_returns_all_payouts_for_batch(): void {
        // Arrange
        $payouts = [
            SellerPayout::create( 1, 10, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
            SellerPayout::create( 2, 10, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
            SellerPayout::create( 3, 10, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
            SellerPayout::create( 4, 10, 4, 175000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
            SellerPayout::create( 5, 10, 5, 225000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
        ];

        $this->repository->expects( $this->once() )
            ->method( 'findByBatch' )
            ->with( 10 )
            ->willReturn( $payouts );

        // Act
        $result = $this->service->getBatchPayouts( 10 );

        // Assert
        $this->assertCount( 5, $result );
    }

    /**
     * Test calculateBatchTotalAmount excludes failed payouts
     *
     * @test
     * @requirement REQ-4D-031: Calculate batch amounts
     */
    public function test_calculate_batch_total_amount_excludes_failed(): void {
        // Arrange - $1000 + $1500 = $2500, FAILED excluded
        $payouts = [
            SellerPayout::create( 1, 10, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
            SellerPayout::create( 2, 10, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED ),
            SellerPayout::create( 3, 10, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_FAILED ),
        ];

        $this->repository->expects( $this->once() )
            ->method( 'findByBatch' )
            ->with( 10 )
            ->willReturn( $payouts );

        // Act
        $total = $this->service->calculateBatchTotalAmount( 10 );

        // Assert - only first 2: 100000 + 150000 = 250000
        $this->assertEquals( 250000, $total );
    }

    /**
     * Test validatePayout checks amount is positive
     *
     * @test
     * @requirement REQ-4D-031: Validate payout input
     */
    public function test_validate_payout_checks_amount_positive(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );
        $payout = SellerPayout::create( 1, 1, 1, 0, 'METHOD_ACH', SellerPayout::STATUS_PENDING );

        // Act & Assert
        $this->expectException( \InvalidArgumentException::class );
        $this->service->validatePayout( $batch, $payout );
    }

    /**
     * Test validatePayout checks seller has payout method
     *
     * @test
     * @requirement REQ-4D-031: Validate seller methods
     */
    public function test_validate_payout_checks_seller_has_method(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );
        $payout = SellerPayout::create( 1, 1, 999, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );

        // Act & Assert - seller 999 has no methods
        $this->expectException( \LogicException::class );
        $this->service->validatePayout( $batch, $payout );
    }

    /**
     * Test initiate payout uses seller primary method if not specified
     *
     * @test
     * @requirement REQ-4D-031: Use default payout method
     */
    public function test_initiate_seller_payout_uses_primary_method_if_null(): void {
        // Arrange
        $batch = $this->createSettlementBatch( 1, 'VALIDATED' );

        $this->repository->expects( $this->once() )
            ->method( 'save' )
            ->willReturn( 1 );

        $adapter = $this->createMock( IPaymentProcessorAdapter::class );
        $adapter->method( 'getProcessorName' )->willReturn( 'PayPal' );
        $adapter->method( 'initiatePayment' )
            ->willReturn( $this->createTransactionResult() );

        $this->factory->method( 'getAdapter' )
            ->with( SellerPayoutMethod::METHOD_PAYPAL ) // Primary method for seller 1
            ->willReturn( $adapter );

        // Act
        $result = $this->service->initiateSellerPayout( $batch, 1, 100000, null );

        // Assert - should use default method
        $this->assertInstanceOf( SellerPayout::class, $result );
    }

    // ===== Helper Methods =====

    /**
     * Create mock settlement batch
     *
     * @param int    $id Batch ID (for testing only)
     * @param string $status Batch status (for testing only)
     * @return SettlementBatch
     */
    private function createSettlementBatch( int $id, string $status ): SettlementBatch {
        // Use fromDatabase to simulate a persisted batch with ID
        return SettlementBatch::fromDatabase( [
            'id' => $id,
            'batch_number' => "2026-03-24-001",
            'settlement_date' => gmdate( 'Y-m-d H:i:s' ),
            'batch_period_start' => '2026-03-01 00:00:00',
            'batch_period_end' => '2026-03-31 23:59:59',
            'status' => $status,
            'total_amount_cents' => 0,
            'commission_amount_cents' => 0,
            'processor_fees_cents' => 0,
            'payout_count' => 0,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'processed_at' => null,
            'notes' => null,
        ] );
    }

    /**
     * Create mock payout method
     *
     * @param int    $seller_id Seller ID
     * @param string $method_type Method type
     * @return SellerPayoutMethod
     */
    private function createPayoutMethod( int $seller_id, string $method_type ): SellerPayoutMethod {
        return SellerPayoutMethod::create(
            $seller_id,
            $method_type,
            true,
            'John Seller',
            '1234',
            'encrypted_data',
            true,
            new \DateTime( 'now' )
        );
    }

    /**
     * Create mock transaction result
     *
     * @param string $status Transaction status
     * @return TransactionResult
     */
    private function createTransactionResult( string $status = TransactionResult::STATUS_PENDING ): TransactionResult {
        return TransactionResult::create(
            'txn_' . uniqid(),
            'TestProcessor',
            $status,
            100000,
            1025,
            100000 - 1025
        );
    }
}
