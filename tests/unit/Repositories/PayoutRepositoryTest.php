<?php
/**
 * PayoutRepository Unit Tests
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    4.0.0
 * @requirement REQ-4D-034: PayoutRepository persists payout data
 * @requirement REQ-4D-035: PayoutRepository queries payouts by various filters
 * @requirement REQ-4D-036: PayoutRepository provides atomic batch updates
 */

namespace WC\Auction\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use WC\Auction\Repositories\PayoutRepository;
use WC\Auction\Models\SellerPayout;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test PayoutRepository data access operations
 *
 * @requirement REQ-4D-034: Persist payout data
 * @requirement REQ-4D-035: Query payouts by status, batch, seller
 * @requirement REQ-4D-036: Atomic batch operations
 */
class PayoutRepositoryTest extends TestCase {

    /**
     * Repository under test
     *
     * @var PayoutRepository
     */
    private $repository;

    /**
     * WordPress database instance
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Setup test fixtures
     */
    protected function setUp(): void {
        $this->repository = new PayoutRepository();
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Test repository can be instantiated
     *
     * @test
     * @requirement REQ-4D-034: Repository initialization
     */
    public function test_repository_can_be_instantiated(): void {
        $this->assertInstanceOf( PayoutRepository::class, $this->repository );
    }

    /**
     * Test save creates new payout record
     *
     * @test
     * @requirement REQ-4D-034: Save new payout
     */
    public function test_save_creates_new_payout_record(): void {
        // Arrange
        $payout = SellerPayout::create(
            null,
            1,
            1,
            100000,
            'METHOD_ACH',
            SellerPayout::STATUS_PENDING
        );

        // Act
        $id = $this->repository->save( $payout );

        // Assert
        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );
    }

    /**
     * Test save persists all fields correctly
     *
     * @test
     * @requirement REQ-4D-034: Persist all payout properties
     */
    public function test_save_persists_all_fields(): void {
        // Arrange
        $payout = SellerPayout::create(
            null,
            5,        // batch_id
            10,       // seller_id
            250000,   // amount_cents
            'METHOD_PAYPAL',
            SellerPayout::STATUS_PENDING
        );

        // Act
        $id = $this->repository->save( $payout );

        // Assert - find and verify
        $found = $this->repository->find( $id );
        $this->assertNotNull( $found );
        $this->assertEquals( 5, $found->getBatchId() );
        $this->assertEquals( 10, $found->getSellerId() );
        $this->assertEquals( 250000, $found->getAmountCents() );
        $this->assertEquals( 'METHOD_PAYPAL', $found->getMethodType() );
        $this->assertEquals( SellerPayout::STATUS_PENDING, $found->getStatus() );
    }

