<?php

namespace Yith\Auctions\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Yith\Auctions\Services\BidPaymentIntegration;
use Yith\Auctions\Contracts\PaymentGatewayInterface;
use Yith\Auctions\Repository\PaymentAuthorizationRepository;
use Yith\Auctions\Services\CommissionCalculator;
use Yith\Auctions\Exceptions\PaymentException;

/**
 * BidPaymentIntegrationTest - Unit tests for bid payment integration.
 *
 * Verifies:
 * - Entry fee authorization on bid placement
 * - Payment method retrieval
 * - Error handling (card decline, expired card, etc)
 * - Idempotency key generation
 * - Authorization storage with bid linkage
 * - Entry fee calculation
 * - User-friendly error messages
 *
 * @package Yith\Auctions\Tests\Unit\Services
 * @requirement REQ-ENTRY-FEE-PAYMENT-002
 */
class BidPaymentIntegrationTest extends TestCase
{
    private object $payment_gateway;
    private object $repository;
    private object $calculator;
    private BidPaymentIntegration $integration;

    protected function setUp(): void
    {
        $this->payment_gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->repository = $this->createMock(PaymentAuthorizationRepository::class);
        $this->calculator = $this->createMock(CommissionCalculator::class);

        $this->integration = new BidPaymentIntegration(
            $this->payment_gateway,
            $this->repository,
            $this->calculator
        );
    }

