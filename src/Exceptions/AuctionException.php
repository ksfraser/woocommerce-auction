<?php
/**
 * Base Exception for YITH Auctions
 * 
 * All custom exceptions extend this base class for consistent error handling
 * and precise catch blocks throughout the auction system.
 * 
 * @package WC\Auction\Exceptions
 * @requirement AGENTS.md - Exception Hierarchy: Custom exceptions for different error types
 */

namespace WC\Auction\Exceptions;

/**
 * Base exception class for all auction system exceptions
 * 
 * Usage:
 *   try {
 *       $bidQueue->enqueue($job);
 *   } catch (AuctionException $e) {
 *       // Handle any auction-related exception
 *       error_log($e->getMessage());
 *   }
 */
abstract class AuctionException extends \Exception
{
    /**
     * Exception code - can be extended by subclasses
     * Suggested ranges:
     *   1000-1099: Queue/Async errors
     *   1100-1199: Validation errors
     *   1200-1299: State/Transition errors
     *   1300-1399: Resource errors
     *   1400-1499: Database errors
     */
    protected $code = 0;

    /**
     * Detailed error context for debugging
     * 
     * @var array
     */
    protected $context = [];

    /**
     * Initialize exception with message and context
     * 
     * @param string $message Exception message
     * @param int $code Exception code
     * @param array $context Additional context for debugging
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        array $context = [],
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get exception context for detailed error handling
     * 
     * @return array Context array with additional error details
     * 
     * @example
     *   $context = $exception->getContext();
     *   if (isset($context['job_id'])) {
     *       // Handle job-specific error
     *   }
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set exception context
     * 
     * @param array $context
     * @return self For fluent interface
     */
    public function setContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Convert exception to array for logging
     * 
     * @return array Exception data suitable for logging systems
     */
    public function toArray(): array
    {
        return [
            'exception' => static::class,
            'message' => $this->message,
            'code' => $this->code,
            'context' => $this->context,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}
