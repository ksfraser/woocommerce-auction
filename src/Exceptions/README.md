# Exception Hierarchy

Complete exception hierarchy for the YITH Auctions system. All exceptions extend `AuctionException` for consistent error handling and precise catch blocks.

## Exception Organization

```
AuctionException (base class)
├── Queue/
│   ├── ConnectionException (1001)
│   ├── ValidationException (1101)
│   ├── OverflowException (1102)
│   ├── MaxRetriesExceededException (1103)
│   ├── JobNotFoundException (1104)
│   ├── TaskTimeoutException (1105)
│   └── WorkerException (1106)
├── ProxyBidValidationException (1201)
├── InvalidBidException (1202)
├── AuctionStateException (1203)
├── InvalidStateException (1204)
├── DatabaseException (1301)
└── ResourceNotFoundException (1302)
```

## Exception Codes

Codes are organized by range for easy categorization:

| Range | Purpose | Examples |
|-------|---------|----------|
| 1000-1099 | Queue/Async System | Connection, Validation, Overflow |
| 1100-1199 | Queue Operations | Validation, Retries, Timeout |
| 1200-1299 | Proxy Bid Operations | Validation, State, Bid Invalid |
| 1300-1399 | Resource Errors | Database, Not Found |

## Using Exceptions

### Specific Error Handling

```php
use WC\Auction\Exceptions\Queue\ConnectionException;
use WC\Auction\Exceptions\Queue\ValidationException;

try {
    $bidQueue->enqueue($jobData);
} catch (ConnectionException $e) {
    // Handle Redis connection error
    error_log('Queue unavailable: ' . $e->getMessage());
} catch (ValidationException $e) {
    // Handle invalid job data
    error_log('Job invalid: ' . $e->getMessage());
} catch (AuctionException $e) {
    // Catch any other auction-related exception
    error_log('Error: ' . $e->getMessage());
}
```

### Error Context

All exceptions support context for debugging:

```php
throw new ValidationException(
    'Queue overflow',
    context: [
        'max_size' => 10000,
        'current_size' => 10000,
        'operation' => 'enqueue'
    ]
);

// Retrieve context
$context = $e->getContext();
if (isset($context['max_size'])) {
    // Log queue metrics
}
```

### Logging and Monitoring

Convert exceptions to arrays for structured logging:

```php
try {
    $bidQueue->enqueue($job);
} catch (AuctionException $e) {
    $errorData = $e->toArray();
    // Log to external service
    $logger->error('Auction exception', $errorData);
}
```

## Base Exception Features

The `AuctionException` base class provides:

- **Context Support**: Attach additional data to exceptions
- **Array Conversion**: `toArray()` for logging systems
- **Exception Chaining**: Support for previous exceptions
- **Fluent Interface**: Methods return `$this` for chaining

```php
$exception = new ProxyBidValidationException(
    'Validation failed',
    context: ['auction_id' => 123]
)
->addFailedRule('duplicate-bid')
->addFailedRule('insufficient-funds');
```

## Creating New Exceptions

When creating new exceptions for Phase 1-5:

1. **Extend**: `AuctionException` instead of generic `Exception`
2. **Assign Code**: Use code range appropriate to component
3. **Document**: Add PHPDoc with usage example
4. **Context**: Support context data for debugging

```php
class MyCustomException extends AuctionException
{
    protected $code = 1250; // In appropriate range
}
```

## Phase Coverage

### Phase 0 (Current)
- ✅ ProxyBidValidationException
- ✅ InvalidBidException
- ✅ AuctionStateException
- ✅ InvalidStateException
- ✅ DatabaseException
- ✅ ResourceNotFoundException

### Phase 1 (Current - TDD)
- ✅ Queue/* exceptions (Connection, Validation, Overflow, MaxRetries, etc.)
- ⏳ CircuitBreaker exceptions (Phase 1 - to add)
- ⏳ Performance Monitoring exceptions (Phase 1 - to add)

### Phases 2-5
- Additional exceptions as features are implemented
- All extending from base `AuctionException`

## Testing

All exceptions are testable via PHPUnit:

```php
public function testThrowsValidationException()
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('Job data invalid');
    
    $bidQueue->enqueue([]); // Invalid empty data
}

public function testExceptionContext()
{
    try {
        // Trigger exception
    } catch (AuctionException $e) {
        $this->assertArrayHasKey('job_id', $e->getContext());
    }
}
```

---

**Exception Hierarchy Documentation**  
Last Updated: 2026-03-22  
Phase Coverage: 0-1 (with provisions for 2-5)
