<?php

namespace Yith\Auctions\ValueObjects;

/**
 * Money - Immutable value object for monetary amounts.
 *
 * Represents a decimal currency amount with validation.
 * Ensures consistent handling of currency values across the system.
 *
 * @package Yith\Auctions\ValueObjects
 * @requirement REQ-DOMAIN-MONEY-001: Immutable money value object
 *
 * Usage:
 * ```php
 * $amount = Money::fromFloat(99.99);
 * echo $amount->value(); // "99.99"
 * echo $amount->cents(); // 9999
 * ```
 */
final class Money
{
    /**
     * @var string Amount as string (for precision)
     */
    private string $amount;

    /**
     * Initialize money value.
     *
     * @param string $amount Amount as decimal string (e.g., "99.99")
     * @throws \InvalidArgumentException If amount is invalid
     * @requirement REQ-DOMAIN-MONEY-001
     */
    private function __construct(string $amount)
    {
        // Validate format: up to 2 decimal places
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException("Invalid money amount: {$amount}");
        }

        $this->amount = number_format((float)$amount, 2, '.', '');
    }

    /**
     * Create from float value.
     *
     * @param float $value Decimal amount
     * @return self
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public static function fromFloat(float $value): self
    {
        return new self((string)$value);
    }

    /**
     * Create from string value.
     *
     * @param string $value Decimal amount
     * @return self
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Create from cents (integer).
     *
     * @param int $cents Amount in cents
     * @return self
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public static function fromCents(int $cents): self
    {
        return new self(number_format($cents / 100, 2, '.', ''));
    }

    /**
     * Get amount as string (for database storage).
     *
     * @return string
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function value(): string
    {
        return $this->amount;
    }

    /**
     * Get amount as float.
     *
     * @return float
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function asFloat(): float
    {
        return (float)$this->amount;
    }

    /**
     * Get amount in cents as integer.
     *
     * @return int
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function cents(): int
    {
        return (int)round($this->asFloat() * 100);
    }

    /**
     * Add amount to this money.
     *
     * @param Money $other Amount to add
     * @return self New Money instance
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function add(Money $other): self
    {
        $sum = $this->asFloat() + $other->asFloat();
        return self::fromFloat($sum);
    }

    /**
     * Subtract amount from this money.
     *
     * @param Money $other Amount to subtract
     * @return self New Money instance
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function subtract(Money $other): self
    {
        $diff = $this->asFloat() - $other->asFloat();
        return self::fromFloat($diff);
    }

    /**
     * Multiply this money by a factor.
     *
     * @param float $factor Multiplication factor
     * @return self New Money instance
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function multiply(float $factor): self
    {
        $product = $this->asFloat() * $factor;
        return self::fromFloat($product);
    }

    /**
     * Compare equality.
     *
     * @param Money $other Amount to compare
     * @return bool True if equal
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function equals(Money $other): bool
    {
        return $this->cents() === $other->cents();
    }

    /**
     * Compare if greater than.
     *
     * @param Money $other Amount to compare
     * @return bool True if this is greater
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function greaterThan(Money $other): bool
    {
        return $this->cents() > $other->cents();
    }

    /**
     * Compare if less than.
     *
     * @param Money $other Amount to compare
     * @return bool True if this is less
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function lessThan(Money $other): bool
    {
        return $this->cents() < $other->cents();
    }

    /**
     * String representation.
     *
     * @return string
     * @requirement REQ-DOMAIN-MONEY-001
     */
    public function __toString(): string
    {
        return $this->amount;
    }
}
