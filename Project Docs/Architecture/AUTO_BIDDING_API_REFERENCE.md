## Auto-Bidding System API Reference

**Requirements**: REQ-AUTO-BID-SERVICE-001, REQ-AUTO-BID-ENGINE-001, REQ-AUTO-BID-REPO-001

Complete API documentation for the proxy bidding system.

---

## Classes Overview

| Class | Purpose | Location |
|-------|---------|----------|
| `AutoBidService` | Orchestrates auto-bidding workflows | `src/Services/AutoBidding/AutoBidService.php` |
| `ProxyBiddingEngine` | Implements bidding algorithm | `src/Services/AutoBidding/ProxyBiddingEngine.php` |
| `AutoBidRepository` | Database access layer | `src/Repository/AutoBidRepository.php` |
| `AutoBidStatus` | Status enumeration | `src/ValueObjects/AutoBidStatus.php` |
| `ProxyBidResult` | Calculation result value object | `src/ValueObjects/ProxyBidResult.php` |

---

## AutoBidService

**Namespace**: `Yith\Auctions\Services\AutoBidding`

**Requirement**: REQ-AUTO-BID-SERVICE-001

Main service for managing auto-bids. Orchestrates repository, engine, and queue operations.

### Methods

#### setAutoBid()

Creates a new auto-bid for a user on an auction.

```php
public function setAutoBid(
    int $auction_id,
    int $user_id,
    float $maximum_bid,
    array $metadata = []
): string
```

**Parameters:**
- `$auction_id` (int): ID of the auction
- `$user_id` (int): ID of the user setting auto-bid
- `$maximum_bid` (float): Maximum amount to bid automatically
- `$metadata` (array, optional): Additional metadata to store

**Returns:** (string) UUID of created auto-bid

**Throws:**
- `\InvalidArgumentException`: If duplicate exists or invalid amount
- `\DomainException`: If auction not found or not active

**Example:**
```php
$service = wcact_get_service('auto_bid.service');
try {
    $auto_bid_id = $service->setAutoBid(
        auction_id: 123,
        user_id: get_current_user_id(),
        maximum_bid: 100.00,
        metadata: ['source' => 'mobile_app']
    );
    echo "Auto-bid created: $auto_bid_id";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

**History Event:** `AUTO_BID_CREATED`

---

#### cancelAutoBid()

Cancels an active auto-bid.

```php
public function cancelAutoBid(string $auto_bid_id): bool
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid to cancel

**Returns:** (bool) True if cancelled, false if already terminal

**Throws:**
- `\InvalidArgumentException`: If auto-bid not found or already terminal
- `\RuntimeException`: If database update fails

**Example:**
```php
if ($service->cancelAutoBid($auto_bid_id)) {
    echo "Auto-bid cancelled successfully";
} else {
    echo "Auto-bid already in terminal state";
}
```

**History Event:** `AUTO_BID_CANCELLED`

---

#### updateMaximum()

Increases the maximum bid amount for an active auto-bid.

```php
public function updateMaximum(string $auto_bid_id, float $new_maximum): bool
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid
- `$new_maximum` (float): New maximum amount (must be >= current)

**Returns:** (bool) True if updated

**Throws:**
- `\InvalidArgumentException`: If maximum cannot be decreased or invalid amount
- `\DomainException`: If auto-bid not found or in terminal state

**Example:**
```php
try {
    $service->updateMaximum($auto_bid_id, 150.00);
    echo "Maximum bid increased to $150";
} catch (\InvalidArgumentException $e) {
    echo "Cannot increase: " . $e->getMessage();
}
```

**History Event:** `MAXIMUM_UPDATED`

---

#### getAutoBid()

Retrieves auto-bid details by ID.

```php
public function getAutoBid(string $auto_bid_id): ?array
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid

**Returns:** (array|null) Auto-bid data or null if not found

**Example:**
```php
$auto_bid = $service->getAutoBid($auto_bid_id);
if ($auto_bid) {
    echo "Status: " . $auto_bid['status'];
    echo "Maximum: $" . $auto_bid['maximum_bid'];
    echo "Created: " . $auto_bid['created_at'];
} else {
    echo "Auto-bid not found";
}
```

**Return Array Structure:**
```php
[
    'auto_bid_id'   => 'uuid-12345',
    'auction_id'    => 123,
    'user_id'       => 456,
    'maximum_bid'   => '100.00',
    'status'        => 'ACTIVE',
    'created_at'    => '2024-01-01 12:00:00',
    'updated_at'    => '2024-01-01 12:00:00',
    'metadata'      => [],
]
```