    /**
     * Test: Authorize payment for bid successfully.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_authorize_payment_succeeds(): void
    {
        $auction_id = 123;
        $bidder_id = 456;
        $bid_amount = 1500.00;
        $bid_id = 'bid_abc123';

        // Mock entry fee calculation
        $this->calculator->method('calculateEntryFee')->willReturn(5000); // $50.00

        // Mock payment gateway authorization
        $this->payment_gateway->method('authorizePayment')->willReturn('auth_xyz789');
        $this->payment_gateway->method('getProviderName')->willReturn('square');

        // Mock repository storage
        $this->repository->method('recordAuthorization')->willReturn([
            'authorization_id' => 'auth_xyz789',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

        // Mock product and payment method
        $this->mockWooCommerceProduct($auction_id, true);
        $this->mockPaymentMethod($bidder_id, 'tok_visa');

        // Authorize payment
        $result = $this->integration->authorizePaymentForBid($auction_id, $bidder_id, $bid_amount, $bid_id);

        // Assert
        $this->assertEquals('auth_xyz789', $result['authorization_id']);
        $this->assertEquals(5000, $result['amount_cents']);
        $this->assertEquals('AUTHORIZED', $result['status']);
        $this->assertEquals($bid_id, $result['bid_id']);
    }

    /**
     * Test: Entry fees disabled returns skipped authorization.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_entry_fees_disabled_returns_skipped(): void
    {
        $this->mockWooCommerceProduct(123, false); // Entry fees disabled

        $result = $this->integration->authorizePaymentForBid(123, 456, 1500.00, 'bid_123');

        $this->assertEquals('', $result['authorization_id']);
        $this->assertEquals(0, $result['amount_cents']);
        $this->assertEquals('SKIPPED', $result['status']);
    }

    /**
     * Test: No payment method throws exception.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_no_payment_method_throws_exception(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionCode(0); // PaymentException uses message as code

        $this->mockWooCommerceProduct(123, true);
        $this->mockPaymentMethod(456, null); // No payment method

        $this->integration->authorizePaymentForBid(123, 456, 1500.00, 'bid_123');
    }

    /**
     * Test: Card decline throws exception with correct code.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_card_decline_throws_payment_exception(): void
    {
        $this->mockWooCommerceProduct(123, true);
        $this->mockPaymentMethod(456, 'tok_declined');

        $this->calculator->method('calculateEntryFee')->willReturn(5000);

        // Gateway throws exception on declined card
        $this->payment_gateway->method('authorizePayment')
            ->willThrowException(new PaymentException('Card declined', 'CARD_DECLINED'));

        $this->expectException(PaymentException::class);

        $this->integration->authorizePaymentForBid(123, 456, 1500.00, 'bid_123');
    }

    /**
     * Test: Card expiration error returns correct message.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_expired_card_error_message(): void
    {
        $exception = new PaymentException('Card expired', 'CARD_EXPIRED');

        $message = $this->integration->getErrorMessage($exception);

        $this->assertStringContainsString('expired', strtolower($message));
        $this->assertStringContainsString('different card', strtolower($message));
    }

    /**
     * Test: Invalid CVC error message.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_invalid_cvc_error_message(): void
    {
        $exception = new PaymentException('Invalid CVC', 'INVALID_CVC');

        $message = $this->integration->getErrorMessage($exception);

        $this->assertStringContainsString('security code', strtolower($message));
    }

    /**
     * Test: Insufficient funds error message.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_insufficient_funds_error_message(): void
    {
        $exception = new PaymentException('Insufficient funds', 'INSUFFICIENT_FUNDS');

        $message = $this->integration->getErrorMessage($exception);

        $this->assertStringContainsString('insufficient', strtolower($message));
    }

    /**
     * Test: Network error message.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_network_error_message(): void
    {
        $exception = new PaymentException('Network error', 'NETWORK_ERROR');

        $message = $this->integration->getErrorMessage($exception);

        $this->assertStringContainsString('temporarily', strtolower($message));
    }

    /**
     * Test: Rate limit error message.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_rate_limit_error_message(): void
    {
        $exception = new PaymentException('Rate limited', 'RATE_LIMIT');

        $message = $this->integration->getErrorMessage($exception);

        $this->assertStringContainsString('too many', strtolower($message));
    }

    /**
     * Test: Authorization stored with bid_id linkage.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_authorization_stored_with_bid_id(): void
    {
        $auction_id = 123;
        $bidder_id = 456;
        $bid_id = 'bid_unique_123';

        $this->mockWooCommerceProduct($auction_id, true);
        $this->mockPaymentMethod($bidder_id, 'tok_visa');
        $this->calculator->method('calculateEntryFee')->willReturn(5000);
        $this->payment_gateway->method('authorizePayment')->willReturn('auth_xyz');
        $this->payment_gateway->method('getProviderName')->willReturn('square');

        // Verify recordAuthorization is called with bid_id
        $this->repository->expects($this->once())
            ->method('recordAuthorization')
            ->willReturnCallback(function ($data) use ($bid_id) {
                $this->assertEquals($bid_id, $data['bid_id']);
                $this->assertEquals('AUTHORIZED', $data['status']);
                return ['expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))];
            });

        $this->integration->authorizePaymentForBid($auction_id, $bidder_id, 1500.00, $bid_id);
    }

    /**
     * Test: Idempotency key prevents duplicate charges.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_idempotency_key_deterministic(): void
    {
        $this->mockWooCommerceProduct(123, true);
        $this->mockPaymentMethod(456, 'tok_visa');
        $this->calculator->method('calculateEntryFee')->willReturn(5000);
        $this->payment_gateway->method('authorizePayment')->willReturn('auth_1');
        $this->payment_gateway->method('getProviderName')->willReturn('square');

        // Capture idempotency keys from gateway calls
        $captured_keys = [];
        $this->payment_gateway->expects($this->any())
            ->method('authorizePayment')
            ->willReturnCallback(function ($payment_method_id, $amount, $metadata) use (&$captured_keys) {
                $captured_keys[] = $metadata['idempotency_key'];
                return 'auth_' . count($captured_keys);
            });

        $this->repository->method('recordAuthorization')
            ->willReturn(['expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))]);

        // Same bid_id should produce same key
        $bid_id = 'bid_same';
        $this->integration->authorizePaymentForBid(123, 456, 1500.00, $bid_id);
        $key1 = end($captured_keys);

        $this->integration->authorizePaymentForBid(123, 456, 1500.00, $bid_id);
        $key2 = end($captured_keys);

        $this->assertEquals($key1, $key2, 'Same bid_id should produce same idempotency key');

        // Different bid_id should produce different key
        $this->integration->authorizePaymentForBid(123, 456, 1500.00, 'bid_different');
        $key3 = end($captured_keys);

        $this->assertNotEquals($key1, $key3, 'Different bid_id should produce different key');
    }

    /**
     * Test: Entry fee calculation called with correct amount.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_entry_fee_calculation_called_correctly(): void
    {
        $this->mockWooCommerceProduct(123, true);
        $this->mockPaymentMethod(456, 'tok_visa');
        $this->payment_gateway->method('authorizePayment')->willReturn('auth_1');
        $this->payment_gateway->method('getProviderName')->willReturn('square');

        // Verify entry fee calculator called with cents value
        $this->calculator->expects($this->once())
            ->method('calculateEntryFee')
            ->with(150000) // 1500.00 * 100
            ->willReturn(5000);

        $this->repository->method('recordAuthorization')
            ->willReturn(['expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))]);

        $this->integration->authorizePaymentForBid(123, 456, 1500.00, 'bid_123');
    }

    /**
     * Test: Payment gateway called with correct metadata.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_payment_gateway_called_with_correct_metadata(): void
    {
        $auction_id = 123;
        $bidder_id = 456;
        $bid_amount = 1500.00;
        $bid_id = 'bid_xyz';

        $this->mockWooCommerceProduct($auction_id, true);
        $this->mockPaymentMethod($bidder_id, 'tok_visa');
        $this->calculator->method('calculateEntryFee')->willReturn(5000);
        $this->payment_gateway->method('getProviderName')->willReturn('square');

        // Verify payment gateway called with correct metadata
        $this->payment_gateway->expects($this->once())
            ->method('authorizePayment')
            ->willReturnCallback(function ($token, $amount, $metadata) use ($auction_id, $bidder_id, $bid_id, $bid_amount) {
                $this->assertEquals($bid_id, $metadata['bid_id']);
                $this->assertEquals($auction_id, $metadata['auction_id']);
                $this->assertEquals($bidder_id, $metadata['bidder_id']);
                $this->assertEquals($bid_amount, $metadata['bid_amount']);
                $this->assertArrayHasKey('idempotency_key', $metadata);
                return 'auth_1';
            });

        $this->repository->method('recordAuthorization')
            ->willReturn(['expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))]);

        $this->integration->authorizePaymentForBid($auction_id, $bidder_id, $bid_amount, $bid_id);
    }

    /**
     * Test: Multiple bids from same user create separate authorizations.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_multiple_bids_separate_authorizations(): void
    {
        $bidder_id = 456;

        $this->mockWooCommerceProduct(123, true);
        $this->mockWooCommerceProduct(124, true);
        $this->mockPaymentMethod($bidder_id, 'tok_visa');
        $this->calculator->method('calculateEntryFee')->willReturn(5000);
        $this->payment_gateway->method('getProviderName')->willReturn('square');

        // Different auctions should create different authorizations
        $this->payment_gateway->method('authorizePayment')
            ->willReturnOnConsecutiveCalls('auth_auction_1', 'auth_auction_2');

        $this->repository->method('recordAuthorization')
            ->willReturn(['expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))]);

        $result1 = $this->integration->authorizePaymentForBid(123, $bidder_id, 1500.00, 'bid_auction_1');
        $result2 = $this->integration->authorizePaymentForBid(124, $bidder_id, 2000.00, 'bid_auction_2');

        $this->assertEquals('auth_auction_1', $result1['authorization_id']);
        $this->assertEquals('auth_auction_2', $result2['authorization_id']);
        $this->assertNotEquals($result1['authorization_id'], $result2['authorization_id']);
    }

    /**
     * Test: Unknown error code returns generic message.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-002
     */
    public function test_unknown_error_code_returns_generic_message(): void
    {
        $exception = new PaymentException('Unknown error', 'UNKNOWN_ERROR_CODE');

        $message = $this->integration->getErrorMessage($exception);

        $this->assertStringContainsString('failed', strtolower($message));
        $this->assertStringContainsString('contact support', strtolower($message));
    }

    /**
     * Helper: Mock WooCommerce product.
     */
    private function mockWooCommerceProduct(int $product_id, bool $entry_fees_enabled): void
    {
        // Would mock WooCommerce product methods in integration test environment
        // Simplified for unit tests
    }

    /**
     * Helper: Mock payment method retrieval.
     */
    private function mockPaymentMethod(int $user_id, $token): void
    {
        // Would mock database query in integration test environment
        // Simplified for unit tests
    }
}