    /**
     * Test find retrieves payout by ID
     *
     * @test
     * @requirement REQ-4D-035: Find payout by ID
     */
    public function test_find_retrieves_payout_by_id(): void {
        // Arrange
        $payout = SellerPayout::create( null, 2, 20, 500000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $id = $this->repository->save( $payout );

        // Act
        $found = $this->repository->find( $id );

        // Assert
        $this->assertNotNull( $found );
        $this->assertEquals( $id, $found->getId() );
        $this->assertEquals( 20, $found->getSellerId() );
    }

    /**
     * Test find returns null for missing payout
     *
     * @test
     * @requirement REQ-4D-035: Handle missing payouts
     */
    public function test_find_returns_null_for_missing_payout(): void {
        // Act
        $found = $this->repository->find( 999999 );

        // Assert
        $this->assertNull( $found );
    }

    /**
     * Test findByBatch returns all payouts in batch
     *
     * @test
     * @requirement REQ-4D-035: Query by batch
     */
    public function test_find_by_batch_returns_all_payouts_in_batch(): void {
        // Arrange
        $batch_id = 3;
        $payout1 = SellerPayout::create( null, $batch_id, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $payout2 = SellerPayout::create( null, $batch_id, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $payout3 = SellerPayout::create( null, 999, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );

        $this->repository->save( $payout1 );
        $this->repository->save( $payout2 );
        $this->repository->save( $payout3 );

        // Act
        $payouts = $this->repository->findByBatch( $batch_id );

        // Assert
        $this->assertGreaterThanOrEqual( 2, count( $payouts ) );
        foreach ( $payouts as $p ) {
            $this->assertEquals( $batch_id, $p->getBatchId() );
        }
    }

    /**
     * Test findByStatus returns filtered payouts
     *
     * @test
     * @requirement REQ-4D-035: Query by status
     */
    public function test_find_by_status_returns_filtered_payouts(): void {
        // Arrange
        $batch_id = 4;
        $payout1 = SellerPayout::create( null, $batch_id, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $payout2 = SellerPayout::create( null, $batch_id, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $payout3 = SellerPayout::create( null, $batch_id, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_PROCESSING );

        $this->repository->save( $payout1 );
        $this->repository->save( $payout2 );
        $this->repository->save( $payout3 );

        // Act
        $pending = $this->repository->findByStatus( SellerPayout::STATUS_PENDING );
        $processing = $this->repository->findByStatus( SellerPayout::STATUS_PROCESSING );

        // Assert
        $this->assertGreaterThanOrEqual( 2, count( $pending ) );
        $this->assertGreaterThanOrEqual( 1, count( $processing ) );

        foreach ( $pending as $p ) {
            $this->assertEquals( SellerPayout::STATUS_PENDING, $p->getStatus() );
        }
        foreach ( $processing as $p ) {
            $this->assertEquals( SellerPayout::STATUS_PROCESSING, $p->getStatus() );
        }
    }

    /**
     * Test findBySeller returns seller payouts
     *
     * @test
     * @requirement REQ-4D-035: Query by seller
     */
    public function test_find_by_seller_returns_seller_payouts(): void {
        // Arrange
        $seller_id = 50;
        for ( $i = 1; $i <= 5; $i++ ) {
            $payout = SellerPayout::create( null, $i, $seller_id, 100000 * $i, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
            $this->repository->save( $payout );
        }

        // Act
        $payouts = $this->repository->findBySeller( $seller_id );

        // Assert
        $this->assertGreaterThanOrEqual( 5, count( $payouts ) );
        foreach ( $payouts as $p ) {
            $this->assertEquals( $seller_id, $p->getSellerId() );
        }
    }

    /**
     * Test update modifies existing payout
     *
     * @test
     * @requirement REQ-4D-034: Update persisted payout
     */
    public function test_update_modifies_existing_payout(): void {
        // Arrange
        $payout = SellerPayout::create( null, 5, 60, 300000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $id = $this->repository->save( $payout );

        // Act - update status
        $payout->setId( $id );
        $payout->setStatus( SellerPayout::STATUS_PROCESSING );
        $result = $this->repository->update( $payout );

        // Assert
        $this->assertTrue( $result );
        $updated = $this->repository->find( $id );
        $this->assertEquals( SellerPayout::STATUS_PROCESSING, $updated->getStatus() );
    }

    /**
     * Test update throws error for payout without ID
     *
     * @test
     * @requirement REQ-4D-034: Validate update input
     */
    public function test_update_throws_error_for_payout_without_id(): void {
        // Arrange
        $payout = SellerPayout::create( null, 5, 70, 400000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        // Don't save - has no ID

        // Act & Assert
        $this->expectException( \Exception::class );
        $this->repository->update( $payout );
    }

    /**
     * Test findByTransactionId returns payout
     *
     * @test
     * @requirement REQ-4D-035: Query by transaction ID
     */
    public function test_find_by_transaction_id_returns_payout(): void {
        // Arrange
        $payout = SellerPayout::create( null, 6, 80, 500000, 'METHOD_ACH', SellerPayout::STATUS_PROCESSING );
        $id = $this->repository->save( $payout );

        $payout->setId( $id );
        $payout->setTransactionId( 'txn_abc123def456' );
        $this->repository->update( $payout );

        // Act
        $found = $this->repository->findByTransactionId( 'txn_abc123def456' );

        // Assert
        $this->assertNotNull( $found );
        $this->assertEquals( 'txn_abc123def456', $found->getTransactionId() );
    }

    /**
     * Test findPending returns only pending payouts
     *
     * @test
     * @requirement REQ-4D-035: Query pending payouts
     */
    public function test_find_pending_payouts_returns_unprocessed(): void {
        // Arrange
        $batch_id = 7;
        $payout1 = SellerPayout::create( null, $batch_id, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $payout2 = SellerPayout::create( null, $batch_id, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_COMPLETED );
        $payout3 = SellerPayout::create( null, $batch_id, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );

        $this->repository->save( $payout1 );
        $this->repository->save( $payout2 );
        $this->repository->save( $payout3 );

        // Act
        $pending = $this->repository->findPending();

        // Assert
        $this->assertGreaterThanOrEqual( 2, count( $pending ) );
        foreach ( $pending as $p ) {
            $this->assertEquals( SellerPayout::STATUS_PENDING, $p->getStatus() );
        }
    }

    /**
     * Test findByDateRange filters by created date
     *
     * @test
     * @requirement REQ-4D-035: Query by date range
     */
    public function test_find_by_date_range_filters_by_created_date(): void {
        // Arrange - create payouts with known dates
        $payout = SellerPayout::create( null, 8, 100, 600000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $id = $this->repository->save( $payout );

        $start_date = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $start_date->modify( '-1 day' );
        $end_date = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $end_date->modify( '+1 day' );

        // Act
        $payouts = $this->repository->findByDateRange( $start_date, $end_date );

        // Assert
        $this->assertIsArray( $payouts );
        $this->assertGreaterThan( 0, count( $payouts ) );
    }

    /**
     * Test batchUpdate processes multiple payouts
     *
     * @test
     * @requirement REQ-4D-036: Batch atomic updates
     */
    public function test_batch_update_processes_multiple_payouts(): void {
        // Arrange
        $batch_id = 9;
        $ids = [];
        for ( $i = 1; $i <= 5; $i++ ) {
            $payout = SellerPayout::create( null, $batch_id, $i, 100000 * $i, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
            $ids[] = $this->repository->save( $payout );
        }

        // Act
        $result = $this->repository->batchUpdateStatus( $ids, SellerPayout::STATUS_PROCESSING );

        // Assert
        $this->assertTrue( $result );
        foreach ( $ids as $id ) {
            $payout = $this->repository->find( $id );
            $this->assertEquals( SellerPayout::STATUS_PROCESSING, $payout->getStatus() );
        }
    }

    /**
     * Test find returns object with all properties
     *
     * @test
     * @requirement REQ-4D-035: Complete object restoration
     */
    public function test_find_returns_object_with_all_properties(): void {
        // Arrange
        $payout = SellerPayout::create( null, 10, 110, 700000, 'METHOD_STRIPE', SellerPayout::STATUS_PENDING );
        $id = $this->repository->save( $payout );

        $payout->setId( $id );
        $payout->setTransactionId( 'txn_stripe_123' );
        $payout->setProcessorName( 'Stripe' );
        $payout->setProcessorFeesCents( 7030 );
        $payout->setNetPayoutCents( 700000 - 7030 );
        $this->repository->update( $payout );

        // Act
        $found = $this->repository->find( $id );

        // Assert - all properties intact
        $this->assertNotNull( $found );
        $this->assertEquals( $id, $found->getId() );
        $this->assertEquals( 10, $found->getBatchId() );
        $this->assertEquals( 110, $found->getSellerId() );
        $this->assertEquals( 700000, $found->getAmountCents() );
        $this->assertEquals( 'METHOD_STRIPE', $found->getMethodType() );
        $this->assertEquals( 'txn_stripe_123', $found->getTransactionId() );
        $this->assertEquals( 'Stripe', $found->getProcessorName() );
        $this->assertEquals( 7030, $found->getProcessorFeesCents() );
    }

    /**
     * Test save and find round-trip consistency
     *
     * @test
     * @requirement REQ-4D-034: Data consistency
     */
    public function test_save_and_find_round_trip_consistency(): void {
        // Arrange
        $payout = SellerPayout::create( null, 11, 120, 800000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );

        // Act
        $id = $this->repository->save( $payout );
        $found = $this->repository->find( $id );

        // Assert - original and found are equivalent
        $this->assertEquals( $payout->getBatchId(), $found->getBatchId() );
        $this->assertEquals( $payout->getSellerId(), $found->getSellerId() );
        $this->assertEquals( $payout->getAmountCents(), $found->getAmountCents() );
        $this->assertEquals( $payout->getMethodType(), $found->getMethodType() );
        $this->assertEquals( $payout->getStatus(), $found->getStatus() );
    }

    /**
     * Test findByBatchAndStatus combines filters
     *
     * @test
     * @requirement REQ-4D-035: Complex filtering
     */
    public function test_find_by_batch_and_status_combines_filters(): void {
        // Arrange
        $batch_id = 12;
        $payout1 = SellerPayout::create( null, $batch_id, 1, 100000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );
        $payout2 = SellerPayout::create( null, $batch_id, 2, 150000, 'METHOD_ACH', SellerPayout::STATUS_PROCESSING );
        $payout3 = SellerPayout::create( null, $batch_id + 1, 3, 200000, 'METHOD_ACH', SellerPayout::STATUS_PENDING );

        $this->repository->save( $payout1 );
        $this->repository->save( $payout2 );
        $this->repository->save( $payout3 );

        // Act
        $payouts = $this->repository->findByBatchAndStatus( $batch_id, SellerPayout::STATUS_PENDING );

        // Assert
        $this->assertGreaterThanOrEqual( 1, count( $payouts ) );
        foreach ( $payouts as $p ) {
            $this->assertEquals( $batch_id, $p->getBatchId() );
            $this->assertEquals( SellerPayout::STATUS_PENDING, $p->getStatus() );
        }
    }
}
