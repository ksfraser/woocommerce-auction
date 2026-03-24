<?php
/**
 * Payment Processor Factory
 *
 * Factory for creating and managing payment processor adapters.
 * Routes payment requests to correct adapter (Square, PayPal, Stripe) based on seller's method.
 *
 * @package    YITH\Auctions\Services
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-1: Unified payment processor factory
 *
 * UML Class Diagram:
 *
 *     ┌──────────────────────────────────────────────┐
 *     │  PaymentProcessorFactory                     │
 *     ├──────────────────────────────────────────────┤
 *     │ - adapters: IPaymentProcessorAdapter[]       │
 *     │ - method_adapter_map: array<string,string>   │
 *     ├──────────────────────────────────────────────┤
 *     │ + registerAdapter(...): void                 │
 *     │ + getAdapter(method_type): ?Adapter          │
 *     │ + getAdapterByProcessor(name): ?Adapter      │
 *     │ + supportsMethod(method_type): bool          │
 *     │ + supportsProcessor(name): bool              │
 *     └──────────────────────────────────────────────┘
 *            │
 *            │ uses
 *            ▼
 *     ┌──────────────────────────────────────────────┐
 *     │  IPaymentProcessorAdapter                    │
 *     │  (interface)                                 │
 *     │                                              │
 *     │  Implementations:                            │
 *     │  - SquarePayoutAdapter                       │
 *     │  - PayPalPayoutAdapter                       │
 *     │  - StripePayoutAdapter                       │
 *     └──────────────────────────────────────────────┘
 *
 * Usage:
 *
 *     $factory = new PaymentProcessorFactory();
 *     $factory->registerAdapter(
 *         new SquarePayoutAdapter($square_client, $location_id)
 *     );
 *     $factory->registerAdapter(
 *         new PayPalPayoutAdapter($paypal_context)
 *     );
 *     $factory->registerAdapter(
 *         new StripePayoutAdapter($stripe_key)
 *     );
 *
 *     // Get adapter for seller's payout method
 *     $adapter = $factory->getAdapter(SellerPayoutMethod::METHOD_ACH);
 *     $result = $adapter->initiatePayment($txn_id, $amount_cents, $recipient);
 */

namespace WC\Auction\Services;

use WC\Auction\Contracts\IPaymentProcessorAdapter;
use WC\Auction\Models\SellerPayoutMethod;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class PaymentProcessorFactory
 *
 * Factory for managing and routing to payment processor adapters.
 * Maintains registry of available adapters and routes based on method type or processor name.
 *
 * @since 4.0.0
 */
class PaymentProcessorFactory
{
    /**
     * Registered payment processor adapters
     *
     * @var IPaymentProcessorAdapter[]
     */
    private array $adapters = [];

    /**
     * Map of method type to processor name
     *
     * @var array<string, string>
     */
    private array $method_adapter_map = [
        SellerPayoutMethod::METHOD_ACH => 'Square', // Default ACH to Square
        SellerPayoutMethod::METHOD_PAYPAL => 'PayPal',
        SellerPayoutMethod::METHOD_STRIPE => 'Stripe',
        SellerPayoutMethod::METHOD_WALLET => 'PayPal', // Default wallet to PayPal
    ];

    /**
     * Constructor
     *
     * @since 4.0.0
     */
    public function __construct()
    {
        // Initialize empty
    }

    /**
     * Registers a payment processor adapter
     *
     * @param IPaymentProcessorAdapter $adapter Payment processor adapter
     *
     * @return $this For method chaining
     *
     * @since 4.0.0
     */
    public function registerAdapter(IPaymentProcessorAdapter $adapter): self
    {
        $processor_name = $adapter->getProcessorName();
        $this->adapters[$processor_name] = $adapter;

        return $this;
    }

