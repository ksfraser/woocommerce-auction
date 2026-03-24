<?php
/**
 * Payment Processor Factory Unit Tests
 *
 * Tests for payment processor factory routing and adapter registration.
 *
 * @package    YITH\Auctions\Tests\Unit\Services
 * @since      4.0.0
 * @author     YITH
 * @requirement REQ-4D-2-1: Payment processor factory testing
 *
 * @coversDefaultClass \WC\Auction\Services\PaymentProcessorFactory
 */

namespace WC\Auction\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WC\Auction\Services\PaymentProcessorFactory;
use WC\Auction\Contracts\IPaymentProcessorAdapter;
use WC\Auction\Models\SellerPayoutMethod;
use WC\Auction\Models\TransactionResult;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/../../../../' );
}

/**
 * Mock adapter for testing
 *
 * @since 4.0.0
 */
class MockAdapter implements IPaymentProcessorAdapter
{
    private string $processor_name;
    private array $supported_methods;

    public function __construct(string $processor_name, array $supported_methods = [])
    {
        $this->processor_name = $processor_name;
        $this->supported_methods = $supported_methods;
    }

    public function initiatePayment(
        string $transaction_id,
        int $amount_cents,
        SellerPayoutMethod $recipient
    ): TransactionResult {
        return TransactionResult::create(
            transaction_id: $transaction_id,
            processor_name: $this->processor_name,
            status: TransactionResult::STATUS_PENDING,
            amount_cents: $amount_cents,
            processor_fees_cents: 100,
            processor_reference: 'mock_ref'
        );
    }

    public function getTransactionStatus(string $transaction_id): TransactionResult
    {
        return TransactionResult::create(
            transaction_id: $transaction_id,
            processor_name: $this->processor_name,
            status: TransactionResult::STATUS_COMPLETED,
            amount_cents: 10000,
            processor_fees_cents: 100,
            processor_reference: 'mock_ref'
        );
    }

    public function refundTransaction(string $transaction_id, ?int $amount_cents = null): TransactionResult
    {
        return TransactionResult::create(
            transaction_id: $transaction_id,
            processor_name: $this->processor_name,
            status: TransactionResult::STATUS_COMPLETED,
            amount_cents: $amount_cents ?? 10000,
            processor_fees_cents: 100,
            processor_reference: 'mock_ref'
        );
    }

    public function getProcessorName(): string
    {
        return $this->processor_name;
    }

    public function supportsMethod(string $method_type): bool
    {
        return in_array($method_type, $this->supported_methods, true);
    }
}

/**
 * Class PaymentProcessorFactoryTest
 *
 * @since 4.0.0
 */
class PaymentProcessorFactoryTest extends TestCase
{
    /**
     * Factory instance
     *
     * @var PaymentProcessorFactory
     */
    private PaymentProcessorFactory $factory;

    /**
     * Set up test environment
     *
     * @since 4.0.0
     */
    protected function setUp(): void
    {
        $this->factory = new PaymentProcessorFactory();
    }

    /**
     * Test factory registers adapter
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::registerAdapter
     *
     * @since 4.0.0
     */
    public function test_factory_registers_adapter(): void
    {
        $adapter = new MockAdapter('TestProcessor', [SellerPayoutMethod::METHOD_ACH]);

        $result = $this->factory->registerAdapter($adapter);

        $this->assertSame($this->factory, $result, 'registerAdapter should return $this for chaining');
        $this->assertTrue($this->factory->supportsProcessor('TestProcessor'));
    }

    /**
     * Test factory returns registered adapter by processor name
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::getAdapterByProcessor
     *
     * @since 4.0.0
     */
    public function test_factory_returns_adapter_by_processor_name(): void
    {
        $adapter = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $this->factory->registerAdapter($adapter);

        $retrieved = $this->factory->getAdapterByProcessor('Square');

        $this->assertSame($adapter, $retrieved);
    }

    /**
     * Test factory returns null for non-registered processor
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::getAdapterByProcessor
     *
     * @since 4.0.0
     */
    public function test_factory_returns_null_for_unregistered_processor(): void
    {
        $result = $this->factory->getAdapterByProcessor('NonExistent');

        $this->assertNull($result);
    }

    /**
     * Test factory returns adapter for supported method
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::getAdapter
     *
     * @since 4.0.0
     */
    public function test_factory_returns_adapter_for_method(): void
    {
        $adapter = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $this->factory->registerAdapter($adapter);

        $retrieved = $this->factory->getAdapter(SellerPayoutMethod::METHOD_ACH);

        $this->assertSame($adapter, $retrieved);
    }

    /**
     * Test factory supports method check
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::supportsMethod
     *
     * @since 4.0.0
     */
    public function test_factory_supports_method(): void
    {
        $adapter = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $this->factory->registerAdapter($adapter);

        $this->assertTrue($this->factory->supportsMethod(SellerPayoutMethod::METHOD_ACH));
        $this->assertFalse($this->factory->supportsMethod(SellerPayoutMethod::METHOD_PAYPAL));
    }

