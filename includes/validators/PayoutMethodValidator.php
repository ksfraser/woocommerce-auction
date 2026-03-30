<?php
/**
 * Payout Method Validator - Processor-specific validation for payment methods
 *
 * @package    WooCommerce Auction
 * @subpackage Validators
 * @version    4.0.0
 * @requirement REQ-4D-045: Validate payout methods per processor type
 */

namespace WC\Auction\Validators;

use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Exceptions\ValidationException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PayoutMethodValidator - Validates payout methods by processor type
 *
 * Different payment processors require different validation rules.
 * This class handles processor-specific validation logic.
 *
 * @requirement REQ-4D-045: Validate payout method data by processor
 */
class PayoutMethodValidator {

    /**
     * Validate payout method for specific processor
     *
     * @param SellerPayoutMethod $method Method to validate
     * @param string             $processor Processor type (square, paypal, stripe)
     * @throws ValidationException If validation fails
     *
     * @requirement REQ-4D-045: Validate all processor-specific required fields
     */
    public function validate( SellerPayoutMethod $method, string $processor ): void {
        $processor = strtolower( $processor );

        switch ( $processor ) {
            case 'square':
                $this->validateSquareMethod( $method );
                break;
            case 'paypal':
                $this->validatePayPalMethod( $method );
                break;
            case 'stripe':
                $this->validateStripeMethod( $method );
                break;
            default:
                throw new ValidationException( "Unknown processor type: {$processor}" );
        }
    }

    /**
     * Validate Square payout method
     *
     * Square requires: location_id, account_id, access_token
     *
     * @param SellerPayoutMethod $method
     * @throws ValidationException
     */
    private function validateSquareMethod( SellerPayoutMethod $method ): void {
        $data = $method->getMethodData();

        if ( empty( $data['location_id'] ) ) {
            throw new ValidationException( 'Square payout method requires location_id' );
        }

        if ( ! is_string( $data['location_id'] ) ) {
            throw new ValidationException( 'Square location_id must be a string' );
        }

        if ( empty( $data['account_id'] ) ) {
            throw new ValidationException( 'Square payout method requires account_id' );
        }

        if ( ! is_string( $data['account_id'] ) ) {
            throw new ValidationException( 'Square account_id must be a string' );
        }

        if ( empty( $data['access_token'] ) ) {
            throw new ValidationException( 'Square payout method requires access_token' );
        }

        if ( ! is_string( $data['access_token'] ) ) {
            throw new ValidationException( 'Square access_token must be a string' );
        }

        // location_id format: "sq0a..." (Square test/prod prefixes)
        if ( ! preg_match( '/^(sq0a|sq1a)/', $data['location_id'] ) ) {
            throw new ValidationException( 'Square location_id format invalid' );
        }
    }

    /**
     * Validate PayPal payout method
     *
     * PayPal requires: account_email, verification status
     *
     * @param SellerPayoutMethod $method
     * @throws ValidationException
     */
    private function validatePayPalMethod( SellerPayoutMethod $method ): void {
        $data = $method->getMethodData();

        if ( empty( $data['account_email'] ) ) {
            throw new ValidationException( 'PayPal payout method requires account_email' );
        }

        if ( ! is_email( $data['account_email'] ) ) {
            throw new ValidationException( 'PayPal account_email must be a valid email' );
        }

        if ( empty( $data['merchant_id'] ) ) {
            throw new ValidationException( 'PayPal payout method requires merchant_id' );
        }

        if ( ! is_string( $data['merchant_id'] ) ) {
            throw new ValidationException( 'PayPal merchant_id must be a string' );
        }

        // merchant_id format: 10-13 alphanumeric characters
        if ( ! preg_match( '/^[A-Z0-9]{10,13}$/i', $data['merchant_id'] ) ) {
            throw new ValidationException( 'PayPal merchant_id format invalid (10-13 alphanumeric)' );
        }

        // Check verification status if provided
        if ( isset( $data['verified'] ) && ! is_bool( $data['verified'] ) ) {
            throw new ValidationException( 'PayPal verified flag must be boolean' );
        }
    }

    /**
     * Validate Stripe payout method
     *
     * Stripe requires: connected_account_id
     *
     * @param SellerPayoutMethod $method
     * @throws ValidationException
     */
    private function validateStripeMethod( SellerPayoutMethod $method ): void {
        $data = $method->getMethodData();

        if ( empty( $data['connected_account_id'] ) ) {
            throw new ValidationException( 'Stripe payout method requires connected_account_id' );
        }

        if ( ! is_string( $data['connected_account_id'] ) ) {
            throw new ValidationException( 'Stripe connected_account_id must be a string' );
        }

        // Stripe account IDs start with "acct_"
        if ( ! preg_match( '/^acct_[a-zA-Z0-9]{16,}$/', $data['connected_account_id'] ) ) {
            throw new ValidationException( 'Stripe connected_account_id format invalid (should be acct_...)' );
        }

        // Optional: check for restrictions
        if ( isset( $data['restrictions'] ) && ! is_array( $data['restrictions'] ) ) {
            throw new ValidationException( 'Stripe restrictions must be an array' );
        }
    }

    /**
     * Check if method is active/verified for payouts
     *
     * @param SellerPayoutMethod $method
     * @param string             $processor Processor type
     * @return bool
     */
    public function isMethodActive( SellerPayoutMethod $method, string $processor ): bool {
        $processor = strtolower( $processor );
        $data      = $method->getMethodData();

        // All methods: require is_active flag
        if ( ! $method->isActive() ) {
            return false;
        }

        // All methods: require not deleted
        if ( $method->isDeleted() ) {
            return false;
        }

        // Processor-specific checks
        switch ( $processor ) {
            case 'square':
                return ! empty( $data['location_id'] ) && ! empty( $data['access_token'] );
            case 'paypal':
                // Require email to be verified
                return ! empty( $data['account_email'] ) && ( ! isset( $data['verified'] ) || $data['verified'] );
            case 'stripe':
                // Check for any account restrictions
                return ! empty( $data['connected_account_id'] ) && ! ( isset( $data['restrictions'] ) && ! empty( $data['restrictions'] ) );
            default:
                return false;
        }
    }

    /**
     * Validate amount for payout
     *
     * @param int    $amount_cents Amount in cents
     * @param string $processor Processor type
     * @throws ValidationException
     */
    public function validateAmount( int $amount_cents, string $processor ): void {
        if ( $amount_cents <= 0 ) {
            throw new ValidationException( 'Payout amount must be greater than zero' );
        }

        // Processor-specific minimums (in cents)
        $minimums = [
            'square'  => 100,      // $1.00
            'paypal'  => 100,      // $1.00
            'stripe'  => 100,      // $1.00 (100 cents)
        ];

        $processor = strtolower( $processor );
        if ( isset( $minimums[ $processor ] ) && $amount_cents < $minimums[ $processor ] ) {
            $min_dollars = $minimums[ $processor ] / 100;
            throw new ValidationException(
                sprintf( '%s requires minimum payout of $%.2f', ucfirst( $processor ), $min_dollars )
            );
        }
    }
}