---

#### getUserAutoBids()

Retrieves all auto-bids for a user with optional filters.

```php
public function getUserAutoBids(
    int $user_id,
    array $filters = []
): array
```

**Parameters:**
- `$user_id` (int): User ID
- `$filters` (array, optional): Query filters
  - `status` (string): Filter by status (e.g., 'ACTIVE')
  - `auction_id` (int): Filter by auction

**Returns:** (array) Array of auto-bid records

**Example:**
```php
// Get all user's auto-bids
$all_bids = $service->getUserAutoBids(456);

// Get only active auto-bids
$active_bids = $service->getUserAutoBids(456, ['status' => 'ACTIVE']);

// Get auto-bids for specific auction
$auction_bids = $service->getUserAutoBids(456, ['auction_id' => 123]);
```

---

#### getHistory()

Retrieves history entries for an auto-bid.

```php
public function getHistory(string $auto_bid_id, int $limit = 50): array
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid
- `$limit` (int, optional): Maximum entries to return (default: 50)

**Returns:** (array) Array of history event records

**Example:**
```php
$history = $service->getHistory($auto_bid_id);
foreach ($history as $event) {
    echo $event['event_type'] . " - " . $event['created_at'];
    if (!empty($event['context'])) {
        echo " - " . json_encode($event['context']);
    }
    echo "\n";
}
```

**History Event Types:**
- `AUTO_BID_CREATED`: Auto-bid initially set
- `AUTO_BID_CANCELLED`: User cancelled
- `MAXIMUM_UPDATED`: Maximum amount increased
- `PROXY_BID_QUEUED`: Counter-bid queued for processing
- `PROXY_BID_PLACED`: Counter-bid successfully placed
- `PROXY_BID_FAILED`: Counter-bid placement failed
- `AUTO_BID_WON`: Auto-bidder won the auction
- `AUTO_BID_LOST`: Auto-bidder lost the auction
- `AUTO_BID_COMPLETED`: Auction ended (terminal)

---

#### processOutbid()

Internal method: Processes when competitor places bid higher than auto-bid.

```php
public function processOutbid(
    int $auction_id,
    array $new_bid,
    string $auction_status
): bool
```

**Parameters:**
- `$auction_id` (int): Auction ID where bid placed
- `$new_bid` (array): The new bid placed
  - `user_id` (int): User who placed bid
  - `amount` (float): Bid amount
  - `timestamp` (string, optional): When bid placed
- `$auction_status` (string): Current auction status

**Returns:** (bool) True if counter-bid processed/queued

**Example (Internal Usage):**
```php
// Called from bid placement hook
add_action('yith_wcact_bid_placed', function($auction_id, $bid, $user_id) {
    $service->processOutbid($auction_id, [
        'user_id' => $user_id,
        'amount' => $bid,
    ], 'ACTIVE');
});
```

---

## ProxyBiddingEngine

**Namespace**: `Yith\Auctions\Services\AutoBidding`

**Requirement**: REQ-AUTO-BID-ENGINE-001

Implements the proxy bidding algorithm.

### Methods

#### calculateProxyBid()

Calculates the proxy bid amount given current bid and auto-bidder's maximum.

```php
public function calculateProxyBid(
    float $current_bid,
    float $auto_bidder_maximum,
    ?ProxyBidResult $last_proxy = null
): ProxyBidResult
```

**Parameters:**
- `$current_bid` (float): Current winning bid in auction
- `$auto_bidder_maximum` (float): Auto-bidder's maximum willing to pay
- `$last_proxy` (ProxyBidResult, optional): Previous proxy result (for escalation)

**Returns:** (ProxyBidResult) Calculated proxy bid with metadata

**Example:**
```php
$engine = wcact_get_service('auto_bid.engine');

$result = $engine->calculateProxyBid(
    current_bid: 50.00,
    auto_bidder_maximum: 100.00
);

