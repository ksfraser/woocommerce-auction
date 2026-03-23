<?php

namespace Tests\Unit\Services\EntryFees;

use PHPUnit\Framework\TestCase;
use Yith\Auctions\Services\EntryFees\EntryFeePaymentService;
use Yith\Auctions\Contracts\PaymentGatewayInterface;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\Services\EntryFees\CommissionCalculator;
use Yith\Auctions\ValueObjects\Money;
use Yith\Auctions\Exceptions\PaymentException;
use Yith\Auctions\Exceptions\ValidationException;

/**
 * EntryFeePaymentServiceTest - Test payment authorization workflows.
 *
 * Tests the complete entry fee payment lifecycle:
 * 1. Payment method storage
 * 2. Authorization (placing hold)
 * 3. Capture (charging winner)
 * 4. Refund scheduling (for outbid)
 * 5. Refund processing
 *
 * @package Tests\Unit\Services\EntryFees
 * @requirement REQ-ENTRY-FEE-PAYMENT-001: Test payment orchestration
 */
class EntryFeePaymentServiceTest extends TestCase
{
    /**
     * @var EntryFeePaymentService Service under test
     */
    private EntryFeePaymentService $service;

    /**
     * @var PaymentGatewayInterface Mock payment gateway
     */
    private PaymentGatewayInterface $gateway;

    /**
     * @var PaymentAuthorizationRepository Mock repository
     */
    private PaymentAuthorizationRepository $repository;

    /**
     * @var CommissionCalculator Mock fee calculator
     */
    private CommissionCalculator $calculator;

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
        $this->calculator = $this->createMock(CommissionCalculator::class);

        // Inject mocks
        $this->service = new EntryFeePaymentService(
            $this->gateway,
            $this->repository,
            $this->calculator
        );
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
        $card_details = [
            'card_number' => '4111111111111111',
            'exp_month' => 12,
            'exp_year' => 2026,
            'cvc' => '123',
            'cardholder_name' => 'John Doe',
            'billing_email' => 'john@example.com',
        ];

        $token_result = [
            'token' => 'tok_square_abc123',
            'last_four' => '1111',
            'brand' => 'Visa',
            'exp_month' => 12,
            'exp_year' => 2026,
        ];

        // Gateway should tokenize card
        $this->gateway->expects($this->once())
            ->method('createPaymentMethod')
            ->with($card_details)
            ->willReturn($token_result);

        // Repository should store payment method
        $this->repository->expects($this->once())
            ->method('storePaymentMethod')
            ->with(
                $user_id,
                'tok_square_abc123',
                'Visa',
                '1111',
                12,
                2026
            )
            ->willReturn(1);

