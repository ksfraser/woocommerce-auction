# Automatic Bidding System - API Reference

## Overview

This document provides a comprehensive API reference for the Automatic Bidding System in YITH Auctions for WooCommerce.

## Table of Contents

1. [AutoBiddingEngine](#autobiddingengine)
2. [ProxyBidService](#proxybidservice)
3. [BidService](#bidservice)
4. [BidIncrementCalculator](#bidincrementcalculator)
5. [ProxyBidValidator](#proxybidvalidator)
6. [Repositories](#repositories)
7. [Models](#models)
8. [Exceptions](#exceptions)

---

## AutoBiddingEngine

**Namespace:** `WC\Auction\Services`  
**Implements:** `AutoBiddingEngineInterface`  
**Requirement:** REQ-AB-001, REQ-AB-002, REQ-AB-004, REQ-AB-005

Orchestrates automatic bidding when new manual bids are placed on an auction.

### Constructor

```php
public function __construct(
    ProxyBidRepository $proxy_repository,
    AutoBidLogRepository $log_repository,
    ProxyBidService $proxy_service,
    BidIncrementCalculator $calculator
)
```

### Methods

#### handleNewBid()

**Signature:**
```php
public function handleNewBid(
    int $auction_id,
    float $new_bid_amount,
    int $bidder_user_id = 0,
    int $previous_bidder_id = 0
): void
```

**Description:**  
Evaluates all active proxy bids for an auction when a new manual bid is placed. For each proxy:
- Skips if same user who placed the manual bid
- Calculates required bid amount using current strategy
- Places auto-bid if proxy maximum allows
- Marks proxy as outbid if maximum exceeded

**Parameters:**
- `auction_id` (int): ID of auction receiving new bid
- `new_bid_amount` (float): Amount of manual bid placed
- `bidder_user_id` (int): User who placed the manual bid
- `previous_bidder_id` (int): Previous highest bidder (optional)

**Return:** void

**Exceptions:**
- Exceptions are caught and logged; method does not throw

**Example:**
```php
$engine->handleNewBid(
    auction_id: 100,
    new_bid_amount: 150.00,
    bidder_user_id: 50
);
```

#### setEnabled()

**Signature:**
```php
public function setEnabled(bool $enabled): self
```

**Description:**  
Enables or disables automatic bidding processing. Useful for maintenance or testing.

**Parameters:**
- `enabled` (bool): True to enable, false to disable

**Return:** self (fluent interface)

**Example:**
```php
$engine->setEnabled(true);
```

#### setCalculator()

**Signature:**
```php
public function setCalculator(BidIncrementCalculator $calculator): self
```

**Description:**  
Sets the bid increment calculator strategy for this engine instance.

**Parameters:**
- `calculator` (BidIncrementCalculator): Calculator instance with configured strategy

**Return:** self (fluent interface)

**Example:**
```php
$calc = new BidIncrementCalculator(
    BidIncrementCalculator::STRATEGY_PERCENTAGE,
    ['percentage' => 0.10]
);
$engine->setCalculator($calc);
```

#### getAuctionStatistics()

**Signature:**
```php
public function getAuctionStatistics(int $auction_id): array
```

**Description:**  
Retrieves auto-bidding statistics for an auction.

**Parameters:**
- `auction_id` (int): Auction ID

**Return:** array
```php
[
    'total_attempts' => 42,
    'successful_attempts' => 38,
    'failed_attempts' => 4,
    'outbid_count' => 15,
    'average_processing_time_ms' => 12.5
]
```

**Example:**
```php
$stats = $engine->getAuctionStatistics(100);
echo "Success rate: " . ($stats['successful_attempts'] / $stats['total_attempts'] * 100) . "%";
```

---

## ProxyBidService

**Namespace:** `WC\Auction\Services`  
**Implements:** `ProxyBidServiceInterface`  
**Requirement:** REQ-PROXY-CREATE, REQ-PROXY-UPDATE, REQ-PROXY-CANCEL

Manages proxy bid lifecycle: creation, updates, and cancellation.

### Constructor

```php
public function __construct(
    ProxyBidRepository $proxy_repository,
    ProxyBidValidator $validator = null,
    LoggerInterface $logger = null
)
```

### Methods

#### create()

**Signature:**
```php
public function create(array $data): ProxyBid
```

**Description:**  
Creates a new proxy bid with validation. Enforces business rules (max > current, user not already bidding, etc).

**Parameters:**
- `data` (array):
  - `auction_id` (int, required): Auction ID
  - `user_id` (int, required): User placing proxy bid
  - `maximum_bid` (float, required): Maximum bid amount
  - `starting_proxy_bid` (float, optional): Initial auto-bid amount

**Return:** ProxyBid (created instance)

**Exceptions:**
- `ProxyBidValidationException`: Validation failed
- `DatabaseException`: Persistence failed

**Example:**
```php
$proxy = $service->create([
    'auction_id' => 100,
    'user_id' => 5,
    'maximum_bid' => 500.00
]);
```

#### update()

**Signature:**
```php
public function update(int $proxy_id, array $data): ProxyBid
```

**Description:**  
Updates proxy bid attributes (not state transitions).

**Parameters:**
- `proxy_id` (int): Proxy bid ID
- `data` (array): Attributes to update (maximum_bid, notes)

**Return:** ProxyBid (updated instance)

**Exceptions:**
- `InvalidProxyBidException`: Proxy not found

**Example:**
```php
$proxy = $service->update(1, ['maximum_bid' => 600.00]);
```

#### updateCurrentBid()

**Signature:**
```php
public function updateCurrentBid(int $proxy_id, float $amount): ProxyBid
```

**Description:**  
Updates the current proxy bid amount during auto-bidding. Updates timestamp.

**Parameters:**
- `proxy_id` (int): Proxy bid ID
- `amount` (float): New bid amount

**Return:** ProxyBid (updated instance)

**Exceptions:**
- `InvalidArgumentException`: Amount exceeds maximum or negative

**Example:**
```php
$proxy = $service->updateCurrentBid(1, 200.00);
```

#### markOutbid()

**Signature:**
```php
public function markOutbid(int $proxy_id): ProxyBid
```

**Description:**  
Marks proxy bid as outbid when another bid exceeds its maximum.

**Parameters:**
- `proxy_id` (int): Proxy bid ID

**Return:** ProxyBid (updated instance)

**Example:**
```php
$proxy = $service->markOutbid(1);
```

#### cancel()

**Signature:**
```php
public function cancel(int $proxy_id, string $reason = ''): void
```

**Description:**  
Cancels proxy bid. Reasons: 'user', 'admin', 'auction_ended'.

**Parameters:**
- `proxy_id` (int): Proxy bid ID
- `reason` (string): Cancellation reason

**Return:** void

**Exceptions:**
- `InvalidStateException`: Cannot cancel from current state

**Example:**
```php
$service->cancel(1, 'user');
```

#### findActive()

**Signature:**
```php
public function findActive(int $auction_id): ProxyBid[]
```

**Description:**  
Retrieves all active proxy bids for an auction.

**Parameters:**
- `auction_id` (int): Auction ID

**Return:** ProxyBid[] (array of active proxies)

**Example:**
```php
$active_proxies = $service->findActive(100);
foreach ($active_proxies as $proxy) {
    echo $proxy->user_id . ": $" . $proxy->maximum_bid;
}
```

---

## BidService

**Namespace:** `WC\Auction\Services`  
**Implements:** `BidServiceInterface`

Manages bid placement and state management.

### Constructor

```php
public function __construct(
    BidRepository $bid_repository,
    BidIncrementCalculator $calculator = null
)
```

### Methods

#### place()

**Signature:**
```php
public function place(
    int $auction_id,
    int $user_id,
    float $amount,
    string $bid_type = Bid::TYPE_MANUAL,
    \DateTime $expires_at = null
): Bid
```

**Description:**  
Places a bid on an auction. Validates amount and auction state.

**Parameters:**
- `auction_id` (int): Auction ID
- `user_id` (int): User ID
- `amount` (float): Bid amount
- `bid_type` (string): 'manual', 'proxy', 'admin'
- `expires_at` (DateTime): Optional expiration time

**Return:** Bid (created bid)

**Exceptions:**
- `InvalidBidException`: Bid amount invalid or auction not active

**Example:**
```php
$bid = $service->place(100, 5, 150.00, Bid::TYPE_MANUAL);
```

#### getNextRequiredBid()

**Signature:**
```php
public function getNextRequiredBid(float $current_bid): float
```

**Description:**  
Calculates the minimum bid required to beat current bid.

**Parameters:**
- `current_bid` (float): Current auction bid

**Return:** float (minimum required bid)

**Example:**
```php
$next = $service->getNextRequiredBid(100.00); // Returns 101.00 (with default +$1)
```

#### retract()

**Signature:**
```php
public function retract(int $bid_id): bool
```

**Description:**  
Retracts a bid (marks as retracted).

**Parameters:**
- `bid_id` (int): Bid ID

**Return:** bool (success)

**Example:**
```php
if ($service->retract(1)) {
    echo "Bid retracted";
}
```

---

## BidIncrementCalculator

**Namespace:** `WC\Auction\Services`  
**Requirement:** REQ-BID-API-003

Calculates bid increments using configurable strategies.

### Constants

```php
const STRATEGY_FIXED = 'fixed';
const STRATEGY_PERCENTAGE = 'percentage';
const STRATEGY_TIERED = 'tiered';
const STRATEGY_DYNAMIC = 'dynamic';
const STRATEGY_CUSTOM = 'custom';
```

### Constructor

```php
public function __construct(
    string $strategy = self::STRATEGY_FIXED,
    array $config = []
)
```

**Default config (FIXED strategy):**
```php
['increment' => 1.00]
```

### Methods

#### calculate()

**Signature:**
```php
public function calculate(float $current_bid): float
```

**Description:**  
Calculates next bid using configured strategy.

**Parameters:**
- `current_bid` (float): Current bid amount

**Return:** float (next bid amount)

**Example - Fixed:**
```php
$calc = new BidIncrementCalculator(
    BidIncrementCalculator::STRATEGY_FIXED,
    ['increment' => 5.00]
);
$next = $calc->calculate(100.00); // Returns 105.00
```

**Example - Percentage:**
```php
$calc = new BidIncrementCalculator(
    BidIncrementCalculator::STRATEGY_PERCENTAGE,
    ['percentage' => 0.10] // 10%
);
$next = $calc->calculate(100.00); // Returns 110.00
```

**Example - Tiered:**
```php
$calc = new BidIncrementCalculator(
    BidIncrementCalculator::STRATEGY_TIERED,
    [
        'tiers' => [
            100 => 1.00,
            500 => 5.00,
            1000 => 10.00
        ]
    ]
);
$next = $calc->calculate(250.00); // Returns 255.00 (tier: 100-500 → +$5)
```

#### setStrategy()

**Signature:**
```php
public function setStrategy(string $strategy, array $config = []): self
```

**Description:**  
Changes calculator strategy.

**Parameters:**
- `strategy` (string): Strategy constant
- `config` (array): Strategy configuration

**Return:** self (fluent interface)

**Example:**
```php
$calc->setStrategy(BidIncrementCalculator::STRATEGY_PERCENTAGE, ['percentage' => 0.10]);
```

---

## ProxyBidValidator

**Namespace:** `WC\Auction\Validators`  
**Requirement:** REQ-PROXY-VALIDATION

Validates proxy bid creation and state transitions.

### Methods

#### validate()

**Signature:**
```php
public function validate(array $data): void
```

**Description:**  
Validates proxy bid data for creation.

**Parameters:**
- `data` (array): Proxy bid data

**Exceptions:**
- `ProxyBidValidationException`: Multiple validation rules for:
  - Maximum bid below current bid
  - User already has active proxy
  - Auction not found or not active
  - Invalid amounts

**Example:**
```php
try {
    $validator->validate([
        'auction_id' => 100,
        'user_id' => 5,
        'maximum_bid' => 500.00
    ]);
} catch (ProxyBidValidationException $e) {
    echo "Validation failed: " . $e->getMessage();
}
```

#### validateMaxBid()

**Signature:**
```php
public function validateMaxBid(float $max_bid, float $current_bid): bool
```

**Description:**  
Validates maximum bid exceeds current bid.

**Parameters:**
- `max_bid` (float): Proposed maximum
- `current_bid` (float): Auction current bid

**Return:** bool (valid)

**Example:**
```php
if (!$validator->validateMaxBid(100.00, 150.00)) {
    // Error: max below current
}
```

---

## Repositories

All repositories implement the `RepositoryInterface` pattern with standard CRUD operations.

### ProxyBidRepository

```php
public function save(ProxyBid $proxy): int // Returns ID
public function findById(int $id): ProxyBid
public function findActiveByAuction(int $auction_id): ProxyBid[]
public function findByUser(int $user_id): ProxyBid[]
public function delete(int $id): bool
public function deleteByAuction(int $auction_id): int // Returns count deleted
```

### BidRepository

```php
public function save(Bid $bid): int
public function findById(int $id): Bid
public function findByAuction(int $auction_id): Bid[]
public function findByUserAndAuction(int $user_id, int $auction_id): Bid[]
public function delete(int $id): bool
public function deleteByAuction(int $auction_id): int
```

### AutoBidLogRepository

```php
public function log(AutoBidLog $log): int
public function findByAuction(int $auction_id): AutoBidLog[]
public function getStatistics(int $auction_id): array
public function delete(int $id): bool
```

---

## Models

All models implement the `ModelInterface` and support hydration/serialization.

### ProxyBid

```php
class ProxyBid {
    public const STATUS_ACTIVE = 'active';
    public const STATUS_OUTBID = 'outbid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WON = 'won';

    public int $id;
    public int $auction_id;
    public int $user_id;
    public float $maximum_bid;
    public float $current_proxy_bid;
    public string $status;
    public bool $cancelled_by_user;
    public \DateTime $created_at;
    public \DateTime $updated_at;
    
    public static function create(array $data): self;
}
```

### Bid

```php
class Bid {
    public const TYPE_MANUAL = 'manual';
    public const TYPE_PROXY = 'proxy';
    public const TYPE_ADMIN = 'admin';
    
    public int $id;
    public int $auction_id;
    public int $user_id;
    public float $bid_amount;
    public string $bid_type;
    public int $bid_number;
    public bool $is_retracted;
    public \DateTime $created_at;
    public \DateTime $updated_at;
    public ?\DateTime $expires_at;
    
    public static function create(array $data): self;
}
```

### Auction

```php
class Auction {
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const STATUS_CANCELLED = 'cancelled';
    
    public int $id;
    public int $product_id;
    public string $name;
    public float $current_bid;
    public int $current_bidder_id;
    public string $status;
    public ?\DateTime $end_time;
    public \DateTime $created_at;
    public \DateTime $updated_at;
    
    public function updateBid(Bid $bid): void;
}
```

---

## Exceptions

All exceptions inherit from a base `AuctionException` which extends `Exception`.

### ProxyBidValidationException

Thrown when proxy bid validation fails.

```php
throw new ProxyBidValidationException(
    'Maximum bid must exceed current bid'
);
```

### InvalidBidException

Thrown when bid placement fails validation.

```php
throw new InvalidBidException('Bid amount too low');
```

### InvalidStateException

Thrown when operation invalid for current state.

```php
throw new InvalidStateException(
    'Cannot cancel from ' . $current_status . ' status'
);
```

### InvalidCalculatorStrategyException

Thrown when unknown calculator strategy specified.

```php
throw new InvalidCalculatorStrategyException(
    'Unknown strategy: ' . $strategy
);
```

---

## Usage Example: Complete Workflow

```php
// 1. Initialize services
$proxy_repo = new ProxyBidRepository();
$bid_repo = new BidRepository();
$calculator = new BidIncrementCalculator(
    BidIncrementCalculator::STRATEGY_FIXED,
    ['increment' => 1.00]
);
$engine = new AutoBiddingEngine(
    $proxy_repo,
    new AutoBidLogRepository(),
    new ProxyBidService($proxy_repo),
    $calculator
);

// 2. User creates proxy bid
$proxy = (new ProxyBidService($proxy_repo))->create([
    'auction_id' => 100,
    'user_id' => 5,
    'maximum_bid' => 500.00
]);

// 3. Another user places manual bid
$bid_service = new BidService($bid_repo, $calculator);
$bid = $bid_service->place(100, 10, 200.00, Bid::TYPE_MANUAL);

// 4. Automatic bidding triggered
$engine->handleNewBid(
    auction_id: 100,
    new_bid_amount: 200.00,
    bidder_user_id: 10
);
// Engine automatically places auto-bid for user 5 if within their max

// 5. Check statistics
$stats = $engine->getAuctionStatistics(100);
echo "Success rate: " . ($stats['successful_attempts'] / $stats['total_attempts'] * 100) . "%";
```

---

## Error Handling Best Practices

```php
try {
    $proxy = $service->create($user_input);
} catch (ProxyBidValidationException $e) {
    // User input error - show user-friendly message
    return ['error' => $e->getMessage()];
} catch (DatabaseException $e) {
    // System error - log and show generic message
    logger->error($e);
    return ['error' => 'Unable to process request'];
}
```

---

## Performance Tips

1. **Use indexed queries** - ProxyBidRepository::findActiveByAuction() uses auction_id index
2. **Batch operations** - For 100+ proxies, consider async processing
3. **Cache calculator config** - BidIncrementCalculator strategy configuration
4. **Lazy load** - Don't load full user objects if only ID needed
5. **Monitor queries** - Log slow queries > 100ms

---

## Testing

See [tests/](../tests/) directory for comprehensive unit and integration tests:
- `AutoBiddingEngineTest.php` - 15+ test cases
- `ProxyBidServiceTest.php` - 12+ test cases
- `BidIncrementCalculatorTest.php` - 20+ test cases
- `AutoBiddingIntegrationTest.php` - 8+ integration scenarios

---

## Additional Resources

- [Architecture Document](ARCHITECTURE.md)
- [Database Schema](DB_SCHEMA.md)
- [Contributing Guide](CONTRIBUTING.md)
- [YITH Requirements](../AGENTS.md)
