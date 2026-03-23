<?php
/**
 * Proxy Bid Validation Exceptions
 * 
 * Exceptions for proxy bid creation and validation
 * Used by ProxyBidValidator and ProxyBidService
 * 
 * @package WC\Auction\Exceptions
 * @requirement REQ-AB-005: Proxy bid validation rules
 */

namespace WC\Auction\Exceptions;

/**
 * Proxy bid validation exception
 * 
 * Thrown when proxy bid data fails validation checks
 * Includes details about which validation rule failed
 * 
 * Example:
 *   throw new ProxyBidValidationException(
 *       'User already has an active proxy bid for this auction',
 *       context: ['auction_id' => 123, 'user_id' => 456]
 *   );
 */
class ProxyBidValidationException extends AuctionException
{
    protected $code = 1201;
    
    /**
     * Validation rules that failed
     * 
     * @var array
     */
    protected $failedRules = [];

    /**
     * Initialize proxy bid validation exception
     * 
     * @param string $message Primary error message
     * @param int $code Exception code
     * @param array $context Error context
     * @param array $failedRules Array of rule names that failed
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        array $context = [],
        array $failedRules = [],
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $context, $previous);
        $this->failedRules = $failedRules;
    }

    /**
     * Get validation rules that failed
     * 
     * @return array List of failed rule identifiers
     */
    public function getFailedRules(): array
    {
        return $this->failedRules;
    }

    /**
     * Add a failed validation rule
     * 
     * @param string $rule Rule identifier
     * @return self For fluent interface
     */
    public function addFailedRule(string $rule): self
    {
        $this->failedRules[] = $rule;
        return $this;
    }
}

/**
 * Invalid bid exception
 * 
 * Thrown when bid amount or bid placement is invalid
 * 
 * Example:
 *   throw new InvalidBidException(
 *       'Bid amount must be positive',
 *       context: ['bid_amount' => -100]
 *   );
 */
class InvalidBidException extends AuctionException
{
    protected $code = 1202;
}

/**
 * Auction state exception
 * 
 * Thrown when auction is not in the required state for an operation
 * 
 * Example:
 *   throw new AuctionStateException(
 *       'Auction not active',
 *       context: ['auction_id' => 123, 'status' => 'ended']
 *   );
 */
class AuctionStateException extends AuctionException
{
    protected $code = 1203;
}

/**
 * Invalid state exception (for state machines)
 * 
 * Thrown when attempting an invalid state transition
 * 
 * Example:
 *   throw new InvalidStateException(
 *       'Cannot transition from WON to ACTIVE',
 *       context: ['current_state' => 'WON', 'requested_state' => 'ACTIVE']
 *   );
 */
class InvalidStateException extends AuctionException
{
    protected $code = 1204;
}

/**
 * Database exception
 * 
 * Thrown when database operations fail (insert, update, delete)
 * 
 * Example:
 *   throw new DatabaseException(
 *       'Failed to save proxy bid',
 *       context: ['operation' => 'insert', 'table' => 'proxy_bids', 'error' => $dbError]
 *   );
 */
class DatabaseException extends AuctionException
{
    protected $code = 1301;
}

/**
 * Resource not found exception
 * 
 * Thrown when attempting to access a non-existent resource
 * 
 * Example:
 *   throw new ResourceNotFoundException(
 *       'Proxy bid not found',
 *       context: ['proxy_id' => 999]
 *   );
 */
class ResourceNotFoundException extends AuctionException
{
    protected $code = 1302;
}
