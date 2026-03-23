<?php

namespace Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Exceptions\RepositoryException;

/**
 * PaymentAuthorizationRepositoryTest - Test payment record persistence.
 *
 * Tests database operations for payment authorization and refund tracking.
 *
 * @package Tests\Unit\Repository
 * @requirement REQ-ENTRY-FEE-PAYMENT-001: Test authorization tracking
 */
class PaymentAuthorizationRepositoryTest extends TestCase
{
    /**
     * @var PaymentAuthorizationRepository Repository under test
     */
    private PaymentAuthorizationRepository $repository;

    /**
     * @var \wpdb Mock database instance
     */
    private \wpdb $wpdb;

    /**
     * Set up test fixtures.
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock wpdb
        $this->wpdb = $this->createMock(\wpdb::class);
        $this->wpdb->prefix = 'wp_';

        // Initialize repository with mock
        $this->repository = new PaymentAuthorizationRepository($this->wpdb);
    }

    /**
     * Test storing payment method successfully.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_store_payment_method_success(): void
    {
        // Arrange
        $user_id = 1;
        $token = 'tok_square_abc123';
        $brand = 'Visa';
        $last_four = '1111';
        $exp_month = 12;
        $exp_year = 2026;

        $this->wpdb->insert_id = 1;
        $this->wpdb->expects($this->once())
            ->method('insert')
            ->willReturn(true);

        // Act
        $result = $this->repository->storePaymentMethod(
            $user_id,
            $token,
            $brand,
            $last_four,
            $exp_month,
            $exp_year
        );

        // Assert
        $this->assertEquals(1, $result);
    }

    /**
     * Test storing payment method fails on database error.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_store_payment_method_database_error(): void
    {
        // Arrange
        $this->wpdb->insert_id = 0;
        $this->wpdb->last_error = 'Database error';
        $this->wpdb->expects($this->once())
            ->method('insert')
            ->willReturn(false);

        // Act & Assert
        $this->expectException(RepositoryException::class);
        $this->repository->storePaymentMethod(
            1,
            'tok_square_abc123',
            'Visa',
            '1111',
            12,
            2026
        );
    }

    /**
     * Test retrieving payment method for user.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_payment_method_for_user(): void
    {
        // Arrange
        $user_id = 1;
        $payment_method = [
            'id' => 1,
            'user_id' => 1,
            'payment_token' => 'tok_square_abc123',
            'card_brand' => 'Visa',
            'card_last_four' => '1111',
            'exp_month' => 12,
            'exp_year' => 2026,
        ];

        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn((object) $payment_method);

        // Act
        $result = $this->repository->getPaymentMethodForUser($user_id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals('tok_square_abc123', $result['payment_token']);
        $this->assertEquals('Visa', $result['card_brand']);
    }

    /**
     * Test retrieving non-existent payment method returns null.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_payment_method_not_found(): void
    {
        // Arrange
        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn(null);

        // Act
        $result = $this->repository->getPaymentMethodForUser(999);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test recording authorization creates database record.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_record_authorization_success(): void
    {
        // Arrange
        $auction_id = 123;
        $user_id = 1;
        $bid_id = 'bid-uuid-123';
        $auth_id = 'auth_123abc';
        $gateway = 'square';
        $amount = new Money(2500);
        $status = 'AUTHORIZED';
        $metadata = ['charge_email' => 'john@example.com'];

        $this->wpdb->insert_id = 1;
        $this->wpdb->expects($this->once())
            ->method('insert')
            ->willReturn(true);

        // Act
        $result = $this->repository->recordAuthorization(
            $auction_id,
            $user_id,
            $bid_id,
            $auth_id,
            $gateway,
            $amount,
            $status,
            $metadata
        );

        // Assert
        $this->assertEquals(1, $result);
    }

    /**
     * Test updating authorization status.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_update_authorization_status_success(): void
    {
        // Arrange
        $auth_id = 'auth_123abc';
        $new_status = 'CAPTURED';

        $this->wpdb->expects($this->once())
            ->method('update')
            ->willReturn(1);

        // Act
        $result = $this->repository->updateAuthorizationStatus($auth_id, $new_status);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test updating authorization status with additional data.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_update_authorization_status_with_additional_data(): void
    {
        // Arrange
        $auth_id = 'auth_123abc';
        $new_status = 'CAPTURED';
        $additional = ['charged_at' => '2026-01-01 12:00:00'];

        $this->wpdb->expects($this->once())
            ->method('update')
            ->with(
                $this->stringContains('payment_authorizations'),
                $this->arrayHasKey('status'),
                ['authorization_id' => $auth_id]
            )
            ->willReturn(1);

        // Act
        $result = $this->repository->updateAuthorizationStatus(
            $auth_id,
            $new_status,
            $additional
        );

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test retrieving authorization by ID.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_authorization_by_id(): void
    {
        // Arrange
        $auth_id = 'auth_123abc';
        $auth_record = [
            'id' => 1,
            'authorization_id' => 'auth_123abc',
            'status' => 'AUTHORIZED',
            'amount_cents' => 2500,
        ];

        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn((object) $auth_record);

        // Act
        $result = $this->repository->getAuthorizationById($auth_id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals('AUTHORIZED', $result['status']);
        $this->assertEquals(2500, $result['amount_cents']);
    }

    /**
     * Test retrieving authorization by bid ID.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_authorization_by_bid(): void
    {
        // Arrange
        $bid_id = 'bid-uuid-123';
        $auth_record = [
            'id' => 1,
            'bid_id' => 'bid-uuid-123',
            'authorization_id' => 'auth_123abc',
            'status' => 'AUTHORIZED',
        ];

        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn((object) $auth_record);

        // Act
        $result = $this->repository->getAuthorizationByBid($bid_id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals('auth_123abc', $result['authorization_id']);
    }

    /**
     * Test retrieving all authorizations for auction.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_authorizations_by_auction(): void
    {
        // Arrange
        $auction_id = 123;
        $auth_records = [
            (object) [
                'id' => 1,
                'bid_id' => 'bid-1',
                'status' => 'CAPTURED',
                'amount_cents' => 2500,
            ],
            (object) [
                'id' => 2,
                'bid_id' => 'bid-2',
                'status' => 'REFUNDED',
                'amount_cents' => 2500,
            ],
        ];

        $this->wpdb->expects($this->once())
            ->method('get_results')
            ->willReturn($auth_records);

        // Act
        $result = $this->repository->getAuthorizationsByAuction($auction_id);

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals('CAPTURED', $result[0]['status']);
        $this->assertEquals('REFUNDED', $result[1]['status']);
    }

    /**
     * Test scheduling refund for later processing.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_schedule_refund_success(): void
    {
        // Arrange
        $auth_id = 'auth_123abc';
        $user_id = 1;
        $scheduled_for = new \DateTime('+24 hours');
        $reason = 'Outbid in auction';

        $this->wpdb->insert_id = 1;
        $this->wpdb->expects($this->once())
            ->method('insert')
            ->willReturn(true);

        // Act
        $result = $this->repository->scheduleRefund(
            $auth_id,
            $user_id,
            $scheduled_for,
            $reason
        );

        // Assert
        $this->assertStringStartsWith('REFUND-', $result);
    }

    /**
     * Test retrieving refund by bid ID.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_refund_by_bid(): void
    {
        // Arrange
        $bid_id = 'bid-uuid-123';
        $auth_record = ['authorization_id' => 'auth_123abc'];
        $refund_record = [
            'id' => 1,
            'refund_id' => 'REFUND-123',
            'status' => 'PENDING',
            'scheduled_for' => '2026-01-02 12:00:00',
        ];

        $this->wpdb->expects($this->at(0))
            ->method('get_row')
            ->willReturn((object) $auth_record);

        $this->wpdb->expects($this->at(1))
            ->method('get_row')
            ->willReturn((object) $refund_record);

        // Act
        $result = $this->repository->getRefundByBid($bid_id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals('PENDING', $result['status']);
    }

    /**
     * Test retrieving refund by bid when no refund exists.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_refund_by_bid_not_found(): void
    {
        // Arrange
        $bid_id = 'bid-uuid-123';

        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn(null);

        // Act
        $result = $this->repository->getRefundByBid($bid_id);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test retrieving pending refunds for processing.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_pending_refunds(): void
    {
        // Arrange
        $refund_records = [
            (object) [
                'id' => 1,
                'refund_id' => 'REFUND-1',
                'status' => 'PENDING',
                'scheduled_for' => '2025-12-31 12:00:00',
            ],
            (object) [
                'id' => 2,
                'refund_id' => 'REFUND-2',
                'status' => 'PENDING',
                'scheduled_for' => '2025-12-31 13:00:00',
            ],
        ];

        $this->wpdb->expects($this->once())
            ->method('get_results')
            ->willReturn($refund_records);

        // Act
        $result = $this->repository->getPendingRefunds();

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals('PENDING', $result[0]['status']);
        $this->assertEquals('PENDING', $result[1]['status']);
    }

    /**
     * Test updating refund status.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_update_refund_status_success(): void
    {
        // Arrange
        $refund_id = 'REFUND-123';
        $new_status = 'PROCESSED';

        $this->wpdb->expects($this->once())
            ->method('update')
            ->willReturn(1);

        // Act
        $result = $this->repository->updateRefundStatus($refund_id, $new_status);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test updating refund status adds processed_at timestamp.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_update_refund_status_sets_processed_at(): void
    {
        // Arrange
        $refund_id = 'REFUND-123';
        $new_status = 'PROCESSED';

        $this->wpdb->expects($this->once())
            ->method('update')
            ->with(
                $this->stringContains('refund_schedule'),
                $this->arrayHasKey('processed_at'),
                ['refund_id' => $refund_id]
            )
            ->willReturn(1);

        // Act
        $result = $this->repository->updateRefundStatus($refund_id, $new_status);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test retrieving authorization history for user (audit trail).
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_authorization_history(): void
    {
        // Arrange
        $user_id = 1;
        $auth_records = [
            (object) [
                'id' => 1,
                'bid_id' => 'bid-1',
                'status' => 'CAPTURED',
            ],
            (object) [
                'id' => 2,
                'bid_id' => 'bid-2',
                'status' => 'REFUNDED',
            ],
        ];

        $this->wpdb->expects($this->once())
            ->method('get_results')
            ->willReturn($auth_records);

        // Act
        $result = $this->repository->getAuthorizationHistory($user_id);

        // Assert
        $this->assertCount(2, $result);
    }

    /**
     * Test retrieving failed authorizations.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_failed_authorizations(): void
    {
        // Arrange
        $failure_records = [
            (object) [
                'id' => 1,
                'status' => 'FAILED',
                'authorization_id' => 'auth_failed_1',
            ],
            (object) [
                'id' => 2,
                'status' => 'CAPTURE_FAILED',
                'authorization_id' => 'auth_failed_2',
            ],
        ];

        $this->wpdb->expects($this->once())
            ->method('get_results')
            ->willReturn($failure_records);

        // Act
        $result = $this->repository->getFailedAuthorizations();

        // Assert
        $this->assertCount(2, $result);
        $this->assertContains('FAILED', [$result[0]['status'], $result[1]['status']]);
    }

    /**
     * Test retrieving refund by ID.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_refund_by_id(): void
    {
        // Arrange
        $refund_id = 'REFUND-123';
        $refund_record = [
            'id' => 1,
            'refund_id' => 'REFUND-123',
            'status' => 'PENDING',
            'scheduled_for' => '2026-01-02 12:00:00',
        ];

        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn((object) $refund_record);

        // Act
        $result = $this->repository->getRefundById($refund_id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals('REFUND-123', $result['refund_id']);
        $this->assertEquals('PENDING', $result['status']);
    }

    /**
     * Test retrieving non-existent refund returns null.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_refund_by_id_not_found(): void
    {
        // Arrange
        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn(null);

        // Act
        $result = $this->repository->getRefundById('REFUND-NONEXISTENT');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test retrieving failed refunds.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_failed_refunds(): void
    {
        // Arrange
        $failed_records = [
            (object) [
                'id' => 1,
                'refund_id' => 'REFUND-FAIL-1',
                'status' => 'FAILED',
            ],
            (object) [
                'id' => 2,
                'refund_id' => 'REFUND-FAIL-2',
                'status' => 'FAILED',
            ],
        ];

        $this->wpdb->expects($this->once())
            ->method('get_results')
            ->willReturn($failed_records);

        // Act
        $result = $this->repository->getFailedRefunds();

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals('FAILED', $result[0]['status']);
        $this->assertEquals('FAILED', $result[1]['status']);
    }

    /**
     * Test pruning old authorization records.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_prune_old_records(): void
    {
        // Arrange
        $this->wpdb->expects($this->once())
            ->method('query')
            ->willReturn(5);

        // Act
        $result = $this->repository->pruneOldRecords(90);

        // Assert
        $this->assertEquals(5, $result);
    }

    /**
     * Test payment method storage and retrieval flow.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_payment_method_lifecycle(): void
    {
        // Arrange - Store
        $this->wpdb->insert_id = 1;
        $this->wpdb->expects($this->once())
            ->method('insert')
            ->willReturn(true);

        $store_result = $this->repository->storePaymentMethod(
            1,
            'tok_square_abc123',
            'Visa',
            '1111',
            12,
            2026
        );

        // Arrange - Retrieve
        $payment_method = [
            'id' => 1,
            'payment_token' => 'tok_square_abc123',
            'card_brand' => 'Visa',
            'card_last_four' => '1111',
        ];

        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn((object) $payment_method);

        // Act - Retrieve
        $retrieved = $this->repository->getPaymentMethodForUser(1);

        // Assert
        $this->assertEquals(1, $store_result);
        $this->assertEquals('tok_square_abc123', $retrieved['payment_token']);
    }

    /**
     * Test authorization lifecycle: record, update, retrieve.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_authorization_lifecycle(): void
    {
        // Arrange - Record
        $this->wpdb->insert_id = 1;
        $this->wpdb->expects($this->at(0))
            ->method('insert')
            ->willReturn(true);

        $record_id = $this->repository->recordAuthorization(
            123,
            1,
            'bid-123',
            'auth_abc123',
            'square',
            new Money(2500),
            'AUTHORIZED'
        );

        // Arrange - Update status
        $this->wpdb->expects($this->once())
            ->method('update')
            ->willReturn(1);

        $update_result = $this->repository->updateAuthorizationStatus(
            'auth_abc123',
            'CAPTURED'
        );

        // Arrange - Retrieve
        $auth_record = [
            'id' => 1,
            'authorization_id' => 'auth_abc123',
            'status' => 'CAPTURED',
        ];

        $this->wpdb->expects($this->once())
            ->method('get_row')
            ->willReturn((object) $auth_record);

        $retrieved = $this->repository->getAuthorizationById('auth_abc123');

        // Assert
        $this->assertEquals(1, $record_id);
        $this->assertTrue($update_result);
        $this->assertEquals('CAPTURED', $retrieved['status']);
    }
}
