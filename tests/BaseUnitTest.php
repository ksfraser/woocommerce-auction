<?php

namespace Yith\Auctions\Tests;

use PHPUnit\Framework\TestCase;

/**
 * BaseUnitTest - Base class for all unit tests.
 *
 * Provides common setup, test utilities, and mock factories
 * for unit test suite. All unit tests should extend this class.
 *
 * @package Yith\Auctions\Tests
 * @requirement REQ-TESTING-UNIT-001: Standardized unit test infrastructure
 */
abstract class BaseUnitTest extends TestCase
{
    /**
     * Test teardown - cleanup after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        \Mockery::close();
    }

    /**
     * Create a mock of the given class.
     *
     * @param string $class Class name to mock
     * @return \Mockery\MockInterface Mock object
     * @requirement REQ-TESTING-UNIT-001
     */
    protected function mock(string $class): \Mockery\MockInterface
    {
        return \Mockery::mock($class);
    }

    /**
     * Create a spy of the given class.
     *
     * @param string $class Class name to spy on
     * @return \Mockery\MockInterface Spy object
     * @requirement REQ-TESTING-UNIT-001
     */
    protected function spy(string $class): \Mockery\MockInterface
    {
        return \Mockery::spy($class);
    }

    /**
     * Assert numeric equality with tolerance.
     *
     * @param float $expected Expected value
     * @param float $actual   Actual value
     * @param float $delta    Tolerance (default 0.01)
     * @param string $message Error message
     * @requirement REQ-TESTING-UNIT-001
     */
    protected function assertMoneyEquals(
        float $expected,
        float $actual,
        float $delta = 0.01,
        string $message = ''
    ): void {
        $this->assertEquals($expected, $actual, $message, $delta);
    }

    /**
     * Assert that a value is valid according to a rule set.
     *
     * @param mixed  $value  Value to validate
     * @param array  $rules  Validation rules
     * @param string $message Error message
     * @requirement REQ-TESTING-UNIT-001
     */
    protected function assertValidates($value, array $rules, string $message = ''): void
    {
        // Simple validator implementation for tests
        foreach ($rules as $rule => $expected) {
            switch ($rule) {
                case 'required':
                    $this->assertNotEmpty($value, $message);
                    break;
                case 'numeric':
                    $this->assertTrue(is_numeric($value), $message);
                    break;
                case 'in':
                    $this->assertContains($value, $expected, $message);
                    break;
            }
        }
    }
}