    /**
     * Gets adapter for a specific payout method
     *
     * Routes based on method type (ACH, PAYPAL, STRIPE, WALLET) to appropriate adapter.
     * Can be configured to prefer different adapters for same method type.
     *
     * @param string $method_type Method type constant (from SellerPayoutMethod)
     *
     * @return IPaymentProcessorAdapter|null Matching adapter or null if not found
     *
     * @since 4.0.0
     */
    public function getAdapter(string $method_type): ?IPaymentProcessorAdapter
    {
        $processor_name = $this->method_adapter_map[$method_type] ?? null;

        if ( ! $processor_name ) {
            return null;
        }

        $adapter = $this->getAdapterByProcessor($processor_name);

        // Verify adapter supports this method
        if ( $adapter && $adapter->supportsMethod($method_type) ) {
            return $adapter;
        }

        // Fallback: Find first adapter that supports method
        foreach ( $this->adapters as $adapter ) {
            if ( $adapter->supportsMethod($method_type) ) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Gets adapter by processor name
     *
     * @param string $processor_name Processor name ('Square', 'PayPal', 'Stripe')
     *
     * @return IPaymentProcessorAdapter|null Matching adapter or null if not registered
     *
     * @since 4.0.0
     */
    public function getAdapterByProcessor(string $processor_name): ?IPaymentProcessorAdapter
    {
        return $this->adapters[$processor_name] ?? null;
    }

    /**
     * Checks if factory supports a payout method
     *
     * @param string $method_type Method type constant
     *
     * @return bool True if at least one adapter supports method
     *
     * @since 4.0.0
     */
    public function supportsMethod(string $method_type): bool
    {
        foreach ( $this->adapters as $adapter ) {
            if ( $adapter->supportsMethod($method_type) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if factory supports a processor
     *
     * @param string $processor_name Processor name
     *
     * @return bool True if processor is registered
     *
     * @since 4.0.0
     */
    public function supportsProcessor(string $processor_name): bool
    {
        return isset($this->adapters[$processor_name]);
    }

    /**
     * Gets all supported method types
     *
     * @return string[] Array of supported method types
     *
     * @since 4.0.0
     */
    public function getSupportedMethods(): array
    {
        $methods = [];

        foreach ( $this->adapters as $adapter ) {
            if ( $adapter->supportsMethod(SellerPayoutMethod::METHOD_ACH) ) {
                $methods[SellerPayoutMethod::METHOD_ACH] = true;
            }
            if ( $adapter->supportsMethod(SellerPayoutMethod::METHOD_PAYPAL) ) {
                $methods[SellerPayoutMethod::METHOD_PAYPAL] = true;
            }
            if ( $adapter->supportsMethod(SellerPayoutMethod::METHOD_STRIPE) ) {
                $methods[SellerPayoutMethod::METHOD_STRIPE] = true;
            }
            if ( $adapter->supportsMethod(SellerPayoutMethod::METHOD_WALLET) ) {
                $methods[SellerPayoutMethod::METHOD_WALLET] = true;
            }
        }

        return array_keys($methods);
    }

    /**
     * Gets all registered processor names
     *
     * @return string[] Array of processor names
     *
     * @since 4.0.0
     */
    public function getRegisteredProcessors(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Sets preferred adapter for a method type
     *
     * Useful for scenarios where multiple adapters support same method.
     * E.g., both PayPal and Stripe support ACH, prefer PayPal.
     *
     * @param string $method_type    Method type constant
     * @param string $processor_name Preferred processor name
     *
     * @return $this For method chaining
     *
     * @throws \Exception If processor not registered
     *
     * @since 4.0.0
     */
    public function setPreferredProcessor(string $method_type, string $processor_name): self
    {
        if ( ! isset($this->adapters[$processor_name]) ) {
            throw new \Exception(
                sprintf(
                    'Processor "%s" not registered. Available processors: %s',
                    $processor_name,
                    implode(', ', array_keys($this->adapters))
                )
            );
        }

        $this->method_adapter_map[$method_type] = $processor_name;

        return $this;
    }

    /**
     * Gets method-to-processor mapping (for debugging)
     *
     * @return array<string, string> Mapping of method type to processor name
     *
     * @since 4.0.0
     */
    public function getMethodMapping(): array
    {
        return $this->method_adapter_map;
    }
}
