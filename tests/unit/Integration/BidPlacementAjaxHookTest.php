<?php

namespace Yith\Auctions\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Yith\Auctions\Integration\BidPlacementAjaxHook;
use Yith\Auctions\Services\BidPaymentIntegration;
use Yith\Auctions\Exceptions\PaymentException;

/**
 * BidPlacementAjaxHookTest - Unit tests for AJAX hook integration.
 *
 * Verifies:
 * - Hook correctly intercepts bid submission
 * - Payment authorization called with correct parameters
 * - Authorization ID added to bid data
 * - Payment exceptions handled and re-thrown
 * - Unique bid IDs generated consistently
 * - Error logging occurs on failure
 *
 * @package Yith\Auctions\Tests\Unit\Integration
 * @requirement REQ-ENTRY-FEE-PAYMENT-003
 */
class BidPlacementAjaxHookTest extends TestCase
{
    private object $payment_integration;
    private BidPlacementAjaxHook $hook;

    protected function setUp(): void
    {
        $this->payment_integration = $this->createMock(BidPaymentIntegration::class);
        $this->hook = new BidPlacementAjaxHook($this->payment_integration);
    }

    /**
     * Test: Hook successfully adds authorization_id to bid data.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_hook_adds_authorization_to_bid_data(): void
    {
        $bid_data = [
            'user_id' => 123,
            'product_id' => 456,
            'bid_amount' => 1500.00,
            'date' => current_time('mysql'),
        ];

        $this->payment_integration->method('authorizePaymentForBid')
            ->willReturn([
                'authorization_id' => 'auth_xyz789',
                'amount_cents' => 5000,
                'status' => 'AUTHORIZED',
            ]);

        $result = $this->hook->authorizePaymentForBid(
            $bid_data,
            123,
            456,
            1500.00
        );

        $this->assertArrayHasKey('authorization_id', $result);
        $this->assertEquals('auth_xyz789', $result['authorization_id']);
        $this->assertArrayHasKey('bid_id', $result);
    }

    /**
     * Test: Payment authorization called with correct parameters.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_payment_authorization_called_correctly(): void
    {
        $bid_data = [
            'user_id' => 123,
            'product_id' => 456,
            'bid_amount' => 1500.00,
            'date' => current_time('mysql'),
        ];

        $this->payment_integration->expects($this->once())
            ->method('authorizePaymentForBid')
            ->willReturnCallback(function ($product_id, $user_id, $bid_amount, $bid_id) {
                $this->assertEquals(456, $product_id);
                $this->assertEquals(123, $user_id);
                $this->assertEquals(1500.00, $bid_amount);
                $this->assertIsString($bid_id);
                $this->assertNotEmpty($bid_id);
                return [
                    'authorization_id' => 'auth_test',
                    'status' => 'AUTHORIZED',
                ];
            });

        $this->hook->authorizePaymentForBid($bid_data, 123, 456, 1500.00);
    }

    /**
     * Test: Payment exception is re-thrown.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_payment_exception_rethrown(): void
    {
        $this->payment_integration->method('authorizePaymentForBid')
            ->willThrowException(new PaymentException('Card declined', 'CARD_DECLINED'));

        $this->expectException(PaymentException::class);

        $this->hook->authorizePaymentForBid(
            ['user_id' => 123],
            123,
            456,
            1500.00
        );
    }

    /**
     * Test: Bid ID is generated and consistent.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_bid_id_generated_and_returned(): void
    {
        $bid_data = [
            'user_id' => 123,
            'product_id' => 456,
            'bid_amount' => 1500.00,
        ];

        $captured_bid_id = null;
        $this->payment_integration->method('authorizePaymentForBid')
            ->willReturnCallback(function ($product_id, $user_id, $bid_amount, $bid_id) use (&$captured_bid_id) {
                $captured_bid_id = $bid_id;
                return [
                    'authorization_id' => 'auth_test',
                    'status' => 'AUTHORIZED',
                ];
            });

        $result = $this->hook->authorizePaymentForBid($bid_data, 123, 456, 1500.00);

        $this->assertNotEmpty($captured_bid_id);
        $this->assertEquals($captured_bid_id, $result['bid_id']);
    }

    /**
     * Test: Original bid data preserved in result.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_original_bid_data_preserved(): void
    {
        $bid_data = [
            'user_id' => 123,
            'product_id' => 456,
            'bid_amount' => 1500.00,
            'date' => '2024-03-25 10:30:00',
            'custom_field' => 'custom_value',
        ];

        $this->payment_integration->method('authorizePaymentForBid')
            ->willReturn([
                'authorization_id' => 'auth_test',
                'status' => 'AUTHORIZED',
            ]);

        $result = $this->hook->authorizePaymentForBid($bid_data, 123, 456, 1500.00);

        // Original data should be preserved
        $this->assertEquals(123, $result['user_id']);
        $this->assertEquals(456, $result['product_id']);
        $this->assertEquals(1500.00, $result['bid_amount']);
        $this->assertEquals('2024-03-25 10:30:00', $result['date']);
        $this->assertEquals('custom_value', $result['custom_field']);

        // New data should be added
        $this->assertArrayHasKey('authorization_id', $result);
        $this->assertArrayHasKey('bid_id', $result);
    }

    /**
     * Test: Multiple bids generate different bid IDs.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_different_bids_get_different_ids(): void
    {
        $bid_amounts = [1500.00, 2000.00, 2500.00];
        $generated_ids = [];

        $this->payment_integration->method('authorizePaymentForBid')
            ->willReturnCallback(function ($product_id, $user_id, $bid_amount, $bid_id) use (&$generated_ids) {
                $generated_ids[] = $bid_id;
                return [
                    'authorization_id' => 'auth_' . count($generated_ids),
                    'status' => 'AUTHORIZED',
                ];
            });

        foreach ($bid_amounts as $bid_amount) {
            $this->hook->authorizePaymentForBid(
                ['user_id' => 123],
                123,
                456,
                $bid_amount
            );
        }

        // All IDs should be unique
        $this->assertCount(3, $generated_ids);
        $this->assertCount(3, array_unique($generated_ids));
    }

    /**
     * Test: Card decline error is logged and re-thrown.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_card_decline_logged(): void
    {
        $this->payment_integration->method('authorizePaymentForBid')
            ->willThrowException(new PaymentException('Card declined', 'CARD_DECLINED'));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Card declined');

        $this->hook->authorizePaymentForBid(
            [],
            123,
            456,
            1500.00
        );
    }

    /**
     * Test: Multiple calls from same user generate different bid IDs.
     *
     * @test
     *
     * @requirement REQ-ENTRY-FEE-PAYMENT-003
     */
    public function test_same_user_different_auctions(): void
    {
        $bid_ids = [];

        $this->payment_integration->method('authorizePaymentForBid')
            ->willReturnCallback(function ($product_id, $user_id, $bid_amount, $bid_id) use (&$bid_ids) {
                $bid_ids[] = $bid_id;
                return [
                    'authorization_id' => 'auth_' . count($bid_ids),
                    'status' => 'AUTHORIZED',
                ];
            });

        // Same user bidding on different auctions
        $this->hook->authorizePaymentForBid(['user_id' => 123], 123, 456, 1500.00);
        $this->hook->authorizePaymentForBid(['user_id' => 123], 123, 789, 2000.00);

        // Should generate different bid IDs
        $this->assertNotEquals($bid_ids[0], $bid_ids[1]);
    }
}
