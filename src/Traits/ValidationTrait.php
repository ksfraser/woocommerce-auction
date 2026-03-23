<?php

namespace Yith\Auctions\Traits;

/**
 * ValidationTrait - Provides input validation methods for services.
 *
 * Implements common validations: required fields, type checking, range validation.
 * Throws appropriate exceptions for validation failures.
 *
 * @package Yith\Auctions\Traits
 * @requirement REQ-VALIDATION-001: Input validation and sanitization
 */
trait ValidationTrait
{
    /**
     * Validate required field.
     *
     * @param mixed  $value Field value
     * @param string $field Field name (for error message)
     * @throws \InvalidArgumentException If field is empty
     * @requirement REQ-VALIDATION-001
     */
    protected function validateRequired($value, string $field): void
    {
        if (empty($value) && $value !== 0 && $value !== '0') {
            throw new \InvalidArgumentException("Required field '{$field}' is missing or empty");
        }
    }

    /**
     * Validate field is numeric.
     *
     * @param mixed  $value Field value
     * @param string $field Field name
     * @throws \InvalidArgumentException If not numeric
     * @requirement REQ-VALIDATION-001
     */
    protected function validateNumeric($value, string $field): void
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Field '{$field}' must be numeric");
        }
    }

    /**
     * Validate field is an integer.
     *
     * @param mixed  $value Field value
     * @param string $field Field name
     * @throws \InvalidArgumentException If not integer
     * @requirement REQ-VALIDATION-001
     */
    protected function validateInteger($value, string $field): void
    {
        if (!is_int($value) && (string)(int)$value !== (string)$value) {
            throw new \InvalidArgumentException("Field '{$field}' must be an integer");
        }
    }

    /**
     * Validate field is within range.
     *
     * @param numeric $value Field value
     * @param numeric $min   Minimum value (inclusive)
     * @param numeric $max   Maximum value (inclusive)
     * @param string  $field Field name
     * @throws \InvalidArgumentException If outside range
     * @requirement REQ-VALIDATION-001
     */
    protected function validateRange($value, $min, $max, string $field): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(
                "Field '{$field}' must be between {$min} and {$max}, got {$value}"
            );
        }
    }

    /**
     * Validate field is one of allowed values.
     *
     * @param mixed  $value  Field value
     * @param array  $allowed Allowed values
     * @param string $field  Field name
     * @throws \InvalidArgumentException If not in allowed list
     * @requirement REQ-VALIDATION-001
     */
    protected function validateEnum($value, array $allowed, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Field '{$field}' must be one of: " . implode(', ', $allowed)
            );
        }
    }

    /**
     * Validate email format.
     *
     * @param string $email Email address
     * @param string $field Field name
     * @throws \InvalidArgumentException If not valid email
     * @requirement REQ-VALIDATION-001
     */
    protected function validateEmail(string $email, string $field = 'email'): void
    {
        if (!is_email($email)) {
            throw new \InvalidArgumentException("Field '{$field}' must be a valid email address");
        }
    }

    /**
     * Validate UUID v4 format.
     *
     * @param string $uuid UUID value
     * @param string $field Field name
     * @throws \InvalidArgumentException If not valid UUID
     * @requirement REQ-VALIDATION-001
     */
    protected function validateUUID(string $uuid, string $field = 'id'): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (!preg_match($pattern, $uuid)) {
            throw new \InvalidArgumentException("Field '{$field}' must be a valid UUID");
        }
    }

    /**
     * Validate decimal precision.
     *
     * @param float  $value     Decimal value
     * @param int    $places    Number of decimal places allowed
     * @param string $field     Field name
     * @throws \InvalidArgumentException If too many decimal places
     * @requirement REQ-VALIDATION-001
     */
    protected function validateDecimalPlaces(float $value, int $places, string $field): void
    {
        $decimals = strlen(substr(strrchr((string)$value, '.'), 1));
        if ($decimals > $places) {
            throw new \InvalidArgumentException(
                "Field '{$field}' exceeds {$places} decimal places"
            );
        }
    }
}
