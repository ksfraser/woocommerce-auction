<?php
/**
 * PayoutMethodValidatorTest - Unit tests for PayoutMethodValidator
 *
 * @package    WooCommerce Auction
 * @subpackage Tests
 * @version    4.0.0
 * @requirement REQ-4D-045: Test processor-specific payout method validation
 */

namespace WC\Auction\Tests\Validators;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WC\Auction\Validators\PayoutMethodValidator;
use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Exceptions\ValidationException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test suite for PayoutMethodValidator
 *
 * @requirement REQ-4D-045: Test validation for Square, PayPal, Stripe
 */
class PayoutMethodValidatorTest extends TestCase {

    /**
     * Validator instance
     *
     * @var PayoutMethodValidator
     */
    private $validator;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();
        $this->validator = new PayoutMethodValidator();
    }

    /**
     * Test validator can be instantiated
     *
     * @test
     */
    public function test_validator_can_be_instantiated(): void {
        $this->assertInstanceOf( PayoutMethodValidator::class, $this->validator );
    }

    /**
     * Test validate square method with valid data
     *
     * @test
     */
    public function test_validate_square_method_with_valid_data(): void {
        $method = $this->createMockPayoutMethod( 'square', [
            'location_id'  => 'sq0a123456789012',
            'account_id'   => 'acct_12345',
            'access_token' => 'sq_token_xyz',
        ] );

        // Should not throw
        $this->validator->validate( $method, 'square' );
        $this->assertTrue( true );
    }

    /**
     * Test validate square method missing location_id
     *
     * @test
     */
    public function test_validate_square_method_missing_location_id(): void {
        $method = $this->createMockPayoutMethod( 'square', [
            'account_id'   => 'acct_12345',
            'access_token' => 'token',
        ] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'location_id' );
        $this->validator->validate( $method, 'square' );
    }

    /**
     * Test validate square method invalid location_id format
     *
     * @test
     */
    public function test_validate_square_method_invalid_location_id_format(): void {
        $method = $this->createMockPayoutMethod( 'square', [
            'location_id'  => 'invalid_location',
            'account_id'   => 'acct_12345',
            'access_token' => 'token',
        ] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'location_id format' );
        $this->validator->validate( $method, 'square' );
    }

    /**
     * Test validate paypal method with valid data
     *
     * @test
     */
    public function test_validate_paypal_method_with_valid_data(): void {
        $method = $this->createMockPayoutMethod( 'paypal', [
            'account_email' => 'seller@example.com',
            'merchant_id'   => 'MERCHANT12345678',
            'verified'      => true,
        ] );

        // Should not throw
        $this->validator->validate( $method, 'paypal' );
        $this->assertTrue( true );
    }

    /**
     * Test validate paypal method invalid email
     *
     * @test
     */
    public function test_validate_paypal_method_invalid_email(): void {
        $method = $this->createMockPayoutMethod( 'paypal', [
            'account_email' => 'not-an-email',
            'merchant_id'   => 'MERCHANT12345678',
        ] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'email' );
        $this->validator->validate( $method, 'paypal' );
    }

    /**
     * Test validate paypal method missing merchant_id
     *
     * @test
     */
    public function test_validate_paypal_method_missing_merchant_id(): void {
        $method = $this->createMockPayoutMethod( 'paypal', [
            'account_email' => 'seller@example.com',
        ] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'merchant_id' );
        $this->validator->validate( $method, 'paypal' );
    }

    /**
     * Test validate paypal method invalid merchant_id format
     *
     * @test
     */
    public function test_validate_paypal_method_invalid_merchant_id_format(): void {
        $method = $this->createMockPayoutMethod( 'paypal', [
            'account_email' => 'seller@example.com',
            'merchant_id'   => 'TOO_SHORT',
        ] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'merchant_id format' );
        $this->validator->validate( $method, 'paypal' );
    }

    /**
     * Test validate stripe method with valid data
     *
     * @test
     */
    public function test_validate_stripe_method_with_valid_data(): void {
        $method = $this->createMockPayoutMethod( 'stripe', [
            'connected_account_id' => 'acct_1234567890123456',
        ] );

        // Should not throw
        $this->validator->validate( $method, 'stripe' );
        $this->assertTrue( true );
    }

    /**
     * Test validate stripe method missing connected_account_id
     *
     * @test
     */
    public function test_validate_stripe_method_missing_connected_account_id(): void {
        $method = $this->createMockPayoutMethod( 'stripe', [] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'connected_account_id' );
        $this->validator->validate( $method, 'stripe' );
    }

    /**
     * Test validate stripe method invalid account_id format
     *
     * @test
     */
    public function test_validate_stripe_method_invalid_account_id_format(): void {
        $method = $this->createMockPayoutMethod( 'stripe', [
            'connected_account_id' => 'invalid_account_id',
        ] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'connected_account_id format' );
        $this->validator->validate( $method, 'stripe' );
    }

    /**
     * Test validate unknown processor throws exception
     *
     * @test
     */
    public function test_validate_unknown_processor_throws_exception(): void {
        $method = $this->createMockPayoutMethod( 'unknown', [] );

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'Unknown processor' );
        $this->validator->validate( $method, 'unknown' );
    }

    /**
     * Test is method active checks all conditions
     *
     * @test
     */
    public function test_is_method_active_active_method(): void {
        $method = $this->createActiveMockPayoutMethod( 'square', [
            'location_id' => 'sq0a123',
            'access_token' => 'token',
        ] );

        $is_active = $this->validator->isMethodActive( $method, 'square' );

        $this->assertTrue( $is_active );
    }

    /**
     * Test is method active returns false for deleted method
     *
     * @test
     */
    public function test_is_method_active_deleted_method(): void {
        $method = $this->createMock( SellerPayoutMethod::class );
        $method->method( 'isActive' )->willReturn( true );
        $method->method( 'isDeleted' )->willReturn( true );

        $is_active = $this->validator->isMethodActive( $method, 'square' );

        $this->assertFalse( $is_active );
    }

    /**
     * Test is method active returns false for inactive method
     *
     * @test
     */
    public function test_is_method_active_inactive_method(): void {
        $method = $this->createMock( SellerPayoutMethod::class );
        $method->method( 'isActive' )->willReturn( false );
        $method->method( 'isDeleted' )->willReturn( false );

        $is_active = $this->validator->isMethodActive( $method, 'square' );

        $this->assertFalse( $is_active );
    }

    /**
     * Test validate amount minimum for square
     *
     * @test
     */
    public function test_validate_amount_minimum_square(): void {
        $valid_amount = 100; // $1.00

        // Should not throw
        $this->validator->validateAmount( $valid_amount, 'square' );
        $this->assertTrue( true );
    }

    /**
     * Test validate amount below minimum for square
     *
     * @test
     */
    public function test_validate_amount_below_minimum_square(): void {
        $invalid_amount = 50; // Less than $1.00

        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'minimum payout' );
        $this->validator->validateAmount( $invalid_amount, 'square' );
    }

    /**
     * Test validate amount zero
     *
     * @test
     */
    public function test_validate_amount_zero(): void {
        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'greater than zero' );
        $this->validator->validateAmount( 0, 'square' );
    }

    /**
     * Test validate amount negative
     *
     * @test
     */
    public function test_validate_amount_negative(): void {
        $this->expectException( ValidationException::class );
        $this->expectExceptionMessage( 'greater than zero' );
        $this->validator->validateAmount( -100, 'square' );
    }

    /**
     * Test validate large amount
     *
     * @test
     */
    public function test_validate_large_amount(): void {
        $large_amount = 50000000; // $500,000

        // Should not throw
        $this->validator->validateAmount( $large_amount, 'paypal' );
        $this->assertTrue( true );
    }

    /**
     * Create mock payout method
     *
     * @param string $processor Processor type
     * @param array  $method_data Method data
     * @return SellerPayoutMethod|MockObject
     */
    private function createMockPayoutMethod( $processor, $method_data ) {
        $method = $this->createMock( SellerPayoutMethod::class );
        $method->method( 'getProcessor' )->willReturn( $processor );
        $method->method( 'getMethodData' )->willReturn( $method_data );
        $method->method( 'isActive' )->willReturn( true );
        $method->method( 'isDeleted' )->willReturn( false );

        return $method;
    }

    /**
     * Create active mock payout method
     *
     * @param string $processor Processor type
     * @param array  $method_data Method data
     * @return SellerPayoutMethod|MockObject
     */
    private function createActiveMockPayoutMethod( $processor, $method_data ) {
        return $this->createMockPayoutMethod( $processor, $method_data );
    }
}