echo "Proxy bid: $" . $result->amount;
echo "Increment: $" . $result->increment;
echo "Outbid amount: $" . $result->outbid_amount;
```

**Algorithm:**
1. If current_bid >= maximum → return current_bid (already outbid)
2. Calculate next_bid = current_bid + increment
3. If next_bid > maximum → return maximum
4. Return next_bid

---

#### shouldPlaceCounterBid()

Determines if auto-bidder should place counter-bid for a new bid.

```php
public function shouldPlaceCounterBid(
    array $auto_bid,
    float $outbid_amount,
    ?int $outbidder_id = null
): bool
```

**Parameters:**
- `$auto_bid` (array): Auto-bid record with keys: `user_id`, `maximum_bid`, `status`
- `$outbid_amount` (float): Amount by which auto-bid was outbid
- `$outbidder_id` (int, optional): ID of user who outbid (to skip self-bids)

**Returns:** (bool) True if counter-bid should be placed

**Conditions for True:**
- Auto-bid status is ACTIVE or COMPOSED
- Outbid amount is less than auto-bid's maximum
- Outbidder is not the auto-bidder themselves
- Auto-bid not already escalating infinitely

**Example:**
```php
if ($engine->shouldPlaceCounterBid($auto_bid, 50.75, $competitor_id)) {
    // Place counter-bid
    $service->placeCounterBid($auto_bid['auto_bid_id']);
}
```

---

#### validateBid()

Validates a proposed bid amount.

```php
public function validateBid(
    float $amount,
    float $minimum_increment,
    float $current_bid,
    float $maximum_bid
): bool
```

**Parameters:**
- `$amount` (float): Bid amount to validate
- `$minimum_increment` (float): Minimum increment size
- `$current_bid` (float): Current winning bid
- `$maximum_bid` (float): Auto-bidder's maximum

**Returns:** (bool) True if bid is valid

**Validation Rules:**
- amount > 0
- amount >= current_bid + minimum_increment
- amount <= maximum_bid

**Example:**
```php
if ($engine->validateBid(75.50, 0.25, 50.00, 100.00)) {
    // Bid is valid
} else {
    // Bid is invalid
}
```

---

#### calculateMinimumIncrement()

Calculates minimum bid increment for given amount.

```php
public function calculateMinimumIncrement(float $bid_amount): float
```

**Parameters:**
- `$bid_amount` (float): Current bid amount

**Returns:** (float) Minimum increment size

**Increment Schedule:**
```
$0.00 - $0.99   → $0.05
$1.00 - $4.99   → $0.25
$5.00 - $99.99  → $1.00
$100+           → $2.50
```

**Example:**
```php
echo $engine->calculateMinimumIncrement(3.50);  // 0.25
echo $engine->calculateMinimumIncrement(50.00); // 1.00
echo $engine->calculateMinimumIncrement(150.00); // 2.50
```

**Callable Configuration:**

The increment calculator is configurable via service container:

```php
$service_container->register('increment_calculator', function() {
    return function(float $bid): float {
        if ($bid < 1.00) return 0.05;
        if ($bid < 5.00) return 0.25;
        return 1.00;
    };
});
```

---

## AutoBidRepository

**Namespace**: `Yith\Auctions\Repository`

**Requirement**: REQ-AUTO-BID-REPO-001

Database access layer for auto-bid operations.

### Methods

#### create()

Creates a new auto-bid record.

```php
public function create(array $data): string
```

**Parameters:**
- `$data` (array): Auto-bid data
  - `auction_id` (int, required)
  - `user_id` (int, required)
  - `maximum_bid` (float, required)
  - `status` (string, optional): Defaults to 'ACTIVE'
  - `metadata` (array, optional): Custom data

**Returns:** (string) Generated UUID of created record

**Example:**
```php
$auto_bid_id = $repository->create([
    'auction_id' => 123,
    'user_id' => 456,
    'maximum_bid' => 100.00,
    'metadata' => ['source' => 'web'],
]);
```

---

#### getById()

Retrieves auto-bid by ID.

```php
public function getById(string $auto_bid_id): ?array
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid

**Returns:** (array|null) Auto-bid record or null

---

#### update()

Updates auto-bid record.

```php
public function update(string $auto_bid_id, array $data): bool
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid
- `$data` (array): Fields to update
  - `maximum_bid` (float)
  - `status` (string)
  - `metadata` (array)

**Returns:** (bool) True if updated

**Example:**
```php
$repository->update($auto_bid_id, [
    'maximum_bid' => 150.00,
    'status' => 'ACTIVE',
]);
```

---

#### getActiveForAuction()

Gets all active auto-bids for an auction.

```php
public function getActiveForAuction(int $auction_id): array
```

**Parameters:**
- `$auction_id` (int): Auction ID

**Returns:** (array) Array of active auto-bid records

**SQL Generated:**
```sql
SELECT * FROM auto_bids
WHERE auction_id = ?
  AND status IN ('ACTIVE', 'COMPOSED')