        // Act
        $result = $this->service->storePaymentMethod($user_id, $card_details);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('tok_square_abc123', $result['token']);
        $this->assertEquals('1111', $result['last_four']);
        $this->assertEquals('Visa', $result['brand']);
    }

    /**
     * Test storing payment method with invalid card data.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_store_payment_method_invalid_card(): void
    {
        // Arrange
        $user_id = 1;
        $card_details = [
            'card_number' => '',  // Missing
            'exp_month' => 12,
            'exp_year' => 2026,
            'cvc' => '123',
            'cardholder_name' => 'John Doe',
        ];

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->service->storePaymentMethod($user_id, $card_details);
    }

    /**
     * Test authorizing entry fee places pre-auth hold.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_authorize_entry_fee_success(): void
    {
        // Arrange
        $auction_id = 123;
        $user_id = 1;
        $bid_id = 'bid-uuid-123';
        $payment_token = 'tok_square_abc123';
        $bid_amount = new Money(5000);  // $50.00
        $entry_fee = new Money(2500);   // $25.00 (50%)
        $customer_email = 'john@example.com';

        // Calculator should determine entry fee
        $this->calculator->expects($this->once())
            ->method('calculateEntryFee')
            ->with(5000)
            ->willReturn($entry_fee);

        // Gateway should authorize (place pre-auth hold)
        $auth_result = [
            'auth_id' => 'auth_123abc',
            'expires_at' => new \DateTime('+7 days'),
        ];

        $this->gateway->expects($this->once())
            ->method('authorizePayment')
            ->with(
                $payment_token,
                $entry_fee,
                [
                    'auction_id' => $auction_id,
                    'user_id' => $user_id,
                    'bid_id' => $bid_id,
                    'description' => "Entry fee for Auction #{$auction_id}",
                    'customer_email' => $customer_email,
                ]
            )
            ->willReturn($auth_result);

        // Repository should record authorization
        $this->repository->expects($this->once())
            ->method('recordAuthorization')
            ->willReturn(1);

        $this->gateway->expects($this->once())
            ->method('getProviderName')
            ->willReturn('square');

        // Act
        $result = $this->service->authorizeEntryFee(
            $auction_id,
            $user_id,
            $bid_id,
            $payment_token,
            $bid_amount,
            $customer_email
        );

        // Assert
        $this->assertEquals('AUTHORIZED', $result['status']);
        $this->assertEquals('auth_123abc', $result['auth_id']);
        $this->assertEquals($entry_fee->getAmount(), $result['entry_fee']->getAmount());
        $this->assertEquals(1, $result['authorization_record_id']);
    }

    /**
     * Test authorizing entry fee with invalid auction ID.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_authorize_entry_fee_invalid_auction(): void
    {
        // Act & Assert
        $this->expectException(ValidationException::class);

        $this->service->authorizeEntryFee(
            -1,  // Invalid auction ID
            1,
            'bid-123',
            'tok_square_abc123',
            new Money(5000),
            'john@example.com'
        );
    }

    /**
     * Test authorization failure stores failed record for audit.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_authorize_entry_fee_gateway_failure(): void
    {
        // Arrange
        $this->calculator->expects($this->once())
            ->method('calculateEntryFee')
            ->willReturn(new Money(2500));

        $this->gateway->expects($this->once())
            ->method('authorizePayment')
            ->willThrowException(new PaymentException('Card declined'));

        $this->gateway->expects($this->once())
            ->method('getProviderName')
            ->willReturn('square');

        // Failed authorization still recorded for audit
        $this->repository->expects($atLeastOnce())
            ->method('recordAuthorization')
            ->willReturn(1);

        // Act & Assert
        $this->expectException(PaymentException::class);

        $this->service->authorizeEntryFee(
            123,
            1,
            'bid-123',
            'tok_square_abc123',
            new Money(5000),
            'john@example.com'
        );
    }

    /**
     * Test capturing entry fee (charging authorized hold).
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_capture_entry_fee_success(): void
    {
        // Arrange
        $auth_id = 'auth_123abc';
        $amount = new Money(2500);
        $charge_time = new \DateTime();

        $capture_result = [
            'capture_id' => 'capture_456def',
            'amount' => $amount,
            'status' => 'CAPTURED',
            'charge_timestamp' => $charge_time,
        ];

        // Gateway should capture (finalize charge)
        $this->gateway->expects($this->once())
            ->method('capturePayment')
            ->with($auth_id, $amount)
            ->willReturn($capture_result);

        // Repository should update status
        $this->repository->expects($this->once())
            ->method('updateAuthorizationStatus')
            ->with($auth_id, 'CAPTURED');

        // Act
        $result = $this->service->captureEntryFee($auth_id, $amount);

        // Assert
        $this->assertEquals('CAPTURED', $result['status']);
        $this->assertEquals('capture_456def', $result['capture_id']);
    }

    /**
     * Test capture failure updates status to failed.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_capture_entry_fee_fails(): void
    {
        // Arrange
        $auth_id = 'auth_123abc';
        $amount = new Money(2500);

        $this->gateway->expects($this->once())
            ->method('capturePayment')
            ->willThrowException(new PaymentException('Charge failed'));

        $this->repository->expects($this->once())
            ->method('updateAuthorizationStatus')
            ->with($auth_id, 'CAPTURE_FAILED');

        // Act & Assert
        $this->expectException(PaymentException::class);
        $this->service->captureEntryFee($auth_id, $amount);
    }

    /**
     * Test scheduling refund for outbid bidder.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_schedule_refund_success(): void
    {
        // Arrange
        $auth_id = 'auth_123abc';
        $user_id = 1;
        $reason = 'Outbid in auction #123';

        $refund_id = 'REFUND-1234567-uuid-123';

        // Repository should schedule refund
        $this->repository->expects($this->once())
            ->method('scheduleRefund')
            ->with(
                $auth_id,
                $user_id,
                $this->isInstanceOf(\DateTime::class),
                $reason
            )
            ->willReturn($refund_id);

        // Repository should update authorization status
        $this->repository->expects($this->once())
            ->method('updateAuthorizationStatus')
            ->with(
                $auth_id,
                'REFUND_PENDING',
                ['refund_id' => $refund_id]
            );

        // Act
        $result = $this->service->scheduleRefund($auth_id, $user_id, $reason);

        // Assert
        $this->assertEquals('REFUND_PENDING', $result['status']);
        $this->assertEquals($refund_id, $result['refund_id']);
    }

    /**
     * Test processing scheduled refund (after 24h delay).
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_process_scheduled_refund_success(): void
    {
        // Arrange
        $refund_id = 'REFUND-1234567-uuid-123';
        $auth_id = 'auth_123abc';
        $amount = new Money(2500);
        $refund_time = new \DateTime();

        $refund_result = [
            'refund_id' => $refund_id,
            'amount_refunded' => $amount,
            'status' => 'REFUNDED',
            'refund_timestamp' => $refund_time,
        ];

        // Gateway should refund
        $this->gateway->expects($this->once())
            ->method('refundPayment')
            ->with(
                $auth_id,
                $amount,
                ['reason' => 'Auction entry fee refund']
            )
            ->willReturn($refund_result);

        // Repository should update both authorization and refund statuses
        $this->repository->expects($this->exactly(2))
            ->method('updateAuthorizationStatus')
            ->willReturnOnConsecutiveCalls(true, true);

        $this->repository->expects($this->once())
            ->method('updateRefundStatus')
            ->with($refund_id, 'REFUNDED');

        // Act
        $result = $this->service->processScheduledRefund($refund_id, $auth_id, $amount);

        // Assert
        $this->assertEquals('REFUNDED', $result['status']);
        $this->assertEquals($amount->getAmount(), $result['amount_refunded']->getAmount());
    }

    /**
     * Test getting auction payments for admin review.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_auction_payments(): void
    {
        // Arrange
        $auction_id = 123;
        $payments = [
            [
                'bid_id' => 'bid-1',
                'status' => 'CAPTURED',
                'amount_cents' => 2500,
            ],
            [
                'bid_id' => 'bid-2',
                'status' => 'REFUNDED',
                'amount_cents' => 2500,
            ],
        ];

        $this->repository->expects($this->once())
            ->method('getAuthorizationsByAuction')
            ->with($auction_id)
            ->willReturn($payments);

        // Act
        $result = $this->service->getAuctionPayments($auction_id);

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals('bid-1', $result[0]['bid_id']);
        $this->assertEquals('CAPTURED', $result[0]['status']);
    }

    /**
     * Test getting refund status for bidder.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_refund_status(): void
    {
        // Arrange
        $bid_id = 'bid-123';
        $refund_data = [
            'refund_id' => 'REFUND-123',
            'status' => 'PENDING',
            'scheduled_for' => '2026-01-01 12:00:00',
        ];

        $this->repository->expects($this->once())
            ->method('getRefundByBid')
            ->with($bid_id)
            ->willReturn($refund_data);

        // Act
        $result = $this->service->getRefundStatus($bid_id);

        // Assert
        $this->assertEquals('PENDING', $result['status']);
        $this->assertEquals('REFUND-123', $result['refund_id']);
    }

    /**
     * Test getting refund status when no refund exists.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_get_refund_status_not_found(): void
    {
        // Arrange
        $bid_id = 'bid-123';

        $this->repository->expects($this->once())
            ->method('getRefundByBid')
            ->with($bid_id)
            ->willReturn(null);

        // Act
        $result = $this->service->getRefundStatus($bid_id);

        // Assert
        $this->assertEmpty($result);
    }

    /**
     * Test complete payment lifecycle: authorize → capture.
     *
     * Scenario: Bidder places bid (authorize), wins auction (capture).
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_complete_lifecycle_winner(): void
    {
        // Arrange - Authorize phase
        $auction_id = 123;
        $user_id = 1;
        $bid_id = 'bid-uuid-123';
        $payment_token = 'tok_square_abc123';
        $bid_amount = new Money(5000);
        $entry_fee = new Money(2500);

        $this->calculator->expects($this->once())
            ->method('calculateEntryFee')
            ->willReturn($entry_fee);

        $this->gateway->expects($this->once())
            ->method('authorizePayment')
            ->willReturn([
                'auth_id' => 'auth_123',
                'expires_at' => new \DateTime('+7 days'),
            ]);

        $this->gateway->expects($this->once())
            ->method('getProviderName')
            ->willReturn('square');

        $this->repository->expects($this->exactly(1))
            ->method('recordAuthorization')
            ->willReturn(1);

        // Authorize the bid
        $auth_result = $this->service->authorizeEntryFee(
            $auction_id,
            $user_id,
            $bid_id,
            $payment_token,
            $bid_amount,
            'john@example.com'
        );

        // Arrange - Capture phase
        $auth_id = $auth_result['auth_id'];
        $this->gateway->expects($this->once())
            ->method('capturePayment')
            ->with($auth_id, $entry_fee)
            ->willReturn([
                'capture_id' => 'capture_456',
                'amount' => $entry_fee,
                'status' => 'CAPTURED',
                'charge_timestamp' => new \DateTime(),
            ]);

        $this->repository->expects($this->once())
            ->method('updateAuthorizationStatus')
            ->with($auth_id, 'CAPTURED');

        // Act - Capture when bidder wins
        $capture_result = $this->service->captureEntryFee($auth_id, $entry_fee);

        // Assert
        $this->assertEquals('AUTHORIZED', $auth_result['status']);
        $this->assertEquals('CAPTURED', $capture_result['status']);
        $this->assertEquals($entry_fee->getAmount(), $capture_result['amount']->getAmount());
    }

    /**
     * Test complete lifecycle: authorize → refund (outbid).
     *
     * Scenario: Bidder places bid (authorize), gets outbid, refund scheduled then processed.
     *
     * @test
     * @requirement REQ-ENTRY-FEE-PAYMENT-001
     */
    public function test_complete_lifecycle_outbid_refund(): void
    {
        // Arrange - Authorize phase
        $auction_id = 123;
        $user_id = 1;
        $bid_id = 'bid-uuid-123';
        $payment_token = 'tok_square_abc123';
        $bid_amount = new Money(5000);
        $entry_fee = new Money(2500);

        $this->calculator->expects($this->once())
            ->method('calculateEntryFee')
            ->willReturn($entry_fee);

        $this->gateway->expects($this->once())
            ->method('authorizePayment')
            ->willReturn([
                'auth_id' => 'auth_123',
                'expires_at' => new \DateTime('+7 days'),
            ]);

        $this->gateway->expects($this->once())
            ->method('getProviderName')
            ->willReturn('square');

        $this->repository->expects($this->any())
            ->method('recordAuthorization')
            ->willReturn(1);

        // Authorize the bid
        $auth_result = $this->service->authorizeEntryFee(
            $auction_id,
            $user_id,
            $bid_id,
            $payment_token,
            $bid_amount,
            'john@example.com'
        );

        // Arrange - Schedule refund
        $auth_id = $auth_result['auth_id'];
        $this->repository->expects($this->once())
            ->method('scheduleRefund')
            ->willReturn('REFUND-123');

        $this->repository->expects($this->atLeast(1))
            ->method('updateAuthorizationStatus')
            ->willReturn(true);

        // Schedule refund (outbid)
        $refund_schedule = $this->service->scheduleRefund(
            $auth_id,
            $user_id,
            'Outbid in auction'
        );

        // Arrange - Process refund (24h later)
        $this->gateway->expects($this->once())
            ->method('refundPayment')
            ->willReturn([
                'refund_id' => 'REFUND-123',
                'amount_refunded' => $entry_fee,
                'status' => 'REFUNDED',
                'refund_timestamp' => new \DateTime(),
            ]);

        $this->repository->expects($this->once())
            ->method('updateRefundStatus')
            ->with('REFUND-123', 'REFUNDED');

        // Act - Process the scheduled refund
        $refund_result = $this->service->processScheduledRefund(
            'REFUND-123',
            $auth_id,
            $entry_fee
        );

        // Assert
        $this->assertEquals('AUTHORIZED', $auth_result['status']);
        $this->assertEquals('REFUND_PENDING', $refund_schedule['status']);
        $this->assertEquals('REFUNDED', $refund_result['status']);
    }
}
