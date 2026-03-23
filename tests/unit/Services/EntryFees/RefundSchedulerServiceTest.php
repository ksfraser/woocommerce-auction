<?php

namespace Tests\Unit\Services\EntryFees;

use PHPUnit\Framework\TestCase;
use Yith\Auctions\Services\EntryFees\RefundSchedulerService;
use Yith\Auctions\Contracts\PaymentGatewayInterface;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Exceptions\PaymentException;

/**
 * RefundSchedulerServiceTest - Test cron-based refund processing.
 *
 * Tests batch refund processing (24h+ delayed refunds).
 *
 * @package Tests\Unit\Services\EntryFees
 * @requirement REQ-ENTRY-FEE-PAYMENT-001: Test refund scheduler
 */
class RefundSchedulerServiceTest extends TestCase
{
    /**
     * @var RefundSchedulerService Service under test
     */
    private RefundSchedulerService $service;

    /**
     * @var PaymentGatewayInterface Mock payment gateway
     */
    private PaymentGatewayInterface $gateway;

    /**
     * @var PaymentAuthorizationRepository Mock repository
     */
    private PaymentAuthorizationRepository $repository;

    /**
     * Set up test fixtures.
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->repository = $this->createMock(PaymentAuthorizationRepository::class);

        // Initialize service without notification callback
        $this->service = new RefundSchedulerService(
            $this->gateway,
            $this->repository
        );
    }

    /**
     * Test processing refunds with empty pending list.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_process_scheduled_refunds_no_pending(): void
    {
        // Arrange
        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->with(50)
            ->willReturn([]);

        // Act
        $result = $this->service->processScheduledRefunds();

        // Assert
        $this->assertEquals(0, $result['total_processed']);
        $this->assertEquals(0, $result['successful']);
        $this->assertEquals(0, $result['failed']);
    }

    /**
     * Test processing single refund successfully.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_process_scheduled_refunds_single_success(): void
    {
        // Arrange
        $refund = [
            'id' => 1,
            'refund_id' => 'REFUND-123',
            'authorization_id' => 'auth_123abc',
            'user_id' => 1,
            'amount_cents' => 2500,
            'reason' => 'Outbid',
            'scheduled_for' => '2025-12-31 12:00:00',
        ];

        $auth = [
            'id' => 1,
            'authorization_id' => 'auth_123abc',
            'amount_cents' => 2500,
            'status' => 'AUTHORIZED',
        ];

        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->willReturn([$refund]);

        $this->repository->expects($this->once())
            ->method('getAuthorizationById')
            ->with('auth_123abc')
            ->willReturn($auth);

        $this->gateway->expects($this->once())
            ->method('refundPayment')
            ->willReturn([
                'refund_id' => 'REFUND-123',
                'amount_refunded' => new Money(2500),
                'status' => 'REFUNDED',
                'refund_timestamp' => new \DateTime(),
            ]);

        $this->repository->expects($this->exactly(2))
            ->method('updateRefundStatus')
            ->willReturnOnConsecutiveCalls(true, true);

        $this->repository->expects($this->once())
            ->method('updateAuthorizationStatus');

        // Act
        $result = $this->service->processScheduledRefunds();

        // Assert
        $this->assertEquals(1, $result['total_processed']);
        $this->assertEquals(1, $result['successful']);
        $this->assertEquals(0, $result['failed']);
    }

    /**
     * Test processing batch of refunds (multiple).
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_process_scheduled_refunds_batch(): void
    {
        // Arrange
        $refunds = [
            [
                'id' => 1,
                'refund_id' => 'REFUND-1',
                'authorization_id' => 'auth_1',
                'user_id' => 1,
                'reason' => 'Outbid',
                'scheduled_for' => '2025-12-31 12:00:00',
            ],
            [
                'id' => 2,
                'refund_id' => 'REFUND-2',
                'authorization_id' => 'auth_2',
                'user_id' => 2,
                'reason' => 'Outbid',
                'scheduled_for' => '2025-12-31 13:00:00',
            ],
        ];

        $auth1 = [
            'authorization_id' => 'auth_1',
            'amount_cents' => 2500,
        ];
        $auth2 = [
            'authorization_id' => 'auth_2',
            'amount_cents' => 5000,
        ];

        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->willReturn($refunds);

        $this->repository->expects($this->exactly(2))
            ->method('getAuthorizationById')
            ->willReturnOnConsecutiveCalls($auth1, $auth2);

        $this->gateway->expects($this->exactly(2))
            ->method('refundPayment')
            ->willReturn([
                'refund_id' => 'test',
                'status' => 'REFUNDED',
                'refund_timestamp' => new \DateTime(),
            ]);

        $this->repository->expects($this->any())
            ->method('updateRefundStatus')
            ->willReturn(true);

        $this->repository->expects($this->any())
            ->method('updateAuthorizationStatus')
            ->willReturn(true);

        // Act
        $result = $this->service->processScheduledRefunds();

        // Assert
        $this->assertEquals(2, $result['total_processed']);
        $this->assertEquals(2, $result['successful']);
        $this->assertEquals(0, $result['failed']);
    }

    /**
     * Test batch processing continues on individual failure.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_process_scheduled_refunds_one_fails_continues(): void
    {
        // Arrange
        $refunds = [
            [
                'id' => 1,
                'refund_id' => 'REFUND-1',
                'authorization_id' => 'auth_1',
                'user_id' => 1,
                'reason' => 'Outbid',
                'scheduled_for' => '2025-12-31 12:00:00',
            ],
            [
                'id' => 2,
                'refund_id' => 'REFUND-2',
                'authorization_id' => 'auth_2',
                'user_id' => 2,
                'reason' => 'Outbid',
                'scheduled_for' => '2025-12-31 13:00:00',
            ],
        ];

        $auth1 = [
            'authorization_id' => 'auth_1',
            'amount_cents' => 2500,
        ];
        $auth2 = [
            'authorization_id' => 'auth_2',
            'amount_cents' => 5000,
        ];

        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->willReturn($refunds);

        $this->repository->expects($this->exactly(2))
            ->method('getAuthorizationById')
            ->willReturnOnConsecutiveCalls($auth1, $auth2);

        // First refund succeeds, second fails
        $this->gateway->expects($this->exactly(2))
            ->method('refundPayment')
            ->willReturnOnConsecutiveCalls(
                [
                    'refund_id' => 'REFUND-1',
                    'status' => 'REFUNDED',
                    'refund_timestamp' => new \DateTime(),
                ],
                $this->throwException(new PaymentException('Network error'))
            );

        // Both should have status updates attempted
        $this->repository->expects($this->any())
            ->method('updateRefundStatus')
            ->willReturn(true);

        $this->repository->expects($this->any())
            ->method('updateAuthorizationStatus')
            ->willReturn(true);

        // Act
        $result = $this->service->processScheduledRefunds();

        // Assert
        $this->assertEquals(2, $result['total_processed']);
        $this->assertEquals(1, $result['successful']);
        $this->assertEquals(1, $result['failed']);
    }

    /**
     * Test processing refund notifies bidder if callback provided.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_process_refund_notifies_bidder(): void
    {
        // Arrange
        $refund = [
            'id' => 1,
            'refund_id' => 'REFUND-123',
            'authorization_id' => 'auth_123abc',
            'user_id' => 1,
            'amount_cents' => 2500,
            'reason' => 'Outbid in auction',
            'scheduled_for' => '2025-12-31 12:00:00',
        ];

        $auth = [
            'authorization_id' => 'auth_123abc',
            'amount_cents' => 2500,
        ];

        $notification_called = false;
        $notification_callback = function($user_id, $amount, $refund_id) use (&$notification_called) {
            $notification_called = true;
            $this->assertEquals(1, $user_id);
            $this->assertEquals(2500, $amount->getAmount());
            $this->assertEquals('REFUND-123', $refund_id);
        };

        // Create service with callback
        $service_with_callback = new RefundSchedulerService(
            $this->gateway,
            $this->repository,
            $notification_callback
        );

        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->willReturn([$refund]);

        $this->repository->expects($this->once())
            ->method('getAuthorizationById')
            ->willReturn($auth);

        $this->gateway->expects($this->once())
            ->method('refundPayment')
            ->willReturn([
                'refund_id' => 'REFUND-123',
                'refund_timestamp' => new \DateTime(),
            ]);

        $this->repository->expects($this->any())
            ->method('updateRefundStatus')
            ->willReturn(true);

        $this->repository->expects($this->any())
            ->method('updateAuthorizationStatus')
            ->willReturn(true);

        // Act
        $result = $service_with_callback->processScheduledRefunds();

        // Assert
        $this->assertTrue($notification_called);
        $this->assertEquals(1, $result['successful']);
    }

    /**
     * Test missing authorization is handled gracefully.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_process_refund_authorization_not_found(): void
    {
        // Arrange
        $refund = [
            'id' => 1,
            'refund_id' => 'REFUND-123',
            'authorization_id' => 'auth_123abc',
            'user_id' => 1,
            'reason' => 'Outbid',
            'scheduled_for' => '2025-12-31 12:00:00',
        ];

        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->willReturn([$refund]);

        $this->repository->expects($this->once())
            ->method('getAuthorizationById')
            ->willReturn(null);  // Not found

        $this->repository->expects($this->any())
            ->method('updateRefundStatus')
            ->willReturn(true);

        // Act
        $result = $this->service->processScheduledRefunds();

        // Assert
        $this->assertEquals(1, $result['total_processed']);
        $this->assertEquals(0, $result['successful']);
        $this->assertEquals(1, $result['failed']);
    }

    /**
     * Test getting processing statistics.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_processing_stats(): void
    {
        // Arrange
        $pending = [
            [
                'refund_id' => 'REFUND-1',
                'scheduled_for' => '2025-12-31 12:00:00',
            ],
            [
                'refund_id' => 'REFUND-2',
                'scheduled_for' => '2025-12-31 13:00:00',
            ],
        ];

        $failed = [
            [
                'refund_id' => 'REFUND-FAIL-1',
                'reason' => 'Network error',
            ],
        ];

        $this->repository->expects($this->exactly(2))
            ->method('getPendingRefunds')
            ->willReturn($pending);

        $this->repository->expects($this->once())
            ->method('getFailedRefunds')
            ->willReturn($failed);

        // Act
        $stats = $this->service->getProcessingStats();

        // Assert
        $this->assertEquals(2, $stats['pending_count']);
        $this->assertEquals(1, $stats['failed_count']);
        $this->assertNotNull($stats['oldest_pending_scheduled_for']);
        $this->assertEquals(2, $stats['next_batch_size']);
    }

    /**
     * Test manual retry of failed refund.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_retry_failed_refund_success(): void
    {
        // Arrange
        $refund = [
            'id' => 1,
            'refund_id' => 'REFUND-123',
            'authorization_id' => 'auth_123abc',
            'user_id' => 1,
            'amount_cents' => 2500,
            'reason' => 'Outbid',
            'scheduled_for' => '2025-12-31 12:00:00',
        ];

        $auth = [
            'authorization_id' => 'auth_123abc',
            'amount_cents' => 2500,
        ];

        $this->repository->expects($this->once())
            ->method('getRefundById')
            ->with('REFUND-123')
            ->willReturn($refund);

        $this->repository->expects($this->once())
            ->method('getAuthorizationById')
            ->willReturn($auth);

        $this->gateway->expects($this->once())
            ->method('refundPayment')
            ->willReturn([
                'refund_id' => 'REFUND-123',
                'refund_timestamp' => new \DateTime(),
            ]);

        $this->repository->expects($this->any())
            ->method('updateRefundStatus')
            ->willReturn(true);

        $this->repository->expects($this->any())
            ->method('updateAuthorizationStatus')
            ->willReturn(true);

        // Act
        $result = $this->service->retryFailedRefund('REFUND-123');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test manual retry fails gracefully.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_retry_failed_refund_not_found(): void
    {
        // Arrange
        $this->repository->expects($this->once())
            ->method('getRefundById')
            ->willReturn(null);

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->service->retryFailedRefund('REFUND-NONEXISTENT');
    }

    /**
     * Test pruning refund records.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_prune_refund_records(): void
    {
        // Arrange
        $this->repository->expects($this->once())
            ->method('pruneOldRecords')
            ->with(90)
            ->willReturn(5);

        // Act
        $deleted = $this->service->pruneRefundRecords(90);

        // Assert
        $this->assertEquals(5, $deleted);
    }

    /**
     * Test batch respects size limit (50 refunds per run).
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_batch_size_limit(): void
    {
        // Arrange - Create 50 refunds (batch size limit)
        $refunds = [];
        for ($i = 0; $i < 50; $i++) {
            $refunds[] = [
                'id' => $i,
                'refund_id' => 'REFUND-' . $i,
                'authorization_id' => 'auth_' . $i,
                'user_id' => 1,
                'reason' => 'Outbid',
                'scheduled_for' => '2025-12-31 12:00:00',
            ];
        }

        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->with(50)  // Verify batch size
            ->willReturn($refunds);

        // Setup gateway and repository mocks to handle all
        $this->gateway->method('refundPayment')->willReturn([
            'refund_id' => 'test',
            'refund_timestamp' => new \DateTime(),
        ]);

        $this->repository->method('getAuthorizationById')->willReturn([
            'authorization_id' => 'auth_test',
            'amount_cents' => 2500,
        ]);

        $this->repository->method('updateRefundStatus')->willReturn(true);
        $this->repository->method('updateAuthorizationStatus')->willReturn(true);

        // Act
        $result = $this->service->processScheduledRefunds();

        // Assert
        $this->assertEquals(50, $result['total_processed']);
    }

    /**
     * Test complete refund lifecycle: schedule → process.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_complete_refund_lifecycle(): void
    {
        // Arrange: Pending refund waiting to be processed
        $scheduled_refund = [
            'id' => 1,
            'refund_id' => 'REFUND-1',
            'authorization_id' => 'auth_1',
            'user_id' => 1,
            'amount_cents' => 2500,
            'reason' => 'Outbid in auction #123',
            'status' => 'PENDING',
            'scheduled_for' => '2025-12-31 12:00:00',
        ];

        $auth = [
            'id' => 1,
            'authorization_id' => 'auth_1',
            'amount_cents' => 2500,
            'status' => 'AUTHORIZED',
        ];

        $this->repository->expects($this->once())
            ->method('getPendingRefunds')
            ->willReturn([$scheduled_refund]);

        $this->repository->expects($this->once())
            ->method('getAuthorizationById')
            ->willReturn($auth);

        $this->gateway->expects($this->once())
            ->method('refundPayment')
            ->with(
                'auth_1',
                $this->isInstanceOf(Money::class),
                $this->arrayHasKey('reason')
            )
            ->willReturn([
                'refund_id' => 'REFUND-1',
                'amount_refunded' => new Money(2500),
                'status' => 'REFUNDED',
                'refund_timestamp' => new \DateTime(),
            ]);

        // Should update refund status twice (PROCESSED and authorization REFUNDED)
        $this->repository->expects($this->any())
            ->method('updateRefundStatus')
            ->willReturn(true);

        $this->repository->expects($this->once())
            ->method('updateAuthorizationStatus')
            ->willReturn(true);

        // Act
        $result = $this->service->processScheduledRefunds();

        // Assert
        $this->assertEquals(1, $result['total_processed']);
        $this->assertEquals(1, $result['successful']);
        $this->assertEquals(0, $result['failed']);
    }
}