ORDER BY maximum_bid DESC
```

---

#### getActiveForAuctionUser()

Gets active auto-bid for specific auction and user.

```php
public function getActiveForAuctionUser(
    int $auction_id,
    int $user_id
): ?array
```

**Parameters:**
- `$auction_id` (int): Auction ID
- `$user_id` (int): User ID

**Returns:** (array|null) Auto-bid record or null if no active bid

---

#### getForUser()

Gets all auto-bids for a user with optional filters.

```php
public function getForUser(
    int $user_id,
    array $filters = []
): array
```

**Parameters:**
- `$user_id` (int): User ID
- `$filters` (array, optional):
  - `status` (string): Filter by status
  - `auction_id` (int): Filter by auction
  - `include_deleted` (bool): Include soft-deleted (default: false)

**Returns:** (array) Array of auto-bids

---

#### recordHistory()

Records a history event for an auto-bid.

```php
public function recordHistory(
    string $auto_bid_id,
    string $event_type,
    array $context = []
): string
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid
- `$event_type` (string): Type of event (see list below)
- `$context` (array, optional): Additional context/data

**Returns:** (string) ID of history record

**Event Types:**
- `AUTO_BID_CREATED`
- `AUTO_BID_CANCELLED`
- `MAXIMUM_UPDATED`
- `PROXY_BID_QUEUED`
- `PROXY_BID_PLACED`
- `PROXY_BID_FAILED`
- `AUTO_BID_WON`
- `AUTO_BID_LOST`
- `AUTO_BID_COMPLETED`

**Example:**
```php
$repository->recordHistory(
    $auto_bid_id,
    'PROXY_BID_PLACED',
    [
        'bid_amount' => 75.50,
        'increment' => 0.25,
        'job_id' => 'job-12345',
    ]
);
```

---

#### getHistory()

Retrieves history events for an auto-bid.

```php
public function getHistory(
    string $auto_bid_id,
    int $limit = 50,
    int $offset = 0
): array
```

**Parameters:**
- `$auto_bid_id` (string): UUID of auto-bid
- `$limit` (int, optional): Records to return (default: 50)
- `$offset` (int, optional): Pagination offset (default: 0)

**Returns:** (array) History records ordered by date descending

---

## AutoBidStatus

**Namespace**: `Yith\Auctions\ValueObjects`

**Requirement**: REQ-AUTO-BID-SERVICE-001

Status enumeration for auto-bids.

### Constants

```php
class AutoBidStatus {
    /**
     * Auto-bid is active and monitoring bids.
     */
    public const ACTIVE = 'ACTIVE';

    /**
     * Auto-bid is temporarily in composed state while placing counter-bid.
     */
    public const COMPOSED = 'COMPOSED';

    /**
     * Auto-bid successfully won the auction.
     */
    public const COMPLETED = 'COMPLETED';

    /**
     * User manually cancelled the auto-bid.
     */
    public const CANCELLED = 'CANCELLED';

    /**
     * Auto-bid encountered an error.
     */
    public const FAILED = 'FAILED';
}
```

### Static Methods

#### isTerminal()

Checks if status is terminal (cannot be changed).

```php
public static function isTerminal(string $status): bool
```

**Terminal Statuses:**
- `COMPLETED`
- `CANCELLED`
- `FAILED`

**Example:**
```php
if (AutoBidStatus::isTerminal('CANCELLED')) {
    // Cannot update cancelled auto-bid
}
```

---

#### canTransitionTo()

Checks if status can transition to another status.

```php
public static function canTransitionTo(string $from, string $to): bool
```

**Valid Transitions:**
```
ACTIVE → COMPOSED, CANCELLED, FAILED
COMPOSED → ACTIVE, COMPLETED, FAILED
COMPLETED, CANCELLED, FAILED → (no transitions)
```

**Example:**
```php
if (AutoBidStatus::canTransitionTo('ACTIVE', 'CANCELLED')) {
    // Can cancel
}
```

---

## ProxyBidResult

**Namespace**: `Yith\Auctions\ValueObjects`

Value object for proxy bid calculation results.

### Properties

```php
class ProxyBidResult {
    /**
     * The calculated proxy bid amount.
     */
    public float $amount;

    /**
     * The increment added to current bid.
     */
    public float $increment;

    /**
     * How much higher than current bid the proxy is.
     */
    public float $outbid_amount;

    /**
     * Whether this bid equals the maximum.
     */
    public bool $is_maximum_reached;
}
```

### Methods

#### fromAmount()

Creates result from calculated amount.