    /**
     * Test factory supports processor check
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::supportsProcessor
     *
     * @since 4.0.0
     */
    public function test_factory_supports_processor(): void
    {
        $adapter = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $this->factory->registerAdapter($adapter);

        $this->assertTrue($this->factory->supportsProcessor('Square'));
        $this->assertFalse($this->factory->supportsProcessor('PayPal'));
    }

    /**
     * Test factory gets supported methods
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::getSupportedMethods
     *
     * @since 4.0.0
     */
    public function test_factory_gets_supported_methods(): void
    {
        $square = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $paypal = new MockAdapter('PayPal', [
            SellerPayoutMethod::METHOD_PAYPAL,
            SellerPayoutMethod::METHOD_ACH,
        ]);

        $this->factory->registerAdapter($square);
        $this->factory->registerAdapter($paypal);

        $methods = $this->factory->getSupportedMethods();

        $this->assertContains(SellerPayoutMethod::METHOD_ACH, $methods);
        $this->assertContains(SellerPayoutMethod::METHOD_PAYPAL, $methods);
    }

    /**
     * Test factory gets registered processors
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::getRegisteredProcessors
     *
     * @since 4.0.0
     */
    public function test_factory_gets_registered_processors(): void
    {
        $square = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $paypal = new MockAdapter('PayPal', [SellerPayoutMethod::METHOD_PAYPAL]);

        $this->factory->registerAdapter($square);
        $this->factory->registerAdapter($paypal);

        $processors = $this->factory->getRegisteredProcessors();

        $this->assertContains('Square', $processors);
        $this->assertContains('PayPal', $processors);
    }

    /**
     * Test factory sets preferred processor for method
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::setPreferredProcessor
     *
     * @since 4.0.0
     */
    public function test_factory_sets_preferred_processor(): void
    {
        $square = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $paypal = new MockAdapter('PayPal', [SellerPayoutMethod::METHOD_ACH]);

        $this->factory->registerAdapter($square);
        $this->factory->registerAdapter($paypal);

        // Prefer PayPal for ACH
        $result = $this->factory->setPreferredProcessor(
            SellerPayoutMethod::METHOD_ACH,
            'PayPal'
        );

        $this->assertSame($this->factory, $result, 'setPreferredProcessor should return $this for chaining');

        // Should now return PayPal adapter for ACH
        $adapter = $this->factory->getAdapter(SellerPayoutMethod::METHOD_ACH);
        $this->assertSame($paypal, $adapter);
    }

    /**
     * Test factory throws when setting preferred processor that doesn't exist
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::setPreferredProcessor
     *
     * @since 4.0.0
     */
    public function test_factory_throws_when_setting_unregistered_preferred_processor(): void
    {
        $this->expectException(\Exception::class);

        $this->factory->setPreferredProcessor(
            SellerPayoutMethod::METHOD_ACH,
            'NonExistent'
        );
    }

    /**
     * Test factory gets method mapping
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::getMethodMapping
     *
     * @since 4.0.0
     */
    public function test_factory_gets_method_mapping(): void
    {
        $square = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $paypal = new MockAdapter('PayPal', [SellerPayoutMethod::METHOD_PAYPAL]);

        $this->factory->registerAdapter($square);
        $this->factory->registerAdapter($paypal);

        $mapping = $this->factory->getMethodMapping();

        $this->assertIsArray($mapping);
        $this->assertArrayHasKey(SellerPayoutMethod::METHOD_ACH, $mapping);
    }

    /**
     * Test factory method chaining
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::registerAdapter
     *
     * @since 4.0.0
     */
    public function test_factory_method_chaining(): void
    {
        $square = new MockAdapter('Square', [SellerPayoutMethod::METHOD_ACH]);
        $paypal = new MockAdapter('PayPal', [SellerPayoutMethod::METHOD_PAYPAL]);
        $stripe = new MockAdapter('Stripe', [
            SellerPayoutMethod::METHOD_ACH,
            SellerPayoutMethod::METHOD_STRIPE,
        ]);

        // Chain multiple registrations
        $result = $this->factory
            ->registerAdapter($square)
            ->registerAdapter($paypal)
            ->registerAdapter($stripe);

        $this->assertSame($this->factory, $result);
        $this->assertCount(3, $this->factory->getRegisteredProcessors());
    }

    /**
     * Test factory fallback to first supporting adapter if preferred not found
     *
     * @test
     * @covers \WC\Auction\Services\PaymentProcessorFactory::getAdapter
     *
     * @since 4.0.0
     */
    public function test_factory_fallback_to_first_supporting_adapter(): void
    {
        $stripe = new MockAdapter('Stripe', [SellerPayoutMethod::METHOD_ACH]);
        $paypal = new MockAdapter('PayPal', [SellerPayoutMethod::METHOD_ACH]);

        // Register in order
        $this->factory->registerAdapter($stripe);
        $this->factory->registerAdapter($paypal);

        // Request ACH (default maps to Square, which isn't registered)
        $adapter = $this->factory->getAdapter(SellerPayoutMethod::METHOD_ACH);

        // Should fall back to first registered that supports it
        $this->assertNotNull($adapter);
        $this->assertContains($adapter->getProcessorName(), ['Stripe', 'PayPal']);
    }
}