```php
public static function fromAmount(
    float $amount,
    float $current_bid,
    float $auto_bidder_maximum,
    float $increment_calc_func
): ProxyBidResult
```

**Example:**
```php
$result = ProxyBidResult::fromAmount(
    amount: 75.50,
    current_bid: 50.00,
    auto_bidder_maximum: 100.00,
    increment: 0.25
);

echo $result->outbid_amount; // 25.50
echo $result->is_maximum_reached; // false
```

---

## Common Use Cases

### Use Case 1: User Sets Auto-Bid

```php
$service = wcact_get_service('auto_bid.service');

try {
    $auto_bid_id = $service->setAutoBid(
        auction_id: $auction->get_id(),
        user_id: get_current_user_id(),
        maximum_bid: floatval($_POST['maximum_bid'])
    );
    
    wp_send_json_success([
        'auto_bid_id' => $auto_bid_id,
        'message' => 'Auto-bid set successfully',
    ]);
} catch (\InvalidArgumentException $e) {
    wp_send_json_error(['message' => $e->getMessage()]);
}
```

### Use Case 2: Process Outbid

```php
add_action('yith_wcact_bid_placed', function($auction_id, $bid, $user_id) {
    $service = wcact_get_service('auto_bid.service');
    
    $service->processOutbid($auction_id, [
        'user_id' => $user_id,
        'amount' => $bid,
    ], 'ACTIVE');
});
```

### Use Case 3: Audit Auto-Bid Activity

```php
$service = wcact_get_service('auto_bid.service');
$history = $service->getHistory($auto_bid_id);

foreach ($history as $event) {
    $timestamp = $event['created_at'];
    $type = $event['event_type'];
    $context = json_decode($event['context'], true);
    
    log_activity("Auto-bid $type at $timestamp", $context);
}
```

### Use Case 4: Admin Dashboard Stats

```php
$repository = wcact_get_service('auto_bid.repository');

$user_bids = $repository->getForUser(get_current_user_id());
$active_count = count(array_filter($user_bids, fn($b) => $b['status'] === 'ACTIVE'));
$completed_count = count(array_filter($user_bids, fn($b) => $b['status'] === 'COMPLETED'));

echo "Active auto-bids: $active_count";
echo "Completed: $completed_count";
```

---

## Error Handling Reference

```php
try {
    $auto_bid_id = $service->setAutoBid($auction_id, $user_id, $max);
} catch (\InvalidArgumentException $e) {
    // Business logic validation failed
    // e.g., Duplicate auto-bid, invalid amount
    log_error('Validation: ' . $e->getMessage());
} catch (\DomainException $e) {
    // Domain constraint violated
    // e.g., Auction not found, user not authorized
    log_error('Domain: ' . $e->getMessage());
} catch (\RuntimeException $e) {
    // Runtime error (DB, permissions)
    // e.g., Database connection failed
    log_error('Runtime: ' . $e->getMessage());
} catch (\Exception $e) {
    // Unexpected error
    log_error('Unexpected: ' . $e->getMessage());
}
```

---

## Performance Considerations

### Large Result Sets

For users with many auto-bids, use pagination:

```php
$user_id = 456;
$page = 1;
$per_page = 20;

$all_bids = $service->getUserAutoBids($user_id);
$paginated = array_slice($all_bids, ($page-1) * $per_page, $per_page);

$total_pages = ceil(count($all_bids) / $per_page);
```

### History Queries

History can grow large. Use limit and pagination:

```php
// Get recent 50 events
$recent = $repository->getHistory($auto_bid_id, limit: 50);

// Get older events (page 2)
$page_2 = $repository->getHistory($auto_bid_id, limit: 50, offset: 50);
```

### Database Optimization

All query methods use optimized indexed queries. The migration creates:

- `idx_auto_bids_auction` - For auction lookups
- `idx_auto_bids_user` - For user lookups
- `idx_auto_bids_status` - For status filtering
- `idx_auto_bid_history_auto_bid` - For history queries

---

## Compatibility

- **PHP**: 7.3+
- **WordPress**: 5.0+
- **WooCommerce**: 4.0+
- **Databases**: MySQL 5.7+, PostgreSQL 9.6+

---

## See Also

- [Integration Guide](AUTO_BIDDING_INTEGRATION_GUIDE.md)
- [Testing Guide](../Testing/AUTO_BIDDING_TESTING_GUIDE.md)
- [Database Schema](../Database/AUTO_BIDDING_SCHEMA.md)
- [Algorithm Design](../Design/AUTO_BIDDING_ALGORITHM.md)
